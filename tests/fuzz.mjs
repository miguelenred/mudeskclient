// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// Fuzz tests of the browser decoders: node tests/fuzz.mjs [--iterations=N] [--seed=S] [--target=NAME [--case=K]] [--strict]
//
// Random and mutated server data against what the stream worker decodes: bitmaps (interleaved RLE, planar, raw),
// pointers and palettes, drawing orders, RemoteFX (RLGR, DWT) and RemoteFX progressive, NSCodec, ClearCodec, the
// graphics pipeline with ZGFX, and bulk decompression (MPPC, NCRUSH, XCRUSH). A case fails when it hangs (5 s), takes
// over 2 s, allocates more than 64 MB at once (the surfaces here are a few hundred pixels: only a size taken from the
// data unchecked gets there), gives output of the wrong size, or throws anything but the decoders' own errors (Error)
// or a read past the end of the data (DataView): a TypeError or another RangeError counts even when it is caught inside
// (the graphics pipeline logs it and goes on: that log is checked).
// The drawing order parser drops what fails on purpose: there only hangs, time and memory count.
// Each case is built from the seed, the target and its number (the same seed gives the same inputs) and runs in a
// worker that is stopped if it hangs; a failure shows its input (hex) and the command that replays it.
// N cases per target (300 to 500 each by default: a few seconds). KNOWN failures (found, not fixed yet) are shown as
// pending and only fail with --strict.
import { readFileSync } from 'node:fs';
import { Worker, isMainThread, parentPort, workerData } from 'node:worker_threads';

const HANG = 5000, SLOW = 2000, ALLOC = 64 << 20;
const DESKTOP = 1920 * 1088;                             // most pixels of a bitmap: a 1920 x 1080 desktop, as stream-worker.js counts it
/** Failures found and not fixed yet: [regular expression on the failure text, what is wrong]. Remove each one once fixed. */
const KNOWN = [];

// ------------------------------------------------------------------ random data (seeded for each case)

let state = 0;
function seedCase(seed, target, n) {
  let h = 2166136261;
  for (const ch of `${seed}/${target}/${n}`) h = Math.imul(h ^ ch.charCodeAt(0), 16777619);
  state = h >>> 0;
}
function random() {                                      // mulberry32
  state = (state + 0x6d2b79f5) >>> 0;
  let t = Math.imul(state ^ (state >>> 15), state | 1);
  t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
  return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
}
const rnd = (n) => Math.floor(random() * n);
const chance = (percent) => rnd(100) < percent;
const pick = (a) => a[rnd(a.length)];
const bytes = (n) => Uint8Array.from({ length: n }, () => rnd(256));
const INTERESTING = [0, 1, 2, 3, 4, 7, 8, 0x7f, 0x80, 0xff, 0x100, 0x7fff, 0x8000, 0xffff, 0x10000, 0x7fffffff, 0x80000000, 0xffffffff];

/** Little-endian bytes of v (negative values in two's complement). */
function le(v, n) {
  let x = ((Math.trunc(v) % 2 ** (8 * n)) + 2 ** (8 * n)) % 2 ** (8 * n);
  return Array.from({ length: n }, () => { const b = x % 256; x = Math.floor(x / 256); return b; });
}
const u16 = (v) => le(v, 2), u32 = (v) => le(v, 4);
function cat(...parts) {
  const a = [];
  for (const p of parts) if (typeof p === 'number') a.push(p); else for (const b of p) a.push(b);
  return Uint8Array.from(a);
}

function mutate(src) {
  const s = Array.from(src);
  for (let k = 1 + rnd(3); k > 0; k--) {
    const n = s.length, p = n ? rnd(n) : 0;
    switch (n ? rnd(9) : 0) {
      case 0: s.push(...bytes(1 + rnd(16))); break;
      case 1: s[p] ^= 1 << rnd(8); break;
      case 2: s[p] = pick([0, 1, 0x7f, 0x80, 0xff]); break;
      case 3: s.splice(p, 2, ...u16(pick(INTERESTING))); break;
      case 4: s.splice(p, 4, ...u32(pick(INTERESTING))); break;
      case 5: s.splice(p, 2, ...u16(pick(INTERESTING)).reverse()); break;
      case 6: s.splice(p, 1 + rnd(8)); break;
      case 7: s.splice(p, 0, ...bytes(1 + rnd(8))); break;
      default: s.length = p;
    }
  }
  return Uint8Array.from(s);
}

/** Well-formed data, mutated in a quarter of the cases or, rarely, replaced by random bytes ($percent for parts). */
const maybe = (u8, percent = 25) => (chance(3) ? bytes(rnd(64)) : chance(percent) ? mutate(u8) : u8);

// ------------------------------------------------------------------ inputs

const fixture = (name) => readFileSync(new URL(`./fixtures/${name}`, import.meta.url), 'utf8').split('\n').filter((l) => l && l[0] !== '#');
const fromHex = (s) => Uint8Array.from(Buffer.from(s, 'hex'));
let vectors = null, packets = null;

/** [width, height, data] of a codec vector of tests/fixtures/gfx-vectors.txt. */
function vector(codec) {
  vectors ??= fixture('gfx-vectors.txt').map((l) => l.split(' ')).map(([c, w, h, input]) => [c, +w, +h, fromHex(input)]);
  return pick(vectors.filter((v) => v[0] === codec)).slice(1);
}

