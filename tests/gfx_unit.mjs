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
