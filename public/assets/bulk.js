// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// RDP bulk decompression in the browser, same state as the gateway: MPPC with a 64 KB history (MS-RDPBCGR 3.1.8.4.2),
// NCRUSH (RDP 6.0, MS-RDPEGDI 3.1.8.1) and XCRUSH (RDP 6.1, MS-RDPEGDI 3.1.8.2).
'use strict';

const COMPRESSED = 0x20, AT_FRONT = 0x40, FLUSHED = 0x80;
const MAX_UPDATE = 1 << 24;                         // the MaxRequestSize the gateway announces (multifragment capability)

/** The 32 bits of src from bit on, most significant first, zero padded past the end. */
function top(src, bit) {
  const o = bit >> 3, r = bit & 7;
  if (o + 4 < src.length) {
    const w = (src[o] << 24 | src[o + 1] << 16 | src[o + 2] << 8 | src[o + 3]) >>> 0;
    return r ? ((w << r) | (src[o + 4] >> (8 - r))) >>> 0 : w;
  }
  const w = ((src[o] ?? 0) << 24 | (src[o + 1] ?? 0) << 16 | (src[o + 2] ?? 0) << 8 | (src[o + 3] ?? 0)) >>> 0;
  return r ? ((w << r) | ((src[o + 4] ?? 0) >> (8 - r))) >>> 0 : w;
}

export class Mppc {
  constructor() {
    this.hist = new Uint8Array(65536);
    this.pos = 0;
  }

  /** Decompresses one block (flags: the compression byte sent with it). */
  decompress(src, flags) {
    const r = this.inPlace(src, flags);
    return r === src ? src : r.slice();
  }

  /** decompress(), but a view of the history (valid until the next block) instead of a copy. */
  inPlace(src, flags) {
    if (flags & (AT_FRONT | FLUSHED)) {
      this.pos = 0;
      if (flags & FLUSHED) this.hist.fill(0);
    }
    if (!(flags & COMPRESSED)) return src;
    const h = this.hist, start = this.pos, n = src.length, total = n * 8;
    let pos = start, bit = 0;
    while (total - bit >= 8) {
      let t = top(src, bit);                       // (local state and a top-level reader: no closure in the hot loop)
      if (!(t & 0x80000000)) { h[pos++] = t >>> 24; bit += 8; continue; }
      if ((t & 0xc0000000) >>> 0 === 0x80000000) { h[pos++] = ((t >>> 23) & 0x7f) | 0x80; bit += 9; continue; }
      let off;
      if ((t & 0xf8000000) >>> 0 === 0xf8000000) { off = (t >>> 21) & 0x3f; bit += 11; }
      else if ((t & 0xf8000000) >>> 0 === 0xf0000000) { off = ((t >>> 19) & 0xff) + 64; bit += 13; }
      else if ((t & 0xf0000000) >>> 0 === 0xe0000000) { off = ((t >>> 17) & 0x7ff) + 320; bit += 15; }
      else if ((t & 0xe0000000) >>> 0 === 0xc0000000) { off = ((t >>> 13) & 0xffff) + 2368; bit += 19; }
      else throw new Error('MPPC: bad copy offset');
      t = top(src, bit);
      const k = Math.clz32(~t);                    // length: k ones, a zero, k + 1 value bits
      let len;
      if (k === 0) { len = 3; bit += 1; }
      else {
        if (k > 14) throw new Error('MPPC: bad length');
        const used = 2 * k + 2;
        len = ((t >>> (32 - used)) & ((1 << (k + 1)) - 1)) + (1 << (k + 1));
        bit += used;
      }
      if (pos + len > 65536) throw new Error('MPPC: history overflow');
      let from = (pos - off) & 0xffff;
      if (from + len <= pos) { h.copyWithin(pos, from, from + len); pos += len; }
      else for (let i = 0; i < len; i++, from = (from + 1) & 0xffff) h[pos++] = h[from];
    }
    if (pos > 65536) throw new Error('MPPC: history overflow');   // literals past the end were lost (checked once, not per byte)
    this.pos = pos;
    return h.subarray(start, pos);
  }
}

