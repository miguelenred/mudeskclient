// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// Unit tests for the drawing order parser (encodings built by hand following MS-RDPEGDI 2.2.2.2.1.1).
import { OrderParser, orderColor, rop3 } from '../public/assets/orders.js';

let fail = 0;
const eq = (name, got, want) => {
  const ok = JSON.stringify(got) === JSON.stringify(want);
  fail += ok ? 0 : 1;
  console.log((ok ? 'OK   ' : 'FAIL ') + name + (ok ? '' : `\n  got  ${JSON.stringify(got)}\n  want ${JSON.stringify(want)}`));
};
const i16 = (v) => [v & 255, (v >> 8) & 255];
const run = (bytes, bpp = 32, parser = new OrderParser()) => {
  const out = [];
  const ok = parser.parse(new Uint8Array([bytes.count & 255, bytes.count >> 8, ...bytes.data]), bpp, (c) => out.push(c));
  return { ok, out, parser };
};

// 1) ScrBlt, type change, all 7 fields, absolute coordinates
let r = run({ count: 1, data: [0x09, 0x02, 0x7f, ...i16(10), ...i16(20), ...i16(300), ...i16(200), 0xcc, ...i16(10), ...i16(40)] });
eq('ScrBlt absolute', r.out, [{ op: 'copy', x: 10, y: 20, w: 300, h: 200, sx: 10, sy: 40, clip: null }]);

// 2) same parser: ScrBlt again with delta coordinates, only top and nYSrc changed
r = run({ count: 1, data: [0x11, 0x42, 0x5, 0x80] }, 32, r.parser);   // fields 2 and 7? -> flags 0x42
eq('ScrBlt delta', r.out, [{ op: 'copy', x: 10, y: 25, w: 300, h: 200, sx: 10, sy: -88, clip: null }]);

// 3) OpaqueRect 16 bpp (white 0xffff) with bounds (absolute left/top/right/bottom)
r = run({ count: 1, data: [0x0d, 0x0a, 0x7f, 0x0f, ...i16(0), ...i16(0), ...i16(99), ...i16(49), ...i16(0), ...i16(0), ...i16(640), ...i16(480), 0xff, 0xff, 0x00] }, 16);
eq('OpaqueRect + bounds', r.out, [{ op: 'fill', x: 0, y: 0, w: 640, h: 480, color: 0xffffffff, clip: { l: 0, t: 0, r: 99, b: 49 } }]);

// 4) zero-bounds-delta reuses previous bounds; only the blue byte changes (field 7)
r = run({ count: 1, data: [0x25, 0x40, 0x00] }, 16, r.parser);
eq('OpaqueRect zero bounds delta', r.out, [{ op: 'fill', x: 0, y: 0, w: 640, h: 480, color: 0xffffffff, clip: { l: 0, t: 0, r: 99, b: 49 } }]);

// 5) DstBlt DSTINVERT, then BLACKNESS, then DSTCOPY (ignored)
r = run({ count: 3, data: [0x09, 0x00, 0x1f, ...i16(1), ...i16(2), ...i16(3), ...i16(4), 0x55, 0x01, 0x10, 0x00, 0x01, 0x10, 0xaa] });
eq('DstBlt ROPs', r.out.map((o) => o.op + (o.color ? ':' + o.color.toString(16) : '')), ['invert', 'fill:ff000000']);

// 6) PatBlt solid brush, PATCOPY, colour fields 6 and 7 (3 bytes), brush fields skipped
r = run({ count: 1, data: [0x09, 0x01, 0xff, 0x0f, ...i16(5), ...i16(6), ...i16(7), ...i16(8), 0xf0, 0, 0, 0, 0x33, 0x22, 0x11, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0] });
eq('PatBlt', r.out, [{ op: 'fill', x: 5, y: 6, w: 7, h: 8, color: orderColor(0x112233, 32), clip: null }]);

// 7) unknown order type stops parsing
r = run({ count: 1, data: [0x09, 0x16, 0x00] });
eq('unknown order', r.ok, false);

// 8) cache bitmap v2 (32 bpp, uncompressed, 2x2, height = width, cache 1 index 5), then MemBlt drawing from it
const extra = 1 | (6 << 3) | (0x01 << 7);
r = run({ count: 2, data: [0x03, ...i16(12), ...i16(extra), 4, 0x02, 0x10, 0x05,
  1, 2, 3, 0, 4, 5, 6, 0, 7, 8, 9, 0, 10, 11, 12, 0,                                  // rows bottom-up, B G R X
  0x09, 0x0d, 0xff, 0x01, ...i16(1), ...i16(100), ...i16(50), ...i16(2), ...i16(2), 0xcc, ...i16(0), ...i16(0), ...i16(5)] });
