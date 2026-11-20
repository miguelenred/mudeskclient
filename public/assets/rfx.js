// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// RemoteFX codecs for the graphics pipeline: RemoteFX (MS-RDPRFX) and RemoteFX progressive (MS-RDPEGFX):
// RLGR entropy, dequantization, inverse DWT (plain and reduce-extrapolate), upgrade passes and YCbCr -> RGB.
'use strict';

const KPMAX = 80, LSGR = 3, UP_GR = 4, DN_GR = 6, UQ_GR = 3, DQ_GR = 3;

/** n bits (0..32) at bit of src, most significant first; zeros past the end. */
function rd(src, bit, n) {
  const o = bit >> 3;
  if (n <= 25 && o + 3 < src.length) return n ? (((src[o] << 24) | (src[o + 1] << 16) | (src[o + 2] << 8) | src[o + 3]) << (bit & 7)) >>> (32 - n) : 0;
  let v = 0;
  for (let i = 0, t = src.length * 8; i < n; i++, bit++) v = v * 2 + (bit < t ? (src[bit >> 3] >> (7 - (bit & 7))) & 1 : 0);
  return v;
}

/** RLGR1 (mode 1) or RLGR3 (mode 3) decoding of one component into out (Int16Array, zero-filled beforehand). */
export function rlgrDecode(src, mode, out) {
  const total = src.length * 8, n = out.length;
  let p = 0, bit = 0, k = 1, kp = 8, kr = 1, krp = 8;
  while (bit < total && p < n) {
    if (k) {                                          // run-length mode
      let run = 0;
      while (bit < total && !((src[bit >> 3] >> (7 - (bit & 7))) & 1)) {
        bit++;
        run += 1 << k;
        kp = Math.min(KPMAX, kp + UP_GR);
        k = kp >> LSGR;
      }
      if (bit >= total) { p += run; break; }
      bit++;                                          // the 1 that ends the zeros
      if (bit >= total) { p += run; break; }
      run += rd(src, bit, k); bit += k;
      const sign = rd(src, bit, 1); bit++;
      let vk = 0;                                     // GR code
      while (bit < total && (src[bit >> 3] >> (7 - (bit & 7))) & 1) { bit++; vk++; }
      if (bit < total) bit++;
      let mag = vk * (1 << kr);
      if (kr) { mag += rd(src, bit, kr); bit += kr; }
      if (vk === 0) krp = Math.max(0, krp - 2);
      else if (vk !== 1) krp = Math.min(KPMAX, krp + vk);
      kr = krp >> LSGR;
      mag++;
      p += run;
      if (p < n) out[p++] = sign ? -mag : mag;
      kp = Math.max(0, kp - DN_GR);
      k = kp >> LSGR;
    } else {
      let vk = 0;                                     // GR code
      while (bit < total && (src[bit >> 3] >> (7 - (bit & 7))) & 1) { bit++; vk++; }
      if (bit < total) bit++;
      let mag = vk * (1 << kr);
      if (kr) { mag += rd(src, bit, kr); bit += kr; }
      if (vk === 0) krp = Math.max(0, krp - 2);
      else if (vk !== 1) krp = Math.min(KPMAX, krp + vk);
      kr = krp >> LSGR;
      if (mode === 1) {                               // Golomb-Rice mode, RLGR1
        out[p++] = mag & 1 ? -((mag + 1) >> 1) : mag >> 1;
        kp = mag ? Math.max(0, kp - DQ_GR) : Math.min(KPMAX, kp + UQ_GR);
        k = kp >> LSGR;
      } else {                                        // RLGR3: two values per code
        const nIdx = mag ? 32 - Math.clz32(mag) : 0;
        const v1 = nIdx > 25 ? rd(src, bit, nIdx - 16) * 65536 + rd(src, bit + nIdx - 16, 16) : rd(src, bit, nIdx), v2 = mag - v1;
        bit += nIdx;
        if (v1 && v2) kp = Math.max(0, kp - 2 * DQ_GR);
        else if (!v1 && !v2) kp = Math.min(KPMAX, kp + 2 * UQ_GR);
        k = kp >> LSGR;
        out[p++] = v1 & 1 ? -((v1 + 1) >> 1) : v1 >> 1;
        if (p < n) out[p++] = v2 & 1 ? -((v2 + 1) >> 1) : v2 >> 1;
      }
    }
  }
  return out;
}

