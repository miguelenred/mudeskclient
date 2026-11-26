// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// RDP session in the browser: receives frames from rdp.php, draws them on a canvas and sends input back.
'use strict';

import { isTextCursor, pointerDecode } from './gfx.js';
import { SCANCODES, ev, typedText } from './keymap.js';
import { GfxSurfaces } from './gfxsurfaces.js';
import { drawPattern, drawRop, drawText, glyphPath } from './orders.js';
import { PageRecorder, REC } from './pagerec.js';

const FATAL = new Set(['app_not_opened', 'auth_failed', 'password_expired', 'account_locked', 'account_disabled', 'account_restricted',
  'account_expired', 'logon_not_granted', 'target_denied', 'too_many_sessions', 'cert_changed', 'cert_new', 'cert_unexpected', 'nla_required',
  'nla_unavailable', 'rdp_security', 'rdp_unencrypted', 'tls_required', 'license', 'license_rds', 'user', 'killed', 'idle', 'invalid_request',
  'redirect', 'routing_token', 'gateway_auth', 'gateway_denied', 'gateway_not_allowed', 'gateway_unsupported', 'gateway_consent', 'gateway_unreachable', 'mitm', 'unreachable', 'auth', 'csrf', 'pending', 'signed_out', 'app_not_allowed', 'app_not_found', 'app_failed', 'app_locked',
  'app_unsupported', 'record_failed', 'outside_hours']);
// while reconnecting: the lost session may still count for a minute on the gateway, the computer may be restarting
const RETRY_ON_RECONNECT = new Set(['too_many_sessions', 'unreachable', 'gateway_unreachable']);
const NO_RECONNECT_ERRORINFO = new Set([0x1, 0x2, 0x3, 0x4, 0x5, 0x6, 0x7, 0x9, 0xa, 0xb, 0xc]);
// H.264 decoding in this browser (WebCodecs): the server only gets AVC420 offered when it works
const AVC = typeof VideoDecoder === 'undefined' ? Promise.resolve(false)
  : VideoDecoder.isConfigSupported({ codec: 'avc1.42e01f', optimizeForLatency: true }).then((r) => !!r.supported, () => false);
const BUTTONS = [0x1000, 0x4000, 0x2000];               // left, middle, right
const BUTTON_BITS = [1, 4, 2, 8, 16];                    // PointerEvent.button -> its bit in PointerEvent.buttons
const MODIFIER = /^(Control|Alt|Shift|Meta|OS)/;
// phones and tablets without a mouse or touchpad (the same test as app.js)
const touchOnly = () => matchMedia('(pointer: coarse)').matches && !matchMedia('(any-pointer: fine)').matches;
/**
 * Several monitors resized to their windows: the same arrangement with the new sizes. Side by side (a row, as screens
 * usually are) or one above the other (a column), touching and aligned; the primary stays at 0,0. Null for any other
 * arrangement (then the monitors keep their size).
 */
export function arrange(mons, room) {
  const order = (k, s) => [...mons.keys()].sort((a, b) => mons[a][k] - mons[b][k]).every((i, n, o) => n === 0 || mons[i][k] >= mons[o[n - 1]][k] + mons[o[n - 1]][s] - 1)
    && [...mons.keys()].sort((a, b) => mons[a][k] - mons[b][k]);
  const row = order('vx', 'w'), col = !row && order('vy', 'h');
  if (!row && !col) return null;
  const next = mons.map((m, i) => ({ ...m, w: room[i].w, h: room[i].h, vx: 0, vy: 0 }));
  let at = 0;
  for (const i of row || col) {
    next[i][row ? 'vx' : 'vy'] = at;
    at += row ? next[i].w : next[i].h;
  }
  const p = next.find((m) => m.primary) || next[0], [dx, dy] = [p.vx, p.vy];
  return next.map((m) => ({ ...m, vx: m.vx - dx, vy: m.vy - dy }));
}

/** Where a canvas is on the screen: its window's viewport (Firefox gives it; elsewhere from the window frame) plus its box. */
function screenBox(c) {
  const win = c.ownerDocument?.defaultView || window, r = c.getBoundingClientRect();
  const x = win.mozInnerScreenX ?? win.screenX + (win.outerWidth - win.innerWidth) / 2;
  const y = win.mozInnerScreenY ?? win.screenY + (win.outerHeight - win.innerHeight);
  return { left: x + r.left, top: y + r.top, width: r.width, height: r.height };
}

/** CSS size of a canvas shown whole in a w x h box, in proportion (letterboxed); 1:1 when within 2 %. */
function contain(c, w, h) {
  const s = Math.min(1e3, w / c.width, h / c.height);
  const scale = Math.abs(s - 1) < 0.02 ? 1 : s;
  c.style.width = Math.floor(c.width * scale) + 'px';
  c.style.height = Math.floor(c.height * scale) + 'px';
}
const MSG = { INPUT: 1, RESIZE: 2, VISIBLE: 3, CLIP: 4, QUIT: 5, REFRESH: 6, TEXT: 7, PASTE: 8, GFX_ACK: 10, DIAG: 11, CLIP_PASTE: 12, MONITORS: 13, RAIL: 14, MIC: 15, TOUCH: 16, GW_CONSENT: 17,
  CAM: 18, FOLDER: 19, FOLDER_IO: 20, FOLDER_LIST: 21, REC };
const LANE = 0xFFFFFFFF;                                 // out of order (Sessions::OUT_OF_BAND): camera pictures, the folder's answers, the recording's video
const GUEST_MSG = new Set([MSG.INPUT, MSG.TEXT, MSG.PASTE, MSG.TOUCH]);   // what a guest given control sends (Sessions::GUEST_TYPES)
const SC = { minimize: 0xF020, maximize: 0xF030, restore: 0xF120, close: 0xF060 };   // RemoteApp window commands (SC_*)
const DIAG_MAX = 300;                                    // browser notes per session in diagnostic mode
const REC_BACKLOG = 32 << 20;                            // bytes of a recording made in the page waiting to go, at most
const REC_TRIES = 10;                                    // times a piece of it is sent (again with each next piece, about a second apart)
const INPUT_RETRY = [50, 100, 200, 400, 800];            // ms before each new try of an input request that did not get there (1.55 s in all)
const REFUSED = new Set([429, 503, 508]);                // answered without being run (a hosting's limit of processes at once, its own rate limit)
const REFUSED_QUIET = 5000;                              // ms after the last of those with one input request at a time
const PASTE_CAP = 2500;                                  // ms a paste holds the keys typed after it, at most (see the paste event)

export class RdpSession {
  /**
   * @param {object} o {container, canvas, keyboard (textarea), conn, csrf, retries (automatic reconnections), onState(state, info)};
   *   guest: {link} to watch a session shared with a link (view only, or with control when the owner gave it);
   *   replay: {sid, from (ms), speed} to play a session's recording (administrators and auditors); with video: true, read as
   *   fast as it goes to make a video of it (see recvideo.js: onCapture(ms) takes each picture, the sound comes with its time);
   *   ws: the WebSocket service's path when it answered (see tools/ws-server.php): the session's stream and input go through
   *   it, everything else as always (the worker falls back to HTTP when it does not open)
   */
  constructor(o) {
    Object.assign(this, o);
    this.ctx = this.canvas.getContext('2d', { alpha: !!this.conn.app });
    this.surface = this.canvas;                         // remote desktop pixels (an offscreen canvas with several monitors)
    this.sctx = this.ctx;
    // RemoteApp: the remote desktop is drawn off screen and only the visible parts of the program's windows are copied here,
    // over the page (the rest of that desktop is not painted by the server: what is left there is stale)
    // goneMs: how long without any window of the program before the session ends (a program may close one window and
    // open the next a moment later: its sign-in, then its main window)
    // slowMs: asked for, how long before the page says it is taking longer than usual; giveUpMs: how long before it says the
    // program did not open (meanwhile, a session the server ends for having no program is connected again, quietly)
    this.rail = this.conn.app ? { wins: new Map(), active: 0, rects: [], soon: 0, seen: false, closing: 0, goneMs: 3000, slowMs: 15000,
      giveUpMs: 90000 } : null;
    if (this.rail) {
      this.surface = document.createElement('canvas');
      this.sctx = this.surface.getContext('2d', { alpha: false });
      this.conn = { ...this.conn, resize: 'dynamic' };     // its desktop is this window: maximized programs fill it
    }
    this.views = [{ canvas: this.canvas, keyboard: this.keyboard, x: 0, y: 0 }];
    this.multi = false;
    this.pointers = new Map();
    // pixel-by-pixel ROP drawing's budget (#ropOk): its second and the pixels drawn in it. Before, unset: in a page less than
    // a second old the sum was NaN and every such order was left out
    this.ropAt = -Infinity;
    this.ropPx = 0;
    this.counts = { frames: 0, bytes: 0, bytesAt: 0, rtt: 0 };   // quality indicator: painted frames, bytes received (and when), input latency (ms)
    this.tap = { at: 0, x: -1, y: -1, keyboard: null };   // last touch tap: the text cursor after it opens the keyboard
    this.touchOn = false;                                // multitouch to Windows (the gateway's {"touch": true})
    this.contacts = new Map();                           // pointerId -> {id, x, y, state, after}
    this.down = new Set();
    this.events = [];
    this.move = null;
    this.outbox = [];
    this.inflight = 0;
    this.keepalive = 0;                                  // bytes of keepalive requests in flight
    this.refusedAt = -Infinity;                          // when the hosting last refused an input request (performance.now)
    this.inputSeen = false;                              // set on every input; the page clears it (keeps the web session alive)
    this.disp = false;
    this.attempt = 0;
    this.wheel = { x: 0, y: 0 };
    this.locks = -1;
    this.pasting = false;
    this.deferred = [];
    this.sticky = new Set();
    this.composing = null;                               // IME composition on a computer: 'text', or 'keys' after a dead key
    this.dead = false;                                   // the last key sent was a dead key
    this.zoom = { z: 1, x: 0, y: 0 };
    this.clipboard = null;                               // set by the clipboard bridge
    this.audio = null;                                   // AudioPlayer when audio is enabled
    this.mic = null;                                     // MicCapture when the microphone is offered
    this.cam = null;                                     // CamCapture when the camera is offered (with the microphone)
    this.folder = null;                                  // LocalFolder: a folder of this computer shared in the session
    this.lane = { busy: 0, cam: null, folder: [], rec: [], recOut: false, recWait: false };   // the out-of-order lane: what waits to go, how many requests are out
    this.recorder = null;                                // a recording started in the session, made in this page (pagerec.js)
    this.#bindPointer(this.canvas, this.views[0]);
    this.#bindKeyboard(this.keyboard);
    this.#bindWindow();
  }

  // ------------------------------------------------------------ connection

