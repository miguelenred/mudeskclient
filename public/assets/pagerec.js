// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// A recording started in a session, made in the browser: what the page shows (the remote desktop and its pointer) and
// plays (the remote computer's sound and the microphone it hears), as a video file sent to the gateway in pieces.
'use strict';

import { AudioPlayer } from './audio.js';
import { WebmRemux } from './webm.js';

const FPS = 15;                                          // pictures a second at most
const SLICE = 1000;                                      // ms of video in each piece sent
const MAX_W = 3840, MAX_H = 2160;                        // a larger desktop is scaled into it
export const REC = 22;                                   // the page's message with a piece of its recording (rdp.php)
export const REC_FIRST = 1, REC_LAST = 2, REC_FAILED = 4;
const VIDEO = 33;                                        // Recording::VIDEO: a frame of a recording made in the browser

/** Whether this browser records what the page shows (MediaRecorder of a canvas: Chrome, Edge, Firefox, Safari 14.1). */
export const canRecordPage = () => typeof MediaRecorder === 'function' && typeof HTMLCanvasElement === 'function'
  && typeof HTMLCanvasElement.prototype.captureStream === 'function';

/** The first file type of the list this browser records (WebM with VP9 or VP8; MP4 in Safari), '' for its own default. */
export function pickType(sound, supported = (t) => MediaRecorder.isTypeSupported(t)) {
  const list = sound ? ['video/webm;codecs=vp9,opus', 'video/webm;codecs=vp8,opus', 'video/webm', 'video/mp4;codecs=avc1,mp4a.40.2', 'video/mp4']
    : ['video/webm;codecs=vp9', 'video/webm;codecs=vp8', 'video/webm', 'video/mp4;codecs=avc1', 'video/mp4'];
  return list.find((t) => { try { return supported(t); } catch { return false; } }) ?? '';
}

/** Bits a second for the video: about an office desktop's needs, more for a larger one (0.5-6 Mbit/s). */
export const pageBitrate = (w, h) => Math.round(Math.min(6e6, Math.max(5e5, w * h * FPS * 0.04)));

/** The size of the video: the desktop's, scaled into MAX_W x MAX_H, even. */
export function videoSize(w, h) {
  const k = Math.min(1, MAX_W / Math.max(1, w), MAX_H / Math.max(1, h));
  return [Math.max(2, Math.round(w * k / 2) * 2), Math.max(2, Math.round(h * k / 2) * 2)];
}

/** The system's arrow pointer (the remote one when it is the default: no picture of it was sent). */
export function arrow(c, x, y, k) {
  const s = Math.max(0.75, k);
  c.save();
  c.translate(x, y);
  c.scale(s, s);
  c.beginPath();
  c.moveTo(0, 0); c.lineTo(0, 17); c.lineTo(4.5, 13); c.lineTo(7.5, 19.5); c.lineTo(10, 18.5); c.lineTo(7, 12); c.lineTo(12, 12); c.closePath();
  c.fillStyle = '#fff';
  c.strokeStyle = '#000';
  c.lineWidth = 1;
  c.fill();
  c.stroke();
  c.restore();
}

/**
 * The desktop in the middle of a picture of the video (out: its 2D context), made smaller if it does not fit (never
 * larger), and the pointer over it where the user has it (at: on the desktop; its picture, else the system's arrow).
 */
export function drawPicture(out, src, ptr, at) {
  const W = out.canvas.width, H = out.canvas.height;
  const k = Math.min(1, W / Math.max(1, src.width), H / Math.max(1, src.height));
  const w = Math.round(src.width * k), h = Math.round(src.height * k), x0 = (W - w) >> 1, y0 = (H - h) >> 1;
  out.fillStyle = '#000';
  out.fillRect(0, 0, W, H);
  if (src.width && src.height) out.drawImage(src, 0, 0, src.width, src.height, x0, y0, w, h);
  if (at && ptr && ptr.css !== 'none') {
    const px = x0 + at[0] * k, py = y0 + at[1] * k;
    if (ptr?.img) out.drawImage(ptr.img, px - ptr.hx * k, py - ptr.hy * k, ptr.img.width * k, ptr.img.height * k);
    else arrow(out, px, py, k);
  }
}