// band layout of a 64x64 tile: HL1 LH1 HH1 (32x32), HL2 LH2 HH2 (16x16), HL3 LH3 HH3 LL3 (8x8)
// quant values (stream order): LL3 LH3 HL3 HH3 LH2 HL2 HH2 LH1 HL1 HH1
const BANDS = [[0, 1024, 8], [1024, 1024, 7], [2048, 1024, 9], [3072, 256, 5], [3328, 256, 4], [3584, 256, 6],
  [3840, 64, 2], [3904, 64, 1], [3968, 64, 3], [4032, 64, 0]];

export function dequantize(buf, q) {
  for (const [off, len, i] of BANDS) {
    const s = q[i] - 1;
    if (s > 0) for (let j = off; j < off + len; j++) buf[j] <<= s;
  }
}

const tmp = new Int16Array(4096);

/** One level of the inverse 2D DWT (5/3 lifting) on the sub-bands at buf[off]: HL, LH, HH, LL of w x w each. */
function idwtLevel(buf, off, w) {
  const tw = w * 2;
  const hl = off, lh = off + w * w, hh = off + 2 * w * w, ll = off + 3 * w * w;
  for (let y = 0; y < w; y++) {                     // horizontal: L rows (LL, HL) and H rows (LH, HH)
    const lo = y * tw, ho = (w + y) * tw, r = y * w;
    tmp[lo] = buf[ll + r] - ((buf[hl + r] * 2 + 1) >> 1);
    tmp[ho] = buf[lh + r] - ((buf[hh + r] * 2 + 1) >> 1);
    for (let n = 1; n < w; n++) {
      tmp[lo + 2 * n] = buf[ll + r + n] - ((buf[hl + r + n - 1] + buf[hl + r + n] + 1) >> 1);
      tmp[ho + 2 * n] = buf[lh + r + n] - ((buf[hh + r + n - 1] + buf[hh + r + n] + 1) >> 1);
    }
    for (let n = 0; n < w - 1; n++) {
      tmp[lo + 2 * n + 1] = (buf[hl + r + n] << 1) + ((tmp[lo + 2 * n] + tmp[lo + 2 * n + 2]) >> 1);
      tmp[ho + 2 * n + 1] = (buf[hh + r + n] << 1) + ((tmp[ho + 2 * n] + tmp[ho + 2 * n + 2]) >> 1);
    }
    tmp[lo + tw - 1] = (buf[hl + r + w - 1] << 1) + tmp[lo + tw - 2];
    tmp[ho + tw - 1] = (buf[hh + r + w - 1] << 1) + tmp[ho + tw - 2];
  }
  for (let x = 0; x < tw; x++) {                    // vertical, back into buf: L rows at tmp[n * tw + x], H rows w rows below
    const o = off + x, hb = w * tw + x;
    buf[o] = tmp[x] - ((tmp[hb] * 2 + 1) >> 1);
    for (let n = 1; n < w; n++) {
      const h0 = tmp[hb + (n - 1) * tw];
      buf[o + 2 * n * tw] = tmp[n * tw + x] - ((h0 + tmp[hb + n * tw] + 1) >> 1);
      buf[o + (2 * n - 1) * tw] = (h0 << 1) + ((buf[o + (2 * n - 2) * tw] + buf[o + 2 * n * tw]) >> 1);
    }
    buf[o + (2 * w - 1) * tw] = (tmp[hb + (w - 1) * tw] << 1) + buf[o + (2 * w - 2) * tw];
  }
}

export function idwt(buf) {
  idwtLevel(buf, 3840, 8);
  idwtLevel(buf, 3072, 16);
  idwtLevel(buf, 0, 32);
}

