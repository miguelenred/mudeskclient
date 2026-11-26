// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// Unit tests of the graphics pipeline codecs: node tests/rdpgfx_unit.mjs
import { readFileSync } from 'node:fs';
import { Worker } from 'node:worker_threads';
import { ClearCodec, GfxPipeline, Zgfx, fixColour, nscDecode } from '../public/assets/rdpgfx.js';
import { ProgressiveDecoder, RfxDecoder, idwtExtrapolate, rlgrDecode } from '../public/assets/rfx.js';

let failed = 0;
const check = (name, ok, info = '') => { console.log((ok ? 'OK   ' : 'FAIL ') + name + (info ? ' ' + info : '')); if (!ok) failed++; };
const hex = (s) => Uint8Array.from(Buffer.from(s.replace(/\s/g, ''), 'hex'));

// ZGFX: [MS-RDPEGFX] examples (single segment and multipart with matches)
const fox = 'The quick brown fox jumps over the lazy dog';
check('ZGFX single', new TextDecoder().decode(new Zgfx().decompress(hex(
  'E00454686520717569636B2062726F776E20666F78206A756D7073206F76657220746865206C617A7920646F67'))) === fox);
check('ZGFX multipart', new TextDecoder().decode(new Zgfx().decompress(hex(
  'E103002B000000110000000454686520717569636B2062726F776E200E00000004666F78206A756D7073206F7665100000002439080E91F8D8613D1E4406437 99C02'))) === fox);

// H.264: the SPS gets a BT.709 full range colour description (and no frame reordering)
check('SPS colour rewrite', Buffer.from(fixColour(hex('000000016742c01f8c8d4020030d00f08846a0'))).toString('hex') === '000000016742c01f8c8d4020030d3701010107844235');
check('non-SPS access unit untouched', fixColour(hex('0000000165b80004')).length === 8);
// ... one longer than any real SPS (the same with 5000 bytes after it) passed as it is: before, rewritten element by element (a
// server's of 16 MB took half a gigabyte of the page's memory)
{
  const big = hex('000000016742c01f8c8d4020030d00f08846a0' + '00'.repeat(5000));
  const out = fixColour(big);
  check('an SPS larger than any real one passed as it is', out.length === big.length && Buffer.from(out).equals(Buffer.from(big)), `${out.length} bytes`);
}

// SolidFill: the colour (B, G, R, XA) follows the surface id (Windows fills white areas with it; they came out red)
let fill;
new GfxPipeline((o) => { if (o.op === 'fill') fill = o; }).onData(hex('E004' + '0400 0000 18000000 0700 10203000 0100 0100 0200 0300 0400'));
check('SolidFill colour and rectangle', fill?.id === 7 && fill.color === 0xff102030 && JSON.stringify(fill.rects) === '[{"l":1,"t":2,"r":3,"b":4}]');

// RemoteFX progressive: a tile outside the region rectangles is skipped (it threw a RangeError while dragging windows on Windows)
const reg = Buffer.alloc(53);                    // region block: 1 rectangle (0,0 64x64), 1 quantizer, 1 simple tile at column 2
reg.writeUInt16LE(0xccc4, 0); reg.writeUInt32LE(53, 2); reg[6] = 64; reg.writeUInt16LE(1, 7); reg[9] = 1; reg.writeUInt16LE(1, 12); reg.writeUInt32LE(22, 14);
reg.writeUInt16LE(64, 22); reg.writeUInt16LE(64, 24); reg.fill(0x66, 26, 31);
reg.writeUInt16LE(0xccc5, 31); reg.writeUInt32LE(22, 33); reg.writeUInt16LE(2, 40);
let threw = '';
try { new ProgressiveDecoder().decode(1, new Uint8Array(reg), 256, 64); } catch (e) { threw = e.message; }
check('progressive: tile outside the rectangles', threw === '', threw);

