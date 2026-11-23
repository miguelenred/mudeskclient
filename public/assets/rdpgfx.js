// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// Graphics pipeline (MS-RDPEGFX): ZGFX decompression, PDU parsing and codecs (AVC420 via WebCodecs, RemoteFX, RemoteFX progressive, ClearCodec, planar, uncompressed, alpha).
'use strict';

import { planarDecode } from './gfx.js';
import { ProgressiveDecoder, RfxDecoder } from './rfx.js';

// ------------------------------------------------------------------ ZGFX (RDP 8.0 bulk compression)

// [prefix length, prefix code, value bits, type (0 literal, 1 match), base]
const ZGFX_TOKENS = [
  [1, 0, 8, 0, 0], [5, 17, 5, 1, 0], [5, 18, 7, 1, 32], [5, 19, 9, 1, 160], [5, 20, 10, 1, 672], [5, 21, 12, 1, 1696],
  [5, 24, 0, 0, 0x00], [5, 25, 0, 0, 0x01], [6, 44, 14, 1, 5792], [6, 45, 15, 1, 22176], [6, 52, 0, 0, 0x02], [6, 53, 0, 0, 0x03],
  [6, 54, 0, 0, 0xff], [7, 92, 18, 1, 54944], [7, 93, 20, 1, 317088], [7, 110, 0, 0, 0x04], [7, 111, 0, 0, 0x05], [7, 112, 0, 0, 0x06],
  [7, 113, 0, 0, 0x07], [7, 114, 0, 0, 0x08], [7, 115, 0, 0, 0x09], [7, 116, 0, 0, 0x0a], [7, 117, 0, 0, 0x0b], [7, 118, 0, 0, 0x3a],
  [7, 119, 0, 0, 0x3b], [7, 120, 0, 0, 0x3c], [7, 121, 0, 0, 0x3d], [7, 122, 0, 0, 0x3e], [7, 123, 0, 0, 0x3f], [7, 124, 0, 0, 0x40],
  [7, 125, 0, 0, 0x80], [8, 188, 20, 1, 1365664], [8, 189, 21, 1, 2414240], [8, 252, 0, 0, 0x0c], [8, 253, 0, 0, 0x38],
  [8, 254, 0, 0, 0x39], [8, 255, 0, 0, 0x66], [9, 380, 22, 1, 4511392], [9, 381, 23, 1, 8705696], [9, 382, 24, 1, 17094304],
];
// the token of each 9-bit string the prefix can start (index + 1; 0: no token): one look-up instead of a bit-by-bit search
const ZGFX_LOOKUP = new Uint8Array(512);
ZGFX_TOKENS.forEach(([len, code], i) => { for (let v = 0; v < 1 << (9 - len); v++) ZGFX_LOOKUP[(code << (9 - len)) | v] ||= i + 1; });
const ZGFX_LEN = Uint8Array.from(ZGFX_TOKENS, (t) => t[0]), ZGFX_BITS = Uint8Array.from(ZGFX_TOKENS, (t) => t[2]);
const ZGFX_MATCH = Uint8Array.from(ZGFX_TOKENS, (t) => t[3]), ZGFX_BASE = Int32Array.from(ZGFX_TOKENS, (t) => t[4]);
const HISTORY = 2500000;
const ZGFX_MAX = 1 << 26;                          // largest decompressed message accepted (64 MB: a 4K picture uncompressed is 33 MB)
const CACHE_MAX = 100 * 1048576;                   // the bitmap cache a server may fill (MS-RDPEGFX: 100 MB, 16 MB thin client)

export class Zgfx {
  constructor() {
    this.hist = new Uint8Array(HISTORY);
    this.hpos = 0;
  }

