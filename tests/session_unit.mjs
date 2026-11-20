// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// Session input unit tests (keyboard, mouse, touch, reconnection, drawing orders, stream worker): node tests/session_unit.mjs
// The browser is replaced by minimal doubles; what reaches the gateway is decoded into readable events.

let failed = 0;
const check = (name, ok, info = '') => { console.log((ok ? 'OK   ' : 'FAIL ') + name + (info ? ' ' + info : '')); if (!ok) failed++; };
const tick = () => new Promise((r) => setTimeout(r, 0));

// ------------------------------------------------------------ browser doubles
class El {
  constructor() { this.l = {}; this.style = {}; this.value = ''; this.width = 1024; this.height = 768; this.offsetWidth = 1024; this.offsetHeight = 768; }
  addEventListener(t, f) { (this.l[t] ||= []).push(f); }
  fire(t, e = {}) { e.target ??= this; e.preventDefault ??= () => { e.prevented = true; }; (this.l[t] || []).forEach((f) => f(e)); return e; }
  getBoundingClientRect() { return { left: 0, top: 0, width: this.width, height: this.height }; }
  getContext() { return this.ctx ||= new Proxy({ calls: [] }, { get: (o, k) => (k in o ? o[k] : (...a) => o.calls.push([k, ...a])) }); }
  focus() { this.focused = true; }
  blur() { if (this.focused) { this.focused = false; this.fire('blur'); } }
  setPointerCapture() {}
}
/** A 2D canvas (and its context) keeping its pixels, 0xAABBGGRR words: image data in and out, drawImage copies 1:1. */
class Pixels {
  constructor(w, h, v) { Object.assign(this, { width: w, height: h, px: new Uint32Array(w * h).fill(v), calls: [], canvas: this }); }
  getContext() { return this; }
  getImageData(x, y, w, h) {
    this.calls.push('getImageData');
    const d = new Uint32Array(w * h);
    for (let j = 0; j < h; j++) d.set(this.px.subarray((y + j) * this.width + x, (y + j) * this.width + x + w), j * w);
    return { width: w, height: h, data: new Uint8ClampedArray(d.buffer) };
  }
  putImageData(img, x, y) {
    this.calls.push('putImageData');
    const d = new Uint32Array(img.data.buffer);
    for (let j = 0; j < img.height; j++) this.px.set(d.subarray(j * img.width, (j + 1) * img.width), (y + j) * this.width + x);
  }
  drawImage(b, sx, sy, w, h, x, y) {
    this.calls.push('drawImage');
    for (let j = 0; j < h; j++) for (let i = 0; i < w; i++) this.px[(y + j) * this.width + x + i] = b.px[(sy + j) * b.width + sx + i];
  }
  save() {} restore() {} beginPath() {} rect() {} clip() {}
}
Object.assign(globalThis, {
  document: { hidden: false, createElement: () => new El(), addEventListener() {} },
  ResizeObserver: class { observe() {} },
  ImageData: class { constructor(data, w, h) { Object.assign(this, { data, w, h }); } },
  Worker: class { constructor() { this.msgs = []; workers.push(this); } postMessage(m) { this.msgs.push(m); } terminate() {} },
  requestAnimationFrame: (f) => setTimeout(f, 0),
  location: { href: 'https://gateway.test/' },
  devicePixelRatio: 1, screen: { width: 1920, height: 1080 },
  matchMedia: (q) => ({ matches: q === '(pointer: coarse)' ? touchOnly : !touchOnly }),
});
globalThis.self = globalThis.window = globalThis;
const workers = [];
let touchOnly = false;                                   // a phone or tablet without mouse; otherwise a computer
const sent = [];                                         // requests to rdp.php?a=send: {payload, keepalive, url, headers}
let hold = null;                                         // array: requests wait here until released
globalThis.fetch = (url, o) => {
  sent.push({ payload: [...o.body.subarray(4)], keepalive: o.keepalive, url, headers: o.headers || {} });
  return hold ? new Promise((r) => hold.push(r)) : Promise.resolve({ ok: true });
};

const { RdpSession } = await import('../public/assets/session.js');
const { SCANCODES, typedText } = await import('../public/assets/keymap.js');
const NAMES = {};
for (const [k, v] of Object.entries(SCANCODES)) NAMES[v] ??= k;

/** Input events sent since index `from`, as text: KeyA↓, mouse:9000 (flags in hex)...; sync events left out. */
function events(from = 0) {
  const out = [];
  for (const { payload: p } of sent.slice(from)) {
    if (p[0] !== 1) { out.push('msg' + p[0]); continue; }
    for (let o = 2, i = 0; i < p[1]; i++) {
      const h = p[o], type = h >> 5;
      if (type === 0) { out.push((NAMES[p[o + 1] | (h & 2 ? 0x100 : 0)] || '?') + (h & 1 ? '↑' : '↓')); o += 2; }
      else if (type === 1) { const f = p[o + 1] | (p[o + 2] << 8); if (f !== 0x800) out.push('mouse:' + f.toString(16)); o += 7; }
      else o += type === 3 ? 1 : type === 4 ? 3 : 7;
    }
  }
  return out;
}

/** A connected session: the stream worker double has delivered the token and "connected". */
async function session(o = {}) {
  const container = new El(), canvas = new El(), keyboard = new El(), states = [];
  const s = new RdpSession({ container, canvas, keyboard, conn: { id: 'c', resize: 'fit' }, csrf: 'x', retries: 3,
    onState: (st, i) => states.push(st + (i.end ? ':' + i.end : '')), ...o });
  s.start();
  await tick();
  const wk = workers.at(-1);
  const status = (j) => wk.onmessage({ data: { t: 'frame', type: 3, d: new TextEncoder().encode(JSON.stringify(j)) } });
  status({ token: 't' });
  status({ connected: true });
  await tick();
  return { s, canvas, keyboard, states, wk, from: sent.length };
}
const key = (k, type, code, m = {}) => k.fire(type, { code, key: m.key || '', ctrlKey: !!m.ctrl, metaKey: !!m.meta, shiftKey: !!m.shift,
  repeat: false, isComposing: !!m.composing, keyCode: m.keyCode || 0, getModifierState: () => false });
const tap = (k, code, m) => { key(k, 'keydown', code, m); key(k, 'keyup', code, m); };
const touch = (id, x, y) => ({ pointerId: id, pointerType: 'touch', clientX: x, clientY: y, button: 0 });
const mouse = (button, buttons) => ({ pointerId: 1, pointerType: 'mouse', clientX: 50, clientY: 50, button, buttons });
const leftHeld = (ev) => ev.filter((e) => e === 'mouse:9000').length > ev.filter((e) => e === 'mouse:1000').length;

// ------------------------------------------------------------ keyboard