// AVC444 (v1, v2) in full colour: the luma and the chroma streams each to a decoder of their own, their planes put
// together (MS-RDPEGFX 3.3.8.3.2) and checked against a second way of doing it, written as the protocol lays the
// auxiliary picture out, block by block (B4 to B9), and the chroma filter after it; the luma alone keeps the last chroma,
// the chroma alone the last luma; everything after waits for the pictures; NV12 as I420; a browser without the planes
// shows the luma picture (4:2:0), as before. Before, only the luma picture was shown: coloured text blurred.
{
  const { yuv444Rect } = await import('../public/assets/rdpgfx.js');
  const W = 32, H = 40, CH = 48;                 // the surface, and its coded height (a multiple of 16)
  let seed = 7;
  const rnd = () => (seed = (seed * 1103515245 + 12345) >>> 0) >>> 24;
  const pic = () => ({ Y: Uint8Array.from({ length: W * CH }, rnd), sy: W, U: Uint8Array.from({ length: W * CH / 4 }, rnd), su: W / 2,
    V: Uint8Array.from({ length: W * CH / 4 }, rnd), sv: W / 2, w: W, h: CH });
  const ref444 = (main, aux, v2) => {            // the whole surface, block by block
    const U = new Uint8Array(W * H), V = new Uint8Array(W * H);
    for (let y = 0; y < H; y++) for (let x = 0; x < W; x++) { U[y * W + x] = main.U[(y >> 1) * main.su + (x >> 1)]; V[y * W + x] = main.V[(y >> 1) * main.sv + (x >> 1)]; }
    if (aux) {
      if (!v2) {
        let uY = 0, vY = 0;
        for (let y = 0; y < H + 16 - H % 16; y++) {           // B4, B5: 8 rows of U, 8 of V, to the odd rows
          const [pos, dst] = y % 16 < 8 ? [2 * uY++ + 1, U] : [2 * vY++ + 1, V];
          if (pos >= H) continue;
          for (let x = 0; x < W; x++) dst[pos * W + x] = aux.Y[y * aux.sy + x];
        }
        for (let y = 0; y < H / 2; y++) {                     // B6, B7: the odd columns of the even rows
          for (let x = 0; x < W / 2; x++) { U[2 * y * W + 2 * x + 1] = aux.U[y * aux.su + x]; V[2 * y * W + 2 * x + 1] = aux.V[y * aux.sv + x]; }
        }
      } else {
        for (let y = 0; y < H; y++) {                         // B4, B5: the odd columns, U from the left half, V the right
          for (let x = 0; x < W / 2; x++) { U[y * W + 2 * x + 1] = aux.Y[y * aux.sy + x]; V[y * W + 2 * x + 1] = aux.Y[y * aux.sy + W / 2 + x]; }
        }
        for (let y = 0; y < H / 2; y++) {                     // B6 to B9: the even columns of the odd rows
          for (let x = 0; x < W / 4; x++) {
            const o = (2 * y + 1) * W;
            U[o + 4 * x] = aux.U[y * aux.su + x]; V[o + 4 * x] = aux.U[y * aux.su + W / 4 + x];
            U[o + 4 * x + 2] = aux.V[y * aux.sv + x]; V[o + 4 * x + 2] = aux.V[y * aux.sv + W / 4 + x];
          }
        }
      }
      for (const p of [U, V]) {                               // the filter: the sample the average stands for
        for (let y = 0; y + 1 < H; y += 2) {
          for (let x = 0; x + 1 < W; x += 2) {
            const a = p[y * W + x], f = 4 * a - p[y * W + x + 1] - p[(y + 1) * W + x] - p[(y + 1) * W + x + 1], c = Math.max(0, Math.min(255, f));
            if (Math.abs(c - a) >= 30) p[y * W + x] = c;
          }
        }
      }
    }
    const out = new Uint32Array(W * H);
    for (let i = 0; i < W * H; i++) {
      const Y = main.Y[Math.floor(i / W) * main.sy + i % W], d = V[i] - 128, e = U[i] - 128, cl = (v) => Math.max(0, Math.min(255, v));
      out[i] = (0xff000000 | (cl(Y + ((475 * e) >> 8)) << 16) | (cl(Y - ((48 * e + 120 * d) >> 8)) << 8) | cl(Y + ((403 * d) >> 8))) >>> 0;
    }
    return out;
  };
  const cut = (all, l, t, r, b) => { const o = []; for (let y = t; y < b; y++) for (let x = l; x < r; x++) o.push(all[y * W + x]); return o; };
  const same = (a, b) => a.length === b.length && a.every((v, i) => v === b[i]);
  const [M1, A1, M2, A2] = [pic(), pic(), pic(), pic()];
  const whole = [[M1, A1, false], [M1, A1, true], [M1, null, false]].map(([m, a, v2]) => same([...yuv444Rect(m, a, v2, 0, 0, W, H, W, H)], [...ref444(m, a, v2)]));
  const part = same([...yuv444Rect(M1, A1, true, 5, 3, 20, 30, W, H)], cut(ref444(M1, A1, true), 5, 3, 25, 33));

  // the pipeline, on a decoder double: each chunk's last byte names the picture it gives
  const pics = { 1: M1, 2: A1, 3: M2, 4: A2 };
  let fmt = 'I420', made = 0;
  const frames = [];
  const Dec0 = globalThis.VideoDecoder, Chunk0 = globalThis.EncodedVideoChunk;
  globalThis.EncodedVideoChunk = class { constructor(o) { this.data = o.data; } };
  globalThis.VideoDecoder = class {
    constructor(o) { this.o = o; made++; }
    configure() {}
    close() {}
    decode(c) {
      const p = pics[c.data[c.data.length - 1]], format = fmt;
      const f = { format, codedWidth: W, codedHeight: CH, closed: false, close() { this.closed = true; }, allocationSize: () => W * CH * 3 / 2,
        async copyTo(buf) {
          this.dst = buf;
          buf.set(p.Y.subarray(0, W * CH));
          if (format === 'I420') {
            buf.set(p.U, W * CH); buf.set(p.V, W * CH * 5 / 4);
            return [{ offset: 0, stride: W }, { offset: W * CH, stride: W / 2 }, { offset: W * CH * 5 / 4, stride: W / 2 }];
          }
          for (let i = 0; i < W * CH / 4; i++) { buf[W * CH + 2 * i] = p.U[i]; buf[W * CH + 2 * i + 1] = p.V[i]; }
          return [{ offset: 0, stride: W }, { offset: W * CH, stride: W }];
        } };
      frames.push(f);
      setTimeout(() => this.o.output(f), 0);
    }
  };
  const sps = '000000016742c01f8c8d4020030d00f08846a0';
  const meta = (rects, n) => {
    const b = Buffer.alloc(4 + rects.length * 10);
    b.writeUInt32LE(rects.length);
    rects.forEach(([l, t, r, bt], i) => { b.writeUInt16LE(l, 4 + i * 8); b.writeUInt16LE(t, 6 + i * 8); b.writeUInt16LE(r, 8 + i * 8); b.writeUInt16LE(bt, 10 + i * 8); });
    return b.toString('hex') + sps + '0000000165b800' + n.toString(16).padStart(2, '0');
  };
  const wire4 = (sid, codec, lc, s1, s2 = '') => {
    const d = Buffer.from((() => { const b = Buffer.alloc(4); b.writeUInt32LE(((lc << 30) | (lc === 0 ? s1.length / 2 : 0)) >>> 0); return b.toString('hex'); })() + s1 + s2, 'hex');
    const h = Buffer.alloc(25);
    h.writeUInt16LE(1, 0); h.writeUInt32LE(25 + d.length, 4); h.writeUInt16LE(sid, 8); h.writeUInt16LE(codec, 10); h[12] = 0x20;
    h.writeUInt16LE(W, 17); h.writeUInt16LE(H, 19); h.writeUInt32LE(d.length, 21);
    return hex('E004' + h.toString('hex') + d.toString('hex'));
  };
  const surface = (sid) => hex('E004' + '0900 0000 0f000000' + Buffer.from([sid, 0, W, 0, H, 0]).toString('hex') + '20');
  const ops = [];
  const p = new GfxPipeline((o) => ops.push(o));
  p.onData(surface(0));
  p.onData(surface(1));
  p.onData(wire4(0, 0x000e, 0, meta([[0, 0, W, H]], 1), meta([[0, 0, 16, 16]], 2)));            // v1, both streams
  p.onData(hex('E004' + '0400 0000 18000000 0000 10203000 0100 0000 0000 0100 0100'));          // a fill after it: waits
  p.onData(wire4(0, 0x000e, 1, meta([[8, 8, 24, 24]], 3)));                                       // the luma alone: the last chroma
  p.onData(wire4(0, 0x000e, 2, meta([[0, 16, 32, 40]], 4)));                                      // the chroma alone: the last luma
  fmt = 'NV12';
  p.onData(wire4(1, 0x000f, 1, meta([[0, 0, W, H]], 3)));                                         // v2, no chroma yet: 4:2:0
  p.onData(wire4(1, 0x000f, 0, meta([[4, 4, 12, 12]], 1), meta([[0, 0, W, H]], 2)));             // v2, both
  await p.idle();
  const px = ops.filter((o) => o.op === 'px');
  const want = [ref444(M1, A1, false), cut(ref444(M1, A1, false), 0, 0, 16, 16), cut(ref444(M2, A1, false), 8, 8, 24, 24), cut(ref444(M2, A2, false), 0, 16, 32, 40),
    ref444(M2, null, true), cut(ref444(M1, A1, true), 4, 4, 12, 12), ref444(M1, A1, true)];
  const got = px.map((o, i) => same([...o.px], [...want[i]]) && o.id === (i < 4 ? 0 : 1));
  const order = ops.filter((o) => ['px', 'fill'].includes(o.op)).map((o) => o.op).slice(0, 3).join();
  const madeBoth = made;
  // the memory of a stream's picture before the last one used again for its next picture (a buffer for each picture made
  // some 90 MB of garbage a second at 1920 x 1080 and 30 pictures a second)
  fmt = 'I420';
  const p3 = new GfxPipeline(() => {});
  p3.onData(surface(0));
  const lumaBufs = [];
  for (const n of [1, 3, 1, 3]) {
    p3.onData(wire4(0, 0x000e, 1, meta([[0, 0, W, H]], n)));
    await p3.idle();
    lumaBufs.push(frames.at(-1).dst);
  }
  const reused = lumaBufs[2] === lumaBufs[0] && lumaBufs[3] === lumaBufs[1] && lumaBufs[1] !== lumaBufs[0];
  // no planes from the browser: the luma picture through its rectangles, the chroma one closed
  fmt = null;
  ops.length = 0;
  const p2 = new GfxPipeline((o) => ops.push(o));
  p2.onData(surface(0));
  p2.onData(wire4(0, 0x000e, 0, meta([[0, 0, 16, 8], [16, 8, 32, 16]], 1), meta([[0, 0, W, H]], 2)));
  await p2.idle();
  const video = ops.filter((o) => o.op === 'video');
  const fallback = video.length === 3 && video[0].frame === frames.at(-2) && JSON.stringify(video[0].rects) === '[{"l":0,"t":0,"r":16,"b":8},{"l":16,"t":8,"r":32,"b":16}]'
    && !video[1].frame && !video[2].frame && frames.at(-1).closed;
  // a stream's rectangles overlapping, more pixels in all than the surface (a few KB brought 10,000 over a whole 1080p
  // surface, each converted on its own): their bounding box, converted once; one past the surface draws nothing
  fmt = 'I420';
  ops.length = 0;
  const p4 = new GfxPipeline((o) => ops.push(o));
  p4.onData(surface(0));
  p4.onData(wire4(0, 0x000e, 1, meta([...Array(1000).fill([0, 0, W, H]), [W, 0, W + 8, 8]], 1)));
  await p4.idle();
  const merged = ops.filter((o) => o.op === 'px');
  const capped = merged.length === 1 && [merged[0].x, merged[0].y, merged[0].w, merged[0].h].join() === `0,0,${W},${H}` && same([...merged[0].px], [...ref444(M1, null, false)]);
  check('AVC444: overlapping rectangles of a stream converted once, as their bounding box', capped, `${merged.length} conversions`);
  // an error drawing a message's pictures (here, its statistics) still frees its places in the order: the end of frame after
  // it went out never (no acknowledgement: the server sent nothing more)
  const swallow = () => {};                      // (before, the error was thrown out of a promise)
  process.on('unhandledRejection', swallow);
  ops.length = 0;
  const p5 = new GfxPipeline((o) => ops.push(o));
  p5.stats = new Proxy({}, { set(o, k, v) { if (k === 'avc444:luma only') throw new Error('broken'); o[k] = v; return true; } });
  p5.onData(surface(0));
  p5.onData(wire4(0, 0x000e, 1, meta([[0, 0, W, H]], 1)));
  p5.onData(hex('E004' + '0c00 0000 0c000000 07000000'));                                        // end of frame 7
  const went = await Promise.race([p5.idle().then(() => true), new Promise((r) => setTimeout(() => r(false), 500))]);
  process.off('unhandledRejection', swallow);
  check('AVC444: an error drawing a message still lets what follows go out', went && ops.some((o) => o.op === 'end' && o.frame === 7)
    && ops.some((o) => o.op === 'log' && o.msg === 'gfx: broken'), ops.map((o) => o.op).join());
  // H.264 messages of many rectangles (as reported against other clients: 100,000 from a few hundred bytes took a minute,
  // 6.7 million hours): their bounding box past 64, at once; AVC420's count checked against its data; without WebCodecs,
  // H.264 refused before anything is queued (it was never offered), what follows going out
  ops.length = 0;
  const p6 = new GfxPipeline((o) => ops.push(o));
  p6.onData(surface(0));
  const many = Buffer.alloc(4 + 100000 * 10);
  many.writeUInt32LE(100000);
  for (let i = 0; i < 100000; i++) { many.writeUInt16LE(W, 8 + i * 8); many.writeUInt16LE(H, 10 + i * 8); }
  const t0 = Date.now();
  p6.onData(wire4(0, 0x000e, 1, many.toString('hex') + sps + '0000000165b80001'));
  const a420 = Buffer.alloc(25 + 14);
  a420.writeUInt16LE(1, 0); a420.writeUInt32LE(25 + 14, 4); a420.writeUInt16LE(0, 8); a420.writeUInt16LE(0x000b, 10); a420[12] = 0x20;
  a420.writeUInt16LE(W, 17); a420.writeUInt16LE(H, 19); a420.writeUInt32LE(14, 21); a420.writeUInt32LE(1000, 25);
  p6.onData(hex('E004' + a420.toString('hex')));
  p6.onData(hex('E004' + '0c00 0000 0c000000 08000000'));                                        // end of frame 8
  const went6 = await Promise.race([p6.idle().then(() => true), new Promise((r) => setTimeout(() => r(false), 2000))]);
  check('H.264: many rectangles as their bounding box at once; AVC420\'s count past its data refused', went6 && Date.now() - t0 < 1500
    && ops.filter((o) => o.op === 'px').length === 1 && ops.some((o) => o.op === 'log' && /AVC420 metablock beyond its data/.test(o.msg))
    && ops.some((o) => o.op === 'end' && o.frame === 8), `${Date.now() - t0} ms ` + ops.map((o) => o.op).join());
  globalThis.VideoDecoder = Dec0;
  globalThis.EncodedVideoChunk = Chunk0;
  {
    globalThis.VideoDecoder = undefined;            // (a browser without WebCodecs)
    const seen = [];
    const p7 = new GfxPipeline((o) => seen.push(o));
    p7.onData(surface(0));
    p7.onData(wire4(0, 0x000e, 1, meta([[0, 0, W, H]], 1)));
    p7.onData(hex('E004' + '0c00 0000 0c000000 09000000'));                                      // end of frame 9
    const went7 = await Promise.race([p7.idle().then(() => true), new Promise((r) => setTimeout(() => r(false), 500))]);
    check('H.264 without WebCodecs: refused, what follows goes out', went7 && seen.some((o) => o.op === 'log' && /H\.264 not offered/.test(o.msg))
      && seen.some((o) => o.op === 'end' && o.frame === 9), seen.map((o) => o.op).join());
  }
  check('AVC444 in full colour: v1 and v2 put together as the protocol lays them out (also part of a surface), each stream on its own decoder, the luma alone with the last chroma and the chroma alone with the last luma, 4:2:0 before any chroma, NV12 as I420, everything after in order, the memory of the picture before the last one used again; without planes, the luma picture as before',
    whole.every(Boolean) && part && got.length === 7 && got.every(Boolean) && order === 'px,px,fill' && madeBoth === 4 && fallback && reused,
    JSON.stringify({ whole, part, got, n: px.length, order, madeBoth, fallback, video: video.length, reused }));
}