/** Compressed blocks of tests/fixtures (FreeRDP's compressors) in order from a random one, each as [flags, data...]. */
function compressed(n) {
  packets ??= ['mppc', 'ncrush', 'xcrush'].map((name) => fixture(`${name}-vectors.txt`).map((l) => l.split(' ')).map(([f, h]) => cat(+f, fromHex(h))));
  const set = pick(packets), start = chance(50) ? 0 : rnd(set.length);
  return Array.from({ length: n }, (_, i) => set[(start + i) % set.length]);
}

// interleaved RLE orders with what follows them, and planar planes (raw or RLE rows)
function rle(Bpp) {
  const out = [];
  for (let i = rnd(24); i > 0; i--) {
    out.push(...pick([[pick([0, 1, 3, 4]) << 5 | rnd(32)], [pick([0x0c, 0x0e]) << 4 | rnd(16)], [0x40 | rnd(32)], [0xd0 | rnd(16)],
      [pick([0xf0, 0xf1, 0xf2, 0xf3, 0xf4, 0xf6, 0xf7, 0xf8]), ...u16(rnd(300))], [pick([0xf9, 0xfa, 0xfd, 0xfe])], [rnd(256)]]));
    out.push(...bytes(rnd(3) * Bpp + rnd(8)));
  }
  return Uint8Array.from(out);
}
function planar(w, h) {
  const coded = chance(70), noAlpha = chance(50), out = [(coded ? 0x10 : 0) | (noAlpha ? 0x20 : 0) | (chance(5) ? rnd(8) : 0)];
  for (let plane = noAlpha ? 3 : 4; plane > 0; plane--) {
    if (!coded) { out.push(...bytes(Math.min(w * h, 4096))); continue; }
    for (let y = 0; y < Math.min(h, 64); y++) {
      for (let x = 0; x < w;) {
        const raw = rnd(16), run = rnd(16);
        out.push(raw << 4 | run, ...bytes(raw));
        x += run === 1 ? raw + 16 : run === 2 ? raw + 32 : raw + run || w;
      }
    }
  }
  return Uint8Array.from(out);
}

// drawing orders (MS-RDPEGDI): the encodings of tests/orders_unit.mjs, and random primary and secondary orders
const i16 = u16;
const ORDERS = [
  [0x09, 0x02, 0x7f, ...i16(10), ...i16(20), ...i16(300), ...i16(200), 0xcc, ...i16(10), ...i16(40), 0x11, 0x42, 0x5, 0x80],
  [0x0d, 0x0a, 0x7f, 0x0f, ...i16(0), ...i16(0), ...i16(99), ...i16(49), ...i16(0), ...i16(0), ...i16(640), ...i16(480), 0xff, 0xff, 0x00, 0x25, 0x40, 0x00],
  [0x09, 0x00, 0x1f, ...i16(1), ...i16(2), ...i16(3), ...i16(4), 0x55, 0x01, 0x10, 0x00, 0x01, 0x10, 0xaa],
  [0x09, 0x01, 0xff, 0x0f, ...i16(5), ...i16(6), ...i16(7), ...i16(8), 0xf0, 0, 0, 0, 0x33, 0x22, 0x11, ...Array(11).fill(0)],
  [0x03, ...i16(12), ...i16(1 | (6 << 3) | (0x01 << 7)), 4, 0x02, 0x10, 0x05, 1, 2, 3, 0, 4, 5, 6, 0, 7, 8, 9, 0, 10, 11, 12, 0,
    0x09, 0x0d, 0xff, 0x01, ...i16(1), ...i16(100), ...i16(50), ...i16(2), ...i16(2), 0xcc, ...i16(0), ...i16(0), ...i16(5)],
  [0x03, ...i16(2), ...i16(2 | (1 << 8)), 3, 5, 0x00, 0x48, 3, 2, 0xa0, 0x40, 0, 0],
  [0x09, 0x1b, 0xff, 0x3f, 0x38, 2, 0x02, 0, 0, 0x11, 0x22, 0x33, 0x44, 0x55, 0x66, ...i16(100), ...i16(40), ...i16(140), ...i16(52), ...i16(98),
    ...i16(40), ...i16(150), ...i16(52), ...i16(100), ...i16(50), 10, 5, 0, 5, 10, 0xff, 1, 4, 0xfe, 1, 30],
  [0x09, 0x18, 0xff, 0x7f, 2, 0, 0x02, 0x11, 0x22, 0x33, 0x44, 0x55, 0x66, ...i16(10), ...i16(20), ...i16(30), ...i16(29), ...i16(0), ...i16(0x0f),
    ...i16(0), ...i16(-32768), ...i16(12), ...i16(28), 7, 7, 1, 0x44, 2, 1, 0xc0],
];
const two = (v) => (v < 0x80 ? [v] : [0x80 | (v >> 8), v & 255]);
const twoS = (v) => { const m = Math.min(Math.abs(v), 0x3fff), s = v < 0 ? 0x40 : 0; return m < 0x40 ? [s | m] : [0x80 | s | (m >> 8), m & 255]; };
const four = (v) => { const n = v < 0x40 ? 0 : v < 0x4000 ? 1 : v < 0x400000 ? 2 : 3; return [n << 6 | Math.floor(v / 256 ** n) & 0x3f, ...le(v, n).reverse()]; };
function order() {
  if (chance(30)) {                                     // secondary: cache bitmap v2, cache glyph v2 or another (skipped)
    const type = pick([3, 4, 5, 5, rnd(16)]);
    let extra = rnd(0x10000), body = bytes(rnd(24));
    if (type === 4 || type === 5) {
      const bppId = pick([3, 4, 5, 6, rnd(16)]), flags = rnd(16), Bpp = { 3: 1, 4: 2, 5: 3, 6: 4 }[bppId] ?? 1;
      const size = () => (chance(90) ? 1 + rnd(32) : pick([0, 255, 4096, 32767]));
      const w = size(), h = flags & 1 ? w : size();
      const px = type === 4 ? bytes(Math.min(((w * Bpp + 3) & ~3) * h, 8192)) : Bpp === 4 ? planar(w, h) : rle(Bpp);
      const header = type === 5 && !(flags & 0x08) ? [0, 0, ...u16(px.length), 0, 0, 0, 0] : [];
      extra = rnd(3) | bppId << 3 | flags << 7;
      body = cat(flags & 2 ? bytes(8) : [], two(w), flags & 1 ? [] : two(h), four(px.length + header.length), two(rnd(600)), header, px);
    } else if (type === 3) {
      const glyphs = [], n = 1 + rnd(4);
      for (let i = 0; i < n; i++) {
        const w = chance(90) ? 1 + rnd(24) : pick([0, 255, 0x7fff]), h = chance(90) ? 1 + rnd(24) : pick([0, 255, 0x7fff]), cb = Math.min(((w + 7) >> 3) * h, 512);
        glyphs.push(rnd(256), ...twoS(rnd(64) - 32), ...twoS(rnd(64) - 32), ...two(w), ...two(h), ...bytes(cb + (4 - (cb % 4)) % 4));
      }
      extra = rnd(12) | rnd(16) << 4 | n << 8;
      body = Uint8Array.from(glyphs);
    }
    return cat(0x03, i16(body.length - 7), u16(extra), type, body);
  }
  const ctl = 0x01 | (chance(70) ? 0x08 : 0) | (chance(30) ? 0x04 : 0) | (chance(30) ? 0x10 : 0) | (chance(10) ? 0x20 : 0) | (chance(10) ? 0x40 : 0) | (chance(5) ? 0x80 : 0);
  return cat(ctl, ctl & 0x08 ? [pick([0x00, 0x01, 0x02, 0x0a, 0x0d, 0x13, 0x18, 0x1b, rnd(32)])] : [], bytes(3 + rnd(40)));
}

