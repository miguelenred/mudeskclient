// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// Plays the remote session's audio (IMA ADPCM, A-law, mu-law or PCM) with the Web Audio API.
'use strict';

const IMA_STEP = [7, 8, 9, 10, 11, 12, 13, 14, 16, 17, 19, 21, 23, 25, 28, 31, 34, 37, 41, 45, 50, 55, 60, 66, 73, 80, 88, 97,
  107, 118, 130, 143, 157, 173, 190, 209, 230, 253, 279, 307, 337, 371, 408, 449, 494, 544, 598, 658, 724, 796, 876, 963, 1060,
  1166, 1282, 1411, 1552, 1707, 1878, 2066, 2272, 2499, 2749, 3024, 3327, 3660, 4026, 4428, 4871, 5358, 5894, 6484, 7132, 7845,
  8630, 9493, 10442, 11487, 12635, 13899, 15289, 16818, 18500, 20350, 22385, 24623, 27086, 29794, 32767];
const IMA_INDEX = [-1, -1, -1, -1, 2, 4, 6, 8];

const law = (alaw) => {
  const t = new Float32Array(256);
  for (let i = 0; i < 256; i++) {
    let v;
    if (alaw) {
      const a = i ^ 0x55, seg = (a >> 4) & 7;
      v = ((a & 0x0f) << 4) + 8;
      if (seg) v = (v + 0x100) << (seg - 1);
      v = a & 0x80 ? v : -v;
    } else {
      const u = ~i & 0xff, seg = (u >> 4) & 7;
      v = ((((u & 0x0f) << 3) + 0x84) << seg) - 0x84;
      v = u & 0x80 ? -v : v;
    }
    t[i] = v / 32768;
  }
  return t;
};
const ALAW = law(true), ULAW = law(false);

/** Decodes one audio chunk. fmt: {tag, ch, rate, bits, align}. Returns one Float32Array per channel. */
export function decodeAudio(fmt, u8) {
  const ch = fmt.ch;
  if (fmt.tag === 0x0001) {
    const bytes = fmt.bits >> 3, frames = Math.floor(u8.length / (bytes * ch));
    const out = Array.from({ length: ch }, () => new Float32Array(frames));
    const dv = new DataView(u8.buffer, u8.byteOffset, u8.byteLength);
    for (let i = 0; i < frames; i++) {
      for (let c = 0; c < ch; c++) {
        const o = (i * ch + c) * bytes;
        out[c][i] = bytes === 2 ? dv.getInt16(o, true) / 32768 : (u8[o] - 128) / 128;
      }
    }
    return out;
  }
  if (fmt.tag === 0x0006 || fmt.tag === 0x0007) {
    const table = fmt.tag === 0x0006 ? ALAW : ULAW, frames = Math.floor(u8.length / ch);
    const out = Array.from({ length: ch }, () => new Float32Array(frames));
    for (let i = 0; i < frames; i++) for (let c = 0; c < ch; c++) out[c][i] = table[u8[i * ch + c]];
    return out;
  }
  // IMA ADPCM: blocks of fmt.align bytes, a 4-byte header per channel, then 4-byte groups per channel (other blocks: nothing)
  const align = fmt.align, perBlock = ((align - 4 * ch) * 2) / ch + 1;
  if (!(align > 4 * ch) || (align - 4 * ch) % (4 * ch)) return [];
  const blocks = Math.floor(u8.length / align);
  const out = Array.from({ length: ch }, () => new Float32Array(blocks * perBlock));
  for (let b = 0; b < blocks; b++) {
    const base = b * align;
    for (let c = 0; c < ch; c++) {
      let pred = (u8[base + c * 4] | (u8[base + c * 4 + 1] << 8)) << 16 >> 16;
      let idx = Math.min(88, u8[base + c * 4 + 2]);
      const dst = out[c];
      let n = b * perBlock;
      dst[n++] = pred / 32768;
      for (let g = 0; ; g++) {
        const o = base + 4 * ch + (g * ch + c) * 4;
        if (o + 4 > base + align) break;
        for (let k = 0; k < 8; k++) {
          const nib = (u8[o + (k >> 1)] >> ((k & 1) * 4)) & 0x0f;
          const step = IMA_STEP[idx];
          let diff = step >> 3;
          if (nib & 4) diff += step;
          if (nib & 2) diff += step >> 1;
          if (nib & 1) diff += step >> 2;
          pred = Math.max(-32768, Math.min(32767, nib & 8 ? pred - diff : pred + diff));
          idx = Math.max(0, Math.min(88, idx + IMA_INDEX[nib & 7]));
          dst[n++] = pred / 32768;
        }
      }
    }
  }
  return out;
}