const LEC_LEN = [
  6, 6, 6, 7, 7, 7, 7, 7, 7, 7, 7, 8, 8, 8, 8, 8, 8, 8, 9, 8, 9, 9, 9, 9,
  8, 8, 9, 9, 9, 9, 9, 9, 8, 9, 9, 10, 9, 9, 9, 9, 9, 9, 9, 10, 9, 10, 10, 10,
  9, 9, 10, 9, 10, 9, 10, 9, 9, 9, 10, 10, 9, 10, 9, 9, 8, 9, 9, 9, 9, 10, 10, 10,
  9, 9, 10, 10, 10, 10, 10, 10, 9, 9, 10, 10, 10, 10, 10, 10, 10, 9, 10, 10, 10, 10, 10, 10,
  8, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 9, 10, 10, 10, 10, 10, 10, 10,
  9, 10, 10, 10, 10, 10, 10, 9, 7, 9, 9, 10, 9, 10, 10, 10, 9, 10, 10, 10, 10, 10, 10, 10,
  9, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10,
  10, 10, 10, 13, 10, 10, 10, 10, 10, 10, 11, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10,
  9, 10, 10, 10, 10, 10, 9, 10, 10, 10, 10, 10, 9, 10, 10, 10, 9, 10, 10, 10, 10, 10, 10, 10,
  10, 10, 10, 10, 10, 10, 10, 10, 9, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 9, 10,
  8, 9, 9, 10, 9, 10, 10, 10, 9, 10, 10, 10, 9, 9, 8, 7, 13, 13, 7, 7, 10, 7, 7, 6,
  6, 6, 6, 5, 6, 6, 6, 5, 6, 5, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6,
  8, 5, 6, 7, 7, 13,
];
const LEC_CODE = [
  4, 36, 20, 17, 81, 49, 113, 9, 73, 41, 105, 21, 149, 85, 213, 53, 181, 117, 29, 245, 285, 157, 413, 93,
  13, 141, 349, 221, 477, 61, 317, 189, 77, 445, 125, 107, 381, 253, 509, 3, 259, 131, 387, 619, 67, 363, 875, 235,
  323, 195, 747, 451, 491, 35, 1003, 291, 163, 419, 27, 539, 99, 283, 355, 227, 205, 483, 19, 275, 147, 795, 155, 667,
  403, 83, 411, 923, 91, 603, 347, 859, 339, 211, 219, 731, 475, 987, 59, 571, 315, 467, 827, 187, 699, 443, 955, 123,
  45, 635, 379, 891, 251, 763, 507, 1019, 7, 519, 263, 775, 135, 647, 391, 903, 51, 71, 583, 327, 839, 199, 711, 455,
  307, 967, 39, 551, 295, 807, 167, 179, 25, 435, 115, 679, 371, 423, 935, 103, 243, 615, 359, 871, 231, 743, 487, 999,
  499, 23, 535, 279, 791, 151, 663, 407, 919, 87, 599, 343, 855, 215, 727, 471, 983, 55, 567, 311, 823, 183, 695, 439,
  951, 119, 631, 2047, 375, 887, 247, 759, 503, 1015, 1023, 15, 527, 271, 783, 143, 655, 399, 911, 79, 591, 335, 847, 207,
  11, 719, 463, 975, 47, 559, 267, 303, 815, 175, 687, 431, 139, 943, 111, 623, 395, 367, 879, 239, 751, 495, 1007, 31,
  543, 287, 799, 159, 671, 415, 927, 95, 75, 607, 351, 863, 223, 735, 479, 991, 63, 575, 319, 831, 191, 703, 331, 447,
  173, 203, 459, 959, 43, 127, 639, 383, 299, 895, 255, 767, 171, 427, 109, 89, 6143, 4095, 57, 121, 511, 5, 69, 52,
  12, 44, 28, 0, 60, 2, 34, 16, 18, 8, 50, 10, 42, 26, 58, 6, 38, 22, 54, 14, 46, 30, 62, 1,
  237, 24, 33, 37, 101, 8191,
];
const LOM_LEN = [
  4, 2, 3, 4, 3, 4, 4, 5, 4, 5, 5, 6, 6, 7, 7, 8, 7, 8, 8, 9, 9, 8, 9, 9,
  9, 9, 9, 9, 9, 9, 9, 9,
];
const LOM_CODE = [
  1, 0, 2, 9, 6, 5, 13, 11, 3, 27, 7, 23, 55, 15, 79, 111, 47, 239, 31, 95, 351, 159, 223, 479,
  63, 319, 191, 447, 127, 383, 255, 511,
];
const COPY_BITS = [
  0, 0, 0, 0, 1, 1, 2, 2, 3, 3, 4, 4, 5, 5, 6, 6, 7, 7, 8, 8, 9, 9, 10, 10,
  11, 11, 12, 12, 13, 13, 14, 14,
];
const COPY_BASE = [
  1, 2, 3, 4, 5, 7, 9, 13, 17, 25, 33, 49, 65, 97, 129, 193, 257, 385, 513, 769, 1025, 1537, 2049, 3073,
  4097, 6145, 8193, 12289, 16385, 24577, 32769, 49153,
];
const LOM_BITS = [
  0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 1, 1, 2, 2, 2, 2, 3, 3, 3, 3, 4, 4, 4, 4,
  6, 6, 8, 8, 14, 14,
];
const LOM_BASE = [
  2, 3, 4, 5, 6, 7, 8, 9, 10, 12, 14, 16, 18, 22, 26, 30, 34, 42, 50, 58, 66, 82, 98, 114,
  130, 194, 258, 514, 2, 2,
];

