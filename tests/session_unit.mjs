// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// Session input unit tests (keyboard, mouse, touch, reconnection, drawing orders, stream worker): node tests/session_unit.mjs
// The browser is replaced by minimal doubles; what reaches the gateway is decoded into readable events.

let failed = 0;
const check = (name, ok, info = '') => { console.log((ok ? 'OK   ' : 'FAIL ') + name + (info ? ' ' + info : '')); if (!ok) failed++; };
const tick = () => new Promise((r) => setTimeout(r, 0));
// the test's own clock for the session's timers (retries, resize checks): the timers due run in their order, the answers
// between them, the same on any computer, loaded or not (checks in real time failed now and then on a busy one); a timer
// of 0 ms stays a real one (tick)
const testClock = () => {
  const real = [globalThis.setTimeout, globalThis.clearTimeout];
  let now = 0;
  const due = [];
  globalThis.setTimeout = (f, ms = 0, ...a) => {
    if (!(+ms > 0)) return real[0](f, 0, ...a);
    const tm = { at: now + +ms, f: () => f(...a) };
    due.push(tm);
    return tm;
  };
  globalThis.clearTimeout = (tm) => { if (tm && typeof tm === 'object') tm.f = null; else real[1](tm); };
  const settle = async () => { for (let i = 0; i < 4; i++) await new Promise((r) => setImmediate(r)); };
  return {
    async advance(ms) {
      const end = now + ms;
      for (;;) {
        await settle();
        const next = due.filter((tm) => tm.f && tm.at <= end).sort((x, y) => x.at - y.at)[0];
        if (!next) break;
        now = next.at;
        const f = next.f;
        next.f = null;
        f();
      }
      now = end;
      await settle();
    },
    restore() { [globalThis.setTimeout, globalThis.clearTimeout] = real; },
  };
};

// ------------------------------------------------------------ browser doubles
class El {
  constructor() {
    this.l = {}; this.style = {}; this.value = ''; this.width = 1024; this.height = 768; this.offsetWidth = 1024; this.offsetHeight = 768;
    const c = this.classes = new Set();
    this.classList = { add: (...n) => n.forEach((x) => c.add(x)), remove: (...n) => n.forEach((x) => c.delete(x)), contains: (n) => c.has(n),
      toggle: (n, on = !c.has(n)) => { if (on) c.add(n); else c.delete(n); return on; } };
  }
  appendChild(e) { (this.children ||= []).push(e); return e; }
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
  ResizeObserver: class { observe() {} disconnect() {} },
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
  check('worker: bitmaps no larger than the desktop', seen === 'frame | rects | bitmap: bad size or depth (200 x 100 at 16 bpp)', seen);

  // the bitmaps of an update go to the page together, 64 at most in a message (one each was half of the page's time on
  // them), in order; those decoded before a broken one still go, before its error
  const many = (n, bad) => {
    const len = 16 * 16 * 2, u = new Uint8Array(5 + 1 + 4 + n * (18 + len)), dv = new DataView(u.buffer);
    u[0] = 1; dv.setUint32(1, u.length - 5, true); u[5] = 1; dv.setUint16(8, n, true);
    for (let i = 0, o = 10; i < n; i++, o += 18 + len) {
      const [w, h] = i === bad ? [200, 100] : [16, 16];
      [i * 16 % 96, 0, i * 16 % 96 + 15, 15, w, h, 16, 0, len].forEach((v, k) => dv.setUint16(o + k * 2, v, true));
    }
    return u;
  };
  const upd = [Uint8Array.of(2, 5, 0, 0, 0, 100, 0, 100, 0, 16), many(150), many(5, 3)];
  const batches = await run({ ok: true, body: { getReader: () => ({ read: async () => (upd.length ? { value: upd.shift(), done: false } : { done: true }) }) } });
  const shape = batches.filter((m) => m.t !== 'end' && m.t !== 'frame').map((m) => (m.t === 'rects' ? m.rects.length + ':' + m.rects.map((r) => r.x).join('.') : m.t)).join(' | ');
  const xs = (n) => Array.from({ length: n }, (_, i) => i * 16 % 96).join('.');
  check('worker: the bitmaps of an update in messages of 64 at most, in order; those before a broken one first',
    shape === `64:${xs(64)} | ${'64:' + Array.from({ length: 64 }, (_, i) => (i + 64) * 16 % 96).join('.')} | 22:${Array.from({ length: 22 }, (_, i) => (i + 128) * 16 % 96).join('.')} | 3:${xs(3)} | log`, shape);