{ // Ctrl+V of an image or a file: the upload takes a while and the user goes on typing "ax"
  const { s, keyboard: k, from } = await session();
  let uploaded;
  s.clipboard = { fromPasteEvent: () => new Promise((r) => { uploaded = r; }) };
  key(k, 'keydown', 'ControlLeft', { ctrl: true });
  key(k, 'keydown', 'KeyV', { ctrl: true });
  k.fire('paste', { clipboardData: {} });
  key(k, 'keyup', 'KeyV', { ctrl: true });
  key(k, 'keyup', 'ControlLeft');
  tap(k, 'KeyA');
  tap(k, 'KeyX');
  await tick();
  check('paste: keys typed during the upload do not go out with Ctrl held', events(from).join(' ') === 'ControlLeft↓', events(from).join(' '));
  uploaded();
  await tick();
  check('paste: ...and go after Ctrl+V, in order', events(from).join(' ') === 'ControlLeft↓ KeyV↓ KeyV↑ ControlLeft↑ KeyA↓ KeyA↑ KeyX↓ KeyX↑',
    events(from).join(' '));
}
{ // Ctrl+V twice while the first paste waits for the gateway
  const { s, keyboard: k, from } = await session();
  let n = 0;
  s.clipboard = { fromPasteEvent: () => (n++ ? Promise.resolve() : new Promise((r) => setTimeout(r, 50))) };
  key(k, 'keydown', 'ControlLeft', { ctrl: true });
  for (let i = 0; i < 2; i++) {
    if (!key(k, 'keydown', 'KeyV', { ctrl: true }).prevented) k.fire('paste', { clipboardData: {} });   // the browser pastes unless prevented
    key(k, 'keyup', 'KeyV', { ctrl: true });
  }
  await new Promise((r) => setTimeout(r, 80));
  key(k, 'keyup', 'ControlLeft');
  await tick();
  check('paste: two Ctrl+V give two pastes', events(from).join(' ') === 'ControlLeft↓ KeyV↓ KeyV↑ KeyV↓ KeyV↑ ControlLeft↑', events(from).join(' '));
}
{ // focus lost while a paste waits: no lone "v" afterwards
  const { s, keyboard: k, from } = await session();
  let uploaded;
  s.clipboard = { fromPasteEvent: () => new Promise((r) => { uploaded = r; }) };
  k.focus();
  key(k, 'keydown', 'ControlLeft', { ctrl: true });
  key(k, 'keydown', 'KeyV', { ctrl: true });
  k.fire('paste', { clipboardData: {} });
  k.blur();
  uploaded();
  await tick();
  check('paste: cancelled when the focus goes away', events(from).join(' ') === 'ControlLeft↓ ControlLeft↑', events(from).join(' '));
}
{ // macOS: Cmd+V must paste (Ctrl+V on Windows), not open the clipboard history (Win+V) nor Start
  const { s, keyboard: k, from } = await session();
  s.clipboard = { fromPasteEvent: () => Promise.resolve() };
  key(k, 'keydown', 'MetaLeft', { meta: true });
  key(k, 'keydown', 'KeyV', { meta: true });
  k.fire('paste', { clipboardData: {} });
  await tick();
  key(k, 'keyup', 'MetaLeft');
  await tick();
  check('paste: Cmd+V on a Mac is Ctrl+V', events(from).join(' ') === 'MetaLeft↓ ControlLeft↓ MetaLeft↑ KeyV↓ KeyV↑ ControlLeft↑', events(from).join(' '));
}
{ // Ctrl from the key panel, then a Bluetooth keyboard
  const { s, keyboard: k, from } = await session();
  s.tapKey('ControlLeft');
  tap(k, 'KeyC');
  tap(k, 'KeyX');
  await tick();
  check('key panel: a fixed Ctrl lasts one physical key', events(from).join(' ') === 'ControlLeft↓ KeyC↓ ControlLeft↑ KeyC↑ KeyX↓ KeyX↑', events(from).join(' '));
  const f2 = sent.length;
  s.tapKey('AltLeft');
  k.focus();
  s.showKeyboard(k);                                     // blur + focus to bring up the phone keyboard: Alt stays
  const kept = s.sticky.has('AltLeft');
  k.blur();                                              // the user leaves: Alt goes
  await tick();
  check('key panel: a fixed modifier stays while the phone keyboard opens and goes with the focus', kept && events(f2).join(' ') === 'AltLeft↓ AltLeft↑',
    events(f2).join(' '));
  const f3 = sent.length;
  s.tapKey('ControlLeft');
  k.value = 'ñ';
  k.fire('input', { inputType: 'insertText' });
  await tick();
  check('key panel: a fixed Ctrl lasts one typed character', events(f3).join(' ') === 'ControlLeft↓ msg7 ControlLeft↑', events(f3).join(' '));
}
{ // on-screen keyboard that sends "OK" with shiftKey but no Shift key events, then a tap on the screen
  const { canvas: c, keyboard: k, from } = await session();
  tap(k, 'KeyO', { shift: true });
  tap(k, 'KeyK', { shift: true });
  c.fire('pointerdown', touch(1, 10, 10));
  c.fire('pointerup', touch(1, 10, 10));
  await tick();
  const ev = events(from);
  check('virtual Shift: released with its character, the tap is not Shift+click', ev.join(' ') ===
    'ShiftLeft↓ KeyO↓ KeyO↑ ShiftLeft↑ ShiftLeft↓ KeyK↓ KeyK↑ ShiftLeft↑ mouse:9000 mouse:1000', ev.join(' '));
}
{ // Japanese IME on a computer, "kana" -> かな as Chrome sends it: keys of code 229, the box's text in each input event
  const { keyboard: k, from } = await session();
  const ime = (code, value) => {
    key(k, 'keydown', code, { keyCode: 229, composing: k.value !== '' });
    if (!k.value) k.fire('compositionstart', { data: '' });
    k.value = value;
    k.fire('input', { inputType: 'insertCompositionText', isComposing: true, data: value });
    key(k, 'keyup', code, { keyCode: 229, composing: true });
  };
  [['KeyK', 'k'], ['KeyA', 'か'], ['KeyN', 'かn'], ['KeyA', 'かな']].forEach(([c, v]) => ime(c, v));
  key(k, 'keydown', 'Enter', { keyCode: 229, composing: true });
  await tick();
  const during = sent.length - from, box = k.value;
  k.fire('compositionend', { data: 'かな' });
  k.fire('input', { inputType: 'insertCompositionText', isComposing: false, data: 'かな' });   // Firefox: after compositionend
  key(k, 'keyup', 'Enter');
  await tick();
  check('IME on a computer: nothing goes during the composition, the box keeps it', during === 0 && box === 'かな', `${during} sent, box "${box}"`);
  check('IME on a computer: then the text, once', JSON.stringify(sent.slice(from).map((x) => x.payload)) ===
    JSON.stringify([[8, ...new TextEncoder().encode('かな')]]) && k.value === '', events(from).join(' '));
}
{ // a phone keyboard composes every word: each input goes as it comes, as before (the box is emptied each time)
  touchOnly = true;
  const { keyboard: k, from } = await session();
  k.fire('compositionstart', { data: '' });
  for (const ch of 'hola') { k.value = ch; k.fire('input', { inputType: 'insertCompositionText', isComposing: true, data: ch }); }
  k.fire('compositionend', { data: 'hola' });
  await tick();
  touchOnly = false;
  check('IME on a phone: as before', JSON.stringify(sent.slice(from).map((x) => x.payload)) ===
    JSON.stringify([...'hola'].map((ch) => [7, ...typedText(ch)])), events(from).join(' '));
}
{ // dead key of a physical keyboard (´ + a): its scancodes only, never also the character as text, whether the browser
  // composes the character (macOS, Linux) or not (Windows)
  const { keyboard: k, from } = await session();
  key(k, 'keydown', 'Quote', { key: 'Dead', keyCode: 222 });
  k.fire('compositionstart', { data: '' });
  k.value = '´';
  k.fire('input', { inputType: 'insertCompositionText', isComposing: true, data: '´' });
  key(k, 'keyup', 'Quote', { key: 'Dead', keyCode: 222, composing: true });
  key(k, 'keydown', 'KeyA', { key: 'á', keyCode: 229, composing: true });
  k.value = 'á';
  k.fire('input', { inputType: 'insertCompositionText', isComposing: true, data: 'á' });
  k.fire('compositionend', { data: 'á' });
  key(k, 'keyup', 'KeyA', { key: 'a', keyCode: 65 });
  tap(k, 'Quote', { key: 'Dead', keyCode: 222 });
  tap(k, 'KeyA', { key: 'á', keyCode: 65 });
  await tick();
  check('dead key: scancodes only', events(from).join(' ') === 'Quote↓ Quote↑ KeyA↓ KeyA↑ Quote↓ Quote↑ KeyA↓ KeyA↑' && k.value === '', events(from).join(' '));
}

// ------------------------------------------------------------ mouse and touch

{ // long press (right click) and the finger drifts 15 px before lifting
  const { canvas: c, from } = await session();
  c.fire('pointerdown', touch(1, 100, 100));
  await new Promise((r) => setTimeout(r, 650));
  c.fire('pointermove', touch(1, 115, 100));
  c.fire('pointerup', touch(1, 115, 100));
  await tick();
  check('touch: long press then a small drift leaves no button pressed', events(from).join(' ') === 'mouse:a000 mouse:2000', events(from).join(' '));
}
{ // one-finger drag, then a second finger
  const { canvas: c, from } = await session();
  c.fire('pointerdown', touch(1, 100, 100));
  c.fire('pointermove', touch(1, 140, 100));
  c.fire('pointerdown', touch(2, 300, 300));
  c.fire('pointerup', touch(2, 300, 300));
  c.fire('pointerup', touch(1, 140, 100));
  await tick();
  check('touch: a second finger ends the drag', !leftHeld(events(from)), events(from).join(' '));
}
{ // mouse buttons chorded: Pointer Events send the second button as pointermove
  const { canvas: c, from } = await session();
  c.fire('pointerdown', mouse(0, 1));                    // left
  c.fire('pointermove', mouse(2, 3));                    // + right
  c.fire('pointermove', mouse(0, 2));                    // - left
  c.fire('pointermove', mouse(-1, 2));                   // a plain move
  c.fire('pointerup', mouse(2, 0));                      // - right
  await tick();
  check('mouse: chorded buttons are pressed and released', events(from).join(' ') === 'mouse:9000 mouse:a000 mouse:1000 mouse:2000', events(from).join(' '));
}

// ------------------------------------------------------------ drawing orders