  #keep(b) {                                       // appends bytes to the history ring
    if (b.length > HISTORY) b = b.subarray(b.length - HISTORY);
    const n = Math.min(b.length, HISTORY - this.hpos);
    this.hist.set(b.subarray(0, n), this.hpos);
    this.hist.set(b.subarray(n), 0);
    this.hpos = (this.hpos + b.length) % HISTORY;
  }

  /**
   * One segment into buf from at; returns where it ends. A segment decompresses to 65535 bytes at most (larger: corrupt or
   * hostile), and never past buf.
   */
  #segment(seg, buf, at) {
    const flags = seg[0], data = seg.subarray(1);
    if (!(flags & 0x20)) {                           // not compressed: as is
      if (at + data.length > buf.length) throw new Error('ZGFX: segment too long');   // (bounded by the message, or the size declared)
      this.#keep(data);
      buf.set(data, at);
      return at + data.length;
    }
    if (!data.length) return at;
    const end = Math.min(buf.length, at + 65535), h = this.hist;
    let o = at, hpos = this.hpos, remaining = (data.length - 1) * 8 - data[data.length - 1];
    // bit reader in local variables (it is the hot loop): cur holds the curBits bits read ahead from data[p - 1] back,
    // zeros past the end of the data; each read below is: refill, then take n bits, most significant first
    let p = 0, cur = 0, curBits = 0, n = 0, v = 0;
    while (remaining > 0) {
      while (curBits < 9) { cur = ((cur << 8) | data[p++]) >>> 0; curBits += 8; }
      const t = ZGFX_LOOKUP[(cur >>> (curBits - 9)) & 511] - 1;   // the token its prefix (1 to 9 bits) starts
      if (t < 0) throw new Error('ZGFX: bad token');
      curBits -= ZGFX_LEN[t];
      remaining -= ZGFX_LEN[t];
      cur &= (1 << curBits) - 1;
      n = ZGFX_BITS[t];
      v = 0;
      if (n) {
        while (curBits < n) { cur = ((cur << 8) | data[p++]) >>> 0; curBits += 8; }
        curBits -= n; remaining -= n; v = (cur >>> curBits) & ((1 << n) - 1); cur &= (1 << curBits) - 1;
      }
      if (!ZGFX_MATCH[t]) {
        if (o === end) throw new Error('ZGFX: segment too long');
        buf[o++] = h[hpos] = ZGFX_BASE[t] + v;
        if (++hpos === HISTORY) hpos = 0;
        continue;
      }
      const dist = ZGFX_BASE[t] + v;
      if (dist) {
        let count = 3;
        if (!curBits) { cur = data[p++] | 0; curBits = 8; }
        curBits--; remaining--;
        if ((cur >>> curBits) & 1) {                   // count: 0 is 3; else ones, a zero, then that many bits plus 2
          cur &= (1 << curBits) - 1;
          count = 4;
          let extra = 2;
          for (;;) {
            if (!curBits) { cur = data[p++] | 0; curBits = 8; }
            curBits--; remaining--;
            const bit = (cur >>> curBits) & 1;
            cur &= (1 << curBits) - 1;
            if (!bit) break;
            count *= 2;
            extra++;
          }
          n = extra;
          while (curBits < n) { cur = ((cur << 8) | data[p++]) >>> 0; curBits += 8; }
          curBits -= n; remaining -= n; v = (cur >>> curBits) & ((1 << n) - 1); cur &= (1 << curBits) - 1;
          count += v;
        } else cur &= (1 << curBits) - 1;
        if (o + count > end) throw new Error('ZGFX: segment too long');
        let src = (hpos - dist + HISTORY) % HISTORY;
        for (let i = 0; i < count; i++) {                // byte by byte: the source may overlap what is written
          buf[o++] = h[hpos] = h[src];
          if (++src === HISTORY) src = 0;
          if (++hpos === HISTORY) hpos = 0;
        }
      } else {                                       // literal run, byte aligned
        while (curBits < 15) { cur = ((cur << 8) | data[p++]) >>> 0; curBits += 8; }
        curBits -= 15; remaining -= 15;
        const count = (cur >>> curBits) & 0x7fff;        // (cur holds under a byte now: the run starts at data[p])
        remaining -= curBits + count * 8;
        cur = curBits = 0;
        if (o + count > end || p + count > data.length) throw new Error('ZGFX: bad literal run');
        const lit = data.subarray(p, p + count);
        buf.set(lit, o);
        this.hpos = hpos;
        this.#keep(lit);
        hpos = this.hpos;
        o += count;
        p += count;
      }
    }
    this.hpos = hpos;
    return o;
  }

  /**
   * Decompresses an RDP_SEGMENTED_DATA block into one buffer: a single segment's 65535 bytes, or the size a multipart block
   * declares (at most ZGFX_MAX). Before, each segment had a buffer of its own, kept even when empty: a small hostile block
   * of 65535 segments held 4 GB.
   */
  decompress(u8) {
    if (u8[0] === 0xe0) {
      const buf = new Uint8Array(u8[1] & 0x20 ? 65535 : Math.max(0, u8.length - 2));
      return buf.subarray(0, this.#segment(u8.subarray(1), buf, 0));
    }
    if (u8[0] !== 0xe1 || u8.length < 7) throw new Error('ZGFX: bad descriptor');
    const dv = new DataView(u8.buffer, u8.byteOffset, u8.byteLength);
    const n = dv.getUint16(1, true), size = dv.getUint32(3, true);
    if (size > ZGFX_MAX) throw new Error(`ZGFX: ${size} bytes`);
    const buf = new Uint8Array(size);
    let p = 7, at = 0;
    for (let i = 0; i < n && p + 4 <= u8.length; i++) {
      const len = dv.getUint32(p, true);
      at = this.#segment(u8.subarray(p + 4, p + 4 + len), buf, at);
      p += 4 + len;
    }
    return at === size ? buf : buf.subarray(0, at);
  }
}

// ------------------------------------------------------------------ NSCodec (ClearCodec subcodec)

function nscRle(src, size) {
  const out = new Uint8Array(size);
  let i = 0, o = 0, left = size;
  while (left > 4) {
    const v = src[i++];
    if (left === 5) { out[o++] = v; left--; }
    else if (v === src[i]) {
      i++;
      let len;
      if (src[i] < 0xff) { len = src[i] + 2; i++; } else { i++; len = (src[i] | (src[i + 1] << 8) | (src[i + 2] << 16) | (src[i + 3] << 24)) >>> 0; i += 4; }
      if (len < 2 || len > left - 4) throw new Error('NSCodec: bad run');   // the last 4 bytes are raw; past the end of the data too
      out.fill(v, o, o + len);
      o += len;
      left -= len;
    } else { out[o++] = v; left--; }
  }
  out.set(src.subarray(i, i + 4), o);
  return out;
}