/**
 * A page's recording of its session: started with the gateway's number for it, it draws the desktop and the pointer into
 * a canvas of its own up to FPS times a second (when something changed, and once a second), mixes the remote computer's
 * sound and the microphone the remote computer hears on a line of its own (also while the page is muted), and records
 * both with MediaRecorder; each second's piece goes to send(message) in order: [22][u16 number][u32 piece][u8 flags]
 * (the first: + u16 length + JSON {mime, w, h, sound}), then its bytes. Stopped, the rest goes with REC_LAST; a browser
 * that cannot record says so with REC_FAILED.
 *
 * session: {surface, ptr, mouseAt, counts: {frames}, soundFormats, micFormatMsg (the last frames 7 'F' and 13), audio (its
 * player: its AudioContext, running once the page was clicked or a key pressed)}; sound: whether the session has sound or
 * the microphone (an audio track). The sound is mixed in the session's own AudioContext when it is running, else in one of
 * its own; a silent source keeps it going (Chrome writes nothing while an audio track gives no data). With no context
 * running (the browser lets audio start only after the page was used: a page reloaded and not touched since), it records
 * the picture alone: in Chrome an audio track of a context that does not run held the whole recording back.
 */
export class PageRecorder {
  constructor({ session, gen, send, sound = false }) {
    Object.assign(this, { s: session, gen, send, sound });
    this.n = 0;                                          // pieces made
    this.chain = Promise.resolve();                      // pieces sent in order (each Blob read in turn)
    this.done = false;
    this.mr = null;
  }