{ // cache bitmap v2 then a MemBlt that draws it (as orders.js emits them)
  const { s, wk } = await session();
  const ctx = new El().getContext();
  s.sctx = ctx;
  wk.onmessage({ data: { t: 'op', cmd: { op: 'cache', id: 1, idx: 5, w: 2, h: 2, px: new Uint32Array(4).fill(0xff0000ff) } } });
  wk.onmessage({ data: { t: 'op', cmd: { op: 'mem', id: 1, idx: 5, x: 10, y: 10, w: 2, h: 2, sx: 0, sy: 0, rop: 0xcc } } });
  const draw = ctx.calls.find(([k]) => k === 'drawImage');
  check('orders: a cached bitmap is stored and MemBlt draws it', !ctx.calls.some(([k]) => k === 'fillRect') && draw && draw[2] === 0 && draw[6] === 10,
    JSON.stringify(ctx.calls.map(([k]) => k)));
}
{ // other ROPs than the plain copy go pixel by pixel within the bounds: MemBlt SRCAND, PatBlt PATINVERT with a pattern
  const { s, wk } = await session();
  const screen = new Pixels(8, 4, 0xff0f0f0f), op = (cmd) => wk.onmessage({ data: { t: 'op', cmd } });
  s.sctx = screen;
  s.bcache.set(65536 + 5, new Pixels(4, 4, 0xff00ff33));
  const hex = () => [...screen.px].map((v) => v.toString(16)).join();
  op({ op: 'mem', id: 1, idx: 5, x: 2, y: 0, w: 4, h: 4, sx: 0, sy: 0, rop: 0x88, clip: { l: 0, t: 0, r: 7, b: 1 } });
  const and = [...screen.px].map((_, i) => (i < 16 && i % 8 >= 2 && i % 8 < 6 ? 0xff000f03 : 0xff0f0f0f));
  check('orders: MemBlt SRCAND on the pixels, within the bounds', screen.px.join() === and.join() && !screen.calls.includes('drawImage'), hex());
  op({ op: 'pattern', x: 0, y: 2, w: 8, h: 2, rows: [0xaa, 0x55, 0xaa, 0x55, 0xaa, 0x55, 0xaa, 0x55], ox: 0, oy: 0, set: 0xffffffff, clear: 0xff000000, rop: 0x5a });
  const xor = and.map((v, i) => (i < 16 || ((i % 8) + (i >> 3)) % 2 ? v : 0xfff0f0f0));
  check('orders: PatBlt PATINVERT with a pattern on the pixels', screen.px.join() === xor.join(), hex());
  screen.calls.length = 0;
  op({ op: 'mem', id: 1, idx: 5, x: 2, y: 0, w: 4, h: 4, sx: 0, sy: 0, rop: 0xcc });
  check('orders: MemBlt SRCCOPY is still a drawImage', screen.calls.join() === 'drawImage', screen.calls.join());
}

// ------------------------------------------------------------ sending, ending, reconnecting

{ // two 40 KB clipboard messages in flight: browsers refuse more than 64 KiB of keepalive bodies (the fetch fails)
  const { s } = await session();
  hold = [];
  const from = sent.length;
  s.sendClip(new Uint8Array(40000));
  s.sendClip(new Uint8Array(40000));
  const ka = sent.slice(from).map((x) => x.keepalive);
  hold.splice(0).forEach((r) => r({ ok: true }));
  hold = null;
  await tick();
  check('send: keepalive stays within 64 KiB in flight', ka.join() === 'true,false' && s.keepalive === 0, ka.join() + ' ' + s.keepalive);
  // the session token goes in a header: in the URL it ended up in the access logs of the web server and proxies
  const r = sent.at(-1);
  check('send: session token in a header, not in the URL', !/[?&]t=/.test(r.url) && r.headers['X-MuDesk-Token'] === 't', r.url);
}
{
  const { s, wk, states } = await session();
  wk.onmessage({ data: { t: 'end', err: 'http 403', end: 'csrf' } });
  check('end: the gateway refusing the stream (web session expired) is told, not retried', states.at(-1) === 'ended:csrf', states.join(' > '));
  s.stop();
}
{ // network cut; the first retry finds the lost session still counted by the gateway
  const { s, wk, states } = await session();
  wk.onmessage({ data: { t: 'end' } });
  await new Promise((r) => setTimeout(r, 1100));
  const w2 = workers.at(-1);
  w2.onmessage({ data: { t: 'frame', type: 3, d: new TextEncoder().encode('{"end":"too_many_sessions"}') } });
  w2.onmessage({ data: { t: 'end' } });
  check('reconnect: too_many_sessions while reconnecting is retried', states.at(-1) === 'reconnecting:too_many_sessions', states.join(' > '));
  s.stop();
}
{ // "Disconnect" when the gateway's answer takes longer than the 300 ms wait
  const { s, wk, states } = await session();
  s.stop();
  wk.onmessage({ data: { t: 'end', err: 'aborted' } });
  check('end: disconnecting is not shown as a lost connection', states.at(-1) === 'ended:user', states.join(' > '));
}
{ // the one retry used up, then the "Reconnect" button
  const { s, wk, states } = await session({ retries: 1 });
  wk.onmessage({ data: { t: 'end' } });
  await new Promise((r) => setTimeout(r, 1100));
  workers.at(-1).onmessage({ data: { t: 'end' } });
  s.start();
  await tick();
  check('reconnect: the button starts counting again', states.at(-1) === 'connecting', states.join(' > '));
}

// ------------------------------------------------------------ stream worker

{
  const posted = [], fetch0 = globalThis.fetch;            // (the gateway double is put back at the end)
  globalThis.postMessage = (m) => posted.push(m);
  globalThis.onmessage = null;
  await import('../public/assets/stream-worker.js');
  const run = async (res) => {
    globalThis.fetch = async () => res;
    posted.length = 0;
    await globalThis.onmessage({ data: { url: 'https://gateway.test/rdp.php?a=stream', headers: {}, body: '' } });
    globalThis.onmessage({ data: { cmd: 'stop' } });
    return posted.filter((m) => m.t !== 'bytes');
  };
  const refused = await run({ ok: false, status: 403, json: async () => ({ error: 'csrf' }) });
  check('worker: the gateway refusal reaches the session', refused.at(-1).t === 'end' && refused.at(-1).end === 'csrf', JSON.stringify(refused.at(-1)));

  // a 4 MB frame (an image copied on the remote computer) arriving in 64 KB reads
  const size = 4e6, frame = new Uint8Array(5 + size);
  frame[0] = 4;
  new DataView(frame.buffer).setUint32(1, size, true);
  const reads = [];
  for (let o = 0; o < frame.length; o += 65536) reads.push(frame.slice(o, o + 65536));
  let moved = 0;
  const copyWithin = Uint8Array.prototype.copyWithin;
  Uint8Array.prototype.copyWithin = function (t, s, e) { moved += e - s; return copyWithin.call(this, t, s, e); };
  const got = await run({ ok: true, body: { getReader: () => ({ read: async () => (reads.length ? { value: reads.shift(), done: false } : { done: true }) }) } });
  Uint8Array.prototype.copyWithin = copyWithin;
  check('worker: a big frame is not copied again on every read', got[0].t === 'frame' && got[0].d.length === size && moved < size,
    `${(moved / 1e6).toFixed(0)} MB moved`);

  // a frame length the gateway never sends: -5 as a signed number (the worker spun for ever on it) or 2^31 - 1 (the buffer
  // grew until the tab died) ends the stream with an error, and the request is cancelled
  const broken = async (n) => {
    const u = new Uint8Array(5 + 16);
    u[0] = 4;
    new DataView(u.buffer).setInt32(1, n, true);
    let aborted = false, reads = [u];
    const abort0 = AbortController.prototype.abort;
    AbortController.prototype.abort = function () { aborted = true; return abort0.call(this); };
    const out = await Promise.race([
      run({ ok: true, body: { getReader: () => ({ read: async () => (reads.length ? { value: reads.shift(), done: false } : { done: true }) }) } }),
      new Promise((r) => setTimeout(() => r('hung'), 2000)),
    ]);
    AbortController.prototype.abort = abort0;
    return out === 'hung' ? 'hung' : [out.at(-1)?.t, /frame length/.test(out.at(-1)?.err ?? ''), aborted];
  };
  const negative = await broken(-5), huge = await broken(0x7fffffff);
  check('worker: an impossible frame length ends the stream (no endless loop, no endless buffer)',
    JSON.stringify([negative, huge]) === JSON.stringify([['end', true, true], ['end', true, true]]), JSON.stringify([negative, huge]));

  // bitmaps up to the desktop (100 x 100: 128 x 128 in whole tiles), sent first: 64 x 64 is drawn, 200 x 100 refused
  const bitmap = (w, h) => {
    const len = w * h * 2, u = new Uint8Array(5 + 1 + 4 + 18 + len), dv = new DataView(u.buffer);
    u[0] = 1; dv.setUint32(1, u.length - 5, true); u[5] = 1;                          // frame 1, bitmap update
    [1, 1, 0, 0, w - 1, h - 1, w, h, 16, 0, len].forEach((v, i) => dv.setUint16(6 + (i < 2 ? i * 2 : 4 + (i - 2) * 2), v, true));
    return u;
  };
  const frames = [Uint8Array.of(2, 5, 0, 0, 0, 100, 0, 100, 0, 16), bitmap(64, 64), bitmap(200, 100)];
  const sizes = await run({ ok: true, body: { getReader: () => ({ read: async () => (frames.length ? { value: frames.shift(), done: false } : { done: true }) }) } });
  const seen = sizes.filter((m) => m.t !== 'end').map((m) => m.t === 'log' ? m.msg : m.t).join(' | ');
  check('worker: bitmaps no larger than the desktop', seen === 'frame | rect | bitmap: bad size or depth (200 x 100 at 16 bpp)', seen);
  globalThis.fetch = fetch0;
}