const clip = (v) => (v < 0 ? 0 : v > 255 ? 255 : v);

/** YCbCr planes (11.5 fixed point, Y centred on 0) -> w x h RGBA words from (sx, sy) of the tile, written into out at (ox, oy) with stride ow. */
export function ycbcrToRgba(y, cb, cr, out, ow, ox, oy, w = 64, h = 64, sx = 0, sy = 0) {
  for (let j = 0; j < h; j++) {
    let s = (sy + j) * 64 + sx, o = (oy + j) * ow + ox;
    for (const e = s + w; s < e; s++, o++) {
      const Y = (y[s] + 4096) << 16, Cb = cb[s], Cr = cr[s];
      let R = (Math.imul(Cr, 91916) + Y) >> 21, G = (Y - Math.imul(Cb, 22527) - Math.imul(Cr, 46819)) >> 21, B = (Math.imul(Cb, 115992) + Y) >> 21;
      R = R < 0 ? 0 : R > 255 ? 255 : R;
      G = G < 0 ? 0 : G > 255 ? 255 : G;
      B = B < 0 ? 0 : B > 255 ? 255 : B;
      out[o] = 0xff000000 | (B << 16) | (G << 8) | R;
    }
  }
}

const quantsOf = (s, p, n) => {
  const q = [];
  for (let i = 0; i < n; i++) {
    const v = [];
    for (let b = 0; b < 5; b++) v.push(s[p + i * 5 + b] & 15, s[p + i * 5 + b] >> 4);
    q.push(v);
  }
  return q;
};

/** RemoteFX messages (codec id 3 of the graphics pipeline). */
export class RfxDecoder {
  constructor() {
    this.mode = 1;
    this.planes = [new Int16Array(4096), new Int16Array(4096), new Int16Array(4096)];
  }

  /** Decodes one message for a w x h destination: RGBA words, alpha 0 outside the region. */
  decode(s, w, h) {
    const dv = new DataView(s.buffer, s.byteOffset, s.byteLength);
    const out = new Uint32Array(w * h);
    let rects = [{ x: 0, y: 0, w, h }];
    let p = 0;
    while (p + 6 <= s.length) {
      const type = dv.getUint16(p, true), len = dv.getUint32(p + 2, true);
      if (len < 6 || p + len > s.length) break;
      const b = p + 6 + (type >= 0xccc3 && type <= 0xccc7 ? 2 : 0);   // codec channel blocks: codecId, channelId
      if (type === 0xccc3) this.mode = ((dv.getUint16(b + 3, true) >> 9) & 15) === 4 ? 3 : 1;
      else if (type === 0xccc6) {
        const n = dv.getUint16(b + 1, true);
        if (n) {
          rects = [];
          for (let i = 0; i < n; i++) {
            const o = b + 3 + i * 8;
            rects.push({ x: dv.getUint16(o, true), y: dv.getUint16(o + 2, true), w: dv.getUint16(o + 4, true), h: dv.getUint16(o + 6, true) });
          }
        }
      } else if (type === 0xccc7) {
        const nq = s[b + 6], nt = dv.getUint16(b + 8, true);
        const quants = quantsOf(s, b + 14, nq);
        let t = b + 14 + nq * 5;
        for (let i = 0; i < nt; i++) {
          const tl = dv.getUint32(t + 2, true);
          if (tl < 19) break;                            // shorter than its header: corrupt (and it would be decoded again and again)
          const q = [s[t + 6], s[t + 7], s[t + 8]], tx = dv.getUint16(t + 9, true) * 64, ty = dv.getUint16(t + 11, true) * 64;
          if (q[0] >= nq || q[1] >= nq || q[2] >= nq) throw new Error('RemoteFX: bad quantizer index');
          let d = t + 19;
          for (let c = 0; c < 3; c++) {
            const n = dv.getUint16(t + 13 + c * 2, true);
            const pl = this.planes[c].fill(0);
            rlgrDecode(s.subarray(d, d + n), this.mode, pl);
            for (let j = 4033; j < 4096; j++) pl[j] += pl[j - 1];          // LL3 is differentially coded
            dequantize(pl, quants[q[c]]);
            idwt(pl);
            d += n;
          }
          for (const r of rects) {                       // the tile where it intersects the region
            const x0 = Math.max(tx, r.x), y0 = Math.max(ty, r.y), x1 = Math.min(tx + 64, r.x + r.w, w), y1 = Math.min(ty + 64, r.y + r.h, h);
            if (x1 > x0 && y1 > y0) ycbcrToRgba(this.planes[0], this.planes[1], this.planes[2], out, w, x0, y0, x1 - x0, y1 - y0, x0 - tx, y0 - ty);
          }
          t += tl;
        }
      }
      p += len;
    }
    return out;
  }
}

