// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// Interface unit tests (profile, audit, session window, ZIP downloads): node tests/ui_unit.mjs
// app.js runs on a minimal document double; the API answers are canned and every request is recorded.

let failed = 0;
const check = (name, ok, info = '') => { console.log((ok ? 'OK   ' : 'FAIL ') + name + (info ? ' ' + info : '')); if (!ok) failed++; };
const settle = async () => { for (let i = 0; i < 20; i++) await new Promise((r) => setImmediate(r)); };

// ------------------------------------------------------------ document double
class Node0 {}
class Text0 extends Node0 { constructor(s) { super(); this.data = s; } get textContent() { return this.data; } }
class Ev {
  constructor(type, o = {}) { Object.assign(this, o); this.type = type; }
  preventDefault() { this.defaultPrevented = true; }
  stopPropagation() { this.stopped = true; }
  getModifierState() { return false; }
}
const simple = (el, s) => {
  const not = /:not\(([^)]*)\)/.exec(s);
  if (not && simple(el, not[1])) return false;
  s = s.replace(/:not\([^)]*\)/, '');
  const tag = /^[a-z]+/i.exec(s)?.[0];
  if (tag && el.tagName !== tag.toUpperCase()) return false;
  for (const [, c] of s.matchAll(/\.([\w-]+)/g)) if (!el.className.split(' ').includes(c)) return false;
  for (const [, a, v] of s.matchAll(/\[([\w-]+)(?:=([^\]]*))?\]/g)) if (el.getAttribute(a) === null || (v !== undefined && el.getAttribute(a) !== v)) return false;
  return true;
};
class El extends Node0 {
  constructor(tag) {
    super();
    Object.assign(this, { tagName: tag.toUpperCase(), childNodes: [], parentNode: null, attrs: {}, l: {}, style: {}, value: '', checked: false });
    this.dataset = new Proxy({}, { get: (_, k) => this.attrs['data-' + String(k)], set: (_, k, v) => { this.attrs['data-' + String(k)] = String(v); return true; } });
  }
  get className() { return this.attrs.class || ''; }
  set className(v) { this.attrs.class = v; }
  get classList() {
    const set = (f) => { const c = new Set(this.className.split(' ').filter(Boolean)); const r = f(c); this.className = [...c].join(' '); return r; };
    return { add: (x) => set((c) => c.add(x)), remove: (x) => set((c) => c.delete(x)), contains: (x) => set((c) => c.has(x)),
      toggle: (x, on) => set((c) => { on ??= !c.has(x); if (on) c.add(x); else c.delete(x); return on; }) };
  }
  get children() { return this.childNodes.filter((c) => c instanceof El); }
  get textContent() { return this.childNodes.map((c) => c.textContent).join(''); }
  set textContent(v) { this.replaceChildren(String(v)); }
  get isConnected() { let n = this; while (n.parentNode) n = n.parentNode; return n === document.documentElement; }
  setAttribute(k, v) { this.attrs[k] = String(v); }
  getAttribute(k) { return this.attrs[k] ?? null; }
  append(...cs) { for (const c of cs) { const n = c instanceof Node0 ? c : new Text0(String(c)); n.parentNode?.removeChild(n); n.parentNode = this; this.childNodes.push(n); } }
  appendChild(c) { this.append(c); return c; }
  removeChild(c) { this.childNodes = this.childNodes.filter((x) => x !== c); c.parentNode = null; }
  replaceChildren(...cs) { this.childNodes.forEach((c) => { c.parentNode = null; }); this.childNodes = []; this.append(...cs); }
  remove() { this.parentNode?.removeChild(this); }
  replaceWith(n) { const p = this.parentNode; if (!p) return; const i = p.childNodes.indexOf(this); n.parentNode?.removeChild(n); n.parentNode = p; p.childNodes[i] = n; this.parentNode = null; }
  contains(n) { for (; n; n = n.parentNode) if (n === this) return true; return false; }
  addEventListener(t, f) { (this.l[t] ||= []).push(f); }
  removeEventListener(t, f) { this.l[t] = (this.l[t] || []).filter((x) => x !== f); }
  dispatchEvent(e) { e.target ??= this; for (let n = this; n && !e.stopped; n = n.parentNode) (n.l?.[e.type] || []).forEach((f) => f(e)); return !e.defaultPrevented; }
  click() { this.dispatchEvent(new Ev('click')); }
  focus() { document.activeElement = this; }
  blur() { if (document.activeElement === this) { document.activeElement = document.body; this.dispatchEvent(new Ev('blur')); } }
  querySelectorAll(sel) {
    const out = [], walk = (n) => n.children.forEach((c) => { if (sel.split(',').some((s) => simple(c, s.trim()))) out.push(c); walk(c); });
    walk(this);
    return out;
  }
  querySelector(sel) { return this.querySelectorAll(sel)[0] ?? null; }
  closest(sel) { for (let n = this; n instanceof El; n = n.parentNode) if (simple(n, sel)) return n; return null; }
  getBoundingClientRect() { return { left: 0, top: 0, width: 1024, height: 768 }; }
  get offsetHeight() { return 40; }
  getContext() { return new Proxy({}, { get: () => () => {} }); }
  setPointerCapture() {}
  requestFullscreen() { return Promise.resolve(); }
  reportValidity() { return true; }
  toBlob(cb, type) { cb(new Blob([new Uint8Array(8)], { type })); }
}
const html = new El('html'), body = new El('body'), appEl = new El('div');
html.append(body);
body.append(appEl);
const timers = [];                                       // fake timers: [{f, ms, every}]
const winL = {};
let clock = 1000;
Object.defineProperty(globalThis, 'performance', { value: { now: () => clock }, configurable: true, writable: true });
Object.assign(globalThis, {
  Node: Node0,
  document: { documentElement: html, body, activeElement: body, hidden: false, createElement: (t) => new El(t), createElementNS: (_, t) => new El(t),
    getElementById: (id) => (id === 'app' ? appEl : html.querySelector(`[id=${id}]`)), querySelector: (s) => html.querySelector(s), addEventListener() {} },
  location: { hash: '#/profile', href: 'https://gateway.test/' },
  addEventListener: (t, f) => (winL[t] ||= []).push(f),
  removeEventListener: (t, f) => { winL[t] = (winL[t] || []).filter((x) => x !== f); },
  sessionStorage: { getItem: (k) => (k === 'mudesk-data-check' ? '0' : null), setItem() {} },
  matchMedia: () => ({ matches: false }),
  PublicKeyCredential: class {},
  ResizeObserver: class { observe() {} },
  ImageData: class {},
  requestAnimationFrame: () => 0,
  Worker: class { constructor() { workers.push(this); this.sent = []; } postMessage(m) { this.sent.push(m); } terminate() {} },
  devicePixelRatio: 1, screen: { width: 1920, height: 1080 },
  setTimeout: (f, ms) => timers.push({ f, ms }), clearTimeout: (i) => { if (timers[i - 1]) timers[i - 1].f = () => {}; },
  setInterval: (f, ms) => timers.push({ f, ms, every: true }), clearInterval: (i) => { if (timers[i - 1]) timers[i - 1].f = () => {}; },
  FormData: class {
    constructor(form) {
      this.e = form.querySelectorAll('input,select,textarea').filter((el) => el.getAttribute('name') && (!/^(checkbox|radio)$/.test(el.getAttribute('type')) || el.checked))
        .map((el) => [el.getAttribute('name'), el.tagName === 'SELECT' ? (el.children.find((o) => o.selected) || el.children[0])?.value
          : /^(checkbox|radio)$/.test(el.getAttribute('type')) ? el.value || 'on' : el.value]);
    }
    entries() { return this.e[Symbol.iterator](); }
  },
});
globalThis.window = globalThis.self = globalThis;
const workers = [], blobs = new Map(), revoked = [];
URL.createObjectURL = (b) => { const u = 'blob:' + blobs.size; blobs.set(u, b); return u; };
URL.revokeObjectURL = (u) => revoked.push(u);
const runTimers = (maxMs) => timers.filter((x) => x.ms <= maxMs).forEach((x) => x.f());

// ------------------------------------------------------------ API double
const calls = [];                                        // bodies of the api.php requests
const STATE = { setup: false, csrf: 'x', second: null,
  user: { id: 'u1', username: 'ana', role: 'admin', lang: 'es', totp: false, totp_required: false, passkeys: [], must_change: false, reconnect: 3, save_passwords: true } };
const CONN = { id: 'c1', name: 'PC', host: 'pc.test', port: 3389, user: 'ana', domain: '', has_pass: false, ask: true, audio: true, clipboard: false,
  paste: 'auto', drive: false, printer: false, gfx: 'auto', bpp: 16, resize: 'fit' };
const answers = { state: () => STATE, 'conns.list': () => ({ conns: [CONN], keyboards: [0x040A] }), 'profile.activity': () => ({ sessions: [], browsers: [] }) };
const fileCalls = [];                                    // requests to files.php: { a, p, q, body }
let filesAnswer = () => ({ error: 'unknown_action' });
const rdpSent = [];                                      // messages sent to rdp.php?a=send (without their sequence number)
globalThis.fetch = async (url, o = {}) => {
  if (String(url).endsWith('web-check.txt')) return { ok: false, text: async () => '' };   // the data folder is not served
  if (String(url).startsWith('rdp.php')) { if (o.body instanceof Uint8Array) rdpSent.push([...o.body.subarray(4)]); return { ok: true }; }
  if (String(url).startsWith('files.php')) {
    const q = new URLSearchParams(String(url).split('?')[1]);
    fileCalls.push({ a: q.get('a'), p: q.get('p'), q, body: o.body });
    const j = await filesAnswer(q, o.body);
    return { ok: !j.error, json: async () => j };
  }
  const b = JSON.parse(o.body || '{}');
  calls.push(b);
  const j = await (answers[b.action]?.(b) ?? {});
  const raw = typeof j === 'string' ? j : JSON.stringify(j);       // an answer given as text is the body itself (the signed export)
  return { ok: !j.error, json: async () => (typeof j === 'string' ? JSON.parse(j) : j), arrayBuffer: async () => new TextEncoder().encode(raw).buffer };
};
const go = async (hash) => { location.hash = hash; (winL.hashchange || []).forEach((f) => f()); await settle(); };
const byText = (text, root = appEl) => root.querySelectorAll('button,a').find((b) => b.textContent === text);
const submit = (form) => form.dispatchEvent(new Ev('submit'));

const { t } = await import('../public/assets/i18n.js');
await import('../public/assets/app.js');
await settle();

// ------------------------------------------------------------ texts: every key once, in both languages

