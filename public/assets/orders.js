// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// Drawing orders (MS-RDPEGDI): DstBlt, PatBlt, ScrBlt, OpaqueRect, MemBlt and Mem3Blt, plus the bitmap cache (revision 2), and
// text: GlyphIndex, FastIndex and FastGlyph with the glyph cache (Cache Glyph revision 2) and the fragment cache.
// They turn scrolling, window moves, plain fills, repeated pictures and text into tiny commands instead of full bitmaps.
'use strict';

// bitmap cache rev. 2 as the gateway offers it (Client.php): 3 cells of 600, 600 and 1024 entries, bitmaps of up to 256,
// 1024 and 4096 pixels (MS-RDPBCGR); a server asking for others would grow the cache without bound
const CACHE_CELLS = [600, 600, 1024], CACHE_PIXELS = [256, 1024, 4096];

import { decodeBitmap } from './gfx.js';

const FIELD_BYTES = { 0x00: 1, 0x01: 2, 0x02: 1, 0x0a: 1, 0x0d: 2, 0x0e: 3, 0x13: 2, 0x18: 2, 0x1b: 3 };
const HATCHES = [[0xff, 0xff, 0xff, 0x00, 0xff, 0xff, 0xff, 0xff], [0xf7, 0xf7, 0xf7, 0xf7, 0xf7, 0xf7, 0xf7, 0xf7],
  [0xfe, 0xfd, 0xfb, 0xf7, 0xef, 0xdf, 0xbf, 0x7f], [0x7f, 0xbf, 0xdf, 0xef, 0xf7, 0xfb, 0xfd, 0xfe],
  [0xf7, 0xf7, 0xf7, 0x00, 0xf7, 0xf7, 0xf7, 0xf7], [0x7e, 0xbd, 0xdb, 0xe7, 0xe7, 0xdb, 0xbd, 0x7e]];   // hatched brushes (8 rows)
const SO_HORIZONTAL = 0x02, SO_VERTICAL = 0x04, SO_CHAR_INC_EQUAL_BM_BASE = 0x20;
const TEXT = () => ({ cacheId: 0, flAccel: 0, ulCharInc: 0, fOpRedundant: 0, back: 0, fore: 0, bkl: 0, bkt: 0, bkr: 0, bkb: 0,
  opl: 0, opt: 0, opr: 0, opb: 0, x: 0, y: 0, data: new Uint8Array(0) });
const CBR2_BPP = { 3: 8, 4: 16, 5: 24, 6: 32 };
const GLYPH_MAX = 2048;                                    // largest glyph cache cell the client offers (bytes of bitmap)
// the glyph caches as the gateway offers them (Client.php, capability 16): [entries, bytes of bitmap a cell holds]
const GLYPH_CACHES = [[254, 4], [254, 4], [254, 8], [254, 8], [254, 16], [254, 32], [254, 64], [254, 128], [254, 256], [64, 2048]];
const SOLID = [0, 0, 0, 0, 0, 0, 0, 0];                   // solid brush as a pattern: all in the clear colour (foreground field)
const WHITE = new Uint32Array(64).fill(0xffffffff);

/**
 * Order colour (3 bytes as sent) -> 0xAABBGGRR for the session colour depth. At 8 bpp it is an index of the palette (it
 * was taken for a red level: a server capped at 8 bits drew every fill in the wrong colour).
 */
export function orderColor(raw, bpp, palette = null) {
  if (bpp === 8 && palette) return palette[raw & 0xff];
  if (bpp === 16) {
    const r = (raw >> 11) & 0x1f, g = (raw >> 5) & 0x3f, b = raw & 0x1f;
    return (0xff000000 | (((b << 3) | (b >> 2)) << 16) | (((g << 2) | (g >> 4)) << 8) | ((r << 3) | (r >> 2))) >>> 0;
  }
  if (bpp === 15) {
    const r = (raw >> 10) & 0x1f, g = (raw >> 5) & 0x1f, b = raw & 0x1f;
    return (0xff000000 | (((b << 3) | (b >> 2)) << 16) | (((g << 3) | (g >> 2)) << 8) | ((r << 3) | (r >> 2))) >>> 0;
  }
  return (0xff000000 | raw) >>> 0;                         // TS_COLOR: red, green, blue bytes = the canvas byte order
}

