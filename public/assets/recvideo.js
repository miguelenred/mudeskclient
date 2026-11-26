// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// A session's recording made into a video file (WebM: VP9 or VP8, and Opus for its sound) in the browser, with WebCodecs.
'use strict';

import { RdpSession } from './session.js';
import { WebmWriter } from './webm.js';
import { decodeAudio, micFormat } from './audio.js';
import { drawPicture } from './pagerec.js';

const RATE = 48000;                                      // Opus's own rate: the sound is taken to it
const KEY_EVERY = 5000;                                  // ms between key frames (where a player can start)
const QUEUE = 8;                                         // pictures waiting for the encoder before the next is taken

/** Whether this browser makes videos (Chrome and Edge 94, Firefox 130, Safari 26 and later). */
export const canMakeVideo = () => typeof VideoEncoder === 'function' && typeof VideoFrame === 'function';

/**
 * Sound of one rate taken to 48 kHz: a windowed sinc (16 points, Blackman), its weights worked out once for 512 places
 * between two samples. The last samples of a piece are kept for the next one (no click between them); reset() starts anew.
 */
export class Resampler {
  constructor(from, to = RATE, taps = 16, phases = 512) {
    this.step = from / to;                               // input samples per output sample
    this.half = taps / 2;
    this.phases = phases;
    const cut = Math.min(1, to / from);                  // (fewer samples: the band above the new half rate left out)
    this.w = new Float32Array((phases + 1) * taps);
    for (let p = 0; p <= phases; p++) {
      let sum = 0;
      for (let m = 0; m < taps; m++) {
        const d = p / phases + this.half - 1 - m;        // distance from the output sample to input sample m of its window
        const x = d * cut;
        const win = 0.42 + 0.5 * Math.cos(Math.PI * d / this.half) + 0.08 * Math.cos(2 * Math.PI * d / this.half);
        const v = (x === 0 ? 1 : Math.sin(Math.PI * x) / (Math.PI * x)) * (Math.abs(d) < this.half ? win : 0);
        this.w[p * taps + m] = v;
        sum += v;
      }
      for (let m = 0; m < taps; m++) this.w[p * taps + m] /= sum || 1;   // a steady level stays the same
    }
    this.reset();
  }

  reset() {
    this.tail = null;                                    // per channel: the input not yet used up
    this.t = this.half - 1;                              // where the next output sample falls in it
  }

  /** The end of what was given (its last samples wait for the next piece otherwise), then anew. */
  flush() {
    if (!this.tail || this.step === 1) { this.reset(); return null; }
    const out = this.push(this.tail.map(() => new Float32Array(this.half)));
    this.reset();
    return out;
  }

  /** @param {Float32Array[]} chans one piece, its channels @returns {Float32Array[]} */
  push(chans) {
    if (this.step === 1) return chans;
    const taps = this.half * 2;
    if (!this.tail) this.tail = chans.map(() => new Float32Array(this.half - 1));   // (silence before the first sample)
    const ins = chans.map((c, i) => { const a = new Float32Array(this.tail[i].length + c.length); a.set(this.tail[i]); a.set(c, this.tail[i].length); return a; });
    const len = ins[0].length;
    const n = Math.max(0, Math.ceil((len - this.half - this.t) / this.step));
    const outs = ins.map(() => new Float32Array(n));
    let t = this.t;
    for (let k = 0; k < n; k++, t += this.step) {
      const i = Math.floor(t), p = Math.round((t - i) * this.phases) * taps, start = i - this.half + 1;
      for (let c = 0; c < ins.length; c++) {
        const src = ins[c];
        let acc = 0;
        for (let m = 0; m < taps; m++) acc += src[start + m] * this.w[p + m];
        outs[c][k] = acc;
      }
    }
    const keep = Math.max(0, Math.floor(t) - this.half + 1);   // what the next output samples still need
    this.tail = ins.map((a) => a.slice(keep));
    this.t = t - keep;
    return outs;
  }
}

/**
 * The recording's sound on one line of time at 48 kHz, stereo: the session's (frame 7) and the user's microphone (frames 13
 * and 32), each piece placed at its time, right after the one before of its own while it comes in time (as the session's
 * page plays them, see audio.js), apart from it after a pause or a lag; what is placed over something adds up, as it
 * would sound. What lies before the time the pictures (or the sound) reached is final: given to the encoder (silence
 * where there was none).
 */