// MapSurfaceToScaledOutput (Windows with 10.x capabilities) places the surface like MapSurfaceToOutput (it showed a black screen)
let map;
new GfxPipeline((o) => { if (o.op === 'map') map = o; }).onData(hex('E004' + '1700 0000 1c000000 0300 0000 10000000 20000000 00040000 00030000'));
check('MapSurfaceToScaledOutput places the surface', map?.id === 3 && map.x === 16 && map.y === 32);

// codec vectors: <codec> <w> <h> <input> <expected RGB>; RemoteFX output may differ by 1 (rounding of FreeRDP's SIMD code)
for (const line of readFileSync(new URL('./fixtures/gfx-vectors.txt', import.meta.url), 'utf8').split('\n')) {
  if (!line || line[0] === '#') continue;
  const [codec, ws, hs, input, expected] = line.split(' ');
  const w = +ws, h = +hs, src = hex(input), ref = hex(expected);
  let px;
  if (codec === 'clear') px = new ClearCodec().decode(src, w, h).px;
  else if (codec === 'nsc') px = nscDecode(src, w, h);
  else if (codec === 'rfx') px = new RfxDecoder().decode(src, w, h);
  else if (codec === 'progressive') {
    px = new Uint32Array(w * h);
    const boxes = new ProgressiveDecoder().decode(1, src, w, h);
    for (const b of boxes) for (let y = 0; y < b.h; y++) px.set(b.px.subarray(y * b.w, (y + 1) * b.w), (b.y + y) * w + b.x);
    // the areas each box's tiles wrote ("parts", put on the page as they are, without blending): opaque inside, nothing
    // written outside (the page blended every box through a new canvas)
    let bad = 0, inParts = 0;
    for (const b of boxes) {
      const inside = new Uint8Array(b.w * b.h);
      for (let i = 0; i < (b.parts?.length ?? 0); i += 4) for (let y = 0; y < b.parts[i + 3]; y++) inside.fill(1, (b.parts[i + 1] + y) * b.w + b.parts[i], (b.parts[i + 1] + y) * b.w + b.parts[i] + b.parts[i + 2]);
      for (let i = 0; i < inside.length; i++) { inParts += inside[i]; if ((b.px[i] >>> 24) !== (inside[i] ? 255 : 0)) bad++; }
    }
    check(`progressive ${w}x${h}: the areas written listed, opaque, nothing outside`, boxes.every((b) => Array.isArray(b.parts)) && inParts > 0 && bad === 0, `${bad} pixels wrong`);
  }
  let max = 0;
  for (let i = 0; i < w * h; i++) for (let c = 0; c < 3; c++) max = Math.max(max, Math.abs(((px[i] >> (8 * c)) & 255) - ref[i * 3 + c]));
  const tolerance = codec === 'rfx' || codec === 'progressive' ? 1 : 0;
  check(`${codec} ${w}x${h}`, max <= tolerance, `max diff ${max}`);
}

// ------------------------------------------------------------------ corrupt or hostile data, robustness, reference streams

/** Runs fn(module) in a worker: 'returned', 'threw: message' or, after ms, 'hung' (an endless loop must not hang the tests). */
const inWorker = (fn, ms = 3000) => new Promise((resolve) => {
  const w = new Worker(`const { parentPort } = require('node:worker_threads');
    import(${JSON.stringify(new URL('../public/assets/rdpgfx.js', import.meta.url).href)}).then((m) => {
      try { (${fn})(m); parentPort.postMessage('returned'); } catch (e) { parentPort.postMessage('threw: ' + e.message); } });`, { eval: true });
  const t = setTimeout(() => { w.terminate(); resolve('hung'); }, ms);
  w.on('message', (m) => { clearTimeout(t); w.terminate(); resolve(m); });
});
const attempt = (fn) => { try { fn(); return ''; } catch (e) { return e.message; } };

// ZGFX: every kind of token (short and long literals, matches of each distance class with short, long and overlapping
// copies, literal runs) in compressed segments across the wrap of the 2.5 MB history, after uncompressed segments:
// the output must be the reference stream, built here byte by byte
{
  const ref = new Uint8Array(2600000);
  let len = 0, seed = 1;
  const rnd = () => (seed = (seed * 1103515245 + 12345) >>> 0) >>> 24;
  const DIST = [[5, 17, 5, 0], [5, 18, 7, 32], [5, 19, 9, 160], [5, 20, 10, 672], [5, 21, 12, 1696], [6, 44, 14, 5792], [6, 45, 15, 22176],
    [7, 92, 18, 54944], [7, 93, 20, 317088], [8, 188, 20, 1365664], [8, 189, 21, 2414240]];   // prefix bits, prefix, value bits, base
  const raw = (n) => {                            // uncompressed segments (multipart) of n bytes
    const segs = [Buffer.from([0xe1, Math.ceil(n / 65000), 0, n & 255, (n >> 8) & 255, (n >> 16) & 255, n >>> 24])];   // count, then the whole size
    for (let left = n; left > 0; left -= 65000) {
      const k = Math.min(65000, left), s = Buffer.alloc(5 + k);
      s.writeUInt32LE(k + 1, 0); s[4] = 0x04;
      for (let i = 0; i < k; i++) s[5 + i] = ref[len++] = rnd();
      segs.push(s);
    }
    return new Uint8Array(Buffer.concat(segs));
  };
  const coded = (tokens) => {                     // one compressed segment
    const bits = [], put = (v, n) => { for (let i = n - 1; i >= 0; i--) bits.push(Math.floor(v / 2 ** i) & 1); };
    for (const [kind, a, b] of tokens) {
      if (kind === 'lit') {
        if (a === 0x00) put(24, 5); else if (a === 0xff) put(54, 6); else { put(0, 1); put(a, 8); }
        ref[len++] = a;
      } else if (kind === 'match') {             // distance a, count b
        const d = DIST.findLast((t) => t[3] <= a);
        put(d[1], d[0]); put(a - d[3], d[2]);
        if (b === 3) put(0, 1);
        else { let k = 0; while (4 * 2 ** (k + 1) <= b) k++; put(2 ** (k + 2) - 2, k + 2); put(b - 4 * 2 ** k, k + 2); }
        for (let i = 0; i < b; i++, len++) ref[len] = ref[len - a];
      } else {                                   // literal run of a bytes, byte aligned
        put(17, 5); put(0, 5); put(a, 15);
        while (bits.length % 8) bits.push(0);
        for (let i = 0; i < a; i++) { const v = rnd(); put(v, 8); ref[len++] = v; }
      }
    }
    const pad = (8 - bits.length % 8) % 8, o = new Uint8Array(2 + (bits.length + pad) / 8 + 1);
    o[0] = 0xe0; o[1] = 0x24;
    bits.forEach((v, i) => { o[2 + (i >> 3)] |= v << (7 - (i & 7)); });
    o[o.length - 1] = pad;
    return o;
  };
  const counts = [3, 4, 7, 8, 15, 16, 300, 4096, 5, 65, 3];
  const z = new Zgfx(), messages = [raw(2495000),
    coded([['lit', 0x00], ['lit', 0xff], ['lit', 0x41], ['match', 1, 3], ['match', 1, 100], ['run', 20],
      ...DIST.map((d, i) => ['match', d[3] + 7 + i, counts[i]]), ['match', 2450000, 1000]]),
    coded([['match', 2400000, 2000], ['match', 3000, 500], ['lit', 7]])];
  const got = Buffer.concat(messages.map((m) => z.decompress(m)));
  check('ZGFX: all token kinds across the history wrap give the reference stream', len > 2500000 && got.equals(ref.subarray(0, len)));
}
// ... and a segment gives 65535 bytes at most (these 12 bytes asked for 16 MB)
check('ZGFX: oversized segment refused', attempt(() => new Zgfx().decompress(hex('e02420c43fffffc000000005'))) === 'ZGFX: segment too long');
// one buffer for a whole message, of the size it declares: 65535 empty compressed segments held 64 KB each (4 GB from a
// 400 KB message); a message declaring more than 64 MB, or segments past what it declared, refused
{
  const n = 65535, m = new Uint8Array(7 + n * 6);
  m[0] = 0xe1; m[1] = n & 255; m[2] = n >> 8;               // declares 0 bytes
  for (let i = 0; i < n; i++) { m[7 + i * 6] = 2; m[11 + i * 6] = 0x24; m[12 + i * 6] = 0; }   // segments of 2 bytes: flags and an empty bit stream
  const used0 = process.memoryUsage().arrayBuffers, out = new Zgfx().decompress(m), grew = process.memoryUsage().arrayBuffers - used0;
  const big = new Uint8Array([0xe1, 1, 0, 0, 0, 0, 8, 5, 0, 0, 0, 0x04, 1, 2, 3, 4]);   // declares 128 MB
  const past = new Uint8Array([0xe1, 1, 0, 2, 0, 0, 0, 5, 0, 0, 0, 0x04, 1, 2, 3, 4]);   // declares 2 bytes, brings 4
  check('ZGFX: one buffer of the declared size (thousands of empty segments keep nothing), over 64 MB or past it refused',
    out.length === 0 && grew < 1 << 24 && attempt(() => new Zgfx().decompress(big)) === 'ZGFX: 134217728 bytes'
    && attempt(() => new Zgfx().decompress(past)) === 'ZGFX: segment too long', JSON.stringify({ len: out.length, grew }));
}