/** Main thread: a 1 bpp glyph ({w, h, bits}) as a path of its pixel runs, to be filled in any colour. */
export function glyphPath(o) {
  const path = new Path2D(), stride = (o.w + 7) >> 3;
  const on = (x, y) => (o.bits[y * stride + (x >> 3)] << (x & 7)) & 0x80;
  for (let y = 0; y < o.h; y++) {
    for (let x = 0; x < o.w;) {
      if (!on(x, y)) { x++; continue; }
      const x0 = x;
      while (x < o.w && on(x, y)) x++;
      path.rect(x0, y, x - x0, 1);
    }
  }
  return path;
}

/**
 * An 8x8 pattern brush's rows from the top: sent as a Windows bitmap is kept, from the bottom up (its hatch field the bottom
 * row, then the 7 bytes of its extra field upwards). Taken as the top row, every pattern came one row lower than Windows
 * draws it: the 50 % grey of scroll bars and of the dotted focus rectangle inverted, seen against Server 2003's own pixels.
 */
function patternRows(f) {
  return [...[...f.extra].reverse(), f.hatch];
}

/** The 8x8 brush of a 'pattern' operation (rows, set/clear colours) as 64 pixels. */
function brushTile(o) {
  const px = new Uint32Array(64);
  for (let i = 0; i < 64; i++) px[i] = (o.rows[i >> 3] << (i & 7)) & 0x80 ? o.set : o.clear;
  return px;
}

/**
 * Any raster operation (ROP3) of a 'mem' (src: the pixels of its bitmap) or 'pattern' operation (src: null) on the w x h
 * pixels at x0, y0 of the screen, held in dst (0xAABBGGRR words, changed in place): each result bit is bit (P << 2 | S << 1
 * | D) of o.rop. A Mem3Blt carries its brush (rows, colours and origin, as a PatBlt's); a MemBlt none: P is then the
 * default brush of a GDI device context, white. PatBlt has no source: S is the destination (its ROPs do not use it).
 */
export function rop3(o, dst, src, x0, y0, w, h) {
  const pat = o.rows !== undefined, brush = pat ? brushTile(o) : WHITE, bx = pat ? o.ox - x0 : 0, by = pat ? o.oy - y0 : 0;
  const m = (i) => -((o.rop >> i) & 1);
  const m0 = m(0), m1 = m(1), m2 = m(2), m3 = m(3), m4 = m(4), m5 = m(5), m6 = m(6), m7 = m(7);
  src ||= dst;
  for (let y = 0, i = 0; y < h; y++) {
    const row = ((y - by) & 7) << 3;
    for (let x = 0; x < w; x++, i++) {
      const p = brush[row + ((x - bx) & 7)], s = src[i], d = dst[i], nd = ~d;
      dst[i] = (~p & (~s & (nd & m0 | d & m1) | s & (nd & m2 | d & m3)) | p & (~s & (nd & m4 | d & m5) | s & (nd & m6 | d & m7))) | 0xff000000;
    }
  }
}

/**
 * Main thread: a 'mem' (with its cached bitmap b, a canvas) or 'pattern' operation of another ROP than the plain copy, pixel
 * by pixel on its rectangle clipped to the bounds, the canvas and the bitmap (putImageData ignores the canvas clip).
 */
export function drawRop(ctx, o, b) {
  let x0 = Math.max(o.x, 0), y0 = Math.max(o.y, 0), x1 = Math.min(o.x + o.w, ctx.canvas.width), y1 = Math.min(o.y + o.h, ctx.canvas.height);
  if (o.clip) { x0 = Math.max(x0, o.clip.l); y0 = Math.max(y0, o.clip.t); x1 = Math.min(x1, o.clip.r + 1); y1 = Math.min(y1, o.clip.b + 1); }
  if (b) { x0 = Math.max(x0, o.x - o.sx); y0 = Math.max(y0, o.y - o.sy); x1 = Math.min(x1, o.x - o.sx + b.width); y1 = Math.min(y1, o.y - o.sy + b.height); }
  const w = x1 - x0, h = y1 - y0;
  if (w <= 0 || h <= 0) return;
  const img = ctx.getImageData(x0, y0, w, h);
  const src = b && new Uint32Array(b.getContext('2d').getImageData(x0 - o.x + o.sx, y0 - o.y + o.sy, w, h).data.buffer);
  rop3(o, new Uint32Array(img.data.buffer), src, x0, y0, w, h);
  ctx.putImageData(img, x0, y0);
}

