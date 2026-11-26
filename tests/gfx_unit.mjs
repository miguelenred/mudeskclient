// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// Unit tests of the bitmap and pointer decoders: node tests/gfx_unit.mjs
import { Worker } from 'node:worker_threads';
import { bitmapUpdate, pointerDecode, rleDecode } from '../public/assets/gfx.js';

let failed = 0;
const check = (name, ok, info = '') => { console.log((ok ? 'OK   ' : 'FAIL ') + name + (info ? ' ' + info : '')); if (!ok) failed++; };
/** Runs fn(module) in a worker: 'returned', 'threw: message' or, after ms, 'hung' (an endless loop must not hang the tests). */
const inWorker = (fn, ms = 3000) => new Promise((resolve) => {
  const w = new Worker(`const { parentPort } = require('node:worker_threads');
    import(${JSON.stringify(new URL('../public/assets/gfx.js', import.meta.url).href)}).then((m) => {
      try { (${fn})(m); parentPort.postMessage('returned'); } catch (e) { parentPort.postMessage('threw: ' + e.message); } });`, { eval: true });
  const t = setTimeout(() => { w.terminate(); resolve('hung'); }, ms);
  w.on('message', (m) => { clearTimeout(t); w.terminate(); resolve(m); });
});

// background run longer than a row must repeat the row above sequentially
// first line: color image of 4 pixels (16bpp): 0x84 = color image run 4, then REGULAR_BG_RUN with run 12 (0x0c)
const out = rleDecode(new Uint8Array([0x84, 1, 0, 2, 0, 3, 0, 4, 0, 0x0c]), 4, 4, 2);
check('RLE background run over several rows', Array.from(out).join(',') === '1,2,3,4,1,2,3,4,1,2,3,4,1,2,3,4');

// planar RLE that ends early (or with a control byte 0, which does not move x) ends with an error instead of looping forever
let r = await inWorker((m) => m.planarDecode(Uint8Array.of(0x30, 0x40), 4, 2));
check('planar: truncated plane', r === 'threw: Planar: truncated plane', r);
r = await inWorker((m) => m.planarDecode(Uint8Array.of(0x30, 0x00), 4, 2));
check('planar: control byte 0 at the end', r === 'threw: Planar: truncated plane', r);

// pointers: larger than the protocol allows (384x384) are refused before allocating; 16 bpp white is white
const ptr = (bpp, w, h, xor) => {
  const u = new Uint8Array(16 + xor.length), dv = new DataView(u.buffer);
  dv.setUint16(0, bpp, true); dv.setUint16(8, w, true); dv.setUint16(10, h, true); dv.setUint16(14, xor.length, true); u.set(xor, 16);
  return u;
};
let msg = '';
try { pointerDecode(ptr(32, 1000, 1000, []), 11); } catch (e) { msg = e.message; }
check('pointer: oversized one refused', msg === 'pointer: bad size', msg);
const white = pointerDecode(ptr(16, 1, 1, [0xff, 0xff]), 11).rgba[0];
check('pointer: 16 bpp white', white === 0xffffffff, white.toString(16));
// ... the way up of each: 1 bpp masks top-down, colour ones bottom-up. A 1 bpp cross of 32 x 32 whose arms meet at its hot spot
// (7, 7), as the one Windows shows over a sheet's cells: before, drawn upside down, its horizontal arm at row 24, 17 px below
// where the click goes (the click fell on the cell above the one seen)
{
  const masks = (bpp, w, h, hx, hy, xor, and) => {
    const u = new Uint8Array(16 + xor.length + and.length), dv = new DataView(u.buffer);
    dv.setUint16(0, bpp, true); dv.setUint16(4, hx, true); dv.setUint16(6, hy, true); dv.setUint16(8, w, true); dv.setUint16(10, h, true);
    dv.setUint16(12, and.length, true); dv.setUint16(14, xor.length, true); u.set(xor, 16); u.set(and, 16 + xor.length);
    return u;
  };
  const xor = new Uint8Array(32 * 4), and = new Uint8Array(32 * 4);          // (rows of 4 bytes: 32 bits, already even)
  xor.fill(0xff, 7 * 4, 8 * 4);                                               // row 7 of the data, the horizontal arm
  for (let r = 0; r < 32; r++) xor[r * 4] |= 0x01;                             // column 7, the vertical arm
  const p = pointerDecode(masks(1, 32, 32, 7, 7, xor, and), 11);
  const armRow = [...Array(32).keys()].find((y) => p.rgba[y * 32 + 20] === 0xffffffff);
  // 24 bpp, 2 x 2: the data's first row is the bottom one
  const c = pointerDecode(masks(24, 2, 2, 0, 0, Uint8Array.of(255, 255, 255, 255, 255, 255, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0), new Uint8Array(4)), 11);
  const got = [p.x, p.y, armRow, p.rgba[7 * 32 + 7], c.rgba[2], c.rgba[0]].map((v) => v >>> 0).join();
  check('pointer: 1 bpp masks top-down (the cross at its hot spot), colour ones bottom-up',
    got === [7, 7, 7, 0xffffffff, 0xffffffff, 0xff000000].join(), got);
}
// ... and as the gateway offers them (src/Rdp/Client.php): 96 x 96 at most and a cache of 25 (the protocol's 384 x 384 was
// accepted, and any of 65,536 cache indexes, each kept by the page)
{
  const at = (w, h, index) => { const u = ptr(32, w, h, []); new DataView(u.buffer).setUint16(2, index, true); return u; };
  const one = (u, kind = 11) => { try { pointerDecode(u, kind); return 'ok'; } catch (e) { return e.message; } };
  msg = [one(at(96, 96, 24)), one(at(97, 1, 0)), one(at(1, 384, 0)), one(at(1, 1, 25)), one(at(1, 1, 65535), 12)].join(' | ');
  check('pointer: up to 96 x 96 and cache index 24', msg === 'ok | pointer: bad size | pointer: bad size | pointer: bad cache index | pointer: bad cache index', msg);
}