// NSCodec plane and H.264 SPS that end early end with an error instead of looping forever
let r = await inWorker((m) => { const s = new Uint8Array(21); s[0] = 5; s[16] = 1; s[20] = 0x10; m.nscDecode(s, 16, 1); });
check('NSCodec: truncated plane', r === 'threw: NSCodec: bad run', r);
// ... and a run over the last 4 bytes of a plane, which are always raw (RangeError: offset is out of bounds)
const nr = new Uint8Array(27);
nr[0] = 7; nr[16] = 1; nr.set([5, 5, 4, 1, 2, 3, 4], 20);
check('NSCodec: run over the last 4 bytes', attempt(() => nscDecode(nr, 8, 1)) === 'NSCodec: bad run');
r = await inWorker((m) => m.fixColour(Uint8Array.of(0, 0, 0, 1, 0x67, 0x42, 0xc0, 0x1f, 0, 0)));
check('H.264: SPS cut short', r === 'threw: SPS: bad Exp-Golomb code', r);

// ClearCodec: a band is 52 rows at most (each vBar of a 65536-row band kept 256 KB in the cache)
const band = Buffer.alloc(14 + 11 + 2 * 200);
band.writeUInt32LE(11 + 2 * 200, 6); band.writeUInt16LE(199, 16); band.writeUInt16LE(65535, 20);
check('ClearCodec: band of 65536 rows refused', attempt(() => new ClearCodec().decode(new Uint8Array(band), 8, 8)) === 'ClearCodec: bad band height');

// pieces starting right of the destination rectangle are left out (they threw RangeError): a RemoteFX tile, a ClearCodec subcodec
const ts = Buffer.alloc(46);
ts.writeUInt16LE(0xccc7, 0); ts.writeUInt32LE(46, 2); ts[14] = 1; ts.writeUInt16LE(1, 16); ts.fill(0x66, 22, 27);
ts.writeUInt16LE(0xcac3, 27); ts.writeUInt32LE(19, 29); ts.writeUInt16LE(2, 36);    // one tile, column 2 (x = 128)
check('RemoteFX: tile beyond the right edge', attempt(() => new RfxDecoder().decode(new Uint8Array(ts), 64, 64)) === '');
const sub = Buffer.alloc(30);
sub.writeUInt32LE(16, 10); sub.writeUInt16LE(10, 14); sub.writeUInt16LE(7, 16); sub.writeUInt16LE(1, 18); sub.writeUInt16LE(1, 20); sub.writeUInt32LE(3, 22);
check('ClearCodec: subcodec beyond the right edge', attempt(() => new ClearCodec().decode(new Uint8Array(sub), 8, 8)) === '');
// ... and one that does not fit in it, as FreeRDP (its size, from the data, asked for gigabytes): it was drawn cut
const sub9 = Buffer.alloc(54, 0xff);
sub9.fill(0, 0, 27); sub9.writeUInt32LE(40, 10); sub9.writeUInt16LE(9, 18); sub9.writeUInt16LE(1, 20); sub9.writeUInt32LE(27, 22);
check('ClearCodec: subcodec not within the rectangle left out', new ClearCodec().decode(new Uint8Array(sub9), 8, 8).px.every((v) => v === 0));
// an NSCodec subcodec is opaque whatever its alpha plane says (NSCodec alone keeps it), in its place in the rectangle
{
  const nsc = [2, 0, 0, 0, 2, 0, 0, 0, 2, 0, 0, 0, 2, 0, 0, 0, 1, 0, 0, 0, 100, 200, 0, 0, 0, 0, 0, 7];   // 2 x 1, raw planes Y Co Cg A
  const msg = Uint8Array.from([0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 13 + nsc.length, 0, 0, 0, 1, 0, 1, 0, 2, 0, 1, 0, nsc.length, 0, 0, 0, 1, ...nsc]);
  const got = [...new ClearCodec().decode(msg, 4, 2).px].map((v) => v.toString(16)), alone = [...nscDecode(Uint8Array.from(nsc), 2, 1)].map((v) => v.toString(16));
  check('ClearCodec: NSCodec subcodec opaque', got.join() === '0,0,0,0,0,ff646464,ffc8c8c8,0' && alone.join() === '646464,7c8c8c8', `${got} | ${alone}`);
}
// a RemoteFX quantizer index past the list sent: an error of the decoder (TypeError before)
const tq = Buffer.from(ts);
tq.writeUInt16LE(0, 36); tq[33] = 1;                                                 // the tile at column 0, with quantizer 1 of 1
check('RemoteFX: quantizer index past the list', attempt(() => new RfxDecoder().decode(new Uint8Array(tq), 64, 64)) === 'RemoteFX: bad quantizer index');

// RemoteFX progressive
/** RFX_PROGRESSIVE_REGION: rectangles [x, y, w, h], quantizers (5 bytes each), progressive quantizers (16), flags, tile blocks */
const region = (rects, quant, progQuant, flags, tiles, count = tiles.length) => {   // (a progressive region block)
  const t = Buffer.concat(tiles), r = Buffer.alloc(18 + 8 * rects.length);
  r.writeUInt16LE(0xccc4, 0); r[6] = 64; r.writeUInt16LE(rects.length, 7); r[9] = quant.length / 5; r[10] = progQuant.length / 16; r[11] = flags;
  r.writeUInt16LE(count, 12); r.writeUInt32LE(t.length, 14);
  rects.forEach((v, i) => v.forEach((n, k) => r.writeUInt16LE(n, 18 + 8 * i + 2 * k)));
  const b = Buffer.concat([r, Buffer.from(quant), Buffer.from(progQuant), t]);
  b.writeUInt32LE(b.length, 2);
  return new Uint8Array(b);
};
/** Tile block: type, quantizer index of the three components, column, row, rest of the header and data (blockLen by default its size) */
const tile = (type, q, x, y, rest, len) => {
  const b = Buffer.alloc(13 + rest.length);
  b.writeUInt16LE(type, 0); b[6] = b[7] = b[8] = q; b.writeUInt16LE(x, 9); b.writeUInt16LE(y, 11); Buffer.from(rest).copy(b, 13);
  b.writeUInt32LE(len ?? b.length, 2);
  return b;
};
const q6 = Array(5).fill(0x66), simple = (x, y, len) => tile(0xccc5, 0, x, y, Array(9).fill(0), len);
// tiles outside the surface are neither decoded nor kept (48 KB each, without limit)
let pd = new ProgressiveDecoder();
pd.decode(1, region([[0, 0, 64, 64]], q6, [], 1, Array.from({ length: 50 }, (_, i) => simple(100 + i, 900))), 64, 64);
check('progressive: tiles outside the surface not kept', pd.surfaces.get(1).size === 0, `kept ${pd.surfaces.get(1).size}`);
// the pixels a message makes the page keep: never more than the surface. 20 regions of 65 rectangles of 1 x 1 in opposite
// corners: before, counted as 65 pixels each while bounded() made each a box of the whole surface (20 surfaces of pixels)
{
  const corners = Array.from({ length: 65 }, (_, i) => (i % 2 ? [1023, 767, 1, 1] : [0, 0, 1, 1]));
  const msg = Buffer.concat(Array.from({ length: 20 }, () => Buffer.from(region(corners, q6, [], 0, []))));
  const boxes = new ProgressiveDecoder().decode(1, new Uint8Array(msg), 1024, 768);
  const kept = boxes.reduce((n, b) => n + b.px.length, 0);
  check('progressive: a message keeps no more pixels than its surface', kept <= 1024 * 768, `${kept} pixels for ${1024 * 768}`);
}
// a tile block shorter than its header ends the message (with length 0 it was decoded numTiles times, up to 65535)
let t0 = performance.now();
new ProgressiveDecoder().decode(1, region([[0, 0, 64, 64]], q6, [], 1, [simple(0, 0, 0)], 65535), 64, 64);
const ts0 = Buffer.from(ts);
ts0.writeUInt16LE(65535, 16); ts0.writeUInt32LE(0, 29); ts0.writeUInt16LE(0, 36);
new RfxDecoder().decode(new Uint8Array(ts0), 64, 64);
let ms = performance.now() - t0;
check('RemoteFX and progressive: tile of length 0', ms < 500, `${ms.toFixed(0)} ms`);
// upgrade pass of 30 bits (first pass quantizer 15 + 15, upgrade quantizer 0): the magnitude stops with the SRL data
pd = new ProgressiveDecoder();
const q15 = [...Array(5).fill(0xff), ...Array(5).fill(0)], p15 = [0, ...Array(15).fill(0xff)];
pd.decode(1, region([[0, 0, 64, 64]], q15, p15, 1, [tile(0xccc6, 0, 0, 0, Array(10).fill(0))]), 64, 64);
t0 = performance.now();
pd.decode(1, region([[0, 0, 64, 64]], q15, p15, 1, [tile(0xccc7, 1, 0, 0, [0xff, 1, 0, 0, 0, 1, 0, 0, 0, 1, 0, 0, 0, 0x80, 0x80, 0x80])]), 64, 64);
ms = performance.now() - t0;
check('progressive: upgrade pass of 30 bits', ms < 500, `${ms.toFixed(0)} ms`);
// without reduce-extrapolate, upgrade passes use the plain band layout (LL3 = coefficients 4032..4095)
pd = new ProgressiveDecoder();
const p1 = [50, ...Array(15).fill(0x11)];
pd.decode(1, region([[0, 0, 64, 64]], q6, p1, 0, [tile(0xccc6, 0, 0, 0, Array(10).fill(0))]), 64, 64);
pd.decode(1, region([[0, 0, 64, 64]], q6, p1, 0, [tile(0xccc7, 0, 0, 0, [0xff, 16, 0, 16, 0, ...Array(8).fill(0), ...Array(16).fill(0), ...Array(16).fill(0xff)])]), 64, 64);
const changed = [...pd.surfaces.get(1).get(0).current[0].keys()].filter((j) => pd.surfaces.get(1).get(0).current[0][j]);
check('progressive: plain upgrade pass', changed.length === 64 && changed[0] === 4032, `${changed[0]}..${changed.at(-1)}`);
// rectangles in opposite corners of 1920x1080 give a box each, opaque (written without blending), not one box of the whole
// screen (8 MB, almost all transparent); rectangles that fill their bounding box still give that one box
const boxes = (rects, tiles) => JSON.stringify(new ProgressiveDecoder().decode(1, region(rects, q6, [], 1, tiles), 1920, 1080)
  .map((b) => [b.x, b.y, b.w, b.h, b.px.every((v) => v >>> 24 === 255)]));
