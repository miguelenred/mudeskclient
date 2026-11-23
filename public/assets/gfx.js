// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// Bitmap and pointer decoders for RDP updates (interleaved RLE, planar, raw).
'use strict';

const WHITE = { 1: 0xff, 2: 0xffff, 3: 0xffffff };
const DEPTHS = new Set([8, 15, 16, 24, 32]);             // colour depths of RDP bitmaps

/** A pixel value of bpp bytes at src[s] (bytes past the end read as 0). */
function pixel(src, s, bpp) {
  let v = src[s];
  if (bpp > 1) v |= src[s + 1] << 8;
  if (bpp > 2) v |= src[s + 2] << 16;
  return v;
}

/** bits pixels of a foreground/background mask from out[d] (on the first line, the row above is black). Returns the new d. */
function fgbg(out, d, n, width, mask, bits, fg, firstLine) {
  for (let i = 0; i < bits && d < n; i++, d++) {
    if (firstLine) out[d] = (mask >> i) & 1 ? fg : 0;
    else { const a = out[d - width]; out[d] = (mask >> i) & 1 ? a ^ fg : a; }
  }
  return d;
}

/**
 * Interleaved RLE (MS-RDPBCGR 2.2.9.1.1.3.1.2.4). Returns pixel values, bottom-up, width*height: out when given (a buffer
 * reused by the caller, at least that long), else a new array. Local variables only, no closures: it is a hot loop.
 */
export function rleDecode(src, width, height, bpp, out = null) {
  const n = width * height;
  out ??= new Uint32Array(n);
  const end = src.length;
  const white = WHITE[bpp];
  let s = 0, d = 0, fg = white, insertFg = false, firstLine = true;

  while (s < end && d < n) {
    if (firstLine && d >= width) { firstLine = false; insertFg = false; }
    const h = src[s];
    let code;
    if ((h & 0xc0) !== 0xc0) code = h >> 5;
    else if ((h & 0xf0) === 0xf0) code = h;
    else code = h >> 4;

    let run = 0;
    if (code === 0x02 || code === 0x0d) {            // FGBG images: length in units of 8
      const m = code === 0x02 ? 0x1f : 0x0f;
      run = h & m;
      if (run) { run *= 8; s += 1; } else { run = src[s + 1] + 1; s += 2; }
    } else if (code <= 0x04) {                          // regular orders
      run = h & 0x1f;
      if (run) s += 1; else { run = src[s + 1] + 32; s += 2; }
    } else if (code === 0x0c || code === 0x0e) {        // lite orders
      run = h & 0x0f;
      if (run) s += 1; else { run = src[s + 1] + 16; s += 2; }
    } else if (code >= 0xf0 && code <= 0xf8) {          // mega mega
      run = src[s + 1] | (src[s + 2] << 8);
      s += 3;
    } else {
      s += 1;                                           // single-byte special orders
    }
    if (run !== run) break;                             // its length past the end of the data: nothing more is drawn

    if (code === 0x00 || code === 0xf0) {               // background run
      if (insertFg && run > 0) { if (d < n) { out[d] = firstLine ? fg : out[d - width] ^ fg; d++; } run--; }
      const e = Math.min(d + run, n);
      if (firstLine) out.fill(0, d, e);
      else if (e - d <= width) out.copyWithin(d, d - width, e - width);      // no overlap with what is being written
      else for (let i = d; i < e; i++) out[i] = out[i - width];
      d = e;
      insertFg = true;
      continue;
    }
    insertFg = false;

    switch (code) {
      case 0x01: case 0xf1: case 0x0c: case 0xf6: {    // foreground runs
        if (code === 0x0c || code === 0xf6) { fg = pixel(src, s, bpp); s += bpp; }
        const e = Math.min(d + run, n);
        if (firstLine) for (; d < e; d++) out[d] = fg;
        else for (; d < e; d++) out[d] = out[d - width] ^ fg;
        break;
      }
      case 0x0e: case 0xf8: {                           // dithered run
        const a = pixel(src, s, bpp), b = pixel(src, s + bpp, bpp);
        s += 2 * bpp;
        for (let i = 0; i < run && d < n; i++) { out[d++] = a; if (d < n) out[d++] = b; }
        break;
      }
      case 0x03: case 0xf3: {                           // color run
        const c = pixel(src, s, bpp);
        s += bpp;
        const e = Math.min(d + run, n);
        out.fill(c, d, e);
        d = e;
        break;
      }
      case 0x02: case 0xf2: case 0x0d: case 0xf7:      // FGBG images
        if (code === 0x0d || code === 0xf7) { fg = pixel(src, s, bpp); s += bpp; }
        for (; run > 0; run -= 8) d = fgbg(out, d, n, width, src[s++], run > 8 ? 8 : run, fg, firstLine);
        break;
      case 0x04: case 0xf4: {                           // color image (hot path)
        const e = Math.min(d + run, n), skip = (run - (e - d)) * bpp;
        if (bpp === 2) for (; d < e; s += 2) out[d++] = src[s] | (src[s + 1] << 8);
        else if (bpp === 3) for (; d < e; s += 3) out[d++] = src[s] | (src[s + 1] << 8) | (src[s + 2] << 16);
        else while (d < e) out[d++] = src[s++];
        s += skip;
        break;
      }
      case 0xf9: d = fgbg(out, d, n, width, 0x03, 8, fg, firstLine); break;   // special FGBG 1
      case 0xfa: d = fgbg(out, d, n, width, 0x05, 8, fg, firstLine); break;   // special FGBG 2
      case 0xfd: if (d < n) out[d++] = white; break;
      case 0xfe: if (d < n) out[d++] = 0; break;
      default: throw new Error('RLE: bad order 0x' + h.toString(16));
    }
  }
  if (d < n) out.fill(0, d, n);                         // (a reused buffer: what the data did not reach is black)
  return out;
}

