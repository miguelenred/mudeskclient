// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// Web Worker: reads the session stream and decodes bitmaps off the main thread, so input stays responsive.
'use strict';

import { bitmapUpdate, paletteUpdate } from './gfx.js';
import { OrderParser } from './orders.js';
import { GfxPipeline } from './rdpgfx.js';
import { CompressedStream } from './bulk.js';

const MAX_FRAME = 1 << 27;                               // 128 MB: twice the largest channel message
const palette = new Uint32Array(256);
let orders = new OrderParser();
let ctrl = null, bpp = 16, diag = 0, received = 0, rate = 0;
// the most pixels of a bitmap (update or cached): the desktop (sent before any update) rounded up to whole 64 x 64 tiles
let maxBitmap = 0;
let bulk = new CompressedStream();
const gfxOut = (op, transfer) => postMessage({ t: 'gfx', op }, transfer);
let gfx = new GfxPipeline(gfxOut);

/**
 * A recording played back (rdp.php?a=replay): frames with their time, [u32 ms][u8 type][u32 length][payload], given at the
 * pace they were recorded (times speed). What comes before `from` is decoded at once (a jump forward), without its sound.
 * The page pauses, plays and changes the speed; the position is told four times a second.
 * Made into a video (exp): as fast as it goes, the page told to take a picture ('cap', its time) before a frame that comes
 * at least STEP after the last one, if something was drawn since: once every decoded picture is drawn there, the page
 * answering ('ack') when it took it. Each frame goes with its time (the sound is placed by it).
 */
const STEP = 1000 / 15;                                  // the video's pictures: 15 a second at most
const play = { on: false, from: 0, speed: 1, paused: false, at: 0, ms: 0, told: 0, whole: false, exp: false, cur: 0,
  dirty: false, drawn: 0, cap: -1, ack: null };
const clock = () => (play.paused || !play.at ? play.ms : play.ms + (performance.now() - play.at) * play.speed);
/** A picture taken by the page: at the time of the last thing drawn, STEP after the one before at least (the first at 0). */
async function capture(ms) {
  await gfx.idle();                                      // the video frames being decoded, drawn there first
  if (ctrl?.signal.aborted) throw new Error('stopped');   // (stopped while waiting for them)
  const at = play.cap < 0 ? 0 : Math.max(play.drawn, play.cap + STEP);
  if (at > ms) return;
  Object.assign(play, { cap: at, dirty: false });
  postMessage({ t: 'cap', ms: at });
  await new Promise((r) => { play.ack = r; });
  if (ctrl?.signal.aborted) throw new Error('stopped');   // (the page stopped it meanwhile)
  if (performance.now() - play.told >= 250) { play.told = performance.now(); postMessage({ t: 'pos', ms: at }); }
}
async function pace(ms) {
  if (play.exp) {
    if (play.dirty && (play.cap < 0 || ms >= play.cap + STEP)) await capture(ms);
    return;
  }
  if (ms < play.from) return;
  if (!play.at && !play.paused) play.at = performance.now();   // the clock starts where asked (play.ms: from, or where it was paused)
  for (;;) {
    if (ctrl?.signal.aborted) throw new Error('stopped');   // the page left: no more waiting
    const now = clock();
    if (performance.now() - play.told >= 250) { play.told = performance.now(); postMessage({ t: 'pos', ms: Math.min(now, ms) }); }
    if (!play.paused && now >= ms) return;
    await new Promise((r) => setTimeout(r, play.paused ? 100 : Math.max(1, Math.min(100, (ms - now) / play.speed))));
  }
}
function playCmd({ cmd, v }) {
  if (cmd === 'ack') { const r = play.ack; play.ack = null; r?.(); return; }
  const now = clock();
  if (cmd === 'pause') Object.assign(play, { ms: now, paused: true });
  else if (cmd === 'play') Object.assign(play, { ms: now, at: performance.now(), paused: false });
  else if (cmd === 'speed') Object.assign(play, { ms: now, at: play.paused ? 0 : performance.now(), speed: v });
}

/** The gateway connected again (a guest joined a shared session): every decoder starts anew, as on a new connection. */
function reset() {
  for (const d of gfx.decoders.values()) {
    try { d.dec.close(); } catch { /* already closed */ }
  }
  const { stats, software } = gfx;
  gfx = new GfxPipeline(gfxOut);
  Object.assign(gfx, { stats, software });
  orders = new OrderParser();
  bulk = new CompressedStream();
  palette.fill(0);
}

/** Diagnostic mode: graphics pipeline counts every 10 s (the gateway only sees the compressed channel). */
function diagTick() {
  if (gfx.stats && Object.keys(gfx.stats).length) postMessage({ t: 'diag', d: { gfx: gfx.stats } });
  gfx.stats = {};
}