export class SoundLine {
  constructor(encode) {
    this.encode = encode;                                // (f32-planar left and right, sample index of the first)
    this.done = 0;                                       // samples given to the encoder
    this.end = 0;                                        // the furthest sample placed (a piece placed apart may end before it)
    this.buf = [new Float32Array(RATE), new Float32Array(RATE)];   // from `done` on
    // each source: its formats, the format its resampler is for, the sample where its next piece goes on
    this.remote = { formats: [], inUse: null, rs: null, next: 0 };
    this.mic = { fmt: null, inUse: null, rs: null, next: 0 };
  }

  /** The session's sound (frame 7): 'F' + its formats, 'W' + u16 the format + the samples. */
  onMessage(d, ms) {
    const kind = String.fromCharCode(d[0]);
    if (kind === 'F') { this.remote.formats = JSON.parse(new TextDecoder().decode(d.subarray(1))); return; }
    if (kind === 'W' && ms !== undefined) this.#piece(this.remote, this.remote.formats[d[1] | (d[2] << 8)], d.subarray(3), ms);
  }

  /** The microphone's format (frame 13), then what it sent (frame 32). */
  micFormat(d) { this.mic.fmt = micFormat(d); }

  micData(d, ms) { if (ms !== undefined) this.#piece(this.mic, this.mic.fmt, d, ms); }

  #piece(v, fmt, bytes, ms) {
    if (!fmt || !(fmt.ch >= 1 && fmt.ch <= 2) || !(fmt.rate >= 8000 && fmt.rate <= 192000)) return;   // (as the page plays them)
    let chans;
    try {
      chans = decodeAudio(fmt, bytes);
    } catch {
      return;
    }
    if (!chans[0]?.length) return;
    const at = Math.round(ms * RATE / 1000);
    const apart = v.next < at || v.next > at + RATE / 2;   // a pause, or too far ahead: placed at its own time
    if (fmt !== v.inUse || !v.rs) {
      this.#place(v, v.rs?.flush());                   // (the end of the piece before, where it goes on)
      v.rs = new Resampler(fmt.rate);
      v.inUse = fmt;
    } else if (apart) this.#place(v, v.rs.flush());
    // what lies before this piece's time is final now: no later piece goes before it (sound on a still screen, where no
    // picture is taken, held all of it otherwise)
    this.upTo(ms);
    if (apart) v.next = at;
    this.#place(v, v.rs.push(chans));
  }

  /** Samples (one or two channels) at the source's `next`, added to what is there; before what was given to the encoder, left out. */
  #place(v, out) {
    if (!out?.[0]?.length) return;
    const l = out[0], r = out[1] ?? out[0];
    const skip = Math.max(0, this.done - v.next);
    this.#room(v.next + l.length);
    for (let i = skip; i < l.length; i++) {
      this.buf[0][v.next - this.done + i] += l[i];
      this.buf[1][v.next - this.done + i] += r[i];
    }
    v.next += l.length;
    this.end = Math.max(this.end, v.next);
  }

  #room(end) {
    const need = end - this.done;
    if (need <= this.buf[0].length) return;
    let n = this.buf[0].length;
    while (n < need) n *= 2;
    this.buf = this.buf.map((b) => { const a = new Float32Array(n); a.set(b); return a; });
  }

  /** Everything before ms is final: to the encoder, in pieces of a second at most. */
  upTo(ms) {
    const end = Math.round(ms * RATE / 1000);
    while (this.done < end) {
      const n = Math.min(end - this.done, RATE);
      this.#room(this.done + n);
      const planar = new Float32Array(n * 2);
      planar.set(this.buf[0].subarray(0, n));
      planar.set(this.buf[1].subarray(0, n), n);
      this.encode(planar, n, this.done);
      const used = Math.max(n, this.end - this.done);   // (what was placed: moved to the start, the rest silence again)
      for (const b of this.buf) { b.copyWithin(0, n, used); b.fill(0, used - n, used); }
      this.done += n;
    }
  }

  /** Up to the end of what was placed, or ms. */
  finish(ms) {
    for (const v of [this.remote, this.mic]) this.#place(v, v.rs?.flush());
    this.upTo(Math.max(ms, this.end * 1000 / RATE));
  }
}