eq('cache bitmap v2', r.out[0] && [r.out[0].op, r.out[0].id, r.out[0].idx, r.out[0].w, r.out[0].h, [...r.out[0].px].map((v) => v.toString(16))],
  ['cache', 1, 5, 2, 2, ['ff070809', 'ff0a0b0c', 'ff010203', 'ff040506']]);
eq('MemBlt', r.out[1], { op: 'mem', id: 1, idx: 5, x: 100, y: 50, w: 2, h: 2, sx: 0, sy: 0, rop: 0xcc, clip: null });
// ... not stored when larger than the desktop (max pixels, from stream-worker.js): its size, from the data, asked for gigabytes
const cached = [];
new OrderParser().parse(new Uint8Array([1, 0, 0x03, ...i16(12), ...i16(extra), 4, 0x02, 0x10, 0x05, ...Array(16).fill(0)]), 32, (c) => cached.push(c), undefined, 3);
eq('cache bitmap v2 larger than the desktop', cached, []);

// 9) other secondary orders (here a brush cache) are skipped by their length
r = run({ count: 2, data: [0x03, ...i16(0), ...i16(0), 7, 0, 0, 0, 0, 0, 0, 0, 0x09, 0x0a, 0x0f, ...i16(1), ...i16(2), ...i16(3), ...i16(4)] });
eq('secondary order skipped', r.ok && r.out.length === 1 && r.out[0].op, 'fill');

// 10) glyphs: Cache Glyph (revision 2, sent as order type 3 for glyph support level "encode", as xrdp does: cache 2,
// index 5: 3x2 at offset 0,-8 in the 1-2 byte encodings, rows padded to 4 bytes), then GlyphIndex with
// variable pitch (two glyphs 10 px apart), an opaque rectangle (foreground colour field) and text in the background field
const g = new OrderParser();
r = run({ count: 1, data: [0x03, ...i16(9 - 7), ...i16(2 | (1 << 8)), 3, 5, 0x00, 0x48, 3, 2, 0xa0, 0x40, 0, 0] }, 32, g);
eq('cache glyph', r.out.map((o) => [o.op, o.key, o.w, o.h, [...o.bits]]), [['glyph', 517, 3, 2, [0xa0, 0x40]]]);
const gi = (data, more = []) => [0x09, 0x1b, 0xff, 0x3f, 0x38, 2, 0x02, 0, 0, 0x11, 0x22, 0x33, 0x44, 0x55, 0x66,
  ...i16(100), ...i16(40), ...i16(140), ...i16(52), ...i16(98), ...i16(40), ...i16(150), ...i16(52), ...i16(100), ...i16(50), data.length, ...data, ...more];
r = run({ count: 1, data: gi([5, 0, 5, 10]) }, 32, g);
eq('GlyphIndex', r.out, [{ op: 'fill', x: 98, y: 40, w: 53, h: 13, color: orderColor(0x665544, 32), clip: null },
  { op: 'text', color: orderColor(0x332211, 32), glyphs: [517, 100, 42, 517, 110, 42], x: 100, y: 40, w: 41, h: 13 }]);

// 11) fragments: add the bytes "5 0 5 10" as fragment 1, then use it 30 px further (its own first delta is 0)
r = run({ count: 1, data: gi([5, 0, 5, 10, 0xff, 1, 4, 0xfe, 1, 30]) }, 32, g);
eq('fragment add and use', r.out[1].glyphs, [517, 100, 42, 517, 110, 42, 517, 140, 42, 517, 150, 42]);

// 12) FastGlyph: defines glyph 7 of cache 2 (offset 1,-4 with the 2-byte encodings, 2x1) and draws it; opaque rect from bk
r = run({ count: 1, data: [0x09, 0x18, 0xff, 0x7f, 2, 0, 0x02, 0x11, 0x22, 0x33, 0x44, 0x55, 0x66, ...i16(10), ...i16(20), ...i16(30), ...i16(29),
  ...i16(0), ...i16(0x0f), ...i16(0), ...i16(-32768), ...i16(12), ...i16(28), 7, 7, 1, 0x44, 2, 1, 0xc0] }, 32, g);