/** Decoding table: index = next `bits` bits (least significant first), value = code length << 12 | symbol (0 = invalid). */
function huffTable(lengths, codes, bits) {
  const t = new Uint16Array(1 << bits);
  lengths.forEach((len, sym) => { for (let hi = 0; hi < 1 << (bits - len); hi++) t[codes[sym] | (hi << len)] = (len << 12) | sym; });
  return t;
}
let LEC = null, LOM = null;

export class Ncrush {
  constructor() {
    LEC ||= huffTable(LEC_LEN, LEC_CODE, 13);
    LOM ||= huffTable(LOM_LEN, LOM_CODE, 9);
    this.hist = new Uint8Array(65536);
    this.pos = 0;
    this.cache = [0, 0, 0, 0];
  }

  decompress(src, flags) {
    const h = this.hist;
    if (flags & AT_FRONT) {                        // keep the last 32 KB at the front
      if (this.pos <= 32768) throw new Error('NCRUSH: bad history move');
      h.copyWithin(0, this.pos - 32768, this.pos);
      h.fill(0, 32768);
      this.pos = 32768;
    }
    if (flags & FLUSHED) { h.fill(0); this.pos = 0; this.cache = [0, 0, 0, 0]; }
    if (!(flags & COMPRESSED)) return src;
    const n = src.length, cache = this.cache, start = this.pos;
    if (n < 4) throw new Error('NCRUSH: short block');
    let bits = (src[0] | src[1] << 8 | src[2] << 16 | src[3] << 24) >>> 0, nbits = 32, sp = 4, pos = start, k = 0;
    // each "take" below drops k bits, then refills to at least 16 (written out: a closure kept the reader out of registers)
    for (;;) {
      let e = LEC[bits & 0x1fff];
      if (!e) throw new Error('NCRUSH: bad code');
      k = e >> 12;
      bits >>>= k; nbits -= k;                      // take
      if (nbits < 16) {
        if (sp + 1 < n) { bits = (bits | ((src[sp] | src[sp + 1] << 8) << nbits)) >>> 0; nbits += 16; sp += 2; }
        else if (sp < n) { bits = (bits | (src[sp++] << nbits)) >>> 0; nbits += 8; }
        else if (nbits < 0) throw new Error('NCRUSH: truncated block');
      }
      const sym = e & 0xfff;
      if (sym < 256) { h[pos++] = sym; continue; }
      if (sym === 256) break;                       // end of stream
      let off;
      if (sym < 289) {                              // copy offset class: base + extra bits
        const c = sym - 257, b = COPY_BITS[c];
        off = COPY_BASE[c] - 1 + (b ? bits & ((1 << b) - 1) : 0);
        if (b) {
          bits >>>= b; nbits -= b;                  // take
          if (nbits < 16) {
            if (sp + 1 < n) { bits = (bits | ((src[sp] | src[sp + 1] << 8) << nbits)) >>> 0; nbits += 16; sp += 2; }
            else if (sp < n) { bits = (bits | (src[sp++] << nbits)) >>> 0; nbits += 8; }
            else if (nbits < 0) throw new Error('NCRUSH: truncated block');
          }
        }
        cache[3] = cache[2]; cache[2] = cache[1]; cache[1] = cache[0]; cache[0] = off;
      } else if (sym < 293) {                       // offset cache entry, moved to the front
        const c = sym - 289;
        off = cache[c];
        cache[c] = cache[0];
        cache[0] = off;
      } else throw new Error('NCRUSH: bad symbol');
      e = LOM[bits & 0x1ff];
      if (!e || (e & 0xfff) >= 30) throw new Error('NCRUSH: bad length');
      k = e >> 12;
      bits >>>= k; nbits -= k;                      // take
      if (nbits < 16) {
        if (sp + 1 < n) { bits = (bits | ((src[sp] | src[sp + 1] << 8) << nbits)) >>> 0; nbits += 16; sp += 2; }
        else if (sp < n) { bits = (bits | (src[sp++] << nbits)) >>> 0; nbits += 8; }
        else if (nbits < 0) throw new Error('NCRUSH: truncated block');
      }
      const b = LOM_BITS[e & 0xfff];
      const len = LOM_BASE[e & 0xfff] + (b ? bits & ((1 << b) - 1) : 0);
      if (b) {
        bits >>>= b; nbits -= b;                    // take
        if (nbits < 16) {
          if (sp + 1 < n) { bits = (bits | ((src[sp] | src[sp + 1] << 8) << nbits)) >>> 0; nbits += 16; sp += 2; }
          else if (sp < n) { bits = (bits | (src[sp++] << nbits)) >>> 0; nbits += 8; }
          else if (nbits < 0) throw new Error('NCRUSH: truncated block');
        }
      }
      if (len < 2 || pos + len >= 65535) throw new Error('NCRUSH: history overflow');
      let from = (pos - off) & 0xffff;
      if (off >= len && from + len <= pos) { h.copyWithin(pos, from, from + len); pos += len; }
      else for (let i = 0; i < len; i++, from = (from + 1) & 0xffff) h[pos++] = h[from];
    }
    if (pos > 65536) throw new Error('NCRUSH: history overflow');   // literals past the end were lost (checked once, not per byte)
    this.pos = pos;
    return h.slice(start, pos);
  }
}