// several screens: each window shows its remote monitor whole and in proportion; full screen on its screen, 1:1 (before,
// stretched to the window: squashed in a window that was not full screen)
{
  const { s, canvas } = await session();
  s.setMonitors([{ vx: 0, vy: 0, w: 1920, h: 1080, primary: true }, { vx: 1920, vy: 0, w: 1280, h: 1024, primary: false }], 0);
  const main = [canvas.style.width, canvas.style.height];   // in a 1024 x 768 window
  const listeners = {}, win = { innerWidth: 500, innerHeight: 700, addEventListener: (t, f) => { listeners[t] = f; } };
  const other = new El();
  other.ownerDocument = { defaultView: win };
  s.addView(other, new El(), 1);
  const small = [other.style.width, other.style.height];
  Object.assign(win, { innerWidth: 1280, innerHeight: 1024 });   // that window full screen on its 1280 x 1024 screen
  listeners.resize?.();
  check('several screens: each window shows its monitor whole and in proportion, 1:1 in full screen',
    JSON.stringify([main, small, [other.style.width, other.style.height]]) === '[["1024px","576px"],["500px","400px"],["1280px","1024px"]]',
    JSON.stringify([main, small, [other.style.width, other.style.height]]));
}

// several monitors: each one the size of the window that shows it, through Display Control (message 13, applied once the
// server takes it), in the same arrangement; and a drag out of a window onto the other screen lands where the pointer is
// (that window keeps the pointer: its screen position finds the window under it). Before, the monitors kept the size of
// the screens (black margins in windows not full screen) and this window's scale was carried on past its edge.
{
  const { s, canvas, wk } = await session({ conn: { id: 'c', resize: 'dynamic' } });
  s.setMonitors([{ vx: 0, vy: 0, w: 1920, h: 1080, primary: true }, { vx: 1920, vy: 0, w: 1920, h: 1080, primary: false }], 0);
  const win = { innerWidth: 1000, innerHeight: 700, mozInnerScreenX: 1920, mozInnerScreenY: 0, addEventListener() {} };
  const other = new El();
  other.ownerDocument = { defaultView: win };
  s.addView(other, new El(), 1);
  const from = sent.length;
  wk.onmessage({ data: { t: 'frame', type: 5, d: new Uint8Array(0) } });   // Display Control is there
  await tick();
  const asked = sent.slice(from).map((x) => x.payload).find((p) => p[0] === 13);
  const layout = asked && JSON.parse(new TextDecoder().decode(new Uint8Array(asked.slice(1))));
  wk.onmessage({ data: { t: 'frame', type: 2, d: new Uint8Array([2024 & 255, 2024 >> 8, 768 & 255, 768 >> 8, 32]) } });   // taken: 2024 x 768
  const sizes = [[canvas.width, canvas.height], [other.width, other.height], s.mons.map((m) => [m.x, m.y])];
  Object.assign(globalThis, { mozInnerScreenX: 0, mozInnerScreenY: 0 });
  const n = sent.length;
  canvas.fire('pointermove', { pointerId: 1, pointerType: 'mouse', clientX: 1224, clientY: 300, screenX: 2420, screenY: 300, button: -1, buttons: 1 });
  await tick();
  const moves = [];
  for (const { payload: p } of sent.slice(n)) {
    if (p[0] !== 1) continue;
    for (let o = 2, i = 0; i < p[1]; i++) {
      const type = p[o] >> 5;
      if (type === 1) moves.push([p[o + 3] | (p[o + 4] << 8), p[o + 5] | (p[o + 6] << 8)]);
      o += type === 0 ? 2 : type === 3 ? 1 : type === 4 ? 3 : 7;
    }
  }
  delete globalThis.mozInnerScreenX;
  delete globalThis.mozInnerScreenY;
  check('several monitors: each the size of its window (Display Control), the new layout once taken; a drag onto the other screen lands under the pointer',
    JSON.stringify(layout) === '[[0,0,1024,768,1],[1024,0,1000,700,0]]' && JSON.stringify(sizes) === '[[1024,768],[1000,700],[[0,0],[1024,0]]]'
    && JSON.stringify(moves.at(-1)) === '[1524,300]', JSON.stringify({ layout, sizes, moves }));
}

// several screens: a screen is a remote monitor only while its window is open, in any resize mode. At the start the session
// has this window's monitor alone (a plain one-monitor connection); a window opened adds its monitor (Display Control,
// applied once the server takes it; input there waits for it), a window closed removes it (one monitor: the plain resize).
{
  const { s, canvas, wk } = await session();             // resize "fit": monitors the size of the screens
  s.setMonitors([{ vx: 0, vy: 0, w: 1920, h: 1080, primary: true }, { vx: 1920, vy: 0, w: 1280, h: 1024, primary: false }], 0);
  const alone = s.mons.map((m) => [m.i, m.w, m.h]);
  wk.onmessage({ data: { t: 'frame', type: 5, d: new Uint8Array(0) } });   // Display Control is there
  await tick();
  const n0 = sent.length;
  const win = { innerWidth: 1280, innerHeight: 1024, addEventListener() {} };
  const other = new El();
  other.ownerDocument = { defaultView: win };
  s.addView(other, new El(), 1);
  await tick();
  other.fire('pointermove', { pointerId: 1, pointerType: 'mouse', clientX: 10, clientY: 10, button: -1, buttons: 0 });   // not taken yet
  await tick();
  const asked = sent.slice(n0).map((x) => x.payload);
  wk.onmessage({ data: { t: 'frame', type: 2, d: new Uint8Array([3200 & 255, 3200 >> 8, 1080 & 255, 1080 >> 8, 32]) } });   // taken
  const both = s.mons.map((m) => [m.i, m.x, m.y, m.w, m.h]);
  const n1 = sent.length;
  s.removeView(other);
  await tick();
  const removed = sent.slice(n1).map((x) => x.payload);
  wk.onmessage({ data: { t: 'frame', type: 2, d: new Uint8Array([1920 & 255, 1920 >> 8, 1080 & 255, 1080 >> 8, 32]) } });
  const after = s.mons.map((m) => [m.i, m.w, m.h]);
  check('several screens: a remote monitor only for the screens whose window is open, added and removed as windows open and close',
    JSON.stringify(alone) === '[[0,1920,1080]]' && asked.length === 1 && asked[0][0] === 13
    && new TextDecoder().decode(new Uint8Array(asked[0].slice(1))) === '[[0,0,1920,1080,1],[1920,0,1280,1024,0]]'
    && JSON.stringify(both) === '[[0,0,0,1920,1080],[1,1920,0,1280,1024]]'
    && JSON.stringify(removed) === JSON.stringify([[2, 1920 & 255, 1920 >> 8, 1080 & 255, 1080 >> 8]]) && JSON.stringify(after) === '[[0,1920,1080]]',
    JSON.stringify({ alone, asked, both, removed, after }));
}

// a desktop over 32766 a side or 2^26 pixels is not taken (a hostile server made the page allocate gigabytes; the gateway
// refuses it too)
{
  const { s, wk } = await session();
  const before = [s.surface.width, s.surface.height];
  wk.onmessage({ data: { t: 'frame', type: 2, d: new Uint8Array([40000 & 255, 40000 >> 8, 100, 0, 32]) } });
  wk.onmessage({ data: { t: 'frame', type: 2, d: new Uint8Array([0x00, 0x20, 0x01, 0x20, 32]) } });    // 8192 x 8193
  const kept = [s.surface.width, s.surface.height];
  wk.onmessage({ data: { t: 'frame', type: 2, d: new Uint8Array([0x00, 0x20, 0x00, 0x20, 32]) } });    // 8192 x 8192: taken
  check('a desktop over 32766 a side or 8192 x 8192 pixels is not taken', JSON.stringify(kept) === JSON.stringify(before)
    && s.surface.width === 8192 && s.surface.height === 8192, JSON.stringify({ before, kept }));
}

// a window opened while connecting: its monitor is asked for once connected (the request had gone without it; the layout
// changed here only, so the server was never asked and that screen stayed black)
{
  const container = new El(), canvas = new El(), keyboard = new El();
  const s = new RdpSession({ container, canvas, keyboard, conn: { id: 'c', resize: 'fit' }, csrf: 'x', retries: 3, onState: () => {} });
  s.setMonitors([{ vx: 0, vy: 0, w: 1920, h: 1080, primary: true }, { vx: 1920, vy: 0, w: 1280, h: 1024, primary: false }], 0);
  s.start();
  await tick();
  const wk = workers.at(-1);
  const other = new El();
  other.ownerDocument = { defaultView: { innerWidth: 1280, innerHeight: 1024, addEventListener() {} } };
  s.addView(other, new El(), 1);                         // connecting: no session id yet
  const status = (j) => wk.onmessage({ data: { t: 'frame', type: 3, d: new TextEncoder().encode(JSON.stringify(j)) } });
  status({ token: 't' });
  status({ connected: true });
  await tick();
  const n0 = sent.length;
  wk.onmessage({ data: { t: 'frame', type: 5, d: new Uint8Array(0) } });   // Display Control is there
  await new Promise((r) => setTimeout(r, 450));
  const asked = sent.slice(n0).map((x) => x.payload).filter((p) => p[0] === 13).map((p) => new TextDecoder().decode(new Uint8Array(p.slice(1))));
  check('several screens: a window opened while connecting gets its monitor once connected', asked.length >= 1
    && asked.every((a) => a === '[[0,0,1920,1080,1],[1920,0,1280,1024,0]]'), JSON.stringify(asked));
}