eq('FastGlyph', r.out.map((o) => o.op === 'glyph' ? [o.key, o.w, o.h, [...o.bits]] : o.op === 'fill' ? [o.x, o.y, o.w, o.h] : o.glyphs),
  [[519, 2, 1, [0xc0]], [10, 20, 21, 10], [519, 13, 24]]);

// 12b) FastGlyph with fixed pitch (ulCharInc 8) draws its one glyph only: index 5 of cache 0 at 10,10, not also glyph 0 at 18,10
const fg = new OrderParser();
const fastGlyph = (idx, inc) => [0x09, 0x18, 0xff, 0x7f, 0, inc, 0x02, 0x11, 0x22, 0x33, 0x44, 0x55, 0x66, ...i16(0), ...i16(0), ...i16(100), ...i16(100),
  ...i16(0), ...i16(0), ...i16(0), ...i16(0), ...i16(10), ...i16(10), 6, idx, 0, 0, 1, 1, 0x80];
run({ count: 1, data: fastGlyph(0, 0) }, 32, fg);                                  // defines glyph 0 of cache 0
r = run({ count: 1, data: fastGlyph(5, 8) }, 32, fg);
eq('FastGlyph with fixed pitch: one glyph', r.out.find((o) => o.op === 'text')?.glyphs, [5, 10, 10]);

// 13) FastIndex with fixed pitch (ulCharInc 6) using glyphs 5 and 7; cache glyph (cache 2, index 9) first
r = run({ count: 2, data: [0x03, ...i16(9 - 7), ...i16(2 | (1 << 8)), 3, 9, 0x41, 0x02, 1, 1, 0x80, 0, 0, 0,
  0x09, 0x13, 0xff, 0x7f, 2, 6, 0x02, 0x11, 0x22, 0x33, 0x44, 0x55, 0x66, ...i16(0), ...i16(0), ...i16(99), ...i16(99),
  ...i16(1), ...i16(1), ...i16(2), ...i16(2), ...i16(50), ...i16(60), 3, 5, 7, 9] }, 32, g);
eq('cache glyph + FastIndex', [r.out[0].key, r.out[0].w, r.out[0].h, r.out[2].glyphs], [521, 1, 1, [517, 50, 52, 519, 57, 56, 521, 61, 62]]);

// 14) PatBlt PATCOPY with an 8x8 pattern brush (style 3): first row in the hatch field, the other 7 in reverse order
r = run({ count: 1, data: [0x09, 0x01, 0xff, 0x0f, ...i16(5), ...i16(6), ...i16(7), ...i16(8), 0xf0, 0x11, 0x11, 0x11, 0x22, 0x22, 0x22, 2, 3, 3, 0xaa, 1, 2, 3, 4, 5, 6, 7] });
eq('PatBlt pattern brush', r.out, [{ op: 'pattern', x: 5, y: 6, w: 7, h: 8, rows: [0xaa, 7, 6, 5, 4, 3, 2, 1], ox: 2, oy: 3,
  set: orderColor(0x111111, 32), clear: orderColor(0x222222, 32), rop: 0xf0, clip: null }]);

// 14b) PatBlt PATINVERT XORs the brush, that pattern and then a solid one (style 0: the foreground field), instead of
// inverting the whole rectangle; MemBlt sends its ROP (SRCAND here; they were all drawn as a copy)
r = run({ count: 3, data: [0x09, 0x01, 0xff, 0x0f, ...i16(5), ...i16(6), ...i16(7), ...i16(8), 0x5a, 0x11, 0x11, 0x11, 0x22, 0x22, 0x22, 2, 3, 3, 0xaa, 1, 2, 3, 4, 5, 6, 7,
  0x01, 0x00, 0x02, 0,                                                                 // field 10: brush style 0
  0x09, 0x0d, 0xff, 0x01, ...i16(1), ...i16(100), ...i16(50), ...i16(2), ...i16(2), 0x88, ...i16(0), ...i16(0), ...i16(5)] });
const patinvert = { op: 'pattern', x: 5, y: 6, w: 7, h: 8, rows: [0xaa, 7, 6, 5, 4, 3, 2, 1], ox: 2, oy: 3, set: orderColor(0x111111, 32), clear: orderColor(0x222222, 32), rop: 0x5a, clip: null };
eq('PatBlt PATINVERT with a pattern and a solid brush, MemBlt SRCAND', r.out, [patinvert, { ...patinvert, rows: [0, 0, 0, 0, 0, 0, 0, 0] },
  { op: 'mem', id: 1, idx: 5, x: 100, y: 50, w: 2, h: 2, sx: 0, sy: 0, rop: 0x88, clip: null }]);