/** Main thread: a 'pattern' operation (8x8 brush rows, set/clear colours, brush origin) filling a rectangle (PATCOPY). */
export function drawPattern(ctx, o) {
  const tile = new OffscreenCanvas(8, 8);
  tile.getContext('2d').putImageData(new ImageData(new Uint8ClampedArray(brushTile(o).buffer), 8, 8), 0, 0);
  const pat = ctx.createPattern(tile, 'repeat');
  pat.setTransform(new DOMMatrix([1, 0, 0, 1, o.ox, o.oy]));
  ctx.fillStyle = pat;
  ctx.fillRect(o.x, o.y, o.w, o.h);
}

/** Main thread: a 'text' operation ({color, glyphs: [key, x, y, ...], x, y, w, h}) with the cached glyph paths. */
export function drawText(ctx, o, paths) {
  ctx.save();
  ctx.beginPath();
  ctx.rect(o.x, o.y, o.w, o.h);
  ctx.clip();
  ctx.fillStyle = `rgb(${o.color & 255},${(o.color >> 8) & 255},${(o.color >> 16) & 255})`;
  for (let i = 0; i < o.glyphs.length; i += 3) {
    const g = paths.get(o.glyphs[i]);
    if (!g) continue;
    ctx.setTransform(1, 0, 0, 1, o.glyphs[i + 1], o.glyphs[i + 2]);
    ctx.fill(g);
  }
  ctx.restore();
}

/**
 * A Windowing Alternate Secondary Drawing Order (MS-RDPERP 2.2.1.3), whole in dv (TS_WINDOW_ORDER_HEADER: control byte,
 * OrderSize, FieldsPresentFlags, then the window id): a window new, changed (only the fields present: the others keep their
 * values) or deleted -> draw({op: 'window', id, new?, deleted?, owner, style, exStyle, show, title, x, y, w, h, vis, visX,
 * visY...}); the desktop (active window, z-order) -> draw({op: 'desktop', active, order}). Icons and notification area icons
 * are not shown (skipped whole by the caller, with OrderSize).
 */
function windowOrder(dv, draw) {
  const f = dv.getUint32(3, true);
  let p = 7;
  const u8 = () => dv.getUint8(p++);
  const u16 = () => { const v = dv.getUint16(p, true); p += 2; return v; };
  const u32 = () => { const v = dv.getUint32(p, true); p += 4; return v; };
  const i32 = () => { const v = dv.getInt32(p, true); p += 4; return v; };
  if (f & 0x01000000) {                                  // WINDOW_ORDER_TYPE_WINDOW
    const id = u32();
    if (f & 0x20000000) { draw({ op: 'window', id, deleted: true }); return; }   // WINDOW_ORDER_STATE_DELETED
    if (f & 0xC0000000) return;                          // its icon, or a cached one
    const w = { op: 'window', id, new: !!(f & 0x10000000) };
    const text = () => {                                 // UNICODE_STRING: CbString, then UTF-16 (up to 520 bytes)
      const n = Math.min(u16(), dv.byteLength - p);
      const s = new TextDecoder('utf-16le').decode(new Uint8Array(dv.buffer, dv.byteOffset + p, n & ~1));
      p += n;
      return s.replace(/\0[\s\S]*$/, '');
    };
    const rects = () => {                                // TS_RECTANGLE_16: left, top, right, bottom
      const n = u16();
      if (p + n * 8 > dv.byteLength) throw new RangeError('window rectangles');
      return Array.from({ length: n }, () => [u16(), u16(), u16(), u16()]);
    };
    if (f & 0x00000002) w.owner = u32();
    if (f & 0x00000008) { w.style = u32(); w.exStyle = u32(); }
    if (f & 0x00000010) w.show = u8();
    if (f & 0x00000004) w.title = text();
    if (f & 0x00004000) { w.clientX = i32(); w.clientY = i32(); }
    if (f & 0x00010000) { w.clientW = u32(); w.clientH = u32(); }
    if (f & 0x00000080) { w.marginL = u32(); w.marginR = u32(); }
    if (f & 0x08000000) { w.marginT = u32(); w.marginB = u32(); }
    if (f & 0x00020000) w.rpContent = u8();
    if (f & 0x00040000) w.rootParent = u32();
    if (f & 0x00000800) { w.x = i32(); w.y = i32(); }
    if (f & 0x00008000) { w.deltaX = i32(); w.deltaY = i32(); }
    if (f & 0x00000400) { w.w = u32(); w.h = u32(); }
    if (f & 0x00000100) w.rects = rects();
    if (f & 0x00001000) { w.visX = i32(); w.visY = i32(); }
    if (f & 0x00000200) w.vis = rects();
    draw(w);
  } else if (f & 0x04000000) {                           // WINDOW_ORDER_TYPE_DESKTOP
    const d = { op: 'desktop' };
    if (f & 0x00000001) d.none = true;                   // not monitored: every window goes
    if (f & 0x00000008) d.began = true;                  // ARC_BEGAN: the server sends its windows again (the old ones go)
    if (f & 0x00000004) d.completed = true;              // ARC_COMPLETED: all of them sent
    if (f & 0x00000020) d.active = u32();                // ACTIVE_WND
    if (f & 0x00000010) {                                // ZORDER: the windows, topmost first
      const n = u8();
      d.order = Array.from({ length: Math.min(n, (dv.byteLength - p) >> 2) }, () => u32());
    }
    draw(d);
  }                                                      // notification area icons: not shown
}