// RemoteFX (MS-RDPRFX) messages: blocks, the codec channel ones with codec and channel ids
const rfxBlock = (type, body, channel = type >= 0xccc3 && type <= 0xccc7) => cat(u16(type), u32(6 + (channel ? 2 : 0) + body.length), channel ? [1, 0] : [], body);
const quants = (n) => Array.from({ length: 5 * n }, () => (chance(80) ? 6 + rnd(10) : rnd(16)) | (chance(80) ? 6 + rnd(10) : rnd(16)) << 4);
function rfxMessage(w, h) {
  const tiles = [], nq = 1 + rnd(3), past = chance(5) ? 1 : 0, q = () => rnd(nq + past);   // quantizer index (now and then past the list)
  for (let i = 1 + rnd(4); i > 0; i--) {
    const lens = [rnd(120), rnd(60), rnd(60)];
    tiles.push(maybe(cat(u16(0xcac3), u32(19 + lens[0] + lens[1] + lens[2]), q(), q(), q(), u16(rnd(w / 64 + 2)), u16(rnd(h / 64 + 2)),
      ...lens.map(u16), bytes(lens[0] + lens[1] + lens[2])), 10));
  }
  const tileData = cat(...tiles), rects = Array.from({ length: rnd(3) }, () => [...u16(rnd(w)), ...u16(rnd(h)), ...u16(1 + rnd(w)), ...u16(1 + rnd(h))]);
  return cat(rfxBlock(0xccc0, cat(u32(0xcacccaca), u16(0x0100))), rfxBlock(0xccc1, [1, 1, ...u16(0x0100)]), rfxBlock(0xccc2, [1, 0, ...u16(w), ...u16(h)]),
    rfxBlock(0xccc3, [0, ...u16(64), ...u16(pick([1, 4]) << 9 | 0x28)]), rfxBlock(0xccc4, cat(u32(rnd(100)), u16(1))),
    rfxBlock(0xccc6, cat(1, u16(rects.length), ...rects, u16(0xcac1), u16(1))),
    rfxBlock(0xccc7, cat(u16(0xcac2), u16(0), u16(0), nq, 64, u16(tiles.length), u32(tileData.length), quants(nq), tileData)), rfxBlock(0xccc5, []));
}