// 14c) raster operations pixel by pixel (main thread): MemBlt ROPs on S and D (the brush of MemBlt is white: MERGECOPY P & S
// is S, PATPAINT P | ~S | D is white), alpha always opaque; PATINVERT XORs each pixel with its brush pixel from the origin
const S = 0x0f0f0f, D = 0x123456;
const memRops = [[0x88, S & D], [0xee, S | D], [0x66, S ^ D], [0x33, ~S], [0x44, S & ~D], [0x11, ~(S | D)], [0xbb, ~S | D], [0xcc, S], [0xc0, S], [0xfb, ~0]];
eq('MemBlt ROPs', memRops.map(([rop]) => { const d = Uint32Array.of(D); rop3({ rop }, d, Uint32Array.of(S), 0, 0, 1, 1); return d[0].toString(16); }),
  memRops.map(([, v]) => ((v | 0xff000000) >>> 0).toString(16)));
const dst = new Uint32Array(8).fill(0xff102030);                                      // 8x1 at 3,5; brush origin 1,0: row 5, column 0 at x = 9
rop3({ rop: 0x5a, rows: [0, 0, 0, 0, 0, 0x80, 0, 0], set: 0xff0000ff, clear: 0xff00ff00, ox: 1, oy: 0 }, dst, null, 3, 5, 8, 1);
eq('PATINVERT pixel by pixel', [...dst].map((v) => v.toString(16)), [...Array(8)].map((_, i) => ((0xff102030 ^ (i === 6 ? 0xff : 0xff00)) | 0xff000000) >>> 0).map((v) => v.toString(16)));

// 15) corrupt data ends the update (parse returns false) instead of throwing: it closed the whole session
const safe = (bytes, bpp) => { try { return run(bytes, bpp).ok; } catch (e) { return 'threw ' + e.message; } };
eq('corrupt cached bitmap', safe({ count: 1, data: [0x03, ...i16(5 - 7), ...i16(0x0420), 5, 8, 8, 1, 0, 0xa0] }, 16), false);   // RLE byte 0xa0
eq('truncated MemBlt', safe({ count: 1, data: [0x09, 0x0d, 0x01] }), false);

// 16) a glyph whose bitmap is not there, or larger than the largest cache cell (2048 bytes), is not stored: an 8192x8192
// glyph without data froze the main thread building its path
r = run({ count: 1, data: [0x03, ...i16(7 - 7), ...i16(1 << 8), 3, 7, 0, 0, 0xa0, 0x00, 0xa0, 0x00] });
eq('oversized cache glyph', r.out, []);
r = run({ count: 1, data: [0x09, 0x18, 0xff, 0x7f, 2, 0, 0x02, 0x11, 0x22, 0x33, 0x44, 0x55, 0x66, ...i16(10), ...i16(20), ...i16(30), ...i16(29),
  ...i16(0), ...i16(0x0f), ...i16(0), ...i16(-32768), ...i16(12), ...i16(28), 7, 7, 0, 0, 0xa0, 0x00, 0xa0, 0x00] });
eq('oversized FastGlyph', r.out.filter((o) => o.op === 'glyph'), []);