/** NSCodec bitmap -> RGBA words (top-down); opaque: alpha 255 (its plane is still decoded, and checked). */
export function nscDecode(src, width, height, opaque = false) {
  const dv = new DataView(src.buffer, src.byteOffset, src.byteLength);
  const counts = [0, 1, 2, 3].map((i) => dv.getUint32(i * 4, true));
  const cll = src[16], cs = src[17];
  const rw = cs ? (width + 7) & ~7 : width, rh = cs ? (height + 1) & ~1 : height;
  const sizes = [rw * height, cs ? (rw >> 1) * (rh >> 1) : width * height, cs ? (rw >> 1) * (rh >> 1) : width * height, width * height];
  let p = 20;
  const planes = counts.map((c, i) => {
    let plane;
    if (c === 0) plane = new Uint8Array(sizes[i]).fill(i === 3 ? 0xff : 0);
    else if (c < sizes[i]) plane = nscRle(src.subarray(p, p + c), sizes[i]);
    else plane = src.slice(p, p + sizes[i]);
    p += c;
    return plane;
  });
  const shift = cll - 1;
  const out = new Uint32Array(width * height), [Y, Co, Cg, A] = planes;
  // (a plane shorter than its size reads undefined: NaN channels, which the | below makes 0, as Math.min/max did)
  for (let y = 0, o = 0; y < height; y++) {
    const yRow = y * rw, cRow = cs ? (y >> 1) * (rw >> 1) : y * width;
    for (let x = 0; x < width; x++, o++) {
      const yv = Y[yRow + x];
      const ci = cRow + (cs ? x >> 1 : x);
      const co = ((Co[ci] << shift) << 24) >> 24, cg = ((Cg[ci] << shift) << 24) >> 24;
      let r = yv + co - cg, g = yv + cg, b = yv - co - cg;
      r = r < 0 ? 0 : r > 255 ? 255 : r;
      g = g < 0 ? 0 : g > 255 ? 255 : g;
      b = b < 0 ? 0 : b > 255 ? 255 : b;
      out[o] = (opaque ? 0xff000000 : A[o] << 24) | (b << 16) | (g << 8) | r;
    }
  }
  return out;
}

// ------------------------------------------------------------------ ClearCodec

const bgr = (s, p) => (0xff000000 | (s[p + 2]) | (s[p + 1] << 8) | (s[p] << 16)) >>> 0;   // B,G,R bytes -> RGBA word

export class ClearCodec {
  constructor() {
    this.vbars = new Array(32768);
    this.short = new Array(16384);
    this.vCursor = 0;
    this.sCursor = 0;
  }

  /**
   * Decodes width x height RGBA words (top-down). Pixels the stream does not set keep alpha 0: they must leave the
   * surface untouched. Returns {px, glyph} or, for a glyph cache hit, {hit} (the glyph cache lives with the surfaces).
   */
  decode(src, width, height) {
    const dv = new DataView(src.buffer, src.byteOffset, src.byteLength);
    const flags = src[0];
    let p = 2, glyph = -1;
    if (flags & 0x01) { glyph = dv.getUint16(p, true); p += 2; }
    if (flags & 0x02) return { hit: glyph };
    if (flags & 0x04) { this.vCursor = 0; this.sCursor = 0; }
    const out = new Uint32Array(width * height);
    const residual = dv.getUint32(p, true), bands = dv.getUint32(p + 4, true), sub = dv.getUint32(p + 8, true);
    p += 12;
    if (residual) this.#residual(src.subarray(p, p + residual), out, width * height);
    p += residual;
    if (bands) this.#bands(src.subarray(p, p + bands), out, width, height);
    p += bands;
    if (sub) this.#subcodecs(src.subarray(p, p + sub), out, width, height);
    return { px: out, glyph };
  }

  #at = 0;                                               // where the last #run ended