// bitmap updates: no more pixels than the desktop (passed by stream-worker.js) and only the RDP colour depths; the sizes taken
// from the data asked for up to 16 GB (and a depth of 5376 bpp was decoded)
const update = (w, h, bpp) => {                     // one uncompressed rectangle of w x h
  const len = ((w * ((bpp + 7) >> 3) + 3) & ~3) * h, u = new Uint8Array(4 + 18 + len), dv = new DataView(u.buffer);
  [1, 1, 0, 0, w - 1, h - 1, w, h, bpp, 0, len].forEach((v, i) => dv.setUint16(i < 2 ? i * 2 : 4 + (i - 2) * 2, v, true));
  return u;
};
const bmp = (w, h, bpp) => { try { bitmapUpdate(update(w, h, bpp), new Uint32Array(256), () => {}, 64 * 64); return 'drawn'; } catch (e) { return e.message; } };
msg = [bmp(64, 64, 16), bmp(65, 64, 16), bmp(4, 4, 5376)].join(' | ');
check('bitmap: larger than the desktop or of no RDP depth refused', msg === 'drawn | bad size or depth (65 x 64 at 16 bpp) | bad size or depth (4 x 4 at 5376 bpp)', msg);

// bitmaps decoded exactly as before the decoders were rewritten for speed (no closures, reused scratch buffers, one pass for
// raw data and planar): random interleaved RLE at 8, 15, 16 and 24 bpp (every order, data cut anywhere), planar (raw and
// RLE planes, with and without alpha) and raw rectangles at every depth, one after another (a bitmap after a larger one must
// not show its leftovers); the digest of every pixel and error is the one the decoders gave before
{
  const { createHash } = await import('node:crypto');
  const { planarDecode } = await import('../public/assets/gfx.js');
  let seed = 5;
  const rnd = (n) => { seed = (seed * 1103515245 + 12345) >>> 0; return (seed >>> 8) % n; };
  const bytes = (n) => Array.from({ length: n }, () => rnd(256));
  const rle = (Bpp) => {
    const out = [];
    for (let i = 4 + rnd(40); i > 0; i--) {
      const kind = rnd(8);
      if (kind === 0) out.push(rnd(5) << 5);                                       // regular order, length in the next byte
      else if (kind === 1) out.push((rnd(2) ? 0xc0 : 0xe0) | rnd(16));             // lite: set foreground, dithered
      else if (kind === 2) out.push(0xd0 | rnd(16));                               // lite FGBG with its foreground
      else if (kind === 3) out.push([0xf0, 0xf1, 0xf2, 0xf3, 0xf4, 0xf6, 0xf7, 0xf8][rnd(8)], rnd(80), 0);   // mega mega
      else if (kind === 4) out.push([0xf9, 0xfa, 0xfd, 0xfe][rnd(4)]);
      else out.push(rnd(5) << 5 | (1 + rnd(31)));
      out.push(...bytes(rnd(4) * Bpp + rnd(3)));
    }
    return out.slice(0, out.length - rnd(4));
  };
  const planar = (w, h) => {
    const coded = rnd(4) > 0, noAlpha = rnd(2), out = [(coded ? 0x10 : 0) | (noAlpha ? 0x20 : 0)];
    for (let plane = noAlpha ? 3 : 4; plane > 0; plane--) {
      if (!coded) { out.push(...bytes(w * h - rnd(2) * rnd(w * h))); continue; }
      for (let y = 0; y < h; y++) for (let x = 0; x < w;) { const raw = rnd(16), run = rnd(16); out.push(raw << 4 | run, ...bytes(raw)); x += run === 1 ? raw + 16 : run === 2 ? raw + 32 : raw + run || w; }
    }
    return out;
  };
  const hash = createHash('md5'), palette = Uint32Array.from({ length: 256 }, (_, i) => 0xff000000 + i * 0x10305);
  for (let k = 0; k < 600; k++) {
    const bpp = [8, 15, 16, 24, 32][k % 5], Bpp = (bpp + 7) >> 3, w = 1 + rnd(k % 7 ? 24 : 64), h = 1 + rnd(k % 7 ? 24 : 64), compressed = k % 3 > 0;
    const data = compressed ? (bpp === 32 ? planar(w, h) : rle(Bpp)) : bytes(((w * Bpp + 3) & ~3) * h - rnd(2) * rnd(64));
    const u = new Uint8Array(4 + 18 + data.length), dv = new DataView(u.buffer);
    [1, 1, 0, 0, w - 1, h - 1, w, h, bpp, compressed ? 0x401 : 0, data.length].forEach((v, i) => dv.setUint16(i < 2 ? i * 2 : 4 + (i - 2) * 2, v, true));
    u.set(data, 22);
    try { bitmapUpdate(u, palette, (x, y, bw, bh, rgba) => hash.update(rgba), 1 << 20); } catch (e) { hash.update(e.message); }
  }
  const digest = hash.digest('hex');
  check('bitmaps: RLE, planar and raw at every depth decoded as before', digest === 'b1fda02e73d67b4c18b34ae3d0a8fac7', digest);
  // planar as the graphics pipeline asks for it: rows top-down, alpha kept, or opaque; reversed rows for bitmap updates
  let same = true;
  for (let k = 0; k < 50; k++) {
    const w = 1 + rnd(40), h = 1 + rnd(40), src = Uint8Array.from(planar(w, h));
    try {
      const plain = planarDecode(src, w, h), opaque = planarDecode(src, w, h, true), up = planarDecode(src, w, h, true, true);
      same &&= plain.every((v, i) => opaque[i] === (v | 0xff000000) >>> 0 && up[(h - 1 - Math.floor(i / w)) * w + i % w] === opaque[i]);
    } catch (e) { same &&= e.message === 'Planar: truncated plane'; }
  }
  check('planar: opaque and bottom-up are the plain rows with alpha 255, reversed', same);
}