let got = boxes([[0, 0, 64, 64], [1856, 1016, 64, 64]], [simple(0, 0), simple(29, 15), simple(29, 16)]);
check('progressive: rectangles far apart, a box each', got === '[[0,0,64,64,true],[1856,1016,64,64,true]]', got);
got = boxes([[0, 0, 128, 64], [0, 64, 128, 64]], [simple(0, 0), simple(1, 0), simple(0, 1), simple(1, 1)]);
check('progressive: rectangles filling their bounding box, one box', got === '[[0,0,128,128,true]]', got);
// progressive dequantization, first and differential (RFX_TILE_DIFFERENCE) passes, plain and reduce-extrapolate layouts, a
// different quantizer for each band: only the values the RLGR decoder wrote (and LL3, made whole) are visited; the
// coefficients kept must be those of every band shifted in full, as before
{
  const PLAIN = [[4032, 64], [3840, 64], [3904, 64], [3968, 64], [3072, 256], [3328, 256], [3584, 256], [0, 1024], [1024, 1024], [2048, 1024]];
  const EXTRA = [[4015, 81], [3807, 72], [3879, 72], [3951, 64], [3007, 272], [3279, 272], [3551, 256], [0, 1023], [1023, 1023], [2046, 961]];
  const quant = [0x32, 0x54, 0x76, 0x98, 0x2a], shifts = [1, 2, 3, 4, 5, 6, 7, 8, 9, 1];   // nibbles - 1: LL3 HL3 LH3 HH3 HL2 ...
  /** RLGR1 encoding of 4096 coefficients (MS-RDPRFX 3.1.8.1.7.3, as the decoder adapts k and kr). */
  const rlgr1 = (coef) => {
    const bits = [], put = (v, n) => { for (let i = n - 1; i >= 0; i--) bits.push((v >> i) & 1); };
    let k = 1, kp = 8, kr = 1, krp = 8;
    const gr = (v) => {
      const vk = v >> kr;
      for (let i = 0; i < vk; i++) bits.push(1);
      bits.push(0);
      put(v & ((1 << kr) - 1), kr);
      if (vk === 0) krp = Math.max(0, krp - 2); else if (vk !== 1) krp = Math.min(80, krp + vk);
      kr = krp >> 3;
    };
    for (let i = 0; i < 4096;) {
      if (k) {                                    // run of zeros, then a value
        let run = 0;
        while (i + run < 4096 && !coef[i + run]) run++;
        if (i + run === 4096) break;              // trailing zeros: none sent
        i += run;
        while (run >= 1 << k) { bits.push(0); run -= 1 << k; kp = Math.min(80, kp + 4); k = kp >> 3; }
        bits.push(1); put(run, k); bits.push(coef[i] < 0 ? 1 : 0);
        gr(Math.abs(coef[i++]) - 1);
        kp = Math.max(0, kp - 6); k = kp >> 3;
      } else {
        const v = coef[i++], m = v < 0 ? -2 * v - 1 : 2 * v;
        gr(m);
        kp = m ? Math.max(0, kp - 3) : Math.min(80, kp + 3); k = kp >> 3;
      }
    }
    while (bits.length & 7) bits.push(0);
    return Array.from({ length: bits.length >> 3 }, (_, i) => bits.slice(i * 8, i * 8 + 8).reduce((a, b) => a * 2 + b, 0));
  };
  let seed = 11;
  const rnd = (n) => { seed = (seed * 1103515245 + 12345) >>> 0; return (seed >>> 8) % n; };
  const coefs = (ll) => Int16Array.from({ length: 4096 }, (_, j) => (j >= ll || !rnd(40) ? rnd(81) - 40 : 0));
  const results = [];
  for (const extrapolate of [1, 0]) {
    const bands = extrapolate ? EXTRA : PLAIN, pd = new ProgressiveDecoder(), want = [0, 1, 2].map(() => new Int16Array(4096));
    for (const diff of [0, 1, 1]) {
      const values = [0, 1, 2].map(() => coefs(bands[0][0])), data = values.map(rlgr1);
      const rest = [diff, 0xff, ...data.flatMap((d) => [d.length & 255, d.length >> 8]), 0, 0, ...data.flat()];
      pd.decode(1, region([[0, 0, 64, 64]], quant, [], extrapolate, [tile(0xccc6, 0, 0, 0, rest)]), 64, 64);
      values.forEach((b, c) => {
        if (!rlgrDecode(Uint8Array.from(data[c]), 1, new Int16Array(4096)).every((v, j) => v === b[j])) results.push(false);   // (the encoder above)
        for (let j = bands[0][0] + 1; j < 4096; j++) b[j] += b[j - 1];
        bands.forEach(([off, len], i) => { for (let j = off; j < off + len; j++) want[c][j] = (diff ? want[c][j] : 0) + (b[j] << shifts[i]); });
      });
      const got = pd.surfaces.get(1).get(0).current;
      results.push(want.every((w, c) => w.every((v, j) => v === got[c][j])));
    }
  }
  check('progressive: dequantization of first and differential passes, both layouts', results.every(Boolean), results.join());
}

// reduce-extrapolate inverse DWT: a level whose three high bands are all 0 is interpolated from its LL band (most tiles of
// an animation at level 1); the result must be the whole transform's (written out here as the decoder did it before), with
// LL only, LL at the int16 limits, a single coefficient at each end of each level's high bands, and a few at random
{
  const clamp = (v) => (v < -32768 ? -32768 : v > 32767 ? 32767 : v), half = (v) => (v + (v >>> 31)) >> 1;
  const line = (L, lo, ls, H, ho, hs, O, oo, os, nL, nH) => {
    const l0 = lo;
    let H0 = H[ho], X0 = clamp(L[lo] - H0), X2 = X0;
    for (let j = 1; j < nH; j++, oo += 2 * os) {
      lo += ls; ho += hs;
      const H1 = H[ho];
      X2 = clamp(L[lo] - half(H0 + H1));
      O[oo] = X0; O[oo + os] = clamp(half(X0 + X2) + 2 * H0);
      X0 = X2; H0 = H1;
    }
    O[oo] = X2;
    if (nL <= nH) { O[oo + os] = clamp(X2 + 2 * H0); return; }
    X0 = clamp(L[l0 + nH * ls] - (nL === nH + 1 ? H0 : half(H0)));
    O[oo + os] = clamp(half(X0 + X2) + 2 * H0); O[oo + 2 * os] = X0;
    if (nL > nH + 1) O[oo + 3 * os] = clamp(half(X0 + L[l0 + (nH + 1) * ls]));
  };
  const whole = (b) => {
    const t = new Int16Array(4096);
    for (const [off, level] of [[3807, 3], [3007, 2], [0, 1]]) {
      const nL = (64 >> level) + 1, nH = level === 1 ? 31 : (64 + (1 << (level - 1))) >> level, W = nL + nH;
      const LH = off + nH * nL, HH = LH + nL * nH, LL = HH + nH * nH;
      for (let r = 0; r < nL; r++) line(b, LL + r * nL, 1, b, off + r * nH, 1, t, r * W, 1, nL, nH);
      for (let r = 0; r < nH; r++) line(b, LH + r * nL, 1, b, HH + r * nH, 1, t, (nL + r) * W, 1, nL, nH);
      for (let c = 0; c < W; c++) line(t, c, W, t, nL * W + c, W, b, off + c, W, nL, nH);
    }
    return b;
  };
  let seed = 7;
  const rnd = (n) => { seed = (seed * 1103515245 + 12345) >>> 0; return (seed >>> 8) % n; };
  const cases = [];
  const ll = () => { const b = new Int16Array(4096); for (let j = 4015; j < 4096; j++) b[j] = rnd(4000) - 2000; return b; };
  cases.push(ll(), Int16Array.from({ length: 4096 }, (_, j) => (j < 4015 ? 0 : j & 1 ? 32767 : -32768)));
  for (const j of [0, 1, 3005, 3006, 3007, 3008, 3805, 3806, 3807, 3808, 4013, 4014]) { const b = ll(); b[j] = rnd(2) ? 1 : -300; cases.push(b); }
  for (let k = 0; k < 20; k++) { const b = ll(), [from, to] = [[0, 3007], [3007, 3807], [3807, 4015]][k % 3]; for (let i = rnd(4); i >= 0; i--) b[from + rnd(to - from)] = rnd(600) - 300; cases.push(b); }
  const bad = cases.filter((b) => { const got = b.slice(), want = whole(b.slice()); idwtExtrapolate(got); return got.some((v, j) => v !== want[j]); });
  check('progressive: levels with no high bands interpolated, as the whole transform', bad.length === 0, `${bad.length} of ${cases.length} differ`);
}

// quantizer index or quality past the lists sent: an error of the decoder (TypeError before)
const progErr = (t) => attempt(() => new ProgressiveDecoder().decode(1, region([[0, 0, 64, 64]], q6, [], 1, [t]), 64, 64));
got = [progErr(tile(0xccc5, 1, 0, 0, Array(9).fill(0))), progErr(tile(0xccc6, 0, 0, 0, [0, 5, ...Array(8).fill(0)]))].join(' | ');
check('progressive: quantizer index or quality past the lists', got === 'RemoteFX progressive: bad quantizer index | RemoteFX progressive: bad quantizer index', got);