// RemoteFX progressive (MS-RDPEGFX 2.2.4): region blocks with their tiles (as in tests/rdpgfx_unit.mjs)
function region(rects, quant, prog, flags, tiles) {
  const t = cat(...tiles), body = cat(64, u16(rects.length), quant.length / 5, prog.length / 16, flags, u16(tiles.length), u32(t.length),
    ...rects.map((r) => r.flatMap(u16)), quant, prog, t);
  return cat(u16(0xccc4), u32(6 + body.length), body);
}
function progressive(w, h, upgrade) {
  const tiles = [], nq = 1 + rnd(2), np = rnd(3), past = chance(5) ? 1 : 0;   // quantizer indices now and then past the lists
  for (let i = 1 + rnd(4); i > 0; i--) {
    const type = upgrade ? 0xccc7 : pick([0xccc5, 0xccc6]), q = rnd(nq + past), x = rnd(w / 64 + 1), y = rnd(h / 64 + 1);
    const lens = Array.from({ length: type === 0xccc7 ? 6 : 3 }, () => rnd(80));
    const quality = (np || past) && chance(70) ? rnd(np + past) : 0xff;     // progressive quantizer, 0xff: none
    const head = type === 0xccc5 ? [rnd(2), ...lens.flatMap(u16), 0, 0] : type === 0xccc6 ? [rnd(2), quality, ...lens.flatMap(u16), 0, 0] : [quality, ...lens.flatMap(u16)];
    const body = cat(head, bytes(lens.reduce((a, b) => a + b, 0)));
    tiles.push(maybe(cat(u16(type), u32(13 + body.length), q, q, q, u16(x), u16(y), body), 10));
  }
  const rects = Array.from({ length: 1 + rnd(3) }, () => [rnd(w), rnd(h), 1 + rnd(w), 1 + rnd(h)]);
  const prog = Array.from({ length: np }, () => [rnd(256), ...quants(3)]).flat();
  return region(rects, quants(nq), prog, rnd(2), tiles);
}

// NSCodec and ClearCodec (MS-RDPEGFX 2.2.4.1)
function nsc(w, h) {
  const cs = rnd(2), rw = cs ? (w + 7) & ~7 : w, rh = cs ? (h + 1) & ~1 : h, chroma = cs ? (rw >> 1) * (rh >> 1) : w * h;
  const planes = [rw * h, chroma, chroma, w * h].map((size) => (chance(20) ? [] : chance(50) ? bytes(size) : bytes(rnd(size))));
  return cat(...planes.map((p) => u32(p.length)), chance(90) ? 1 + rnd(7) : rnd(256), cs, 0, 0, ...planes);
}
const runLength = () => pick([[rnd(255)], [0xff, ...u16(rnd(0xffff))], [0xff, 0xff, 0xff, ...u32(rnd(5000))]]);
function clear(w, h) {
  const flags = pick([0, 0, 0x04, 0x01, 0x05, 0x03, rnd(256)]);
  const residual = chance(60) ? cat(...Array.from({ length: rnd(6) }, () => cat(bytes(3), runLength()))) : [];
  const bands = chance(50) ? cat(...Array.from({ length: 1 + rnd(2) }, () => {
    const x0 = rnd(w), x1 = x0 + rnd(4), y0 = rnd(h), y1 = y0 + (chance(90) ? rnd(52) : rnd(300));
    return cat(u16(x0), u16(x1), u16(y0), u16(y1), bytes(3), ...Array.from({ length: x1 - x0 + 1 }, () => {
      const kind = rnd(4), on = rnd(10), count = rnd(20);
      return kind === 0 ? cat(u16(on | (on + count) << 8), bytes(3 * count)) : kind === 1 ? cat(u16(0x4000 | rnd(20)), rnd(10)) : u16(0x8000 | rnd(40));
    }));
  })) : [];
  const sub = chance(50) ? cat(...Array.from({ length: 1 + rnd(2) }, () => {
    const sw = 1 + rnd(16), sh = 1 + rnd(16), id = chance(90) ? rnd(3) : rnd(256);
    const data = id === 0 ? bytes(sw * sh * 3) : id === 1 ? nsc(sw, sh) : cat(1 + rnd(8), bytes(3 * 8), ...Array.from({ length: rnd(6) }, () => cat(rnd(256), runLength())));
    return cat(u16(rnd(w)), u16(rnd(h)), u16(sw), u16(sh), u32(data.length), id, data);
  })) : [];
  return cat(flags, 0, flags & 1 ? u16(rnd(4000)) : [], u32(residual.length), u32(bands.length), u32(sub.length), residual, bands, sub);
}