// an order whose length byte is past the end of the data draws nothing more: the rest of the bitmap is black, also right
// after a white one (the pixel values are decoded into a buffer reused from one bitmap to the next)
{
  const rect = (data) => [0, 0, 7, 7, 8, 8, 16, 0x401, data.length].flatMap((v) => [v & 255, v >> 8]).concat(data);
  const u = Uint8Array.from([1, 0, 4, 0, ...rect([0xf3, 64, 0, 0xff, 0xff]), ...rect([0x60]), ...rect([0xf3, 64, 0, 0xff, 0xff]), ...rect([0x00])]);
  const got = [];
  bitmapUpdate(u, new Uint32Array(256), (x, y, w, h, rgba) => got.push(rgba.every((v) => v === 0xffffffff) ? 'white' : rgba.every((v) => v === 0xff000000) ? 'black' : 'mixed'), 64);
  check('RLE: length past the end of the data, the rest black after a white bitmap', got.join() === 'white,black,white,black', got.join());
}

// a palette update shorter than its header, or than the entries it announces: no error (it threw out of the stream
// worker, and the session ended)
{
  const { paletteUpdate } = await import('../public/assets/gfx.js');
  const pal = new Uint32Array(256).fill(7);
  let err = '';
  try {
    paletteUpdate(Uint8Array.of(0, 0, 0), pal);
    const short = new Uint8Array(8 + 3);
    new DataView(short.buffer).setUint32(4, 256, true);
    short.set([1, 2, 3], 8);
    paletteUpdate(short, pal);
  } catch (e) { err = e.message; }
  check('palette: a short update taken as far as it goes, never thrown', err === '' && pal[0] === 0xff030201 && pal[1] === 7, err || pal[0].toString(16));
}

console.log(failed ? `\n${failed} failed` : '\nAll tests passed');
process.exit(failed ? 1 : 0);