// pipeline: one faulty PDU does not take the ones after it (the end of frame above all); H.264 queue never stalls
const pdu = (cmd, body) => { const h = Buffer.alloc(8); h.writeUInt16LE(cmd, 0); h.writeUInt32LE(8 + body.length, 4); return Buffer.concat([h, Buffer.from(body)]); };
const msg = (...pdus) => new Uint8Array(Buffer.concat([Buffer.from([0xe0, 0x04]), ...pdus]));
const create = (id) => pdu(9, [id, 0, 64, 0, 64, 0, 0x20]), end = (f) => pdu(12, [f, 0, 0, 0]);
const w2s = (id, codec, data) => {
  const b = Buffer.alloc(17);
  b.writeUInt16LE(id, 0); b.writeUInt16LE(codec, 2); b[4] = 0x20; b.writeUInt16LE(64, 9); b.writeUInt16LE(64, 11); b.writeUInt32LE(data.length, 13);
  return pdu(1, Buffer.concat([b, data]));
};
const badClear = Buffer.alloc(27);
badClear.writeUInt32LE(13, 10); badClear.writeUInt16LE(8, 18); badClear.writeUInt16LE(8, 20); badClear[26] = 3;   // subcodec id 3
let ops = [];
attempt(() => new GfxPipeline((o) => ops.push(o.op)).onData(msg(create(1), w2s(1, 8, badClear), end(5))));
check('a faulty PDU does not drop the rest of the message', ops.join() === 'create,log,end', ops.join());

// uncompressed pixels (B, G, R, A, read a word at a time): alpha kept on an ARGB surface, 255 on the others; data cut in
// the middle of a pixel gives what the bytes there say (the missing ones 0)
{
  const px = [];
  const raw3 = (id) => { const b = Buffer.alloc(17); b.writeUInt16LE(id, 0); b[4] = 0x20; b.writeUInt16LE(3, 9); b.writeUInt16LE(1, 11); b.writeUInt32LE(10, 13); return pdu(1, Buffer.concat([b, Buffer.from([1, 2, 3, 4, 5, 6, 7, 8, 9, 10])])); };
  new GfxPipeline((o) => { if (o.op === 'px') px.push([...o.px].map((v) => v.toString(16)).join(' ')); })
    .onData(msg(pdu(9, [1, 0, 3, 0, 1, 0, 0x20]), pdu(9, [2, 0, 3, 0, 1, 0, 0x21]), raw3(1), raw3(2)));
  check('uncompressed pixels, with and without alpha, data cut short', px.join(' | ') === 'ff010203 ff050607 ff090a00 | 4010203 8050607 90a00', px.join(' | '));
}

// sizes from the data: surfaces and output up to 32766 a side (RDP), each rectangle within its surface (one of 65535 x 65535
// asked for 16 GB; with right < left it threw RangeError)
ops = [];
const logs = [], le32 = (v) => [v & 255, (v >> 8) & 255, (v >> 16) & 255, v >>> 24];
const surface = (id, w, h) => pdu(9, [id, 0, w & 255, w >> 8, h & 255, h >> 8, 0x20]);
const raw = (l, t, r, b) => { const x = Buffer.alloc(17); x.writeUInt16LE(2, 0); x[4] = 0x20; [l, t, r, b].forEach((v, i) => x.writeUInt16LE(v, 5 + 2 * i)); return pdu(1, x); };
new GfxPipeline((o) => { ops.push(o.op); if (o.op === 'log') logs.push(o.msg); }).onData(msg(surface(1, 40000, 10), pdu(14, [...le32(40000), ...le32(10), ...le32(0)]),
  surface(2, 64, 64), raw(10, 0, 5, 4), raw(0, 0, 65, 4), raw(0, 0, 4, 4)));
check('surfaces, output and rectangles within the limits', ops.join() === 'log,log,create,log,log,px' && logs.join(' | ') ===
  'gfx: GFX: surface of 40000 x 10 | gfx: GFX: output of 40000 x 10 | gfx: GFX: rectangle outside the surface | gfx: GFX: rectangle outside the surface', `${ops} ${logs.join(' | ')}`);

globalThis.VideoDecoder = class {               // as WebCodecs: a key frame first, then one frame per chunk; close() ends it all silently
  constructor(o) { Object.assign(this, { o, key: false, closed: false }); }
  configure() {}
  decode(c) {
    if (!this.key && c.type !== 'key') throw new Error('A key frame is required after configure() or flush().');
    this.key = true;
    setTimeout(() => { if (!this.closed) this.o.output({ close() {} }); }, 1);
  }
  close() { this.closed = true; }
};
globalThis.EncodedVideoChunk = class { constructor(o) { Object.assign(this, o); } };
const avc = (id, au) => { const meta = Buffer.alloc(14); meta.writeUInt32LE(1); meta.writeUInt16LE(64, 8); meta.writeUInt16LE(64, 10); return w2s(id, 0x0b, Buffer.concat([meta, au])); };
const key = (n) => Buffer.concat([hex('000000016742c01f8c8d4020030d00f08846a0 0000000168ce3c80 0000000165'), Buffer.alloc(n, 0x55)]);
const delta = Buffer.concat([hex('0000000141'), Buffer.alloc(3000, 0x55)]);
const acks = [];
let shown = 0;
const pipeline = () => { acks.length = shown = 0; return new GfxPipeline((o) => { if (o.op === 'end') acks.push(o.frame); if (o.frame?.close) shown++; }); };
let gp = pipeline();
attempt(() => gp.onData(msg(create(1), avc(1, key(200000)), end(1))));             // its NAL is too long to spread into push()
for (let f = 2; f <= 4; f++) attempt(() => gp.onData(msg(avc(1, delta), end(f))));
await new Promise((res) => setTimeout(res, 50));
check('H.264: key frame of 200 KB', acks.join() === '1,2,3,4' && shown === 4, `acks ${acks.join()}, frames ${shown}`);
gp = pipeline();
attempt(() => gp.onData(msg(create(1), avc(1, Buffer.concat([hex('000000016742c01f8c8d4020030d00f08846a0'), delta])), end(1))));   // SPS, no IDR
attempt(() => gp.onData(msg(avc(1, key(1000)), end(2))));
await new Promise((res) => setTimeout(res, 50));
check('H.264: decoder refusing the first frame', acks.join() === '1,2' && shown === 1, `acks ${acks.join()}, frames ${shown}`);
gp = pipeline();
attempt(() => gp.onData(msg(create(1), avc(1, key(1000)), end(1))));
attempt(() => gp.onData(msg(pdu(10, [1, 0]), create(2), end(2))));                 // deleted while its frame is decoding
await new Promise((res) => setTimeout(res, 50));
check('H.264: surface deleted while its frame decodes', acks.join() === '1,2', acks.join());
// a recording made into a video: idle() once every frame given was decoded and went out (its picture is taken then), at once
// with nothing waiting; decoded in software there (every picture out at once)
{
  const Dec0 = globalThis.VideoDecoder;
  let configured = null;
  globalThis.VideoDecoder = class extends Dec0 { configure(c) { configured = c; } };
  gp = pipeline();
  gp.software = true;
  attempt(() => gp.onData(msg(create(1), avc(1, key(1000)), end(1))));
  attempt(() => gp.onData(msg(avc(1, delta), end(2))));
  const before = shown;
  const idled = await Promise.race([gp.idle().then(() => true), new Promise((r) => setTimeout(() => r(false), 2000))]);   // (never: said, not waited for)
  const after = shown;
  const empty = await Promise.race([gp.idle().then(() => 'at once'), new Promise((r) => setTimeout(() => r('waited'), 50))]);
  globalThis.VideoDecoder = Dec0;
  check('H.264: idle once every frame given went out, at once with none; decoded in software for a video', idled && before === 0 && after === 2 && empty === 'at once'
    && configured?.hardwareAcceleration === 'prefer-software', JSON.stringify({ idled, before, after, empty, configured }));
}
// an SPS in the last bytes of an access unit gives no codec string (it read past the end: TypeError)
const avcLogs = [];
new GfxPipeline((o) => { if (o.op === 'log') avcLogs.push(o.msg); }).onData(msg(create(1), avc(1, hex('0000000167 42'))));
check('H.264: SPS in the last bytes', avcLogs.length === 0, avcLogs.join());

// what a server can make the page hold: all the surfaces within 4 times the output (at least 1920 x 1080: a surface for each
// monitor and a few more), the bitmap cache within 100 MB (each slot a rectangle inside its surface), an output of at most
// 8192 x 8192 pixels; before, any number of surfaces of 32766 x 32766 and 65,535 cache slots of any size
{
  const ops = [], logs = [];
  const g = new GfxPipeline((o) => { ops.push(o.op); if (o.op === 'log') logs.push(o.msg); });
  const reset = (w, h) => pdu(14, [...le32(w), ...le32(h), ...le32(0)]);
  const s2c = (id, slot, l, t, r, b) => { const x = Buffer.alloc(20); x.writeUInt16LE(id, 0); x.writeUInt16LE(slot, 10); [l, t, r, b].forEach((v, i) => x.writeUInt16LE(v, 12 + 2 * i)); return pdu(6, x); };
  const evict = (slot) => pdu(8, [slot & 255, slot >> 8]);
  g.onData(msg(reset(1920, 1080), surface(1, 1920, 1080), surface(2, 1920, 1080), surface(3, 1920, 1080), surface(4, 1920, 1080),
    surface(1, 1920, 1080), surface(5, 1920, 1080)));
  const surfaces = [ops.join(), logs.join()];
  ops.length = logs.length = 0;
  g.onData(msg(reset(4096, 4096), surface(6, 4096, 4096), s2c(6, 1, 0, 0, 4096, 4096), s2c(6, 2, 0, 0, 4096, 4096), evict(1), s2c(6, 2, 0, 0, 4096, 4096),
    s2c(6, 2, 0, 0, 4096, 4096), s2c(6, 3, 0, 0, 4097, 10), s2c(6, 3, 10, 0, 5, 10), s2c(99, 3, 0, 0, 1, 1), reset(8192, 8193)));
  check('GFX: surfaces within 4 times the output, bitmap cache within 100 MB and inside its surface, output up to 8192 x 8192',
    surfaces[0] === 'reset,create,create,create,create,create,log' && surfaces[1] === 'gfx: GFX: surfaces of 10368000 pixels'
    && ops.join() === 'reset,create,s2c,log,evict,s2c,s2c,log,log,log,log' && logs.join(' | ') === 'gfx: GFX: bitmap cache over 100 MB | '
      + 'gfx: GFX: cache rectangle outside its surface | gfx: GFX: cache rectangle outside its surface | gfx: GFX: cache rectangle outside its surface | gfx: GFX: output of 8192 x 8193',
    JSON.stringify({ surfaces, ops, logs }));
}

