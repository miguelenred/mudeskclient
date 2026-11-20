// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// Unit tests of the graphics pipeline codecs: node tests/rdpgfx_unit.mjs
import { readFileSync } from 'node:fs';
import { Worker } from 'node:worker_threads';
import { ClearCodec, GfxPipeline, Zgfx, fixColour, nscDecode } from '../public/assets/rdpgfx.js';
import { ProgressiveDecoder, RfxDecoder } from '../public/assets/rfx.js';

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

// AVC444 (v1, v2): the first stream is a 4:2:0 picture of its own and goes to the H.264 decoder; the extra chroma stream is
// left out and chroma-only updates are skipped (Windows may answer the 10.x capabilities with AVC444)
const chunks = [];
globalThis.VideoDecoder = class { configure() {} decode(c) { chunks.push(Buffer.from(c.data).toString('hex')); } };
globalThis.EncodedVideoChunk = class { constructor(o) { this.data = o.data; } };
const avc420 = '01000000' + '0000000040004000' + '1864' + '000000016742c01f8c8d4020030d00f08846a0' + '0000000165b80004';
const wire = (codec, data) => {
  const d = Buffer.from(data, 'hex'), h = Buffer.alloc(25);
  h.writeUInt16LE(1, 0); h.writeUInt32LE(25 + d.length, 4); h.writeUInt16LE(codec, 10); h[12] = 0x20;
  h.writeUInt16LE(64, 17); h.writeUInt16LE(64, 19); h.writeUInt32LE(d.length, 21);
  return hex('E004' + h.toString('hex') + d.toString('hex'));
};
const lcSize = (lc, n) => { const b = Buffer.alloc(4); b.writeUInt32LE(((lc << 30) | n) >>> 0); return b.toString('hex'); };
const p444 = new GfxPipeline(() => {});
p444.onData(hex('E004' + '0900 0000 0f000000 0000 4000 4000 20'));                  // surface 0, 64 x 64
p444.onData(wire(0x000e, lcSize(0, avc420.length / 2) + avc420 + 'deadbeef'));       // both streams
p444.onData(wire(0x000f, lcSize(2, 0) + 'deadbeef'));                                // chroma only
check('AVC444: main 4:2:0 view decoded, extra chroma left out', chunks.length === 1 && chunks[0].endsWith('0000000165b80004') && !chunks[0].includes('deadbeef'),
  JSON.stringify(chunks));

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
// a RemoteFX quantizer index past the list sent: an error of the decoder (TypeError before)
const tq = Buffer.from(ts);
tq.writeUInt16LE(0, 36); tq[33] = 1;                                                 // the tile at column 0, with quantizer 1 of 1
check('RemoteFX: quantizer index past the list', attempt(() => new RfxDecoder().decode(new Uint8Array(tq), 64, 64)) === 'RemoteFX: bad quantizer index');

// RemoteFX progressive
/** RFX_PROGRESSIVE_REGION: rectangles [x, y, w, h], quantizers (5 bytes each), progressive quantizers (16), flags, tile blocks */
const region = (rects, quant, progQuant, flags, tiles, count = tiles.length) => {
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

console.log(failed ? `\n${failed} failed` : '\nAll tests passed');
process.exit(failed ? 1 : 0);