export class OrderParser {
  constructor() {
    this.resetState();
    this.glyphs = new Map();                             // cacheId * 256 + index -> {x, y, w, h} (the bitmaps live on the main thread)
    this.frags = new Map();                              // fragment cache: index -> glyph bytes
  }

  /**
   * The orders' history as at the start (the last order type PatBlt, bounds and every field 0): the server starts it anew
   * with each Demand Active, also a reactivation (Windows Server 2003 going back to a disconnected session, a resize),
   * and each field after it is sent as a change from that. Kept, the coordinates sent as deltas landed on old values: text
   * drawn far from its place, fills clipped to stale bounds (parts of the desktop left unpainted). The caches stay.
   */
  resetState() {
    this.type = 0x01;                                    // initial order type is PatBlt
    this.bounds = { l: 0, t: 0, r: 0, b: 0 };
    this.f = {
      0x00: { x: 0, y: 0, w: 0, h: 0, rop: 0 },
      0x01: { x: 0, y: 0, w: 0, h: 0, rop: 0, back: 0, fore: 0, bx: 0, by: 0, style: 0, hatch: 0, extra: new Uint8Array(7) },
      0x02: { x: 0, y: 0, w: 0, h: 0, rop: 0, sx: 0, sy: 0 },
      0x0a: { x: 0, y: 0, w: 0, h: 0, color: 0 },
      0x0d: { id: 0, x: 0, y: 0, w: 0, h: 0, rop: 0, sx: 0, sy: 0, idx: 0 },
      0x0e: { id: 0, x: 0, y: 0, w: 0, h: 0, rop: 0, sx: 0, sy: 0, back: 0, fore: 0, bx: 0, by: 0, style: 0, hatch: 0, extra: new Uint8Array(7), idx: 0 },
      0x13: TEXT(), 0x18: TEXT(), 0x1b: TEXT(),
    };
  }