// ... and only within the cells offered (600, 600 and 1024 entries of up to 256, 1024 and 4096 pixels): a server could fill
// 65,535 entries of any size
{
  const two = (v) => (v < 0x80 ? [v] : [0x80 | (v >> 8), v & 255]);
  const four = (v) => (v < 0x40 ? [v] : v < 0x4000 ? [0x40 | (v >> 8), v & 255] : [0x80 | (v >> 16), (v >> 8) & 255, v & 255]);
  const v2 = (id, w, idx) => {
    const body = [...two(w), ...four(w * w * 4), ...two(idx), ...Array(w * w * 4).fill(0)];
    return [0x03, ...i16(body.length - 7), ...i16(id | (6 << 3) | (1 << 7)), 4, ...body];
  };
  const stored = (id, w, idx) => run({ count: 1, data: v2(id, w, idx) }).out.length;
  eq('cache bitmap v2 within the cells offered', [stored(0, 2, 599), stored(0, 2, 600), stored(0, 16, 1), stored(0, 17, 1), stored(1, 32, 599),
    stored(1, 33, 1), stored(2, 64, 1023), stored(2, 64, 1024), stored(2, 65, 1), stored(3, 2, 1)], [1, 0, 1, 0, 1, 0, 1, 0, 0, 0]);
}
// RemoteApp windowing orders (alternate secondary, TS_ALTSEC_WINDOW): a new window with its fields, one changed (only the
// fields sent), one deleted, the desktop (active window and z-order), a window icon (skipped whole), and the next order of
// the update still read
{
  const u32 = (v) => [v & 255, (v >> 8) & 255, (v >> 16) & 255, (v >>> 24) & 255];
  const s16 = (s) => [...i16(s.length * 2), ...[...s].flatMap((c) => i16(c.charCodeAt(0)))];
  const rect = (l, t, r, b) => [...i16(l), ...i16(t), ...i16(r), ...i16(b)];
  const order = (flags, body) => { const size = 7 + body.length; return [0x2e, ...i16(size), ...u32(flags), ...body]; };
  const WIN = 0x01000000, NEW = 0x10000000, DEL = 0x20000000;
  const fresh = order(WIN | NEW | 0x2 | 0x8 | 0x10 | 0x4 | 0x800 | 0x400 | 0x1000 | 0x200, [...u32(0x20004), ...u32(0), ...u32(0x16CF0000), ...u32(0x100), 5,
    ...s16('Bloc de notas'), ...u32(100), ...u32(-20), ...u32(640), ...u32(480), ...u32(108), ...u32(-20), ...i16(2), ...rect(0, 0, 624, 30), ...rect(0, 30, 624, 470)]);
  const shown = order(WIN | 0x10, [...u32(0x20004), 2]);
  const gone = order(WIN | DEL, u32(0x20008));
  const desk = order(0x04000000 | 0x20 | 0x10, [...u32(0x20004), 2, ...u32(0x20004), ...u32(0x20008)]);
  const icon = order(WIN | 0x40000000, [...u32(0x20004), 1, 2, 3, 4, 5, 6, 7, 8, 9]);
  r = run({ count: 6, data: [...fresh, ...shown, ...gone, ...desk, ...icon, 0x09, 0x0a, 0x7f, 0x0f, ...i16(0), ...i16(0), ...i16(9), ...i16(9), ...i16(1), ...i16(2), ...i16(3), ...i16(4), 0xff, 0, 0] });
  eq('windowing orders: new, changed, deleted, desktop; an icon skipped, the next order read', [r.ok, r.out.map((o) => o.op), r.out[0], r.out[1], r.out[2], r.out[3]], [true,
    ['window', 'window', 'window', 'desktop', 'fill'],
    { op: 'window', id: 0x20004, new: true, owner: 0, style: 0x16CF0000, exStyle: 0x100, show: 5, title: 'Bloc de notas', x: 100, y: -20, w: 640, h: 480,
      visX: 108, visY: -20, vis: [[0, 0, 624, 30], [0, 30, 624, 470]] },
    { op: 'window', id: 0x20004, new: false, show: 2 }, { op: 'window', id: 0x20008, deleted: true }, { op: 'desktop', active: 0x20004, order: [0x20004, 0x20008] }]);
  eq('alternate secondary orders other than windowing: not negotiated', run({ count: 1, data: [0x02, 4, 0, 1, 2] }).ok, false);
  const arcs = run({ count: 3, data: [...order(0x04000000 | 0x02 | 0x08, []), ...order(0x04000000 | 0x02 | 0x04, []), ...order(0x04000001, [])] }).out;
  eq('desktop orders: the server sending its windows again, all sent, not monitored', arcs,
    [{ op: 'desktop', began: true }, { op: 'desktop', completed: true }, { op: 'desktop', none: true }]);
}

// colour conversion
eq('colour 16 bpp red', orderColor(0xf800, 16).toString(16), 'ff0000ff');
eq('colour 24 bpp (R,G,B bytes, as xrdp sends them)', orderColor(0x0000ff, 24).toString(16), 'ff0000ff');
// 8 bpp (a server capped at 8 bits): an order colour is an index of the palette (it was taken for a red level)
{
  const pal8 = new Uint32Array(256);
  pal8[5] = 0xffff8000;
  const out8 = [];
  new OrderParser().parse(Uint8Array.of(1, 0, 0x09, 0x0a, 0x7f, 10, 0, 20, 0, 30, 0, 40, 0, 5, 0, 0), 8, (o) => out8.push(o), pal8, 1 << 20);
  eq('colour 8 bpp: the palette entry', out8.map((o) => o.color.toString(16)), ['ffff8000']);
}
process.exit(fail ? 1 : 0);