  start(extra = {}) {
    this.extra = extra;
    this.stopped = false;
    this.appClosed = false;
    Object.assign(this, { quiet: false, railSince: 0, appNotOpened: false });   // RemoteApp: a new wait for its program
    this.attempt = 0;
    AVC.then((avc) => { this.avc = avc; this.#run(); });
  }

  stop() {
    this.stopped = true;
    this.recorder?.dispose();                            // (the gateway closes its recording with the session)
    this.recorder = null;
    this.mic?.stop();
    this.cam?.stop();
    if (!this.worker && this.again) {                   // waiting to connect again: no stream to end, so it ends here (before,
      clearTimeout(this.again);                          // the page kept saying "Reconnecting" for ever)
      this.again = 0;
      this.ended = { end: 'user' };
      this.#finish();
      return;
    }
    if (this.sid) this.#post([MSG.QUIT]);
    // a worker that never ends (busy in a long decode a hostile server asked for: it never reads the 'stop') is ended by force
    // after 3 s. Before, Disconnect did nothing until the page was closed
    const wk = this.worker;
    setTimeout(() => {
      if (!wk || wk !== this.worker || this.ended) return;
      wk.terminate();
      this.worker = null;
      this.sid = this.token = null;
      this.ended = { end: 'user' };
      this.#finish();
    }, 3000);
    if (this.replay || this.guest) { this.worker?.postMessage({ cmd: 'stop' }); return; }   // (nothing of theirs to say goodbye with)
    setTimeout(() => this.worker?.postMessage({ cmd: 'stop' }), 300);
  }

  /** The page is done with this session: its window listeners go, and its worker (no more memory held by the tab). */
  dispose() {
    this.recorder?.dispose();                            // (the page left the session: nothing more drawn, recorded or sent)
    this.recorder = null;
    this.leaveWindow?.abort();
    this.resizeObs?.disconnect();
    this.audio?.close?.();
    if (this.stopped) { this.worker?.terminate(); this.worker = null; }
  }

  viewSize() {
    const r = this.container.getBoundingClientRect();
    return { w: Math.max(200, Math.min(8192, Math.floor(r.width) & ~1)), h: Math.max(200, Math.min(8192, Math.floor(r.height))) };
  }

  #run() {
    const sid = [...crypto.getRandomValues(new Uint8Array(16))].map((b) => b.toString(16).padStart(2, '0')).join('');
    this.nextMons = null;
    this.pendingLayout = false;
    if (this.multi) this.#setLayout(this.#wanted());     // the screens whose window is open now
    const one = this.multi && this.mons.length === 1 ? this.mons[0] : null;   // only this window's: a plain one-monitor session
    const { w, h } = one ? { w: one.w, h: one.h } : this.viewSize();
    // (gen: requests of the connection before still in flight no longer count; locks: synced again on the next key; down,
    // autoShift, cmd: a new connection's computer has no key held, so a Shift still held is pressed there again by the next
    // key's own state, see #syncShift: kept, Shift+Arrow went as Arrow)
    Object.assign(this, { sid: null, token: null, viaWs: false, seq: 0, inflight: 0, gen: (this.gen || 0) + 1, outbox: [], events: [], move: null, ack: null, ackOwed: false,
      locks: -1, inView: true, disp: false, ended: null, again: 0, down: new Set(), autoShift: false, cmd: null, bcache: new Map(), gcache: new Map(),
      lane: { busy: 0, cam: null, folder: [], rec: [], recOut: false, recWait: false } });
    if (this.rail) {                                     // the server sends its windows again
      clearTimeout(this.rail.closing);
      Object.assign(this.rail, { active: 0, ready: false, seen: false, closing: 0, asked: 0 });
      this.rail.wins.clear();
    }
    this.resumed = !!(this.rail && this.hadApp);
    this.#newGfx();
    this.pendingSid = sid;
    this.onState(this.attempt ? 'reconnecting' : 'connecting', { attempt: this.attempt, quiet: this.quiet || undefined });
    this.worker?.terminate();
    const wk = this.worker = new Worker(new URL('./stream-worker.js', import.meta.url), { type: 'module' });
    let spoke = false;                                   // (whether it ever said anything: see onerror)
    wk.onmessage = ({ data: m }) => {
      if (wk !== this.worker) { m.op?.frame?.close?.(); return; }   // (a video frame of a worker replaced: freed)
      spoke = true;
      if (m.t === 'rects') {                             // bitmaps of an update
        for (const r of m.rects) {
          if (r.w && r.h) {                              // a bitmap of no size draws nothing (ImageData of 0 threw)
            this.sctx.putImageData(new ImageData(new Uint8ClampedArray(r.px.buffer), r.w, r.h), r.x, r.y, 0, 0, r.cw, r.ch);
            this.#dirty(r.x, r.y, r.cw, r.ch);
          }
        }
      }
      else if (m.t === 'op') this.#order(m.cmd);
      else if (m.t === 'gfx') this.gfx.apply(m.op);
      else if (m.t === 'frame') this.#frame(m.type, m.d, m.ms);
      else if (m.t === 'cap') {                          // a recording made into a video: its picture now, then the next frames
        Promise.resolve(this.onCapture?.(m.ms)).then(() => { if (wk === this.worker) wk.postMessage({ cmd: 'ack' }); }, () => this.stop());
      }
      else if (m.t === 'log') this.#log(m.msg);
      else if (m.t === 'diag') this.diag(m.d);
      else if (m.t === 'bytes') Object.assign(this.counts, { bytes: m.n, bytesAt: m.at });
      else if (m.t === 'pos') this.onReplayPos?.(m.ms, !!m.done);
      else if (m.t === 'transport') {                    // the stream came through the WebSocket service: the input goes back the same
        this.viaWs = !!m.ws;                             // way, with no answer to time, so its latency is not known (null: not 0 ms)
        if (this.viaWs) this.counts.rtt = null;
      }
      else if (m.t === 'end') {
        wk.terminate();
        this.worker = null;
        this.sid = this.token = null;
        if (!this.ended) this.ended = { end: m.end || (this.stopped ? 'user' : 'network'), reason: m.reason };   // m.end: the gateway's refusal ('auth', 'csrf'...)
        this.#finish();
      }
    };
    // a worker that could not start (its script not loaded: the network down just as a connection was tried again, or an
    // update half there) ends as a dropped connection, tried again as one: it never said anything, and the page stayed on
    // "Reconnecting" for good
    wk.onerror = (ev) => {
      if (wk !== this.worker || spoke) { if (spoke) this.#log(`worker: ${ev.message || 'error'}`); return; }   // (one running goes on)
      ev.preventDefault?.();
      wk.terminate();
      this.worker = null;
      this.sid = this.token = null;
      if (!this.ended) this.ended = { end: this.stopped ? 'user' : 'network' };
      this.#finish();
    };
    if (this.replay) {                                   // a recording: its frames at their pace, nothing sent
      wk.postMessage({ url: new URL('rdp.php?a=replay', location.href).href, headers: { 'Content-Type': 'application/json', 'X-CSRF': this.csrf },
        body: JSON.stringify({ sid: this.replay.sid }), timed: true, from: this.replay.from || 0, speed: this.replay.speed || 1, exp: !!this.replay.video });
      return;
    }
    if (this.guest) {                                    // a shared session: its stream from the owner's, through the link
      wk.postMessage({ url: new URL('rdp.php?a=watch', location.href).href, headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ link: this.guest.link }) });
      return;
    }
    wk.postMessage({
      url: new URL('rdp.php?a=stream', location.href).href,
      ws: this.ws ? new URL(this.ws, location.href).href.replace(/^http/, 'ws') : undefined,
      headers: { 'Content-Type': 'application/json', 'X-CSRF': this.csrf },
      body: JSON.stringify({ c: this.conn.id, sid, w, h, tz: new Date().getTimezoneOffset(), lang: document.documentElement?.lang, avc: this.avc, diag: this.#browserInfo(), ...this.extra, mons: this.multi && !one ? this.mons.map((m) => [m.vx, m.vy, m.w, m.h, m.primary ? 1 : 0]) : undefined,
        // RemoteApp: again, reconnected after the connection dropped; resume, the program was open in this page: either way
        // it is started only if its windows do not come back (no second one)
        again: this.rail && this.connectedOnce ? true : undefined, resume: this.resumed || undefined }),
    });
  }