/** The video's bit rate for pictures of `px` pixels: what it takes with something moving all the time, 1 to 12 Mbit/s. */
const bitrateFor = (px) => Math.round(Math.min(12e6, Math.max(1e6, px * 15 * 0.08)));
const SOUND_BITRATE = 96000;                             // Opus
const OFFICE = 16000;                                    // bytes a second per megapixel of an office desktop's video (measured: 13.3 KB/s at 928 x 917)

/**
 * What the video of a recording ({seconds, ended, started, width, height, sound} of its details) may take, in bytes: `most`
 * with something moving all the time (the encoder's whole bit rate, a tenth more for the key frames and the file), the
 * room to have; `about` with an office desktop (most of it still). The sound counted whole in both.
 */
export function videoEstimate(info) {
  const secs = info.ended || !info.started ? (info.seconds || 0) : Math.max(1, (Date.now() - Date.parse(info.started)) / 1000);
  const px = (info.width || 1024) * (info.height || 768);
  const sound = info.sound ? SOUND_BITRATE / 8 : 0;
  return { most: Math.round(secs * (bitrateFor(px) / 8 * 1.1 + sound)), about: Math.round(secs * (px / 1e6 * OFFICE + sound)) };
}

/** VP9 (VP8 when it is not there) at the largest size this browser encodes, from the desktop's: [codec id, config]. */
async function videoConfig(w, h) {
  const even = (v) => Math.max(2, Math.ceil(v / 2) * 2);
  for (const max of [Infinity, 3840 * 2160, 1920 * 1080]) {
    const k = Math.min(1, Math.sqrt(max / (w * h)));
    const width = even(w * k), height = even(h * k);
    const px = width * height, bitrate = bitrateFor(px);
    const level = px <= 2228224 ? '40' : px <= 8912896 ? '50' : '60';
    for (const [id, codec] of [['V_VP9', `vp09.00.${level}.08`], ['V_VP8', 'vp8']]) {
      const config = { codec, width, height, bitrate, framerate: 15, latencyMode: 'quality' };
      try {
        if ((await VideoEncoder.isConfigSupported(config)).supported) return [id, config];
      } catch { /* not this one */ }
    }
  }
  return null;
}

/**
 * The video of recording `sid` ({width, height, sound, seconds} of its details: info): a Blob (video/webm). onProgress(ms) as
 * it goes; signal: stops it (rejected with 'stopped'). Rejected with 'video_unsupported', 'recording_damaged' or another
 * reason of the stream when it cannot be made.
 */