const LUT = {};
/** 15/16-bit pixel -> RGBA lookup table (built on first use). */
function lut(bpp) {
  if (!LUT[bpp]) {
    const t = new Uint32Array(65536);
    for (let v = 0; v < 65536; v++) t[v] = toRgba(v, bpp, null);
    LUT[bpp] = t;
  }
  return LUT[bpp];
}

/** A 24-bit pixel value (blue in the low byte) as 0xAABBGGRR. */
const rgb24 = (v) => 0xff000000 | ((v & 0xff) << 16) | (v & 0xff00) | ((v >> 16) & 0xff);

/** Converts a pixel value of the given depth to 0xAABBGGRR (little-endian RGBA). */
function toRgba(v, bpp, palette) {
  switch (bpp) {
    case 32: case 24: return rgb24(v);
    case 16: {
      const r = (v >> 11) & 0x1f, g = (v >> 5) & 0x3f, b = v & 0x1f;
      return 0xff000000 | (((b << 3) | (b >> 2)) << 16) | (((g << 2) | (g >> 4)) << 8) | ((r << 3) | (r >> 2));
    }
    case 15: {
      const r = (v >> 10) & 0x1f, g = (v >> 5) & 0x1f, b = v & 0x1f;
      return 0xff000000 | (((b << 3) | (b >> 2)) << 16) | (((g << 3) | (g >> 2)) << 8) | ((r << 3) | (r >> 2));
    }
    default: return palette[v & 0xff];
  }
}

// Scratch buffers for one decode, kept for the next while small (a larger one is only for its call).
const SCRATCH_MAX = 1 << 16;
let pixScratch = new Uint32Array(4096), planeScratch = new Uint8Array(4 * 4096);
function pixBuffer(n) {
  if (n <= pixScratch.length) return pixScratch;
  const b = new Uint32Array(n);
  if (n <= SCRATCH_MAX) pixScratch = b;
  return b;
}
function planeBuffer(n) {
  if (n <= planeScratch.length) return planeScratch;
  const b = new Uint8Array(n);
  if (n <= 4 * SCRATCH_MAX) planeScratch = b;
  return b;
}

/** One plane of planar data from src[s] into p[po..po + width * height) (raw or RLE rows). Returns where its data ends. */
function planarPlane(src, s, p, po, width, height, rle) {
  const n = width * height;
  if (!rle) {
    const part = src.subarray(s, s + n);
    p.set(part, po);
    if (part.length < n) p.fill(0, po + part.length, po + n);
    return s + n;
  }
  for (let y = 0; y < height; y++) {
    const row = po + y * width, end = row + width;
    let x = row, last = 0;
    while (x < end) {
      if (s >= src.length) throw new Error('Planar: truncated plane');   // a control byte 0 does not move x
      const ctl = src[s++];
      let runLen = ctl & 0x0f, raw = ctl >> 4;
      if (runLen === 1) { runLen = raw + 16; raw = 0; } else if (runLen === 2) { runLen = raw + 32; raw = 0; }
      if (y === 0) {
        for (; raw > 0 && x < end; raw--) { last = src[s++]; p[x++] = last; }
        for (; runLen > 0 && x < end; runLen--) p[x++] = last;
      } else {
        for (; raw > 0 && x < end; raw--, x++) {
          const b = src[s++];
          last = b & 1 ? -((b >> 1) + 1) : b >> 1;
          p[x] = p[x - width] + last;
        }
        for (; runLen > 0 && x < end; runLen--, x++) p[x] = p[x - width] + last;
      }
    }
  }
  return s;
}