// graphics pipeline (MS-RDPEGFX): ZGFX segments and PDUs
const gfxPdu = (cmd, body) => cat(u16(cmd), u16(0), u32(8 + body.length), body);
const KEY = fromHex('000000016742c01f8c8d4020030d00f08846a0' + '0000000168ce3c80' + '0000000165');
function wire(id, w, h) {
  const l = rnd(16), t = rnd(16), r = chance(97) ? l + Math.min(w, 1 + rnd(64)) : rnd(0x10000), b = chance(97) ? t + Math.min(h, 1 + rnd(64)) : rnd(0x10000);
  const ww = Math.max(1, Math.min(r - l, 64)), hh = Math.max(1, Math.min(b - t, 64));
  const codec = pick([0, 3, 8, 10, 11, 12, 14, 15, rnd(16)]);
  const avc = () => cat(u32(1), u16(0), u16(0), u16(ww), u16(hh), 22, 100, maybe(cat(KEY, bytes(rnd(64))), 20));
  const data = {
    0: () => bytes(Math.min(ww * hh * 4, 16384)), 3: () => (chance(50) ? vector('rfx')[2] : rfxMessage(64, 64)), 8: () => (chance(30) ? vector('clear')[2] : clear(ww, hh)),
    10: () => planar(ww, hh), 11: avc, 12: () => cat(u16(0x414c), u16(rnd(2)), bytes(rnd(300))),
    14: () => { const a = avc(); return cat(u32(pick([0, 1, 2]) * 2 ** 30 + a.length), a, bytes(rnd(20))); },
  }[codec === 15 ? 14 : codec]?.() ?? bytes(rnd(64));
  return gfxPdu(1, cat(u16(id), u16(codec), pick([0x20, 0x21]), u16(l), u16(t), u16(r), u16(b), u32(data.length), maybe(data, 10)));
}
function gfxPdus() {
  const id = pick([1, 2]), w = chance(97) ? 64 + rnd(200) : pick([0, 4096, 65535]), h = chance(97) ? 64 + rnd(200) : pick([0, 4096, 65535]);
  const rect = () => cat(u16(rnd(w)), u16(rnd(h)), u16(rnd(w + 64)), u16(rnd(h + 64)));
  const pdus = chance(80) ? [gfxPdu(9, cat(u16(id), u16(w), u16(h), pick([0x20, 0x21])))] : [];
  for (let i = 1 + rnd(5); i > 0; i--) {
    pdus.push(maybe(pick([
      () => wire(id, w, h), () => wire(id, w, h),
      () => gfxPdu(2, cat(u16(id), u16(9), u32(1), 0x20, ...((d) => [...u32(d.length), ...d])(chance(50) ? vector('progressive')[2] : progressive(w, h, chance(30))))),
      () => gfxPdu(4, cat(u16(id), bytes(4), u16(2), rect(), rect())),
      () => gfxPdu(5, cat(u16(id), u16(pick([1, 2])), rect(), u16(2), u16(rnd(w)), u16(rnd(h)), u16(rnd(w)), u16(rnd(h)))),
      () => gfxPdu(6, cat(u16(id), bytes(8), u16(rnd(10)), rect())), () => gfxPdu(7, cat(u16(rnd(10)), u16(id), u16(1), u16(rnd(w)), u16(rnd(h)))),
      () => gfxPdu(8, u16(rnd(10))), () => gfxPdu(10, u16(id)), () => gfxPdu(pick([15, 23]), cat(u16(id), u16(0), u32(rnd(100)), u32(rnd(100)), u32(w), u32(h))),
      () => gfxPdu(14, cat(u32(w), u32(h), u32(1), bytes(20))), () => gfxPdu(11, cat(u32(0), u32(rnd(10)))), () => gfxPdu(12, u32(rnd(10))),
      () => gfxPdu(19, cat(u32(0x000a0701), u32(4), u32(0x80))), () => gfxPdu(rnd(32), bytes(rnd(24))),
    ])(), 10));
  }
  return cat(...pdus);
}
function zgfx() {
  return pick([
    () => fromHex('E00454686520717569636B2062726F776E20666F78206A756D7073206F76657220746865206C617A7920646F67'),
    () => fromHex('E103002B000000110000000454686520717569636B2062726F776E200E00000004666F78206A756D7073206F7665100000002439080E91F8D8613D1E440643799C02'),
    () => cat(0xe0, 0x04, bytes(rnd(200))), () => cat(0xe0, 0x24, bytes(1 + rnd(200)), rnd(8)),
    () => { const segs = Array.from({ length: 1 + rnd(3) }, () => (chance(50) ? cat(0x04, bytes(rnd(100))) : cat(0x24, bytes(1 + rnd(100)), rnd(8)))); return cat(0xe1, u16(segs.length), u32(0), ...segs.map((s) => cat(u32(s.length), s))); },
  ])();
}

// ------------------------------------------------------------------ targets: {cases, gen() -> {opts, inputs}, run(case, modules, fail)}