  #run(s, p) {                                           // run length: u8, 0xff -> u16, 0xffff -> u32
    let n = s[p++];
    if (n === 0xff) {
      n = s[p] | (s[p + 1] << 8); p += 2;
      if (n === 0xffff) { n = (s[p] | (s[p + 1] << 8) | (s[p + 2] << 16) | (s[p + 3] << 24)) >>> 0; p += 4; }
    }
    this.#at = p;
    return n;
  }

  #residual(s, out, total) {
    let p = 0, o = 0;
    while (p < s.length && o < total) {
      const c = bgr(s, p);
      const n = this.#run(s, p + 3);
      p = this.#at;
      out.fill(c, o, Math.min(total, o + n));
      o += n;
    }
  }

  #bands(s, out, width, height) {
    const dv = new DataView(s.buffer, s.byteOffset, s.byteLength);
    let p = 0;
    while (p + 11 <= s.length) {
      const xStart = dv.getUint16(p, true), xEnd = dv.getUint16(p + 2, true);
      const yStart = dv.getUint16(p + 4, true), yEnd = dv.getUint16(p + 6, true);
      const bkg = bgr(s, p + 8);
      p += 11;
      const h = yEnd - yStart + 1;
      if (h < 1 || h > 52) throw new Error('ClearCodec: bad band height');   // a vBar has 52 pixels at most
      for (let x = xStart; x <= xEnd; x++) {
        const hdr = dv.getUint16(p, true);
        p += 2;
        let vbar;
        if ((hdr & 0xc000) === 0x4000 || (hdr & 0xc000) === 0x0000) {   // short vBar: hit or miss
          let yOn, pix;
          if (hdr & 0x4000) { pix = this.short[hdr & 0x3fff]; yOn = s[p++]; }
          else {
            yOn = hdr & 0xff;
            const count = ((hdr >> 8) & 0x3f) - yOn;
            pix = new Uint32Array(Math.max(0, count));
            for (let i = 0; i < count; i++, p += 3) pix[i] = bgr(s, p);
            this.short[this.sCursor] = pix;
            this.sCursor = (this.sCursor + 1) % 16384;
          }
          vbar = new Uint32Array(h).fill(bkg);
          if (pix) vbar.set(pix.subarray(0, Math.max(0, Math.min(pix.length, h - yOn))), Math.min(yOn, h));
          this.vbars[this.vCursor] = vbar;
          this.vCursor = (this.vCursor + 1) % 32768;
        } else vbar = this.vbars[hdr & 0x7fff] || new Uint32Array(h);   // full vBar cache hit (unknown: left untouched)
        if (x >= width) continue;
        for (let y = 0; y < h && yStart + y < height; y++) out[(yStart + y) * width + x] = vbar[y];
      }
    }
  }

  #subcodecs(s, out, width, height) {
    const dv = new DataView(s.buffer, s.byteOffset, s.byteLength);
    let p = 0;
    while (p + 13 <= s.length) {
      const x0 = dv.getUint16(p, true), y0 = dv.getUint16(p + 2, true), w = dv.getUint16(p + 4, true), h = dv.getUint16(p + 6, true);
      const len = dv.getUint32(p + 8, true), id = s[p + 12];
      const data = s.subarray(p + 13, p + 13 + len);
      p += 13 + len;
      if (x0 + w > width || y0 + h > height) continue;   // not within the rectangle (its size, from the data, asked for gigabytes)
      if (id === 0) {                                    // uncompressed: straight into place
        for (let y = 0, i = 0; y < h; y++) for (let x = 0, o = (y0 + y) * width + x0; x < w; x++, i += 3) out[o++] = bgr(data, i);
        continue;
      }
      let px;
      if (id === 1) px = nscDecode(data, w, h, true);
      else if (id === 2) px = this.#rlex(data, w * h);
      else throw new Error('ClearCodec: bad subcodec');
      for (let y = 0, i = 0; y < h; y++) for (let x = 0, o = (y0 + y) * width + x0; x < w; x++) out[o++] = px[i++];
    }
  }

  #rlex(s, total) {
    const n = s[0];
    const pal = new Uint32Array(n);
    for (let i = 0; i < n; i++) pal[i] = bgr(s, 1 + i * 3);
    const bitsN = Math.max(1, Math.ceil(Math.log2(n)));
    const out = new Uint32Array(total);
    let p = 1 + n * 3, o = 0;
    while (p < s.length && o < total) {
      const t = s[p++];
      const stop = t & ((1 << bitsN) - 1), depth = t >> bitsN;
      const run = this.#run(s, p);
      p = this.#at;
      const start = stop - depth;
      out.fill(pal[start], o, Math.min(total, o + run));
      o += run;
      for (let i = 0; i <= depth && o < total; i++) out[o++] = pal[start + i];
    }
    return out;
  }
}

// ------------------------------------------------------------------ H.264 (AVC420)

function avcCodec(nal) {                         // codec string from the SPS (avc1.PPCCLL)
  for (let i = 0; i + 6 < nal.length; i++) {                // start code, NAL header and the 3 bytes of the string
    if (nal[i] === 0 && nal[i + 1] === 0 && nal[i + 2] === 1 && (nal[i + 3] & 0x1f) === 7) {
      return 'avc1.' + [nal[i + 4], nal[i + 5], nal[i + 6]].map((b) => b.toString(16).padStart(2, '0')).join('');
    }
  }
  return null;
}
const isKey = (nal) => {
  for (let i = 0; i + 3 < nal.length; i++) if (nal[i] === 0 && nal[i + 1] === 0 && nal[i + 2] === 1 && (nal[i + 3] & 0x1f) === 5) return true;
  return false;
};

// AVC420 in RDP is BT.709 full range, but the SPS usually does not say so and decoders then assume limited range.
// The SPS is rewritten with that colour description (VUI video signal type), which every browser honours.
const BT709 = { primaries: 'bt709', transfer: 'bt709', matrix: 'bt709', fullRange: true };

class Bits {
  constructor(u8) { this.u8 = u8; this.p = 0; }
  u(n) { let v = 0; for (let i = 0; i < n; i++, this.p++) v = v * 2 + ((this.u8[this.p >> 3] >> (7 - (this.p & 7))) & 1); return v; }
  ue() { let z = 0; while (!this.u(1)) if (++z > 31) throw new Error('SPS: bad Exp-Golomb code'); return 2 ** z - 1 + this.u(z); }
  se() { const v = this.ue(); return v & 1 ? (v + 1) / 2 : -v / 2; }
}