// RemoteApp: only the windows of the program are copied to the page (their visible region, else their frame), over the page:
// not what the remote desktop kept behind them; a minimized or deleted window goes; updates are copied where they are a
// window; the windows listed with the active one; a window brought to the front (restored first), closed; reconnected after
// the connection dropped, said so (the gateway starts the program again only if its windows do not come back)
{
  const { s, canvas, wk } = await session({ conn: { id: 'c', resize: 'fit', app: '||Notepad' } });
  const listed = [];
  s.onRail = (w) => listed.push(w);
  wk.onmessage({ data: { t: 'frame', type: 2, d: new Uint8Array([0x00, 0x04, 0x00, 0x03, 16]) } });   // 1024 x 768
  const op = (cmd) => wk.onmessage({ data: { t: 'op', cmd } });
  canvas.ctx.calls.length = 0;
  op({ op: 'window', id: 1, new: true, owner: 0, show: 5, title: 'Bloc de notas', x: 100, y: 50, w: 300, h: 200 });
  op({ op: 'window', id: 2, new: true, owner: 0, show: 5, title: 'Guardar', x: 500, y: 400, w: 200, h: 100, visX: 504, visY: 400, vis: [[0, 0, 192, 96]] });
  op({ op: 'window', id: 3, new: true, owner: 1, show: 5, title: 'menú', x: 120, y: 80, w: 50, h: 40 });
  op({ op: 'desktop', active: 2 });
  await new Promise((r) => setTimeout(r, 5));
  const first = canvas.ctx.calls.filter((c) => c[0] === 'clearRect' || c[0] === 'drawImage').map((c) => c.slice(0, c[0] === 'drawImage' ? 5 : 5).map((v) => (typeof v === 'object' ? 'surface' : v)).join(' '));
  canvas.ctx.calls.length = 0;
  wk.onmessage({ data: { t: 'rect', x: 0, y: 0, w: 450, h: 120, cw: 450, ch: 120, px: new Uint8Array(450 * 120 * 4) } });   // a bitmap over window 1 and outside
  await new Promise((r) => setTimeout(r, 5));
  const copied = canvas.ctx.calls.filter((c) => c[0] === 'drawImage').map((c) => c.slice(1, 9).map((v) => (typeof v === 'object' ? 'surface' : v)).join(' '));
  op({ op: 'window', id: 1, show: 2 });                // minimized
  op({ op: 'window', id: 3, deleted: true });
  await new Promise((r) => setTimeout(r, 5));
  const n0 = sent.length;
  s.railCommand(1, 'front');
  s.railCommand(2, 'close');
  s.railCommand(99, 'close');
  const cmds = sent.slice(n0).filter((x) => x.payload[0] === 14).map((x) => new TextDecoder().decode(new Uint8Array(x.payload.slice(1))));
  check('RemoteApp: only the program\'s windows on the page, updates copied where they are windows; listed (active marked); commands',
    first[0] === 'clearRect 0 0 1024 768' && first.includes('drawImage surface 100 50 300') && first.includes('drawImage surface 504 400 192') && first.includes('drawImage surface 120 80 50')
    && JSON.stringify(copied) === JSON.stringify(['surface 100 50 300 70 100 50 300', 'surface 120 80 50 40 120 80 50'])
    && JSON.stringify(listed.at(-1)) === JSON.stringify([{ id: 1, title: 'Bloc de notas', minimized: true, active: false }, { id: 2, title: 'Guardar', minimized: false, active: true }])
    && JSON.stringify(cmds) === JSON.stringify(['{"a":"sys","id":1,"c":61728}', '{"a":"activate","id":1}', '{"a":"sys","id":2,"c":61536}']),
    JSON.stringify({ first, copied, listed: listed.at(-1), cmds }));
  const n1 = sent.length;
  op({ op: 'desktop', completed: true });              // the server sent its windows: the gateway may ask for the program
  op({ op: 'desktop', completed: true });
  op({ op: 'desktop', began: true });                  // it sends them again: the old ones go
  await new Promise((r) => setTimeout(r, 5));
  const ready = sent.slice(n1).filter((x) => x.payload[0] === 14).map((x) => new TextDecoder().decode(new Uint8Array(x.payload.slice(1))));
  check('RemoteApp: the desktop synced reported once (then the program is asked for); windows discarded when the server sends them again',
    JSON.stringify(ready) === '["{\\"a\\":\\"ready\\",\\"open\\":2}"]' && s.railWindows().length === 0, JSON.stringify({ ready, wins: s.railWindows() }));
  // the window resized: what the remote desktop had is kept (a new size empties a canvas: the server moves its windows by
  // copying from there, and they came back in mixed pieces), then the server asked to draw it all again
  const sctx = s.surface.getContext('2d');
  sctx.calls.length = 0;
  const n2 = sent.length;
  wk.onmessage({ data: { t: 'frame', type: 2, d: new Uint8Array([0x00, 0x02, 0x00, 0x03, 16]) } });   // 512 x 768
  const kept = sctx.calls.some((c) => c[0] === 'drawImage' && c[2] === 0 && c[3] === 0 && c[1]?.width === 1024);
  await new Promise((r) => setTimeout(r, 450));
  const refreshed = sent.slice(n2).some((x) => x.payload[0] === 6);
  check('RemoteApp: the window resized, the remote desktop kept and then drawn again by the server', kept && refreshed && s.canvas.width === 512,
    JSON.stringify({ kept, refreshed, w: s.canvas.width }));
  const w0 = workers.length;
  wk.onmessage({ data: { t: 'end' } });                // the connection dropped: reconnected
  await new Promise((r) => setTimeout(r, 1200));
  const body = JSON.parse(workers.at(-1)?.msgs[0]?.body || '{}');
  check('RemoteApp: reconnected after the connection dropped, said so (again), its windows sent anew by the server',
    workers.length === w0 + 1 && body.again === true && s.railWindows().length === 0, JSON.stringify({ again: body.again, wins: s.railWindows() }));
  s.stop();
}

// RemoteApp: the program's last window closed, the session ends a moment later as "the program was closed" (Windows keeps
// it: before, the page stayed connected and empty); not while one window closes and the next opens, nor while the server
// sends its windows again; windows a program keeps for a while as it closes (of no size, with nothing visible, hidden) do
// not keep it open (they did for about 15 s); Reconnect then is a new connection, where the program is started again only
// if its windows do not come back (before, it was taken for a reconnection and the program was not asked for)
{
  const { s, wk, states } = await session({ conn: { id: 'c', resize: 'fit', app: '||Notepad' } });
  const wait0 = s.rail.goneMs;
  s.rail.goneMs = 40;
  const op = (cmd) => wk.onmessage({ data: { t: 'op', cmd } });
  const quits = () => sent.filter((x) => x.payload[0] === 5).length;
  const q0 = quits();
  await new Promise((r) => setTimeout(r, 80));
  const notYet = quits() - q0;                          // no window ever: the program is still starting
  op({ op: 'window', id: 1, new: true, owner: 0, show: 5, title: 'Inicio de sesión', x: 10, y: 10, w: 300, h: 200 });
  op({ op: 'window', id: 1, deleted: true });           // its sign-in closed, its main window comes a moment later
  await new Promise((r) => setTimeout(r, 10));
  op({ op: 'window', id: 2, new: true, owner: 0, show: 5, title: 'Programa', x: 0, y: 0, w: 800, h: 600 });
  op({ op: 'window', id: 3, new: true, owner: 2, show: 0, title: '', x: 0, y: 0, w: 1, h: 1 });   // a hidden one of its own
  op({ op: 'desktop', began: true });                   // the server sends its windows again
  await new Promise((r) => setTimeout(r, 80));
  op({ op: 'window', id: 2, new: true, owner: 0, show: 2, title: 'Programa', x: 0, y: 0, w: 800, h: 600 });   // minimized: open
  op({ op: 'window', id: 3, new: true, owner: 2, show: 0, title: '', x: 0, y: 0, w: 1, h: 1 });
  op({ op: 'desktop', completed: true });
  await new Promise((r) => setTimeout(r, 80));
  const kept = quits() - q0;
  op({ op: 'window', id: 4, new: true, owner: 0, show: 5, title: 'Programa', x: 600, y: 400, w: 0, h: 0 });   // of no size
  op({ op: 'window', id: 5, new: true, owner: 0, show: 5, title: '', x: 0, y: 0, w: 200, h: 100, visX: 0, visY: 0, vis: [] });   // nothing visible
  op({ op: 'window', id: 2, deleted: true });           // closed: only those left
  await new Promise((r) => setTimeout(r, 80));
  const closed = quits() - q0;
  wk.onmessage({ data: { t: 'end' } });                 // the gateway's stream ends
  await new Promise((r) => setTimeout(r, 30));
  const w0 = workers.length;
  s.start();                                            // Reconnect
  await new Promise((r) => setTimeout(r, 30));
  const body = JSON.parse(workers.at(-1)?.msgs[0]?.body || '{}');
  check('RemoteApp: its last window closed, the session ends as "program closed"; Reconnect a new connection (the program started again unless its windows come back)',
    wait0 === 3000 && notYet === 0 && kept === 0 && closed === 1 && states.at(-2) === 'ended:app_closed' && workers.length === w0 + 1
    && body.again === undefined && body.resume === true,
    JSON.stringify({ wait0, notYet, kept, closed, states, again: body.again, resume: body.resume }));
  s.stop();
}