const sized = (n, w, h, what) => n === w * h ? null : `${what}: ${w} x ${h} with ${n} pixels`;
const TARGETS = {
  bitmap: {                                            // bitmap updates: interleaved RLE, planar, raw; palette
    cases: 300,
    gen() {
      const rects = [];
      for (let i = 1 + rnd(3); i > 0; i--) {
        const bpp = pick([8, 15, 16, 24, 32]), Bpp = (bpp + 7) >> 3, size = () => (chance(95) ? 1 + rnd(64) : pick([0, 65, 300, 2048, 65535]));
        const w = size(), h = size(), compressed = chance(75), header = compressed && chance(40);
        let data = compressed ? (bpp === 32 ? planar(w, h) : rle(Bpp)) : bytes(Math.min(((w * Bpp + 3) & ~3) * h, 16384));
        if (header) data = cat(u16(0), u16(data.length), u16((w * Bpp + 3) & ~3), u16(w * h * Bpp), data);
        data = maybe(data, 15).subarray(0, 65535);
        rects.push(cat(u16(0), u16(0), u16(w - 1), u16(h - 1), u16(w), u16(h), u16(bpp), u16((compressed ? 1 : 0) | (compressed && !header ? 0x400 : 0)), u16(data.length), data));
      }
      const palette = chance(30);
      return { opts: { palette }, inputs: [...(palette ? [maybe(cat(u16(2), u16(0), u32(chance(90) ? 256 : rnd(1000)), bytes(768)))] : []), maybe(cat(u16(1), u16(rects.length), ...rects), 10)] };
    },
    run({ opts, inputs }, M) {
      const palette = new Uint32Array(256);
      if (opts.palette) M.paletteUpdate(inputs[0], palette);
      M.bitmapUpdate(inputs.at(-1), palette, () => {}, DESKTOP);
    },
  },
  pointer: {                                           // color, new and large pointers
    cases: 500,
    gen() {
      const kind = pick([9, 11, 12]), bpp = kind === 9 ? 24 : pick([1, 16, 24, 32, 32, 8, 0]), size = () => (chance(90) ? 1 + rnd(48) : pick([0, 96, 384, 385, 65535]));
      const w = size(), h = size(), xorLen = Math.min(((((w * bpp + 7) >> 3) + 1) & ~1) * h, 40000), andLen = Math.min(((((w + 7) >> 3) + 1) & ~1) * h, 10000);
      const lens = kind === 12 ? [...u32(andLen), ...u32(xorLen)] : [...u16(andLen), ...u16(xorLen)];
      return { opts: { kind }, inputs: [maybe(cat(kind === 9 ? [] : u16(bpp), u16(rnd(20)), u16(rnd(w + 1)), u16(rnd(h + 1)), u16(w), u16(h), lens, bytes(xorLen), chance(80) ? bytes(andLen) : []))] };
    },
    run({ opts, inputs }, M, fail) {
      const p = M.pointerDecode(inputs[0], opts.kind);
      const bad = sized(p.rgba.length, p.w, p.h, 'pointer');
      if (bad) fail(bad, 'size pointer');
      M.isTextCursor(p);
    },
  },
  orders: {                                            // drawing orders and the bitmap and glyph caches, one parser per case
    cases: 500,
    gen() {
      const inputs = [];
      for (let i = 1 + rnd(3); i > 0; i--) {
        const list = Array.from({ length: 1 + rnd(5) }, () => maybe(chance(30) ? Uint8Array.from(pick(ORDERS)) : order(), 15));
        inputs.push(maybe(cat(u16(chance(90) ? list.length + rnd(3) : rnd(0x10000)), ...list), 10));
      }
      return { opts: { bpp: pick([8, 15, 16, 24, 32]) }, inputs };
    },
    run({ opts, inputs }, M, fail) {
      const parser = new M.OrderParser(), palette = new Uint32Array(256);
      const draw = (o) => { const bad = o.px && sized(o.px.length, o.w, o.h, 'cached bitmap'); if (bad) fail(bad, 'size cache'); };
      for (const u of inputs) parser.parse(u, opts.bpp, draw, palette, DESKTOP);
    },
  },
  rfx: {                                               // RemoteFX messages, RLGR on its own, dequantization and DWT
    cases: 300,
    gen() {
      const [w, h, data] = chance(40) ? vector('rfx') : [64 * (1 + rnd(3)), 64 * (1 + rnd(3)), rfxMessage(128, 128)];
      return { opts: { w, h, mode: pick([1, 3]), quant: Array.from({ length: 10 }, () => pick([0, 1, 6, 9, 15])) },
        inputs: [maybe(data), ...(chance(30) ? [maybe(rfxMessage(w, h))] : []), bytes(rnd(400))] };
    },
    run({ opts: { w, h, mode, quant }, inputs }, M, fail) {
      const rfx = new M.RfxDecoder();
      for (const s of inputs.slice(0, -1)) { const bad = sized(rfx.decode(s, w, h).length, w, h, 'RemoteFX'); if (bad) fail(bad, 'size rfx'); }
      const buf = M.rlgrDecode(inputs.at(-1), mode, new Int16Array(4096));
      M.dequantize(buf, quant);
      M.idwt(buf);
      M.ycbcrToRgba(buf, buf, buf, new Uint32Array(4096), 64, 0, 0);
    },
  },
  progressive: {                                       // RemoteFX progressive: first passes and upgrade passes on one decoder
    cases: 300,
    gen() {
      const [w, h, data] = chance(30) ? vector('progressive') : [64 * (1 + rnd(4)), 64 * (1 + rnd(3)), null];
      return { opts: { w, h }, inputs: [maybe(data ?? progressive(w, h, false)), ...Array.from({ length: rnd(3) }, () => maybe(progressive(w, h, chance(70))))] };
    },
    run({ opts: { w, h }, inputs }, M, fail) {
      const pd = new M.ProgressiveDecoder();
      for (const s of inputs) {
        for (const b of pd.decode(1, s, w, h)) {
          const bad = sized(b.px.length, b.w, b.h, 'progressive box') ?? (b.w > 0 && b.h > 0 && b.x + b.w <= w && b.y + b.h <= h ? null : `progressive box ${b.x},${b.y} ${b.w} x ${b.h} outside ${w} x ${h}`);
          if (bad) fail(bad, 'size progressive');
        }
      }
    },
  },
  nsc: {                                               // NSCodec
    cases: 400,
    gen() {
      const [w, h, data] = chance(30) ? vector('nsc') : [1 + rnd(64), 1 + rnd(64), null];
      return { opts: { w, h }, inputs: [maybe(data ?? nsc(w, h))] };
    },
    run({ opts: { w, h }, inputs }, M, fail) {
      const bad = sized(M.nscDecode(inputs[0], w, h).length, w, h, 'NSCodec');
      if (bad) fail(bad, 'size nsc');
    },
  },
  clear: {                                             // ClearCodec, its caches kept across the messages of a case
    cases: 400,
    gen() {
      const [w, h, data] = chance(30) ? vector('clear') : [1 + rnd(80), 1 + rnd(60), null];
      return { opts: { w, h }, inputs: [maybe(data ?? clear(w, h)), ...Array.from({ length: rnd(3) }, () => maybe(clear(w, h)))] };
    },
    run({ opts: { w, h }, inputs }, M, fail) {
      const codec = new M.ClearCodec();
      for (const s of inputs) {
        const r = codec.decode(s, w, h), bad = r.px && sized(r.px.length, w, h, 'ClearCodec');
        if (bad) fail(bad, 'size clear');
      }
    },
  },
  zgfx: {                                              // ZGFX, one history per case
    cases: 500,
    gen() {
      return { opts: {}, inputs: Array.from({ length: 1 + rnd(4) }, () => maybe(zgfx())) };
    },
    run({ inputs }, M) {
      const z = new M.Zgfx();
      for (const u of inputs) z.decompress(u);
    },
  },
  rdpgfx: {                                            // graphics pipeline: PDUs, codecs, H.264 (WebCodecs double), its log
    cases: 400,
    gen() {
      return { opts: { stats: chance(30) }, inputs: Array.from({ length: 1 + rnd(3) }, () => (chance(90) ? maybe(cat(0xe0, 0x04, gfxPdus()), 5) : cat(0xe0, 0x24, bytes(rnd(100)), rnd(8)))) };
    },
    run({ opts, inputs }, M, fail) {
      const own = /^(gfx: )?(fuzz: |H\.264: |(ZGFX|NSCodec|ClearCodec|SPS|GFX|Planar|RLE|RemoteFX( progressive)?): |Offset is outside the bounds of the DataView)/;
      const p = new M.GfxPipeline((o) => {
        if (o.op === 'log' && !own.test(o.msg)) fail(`pipeline log: ${o.msg}`, `log ${o.msg.replace(/[\d-]+/g, 'N')}`);
        const bad = (o.op === 'px' && sized(o.px.length, o.w, o.h, 'pixels')) || (o.op === 'alpha' && sized(o.a.length, o.w, o.h, 'alpha'));
        if (bad) fail(bad, `size ${o.op}`);
      });
      if (opts.stats) p.stats = {};
      for (const u of inputs) p.onData(u);
    },
  },
  bulk: {                                              // MPPC, NCRUSH, XCRUSH through frames 10 and 11 of the gateway, or alone
    cases: 300,
    gen() {
      const frames = chance(50);
      return { opts: { frames }, inputs: compressed(1 + rnd(5)).map(([flags, ...data]) => maybe(frames
        ? (chance(80) ? cat(pick([0, 1, 2, 9, 10, 11, 12]), chance(80) ? 0 : rnd(4), flags, data) : cat(flags, pick([0x02, 0x1b, rnd(256)]), data))
        : cat(flags, data))) };
    },
    run({ opts, inputs }, M) {
      const stream = new M.CompressedStream(), bulk = new M.Bulk();
      for (const u of inputs) {
        if (!opts.frames) bulk.decompress(u.subarray(1), u[0] ?? 0);
        else if (u[2] & 0xe0 || u[1] < 4) stream.update(u);
        else stream.slow(u);
      }
    },
  },
};