export class Xcrush {
  constructor() {
    this.l2 = new Mppc();
    this.hist = new Uint8Array(2000000);
    this.pos = 0;
  }

  decompress(src, flags) {
    if (src.length < 2) throw new Error('XCRUSH: short block');
    const l1 = src[0], l2 = src[1], h = this.hist;
    if (flags & FLUSHED) { h.fill(0); this.pos = 0; }
    let d = src.subarray(2);
    if (l2 & COMPRESSED) d = this.l2.inPlace(d, l2);    // (copied into this history below, before the next block)
    if (l1 & 0x04) this.pos = 0;                   // level 1 at front: writing starts again at offset 0
    const n = d.length, start = this.pos;
    let pos = start, lit = 0;
    if (!(l1 & 0x02)) {                            // not "no compression": match list, then literals
      if (!(l1 & 0x01) || n < 2) throw new Error('XCRUSH: bad level 1 flags');
      const dv = new DataView(d.buffer, d.byteOffset, n), count = dv.getUint16(0, true);
      lit = 2 + 8 * count;
      if (lit > n) throw new Error('XCRUSH: bad match count');
      let out = 0;
      for (let i = 0; i < count; i++) {
        const len = dv.getUint16(2 + 8 * i, true), at = dv.getUint16(4 + 8 * i, true), from = dv.getUint32(6 + 8 * i, true);
        if (at < out || at - out > n - lit) throw new Error('XCRUSH: bad match');
        h.set(d.subarray(lit, lit + at - out), pos);
        pos += at - out;
        lit += at - out;
        if (pos + len >= 2000000 || from + len >= 2000000) throw new Error('XCRUSH: history overflow');
        if (from < pos && from + len > pos) for (let k = 0; k < len; k++) h[pos + k] = h[from + k];   // overlapping: byte by byte
        else h.copyWithin(pos, from, from + len);
        pos += len;
        out = at + len;
      }
    }
    if (pos + n - lit >= 2000000) throw new Error('XCRUSH: history overflow');
    h.set(d.subarray(lit), pos);
    pos += n - lit;
    this.pos = pos;
    return h.slice(start, pos);
  }
}