// RemoteApp, Reconnect after the program closed: the program is asked for at once. Windows keeps some windows in every
// session (one of no size per screen, hidden ones), also before the program first opens: they are not the program. Taken
// for the program still closing, they kept it from being asked for, and the server ended the session some 12 s later (no
// program there: error info 0x0B), seen in a real trace. "Opening" (a spinner) until its window comes; still nothing after a
// while, "taking longer than usual"
{
  const { s, wk } = await session({ conn: { id: 'c', resize: 'fit', app: '||Notepad' } });
  Object.assign(s.rail, { goneMs: 20, slowMs: 200 });
  const op = (cmd, w = wk) => w.onmessage({ data: { t: 'op', cmd } });
  const rails = (from) => sent.slice(from).filter((x) => x.payload[0] === 14).map((x) => new TextDecoder().decode(new Uint8Array(x.payload.slice(1))));
  const kept = (w) => {                                 // as in the trace: two of no size (one per screen), one hidden
    op({ op: 'window', id: 101, new: true, owner: 0, show: 5, title: '', x: 0, y: 0, w: 0, h: 0, visX: 0, visY: 0, vis: [] }, w);
    op({ op: 'window', id: 102, new: true, owner: 0, show: 5, title: '', x: 1920, y: 0, w: 0, h: 0, visX: 1920, visY: 0, vis: [] }, w);
    op({ op: 'window', id: 103, new: true, owner: 0, show: 0, title: '', x: -1, y: -8, w: 1918, h: 952, visX: -1, visY: -8, vis: [[0, 0, 1918, 952]] }, w);
  };
  op({ op: 'desktop', began: true });
  kept(wk);
  op({ op: 'desktop', completed: true });
  op({ op: 'window', id: 200, new: true, owner: 0, show: 3, title: 'Bloc de notas', x: 0, y: 0, w: 1920, h: 907 });
  op({ op: 'window', id: 200, deleted: true });         // closed: the session ends
  await new Promise((r) => setTimeout(r, 50));
  wk.onmessage({ data: { t: 'end' } });
  await new Promise((r) => setTimeout(r, 10));
  s.start();                                            // Reconnect
  await new Promise((r) => setTimeout(r, 10));
  const w2 = workers.at(-1);
  const st = (j) => w2.onmessage({ data: { t: 'frame', type: 3, d: new TextEncoder().encode(JSON.stringify(j)) } });
  st({ token: 't2' });
  st({ connected: true });
  const before = s.railPending();                       // connected, the server's windows not here yet
  const from = sent.length;
  op({ op: 'desktop', began: true }, w2);
  kept(w2);
  op({ op: 'desktop', completed: true }, w2);
  await new Promise((r) => setTimeout(r, 30));
  const asked = [rails(from), s.railPending()];
  await new Promise((r) => setTimeout(r, 250));
  const later = s.railPending();
  check('RemoteApp: Reconnect asks for the program at once, the windows Windows keeps in every session not taken for it; "Opening", then "taking longer"',
    before === 'opening' && JSON.stringify(asked) === JSON.stringify([['{"a":"ready","open":0}'], 'opening']) && later === 'slow',
    JSON.stringify({ before, asked, later }));
  s.stop();
}
{
  // the server ends the session while the program is still opening (error info 0x0B, no program there yet, some 12 s after
  // the desktop synced): connected again quietly (the page keeps saying "Opening"), its windows come back. Before, the page
  // offered to open it and "Disconnected" came right after. Once it was there, an end is said as usual
  const log = [];
  const { s, wk } = await session({ conn: { id: 'c', resize: 'fit', app: '||Notepad' }, onState: (st, i) => log.push([st, i.quiet ?? null, i.end ?? null]) });
  const st = (w, j) => w.onmessage({ data: { t: 'frame', type: 3, d: new TextEncoder().encode(JSON.stringify(j)) } });
  const op = (w, cmd) => w.onmessage({ data: { t: 'op', cmd } });
  op(wk, { op: 'desktop', completed: true });           // the program asked for
  const w0 = workers.length, n0 = log.length;
  st(wk, { errorInfo: 11 });
  st(wk, { end: 'error_info', errorInfo: 11 });
  wk.onmessage({ data: { t: 'end' } });
  const between = s.railPending();                      // while connecting again
  await new Promise((r) => setTimeout(r, 400));
  const w2 = workers.at(-1), body = JSON.parse(w2?.msgs[0]?.body || '{}');
  st(w2, { token: 't9' });
  st(w2, { connected: true });
  op(w2, { op: 'desktop', completed: true });
  op(w2, { op: 'window', id: 7, new: true, owner: 0, show: 5, title: 'Bloc de notas', x: 0, y: 0, w: 800, h: 600 });   // it came
  const quietly = log.slice(n0);
  const n1 = log.length;
  st(w2, { errorInfo: 11 });                            // now (it was there) an end is an end
  st(w2, { end: 'error_info', errorInfo: 11 });
  w2.onmessage({ data: { t: 'end' } });
  await new Promise((r) => setTimeout(r, 50));
  check('RemoteApp: ended by the server while the program was opening, connected again quietly; once it was there, an end is said',
    workers.length >= w0 + 1 && between === 'opening' && body.again === true && JSON.stringify(quietly) === JSON.stringify([['connecting', true, null], ['connected', null, null]])
    && JSON.stringify(log.slice(n1)) === JSON.stringify([['ended', null, 'error_info']]), JSON.stringify({ between, again: body.again, quietly, after: log.slice(n1) }));
  s.stop();
}
{
  // and it has an end: giveUpMs after it was first asked for without its window, the session ends saying it did not open,
  // whether the server is still connected (the page stops it) or ends it then
  const log = [];
  const { s, wk } = await session({ conn: { id: 'c', resize: 'fit', app: '||Notepad' }, onState: (st, i) => log.push([st, i.end ?? null]) });
  s.rail.giveUpMs = 150;
  wk.onmessage({ data: { t: 'op', cmd: { op: 'desktop', completed: true } } });
  const q0 = sent.filter((x) => x.payload[0] === 5).length;
  await new Promise((r) => setTimeout(r, 250));
  const quit = sent.filter((x) => x.payload[0] === 5).length - q0;
  wk.onmessage({ data: { t: 'end' } });
  await new Promise((r) => setTimeout(r, 20));
  check('RemoteApp: no window of the program giveUpMs after it was asked for, the session ends saying it did not open',
    quit === 1 && JSON.stringify(log.at(-1)) === '["ended","app_not_opened"]', JSON.stringify({ quit, last: log.at(-1) }));
}
{
  // once the program's window came, its closing does not say "Opening" (before, it did, until the session ended)
  const { s, wk } = await session({ conn: { id: 'c', resize: 'fit', app: '||Notepad' } });
  const op = (cmd) => wk.onmessage({ data: { t: 'op', cmd } });
  op({ op: 'desktop', completed: true });
  const opening = s.railPending();
  op({ op: 'window', id: 5, new: true, owner: 0, show: 5, title: 'Bloc de notas', x: 0, y: 0, w: 800, h: 600 });
  const shown = s.railPending();
  op({ op: 'window', id: 5, deleted: true });
  const closed = s.railPending();
  check('RemoteApp: once the program\'s window came, its closing does not say "Opening"', opening === 'opening' && shown === null && closed === null,
    JSON.stringify({ opening, shown, closed }));
  s.stop();
}

// RemoteApp, the windows of a program starting, as in a real trace: tool windows (a strip 4 px high, a hint window) and a
// splash screen without a title are not "the program is here" (they ended the spinner, and its closing was then taken from
// them); its main window, owned by a hidden window of its own (sent as 0xFFFFFFFE), is on the taskbar (it was left out),
// a titled one of no size is not
{
  const { s, wk } = await session({ conn: { id: 'c', resize: 'fit', app: '||Notepad' } });
  s.rail.goneMs = 30;
  const op = (cmd) => wk.onmessage({ data: { t: 'op', cmd } });
  const quits = () => sent.filter((x) => x.payload[0] === 5).length;
  const q0 = quits();
  op({ op: 'desktop', completed: true });
  op({ op: 'window', id: 1, new: true, owner: 0, show: 5, title: '', exStyle: 0x08200088, x: 1935, y: 0, w: 1889, h: 4 });
  op({ op: 'window', id: 2, new: true, owner: 0xFFFFFFFE, show: 5, title: 'x'.repeat(44), exStyle: 0x08000088, x: 252, y: 0, w: 248, h: 22 });
  const withTools = s.railPending();
  op({ op: 'window', id: 1, show: 0 });
  op({ op: 'window', id: 3, new: true, owner: 0, show: 5, title: '', exStyle: 0, x: 600, y: 300, w: 400, h: 300 });   // splash
  const withSplash = s.railPending();
  op({ op: 'window', id: 3, deleted: true });
  await new Promise((r) => setTimeout(r, 80));
  const afterSplash = [s.railPending(), quits() - q0];
  op({ op: 'window', id: 4, new: true, owner: 0, show: 5, title: 'Inicio', exStyle: 0x100, x: 960, y: 473, w: 0, h: 0 });
  op({ op: 'window', id: 5, new: true, owner: 0xFFFFFFFE, show: 3, title: 'Programa', exStyle: 0x10100, x: 0, y: 0, w: 1920, h: 907 });
  await new Promise((r) => setTimeout(r, 5));
  const here = s.railPending(), listed = s.railWindows().map((w) => w.title);
  check('RemoteApp: tool windows and an untitled splash are not the program being there; its main window owned by a hidden one is on the taskbar',
    withTools === 'opening' && withSplash === null && JSON.stringify(afterSplash) === '["opening",0]' && here === null && JSON.stringify(listed) === '["Programa"]',
    JSON.stringify({ withTools, withSplash, afterSplash, here, listed }));
  s.stop();
}