// ------------------------------------------------------------------ worker: runs cases, reports failures

/** Where an error was raised: file, line and function of the first frame in the browser code. */
function where(e) {
  for (const line of String(e?.stack).split('\n').slice(1)) {
    const m = line.match(/at (?:(.+?) \()?.*\/public\/assets\/([\w-]+\.js):(\d+):\d+\)?$/);
    if (m) return `${m[2]}:${m[3]}${m[1] ? ' in ' + m[1].replace(/^new /, '') : ''}`;
  }
  return 'test';
}

/** The decoders' own errors, or a read past the end of the data. */
const expected = (e) => e?.constructor === Error || (e instanceof RangeError && e.message.includes('outside the bounds of the DataView'));
const view = ({ opts, inputs }) => ({ opts, inputs: inputs.map((u) => Buffer.from(u).toString('hex')) });

async function work({ target, seed, from, count, progress }) {
  const problems = [], fail = (text, key = text) => problems.push({ text, key });
  // large allocations are refused (and recorded even if the decoder catches the error)
  for (const name of ['ArrayBuffer', 'Int8Array', 'Uint8Array', 'Uint8ClampedArray', 'Int16Array', 'Uint16Array', 'Int32Array', 'Uint32Array', 'Float32Array', 'Float64Array']) {
    const T = globalThis[name], size = T.BYTES_PER_ELEMENT ?? 1;
    globalThis[name] = new Proxy(T, {
      construct(target, args, newTarget) {
        if (typeof args[0] === 'number' && args[0] * size > ALLOC) {
          const e = Object.assign(new Error(`fuzz: allocation of ${Math.round(args[0] * size / 1048576)} MB`), { fuzz: true });
          fail(`${e.message.slice(6)} (${where(e)})`, `allocation ${where(e)}`);
          throw e;
        }
        return Reflect.construct(target, args, newTarget);
      },
    });
  }
  globalThis.VideoDecoder = class {                    // WebCodecs double: a key frame first, then one frame out per chunk
    constructor(o) { this.o = o; this.key = false; }
    configure() {}
    decode(c) {
      if (!this.key && c.type !== 'key') throw new Error('A key frame is required after configure() or flush().');
      this.key = true;
      this.o.output({ close() {} });
    }
    close() {}
  };
  globalThis.EncodedVideoChunk = class { constructor(o) { Object.assign(this, o); } };
  const M = {};
  for (const m of ['gfx', 'orders', 'rfx', 'rdpgfx', 'bulk']) Object.assign(M, await import(new URL(`../public/assets/${m}.js`, import.meta.url)));
  const at = new Int32Array(progress), T = TARGETS[target];
  let inputs = 0;
  for (let n = from; n < from + count; n++) {
    Atomics.store(at, 0, n);
    seedCase(seed, target, n);
    const c = T.gen();
    problems.length = 0;
    const t0 = performance.now();
    try {
      T.run(c, M, fail);
    } catch (e) {
      if (!e?.fuzz && !expected(e)) fail(`${e?.name ?? typeof e}: ${e?.message ?? e} (${where(e)})`, `${e?.name} ${where(e)}`);
    }
    const ms = performance.now() - t0;
    if (ms > SLOW) fail(`slow: ${Math.round(ms)} ms`, 'slow');
    if (problems.length) parentPort.postMessage({ n, problems, ...view(c) });
    inputs += c.inputs.length;
  }
  parentPort.postMessage({ done: true, inputs });
}

