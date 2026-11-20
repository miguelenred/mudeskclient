// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// Web Worker: reads the session stream and decodes bitmaps off the main thread, so input stays responsive.
'use strict';

import { bitmapUpdate, paletteUpdate } from './gfx.js';
import { OrderParser } from './orders.js';
import { GfxPipeline } from './rdpgfx.js';
import { CompressedStream } from './bulk.js';

const MAX_FRAME = 1 << 27;                               // 128 MB: twice the largest channel message
const palette = new Uint32Array(256);
const orders = new OrderParser();
let ctrl = null, bpp = 16, diag = 0, received = 0, rate = 0;
// the most pixels of a bitmap (update or cached): the desktop (sent before any update) rounded up to whole 64 x 64 tiles
let maxBitmap = 0;
const bulk = new CompressedStream();
const gfx = new GfxPipeline((op, transfer) => postMessage({ t: 'gfx', op }, transfer));

/** Diagnostic mode: graphics pipeline counts every 10 s (the gateway only sees the compressed channel). */
function diagTick() {
  if (gfx.stats && Object.keys(gfx.stats).length) postMessage({ t: 'diag', d: { gfx: gfx.stats } });
  gfx.stats = {};
}

function frame(type, d) {
  if (type === 10) {                                     // bulk compression: decompress and reassemble
    const u = bulk.update(d);
    if (u) frame(1, u);
    return;
  }
  if (type === 11) { const u = bulk.slow(d); if (u) frame(1, u); return; }
  if (type === 1 && d[0] === 1) {
    try {
      bitmapUpdate(d.subarray(1), palette, (x, y, w, h, px, cw, ch) => postMessage({ t: 'rect', x, y, w, h, cw, ch, px }, [px.buffer]), maxBitmap);
    } catch (e) {
      postMessage({ t: 'log', msg: 'bitmap: ' + e.message });
    }
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
    postMessage({ t: 'frame', type, d }, [d.buffer]);
  }
}

async function read(reader) {
  let buf = new Uint8Array(1 << 16), len = 0;
  for (;;) {
    const { value, done } = await reader.read();
    if (done) return;
    received += value.length;
    if (len + value.length > buf.length) {
      const nb = new Uint8Array(Math.max(buf.length * 2, len + value.length));
      nb.set(buf.subarray(0, len));
      buf = nb;
    }
    buf.set(value, len);
    len += value.length;
    let p = 0;
    while (len - p >= 5) {
      // unsigned (a length read as -5 never moved on: the worker spun for ever) and within the largest frame the gateway
      // sends (a channel message of 64 MB at most): anything else is a broken stream, never a reason to grow the buffer
      const n = (buf[p + 1] | (buf[p + 2] << 8) | (buf[p + 3] << 16) | (buf[p + 4] << 24)) >>> 0;
      if (n > MAX_FRAME) throw new Error('stream: frame length ' + n);
      if (len - p < 5 + n) break;
      frame(buf[p], buf.slice(p + 5, p + 5 + n));
      p += 5 + n;
    }
    if (p) {                                             // a frame still arriving stays where it is
      buf.copyWithin(0, p, len);
      len -= p;
    }
  }
}

onmessage = async ({ data }) => {
  if (data.cmd === 'stop') { ctrl?.abort(); clearInterval(diag); clearInterval(rate); return; }
  if (data.cmd === 'diag') { gfx.stats = {}; diag ||= setInterval(diagTick, 10000); return; }
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
    ctrl.abort();                                        // a broken stream: the gateway stops writing it
  }
  postMessage({ t: 'end', err, end, reason });
};