  /** Starts recording; false (REC_FAILED sent) when this browser cannot. */
  start() {
    try {
      if (!canRecordPage()) throw new Error('unsupported');
      const [w, h] = videoSize(this.s.surface.width, this.s.surface.height);
      this.canvas = Object.assign(document.createElement('canvas'), { width: w, height: h });
      this.ctx2d = this.canvas.getContext('2d', { alpha: false });
      drawPicture(this.ctx2d, this.s.surface, this.s.ptr, this.s.mouseAt);
      const stream = this.stream = this.canvas.captureStream(0);   // a picture each time one is asked for (requestFrame)
      this.track = stream.getVideoTracks()[0];
      if (this.sound) {                                  // (the sound's, else the microphone's while it records)
        const shared = [this.s.audio?.ctx, this.s.mic?.live?.ctx].find((c) => c?.state === 'running') ?? null;
        this.actx = shared ?? new AudioContext();
        this.ownCtx = !shared;
        if (this.actx.state !== 'running') {             // (not allowed to start yet: the picture alone)
          this.actx.close?.().catch?.(() => {});
          this.actx = null;
          this.sound = false;
        }
      }
      if (this.sound) {
        this.dest = this.actx.createMediaStreamDestination();
        this.silence = this.actx.createConstantSource();
        this.silence.offset.value = 0;
        this.silence.connect(this.dest);
        this.silence.start();
        this.player = Object.assign(new AudioPlayer(), { ctx: this.actx, out: this.dest });
        if (this.s.soundFormats) this.player.onMessage(this.s.soundFormats);   // (the formats came at the start of the session)
        if (this.s.micFormatMsg) this.player.micFormat(this.s.micFormatMsg);
        for (const t of this.dest.stream.getAudioTracks()) stream.addTrack(t);
      }
      this.mime = pickType(this.sound);
      this.mr = new MediaRecorder(stream, { ...(this.mime ? { mimeType: this.mime } : {}), videoBitsPerSecond: pageBitrate(w, h),
        ...(this.sound ? { audioBitsPerSecond: 64000 } : {}), videoKeyFrameIntervalDuration: 5000 });
      this.info = { mime: this.mr.mimeType || this.mime || 'video/webm', w, h, sound: this.sound };
      this.mr.ondataavailable = (e) => { if (e.data?.size) this.#piece(e.data, 0); };
      this.mr.onstop = () => this.#piece(null, this.failed ? REC_FAILED : REC_LAST);
      this.mr.onerror = () => { this.failed = true; this.#stopRecorder(); };
      this.mr.start(SLICE);
      this.#picture();
      this.seen = { f: this.s.counts.frames, at: this.s.mouseAt, p: this.s.ptr, t: performance.now() };
      this.timer = setInterval(() => this.#tick(), 1000 / FPS);
      return true;
    } catch {
      if (this.mr) this.mr.ondataavailable = this.mr.onstop = this.mr.onerror = null;
      this.#release();
      this.done = true;
      this.info ??= { mime: '', w: 0, h: 0, sound: false };
      this.#queue(new Uint8Array(0), REC_FAILED);        // (the gateway stops it and says why)
      return false;
    }
  }

  /** A new picture of the video when the desktop or the pointer changed, or a second went by. */
  #tick() {
    const s = this.s, seen = this.seen, now = performance.now();
    if (s.counts.frames === seen.f && s.mouseAt === seen.at && s.ptr === seen.p && now - seen.t < 1000) return;
    Object.assign(seen, { f: s.counts.frames, at: s.mouseAt, p: s.ptr, t: now });
    drawPicture(this.ctx2d, s.surface, s.ptr, s.mouseAt);
    this.#picture();
  }

  /** The picture drawn given to the video: asked of the track (Chrome, Safari) or of the stream (Firefox has it there). */
  #picture() {
    if (typeof this.track.requestFrame === 'function') this.track.requestFrame();
    else this.stream.requestFrame?.();
  }

  /** The session's sound (frame 7) and microphone (its format, frame 13; what the page sent, 16-bit PCM). */
  onSound(d) { if (d[0] === 70 || !this.done) this.player?.onMessage(d); }
  micFormat(d) { this.player?.micFormat(d); }
  micData(pcm) { if (!this.done) this.player?.micData(pcm); }

  /** The browser may keep the audio paused until the page is touched or a key pressed. */
  unlock() { if (this.actx?.state === 'suspended') this.actx.resume().catch(() => {}); }

  /** Stopped by the gateway: what it has left goes with REC_LAST. */
  stop() {
    if (this.done) return;
    this.done = true;
    this.#stopRecorder();
  }

  #stopRecorder() {
    clearInterval(this.timer);
    try { if (this.mr?.state !== 'inactive') this.mr?.stop(); else this.#piece(null, this.failed ? REC_FAILED : REC_LAST); } catch { this.#piece(null, REC_FAILED); }
  }

  #piece(blob, flags) {
    if (flags) this.#release();
    this.chain = this.chain.then(async () => this.#queue(blob ? new Uint8Array(await blob.arrayBuffer()) : new Uint8Array(0), flags))
      .catch(() => this.#queue(new Uint8Array(0), REC_FAILED));
  }

  #queue(bytes, flags) {
    if (this.sentLast) return;
    const head = this.n === 0 ? new TextEncoder().encode(JSON.stringify(this.info)) : null;
    if (head) flags |= REC_FIRST;
    const m = new Uint8Array(8 + (head ? 2 + head.length : 0) + bytes.length);
    const dv = new DataView(m.buffer);
    m[0] = REC;
    dv.setUint16(1, this.gen, true);
    dv.setUint32(3, this.n++, true);
    m[7] = flags;
    let o = 8;
    if (head) { dv.setUint16(8, head.length, true); m.set(head, 10); o = 10 + head.length; }
    m.set(bytes, o);
    if (flags & (REC_LAST | REC_FAILED)) this.sentLast = true;
    this.send(m);
  }

  /** Its canvas, tracks and audio let go (the pieces still being read are sent). */
  #release() {
    clearInterval(this.timer);
    this.track?.stop();
    try { this.silence?.stop(); } catch { /* already stopped */ }
    this.silence?.disconnect();
    for (const t of this.dest?.stream.getTracks() ?? []) t.stop();
    if (this.ownCtx) this.actx?.close?.().catch?.(() => {});   // (the session's own goes on playing its sound)
    this.player = null;
  }