/** The microphone's format of a frame 13 (F_MIC: 1, u32 rate, u8 channels; 16-bit PCM), or null (0: it stopped). */
export function micFormat(d) {
  if (d[0] !== 1 || d.length < 6) return null;
  const rate = new DataView(d.buffer, d.byteOffset, d.byteLength).getUint32(1, true), ch = d[5];
  return ch >= 1 && ch <= 2 && rate >= 8000 && rate <= 192000 ? { tag: 1, ch, rate, bits: 16, align: 2 * ch } : null;
}

export class AudioPlayer {
  constructor() {
    this.ctx = null;
    this.formats = [];
    this.next = 0;
    this.micFmt = null;                                  // a recording played: the user's microphone, heard with the session
    this.micNext = 0;
    this.out = null;                                     // where it plays (a recording made in the page: its own line), else the speakers
    this.muted = false;
  }

  /** Must be called from a user gesture (browser autoplay rules). */
  unlock() {
    try {
      this.ctx ??= new AudioContext({ latencyHint: 'interactive' });
      if (this.ctx.state === 'suspended') this.ctx.resume();
    } catch { /* no audio support */ }
  }

  onMessage(d) {
    const kind = String.fromCharCode(d[0]);
    if (kind === 'F') { this.formats = JSON.parse(new TextDecoder().decode(d.subarray(1))); return; }
    if (kind !== 'W' || this.muted || !this.ctx || this.ctx.state !== 'running') return;
    const fmt = this.formats[d[1] | (d[2] << 8)];
    // the gateway only takes formats the page plays (1-2 channels, 8-192 kHz); a chunk of any other is dropped, never thrown
    if (!fmt || !(fmt.ch >= 1 && fmt.ch <= 2) || !(fmt.rate >= 8000 && fmt.rate <= 192000)) return;
    this.#play(fmt, d.subarray(3), 'next');
  }

  /** A recording played: the microphone's format (frame 13), then what it sent (frame 32, see Recording::MIC). */
  micFormat(d) { this.micFmt = micFormat(d); }

  micData(d) {
    if (this.micFmt && !this.muted && this.ctx?.state === 'running') this.#play(this.micFmt, d, 'micNext');
  }

  /** Decoded and played right after the piece before on its own line (`cursor`), or soon after a lag. */
  #play(fmt, bytes, cursor) {
    let buf;
    try {
      const chans = decodeAudio(fmt, bytes);
      if (!chans[0]?.length) return;
      buf = this.ctx.createBuffer(chans.length, chans[0].length, fmt.rate);
      chans.forEach((c, i) => buf.copyToChannel(c, i));
    } catch {
      return;
    }
    const now = this.ctx.currentTime;
    if (this[cursor] < now + 0.02 || this[cursor] > now + 0.5) this[cursor] = now + 0.06;   // start or resync after lag
    const src = this.ctx.createBufferSource();
    src.buffer = buf;
    src.connect(this.out ?? this.ctx.destination);
    src.start(this[cursor]);
    this[cursor] += buf.duration;
  }

  setMuted(m) {
    this.muted = m;
    if (m) this.next = this.micNext = 0;
  }

  /** The page is done with it: its audio context closed (browsers keep only so many). */
  close() {
    this.ctx?.close().catch(() => {});
    this.ctx = null;
  }
}