// ZGFX: a match reaches 2.5 MB back at most, the history (its tokens reach 33 MB: it read before the ring, giving zeros)
check('ZGFX: match beyond the history refused', attempt(() => new Zgfx().decompress(hex('e024bf0000000006'))) === 'ZGFX: match beyond the history');

// H.264: a VUI that says nothing of reordering (here with chroma location, timing and HRD, no bitstream_restriction) gets
// "no reordering" too, where it goes, its fields kept (a decoder may hold each frame until later ones come, and none comes
// before it is acknowledged)
{
  const bits = (...f) => f.flatMap(([n, v]) => Array.from({ length: n }, (_, i) => Math.floor(v / 2 ** (n - 1 - i)) & 1));
  const ue = (v) => { const z = Math.floor(Math.log2(v + 1)); return [[z, 0], [z + 1, v + 1]]; };
  const esc = (r) => { const o = []; let z = 0; for (const b of r) { if (z >= 2 && b <= 3) { o.push(3); z = 0; } o.push(b); z = b ? 0 : z + 1; } return o; };
  const au = (b) => {                             // SPS NAL unit: the RBSP, its stop bit, escaped
    b = [...b, 1];
    while (b.length & 7) b.push(0);
    return Buffer.from([0, 0, 0, 1, 0x67, ...esc(Array.from({ length: b.length / 8 }, (_, i) => b.slice(i * 8, i * 8 + 8).reduce((a, x) => a * 2 + x, 0)))]);
  };
  // the SPS above up to vui_parameters_present_flag; the rest of a VUI: chroma location, timing, NAL HRD (one CPB), no VCL
  // HRD, low_delay_hrd_flag, pic_struct_present_flag
  const head = [[8, 66], [8, 0xc0], [8, 31], ...ue(0), ...ue(11), ...ue(0), ...ue(12), ...ue(1), [1, 0], ...ue(63), ...ue(47), [1, 1], [1, 1], [1, 0], [1, 1]];
  const rest = [[1, 1], ...ue(0), ...ue(1), [1, 1], [32, 1], [32, 60], [1, 0], [1, 1], ...ue(0), [4, 2], [4, 3], ...ue(9999), ...ue(4999), [1, 1],
    [5, 23], [5, 23], [5, 23], [5, 24], [1, 0], [1, 0], [1, 0]];
  const bt709 = [[1, 1], [3, 5], [1, 1], [1, 1], [8, 1], [8, 1], [8, 1]], none = [[1, 1], [1, 1], ...ue(2), ...ue(1), ...ue(16), ...ue(16), ...ue(0), ...ue(1)];
  const got = Buffer.from(fixColour(au(bits(...head, [1, 0], [1, 0], [1, 0], ...rest, [1, 0])))).toString('hex');
  const want = au(bits(...head, [1, 0], [1, 0], ...bt709, ...rest, ...none)).toString('hex');
  check('SPS colour rewrite: no reordering added to a VUI without it', got === want, `${got} | ${want}`);
}

// diagnostic mode: a WireToSurface1 too short to name its codec, last in the message, counted without one (its codec was
// read outside the PDU's own error handling: the message threw, its other PDUs lost)
{
  const ops = [], g = new GfxPipeline((o) => ops.push(o.op));
  g.stats = {};
  const err = attempt(() => g.onData(msg(end(1), pdu(1, [1, 0]))));
  check('GFX statistics: a short WireToSurface1 at the end of a message', err === '' && g.stats.wireToSurface1 === 1 && ops.join() === 'end,log', `${err} ${JSON.stringify(g.stats)} ${ops}`);
}

// SurfaceToSurface: its rectangle inside its surface and each copy inside the other, as FreeRDP checks (one of 65535 x
// 65535 grew the page's scratch canvas to that size, kept for good); on the page, a copy never past its surface either; the
// page asked for a full picture when the pipeline says so
{
  const ops = [], logs = [];
  const g = new GfxPipeline((o) => { ops.push(o.op); if (o.op === 'log') logs.push(o.msg); });
  const s2s = (src, dst, rect, ...pts) => {
    const x = Buffer.alloc(14 + pts.length * 4);
    x.writeUInt16LE(src, 0); x.writeUInt16LE(dst, 2); rect.forEach((v, i) => x.writeUInt16LE(v, 4 + 2 * i)); x.writeUInt16LE(pts.length, 12);
    pts.forEach(([px, py], i) => { x.writeUInt16LE(px, 14 + i * 4); x.writeUInt16LE(py, 16 + i * 4); });
    return pdu(5, x);
  };
  g.onData(msg(surface(1, 64, 64), surface(2, 32, 32), s2s(1, 1, [0, 0, 65535, 65535], [0, 0]), s2s(1, 2, [0, 0, 64, 64], [0, 0]),
    s2s(1, 2, [0, 0, 16, 16], [16, 16], [17, 0]), s2s(1, 2, [0, 0, 16, 16], [16, 16])));
  const { GfxSurfaces } = await import('../public/assets/gfxsurfaces.js');
  const doc0 = globalThis.document, made = [], ctx = new Proxy({}, { get: (o, k) => (k in o ? o[k] : () => {}) });
  globalThis.document = { createElement: () => { const c = { width: 0, height: 0, getContext: () => ctx }; made.push(c); return c; } };
  let asked = 0;
  const page = new GfxSurfaces({ out: () => ({ ctx }), dirty() {}, reset() {}, ack() {}, log() {}, note() {}, refresh: () => asked++ });
  page.apply({ op: 'create', id: 1, w: 64, h: 64 });
  page.apply({ op: 's2s', src: 1, dst: 1, rect: { l: 0, t: 0, r: 65535, b: 65535 }, pts: [[0, 0]] });
  page.apply({ op: 'refresh' });
  globalThis.document = doc0;
  check('SurfaceToSurface within its surfaces; the page\'s scratch canvas within the surface; a full picture asked for',
    ops.join() === 'create,create,log,log,log,s2s' && logs.join(' | ') === 'gfx: GFX: copy rectangle outside its surface | gfx: GFX: copy outside its surface | gfx: GFX: copy outside its surface'
    && made.length === 2 && made[1].width === 64 && made[1].height === 64 && asked === 1, `${ops} ${logs.join(' | ')} ${made.map((c) => c.width + 'x' + c.height)} ${asked}`);
}

// RemoteFX progressive tile state (48 KB a tile): a surface id used again starts with none (the tiles of the surface before
// stayed); a simple tile keeps no signs (only upgrade passes need them, after a progressive first pass); past maxTiles in
// all, the oldest is dropped (thousands of 1 x 1 surfaces kept 48 KB each)
{
  const g = new GfxPipeline(() => {});
  const w2s2 = (id, data) => { const b = Buffer.alloc(13); b.writeUInt16LE(id, 0); b.writeUInt16LE(9, 2); b.writeUInt32LE(data.length, 9); return pdu(2, Buffer.concat([b, Buffer.from(data)])); };
  g.onData(msg(surface(1, 64, 64), w2s2(1, region([[0, 0, 64, 64]], q6, [], 1, [simple(0, 0)]))));
  const before = g.prog.surfaces.get(1)?.size, simpleSign = g.prog.surfaces.get(1)?.get(0)?.sign;
  g.onData(msg(surface(1, 64, 64)));
  const after = g.prog.surfaces.get(1)?.size ?? 0;
  const pd = new ProgressiveDecoder();
  pd.decode(1, region([[0, 0, 64, 64]], q6, p1, 0, [tile(0xccc6, 0, 0, 0, Array(10).fill(0))]), 64, 64);
  const firstSign = !!pd.surfaces.get(1).get(0).sign;
  pd.maxTiles = 3;
  pd.decode(2, region([[0, 0, 320, 64]], q6, [], 1, [0, 1, 2, 3, 4].map((x) => simple(x, 0))), 320, 64);
  const keys = [...pd.surfaces.get(1).keys(), ...pd.surfaces.get(2).keys()].join();
  check('progressive: tile state dropped with an id used again, no signs for simple tiles, the oldest dropped past the limit',
    before === 1 && simpleSign === null && after === 0 && firstSign && pd.kept?.size === 3 && keys === '2,3,4', JSON.stringify({ before, simpleSign: !!simpleSign, after, firstSign, keys }));
}