// RemoteApp on several screens: this window's screen is the primary monitor (the program opens here, where its taskbar is)
// though it is not this computer's primary; each window of the program goes to the window of the screen it is on, moved
// by where the primary is on the desktop (a screen at its left: negative coordinates); the other screen's window is
// transparent too, only the windows on it. Before, RemoteApp had one screen.
{
  const { s, canvas, wk } = await session({ conn: { id: 'c', resize: 'fit', app: '||Notepad' } });
  s.setMonitors([{ vx: 0, vy: 0, w: 1280, h: 1024, primary: true }, { vx: 1280, vy: 0, w: 1920, h: 1080, primary: false }], 1);
  const other = new El();
  other.ownerDocument = { defaultView: { innerWidth: 1000, innerHeight: 700, addEventListener() {} } };
  let alpha;
  other.getContext = function (type, o) { alpha = o?.alpha; return El.prototype.getContext.call(this); };
  s.addView(other, new El(), 0);                        // the window of the screen at the left
  const from = sent.length;
  wk.onmessage({ data: { t: 'frame', type: 5, d: new Uint8Array(0) } });   // Display Control is there
  await tick();
  const asked = sent.slice(from).map((x) => x.payload).find((p) => p[0] === 13);
  const layout = asked && JSON.parse(new TextDecoder().decode(new Uint8Array(asked.slice(1))));
  wk.onmessage({ data: { t: 'frame', type: 2, d: new Uint8Array([2024 & 255, 2024 >> 8, 768 & 255, 768 >> 8, 32]) } });   // taken
  await new Promise((r) => setTimeout(r, 5));
  canvas.ctx.calls.length = 0;
  other.ctx.calls.length = 0;
  const op = (cmd) => wk.onmessage({ data: { t: 'op', cmd } });
  op({ op: 'window', id: 1, new: true, owner: 0, show: 5, title: 'Bloc de notas', x: 100, y: 50, w: 300, h: 200 });   // on this screen
  op({ op: 'window', id: 2, new: true, owner: 0, show: 5, title: 'Buscar', x: -900, y: 20, w: 200, h: 100 });         // on the left one
  op({ op: 'window', id: 3, new: true, owner: 0, show: 5, title: 'Ancha', x: -100, y: 400, w: 300, h: 50 });          // across both
  await new Promise((r) => setTimeout(r, 5));
  const draws = (c) => c.ctx.calls.filter((x) => x[0] === 'drawImage').map((x) => x.slice(2).join(' '));
  const [here, there] = [draws(canvas), draws(other)];
  check('RemoteApp on several screens: this window\'s screen the primary monitor; each window of the program on the window of its screen, the other transparent',
    JSON.stringify(layout) === '[[-1000,0,1000,700,0],[0,0,1024,768,1]]' && alpha === true
    && JSON.stringify(here) === JSON.stringify(['1100 50 300 200 100 50 300 200', '1000 400 200 50 0 400 200 50'])
    && JSON.stringify(there) === JSON.stringify(['100 20 200 100 100 20 200 100', '900 400 100 50 900 400 100 50']),
    JSON.stringify({ layout, alpha, here, there }));
  s.stop();
}

// ------------------------------------------------------------ sending under load, reconnection, screens, touch, phone keyboard

{
  // the mouse kept moving with every request slot busy: frame acknowledgements were sent only when nothing else waited, so
  // they starved (Windows paces its frames by them: the screen stalled while dragging), and a key waited behind the moves
  const { s, canvas, keyboard: k } = await session();
  hold = [];
  const mv = (x) => canvas.fire('pointermove', { pointerId: 1, pointerType: 'mouse', clientX: x, clientY: 5, button: -1, buttons: 0 });
  const from = sent.length;
  for (let i = 0; i < 12; i++) {
    mv(10 + i); mv(11 + i); mv(12 + i);
    s.gfx.apply({ op: 'end', frame: i + 1 });
    await tick();
    hold.shift()?.();
    await tick();
  }
  const acks = sent.slice(from).filter((r) => r.payload[0] === 10).length;
  const before = sent.length;
  tap(k, 'KeyA');
  await tick();
  const keyOut = events(before).includes('KeyA↓');
  hold.splice(0).forEach((r) => r());
  hold = null;
  await tick();
  check('sending: frame acknowledgements go out while the mouse keeps moving, and a key never waits behind moves', acks >= 4 && keyOut,
    JSON.stringify({ acks, keyOut }));
  s.stop();
}
{
  // requests still in flight when the connection drops: the new connection counted their ends as its own (the count went
  // below 0, then 8 requests at once instead of 4), and the lock keys were not synced again after it
  const syncs = (from) => sent.slice(from).filter((r) => r.payload[0] === 1).reduce((n, { payload: p }) => {
    for (let o = 2, i = 0; i < p[1]; i++) { const t = p[o] >> 5; if (t === 3) n++; o += t === 0 ? 2 : t === 3 ? 1 : t === 4 ? 3 : 7; }
    return n;
  }, 0);
  const caps = (k, type) => k.fire(type, { code: 'KeyA', key: 'a', ctrlKey: false, metaKey: false, shiftKey: false, repeat: false, isComposing: false,
    keyCode: 0, getModifierState: (m) => m === 'CapsLock' });
  const { s, canvas, keyboard: k } = await session();
  const f1 = sent.length;
  caps(k, 'keydown'); caps(k, 'keyup');
  await tick();
  const sync1 = syncs(f1);
  hold = [];
  for (let i = 0; i < 6; i++) { canvas.fire('pointermove', { pointerId: 1, pointerType: 'mouse', clientX: i * 10, clientY: 5, button: -1, buttons: 0 }); await tick(); }
  workers.at(-1).onmessage({ data: { t: 'end' } });
  await new Promise((r) => setTimeout(r, 1100));                     // the first retry comes after 1 s
  const w2 = workers.at(-1), st = (j) => w2.onmessage({ data: { t: 'frame', type: 3, d: new TextEncoder().encode(JSON.stringify(j)) } });
  st({ token: 't2' }); st({ connected: true });
  await tick();
  hold.splice(0).forEach((r) => r());                               // the old connection's requests answer now
  await tick();
  const f2 = sent.length;
  for (let i = 0; i < 12; i++) { canvas.fire('pointermove', { pointerId: 1, pointerType: 'mouse', clientX: 100 + i, clientY: 5, button: -1, buttons: 0 }); await tick(); }
  const atOnce = sent.length - f2;
  hold.splice(0).forEach((r) => r());
  hold = null;
  await tick();
  const f3 = sent.length;
  caps(k, 'keydown'); caps(k, 'keyup');
  await tick();
  check('reconnection: requests of the connection before not counted, at most 4 at once; lock keys synced again',
    s.inflight >= 0 && atOnce <= 4 && sync1 === 1 && syncs(f3) === 1, JSON.stringify({ inflight: s.inflight, atOnce, sync1, sync2: syncs(f3) }));
  s.stop();
}
{
  // several screens: hiding this window (minimized, another tab) stopped the server drawing while the other screen's
  // window was still in view; only when no window of the session is seen
  const docL = {}, add0 = document.addEventListener;
  document.addEventListener = (t, f) => (docL[t] ||= []).push(f);
  const { s } = await session();
  document.addEventListener = add0;
  s.setMonitors([{ vx: 0, vy: 0, w: 1920, h: 1080, primary: true }, { vx: 1920, vy: 0, w: 1920, h: 1080, primary: false }], 0);
  const otherL = {}, other = new El();
  other.ownerDocument = { hidden: false, addEventListener: (t, f) => (otherL[t] ||= []).push(f), defaultView: { innerWidth: 1920, innerHeight: 1080, addEventListener() {} } };
  s.addView(other, new El(), 1);
  const f = sent.length;
  document.hidden = true;
  docL.visibilitychange?.forEach((g) => g());
  await tick();
  const whileOther = sent.slice(f).filter((r) => r.payload[0] === 3).map((r) => r.payload[1]);
  other.ownerDocument.hidden = true;
  otherL.visibilitychange?.forEach((g) => g());
  await tick();
  const allHidden = sent.slice(f).filter((r) => r.payload[0] === 3).map((r) => r.payload[1]);
  document.hidden = false;
  docL.visibilitychange?.forEach((g) => g());
  await tick();
  const back = sent.slice(f).filter((r) => r.payload[0] === 3).map((r) => r.payload[1]);
  check('screens: the server keeps drawing while one window of the session is seen; stops when none is',
    JSON.stringify([whileOther, allHidden, back]) === JSON.stringify([[], [0], [0, 1]]), JSON.stringify([whileOther, allHidden, back]));
  s.stop();
}
{
  // a touch the browser cancels (a system gesture starting on the screen) was sent as a click
  const { s, canvas } = await session();
  const f = sent.length;
  canvas.fire('pointerdown', touch(1, 200, 200));
  canvas.fire('pointercancel', touch(1, 200, 200));
  await tick();
  check('touch: a tap the browser cancels is no click', !events(f).some((e) => e.startsWith('mouse:')), events(f).join(' '));
  s.stop();
}
{
  // the phone keyboard hidden without leaving the field (Android's Back): the desktop stopped following the window's size
  const vvL = [];
  globalThis.visualViewport = { height: 800, addEventListener: (t, f) => vvL.push(f) };
  const { s, keyboard: k } = await session({ conn: { id: 'c', resize: 'dynamic' } });
  s.showKeyboard(k);
  const opened = s.softKeyboard;
  visualViewport.height = 480; vvL.forEach((f) => f());           // the keyboard is shown
  const shown = s.softKeyboard;
  visualViewport.height = 800; vvL.forEach((f) => f());           // and hidden with Back, the field keeps the focus
  check('phone keyboard hidden without leaving the field: the desktop follows the window again', opened && shown && !s.softKeyboard,
    JSON.stringify({ opened, shown, after: s.softKeyboard }));
  delete globalThis.visualViewport;
  s.stop();
}