/**
 * Planar codec (MS-RDPEGDI 2.2.2.5.1). Returns RGBA words in the rows' own order, or reversed with bottomUp (a bitmap
 * update's rows are bottom-up); opaque sets every alpha to 255.
 */
export function planarDecode(src, width, height, opaque = false, bottomUp = false) {
  const hdr = src[0];
  if (hdr & 0x07) throw new Error('Planar: color loss not supported');
  const rle = hdr & 0x10, noAlpha = hdr & 0x20;
  const n = width * height, p = planeBuffer(4 * n);    // planes: alpha, red, green, blue
  let s = 1;
  if (!noAlpha) s = planarPlane(src, s, p, 0, width, height, rle);
  s = planarPlane(src, s, p, n, width, height, rle);
  s = planarPlane(src, s, p, 2 * n, width, height, rle);
  planarPlane(src, s, p, 3 * n, width, height, rle);
  const out = new Uint32Array(n), fixed = opaque || noAlpha;
  const a = p.subarray(0, n), r = p.subarray(n, 2 * n), g = p.subarray(2 * n, 3 * n), b = p.subarray(3 * n, 4 * n);
  for (let y = 0; y < height; y++) {
    const i0 = y * width, i1 = i0 + width;
    let o = (bottomUp ? height - 1 - y : y) * width;
    if (fixed) for (let i = i0; i < i1; i++) out[o++] = 0xff000000 | b[i] << 16 | g[i] << 8 | r[i];
    else for (let i = i0; i < i1; i++) out[o++] = a[i] << 24 | b[i] << 16 | g[i] << 8 | r[i];
  }
  return out;
}

/**
 * Decodes one TS_BITMAP_DATA rectangle into ImageData-ready RGBA (top-down). max: the most pixels a bitmap can have (the
 * desktop, see stream-worker.js); larger sizes, taken from the data, asked for gigabytes.
 */
export function decodeBitmap(data, w, h, bpp, compressed, palette, max) {
  if (!DEPTHS.has(bpp) || w * h > max) throw new Error(`bad size or depth (${w} x ${h} at ${bpp} bpp)`);
  const Bpp = (bpp + 7) >> 3;
  if (compressed && bpp === 32) return planarDecode(data, w, h, true, true);
  const rgba = new Uint32Array(w * h);
  const table = bpp === 16 || bpp === 15 ? lut(bpp) : null;
  if (!compressed) {                            // one pass: values of each row (bottom-up) into their colours (top-down)
    const stride = ((w * Bpp + 3) & ~3);
    for (let y = 0; y < h; y++) {
      let o = y * stride, d = (h - 1 - y) * w;
      if (table) for (let x = 0; x < w; x++, o += 2) rgba[d++] = table[data[o] | (data[o + 1] << 8)];
      else if (Bpp === 1) for (let x = 0; x < w; x++, o++) rgba[d++] = palette[data[o] & 0xff];
      else for (let x = 0; x < w; x++, o += Bpp) rgba[d++] = rgb24(data[o] | (data[o + 1] << 8) | (data[o + 2] << 16));
    }
    return rgba;
  }
  const pix = rleDecode(data, w, h, Bpp, pixBuffer(w * h));
  for (let y = 0; y < h; y++) {                 // bottom-up -> top-down
    const srcRow = (h - 1 - y) * w, dstRow = y * w;
    if (table) for (let x = 0; x < w; x++) rgba[dstRow + x] = table[pix[srcRow + x]];
    else if (Bpp === 1) for (let x = 0; x < w; x++) rgba[dstRow + x] = palette[pix[srcRow + x] & 0xff];
    else for (let x = 0; x < w; x++) rgba[dstRow + x] = rgb24(pix[srcRow + x]);
  }
  return rgba;
}

/** Parses a bitmap update and calls draw(x, y, w, h, rgba Uint32Array (stride w), cw, ch); max: see decodeBitmap. */
export function bitmapUpdate(u8, palette, draw, max) {
  const dv = new DataView(u8.buffer, u8.byteOffset, u8.byteLength);
  const count = dv.getUint16(2, true);
  let p = 4;
  for (let i = 0; i < count; i++) {
    const left = dv.getUint16(p, true), top = dv.getUint16(p + 2, true);
    const right = dv.getUint16(p + 4, true), bottom = dv.getUint16(p + 6, true);
    const w = dv.getUint16(p + 8, true), h = dv.getUint16(p + 10, true);
    const bpp = dv.getUint16(p + 12, true), flags = dv.getUint16(p + 14, true);
    let len = dv.getUint16(p + 16, true);
    p += 18;
    let data = u8.subarray(p, p + len);
    p += len;
    const compressed = flags & 0x0001;
    if (compressed && !(flags & 0x0400)) data = data.subarray(8);
    draw(left, top, w, h, decodeBitmap(data, w, h, bpp, compressed, palette, max), right - left + 1, bottom - top + 1);
  }
}