class BitOut {
  constructor() { this.b = []; }
  u(n, v) { for (let i = n - 1; i >= 0; i--) this.b.push(Math.floor(v / 2 ** i) & 1); }
  ue(v) { const z = Math.floor(Math.log2(v + 1)); this.u(z, 0); this.u(z + 1, v + 1); }
  copy(r, end) { while (r.p < end) this.b.push(r.u(1)); }
  bytes() {
    this.b.push(1);                                   // rbsp_stop_one_bit + alignment
    while (this.b.length & 7) this.b.push(0);
    const out = new Uint8Array(this.b.length >> 3);
    for (let i = 0; i < this.b.length; i++) out[i >> 3] |= this.b[i] << (7 - (i & 7));
    return out;
  }
}

const unescape = (n) => { const o = []; for (let i = 0; i < n.length; i++) { if (i >= 2 && n[i] === 3 && !n[i - 1] && !n[i - 2]) continue; o.push(n[i]); } return o; };
const escape = (r) => { const o = []; let z = 0; for (const b of r) { if (z >= 2 && b <= 3) { o.push(3); z = 0; } o.push(b); z = b ? 0 : z + 1; } return o; };

/** SPS RBSP (without the NAL header) -> the same SPS declaring BT.709 full range. */
export function spsFullRange(rbsp) {
  const r = new Bits(Uint8Array.from(rbsp)), w = new BitOut();
  const profile = r.u(8);
  r.u(16); r.ue();
  if ([100, 110, 122, 244, 44, 83, 86, 118, 128, 138, 139, 134, 135].includes(profile)) {
    const chroma = r.ue();
    if (chroma === 3) r.u(1);
    r.ue(); r.ue(); r.u(1);
    if (r.u(1)) {
      for (let i = 0; i < (chroma === 3 ? 12 : 8); i++) {     // scaling lists: only skipped
        if (!r.u(1)) continue;
        let last = 8, next = 8;
        for (let j = 0; j < (i < 6 ? 16 : 64); j++) { if (next) { next = (last + r.se() + 256) % 256; } last = next || last; }
      }
    }
  }
  r.ue();
  const poc = r.ue();
  if (poc === 0) r.ue();
  else if (poc === 1) { r.u(1); r.se(); r.se(); const n = r.ue(); for (let i = 0; i < n; i++) r.se(); }
  const refs = r.ue();
  r.u(1); r.ue(); r.ue();
  if (!r.u(1)) r.u(1);
  r.u(1);
  if (r.u(1)) { r.ue(); r.ue(); r.ue(); r.ue(); }
  w.copy(new Bits(r.u8), r.p);
  w.u(1, 1);
  const signal = () => { w.u(1, 1); w.u(3, 5); w.u(1, 1); w.u(1, 1); w.u(8, 1); w.u(8, 1); w.u(8, 1); };
  if (!r.u(1)) {                                          // no VUI: write a minimal one
    w.u(2, 0); signal(); w.u(5, 0);                       // no aspect/overscan; signal; no chroma loc, timing, HRD, pic_struct
    w.u(1, 1); w.u(1, 1); w.ue(2); w.ue(1); w.ue(16); w.ue(16); w.ue(0); w.ue(refs);   // no reordering: output each frame at once
    return w.bytes();
  }
  const aspect = r.u(1);
  w.u(1, aspect);
  if (aspect) { const idc = r.u(8); w.u(8, idc); if (idc === 255) w.u(32, r.u(32)); }
  const overscan = r.u(1);
  w.u(1, overscan);
  if (overscan) w.u(1, r.u(1));
  if (r.u(1)) { r.u(4); if (r.u(1)) r.u(24); }            // the existing video signal type is replaced
  signal();
  let stop = r.u8.length * 8 - 1;                         // the rest of the VUI is kept as is, up to the stop bit
  while (stop > r.p && !((r.u8[stop >> 3] >> (7 - (stop & 7))) & 1)) stop--;
  w.copy(r, stop);
  return w.bytes();
}

/** Annex B access unit with every SPS rewritten by spsFullRange. */
export function fixColour(au) {
  const starts = [];
  for (let i = 0; i + 3 <= au.length; i++) if (au[i] === 0 && au[i + 1] === 0 && au[i + 2] === 1) { starts.push(i + 3); i += 2; }
  if (!starts.some((p) => (au[p] & 0x1f) === 7)) return au;
  const parts = [], start = Uint8Array.of(0, 0, 0, 1);   // typed arrays: a key frame NAL is too long to spread into push()
  starts.forEach((p, k) => {
    let end = k + 1 < starts.length ? starts[k + 1] - 3 : au.length;
    if (k + 1 < starts.length && au[end - 1] === 0) end--;         // 4-byte start code
    const nal = au.subarray(p, end);
    parts.push(start, (nal[0] & 0x1f) === 7 ? Uint8Array.of(nal[0], ...escape(spsFullRange(unescape(nal.subarray(1))))) : nal);
  });
  const out = new Uint8Array(parts.reduce((n, x) => n + x.length, 0));
  parts.reduce((o, x) => { out.set(x, o); return o + x.length; }, 0);
  return out;
}