// ------------------------------------------------------------------ RemoteFX progressive (codec id 9)

const clamp16 = (v) => (v < -32768 ? -32768 : v > 32767 ? 32767 : v);
const half = (v) => (v + (v >>> 31)) >> 1;          // v / 2 rounded toward zero, in integers
// quant nibbles in stream order (LL3 HL3 LH3 HH3 HL2 LH2 HH2 HL1 LH1 HH1): [offset, length] of the band each one scales
const PLAIN = [[4032, 64], [3840, 64], [3904, 64], [3968, 64], [3072, 256], [3328, 256], [3584, 256], [0, 1024], [1024, 1024], [2048, 1024]];
const EXTRA = [[4015, 81], [3807, 72], [3879, 72], [3951, 64], [3007, 272], [3279, 272], [3551, 256], [0, 1023], [1023, 1023], [2046, 961]];
const UPGRADE_ORDER = [7, 8, 9, 4, 5, 6, 1, 2, 3, 0];   // HL1 LH1 HH1 HL2 LH2 HH2 HL3 LH3 HH3 LL3

const quantRead = (s, p) => {
  const q = new Array(10);
  for (let i = 0; i < 10; i++) q[i] = i & 1 ? s[p + (i >> 1)] >> 4 : s[p + (i >> 1)] & 15;
  return q;
};
const qOp = (a, b, f) => { const r = new Array(10); for (let i = 0; i < 10; i++) r[i] = f(a[i], b[i]); return r; };
const ZERO = new Array(10).fill(0);
const add = (a, x) => a + x, sub = (a, x) => a - x, dec = (a) => a - 1;
/** The progressive quantizers of a tile pass (quality 0xff: none); its indexes must be within the lists sent. */
const progQuant = (progs, quality, q) => {
  const prog = quality === 0xff ? [ZERO, ZERO, ZERO] : progs[quality];
  if (!prog || !q[0] || !q[1] || !q[2]) throw new Error('RemoteFX progressive: bad quantizer index');
  return prog;
};

/**
 * One line of the reduce-extrapolate inverse DWT: nL low (L from lo, stride ls) and nH high (H from ho, stride hs)
 * coefficients into O from oo with stride os. Direct indexing: it is the hot loop of the progressive codec.
 */
function idwtLine(L, lo, ls, H, ho, hs, O, oo, os, nL, nH) {
  const l0 = lo, os2 = 2 * os;
  let H0 = H[ho], X0 = clamp16(L[lo] - H0), X2 = X0;
  for (let j = 1; j < nH; j++) {
    lo += ls;
    ho += hs;
    const H1 = H[ho];
    X2 = clamp16(L[lo] - half(H0 + H1));
    O[oo] = X0;
    O[oo + os] = clamp16(half(X0 + X2) + 2 * H0);
    oo += os2;
    X0 = X2;
    H0 = H1;
  }
  O[oo] = X2;
  if (nL <= nH) O[oo + os] = clamp16(X2 + 2 * H0);
  else {
    X0 = clamp16(L[l0 + nH * ls] - (nL === nH + 1 ? H0 : half(H0)));
    O[oo + os] = clamp16(half(X0 + X2) + 2 * H0);
    O[oo + os2] = X0;
    if (nL > nH + 1) O[oo + 3 * os] = clamp16(half(X0 + L[l0 + (nH + 1) * ls]));
  }
}