  // ... and about 4 million pixels at most in a message (64 bitmaps of a whole 1920 x 1080 desktop, from a few hundred bytes
  // of update, held 506 MB in the worker before it posted them)
  const whole = (n) => {
    const u = new Uint8Array(5 + 1 + 4 + n * 18), dv = new DataView(u.buffer);
    u[0] = 1; dv.setUint32(1, u.length - 5, true); u[5] = 1; dv.setUint16(8, n, true);
    for (let i = 0, o = 10; i < n; i++, o += 18) [0, 0, 1023, 1023, 1024, 1024, 32, 0, 0].forEach((v, k) => dv.setUint16(o + k * 2, v, true));
    return u;
  };
  const updWhole = [Uint8Array.of(2, 5, 0, 0, 0, 0, 4, 0, 4, 32), whole(10)];
  const wholes = await run({ ok: true, body: { getReader: () => ({ read: async () => (updWhole.length ? { value: updWhole.shift(), done: false } : { done: true }) }) } });
  const perMsg = wholes.filter((m) => m.t === 'rects').map((m) => m.rects.length).join(' ');
  check('worker: about 4 million pixels at most in a message of bitmaps', perMsg === '4 4 2', perMsg);

  // connected again for a guest of a shared session (frame 14): the worker's decoders start anew (a fragment of the old
  // connection is not joined to the new one's), and the page is told
  const f10 = (frag, bytes) => { const u = new Uint8Array(8 + bytes.length); u[0] = 10; new DataView(u.buffer).setUint32(1, 3 + bytes.length, true); u.set([5, frag, 0, ...bytes], 5); return u; };
  const resetRun = async (withReset) => {
    const parts = [f10(2, [7, 7, 7]), ...(withReset ? [Uint8Array.of(14, 0, 0, 0, 0)] : []), f10(1, [9, 9])];
    const out = await run({ ok: true, body: { getReader: () => ({ read: async () => (parts.length ? { value: parts.shift(), done: false } : { done: true }) }) } });
    return out.filter((m) => m.t === 'frame').map((m) => m.type + ':' + [...m.d].join(','));
  };
  const resets = [await resetRun(false), await resetRun(true)];
  check('worker: connected again for a guest: its decoders start anew, and the page is told',
    JSON.stringify(resets) === JSON.stringify([['1:5,7,7,7,9,9'], ['14:', '1:5,9,9']]), JSON.stringify(resets));

  // a recording played (frames with their time, [u32 ms][u8 type][u32 length][payload]): at its pace, times the speed; what
  // comes before `from` at once; paused and played again from the page; its position told, and its end
  const timed = (list) => {
    const parts = list.map(([ms, json]) => {
      const d = new TextEncoder().encode(json), u = new Uint8Array(9 + d.length), dv = new DataView(u.buffer);
      dv.setUint32(0, ms, true); u[4] = 3; dv.setUint32(5, d.length, true); u.set(d, 9);
      return u;
    });
    parts.push(Uint8Array.of(0, 0, 0, 0, 0, 3, 0, 0, 0, 101, 110, 100));   // the gateway's "end": read whole
    return { ok: true, body: { getReader: () => ({ read: async () => (parts.length ? { value: parts.shift(), done: false } : { done: true }) }) } };
  };
  const playFor = async (o, during) => {
    globalThis.fetch = async () => timed([[0, '{"a":0}'], [100, '{"a":100}'], [200, '{"a":200}']]);
    posted.length = 0;
    const t0 = performance.now(), at = [];
    const seen = new Proxy(posted, { set: (a, k, v) => { if (v?.t === 'frame') at.push(Math.round(performance.now() - t0)); a[k] = v; return true; } });
    globalThis.postMessage = (m) => seen.push(m);
    const done = globalThis.onmessage({ data: { url: 'https://gateway.test/rdp.php?a=replay', headers: {}, body: '', timed: true, ...o } });
    await during?.();
    await Promise.race([done, new Promise((r) => setTimeout(r, 3000))]);   // (a worker that never ends: said, not waited for)
    globalThis.onmessage({ data: { cmd: 'stop' } });
    globalThis.postMessage = (m) => posted.push(m);
    return { at, frames: posted.filter((m) => m.t === 'frame').length, end: posted.filter((m) => m.t === 'pos').at(-1) };
  };
  // (times measured on a busy computer can come late: measured again, up to 3 times; a pace that is wrong fails all of them)
  const paced = async () => ({
    normal: await playFor({ from: 0, speed: 1 }),
    fast: await playFor({ from: 0, speed: 4 }),
    jump: await playFor({ from: 150, speed: 1 }),
    paused: (await playFor({ from: 0, speed: 1 }, async () => {
      await new Promise((r) => setTimeout(r, 20));
      globalThis.onmessage({ data: { cmd: 'pause' } });
      await new Promise((r) => setTimeout(r, 300));
      globalThis.onmessage({ data: { cmd: 'play' } });
    })).at,
  });
  const onPace = ({ normal, fast, jump, paused }) => normal.frames === 3 && normal.at[2] >= 190 && normal.at[2] < 400 && fast.at[2] >= 45
    && fast.at[2] < 150 && jump.at[1] < 40 && jump.at[2] >= 40 && jump.at[2] < 150 && paused[2] >= 480 && normal.end?.done === true && normal.end?.ms === 200;
  let pacing = await paced();
  for (let i = 1; i < 3 && !onPace(pacing); i++) pacing = await paced();
  let stoppedIn = null;
  const stopped = await playFor({ from: 0, speed: 1 }, async () => {
    await new Promise((r) => setTimeout(r, 20));
    globalThis.onmessage({ data: { cmd: 'pause' } });
    const t = performance.now();
    globalThis.onmessage({ data: { cmd: 'stop' } });   // the page left the player while paused
    stoppedIn = t;
  });
  stoppedIn = performance.now() - stoppedIn;
  check('worker: a recording stopped while paused ends at once (its worker went on for ever)', stopped.frames === 1 && stoppedIn < 500, JSON.stringify({ stopped, stoppedIn }));
  check('worker: a recording at its pace (times the speed), from a point at once, paused and played again; its end told',
    onPace(pacing), JSON.stringify(pacing));
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
  const clock = testClock();
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
  await clock.advance(450);
  clock.restore();
  const asked = sent.slice(n0).map((x) => x.payload).filter((p) => p[0] === 13).map((p) => new TextDecoder().decode(new Uint8Array(p.slice(1))));
  check('several screens: a window opened while connecting gets its monitor once connected', asked.length >= 1
    && asked.every((a) => a === '[[0,0,1920,1080,1],[1920,0,1280,1024,0]]'), JSON.stringify(asked));
}

