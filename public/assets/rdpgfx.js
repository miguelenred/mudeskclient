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
const SLOTS_MAX = 25600;                           // the cache's slots (MS-RDPEGFX MaxCacheSlots): 1 to this
// rectangles of an H.264 message taken one by one at most (more: their bounding box). Before, each went into the queue: a
// few hundred bytes brought 100,000 (60 s of the worker), 11 KB 6.7 million (hours, and 775 MB)
const RECTS_MAX = 64;

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
        if (dist > HISTORY) throw new Error('ZGFX: match beyond the history');   // (its tokens reach 33 MB back: it read before the ring)
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
    // (exactly the size said: shorter was taken, and the 64 MB a 7-byte header asked for kept alive by what referred to it)
    if (at !== size) throw new Error('ZGFX: not the size said');
    return buf;
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
    let p = 0, area = 0;
    while (p + 13 <= s.length) {
      const x0 = dv.getUint16(p, true), y0 = dv.getUint16(p + 2, true), w = dv.getUint16(p + 4, true), h = dv.getUint16(p + 6, true);
      const len = dv.getUint32(p + 8, true), id = s[p + 12];
      const data = s.subarray(p + 13, p + 13 + len);
      p += 13 + len;
      if (x0 + w > width || y0 + h > height) continue;   // not within the rectangle (its size, from the data, asked for gigabytes)
      // all of them over the rectangle once at most (before, empty ones of 13 bytes each drew the whole rectangle: 2.6 KB took
      // 4 s), an uncompressed one with all its pixels (as the specification and other clients require)
      if ((area += w * h) > width * height || (id === 0 && data.length < w * h * 3)) throw new Error('ClearCodec: subcodecs beyond their data');
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
  const restrict = () => { w.u(1, 1); w.u(1, 1); w.ue(2); w.ue(1); w.ue(16); w.ue(16); w.ue(0); w.ue(refs); };
  if (!r.u(1)) {                                          // no VUI: write a minimal one
    w.u(2, 0); signal(); w.u(5, 0);                       // no aspect/overscan; signal; no chroma loc, timing, HRD, pic_struct
    restrict();                                           // no reordering: output each frame at once
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
  // ... and when it says nothing of reordering (no bitstream_restriction: a decoder may then hold each frame until later
  // ones come, and none comes before it is acknowledged), the same "no reordering" as above, put where that flag is
  const at = restrictionFlag(new Bits(r.u8), r.p);
  if (at + 1 === stop && !((r.u8[at >> 3] >> (7 - (at & 7))) & 1)) {   // (the flag 0, the stop bit right after it)
    w.copy(r, at);
    restrict();
    return w.bytes();
  }
  w.copy(r, stop);
  return w.bytes();
}

/**
 * Where bitstream_restriction_flag is in a VUI read up to its chroma location (H.264 E.1.1, E.1.2), or -1 when it cannot
 * be read that way (the VUI is then kept as it is).
 */
function restrictionFlag(r, p) {
  r.p = p;
  try {
    if (r.u(1)) { r.ue(); r.ue(); }                         // chroma_loc_info: two locations
    if (r.u(1)) r.p += 65;                                  // timing_info: num_units_in_tick, time_scale, fixed_frame_rate_flag
    const hrd = () => {                                     // hrd_parameters
      const n = r.ue();
      if (n > 31) throw new Error('SPS: bad HRD');
      r.p += 8;                                             // bit_rate_scale, cpb_size_scale
      for (let i = 0; i <= n; i++) { r.ue(); r.ue(); r.p++; }   // bit rate, CPB size, cbr_flag
      r.p += 20;                                            // four delay and offset lengths
    };
    const nal = r.u(1);
    if (nal) hrd();
    const vcl = r.u(1);
    if (vcl) hrd();
    if (nal || vcl) r.p++;                                  // low_delay_hrd_flag
    r.p++;                                                  // pic_struct_present_flag
    return r.p;
  } catch {
    return -1;
  }
}

/**
 * Annex B access unit with every SPS rewritten by spsFullRange; one longer than SPS_MAX passed as it is (a real one is tens of
 * bytes: a server's of 16 MB was gone through element by element, half a gigabyte of the page's memory).
 */
const SPS_MAX = 4096;
export function fixColour(au) {
  const starts = [];
  for (let i = 0; i + 3 <= au.length; i++) if (au[i] === 0 && au[i + 1] === 0 && au[i + 2] === 1) { starts.push(i + 3); i += 2; }
  if (!starts.some((p) => (au[p] & 0x1f) === 7)) return au;
  const parts = [], start = Uint8Array.of(0, 0, 0, 1);   // typed arrays: a key frame NAL is too long to spread into push()
  starts.forEach((p, k) => {
    let end = k + 1 < starts.length ? starts[k + 1] - 3 : au.length;
    if (k + 1 < starts.length && au[end - 1] === 0) end--;         // 4-byte start code
    const nal = au.subarray(p, end);
    parts.push(start, (nal[0] & 0x1f) === 7 && nal.length <= SPS_MAX ? Uint8Array.of(nal[0], ...escape(spsFullRange(unescape(nal.subarray(1))))) : nal);
  });
  const out = new Uint8Array(parts.reduce((n, x) => n + x.length, 0));
  parts.reduce((o, x) => { out.set(x, o); return o + x.length; }, 0);
  return out;
}

// ------------------------------------------------------------------ AVC444

/**
 * AVC444 (MS-RDPEGFX 3.3.8.3.2): rectangle (x0, y0, w, h) in full colour, as RGBA words (0xAABBGGRR), from the two 4:2:0
 * pictures Windows sends: the main view (the luma, and the chroma averaged over 2 x 2 pixels) and the auxiliary one (the
 * chroma samples the main view leaves out). main, aux: {Y, sy, U, su, V, sv} planes and strides; aux null (none yet): the
 * main view's chroma for all four samples, a 4:2:0 picture. width, height: the surface's.
 * - v1: odd rows from the auxiliary luma, 8 rows of U then 8 of V in turn (B4, B5); odd columns of even rows from the
 *   auxiliary chroma (B6, B7).
 * - v2: odd columns from the auxiliary luma, U in its left half and V in its right half (B4, B5); even columns of odd rows
 *   from the auxiliary chroma, U at every 4th column from 0 and V from 2, each plane U in its left half and V in its right
 *   half (B6 to B9).
 * The sample at an even column and row is the average's: 4 times it less the other three, kept as the average when that
 * moves it by less than 30 (the encoder's rounding otherwise shows as noise). BT.709, full range, as Windows encodes it.
 */
// clamped to 0-255 by a table, with no comparison to mispredict: 1920 x 1080 of varied colours in 10 ms, not 35
const CLAMP = new Uint8Array(2048).map((_, i) => Math.max(0, Math.min(255, i - 1024)));
const rgba = (Y, u, v) => {                      // BT.709, full range: one pixel as a signed RGBA word
  const d = v - 128, e = u - 128, y = Y + 1024;
  return 0xff000000 | (CLAMP[y + ((475 * e) >> 8)] << 16) | (CLAMP[y - ((48 * e + 120 * d) >> 8)] << 8) | CLAMP[y + ((403 * d) >> 8)];
};

export function yuv444Rect(main, aux, v2, x0, y0, w, h, width, height) {
  // by blocks of 2 x 2 pixels (the four samples one 4:2:0 sample stands for, which the filter needs together), the planes
  // and indexes in locals, words written signed (an unsigned one over 2^31 is no small integer to the engine): 1920 x 1080
  // took 95 ms (v1) and 141 ms (v2) a sample at a time with a closure
  const out = new Int32Array(w * h), x1 = x0 + w, y1 = y0 + h, halfW = width >> 1, quarterW = width >> 2;
  const mY = main.Y, mU = main.U, mV = main.V, msy = main.sy, msu = main.su, msv = main.sv;
  const aY = aux ? aux.Y : null, aU = aux ? aux.U : null, aV = aux ? aux.V : null, asy = aux ? aux.sy : 0, asu = aux ? aux.su : 0, asv = aux ? aux.sv : 0;
  for (let by = y0 & ~1; by < y1; by += 2) {
    const cy = by >> 1, row1 = by + 1 < height, top = by >= y0, bottom = row1 && by + 1 < y1;
    const my0 = by * msy, my1 = my0 + msy, o0 = (by - y0) * w - x0, o1 = o0 + w;
    const mu = cy * msu, mv = cy * msv, au = cy * asu, av = cy * asv;
    const ru = (((cy >> 3) << 4) + (cy & 7)) * asy, rv = ru + 8 * asy, r0 = by * asy;
    for (let bx = x0 & ~1; bx < x1; bx += 2) {
      const cx = bx >> 1, col1 = bx + 1 < width, left = bx >= x0, right = col1 && bx + 1 < x1;
      let u00 = mU[mu + cx], v00 = mV[mv + cx];
      let u10 = u00, v10 = v00, u01 = u00, v01 = v00, u11 = u00, v11 = v00;
      if (aux) {
        if (!v2) {
          if (col1) { u10 = aU[au + cx]; v10 = aV[av + cx]; }                                       // B6, B7
          if (row1) {                                                                                // B4, B5
            u01 = aY[ru + bx]; v01 = aY[rv + bx];
            if (col1) { u11 = aY[ru + bx + 1]; v11 = aY[rv + bx + 1]; }
          }
        } else {
          if (col1) {                                                                                // B4, B5
            u10 = aY[r0 + cx]; v10 = aY[r0 + halfW + cx];
            if (row1) { u11 = aY[r0 + asy + cx]; v11 = aY[r0 + asy + halfW + cx]; }
          }
          if (row1) {                                                                                // B6 to B9
            const q = bx >> 2;
            if (bx & 2) { u01 = aV[av + q]; v01 = aV[av + quarterW + q]; } else { u01 = aU[au + q]; v01 = aU[au + quarterW + q]; }
          }
        }
        if (col1 && row1) {
          const fu = 4 * u00 - u10 - u01 - u11, fv = 4 * v00 - v10 - v01 - v11;
          const cu = CLAMP[fu + 1024], cv = CLAMP[fv + 1024];
          if (cu - u00 >= 30 || u00 - cu >= 30) u00 = cu;
          if (cv - v00 >= 30 || v00 - cv >= 30) v00 = cv;
        }
      }
      if (top) {
        if (left) out[o0 + bx] = rgba(mY[my0 + bx], u00, v00);
        if (right) out[o0 + bx + 1] = rgba(mY[my0 + bx + 1], u10, v10);
      }
      if (bottom) {
        if (left) out[o1 + bx] = rgba(mY[my1 + bx], u01, v01);
        if (right) out[o1 + bx + 1] = rgba(mY[my1 + bx + 1], u11, v11);
      }
    }
  }
  return new Uint32Array(out.buffer);
}

/**
 * The planes of a decoded picture ({Y, sy, U, su, V, sv, w, h}; NV12's chroma taken apart), or null when the browser gives
 * none. spare: planes no longer used (the picture before the last one), whose memory is used again when it fits: a new
 * buffer for each picture made some 90 MB of garbage a second at 1920 x 1080 and 30 pictures a second.
 */
async function framePlanes(frame, spare) {
  try {
    const w = frame.codedWidth & ~1, h = frame.codedHeight & ~1, rect = { x: 0, y: 0, width: w, height: h }, size = frame.allocationSize({ rect });
    const buf = spare?.buf.length === size ? spare.buf : new Uint8Array(size);
    const lay = await frame.copyTo(buf, { rect });
    const p = { buf, Y: buf.subarray(lay[0].offset), sy: lay[0].stride, w, h };
    if (frame.format === 'I420') return Object.assign(p, { U: buf.subarray(lay[1].offset), su: lay[1].stride, V: buf.subarray(lay[2].offset), sv: lay[2].stride });
    const cw = w >> 1, ch = h >> 1, uv = buf.subarray(lay[1].offset), s = lay[1].stride;
    const [U, V] = spare?.nv12 && spare.U.length === cw * ch ? [spare.U, spare.V] : [new Uint8Array(cw * ch), new Uint8Array(cw * ch)];
    p.nv12 = true;
    for (let j = 0; j < ch; j++) {
      for (let i = 0; i < cw; i++) { U[j * cw + i] = uv[j * s + 2 * i]; V[j * cw + i] = uv[j * s + 2 * i + 1]; }
    }
    return Object.assign(p, { U, su: cw, V, sv: cw });
  } catch {
    return null;
  } finally {
    frame.close();
  }
}

// ------------------------------------------------------------------ pipeline

/**
 * Parses GFX PDUs and turns them into ordered drawing operations for the main thread (see gfxsurfaces.js).
 * Operations keep their order: those after an H.264 frame wait until the decoder outputs it (or fails, or is given up on:
 * see #unstall).
 */
const GFX_CMDS = { 1: 'wireToSurface1', 2: 'wireToSurface2', 3: 'deleteEncodingContext', 4: 'solidFill', 5: 'surfaceToSurface', 6: 'surfaceToCache',
  7: 'cacheToSurface', 8: 'evictCache', 9: 'createSurface', 10: 'deleteSurface', 11: 'startFrame', 12: 'endFrame', 14: 'resetGraphics',
  15: 'mapSurfaceToOutput', 17: 'cacheImportReply', 19: 'capsConfirm', 21: 'mapSurfaceToWindow', 22: 'qoeFrameAckn', 23: 'mapSurfaceToScaledOutput', 24: 'mapSurfaceToScaledWindow' };
const OUTPUT_MAX = 32766;                          // largest side of the graphics output and of a desktop in RDP (MS-RDPEGFX, MS-RDPBCGR)
const DESKTOP_MAX = 1 << 26;                       // largest desktop accepted, in pixels (8192 x 8192; 4 monitors of 4K are half of it)
const GFX_CODECS = { 0: 'uncompressed', 3: 'remoteFX', 8: 'clearCodec', 10: 'planar', 11: 'avc420', 12: 'alpha', 14: 'avc444', 15: 'avc444v2' };
const STALL = 1000;                                // ms an H.264 decoder may owe pictures without giving any (see #unstall)
const REFRESH_GAP = 2000;                          // ms between two full pictures asked for (see #refresh)

export class GfxPipeline {
  constructor(emit) {
    this.emit = emit;
    this.zgfx = new Zgfx();
    this.clear = new ClearCodec();
    this.rfx = new RfxDecoder();
    this.prog = new ProgressiveDecoder();
    this.decoders = new Map();                      // surfaceId -> {dec, pending: []}
    this.yuv = new Map();                           // surfaceId -> AVC444: {main, aux, luma, chroma, jobs} (see #avc444)
    this.argb = new Set();                          // surfaces with an alpha channel
    this.sizes = new Map();                         // surfaceId -> [width, height]
    this.outArea = 0;                               // pixels of the graphics output (ResetGraphics)
    this.slots = new Map();                         // cache slot -> its bytes (CACHE_MAX in all)
    this.cached = 0;
    this.queue = [];                                // ordered ops waiting for asynchronous video frames
    this.idlers = [];                               // idle() waiting for that queue to empty
    this.software = false;                          // H.264 decoded in software (a recording made into a video: every picture out at once)
    this.ts = 0;
    this.watchdog = 0;                              // timer of #unstall, while pictures are owed (started at watched)
    this.watched = 0;
    this.refreshed = -Infinity;                     // when a full picture was last asked for (#refresh)
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
    if (!this.queue.length) for (const r of this.idlers.splice(0)) r();
  }

  /** Once every operation given so far went out (the video frames being decoded among them). */
  idle() {
    return this.queue.length ? new Promise((r) => this.idlers.push(r)) : Promise.resolve();
  }

  /** One message of the graphics channel (RDP_SEGMENTED_DATA). */
  onData(u8) {
    const data = this.zgfx.decompress(u8);
    const dv = new DataView(data.buffer, data.byteOffset, data.byteLength);
    let p = 0;
    while (p + 8 <= data.length) {
      const cmd = dv.getUint16(p, true), len = dv.getUint32(p + 4, true);
      if (len < 8 || p + len > data.length) break;
      if (this.stats) {                              // (the codec of a PDU long enough to have one: outside the try below, it threw)
        const k = cmd === 0x0001 && len >= 12 ? 'wireToSurface1:' + (GFX_CODECS[dv.getUint16(p + 10, true)] ?? dv.getUint16(p + 10, true)) : GFX_CMDS[cmd] ?? 'cmd' + cmd;
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

  /** $n rectangles from $o on: as they are up to RECTS_MAX, else their bounding box (see RECTS_MAX). */
  #rects(dv, o, n) {
    const rects = [];
    for (let i = 0; i < Math.min(n, RECTS_MAX + 1); i++) rects.push(this.#rect(dv, o + i * 8));
    if (n <= RECTS_MAX) return rects;
    const box = { l: 0xffff, t: 0xffff, r: 0, b: 0 };
    for (let i = 0; i < n; i++) {
      const r = this.#rect(dv, o + i * 8);
      box.l = Math.min(box.l, r.l); box.t = Math.min(box.t, r.t); box.r = Math.max(box.r, r.r); box.b = Math.max(box.b, r.b);
    }
    return box.r > box.l && box.b > box.t ? [box] : [];
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
        this.prog.deleteSurface(id);                   // (an id used again: the progressive tiles of the surface before are not its)
        this.sizes.set(id, [w, h]);
        this.#out({ op: 'create', id, w, h });
        break;
      }
      case 0x000a: {
        const id = dv.getUint16(0, true), d = this.decoders.get(id), y = this.yuv.get(id);
        if (d) {                                       // close() outputs nothing more and calls no error: free the waiting frames
          for (const e of d.pending) e.ready = true;
          d.pending = [];
          d.dec.close();
          this.decoders.delete(id);
        }
        if (y) {                                       // (AVC444: its two decoders, and the pictures they still owed)
          for (const k of ['luma', 'chroma']) if (y[k]) { y[k].dec.close(); y[k] = null; }
          for (const job of y.jobs) {
            for (const e of job.entries) e.ready = true;
            job.luma?.frame?.close();                  // (pictures of a browser without planes, held for the other stream's)
            job.chroma?.frame?.close();
          }
          y.jobs = [];                                 // (a picture still being read finds nothing left to draw)
          this.yuv.delete(id);
          this.#flush();
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
      case 0x0005: {                                   // surface to surface: the rectangle inside its surface, each copy inside the other
        const src = dv.getUint16(0, true), dst = dv.getUint16(2, true), r = this.#rect(dv, 4), n = dv.getUint16(12, true), pts = [];
        const [sw, sh] = this.sizes.get(src) || [0, 0], [dw, dh] = this.sizes.get(dst) || [0, 0];
        // (as FreeRDP checks: one of 65535 x 65535 grew the page's scratch canvas to that size, kept for good)
        if (r.r < r.l || r.b < r.t || r.r > sw || r.b > sh) throw new Error('GFX: copy rectangle outside its surface');
        for (let i = 0; i < n; i++) {
          const x = dv.getUint16(14 + i * 4, true), y = dv.getUint16(16 + i * 4, true);
          if (x + r.r - r.l > dw || y + r.b - r.t > dh) throw new Error('GFX: copy outside its surface');
          pts.push([x, y]);
        }
        this.#out({ op: 's2s', src, dst, rect: r, pts });
        break;
      }
      case 0x0006: {                                   // surface to cache: a rectangle inside the surface, all slots within CACHE_MAX
        const id = dv.getUint16(0, true), slot = dv.getUint16(10, true), r = this.#rect(dv, 12), [sw, sh] = this.sizes.get(id) || [0, 0];
        if (r.r <= r.l || r.b <= r.t || r.r > sw || r.b > sh) throw new Error('GFX: cache rectangle outside its surface');
        if (slot < 1 || slot > SLOTS_MAX) throw new Error('GFX: cache slot out of range');   // (65,536 tiny canvases were possible)
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
      case 0x000b: case 0x000e: case 0x000f:
        // (not offered without WebCodecs: Drdynvc; one sent anyway queued its places and then stopped every later picture)
        if (typeof VideoDecoder !== 'function') throw new Error('GFX: H.264 not offered');
        if (codec === 0x000b) this.#avc(id, data);
        else this.#avc444(id, codec === 0x000f, data);
        return;
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
    const n = data.length >= 4 ? dv.getUint32(0, true) : -1;
    if (n < 0 || 4 + n * 10 > data.length) throw new Error('GFX: AVC420 metablock beyond its data');
    const rects = this.#rects(dv, 4, n);
    const nal = data.subarray(4 + n * 10);
    let d = this.decoders.get(id);
    if (!d) {
      const codec = avcCodec(nal);
      if (!codec) { if (nal.length) this.#refresh(); return; }   // wait for a key frame with SPS (asked for)
      d = { pending: [], last: 0 };                   // last: when it last gave a picture, or began to owe one
      d.dec = new VideoDecoder({
        output: (frame) => {
          d.last = performance.now();
          // pictures come out in the order given (the SPS says no reordering): the chunks before this one's, which the
          // decoder dropped, go out without theirs; a picture whose entry was given up on (#unstall) is freed
          while (d.pending.length && d.pending[0].ts < frame.timestamp) d.pending.shift().ready = true;
          const entry = d.pending[0]?.ts > frame.timestamp ? null : d.pending.shift();
          if (!entry) { frame.close(); this.#flush(); return; }
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
          this.#refresh();                             // (the next decoder needs a key frame)
        },
      });
      d.dec.configure({ codec, optimizeForLatency: true, colorSpace: BT709, ...(this.software ? { hardwareAcceleration: 'prefer-software' } : {}) });
      this.decoders.set(id, d);
    }
    const entry = { op: { op: 'video', id, rects }, transfer: [], ready: false, ts: this.ts };   // (ts: its chunk's)
    this.queue.push(entry);
    if (!d.pending.length) d.last = performance.now();
    d.pending.push(entry);
    try {
      if (nal.length) {
        d.dec.decode(new EncodedVideoChunk({ type: isKey(nal) ? 'key' : 'delta', timestamp: this.ts++, data: fixColour(nal) }));
        this.#watch();
        return;
      }
    } catch (e) {                                     // e.g. a delta frame first: nothing will come out for this entry
      this.emit({ op: 'log', msg: 'H.264: ' + e.message });
      this.#refresh();
    }
    d.pending.splice(d.pending.indexOf(entry), 1);
    entry.ready = true;
    this.#flush();
  }

  /**
   * AVC444 (v1, v2): the luma stream (a 4:2:0 picture of its own) and the chroma stream (the samples it leaves out) each go
   * to a decoder of their own (LC: both, the luma alone or the chroma alone); once a message's pictures are out, the
   * rectangles each stream updated are made in full colour from the latest two (yuv444Rect), in order with every other
   * operation. Before, only the luma picture was shown (4:2:0: coloured text and thin lines blurred). A browser that gives a
   * picture without its planes: the luma picture shown as it is, as before.
   */
  #avc444(id, v2, data) {
    const head = new DataView(data.buffer, data.byteOffset, data.byteLength).getUint32(0, true), lc = head >>> 30, n1 = head & 0x3fffffff;
    if (lc === 3 || (lc === 0 && 4 + n1 > data.length)) throw new Error('GFX: AVC444 stream sizes beyond its data');
    const streams = lc === 0 ? [['luma', data.subarray(4, 4 + n1)], ['chroma', data.subarray(4 + n1)]] : [[lc === 1 ? 'luma' : 'chroma', data.subarray(4)]];
    const job = { v2, need: 0, entries: [], parts: [] };   // parts: [stream, its rectangles, its H.264 data]
    for (const [kind, s] of streams) {
      const dv = new DataView(s.buffer, s.byteOffset, s.byteLength), n = s.length >= 4 ? dv.getUint32(0, true) : -1;
      if (n < 0 || 4 + n * 10 > s.length) throw new Error('GFX: AVC444 metablock beyond its data');
      job.parts.push([kind, this.#rects(dv, 4, n), s.subarray(4 + n * 10)]);
    }
    if (this.stats) {                                // (diagnostic mode: which streams come, and the first ones' NAL unit types)
      this.stats['avc444:LC' + lc] = (this.stats['avc444:LC' + lc] || 0) + 1;
      if ((this.avcNotes = (this.avcNotes || 0) + 1) <= 6) {
        const types = (s) => { const t = []; for (let i = 0; i + 3 < s.length && t.length < 8; i++) if (s[i] === 0 && s[i + 1] === 0 && s[i + 2] === 1) t.push(s[i + 3] & 0x1f); return t.join(' '); };
        this.emit({ op: 'note', msg: `avc444 v${v2 ? 2 : 1} LC ${lc}: ` + streams.map(([k, s]) => `${k} ${s.length} B, NAL ${types(s)}`).join('; ') });
      }
    }
    let st = this.yuv.get(id);
    if (!st) this.yuv.set(id, st = { main: null, aux: null, luma: null, chroma: null, jobs: [], spare: { luma: null, chroma: null } });
    for (const [, rects] of job.parts) {             // (their places among the other operations, kept until the pictures are out)
      for (let i = 0; i < rects.length; i++) {
        const e = { op: { op: 'video', id }, transfer: [], ready: false };   // (a video without a frame draws nothing)
        job.entries.push(e);
        this.queue.push(e);
      }
    }
    st.jobs.push(job);
    for (const [kind, , nal] of job.parts) {
      let d = st[kind];
      if (!d) {
        const codec = avcCodec(nal);
        if (!codec) {                                  // wait for a key frame with SPS (asked for)
          if (this.stats) this.stats[`avc444:${kind} before its SPS`] = (this.stats[`avc444:${kind} before its SPS`] || 0) + 1;
          if (nal.length) this.#refresh();
          continue;
        }
        d = st[kind] = this.#yuvDecoder(id, st, kind, codec);
      }
      if (!nal.length) continue;
      job.need++;
      const p = { job, ts: this.ts };                 // (ts: its chunk's)
      if (!d.pending.length) d.last = performance.now();
      d.pending.push(p);
      try {
        d.dec.decode(new EncodedVideoChunk({ type: isKey(nal) ? 'key' : 'delta', timestamp: this.ts++, data: fixColour(nal) }));
        this.#watch();
      } catch (e) {                                   // e.g. a delta frame first: nothing will come out for it
        this.emit({ op: 'log', msg: 'H.264: ' + e.message });
        const i = d.pending.indexOf(p);
        if (i >= 0) { d.pending.splice(i, 1); job.need--; }
        this.#refresh();
      }
    }
    this.#yuvFlush(id, st);
  }

  #yuvDecoder(id, st, kind, codec) {
    const d = { pending: [], last: 0 };             // pending: {job, ts}; last: as #avc's
    d.dec = new VideoDecoder({
      output: (frame) => {
        d.last = performance.now();
        // (in the order given, as #avc's: the jobs of chunks the decoder dropped go without this stream's picture)
        while (d.pending.length && d.pending[0].ts < frame.timestamp) d.pending.shift().job.need--;
        const job = d.pending[0]?.ts > frame.timestamp ? null : d.pending.shift()?.job;
        if (!job) { frame.close(); this.#yuvFlush(id, st); return; }
        const done = (p) => { job[kind] = p; job.need--; this.#yuvFlush(id, st); };
        if (frame.format === 'I420' || frame.format === 'NV12') {
          const spare = st.spare[kind];                // (the planes this stream's picture before the last one had)
          st.spare[kind] = null;
          framePlanes(frame, spare).then(done);
        }
        else done({ frame });                          // (no planes given: the picture itself)
      },
      error: (e) => {
        this.emit({ op: 'log', msg: 'H.264: ' + e.message });
        for (const { job } of d.pending) job.need--;   // skip the lost pictures, keep the rest in order
        d.pending = [];
        if (st[kind] === d) st[kind] = null;
        this.#yuvFlush(id, st);
        this.#refresh();                               // (the next decoder needs a key frame)
      },
    });
    d.dec.configure({ codec, optimizeForLatency: true, colorSpace: BT709, ...(this.software ? { hardwareAcceleration: 'prefer-software' } : {}) });
    return d;
  }

  /** The AVC444 messages whose pictures are all out, in their order: their rectangles drawn (see #avc444). */
  #yuvFlush(id, st) {
    while (st.jobs.length && st.jobs[0].need === 0) {
      // its places in the order freed whatever happens drawing it: an error there left everything after them waiting for
      // good, the end of frame too (no acknowledgement: the server sent nothing more)
      const job = st.jobs.shift();
      try {
        this.#yuvDraw(id, st, job);
      } catch (e) {                                    // (its rectangles undrawn; the next messages still go)
        this.emit({ op: 'log', msg: 'gfx: ' + e.message });
      } finally {
        for (const e of job.entries) e.ready = true;
      }
    }
    this.#flush();
  }

  #yuvDraw(id, st, job) {
    if (this.stats) {                                  // (diagnostic mode: which way its pictures were drawn)
      const k = job.luma?.frame ? 'avc444:4:2:0 (no planes)' : job.chroma ? 'avc444:full colour' : 'avc444:luma only';
      this.stats[k] = (this.stats[k] || 0) + 1;
    }
    if (job.chroma?.frame) job.chroma.frame.close();
    if (job.luma?.frame) {                             // no planes: the luma picture through its own rectangles (4:2:0)
      const rects = job.parts.find(([k]) => k === 'luma')[1];
      if (job.entries.length) Object.assign(job.entries[0], { op: { op: 'video', id, rects, frame: job.luma.frame }, transfer: [job.luma.frame] });
      else job.luma.frame.close();
      return;
    }
    // (null: its planes could not be read; those it replaces kept for the stream's next picture)
    if (job.luma !== undefined) { st.spare.luma = st.main; st.main = job.luma; }
    if (job.chroma !== undefined && !job.chroma?.frame) { st.spare.chroma = st.aux; st.aux = job.chroma; }
    const m = st.main, [sw, sh] = this.sizes.get(id) || [0, 0];
    const a = st.aux && m && st.aux.w === m.w && st.aux.h === m.h ? st.aux : null;
    // each rectangle within the surface and the picture; a stream's rectangles over more pixels in all than there overlap
    // (a few KB brought 10,000 over a whole 1080p surface, each converted on its own): their bounding box instead, converted
    // once and drawn by the stream's first entry (a message converts twice the surface at most, both streams over all of it)
    const W = Math.min(sw, m?.w ?? 0), H = Math.min(sh, m?.h ?? 0);
    let i = 0;
    for (const [, rects] of job.parts) {
      const areas = [];
      let sum = 0, x0 = W, y0 = H, x1 = 0, y1 = 0;
      for (const r of rects) {
        const w = Math.min(r.r, W) - r.l, h = Math.min(r.b, H) - r.t;
        if (w <= 0 || h <= 0) { areas.push(null); continue; }
        areas.push([r.l, r.t, w, h]);
        sum += w * h;
        x0 = Math.min(x0, r.l); y0 = Math.min(y0, r.t); x1 = Math.max(x1, r.l + w); y1 = Math.max(y1, r.t + h);
      }
      if (sum > W * H) areas.splice(0, areas.length, [x0, y0, x1 - x0, y1 - y0]);
      for (let k = 0; k < areas.length; k++) {
        if (!areas[k]) continue;
        const [x, y, w, h] = areas[k], px = yuv444Rect(m, a, job.v2, x, y, w, h, sw, sh);
        Object.assign(job.entries[i + k], { op: { op: 'px', id, x, y, w, h, px }, transfer: [px.buffer] });
      }
      i += rects.length;
    }
  }

  /** The watchdog of the pictures owed, once a second while there are some (see #unstall). */
  #watch() {
    if (this.watchdog) return;
    this.watched = performance.now();
    this.watchdog = setTimeout(() => this.#unstall(), STALL);
  }

  /**
   * The pictures a decoder owes when it gave none since the watchdog started, STALL ms ago: their operations go out without
   * them, in their order, and a full picture is asked for. Before, an entry waited for its picture for ever, and everything
   * after it (the end of frame too: no acknowledgement, so the server sent nothing more).
   */
  #unstall() {
    this.watchdog = 0;
    const since = this.watched;                       // (not now less STALL: a timer may come a little early)
    let owed = false, lost = false;
    for (const d of this.decoders.values()) {
      if (!d.pending.length) continue;
      if (d.last > since) { owed = true; continue; }
      for (const e of d.pending) e.ready = true;
      d.pending = [];
      lost = true;
    }
    for (const [id, st] of this.yuv) {
      for (const d of [st.luma, st.chroma]) {
        if (!d?.pending.length) continue;
        if (d.last > since) { owed = true; continue; }
        for (const { job } of d.pending) job.need--;
        d.pending = [];
        lost = true;
      }
      this.#yuvFlush(id, st);
    }
    this.#flush();
    if (lost) this.#refresh();
    if (owed) this.#watch();
  }

  /**
   * Asks the page for a full picture from the server (Refresh Rect): a decoder failed or lost pictures, and the next one
   * starts at a key frame, which may not come otherwise. Once in REFRESH_GAP ms at most (a broken stream errs on every frame).
   */
  #refresh() {
    const now = performance.now();
    if (now - this.refreshed < REFRESH_GAP) return;
    this.refreshed = now;
    this.emit({ op: 'refresh' });
  }

  /**
   * The connection is over or starts again (stream-worker.js): every decoder closed, AVC444's too, the pictures held
   * freed, nothing more sent (a late picture of the connection before was drawn on the new one, its frame acknowledged).
   */
  dispose() {
    this.emit = () => {};
    clearTimeout(this.watchdog);
    for (const d of this.decoders.values()) try { d.dec.close(); } catch { /* already closed */ }
    for (const st of this.yuv.values()) {
      for (const d of [st.luma, st.chroma]) try { d?.dec.close(); } catch { /* already closed */ }
      for (const job of st.jobs) { job.luma?.frame?.close(); job.chroma?.frame?.close(); }
      st.jobs = [];
    }
    for (const e of this.queue) if (e.op.op === 'video') e.op.frame?.close();   // (an end of frame's is its number)
    this.decoders.clear();
    this.yuv.clear();
    this.queue = [];
    for (const r of this.idlers.splice(0)) r();
  }
}