const ptmp = new Int16Array(4096);

function idwtExtrapolateLevel(buf, off, level) {
  const nL = (64 >> level) + 1, nH = level === 1 ? 31 : (64 + (1 << (level - 1))) >> level, W = nL + nH;
  const HL = off, LH = HL + nH * nL, HH = LH + nL * nH, LL = HH + nH * nH;
  for (let r = 0; r < nL; r++) idwtLine(buf, LL + r * nL, 1, buf, HL + r * nH, 1, ptmp, r * W, 1, nL, nH);
  for (let r = 0; r < nH; r++) idwtLine(buf, LH + r * nL, 1, buf, HH + r * nH, 1, ptmp, (nL + r) * W, 1, nL, nH);
  for (let c = 0; c < W; c++) idwtLine(ptmp, c, W, ptmp, nL * W + c, W, buf, off + c, W, nL, nH);
}

function idwtExtrapolate(buf) {
  idwtExtrapolateLevel(buf, 3807, 3);
  idwtExtrapolateLevel(buf, 3007, 2);
  idwtExtrapolateLevel(buf, 0, 1);
}

/** SRL (sign/run-length) reader of upgrade passes. */
class Srl {
  constructor(src) { this.src = src; this.end = src.length * 8; this.bit = 0; this.kp = 8; this.nz = 0; this.mode = 0; }
  get(n) {
    let v = 0;
    for (let i = 0; i < n; i++, this.bit++) v = v * 2 + (this.bit < this.src.length * 8 ? (this.src[this.bit >> 3] >> (7 - (this.bit & 7))) & 1 : 0);
    return v;
  }
  read(numBits) {
    if (this.nz) { this.nz--; return 0; }
    const k = this.kp >> 3;
    if (!this.mode) {
      if (!this.get(1)) {
        this.nz = (1 << k) - 1;
        this.kp = Math.min(80, this.kp + 4);
        return 0;
      }
      this.mode = 1;
      this.nz = k ? this.get(k) : 0;
      if (this.nz) { this.nz--; return 0; }
    }
    this.mode = 0;
    const sign = this.get(1);
    this.kp = Math.max(0, this.kp - 6);
    if (numBits === 1) return sign ? -1 : 1;
    let mag = 1;
    const max = (1 << numBits) - 1;
    while (mag < max && this.bit < this.end && !this.get(1)) mag++;   // max goes up to 2^30 - 1: stop with the data
    return sign ? -mag : mag;
  }
}

export class ProgressiveDecoder {
  constructor() {
    this.surfaces = new Map();                         // surfaceId -> Map(tileKey -> tile state)
    this.buf = [new Int16Array(4096), new Int16Array(4096), new Int16Array(4096)];
  }

  deleteSurface(id) { this.surfaces.delete(id); }