export async function recordingVideo({ sid, info, csrf, onProgress = () => {}, signal = null }) {
  if (!canMakeVideo()) throw new Error('video_unsupported');
  const dw = info.width || 1024, dh = info.height || 768;
  const vc = await videoConfig(dw, dh);
  if (!vc) throw new Error('video_unsupported');
  const [codecId, config] = vc;
  let audioOn = false;
  const audioConfig = { codec: 'opus', sampleRate: RATE, numberOfChannels: 2, bitrate: SOUND_BITRATE };
  if (info.sound && typeof AudioEncoder === 'function') {
    try { audioOn = !!(await AudioEncoder.isConfigSupported(audioConfig)).supported; } catch { audioOn = false; }
  }
  const writer = new WebmWriter({ codec: codecId, width: config.width, height: config.height }, audioOn ? { channels: 2, head: null, preSkip: 312 } : null);
  let failed = null;
  const fail = (e) => { failed ??= e instanceof Error ? e : new Error(String(e)); };
  const venc = new VideoEncoder({
    output: (chunk) => { const b = new Uint8Array(chunk.byteLength); chunk.copyTo(b); writer.addVideo(chunk.timestamp / 1000, b, chunk.type === 'key'); },
    error: fail,
  });
  venc.configure(config);
  let aenc = null, sound = null;
  if (audioOn) {
    aenc = new AudioEncoder({
      output: (chunk, meta) => {
        const head = meta?.decoderConfig?.description;
        if (head && !writer.audio.headSet) {             // OpusHead, from the encoder: its pre-skip too
          const u = head instanceof ArrayBuffer ? new Uint8Array(head) : new Uint8Array(head.buffer, head.byteOffset, head.byteLength);
          if (u.length >= 19 && new TextDecoder().decode(u.subarray(0, 8)) === 'OpusHead') {
            Object.assign(writer.audio, { head: u.slice(), preSkip: u[10] | (u[11] << 8), headSet: true });
          }
        }
        const b = new Uint8Array(chunk.byteLength);
        chunk.copyTo(b);
        writer.addAudio(chunk.timestamp / 1000, b);
      },
      error: fail,
    });
    aenc.configure(audioConfig);
    sound = new SoundLine((planar, frames, first) => {
      const data = new AudioData({ format: 'f32-planar', sampleRate: RATE, numberOfFrames: frames, numberOfChannels: 2, timestamp: Math.round(first * 1e6 / RATE), data: planar });
      try {
        aenc.encode(data);
      } finally {
        data.close();                                    // (as the pictures: also when the encoder threw)
      }
    });
  }

  // the recording played off the page, as fast as it goes, with the session's own decoders
  const holder = Object.assign(document.createElement('div'), { className: 'rec-video-work' });
  holder.style.cssText = 'position:fixed;left:-20000px;top:0;width:10px;height:10px;overflow:hidden;visibility:hidden';
  const canvas = Object.assign(document.createElement('canvas'), { width: dw, height: dh });
  const keyboard = document.createElement('textarea');
  holder.append(canvas, keyboard);
  document.body.append(holder);
  const out = Object.assign(document.createElement('canvas'), { width: config.width, height: config.height });
  const octx = out.getContext('2d', { alpha: false });
  let lastKey = -Infinity, end = 0;
  const waitQueue = async () => {
    while (venc.encodeQueueSize > QUEUE && !failed) {
      if ('ondequeue' in venc) await new Promise((r) => venc.addEventListener('dequeue', r, { once: true }));
      else await venc.flush();
    }
  };
  /** The desktop as it is now, the pointer over it where the user had it: a picture of the video at ms. */
  const take = async (ms) => {
    if (failed) throw failed;
    if (signal?.aborted) throw new Error('stopped');
    sound?.upTo(ms);
    drawPicture(octx, s.surface, s.ptr, s.pointerAt);
    const frame = new VideoFrame(out, { timestamp: Math.round(ms * 1000) });
    const key = ms - lastKey >= KEY_EVERY;
    if (key) lastKey = ms;
    try {
      venc.encode(frame, { keyFrame: key });
    } finally {
      frame.close();                                     // (also when the encoder threw: it holds a whole picture)
    }
    end = Math.max(end, ms);
    await waitQueue();
  };

  let s;
  try {
    await new Promise((resolve, reject) => {
      let whole = false;
      s = new RdpSession({ container: holder, canvas, keyboard, conn: { id: '', name: '' }, csrf, retries: 0,
        replay: { sid, from: 0, speed: 1, video: true }, onState: (state, e) => {
          if (state !== 'ended') return;
          if (whole) resolve();
          else reject(new Error(signal?.aborted ? 'stopped' : failed ? 'video_failed' : e?.end && !['network', 'user'].includes(e.end) ? e.end : 'recording_damaged'));
        } });
      s.audio = sound;
      s.onCapture = take;
      s.onReplayPos = (ms, done) => { end = Math.max(end, ms); onProgress(ms); if (done) whole = true; };
      signal?.addEventListener('abort', () => s.stop(), { once: true });
      s.start();
    });
    if (failed) throw failed;
    const total = Math.max(end, (info.seconds || 0) * 1000);
    sound?.finish(total);
    await venc.flush();
    if (aenc) await aenc.flush();
    if (failed) throw failed;
    return writer.finish(total);
  } finally {
    s?.stop();
    s?.dispose();                                        // (its window listeners held it, its canvases and caches with it)
    holder.remove();
    if (venc.state !== 'closed') venc.close();
    if (aenc && aenc.state !== 'closed') aenc.close();
  }
}