// H.264: pictures matched to their chunks, given up on, asked for again; decoders closed with their surface or pipeline
{
  const Dec0 = globalThis.VideoDecoder, decs = [];
  globalThis.VideoDecoder = class {              // outputs only when told: out(i), the picture of its i-th chunk, with that chunk's time
    constructor(o) { Object.assign(this, { o, chunks: [], closed: false }); decs.push(this); }
    configure() {}
    decode(c) { this.chunks.push(c.timestamp); }
    close() { this.closed = true; }
    out(i) { const f = { timestamp: this.chunks[i], closed: false, close() { this.closed = true; } }; this.o.output(f); return f; }
  };
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  let ops = [], g;
  const start = () => { ops = []; decs.length = 0; g = new GfxPipeline((o) => ops.push(o)); };
  const ends = () => ops.filter((o) => o.op === 'end').map((o) => o.frame).join();
  const avc444 = (id, ...streams) => {            // LC 0: [rectangles, access unit] of the luma stream, then of the chroma one
    const parts = streams.map(([rects, au]) => {
      const m = Buffer.alloc(4 + rects.length * 10);
      m.writeUInt32LE(rects.length);
      rects.forEach((r, i) => r.forEach((v, k) => m.writeUInt16LE(v, 4 + i * 8 + k * 2)));
      return Buffer.concat([m, au]);
    });
    const head = Buffer.alloc(4);
    head.writeUInt32LE(parts[0].length);
    return w2s(id, 0x0e, Buffer.concat([head, ...parts]));
  };
  const both = (id) => avc444(id, [[[0, 0, 64, 64]], key(100)], [[[0, 0, 64, 64]], key(100)]);

  // the decoder drops a chunk: the next picture is the next chunk's (it was taken for the dropped one's, and every one after
  // it shifted by one), the dropped one's operations go without a picture; a picture of an entry already gone is freed
  start();
  g.onData(msg(create(1), avc(1, key(100)), end(1)));
  g.onData(msg(avc(1, delta), end(2)));
  g.onData(msg(avc(1, delta), end(3)));
  const f0 = decs[0].out(0), f2 = decs[0].out(2), late = decs[0].out(1);
  const shown = ops.filter((o) => o.op === 'video').map((o) => (o.frame === f0 ? 0 : o.frame === f2 ? 2 : o.frame ? 'other' : '-')).join();
  check('H.264: each picture with its own chunk, a dropped one\'s operations without', shown === '0,-,2' && ends() === '1,2,3' && late.closed, `${shown} | ${ends()}`);

  // a decoder failing: a full picture asked for (its next decoder needs a key frame, which may not come otherwise), once in a
  // while however often it fails
  start();
  g.onData(msg(create(1), avc(1, key(100)), end(1)));
  decs[0].o.error(new Error('broken'));
  g.onData(msg(avc(1, key(100)), end(2)));
  decs[1].o.error(new Error('broken'));
  check('H.264: a failing decoder asks for a full picture, once in a while', ops.filter((o) => o.op === 'refresh').length === 1 && ends() === '1,2', ops.map((o) => o.op).join());

  // a decoder that gives nothing for a second: what waits for its pictures goes without them (the end of frame waited for
  // good: no acknowledgement, and the server sent nothing more), a full picture asked for; a picture after that is freed
  start();
  g.onData(msg(create(1), avc(1, key(100)), end(1)));
  g.onData(msg(avc(1, delta), end(2)));
  const waited = ends() === '';
  await sleep(1300);
  const gone = decs[0].out(0);
  check('H.264: a decoder silent for a second is given up on', waited && ends() === '1,2' && ops.filter((o) => o.op === 'refresh').length === 1 && gone.closed, `${waited} ${ends()}`);

  // AVC444: a picture held for the other stream's is freed with its surface (DeleteSurface)...
  start();
  g.onData(msg(create(1), both(1)));
  const held = decs[0].out(0);                    // (the luma picture, without planes: the chroma one still owed)
  g.onData(msg(pdu(10, [1, 0])));
  const freedWithSurface = held.closed;
  // ... and with the pipeline (dispose(): connected again, see stream-worker.js), every decoder of both kinds closed, the
  // pictures waiting in the order freed, nothing sent after it
  start();
  g.onData(msg(create(1), create(2), both(2), avc(1, key(100)), end(1)));
  const luma = decs[0].out(0), queued = decs[2].out(0);   // (the AVC420 picture waits behind the AVC444 message)
  const sent = ops.length;
  let idled = false;
  g.idle().then(() => { idled = true; });
  attempt(() => g.dispose());
  attempt(() => decs[1].o.error(new Error('late')));
  await sleep(0);
  check('AVC444: pictures held freed with their surface, and every decoder and picture with the pipeline', freedWithSurface && decs.length === 3
    && decs.every((x) => x.closed) && luma.closed && queued.closed && idled && ops.length === sent, JSON.stringify({ freedWithSurface, closed: decs.map((x) => x.closed), luma: luma.closed, queued: queued.closed, idled, sent: ops.length - sent }));

  // the stream worker connected again (frame 14): the pipeline disposed of (the AVC444 decoders were left open, and their late
  // pictures drew on the new connection, their frame acknowledged there)
  {
    const posted = [], fetch0 = globalThis.fetch;
    globalThis.postMessage = (m) => posted.push(m);
    globalThis.onmessage = null;
    await import('../public/assets/stream-worker.js');
    const frame = (type, d) => { const b = Buffer.alloc(5); b[0] = type; b.writeUInt32LE(d.length, 1); return new Uint8Array(Buffer.concat([b, d])); };
    const reads = [frame(9, Buffer.from(msg(create(1), both(1)))), frame(14, Buffer.alloc(0))];
    globalThis.fetch = async () => ({ ok: true, body: { getReader: () => ({ read: async () => (reads.length ? { value: reads.shift(), done: false } : { done: true }) }) } });
    decs.length = 0;
    await globalThis.onmessage({ data: { url: 'https://gateway.test/rdp.php?a=stream', headers: {}, body: '' } });
    for (const x of decs) if (!x.closed) x.out(0);   // (a decoder left open gives its picture late)
    await sleep(10);
    globalThis.onmessage({ data: { cmd: 'stop' } });
    globalThis.fetch = fetch0;
    const after = posted.slice(posted.findIndex((m) => m.t === 'frame' && m.type === 14));
    check('stream worker: connected again, the AVC444 decoders closed and none of their pictures sent', decs.length === 2 && decs.every((x) => x.closed)
      && after.length > 0 && !after.some((m) => m.t === 'gfx' && ['video', 'px'].includes(m.op.op)), JSON.stringify({ closed: decs.map((x) => x.closed), after: after.map((m) => m.op?.op ?? m.t) }));
  }
  globalThis.VideoDecoder = Dec0;
}

// ZGFX: exactly the size said (shorter was taken, the size said allocated all the same); a cache slot within 1..25600;
// ClearCodec's subcodecs over their rectangle once at most, an uncompressed one with all its pixels
{
  let zg = '';
  try { new Zgfx().decompress(hex('E1 0100 00100000 01000000 04 41')); } catch (e) { zg = e.message; }
  const seen = [];
  const pc = new GfxPipeline((o) => seen.push(o));
  pc.onData(hex('E004' + '0900 0000 0f000000 0100 2000 2000 20'));                               // surface 1, 32x32
  pc.onData(hex('E004' + '0600 0000 1c000000 0100 0000000000000000 0000 0000 0000 1000 1000'));   // to cache slot 0
  let cc = '';
  const sub = Buffer.alloc(13 * 3);                // three empty uncompressed subcodecs over a whole 8x8 rectangle
  for (let i = 0; i < 3; i++) { sub.writeUInt16LE(8, i * 13 + 4); sub.writeUInt16LE(8, i * 13 + 6); }
  const clear = Buffer.concat([Buffer.from([0x00, 0x00]), Buffer.from([0, 0, 0, 0, 0, 0, 0, 0, sub.length, 0, 0, 0]), sub]);
  try { new ClearCodec().decode(new Uint8Array(clear), 8, 8); } catch (e) { cc = e.message; }
  check('ZGFX of another size than said, a cache slot out of range and ClearCodec subcodecs past their rectangle refused',
    /not the size said/.test(zg) && seen.some((o) => o.op === 'log' && /cache slot out of range/.test(o.msg)) && /subcodecs beyond their data/.test(cc),
    JSON.stringify([zg, seen.map((o) => o.msg).filter(Boolean), cc]));
}

// RemoteFX and progressive: a region of 65,535 copies of one full-surface rectangle (under 1 KB compressed) and 4 tiles;
// each tile converted for each rectangle it met (1.2-1.6 s a tile, hours with many). Their bounding box instead, at once
{
  const W = 1920, H = 1080, R = 65535, T = 4;
  const prog = (() => {
    const tileLen = 22, size = 18 + R * 8 + 5 + T * tileLen, b = new Uint8Array(size), dv = new DataView(b.buffer);
    dv.setUint16(0, 0xccc4, true); dv.setUint32(2, size, true); b[6] = 64; dv.setUint16(7, R, true); b[9] = 1;
    dv.setUint16(12, T, true); dv.setUint32(14, T * tileLen, true);
    let q = 18;
    for (let i = 0; i < R; i++, q += 8) { dv.setUint16(q + 4, W, true); dv.setUint16(q + 6, H, true); }
    b.fill(0x66, q, q + 5); q += 5;
    for (let i = 0; i < T; i++, q += tileLen) { dv.setUint16(q, 0xccc5, true); dv.setUint32(q + 2, tileLen, true); dv.setUint16(q + 9, i, true); }
    return b;
  })();
  const rfx = (() => {
    const regLen = 8 + 3 + R * 8 + 4, tileLen = 19, tsLen = 27 + T * tileLen, b = new Uint8Array(regLen + tsLen), dv = new DataView(b.buffer);
    dv.setUint16(0, 0xccc6, true); dv.setUint32(2, regLen, true); b[8] = 1; dv.setUint16(9, R, true);
    for (let i = 0, o = 11; i < R; i++, o += 8) { dv.setUint16(o + 4, W, true); dv.setUint16(o + 6, H, true); }
    const s = regLen + 8;
    dv.setUint16(regLen, 0xccc7, true); dv.setUint32(regLen + 2, tsLen, true); b[s + 6] = 1; dv.setUint16(s + 8, T, true); b.fill(0x66, s + 14, s + 19);
    for (let i = 0, q = s + 19; i < T; i++, q += tileLen) { dv.setUint16(q, 0xcac3, true); dv.setUint32(q + 2, tileLen, true); dv.setUint16(q + 9, i, true); }
    return b;
  })();
  let t0 = performance.now();
  const boxes = new ProgressiveDecoder().decode(1, prog, W, H);
  const progMs = performance.now() - t0;
  t0 = performance.now();
  new RfxDecoder().decode(rfx, W, H);
  const rfxMs = performance.now() - t0;
  const parts = boxes.reduce((n, b) => n + b.parts.length, 0);
  check('RemoteFX and progressive: many overlapping rectangles as their bounding box, at once', progMs < 1000 && rfxMs < 1000 && parts <= 4 * T,
    `${progMs.toFixed(0)} ms, ${rfxMs.toFixed(0)} ms, ${parts} parts`);
}

console.log(failed ? `\n${failed} failed` : '\nAll tests passed');
process.exit(failed ? 1 : 0);