// ------------------------------------------------------------------ pipeline

/**
 * Parses GFX PDUs and turns them into ordered drawing operations for the main thread (see gfxsurfaces.js).
 * Operations keep their order: those after an H.264 frame wait until the decoder outputs it.
 */
const GFX_CMDS = { 1: 'wireToSurface1', 2: 'wireToSurface2', 3: 'deleteEncodingContext', 4: 'solidFill', 5: 'surfaceToSurface', 6: 'surfaceToCache',
  7: 'cacheToSurface', 8: 'evictCache', 9: 'createSurface', 10: 'deleteSurface', 11: 'startFrame', 12: 'endFrame', 14: 'resetGraphics',
  15: 'mapSurfaceToOutput', 17: 'cacheImportReply', 19: 'capsConfirm', 21: 'mapSurfaceToWindow', 22: 'qoeFrameAckn', 23: 'mapSurfaceToScaledOutput', 24: 'mapSurfaceToScaledWindow' };
const OUTPUT_MAX = 32766;                          // largest side of the graphics output and of a desktop in RDP (MS-RDPEGFX, MS-RDPBCGR)
const DESKTOP_MAX = 1 << 26;                       // largest desktop accepted, in pixels (8192 x 8192; 4 monitors of 4K are half of it)
const GFX_CODECS = { 0: 'uncompressed', 3: 'remoteFX', 8: 'clearCodec', 10: 'planar', 11: 'avc420', 12: 'alpha', 14: 'avc444', 15: 'avc444v2' };

export class GfxPipeline {
  constructor(emit) {
    this.emit = emit;
    this.zgfx = new Zgfx();
    this.clear = new ClearCodec();
    this.rfx = new RfxDecoder();
    this.prog = new ProgressiveDecoder();
    this.decoders = new Map();                      // surfaceId -> {dec, pending: []}
    this.argb = new Set();                          // surfaces with an alpha channel
    this.sizes = new Map();                         // surfaceId -> [width, height]
    this.outArea = 0;                               // pixels of the graphics output (ResetGraphics)
    this.slots = new Map();                         // cache slot -> its bytes (CACHE_MAX in all)
    this.cached = 0;
    this.queue = [];                                // ordered ops waiting for asynchronous video frames
    this.ts = 0;
    this.stats = null;                              // diagnostic mode: message and codec counts
  }