// ------------------------------------------------------------ reconnection waits

{
  // after the first retry (1 s), each wait is up to half as long again at random: after the gateway restarted, every page
  // tried again at the same moments and most found it busy
  const { wk } = await session();
  const seen = [], st0 = globalThis.setTimeout;
  globalThis.setTimeout = (f, ms, ...a) => { if (ms >= 1000) seen.push(ms); return st0(f, ms, ...a); };
  wk.onmessage({ data: { t: 'end' } });
  await new Promise((r) => st0(r, 1100));
  workers.at(-1).onmessage({ data: { t: 'end' } });
  globalThis.setTimeout = st0;
  await new Promise((r) => st0(r, 50));
  check('reconnection: 1 s first, then waits with some chance in them (2 to 3 s the second)', seen[0] === 1000 && seen[1] > 2000 && seen[1] <= 3000, JSON.stringify(seen));
}

// ------------------------------------------------------------ copies within a canvas

{
  // a console scrolling (ScrBlt) and a graphics-pipeline copy within one surface: never a canvas drawn onto itself (the
  // browser copies all of it first: 6.7 ms for 64 x 16 pixels of 1920 x 1080), through a scratch canvas; the same copy
  const { s, wk } = await session();
  const selfDraws = [];
  const watch = (ctx, canvas, name) => {
    const d0 = ctx.drawImage?.bind(ctx);
    ctx.drawImage = (src, ...a) => { if (src === canvas) selfDraws.push(name); return d0?.(src, ...a); };
  };
  watch(s.sctx, s.surface, 'desktop');
  wk.onmessage({ data: { t: 'op', cmd: { op: 'copy', sx: 0, sy: 16, w: 640, h: 400, x: 0, y: 0 } } });
  s.gfx.apply({ op: 'create', id: 9, w: 256, h: 256 });
  const sf = s.gfx.surfaces.get(9);
  if (sf) watch(sf.ctx, sf.c, 'surface');
  s.gfx.apply({ op: 's2s', src: 9, dst: 9, rect: { l: 0, t: 16, r: 200, b: 216 }, pts: [[0, 0]] });
  const scratchUsed = !!s.copyTmp;
  check('copies within the desktop or a surface: never a canvas drawn onto itself', selfDraws.length === 0 && scratchUsed, JSON.stringify({ selfDraws, scratchUsed }));
  s.stop();
}

// ------------------------------------------------------------ graphics of no size

{
  // a bitmap or a graphics pipeline rectangle of width or height 0 (from a server that sends one) draws nothing: the
  // browser's ImageData and getImageData throw for 0, uncaught on the page's main thread
  const { s, wk } = await session();
  const ImageData0 = globalThis.ImageData;
  globalThis.ImageData = class { constructor(data, w, h) { if (!w || !h) throw new Error('IndexSizeError'); Object.assign(this, { data, w, h }); } };
  const thrown = [];
  const run = (f) => { try { f(); } catch (e) { thrown.push(e.message); } };
  run(() => wk.onmessage({ data: { t: 'rect', x: 0, y: 0, w: 0, h: 64, cw: 0, ch: 64, px: new Uint8Array(0) } }));
  run(() => s.gfx.apply({ op: 'create', id: 7, w: 64, h: 64 }));
  const surf = s.gfx.surfaces.get(7);
  if (surf) surf.ctx = new Proxy({ calls: [] }, { get: (o, k) => (k in o ? o[k] : k === 'getImageData' ? (x, y, w, h) => { if (!w || !h) throw new Error('IndexSizeError'); return { data: [] }; } : () => {}) });
  run(() => s.gfx.apply({ op: 'px', id: 7, x: 0, y: 0, w: 0, h: 64, px: new Uint8Array(0) }));
  run(() => s.gfx.apply({ op: 'alpha', id: 7, x: 0, y: 0, w: 0, h: 64, a: new Uint8Array(0) }));
  globalThis.ImageData = ImageData0;
  check('graphics: a bitmap or a rectangle of no size draws nothing, never throws', thrown.length === 0, JSON.stringify(thrown));
  s.stop();
}

// ------------------------------------------------------------ audio

{
  // a format the gateway should never have taken (no channels, a rate of 0, an IMA ADPCM block of 0 or odd) drops its
  // chunks: decodeAudio() or the Web Audio API threw on every one of them, in the middle of the session's frames
  const { AudioPlayer, decodeAudio } = await import('../public/assets/audio.js');
  const played = [];
  const ctx = {
    state: 'running', currentTime: 0, destination: {},
    createBuffer(ch, n, rate) {
      if (!(rate >= 3000 && rate <= 768000) || !(n > 0) || !(ch > 0)) throw new Error('NotSupportedError');
      return { duration: n / rate, copyToChannel() {} };
    },
    createBufferSource() { return { connect() {}, start() { played.push(1); } }; },
  };
  const p = new AudioPlayer();
  p.ctx = ctx;
  const fmts = [{ tag: 1, ch: 2, rate: 44100, bits: 16, align: 4 }, { tag: 1, ch: 0, rate: 44100, bits: 16, align: 0 },
    { tag: 6, ch: 1, rate: 0, bits: 8, align: 1 }, { tag: 0x11, ch: 2, rate: 22050, bits: 4, align: 0 },
    { tag: 0x11, ch: 2, rate: 22050, bits: 4, align: 2047 }, { tag: 0x11, ch: 1, rate: 22050, bits: 4, align: 2 }];
  p.onMessage(new Uint8Array([70, ...new TextEncoder().encode(JSON.stringify(fmts))]));
  const thrown = [];
  fmts.forEach((f, i) => {
    try { p.onMessage(new Uint8Array([87, i, 0, ...new Uint8Array(4096).fill(7)])); } catch (e) { thrown.push(i + ': ' + e.message); }
  });
  check('audio: chunks of a format the page cannot play dropped, never thrown (the good one still played)',
    thrown.length === 0 && played.length === 1 && decodeAudio(fmts[4], new Uint8Array(4096)).length === 0, JSON.stringify({ thrown, played: played.length }));
}

{ // microphone: the gateway's word passed to the capture; audio joined to the piece still waiting (at most max bytes, whole
  // frames, the oldest dropped), never ahead of a key; the capture stopped when the session ends
  const { s, wk } = await session();
  const got = [];
  s.mic = { onMessage: (d) => got.push([...d]), stop: () => got.push('stop') };
  wk.onmessage({ data: { t: 'frame', type: 13, d: new Uint8Array([1, 0x44, 0xac, 0, 0, 1]) } });
  hold = [];
  const n0 = sent.length;
  s.sendClip(new Uint8Array(10));                       // the 4 requests in flight: what follows waits
  s.sendClip(new Uint8Array(10));
  s.sendClip(new Uint8Array(10));
  s.sendClip(new Uint8Array(10));
  const frame = 2, max = 8;
  s.sendMic(new Uint8Array([1, 1, 2, 2]), max, frame);
  s.sendMic(new Uint8Array([3, 3, 4, 4]), max, frame);
  s.sendMic(new Uint8Array([5, 5, 6, 6]), max, frame);  // 12 bytes: the oldest frames go, 8 kept
  const waiting = s.outbox.filter((m) => m[0] === 15).map((m) => [...m]);
  hold.splice(0).forEach((r) => r({ ok: true }));
  hold = null;
  for (let i = 0; i < 4; i++) await tick();
  const mics = sent.slice(n0).filter((x) => x.payload[0] === 15).map((x) => x.payload.slice(1).join());
  wk.onmessage({ data: { t: 'end' } });
  await tick();
  check('microphone: the gateway\'s format to the capture; audio waiting joined in one message of at most a second (whole frames, the newest kept); stopped at the end',
    JSON.stringify(got[0]) === '[1,68,172,0,0,1]' && waiting.length === 1 && waiting[0].join() === '15,3,3,4,4,5,5,6,6'
      && mics.length === 1 && mics[0] === '3,3,4,4,5,5,6,6' && got.includes('stop'),
    JSON.stringify({ got, waiting, mics }));
  s.stop();
}

console.log(failed ? `\n${failed} failed` : '\nAll tests passed');
process.exit(failed ? 1 : 0);