{
  // a key written twice in a language object keeps only the last one (the .rdp note on a saved password replaced the
  // label of the password field of the connect dialog): the keys of each language read from the source, outside strings
  const { readFileSync } = await import('node:fs');
  const src = readFileSync(new URL('../public/assets/i18n.js', import.meta.url), 'utf8');
  let depth = 0, i = src.indexOf('const T = {') + 'const T = '.length, lang = null;
  const keys = {};
  while (i < src.length) {
    const c = src[i];
    if (c === "'" || c === '"' || c === '`') { const q = c; for (i++; src[i] !== q; i++) if (src[i] === '\\') i++; i++; continue; }
    if (c === '/' && src[i + 1] === '/') { while (src[i] !== '\n') i++; continue; }
    if ('{[('.includes(c)) { depth++; i++; continue; }
    if ('}])'.includes(c)) { depth--; i++; if (depth === 0) break; continue; }
    const m = /^([A-Za-z_$][\w$]*)\s*:/.exec(src.slice(i, i + 80));
    if (m && /[\s,{]/.test(src[i - 1])) {
      if (depth === 1) lang = m[1];
      else if (depth === 2) (keys[lang] ||= []).push(m[1]);
      i += m[0].length;
      continue;
    }
    i++;
  }
  const twice = Object.fromEntries(Object.entries(keys).map(([l, k]) => [l, k.filter((x, n) => k.indexOf(x) !== n)]));
  const es = new Set(keys.es), en = new Set(keys.en);
  check('texts: every key once in each language, and the same keys in both',
    keys.es?.length > 400 && twice.es.length === 0 && twice.en.length === 0 && [...es].every((k) => en.has(k)) && [...en].every((k) => es.has(k)),
    JSON.stringify({ twice, onlyEs: [...es].filter((k) => !en.has(k)), onlyEn: [...en].filter((k) => !es.has(k)) }));

  // every error the gateway answers (Http::fail('key')) has its text (e_key, or r_key for the end of a session): the
  // page showed "Error: offset" for an upload whose file changed meanwhile
  const php = ['api.php', 'rdp.php', 'files.php', 'index.php'].map((f) => readFileSync(new URL('../public/' + f, import.meta.url), 'utf8'))
    .concat(['Http.php', 'Auth.php'].map((f) => readFileSync(new URL('../src/' + f, import.meta.url), 'utf8'))).join('\n');
  const sent = [...new Set([...php.matchAll(/Http::fail\('([a-z_]+)'/g), ...php.matchAll(/self::fail\('([a-z_]+)'/g)].map((m) => m[1]))];
  const missing = sent.filter((k) => !(es.has('e_' + k) || es.has('r_' + k)));
  check('texts: every error the gateway answers has its text', sent.length > 20 && missing.length === 0, JSON.stringify({ sent: sent.length, missing }));
}

// ------------------------------------------------------------ profile: the current password before TOTP and passkeys

{
  const unnamed = appEl.querySelectorAll('input[type=password]').filter((i) => !i.getAttribute('name'));   // [TOTP, passkeys]
  const secret = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';    // 20 bytes in base32, as the gateway makes them
  answers['totp.begin'] = () => ({ secret, uri: `otpauth://totp/MuDeskClient:${encodeURIComponent('Александра.Владимировна')}?secret=${secret}&issuer=MuDeskClient` });
  if (unnamed[0]) unnamed[0].value = 'pass-1';
  byText(t('enable'))?.click();
  await settle();
  const begin = calls.find((c) => c.action === 'totp.begin');
  check('profile: TOTP is set up with the current password', begin?.password === 'pass-1', JSON.stringify(begin));
  check('profile: a key too long for a QR code is still shown to type in', appEl.textContent.includes(secret));

  answers['passkey.create.begin'] = () => ({ error: 'invalid_password' });
  if (unnamed[1]) unnamed[1].value = 'pass-2';
  byText(t('passkey_add'))?.click();
  await settle();
  const pk = calls.find((c) => c.action === 'passkey.create.begin');
  check('profile: a passkey is added with the current password', pk?.password === 'pass-2', JSON.stringify(pk));
  check('profile: the error is announced (role=alert)', appEl.querySelectorAll('.error').some((e) => e.getAttribute('role') === 'alert' && e.textContent === t('e_invalid_password')));
}

// ------------------------------------------------------------ audit: export and searches

{
  let first;
  const row = (user) => ({ t: '2026-11-17T10:00:00.000+01:00', ip: '203.0.113.9', user, event: 'login_fail', ok: false, reason: 'unknown_user', _d: '2026-11-17', _n: 1 });
  answers['admin.audit'] = (b) => b.export ? { rows: [row('=HYPERLINK("https://x.test/?"&A1;"Ver")'), row('@SUM(1)')], total: 2, from: 'a', to: 'b' }
    : first ? { rows: [row('nuevo')], total: 1, events: {}, failures: 1 } : new Promise((r) => { first = () => r({ rows: [row('viejo')], total: 1, events: {}, failures: 1 }); });
  await go('#/admin/audit');
  appEl.querySelector('form').dispatchEvent(new Ev('change'));   // a second search while the first is still running
  await settle();
  first();
  await settle();
  const shown = appEl.querySelector('table')?.textContent || '';
  check('audit: an older search answering late does not replace the newer one', shown.includes('nuevo') && !shown.includes('viejo'), shown.slice(0, 80));

  byText('CSV').click();
  await settle();
  const [url, blob] = [...blobs].at(-1) || [];
  const csv = blob ? await blob.text() : '';
  check('audit: CSV cells cannot start a spreadsheet formula', csv.includes('"\'=HYPERLINK(') && csv.includes('"\'@SUM(1)"'), csv.split('\r\n')[1]);
  runTimers(5000);
  check('audit: the exported file is freed after the download', revoked.includes(url));

  appEl.querySelector('tbody').children[0].click();      // a row opens its detail
  const back = body.querySelector('.modal-back');
  document.activeElement.dispatchEvent(new Ev('keydown', { key: 'Escape' }));
  check('audit: Escape closes the detail window', back && !back.isConnected);

  const file = '{"rows":[\n' + JSON.stringify(row('José')) + '\n],"total":1,"from":"a","to":"b"}\n';   // as the gateway writes it
  const sig = Uint8Array.from([0x30, 0x45, 0x02, 0x21, 0xff, 0x00, 0x80]);
  answers['admin.audit'] = () => file + btoa(String.fromCharCode(...sig));
  byText('JSON').click();
  await settle();
  const zip = new Uint8Array(await ([...blobs].at(-1)?.[1].arrayBuffer() ?? new ArrayBuffer(0)));
  const has = (part) => zip.some((_, i) => part.every((v, k) => zip[i + k] === v));
  check('audit: the signed JSON export kept byte for byte with its signature in a ZIP', has(new TextEncoder().encode(file)) && has(sig)
    && has(new TextEncoder().encode('audit.json.sig')));
  answers['admin.audit.key'] = () => ({ key: '-----BEGIN PUBLIC KEY-----\nMFkwEwYH\n-----END PUBLIC KEY-----\n' });
  const det = appEl.querySelector('details');
  det.open = true;
  det.dispatchEvent(new Ev('toggle'));
  await settle();
  check('audit: the public key of the signatures and the command to check a file', det.textContent.includes('MFkwEwYH')
    && det.textContent.includes('openssl dgst -sha256 -verify mudesk-audit-key.pem -signature audit.json.sig audit.json'));
}

// ------------------------------------------------------------ session window

{
  const status = (wk, j) => wk.onmessage({ data: { t: 'frame', type: 3, d: new TextEncoder().encode(JSON.stringify(j)) } });
  await go('#/s/c1');
  let form = appEl.querySelector('form');
  form.querySelector('[name=password]').value = 'wrong';
  form.querySelector('[name=save_pw]').checked = true;
  submit(form);
  await settle();
  let wk = workers.at(-1);
  status(wk, { token: 't' });
  status(wk, { end: 'auth_failed' });
  wk.onmessage({ data: { t: 'end' } });
  await settle();
  form = appEl.querySelector('.overlay').querySelector('form');   // asks again
  form.querySelector('[name=password]').value = 'right';
  submit(form);
  await settle();
  wk = workers.at(-1);
  status(wk, { token: 't2' });
  status(wk, { connected: true });
  await settle();
  const saved = calls.find((c) => c.action === 'conns.password');
  check('session: "save password" keeps the password that worked', saved?.password === 'right', JSON.stringify(saved));

  const root = appEl.querySelector('.session');
  check('session: sound can start from a tap (pointerup / touchend)', !!(root.l.pointerup && root.l.touchend), Object.keys(root.l).join(' '));

  const pings = () => calls.filter((c) => c.action === 'state').length;
  const before = pings();
  clock += 61000;
  runTimers(1000);                                       // a minute without input: no request
  const idle = pings() - before;
  appEl.querySelector('textarea').dispatchEvent(new Ev('keydown', { code: 'KeyA', key: 'a' }));
  clock += 61000;
  runTimers(1000);
  check('session: the web session is kept alive only while the remote session is in use', idle === 0 && pings() - before === 1, `${idle} / ${pings() - before}`);
}

// ------------------------------------------------------------ session toolbar: on-screen keyboard and clipboard history

{
  // with a mouse the keyboard button opens our on-screen keyboard (before it did nothing there): keys as scancodes, Shift
  // shown pressed until the next key, at the top or the bottom, closed with its X; on a touch-only device, the device's
  // keyboard. Panels close with their X. The quality indicator keeps its width. The clipboard panel lists what was copied
  // and pastes it again (like Win+V).
  const { RdpSession } = await import('../public/assets/session.js');
  const done = [], saved = ['combo', 'showKeyboard', 'pasteText', 'tapKey'].map((k) => [k, RdpSession.prototype[k]]);
  RdpSession.prototype.combo = (codes) => done.push('combo ' + codes.join('+'));
  RdpSession.prototype.showKeyboard = () => done.push('device keyboard');
  RdpSession.prototype.pasteText = (text) => done.push('paste ' + text);
  const tapKey = saved.find(([k]) => k === 'tapKey')[1];
  RdpSession.prototype.tapKey = function (code) { done.push('key ' + code); return tapKey.call(this, code); };
  const media = globalThis.matchMedia;
  const open = async (touch) => {
    globalThis.matchMedia = (q) => ({ matches: touch && q === '(pointer: coarse)' });
    Object.assign(CONN, { clipboard: true, ask: true, kbd: 0x040A });
    await go('#/conns');
    await go('#/s/c1');
    const form = appEl.querySelector('.overlay').querySelector('form');
    dialogs.push(form.textContent);
    const pw = form.querySelector('[name=password]');  // asked unless the password was saved above
    if (pw) pw.value = 'pw';
    submit(form);
    await settle();
    const wk = workers.at(-1);
    wk.onmessage({ data: { t: 'frame', type: 3, d: new TextEncoder().encode('{"token":"t9"}') } });
    wk.onmessage({ data: { t: 'frame', type: 3, d: new TextEncoder().encode('{"connected":true}') } });
    await settle();
    return wk;
  };
  const barButton = (title) => appEl.querySelector('.bar').querySelectorAll('button').find((b) => b.getAttribute('title') === title);

  var dialogs = [];                                      // connect dialogs seen (hoisted: open() pushes to it)
  let wk = await open(false);
  const rect0 = El.prototype.getBoundingClientRect;     // the keyboard 960 x 260, where its style puts it (32, 500 at first)
  El.prototype.getBoundingClientRect = function () {
    return this.className.split(' ').includes('osk') ? { left: Number.parseFloat(this.style.left) || 32, top: Number.parseFloat(this.style.top) || 500, width: 960, height: 260 }
      : rect0.call(this);
  };
  barButton(t('show_keyboard'))?.click();
  await settle();
  const osk = appEl.querySelector('.osk');
  const oskKey = (code) => osk?.querySelectorAll('button').find((b) => b.getAttribute('data-code') === code);
  const labels = ['Semicolon', 'Digit2', 'IntlBackslash'].map((c) => oskKey(c)?.textContent).join(' ');   // Spanish layout: Shift label, then the key's
  oskKey('ShiftLeft')?.click();
  const shiftShown = oskKey('ShiftLeft')?.className.includes('on') && osk.querySelector('.hint')?.textContent === t('osk_held_one', t('key_shift'));
  oskKey('KeyA')?.click();
  const shiftGone = !oskKey('ShiftLeft')?.className.includes('on') && osk.querySelector('.hint')?.textContent === '';
  // dragged from its grip anywhere in the window (1024 x 768 here), never out of it; a key does not move it
  const grip = osk.querySelectorAll('button').find((b) => b.getAttribute('title') === t('osk_move'));
  const dragTo = (from, x, y) => {
    from.dispatchEvent(new Ev('pointerdown', { button: 0, pointerId: 1, clientX: 40, clientY: 510 }));
    osk.dispatchEvent(new Ev('pointermove', { pointerId: 1, clientX: 40 + x, clientY: 510 + y }));
    osk.dispatchEvent(new Ev('pointerup', { pointerId: 1 }));
    return [osk.style.left, osk.style.top];
  };
  const moved = [dragTo(grip, 20, -300), dragTo(grip, 5000, 5000), dragTo(oskKey('KeyQ'), -100, -100)];
  osk?.querySelectorAll('button').find((b) => b.getAttribute('title') === t('close'))?.click();
  barButton(t('show_keyboard'))?.click();               // opened again: where it was left
  await settle();
  const again = [appEl.querySelector('.osk')?.style.left, appEl.querySelector('.osk')?.style.top];
  appEl.querySelector('.osk')?.querySelectorAll('button').find((b) => b.getAttribute('title') === t('close'))?.click();
  El.prototype.getBoundingClientRect = rect0;
  const dragOk = JSON.stringify(moved) === '[["52px","200px"],["64px","508px"],["64px","508px"]]' && JSON.stringify(again) === '["64px","508px"]';
  const oskOk = !!osk && labels === 'Ñ "2 ><' && shiftShown && shiftGone && dragOk && !appEl.querySelector('.osk');
  clock += 1000;
  runTimers(1000);
  const stats = appEl.querySelector('.stats')?.textContent || '';
  barButton(t('clipboard_panel'))?.click();
  const panelEl = appEl.querySelector('.panel');
  panelEl.querySelector('.panel-head')?.querySelectorAll('button').find((b) => b.getAttribute('title') === t('close'))?.click();
  const closed = panelEl.className.includes('hidden');
  barButton(t('clipboard_panel'))?.click();
  const empty = appEl.querySelector('.panel').textContent.includes(t('clip_history_empty'));
  const clip = (text) => {                               // the remote side copies a text: 'T' + UTF-16 with its NUL
    const d = new Uint8Array(1 + text.length * 2 + 2);
    d[0] = 84;
    for (let i = 0; i < text.length; i++) d[1 + 2 * i] = text.charCodeAt(i);
    wk.onmessage({ data: { t: 'frame', type: 4, d } });
  };
  clip('primero');
  clip('segundo');
  await settle();
  const rows = appEl.querySelector('.clip-history')?.querySelectorAll('li') || [];
  const listed = rows.map((li) => li.querySelector('.clip-text')?.textContent);
  rows[1]?.querySelectorAll('button').find((b) => b.textContent === t('clip_paste'))?.click();
  appEl.querySelector('.clip-history').querySelectorAll('li')[1]?.querySelectorAll('button').find((b) => b.textContent === t('clip_copy_item'))?.click();
  const afterCopy = appEl.querySelector('.clip-history').querySelectorAll('li').map((li) => li.querySelector('.clip-text')?.textContent);
  wk = await open(true);                                 // a phone: its own keyboard; the history is the window's
  barButton(t('show_keyboard'))?.click();
  check('session toolbar: our on-screen keyboard with a mouse (Spanish labels, Shift until the next key, dragged anywhere in the window, X), the device keyboard on touch',
    oskOk && JSON.stringify(done.filter((x) => !x.startsWith('paste'))) === '["key ShiftLeft","key KeyA","device keyboard"]', JSON.stringify({ oskOk, labels, moved, again, done }));
  check('session toolbar: panels close with their X; the quality indicator always 29 characters', closed && stats.length === 29, JSON.stringify(stats));
  check('connect dialog: without the Window Management API (Firefox) it says how the other screen is looked for, not on a phone',
    dialogs[0]?.includes(t('screens_other_browsers')) && !dialogs[1]?.includes(t('screens_other_browsers')), JSON.stringify(dialogs.map((d) => d.slice(-90))));
  check('session toolbar: clipboard history listed newest first, pasted again, copied again to the top',
    empty && JSON.stringify(listed) === '["segundo","primero"]' && JSON.stringify(afterCopy) === '["primero","segundo"]' && done.includes('paste primero'),
    JSON.stringify({ empty, listed, afterCopy }));

  globalThis.matchMedia = media;
  saved.forEach(([k, f]) => { RdpSession.prototype[k] = f; });
  Object.assign(CONN, { clipboard: false });
}

// ------------------------------------------------------------ session toolbar: the Files panel

{
  // files dropped on the Files panel go to the remote clipboard only (dropped there, they were pasted on the part of the
  // remote screen behind the panel); folders are left out and said so; dropped on the remote screen, pasted at that point
  const { ClipboardBridge } = await import('../public/assets/clipboard.js');
  const sent = [], send0 = ClipboardBridge.prototype.sendFiles;
  ClipboardBridge.prototype.sendFiles = async function (files, at = null) { sent.push([files.map((f) => f.name), at ? 'at' : 'clipboard']); };
  Object.assign(CONN, { clipboard: true, ask: true, kbd: 0x040A });
  await go('#/conns');
  await go('#/s/c1');
  const form = appEl.querySelector('.overlay').querySelector('form');
  const pw = form.querySelector('[name=password]');
  if (pw) pw.value = 'pw';
  submit(form);
  await settle();
  const wk = workers.at(-1);
  wk.onmessage({ data: { t: 'frame', type: 3, d: new TextEncoder().encode('{"token":"t12"}') } });
  wk.onmessage({ data: { t: 'frame', type: 3, d: new TextEncoder().encode('{"connected":true}') } });
  await settle();
  appEl.querySelector('.bar').querySelectorAll('button').find((b) => b.getAttribute('title') === t('files_panel'))?.click();
  const panel = appEl.querySelector('.panel'), zone = panel.querySelector('.drop-zone');
  const dt = { files: [new File(['a'], 'a.txt'), new File([''], 'Carpeta')], types: ['Files'],
    items: [{ kind: 'file', webkitGetAsEntry: () => ({ isDirectory: false }) }, { kind: 'file', webkitGetAsEntry: () => ({ isDirectory: true }) }] };
  const over = new Ev('dragover', { dataTransfer: dt, clientX: 900, clientY: 100 });
  zone.dispatchEvent(over);
  const marked = panel.className.includes('drop-over') && !appEl.querySelector('.session').className.includes(' drop');
  zone.dispatchEvent(new Ev('drop', { dataTransfer: dt, clientX: 900, clientY: 100 }));
  await settle();
  const toasts = document.body.querySelector('.toasts')?.textContent || '';
  appEl.querySelector('canvas').dispatchEvent(new Ev('drop', { dataTransfer: { files: [new File(['b'], 'b.txt')], items: [] }, clientX: 50, clientY: 50 }));
  await settle();
  check('Files panel: a drop zone; files dropped on the panel only go to the remote clipboard (folders left out, said so), on the screen they are pasted there',
    !!zone && marked && JSON.stringify(sent) === '[[["a.txt"],"clipboard"],[["b.txt"],"at"]]' && toasts.includes(t('folders_not_sent')),
    JSON.stringify({ zone: !!zone, marked, sent }));
  ClipboardBridge.prototype.sendFiles = send0;
  Object.assign(CONN, { clipboard: false });
}

// ------------------------------------------------------------ RemoteApp

{
  // a connection with a program: marked RemoteApp in the list; its connect dialog says so and offers several screens (not
  // the graphics pipeline); the session shows the page behind the program's windows, lists them in a Windows
  // panel (the active one first in the tab title) and sends their commands
  const raf0 = globalThis.requestAnimationFrame;
  globalThis.requestAnimationFrame = (f) => { Promise.resolve().then(f); return 1; };
  Object.assign(CONN, { app: '||Notepad', clipboard: false, ask: true });
  await go('#/conns');
  const badge = appEl.querySelector('.conn')?.querySelector('.app-line')?.textContent;
  await go('#/s/c1');
  const form = appEl.querySelector('.overlay').querySelector('form');
  const dialog = [form.textContent.includes(t('rail_session', 'Notepad')), !!form.querySelector('[name=multi]'), !form.querySelector('[name=gfx]')];
  const pw = form.querySelector('[name=password]');
  if (pw) pw.value = 'pw';
  submit(form);
  await settle();
  const wk = workers.at(-1);
  wk.onmessage({ data: { t: 'frame', type: 3, d: new TextEncoder().encode('{"token":"t13"}') } });
  wk.onmessage({ data: { t: 'frame', type: 3, d: new TextEncoder().encode('{"connected":true}') } });
  const op = (cmd) => wk.onmessage({ data: { t: 'op', cmd } });
  op({ op: 'window', id: 7, new: true, owner: 0, show: 5, title: 'Sin título: Bloc de notas', x: 10, y: 10, w: 400, h: 300 });
  op({ op: 'window', id: 8, new: true, owner: 0, show: 2, title: 'Otro', x: 0, y: 0, w: 100, h: 100 });
  op({ op: 'desktop', active: 7 });
  await settle();
  const page = appEl.querySelector('.session')?.className;
  const title = document.title;
  appEl.querySelector('.bar').querySelectorAll('button').find((b) => b.getAttribute('title') === t('rail_windows'))?.click();
  await settle();
  const rows = appEl.querySelector('.panel').querySelectorAll('li').map((li) => li.querySelector('.rail-title').textContent);
  const n0 = rdpSent.length;
  appEl.querySelector('.panel').querySelectorAll('li')[1].querySelector('.rail-title').click();
  appEl.querySelector('.panel').querySelectorAll('li')[0].querySelectorAll('button').find((b) => b.getAttribute('title') === t('rail_close'))?.click();
  await settle();
  const cmds = rdpSent.slice(n0).filter((p) => p[0] === 14).map((p) => new TextDecoder().decode(new Uint8Array(p.slice(1))));
  check('RemoteApp: marked in the list; its dialog says so and offers several screens (no graphics option); its windows over the page, listed, the active one in the tab title; their commands',
    badge === 'RemoteApp Notepad' && JSON.stringify(dialog) === '[true,true,true]' && page.includes('rail')
    && title.startsWith('Sin título: Bloc de notas · PC') && JSON.stringify(rows) === JSON.stringify(['Sin título: Bloc de notas', 'Otro' + t('rail_minimized')])
    && JSON.stringify(cmds) === JSON.stringify(['{"a":"sys","id":8,"c":61728}', '{"a":"activate","id":8}', '{"a":"sys","id":7,"c":61536}']),
    JSON.stringify({ badge, dialog, page, title, rows, cmds }));
  // the taskbar at the bottom: a button per window (the active one marked, a minimized one too); the active one is minimized
  // from there, another brought to the front (restored first when minimized): before, minimizing left an empty page
  const tb = appEl.querySelector('.rail-taskbar');
  const tasks = tb?.querySelectorAll('button').map((b) => [b.textContent, b.className]);
  const n1 = rdpSent.length;
  tb?.querySelectorAll('button')[0].click();
  tb?.querySelectorAll('button')[1].click();
  await settle();
  const taskCmds = rdpSent.slice(n1).filter((p) => p[0] === 14).map((p) => new TextDecoder().decode(new Uint8Array(p.slice(1))));
  check('RemoteApp: a taskbar at the bottom; the active window minimized from it, a minimized one restored and brought to the front',
    JSON.stringify(tasks) === JSON.stringify([['Sin título: Bloc de notas', 'rail-task active'], ['Otro', 'rail-task min']])
    && JSON.stringify(taskCmds) === JSON.stringify(['{"a":"sys","id":7,"c":61472}', '{"a":"sys","id":8,"c":61728}', '{"a":"activate","id":8}']),
    JSON.stringify({ tasks, taskCmds }));
  // with its windows, nothing in the middle of the page; closed, nothing either (before, it said "Opening" until the
  // session ended)
  const mid = appEl.querySelector('.rail-wait');
  const withWins = mid?.classList.contains('hidden');
  op({ op: 'window', id: 7, deleted: true });
  op({ op: 'window', id: 8, deleted: true });
  await settle();
  const closedMid = mid?.classList.contains('hidden');
  globalThis.requestAnimationFrame = raf0;
  Object.assign(CONN, { app: '' });
  check('RemoteApp: with its windows, nothing in the middle of the page; closed, nothing either (not "Opening")',
    withWins === true && closedMid === true, JSON.stringify({ withWins, closedMid }));
}

{
  // a new connection, the program not here yet: "Opening…" with a spinner in the middle of the page (else it looked stuck)
  // and in the taskbar; a while later, that it is taking longer than usual (no button: a server ends a session without a
  // program some 12 s after it synced, so the page connects again by itself)
  const raf0 = globalThis.requestAnimationFrame;
  globalThis.requestAnimationFrame = (f) => { Promise.resolve().then(f); return 1; };
  Object.assign(CONN, { app: '||Notepad', clipboard: false, ask: true });
  await go('#/conns');
  await go('#/s/c1');
  const form = appEl.querySelector('.overlay').querySelector('form');
  if (form.querySelector('[name=password]')) form.querySelector('[name=password]').value = 'pw';
  submit(form);
  await settle();
  const wk = workers.at(-1);
  wk.onmessage({ data: { t: 'frame', type: 3, d: new TextEncoder().encode('{"token":"t30"}') } });
  wk.onmessage({ data: { t: 'frame', type: 3, d: new TextEncoder().encode('{"connected":true}') } });
  await settle();
  const tb = appEl.querySelector('.rail-taskbar'), mid = appEl.querySelector('.rail-wait');
  const connected = [!mid?.classList.contains('hidden'), mid?.textContent];   // connected, the server's windows not here yet
  const now0 = Date.now;
  let fake = now0();
  Date.now = () => fake;
  wk.onmessage({ data: { t: 'op', cmd: { op: 'desktop', completed: true } } });
  await settle();
  const opening = tb?.textContent;
  const spin = [!mid?.classList.contains('hidden'), !!mid?.querySelector('.spinner'), mid?.textContent];
  fake += 16000;
  timers.filter((x) => x.ms === 15050).forEach((x) => x.f());
  await settle();
  const slow = [tb?.textContent, mid?.textContent, !!mid?.querySelector('.spinner'), mid?.querySelectorAll('button').length];
  // the server ends the session while it is still opening (error info 0x0B): no "Disconnected" box, the spinner stays, and
  // the page connects again by itself (before, it offered to open the program and was disconnected right after)
  wk.onmessage({ data: { t: 'frame', type: 3, d: new TextEncoder().encode('{"errorInfo":11}') } });
  wk.onmessage({ data: { t: 'frame', type: 3, d: new TextEncoder().encode('{"end":"error_info","errorInfo":11}') } });
  wk.onmessage({ data: { t: 'end' } });
  await settle();
  const w0 = workers.length;
  timers.filter((x) => x.ms === 300).forEach((x) => x.f());
  await settle();
  const overlayEl = appEl.querySelector('.overlay');
  const cut = [overlayEl?.classList.contains('hidden'), !mid?.classList.contains('hidden'), !!mid?.querySelector('.spinner'), workers.length === w0 + 1];
  Date.now = now0;
  check('RemoteApp: the program not here yet, a spinner in the middle and the taskbar say it is opening, later that it takes longer; ended by the server meanwhile, the spinner stays and it connects again',
    JSON.stringify(connected) === JSON.stringify([true, t('rail_opening', 'Notepad')])
    && JSON.stringify(spin) === JSON.stringify([true, true, t('rail_opening', 'Notepad')]) && opening === t('rail_opening', 'Notepad')
    && JSON.stringify(slow) === JSON.stringify([t('rail_slow', 'Notepad'), t('rail_slow', 'Notepad'), true, 0])
    && JSON.stringify(cut) === '[true,true,true,true]', JSON.stringify({ connected, spin, opening, slow, cut }));
  globalThis.requestAnimationFrame = raf0;
  Object.assign(CONN, { app: '' });
}

{
  // the session toolbar moved to a side: dragged by its grip (kept inside the window), arrow keys on the grip; where it was
  // left is kept for next time
  const store = new Map();
  globalThis.localStorage = { getItem: (k) => store.get(k) ?? null, setItem: (k, v) => store.set(k, String(v)) };
  const iw0 = globalThis.innerWidth;
  globalThis.innerWidth = 2000;                          // the toolbar (the document double) is 1024 wide
  Object.assign(CONN, { clipboard: false, ask: true });
  await go('#/conns');
  await go('#/s/c1');
  let form = appEl.querySelector('.overlay').querySelector('form');
  if (form.querySelector('[name=password]')) form.querySelector('[name=password]').value = 'pw';
  submit(form);
  await settle();
  const bar = appEl.querySelector('.bar'), grip = bar.querySelector('.grip');
  const fire = (type, o) => grip.dispatchEvent(new Ev(type, { button: 0, pointerId: 1, ...o }));
  const middle = bar.style.left || '';
  fire('pointerdown', { clientX: 1000 });                // grabbed 12 px right of its centre (512)
  fire('pointermove', { clientX: 300 });
  const atEdge = bar.style.left;                         // kept inside: its centre no nearer than half its width
  fire('pointermove', { clientX: 1700 });
  const moved = bar.style.left;
  fire('pointerup', { clientX: 1700 });
  const kept = store.get('mudesk-bar-x');
  grip.dispatchEvent(new Ev('keydown', { key: 'ArrowRight' }));
  const byKey = bar.style.left;
  await go('#/conns');
  await go('#/s/c1');
  form = appEl.querySelector('.overlay').querySelector('form');
  if (form.querySelector('[name=password]')) form.querySelector('[name=password]').value = 'pw';
  submit(form);
  await settle();
  const again = appEl.querySelector('.bar').style.left;
  check('session toolbar: moved to a side by its grip (kept inside the window) or the arrow keys; kept for next time',
    middle === '' && atEdge === '512px' && moved === '1212px' && kept === '0.606' && byKey === '1252px' && again === '1252px',
    JSON.stringify({ middle, atEdge, moved, kept, byKey, again }));
  globalThis.innerWidth = iw0;
  delete globalThis.localStorage;
}

// ------------------------------------------------------------ the session's tab closed from its dialogs

{
  // the connect dialog has a button to close its window (the session's tab); a browser that refuses (Firefox, for a tab
  // opened from a link) leaves the page going back to the connections instead of doing nothing. The disconnected window's
  // Close does the same
  Object.assign(CONN, { ask: true, app: '' });
  await go('#/conns');
  await go('#/s/c1');
  const x = appEl.querySelector('.overlay')?.querySelector('.box')?.querySelector('.box-close');
  const [close0, closed0] = [globalThis.close, globalThis.closed];
  let closes = 0;
  globalThis.close = () => closes++;
  globalThis.closed = false;
  const n0 = timers.length;
  x?.click();
  timers.slice(n0).filter((tm) => tm.ms === 300).forEach((tm) => tm.f());
  const refused = [closes, location.hash];
  // a browser that closes it: nothing else
  await go('#/s/c1');
  globalThis.close = () => { closes++; globalThis.closed = true; };
  const n1 = timers.length;
  location.hash = '#/s/c1';
  appEl.querySelector('.overlay')?.querySelector('.box-close')?.click();
  timers.slice(n1).filter((tm) => tm.ms === 300).forEach((tm) => tm.f());
  check('session tab: its connect dialog closes the window (a browser that refuses it: back to the connections)',
    x?.getAttribute('title') === t('close_window') && JSON.stringify(refused) === JSON.stringify([1, '#/conns']) && closes === 2 && location.hash === '#/s/c1',
    JSON.stringify({ title: x?.getAttribute('title'), refused, closes, hash: location.hash }));
  Object.assign(globalThis, { close: close0, closed: closed0 });
}

// ------------------------------------------------------------ session toolbar: screenshot

{
  // the camera button: the whole remote desktop or an area framed with the mouse (mapped to remote pixels whatever the scale,
  // cut to the remote screen; Esc cancels, a click does not count), then saved as PNG, copied to this computer's clipboard or
  // put on the remote one
  const { ClipboardBridge } = await import('../public/assets/clipboard.js');
  const sendImage0 = ClipboardBridge.prototype.sendImage, toRemote = [];
  ClipboardBridge.prototype.sendImage = async (b) => { toRemote.push(b.type); };
  Object.assign(CONN, { clipboard: true, ask: true });
  await go('#/conns');
  await go('#/s/c1');
  const form = appEl.querySelector('.overlay').querySelector('form');
  const pw = form.querySelector('[name=password]');
  if (pw) pw.value = 'pw';
  submit(form);
  await settle();
  const wk = workers.at(-1);
  wk.onmessage({ data: { t: 'frame', type: 3, d: new TextEncoder().encode('{"token":"t9"}') } });
  wk.onmessage({ data: { t: 'frame', type: 3, d: new TextEncoder().encode('{"connected":true}') } });
  await settle();
  const canvas = appEl.querySelector('canvas');           // a 1024 x 768 desktop shown at half size, 12 px from the left and 40 from the top
  Object.assign(canvas, { width: 1024, height: 768 });
  canvas.getBoundingClientRect = () => ({ left: 12, top: 40, width: 512, height: 384 });
  const draws = [], ctx0 = El.prototype.getContext;       // what each capture copies: [its size, the area of the desktop]
  El.prototype.getContext = function () { const c = this; return new Proxy({}, { get: (_, k) => (k === 'drawImage' ? (...a) => draws.push([c.width, c.height, ...a.slice(1, 5)]) : () => {}) }); };
  const panelEl = () => appEl.querySelector('.panel');
  const caption = () => panelEl().querySelector('figcaption')?.textContent;
  appEl.querySelector('.bar').querySelectorAll('button').find((b) => b.getAttribute('title') === t('shot'))?.click();
  const offered = [byText(t('shot_full'), panelEl()), byText(t('shot_crop'), panelEl())].every(Boolean) && !panelEl().querySelector('figure');
  const toBlob0 = El.prototype.toBlob, later = [];       // the PNG takes a while: the panel says so at once
  El.prototype.toBlob = function (cb, type) { later.push(() => toBlob0.call(this, cb, type)); };
  byText(t('shot_full'), panelEl()).click();
  await settle();
  const waiting = panelEl().textContent.includes(t('shot_taking')) && !byText(t('shot_save'), panelEl());
  El.prototype.toBlob = toBlob0;
  later.forEach((f) => f());
  await settle();
  const full = [JSON.stringify(draws.at(-1)), caption()];
  const frame = async (x0, y0, x1, y1) => {
    byText(t('shot_crop'), panelEl()).click();
    const crop = appEl.querySelector('.crop'), n = draws.length;
    crop.dispatchEvent(new Ev('pointerdown', { button: 0, pointerId: 1, clientX: x0, clientY: y0 }));
    crop.dispatchEvent(new Ev('pointermove', { pointerId: 1, clientX: x1, clientY: y1 }));
    const size = crop.querySelector('.crop-size')?.textContent;
    crop.dispatchEvent(new Ev('pointerup', { pointerId: 1, clientX: x1, clientY: y1 }));
    await settle();
    return [size, draws.length > n ? JSON.stringify(draws.at(-1)) : null, !!appEl.querySelector('.crop'), caption()];
  };
  const part = await frame(62, 90, 162, 165);            // (50, 50)-(150, 125) on the half-size view: (100, 100)-(300, 250) remote
  const past = await frame(900, 700, 0, 0);               // dragged past the remote screen: cut to it
  const click = await frame(80, 80, 80, 80);              // a click: no capture, the frame stays to draw again
  const cropEl = appEl.querySelector('.crop');
  cropEl?.dispatchEvent(new Ev('keydown', { key: 'Escape' }));
  const cancelled = !appEl.querySelector('.crop') && draws.length === 3;
  const saved = [], click0 = El.prototype.click;
  El.prototype.click = function () { if (this.tagName === 'A') saved.push(this.download); return click0.call(this); };
  byText(t('shot_save'), panelEl()).click();
  El.prototype.click = click0;
  const copied = [];
  globalThis.ClipboardItem = class { constructor(o) { this.types = Object.keys(o); } };
  Object.defineProperty(globalThis.navigator, 'clipboard', { value: { write: async (items) => { copied.push(items[0].types.join()); } }, configurable: true });
  byText(t('shot_copy'), panelEl()).click();
  await settle();
  byText(t('shot_remote'), panelEl()).click();
  await settle();
  El.prototype.getContext = ctx0;
  ClipboardBridge.prototype.sendImage = sendImage0;
  delete globalThis.ClipboardItem;
  check('session toolbar: a screenshot of the whole remote desktop or of an area framed over it (remote pixels at any scale, cut to the screen, Esc cancels; "taking it" shown at once), saved as PNG or copied here or to the remote clipboard',
    offered && waiting && !panelEl().textContent.includes(t('shot_taking')) && JSON.stringify(full) === '["[1024,768,0,0,1024,768]","1024 × 768 px"]'
    && JSON.stringify(part) === '["200 × 150","[200,150,100,100,200,150]",false,"200 × 150 px"]'
    && JSON.stringify(past) === '["1024 × 768","[1024,768,0,0,1024,768]",false,"1024 × 768 px"]'
    && JSON.stringify(click) === '["0 × 0",null,true,"1024 × 768 px"]' && cancelled
    && /^PC \d{4}-\d\d-\d\d \d\d-\d\d-\d\d\.png$/.test(saved[0] || '') && JSON.stringify(copied) === '["image/png"]' && JSON.stringify(toRemote) === '["image/png"]',
    JSON.stringify({ offered, waiting, full, part, past, click, cancelled, saved, copied, toRemote }));
  Object.assign(CONN, { clipboard: false });
}

// ------------------------------------------------------------ several screens: full screen uses all of them

{
  // a session on one screen of a computer with two: "Full screen" offers to reconnect using all of them (before, only this
  // screen went full screen); chosen, the other screen's window opens in that click and the session comes back with a
  // monitor on each. A screen is a remote monitor only while its window is open: closed, its monitor leaves (Windows brings
  // its programs to this one); "Full screen" opens it again in the same click, full screen asked for a given screen (Chromium
  // then allows that "companion" window; without it the window was blocked). Before, every screen was a remote monitor from
  // the start, its window open or not, so programs could open on a screen nobody saw.
  const screens = [{ left: 0, top: 0, width: 1920, height: 1080, isPrimary: true, availLeft: 0, availTop: 0, availWidth: 1920, availHeight: 1040 },
    { left: 1920, top: 0, width: 1280, height: 1024, isPrimary: false, availLeft: 1920, availTop: 0, availWidth: 1280, availHeight: 984 }];
  const [media, open0] = [globalThis.matchMedia, window.open];
  globalThis.matchMedia = () => ({ matches: false });
  screen.isExtended = true;
  window.getScreenDetails = async () => ({ screens, currentScreen: screens[0] });
  const opened = [], winL2 = {};
  const docL = {}, fsCalls = [];
  const doc = { title: '', body: new El('body'), fullscreenElement: null, addEventListener: (ty, f) => (docL[ty] ||= []).push(f),
    exitFullscreen: async () => { fsCalls.push('exit'); }, documentElement: { requestFullscreen: async () => { fsCalls.push('enter'); } } };
  doc.createElement = (tag) => Object.assign(new El(tag), { ownerDocument: doc });
  const popup = doc.defaultView = { document: doc, innerWidth: 1280, innerHeight: 984, addEventListener: (ty, f) => (winL2[ty] ||= []).push(f),
    setTimeout: () => 0, clearTimeout() {}, close: () => (winL2.pagehide || []).forEach((f) => f()) };
  window.open = (url, name, features) => { opened.push(features); return popup; };
  Object.assign(CONN, { clipboard: false, ask: true, kbd: 0x040A });
  await go('#/conns');
  await go('#/s/c1');
  const form = appEl.querySelector('.overlay').querySelector('form');
  const pw = form.querySelector('[name=password]');
  if (pw) pw.value = 'pw';
  submit(form);
  await settle();
  let wk = workers.at(-1);
  const status = (j) => wk.onmessage({ data: { t: 'frame', type: 3, d: new TextEncoder().encode(JSON.stringify(j)) } });
  status({ token: 't10' });
  status({ connected: true });
  await settle();
  const full = () => appEl.querySelector('.bar').querySelectorAll('button').find((b) => b.getAttribute('title') === t('fullscreen'));
  full().click();
  await settle();
  const offered = !!document.body.querySelector('.modal')?.textContent.includes(t('screens_offer'));
  byText(t('screens_use_all'), document.body)?.click();
  await settle();
  wk.onmessage({ data: { t: 'end' } });                  // the stream of the session on one screen ends
  await settle();
  clock += 500;
  runTimers(500);
  await settle();
  const restarted = workers.at(-1) !== wk;
  const body = restarted ? JSON.parse(workers.at(-1).sent.find((m) => m.body)?.body || '{}') : {};
  wk = workers.at(-1);
  status({ token: 't11' });
  status({ connected: true });
  wk.onmessage({ data: { t: 'frame', type: 5, d: new Uint8Array(0) } });   // Display Control is there
  await settle();
  const decode = (p) => (p[0] === 13 ? JSON.parse(new TextDecoder().decode(new Uint8Array(p.slice(1)))) : p[0] === 2 ? ['resize', p[1] | (p[2] << 8), p[3] | (p[4] << 8)] : null);
  // its bar: full screen, then (in full screen) out of it; Close this screen closes its window
  const sbar = doc.body.querySelector('.screen-bar'), fsb = () => sbar?.querySelector('.fs');
  const labels = [fsb()?.textContent];
  fsb()?.click();
  doc.fullscreenElement = doc.documentElement;
  (docL.fullscreenchange || []).forEach((f) => f());
  labels.push(fsb()?.textContent);
  fsb()?.click();
  const sentNow = rdpSent.length;
  sbar?.querySelector('.close')?.click();                // the other screen's window closed: its monitor leaves
  await settle();
  const left = rdpSent.slice(sentNow).map(decode).filter(Boolean);
  wk.onmessage({ data: { t: 'frame', type: 2, d: new Uint8Array([1920 & 255, 1920 >> 8, 1080 & 255, 1080 >> 8, 32]) } });   // taken
  await settle();
  const sentThen = rdpSent.length, asked = [], rf0 = El.prototype.requestFullscreen;
  El.prototype.requestFullscreen = function (o) { asked.push(o?.screen ? 'screen ' + o.screen.left : 'none'); return rf0.call(this); };
  full().click();                                        // full screen on this screen, and the other screen's window again
  await settle();
  El.prototype.requestFullscreen = rf0;
  const joined = rdpSent.slice(sentThen).map(decode).filter(Boolean);
  check('several screens: full screen offers all of them (the other window opened in that click); a screen is a remote monitor only while its window is open; Full screen asks for this screen and opens the other in one click',
    offered && JSON.stringify(body.mons) === '[[0,0,1920,1080,1],[1920,0,1280,1024,0]]' && opened.length === 2 && opened.every((f) => f.includes('left=1920'))
    && JSON.stringify(left) === '[["resize",1920,1080]]' && JSON.stringify(asked) === '["screen 0"]' && JSON.stringify(joined) === '[[[0,0,1920,1080,1],[1920,0,1280,1024,0]]]',
    JSON.stringify({ offered, mons: body.mons, opened, left, asked, joined }));
  check('several screens: the other screen\'s window has a bar with full screen, a way out of it, and Close this screen (its monitor leaves)',
    JSON.stringify(labels) === JSON.stringify([t('fullscreen'), t('exit_fullscreen')]) && JSON.stringify(fsCalls) === '["enter","exit"]'
    && sbar?.querySelector('.close')?.textContent === t('close_this_screen') && JSON.stringify(left) === '[["resize",1920,1080]]', JSON.stringify({ labels, fsCalls }));
  globalThis.matchMedia = media;
  window.open = open0;
  delete screen.isExtended;
  delete window.getScreenDetails;
}

{
  // RemoteApp on a computer with two screens, the session on the one that is not the primary: "Full screen" offers all of
  // them (before, RemoteApp had one screen and did not); the session comes back with this screen as the primary monitor
  // (the program opens here, with its taskbar) and the other screen's window shows the page behind the program's windows
  const screens = [{ left: 0, top: 0, width: 1920, height: 1080, isPrimary: true, availLeft: 0, availTop: 0, availWidth: 1920, availHeight: 1040 },
    { left: 1920, top: 0, width: 1280, height: 1024, isPrimary: false, availLeft: 1920, availTop: 0, availWidth: 1280, availHeight: 984 }];
  const [media, open0] = [globalThis.matchMedia, window.open];
  globalThis.matchMedia = () => ({ matches: false });
  screen.isExtended = true;
  window.getScreenDetails = async () => ({ screens, currentScreen: screens[1] });
  const doc = { title: '', body: new El('body'), fullscreenElement: null, addEventListener() {}, documentElement: { requestFullscreen: async () => {} } };
  doc.createElement = (tag) => Object.assign(new El(tag), { ownerDocument: doc });
  doc.defaultView = { document: doc, innerWidth: 1920, innerHeight: 1040, addEventListener() {}, setTimeout: () => 0, clearTimeout() {}, close() {} };
  window.open = () => doc.defaultView;
  Object.assign(CONN, { app: '||Notepad', clipboard: false, ask: true });
  await go('#/conns');
  await go('#/s/c1');
  const form = appEl.querySelector('.overlay').querySelector('form');
  const pw = form.querySelector('[name=password]');
  if (pw) pw.value = 'pw';
  submit(form);
  await settle();
  let wk = workers.at(-1);
  const status = (j) => wk.onmessage({ data: { t: 'frame', type: 3, d: new TextEncoder().encode(JSON.stringify(j)) } });
  status({ token: 't20' });
  status({ connected: true });
  await settle();
  appEl.querySelector('.bar').querySelectorAll('button').find((b) => b.getAttribute('title') === t('fullscreen'))?.click();
  await settle();
  const offered = !!document.body.querySelector('.modal')?.textContent.includes(t('screens_offer'));
  byText(t('screens_use_all'), document.body)?.click();
  await settle();
  wk.onmessage({ data: { t: 'end' } });                  // the stream of the session on one screen ends
  await settle();
  clock += 500;
  runTimers(500);
  await settle();
  const restarted = workers.at(-1) !== wk;
  const mons = restarted ? JSON.parse(workers.at(-1).sent.find((m) => m.body)?.body || '{}').mons : null;
  const bg = (doc.body.style.cssText || '').match(/background:([^;]+)/)?.[1];
  check('RemoteApp: full screen offers all the screens; this one the primary monitor, a screen at its left; the other window with the page behind the windows',
    offered && restarted && mons?.length === 2 && mons.find((m) => m[4] === 1)?.[0] === 0 && mons.find((m) => m[4] === 0)?.[0] < 0 && !!bg && bg !== '#000',
    JSON.stringify({ offered, restarted, mons, bg }));
  // the other screen's window carries the watcher that closes it once this window is gone; and this window, closed or
  // reloaded (pagehide), closes it too. Before, closing the session's tab could leave it open, showing nothing
  const script = (doc.head || doc.body).querySelectorAll('script').map((x) => x.src || x.getAttribute('src') || '');
  let closed = 0;
  doc.defaultView.close = () => closed++;
  (winL.pagehide || []).forEach((f) => f({}));
  check('several screens: the other screen\'s window carries its watcher, and closes with this window (pagehide)',
    script.length === 1 && /assets\/screen-watch\.js\?v=/.test(script[0]) && closed === 1, JSON.stringify({ script, closed }));
  globalThis.matchMedia = media;
  window.open = open0;
  delete screen.isExtended;
  delete window.getScreenDetails;
  Object.assign(CONN, { app: '' });
}

{
  // the watcher, in the other screen's window: it closes that window when the session's window is gone (closed, or another
  // page, or another site) and leaves it while that window shows the session
  const vm = await import('node:vm');
  const fs = await import('node:fs');
  const code = fs.readFileSync(new URL('../public/assets/screen-watch.js', import.meta.url), 'utf8');
  const cases = {
    session: { closed: false, document: { querySelector: (s) => (s === '.session' ? {} : null) } },
    closed: { closed: true, document: { querySelector: () => ({}) } },
    otherPage: { closed: false, document: { querySelector: () => null } },
    otherSite: { closed: false, get document() { throw new Error('cross-origin'); } },
    none: null,
  };
  const out = {};
  for (const [name, opener] of Object.entries(cases)) {
    let tick = null, closes = 0;
    vm.runInNewContext(code, { opener, close: () => closes++, setInterval: (f, ms) => { tick = [f, ms]; return 1; } });
    tick[0]();
    out[name] = [closes, tick[1]];
  }
  check('several screens: the watcher closes the other screen\'s window once the session\'s window is gone, once a second',
    JSON.stringify(out) === JSON.stringify({ session: [0, 1000], closed: [1, 1000], otherPage: [1, 1000], otherSite: [1, 1000], none: [1, 1000] }), JSON.stringify(out));
}

// ------------------------------------------------------------ several screens without the API (Firefox, Safari)

{
  // no Window Management API. "Use all my screens" (connect dialog) opens a window just past the right edge of this screen;
  // at the corner of the screen there; it landed on it, so the session starts with a monitor on each and that window fills that screen and shows
  // it. A session on one screen: Screens → "Look for other screens"; the window stayed here (no screen there), so it asks
  // to be taken to the other one, then the session reconnects with both
  const media = globalThis.matchMedia;
  globalThis.matchMedia = () => ({ matches: false });
  Object.assign(screen, { availLeft: 0, availTop: 0, availWidth: 1920, availHeight: 1040 });
  const right = { availLeft: 1920, availTop: 0, availWidth: 1280, availHeight: 984, width: 1280, height: 1024 };
  const mkPopup = (sc) => {
    const doc = { title: '', body: new El('body'), documentElement: new El('html'), addEventListener() {} };
    const w = { document: doc, screen: sc, innerWidth: 400, innerHeight: 300, addEventListener() {}, setTimeout: () => 0, clearTimeout() {},
      screenX: sc.availLeft, screenY: sc.availTop, resizeTo(a, b) { this.size = [a, b]; } };   // opened at its screen's corner
    doc.defaultView = w;
    doc.createElement = (tag) => Object.assign(new El(tag), { ownerDocument: doc });
    return w;
  };
  let popup = mkPopup(right);
  const opened = [], open0 = window.open;
  window.open = (url, name, features) => { opened.push(features); return popup; };
  const connect = async (multi) => {
    Object.assign(CONN, { clipboard: false, ask: true, kbd: 0x040A });
    await go('#/conns');
    await go('#/s/c1');
    const form = appEl.querySelector('.overlay').querySelector('form');
    const pw = form.querySelector('[name=password]');
    if (pw) pw.value = 'pw';
    const box = form.querySelector('[name=multi]');
    if (box) box.checked = multi;
    const note = form.textContent.includes(t('screens_other_browsers'));
    submit(form);
    await settle();
    return { wk: workers.at(-1), note, box: !!box };
  };
  const monsOf = (wk) => JSON.parse(wk.sent.find((m) => m.body)?.body || '{}').mons;
  let c = await connect(true);
  const first = { note: c.note, box: c.box, probe: opened[0], mons: monsOf(c.wk), shown: !!popup.document.body.querySelector('canvas'),
    size: popup.size };
  popup = mkPopup({ ...screen });                        // next time the window stays on this screen
  c = await connect(false);
  const wk = c.wk;
  const status = (j) => wk.onmessage({ data: { t: 'frame', type: 3, d: new TextEncoder().encode(JSON.stringify(j)) } });
  status({ token: 't21' });
  status({ connected: true });
  await settle();
  appEl.querySelector('.bar').querySelectorAll('button').find((b) => b.getAttribute('title') === t('screens'))?.click();
  const hint = appEl.querySelector('.panel').textContent.includes(t('screens_find_hint'));
  byText(t('screens_find'))?.click();
  const asked = popup.document.body.textContent.includes(t('screen_not_found'));
  const use = popup.document.body.querySelectorAll('button').find((b) => b.textContent === t('screen_use_this'));
  use?.click();                                          // still here: said so
  const same = popup.document.body.textContent.includes(t('screen_same'));
  popup.screen = right;                                  // taken to the other screen
  use?.click();
  await settle();
  wk.onmessage({ data: { t: 'end' } });                  // the one-screen stream ends
  await settle();
  clock += 500;
  runTimers(500);
  await settle();
  const later = workers.at(-1) !== wk ? monsOf(workers.at(-1)) : null;
  const both = '[[0,0,1920,1080,1],[1920,0,1280,1024,0]]';
  check('several screens without the API: found by a window opened past the edge, both used; else that window taken there',
    first.note && first.box && first.probe.includes('left=1920,top=0') && JSON.stringify(first.mons) === both && first.shown
    && JSON.stringify(first.size) === '[1280,1024]' && hint && asked && same && JSON.stringify(later) === both,
    JSON.stringify({ first, hint, asked, same, later }));
  globalThis.matchMedia = media;
  window.open = open0;
}

// ------------------------------------------------------------ ZIP of remote files

{
  const { zipBlob, crc32 } = await import('../public/assets/zip.js');
  const file = (name, text, size) => { const p = new TextEncoder().encode(text); return { name, parts: [p], size: size ?? p.length, crc: crc32(p) }; };
  // the remote file was 10 bytes when copied but only 4 arrived (it shrank: the gateway goes on with the next file)
  const z = new Uint8Array(await zipBlob([file('a.txt', 'hola', 10), file('b.txt', 'adios')]).arrayBuffer());
  const dv = new DataView(z.buffer), eocd = z.length - 22, cd = dv.getUint32(eocd + 16, true);
  const second = cd + 46 + dv.getUint16(cd + 28, true);  // central record of b.txt
  const local = dv.getUint32(second + 42, true);
  const ok = local + 30 <= z.length && dv.getUint32(local, true) === 0x04034b50;
  const n = ok ? dv.getUint16(local + 26, true) : 0;
  const data = ok ? new TextDecoder().decode(z.subarray(local + 30 + n, local + 30 + n + dv.getUint32(local + 18, true))) : '';
  check('zip: entries point at their data when a file arrives shorter than announced', data === 'adios', data || 'no local header there');
}

// ------------------------------------------------------------ the gateway busy (sessions of all users at their limit)

{
  await go('#/s/c1');
  submit(appEl.querySelector('form'));                           // the connect dialog (the password is saved by now)
  await settle();
  workers.at(-1).onmessage({ data: { t: 'end', end: 'busy' } });   // what the worker reports for the gateway's 503 {"error":"busy"}
  await settle();
  check('session: the gateway busy says so', appEl.querySelector('.overlay').textContent.includes(t('r_busy')), appEl.querySelector('.overlay').textContent);
}

// ------------------------------------------------------------ profile: own remote sessions and trusted browsers

{
  const b1 = { id: 'b1', name: 'Chrome 140 · Windows', ip: '203.0.113.5', added: '2026-11-01T10:00:00+01:00', used: '2026-11-18T09:00:00+01:00', current: true };
  const b2 = { id: 'b2', name: 'Firefox 132 · Linux', ip: '198.51.100.9', added: '2026-10-01T10:00:00+01:00', used: '2026-10-02T09:00:00+01:00', current: false };
  answers['profile.activity'] = () => ({ sessions: [{ sid: 's1', conn: 'Oficina', host: 'pc.test', ip: '203.0.113.5', started: '2026-11-18T09:00:00+01:00' }], browsers: [b1, b2] });
  answers['profile.browser.forget'] = (b) => ({ browsers: b.others ? [b1] : [b1, b2].filter((x) => x.id !== b.id) });
  await go('#/profile');
  const text = appEl.textContent;
  check('profile: own remote sessions and trusted browsers, this one marked', text.includes('Oficina') && text.includes('Firefox 132 · Linux') && text.includes(t('this_browser')));
  byText(t('terminate')).click();
  await settle();
  byText(t('forget_others')).click();
  await settle();
  check('profile: a session ended and the other browsers forgotten', calls.some((c) => c.action === 'profile.session.kill' && c.sid === 's1')
    && calls.some((c) => c.action === 'profile.browser.forget' && c.others === true) && !appEl.textContent.includes('Firefox 132 · Linux'));
}

// ------------------------------------------------------------ administration: warning while all targets are allowed, sessions of all users, 2FA for everyone

{
  const settings = { clients: { mode: 'all', list: [] }, targets: { mode: 'all', list: [] }, login_max_attempts: 5, login_lock_minutes: 15, login_global_max: 50,
    session_idle_minutes: 30, rdp_idle_minutes: 0, max_sessions_per_user: 3, max_sessions_total: 20, reconnect_attempts: 10, audit_retention_days: 90,
    files_quota_mb: 500, totp_required: true, trace_all: false };
  let sent = null;
  answers['admin.settings'] = (b) => (b.settings ? (sent = b.settings, { error: 'totp_required_self' }) : { settings });
  STATE.user.targets_all = true;
  // users: administrator rights are a box (several administrators), unticked for a new user and ticked for an administrator
  const saves = [];
  answers['admin.users'] = () => ({ users: [{ id: 'u9', username: 'jefa', role: 'admin', enabled: true, totp: false, passkeys: [], max_sessions: 0, last_login: null }] });
  answers['admin.user.save'] = (b) => { saves.push([b.role, b.username ?? b.id]); return { user: {} }; };
  await go('#/admin/users');
  check('admin: warned while all remote computers are allowed', appEl.textContent.includes(t('targets_all_warn')));
  byText(t('new_user')).click();
  await settle();
  const newForm = document.body.querySelector('.modal')?.querySelector('form');
  const box0 = newForm?.querySelectorAll('input').find((i) => i.getAttribute('name') === 'admin');
  const role0 = !!newForm?.querySelector('select[name=role]');
  newForm.querySelector('input[name=username]').value = 'nuevo';
  newForm.querySelector('input[name=password]').value = 'Temporal-1234';
  box0.checked = true;
  byText(t('save'), document.body.querySelector('.modal')).click();
  await settle();
  appEl.querySelectorAll('button').find((b) => b.getAttribute('title') === t('edit'))?.click();
  await settle();
  const editForm = document.body.querySelector('.modal')?.querySelector('form');
  const box1 = editForm?.querySelectorAll('input').find((i) => i.getAttribute('name') === 'admin');
  const ticked1 = !!box1?.checked;
  box1.checked = false;
  byText(t('save'), document.body.querySelector('.modal')).click();
  await settle();
  check('admin: administrator rights as a box (unticked for a new user, ticked for an administrator), several possible',
    box0?.getAttribute('type') === 'checkbox' && !role0 && ticked1 && JSON.stringify(saves) === JSON.stringify([['admin', 'nuevo'], ['user', 'u9']]),
    JSON.stringify({ type: box0?.getAttribute('type'), role0, ticked1, saves }));
  await go('#/admin/settings');
  const total = appEl.querySelector('input[name=max_sessions_total]');
  check('admin: RDP sessions of all users, with its help', total?.value === 20 && appEl.textContent.includes(t('s_max_sessions_total_help')));
  byText(t('save')).click();
  await settle();
  check('admin: sent, and 2FA for everyone refused while the administrator has none, said so', sent?.max_sessions_total === 20
    && appEl.querySelectorAll('.error').some((e) => e.textContent === t('e_totp_required_self')), JSON.stringify(sent));
}

// ------------------------------------------------------------ administration: saving RDP passwords can be forbidden

{
  // allowed by default; turned off after a warning (the saved ones are erased): the connection form has no password field
  // and the connect dialog no "save password" box
  const settings = { clients: { mode: 'all', list: [] }, targets: { mode: 'all', list: [] }, login_max_attempts: 5, login_lock_minutes: 15, login_global_max: 50,
    session_idle_minutes: 30, rdp_idle_minutes: 0, max_sessions_per_user: 3, max_sessions_total: 20, reconnect_attempts: 10, audit_retention_days: 90,
    files_quota_mb: 500, totp_required: false, trace_all: false, save_passwords: true };
  let sent = null;
  answers['admin.settings'] = (b) => {
    if (!b.settings) return { settings };
    sent = b.settings;
    STATE.user.save_passwords = b.settings.save_passwords;
    return { settings: b.settings };
  };
  const hadPass = CONN.has_pass;
  await go('#/admin/settings');
  const box = appEl.querySelector('input[name=save_passwords]');
  const onByDefault = box?.checked === true && appEl.textContent.includes(t('s_save_passwords_help'));
  if (box) box.checked = false;
  byText(t('save')).click();
  await settle();
  const warned = !!document.body.querySelector('.modal')?.textContent.includes(t('s_save_passwords_off')) && sent === null;
  byText(t('yes'), document.body)?.click();
  await settle();
  Object.assign(CONN, { has_pass: false, ask: true });
  await go('#/conns');
  appEl.querySelectorAll('button').find((b) => b.getAttribute('title') === t('edit'))?.click();
  await settle();
  const modalEl = document.body.querySelector('.modal');
  const formNoField = !!modalEl && !modalEl.querySelectorAll('input').some((i) => i.getAttribute('name') === 'password') && modalEl.textContent.includes(t('pw_not_allowed'));
  byText(t('cancel'), document.body)?.click();
  await settle();
  await go('#/s/c1');
  const dialog = appEl.querySelector('.overlay').querySelector('form');
  const noSaveBox = !!dialog?.querySelector('[name=password]') && !dialog.querySelector('[name=save_pw]');
  check('admin: saving RDP passwords allowed by default, turned off after a warning; then no password field and no "save" box',
    onByDefault && warned && sent?.save_passwords === false && formNoField && noSaveBox, JSON.stringify({ onByDefault, warned, sent: sent?.save_passwords, formNoField, noSaveBox }));
  STATE.user.save_passwords = true;
  CONN.has_pass = hadPass;
  await go('#/conns');
}

// ------------------------------------------------------------ a step pending: the state shows the screen that asks for it

{
  answers['conns.list'] = () => ({ error: 'pending' });          // the administrator has just required a second factor
  answers.state = () => ({ ...STATE, user: { ...STATE.user, totp_required: true, totp: false, passkeys: [] } });
  await go('#/conns');
  check('pending: a user without the second factor now required is shown what to do', appEl.textContent.includes(t('twofa_needed')));
}

// ------------------------------------------------------------ sign-in in the browser's language; a TOTP code already used

{
  Object.defineProperty(globalThis, 'navigator', { value: { languages: ['en-US', 'en'], language: 'en-US' }, configurable: true });
  answers.logout = () => ({ setup: false, csrf: 'x', second: { totp: true, passkey: false }, user: null });
  byText(t('logout')).click();
  await settle();
  check('sign-in: an English browser gets the interface in English before any user is loaded', html.lang === 'en' && appEl.textContent.includes('Two-step verification'), html.lang);
  answers.login = () => ({ error: 'code_used' });
  const form = appEl.querySelector('form');
  form.code = form.querySelector('[name=code]');                // (the document double has no named form controls)
  form.code.value = '123456';
  submit(form);
  await settle();
  check('sign-in: a code already used says to wait for the next one, the field is emptied', form.code.value === ''
    && appEl.querySelectorAll('.error').some((e) => e.textContent === t('e_code_used')), appEl.querySelector('.error')?.textContent);

  // signed in: the previous sign-in and the failed attempts since then, until closed
  answers['conns.list'] = () => ({ conns: [CONN], keyboards: [0x040A] });
  answers.state = () => STATE;
  answers.login = () => ({ ...STATE, previous: { login: '2026-11-17T08:00:00+01:00', ip: '198.51.100.7', failures: 3,
    failed: { t: '2026-11-18T03:12:00.000+01:00', ip: '203.0.113.66' } } });
  form.code.value = '654321';
  submit(form);
  await settle();
  const note = appEl.querySelector('[role=status]');
  check('sign-in: the last sign-in and the failures since then are shown', !!note && note.textContent.includes('198.51.100.7') && note.textContent.includes('203.0.113.66')
    && note.textContent.includes(t('prev_failures', 3, '', '').slice(0, 20)), note?.textContent);
  note?.querySelector('button').click();
  await go('#/conns');
  check('sign-in: the notice, once closed, stays closed', !appEl.querySelector('[role=status]'));
}

// ------------------------------------------------------------ connections: .rdp files

{
  // one .rdp file opens the editor filled in (with what MuDesk does not take said there) to check and save; several are
  // saved at once; a connection is exported as an .rdp file (UTF-16 with BOM, as Remote Desktop Connection saves it)
  const { encodeRdp, decodeRdp } = await import('../public/assets/rdpfile.js');
  const savedConns = [];
  answers['conns.save'] = (b) => { savedConns.push(b.conn); return { conn: { ...b.conn, id: 'n' + savedConns.length } }; };
  await go('#/conns');
  const picker = () => appEl.querySelectorAll('input').find((i) => i.getAttribute('accept') === '.rdp');
  const choose = async (files) => { const p = picker(); p.files = files; p.dispatchEvent(new Ev('change')); for (let i = 0; i < 4; i++) await settle(); };
  await choose([new File([encodeRdp('full address:s:oficina:3390\r\nusername:s:EMP\\eva\r\nremoteapplicationmode:i:1\r\nremoteapplicationname:s:Excel\r\n')], 'Oficina.rdp')]);
  const modalEl = document.body.querySelector('.modal');
  const filled = ['name', 'host', 'port', 'user', 'domain'].map((n) => String(modalEl?.querySelector(`[name=${n}]`)?.value));
  const noted = modalEl?.querySelector('.notice')?.textContent || '';
  const railFolded = modalEl?.querySelector('.rail-fields');   // no program: the RemoteApp fields folded (they can be unfolded)
  const folded = [!!railFolded, railFolded?.getAttribute('open'), railFolded?.querySelector('summary')?.textContent, !!railFolded?.querySelector('[name=app]')];
  byText(t('save'), document.body)?.click();
  for (let i = 0; i < 3; i++) await settle();
  await choose([new File([encodeRdp('full address:s:uno\r\n')], 'Uno.rdp'), new File(['basura'], 'Mal.rdp'), new File([new TextEncoder().encode('full address:s:dos\n')], 'Dos.rdp')]);
  const toasts = document.body.querySelector('.toasts')?.textContent || '';
  const saved = [], click0 = El.prototype.click;
  El.prototype.click = function () { if (this.tagName === 'A') saved.push([this.getAttribute('download') ?? this.download, this.href]); return click0.call(this); };
  appEl.querySelector('.conn')?.querySelectorAll('button').find((b) => b.getAttribute('title') === t('export_rdp'))?.click();
  El.prototype.click = click0;
  const file = saved[0] && blobs.get(saved[0][1]);
  const bytes = file ? new Uint8Array(await file.arrayBuffer()) : new Uint8Array(0);
  check('connections: one .rdp opens the editor filled in (RemoteApp said), several are saved at once (a bad one said), exported as .rdp',
    JSON.stringify(filled) === '["Oficina","oficina","3390","eva","EMP"]' && noted.includes(t('rdp_remoteapp', 'Excel'))
    && JSON.stringify(savedConns.map((c) => [c.host, c.port, c.user, c.domain])) === '[["oficina",3390,"eva","EMP"],["uno",3389,"",""],["dos",3389,"",""]]'
    && toasts.includes('Mal.rdp: ' + t('e_rdp_no_address')) && toasts.includes(t('rdp_imported', 2))
    && saved[0]?.[0] === 'PC.rdp' && bytes[0] === 0xff && bytes[1] === 0xfe && decodeRdp(bytes).startsWith('full address:s:pc.test\r\nusername:s:ana\r\n'),
    JSON.stringify({ filled, noted, savedConns: savedConns.map((c) => c.host), saved, text: decodeRdp(bytes).slice(0, 60) }));
  // a RemoteApp: its program is shown and sent by its name, without the "||" (the gateway adds them)
  await choose([new File([encodeRdp('full address:s:rds\r\nremoteapplicationmode:i:1\r\nremoteapplicationprogram:s:||Excel\r\n')], 'Rds.rdp')]);
  const appField = document.body.querySelector('.modal')?.querySelector('[name=app]')?.value;
  const unfolded = document.body.querySelector('.modal')?.querySelector('.rail-fields')?.getAttribute('open');   // a program: unfolded
  byText(t('save'), document.body)?.click();
  for (let i = 0; i < 3; i++) await settle();
  check('connections: a RemoteApp program shown and saved by its name, without "||"', appField === 'Excel' && savedConns.at(-1)?.app === 'Excel',
    JSON.stringify({ appField, app: savedConns.at(-1)?.app }));
  check('connections: the editor\'s RemoteApp fields folded for a desktop connection, unfolded for one with a program',
    JSON.stringify(folded) === JSON.stringify([true, null, t('remote_app_section'), true]) && unfolded === '', JSON.stringify({ folded, unfolded }));
  delete answers['conns.save'];
}

// ------------------------------------------------------------ connections of the administrator

{
  // the user sees it marked, without delete, and its editor only takes their own password; the administration lists the
  // administrator's connections with who sees them and each user's own; a new one for some users or for all; a user's own
  // changed by the administrator (its password only forgotten)
  const MANAGED = { ...CONN, id: 'm1', name: 'Común', host: 'rds', managed: true, has_pass: false };
  const list0 = answers['conns.list'], sent = [];
  answers['conns.list'] = () => ({ conns: [CONN, MANAGED], keyboards: [0x040A] });
  answers['conns.save'] = (b) => { sent.push(['conns.save', b.conn]); return { conn: b.conn }; };
  await go('#/conns');
  const card = appEl.querySelectorAll('.conn').find((x) => x.textContent.includes('Común'));
  const marked = card?.querySelector('.badge')?.textContent === t('managed_badge');
  const titles = card?.querySelectorAll('button').map((b) => b.getAttribute('title'));
  card?.querySelectorAll('button').find((b) => b.getAttribute('title') === t('managed_edit'))?.click();
  await settle();
  const m = document.body.querySelector('.modal');
  const disabled = ['name', 'host', 'port', 'audio'].map((n) => !!m?.querySelector(`[name=${n}]`)?.disabled);
  const pwField = m?.querySelector('[name=password]');
  if (pwField) pwField.value = 'mi-clave';
  byText(t('save'), document.body)?.click();
  for (let i = 0; i < 3; i++) await settle();
  answers['conns.list'] = list0;
  delete answers['conns.save'];

  const users = [{ id: 'u1', username: 'ana', conns: [{ ...CONN, id: 'c9', name: 'De Ana', has_pass: true }] }, { id: 'u2', username: 'luis', conns: [] }];
  answers['admin.conns'] = () => ({ managed: [{ ...MANAGED, users: ['u2'] }, { ...MANAGED, id: 'm2', name: 'Todos', users: ['*'] }], users, keyboards: [0x040A] });
  answers['admin.conn.save'] = (b) => { sent.push(['admin.conn.save', b.users, b.conn.host]); return { conn: b.conn }; };
  answers['admin.user.conn.save'] = (b) => { sent.push(['admin.user.conn.save', b.uid, b.conn.name, b.forget]); return { conn: b.conn }; };
  await go('#/admin/conns');
  const rows = appEl.querySelectorAll('tr').map((r) => r.querySelectorAll('td').map((d) => d.textContent).slice(0, 3).join('|')).filter(Boolean);
  byText(t('new_conn'))?.click();
  await settle();
  let f = document.body.querySelector('.modal');
  const noPw = !f?.querySelector('[name=password]') && (f?.textContent || '').includes(t('admin_pw_note'));
  f.querySelector('[name=host]').value = 'nuevo';
  f.querySelector('[name=all_users]').checked = false;
  f.querySelector('[name=u_u1]').checked = true;
  byText(t('save'), document.body)?.click();
  for (let i = 0; i < 3; i++) await settle();
  const ownRow = appEl.querySelectorAll('tr').find((r) => r.textContent.includes('De Ana'));
  ownRow?.querySelectorAll('button').find((b) => b.getAttribute('title') === t('edit'))?.click();
  await settle();
  f = document.body.querySelector('.modal');
  const ownTitle = f?.querySelector('h2,h3')?.textContent || f?.textContent.slice(0, 40);
  f.querySelector('[name=forget]').checked = true;
  byText(t('save'), document.body)?.click();
  for (let i = 0; i < 3; i++) await settle();
  check('connections of the administrator: marked for the user, no delete, only their password; the administration lists who sees each one and every user\'s own, creates one for chosen users, changes a user\'s own (its password only forgotten)',
    marked && !titles?.includes(t('delete')) && JSON.stringify(disabled) === '[true,true,true,true]'
    && JSON.stringify(rows) === JSON.stringify(['Común|ana @ rds|luis', 'Todos|ana @ rds|' + t('all_users'), 'ana|De Ana|ana @ pc.test']) && noPw
    && JSON.stringify(sent) === JSON.stringify([['conns.save', { id: 'm1', password: 'mi-clave' }], ['admin.conn.save', ['u1'], 'nuevo'], ['admin.user.conn.save', 'u1', 'De Ana', true]])
    && ownTitle.includes('ana'), JSON.stringify({ marked, titles, disabled, rows, noPw, sent, ownTitle }));
  ['admin.conns', 'admin.conn.save', 'admin.user.conn.save'].forEach((k) => delete answers[k]);
}

// ------------------------------------------------------------ shared folder: a file explorer

{
  // a folder in memory behind files.php: path -> { dir } or { size }
  const FS = { Docs: { dir: true }, 'Docs/Sub': { dir: true }, 'Docs/x.txt': { size: 1 }, Fotos: { dir: true }, 'a.txt': { size: 3 }, 'b.pdf': { size: 2048 }, 'c.png': { size: 10 } };
  const up = (p) => p.slice(0, Math.max(0, p.lastIndexOf('/')));
  const under = (p) => Object.keys(FS).filter((k) => k === p || k.startsWith(p + '/'));
  filesAnswer = async (q, body) => {
    const a = q.get('a'), p = q.get('p');
    const add = (d) => (p ? p + '/' : '') + d;
    if (a === 'list') return FS[p]?.dir || !p ? { items: Object.keys(FS).filter((k) => up(k) === p).map((k) => ({ name: k.slice(k.lastIndexOf('/') + 1), dir: !!FS[k].dir, size: FS[k].size || 0, mtime: 1790000000 })), usage: 5000, quota: 1e6 } : { error: 'not_found' };
    if (a === 'tree') return { dirs: JSON.parse(body).filter((p) => !p || FS[p]?.dir).map((p) => [p, Object.keys(FS).filter((k) => FS[k].dir && up(k) === p).map((k) => k.slice(k.lastIndexOf('/') + 1)).sort(), false]) };
    if (a === 'move') { const to = q.get('to'); if (FS[to]) return { error: 'exists' }; for (const k of under(p)) { FS[to + k.slice(p.length)] = FS[k]; delete FS[k]; } return { ok: true }; }
    if (a === 'mkdir') { FS[add(q.get('n'))] = { dir: true }; return { ok: true }; }
    if (a === 'mkdirs') { for (const d of JSON.parse(body)) FS[add(d)] = { dir: true }; return { ok: true }; }
    if (a === 'delete') { under(p).forEach((k) => delete FS[k]); return { ok: true }; }
    if (a === 'upload') { FS[p] = { size: Number(q.get('o')) + body.size }; return { ok: true }; }
    if (a === 'zip') return { size: 123, id: 'z1', names: JSON.parse(body) };
    return { error: 'unknown_action' };
  };
  const names = () => appEl.querySelectorAll('.fx-item').map((x) => x.getAttribute('data-name'));
  const item = (n) => appEl.querySelectorAll('.fx-item').find((x) => x.getAttribute('data-name') === n);
  const node = (p) => appEl.querySelectorAll('.fx-node').find((x) => x.getAttribute('data-path') === p);
  const list = () => appEl.querySelector('.fx-items');
  const key = (k, o = {}) => list().dispatchEvent(new Ev('keydown', { key: k, ...o }));
  const transfer = (o = {}) => ({ types: [], data: {}, setData(k, v) { this.data[k] = v; if (!this.types.includes(k)) this.types.push(k); }, getData(k) { return this.data[k] || ''; }, setDragImage() {}, ...o });
  const status = () => appEl.querySelector('.fx-status').textContent;
  await go('#/files/');

  // the tree (root open, folders closed), the list (folders first), selection by click, Ctrl and Shift, the status bar
  const shown = [names(), appEl.querySelectorAll('.fx-node').map((x) => x.getAttribute('data-path')), node('Docs')?.getAttribute('aria-expanded')];
  const firstTree = fileCalls.filter((c) => c.a === 'tree').map((c) => c.body);
  node('Docs').querySelector('.twisty').click();          // opened: its folders read then
  await settle();
  const opened = [fileCalls.filter((c) => c.a === 'tree').map((c) => c.body).slice(firstTree.length), appEl.querySelectorAll('.fx-node').map((x) => x.getAttribute('data-path'))];
  node('Docs').querySelector('.twisty').click();
  item('a.txt').dispatchEvent(new Ev('click'));
  item('c.png').dispatchEvent(new Ev('click', { ctrlKey: true }));
  const two = status();
  item('Fotos').dispatchEvent(new Ev('click', { shiftKey: true }));
  const range = appEl.querySelectorAll('.fx-item.sel').map((x) => x.getAttribute('data-name'));
  check('file explorer: a folder tree beside the list (folders first; a folder read when opened), selected with click, Ctrl and Shift, counted in the status bar',
    JSON.stringify(shown) === '[["Docs","Fotos","a.txt","b.pdf","c.png"],["","Docs","Fotos"],"false"]' && two === '5 elementos · 2 seleccionados · 13 B'
    && JSON.stringify(firstTree) === '["[\\"\\"]"]' && JSON.stringify(opened) === '[["[\\"Docs\\"]"],["","Docs","Docs/Sub","Fotos"]]'
    && JSON.stringify(range) === '["Fotos","a.txt","b.pdf","c.png"]', JSON.stringify({ shown, two, range, firstTree, opened }));

  // dragged onto a folder: moved; never onto itself or into what it holds; dropped on the computer's desktop: downloaded (a ZIP for several)
  item('a.txt').dispatchEvent(new Ev('click'));
  item('c.png').dispatchEvent(new Ev('click', { ctrlKey: true }));
  const dt = transfer();
  item('a.txt').dispatchEvent(new Ev('dragstart', { dataTransfer: dt }));
  const overFolder = new Ev('dragover', { dataTransfer: dt }), overFile = new Ev('dragover', { dataTransfer: dt });
  item('Docs').dispatchEvent(overFolder);
  const hint = status();
  item('b.pdf').dispatchEvent(overFile);
  node('Fotos').dispatchEvent(new Ev('drop', { dataTransfer: dt }));
  await settle();
  const dt2 = transfer();
  item('Docs').dispatchEvent(new Ev('click'));
  item('Docs').dispatchEvent(new Ev('dragstart', { dataTransfer: dt2 }));
  const intoItself = new Ev('dragover', { dataTransfer: dt2 });
  node('Docs').dispatchEvent(intoItself);
  item('Docs').dispatchEvent(new Ev('dragend', { dataTransfer: dt2 }));
  check('file explorer: items dragged onto a folder of the list or the tree are moved there (not onto their own folder or into themselves); on the desktop they download',
    overFolder.defaultPrevented && !overFile.defaultPrevented && !intoItself.defaultPrevented && hint === 'Mover 2 elementos a «Docs»'
    && !!FS['Fotos/a.txt'] && !!FS['Fotos/c.png'] && JSON.stringify(names()) === '["Docs","Fotos","b.pdf"]'
    && dt.data['DownloadURL'] === 'application/zip:MuDesk.zip:https://gateway.test/files.php?a=zip&p=&n[]=a.txt&n[]=c.png'
    && dt2.data['DownloadURL'].startsWith('application/zip:Docs.zip:'),
    JSON.stringify({ hint, prevented: [overFolder.defaultPrevented, overFile.defaultPrevented, intoItself.defaultPrevented], names: names(), url: dt.data['DownloadURL'] }));

  // files and folders dragged from the computer: folders walked (empty ones too) and created at once, then each file uploaded;
  // one already there is asked about first (skipped here)
  const fileEntry = (name, text) => ({ isFile: true, name, file: (ok) => ok(new File([text], name)) });
  const dirEntry = (name, kids) => ({ isDirectory: true, name, createReader: () => { let read = false; return { readEntries: (ok) => { ok(read ? [] : kids); read = true; } }; } });
  fileCalls.length = 0;
  const fromPc = transfer({ types: ['Files'], files: [], items: [
    { kind: 'file', webkitGetAsEntry: () => dirEntry('Proyecto', [fileEntry('leeme.txt', 'hola'), dirEntry('vacía', [])]) },
    { kind: 'file', webkitGetAsEntry: () => fileEntry('nota.txt', 'x') }] });
  const overPc = new Ev('dragover', { dataTransfer: fromPc });
  list().dispatchEvent(overPc);
  const overlay = appEl.querySelector('.fx-main').getAttribute('data-drop');
  list().dispatchEvent(new Ev('drop', { dataTransfer: fromPc }));
  for (let i = 0; i < 5; i++) await settle();
  const sent = fileCalls.filter((c) => c.a !== 'list' && c.a !== 'tree').map((c) => c.a === 'mkdirs' ? 'mkdirs ' + c.body : `${c.a} ${c.p} ${c.q.get('o')}`);
  const again = transfer({ types: ['Files'], items: [{ kind: 'file' }], files: [new File(['y'], 'b.pdf'), new File(['z'], 'nuevo.txt')] });
  list().dispatchEvent(new Ev('drop', { dataTransfer: again }));
  await settle();
  const asked = document.body.querySelector('.modal')?.textContent || '';
  document.body.querySelectorAll('button').find((b) => b.textContent === t('fx_skip'))?.click();
  for (let i = 0; i < 3; i++) await settle();
  check('file explorer: files and folders dragged from the computer are uploaded (folders created first, empty ones too); a name already there is asked about',
    overPc.defaultPrevented && overlay === 'Suelta para subir a «Inicio»'
    && JSON.stringify(sent) === '["mkdirs [\\"Proyecto\\",\\"Proyecto/vacía\\"]","upload Proyecto/leeme.txt 0","upload nota.txt 0"]'
    && FS['Proyecto/leeme.txt']?.size === 4 && !!FS['Proyecto/vacía']?.dir && asked.includes('«b.pdf» ya está en esta carpeta.')
    && FS['b.pdf'].size === 2048 && FS['nuevo.txt']?.size === 1, JSON.stringify({ overlay, sent, asked, b: FS['b.pdf'], n: FS['nuevo.txt'] }));

  // PHP could not write to its temporary folder and dropped the body (the gateway says tmp_dir): the reason is shown and the
  // queue stops, every other file would be lost the same way (it said only that some files had failed)
  const answer = filesAnswer;
  filesAnswer = async (q, body) => (q.get('a') === 'upload' ? { error: 'tmp_dir' } : answer(q, body));
  fileCalls.length = 0;
  document.body.querySelector('.toasts')?.replaceChildren();
  list().dispatchEvent(new Ev('drop', { dataTransfer: transfer({ types: ['Files'], items: [{ kind: 'file' }], files: [new File(['1'], 'uno.txt'), new File(['2'], 'dos.txt')] }) }));
  for (let i = 0; i < 4; i++) await settle();
  filesAnswer = answer;
  const tmpToasts = document.body.querySelector('.toasts')?.textContent || '';
  const tmpSent = fileCalls.filter((c) => c.a === 'upload').map((c) => c.p);
  check('file explorer: an upload PHP dropped (no temporary folder) says why and stops the queue',
    tmpToasts.includes(t('e_tmp_dir')) && !tmpToasts.includes(t('fx_upload_failed', 1, 'uno.txt')) && JSON.stringify(tmpSent) === '["uno.txt"]',
    JSON.stringify({ tmpToasts, tmpSent }));

  // renamed in place with F2 (the name without its extension selected), a new folder named at once, keys, a context menu,
  // several items deleted after asking, a folder downloaded as a ZIP (its size asked first: an error is not saved as a file)
  item('b.pdf').dispatchEvent(new Ev('click'));
  key('F2');
  const input = list().querySelector('.fx-rename');
  input.value = 'informe.pdf';
  input.dispatchEvent(new Ev('keydown', { key: 'Enter' }));
  await settle();
  const renamed = !!FS['informe.pdf'] && !FS['b.pdf'] && item('informe.pdf')?.className.includes('sel');
  appEl.querySelectorAll('button').find((b) => b.getAttribute('title') === t('new_folder')).click();
  for (let i = 0; i < 3; i++) await settle();
  const naming = list().querySelector('.fx-rename')?.value;
  list().querySelector('.fx-rename')?.dispatchEvent(new Ev('keydown', { key: 'Escape' }));
  key('Home');
  const first = appEl.querySelectorAll('.fx-item.sel').map((x) => x.getAttribute('data-name'));
  key('n');
  const typed = appEl.querySelectorAll('.fx-item.sel').map((x) => x.getAttribute('data-name'));
  item('Docs').dispatchEvent(new Ev('contextmenu', { clientX: 10, clientY: 10 }));
  const menuItems = document.body.querySelector('.fx-menu')?.querySelectorAll('button').map((b) => b.textContent);
  document.body.querySelector('.fx-menu')?.querySelector('button')?.dispatchEvent(new Ev('keydown', { key: 'Escape' }));
  // a menu closed with Escape left its "click away" listener behind, which closed the next menu at its first press (and
  // the action pressed was lost)
  const tmA = timers.length;
  item('Docs').dispatchEvent(new Ev('contextmenu', { clientX: 10, clientY: 10 }));
  document.body.querySelector('.fx-menu')?.querySelector('button')?.dispatchEvent(new Ev('keydown', { key: 'Escape' }));
  item('Docs').dispatchEvent(new Ev('contextmenu', { clientX: 10, clientY: 10 }));
  const menuB = document.body.querySelector('.fx-menu');
  timers.slice(tmA).filter((tm) => tm.ms === undefined).forEach((tm) => tm.f());   // the menus' "click away" listeners are added
  (winL.pointerdown || []).slice().forEach((f) => f({ target: menuB?.querySelector('button') }));
  check('menus: one closed with Escape leaves nothing that closes the next one at its first press',
    !!menuB && document.body.querySelector('.fx-menu') === menuB);
  menuB?.querySelector('button')?.dispatchEvent(new Ev('keydown', { key: 'Escape' }));
  const saved = [], click0 = El.prototype.click;
  El.prototype.click = function () { if (this.tagName === 'A') saved.push(this.getAttribute('href')); return click0.call(this); };
  appEl.querySelectorAll('button').find((b) => b.getAttribute('title') === t('download')).click();
  await settle();
  El.prototype.click = click0;
  const zipAsked = fileCalls.some((c) => c.a === 'zip' && c.q.get('check') === '1' && c.body === '["Docs"]' && !c.q.getAll('n[]').length);
  item('informe.pdf').dispatchEvent(new Ev('click', { ctrlKey: true }));
  key('Delete');
  await settle();
  const confirmText = document.body.querySelector('.modal')?.textContent || '';
  document.body.querySelectorAll('button').find((b) => b.textContent === t('yes'))?.click();
  for (let i = 0; i < 3; i++) await settle();
  check('file explorer: renamed in place (F2), a new folder named at once, Home and typing move the selection, a context menu, a folder as a ZIP, several deleted after asking',
    renamed && naming === 'Nueva carpeta' && !!FS['Nueva carpeta']?.dir && JSON.stringify(first) === '["Docs"]' && JSON.stringify(typed) === '["Nueva carpeta"]'
    && JSON.stringify(menuItems) === '["Abrir","Descargar en ZIP","Cambiar nombre","Eliminar"]' && zipAsked
    && JSON.stringify(saved) === '["files.php?a=zip&z=z1"]' && confirmText.includes('¿Eliminar 2 elementos?') && !FS.Docs && !FS['Docs/x.txt'] && !FS['informe.pdf'],
    JSON.stringify({ renamed, naming, first, typed, menuItems, zipAsked, saved, confirmText, left: Object.keys(FS) }));

  // opened by double click: the path, the parent button and the tree follow (the folder open in the tree)
  item('Fotos').dispatchEvent(new Ev('dblclick'));
  const hash = location.hash;
  await go(hash);
  check('file explorer: a folder opened by double click, shown in the path bar and open in the tree',
    hash === '#/files/Fotos' && appEl.querySelector('.fx-path').textContent === 'InicioFotos' && node('Fotos')?.className.includes('current')
    && node('Fotos')?.getAttribute('aria-expanded') === null && JSON.stringify(names()) === '["a.txt","c.png"]',
    JSON.stringify({ hash, path: appEl.querySelector('.fx-path')?.textContent, names: names() }));

  // the folders of the tree have their own actions: a context menu, F2 renames in place, Del deletes, a new folder inside
  // named at once, dragged onto another folder; the page follows the folder it is in when it is renamed or deleted
  const menuOf = (p) => {
    node(p).dispatchEvent(new Ev('contextmenu', { clientX: 5, clientY: 5 }));
    const m = document.body.querySelector('.fx-menu');
    return [m?.querySelectorAll('button').map((b) => b.textContent), node(p)?.className.includes('target'), m];
  };
  const pick = (m, text) => m?.querySelectorAll('button').find((b) => b.textContent === text)?.click();
  const [rootMenu] = menuOf('');
  pick(document.body.querySelector('.fx-menu'), t('fx_refresh'));
  const [folderMenu, marked, m1] = menuOf('Proyecto');
  m1?.querySelector('button')?.dispatchEvent(new Ev('keydown', { key: 'Escape' }));
  const unmarked = !node('Proyecto')?.className.includes('target');
  node('Proyecto').dispatchEvent(new Ev('keydown', { key: 'F2' }));
  const treeInput = node('Proyecto')?.querySelector('.fx-rename');
  treeInput.value = 'Proyecto final';
  treeInput.dispatchEvent(new Ev('keydown', { key: 'Enter' }));
  for (let i = 0; i < 3; i++) await settle();
  const renamedTree = [!!FS['Proyecto final/leeme.txt'] && !FS.Proyecto, !!node('Proyecto final'), location.hash];
  pick(menuOf('Proyecto final')[2], t('new_folder'));
  for (let i = 0; i < 3; i++) await settle();
  const inner = node('Proyecto final/Nueva carpeta')?.querySelector('.fx-rename');
  const innerName = inner?.value;
  inner.value = 'Planos';
  inner.dispatchEvent(new Ev('keydown', { key: 'Enter' }));
  for (let i = 0; i < 3; i++) await settle();
  const dtTree = transfer();
  node('Proyecto final/Planos').dispatchEvent(new Ev('dragstart', { dataTransfer: dtTree }));
  const ontoParent = new Ev('dragover', { dataTransfer: dtTree }), ontoRoot = new Ev('dragover', { dataTransfer: dtTree });
  node('Proyecto final').dispatchEvent(ontoParent);
  node('').dispatchEvent(ontoRoot);
  node('').dispatchEvent(new Ev('drop', { dataTransfer: dtTree }));
  for (let i = 0; i < 3; i++) await settle();
  const draggedTree = [!ontoParent.defaultPrevented, ontoRoot.defaultPrevented, !!FS.Planos?.dir && !FS['Proyecto final/Planos'],
    dtTree.data['DownloadURL'].startsWith('application/zip:Planos.zip:https://gateway.test/files.php?a=zip&p=Proyecto%20final&n[]=Planos')];
  node('Planos').dispatchEvent(new Ev('keydown', { key: 'Delete' }));
  await settle();
  const askedTree = document.body.querySelector('.modal')?.textContent || '';
  document.body.querySelectorAll('button').find((b) => b.textContent === t('yes'))?.click();
  for (let i = 0; i < 3; i++) await settle();
  // the folder we are in (Fotos): renamed from the tree, the page goes with it; deleted, the page goes to its parent
  pick(menuOf('Fotos')[2], t('fx_rename'));
  const cur0 = node('Fotos')?.querySelector('.fx-rename');
  cur0.value = 'Fotos2';
  cur0.dispatchEvent(new Ev('keydown', { key: 'Enter' }));
  for (let i = 0; i < 3; i++) await settle();
  const followed = [location.hash, !!FS['Fotos2/a.txt'] && !FS['Fotos/a.txt']];
  await go(followed[0]);
  node('Fotos2').dispatchEvent(new Ev('keydown', { key: 'Delete' }));
  await settle();
  document.body.querySelectorAll('button').find((b) => b.textContent === t('yes'))?.click();
  for (let i = 0; i < 3; i++) await settle();
  check('file explorer: the folders of the tree have a context menu, F2 renames in place, Del deletes after asking, a new folder inside is named there, dragged onto another folder; the page follows the folder it is in',
    JSON.stringify(rootMenu) === '["Abrir","Nueva carpeta","Actualizar"]' && JSON.stringify(folderMenu) === '["Abrir","Nueva carpeta","Descargar en ZIP","Cambiar nombre","Eliminar"]'
    && marked && unmarked && JSON.stringify(renamedTree) === '[true,true,"#/files/Fotos"]' && innerName === 'Nueva carpeta'
    && JSON.stringify(draggedTree) === '[true,true,true,true]' && askedTree.includes('¿Eliminar la carpeta «Planos» con todo su contenido?') && !FS.Planos
    && JSON.stringify(followed) === '["#/files/Fotos2",true]' && location.hash === '#/files/' && !FS.Fotos2 && !FS['Fotos2/a.txt'],
    JSON.stringify({ rootMenu, folderMenu, marked, unmarked, renamedTree, innerName, draggedTree, askedTree, followed, hash: location.hash, left: Object.keys(FS) }));

  // the keyboard stays where it was: in the list after opening a folder and after going up (the folder it came from selected),
  // back in the list when a context menu closes (it fell to the page each time, and the keys did nothing)
  Object.assign(FS, { Uno: { dir: true }, 'Uno/x.txt': { size: 1 }, 'mis fotos': { dir: true }, 'mi casa.txt': { size: 1 }, 'z.txt': { size: 1 } });
  await go('#/files/');
  list().focus();
  item('Uno').dispatchEvent(new Ev('click'));
  key('Enter');
  await go(location.hash);
  const inUno = [location.hash, document.activeElement === list()];
  key('Backspace');
  await go(location.hash);
  const backUp = [location.hash, document.activeElement === list(), appEl.querySelectorAll('.fx-item.sel').map((x) => x.getAttribute('data-name'))];
  item('z.txt').dispatchEvent(new Ev('contextmenu', { clientX: 10, clientY: 10 }));
  document.body.querySelector('.fx-menu')?.querySelector('button')?.dispatchEvent(new Ev('keydown', { key: 'Escape' }));
  const afterMenu = document.activeElement === list();
  // typing a name with a space ("mi c") jumps to it (the space selected the current item and the typing started again)
  clock += 1000;
  key('End');
  for (const k of ['m', 'i', ' ', 'c']) key(k);
  const typedSpace = appEl.querySelectorAll('.fx-item.sel').map((x) => x.getAttribute('data-name'));
  clock += 1000;
  // "New folder" clicked twice quickly makes one folder (both took the same name: the second failed as "exists")
  const mk0 = fileCalls.filter((c) => c.a === 'mkdir').length;
  const nf = appEl.querySelectorAll('button').find((b) => b.getAttribute('title') === t('new_folder'));
  nf.click();
  nf.click();
  for (let i = 0; i < 3; i++) await settle();
  const made = fileCalls.filter((c) => c.a === 'mkdir').length - mk0;
  list().querySelector('.fx-rename')?.dispatchEvent(new Ev('keydown', { key: 'Escape' }));
  await settle();
  check('file explorer: the keyboard stays in the list after opening a folder, going up (that folder selected) and closing a menu; a name typed with a space; one new folder for two quick clicks',
    JSON.stringify(inUno) === '["#/files/Uno",true]' && JSON.stringify(backUp) === '["#/files/",true,["Uno"]]' && afterMenu
    && JSON.stringify(typedSpace) === '["mi casa.txt"]' && made === 1, JSON.stringify({ inUno, backUp, afterMenu, typedSpace, made }));

  // an upload cancelled during its first request: what reached the gateway is removed (it stayed there, cut short)
  const fetch1 = globalThis.fetch;
  globalThis.fetch = (url, o = {}) => (String(url).includes('a=upload')
    ? (fileCalls.push({ a: 'upload', p: new URLSearchParams(String(url).split('?')[1]).get('p') }),
      new Promise((_, no) => o.signal?.addEventListener('abort', () => no(new DOMException('cancelled', 'AbortError')))))
    : fetch1(url, o));
  list().dispatchEvent(new Ev('drop', { dataTransfer: transfer({ types: ['Files'], items: [{ kind: 'file' }], files: [new File(['big'], 'grande.bin')] }) }));
  for (let i = 0; i < 3; i++) await settle();
  const upFrom = fileCalls.length;
  document.querySelector('.fx-up')?.querySelector('button')?.click();
  for (let i = 0; i < 3; i++) await settle();
  globalThis.fetch = fetch1;
  const cutRemoved = fileCalls.slice(upFrom).some((c) => c.a === 'delete' && c.p === 'grande.bin');

  // a drop from another window of the application is moved only with this browser's key (any web page can set the type); a
  // drag left over (its item drawn again before it ended: no dragend) is never taken for a new one
  const store = new Map();
  globalThis.localStorage = { getItem: (k) => store.get(k) ?? null, setItem: (k, v) => store.set(k, String(v)) };
  item('z.txt').dispatchEvent(new Ev('click'));
  item('z.txt').dispatchEvent(new Ev('dragstart', { dataTransfer: transfer() }));
  await go('#/files/');
  const DT = 'application/x-mudesk-files';
  const fromOther = (k) => transfer({ types: [DT], data: { [DT]: JSON.stringify({ from: '', names: ['mi casa.txt'], key: k }) } });
  item('Uno').dispatchEvent(new Ev('drop', { dataTransfer: fromOther('not-the-key') }));
  for (let i = 0; i < 3; i++) await settle();
  const refused = !FS['Uno/mi casa.txt'] && !FS['Uno/z.txt'] && !!FS['z.txt'] && !!FS['mi casa.txt'];
  item('Uno').dispatchEvent(new Ev('drop', { dataTransfer: fromOther(store.get('mudesk-drag-key')) }));
  for (let i = 0; i < 3; i++) await settle();
  const taken = !!FS['Uno/mi casa.txt'] && !FS['Uno/z.txt'] && !!FS['z.txt'];
  delete globalThis.localStorage;
  // a malformed address (#/files/%E0%A4%A) shows the top folder (decodeURIComponent threw: nothing was shown)
  let threw = '';
  try { await go('#/files/%E0%A4%A'); } catch (e) { threw = e.message; }
  const top = appEl.querySelector('.fx-path')?.textContent;
  check('file explorer: a cancelled upload removed from its first request; a drop from another window needs this browser\'s key, a drag left over is not taken; a malformed address shows the top folder',
    cutRemoved && refused && taken && threw === '' && top === 'Inicio', JSON.stringify({ cutRemoved, refused, taken, threw, top }));
}

// ------------------------------------------------------------ the web session ending

{
  // when the session should be over (idle time-out, 12 hours), the page asks the gateway how long is left (session.left,
  // not activity) and asks again later while another window keeps it open; closed, a window says why and OK shows the
  // sign-in screen. A request refused because the session closed does the same, without the error notice. Before, the
  // page only learnt it at its next request and showed the sign-in screen with no word.
  const signedIn = { ...STATE, session: { idle: 1800, max: 43200 } };
  answers['profile.lang'] = () => signedIn;              // a state answer: the page learns when its session ends
  await go('#/conns');
  const lang = appEl.querySelector('select');
  lang.value = 'es';
  lang.dispatchEvent(new Ev('change'));
  await settle();
  const due = (lo, hi) => timers.filter((x) => x.ms >= lo && x.ms <= hi).at(-1);
  const first = due(1795000, 1802000);                    // 30 minutes after the last request, and 2 s
  let left = 600;
  answers['session.left'] = () => (left ? { left } : { error: 'auth', reason: 'idle' });
  const before = calls.length;
  first?.f();
  await settle();
  const asked = calls.slice(before).map((c) => c.action);
  const again = due(601000, 602500);                      // still open (used from another window): asked when it would end now
  left = 0;
  again?.f();
  await settle();
  const said = document.body.querySelector('.modal')?.textContent || '';
  answers.state = () => ({ ...STATE, user: null });       // the gateway: signed out
  Object.defineProperty(El.prototype, 'lang', { get() { return this.querySelector('[name=lang]'); }, configurable: true });   // (form.lang)
  byText(t('accept'), document.body)?.click();
  await settle();
  const signIn = !!appEl.querySelector('input[type=password]') && !document.body.querySelector('.modal');
  // signed in again; then a request refused because the session has just closed (12 hours): the same window, no error notice
  answers.state = answers.login = () => signedIn;
  const form = appEl.querySelector('form');
  form.querySelector('[name=username]').value = 'ana';
  form.querySelector('[name=password]').value = 'pw';
  submit(form);
  await settle();
  answers['conns.list'] = () => ({ error: 'auth', reason: 'max_duration' });
  await go('#/profile');
  await go('#/conns');
  const said2 = document.body.querySelector('.modal')?.textContent || '';
  const notice = (document.body.querySelector('.toasts')?.textContent || '').includes(t('e_auth'));
  byText(t('accept'), document.body)?.click();
  await settle();
  check('web session: asked when it should end (not as activity), asked again while used elsewhere; closed, a window says why and OK shows the sign-in screen; a refused request the same, without the error notice',
    !!first && JSON.stringify(asked) === '["session.left"]' && !!again && said.includes(t('session_title')) && said.includes(t('session_idle', 30)) && signIn
    && said2.includes(t('session_max')) && !notice,
    JSON.stringify({ first: first?.ms, asked, again: again?.ms, said, signIn, said2, notice }));
  // signed out in another tab of this browser: this one ends too and says so (its remote session went on); a request of the
  // explorer refused because the session ended shows the same window, with no error notice beside it
  const relogin = async () => {
    const f = appEl.querySelector('input[type=password]')?.closest('form');
    if (!f) return;                                      // (the page signed in again by itself: the state answers so)
    f.querySelector('[name=username]').value = 'ana';
    f.querySelector('[name=password]').value = 'pw';
    submit(f);
    await settle();
  };
  answers['conns.list'] = () => ({ conns: [CONN], keyboards: [0x040A] });
  for (let i = 0; i < 3 && document.body.querySelector('.modal'); i++) { byText(t('accept'), document.body)?.click(); await settle(); }
  await relogin();
  await go('#/conns');
  const otherTab = new BroadcastChannel('mudesk');
  otherTab.postMessage('logout');
  for (let i = 0; i < 5; i++) { await new Promise((r) => setImmediate(r)); await settle(); }
  const said3 = document.body.querySelector('.modal')?.textContent || '';
  byText(t('accept'), document.body)?.click();
  await settle();
  otherTab.close();
  await relogin();
  filesAnswer = () => ({ error: 'auth', reason: 'idle' });
  await go('#/files/');
  for (let i = 0; i < 3; i++) await settle();
  const said4 = document.body.querySelector('.modal')?.textContent || '';
  const notice4 = (document.body.querySelector('.toasts')?.textContent || '').includes(t('e_auth'));
  byText(t('accept'), document.body)?.click();
  await settle();
  await relogin();
  check('web session: signed out in another tab, this one ends too and says so; an explorer request refused because the session ended, no error notice',
    said3.includes(t('session_logout')) && said4.includes(t('session_idle', 30)) && !notice4, JSON.stringify({ said3, said4, notice4 }));
  answers['conns.list'] = () => ({ conns: [CONN], keyboards: [0x040A] });
  answers.state = () => STATE;
  delete El.prototype.lang;
}

// ------------------------------------------------------------ an update on the server

{
  // a page loaded before an update (its files' fingerprint, from index.php, differs from the gateway's) offers to reload;
  // one that is current says nothing; the footer has the project's link only (no version or fingerprint)
  let reloads = 0;
  location.reload = () => reloads++;
  const state0 = answers.state;
  html.setAttribute('data-build', 'aaaaaaaaaa');
  answers.state = () => ({ ...STATE, version: '0.1.0', build: 'aaaaaaaaaa' });
  appEl.replaceChildren();
  await import('../public/assets/app.js?build-same');
  await settle();
  const same = !document.body.querySelector('.update-bar');
  const footer = appEl.querySelector('footer')?.textContent || '';
  answers.state = () => ({ ...STATE, version: '0.1.0', build: 'bbbbbbbbbb' });
  appEl.replaceChildren();
  await import('../public/assets/app.js?build-newer');
  await settle();
  const bar = document.body.querySelector('.update-bar');
  bar?.querySelectorAll('button').find((b) => b.textContent === t('update_reload'))?.click();
  check('update: a page older than the gateway offers to reload (one that is current, nothing); the footer only the link',
    same && !!bar && bar.textContent.includes(t('update_ready')) && reloads === 1 && footer === 'miguelenred.es/mudeskclient', JSON.stringify({ same, bar: !!bar, reloads, footer }));
  bar?.remove();
  answers.state = state0;
  delete html.attrs['data-build'];
}

// ------------------------------------------------------------ start: the server cannot answer

{
  // a fresh copy of app.js starts against a gateway that cannot start (data folder) and one that cannot be reached:
  // the reason in the browser's language and "try again", never a bare "Error"
  let reloads = 0;
  location.reload = () => reloads++;
  answers.state = () => ({ error: 'data_dir' });
  appEl.replaceChildren();
  await import('../public/assets/app.js?start-data-dir');
  await settle();
  const shown = appEl.textContent;
  byText(t('retry'))?.click();
  const fetch0 = globalThis.fetch;
  globalThis.fetch = async () => { throw new TypeError('Failed to fetch'); };
  appEl.replaceChildren();
  await import('../public/assets/app.js?start-offline');
  await settle();
  globalThis.fetch = fetch0;
  check('start: a server that cannot start or be reached is explained, with "try again"',
    shown.includes(t('e_data_dir')) && appEl.textContent.includes(t('r_network')) && !!byText(t('retry')) && reloads === 1, `${shown} | ${appEl.textContent}`);
}

// ------------------------------------------------------------ leaving a session by the address; saving twice

{
  // the session's view left by its address (Back, a link): the remote session stayed connected where nobody saw it
  const status = (wk, j) => wk.onmessage({ data: { t: 'frame', type: 3, d: new TextEncoder().encode(JSON.stringify(j)) } });
  Object.assign(CONN, { ask: true, app: '' });
  await go('#/s/c1');
  const form = appEl.querySelector('form');
  if (form?.querySelector('[name=password]')) form.querySelector('[name=password]').value = 'pw';
  if (form) submit(form);
  await settle();
  const wk = workers.at(-1);
  status(wk, { token: 'tl' });
  status(wk, { connected: true });
  await settle();
  const n0 = rdpSent.length;
  await go('#/conns');
  const quit = rdpSent.slice(n0).some((p) => p[0] === 5);
  check('session: leaving its view by the address ends the remote session', quit, JSON.stringify(rdpSent.slice(n0).map((p) => p[0])));

  // a double click on Save of a new connection saved it twice
  const saves0 = calls.filter((c) => c.action === 'conns.save').length;
  answers['conns.save'] = (b) => ({ conn: { ...b.conn, id: 'dbl' } });
  byText(t('new_conn'))?.click();
  await settle();
  const m = document.body.querySelectorAll('.modal').find((x) => x.querySelector('[name=host]'));
  m.querySelector('[name=host]').value = 'doble.test';
  const save = byText(t('save'), m);
  save.click();
  save.click();
  await settle();
  const saves = calls.filter((c) => c.action === 'conns.save').length - saves0;
  delete answers['conns.save'];
  check('connections: a double click on Save saves once', saves === 1, String(saves));
  // the microphone: one more box of the connection, unticked for a new one; ticked, it is saved
  answers['conns.save'] = (b) => ({ conn: { ...b.conn, id: 'micro' } });
  byText(t('new_conn'))?.click();
  await settle();
  const mm = document.body.querySelectorAll('.modal').find((x) => x.querySelector('[name=host]') && !x.querySelector('[name=host]').value);
  const box = mm?.querySelector('[name=mic]');
  const unticked = box && !box.checked;
  mm.querySelector('[name=host]').value = 'micro.test';
  box.checked = true;
  byText(t('save'), mm).click();
  await settle();
  const micSaved = calls.filter((c) => c.action === 'conns.save').at(-1)?.conn;
  delete answers['conns.save'];
  check('connections: the microphone box unticked for a new connection, saved when ticked', unticked && micSaved?.mic === true && micSaved?.host === 'micro.test',
    JSON.stringify({ unticked, mic: micSaved?.mic }));
  // the connect dialog: an info button beside the microphone (the browser's permission, the Windows policy step by step,
  // the registry command and the audio service's) and beside the screens (this browser's permission first: Window
  // Management in Chrome and Edge, pop-ups in Firefox and Safari)
  const infoOf = async (api) => {
    if (api) {
      screen.isExtended = true;
      window.getScreenDetails = async () => ({ screens: [], currentScreen: null });
    }
    Object.assign(CONN, { ask: true });
    await go('#/conns');
    await go('#/s/c1');
    const form = appEl.querySelector('.overlay').querySelector('form');
    focused.push(document.activeElement?.textContent || document.activeElement?.getAttribute?.('name'));
    const beside = (name) => form.querySelector(`[name=${name}]`)?.closest('.check-info')?.querySelector('button.info');
    const read = (b) => {
      b?.click();
      const m = document.body.querySelectorAll('.modal').at(-1);
      const out = { text: m?.textContent || '', cmds: m ? m.querySelectorAll('pre.cmd').map((p) => p.textContent) : [], title: b?.getAttribute('title') };
      m?.querySelectorAll('button').find((x) => x.textContent === t('close'))?.click();
      return out;
    };
    const mic = read(beside('mic')), scr = read(beside('multi'));
    delete screen.isExtended;
    delete window.getScreenDetails;
    return { mic, scr };
  };
  const focused = [];
  const noApi = await infoOf(false), withApi = await infoOf(true);
  const micOk = noApi.mic.title === t('more_info') && noApi.mic.text.includes(t('mic_info_browser')) && t('mic_info_gpo').every((s) => noApi.mic.text.includes(s))
    && JSON.stringify(noApi.mic.cmds) === JSON.stringify(['reg add "HKLM\\SOFTWARE\\Policies\\Microsoft\\Windows NT\\Terminal Services" /v fDisableAudioCapture /t REG_DWORD /d 0 /f',
      'Set-Service Audiosrv -StartupType Automatic; Start-Service Audiosrv']);
  const first = (r) => r.scr.text.indexOf(t('screens_info_api')) < r.scr.text.indexOf(t('screens_info_popup')) ? 'api' : 'popup';
  check('connect dialog: info beside the microphone (browser permission, policy, registry command) and the screens (this browser\'s permission first)',
    micOk && first(noApi) === 'popup' && first(withApi) === 'api' && withApi.scr.title === t('more_info') && noApi.scr.text.includes(t('screens_info_general')),
    JSON.stringify({ micOk, cmds: noApi.mic.cmds, noApi: first(noApi), api: first(withApi) }));
  // with nothing to type (the password saved), Connect has the focus, not an info button: Enter connects
  check('connect dialog: with nothing to type, Enter is on Connect (not on an info button)', focused.length === 2 && focused.every((f) => f === t('connect') || f === 'password'),
    JSON.stringify(focused));
}

// ------------------------------------------------------------ storage blocked by the browser

{
  // the tab's storage blocked (site data refused, some private windows): sessionStorage throws, and the page did not start
  const store0 = globalThis.sessionStorage;
  globalThis.sessionStorage = { getItem() { throw new Error('SecurityError'); }, setItem() { throw new Error('SecurityError'); } };
  let err = '';
  try { await go('#/conns'); await go('#/admin/users'); } catch (e) { err = e.message; }   // (administration reads it: the data folder check)
  globalThis.sessionStorage = store0;
  check('storage blocked by the browser: the page still works', err === '' && appEl.textContent.length > 0, err);
}

console.log(failed ? `\n${failed} failed` : '\nAll tests passed');
process.exit(failed ? 1 : 0);