  #component(c, data, q, t, extrapolate, diff) {
    const b = this.buf[c].fill(0);
    rlgrDecode(data, 1, b);
    t.sign[c].set(b);
    const bands = extrapolate ? EXTRA : PLAIN, cur = t.current[c];
    for (let j = bands[0][0] + 1, e = bands[0][0] + bands[0][1]; j < e; j++) b[j] += b[j - 1];
    for (let i = 0; i < 10; i++) {                       // dequantization (and the differential pass): only non-zero values change
      const sh = q[i] > 0 ? q[i] : 0, off = bands[i][0], end = off + bands[i][1];
      if (diff) { for (let j = off; j < end; j++) if (b[j]) cur[j] += b[j] << sh; }
      else if (sh) for (let j = off; j < end; j++) if (b[j]) b[j] <<= sh;
    }
    if (diff) b.set(cur); else cur.set(b);
    if (extrapolate) idwtExtrapolate(b); else idwt(b);
  }

  #upgrade(c, srlData, rawData, shift, numBits, t, extrapolate) {
    const srl = new Srl(srlData), cur = t.current[c], sign = t.sign[c];
    let rbit = 0;
    for (const i of UPGRADE_ORDER) {
      const nb = numBits[i];
      if (nb < 1) continue;
      const [off, len] = (extrapolate ? EXTRA : PLAIN)[i], sh = shift[i];
      for (let j = off; j < off + len; j++) {
        let v;
        if (i === 0 || sign[j] > 0) { v = rd(rawData, rbit, nb); rbit += nb; }
        else if (sign[j] < 0) { v = -rd(rawData, rbit, nb); rbit += nb; }
        else { v = srl.read(nb); sign[j] = v; }
        cur[j] = cur[j] + v * (1 << sh);
      }
    }
    const b = this.buf[c];
    b.set(cur);
    if (extrapolate) idwtExtrapolate(b); else idwt(b);
  }

  /**
   * Decodes a message for surface id (w x h). Returns the updated area as boxes with their RGBA words ([{x, y, w, h, px}],
   * alpha 0 = unchanged): the bounding box of all the rectangles, or one box per rectangle when they cover less than half
   * of it (two tiles in opposite corners gave a box of the whole screen, almost all transparent); [] when nothing changed.
   */
  decode(id, s, w, h) {
    const dv = new DataView(s.buffer, s.byteOffset, s.byteLength);
    if (!this.surfaces.has(id)) this.surfaces.set(id, new Map());
    const tiles = this.surfaces.get(id);
    const regions = [];
    let x0 = w, y0 = h, x1 = 0, y1 = 0, area = 0;
    for (let p = 0; p + 6 <= s.length;) {
      const type = dv.getUint16(p, true), len = dv.getUint32(p + 2, true);
      if (len < 6 || p + len > s.length) break;
      if (type === 0xccc4) {
        regions.push(p + 6);
        for (let i = 0, r = p + 18; i < dv.getUint16(p + 7, true); i++, r += 8) {
          const rx = dv.getUint16(r, true), ry = dv.getUint16(r + 2, true);
          const rx1 = Math.min(w, rx + dv.getUint16(r + 4, true)), ry1 = Math.min(h, ry + dv.getUint16(r + 6, true));
          x0 = Math.min(x0, rx); y0 = Math.min(y0, ry);
          x1 = Math.max(x1, rx1); y1 = Math.max(y1, ry1);
          if (rx1 > rx && ry1 > ry) area += (rx1 - rx) * (ry1 - ry);
        }
      }
      p += len;
    }
    if (x1 <= x0 || y1 <= y0) return [];
    let box = { x: x0, y: y0, w: x1 - x0, h: y1 - y0 };
    if (box.w * box.h > 2 * area) box = null;            // sparse: each region makes a box per rectangle
    else { box.px = new Uint32Array(box.w * box.h); box.parts = []; }
    const boxes = regions.flatMap((b) => this.#region(s, dv, b, tiles, box, w, h));
    return box ? [box] : boxes;
  }

  /** Decodes the tiles of a region into box, or into a box per rectangle (returned) when box is null. */
  #region(s, dv, b, tiles, box, w, h) {
    const nRects = dv.getUint16(b + 1, true), nQuant = s[b + 3], nProg = s[b + 4], extrapolate = !!(s[b + 5] & 1);
    const nTiles = dv.getUint16(b + 6, true);
    let p = b + 12;
    const rects = [];
    for (let i = 0; i < nRects; i++, p += 8) rects.push({ x: dv.getUint16(p, true), y: dv.getUint16(p + 2, true), w: dv.getUint16(p + 4, true), h: dv.getUint16(p + 6, true) });
    const boxes = rects.map((r) => {                       // where the tiles go for each rectangle
      if (box) return box;
      const bw = Math.min(w, r.x + r.w) - r.x, bh = Math.min(h, r.y + r.h) - r.y;
      return bw > 0 && bh > 0 ? { x: r.x, y: r.y, w: bw, h: bh, px: new Uint32Array(bw * bh), parts: [] } : null;
    });
    const quants = [];
    for (let i = 0; i < nQuant; i++, p += 5) quants.push(quantRead(s, p));
    const progs = [];
    for (let i = 0; i < nProg; i++, p += 16) progs.push([quantRead(s, p + 1), quantRead(s, p + 6), quantRead(s, p + 11)]);
    for (let i = 0; i < nTiles && p + 6 <= s.length; i++) {
      const type = dv.getUint16(p, true), len = dv.getUint32(p + 2, true);
      const qi = [s[p + 6], s[p + 7], s[p + 8]], tx = dv.getUint16(p + 9, true), ty = dv.getUint16(p + 11, true);
      if (len < 6) break;                                  // corrupt (and it would be decoded again and again)
      if (tx * 64 >= w || ty * 64 >= h) { p += len; continue; }   // outside the surface: never shown, so never kept
      const key = ty * 65536 + tx;
      let t = tiles.get(key);
      if (!t) {
        t = { current: [0, 1, 2].map(() => new Int16Array(4096)), sign: [0, 1, 2].map(() => new Int16Array(4096)), bitPos: null };
        tiles.set(key, t);
      }
      const q = [quants[qi[0]], quants[qi[1]], quants[qi[2]]];
      if (type === 0xccc5 || type === 0xccc6) {           // simple or first pass
        const simple = type === 0xccc5, flags = s[p + 13], prog = progQuant(progs, simple ? 0xff : s[p + 14], q);
        let d = p + (simple ? 22 : 23);
        const lens = [0, 1, 2].map((c) => dv.getUint16(p + (simple ? 14 : 15) + c * 2, true));
        t.bitPos = [qOp(q[0], prog[0], add), qOp(q[1], prog[1], add), qOp(q[2], prog[2], add)];
        for (let c = 0; c < 3; c++) {
          this.#component(c, s.subarray(d, d + lens[c]), qOp(t.bitPos[c], ZERO, dec), t, extrapolate, !!(flags & 1));
          d += lens[c];
        }
      } else if (type === 0xccc7 && t.bitPos) {           // upgrade pass
        const prog = progQuant(progs, s[p + 13], q);
        const lens = [0, 1, 2, 3, 4, 5].map((k) => dv.getUint16(p + 14 + k * 2, true));
        let d = p + 26;
        for (let c = 0; c < 3; c++) {
          const pos = qOp(q[c], prog[c], add);
          const numBits = qOp(t.bitPos[c], pos, sub);
          const srl = s.subarray(d, d + lens[c * 2]), raw = s.subarray(d + lens[c * 2], d + lens[c * 2] + lens[c * 2 + 1]);
          this.#upgrade(c, srl, raw, qOp(pos, ZERO, dec), numBits, t, extrapolate);
          t.bitPos[c] = pos;
          d += lens[c * 2] + lens[c * 2 + 1];
        }
      } else { p += len; continue; }
      const x0t = tx * 64, y0t = ty * 64;
      for (let k = 0; k < rects.length; k++) {           // the tile straight into each box, where it meets its rectangle
        const r = rects[k], o = boxes[k];
        if (!o) continue;
        const x0 = Math.max(x0t, r.x, o.x), y0 = Math.max(y0t, r.y, o.y);
        const x1 = Math.min(x0t + 64, r.x + r.w, o.x + o.w), y1 = Math.min(y0t + 64, r.y + r.h, o.y + o.h);
        if (x1 > x0 && y1 > y0) {
          ycbcrToRgba(this.buf[0], this.buf[1], this.buf[2], o.px, o.w, x0 - o.x, y0 - o.y, x1 - x0, y1 - y0, x0 - x0t, y0 - y0t);
          o.parts.push(x0 - o.x, y0 - o.y, x1 - x0, y1 - y0);
        }
      }
      p += len;
    }
    return box ? [] : boxes.filter((o) => o);
  }
}