export function paletteUpdate(u8, palette) {
  if (u8.length < 8) return;                               // shorter than its header: it threw, out of the worker, and the session ended
  const dv = new DataView(u8.buffer, u8.byteOffset, u8.byteLength);
  const n = Math.min(256, dv.getUint32(4, true), Math.floor((u8.length - 8) / 3));   // (and no entry past its data)
  for (let i = 0; i < n; i++) {
    const o = 8 + i * 3;
    palette[i] = (0xff000000 | (u8[o + 2] << 16) | (u8[o + 1] << 8) | u8[o]) >>> 0;
  }
}

/**
 * Decodes a pointer (color/new/large). kind: 9 color (24bpp), 11 new, 12 large.
 * Returns {index, x, y, w, h, rgba} with top-down RGBA words.
 */
export function pointerDecode(u8, kind) {
  const dv = new DataView(u8.buffer, u8.byteOffset, u8.byteLength);
  let p = 0, xorBpp = 24;
  if (kind !== 9) { xorBpp = dv.getUint16(0, true); p = 2; }
  const index = dv.getUint16(p, true), hx = dv.getUint16(p + 2, true), hy = dv.getUint16(p + 4, true);
  const w = dv.getUint16(p + 6, true), h = dv.getUint16(p + 8, true);
  if (w > 384 || h > 384) throw new Error('pointer: bad size');   // the protocol limit (the client offers 96x96)
  let andLen, xorLen;
  if (kind === 12) { andLen = dv.getUint32(p + 10, true); xorLen = dv.getUint32(p + 14, true); p += 18; }
  else { andLen = dv.getUint16(p + 10, true); xorLen = dv.getUint16(p + 12, true); p += 14; }
  const xor = u8.subarray(p, p + xorLen), and = u8.subarray(p + xorLen, p + xorLen + andLen);
  const xorStride = (((w * xorBpp + 7) >> 3) + 1) & ~1;
  const andStride = (((w + 7) >> 3) + 1) & ~1;
  const rgba = new Uint32Array(w * h);
  let hasAlpha = false;
  if (xorBpp === 32) for (let i = 3; i < xor.length; i += 4) if (xor[i]) { hasAlpha = true; break; }
  for (let y = 0; y < h; y++) {
    const sy = h - 1 - y;
    for (let x = 0; x < w; x++) {
      const andBit = and.length ? (and[sy * andStride + (x >> 3)] >> (7 - (x & 7))) & 1 : 0;
      let r = 0, g = 0, b = 0, a = 255;
      const o = sy * xorStride;
      if (xorBpp === 32) { b = xor[o + x * 4]; g = xor[o + x * 4 + 1]; r = xor[o + x * 4 + 2]; if (hasAlpha) a = xor[o + x * 4 + 3]; }
      else if (xorBpp === 24) { b = xor[o + x * 3]; g = xor[o + x * 3 + 1]; r = xor[o + x * 3 + 2]; }
      else if (xorBpp === 16) {
        const c = toRgba(xor[o + x * 2] | (xor[o + x * 2 + 1] << 8), 16, null);   // as bitmaps: white is 255,255,255
        r = c & 0xff; g = (c >> 8) & 0xff; b = (c >>> 16) & 0xff;
      } else if (xorBpp === 1) {
        r = g = b = (xor[o + (x >> 3)] >> (7 - (x & 7))) & 1 ? 255 : 0;
      }
      if (!hasAlpha && andBit) {
        if (r | g | b) { r = g = b = 0; a = 255; } else a = 0;   // inverted pixels shown black, others transparent
      }
      rgba[y * w + x] = ((a << 24) | (b << 16) | (g << 8) | r) >>> 0;
    }
  }
  return { index, x: hx, y: hy, w, h, rgba };
}

/**
 * True for the text cursor (I-beam) that Windows shows over text boxes: a thin stem whose first and last rows are wide
 * serifs (the vertical resize and up arrows are thin too, but end in narrow tips). p: a decoded pointer.
 */
export function isTextCursor(p) {
  const spans = [];
  for (let y = 0; y < p.h; y++) {
    let a = -1, b = -1;
    for (let x = 0; x < p.w; x++) if (p.rgba[y * p.w + x] >>> 24 >= 32) { if (a < 0) a = x; b = x; }
    if (a >= 0) spans.push(b - a + 1);
  }
  const n = spans.length, stem = [...spans].sort((a, b) => a - b)[n >> 1];
  return n >= 10 && stem <= Math.max(3, n >> 3) && spans[0] >= 3 * stem && spans[n - 1] >= 3 * stem
    && spans.filter((s) => s > stem).length <= n >> 2;
}