function frame(type, d) {
  if (type === 14) reset();                              // (and told to the page, below)
  if (type === 10) {                                     // bulk compression: decompress and reassemble
    const u = bulk.update(d);
    if (u) frame(1, u);
    return;
  }
  if (type === 11) { const u = bulk.slow(d); if (u) frame(1, u); return; }
  if (type === 1 && d[0] === 1) {
    // the bitmaps of an update go to the page together, up to 64 in a message: a message for each one was half of the
    // page's time on them (a console listing in 16 bits: 24,388 messages took the page's thread 371 ms; 385, 210 ms); and
    // about 4 million pixels at most (64 bitmaps of a whole 1080p desktop, from a few hundred bytes, held 506 MB here)
    let rects = [], move = [], pixels = 0;
    const post = () => { if (rects.length) postMessage({ t: 'rects', rects }, move); rects = []; move = []; pixels = 0; };
    try {
      bitmapUpdate(d.subarray(1), palette, (x, y, w, h, px, cw, ch) => {
        rects.push({ x, y, w, h, cw, ch, px });
        move.push(px.buffer);
        if (rects.length === 64 || (pixels += px.length) >= 1 << 22) post();
      }, maxBitmap);
    } catch (e) {
      post();
      postMessage({ t: 'log', msg: 'bitmap: ' + e.message });
    }
    post();
  } else if (type === 1 && d[0] === 0) {
    if (!orders.parse(d.subarray(1), bpp, (cmd) => postMessage({ t: 'op', cmd }, cmd.px ? [cmd.px.buffer] : []), palette, maxBitmap)) {
      postMessage({ t: 'log', msg: 'unsupported drawing order' });
    }
  } else if (type === 9) {
    try {
      gfx.onData(d);
    } catch (e) {
      postMessage({ t: 'log', msg: 'gfx: ' + e.message });
    }
  } else if (type === 1 && d[0] === 2) {
    paletteUpdate(d.subarray(1), palette);
  } else if (type !== 0 && type !== 6) {
    if (type === 2) {
      bpp = d[4];
      maxBitmap = Math.min(1 << 26, (((d[0] | (d[1] << 8)) + 63) & ~63) * (((d[2] | (d[3] << 8)) + 63) & ~63));   // (desktops past that are refused)
    }
    // a recording: its sound left out while what comes before `from` is decoded at once (its formats kept); with its time
    if (play.on && !play.exp && play.cur < play.from && ((type === 7 && d[0] !== 70) || type === 32)) return;
    postMessage(play.on ? { t: 'frame', type, d, ms: play.cur } : { t: 'frame', type, d }, [d.buffer]);
  }
}

async function read(reader) {
  let buf = new Uint8Array(1 << 16), len = 0, last = 0;
  const hs = play.on ? 9 : 5;                            // a recording's frames have their time first
  for (;;) {
    const { value, done } = await reader.read();
    if (done) {
      if (play.on && (!play.whole || len > 0)) throw new Error('recording damaged');   // not read to its end (the gateway's "end")
      if (play.exp && play.dirty) await capture(Infinity);   // the last picture
      if (play.on) postMessage({ t: 'pos', ms: last, done: true });
      return;
    }
    received += value.length;
    if (len + value.length > buf.length) {
      const nb = new Uint8Array(Math.max(buf.length * 2, len + value.length));
      nb.set(buf.subarray(0, len));
      buf = nb;
    }
    buf.set(value, len);
    len += value.length;
    let p = 0;
    while (len - p >= hs) {
      // unsigned (a length read as -5 never moved on: the worker spun for ever) and within the largest frame the gateway
      // sends (a channel message of 64 MB at most): anything else is a broken stream, never a reason to grow the buffer
      const q = p + hs - 5;                              // (the type and the length)
      const n = (buf[q + 1] | (buf[q + 2] << 8) | (buf[q + 3] << 16) | (buf[q + 4] << 24)) >>> 0;
      if (n > MAX_FRAME) throw new Error('stream: frame length ' + n);
      if (len - p < hs + n) break;
      if (play.on && buf[q] !== 0) {                     // (the gateway's "end" has no time of its own)
        last = (buf[p] | (buf[p + 1] << 8) | (buf[p + 2] << 16) | (buf[p + 3] << 24)) >>> 0;
        await pace(last);
        play.cur = last;
        if (![3, 6, 7, 13, 32].includes(buf[q])) Object.assign(play, { dirty: true, drawn: last });   // (not the state, a ping or sound)
      }
      if (play.on && buf[q] === 0 && n === 3 && buf[q + 5] === 101) play.whole = true;   // "end": all of it was read
      frame(buf[q], buf.slice(q + 5, q + 5 + n));
      p += hs + n;
    }
    if (p) {                                             // a frame still arriving stays where it is
      buf.copyWithin(0, p, len);
      len -= p;
    }
  }
}

onmessage = async ({ data }) => {
  if (data.cmd === 'stop') { ctrl?.abort(); play.ack?.(); for (const r of gfx.idlers.splice(0)) r(); clearInterval(diag); clearInterval(rate); return; }
  if (data.cmd === 'diag') { gfx.stats = {}; diag ||= setInterval(diagTick, 10000); return; }
  if (data.cmd) { playCmd(data); return; }
  Object.assign(play, { on: !!data.timed, from: data.from || 0, speed: data.speed || 1, paused: false, at: 0, ms: data.from || 0, told: 0, whole: false,
    exp: !!data.exp, cur: 0, dirty: false, drawn: 0, cap: -1, ack: null });
  if (play.exp) {                                       // a video made: H.264 decoded in software, each picture out at once, where
    gfx.software = await (globalThis.VideoDecoder?.isConfigSupported({ codec: 'avc1.42e01f', hardwareAcceleration: 'prefer-software' })   // the browser has it
      .then((r) => !!r.supported, () => false) ?? false);
  }
  ctrl = new AbortController();
  rate ||= setInterval(() => postMessage({ t: 'bytes', n: received, at: Date.now() }), 1000);   // for the quality indicator
  let err = null, end, reason;
  try {
    const res = await fetch(data.url, { method: 'POST', signal: ctrl.signal, cache: 'no-store', headers: data.headers, body: data.body });
    if (!res.ok || !res.body) {
      ({ error: end, reason } = await res.json().catch(() => ({})));   // the gateway's refusal: 'auth', 'csrf', 'pending'... (and why)
      throw new Error('http ' + res.status);
    }
    await read(res.body.getReader());
  } catch (e) {
    err = String(e.message || e);
    if (err === 'recording damaged') end = 'recording_damaged';
    ctrl.abort();                                        // a broken stream: the gateway stops writing it
  }
  postMessage({ t: 'end', err, end, reason });
};
