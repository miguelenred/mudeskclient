// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// Bitmap and pointer decoders for RDP updates (interleaved RLE, planar, raw).
'use strict';

const WHITE = { 1: 0xff, 2: 0xffff, 3: 0xffffff };
const DEPTHS = new Set([8, 15, 16, 24, 32]);             // colour depths of RDP bitmaps

/** Interleaved RLE (MS-RDPBCGR 2.2.9.1.1.3.1.2.4). Returns pixel values, bottom-up, width*height. */
export function rleDecode(src, width, height, bpp) {
  const out = new Uint32Array(width * height);
  const end = src.length;
  const white = WHITE[bpp];
  let s = 0, d = 0, fg = white, insertFg = false, firstLine = true;

  const px = () => {
    let v = src[s];
    if (bpp > 1) v |= src[s + 1] << 8;
    if (bpp > 2) v |= src[s + 2] << 16;
    s += bpp;
    return v;
  };
  const put = (v) => { if (d < out.length) out[d++] = v; };
  const above = () => out[d - width];
  const fgbg = (mask, bits) => {
    for (let i = 0; i < bits; i++) {
      const set = mask & (1 << i);
      if (firstLine) put(set ? fg : 0);
      else put(set ? above() ^ fg : above());
    }
  };

  while (s < end && d < out.length) {
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

    if (code === 0x00 || code === 0xf0) {               // background run
      if (insertFg && run > 0) { put(firstLine ? fg : above() ^ fg); run--; }
      const end = Math.min(d + run, out.length);
      if (firstLine) out.fill(0, d, end);
      else if (end - d <= width) out.copyWithin(d, d - width, end - width);      // no overlap with what is being written
      else for (let i = d; i < end; i++) out[i] = out[i - width];
      d = end;
      insertFg = true;
      continue;
    }
    insertFg = false;

    switch (code) {
      case 0x01: case 0xf1: case 0x0c: case 0xf6:      // foreground runs
        if (code === 0x0c || code === 0xf6) fg = px();
        while (run-- > 0) put(firstLine ? fg : above() ^ fg);
        break;
      case 0x0e: case 0xf8: {                           // dithered run
        const a = px(), b = px();
        while (run-- > 0) { put(a); put(b); }
        break;
      }
      case 0x03: case 0xf3: {                           // color run
        const c = px();
        const end = Math.min(d + run, out.length);
        out.fill(c, d, end);
        d = end;
        break;
      }
      case 0x02: case 0xf2: case 0x0d: case 0xf7:      // FGBG images
        if (code === 0x0d || code === 0xf7) fg = px();
        while (run > 8) { fgbg(src[s++], 8); run -= 8; }
        if (run > 0) fgbg(src[s++], run);
        break;
      case 0x04: case 0xf4: {                           // color image (hot path)
        const end = Math.min(d + run, out.length), skip = (run - (end - d)) * bpp;
        if (bpp === 2) for (; d < end; s += 2) out[d++] = src[s] | (src[s + 1] << 8);
        else if (bpp === 3) for (; d < end; s += 3) out[d++] = src[s] | (src[s + 1] << 8) | (src[s + 2] << 16);
        else while (d < end) out[d++] = src[s++];
        s += skip;
        break;
      }
      case 0xf9: fgbg(0x03, 8); break;                  // special FGBG 1
      case 0xfa: fgbg(0x05, 8); break;                  // special FGBG 2
      case 0xfd: put(white); break;
      case 0xfe: put(0); break;
      default: throw new Error('RLE: bad order 0x' + h.toString(16));
    }
  }
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

/** Converts a pixel value of the given depth to 0xAABBGGRR (little-endian RGBA). */
function toRgba(v, bpp, palette) {
  switch (bpp) {
    case 32: case 24: return 0xff000000 | ((v & 0xff) << 16) | (v & 0xff00) | ((v >> 16) & 0xff);
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

/** Planar codec (MS-RDPEGDI 2.2.2.5.1). Returns RGBA words, bottom-up. */
export function planarDecode(src, width, height) {
  const hdr = src[0];
  if (hdr & 0x07) throw new Error('Planar: color loss not supported');
  const rle = hdr & 0x10, noAlpha = hdr & 0x20;
  const n = width * height;
  let s = 1;
  const plane = () => {
    const p = new Uint8Array(n);
    if (!rle) { p.set(src.subarray(s, s + n)); s += n; return p; }
    for (let y = 0; y < height; y++) {
      const row = y * width;
      let x = 0, last = 0;
      while (x < width) {
        if (s >= src.length) throw new Error('Planar: truncated plane');   // a control byte 0 does not move x
        const ctl = src[s++];
        let runLen = ctl & 0x0f, raw = ctl >> 4;
        if (runLen === 1) { runLen = raw + 16; raw = 0; } else if (runLen === 2) { runLen = raw + 32; raw = 0; }
        if (y === 0) {
          while (raw-- > 0 && x < width) { last = src[s++]; p[row + x++] = last; }
          while (runLen-- > 0 && x < width) p[row + x++] = last;
        } else {
          while (raw-- > 0 && x < width) {
            const b = src[s++];
            last = b & 1 ? -((b >> 1) + 1) : b >> 1;
            p[row + x] = p[row - width + x] + last; x++;
          }
          while (runLen-- > 0 && x < width) { p[row + x] = p[row - width + x] + last; x++; }
        }
      }
    }
    return p;
  };
  const a = noAlpha ? null : plane();
  const r = plane(), g = plane(), b = plane();
  const out = new Uint32Array(n);
  for (let i = 0; i < n; i++) out[i] = ((a ? a[i] : 0xff) << 24 | b[i] << 16 | g[i] << 8 | r[i]) >>> 0;
  return out;
}

/**
 * Decodes one TS_BITMAP_DATA rectangle into ImageData-ready RGBA (top-down). max: the most pixels a bitmap can have (the
 * desktop, see stream-worker.js); larger sizes, taken from the data, asked for gigabytes.
 */
export function decodeBitmap(data, w, h, bpp, compressed, palette, max) {
  if (!DEPTHS.has(bpp) || w * h > max) throw new Error(`bad size or depth (${w} x ${h} at ${bpp} bpp)`);
  const Bpp = (bpp + 7) >> 3;
  const rgba = new Uint32Array(w * h);
  let pix, isRgba = false;
  if (!compressed) {
    const stride = ((w * Bpp + 3) & ~3);
    pix = new Uint32Array(w * h);
    for (let y = 0; y < h; y++) {
      let o = y * stride;
      for (let x = 0; x < w; x++, o += Bpp) {
        let v = data[o];
        if (Bpp > 1) v |= data[o + 1] << 8;
        if (Bpp > 2) v |= data[o + 2] << 16;
        pix[y * w + x] = v;
      }
    }
  } else if (bpp === 32) {
    pix = planarDecode(data, w, h);
    isRgba = true;
  } else {
    pix = rleDecode(data, w, h, Bpp);
  }
  const table = bpp === 16 || bpp === 15 ? lut(bpp) : null;
  for (let y = 0; y < h; y++) {                 // bottom-up -> top-down
    const srcRow = (h - 1 - y) * w, dstRow = y * w;
    if (table) for (let x = 0; x < w; x++) rgba[dstRow + x] = table[pix[srcRow + x]];
    else if (isRgba) for (let x = 0; x < w; x++) rgba[dstRow + x] = pix[srcRow + x] | 0xff000000;
    else for (let x = 0; x < w; x++) rgba[dstRow + x] = toRgba(pix[srcRow + x], bpp, palette);
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