  /** The graphics pipeline's surfaces and cache, new (a new connection, or the same stream connected again). */
  #newGfx() {
    const g = this.gfx = new GfxSurfaces({
      out: () => ({ ctx: this.sctx }),
      dirty: (x, y, w, h) => this.#dirty(x, y, w, h),
      reset: (w, h) => this.#resize(w, h),
      ack: (frame, decoded) => {                 // only the latest acknowledgement is kept: it covers the earlier frames
        if (this.stopped || this.guest || g !== this.gfx) return;   // (a guest's page does not pace the server)
        const m = new Uint8Array(9);
        const dv = new DataView(m.buffer);
        m[0] = MSG.GFX_ACK;
        dv.setUint32(1, frame, true);
        dv.setUint32(5, decoded, true);
        this.ack = m;
        this.#pump();
      },
      log: (msg) => this.#log(msg),
      note: (msg) => this.diag({ note: msg }),
      refresh: () => { if (g === this.gfx) this.refresh(); },   // (a video decoder lost or behind: a whole picture asked for)
    });
  }

  #finish() {
    if (this.recorder) {                                 // the gateway closed its recording with the session (a new one, connected
      this.recorder.dispose();                           // again, is not recorded unless started again): no longer said
      this.recorder = null;
      this.onRecording?.(false, null, null);
    }
    this.lane.rec = [];
    this.mic?.stop();                                    // a new connection opens it again if a program there records
    this.cam?.stop();                                    // ... or uses the camera
    this.folder?.reset();                                // its open files went with the connection (the folder stays chosen)
    this.lane.cam = null;
    this.lane.folder = [];
    // the program closed: said so, and the next connection is a new one (the program is started again there)
    let e = this.appClosed ? { end: 'app_closed' } : this.appNotOpened ? { end: 'app_not_opened' } : this.ended || { end: 'network' };
    if (this.appClosed) this.connectedOnce = false;
    if (e.end === 'resize' && !this.stopped) { this.#run(); return; }
    if (this.rail) { clearTimeout(this.rail.later); clearTimeout(this.rail.giveUp); }
    // RemoteApp: the server ended the session before the program's window came (error info 0x0B: no program there, some 12 s
    // after it synced) while it was still opening, so a program that takes long was cut off: "Disconnected" came right
    // after the page offered to open it. Connected again quietly, the spinner kept: its windows come back, or it is asked for
    // again; until giveUpMs after it was first asked for, then said: it did not open.
    if (this.rail && !this.stopped && !this.rail.seen && this.railSince && e.errorInfo === 0xb) {
      if (Date.now() - this.railSince < this.rail.giveUpMs) {
        this.quiet = true;
        this.onRail?.(this.railWindows());
        this.again = setTimeout(() => !this.stopped && this.#run(), 300);
        return;
      }
      e = { end: 'app_not_opened' };
    }
    this.quiet = false;
    const fatal = this.stopped || (FATAL.has(e.end) && !(this.connectedOnce && RETRY_ON_RECONNECT.has(e.end)))
      || NO_RECONNECT_ERRORINFO.has(e.errorInfo);
    if (!fatal && this.connectedOnce && this.attempt < this.retries) {   // waits 1, 2, 3, 5, 8, then 10 s (see below)
      this.attempt++;
      this.onState('reconnecting', { attempt: this.attempt, ...e });
      // after the first retry, up to half as long again at random: after the gateway restarts, every page tried again at
      // the same moments and most found it busy
      const wait = [1000, 2000, 3000, 5000, 8000][this.attempt - 1] ?? 10000;
      this.again = setTimeout(() => !this.stopped && this.#run(), this.attempt > 1 ? wait * (1 + Math.random() / 2) : wait);   // (see stop)
      return;
    }
    this.onState('ended', e);
  }

  /** A frame of the stream (ms: its time in a recording). */
  #frame(type, d, ms) {
    switch (type) {
      case 1: this.#update(d[0], d.subarray(1)); break;
      case 2: this.#resize(d[0] | (d[1] << 8), d[2] | (d[3] << 8)); break;
      case 3: this.#status(JSON.parse(new TextDecoder().decode(d))); break;
      case 4: this.clipboard?.onMessage(d); break;
      case 5: this.disp = true; this.#checkSize(); break;
      case 7:
        if (d[0] === 70) this.soundFormats = d;          // (its formats: a recording made in this page later needs them)
        this.audio?.onMessage(d, ms);
        this.recorder?.onSound(d);
        break;
      case 13:
        this.micFormatMsg = d;
        this.mic?.onMessage(d);
        if (this.replay) this.audio?.micFormat?.(d);
        this.recorder?.micFormat(d);
        break;
      case 32: this.audio?.micData?.(d, ms); break;    // a recording: what the user's microphone sent (Recording::MIC)
      case 16: this.cam?.onMessage(d); break;
      case 17: this.folder?.onRequest(d); break;
      case 14:                                           // connected again for a guest: everything is drawn anew
        this.#newGfx();
        Object.assign(this, { bcache: new Map(), gcache: new Map(), ack: null });
        this.cam?.stop();                                // (the camera and its pictures of before: asked for again if still used)
        this.folder?.reset();                            // (the folder announced again by the gateway: its files opened anew)
        Object.assign(this.lane, { cam: null, folder: [] });   // (answers to the connection before: no longer waited for)
        break;
      case 15: this.#ownerPointer(d[0] | (d[1] << 8), d[2] | (d[3] << 8)); break;
      case 8: this.onPrint?.(JSON.parse(new TextDecoder().decode(d))); break;
      case 12: {                                         // RemoteApp: a window cloaked or shown again
        const m = JSON.parse(new TextDecoder().decode(d)), w = this.rail?.wins.get(m.cloak);
        if (w) this.#railOrder({ op: 'window', id: m.cloak, cloaked: !!m.on });
        break;
      }
    }
  }

  #status(s) {
    if (s.token) {
      this.token = s.token;
      this.sid = this.pendingSid;
      if (this.viaWs) this.worker?.postMessage({ cmd: 'token', token: s.token });   // (the service takes the input with it)
      this.#pump();
      this.#lanePump();
      if (this.pendingLayout) { this.pendingLayout = false; this.#sizeSoon(); }   // a window opened, closed or resized while connecting
    }
    if (s.trace) { this.diagLeft = DIAG_MAX; this.worker?.postMessage({ cmd: 'diag' }); }
    if (s.waking) this.onState('waking', s);           // Wake-on-LAN: the computer did not answer and is being turned on
    if (s.watch && this.guest) {                       // a guest: watching (with control, its input goes with the link's secret)
      this.guest.mode = s.watch;
      this.sid = s.sid;
      this.token = s.watch === 'control' ? this.guest.link.split('.')[1] : null;
      this.connectedOnce = true;
      this.onState('connected', s);
    }
    if ('gateway_message' in s && !this.replay && !this.guest) {   // the Remote Desktop Gateway's message: one to accept
      const consent = !!s.consent;                     // before connecting (its answer sent), or one to read
      Promise.resolve(this.onGatewayMessage?.(String(s.gateway_message), consent)).then((ok) => consent && this.#post([MSG.GW_CONSENT, ok === true ? 1 : 0]));
    }
    if ('viewers' in s) this.onViewers?.(s.viewers);   // the owner: how many guests watch
    if (s.local_folder === 'refused') this.folder?.refused();   // no shared folder in this session (or the gateway's policy)
    // recorded (by the connection, an administrator, or its user): said; who started it ('policy', 'admin', 'owner'); a
    // recording started in the session that could not be written
    if ('recording' in s && !this.replay) this.onRecording?.(!!s.recording, s.record_by || null, s.record_error || null);
    // one started in the session: made in this page, the gateway's number for it in record_video; stopped, the rest sent
    if ('recording' in s && !this.replay && !this.guest) {
      if (s.recording && s.record_video && this.recorder?.gen !== s.record_video) {
        this.recorder?.stop();
        this.lane.rec = [];                              // (the gateway closed the one before: its pieces no longer taken)
        this.recorder = new PageRecorder({ session: this, gen: s.record_video, send: (m) => this.#sendRec(m), sound: !!(this.audio || this.mic) });
        this.recorder.start();
      } else if (!s.recording) {
        this.recorder?.stop();
        this.recorder = null;
      }
    }
    if ('touch' in s) {                                // multitouch ready (or suspended, or gone): the fingers go as touch
      this.touchOn = !!s.touch && navigator.maxTouchPoints > 0 && !this.replay && !(this.guest && this.guest.mode !== 'control');
      if (!this.touchOn) this.contacts.clear();
    }
    if (s.connected) {
      this.attempt = 0;
      this.connectedOnce = true;
      this.onState('connected', s);
      this.inView = this.#seen();                        // (kept: connected while hidden, coming back said it; it was not)
      this.#post([MSG.VISIBLE, this.inView ? 1 : 0]);
      if (this.folder?.shared) this.shareFolder(this.folder.name, this.folder.readonly);   // connected again: shared again
    }
    if (s.errorInfo) this.lastErrorInfo = s.errorInfo;
    if (s.end) this.ended = { ...s, errorInfo: s.errorInfo || this.lastErrorInfo || 0 };
  }

  // ------------------------------------------------------------ graphics

  #resize(w, h) {
    if (!w || !h || w > 32766 || h > 32766 || w * h > 1 << 26) return;   // the gateway refuses them too (Client::demandActive)
    const n = this.nextMons;                             // several monitors resized: the new layout, as the server took it
    if (this.multi && n && Math.max(...n.map((m) => m.vx + m.w)) - Math.min(...n.map((m) => m.vx)) === w
      && Math.max(...n.map((m) => m.vy + m.h)) - Math.min(...n.map((m) => m.vy)) === h) {
      this.nextMons = null;
      this.#setLayout(n);
      return;
    }
    if (this.surface.width !== w || this.surface.height !== h) {
      // RemoteApp: what the desktop had is kept (a new size empties a canvas). The server does not repaint its windows after
      // a resize: it moves them copying from there (with it gone, the window came back in mixed pieces).
      let keep = null;
      if (this.rail && this.surface.width && this.surface.height) {
        keep = document.createElement('canvas');
        keep.width = this.surface.width;
        keep.height = this.surface.height;
        keep.getContext('2d').drawImage(this.surface, 0, 0);
      }
      this.surface.width = w;
      this.surface.height = h;
      this.sctx.fillStyle = '#000';
      this.sctx.fillRect(0, 0, w, h);
      if (keep) this.sctx.drawImage(keep, 0, 0);
    }
    if (this.rail) {
      if (!this.multi) {                                 // (several screens: each window's canvas is its monitor, see #setLayout)
        this.canvas.width = w;
        this.canvas.height = h;
      }
      this.#railPaint();
      clearTimeout(this.rail.refresh);                    // and then all of it drawn again by the server (Refresh Rect)
      this.rail.refresh = setTimeout(() => { if (this.sid) this.refresh(); }, 400);
    }
    this.fit();
  }

  /**
   * Several screens: screens = [{vx, vy, w, h, primary, sc}] of this computer (primary at 0,0); main = the one this window is
   * on. The remote desktop has a monitor only for the screens whose window is open (this one, and each opened with addView):
   * applications cannot end up on a screen nobody sees. Opening or closing a window adds or removes its monitor (Display
   * Control, or a reconnection without it). Before, every screen was a remote monitor from the start, its window open or not.
   * mons: the remote monitors, [{i (screen), vx, vy, w, h, primary, x, y (on the surface)}].
   */
  setMonitors(screens, main) {
    this.screens = screens;
    this.multi = true;
    this.surface = document.createElement('canvas');
    this.sctx = this.surface.getContext('2d', { alpha: false });
    Object.assign(this.views[0], { mon: main, ctx: this.ctx });
    this.#setLayout(this.#wanted());
  }

  /** Shows screen mon's monitor in another window's canvas (multi-monitor): that monitor is added to the remote desktop. */
  addView(canvas, keyboard, mon) {
    // RemoteApp: transparent, only the program's windows on it (over that window's page)
    const v = { canvas, keyboard, x: null, y: null, mon, ctx: canvas.getContext('2d', { alpha: !!this.rail }) };
    const m = this.mons.find((x) => x.i === mon) || this.screens[mon];
    canvas.width = m.w;                                  // black until the server takes its monitor
    canvas.height = m.h;
    if (m.x !== undefined) {
      Object.assign(v, { x: m.x, y: m.y });
      if (!this.rail) v.ctx.drawImage(this.surface, m.x, m.y, m.w, m.h, 0, 0, m.w, m.h);
    }
    const win = canvas.ownerDocument.defaultView;
    v.fit = () => contain(canvas, win.innerWidth, win.innerHeight);
    v.fit();                                             // as the main window: whole and in proportion, also before full screen
    win.addEventListener('resize', () => { v.fit(); this.#sizeSoon(); });   // and its monitor, the size of the window
    if (canvas.ownerDocument !== document) canvas.ownerDocument.addEventListener?.('visibilitychange', () => this.#visibility());
    this.views.push(v);
    this.#bindPointer(canvas, v);
    this.#bindKeyboard(keyboard);
    if (this.rail) this.#railPaint();
    this.#viewsChanged();
  }

  /** A window of another screen closed: its monitor leaves the remote desktop (Windows brings its windows to the others). */
  removeView(canvas) {
    this.views = this.views.filter((v) => v.canvas !== canvas);
    this.#viewsChanged();
    this.#visibility();
  }

  /**
   * Tells the server whether any window of the session is seen (it stops drawing while none is): only this window's
   * visibility was sent, so hiding it froze the other screens' windows still in view. Said only when it changes.
   */
  #visibility() {
    // what waits for a frame of a window just hidden: asked again from one in view (the first to come draws it)
    if (this.dirtyRect) this.#onFrame(() => this.#copyDirty());
    if (this.rail?.soon) this.#onFrame(() => this.#railSoon());
    const seen = this.#seen();
    if (seen === this.inView) return;
    this.inView = seen;
    this.#post([MSG.VISIBLE, seen ? 1 : 0]);
    if (seen) this.refresh();
  }

  /** Whether any window of the session is seen (this page's, or another screen's). */
  #seen() {
    return [document, ...this.views.map((v) => v.canvas.ownerDocument || document)].some((d) => !d.hidden);
  }

  #viewsChanged() {
    if (!this.multi) return;
    if (this.sid) this.#checkSize();                     // connected: the server is asked at once
    else if (this.worker) this.pendingLayout = true;     // connecting (the request went with the monitors as they were): once it is
    else this.#setLayout(this.#wanted());                // not started: the connection takes them
  }

  /**
   * The remote monitors wanted now: one per screen whose window is open, where the screens are (the primary one at 0,0, or
   * this window's when the primary is not shown), the size of the screen, or of its window with dynamic resize (side by
   * side or one above the other, see arrange).
   */
  #wanted() {
    const ids = [...new Set(this.views.map((v) => v.mon))].filter((i) => this.screens[i]).sort((a, b) => a - b);
    // RemoteApp: this window's screen is the primary monitor, so that the program opens here, where its taskbar is (and its
    // work area starts at 0,0)
    const pri = (this.rail ? undefined : ids.find((i) => this.screens[i].primary)) ?? this.views[0].mon, base = this.screens[pri];
    const list = ids.map((i) => { const sc = this.screens[i]; return { i, vx: sc.vx - base.vx, vy: sc.vy - base.vy, w: sc.w, h: sc.h, primary: i === pri }; });
    if (this.conn.resize !== 'dynamic') return list;
    const room = ids.map((i) => this.#room(i));
    if (room.some((r) => !r)) return list;
    return list.length === 1 ? [{ ...list[0], ...room[0] }] : arrange(list, room) || list;
  }

  /** Counts painted frames and, with several monitors, copies changed areas from the surface to the windows on the next frame. */
  #dirty(x, y, w, h) {
    if (!this.painted) { this.painted = true; requestAnimationFrame(() => { this.painted = false; this.counts.frames++; }); }
    if (!this.multi && !this.rail) return;
    const d = this.dirtyRect;
    if (d) { const r = Math.max(d.x + d.w, x + w), b = Math.max(d.y + d.h, y + h); d.x = Math.min(d.x, x); d.y = Math.min(d.y, y); d.w = r - d.x; d.h = b - d.y; }
    else {
      this.dirtyRect = { x, y, w, h };
      this.#onFrame(() => this.#copyDirty());
    }
  }

  /** The changed area, from the surface to each window it is in (once: it may have been asked from two windows). */
  #copyDirty() {
    const r = this.dirtyRect;
    if (!r) return;
    this.dirtyRect = null;
    if (this.rail) { this.#railCopy(r); return; }
    for (const v of this.views) {
      if (v.x === null) continue;                        // its monitor is not there yet
      const ix = Math.max(r.x, v.x), iy = Math.max(r.y, v.y);
      const iw = Math.min(r.x + r.w, v.x + v.canvas.width) - ix, ih = Math.min(r.y + r.h, v.y + v.canvas.height) - iy;
      if (iw > 0 && ih > 0) v.ctx.drawImage(this.surface, ix, iy, iw, ih, ix - v.x, iy - v.y, iw, ih);
    }
  }

  /**
   * f on the next frame of a window of the session in view: this page's, else another screen's. A hidden page (minimized,
   * covered, another tab) gets no frames, and the other screens' windows, still in view, stopped changing with it.
   */
  #onFrame(f) {
    const v = document.hidden && this.views.find((x) => x.canvas.ownerDocument && !x.canvas.ownerDocument.hidden);
    if (v) v.canvas.ownerDocument.defaultView.requestAnimationFrame(f);
    else requestAnimationFrame(f);
  }

  /**
   * The remote desktop area under a rectangle of the page (client coordinates, any two corners) drawn over the canvas c of a
   * view: [x, y, w, h] in desktop pixels, cut to that canvas (not its letterbox margins).
   */
  areaOf(c, x0, y0, x1, y1) {
    const v = this.views.find((x) => x.canvas === c) || this.views[0], r = c.getBoundingClientRect();
    const px = (x) => Math.max(0, Math.min(c.width, Math.round(((x - r.left) * c.width) / r.width)));
    const py = (y) => Math.max(0, Math.min(c.height, Math.round(((y - r.top) * c.height) / r.height)));
    const [l, t, rt, b] = [px(Math.min(x0, x1)), py(Math.min(y0, y1)), px(Math.max(x0, x1)), py(Math.max(y0, y1))];
    return [v.x + l, v.y + t, rt - l, b - t];
  }

  /** A PNG of the remote desktop (with several monitors, all of them) or of its area [x, y, w, h]: its pixels only, no page around. */
  capture(area = null) {
    const src = this.rail ? this.canvas : this.surface;   // RemoteApp: its windows, not what the desktop behind them kept
    const [x, y, w, h] = area || [0, 0, src.width, src.height];
    const c = document.createElement('canvas');
    c.width = w;
    c.height = h;
    c.getContext('2d').drawImage(src, x, y, w, h, 0, 0, w, h);
    return new Promise((ok, ko) => c.toBlob((b) => (b ? ok(b) : ko(new Error('png'))), 'image/png'));
  }

  /** Scales the canvas (with several monitors, this window's one) to the container, letterboxed, 1:1 when sizes match. */
  fit() {
    const r = this.container.getBoundingClientRect();
    contain(this.canvas, r.width, r.height);
  }

  /** Executes a drawing order (fill, copy, invert, cached bitmap, text), clipped to its bounds; 'cache' and 'glyph' store. */
  /**
   * Copies an area of the remote desktop to another place of it (ScrBlt: every line a console scrolls) through a scratch
   * canvas: a canvas drawn onto itself is copied whole first (6.7 ms for 64 x 16 pixels of 1920 x 1080; 12 µs this way).
   * Cleared first, so what lies outside the desktop draws nothing, as with a direct copy.
   */
  #selfCopy(sx, sy, w, h, x, y) {
    // (the desktop's size at most: one ScrBlt of 32767 x 8192 made a 1 GB canvas kept for the session, or one past the
    // browser's limit, and then no scroll drew)
    w = Math.min(w, this.surface.width);
    h = Math.min(h, this.surface.height);
    if (!(w > 0 && h > 0)) return;
    let t = this.copyTmp;
    if (!t || t.width < w || t.height < h) {
      const c = document.createElement('canvas');
      c.width = Math.max(w, t?.width || 0);
      c.height = Math.max(h, t?.height || 0);
      t = this.copyTmp = c;
    }
    const tc = t.getContext('2d');
    tc.clearRect(0, 0, w, h);
    tc.drawImage(this.surface, sx, sy, w, h, 0, 0, w, h);
    this.sctx.drawImage(t, 0, 0, w, h, x, y, w, h);
  }

  #order(o) {
    if (o.op === 'window' || o.op === 'desktop') { this.#railOrder(o); return; }
    if (o.op === 'glyph') { this.gcache.set(o.key, glyphPath(o)); return; }
    if (o.op === 'cache') {                              // cache bitmap v2: kept as a small canvas for MemBlt
      const b = document.createElement('canvas');
      b.width = o.w;
      b.height = o.h;
      b.getContext('2d').putImageData(new ImageData(new Uint8ClampedArray(o.px.buffer, o.px.byteOffset, o.px.byteLength), o.w, o.h), 0, 0);
      this.bcache.set(o.id * 65536 + o.idx, b);
      return;
    }
    if (o.op === 'text') { drawText(this.sctx, o, this.gcache); this.#dirty(o.x, o.y, o.w, o.h); return; }
    const c = this.sctx;
    if (o.clip) { c.save(); c.beginPath(); c.rect(o.clip.l, o.clip.t, o.clip.r - o.clip.l + 1, o.clip.b - o.clip.t + 1); c.clip(); }
    if (o.op === 'copy') this.#selfCopy(o.sx, o.sy, o.w, o.h, o.x, o.y);
    else if (o.op === 'mem') {
      const b = this.bcache.get(o.id * 65536 + o.idx);
      if (b && o.rop === 0xcc) c.drawImage(b, o.sx, o.sy, o.w, o.h, o.x, o.y, o.w, o.h);
      else if (b && this.#ropOk(o)) drawRop(c, o, b);    // SRCAND, SRCPAINT, SRCINVERT...: pixel by pixel
    }
    else if (o.op === 'pattern') { if (o.rop === 0xf0) drawPattern(c, o); else if (this.#ropOk(o)) drawRop(c, o); }
    else if (o.op === 'fill') {
      c.fillStyle = `rgb(${o.color & 255},${(o.color >> 8) & 255},${(o.color >> 16) & 255})`;
      c.fillRect(o.x, o.y, o.w, o.h);
    } else {
      c.save();
      c.globalCompositeOperation = 'difference';
      c.fillStyle = '#fff';
      c.fillRect(o.x, o.y, o.w, o.h);
      c.restore();
    }
    if (o.clip) c.restore();
    this.#dirty(o.x, o.y, o.w, o.h);
  }

  #update(code, d) {
    switch (code) {
      case 5: this.#cursor({ css: 'none' }); break;
      case 6: this.#cursor({ css: 'default' }); break;
      case 9: case 11: case 12: {
        const p = pointerDecode(d, code);
        const c = document.createElement('canvas');
        c.width = p.w;
        c.height = p.h;
        if (p.w && p.h) c.getContext('2d').putImageData(new ImageData(new Uint8ClampedArray(p.rgba.buffer), p.w, p.h), 0, 0);
        const url = p.w && p.h ? c.toDataURL() : null;
        const css = url ? `url(${url}) ${Math.min(p.x, p.w - 1)} ${Math.min(p.y, p.h - 1)}, default` : 'none';
        const ptr = { css, text: isTextCursor(p), url, hx: p.x, hy: p.y, img: url ? c : null };   // (img: drawn into a recording's video)
        this.pointers.set(p.index, ptr);
        this.#cursor(ptr);
        break;
      }
      case 10: this.#cursor(this.pointers.get(d[0] | (d[1] << 8)) || { css: 'default' }); break;
    }
  }

  /** Shows the remote pointer; the text cursor right after a tap opens the phone keyboard (Windows shows it over text boxes). */
  #cursor(ptr) {
    this.ptr = ptr;
    for (const v of this.views) v.canvas.style.cursor = this.guest?.mode === 'view' ? 'default' : ptr.css;   // (a guest that only watches: its own)
    if (this.mark) this.#ownerPointer(...this.pointerAt);
    this.textCursor = !!ptr.text;
    if (this.textCursor && performance.now() - this.tap.at < 1000) { this.tap.at = 0; this.showKeyboard(this.tap.keyboard); }
  }

  /** A recording being played: pause, play, or speed (v: 1, 2, 4, 8). */
  replayCmd(cmd, v) { this.worker?.postMessage({ cmd, v }); }

  /** A guest: where the owner's mouse is on the remote desktop, shown with the remote pointer's picture. */
  #ownerPointer(x, y) {
    this.pointerAt = [x, y];
    const m = this.mark ??= this.container.appendChild(Object.assign(document.createElement('div'), { className: 'owner-pointer' }));
    const c = this.canvas.getBoundingClientRect(), b = this.container.getBoundingClientRect(), k = c.width / this.canvas.width || 1;
    const p = this.ptr, hidden = p?.css === 'none';
    m.classList.toggle('hidden', hidden);
    m.classList.toggle('picture', !!p?.url);
    m.style.backgroundImage = p?.url ? `url(${p.url})` : '';
    m.style.transform = `translate(${Math.round(c.left - b.left + x * k - (p?.url ? p.hx : 0))}px, ${Math.round(c.top - b.top + y * k - (p?.url ? p.hy : 0))}px)`;
  }

  /** Opens the on-screen keyboard of phones and tablets (needs a tap or click just before). */
  showKeyboard(k = this.keyboard) {
    k.style.cssText = 'top:auto;bottom:0;width:100%;height:2em';
    this.refocusing = true;                              // not a real blur: keys and panel modifiers stay
    k.blur();                                            // focus() again so the browser shows the keyboard
    k.focus();
    this.refocusing = false;
    this.softKeyboard = true;
    this.kbBase = globalThis.visualViewport?.height ?? 0;   // the view's height before the keyboard
    this.kbShrunk = false;
    setTimeout(() => { k.style.cssText = ''; }, 500);
  }

  // ------------------------------------------------------------ diagnostic mode

  /** What the gateway cannot know about the browser (written to the trace only in diagnostic mode). */
  #browserInfo() {
    return { dpr: devicePixelRatio, screen: `${screen.width}x${screen.height}`, lang: navigator.language, cores: navigator.hardwareConcurrency,
      webcodecs: 'VideoDecoder' in self, h264: !!this.avc, touch: navigator.maxTouchPoints, windowManagement: 'getScreenDetails' in window };
  }

  /** A note for the trace (decoder errors, statistics); only sent while the gateway records one, and at most DIAG_MAX. */
  diag(d) {
    if (!this.diagLeft) return;
    this.diagLeft--;
    const json = new TextEncoder().encode(JSON.stringify(d).slice(0, 3900));
    const m = new Uint8Array(json.length + 1);
    m[0] = MSG.DIAG;
    m.set(json, 1);
    this.#post(m);
  }

  /**
   * Pixel-by-pixel ROP drawing, 20 desktops of it a second at most (each a whole getImageData and putImageData): a server
   * repeating one PatBlt of the whole desktop with one byte an order made the session unusable. Windows' own are small (a
   * selection, a dragged outline); past the budget they are left out, said once in the diagnostic log.
   */
  #ropOk(o) {
    const now = performance.now(), sw = this.surface.width, sh = this.surface.height;
    if (now - this.ropAt > 1000) { this.ropAt = now; this.ropPx = 0; }
    this.ropPx += Math.min(Math.max(0, o.w), sw) * Math.min(Math.max(0, o.h), sh);
    if (this.ropPx <= 20 * sw * sh) return true;
    if (!this.ropSaid) { this.ropSaid = true; this.#log('drawing: ROP operations past 20 desktops a second left out'); }
    return false;
  }

  #log(msg) {
    console.warn(msg);
    this.diag({ error: msg });
  }

  // ------------------------------------------------------------ sending

  /** Queues a control message (array or Uint8Array) for the running session. */
  // ------------------------------------------------------------ RemoteApp (MS-RDPERP)

  /**
   * A window order of the server: a window new, changed (only the fields sent) or deleted, or the desktop (the active window,
   * the z-order). The page is repainted once per frame and told (onRail) of the windows to list.
   */
  #railOrder(o) {
    const r = this.rail;
    if (!r) return;
    if (this.diagLeft && (r.notes ??= 120) > 0) {          // diagnostic mode: the server's window orders, briefly (at most 120)
      r.notes--;
      const { op, title, vis, rects, ...f } = o;
      this.diag({ rail: { ...f, op, ...(title !== undefined ? { title: title.length } : {}), ...(vis ? { vis: vis.slice(0, 8) } : {}), ...(rects ? { rects: rects.length } : {}) } });
    }
    if (o.op === 'desktop') {
      if (o.none || o.began) r.wins.clear();
      if (o.active !== undefined) r.active = o.active;
      if (o.order) r.order = o.order;
      if (o.completed && !r.ready) this.#railReady();   // the server sent its windows: now it takes the program
    } else if (o.deleted) r.wins.delete(o.id);
    else {
      const { op, new: fresh, ...f } = o;
      r.wins.set(o.id, { ...(fresh ? {} : r.wins.get(o.id)), ...f });
    }
    // the program's last window closed: the session ends a moment later (Windows keeps it, as for Remote Desktop
    // Connection); not while the server sends its windows again
    if (o.op === 'desktop' && (o.none || o.began)) { clearTimeout(r.closing); r.closing = 0; }
    else if (r.seen ? this.#railOpen() : this.#railHere()) {
      if (!r.seen) { clearTimeout(r.later); clearTimeout(r.giveUp); Object.assign(this, { quiet: false, railSince: 0 }); }   // it came
      r.seen = this.hadApp = true;
      clearTimeout(r.closing);
      r.closing = 0;
    }
    else if (r.seen && !r.closing) r.closing = setTimeout(() => this.#railGone(), r.goneMs);
    if (!r.soon) { r.soon = 1; this.#onFrame(() => this.#railSoon()); }
  }

  /** The program's windows painted again and listed, once on the next frame after they changed. */
  #railSoon() {
    if (!this.rail?.soon) return;
    this.rail.soon = 0;
    this.#railPaint();
    this.onRail?.(this.railWindows());
  }

  /**
   * How many of the program's windows are open: minimized, or shown with a size and something visible. Not hidden or
   * cloaked ones, nor those of no size or with nothing visible: a program may keep some of those for seconds while it
   * closes (they kept the session open about 15 s after the program was closed).
   */
  #railOpen() {
    let n = 0;
    for (const w of this.rail.wins.values()) if (this.#railShown(w)) n++;
    return n;
  }

  /**
   * A window there for the user: minimized, or shown with a size and something visible; not hidden, cloaked or a tool
   * window (a program shows small ones while it starts: a strip 4 px high, a hint window, as a real trace showed).
   */
  #railShown(w) {
    if (!w || w.show === 0 || w.cloaked || (w.exStyle ?? 0) & 0x80) return false;
    return w.show === 2 || ((w.w ?? 2) > 1 && (w.h ?? 2) > 1 && (!w.vis || w.vis.length > 0));
  }

  /** The program is there: a window of it shown, with a title (a splash screen has none: the main window may come later). */
  #railHere() {
    for (const w of this.rail.wins.values()) if (w.title && this.#railShown(w)) return true;
    return false;
  }

  /**
   * The server has sent its windows: the gateway may ask for the program at once, told how many are open (connected again,
   * it may still be running there: then not). Windows of no size or hidden are not the program's: Windows keeps some in
   * every session (one of no size per screen, hidden ones), also before the program first opens. Taken for the program
   * still closing, they kept it from being asked for, and the server ended the session some 12 s later (no program there).
   */
  #railReady() {
    const r = this.rail;
    if (r.ready) return;
    const open = this.#railOpen();
    r.ready = true;
    if (!open) this.railSince ||= Date.now();            // asked for (first time in this wait)
    this.#railSend({ a: 'ready', open });
    this.#railWatch();
  }

  /**
   * Until the program's first window: the page drawn again once it is slow (slowMs), and given up giveUpMs after it was
   * first asked for (the session ends: it did not open).
   */
  #railWatch() {
    const r = this.rail;
    clearTimeout(r.later);
    clearTimeout(r.giveUp);
    this.onRail?.(this.railWindows());
    if (r.seen || !this.railSince) return;
    const gone = Date.now() - this.railSince;
    if (gone < r.slowMs) r.later = setTimeout(() => this.onRail?.(this.railWindows()), r.slowMs - gone + 50);
    r.giveUp = setTimeout(() => {
      if (r.seen || this.stopped) return;
      this.appNotOpened = true;
      this.stop();
    }, Math.max(0, r.giveUpMs - gone));
  }

  #railSend(o) { this.#post([MSG.RAIL, ...new TextEncoder().encode(JSON.stringify(o))]); }

  /**
   * RemoteApp, the program not here yet: 'opening' (connected, or connecting again quietly), 'slow' (asked for slowMs ago
   * and still opening); otherwise null. Once its window came, nothing: closed, the session ends (before, the page said
   * "Opening" then).
   */
  railPending() {
    const r = this.rail;
    if (!r || r.seen || this.#railOpen()) return null;
    if (!this.sid) return this.quiet && !this.stopped ? 'opening' : null;
    return this.railSince && Date.now() - this.railSince >= r.slowMs ? 'slow' : 'opening';
  }

  /** No window of the program for a while: it was closed, so the session ends (said as such). */
  #railGone() {
    this.rail.closing = 0;
    if (this.#railOpen() || !this.sid || this.stopped) return;
    if (this.diagLeft) {                                 // diagnostic mode: what was left (windows not counted as open)
      this.diag({ rail: { op: 'closed', left: [...this.rail.wins.values()].slice(0, 20).map((w) => ({ id: w.id, show: w.show, owner: w.owner,
        w: w.w, h: w.h, vis: w.vis?.length, cloaked: !!w.cloaked })) } });
    }
    this.appClosed = true;
    this.stop();
  }

  /**
   * From the server's screen coordinates (windows; its primary monitor at 0,0, others may be at negative ones) to the remote
   * desktop's (its top-left corner at 0,0, as its pixels come): the same with one screen.
   */
  #railOffset() {
    const m = this.multi && this.mons?.[0];
    return m ? [m.x - m.vx, m.y - m.vy] : [0, 0];
  }

  /** The parts of the remote desktop that are windows shown now: [x, y, w, h] each (their visible region, else their frame). */
  #railRects() {
    const out = [], [ox, oy] = this.#railOffset();
    for (const w of this.rail.wins.values()) {
      if (w.show === 0 || w.show === 2 || w.cloaked) continue;   // hidden, minimized or cloaked
      if (w.vis && w.visX !== undefined) for (const [l, t, rt, b] of w.vis) out.push([ox + w.visX + l, oy + w.visY + t, rt - l, b - t]);
      else if (w.x !== undefined && w.w > 0 && w.h > 0) out.push([ox + w.x, oy + w.y, w.w, w.h]);
    }
    return out;
  }

  /** Where the windows go: this page's canvas, or with several screens each window's (where its monitor is on the desktop). */
  #railViews() {
    return this.multi ? this.views.filter((v) => v.x !== null && v.ctx) : [{ ctx: this.ctx, canvas: this.canvas, x: 0, y: 0 }];
  }

  /** The page (every screen's window) again: clear, then every window's visible part from the remote desktop. */
  #railPaint() {
    if (!this.rail) return;
    this.rail.rects = this.#railRects();
    for (const v of this.#railViews()) v.ctx.clearRect(0, 0, v.canvas.width, v.canvas.height);
    this.#railCopy({ x: 0, y: 0, w: this.surface.width, h: this.surface.height });
  }

  /** The changed area d of the remote desktop, copied where it is a window, on the screen that shows that part. */
  #railCopy(d) {
    const sw = this.surface.width, sh = this.surface.height;
    for (const v of this.#railViews()) {
      const vr = v.x + v.canvas.width, vb = v.y + v.canvas.height;
      for (const [x, y, w, h] of this.rail.rects) {
        const ix = Math.max(x, d.x, v.x, 0), iy = Math.max(y, d.y, v.y, 0);
        const iw = Math.min(x + w, d.x + d.w, vr, sw) - ix, ih = Math.min(y + h, d.y + d.h, vb, sh) - iy;
        if (iw > 0 && ih > 0) v.ctx.drawImage(this.surface, ix, iy, iw, ih, ix - v.x, iy - v.y, iw, ih);
      }
    }
  }

  /**
   * The program's windows to list (as a taskbar would): top-level, with a title, not tool windows; the active one marked.
   * [{id, title, minimized, active}]
   */
  railWindows() {
    if (!this.rail) return [];
    // top-level for the user: no owner, or one that is not a window shown here. Some programs' main window is owned by a
    // hidden window of theirs, sent as 0xFFFFFFFE: it was left out of the taskbar. Shown only (not one of no size)
    const wins = this.rail.wins;
    return [...wins.values()].filter((w) => w.title && this.#railShown(w) && !(w.owner && this.#railShown(wins.get(w.owner))))
      .map((w) => ({ id: w.id, title: w.title, minimized: w.show === 2, active: w.id === this.rail.active }));
  }

  /** A window of the program brought to the front (restored first when minimized), minimized, maximized or closed. */
  railCommand(id, what) {
    if (!this.rail?.wins.has(id)) return;
    const w = this.rail.wins.get(id);
    const send = (o) => this.#post([MSG.RAIL, ...new TextEncoder().encode(JSON.stringify(o))]);
    if (what === 'front') {
      if (w.show === 2) send({ a: 'sys', id, c: SC.restore });
      send({ a: 'activate', id });
    } else if (SC[what]) send({ a: 'sys', id, c: SC[what] });
  }

  #post(bytes) {
    if (!this.token || (this.guest && !GUEST_MSG.has(bytes[0]))) return;   // a guest: only its keyboard, mouse and touch
    this.outbox.push(bytes instanceof Uint8Array ? bytes : new Uint8Array(bytes));
    this.#pump();
  }

  /**
   * Microphone audio (16-bit PCM): joined to the piece still waiting to be sent, if any, of which at most max bytes are kept
   * (the oldest go, whole frames): a slow network delays it a little, not more and more.
   */
  sendMic(pcm, max, frame) {
    if (!this.token) return;
    this.recorder?.micData(pcm);                         // (what the remote computer hears: in a recording made in this page too)
    const i = this.outbox.findIndex((m) => m[0] === MSG.MIC);
    if (i < 0) {
      const m = new Uint8Array(pcm.length + 1);
      m[0] = MSG.MIC;
      m.set(pcm, 1);
      this.#post(m);
      return;
    }
    const old = this.outbox[i], len = old.length - 1 + pcm.length, keep = Math.min(len, max - (max % frame));
    const m = new Uint8Array(keep + 1);
    m[0] = MSG.MIC;
    const from = len - keep;                             // bytes dropped from the start (whole frames: both lengths are)
    if (from < old.length - 1) m.set(old.subarray(1 + from), 1);
    m.set(pcm.subarray(Math.max(0, from - (old.length - 1))), 1 + Math.max(0, old.length - 1 - from));
    this.outbox[i] = m;
  }

  sendClip(payload) {
    const m = new Uint8Array(payload.length + 1);
    m[0] = MSG.CLIP;
    m.set(payload, 1);
    this.#post(m);
  }

  /**
   * A picture of the camera (JPEG; empty: it could not be opened) of the start numbered `no` (F_CAM): the newest waits, in
   * place of an older one not sent yet.
   */
  /**
   * A piece of the recording made in this page, on the out-of-order lane: one at a time, in order. More than REC_BACKLOG
   * bytes waiting (an upload slower than the video, the network gone): given up, the recording ends whole up to there.
   */
  #sendRec(m) {
    if (!this.token || this.guest) return;
    this.lane.rec.push(m);
    this.lane.recWait = false;                           // (one that did not get there goes again now, ahead of this one)
    if (this.lane.rec.reduce((n, x) => n + x.length, 0) > REC_BACKLOG) this.#recGiveUp();
    this.#lanePump();
  }

  #recGiveUp() {
    this.lane.rec = [];
    this.recorder?.abandon();                            // (its REC_FAILED, the next piece: the gateway finds the gap and ends it)
  }

  sendCam(jpeg, no) {
    if (!this.token || this.guest) return;
    const m = new Uint8Array(jpeg.length + 2);
    m[0] = MSG.CAM;
    m[1] = no;
    m.set(jpeg, 2);
    this.lane.cam = m;
    this.#lanePump();
  }

  /** A folder of this computer shared in the session (its name; read only where the browser cannot write it). */
  shareFolder(name, readonly) {
    this.#post([MSG.FOLDER, 1, readonly ? 1 : 0, ...new TextEncoder().encode(String(name).slice(0, 64))]);   // (64 kept there)
  }

  unshareFolder() { this.#post([MSG.FOLDER, 0]); }

  /** The folder's answer to the request numbered `no` (F_DRIVE), from its DeviceId on. */
  sendFolderReply(no, bytes) {
    if (!this.token || this.guest) return;
    const m = new Uint8Array(bytes.length + 5), v = new DataView(m.buffer);
    m[0] = MSG.FOLDER_IO;
    v.setUint32(1, no, true);
    m.set(bytes, 5);
    this.lane.folder.push(m);
    this.#lanePump();
  }

  /** The first answer of a folder's listing with its other names, which the gateway then answers itself (Rdpdr::localList). */
  sendFolderList(no, answer, rest) {
    if (!this.token || this.guest) return;
    const m = new Uint8Array(1 + 8 + answer.length + rest.length), v = new DataView(m.buffer);
    m[0] = MSG.FOLDER_LIST;
    v.setUint32(1, no, true);
    v.setUint32(5, answer.length, true);
    m.set(answer, 9);
    m.set(rest, 9 + answer.length);
    this.lane.folder.push(m);
    this.#lanePump();
  }

  /**
   * The out-of-order lane: the gateway takes each one as it arrives, so no key or click waits behind a picture of the camera
   * (some 40 KB) or a piece of a file read (up to 1 MB). Two requests out at most; the folder's answers first (Windows waits
   * for them), then the recording's next piece (one at a time: they go in order), then the newest picture. A folder's
   * answer lost on the way (the network, the gateway busy) is sent again, up to 3 times: else the program there waited for
   * it for ever (the gateway takes only the first of two). A piece of the recording that did not get there (the network,
   * the gateway busy: not refused) waits ahead of the next ones and goes again with the next piece the recorder makes,
   * about a second later, REC_TRIES times at most (a network gone for a few seconds loses nothing); then, or refused, the
   * recording is given up: one missing would make the rest unreadable.
   */
  #lanePump() {
    const gen = this.gen;
    const recNext = () => !this.lane.recOut && !this.lane.recWait && this.lane.rec.length;
    while (this.token && this.lane.busy < 2 && (this.lane.folder.length || recNext() || this.lane.cam)) {
      const payload = this.lane.folder.length ? this.lane.folder.shift() : recNext() ? this.lane.rec.shift() : this.lane.cam;
      if (payload === this.lane.cam) this.lane.cam = null;
      const rec = payload[0] === MSG.REC;
      if (rec) this.lane.recOut = true;
      const body = new Uint8Array(payload.length + 4);
      new DataView(body.buffer).setUint32(0, LANE, true);
      body.set(payload, 4);
      if (this.viaWs && this.worker) {                   // (the WebSocket service: no request to wait for)
        this.worker.postMessage({ cmd: 'send', body }, [body.buffer]);
        if (rec) this.lane.recOut = false;
        continue;
      }
      this.lane.busy++;
      let again = 0;
      let refused = false;
      fetch(`rdp.php?a=send&sid=${this.sid}`, { method: 'POST', body, cache: 'no-store', headers: { 'X-MuDesk-Token': this.token } })
        .then((r) => {
          // (refused by the hosting without being run, 429 too: sent again, a folder's answer as a piece of a recording)
          if (r?.status >= 500 || r?.status === 429 || (rec && r?.status === 410)) throw new Error('busy');
          refused = rec && !r?.ok;                        // (404: not a piece the gateway takes)
        })
        .catch(() => {
          if (rec) again = (payload.tries = (payload.tries ?? 0) + 1) < REC_TRIES ? -1 : 0;
          else if (payload[0] !== MSG.CAM && (payload.tries = (payload.tries ?? 0) + 1) <= 3) again = 500 * payload.tries;
          refused = rec && !again;
        }).finally(() => {
          if (gen !== this.gen) return;
          this.lane.busy--;
          if (rec) {
            this.lane.recOut = false;
            if (refused) this.#recGiveUp();
            else if (again) {                            // (it waits ahead of the next ones: sent again with the next piece)
              this.lane.rec.unshift(payload);
              this.lane.recWait = true;
            }
            this.#lanePump();
            return;
          }
          if (!again) { this.#lanePump(); return; }
          setTimeout(() => {
            if (gen !== this.gen) return;
            this.lane.folder.unshift(payload);
            this.#lanePump();
          }, again);
        });
    }
  }

  /**
   * Sends queued messages, up to 4 requests in flight; the server reorders them by sequence number. Mouse moves and frame
   * acknowledgements take 3 of them at most, so a key or a click never waits behind them, and an acknowledgement owed
   * goes out between two batches of moves: sent only when nothing else waited, a mouse kept moving delayed them up to
   * 0.8 s (with 200 ms to the gateway), and Windows paces its frames by them: the screen stalled while dragging. While the
   * hosting refuses some (see #send), one at a time.
   */
  #pump() {
    const gen = this.gen;
    // a guest's requests are taken as they come: one at a time keeps their order
    const most = this.guest || performance.now() - this.refusedAt < REFUSED_QUIET ? 1 : 4;
    while (this.token && this.inflight < most && (this.outbox.length || this.events.length || ((this.move || this.ack) && this.inflight < Math.max(1, most - 1)))) {
      if (!this.outbox.length) {
        if (this.events.length || (this.move && !(this.ack && this.ackOwed))) { this.#packEvents(); this.ackOwed = !!this.ack; }
        else { this.outbox.push(this.ack); this.ack = null; this.ackOwed = false; }
      }
      const payload = this.outbox.shift();
      const body = new Uint8Array(payload.length + 4);
      new DataView(body.buffer).setUint32(0, this.seq++, true);
      body.set(payload, 4);
      this.inflight++;
      this.#send(body, gen, 0);
    }
  }

  /**
   * One request of #pump, in flight until it got there or was given up. Refused by the hosting without being run (REFUSED:
   * its limit of PHP processes at once reached, say) it goes again after INPUT_RETRY[tries] ms, 5 times at most: before,
   * the keys and clicks in it were lost (the gateway waits 0.5 s for one missing, then goes on without it), and a key
   * released in it stayed pressed on the remote computer. Lost on the way (no answer, a proxy's 502 or 504) it goes again
   * too, but not a guest's: the gateway takes the owner's by their number (a copy of one that got there is left out), a
   * guest's as they come (a copy would type twice). A session gone (410) or a request refused by the gateway (404): not again.
   */
  #send(body, gen, tries) {
    // the WebSocket service: in order, on the session's own connection. Not the goodbye (QUIT): the page ends the worker at
    // once after it (dispose), before the worker sent it, and the gateway took the end for a network drop; over HTTP, as on
    // that path, it gets there (the gateway takes it by its number, and from a page closing too)
    if (this.viaWs && this.worker && body[4] !== MSG.QUIT) {
      this.worker.postMessage({ cmd: 'send', body }, [body.buffer]);
      this.inflight--;
      return;
    }
    const t0 = performance.now();
    const keepalive = this.keepalive + body.length <= 65536;   // browsers refuse more than 64 KiB of these in flight
    if (keepalive) this.keepalive += body.length;
    // the token in a header: in the URL it ended up in the web server's and proxies' access logs
    fetch(`rdp.php?a=send&sid=${this.sid}`, { method: 'POST', body, cache: 'no-store', keepalive, headers: { [this.guest ? 'X-MuDesk-Share' : 'X-MuDesk-Token']: this.token } })
      .then((r) => r?.status, () => 0)
      .then((status) => {
        if (keepalive) this.keepalive -= body.length;
        if (gen !== this.gen) return;                    // of a connection before this one: counted there, not here
        const refused = REFUSED.has(status);
        if (refused) this.refusedAt = performance.now();
        if ((refused || (!this.guest && (status === 0 || status === 502 || status === 504))) && tries < INPUT_RETRY.length && this.token) {
          setTimeout(() => {
            if (gen !== this.gen) return;
            if (this.token) this.#send(body, gen, tries + 1);
            else this.inflight--;                        // (the session ended meanwhile)
          }, INPUT_RETRY[tries]);
          return;
        }
        if (status !== 0 && !refused) { const c = this.counts, d = performance.now() - t0; c.rtt = c.rtt ? c.rtt * 0.8 + d * 0.2 : d; }
        this.inflight--;
        this.#pump();
      });
  }

  #packEvents() {
    if (this.move) { this.events.push(this.move); this.move = null; }
    const batch = this.events.splice(0, 255);
    const size = batch.reduce((n, e) => n + e.length, 2);
    const m = new Uint8Array(size);
    m[0] = MSG.INPUT;
    m[1] = batch.length;
    let o = 2;
    for (const e of batch) { m.set(e, o); o += e.length; }
    this.outbox.push(m);
  }

  #input(e, isMove = false) {
    if (!this.token) return;
    this.inputSeen = true;
    if (isMove) this.move = e;
    else {
      if (this.move) { this.events.push(this.move); this.move = null; }
      this.events.push(e);
    }
    if (!this.queued) { this.queued = true; queueMicrotask(() => { this.queued = false; this.#pump(); }); }
  }

  key(code, up) {
    const sc = SCANCODES[code];
    if (sc) this.#input(ev.key(sc, up));
  }

  /** Sends a key combination (array of KeyboardEvent codes), pressing in order and releasing in reverse. */
  combo(codes) {
    codes.forEach((c) => this.key(c, false));
    [...codes].reverse().forEach((c) => this.key(c, true));
  }

  /**
   * Text from on-screen keyboards and IME. One character is typed (see typedText); several at once (autocorrection, swipe,
   * dictation) are pasted by the gateway through the remote clipboard, because Windows 11 Notepad, Terminal and Explorer
   * mix up injected characters whatever the pause (see Client::pasteText).
   */
  typeText(text) {
    if (!this.token || !text) return;
    this.inputSeen = true;
    while (this.events.length || this.move) this.#packEvents();   // input typed before the text goes first
    this.#post(new Uint8Array([...text].length > 1 ? [MSG.PASTE, ...new TextEncoder().encode(text)] : [MSG.TEXT, ...typedText(text)]));
    if (this.sticky.size) this.#releaseSticky();
  }

  /** A text of the clipboard history: the gateway pastes it into the active remote application through the remote
   *  clipboard, whatever the phone keyboard option (see Client::pasteText). */
  pasteText(text) {
    if (!this.token || !text) return;
    this.inputSeen = true;
    while (this.events.length || this.move) this.#packEvents();   // input typed before the text goes first
    const b = new TextEncoder().encode(text), m = new Uint8Array(b.length + 1);
    m[0] = MSG.CLIP_PASTE;
    m.set(b, 1);
    this.#post(m);
    if (this.sticky.size) this.#releaseSticky();
  }

  refresh() { this.#post([MSG.REFRESH]); }

  // ------------------------------------------------------------ input binding

  #pos(e) {
    let v = this.views.find((x) => x.canvas === e.target) || this.views[0], c = v.canvas;
    let r = c.getBoundingClientRect(), [px, py] = [e.clientX - r.left, e.clientY - r.top];
    if (this.views.length > 1 && (px < 0 || py < 0 || px >= r.width || py >= r.height) && e.screenX !== undefined) {
      // dragging out of this window onto another screen (the window where it started keeps the pointer): the window
      // under it, found by screen position, gives the point (before, this window's scale was carried on and missed it)
      const o = this.views.map((x) => [x, screenBox(x.canvas)]).find(([, b]) => e.screenX >= b.left && e.screenX < b.left + b.width
        && e.screenY >= b.top && e.screenY < b.top + b.height);
      if (o) [v, c, r, px, py] = [o[0], o[0].canvas, o[1], e.screenX - o[1].left, e.screenY - o[1].top];
    }
    if (v.x === null) return null;                     // a window whose monitor the server has not taken yet: no input
    const x = v.x + Math.round(px * c.width / r.width);
    const y = v.y + Math.round(py * c.height / r.height);
    return [Math.max(0, Math.min(this.surface.width - 1, x)), Math.max(0, Math.min(this.surface.height - 1, y))];
  }

  #mouse(flags, e) {
    const p = this.#pos(e);
    if (!p) return;
    this.mouseAt = p;                                    // (where the pointer is drawn in a recording made in this page)
    this.#input(ev.mouse(flags, p[0], p[1]), flags === 0x0800);
  }

  /** The remote desktop point under a browser event, when it happened on the remote screen (else null). */
  pointOf(e) {
    return this.views.some((v) => v.canvas === e.target) ? this.#pos(e) : null;
  }

  /** A left click at a remote desktop point (where files were dropped, before they are pasted there). */
  clickAt([x, y]) {
    this.inputSeen = true;
    this.#input(ev.mouse(0x0800, x, y), true);
    this.#input(ev.mouse(0x1000 | 0x8000, x, y));
    this.#input(ev.mouse(0x1000, x, y));
  }

  /** Mouse button b (PointerEvent.button) pressed or released: where event e is, or at the remote point at. */
  #button(e, down, b = e.button, at = null) {
    const p = at || this.#pos(e);
    if (!p) return;
    if (b <= 2) {
      if (!at) this.mouseAt = p;                         // (as #mouse)
      this.#input(ev.mouse(BUTTONS[b] | (down ? 0x8000 : 0), p[0], p[1]));
    } else if (b <= 4) this.#input(ev.mouseX((b === 3 ? 1 : 2) | (down ? 0x8000 : 0), p[0], p[1]));
  }

  #bindPointer(c, view) {
    const touch = { id: null, timer: 0, start: null, moved: false, right: false, pts: new Map() };
    let held = 0;                                        // mouse buttons pressed on the remote (PointerEvent.buttons bits)
    const press = (e, down, b = e.button) => {
      held = down ? held | BUTTON_BITS[b] : held & ~BUTTON_BITS[b];
      this.#button(e, down, b);
    };
    // the page lost sight of the mouse (the focus went to another window, the pointer capture was taken away): its pointerup
    // may never come, so every button still held is released where the pointer was last (it stayed pressed over there)
    view.letGo = () => {
      BUTTON_BITS.forEach((bit, b) => { if (held & bit && this.mouseAt) this.#button(null, false, b, this.mouseAt); });
      held = 0;
    };
    c.addEventListener('lostpointercapture', (e) => { if (e.pointerType !== 'touch') view.letGo(); });

    c.addEventListener('contextmenu', (e) => e.preventDefault());
    c.addEventListener('pointermove', (e) => {
      if (e.pointerType === 'touch' && this.touchOn) { this.#touch(e, 1); return; }
      if (e.pointerType === 'touch') {
        touch.pts.set(e.pointerId, e);
        if (touch.pts.size === 2) this.#twoFingers(touch, e);
        if (touch.pts.size >= 2) return;
        if (e.pointerId !== touch.id || touch.right) return;   // after a long press (right click) the finger only lifts
        if (!touch.moved && Math.hypot(e.clientX - touch.start.clientX, e.clientY - touch.start.clientY) > 10) {
          touch.moved = true;
          clearTimeout(touch.timer);
          this.#mouse(0x0800, touch.start);
          this.#mouse(0x1000 | 0x8000, touch.start);   // drag starts with the left button down
        }
        if (touch.moved) this.#mouse(0x0800, e);
        return;
      }
      // another button pressed or released while one is held: Pointer Events report it as a move (e.button), not as
      // pointerdown/up. And any button held whose release the page did not see (its pointerup lost) is released now: only
      // e.button was compared, which a plain move does not have, so it stayed pressed on the remote computer
      const pressed = e.button >= 0 ? BUTTON_BITS[e.button] & e.buttons & ~held : 0, released = held & ~e.buttons;
      if (pressed || released) {
        this.#mouse(0x0800, e);
        BUTTON_BITS.forEach((bit, b) => { if ((pressed | released) & bit) press(e, !!(pressed & bit), b); });
        return;
      }
      // moves replace each other until they are sent: only the last of the coalesced ones is placed (each one read the
      // canvas's position, a layout read, for nothing)
      const co = e.getCoalescedEvents?.();
      this.#mouse(0x0800, co?.length ? co[co.length - 1] : e);
    });
    c.addEventListener('pointerdown', (e) => {
      view.keyboard.focus({ preventScroll: true });
      if (e.pointerType === 'touch' && this.touchOn) { this.#touch(e, 0); return; }
      if (e.pointerType === 'touch') {
        touch.pts.set(e.pointerId, e);
        if (touch.pts.size === 2) {
          clearTimeout(touch.timer);
          if (touch.id !== null && touch.moved && !touch.right) this.#mouse(0x1000, touch.pts.get(touch.id));   // a drag ends here
          touch.id = null;
          const [a, b] = [...touch.pts.values()];
          Object.assign(touch, { d0: Math.hypot(a.clientX - b.clientX, a.clientY - b.clientY), z0: this.zoom.z, pinch: false,
            mx: (a.clientX + b.clientX) / 2, my: (a.clientY + b.clientY) / 2 });
          return;
        }
        Object.assign(touch, { id: e.pointerId, start: e, moved: false, right: false });
        touch.timer = setTimeout(() => {                 // long press = right click
          touch.right = true;
          this.#mouse(0x0800, e);
          this.#mouse(0x2000 | 0x8000, e);
          this.#mouse(0x2000, e);
        }, 600);
        return;
      }
      c.setPointerCapture(e.pointerId);
      this.#mouse(0x0800, e);
      press(e, true);
      e.preventDefault();
    });
    const up = (e) => {
      if (e.pointerType === 'touch' && this.touchOn) { this.#touch(e, 2); return; }
      if (e.pointerType === 'touch') {
        touch.pts.delete(e.pointerId);
        if (e.pointerId !== touch.id) return;
        clearTimeout(touch.timer);
        touch.id = null;
        if (touch.right) return;
        if (!touch.moved) {
          const [x, y] = this.#pos(e) || [-99, -99], t = this.tap;
          // tapping where the text cursor already is (e.g. the same text box again): open the keyboard now, inside the tap
          if (this.textCursor && Math.abs(x - t.x) < 24 && Math.abs(y - t.y) < 24) this.showKeyboard(view.keyboard);
          else Object.assign(t, { at: performance.now(), keyboard: view.keyboard });
          Object.assign(t, { x, y });
          this.#mouse(0x0800, e);
          this.#mouse(0x1000 | 0x8000, e);
        }
        this.#mouse(0x1000, e);
        return;
      }
      press(e, false);
    };
    c.addEventListener('pointerup', up);
    c.addEventListener('pointercancel', (e) => {
      if (e.pointerType === 'touch' && this.touchOn) { this.#touch(e, 3); return; }
      if (e.pointerType !== 'touch') { up(e); return; }
      // the browser took the touch (a system gesture starting on the screen): never a click; a drag under way ends there
      touch.pts.delete(e.pointerId);
      if (e.pointerId !== touch.id) return;
      clearTimeout(touch.timer);
      touch.id = null;
      if (touch.moved && !touch.right) this.#mouse(0x1000, e);
    });
    c.addEventListener('wheel', (e) => {
      e.preventDefault();
      const m = e.deltaMode === 1 ? 40 : e.deltaMode === 2 ? 800 : 1;
      this.#wheel(e.deltaX * m, e.deltaY * m, e);
    }, { passive: false });

  }

  /**
   * Multitouch: a finger down (0), moved (1), lifted (2) or taken by the system (3). Each finger is a contact (ids 0-9,
   * the lowest free); what changed in this task goes in one frame with every finger on the screen, as MS-RDPEI wants.
   * A lifted finger keeps its last position; one put down and lifted before its frame went is sent down, then up.
   */
  #touch(e, state) {
    this.inputSeen = true;                              // in use: the web session is kept alive
    let c = this.contacts.get(e.pointerId);
    const p = this.#pos(e);
    if (state === 0) {
      if (c || !p || this.contacts.size >= 10) return;
      let id = 0;
      while ([...this.contacts.values()].some((x) => x.id === id)) id++;
      c = { id, x: p[0], y: p[1], state: 0 };
      this.contacts.set(e.pointerId, c);
      e.target?.setPointerCapture?.(e.pointerId);
    } else if (!c) return;
    else if (state === 1) {
      if (p) [c.x, c.y] = p;
    } else if (c.state === 0) c.after = state;          // not sent yet: down first
    else c.state = state;
    if (!this.touchQueued) {
      this.touchQueued = true;
      queueMicrotask(() => { this.touchQueued = false; this.#touchFrame(); });
    }
  }

  #touchFrame() {
    const list = [...this.contacts.entries()];
    if (!list.length) return;
    const m = new Uint8Array(2 + list.length * 6), dv = new DataView(m.buffer);
    m[0] = MSG.TOUCH;
    m[1] = list.length;
    list.forEach(([, c], i) => {
      const o = 2 + i * 6;
      m[o] = c.id;
      m[o + 1] = c.state;
      dv.setInt16(o + 2, c.x, true);
      dv.setInt16(o + 4, c.y, true);
    });
    if (this.move || this.events.length) this.#packEvents();   // the keys and clicks before it go first
    this.#post(m);
    let again = false;
    for (const [k, c] of list) {
      if (c.state >= 2) this.contacts.delete(k);
      else if (c.after !== undefined) { c.state = c.after; delete c.after; again = true; }
      else c.state = 1;
    }
    if (again) this.#touchFrame();
  }

  #bindKeyboard(k) {
    const mute = () => !this.token && (this.replay || this.guest);   // nothing to send: the keys stay the page's
    k.addEventListener('keydown', (e) => { if (!mute()) this.#keydown(e); });
    k.addEventListener('keyup', (e) => {
      if (mute()) return;
      if (e.code === 'Pause' || !SCANCODES[e.code]) { if (!e.isComposing) e.preventDefault(); return; }
      e.preventDefault();
      if (this.pasting) this.deferred.push([e.code, true]);   // keep Ctrl held until the paste reaches the server
      else this.#up(e.code);
    });
    // IME on a computer (Japanese, Chinese...): the text goes once, composed; clearing the box cut the composition ("ka" sent
    // "k", "か" and then "かな"). After a dead key sent as its scancode (´ + a) the remote computer composes the character
    // from the scancodes: that composition's text is left out. Phones and tablets keep their way (each word is a composition).
    k.addEventListener('compositionstart', () => { if (!touchOnly()) this.composing = this.dead ? 'keys' : 'text'; });
    k.addEventListener('compositionend', (e) => {
      if (touchOnly()) return;
      if (this.composing !== 'keys') this.typeText(e.data);
      this.composing = null;
      k.value = '';
    });
    k.addEventListener('input', (e) => {                 // IME / on-screen keyboards
      if (this.composing || ((e.isComposing || e.inputType === 'insertCompositionText') && !touchOnly())) return;   // see compositionend
      if (e.inputType === 'deleteContentBackward') this.combo(['Backspace']);
      else if (k.value && this.sticky.size && /^[a-z0-9]$/i.test(k.value)) {   // e.g. Ctrl + C from the key panel
        const ch = k.value.toUpperCase();
        this.combo([/\d/.test(ch) ? 'Digit' + ch : 'Key' + ch]);
      } else if (k.value) this.typeText(k.value);
      k.value = '';
      if (this.sticky.size) this.#releaseSticky();
    });
    k.addEventListener('paste', (e) => {
      e.preventDefault();
      clearTimeout(this.pasteTimer);
      // the keys typed meanwhile wait for the Ctrl+V, PASTE_CAP ms at most: files or an image going up for minutes held every
      // key (clicks went on); after that the keys go and the Ctrl+V is left to the user (the files say when they are ready
      // to paste). Nothing put on the remote clipboard (its upload failed): no Ctrl+V, it pasted what was there before.
      // (done: only for this paste, not one started after the cap)
      const timer = this.pasteTimer = setTimeout(() => done(false), PASTE_CAP);
      const done = (ok) => { if (this.pasteTimer === timer) this.#pasteDone(ok); };
      if (this.clipboard) this.clipboard.fromPasteEvent(e.clipboardData).then((r) => done(r !== false), () => done(false));
      else done(true);
    });
    k.addEventListener('blur', () => {
      if (this.refocusing) return;
      if (this.pasting) { this.pasting = false; clearTimeout(this.pasteTimer); this.deferred = []; }   // no Ctrl+V once focus is gone
      this.releaseAll();
      this.views.find((v) => v.keyboard === k)?.letGo?.();   // the mouse buttons held in its window too
      if (this.sticky.size) this.#releaseSticky();
      this.softKeyboard = false;
    });
  }

  #bindWindow() {
    const signal = (this.leaveWindow = new AbortController()).signal;
    document.addEventListener('visibilitychange', () => this.#visibility(), { signal });
    // the phone keyboard hidden without leaving the field (Android's Back): once the view, shrunk by the keyboard, is as
    // tall as before again, the desktop follows the window's size again (it stayed as it was until the field lost focus)
    globalThis.visualViewport?.addEventListener('resize', () => {
      if (!this.softKeyboard || !this.kbBase) return;
      const h = globalThis.visualViewport.height;
      if (h < this.kbBase - 50) this.kbShrunk = true;
      else if (this.kbShrunk && h >= this.kbBase - 2) { this.softKeyboard = false; this.#sizeSoon(); }
    }, { signal });
    this.resizeObs = new ResizeObserver(() => {
      this.fit();
      this.#sizeSoon();
    });
    this.resizeObs.observe(this.container);
  }

  /** The remote size follows the windows once they stop changing. */
  #sizeSoon() {
    clearTimeout(this.sizeTimer);
    this.sizeTimer = setTimeout(() => this.#checkSize(), 400);
  }

  /** Two fingers: pinch = zoom the picture, drag = pan when zoomed, otherwise scroll the remote window. */
  #twoFingers(t, e) {
    const [a, b] = [...t.pts.values()];
    const d = Math.hypot(a.clientX - b.clientX, a.clientY - b.clientY);
    const mx = (a.clientX + b.clientX) / 2, my = (a.clientY + b.clientY) / 2;
    if (!t.pinch && Math.abs(d - t.d0) > 24) t.pinch = true;
    if (t.pinch) this.setZoom(t.z0 * d / t.d0, mx, my);
    if (this.zoom.z > 1) this.pan(mx - t.mx, my - t.my);
    else if (!t.pinch) this.#wheel(0, (t.my - my) * 2, e);
    t.mx = mx;
    t.my = my;
  }

  /** Zooms the picture around a point (CSS transform; the remote resolution does not change). */
  setZoom(z, cx, cy) {
    const zm = this.zoom, nz = Math.max(1, Math.min(4, z));
    const r = this.container.getBoundingClientRect();
    const bx = r.left + (r.width - this.canvas.offsetWidth) / 2, by = r.top + (r.height - this.canvas.offsetHeight) / 2;
    zm.x = cx - bx - (cx - bx - zm.x) * nz / zm.z;
    zm.y = cy - by - (cy - by - zm.y) * nz / zm.z;
    zm.z = nz;
    this.pan(0, 0);
  }

  pan(dx, dy) {
    const zm = this.zoom, w = this.canvas.offsetWidth, h = this.canvas.offsetHeight;
    zm.x = zm.z === 1 ? 0 : Math.min(0, Math.max(w * (1 - zm.z), zm.x + dx));
    zm.y = zm.z === 1 ? 0 : Math.min(0, Math.max(h * (1 - zm.z), zm.y + dy));
    this.canvas.style.transform = zm.z === 1 ? '' : `translate(${zm.x}px, ${zm.y}px) scale(${zm.z})`;
  }

  /** Taps a key from the on-screen key panel; Ctrl, Alt, Shift and Win stay pressed until the next key. */
  tapKey(code) {
    if (MODIFIER.test(code)) {
      if (this.sticky.delete(code)) this.key(code, true);
      else { this.sticky.add(code); this.key(code, false); }
      return this.sticky.has(code);
    }
    this.key(code, false);
    this.key(code, true);
    this.#releaseSticky();
    return false;
  }

  #releaseSticky() {
    for (const c of this.sticky) this.key(c, true);
    this.sticky.clear();
    this.onSticky?.();
  }

  #wheel(dx, dy, e) {
    const p = this.#pos(e);
    if (!p) return;
    const [x, y] = p;
    this.wheel.x += dx * 1.2;
    this.wheel.y -= dy * 1.2;
    for (const [axis, flag] of [['y', 0x0200], ['x', 0x0400]]) {
      let v = Math.trunc(this.wheel[axis]);
      if (Math.abs(v) < 30) continue;
      v = Math.max(-255, Math.min(255, v));
      this.wheel[axis] -= v;
      this.#input(ev.mouse(flag | (v & 0x1ff), x, y));
    }
  }

  #keydown(e) {
    this.dead = false;
    if ((e.isComposing || e.keyCode === 229) && this.composing !== 'keys') return;
    // nothing to send them to (the session ended, or is not connected yet): Tab and Esc stay the page's, so the keyboard
    // alone can leave the remote screen for the page's buttons (before, they were held here)
    if (!this.token && (e.code === 'Tab' || e.code === 'Escape')) return;
    // (the locks recorded only when sent: a key before the session's token sent nothing, and they were never synced after it)
    const locks = (e.getModifierState('ScrollLock') ? 1 : 0) | (e.getModifierState('NumLock') ? 2 : 0) | (e.getModifierState('CapsLock') ? 4 : 0);
    if (locks !== this.locks && this.token && !['CapsLock', 'NumLock', 'ScrollLock'].includes(e.code)) {
      this.locks = locks;
      this.#input(ev.sync(locks));
    }
    if (e.code === 'Pause') { e.preventDefault(); ev.pause(false).concat(ev.pause(true)).forEach((x) => this.#input(x)); return; }
    if (SCANCODES[e.code]) {
      if (this.pasting) { e.preventDefault(); this.deferred.push([e.code, false]); return; }   // goes after the paste
      if (e.code === 'KeyV' && (e.ctrlKey || e.metaKey) && this.clipboard && !e.repeat) {
        // let the browser fire "paste" so the local clipboard reaches the server first
        this.pasting = true;
        this.pasteMeta = e.metaKey && !e.ctrlKey;
        this.deferred = [];
        this.pasteTimer = setTimeout(() => this.#pasteDone(), 300);
        return;
      }
      e.preventDefault();
      this.dead = e.key === 'Dead';
      this.#syncShift(e.shiftKey, e.code);
      this.#press(e.code);
    } else if (e.key && e.key.length === 1) {
      e.preventDefault();
      this.typeText(e.key);
    }
  }

  /** Keeps the remote Shift state in line with the event (virtual keyboards may omit the Shift key events). */
  #syncShift(shift, code) {
    if (code.startsWith('Shift')) return;
    const held = this.down.has('ShiftLeft') || this.down.has('ShiftRight');
    if (shift && !held) { this.down.add('ShiftLeft'); this.key('ShiftLeft', false); this.autoShift = true; }
    else if (!shift && held && this.autoShift) { this.down.delete('ShiftLeft'); this.key('ShiftLeft', true); this.autoShift = false; }
  }

  /** A key pressed; a modifier fixed from the key panel lasts until the next key. */
  #press(code) {
    this.down.add(code);
    this.key(code, false);
    if (this.sticky.size && !MODIFIER.test(code)) this.#releaseSticky();
  }

  /** A key released (Cmd after a Cmd+V stands for Ctrl, see #pasteDone); Shift added for one character goes with it. */
  #up(code) {
    if (code === this.cmd) { this.cmd = null; code = 'ControlLeft'; }
    if (!this.down.delete(code)) return;
    this.key(code, true);
    if (this.autoShift) { this.autoShift = false; if (this.down.delete('ShiftLeft')) this.key('ShiftLeft', true); }
  }

  /** The paste's Ctrl+V (ok: what was pasted is on the remote clipboard; else none), then the keys typed meanwhile. */
  #pasteDone(ok = true) {
    if (!this.pasting) return;
    this.pasting = false;
    clearTimeout(this.pasteTimer);
    const meta = this.pasteMeta && ['MetaLeft', 'MetaRight', 'OSLeft', 'OSRight'].find((c) => this.down.has(c));
    if (meta) {                                          // Cmd+V on a Mac: Ctrl takes the place of Win, pressed before Win
      this.key('ControlLeft', false);                    // is released so Windows opens neither Start nor Win+V
      this.key(meta, true);
      this.down.delete(meta);
      this.down.add('ControlLeft');
      this.cmd = meta;
    }
    if (ok) {
      this.key('KeyV', false);
      this.key('KeyV', true);
    }
    for (const [code, up] of this.deferred) if (up) this.#up(code); else this.#press(code);
    this.deferred = [];
  }

  releaseAll() {
    for (const code of this.down) this.key(code, true);
    this.down.clear();
    this.cmd = null;
    this.autoShift = false;
  }

  focus() { this.keyboard.focus({ preventScroll: true }); }

  #checkSize() {
    if (this.guest) return;                             // (a guest sees the desktop as the owner has it)
    // connecting: a window took another size meanwhile, checked once connected. Firefox goes to full screen with the click
    // on Connect, and the request had gone with the size of the window before it (before, that size stayed: the desktop
    // shown enlarged, with black margins, until the window changed again); with several screens, Firefox gives the other
    // screen's window the size of its screen just after it opens, and the request had gone with its first one, 400 x 300
    // (before, that monitor kept it until the server opened Display Control, a minute later in Windows 11)
    if (!this.sid) { if (this.worker) this.pendingLayout = true; return; }
    if (this.multi) { this.#checkMonitors(); return; }
    if (this.conn.resize !== 'dynamic' || this.softKeyboard) return;
    const { w, h } = this.viewSize();
    if (Math.abs(w - this.canvas.width) < 4 && Math.abs(h - this.canvas.height) < 4) return;
    if (this.disp) this.#post([MSG.RESIZE, w & 0xff, w >> 8, h & 0xff, h >> 8]);
    else {                                             // no display control channel: reconnect with the new size
      clearTimeout(this.reconnectTimer);
      this.reconnectTimer = setTimeout(() => { if (this.sid && !this.disp) this.#reconnectNow(); }, 1200);
    }
  }

  /**
   * Several screens: a monitor for each open window (#wanted), each one the size of the window that shows it with dynamic
   * resize, as one monitor follows its window (#checkSize). Before, they kept the size of the screens, with black margins in
   * windows that are not full screen. With Display Control it is applied without reconnecting (one monitor left: the plain
   * resize); the new layout is used once the server takes it.
   */
  #checkMonitors() {
    const next = this.#wanted();
    const same = (cur) => cur && next.length === cur.length && next.every((m, k) => m.i === cur[k].i && m.vx === cur[k].vx && m.vy === cur[k].vy
      && Math.abs(m.w - cur[k].w) < 4 && Math.abs(m.h - cur[k].h) < 4);
    if (same(this.mons) || (this.disp && same(this.nextMons))) return;   // as it is, or already asked for
    if (!this.disp) {                                  // no display control channel (yet): reconnect with the new monitors
      clearTimeout(this.reconnectTimer);                 // (taken from #wanted then; if it comes meanwhile, it is asked through it)
      this.reconnectTimer = setTimeout(() => { if (this.sid && !this.disp) this.#reconnectNow(); }, 1200);
      return;
    }
    this.nextMons = next;
    if (next.length === 1) { const { w, h } = next[0]; this.#post([MSG.RESIZE, w & 0xff, w >> 8, h & 0xff, h >> 8]); return; }
    const json = new TextEncoder().encode(JSON.stringify(next.map((m) => [m.vx, m.vy, m.w, m.h, m.primary ? 1 : 0])));
    const msg = new Uint8Array(json.length + 1);
    msg[0] = MSG.MONITORS;
    msg.set(json, 1);
    this.#post(msg);
  }

  /** The room for monitor i: the size of the window that shows it (null when none does yet). */
  #room(i) {
    const v = this.views.find((x) => x.mon === i);
    if (!v) return null;
    if (v === this.views[0]) return this.viewSize();
    const win = v.canvas.ownerDocument.defaultView;
    return { w: Math.max(200, Math.min(8192, Math.floor(win.innerWidth) & ~1)), h: Math.max(200, Math.min(8192, Math.floor(win.innerHeight))) };
  }

  /** The remote monitors now (their screens, sizes and places): the desktop surface and every window's canvas follow. */
  #setLayout(next) {
    const minX = Math.min(...next.map((m) => m.vx)), minY = Math.min(...next.map((m) => m.vy));
    // RemoteApp: what the remote desktop had is kept, moved where it now is (the server moves its windows copying from there)
    let keep = null;
    const [ox, oy] = this.#railOffset();
    if (this.rail && this.surface.width && this.surface.height) {
      keep = document.createElement('canvas');
      keep.width = this.surface.width;
      keep.height = this.surface.height;
      keep.getContext('2d').drawImage(this.surface, 0, 0);
    }
    this.mons = next.map((m) => ({ ...m, x: m.vx - minX, y: m.vy - minY }));
    this.surface.width = Math.max(...this.mons.map((m) => m.x + m.w));
    this.surface.height = Math.max(...this.mons.map((m) => m.y + m.h));
    this.sctx.fillStyle = '#000';
    this.sctx.fillRect(0, 0, this.surface.width, this.surface.height);
    if (keep) { const [nx, ny] = this.#railOffset(); this.sctx.drawImage(keep, nx - ox, ny - oy); }
    for (const v of this.views) {
      const m = this.mons.find((x) => x.i === v.mon);
      if (!m) { Object.assign(v, { x: null, y: null }); continue; }   // its monitor not there (yet)
      Object.assign(v, { x: m.x, y: m.y });
      v.canvas.width = m.w;
      v.canvas.height = m.h;
      v.fit?.();
    }
    this.fit();
    if (this.rail) {                                     // its windows again, then all of it drawn again by the server
      this.#railPaint();
      clearTimeout(this.rail.refresh);
      this.rail.refresh = setTimeout(() => { if (this.sid) this.refresh(); }, 400);
    }
  }

  #reconnectNow() {
    this.ended = { end: 'resize' };
    this.attempt = 0;
    this.#post([MSG.QUIT]);
    this.worker?.postMessage({ cmd: 'stop' });
  }
}