  /**
   * Given up (its pieces could not reach the gateway): it stops at once and says so with REC_FAILED as the next piece
   * (the gateway, finding one missing, ends the recording whole up to it).
   */
  abandon() {
    if (this.sentLast) return;
    if (this.mr) { this.mr.ondataavailable = this.mr.onstop = this.mr.onerror = null; try { if (this.mr.state !== 'inactive') this.mr.stop(); } catch { /* already stopped */ } }
    this.#release();
    this.done = true;
    this.#queue(new Uint8Array(0), REC_FAILED);
  }

  /** The session ended (the gateway closed the recording): nothing more is sent. */
  dispose() {
    this.done = true;
    this.sentLast = true;
    if (this.mr) { this.mr.ondataavailable = this.mr.onstop = this.mr.onerror = null; try { if (this.mr.state !== 'inactive') this.mr.stop(); } catch { /* already stopped */ } }
    this.#release();
  }
}

/**
 * A recording made in the browser, read back (rdp.php?a=replay: its frames as recorded): its video file, made seekable
 * (WebM: its duration and the cues of its key frames, which MediaRecorder leaves out); MP4 as it is. Rejects with
 * 'recording_damaged' when it was not read to its end (the 'end' padding frame), with what came before in err.blob.
 */
export async function loadPageVideo({ sid, csrf, mime = 'video/webm', signal, onProgress }) {
  const r = await fetch('rdp.php?a=replay', { method: 'POST', signal, cache: 'no-store',
    headers: { 'Content-Type': 'application/json', 'X-CSRF': csrf }, body: JSON.stringify({ sid }) });
  if (!r.ok) {                                           // (why, as the gateway says it: auth or csrf, the web session is over)
    const why = (await r.json().catch(() => null))?.error;
    throw new Error(typeof why === 'string' ? why : r.status === 404 ? 'not_found' : 'recording_damaged');
  }
  const webm = !mime.startsWith('video/mp4');
  const remux = webm ? new WebmRemux() : null;
  const parts = [];                                      // MP4; a WebM's bytes until its tracks were read (one it cannot read: as it is)
  const reader = r.body.getReader();
  let buf = new Uint8Array(0), whole = false, got = 0;
  for (;;) {
    const { value, done } = await reader.read();
    if (done) break;
    got += value.length;
    onProgress?.(got);
    const all = new Uint8Array(buf.length + value.length);
    all.set(buf);
    all.set(value, buf.length);
    let o = 0;
    while (all.length - o >= 9) {                        // [u32 ms][u8 type][u32 length][payload]
      const dv = new DataView(all.buffer, all.byteOffset + o);
      const type = dv.getUint8(4), n = dv.getUint32(5, true);
      if (all.length - o - 9 < n) break;
      const p = all.subarray(o + 9, o + 9 + n);
      if (type === VIDEO) {
        if (!webm || !remux.tracks) parts.push(p.slice());
        if (webm) remux.push(p);
      } else if (type === 0 && n === 3) whole = true;    // the padding frame "end": read to its end
      o += 9 + n;
    }
    buf = all.slice(o);
  }
  const blob = (webm && remux.finish()) || new Blob(parts, { type: webm ? 'video/webm' : 'video/mp4' });
  if (!whole) throw Object.assign(new Error('recording_damaged'), { blob });
  return blob;
}