// a window that takes its size while connecting (Firefox: the other screen's window opened small, 400 x 300, and made the
// size of its screen just after; the request had gone with its first size): its monitor follows once connected, without
// waiting for Display Control (before, that monitor stayed 400 x 300 until the server opened it, a minute later)
{
  const container = new El(), canvas = new El(), keyboard = new El();
  const s = new RdpSession({ container, canvas, keyboard, conn: { id: 'c', resize: 'dynamic' }, csrf: 'x', retries: 3, onState: () => {} });
  s.setMonitors([{ vx: 0, vy: 0, w: 1920, h: 1080, primary: true }, { vx: 1920, vy: 0, w: 1920, h: 1080, primary: false }], 0);
  const wl = {}, win = { innerWidth: 400, innerHeight: 300, addEventListener: (t, f) => { (wl[t] ||= []).push(f); } };
  const other = new El();
  other.ownerDocument = { defaultView: win };
  s.addView(other, new El(), 1);                         // found before connecting: its window still small
  const clock = testClock();
  s.start();
  await tick();
  const wk = workers.at(-1), monsOf = (w) => JSON.parse(w.msgs.find((m) => m.body)?.body || '{}').mons?.map((m) => m.slice(2, 4));
  const first = monsOf(wk);
  Object.assign(win, { innerWidth: 1904, innerHeight: 1000 });   // the size of its screen, while connecting
  (wl.resize || []).forEach((f) => f());
  await clock.advance(450);
  wk.onmessage({ data: { t: 'frame', type: 3, d: new TextEncoder().encode(JSON.stringify({ token: 't' })) } });   // its session id
  await clock.advance(1800);                             // (checked; no Display Control yet: connected again with it)
  const quit = wk.msgs.some((m) => m.cmd === 'stop');
  wk.onmessage({ data: { t: 'end' } });                  // (the connection before, ended)
  await tick();
  const again = workers.at(-1) === wk ? null : monsOf(workers.at(-1));
  s.stop();
  clock.restore();
  check('several screens: a window that took its size while connecting gets a monitor of that size once connected, not when Display Control opens',
    JSON.stringify(first?.[1]) === '[400,300]' && quit && JSON.stringify(again?.[1]) === '[1904,1000]', JSON.stringify({ first, quit, again }));
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
  wk.onmessage({ data: { t: 'rects', rects: [{ x: 0, y: 0, w: 450, h: 120, cw: 450, ch: 120, px: new Uint8Array(450 * 120 * 4) }] } });   // a bitmap over window 1 and outside
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
  // several screens: this window hidden (minimized, covered by another program, another tab) while another screen's window
  // is in view: what changed reached that window only on this page's frames, which a hidden page does not get (it froze
  // until this one was seen again). Now on the frames of a window in view, also a change waiting as this one hid
  const docL = {}, add0 = document.addEventListener, raf0 = globalThis.requestAnimationFrame;
  document.addEventListener = (t, f) => (docL[t] ||= []).push(f);
  const { s, wk } = await session({ conn: { id: 'c', resize: 'dynamic' } });
  document.addEventListener = add0;
  s.setMonitors([{ vx: 0, vy: 0, w: 1920, h: 1080, primary: true }, { vx: 1920, vy: 0, w: 1920, h: 1080, primary: false }], 0);
  const asked = [];
  const win = { innerWidth: 1000, innerHeight: 700, addEventListener() {}, requestAnimationFrame: (f) => { asked.push('other'); setTimeout(f, 0); } };
  const other = new El();
  other.ownerDocument = { hidden: false, addEventListener() {}, defaultView: win };
  s.addView(other, new El(), 1);
  wk.onmessage({ data: { t: 'frame', type: 5, d: new Uint8Array(0) } });   // Display Control, then the monitors taken
  await tick();
  wk.onmessage({ data: { t: 'frame', type: 2, d: new Uint8Array([2024 & 255, 2024 >> 8, 768 & 255, 768 >> 8, 32]) } });
  await tick();
  await tick();
  const ctx = other.getContext('2d'), drawn = () => ctx.calls.filter((c) => c[0] === 'drawImage').length;
  const change = () => wk.onmessage({ data: { t: 'rects', rects: [{ x: 1030, y: 10, w: 4, h: 4, cw: 4, ch: 4, px: new Uint32Array(16) }] } });
  globalThis.requestAnimationFrame = () => asked.push('this');   // this page's frames: they do not come while it is hidden
  document.hidden = true;
  docL.visibilitychange?.forEach((g) => g());
  let n = drawn();
  asked.length = 0;
  change();                                               // in the other screen's part of the desktop
  await tick();
  await tick();
  const hidden = [asked.join(' '), drawn() - n];
  document.hidden = false;
  docL.visibilitychange?.forEach((g) => g());
  n = drawn();
  asked.length = 0;
  change();                                               // asked from this page, which hides before its frame
  await tick();
  document.hidden = true;
  docL.visibilitychange?.forEach((g) => g());
  await tick();
  await tick();
  const waiting = [asked.join(' '), drawn() - n];
  globalThis.requestAnimationFrame = raf0;
  document.hidden = false;
  check('screens: this window hidden, the other screen\'s window in view keeps changing, also with a change waiting as this one hid',
    JSON.stringify([hidden, waiting]) === JSON.stringify([['this other', 1], ['this other', 1]]), JSON.stringify([hidden, waiting]));
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

// ------------------------------------------------------------ graphics ops of several areas

{
  // an op of several areas (fill, cache to surface, surface to surface, video) draws them all on the surface before it copies
  // them to the desktop: a surface written after a copy of it in the same task is copied whole first (a fill of four
  // rectangles on 1920 x 1080 took 18 ms, 0.2 ms this way); an area drawn over one not copied yet has it copied first. A
  // video frame is drawn once through all its areas (each drawing converts the whole frame: 7 ms at 1920 x 1080)
  const { s } = await session();
  const log = [];
  const rec = (tag) => new Proxy({}, { get: (o, k) => (k in o ? o[k] : (...a) => log.push(tag + k)), set: (o, k, v) => { o[k] = v; return true; } });
  s.gfx.apply({ op: 'create', id: 3, w: 512, h: 512 });
  s.gfx.apply({ op: 'create', id: 4, w: 512, h: 512 });
  s.gfx.apply({ op: 'map', id: 3, x: 0, y: 0 });
  s.gfx.cache.set(1, Object.assign(new El(), { width: 32, height: 32 }));
  s.gfx.surfaces.get(3).ctx = rec('s.');
  s.sctx = rec('d.');
  const run = (o) => { log.length = 0; s.gfx.apply(o); return log.filter((x) => /drawImage|fillRect|clip/.test(x)).join(' '); };
  const a = (l, t, w, h) => ({ l, t, r: l + w, b: t + h });
  let closed = 0;
  const got = {
    fill: run({ op: 'fill', id: 3, color: 0x123456, rects: [a(0, 0, 10, 10), a(20, 0, 10, 10), a(40, 0, 10, 10)] }),
    overlap: run({ op: 'fill', id: 3, color: 0x123456, rects: [a(0, 0, 10, 10), a(5, 5, 10, 10), a(40, 0, 10, 10)] }),
    c2s: run({ op: 'c2s', id: 3, slot: 1, pts: [[0, 0], [100, 0]] }),
    s2s: run({ op: 's2s', src: 4, dst: 3, rect: a(0, 0, 16, 16), pts: [[0, 0], [100, 0]] }),
    video: run({ op: 'video', id: 3, frame: { close: () => closed++ }, rects: [a(0, 0, 16, 16), a(64, 0, 16, 16), a(0, 64, 16, 16)] }),
  };
  const want = {
    fill: 's.fillRect s.fillRect s.fillRect d.drawImage d.drawImage d.drawImage',
    overlap: 's.fillRect d.drawImage s.fillRect s.fillRect d.drawImage d.drawImage',
    c2s: 's.drawImage s.drawImage d.drawImage d.drawImage',
    s2s: 's.drawImage s.drawImage d.drawImage d.drawImage',
    video: 's.clip s.drawImage d.drawImage d.drawImage d.drawImage',
  };
  check('graphics: an op of several areas draws them all on the surface, then copies them to the desktop; a video frame drawn once',
    JSON.stringify(got) === JSON.stringify(want) && closed === 1, JSON.stringify({ got, closed }));
  s.stop();
}
{
  // ... a video frame that cannot be drawn (closed, or of another size) leaves its surface unclipped, and is closed (before,
  // every later drawing on the surface stayed inside the frame's areas, and the frame held the decoder's memory)
  const { s } = await session();
  s.gfx.apply({ op: 'create', id: 3, w: 512, h: 512 });
  const log = [];
  s.gfx.surfaces.get(3).ctx = new Proxy({}, { get: (o, k) => (k in o ? o[k] : k === 'drawImage' ? () => { throw new Error('InvalidStateError'); } : () => log.push(k)),
    set: (o, k, v) => { o[k] = v; return true; } });
  let thrown = '', closed = 0;
  try {
    s.gfx.apply({ op: 'video', id: 3, frame: { close: () => closed++ }, rects: [{ l: 0, t: 0, r: 16, b: 16 }, { l: 64, t: 0, r: 80, b: 16 }] });
  } catch (e) {
    thrown = e.message;
  }
  const n = (k) => log.filter((x) => x === k).length;
  check('graphics: a video frame that cannot be drawn leaves its surface unclipped, and is closed',
    n('save') === 1 && n('restore') === 1 && closed === 1 && thrown === 'InvalidStateError', JSON.stringify({ log, closed, thrown }));
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
  run(() => wk.onmessage({ data: { t: 'rects', rects: [{ x: 0, y: 0, w: 0, h: 64, cw: 0, ch: 64, px: new Uint8Array(0) }] } }));
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

// ------------------------------------------------------------ multitouch: the fingers as touch frames while the gateway says so

{
  // before the gateway says so, a tap is a mouse click; then each change is a frame with every finger on the screen: ids from
  // 0 (the lowest free one again later), down / moved or still / lifted, a finger put down and lifted at once sent down then
  // up; the gateway's {"touch": false} brings the mouse back
  Object.defineProperty(navigator, 'maxTouchPoints', { value: 5, configurable: true });
  const { s, canvas: c, wk } = await session();
  // (only this session's requests: one of a session of an earlier test, late on a slow computer, failed this check)
  const mine = (from) => sent.slice(from).filter((x) => String(x.url).includes(`sid=${s.sid}`));
  const n0 = sent.length;
  c.fire('pointerdown', touch(1, 10, 10));
  c.fire('pointerup', touch(1, 10, 10));
  await tick();
  const asMouse = mine(n0).length > 0 && mine(n0).every((x) => x.payload[0] === 1);
  const frame = (wk.onmessage({ data: { t: 'frame', type: 3, d: new TextEncoder().encode('{"touch":true}') } }), () => {});
  frame();
  const n1 = sent.length;
  c.fire('pointerdown', touch(1, 100, 100));
  c.fire('pointerdown', touch(2, 300, 300));
  await tick();
  c.fire('pointermove', touch(1, 140, 100));
  await tick();
  c.fire('pointerup', touch(2, 300, 300));
  await tick();
  c.fire('pointerdown', touch(3, 50, 50));
  c.fire('pointerup', touch(3, 50, 50));
  await tick();
  c.fire('pointercancel', touch(1, 140, 100));
  await tick();
  const frames = mine(n1).filter((x) => x.payload[0] === 16).map(({ payload: p }) => {
    const out = [];
    for (let i = 0; i < p[1]; i++) {
      const o = 2 + i * 6, x = (p[o + 2] | (p[o + 3] << 8)) << 16 >> 16;
      out.push(`${p[o]}:${['down', 'still', 'up', 'cancel'][p[o + 1]]}@${x}`);
    }
    return out;
  });
  const shape = frames.map((f) => f.map((x) => x.replace(/@.*/, ''))).map((f) => f.join(' ')).join(' | ');
  const xs = frames.map((f) => Number((f.find((x) => x.startsWith('0:')) || '0:@-1').split('@')[1]));
  wk.onmessage({ data: { t: 'frame', type: 3, d: new TextEncoder().encode('{"touch":false}') } });
  const n2 = sent.length;
  c.fire('pointerdown', touch(4, 10, 10));
  c.fire('pointerup', touch(4, 10, 10));
  await tick();
  const mouseAgain = mine(n2).length > 0 && mine(n2).every((x) => x.payload[0] === 1);
  check('multitouch: a mouse until the gateway says so; then frames of every finger (ids, states, a quick tap down then up, cancel); a mouse again after',
    asMouse && shape === '0:down 1:down | 0:still 1:still | 0:still 1:up | 0:still 1:down | 0:still 1:up | 0:cancel' && xs[1] > xs[0] && mouseAgain,
    JSON.stringify({ asMouse, frames, mouseAgain }));
  s.stop();
}

// ------------------------------------------------------------ Wake-on-LAN: the page says the computer is being turned on

{
  // the gateway's {"waking": true} between connecting and connected: its own state, so the page can say it (two minutes can pass)
  const states = [];
  const s = new RdpSession({ container: new El(), canvas: new El(), keyboard: new El(), conn: { id: 'c' }, csrf: 'x', retries: 0, onState: (st) => states.push(st) });
  s.start();
  await tick();
  const wk = workers.at(-1);
  const status = (j) => wk.onmessage({ data: { t: 'frame', type: 3, d: new TextEncoder().encode(JSON.stringify(j)) } });
  status({ token: 't' });
  status({ waking: true });
  status({ connected: true });
  await tick();
  check('Wake-on-LAN: the session goes connecting, waking (the computer being turned on), connected', states.join() === 'connecting,waking,connected', states.join());
  s.stop();
}

{ // the Remote Desktop Gateway's messages: its consent message shown while connecting and the answer sent (message 17: 1
  // accepted, 0 declined, also when the dialog is closed without an answer); a service message shown, nothing sent back
  const shown = [];
  let answer = true;
  const s = new RdpSession({ container: new El(), canvas: new El(), keyboard: new El(), conn: { id: 'c' }, csrf: 'x', retries: 0, onState: () => {} });
  s.onGatewayMessage = (text, consent) => { shown.push([text, consent]); return Promise.resolve(answer); };
  s.start();
  await tick();
  const wk = workers.at(-1), from = sent.length;
  const status = (j) => wk.onmessage({ data: { t: 'frame', type: 3, d: new TextEncoder().encode(JSON.stringify(j)) } });
  status({ token: 't' });
  status({ gateway_message: 'Solo uso autorizado', consent: true });
  await tick();
  answer = null;                                       // (the dialog closed without an answer: declined)
  status({ gateway_message: 'Otra vez', consent: true });
  await tick();
  status({ connected: true });
  status({ gateway_message: 'Mantenimiento a las 22:00' });
  await tick();
  const answers = sent.slice(from).filter((r) => r.payload[0] === 17).map((r) => r.payload.join(','));
  check('gateway messages: the consent message shown and its answer sent (accepted, then closed without one: declined); a service message shown, nothing sent',
    JSON.stringify(shown) === JSON.stringify([['Solo uso autorizado', true], ['Otra vez', true], ['Mantenimiento a las 22:00', false]])
    && answers.join(' ') === '17,1 17,0', JSON.stringify({ shown, answers }));
  s.stop();
}


// ------------------------------------------------------------ a shared session: the guest's page and the owner's

{
  // a guest that only watches: its stream through the link, nothing sent (no input, acknowledgement, size or visibility); the
  // owner's mouse shown where it is, with the remote pointer's picture
  const states = [], container = new El(), canvas = new El(), keyboard = new El();
  const g = new RdpSession({ container, canvas, keyboard, conn: { id: '', name: '' }, csrf: 'x', retries: 0, guest: { link: 'abc.SECRET' },
    onState: (st, i) => states.push(st + (i.watch ? ':' + i.watch : '')) });
  g.start();
  await tick();
  const wk = workers.at(-1), req = wk.msgs.at(-1);
  const status = (j) => wk.onmessage({ data: { t: 'frame', type: 3, d: new TextEncoder().encode(JSON.stringify(j)) } });
  status({ watch: 'view', owner: 'ana', conn: 'PC', sid: 'abc' });
  await tick();
  const n0 = sent.length;
  canvas.fire('pointerdown', mouse(0, 1));
  canvas.fire('pointerup', mouse(0, 0));
  tap(keyboard, 'KeyA');
  g.gfx.ack(1, 1);
  g.refresh();
  container.fire('resize');
  await tick();
  wk.onmessage({ data: { t: 'frame', type: 15, d: Uint8Array.of(100, 0, 50, 0) } });
  const markAt = g.mark?.style.transform;
  check('sharing, a guest that watches: the stream through the link, nothing sent, the owner\'s mouse shown',
    req.url.endsWith('rdp.php?a=watch') && JSON.parse(req.body).link === 'abc.SECRET' && states.join() === 'connecting,connected:view' && g.sid === 'abc'
      && sent.length === n0 && markAt === 'translate(100px, 50px)' && canvas.style.cursor === undefined,
    JSON.stringify({ req, states, sent: sent.length - n0, markAt }));
  g.stop();
}
{
  // a guest given control: keyboard, mouse and touch only, with the link's secret (never a session token), one request at a time
  const canvas = new El(), keyboard = new El();
  const g = new RdpSession({ container: new El(), canvas, keyboard, conn: { id: '', name: '' }, csrf: 'x', retries: 0, guest: { link: 'abc.SECRET' }, onState: () => {} });
  g.start();
  await tick();
  const wk = workers.at(-1);
  wk.onmessage({ data: { t: 'frame', type: 3, d: new TextEncoder().encode(JSON.stringify({ watch: 'control', owner: 'ana', conn: 'PC', sid: 'abc' })) } });
  await tick();
  const n0 = sent.length;
  hold = [];
  tap(keyboard, 'KeyA');
  await tick();
  tap(keyboard, 'KeyB');
  g.gfx.ack(1, 1);
  g.refresh();
  await tick();
  const inFlight = sent.length - n0;
  while (hold.length) { hold.shift()({ ok: true }); await tick(); }
  await tick();
  hold = null;
  const mine = sent.slice(n0);
  check('sharing, a guest with control: keyboard and mouse only, with the link\'s secret, one request at a time',
    inFlight === 1 && events(n0).join(' ') === 'KeyA↓ KeyA↑ KeyB↓ KeyB↑' && mine.every((x) => x.payload[0] === 1 && x.headers['X-MuDesk-Share'] === 'SECRET'
      && !('X-MuDesk-Token' in x.headers) && x.url === 'rdp.php?a=send&sid=abc'),
    JSON.stringify({ inFlight, ev: events(n0), mine }));
  g.stop();
}
{
  // the owner: told how many guests watch; connected again for one (frame 14): new surfaces and caches, and an acknowledgement
  // of the old connection's frames no longer goes out
  const { s, wk, from } = await session();
  let viewers = null;
  s.onViewers = (n) => { viewers = n; };
  wk.onmessage({ data: { t: 'frame', type: 3, d: new TextEncoder().encode('{"viewers":2}') } });
  const old = s.gfx;
  s.bcache.set(1, 'x');
  wk.onmessage({ data: { t: 'frame', type: 14, d: new Uint8Array(0) } });
  old.ack(5, 5);
  await tick();
  const afterOld = sent.length - from;
  s.gfx.ack(6, 6);
  await tick();
  const acks = sent.slice(from).filter((x) => x.payload[0] === 10).map((x) => x.payload[1]);
  check('sharing, the owner: told how many watch; connected again for a guest, everything drawn anew, no old acknowledgement',
    viewers === 2 && s.gfx !== old && s.bcache.size === 0 && afterOld === 0 && JSON.stringify(acks) === '[6]', JSON.stringify({ viewers, afterOld, acks }));
  s.stop();
}

// ------------------------------------------------------------ a recorded session, and its recording played

{
  // the page is told the connection is recorded; a recording played: asked for with its point and speed, nothing sent,
  // paused from the page, its position told
  const { s, wk } = await session();
  let rec = null;
  s.onRecording = (on) => { rec = on; };
  wk.onmessage({ data: { t: 'frame', type: 3, d: new TextEncoder().encode('{"recording":true}') } });
  s.stop();
  const p = new RdpSession({ container: new El(), canvas: new El(), keyboard: new El(), conn: { id: '', name: '' }, csrf: 'x', retries: 0,
    replay: { sid: 'abc', from: 5000, speed: 2 }, onState: () => {} });
  p.keyboard = p.keyboard;
  let pos = null, told = null;
  p.onReplayPos = (ms) => { pos = ms; };
  p.onRecording = () => { told = true; };
  p.start();
  await tick();
  const pw = workers.at(-1), req = pw.msgs.at(-1);
  const n0 = sent.length;
  pw.onmessage({ data: { t: 'frame', type: 3, d: new TextEncoder().encode('{"connected":true,"recording":true}') } });
  pw.onmessage({ data: { t: 'pos', ms: 6000 } });
  p.replayCmd('pause');
  p.refresh();
  p.gfx.ack(1, 1);
  await tick();
  const tabKey = p.keyboard.fire('keydown', { code: 'Tab', key: 'Tab', getModifierState: () => false });
  check('recording: the player does not take the keys (Tab reaches its controls)', !tabKey.prevented);
  check('recording: the page told; played from a point at a speed, nothing sent, paused, its position told',
    rec === true && req.url.endsWith('rdp.php?a=replay') && JSON.parse(req.body).sid === 'abc' && req.timed === true && req.from === 5000 && req.speed === 2
      && req.headers['X-CSRF'] === 'x' && sent.length === n0 && pos === 6000 && pw.msgs.at(-1)?.cmd === 'pause' && told === null,
    JSON.stringify({ rec, req, sent: sent.length - n0, pos, last: pw.msgs.at(-1), told }));
  p.stop();
}

{
  // the camera and this computer's folder: the gateway's frames to them (F_CAM 16, F_DRIVE 17); their pictures and answers on
  // the out-of-order lane (no sequence number: no key waits behind them), two requests out at most, the folder's answers
  // first and only the newest picture waiting; the folder shared again when the session connects again
  const { s, wk } = await session();
  const lane = [], fetch0 = globalThis.fetch;
  globalThis.fetch = (url, o) => {
    lane.push([new DataView(o.body.buffer).getUint32(0, true), o.body[4], o.body.length - 5, o.body[5]]);
    return fetch0(url, o);
  };
  const got = { cam: [], drive: [] };
  s.cam = { onMessage: (d) => got.cam.push([...d]), stop() {} };
  s.folder = { onRequest: (d) => got.drive.push(d.length), reset() {}, shared: true, name: 'Fotos', readonly: false };
  wk.onmessage({ data: { t: 'frame', type: 16, d: Uint8Array.of(1, 128, 2, 224, 1, 30, 4) } });
  wk.onmessage({ data: { t: 'frame', type: 17, d: new Uint8Array(56) } });
  hold = [];
  const n0 = lane.length;
  s.sendCam(new Uint8Array(100), 4);                 // out at once
  s.sendFolderReply(70, new Uint8Array(17));         // out at once (two out)
  s.sendCam(new Uint8Array(200), 4);                 // waits...
  s.sendCam(new Uint8Array(300), 4);                 // ... in place of the one before
  s.sendFolderReply(71, new Uint8Array(5));          // waits, before the picture
  const out1 = lane.slice(n0).map((x) => x.join(':'));
  const release = () => { const h = hold; hold = []; h.forEach((r) => r({ ok: true })); };
  release();
  await tick(); await tick();
  release();
  await tick(); await tick();
  hold = null;
  const out2 = lane.slice(n0 + out1.length).map((x) => x.join(':'));
  const seqs = new Set(lane.slice(n0).map((x) => x[0]));
  // connected again: the folder shared again (in order, with a sequence number)
  const m0 = lane.length;
  wk.onmessage({ data: { t: 'frame', type: 3, d: new TextEncoder().encode(JSON.stringify({ connected: true })) } });
  await tick();
  const again = lane.slice(m0).filter((x) => x[1] === 19);
  check('camera and folder: their frames to them; pictures and answers out of order, two out, answers first, only the newest picture; the folder shared again',
    JSON.stringify(got.cam) === '[[1,128,2,224,1,30,4]]' && JSON.stringify(got.drive) === '[56]'
    && JSON.stringify(out1) === JSON.stringify(['4294967295:18:101:4', '4294967295:20:21:70']) && JSON.stringify(out2) === JSON.stringify(['4294967295:20:9:71', '4294967295:18:301:4'])
    && seqs.size === 1 && again.length === 1 && again[0][0] !== 0xFFFFFFFF && again[0][2] === 2 + 5,
    JSON.stringify({ cam: got.cam, drive: got.drive, out1, out2, again }));

  // a folder's answer lost on the way (the network, the gateway busy) sent again, 3 times at most (the program there waited
  // for ever); a picture or an answer to a session gone (404) not; connected again for a guest (frame 14): the camera let
  // go, the answers waiting to go left out (no longer waited for)
  let fail = null;
  globalThis.fetch = (url, o) => {
    lane.push([new DataView(o.body.buffer).getUint32(0, true), o.body[4], o.body.length - 5, o.body[5]]);
    return fail === 'network' ? Promise.reject(new TypeError('network')) : Promise.resolve({ ok: fail === null, status: fail ?? 200 });
  };
  const clock = testClock(), advance = (ms) => clock.advance(ms);   // (the retries' delays on the test's clock)
  const r0 = lane.length;
  fail = 'network';
  s.sendFolderReply(80, new Uint8Array(3));
  s.sendCam(new Uint8Array(10), 4);
  await advance(6000);                               // (again after 0.5, 1 and 1.5 s; a fourth would come at 5 s)
  const lost = lane.slice(r0).map((x) => `${x[1]}:${x[3]}`);
  const g0 = lane.length;
  fail = 503;
  s.sendFolderReply(81, new Uint8Array(3));
  await advance(700);
  fail = 404;
  s.sendFolderReply(82, new Uint8Array(3));
  await advance(700);
  const busy = lane.slice(g0).map((x) => `${x[1]}:${x[3]}`);
  clock.restore();
  fail = null;
  let camStops = 0;
  s.cam = { onMessage() {}, stop: () => camStops++ };
  hold = [];
  globalThis.fetch = (url, o) => { lane.push([0, o.body[4], 0, o.body[5]]); return new Promise((r) => hold.push(r)); };
  const h0 = lane.length;
  s.sendFolderReply(90, new Uint8Array(3));
  s.sendFolderReply(91, new Uint8Array(3));
  s.sendFolderReply(92, new Uint8Array(3));          // waits (two out)
  wk.onmessage({ data: { t: 'frame', type: 14, d: new Uint8Array(0) } });
  hold.splice(0).forEach((r) => r({ ok: true }));
  await tick(); await tick();
  hold = null;
  const reset = lane.slice(h0).map((x) => x[3]);
  globalThis.fetch = fetch0;
  check('the lane: a folder\'s answer lost sent again 3 times at most, a picture not; again when the gateway was busy, not to a session gone; connected again for a guest: the camera let go, answers waiting left out',
    JSON.stringify(lost) === JSON.stringify(['20:80', '18:4', '20:80', '20:80', '20:80']) && JSON.stringify(busy) === JSON.stringify(['20:81', '20:81', '20:82'])
    && camStops === 1 && JSON.stringify(reset) === '[90,91]', JSON.stringify({ lost, busy, camStops, reset }));
  s.stop();
}

console.log(failed ? `\n${failed} failed` : '\nAll tests passed');
process.exit(failed ? 1 : 0);