// ------------------------------------------------------------------ main: a worker per target, restarted after a hang

/** Runs cases [from, from + count) of a target; resolves {inputs} or, if the worker hangs or dies, {stuck: case, text, key}. */
function runWorker(target, seed, from, count, onProblems) {
  return new Promise((resolve) => {
    const progress = new SharedArrayBuffer(4), at = new Int32Array(progress);
    Atomics.store(at, 0, from);
    const w = new Worker(new URL(import.meta.url), { workerData: { target, seed, from, count, progress } });
    let last = from, since = performance.now(), result = null;
    const finish = (r) => { if (!result) { result = r; clearInterval(timer); w.terminate(); resolve(r); } };
    const timer = setInterval(() => {
      const n = Atomics.load(at, 0);
      if (n !== last) [last, since] = [n, performance.now()];
      else if (performance.now() - since > HANG) finish({ stuck: n, text: `hang: no end after ${HANG / 1000} s`, key: 'hang' });
    }, 100);
    w.on('message', (m) => (m.done ? finish(m) : onProblems(m)));
    w.on('error', (e) => finish({ stuck: Atomics.load(at, 0), text: `worker died: ${e.message}`, key: 'died' }));
    w.on('exit', () => finish({ stuck: Atomics.load(at, 0), text: 'worker ended', key: 'died' }));
  });
}

async function main() {
  const args = Object.fromEntries(process.argv.slice(2).map((a) => a.replace(/^--/, '').split('=')));
  const seed = Number(args.seed ?? 1), only = args.target, one = args.case === undefined ? undefined : Number(args.case);
  if ((only !== undefined && !TARGETS[only]) || (one !== undefined && only === undefined)) {
    console.error(`Usage: node tests/fuzz.mjs [--iterations=N] [--seed=S] [--target=NAME [--case=K]] [--strict]\nTargets: ${Object.keys(TARGETS).join(', ')}`);
    process.exit(2);
  }
  console.log(`Fuzz seed ${seed}${args.iterations ? `, ${args.iterations} cases per target` : ''}`);
  let failed = 0, pending = 0, total = 0;
  const started = performance.now();
  for (const name of only ? [only] : Object.keys(TARGETS)) {
    const first = one ?? 0, cases = one === undefined ? Math.max(1, Number(args.iterations ?? TARGETS[name].cases)) : 1;
    const seen = new Map(), t0 = performance.now();
    let inputs = 0;
    const found = (n, { text, key }, { opts, inputs }) => {
      seen.set(key, (seen.get(key) ?? 0) + 1);
      if (seen.get(key) > 1) return;
      const known = 'strict' in args ? undefined : KNOWN.find(([re]) => re.test(text));
      console.log(`${known ? 'PEND' : 'FAIL'} ${name} case ${n}: ${text}${known ? `\n     known issue: ${known[1]}` : ''}`
        + `\n     replay: node tests/fuzz.mjs --seed=${seed} --target=${name} --case=${n}\n     options: ${JSON.stringify(opts)}`);
      inputs.forEach((h, i) => console.log(`     input ${i + 1}: ${one !== undefined || h.length <= 400 ? h : `${h.slice(0, 400)}... (${h.length / 2} bytes)`}`));
      if (known) pending++; else failed++;
    };
    for (let from = first; from < first + cases;) {
      const r = await runWorker(name, seed, from, first + cases - from, (m) => m.problems.forEach((p) => found(m.n, p, m)));
      if (r.stuck === undefined) { inputs += r.inputs; break; }
      seedCase(seed, name, r.stuck);
      found(r.stuck, r, view(TARGETS[name].gen()));
      from = r.stuck + 1;
    }
    for (const [key, n] of seen) if (n > 1) console.log(`     (${key}: also in ${n - 1} more cases)`);
    console.log(`${seen.size ? '    ' : 'OK  '} ${name}: ${cases} cases, ${inputs} inputs, ${((performance.now() - t0) / 1000).toFixed(1)} s`);
    total += inputs;
  }
  console.log(`\n${failed ? `${failed} fuzz failure(s)` : `All fuzz tests passed${pending ? `, ${pending} known issue(s) pending` : ''}`} (${total} inputs in ${((performance.now() - started) / 1000).toFixed(1)} s)`);
  process.exit(failed ? 1 : 0);
}

if (isMainThread) await main();
else await work(workerData);