  /** Stores a glyph (origin offset x, y, size w x h, 1 bpp rows of (w + 7) >> 3 bytes, MSB first) and tells the main thread. */
  #glyph(draw, cacheId, idx, x, y, w, h, bits) {
    // only within the caches offered: a cache, an index and a size past them left out (before, FastGlyph took any cache of
    // 256 and any size up to 2 KB: 65,536 glyphs of checkerboard bits made millions of rectangles on the page)
    const c = GLYPH_CACHES[cacheId];
    if (!c || idx >= c[0] || bits.length > c[1]) return;
    this.glyphs.set(cacheId * 256 + idx, { x, y, w, h });
    draw({ op: 'glyph', key: cacheId * 256 + idx, w, h, bits: bits.slice() });
  }

  /**
   * A run of text: optional opaque rectangle (filled with the order's foreground colour field), then the glyphs of the
   * data (indexes, positions, fragments) in the background colour field, as RDP defines them, clipped to the background
   * rectangle and the order bounds.
   */
  #text(t, bpp, draw, clip, fill) {
    const id = t.cacheId, data = t.data, run = [];
    let x = t.x, y = t.y;
    const place = (bytes) => {
      for (let i = 0; i < bytes.length;) {
        const op = bytes[i++];
        if (op === 0xff && bytes === data) {             // add fragment: the preceding bytes, of the given size
          const fid = bytes[i++], size = bytes[i++];
          this.frags.set(fid, bytes.slice(Math.max(0, i - 3 - size), i - 3));
          continue;
        }
        const variable = t.ulCharInc === 0 && !(t.flAccel & SO_CHAR_INC_EQUAL_BM_BASE);
        const delta = () => {                            // signed 1 byte, or 0x80 + 2 bytes
          let d = bytes[i++] ?? 0;
          if (d === 0x80) { d = ((bytes[i] | (bytes[i + 1] << 8)) << 16) >> 16; i += 2; } else d = (d << 24) >> 24;
          return d;
        };
        const move = (d) => { if (t.flAccel & SO_VERTICAL) y += d; if (t.flAccel & SO_HORIZONTAL) x += d; };
        if (op === 0xfe && bytes === data) {             // use fragment; its delta counts when the fragment's own first delta is 0
          const f = this.frags.get(bytes[i++]);
          const d = variable && i < bytes.length ? delta() : 0;
          if (f && variable && f[1] === 0) move(d);
          if (f) place(f);
          continue;
        }
        if (variable) move(delta());                     // delta to this glyph's origin
        const g = this.glyphs.get(id * 256 + op);
        if (!g) continue;
        run.push(id * 256 + op, x + g.x, y + g.y);
        if (t.flAccel & SO_CHAR_INC_EQUAL_BM_BASE) x += g.w;
        else if (t.ulCharInc) x += t.ulCharInc;
      }
    };
    // (both rectangles end before their right and bottom values, as Windows draws them: one pixel more covered the next
    // row's first line, the selected line of a list one pixel taller and a frame's line under it gone, seen on Server 2003)
    if (fill) draw({ op: 'fill', x: fill.l, y: fill.t, w: fill.r - fill.l, h: fill.b - fill.t, color: orderColor(t.fore, bpp, this.pal), clip });
    place(data);
    if (!run.length || t.bkr <= t.bkl || t.bkb <= t.bkt) return;
    const box = { l: t.bkl, t: t.bkt, r: t.bkr - 1, b: t.bkb - 1 };
    if (clip) Object.assign(box, { l: Math.max(box.l, clip.l), t: Math.max(box.t, clip.t), r: Math.min(box.r, clip.r), b: Math.min(box.b, clip.b) });
    if (box.r >= box.l && box.b >= box.t) draw({ op: 'text', color: orderColor(t.back, bpp, this.pal), glyphs: run, x: box.l, y: box.t, w: box.r - box.l + 1, h: box.b - box.t + 1 });
  }

  /** Secondary order at p (after the control byte): bitmap cache entries; other caches are skipped. Returns the next p. */
  #secondary(u8, dv, p, palette, draw, max) {
    const len = dv.getUint16(p, true), extra = dv.getUint16(p + 2, true), type = u8[p + 4];   // (unsigned: a negative one went back)
    const start = p + 5, end = start + len + 7;
    const two = (q) => u8[q] & 0x80 ? [((u8[q] & 0x7f) << 8) | u8[q + 1], q + 2] : [u8[q], q + 1];   // 2-byte unsigned encoding
    const twoS = (q) => { const [v, n] = two(q); const neg = u8[q] & 0x40; const m = u8[q] & 0x80 ? v & 0x3fff : v & 0x3f; return [neg ? -m : m, n]; };
    if (type === 3) {                                     // cache glyph revision 2 (the one sent for glyph support level "encode")
      let q = start;
      const cacheId = extra & 0x0f, n = extra >> 8;
      for (let i = 0; i < n && q < end; i++) {
        let x, y, w, h;
        const idx = u8[q++];
        [x, q] = twoS(q);
        [y, q] = twoS(q);
        [w, q] = two(q);
        [h, q] = two(q);
        const cb = ((w + 7) >> 3) * h;
        if (q + cb > end || cb > GLYPH_MAX) break;        // its bitmap must be there and fit the largest cell offered
        if (cacheId < 10) this.#glyph(draw, cacheId, idx, x, y, w, h, u8.subarray(q, q + cb));
        q += cb + (4 - (cb % 4)) % 4;                     // padded to 4 bytes
      }
    } else if (type === 4 || type === 5) {                       // cache bitmap v2, uncompressed or compressed
      const flags = extra >> 7, bpp = CBR2_BPP[(extra >> 3) & 15];
      let q = start;
      const two = () => { const b = u8[q++]; return b & 0x80 ? ((b & 0x7f) << 8) | u8[q++] : b; };
      const four = () => { const b = u8[q++]; let v = b & 0x3f; for (let i = 0; i < b >> 6; i++) v = v * 256 + u8[q++]; return v; };
      if (flags & 0x02) q += 8;                           // persistent key
      const w = two(), h = flags & 0x01 ? w : two();
      let length = four();
      const idx = two();
      if (type === 5 && !(flags & 0x08)) { length = dv.getUint16(q + 2, true); q += 8; }   // compression header
      const id = extra & 7;                               // a cell offered, within its entries and its bitmap size
      if (bpp && w && h && !(flags & 0x10) && id < 3 && idx < CACHE_CELLS[id] && w * h <= CACHE_PIXELS[id]) {
        const px = decodeBitmap(u8.subarray(q, q + length), w, h, bpp, type === 5, palette, max);
        draw({ op: 'cache', id, idx, w, h, px });
      }
    }
    return end;
  }

  /**
   * Parses TS_FP_UPDATE_ORDERS data (u16 count + orders) and calls draw(cmd) for each order:
   * {op: 'fill', x, y, w, h, color, clip} | {op: 'copy', x, y, w, h, sx, sy, clip} | {op: 'invert', ...}
   * | {op: 'cache', id, idx, w, h, px} (store a bitmap) | {op: 'mem', id, idx, x, y, w, h, sx, sy, rop, clip} (draw a stored one;
   * from a Mem3Blt also its brush: rows, ox, oy, set, clear)
   * | {op: 'pattern', x, y, w, h, rows, ox, oy, set, clear, rop, clip} (brush) | {op: 'glyph' | 'text', ...} (see #text)
   * max: the most pixels of a cached bitmap (see decodeBitmap). Returns false when an order it does not know, or corrupt
   * data, is found (the rest of the update is skipped).
   */
  parse(u8, bpp, draw, palette, max) {
    this.pal = palette;                                  // (8 bpp: order colours are indexes of it)
    try {
      return this.#parse(u8, bpp, draw, palette, max);
    } catch {                                            // truncated order, bad cached bitmap...: not worth the session
      return false;
    }
  }

  #parse(u8, bpp, draw, palette, max) {
    const dv = new DataView(u8.buffer, u8.byteOffset, u8.byteLength);
    let p = 2;
    const count = dv.getUint16(0, true);
    for (let n = 0; n < count; n++) {
      const ctl = u8[p++];
      if ((ctl & 0x03) === 0x03) { p = this.#secondary(u8, dv, p, palette, draw, max); continue; }
      if ((ctl & 0x03) === 0x02) {                        // alternate secondary: only the windowing orders (RemoteApp) are negotiated
        const size = dv.getUint16(p, true);              // the whole order, its control byte included
        if (ctl >> 2 !== 0x0B || size < 7 || p - 1 + size > u8.length) return false;
        windowOrder(new DataView(u8.buffer, u8.byteOffset + p - 1, size), draw);
        p += size - 1;
        continue;
      }
      if (!(ctl & 0x01)) return false;
      if (ctl & 0x08) this.type = u8[p++];
      const type = this.type, fb = FIELD_BYTES[type];
      if (fb === undefined) return false;
      let bytes = fb;
      if (ctl & 0x40) bytes--;
      if (ctl & 0x80) bytes = bytes > 1 ? bytes - 2 : 0;
      let flags = 0;
      for (let i = 0; i < bytes; i++) flags |= u8[p++] << (8 * i);
      const clip = (ctl & 0x04) !== 0;
      if (clip && !(ctl & 0x20)) {
        const bf = u8[p++], b = this.bounds;
        const abs = () => { const v = dv.getInt16(p, true); p += 2; return v; };
        const rel = () => dv.getInt8(p++);
        if (bf & 0x01) b.l = abs(); else if (bf & 0x10) b.l += rel();
        if (bf & 0x02) b.t = abs(); else if (bf & 0x20) b.t += rel();
        if (bf & 0x04) b.r = abs(); else if (bf & 0x40) b.r += rel();
        if (bf & 0x08) b.b = abs(); else if (bf & 0x80) b.b += rel();
      }
      const delta = (ctl & 0x10) !== 0;
      const coord = (prev) => { if (delta) return prev + dv.getInt8(p++); const v = dv.getInt16(p, true); p += 2; return v; };
      const byte = () => u8[p++];
      const color = () => { const v = u8[p] | (u8[p + 1] << 8) | (u8[p + 2] << 16); p += 3; return v; };
      const f = this.f[type];
      const has = (i) => (flags >> (i - 1)) & 1;
      const c = clip ? { ...this.bounds } : null;
      if (type === 0x1b || type === 0x13 || type === 0x18) {   // text: GlyphIndex, FastIndex, FastGlyph
        const i16 = () => { const v = dv.getInt16(p, true); p += 2; return v; };
        const t = f;
        if (type === 0x1b) {
          if (has(1)) t.cacheId = byte();
          if (has(2)) t.flAccel = byte();
          if (has(3)) t.ulCharInc = byte();
          if (has(4)) t.fOpRedundant = byte();
          if (has(5)) t.back = color();
          if (has(6)) t.fore = color();
          if (has(7)) t.bkl = i16();
          if (has(8)) t.bkt = i16();
          if (has(9)) t.bkr = i16();
          if (has(10)) t.bkb = i16();
          if (has(11)) t.opl = i16();
          if (has(12)) t.opt = i16();
          if (has(13)) t.opr = i16();
          if (has(14)) t.opb = i16();
          p += has(15) + has(16) + has(17) + has(18) + (has(19) ? 7 : 0);   // brush: not used for text
          if (has(20)) t.x = i16();
          if (has(21)) t.y = i16();
          if (has(22)) { const n = byte(); t.data = u8.slice(p, p + n); p += n; }
          const op = t.opr > t.opl && t.opb > t.opt && !t.fOpRedundant ? { l: t.opl, t: t.opt, r: t.opr, b: t.opb } : null;
          this.#text(t, bpp, draw, c, op);
          continue;
        }
        if (has(1)) t.cacheId = byte();
        if (has(2)) { t.ulCharInc = byte(); t.flAccel = byte(); }
        if (has(3)) t.back = color();
        if (has(4)) t.fore = color();
        if (has(5)) t.bkl = coord(t.bkl);
        if (has(6)) t.bkt = coord(t.bkt);
        if (has(7)) t.bkr = coord(t.bkr);
        if (has(8)) t.bkb = coord(t.bkb);
        if (has(9)) t.opl = coord(t.opl);
        if (has(10)) t.opt = coord(t.opt);
        if (has(11)) t.opr = coord(t.opr);
        if (has(12)) t.opb = coord(t.opb);
        if (has(13)) t.x = coord(t.x);
        if (has(14)) t.y = coord(t.y);
        if (has(15)) {
          const n = byte(), d = u8.subarray(p, p + n);
          p += n;
          if (type === 0x13) t.data = d.slice();
          else if (n) {                                   // FastGlyph: one glyph, defined here when more than its index is sent
            let q = 1;
            if (n > 1) {
              const two = () => { const b = d[q++]; return b & 0x80 ? ((b & 0x7f) << 8) | d[q++] : b; };
              const twoS = () => { const b = d[q]; const v = two(); const m = b & 0x80 ? v & 0x3fff : v & 0x3f; return b & 0x40 ? -m : m; };
              const gx = twoS(), gy = twoS(), w = two(), h = two(), cb = ((w + 7) >> 3) * h;
              if (cb && q + cb <= d.length && cb <= GLYPH_MAX) this.#glyph(draw, t.cacheId, d[0], gx, gy, w, h, d.subarray(q, q + cb));
            }
            t.data = Uint8Array.of(d[0]);                 // drawn like FastIndex with that single glyph (its delta, missing, is 0)
          }
        }
        let { opl, opt, opr, opb } = t, x = t.x, y = t.y;    // FastIndex/FastGlyph encodings of the opaque rectangle
        if (opb === -32768) {
          const fl = opt & 0x0f;
          if (fl & 0x01) opb = t.bkb;
          if (fl & 0x02) opr = t.bkr;
          if (fl & 0x04) opt = t.bkt;
          if (fl & 0x08) opl = t.bkl;
        }
        if (opl === 0) opl = t.bkl;
        if (opr === 0) opr = t.bkr;
        if (x === -32768) x = t.bkl;
        if (y === -32768) y = t.bkt;
        this.#text({ ...t, x, y }, bpp, draw, c, opr > opl && opb > opt ? { l: opl, t: opt, r: opr, b: opb } : null);
        continue;
      }
      if (type === 0x0e) {                                 // Mem3Blt: a rectangle of a cached bitmap with a brush, any ROP3
        if (has(1)) { f.id = dv.getUint16(p, true); p += 2; }
        if (has(2)) f.x = coord(f.x);
        if (has(3)) f.y = coord(f.y);
        if (has(4)) f.w = coord(f.w);
        if (has(5)) f.h = coord(f.h);
        if (has(6)) f.rop = byte();
        if (has(7)) f.sx = coord(f.sx);
        if (has(8)) f.sy = coord(f.sy);
        if (has(9)) f.back = color();
        if (has(10)) f.fore = color();
        if (has(11)) f.bx = byte();                       // brush: origin, style, hatch (a pattern's bottom row), 7 more rows
        if (has(12)) f.by = byte();
        if (has(13)) f.style = byte();
        if (has(14)) f.hatch = byte();
        if (has(15)) { f.extra = u8.slice(p, p + 7); p += 7; }
        if (has(16)) { f.idx = dv.getUint16(p, true); p += 2; }
        const rows = f.style === 3 ? patternRows(f) : f.style === 2 && f.hatch < 6 ? HATCHES[f.hatch] : SOLID;
        draw({ op: 'mem', id: f.id & 0xff, idx: f.idx, x: f.x, y: f.y, w: f.w, h: f.h, sx: f.sx, sy: f.sy, rop: f.rop, clip: c,
          rows, ox: f.bx, oy: f.by, set: orderColor(f.back, bpp, this.pal), clear: orderColor(f.fore, bpp, this.pal) });
        continue;
      }
      if (type === 0x0d) {                                 // MemBlt: a rectangle of a cached bitmap
        if (has(1)) { f.id = dv.getUint16(p, true); p += 2; }
        if (has(2)) f.x = coord(f.x);
        if (has(3)) f.y = coord(f.y);
        if (has(4)) f.w = coord(f.w);
        if (has(5)) f.h = coord(f.h);
        if (has(6)) f.rop = byte();
        if (has(7)) f.sx = coord(f.sx);
        if (has(8)) f.sy = coord(f.sy);
        if (has(9)) { f.idx = dv.getUint16(p, true); p += 2; }
        draw({ op: 'mem', id: f.id & 0xff, idx: f.idx, x: f.x, y: f.y, w: f.w, h: f.h, sx: f.sx, sy: f.sy, rop: f.rop, clip: c });
        continue;
      }
      if (has(1)) f.x = coord(f.x);
      if (has(2)) f.y = coord(f.y);
      if (has(3)) f.w = coord(f.w);
      if (has(4)) f.h = coord(f.h);
      if (type === 0x0a) {                                // OpaqueRect: colour bytes are separate fields
        if (has(5)) f.color = (f.color & ~0xff) | byte();
        if (has(6)) f.color = (f.color & ~0xff00) | (byte() << 8);
        if (has(7)) f.color = (f.color & ~0xff0000) | (byte() << 16);
        draw({ op: 'fill', x: f.x, y: f.y, w: f.w, h: f.h, color: orderColor(f.color, bpp, this.pal), clip: c });
        continue;
      }
      if (has(5)) f.rop = byte();
      if (type === 0x02) {
        if (has(6)) f.sx = coord(f.sx);
        if (has(7)) f.sy = coord(f.sy);
        draw({ op: 'copy', x: f.x, y: f.y, w: f.w, h: f.h, sx: f.sx, sy: f.sy, clip: c });
        continue;
      }
      if (type === 0x01) {
        if (has(6)) f.back = color();
        if (has(7)) f.fore = color();
        if (has(8)) f.bx = byte();                        // brush: origin, style, hatch (a pattern's bottom row), 7 more rows
        if (has(9)) f.by = byte();
        if (has(10)) f.style = byte();
        if (has(11)) f.hatch = byte();
        if (has(12)) { f.extra = u8.slice(p, p + 7); p += 7; }
        const rows = f.style === 3 ? patternRows(f) : f.style === 2 && f.hatch < 6 ? HATCHES[f.hatch] : null;
        // PATCOPY with an 8x8 brush (set bits in the background colour), or another ROP that uses the brush (PATINVERT...)
        if (f.rop === 0xf0 ? rows : f.rop !== 0x00 && f.rop !== 0xff && f.rop !== 0x55) {
          draw({ op: 'pattern', x: f.x, y: f.y, w: f.w, h: f.h, rows: rows || SOLID, ox: f.bx, oy: f.by,
            set: orderColor(f.back, bpp, this.pal), clear: orderColor(f.fore, bpp, this.pal), rop: f.rop, clip: c });
          continue;
        }
      }
      const rop = f.rop;
      if (type === 0x00 && rop !== 0x00 && rop !== 0xff && rop !== 0x55) continue;  // other ROPs leave the screen as is
      if (rop === 0x55) draw({ op: 'invert', x: f.x, y: f.y, w: f.w, h: f.h, clip: c });
      else {
        const col = rop === 0x00 ? 0xff000000 : rop === 0xff ? 0xffffffff : orderColor(type === 0x01 ? f.fore : 0, bpp, this.pal);
        draw({ op: 'fill', x: f.x, y: f.y, w: f.w, h: f.h, color: col, clip: c });
      }
    }
    return true;
  }
}