  #out(op, transfer = []) {
    this.queue.push({ op, transfer, ready: true });
    this.#flush();
  }

  #flush() {
    while (this.queue.length && this.queue[0].ready) {
      const { op, transfer } = this.queue.shift();
      this.emit(op, transfer);
    }
  }

  /** One message of the graphics channel (RDP_SEGMENTED_DATA). */
  onData(u8) {
    const data = this.zgfx.decompress(u8);
    const dv = new DataView(data.buffer, data.byteOffset, data.byteLength);
    let p = 0;
    while (p + 8 <= data.length) {
      const cmd = dv.getUint16(p, true), len = dv.getUint32(p + 4, true);
      if (len < 8 || p + len > data.length) break;
      if (this.stats) {
        const k = cmd === 0x0001 ? 'wireToSurface1:' + (GFX_CODECS[dv.getUint16(p + 10, true)] ?? dv.getUint16(p + 10, true)) : GFX_CMDS[cmd] ?? 'cmd' + cmd;
        this.stats[k] = (this.stats[k] || 0) + 1;
      }
      try {
        this.#pdu(cmd, data.subarray(p + 8, p + len), new DataView(data.buffer, data.byteOffset + p + 8, len - 8));
      } catch (e) {                                  // the PDUs after it (the end of frame, above all) still count
        this.emit({ op: 'log', msg: 'gfx: ' + e.message });
      }
      p += len;
    }
  }

  #rect(dv, o) {
    return { l: dv.getUint16(o, true), t: dv.getUint16(o + 2, true), r: dv.getUint16(o + 4, true), b: dv.getUint16(o + 6, true) };
  }

  #pdu(cmd, s, dv) {
    switch (cmd) {
      case 0x0009: {
        const id = dv.getUint16(0, true), w = dv.getUint16(2, true), h = dv.getUint16(4, true);
        if (w > OUTPUT_MAX || h > OUTPUT_MAX) throw new Error(`GFX: surface of ${w} x ${h}`);
        // all the surfaces together within 4 times the output (each monitor has one; Windows adds a few more): a hostile
        // server could otherwise ask for any number of them, each up to 32766 x 32766
        const [ow, oh] = this.sizes.get(id) || [0, 0];
        let area = w * h - ow * oh;
        for (const [sw, sh] of this.sizes.values()) area += sw * sh;
        if (area > 4 * Math.max(this.outArea, 1920 * 1080)) throw new Error(`GFX: surfaces of ${area} pixels`);
        if (s[6] === 0x21) this.argb.add(id); else this.argb.delete(id);
        this.sizes.set(id, [w, h]);
        this.#out({ op: 'create', id, w, h });
        break;
      }
      case 0x000a: {
        const id = dv.getUint16(0, true), d = this.decoders.get(id);
        if (d) {                                       // close() outputs nothing more and calls no error: free the waiting frames
          for (const e of d.pending) e.ready = true;
          d.pending = [];
          d.dec.close();
          this.decoders.delete(id);
        }
        this.prog.deleteSurface(id);
        this.sizes.delete(id);
        this.#out({ op: 'delete', id });
        break;
      }
      case 0x000f: case 0x0017:                        // map surface to output (scaled: same fields first; it comes 1:1)
        this.#out({ op: 'map', id: dv.getUint16(0, true), x: dv.getUint32(4, true), y: dv.getUint32(8, true) }); break;
      case 0x000e: {
        const w = dv.getUint32(0, true), h = dv.getUint32(4, true);
        if (w > OUTPUT_MAX || h > OUTPUT_MAX || w * h > DESKTOP_MAX) throw new Error(`GFX: output of ${w} x ${h}`);
        this.outArea = w * h;
        this.#out({ op: 'reset', w, h });
        break;
      }
      case 0x000b: break;                              // start frame
      case 0x000c: this.#out({ op: 'end', frame: dv.getUint32(0, true) }); break;
      case 0x0004: {
        const color = (0xff000000 | (s[4] | (s[3] << 8) | (s[2] << 16))) >>> 0;   // after the surface id: B, G, R, XA
        const n = dv.getUint16(6, true), rects = [];
        for (let i = 0; i < n; i++) rects.push(this.#rect(dv, 8 + i * 8));
        this.#out({ op: 'fill', id: dv.getUint16(0, true), color, rects });
        break;
      }
      case 0x0005: {
        const n = dv.getUint16(12, true), pts = [];
        for (let i = 0; i < n; i++) pts.push([dv.getUint16(14 + i * 4, true), dv.getUint16(16 + i * 4, true)]);
        this.#out({ op: 's2s', src: dv.getUint16(0, true), dst: dv.getUint16(2, true), rect: this.#rect(dv, 4), pts });
        break;
      }
      case 0x0006: {                                   // surface to cache: a rectangle inside the surface, all slots within CACHE_MAX
        const id = dv.getUint16(0, true), slot = dv.getUint16(10, true), r = this.#rect(dv, 12), [sw, sh] = this.sizes.get(id) || [0, 0];
        if (r.r <= r.l || r.b <= r.t || r.r > sw || r.b > sh) throw new Error('GFX: cache rectangle outside its surface');
        const bytes = (r.r - r.l) * (r.b - r.t) * 4;
        if (this.cached - (this.slots.get(slot) || 0) + bytes > CACHE_MAX) throw new Error('GFX: bitmap cache over 100 MB');
        this.cached += bytes - (this.slots.get(slot) || 0);
        this.slots.set(slot, bytes);
        this.#out({ op: 's2c', id, slot, rect: r });
        break;
      }
      case 0x0007: {
        const n = dv.getUint16(4, true), pts = [];
        for (let i = 0; i < n; i++) pts.push([dv.getUint16(6 + i * 4, true), dv.getUint16(8 + i * 4, true)]);
        this.#out({ op: 'c2s', slot: dv.getUint16(0, true), id: dv.getUint16(2, true), pts });
        break;
      }
      case 0x0008: {
        const slot = dv.getUint16(0, true);
        this.cached -= this.slots.get(slot) || 0;
        this.slots.delete(slot);
        this.#out({ op: 'evict', slot });
        break;
      }
      case 0x0001: this.#wire(dv.getUint16(0, true), dv.getUint16(2, true), this.#rect(dv, 5), s.subarray(17, 17 + dv.getUint32(13, true))); break;
      case 0x0002: {                                   // wire to surface 2: progressive, the whole surface
        const id = dv.getUint16(0, true), [w, h] = this.sizes.get(id) || [0, 0];
        if (dv.getUint16(2, true) !== 0x0009 || !w) break;
        for (const b of this.prog.decode(id, s.subarray(13, 13 + dv.getUint32(9, true)), w, h)) {
          this.#out({ op: 'px', id, x: b.x, y: b.y, w: b.w, h: b.h, px: b.px, blend: true, parts: b.parts }, [b.px.buffer]);
        }
        break;
      }
      case 0x0003: break;                              // delete encoding context
      case 0x0013:                                     // caps confirm: the version the server chose
        if (this.stats) this.emit({ op: 'note', msg: `caps confirm version 0x${dv.getUint32(0, true).toString(16)} flags 0x${dv.getUint32(8, true).toString(16)}` });
        break;
      default: break;                                  // cache import reply, window mapping: not used
    }
  }

  #wire(id, codec, r, data) {
    const w = r.r - r.l, h = r.b - r.t, argb = this.argb.has(id), [sw, sh] = this.sizes.get(id) || [0, 0];
    if (w < 0 || h < 0 || r.r > sw || r.b > sh) throw new Error('GFX: rectangle outside the surface');   // as FreeRDP checks
    let px;
    switch (codec) {
      case 0x0000: {                                   // uncompressed 32 bpp (B, G, R, A): a word at a time, red and blue swapped
        px = new Uint32Array(w * h);
        const whole = Math.min(px.length, data.length >> 2), dv = new DataView(data.buffer, data.byteOffset, data.byteLength);
        if (argb) for (let i = 0; i < whole; i++) { const v = dv.getUint32(4 * i, true); px[i] = (v & 0xff00ff00) | ((v >> 16) & 0xff) | ((v & 0xff) << 16); }
        else for (let i = 0; i < whole; i++) { const v = dv.getUint32(4 * i, true); px[i] = 0xff000000 | (v & 0xff00) | ((v >> 16) & 0xff) | ((v & 0xff) << 16); }
        for (let i = whole, o = 4 * whole; i < px.length; i++, o += 4) px[i] = ((argb ? data[o + 3] << 24 : 0xff000000) | data[o + 2] | (data[o + 1] << 8) | (data[o] << 16)) >>> 0;
        break;
      }
      case 0x000a:                                     // planar, rows top-down
        px = planarDecode(data, w, h, !argb);
        break;
      case 0x0008: {
        const c = this.clear.decode(data, w, h);
        if (c.hit !== undefined) { this.#out({ op: 'glyph', id, x: r.l, y: r.t, w, h, idx: c.hit }); return; }
        // glyphs as ClearCodec allows them (index below 4000, at most 1024 pixels): more would only grow the cache
        this.#out({ op: 'px', id, x: r.l, y: r.t, w, h, px: c.px, blend: true, glyph: c.glyph < 4000 && w * h <= 1024 ? c.glyph : -1 }, [c.px.buffer]);
        return;
      }
      case 0x0003: px = this.rfx.decode(data, w, h); this.#out({ op: 'px', id, x: r.l, y: r.t, w, h, px, blend: true }, [px.buffer]); return;
      case 0x000c: { const a = this.#alpha(data, w * h); this.#out({ op: 'alpha', id, x: r.l, y: r.t, w, h, a }, [a.buffer]); return; }
      case 0x000b: this.#avc(id, data); return;
      case 0x000e: case 0x000f: {                      // AVC444 v1/v2: the first stream is a 4:2:0 picture of its own (its
        const v = new DataView(data.buffer, data.byteOffset, 4).getUint32(0, true), lc = v >>> 30;   // extra chroma is left out)
        if (lc !== 2) this.#avc(id, data.subarray(4, lc ? data.length : 4 + (v & 0x3fffffff)));     // 2: chroma-only update
        return;
      }
      default: throw new Error('GFX: unsupported codec ' + codec);
    }
    this.#out({ op: 'px', id, x: r.l, y: r.t, w, h, px }, [px.buffer]);
  }

  #alpha(s, total) {
    const dv = new DataView(s.buffer, s.byteOffset, s.byteLength);
    const a = new Uint8Array(total);
    if (!dv.getUint16(2, true)) { a.set(s.subarray(4, 4 + total)); return a; }
    let p = 4, o = 0;
    while (p < s.length && o < total) {
      const v = s[p++];
      let n = s[p++];
      if (n === 0xff) { n = dv.getUint16(p, true); p += 2; if (n === 0xffff) { n = dv.getUint32(p, true); p += 4; } }
      a.fill(v, o, Math.min(total, o + n));
      o += n;
    }
    return a;
  }

  #avc(id, data) {
    const dv = new DataView(data.buffer, data.byteOffset, data.byteLength);
    const n = dv.getUint32(0, true), rects = [];
    for (let i = 0; i < n; i++) rects.push(this.#rect(dv, 4 + i * 8));
    const nal = data.subarray(4 + n * 10);
    let d = this.decoders.get(id);
    if (!d) {
      const codec = avcCodec(nal);
      if (!codec) return;                             // wait for a key frame with SPS
      d = { pending: [] };
      d.dec = new VideoDecoder({
        output: (frame) => {
          const entry = d.pending.shift();
          if (!entry) { frame.close(); return; }
          entry.op.frame = frame;
          entry.transfer = [frame];
          entry.ready = true;
          this.#flush();
        },
        error: (e) => {
          this.emit({ op: 'log', msg: 'H.264: ' + e.message });
          for (const p of d.pending) p.ready = true;   // skip the lost frames, keep the rest in order
          d.pending = [];
          this.decoders.delete(id);
          this.#flush();
        },
      });
      d.dec.configure({ codec, optimizeForLatency: true, colorSpace: BT709 });
      this.decoders.set(id, d);
    }
    const entry = { op: { op: 'video', id, rects }, transfer: [], ready: false };
    this.queue.push(entry);
    d.pending.push(entry);
    try {
      if (nal.length) { d.dec.decode(new EncodedVideoChunk({ type: isKey(nal) ? 'key' : 'delta', timestamp: this.ts++, data: fixColour(nal) })); return; }
    } catch (e) {                                     // e.g. a delta frame first: nothing will come out for this entry
      this.emit({ op: 'log', msg: 'H.264: ' + e.message });
    }
    d.pending.splice(d.pending.indexOf(entry), 1);
    entry.ready = true;
    this.#flush();
  }
}