/** Decompressor chosen by the type in the compression flags (each one keeps its own history, created when first used). */
export class Bulk {
  decompress(src, flags) {
    const t = flags & 0x0f;
    if (!(flags & COMPRESSED) && !t) return src;   // not compressed and no history to update
    const d = t === 1 ? (this.mppc ||= new Mppc()) : t === 2 ? (this.ncrush ||= new Ncrush()) : t === 3 ? (this.xcrush ||= new Xcrush()) : null;
    if (!d) throw new Error('bulk compression type ' + t + ' not supported');
    return d.decompress(src, flags);
  }
}

/** Frames 10 and 11 of the gateway when bulk compression is on: shared history, fast-path reassembly, slow-path updates. */
export class CompressedStream {
  constructor() {
    this.bulk = new Bulk();
    this.frag = [];
    this.size = 0;
  }

  /** Frame 10 (u8 code, u8 fragmentation, u8 flags, data): the complete update (u8 code + data), or null while incomplete. */
  update(d) {
    const data = d[2] & 0xe0 ? this.bulk.decompress(d.subarray(3), d[2]) : d.subarray(3);
    if (d[1] === 2 || d[1] === 3) {                                 // first or next fragment
      if (d[1] === 2) { this.frag = []; this.size = 0; }
      this.frag.push(data);
      if ((this.size += data.length) > MAX_UPDATE) throw new Error('fast-path update too large');
      return null;
    }
    const parts = d[1] === 1 ? [...this.frag, data] : [data];       // last fragment, or a single one
    this.frag = [];
    const u = new Uint8Array(1 + parts.reduce((n, x) => n + x.length, 0));
    u[0] = d[0];
    let o = 1;
    for (const x of parts) { u.set(x, o); o += x.length; }
    return u;
  }

  /**
   * Frame 11 (u8 flags, u8 pduType2, data): compressed slow-path data PDU. Returns update and pointer PDUs in the
   * fast-path form (u8 code + data, as the gateway sends them uncompressed), or null.
   */
  slow(d) {
    const u = this.bulk.decompress(d.subarray(2), d[0]);
    const kind = u[0] | (u[1] << 8);
    const make = (code, head, body) => {                         // code + head bytes + body
      const o = new Uint8Array(1 + head.length + body.length);
      o[0] = code;
      o.set(head, 1);
      o.set(body, 1 + head.length);
      return o;
    };
    if (d[1] === 0x02) {                                         // update: orders, bitmap or palette
      if (kind === 0) return make(0, u.subarray(4, 6), u.subarray(8));
      return kind === 1 || kind === 2 ? make(kind, [], u) : null;
    }
    if (d[1] === 0x1b) {                                         // pointer
      if (kind === 1) return Uint8Array.of((u[4] | u[5] | u[6] | u[7]) === 0 ? 5 : 6);
      const code = { 3: 8, 6: 9, 7: 10, 8: 11, 9: 12 }[kind];
      return code ? make(code, [], u.subarray(4)) : null;
    }
    return null;
  }
}
