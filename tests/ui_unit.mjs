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
  stopImmediatePropagation() { this.stopped = this.immediate = true; }
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
    if (this.tagName === 'VIDEO') this.play = async () => {};   // (a media element: played by the browser)
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
  removeAttribute(k) { delete this.attrs[k]; if (k === 'src') delete this.src; }
  append(...cs) { for (const c of cs) { const n = c instanceof Node0 ? c : new Text0(String(c)); n.parentNode?.removeChild(n); n.parentNode = this; this.childNodes.push(n); } }
  appendChild(c) { this.append(c); return c; }
  removeChild(c) { this.childNodes = this.childNodes.filter((x) => x !== c); c.parentNode = null; }
  replaceChildren(...cs) { this.childNodes.forEach((c) => { c.parentNode = null; }); this.childNodes = []; this.append(...cs); }
  remove() { this.parentNode?.removeChild(this); }
  replaceWith(n) { const p = this.parentNode; if (!p) return; const i = p.childNodes.indexOf(this); n.parentNode?.removeChild(n); n.parentNode = p; p.childNodes[i] = n; this.parentNode = null; }
  contains(n) { for (; n; n = n.parentNode) if (n === this) return true; return false; }
  addEventListener(t, f) { (this.l[t] ||= []).push(f); }
  removeEventListener(t, f) { this.l[t] = (this.l[t] || []).filter((x) => x !== f); }
  dispatchEvent(e) {
    e.target ??= this;
    for (let n = this; n && !e.stopped; n = n.parentNode) for (const f of n.l?.[e.type] || []) { f(e); if (e.immediate) break; }
    return !e.defaultPrevented;
  }
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
const winL = {}, docL0 = {};
let docFocus = true;                                     // document.hasFocus(): this window is the one in front
const listen = (map) => (t, f, o) => {                   // addEventListener with its once and signal options
  const g = o?.once ? (...a) => { map[t] = (map[t] || []).filter((x) => x !== g); f(...a); } : f;
  (map[t] ||= []).push(g);
  o?.signal?.addEventListener?.('abort', () => { map[t] = (map[t] || []).filter((x) => x !== g); });
};
const fire = (map, type) => (map[type] || []).slice().forEach((f) => f(new Ev(type)));
let clock = 1000;
Object.defineProperty(globalThis, 'performance', { value: { now: () => clock }, configurable: true, writable: true });
Object.assign(globalThis, {
  Node: Node0,
  document: { documentElement: html, body, activeElement: body, hidden: false, createElement: (t) => new El(t), createElementNS: (_, t) => new El(t),
    getElementById: (id) => (id === 'app' ? appEl : html.querySelector(`[id=${id}]`)), querySelector: (s) => html.querySelector(s), addEventListener: listen(docL0),
    hasFocus: () => docFocus },
  location: { hash: '#/profile', href: 'https://gateway.test/', origin: 'https://gateway.test' },
  addEventListener: listen(winL),
  removeEventListener: (t, f) => { winL[t] = (winL[t] || []).filter((x) => x !== f); },
  sessionStorage: { getItem: (k) => (k === 'mudesk-data-check' ? '0' : null), setItem() {} },
  matchMedia: () => ({ matches: false }),
  PublicKeyCredential: class {},
  ResizeObserver: class { observe() {} disconnect() {} },
  ImageData: class {},
  requestAnimationFrame: () => 0,
  Worker: class { constructor() { workers.push(this); this.sent = []; } postMessage(m) { this.sent.push(m); } terminate() {} },
  devicePixelRatio: 1, screen: { width: 1920, height: 1080 },
  setTimeout: (f, ms) => timers.push({ f, ms }), clearTimeout: (i) => { if (timers[i - 1]) timers[i - 1].f = () => {}; },
  setInterval: (f, ms) => timers.push({ f, ms, every: true }), clearInterval: (i) => { if (timers[i - 1]) timers[i - 1].f = () => {}; },
  FormData: class {
    constructor(form) {
      this.e = form.querySelectorAll('input,select,textarea').filter((el) => el.getAttribute('name') && !el.disabled && (!/^(checkbox|radio)$/.test(el.getAttribute('type')) || el.checked))
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

const { t, getLang } = await import('../public/assets/i18n.js');
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
  // one that fits: its QR code drawn as nodes, an SVG of a white square and the black modules; no markup parsed anywhere (the
  // page's scripts set no innerHTML: before, the QR code's markup went in that way)
  answers['totp.begin'] = () => ({ secret, uri: `otpauth://totp/MuDeskClient:ana?secret=${secret}&issuer=MuDeskClient` });
  await go('#/profile');
  const pwQr = appEl.querySelectorAll('input[type=password]').filter((i) => !i.getAttribute('name'))[0];
  if (pwQr) pwQr.value = 'pass-1';
  byText(t('enable'))?.click();
  await settle();
  const svg = appEl.querySelector('.qr')?.children[0];
  const { readdirSync, readFileSync } = await import('node:fs');
  const dir = new URL('../public/assets/', import.meta.url);
  const markup = readdirSync(dir).filter((f) => f.endsWith('.js')).filter((f) => /\.(innerHTML|outerHTML)\s*=|insertAdjacentHTML/.test(readFileSync(new URL(f, dir), 'utf8')));
  check('profile: the QR code drawn as nodes (an SVG with its square and modules), no markup set by any script',
    svg?.tagName === 'SVG' && svg.children.map((c) => c.tagName).join() === 'RECT,PATH' && /^(M\d+ \d+h1v1h-1z)+$/.test(svg.children[1].getAttribute('d') ?? '')
    && /^0 0 \d+ \d+$/.test(svg.getAttribute('viewBox') ?? '') && markup.length === 0, JSON.stringify([svg?.tagName, markup]));

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

{
  // the gateway's messages: one window at a time (before, one each, stacked without end), the same text not shown twice, at
  // most three waiting; the open one closed, as declined, when the session ends
  const status = (wk, j) => wk.onmessage({ data: { t: 'frame', type: 3, d: new TextEncoder().encode(JSON.stringify(j)) } });
  await go('#/s/c1');
  for (let i = 0; i < 2; i++) {                          // (the session's options asked, then its password if not saved)
    const form = appEl.querySelector('.overlay')?.querySelector('form');
    if (!form) break;
    const pw = form.querySelector('[name=password]');
    if (pw) pw.value = 'right';
    submit(form);
    await settle();
  }
  const wk = workers.at(-1);
  status(wk, { token: 't3' });
  status(wk, { connected: true });
  for (const m of ['Aviso 1', 'Aviso 1', 'Aviso 2', 'Aviso 3', 'Aviso 4', 'Aviso 5']) status(wk, { gateway_message: m });
  await settle();
  const open = () => document.body.querySelectorAll('.modal').map((d) => d.querySelector('p')?.textContent);
  const first = open();
  byText(t('accept'), document.body)?.click();
  await settle();
  const second = open();
  byText(t('accept'), document.body)?.click();
  await settle();
  byText(t('accept'), document.body)?.click();
  await settle();
  const rest = open();
  status(wk, { gateway_message: 'Aviso 6' });
  await settle();
  status(wk, { end: 'closed' });
  wk.onmessage({ data: { t: 'end' } });
  await settle();
  check('session: the gateway\'s messages one window at a time, the same text once, three waiting at most; closed when the session ends',
    JSON.stringify([first, second, rest, open()]) === JSON.stringify([['Aviso 1'], ['Aviso 2'], [], []]), JSON.stringify([first, second, rest, open()]));
}

{
  // the gateway refused the password typed: asked again, as when the computer refuses it (before, "Reconnect" sent the same
  // wrong one, each time a failed sign-in at the gateway); a changed certificate that is not the computer's says whose it is
  const status = (wk, j) => wk.onmessage({ data: { t: 'frame', type: 3, d: new TextEncoder().encode(JSON.stringify(j)) } });
  const list0 = answers['conns.list'];
  answers['conns.list'] = () => ({ conns: [{ ...CONN, has_pass: false, gw: 'rdg.empresa.com' }], keyboards: [0x040A] });
  await go('#/profile');
  await go('#/s/c1');
  let form = appEl.querySelector('form');
  form.querySelector('[name=password]').value = 'wrong';
  submit(form);
  await settle();
  let wk = workers.at(-1);
  status(wk, { token: 't' });
  status(wk, { end: 'gateway_auth' });
  wk.onmessage({ data: { t: 'end' } });
  await settle();
  form = appEl.querySelector('.overlay')?.querySelector('form');
  const askedAgain = !!form?.querySelector('[name=password]');
  if (form) {
    form.querySelector('[name=password]').value = 'right';
    submit(form);
    await settle();
  }
  wk = workers.at(-1);
  status(wk, { token: 't2' });
  status(wk, { end: 'cert_changed', detail: 'AB:CD', host: 'rdg.empresa.com' });
  wk.onmessage({ data: { t: 'end' } });
  await settle();
  const certSaid = appEl.querySelector('.overlay')?.textContent || '';
  // a computer never seen: its fingerprints shown to compare (SHA-256, and SHA-1 as Windows shows it, in groups of four),
  // trusted only when the user says so (the next connection carries it); an administrator's box, ticked, trusts it for every
  // user (also a renewed one in place of the one fixed); a user has no box and cannot accept one other than the one fixed
  // (before, a first certificate was trusted at once, unseen)
  const sha256 = 'ab'.repeat(32), sha1 = 'cd'.repeat(20);
  const endWith = async (j) => {
    byText(t('accept_cert'), appEl.querySelector('.overlay'))?.click() || byText(t('reconnect'), appEl.querySelector('.overlay'))?.click();
    await settle();
    wk = workers.at(-1);
    status(wk, { token: 'tk' });
    status(wk, j);
    wk.onmessage({ data: { t: 'end' } });
    await settle();
    return appEl.querySelector('.overlay');
  };
  const sent = () => JSON.parse(workers.at(-1).sent.find((m) => m.body)?.body || '{}');
  const boxOf = (ov) => ov?.querySelectorAll('input[type=checkbox]')[0];
  let ov = await endWith({ end: 'cert_new', detail: sha256, sha1 });
  const newSaid = ov?.textContent || '';
  const shown = ov?.querySelectorAll('.mono').map((p) => p.textContent) ?? [];
  const adminBox = [boxOf(ov)?.checked, !!boxOf(ov)?.disabled];
  byText(t('accept_cert'), ov)?.click();
  await settle();
  const adminSent = [sent().trust, sent().trust_all];
  ov = await endWith({ end: 'cert_unexpected', detail: sha256, sha1 });
  const adminRenew = [boxOf(ov)?.checked, !!boxOf(ov)?.disabled, !!byText(t('accept_cert'), ov)];
  // connected, then connecting again to a computer never seen (a broker's other one): asked at once, never tried again unseen
  byText(t('accept_cert'), ov)?.click();
  await settle();
  wk = workers.at(-1);
  status(wk, { token: 'tk' });
  status(wk, { connected: true });
  await settle();
  const late = [];
  for (const end of ['cert_new', 'cert_unexpected']) {
    if (end !== 'cert_new') {
      byText(t('accept_cert'), appEl.querySelector('.overlay'))?.click();
      await settle();
      wk = workers.at(-1);
      status(wk, { token: 'tk' });
      status(wk, { connected: true });
      await settle();
    }
    status(wk, { end, detail: sha256, sha1 });
    wk.onmessage({ data: { t: 'end' } });
    await settle();
    late.push(!!byText(t('accept_cert'), appEl.querySelector('.overlay')));
  }
  const lateNew = late.join() === 'true,true';
  STATE.user.role = 'user';
  ov = await endWith({ end: 'cert_new', detail: sha256, sha1 });
  const userBox = !boxOf(ov);
  byText(t('accept_cert'), ov)?.click();
  await settle();
  const userSent = [sent().trust, sent().trust_all];
  ov = await endWith({ end: 'cert_unexpected', detail: sha256, sha1 });
  const unexpected = [ov?.textContent.startsWith(t('r_cert_unexpected') + t('cert_unexpected_text')), !byText(t('accept_cert'), ov), !!byText(t('close'), ov), !boxOf(ov)];
  STATE.user.role = 'admin';
  answers['conns.list'] = list0;
  check('session: a password the gateway refused asked again; a changed certificate says whose it is', askedAgain && certSaid.startsWith(t('r_cert_changed_host', 'rdg.empresa.com') + t('cert_changed_text')),
    JSON.stringify({ askedAgain, certSaid }));
  check('session: a computer never seen shows its SHA-256 and SHA-1 to compare and is trusted only when the user says so (also met when connecting again); an administrator’s box trusts it for every user (a renewed one too); a user cannot accept one other than the one fixed',
    newSaid.startsWith(t('r_cert_new') + t('cert_new_text')) && JSON.stringify(shown) === JSON.stringify(['ABAB '.repeat(16).trim(), 'CDCD '.repeat(10).trim()])
    && JSON.stringify([adminBox, adminSent, adminRenew, lateNew, userBox, userSent, unexpected]) === JSON.stringify([[true, false], [sha256, true], [true, true, true], true, true, [sha256, false], [true, true, true, true]]),
    JSON.stringify({ newSaid, shown, adminBox, adminSent, adminRenew, lateNew, userBox, userSent, unexpected }));
}

// ------------------------------------------------------------ the data folder readable from the browser

{
  // read at either place (the page's folder or the one above): what it read goes to the gateway, and the page loads again only
  // when the gateway took it (its code now); not readable: said once per browser session, nothing sent
  const { checkDataFolder } = await import('../public/assets/datacheck.js');
  const run = async (answers, kept = null, stopped = true) => {
    const prefs = kept === null ? {} : { 'mudesk-data-check': kept }, sent = [], asked = [];
    let reloaded = 0;
    const read = await checkDataFolder({ base: 'https://h.test/mudesk/public/', pref: (k, v) => (v === undefined ? prefs[k] ?? null : (prefs[k] = v)),
      api: async (a, b) => { sent.push([a, b]); return { stopped }; }, reload: () => { reloaded++; },
      get: async (u) => { asked.push(String(u)); const body = answers[String(u)]; return { ok: body !== undefined, text: async () => body ?? '' }; } });
    return { read, sent, asked, reloaded, pref: prefs['mudesk-data-check'] };
  };
  const code = 'MuDeskClient data folder: must not be reachable from the web\n' + 'f'.repeat(32) + '\n';
  const open = await run({ 'https://h.test/mudesk/data/web-check.txt': code });
  const closed = await run({});
  const known = await run({ 'https://h.test/mudesk/data/web-check.txt': code }, '0');
  const refused = await run({ 'https://h.test/mudesk/public/data/web-check.txt': code }, null, false);
  check('data folder: what the browser read sent to the gateway, the page loaded again once it stopped; not readable, said once a session; a word it refused, no reload',
    open.read && JSON.stringify(open.sent) === JSON.stringify([['data.exposed', { proof: code }]]) && open.reloaded === 1 && open.pref === '1'
    && JSON.stringify(open.asked) === JSON.stringify(['https://h.test/mudesk/data/web-check.txt', 'https://h.test/mudesk/public/data/web-check.txt'])
    && !closed.read && closed.sent.length === 0 && closed.pref === '0' && known.asked.length === 0 && !known.read
    && refused.read && refused.reloaded === 0, JSON.stringify({ open, closed, known, refused }));
}

// ------------------------------------------------------------ administration: a backup

{
  // made with its password twice (not the same: said, nothing asked), the users' folders when ticked, downloaded at the
  // address the gateway gives; restored only after yes: the file sent in pieces of 2 MB, in order, with the CSRF token, then
  // restored with its password; the page loads again (signed out)
  answers['admin.backup.prepare'] = () => ({ id: '0123456789abcdef' });
  answers['admin.backup.restore'] = () => ({ ok: true });
  await go('#/admin/backup');
  const pws = appEl.querySelectorAll('input[type=password]');
  const boxes = appEl.querySelectorAll('input[type=checkbox]');
  pws[0].value = 'Clave-de-copia-1';
  pws[1].value = 'otra';
  const c0 = calls.length;
  byText(t('bk_download'))?.click();
  await settle();
  const mismatch = [appEl.textContent.includes(t('e_backup_mismatch')), calls.length === c0];
  pws[1].value = 'Clave-de-copia-1';
  boxes[0].checked = true;
  const href0 = location.href;
  byText(t('bk_download'))?.click();
  await settle();
  const prep = calls.filter((c) => c.action === 'admin.backup.prepare').at(-1);
  const href = location.href;
  location.href = href0;
  const fetch0 = globalThis.fetch;
  const pieces = [];
  globalThis.fetch = (url, o = {}) => (String(url).startsWith('backup.php')
    ? (pieces.push([String(url), o.body.size, o.headers['X-CSRF']]), Promise.resolve({ ok: true, json: async () => ({ ok: true }) })) : fetch0(url, o));
  let reloaded = 0;
  location.reload = () => { reloaded++; };
  const fileIn = appEl.querySelector('input[type=file]');
  fileIn.files = [new File([new Uint8Array(5 * 1048576)], 'copia.mudeskbackup')];
  pws[2].value = 'Clave-de-copia-1';
  const c1 = calls.length;
  byText(t('bk_restore'))?.click();
  await settle();
  const askedFirst = pieces.length === 0 && calls.length === c1;
  document.body.querySelectorAll('button').find((b) => b.textContent === t('yes'))?.click();
  for (let i = 0; i < 6; i++) await settle();
  globalThis.fetch = fetch0;
  const rest = calls.filter((c) => c.action === 'admin.backup.restore').at(-1);
  const ids = [...new Set(pieces.map(([u]) => new URLSearchParams(u.split('?')[1]).get('id')))];
  timers.filter((x) => x.ms === 1500).forEach((x) => x.f());   // (the page loaded again after the toast)
  delete answers['admin.backup.prepare'];
  delete answers['admin.backup.restore'];
  check('backup: made with its password twice (else said), the folders when ticked, downloaded at its address; restored after yes, sent in pieces of 2 MB in order, then with its password; the page loaded again',
    JSON.stringify(mismatch) === '[true,true]' && prep?.password === 'Clave-de-copia-1' && JSON.stringify(prep?.with) === '["files"]' && href === 'backup.php?a=download&id=0123456789abcdef'
    && askedFirst && JSON.stringify(pieces.map(([u, n, c]) => [new URLSearchParams(u.split('?')[1]).get('o'), n, !!c])) === JSON.stringify([['0', 2097152, true], ['2097152', 2097152, true], ['4194304', 1048576, true]])
    && ids.length === 1 && /^[0-9a-f]{16}$/.test(ids[0]) && rest?.id === ids[0] && rest?.password === 'Clave-de-copia-1' && reloaded === 1,
    JSON.stringify({ mismatch, prep, href, askedFirst, pieces: pieces.map(([u, n]) => [u, n]), rest, reloaded }));
}

// ------------------------------------------------------------ administration: the status

{
  // now: figures, the memory and disk meters (their colour by how close to the limit), the problems, the server; the last 30
  // days: a column a day (sessions; most at once with the limit drawn), the hover giving the day's figures, the computers used
  // most as ranked bars with their hours, each chart with its table
  answers['admin.status'] = () => ({ status: { sessions: 3, limit: 20, guests: 1, recording: 1, processes: 4, memory: { sessions: 30 << 20, largest: 100 << 20, limit: 128 << 20 },
    disk: { free: 10 * 2 ** 30, total: 100 * 2 ** 30, recordings: 2 ** 30, recordings_count: 7 }, housekeeping: '2026-11-24T10:00:00+01:00', load: [0.5, 0.4, 0.3],
    problems: { login_fail: 5, session_error: 2, cert_mismatch: 0, access_denied: 1 }, server: { version: '1.2.0', php: '8.4.1', sapi: 'fpm-fcgi', os: 'Linux 6', openssl: 'OpenSSL 3', time_limit: 0 } } });
  const days = Array.from({ length: 30 }, (_, i) => ({ day: new Date(Date.UTC(2026, 9, 26 + i)).toISOString().slice(0, 10), sessions: i % 3, hours: i / 2, peak: i % 4 }));
  answers['admin.usage'] = () => ({ days, hosts: [{ host: 'pc1.test', hours: 12.5, sessions: 9 }, { host: 'pc2.test', hours: 3, sessions: 2 }] });
  await go('#/admin/status');
  await settle();
  const text = appEl.textContent;
  const charts = appEl.querySelectorAll('svg.chart');
  const bars = charts.map((s) => s.querySelectorAll('.chart-col').length);
  const meters = appEl.querySelectorAll('.meter').map((m) => [m.className, m.getAttribute('aria-valuenow')]);
  charts[0]?.querySelectorAll('.hit')[29]?.dispatchEvent(new Ev('pointerenter'));
  const tip = appEl.querySelector('.chart-tip')?.textContent || '';
  const widths = appEl.querySelectorAll('.hbar-fill').map((f) => f.style.cssText);
  const seen = { bars, meters, tip, widths, limit: charts[1]?.querySelectorAll('.limit').length, tables: appEl.querySelectorAll('details').filter((d) => d.className === 'chart-table').length,
    said: [t('st_of_limit', '20'), t('st_login_fail'), '1.2.0', 'fpm-fcgi', 'pc1.test', '12,5 h'].map((x) => text.includes(x)) };
  delete answers['admin.status'];
  delete answers['admin.usage'];
  check('administration status: figures, meters by how close to their limit, problems and server; a column a day with the day’s figures on hover, the limit drawn, the computers used most, each with its table',
    JSON.stringify(seen.bars) === '[20,22]' && JSON.stringify(seen.meters) === JSON.stringify([['meter warn', '78'], ['meter danger', '90']]) && seen.tip.includes('14,5') && seen.tip.includes(t('st_t_sessions'))
    && JSON.stringify(seen.widths) === JSON.stringify(['width:100.0%', 'width:24.0%']) && seen.limit === 1 && seen.tables === 2 && seen.said.every(Boolean), JSON.stringify(seen));
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
  form.querySelector('[name=multi]').checked = false;     // (ticked by default: unticked, this screen alone, and then the offer)
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
  // its bar: full screen, then (in full screen) out of it; Close this screen closes its window. Icon buttons, as the
  // session's toolbar: its full screen icon, and the X (before, white buttons with their names written)
  const sbar = doc.body.querySelector('.screen-bar'), fsb = () => sbar?.querySelector('.fs');
  const path = (b) => b?.querySelector('path')?.getAttribute('d');
  const mainFullBtn = appEl.querySelector('.bar').querySelectorAll('button').find((b) => b.getAttribute('title') === t('fullscreen'));
  const icons = [path(fsb()) === path(mainFullBtn), path(sbar?.querySelector('.close'))?.startsWith('M6 6l12 12'), fsb()?.textContent, sbar?.querySelector('.close')?.getAttribute('aria-label')];
  const labels = [fsb()?.getAttribute('title')];
  fsb()?.click();
  doc.fullscreenElement = doc.documentElement;
  (docL.fullscreenchange || []).forEach((f) => f());
  labels.push(fsb()?.getAttribute('title'));
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
    // (full screen was asked for: the other screen's window asks for it too as it opens, both times)
    JSON.stringify(labels) === JSON.stringify([t('fullscreen'), t('exit_fullscreen')]) && JSON.stringify(fsCalls) === '["enter","enter","exit","enter"]'
    && sbar?.querySelector('.close')?.getAttribute('title') === t('close_this_screen') && JSON.stringify(left) === '[["resize",1920,1080]]'
    && JSON.stringify(icons) === JSON.stringify([true, true, '', t('close_this_screen')]), JSON.stringify({ labels, fsCalls, icons }));
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
  form.querySelector('[name=multi]').checked = false;     // (ticked by default: unticked, this screen alone, and then the offer)
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

// ------------------------------------------------------------ start in full screen (connect dialog)

{
  // "Start in full screen": on by default (off only if the user unticks it, then remembered with the options); this window in
  // full screen with the click on Connect, also without "Use all my screens" (before, only that option went to full screen,
  // and always). A window the browser keeps out of full screen (allowed only from a click in that window, unless a policy
  // allows it) goes to it with its first press on the remote screen, which reaches no further (a click on the remote
  // computer the user did not mean); once pressed in full screen, the presses are the session's. A connection opened
  // without the dialog asks for it at once (a policy may allow it) and on its first press
  const asked = [], rf0 = El.prototype.requestFullscreen;
  let refuse = false, onAsk = null, held = null;          // held: the requests left pending, answered by the test
  El.prototype.requestFullscreen = function (o) {
    asked.push(this.className.split(' ')[0] + (o?.screen ? ' ' + o.screen.left : ''));
    onAsk?.();
    if (held) return new Promise((ok, no) => held.push([ok, no]));
    return refuse ? Promise.reject(new TypeError('Fullscreen request denied')) : rf0.call(this);
  };
  const press = async (el, id) => {
    const n = rdpSent.length, down = new Ev('pointerdown', { button: 0, buttons: 1, pointerId: id, pointerType: 'mouse', clientX: 30, clientY: 30 });
    el.dispatchEvent(down);
    el.dispatchEvent(new Ev('pointerup', { button: 0, buttons: 0, pointerId: id, pointerType: 'mouse', clientX: 30, clientY: 30 }));
    await settle();
    return [rdpSent.length - n > 0, !!down.immediate];   // [reached the remote computer, stopped]
  };
  const open = async (setup) => {
    Object.assign(CONN, { clipboard: false, ask: true, has_pass: false, kbd: 0x040A, full: true }, setup);
    await go('#/conns');
    await go('#/s/c1');
    return appEl.querySelector('.overlay')?.querySelector('form');
  };
  const connected = async (token) => {
    const wk = workers.at(-1);
    for (const j of [{ token }, { connected: true }]) wk.onmessage({ data: { t: 'frame', type: 3, d: new TextEncoder().encode(JSON.stringify(j)) } });
    await settle();
  };
  // the dialog: ticked (also when the connection says nothing of it), among the options (two columns where they fit);
  // unticked and remembered
  let form = await open({ full: undefined });
  const shown = [!!form.querySelector('.conn-opts')?.querySelector('[name=full]'), form.querySelector('[name=full]')?.checked];
  form.querySelector('[name=password]').value = 'pw';
  form.querySelector('[name=full]').checked = false;
  form.querySelector('[name=remember]').checked = true;
  asked.length = 0;
  submit(form);
  await settle();
  const remembered = calls.filter((c) => c.action === 'conns.options').at(-1)?.opts?.full;
  const offAsked = asked.length;
  await connected('t30');
  const offPress = await press(appEl.querySelector('canvas'), 1);
  // remembered off: unticked; ticked again: full screen with Connect, then the first press (kept out of it) goes there
  form = await open({ full: false });
  const offShown = form.querySelector('[name=full]')?.checked;
  form.querySelector('[name=full]').checked = true;
  form.querySelector('[name=password]').value = 'pw';
  asked.length = 0;
  submit(form);
  await settle();
  const onAsked = asked.splice(0);
  await connected('t31');
  const canvas = appEl.querySelector('canvas');
  const first = [...await press(canvas, 2), asked.splice(0)];
  document.fullscreenElement = appEl.querySelector('.session');
  const inFull = await press(canvas, 3);                  // pressed in full screen: settled
  delete document.fullscreenElement;
  const later = [...await press(canvas, 4), asked.splice(0)];
  // without the dialog (a saved password, "do not ask"): asked for at once, then at the first press
  await open({ ask: false, has_pass: true });
  const direct = asked.splice(0);
  await connected('t32');
  const directPress = [...await press(appEl.querySelector('canvas'), 5), asked.splice(0)];
  // a first press whose release went elsewhere (off the canvas): the next press's release still reaches the session (it was
  // taken for the first one's, and the remote button stayed down)
  await open({ ask: false, has_pass: true });
  await connected('t35');
  const c6 = appEl.querySelector('canvas'), pev = (type, buttons) => new Ev(type, { button: 0, buttons, pointerId: 1, pointerType: 'mouse', clientX: 30, clientY: 30 });
  c6.dispatchEvent(pev('pointerdown', 1));
  document.fullscreenElement = appEl.querySelector('.session');
  const relDown = pev('pointerdown', 1), relUp = pev('pointerup', 0);
  c6.dispatchEvent(relDown);
  c6.dispatchEvent(relUp);
  await settle();
  delete document.fullscreenElement;
  const lostRelease = [!!relDown.immediate, !!relUp.immediate];
  asked.length = 0;
  await open({ ask: false, has_pass: true, full: false });
  const directOff = asked.splice(0);
  // a finger's (or a pen's) press: browsers let a page go to full screen only when it is lifted (a mouse's, when pressed);
  // the whole press kept from the session, its moves too (one that moved stayed there as a second finger for good)
  await open({ ask: false, has_pass: true });
  await connected('t39');
  asked.length = 0;
  const cv = appEl.querySelector('canvas'), tev = (type) => new Ev(type, { button: 0, buttons: type === 'pointerup' ? 0 : 1, pointerId: 9, pointerType: 'touch', clientX: 30, clientY: 30 });
  const tDown = tev('pointerdown'), tMove = tev('pointermove'), tUp = tev('pointerup');
  cv.dispatchEvent(tDown);
  const atDown = asked.splice(0);
  cv.dispatchEvent(tMove);
  cv.dispatchEvent(tUp);
  await settle();
  const atUp = asked.splice(0);
  check('start in full screen: a finger\'s press asks for it when lifted (not when pressed), the press and its moves kept from the session',
    JSON.stringify([atDown, atUp, !!tDown.immediate, !!tMove.immediate, !!tUp.immediate]) === JSON.stringify([[], ['session'], true, true, true]),
    JSON.stringify({ atDown, atUp, down: !!tDown.immediate, move: !!tMove.immediate, up: !!tUp.immediate }));
  // in full screen, then out of it: left in the window in front (Esc: the user's), the next press is the session's; taken
  // out while another window was in front (Firefox, as it comes, when a window opens or comes up), its first press brings
  // it back (before: whichever, by the time it had been in full screen, 2 s)
  const fsOut = async (inFront, token) => {
    await open({ ask: false, has_pass: true });
    await connected(token);
    asked.length = 0;
    document.fullscreenElement = appEl.querySelector('.session');
    fire(docL0, 'fullscreenchange');
    docFocus = inFront;
    delete document.fullscreenElement;
    fire(docL0, 'fullscreenchange');
    docFocus = true;
    return [...await press(appEl.querySelector('canvas'), 7), asked.splice(0)];
  };
  const leftByUser = await fsOut(true, 't36'), takenOut = await fsOut(false, 't37');
  check('start in full screen: left in the window in front, the presses are the session\'s; taken out while another window was in front, its first press brings it back',
    JSON.stringify([leftByUser, takenOut]) === JSON.stringify([[true, false, []], [false, true, ['session']]]), JSON.stringify({ leftByUser, takenOut }));
  // refused while the window is not in front (Firefox gives full screen only to the window in front): asked again when it
  // comes in front (its focus event: no timers), once; refused in front, it is left to its first press
  refuse = true;
  docFocus = false;
  asked.length = 0;
  await open({ ask: false, has_pass: true });
  await settle();
  const notFront = asked.splice(0);
  docFocus = true;
  fire(winL, 'focus');
  await settle();
  const cameFront = asked.splice(0);
  fire(winL, 'focus');
  await settle();
  const cameAgain = asked.splice(0);
  await open({ ask: false, has_pass: true });
  await settle();
  const inFront = asked.splice(0);
  fire(winL, 'focus');
  await settle();
  const inFrontFocus = asked.splice(0);
  // not in front when asked, in front by the time it was refused (its focus event already gone): asked again at once
  docFocus = false;
  onAsk = () => { docFocus = true; onAsk = null; };
  await open({ ask: false, has_pass: true });
  await settle();
  const meanwhile = asked.splice(0);
  fire(winL, 'focus');
  await settle();
  const meanwhileFocus = asked.splice(0);
  refuse = false;
  // a press while a request is pending (Chrome 158 refused the press's request while the one asked for as its window came
  // in front, a moment before the press, was pending): its request made once that one is answered
  held = [];
  await open({ ask: false, has_pass: true });
  await connected('t38');
  const pending = asked.splice(0);
  const pressed = await press(appEl.querySelector('canvas'), 8);
  const whilePending = asked.splice(0);
  held.shift()[1](new TypeError('Fullscreen request denied'));
  await settle();
  const answered = asked.splice(0);
  held.splice(0).forEach(([ok]) => ok());
  held = null;
  check('start in full screen: a press while a request is pending asks once that one is answered (not refused with it)',
    JSON.stringify([pending, pressed, whilePending, answered]) === JSON.stringify([['session'], [false, true], [], ['session']]),
    JSON.stringify({ pending, pressed, whilePending, answered }));
  check('start in full screen: refused while not in front, asked again when the window comes in front (once, or at once if it came meanwhile); refused in front, not asked again',
    JSON.stringify([notFront, cameFront, cameAgain, inFront, inFrontFocus, meanwhile, meanwhileFocus])
    === JSON.stringify([['session'], ['session'], [], ['session'], [], ['session', 'session'], []]),
    JSON.stringify({ notFront, cameFront, cameAgain, inFront, inFrontFocus, meanwhile, meanwhileFocus }));
  check('start in full screen: on by default among the options, unticked and remembered; full screen with Connect, then at the first press (which goes no further), the presses the session\'s once in full screen; at once without the dialog',
    JSON.stringify([shown, remembered, offAsked, offPress, offShown, onAsked, first, inFull, later, direct, directPress, lostRelease, directOff])
    === JSON.stringify([[true, true], false, 0, [true, false], false, ['session'], [false, true, ['session']], [true, false], [true, false, []], ['session'], [false, true, ['session']], [false, false], []]),
    JSON.stringify({ shown, remembered, offAsked, offPress, offShown, onAsked, first, inFull, later, direct, directPress, lostRelease, directOff }));

  // with "Use all my screens" (Window Management API): every screen's window asked for full screen (this one on its screen;
  // the other at once and again from itself, brought to the front: a policy, or a Firefox setting, may allow it), and the
  // other one at its first press; without "Start in full screen", the other screen's window opens and nothing is full screen
  const screens = [{ left: 0, top: 0, width: 1920, height: 1080, isPrimary: true, availLeft: 0, availTop: 0, availWidth: 1920, availHeight: 1040 },
    { left: 1920, top: 0, width: 1280, height: 1024, isPrimary: false, availLeft: 1920, availTop: 0, availWidth: 1280, availHeight: 984 }];
  const [media, open0] = [globalThis.matchMedia, window.open];
  globalThis.matchMedia = () => ({ matches: false });
  screen.isExtended = true;
  window.getScreenDetails = async () => ({ screens, currentScreen: screens[0] });
  const mkWin = (frontOnly = false) => {               // frontOnly: full screen only for the window in front (Firefox)
    const reqs = [], later = [], wl = {}, st = { front: !frontOnly };
    const doc = { title: '', body: new El('body'), fullscreenElement: null, addEventListener() {}, exitFullscreen: async () => {}, hasFocus: () => st.front,
      documentElement: { requestFullscreen: () => { reqs.push('full'); return st.front ? Promise.resolve() : Promise.reject(new TypeError('Fullscreen request denied')); } } };
    doc.createElement = (tag) => Object.assign(new El(tag), { ownerDocument: doc });
    doc.defaultView = { document: doc, innerWidth: 1280, innerHeight: 984, addEventListener: listen(wl), setTimeout: (f) => later.push(f), clearTimeout() {},
      close() {}, focus: () => reqs.push('focus') };
    return { w: doc.defaultView, doc, reqs, later, st, fire: (type) => fire(wl, type) };
  };
  const both = async (full) => {
    const win = mkWin();
    let opened = 0;
    window.open = () => { opened++; return win.w; };
    form = await open({});
    form.querySelector('[name=password]').value = 'pw';
    form.querySelector('[name=multi]').checked = true;
    form.querySelector('[name=full]').checked = full;
    asked.length = 0;
    submit(form);
    await settle();
    const atOnce = [opened, asked.splice(0), win.reqs.splice(0)];
    win.later.splice(0).forEach((f) => f());               // (its bar tucked, and full screen asked for again from it)
    const again = win.reqs.splice(0);
    await connected(full ? 't33' : 't34');
    const p = await press(win.doc.body.querySelector('canvas'), 6);
    return [...atOnce, again, p, win.reqs.splice(0)];
  };
  const allFull = await both(true), allNot = await both(false);
  check('start in full screen with all the screens: this one on its screen, the other screen\'s window at once (no timers asking again), then at its first press; without it, the other screen\'s window opens and nothing is in full screen',
    JSON.stringify([allFull, allNot]) === JSON.stringify([[1, ['session 0'], ['full'], [], [false, true], ['full']], [1, [], [], [], [true, false], []]]),
    JSON.stringify({ allFull, allNot }));
  // without the API (Firefox, Safari): the other screen found by a window opened past the edge; that window and this one in
  // full screen only if chosen (before, this one always)
  delete window.getScreenDetails;
  delete screen.isExtended;
  const ff = async (full) => {
    const win = mkWin(true);
    Object.assign(win.w, { screen: { availLeft: 1920, availTop: 0, availWidth: 1280, availHeight: 984, width: 1280, height: 1024 }, screenX: 1920, screenY: 0, resizeTo() {} });
    let activation = false;                              // the click's (Firefox: one new window per click; full screen takes it)
    onAsk = () => { activation = false; };
    window.open = () => { asked.push(activation ? 'open' : 'blocked'); return activation ? win.w : null; };
    form = await open({});
    form.querySelector('[name=password]').value = 'pw';
    form.querySelector('[name=multi]').checked = true;
    form.querySelector('[name=full]').checked = full;
    asked.length = 0;
    activation = true;
    submit(form);
    await settle();
    const atOnce = [asked.splice(0), win.reqs.splice(0), !!win.doc.body.querySelector('canvas')];
    win.st.front = true;                                 // that window comes in front
    win.fire('focus');
    await settle();
    const front = win.reqs.splice(0);
    win.fire('focus');
    await settle();
    onAsk = null;
    return [...atOnce, front, win.reqs.splice(0)];
  };
  const ffFull = await ff(true), ffNot = await ff(false);
  // with the microphone (and camera) ticked, whether this computer has a camera is asked for after the window opened and
  // full screen was asked for: in the click itself, before waiting (Firefox and Safari count the click only while handling it)
  const nav0 = Object.getOwnPropertyDescriptor(globalThis, 'navigator');
  Object.defineProperty(globalThis, 'navigator', { value: { ...globalThis.navigator, mediaDevices: { enumerateDevices: () => new Promise(() => {}) } }, configurable: true });
  CONN.mic = true;
  const ffMic = (await ff(true)).slice(0, 2);
  CONN.mic = false;
  if (nav0) Object.defineProperty(globalThis, 'navigator', nav0);
  else delete globalThis.navigator;
  check('start in full screen without the Window Management API: the other screen\'s window opened first (asking for full screen first took the click: the window was blocked), then this one in full screen; that window refused until it comes in front, then asked once; nothing if not chosen',
    JSON.stringify([ffFull, ffNot, ffMic]) === JSON.stringify([[['open', 'session'], ['full'], true, ['full'], []], [['open'], [], true, [], []], [['open', 'session'], ['full']]]),
    JSON.stringify({ ffFull, ffNot, ffMic }));
  // "Use all my screens" ticked by default as "Start in full screen" (before, only in Firefox, once its other screen was
  // found): Chrome and Edge with several screens; Firefox and Safari once the other screen was found (they cannot know
  // before, and a computer with one screen would get the looking window at every connection); unticked and remembered
  const multiBox = async (setup) => {
    const f = await open(setup);
    const box = f.querySelector('[name=multi]');
    return box ? box.checked : 'none';
  };
  const store = new Map();
  globalThis.localStorage = { getItem: (k) => store.get(k) ?? null, setItem: (k, v) => store.set(k, String(v)) };
  const ffUnknown = await multiBox({ multi: undefined });
  form = appEl.querySelector('.overlay').querySelector('form');     // remembered so, its box unticked as it came: not kept as off
  form.querySelector('[name=password]').value = 'pw';
  form.querySelector('[name=remember]').checked = true;
  window.open = () => null;
  submit(form);
  await settle();
  const unknownSaved = 'multi' in (calls.filter((c) => c.action === 'conns.options').at(-1)?.opts || { multi: 1 });
  store.set('mudesk-other-screen', JSON.stringify({ x: 1920, y: 0, w: 1280, h: 1024 }));
  const ffKnown = await multiBox({ multi: undefined }), ffOff = await multiBox({ multi: false });
  screen.isExtended = true;
  window.getScreenDetails = async () => ({ screens, currentScreen: screens[0] });
  const apiOn = await multiBox({ multi: undefined }), apiOff = await multiBox({ multi: false });
  screen.isExtended = false;
  const apiOne = await multiBox({ multi: undefined });   // one screen: not offered
  screen.isExtended = true;
  form = await open({ multi: undefined });
  form.querySelector('[name=password]').value = 'pw';
  form.querySelector('[name=multi]').checked = false;
  form.querySelector('[name=remember]').checked = true;
  window.open = () => mkWin().w;
  submit(form);
  await settle();
  const multiSaved = calls.filter((c) => c.action === 'conns.options').at(-1)?.opts?.multi;
  delete globalThis.localStorage;
  check('use all my screens: ticked by default (Chrome and Edge with several screens; Firefox once the other screen was found), unticked and remembered',
    JSON.stringify([ffUnknown, unknownSaved, ffKnown, ffOff, apiOn, apiOff, apiOne, multiSaved]) === JSON.stringify([false, false, true, false, true, false, 'none', false]),
    JSON.stringify({ ffUnknown, unknownSaved, ffKnown, ffOff, apiOn, apiOff, apiOne, multiSaved }));
  globalThis.matchMedia = media;
  window.open = open0;
  delete screen.isExtended;
  delete window.getScreenDetails;
  El.prototype.requestFullscreen = rf0;
  Object.assign(CONN, { ask: true, has_pass: false, full: true, multi: undefined });
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
  answers['admin.settings'] = (b) => (b.settings ? (sent = b.settings, { error: 'totp_required_self' }) : { settings: { ...settings, timezone: '' }, server_timezone: 'Europe/Madrid' });
  STATE.user.targets_all = true;
  // users: administrator rights are a box (several administrators), unticked for a new user and ticked for an administrator
  const saves = [], hoursSent = [];
  answers['admin.users'] = () => ({ users: [{ id: 'u9', username: 'jefa', role: 'admin', enabled: true, totp: false, passkeys: [], max_sessions: 0, last_login: null }] });
  answers['admin.user.save'] = (b) => { saves.push([b.role, b.username ?? b.id]); hoursSent.push(b.hours); return { user: {} }; };
  await go('#/admin/users');
  check('admin: warned while all remote computers are allowed', appEl.textContent.includes(t('targets_all_warn')));
  // the hosting diagnostics (check.php) left in the public folder: the administrator told (administration pages, as the
  // warning above) until it is gone
  STATE.user.check_php = true;
  await go('#/admin/settings');
  const checkWarned = appEl.textContent.includes(t('check_php_left'));
  STATE.user.check_php = false;
  await go('#/admin/users');
  check('admin: warned while the hosting diagnostics stay in the public folder', checkWarned && !appEl.textContent.includes(t('check_php_left')));
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
  // sign-in hours: off by default (any time); turned on, the working days ticked and Saturday added, the times as shown
  const hoursOff = editForm.querySelector('input[name=hours_on]')?.checked === false;
  editForm.querySelector('input[name=hours_on]').checked = true;
  editForm.querySelector('input[name=day_6]').checked = true;
  byText(t('save'), document.body.querySelector('.modal')).click();
  await settle();
  check('admin: administrator rights as a box (unticked for a new user, ticked for an administrator), several possible',
    box0?.getAttribute('type') === 'checkbox' && !role0 && ticked1 && JSON.stringify(saves) === JSON.stringify([['admin', 'nuevo'], ['user', 'u9']]),
    JSON.stringify({ type: box0?.getAttribute('type'), role0, ticked1, saves }));
  check('admin: sign-in hours of a user, off by default (sent as none), sent with the days ticked and the times',
    hoursOff && JSON.stringify(hoursSent) === JSON.stringify([null, { days: [1, 2, 3, 4, 5, 6], from: '08:00', to: '18:00' }]), JSON.stringify({ hoursOff, hoursSent }));
  await go('#/admin/settings');
  const total = appEl.querySelector('input[name=max_sessions_total]');
  check('admin: RDP sessions of all users, with its help', total?.value === 20 && appEl.textContent.includes(t('s_max_sessions_total_help')));
  const tzField = appEl.querySelector('input[name=timezone]');
  const tzShown = tzField?.getAttribute('placeholder') === 'Europe/Madrid' && appEl.textContent.includes(t('s_timezone_help', 'Europe/Madrid'));
  byText(t('save')).click();
  await settle();
  check('admin: the time zone of the sign-in hours, empty meaning the server one (shown), sent', tzShown && sent?.timezone === '', JSON.stringify({ tzShown, tz: sent?.timezone }));
  check('admin: sent, and 2FA for everyone refused while the administrator has none, said so', sent?.max_sessions_total === 20
    && appEl.querySelectorAll('.error').some((e) => e.textContent === t('e_totp_required_self')), JSON.stringify(sent));
}

// ------------------------------------------------------------ administration: an auditor reads the audit and the sessions only

{
  // the Administration link is there; its tabs are the audit log (first) and the active sessions, without "End"; any other
  // tab asked for by its address shows the audit log; an administrator gives the role with its box
  const role = STATE.user.role;
  STATE.user.role = 'auditor';
  answers['admin.sessions'] = () => ({ sessions: [{ sid: 's1', user: 'ana', conn: 'Oficina', host: 'pc', ip: '1.2.3.4', started: '2026-11-20T10:00:00Z' }] });
  await go('#/admin/sessions');
  const tabsOf = () => appEl.querySelectorAll('a').filter((a) => a.closest('nav.tabs'));
  const navAdmin = appEl.querySelectorAll('a').some((a) => a.getAttribute('href') === '#/admin');
  const tabLinks = tabsOf().map((a) => a.getAttribute('href'));
  const noEnd = appEl.textContent.includes('Oficina') && !byText(t('terminate'));
  await go('#/admin/users');
  const usersBlocked = tabsOf().find((a) => a.className.includes('active'))?.getAttribute('href') === '#/admin/audit';
  STATE.user.role = role;
  await go('#/admin/sessions');
  const endForAdmin = !!byText(t('terminate'));
  check('auditor: the administration with the audit log, the active sessions, the recordings and the status only, no session ended; an administrator still ends them',
    navAdmin && JSON.stringify(tabLinks) === JSON.stringify(['#/admin/audit', '#/admin/sessions', '#/admin/recordings', '#/admin/status']) && noEnd && usersBlocked && endForAdmin,
    JSON.stringify({ navAdmin, tabLinks, noEnd, usersBlocked, endForAdmin }));
  delete answers['admin.sessions'];
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

  // signed in: the failed attempts since the last sign-in, until closed, only when there were some (never the last
  // sign-in itself)
  answers['conns.list'] = () => ({ conns: [CONN], keyboards: [0x040A] });
  answers.state = () => STATE;
  answers.login = () => ({ ...STATE, previous: { login: '2026-11-17T08:00:00+01:00', ip: '198.51.100.7', failures: 3,
    failed: { t: '2026-11-18T03:12:00.000+01:00', ip: '203.0.113.66' } } });
  form.code.value = '654321';
  submit(form);
  await settle();
  const note = appEl.querySelector('[role=status]');
  check('sign-in: the failures since the last sign-in are shown, not that sign-in', !!note && !note.textContent.includes('198.51.100.7') && note.textContent.includes('203.0.113.66')
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
  // Wake-on-LAN in the editor: folded without a MAC address; what is typed is sent with the connection
  savedConns.length = 0;
  byText(t('new_conn'))?.click();
  await settle();
  const wm = document.body.querySelector('.modal');
  const wolBox = wm?.querySelector('.wol-fields');
  const wolFolded = !!wolBox && !wolBox.getAttribute('open') && wolBox.querySelector('summary')?.textContent === t('wol_section');
  wm.querySelector('[name=host]').value = 'pc.apagado';
  wm.querySelector('[name=wol]').value = ' AA:BB:CC:DD:EE:02 ';
  wm.querySelector('[name=wol_ip]').value = '192.168.1.255';
  const vmBox = wm.querySelector('.vm-fields');
  const vmFolded = !!vmBox && !vmBox.getAttribute('open') && vmBox.querySelector('summary')?.textContent === t('vm_section');
  wm.querySelector('[name=vm]').value = '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0';
  wm.querySelector('[name=vm_enhanced]').checked = true;
  byText(t('save'), document.body)?.click();
  for (let i = 0; i < 3; i++) await settle();
  check('connections: Wake-on-LAN and Hyper-V fields folded in the editor, what is typed saved with the connection',
    wolFolded && savedConns[0]?.wol === 'AA:BB:CC:DD:EE:02' && savedConns[0]?.wol_ip === '192.168.1.255'
    && vmFolded && savedConns[0]?.vm === '0f1e2d3c-4b5a-6978-8796-a5b4c3d2e1f0' && savedConns[0]?.vm_enhanced === true, JSON.stringify({ wolFolded, vmFolded, saved: savedConns[0] }));
  // the editor in columns where they fit (app.css, .conn-edit): the password among the computer's fields, beside the user
  // and the domain; the four folded sections side by side; the advanced options' lists, boxes and performance in columns
  byText(t('new_conn'))?.click();
  await settle();
  const em = document.body.querySelector('.modal'), ef = em?.querySelector('form');
  const inGroup = (sel, names) => names.filter((n) => ef.querySelector(`[name=${n}]`)?.closest(sel)?.closest('form') === ef);
  const layout = {
    form: ef?.className,
    main: ef?.querySelector('.cols')?.querySelectorAll('input').map((i) => i.getAttribute('name')),
    pw: ef?.querySelector('.cols')?.querySelector('.conn-pw')?.querySelector('[name=password]') ? 'beside' : 'apart',
    sections: ef?.querySelector('.conn-sections')?.querySelectorAll('details').map((d) => d.className),
    selects: inGroup('.conn-selects', ['kbd', 'bpp', 'gfx', 'security', 'resize', 'paste']),
    checks: inGroup('.conn-checks', ['clipboard', 'drive', 'audio', 'mic', 'printer', 'touch', 'ask']),
    perf: inGroup('.conn-perf', ['perf_wallpaper', 'perf_themes', 'perf_smoothing', 'perf_animations', 'perf_drag']).length,
    // (every modal window: its content scrolls between the title and the buttons, always in sight)
    scrolls: ef?.parentNode?.className === 'modal-body' && JSON.stringify(em.children.map((c) => c.tagName + '.' + c.className)) === '["H2.","DIV.modal-body","DIV.row end modal-foot"]',
    buttons: em.querySelector('.modal-foot')?.parentNode === em ? em.querySelector('.modal-foot').querySelectorAll('button').map((b) => b.textContent) : null,
  };
  byText(t('cancel'), document.body)?.click();
  await settle();
  check('connections: the editor in columns (the password beside the user, the folded sections side by side, the advanced options in columns); its content scrolls, its buttons always in sight',
    JSON.stringify(layout) === JSON.stringify({ form: 'conn-edit', main: ['name', 'group', 'host', 'port', 'user', 'domain', 'password'], pw: 'beside',
      sections: ['rail-fields', 'vm-fields', 'farm-fields', 'wol-fields'], selects: ['kbd', 'bpp', 'gfx', 'security', 'resize', 'paste'],
      checks: ['clipboard', 'drive', 'audio', 'mic', 'printer', 'touch', 'ask'], perf: 5, scrolls: true, buttons: [t('cancel'), t('save')] }), JSON.stringify(layout));
  delete answers['conns.save'];
}

// ------------------------------------------------------------ manual connections, not saved

{
  // only for whoever may make connections; its form without name, group, password or "ask" (always asked), said not saved;
  // Connect has the gateway keep it in the web session and opens its session in a tab opened in that click (one opened once
  // the answer came would be blocked), closed if the gateway refuses; that tab finds it among the web session's ones, and its
  // connect dialog asks for the password without offering to save it or to remember the options
  const was = STATE.user.create_conns;
  STATE.user.create_conns = false;
  await go('#/profile');
  await go('#/conns');
  const hidden = !byText(t('occasional_conn'));
  STATE.user.create_conns = was;
  await go('#/profile');
  await go('#/conns');
  byText(t('occasional_conn'))?.click();
  await settle();
  const m = document.body.querySelectorAll('.modal').at(-1);
  const absent = ['name', 'group', 'password', 'ask'].map((n) => !m?.querySelector(`[name=${n}]`));
  const said = (m?.textContent || '').includes(t('occasional_note')) && (m?.textContent || '').startsWith(t('occasional_conn'));
  m.querySelector('[name=host]').value = 'pc.una.vez';
  m.querySelector('[name=user]').value = 'invitado';
  const tabs = [], open0 = window.open;
  window.open = (url, name) => { const tab = { url, name, opener: 'x', closed: false, asked: calls.length, location: { href: '' }, close() { this.closed = true; } }; tabs.push(tab); return tab; };
  answers['conns.occasional'] = (b) => ({ conn: { ...b.conn, id: 'o1234', name: b.conn.host, occasional: true, has_pass: false } });
  const n0 = calls.length;
  byText(t('connect'), m)?.click();
  for (let i = 0; i < 3; i++) await settle();
  const sent = calls.slice(n0).find((c) => c.action === 'conns.occasional')?.conn;
  const gone = !document.body.querySelectorAll('.modal').includes(m);
  answers['conns.occasional'] = () => ({ error: 'conns_locked' });
  byText(t('occasional_conn'))?.click();
  await settle();
  const m2 = document.body.querySelectorAll('.modal').at(-1);
  m2.querySelector('[name=host]').value = 'pc.dos';
  byText(t('connect'), m2)?.click();
  for (let i = 0; i < 3; i++) await settle();
  const refused = [tabs[1]?.closed, document.body.querySelectorAll('.modal').includes(m2)];
  byText(t('cancel'), m2)?.click();
  await settle();
  const list0 = answers['conns.list'];
  answers['conns.list'] = () => ({ conns: [CONN], keyboards: [0x040A], occasional: [{ ...CONN, id: 'o1234', name: 'pc.una.vez', host: 'pc.una.vez', occasional: true, has_pass: false }] });
  await go('#/s/o1234');
  const form = appEl.querySelector('form');
  const dialog = [!!form?.querySelector('[name=password]'), !form?.querySelector('[name=save_pw]'), !form?.querySelector('[name=remember]'), (appEl.textContent || '').includes('pc.una.vez')];
  answers['conns.list'] = list0;
  window.open = open0;
  delete answers['conns.occasional'];
  await go('#/conns');
  check('manual connections: only for whoever may make connections; no name, group or password; kept by the gateway and opened in a tab of that click (closed if refused); found by its tab, nothing of it saved there',
    hidden && absent.every(Boolean) && said && sent?.host === 'pc.una.vez' && sent?.user === 'invitado' && sent?.ask === true && !('password' in (sent || {}))
    && tabs[0]?.opener === null && tabs[0]?.asked === n0 && tabs[0]?.location.href.endsWith('#/s/o1234') && gone && JSON.stringify(refused) === '[true,true]' && dialog.every(Boolean),
    JSON.stringify({ hidden, absent, said, sent, tab: tabs[0]?.location.href, gone, refused, dialog }));
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
  const focusedPw = !!pwField && document.activeElement === pwField;   // (its first fields disabled: before, the focus stayed behind)
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
  const varHint = (f?.textContent || '').includes(t('user_var_hint'));   // {username}: said where it works, not elsewhere
  f.querySelector('[name=host]').value = 'nuevo';
  f.querySelector('[name=all_users]').checked = false;
  f.querySelector('[name=all_users]').dispatchEvent(new Ev('change'));   // (as a browser does: the users' boxes are enabled)
  f.querySelector('[name=u_u1]').checked = true;
  byText(t('save'), document.body)?.click();
  for (let i = 0; i < 3; i++) await settle();
  const ownRow = appEl.querySelectorAll('tr').find((r) => r.textContent.includes('De Ana'));
  ownRow?.querySelectorAll('button').find((b) => b.getAttribute('title') === t('edit'))?.click();
  await settle();
  f = document.body.querySelector('.modal');
  const ownTitle = f?.querySelector('h2,h3')?.textContent || f?.textContent.slice(0, 40);
  const ownVarHint = (f?.textContent || '').includes(t('user_var_hint'));
  f.querySelector('[name=forget]').checked = true;
  byText(t('save'), document.body)?.click();
  for (let i = 0; i < 3; i++) await settle();
  check('connections of the administrator: marked for the user, no delete, only their password; the administration lists who sees each one and every user\'s own, creates one for chosen users, changes a user\'s own (its password only forgotten)',
    marked && !titles?.includes(t('delete')) && JSON.stringify(disabled) === '[true,true,true,true]' && focusedPw
    && JSON.stringify(rows) === JSON.stringify(['Común|ana @ rds|luis', 'Todos|ana @ rds|' + t('all_users'), 'ana|De Ana|ana @ pc.test']) && noPw
    && JSON.stringify(sent) === JSON.stringify([['conns.save', { id: 'm1', password: 'mi-clave' }], ['admin.conn.save', ['u1'], 'nuevo'], ['admin.user.conn.save', 'u1', 'De Ana', true]])
    && ownTitle.includes('ana') && varHint && !ownVarHint, JSON.stringify({ marked, titles, disabled, focusedPw, rows, noPw, sent, ownTitle, varHint, ownVarHint }));
  // CSV import: the file's text sent as it is; the result said (how many, each refused row with its reason, unknown users,
  // unused columns); a file refused as a whole said in a notice
  let csvSent = null;
  answers['admin.conns.import'] = (b) => (csvSent = b.csv, b.csv.startsWith('name') ? { error: 'csv_no_host' }
    : { created: ['A', 'B'], errors: [[3, 'host']], unknown: [[4, 'nadie']], ignored: ['notes'] });
  await go('#/admin/conns');
  const csvInput = appEl.querySelectorAll('input').find((i) => i.getAttribute('accept') === '.csv,text/csv');
  const csvButton = !!byText(t('import_csv'));
  csvInput.files = [new File(['host;name\npc;A\n'], 'conexiones.csv')];
  csvInput.dispatchEvent(new Ev('change'));
  for (let i = 0; i < 4; i++) await settle();
  const summary = document.body.querySelector('.modal')?.textContent || '';
  byText(t('close'), document.body)?.click();
  await settle();
  document.body.querySelector('.toasts')?.replaceChildren();
  csvInput.files = [new File(['name;port\nx;1\n'], 'mal.csv')];
  csvInput.dispatchEvent(new Ev('change'));
  for (let i = 0; i < 4; i++) await settle();
  const wholeRefused = (document.body.querySelector('.toasts')?.textContent || '').includes(t('e_csv_no_host'));
  check('connections of the administrator: CSV import sends the file and says what was added and what was refused, by row',
    csvButton && summary.includes(t('csv_imported', 2)) && summary.includes(t('csv_row', 3, t('e_host'))) && summary.includes(t('csv_unknown_users', 4, 'nadie'))
    && summary.includes(t('csv_ignored', 'notes')) && wholeRefused && csvSent === 'name;port\nx;1\n', JSON.stringify({ csvButton, summary, wholeRefused, csvSent }));
  ['admin.conns', 'admin.conn.save', 'admin.user.conn.save', 'admin.conns.import'].forEach((k) => delete answers[k]);
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
  // an administrator's field: the computer's certificate for every connection to it, shown as fixed now and fixed when changed
  // (before the connection is saved: a fingerprint refused saves nothing); none for a user
  answers['admin.certs'] = () => ({ certs: { 'rdp:pc.test:3389': { fp: 'ab'.repeat(20), by: 'ana', at: '' } } });
  answers['admin.cert.fix'] = () => ({ certs: {} });
  answers['conns.save'] = (b) => ({ conn: { ...b.conn } });
  const editOf = () => appEl.querySelectorAll('button').find((b) => b.getAttribute('title') === t('edit'));
  await go('#/conns');
  editOf()?.click();
  await settle();
  const me = document.body.querySelectorAll('.modal').at(-1);
  const fixField = me?.querySelector('[name=cert_fixed]');
  const fixShown = fixField?.value;
  if (fixField) fixField.value = 'CD:'.repeat(19) + 'CD';
  const c0 = calls.length;
  byText(t('save'), me)?.click();
  await settle();
  const fixOrder = calls.slice(c0).map((c) => c.action).filter((a) => ['admin.cert.fix', 'conns.save'].includes(a));
  const fixCall = calls.slice(c0).find((c) => c.action === 'admin.cert.fix');
  // the field follows the computer: another name shows that one's (none here), and nothing is fixed on saving (before, the
  // first one's stayed and was taken as unchanged); one typed in stays, fixed for the new name
  const moved = [];
  for (const typed of [null, 'ef'.repeat(32)]) {
    await go('#/conns');
    editOf()?.click();
    await settle();
    const mx = document.body.querySelectorAll('.modal').at(-1);
    const fx = mx?.querySelector('[name=cert_fixed]'), hx = mx?.querySelector('[name=host]');
    if (typed) fx.value = typed;
    if (hx) { hx.value = typed ? 'nuevo.test' : 'otro.test'; hx.dispatchEvent(new Ev('input')); }
    const cx = calls.length;
    moved.push(fx?.value);
    byText(t('save'), mx)?.click();
    await settle();
    moved.push(calls.slice(cx).filter((c) => c.action === 'admin.cert.fix').map((c) => [c.host, c.fingerprint]));
  }
  STATE.user.role = 'user';
  await go('#/conns');
  editOf()?.click();
  await settle();
  const mu = document.body.querySelectorAll('.modal').at(-1);
  const userField = !!mu?.querySelector('[name=cert_fixed]');
  byText(t('cancel'), mu)?.click();
  await settle();
  STATE.user.role = 'admin';
  delete answers['admin.certs'];
  delete answers['admin.cert.fix'];
  delete answers['conns.save'];
  check('connections: an administrator fixes the computer’s certificate for every connection to it (shown, fixed before the connection is saved; the field follows the computer); a user has no such field',
    fixShown === 'ab'.repeat(20) && JSON.stringify(fixOrder) === '["admin.cert.fix","conns.save"]' && fixCall?.host === 'pc.test' && fixCall?.port === 3389
    && fixCall?.fingerprint === 'CD:'.repeat(19) + 'CD' && !userField
    && JSON.stringify(moved) === JSON.stringify(['', [], 'ef'.repeat(32), [['nuevo.test', 'ef'.repeat(32)]]]), JSON.stringify({ fixShown, fixOrder, fixCall, userField, moved }));
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
    // the options folded (credentials and Connect not), unfolded to change them
    const det = form.querySelector('.conn-more'), inside = (n) => form.querySelector(`[name=${n}]`)?.closest('.conn-more') === det;
    folded.push({ open: !!det?.open || det?.getAttribute('open') != null, summary: det?.querySelector('summary')?.textContent,
      inside: ['audio', 'mic', 'clipboard', 'paste', 'drive', 'printer', 'multi', 'full', 'gfx', 'bpp', 'remember', 'trace'].filter(inside),
      outside: ['user', 'password', 'save_pw'].filter((n) => form.querySelector(`[name=${n}]`) && !inside(n)),
      connect: !form.querySelectorAll('button').find((b) => b.textContent === t('connect'))?.closest('.conn-more') });
    const mic = read(beside('mic')), scr = read(beside('multi')), full = read(beside('full'));
    delete screen.isExtended;
    delete window.getScreenDetails;
    return { mic, scr, full };
  };
  const folded = [];
  const focused = [];
  const noApi = await infoOf(false), withApi = await infoOf(true);
  const micOk = noApi.mic.title === t('more_info') && noApi.mic.text.includes(t('mic_info_browser')) && t('mic_info_gpo').every((s) => noApi.mic.text.includes(s))
    && JSON.stringify(noApi.mic.cmds) === JSON.stringify(['reg add "HKLM\\SOFTWARE\\Policies\\Microsoft\\Windows NT\\Terminal Services" /v fDisableAudioCapture /t REG_DWORD /d 0 /f',
      'Set-Service Audiosrv -StartupType Automatic; Start-Service Audiosrv',
      'reg add "HKLM\\SOFTWARE\\Policies\\Microsoft\\Windows NT\\Terminal Services" /v fDisableCameraRedir /t REG_DWORD /d 0 /f'])
    && noApi.mic.text.includes(t('cam_info'));         // (the camera goes with the microphone: one option)
  const first = (r) => r.scr.text.indexOf(t('screens_info_api')) < r.scr.text.indexOf(t('screens_info_popup')) ? 'api' : 'popup';
  check('connect dialog: info beside the microphone and camera (browser permission, policies, registry commands) and the screens (this browser\'s permission first)',
    micOk && first(noApi) === 'popup' && first(withApi) === 'api' && withApi.scr.title === t('more_info') && noApi.scr.text.includes(t('screens_info_general')),
    JSON.stringify({ micOk, cmds: noApi.mic.cmds, noApi: first(noApi), api: first(withApi) }));
  // beside "Start in full screen": what each browser does with Connect, and how to let every screen go without a press
  // (the policy's registry commands with this page's address, for Edge and Chrome; Firefox's three settings)
  const fi = withApi.full;
  const fullCmds = ['Microsoft\\Edge', 'Google\\Chrome'].map((k) => `reg add "HKLM\\SOFTWARE\\Policies\\${k}\\AutomaticFullscreenAllowedForUrls" /v 1 /t REG_SZ /d "https://gateway.test" /f`);
  const fullOkInfo = fi.title === t('more_info') && [t('full_info_how'), ...t('full_info_browsers'), t('full_info_press'), t('full_info_auto', 'https://gateway.test'),
    t('full_info_chromium'), t('full_info_chromium_after'), t('full_info_firefox'), 'full-screen-api.allow-trusted-requests-only', 'full-screen-api.exit-on.windowOpen',
    'full-screen-api.exit-on.windowRaise'].every((s) => fi.text.includes(s)) && JSON.stringify(fi.cmds) === JSON.stringify(fullCmds) && noApi.full.text === fi.text;
  check('connect dialog: info beside "Start in full screen" (each browser, the policy\'s commands with this address, Firefox\'s settings)', fullOkInfo,
    JSON.stringify({ title: fi.title, cmds: fi.cmds, text: fi.text.slice(0, 120) }));
  const want = JSON.stringify({ open: false, summary: t('connect_options'), inside: ['audio', 'mic', 'clipboard', 'paste', 'drive', 'printer', 'multi', 'full', 'gfx', 'bpp', 'remember', 'trace'],
    outside: ['password', 'save_pw'], connect: true });
  check('connect dialog: the options folded under their summary (the password, saving it and Connect outside them)', folded.length === 2 && folded.every((f) => JSON.stringify(f) === want),
    JSON.stringify(folded));
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

// ------------------------------------------------------------ sharing a session

{
  // the owner: a "Share" button (not for RemoteApp); its panel lists the links, makes one (view only by default) and shows its
  // address, revokes it; while guests watch, how many is always in sight
  Object.assign(CONN, { clipboard: false, ask: true, app: '' });
  answers['session.shares'] = () => ({ shares: [] });
  answers['session.share'] = (b) => ({ id: 's1', mode: b.mode, link: 'abc.SECRET', shares: [{ id: 's1', mode: b.mode, created: '2026-11-20T10:00:00+01:00',
    expires: b.minutes ? '2026-11-20T10:15:00+01:00' : null }] });
  answers['session.unshare'] = () => ({ shares: [] });
  await go('#/conns');
  await go('#/s/c1');
  const form = appEl.querySelector('.overlay').querySelector('form');
  const pw = form.querySelector('[name=password]');   // (asked unless saved by a test above)
  if (pw) pw.value = 'pw';
  submit(form);
  await settle();
  const wk = workers.at(-1);
  const status = (j) => wk.onmessage({ data: { t: 'frame', type: 3, d: new TextEncoder().encode(JSON.stringify(j)) } });
  status({ token: 't1' });
  status({ connected: true });
  await settle();
  const shareBtn = appEl.querySelector('.bar').querySelectorAll('button').find((b) => b.getAttribute('title') === t('share'));
  shareBtn.click();
  await settle();
  const panel = appEl.querySelector('.panel');
  const empty = panel.textContent.includes(t('share_none'));
  byText(t('share_make'), panel).click();
  await settle();
  const made = calls.filter((c) => c.action === 'session.share').at(-1);
  const link = panel.querySelectorAll('input').find((i) => i.closest('.share-made'))?.value;
  const listed = panel.querySelectorAll('li').length;
  const firstMode = panel.querySelector('select')?.children[0]?.value;      // (the double has no select.value: the first option)
  status({ viewers: 2 });
  await settle();
  const badge = appEl.querySelector('.watch-badge');
  const shown = [badge.classList.contains('hidden'), badge.textContent.includes(t('share_viewers', 2)), panel.textContent.includes(t('share_viewers', 2))];
  byText(t('share_revoke'), panel).click();
  await settle();
  const revoked = calls.filter((c) => c.action === 'session.unshare').at(-1);
  status({ viewers: 0 });
  await settle();
  // for a while: chosen before it is made (as long as the session by default), listed with its end
  const until = panel.querySelectorAll('select')[1];
  const untilFirst = until?.children[0]?.value;
  until.value = '15';
  until.dispatchEvent(new Ev('change'));
  byText(t('share_make'), panel).click();
  await settle();
  const made15 = calls.filter((c) => c.action === 'session.share').at(-1);
  const endSaid = panel.querySelector('li')?.textContent.includes(t('share_expires', new Date('2026-11-20T10:15:00+01:00').toLocaleTimeString(getLang(), { hour: '2-digit', minute: '2-digit' })));
  check('sharing, the owner: a link for a while (as long as the session unless chosen), listed with its end', String(untilFirst) === '0' && made?.minutes === 0 && made15?.minutes === 15 && endSaid,
    JSON.stringify({ untilFirst, made, made15, endSaid }));
  byText(t('share_revoke'), panel).click();
  await settle();
  check('sharing, the owner: links listed, made (view only by default) with their address, revoked; how many guests watch in sight',
    empty && firstMode === 'view' && made?.mode !== 'control' && /^[0-9a-f]{32}$/.test(made?.sid) && link === 'https://gateway.test/#/watch/abc.SECRET' && listed === 1
      && JSON.stringify(shown) === '[false,true,true]' && revoked?.id === 's1' && revoked?.sid === made?.sid && !panel.querySelector('.share-made')
      && badge.classList.contains('hidden'),
    JSON.stringify({ empty, made, link, listed, shown, revoked }));
  await go('#/conns');

  // a guest: the link's page, without the toolbar, says whose session it watches; when it ends, said so
  await go('#/watch/abc.SECRET');
  const gw = workers.at(-1);
  const req = gw?.sent?.at(-1);
  gw?.onmessage?.({ data: { t: 'frame', type: 3, d: new TextEncoder().encode(JSON.stringify({ watch: 'control', owner: 'ana', conn: 'PC', sid: 'abc' })) } });
  await settle();
  const gBadge = appEl.querySelector('.watch-badge');
  const watching = [gBadge?.classList.contains('hidden'), gBadge?.textContent.trim(), !appEl.querySelector('.bar'), appEl.querySelector('.overlay')?.classList.contains('hidden')];
  // its control unused for the administrator's minutes: told, watching on
  gw?.onmessage?.({ data: { t: 'frame', type: 3, d: new TextEncoder().encode(JSON.stringify({ watch: 'view', owner: 'ana', conn: 'PC', sid: 'abc', control_ended: 'idle' })) } });
  await settle();
  const idleSaid = [document.body.querySelector('.toasts')?.textContent.includes(t('share_control_idle')), appEl.querySelector('.watch-badge')?.textContent.trim()];
  gw?.onmessage?.({ data: { t: 'end' } });
  await settle();
  const over = appEl.querySelector('.overlay')?.textContent || '';
  check('sharing, a guest: the link\'s page watches the session (whose, with control or not), no toolbar; control lost when unused, said; its end said',
    req?.url?.endsWith('rdp.php?a=watch') && JSON.parse(req.body).link === 'abc.SECRET'
      && JSON.stringify(watching) === JSON.stringify([false, t('share_watching_control', 'ana', 'PC'), true, true])
      && over.includes(t('share_over')) && over.includes(t('share_over_text')) && JSON.stringify(idleSaid) === JSON.stringify([true, t('share_watching', 'ana', 'PC')]),
    JSON.stringify({ req, watching, over, idleSaid }));
  await go('#/conns');
  delete answers['session.shares'];
  delete answers['session.share'];
  delete answers['session.unshare'];
}

// ------------------------------------------------------------ connections by users

{
  // not allowed by the administrator: no "New connection" or "Import .rdp", the empty list says who gives them; the user's own
  // connection keeps its target (fields locked, said why); the administrator's setting
  const was = STATE.user.create_conns;
  STATE.user.create_conns = false;
  const conns0 = answers['conns.list'];
  answers['conns.list'] = () => ({ conns: [], keyboards: [0x040A] });
  await go('#/profile');
  await go('#/conns');
  const empty = [!byText(t('new_conn')), !byText(t('import_rdp')), appEl.textContent.includes(t('no_conns_given'))];
  answers['conns.list'] = () => ({ conns: [{ ...CONN, id: 'own1', name: 'Mía', managed: false }], keyboards: [0x040A] });
  await go('#/profile');
  await go('#/conns');
  appEl.querySelectorAll('button').find((b) => b.getAttribute('title') === t('edit'))?.click();
  await settle();
  const m = document.body.querySelectorAll('.modal').at(-1);
  const locked = ['host', 'port', 'user', 'domain', 'security', 'gw', 'lb'].map((n) => !!m?.querySelector(`[name=${n}]`)?.disabled);
  const farmHints = m?.querySelector('[name=gw]')?.getAttribute('placeholder') === t('gw_placeholder') && m?.querySelector('[name=lb]')?.getAttribute('placeholder') === t('lb_placeholder');
  const free = !m?.querySelector('[name=name]')?.disabled && !m?.querySelector('[name=audio]')?.disabled;
  const said = (m?.textContent || '').includes(t('conns_locked_hint'));
  const sent0 = calls.length;
  answers['conns.save'] = (b) => ({ conn: b.conn });
  byText(t('save'), document.body)?.click();
  for (let i = 0; i < 3; i++) await settle();
  const saved = calls.slice(sent0).find((c) => c.action === 'conns.save')?.conn;
  delete answers['conns.save'];
  answers['conns.list'] = conns0;
  STATE.user.create_conns = was;
  await go('#/profile');
  await go('#/conns');
  const allowed = !!byText(t('new_conn')) && !!byText(t('import_rdp'));
  answers['admin.settings'] = () => ({ settings: { clients: { mode: 'all', list: [] }, targets: { mode: 'all', list: [] }, users_create_conns: false, timezone: '' }, server_timezone: 'UTC' });
  await go('#/admin/settings');
  const setting = appEl.querySelectorAll('input').some((i) => i.getAttribute('name') === 'users_create_conns');
  const box = appEl.querySelectorAll('input').find((i) => i.getAttribute('name') === 'users_create_conns');
  if (box) box.checked = true;
  const n1 = calls.length;
  byText(t('save'))?.click();
  for (let i = 0; i < 3; i++) await settle();
  const savedSetting = calls.slice(n1).find((c) => c.action === 'admin.settings' && c.settings)?.settings?.users_create_conns;
  delete answers['admin.settings'];
  await go('#/conns');
  check('connections by users: none made or imported unless allowed, their own keep their target (the form sends it as it was), the administrator\'s setting',
    empty.every(Boolean) && locked.every(Boolean) && farmHints && free && said && saved?.host === CONN.host && saved?.port === CONN.port && saved?.user === CONN.user && allowed && setting
      && savedSetting === true,
    JSON.stringify({ empty, locked, free, said, saved, allowed, setting, savedSetting }));
}
{
  // Kerberos with RC4 (Administration -> Settings): off as it comes, its risk said next to it as a warning, saved when ticked
  answers['admin.settings'] = () => ({ settings: { clients: { mode: 'all', list: [] }, targets: { mode: 'all', list: [] }, kerberos_rc4: false, timezone: '' }, server_timezone: 'UTC' });
  await go('#/admin/settings');
  const box = appEl.querySelectorAll('input').find((i) => i.getAttribute('name') === 'kerberos_rc4');
  const off = !!box && !box.checked;
  const note = appEl.querySelectorAll('small.warn').some((x) => x.textContent === t('s_kerberos_rc4_help'));
  if (box) box.checked = true;
  const n1 = calls.length;
  byText(t('save'))?.click();
  for (let i = 0; i < 3; i++) await settle();
  const saved = calls.slice(n1).find((c) => c.action === 'admin.settings' && c.settings)?.settings?.kerberos_rc4;
  delete answers['admin.settings'];
  check('settings: Kerberos with RC4 off as it comes, its risk said next to it, saved when ticked', off && note && saved === true, JSON.stringify({ off, note, saved }));
}

// ------------------------------------------------------------ recorded sessions

{
  // the administrator's editors have "Record", also their own one (a user's own does not); the user sees the connection marked, is told in the
  // connect dialog and during the session; the audit plays a recorded session; the player: its position, speed, a jump and its end
  Object.assign(CONN, { record: true, ask: true, app: '', clipboard: false });
  await go('#/conns');
  const card = appEl.querySelectorAll('.badge').some((b) => b.textContent === t('rec_conn'));
  appEl.querySelectorAll('button').find((b) => b.getAttribute('title') === t('edit'))?.click();
  await settle();
  const adminOwnEditor = !!document.body.querySelectorAll('.modal').at(-1)?.querySelector('[name=record]');   // (an administrator's own)
  byText(t('cancel'), document.body)?.click();
  await settle();
  STATE.user.role = 'user';                              // a user's own editor: not there
  await go('#/profile');
  await go('#/conns');
  appEl.querySelectorAll('button').find((b) => b.getAttribute('title') === t('edit'))?.click();
  await settle();
  const userEditor = !!document.body.querySelectorAll('.modal').at(-1)?.querySelector('[name=record]');
  byText(t('cancel'), document.body)?.click();
  await settle();
  STATE.user.role = 'admin';
  await go('#/profile');
  await go('#/conns');
  await go('#/s/c1');
  const dialog = (appEl.querySelector('.overlay')?.textContent || '').includes(t('rec_notice'));
  const form = appEl.querySelector('.overlay').querySelector('form');
  const pw = form.querySelector('[name=password]');
  if (pw) pw.value = 'pw';
  submit(form);
  await settle();
  const wk = workers.at(-1);
  wk.onmessage({ data: { t: 'frame', type: 3, d: new TextEncoder().encode('{"token":"t5"}') } });
  wk.onmessage({ data: { t: 'frame', type: 3, d: new TextEncoder().encode('{"recording":true}') } });
  wk.onmessage({ data: { t: 'frame', type: 3, d: new TextEncoder().encode('{"connected":true}') } });
  await settle();
  const recBadge = appEl.querySelector('.rec-badge');
  const during = !!recBadge && !recBadge.classList.contains('hidden') && recBadge.textContent === t('rec_badge');
  await go('#/conns');
  Object.assign(CONN, { record: false });
  // the administrator's editor of a managed connection
  answers['admin.conns'] = () => ({ conns: [], users: [{ id: 'u1', username: 'ana', conns: [] }], keyboards: [0x040A], all_users: [{ id: 'u1', username: 'ana' }] });
  await go('#/admin/conns');
  byText(t('new_conn'))?.click();
  await settle();
  const adminEditor = !!document.body.querySelectorAll('.modal').at(-1)?.querySelector('[name=record]');
  byText(t('cancel'), document.body)?.click();
  await settle();
  delete answers['admin.conns'];
  // the audit: a recorded session's start plays it
  const sid = 'ab'.repeat(16);
  answers['admin.audit'] = () => ({ rows: [{ t: '2026-11-20T10:00:00.000+01:00', ip: '203.0.113.9', user: 'ana', event: 'rdp_start', ok: true, sid, conn: 'PC', recording: true, _d: '2026-11-20', _n: 1 }],
    total: 1, events: {}, failures: 0 });
  answers['recording.info'] = () => ({ recording: { sid, user: 'ana', conn: 'PC', started: '2026-11-20T10:00:00+01:00', ended: '2026-11-20T10:02:05+01:00', seconds: 125, bytes: 1000 } });
  await go('#/admin/audit');
  const marked = (appEl.querySelector('tbody')?.textContent || '').includes(t('rec_conn'));
  appEl.querySelector('tbody').children[0].click();
  await settle();
  byText(t('rec_play'), document.body)?.click();
  await settle();
  const hash = location.hash;
  (winL.hashchange || []).forEach((f) => f());
  for (let i = 0; i < 3; i++) await settle();
  const player = workers.at(-1), first = player.sent.at(-1);
  player.onmessage({ data: { t: 'pos', ms: 65000 } });
  const time = appEl.querySelector('.rec-time')?.textContent;
  const speed = appEl.querySelector('.rec-bar')?.querySelector('select');
  if (speed) { speed.value = '4'; speed.dispatchEvent(new Ev('change')); }
  const speedCmd = player.sent.at(-1);
  const seek = appEl.querySelector('.rec-bar')?.querySelector('input');
  if (seek) { seek.value = '90000'; seek.dispatchEvent(new Ev('change')); }
  await settle();
  const jumped = workers.at(-1).sent.at(-1);
  workers.at(-1).onmessage({ data: { t: 'end' } });
  await settle();
  const over = appEl.querySelector('.overlay')?.textContent || '';
  check('recording: set in the administrator\'s editors only; the user told (card, dialog, session); played from the audit with its position, speed, a jump and its end',
    card && adminOwnEditor && !userEditor && dialog && during && adminEditor && marked && hash === '#/rec/' + sid && first?.timed === true && JSON.parse(first?.body || '{}').sid === sid
      && time === '1:05 / 2:05' && speedCmd?.cmd === 'speed' && speedCmd?.v === 4 && jumped?.from === 90000 && jumped?.speed === 4 && over.includes(t('rec_end')),
    JSON.stringify({ card, adminOwnEditor, userEditor, dialog, during, adminEditor, marked, hash, first, time, speedCmd, jumped, over }));
  delete answers['admin.audit'];
  delete answers['recording.info'];
  await go('#/conns');
}
{
  // recording started and stopped in a session: the toolbar's button for whoever may (administrators; users when the settings
  // allow it), following what the session says; one recorded by the connection or an administrator not stopped by a user
  // (its button hidden for them); the active sessions: recorded or not, started and stopped by an administrator; the setting;
  // the audit opening a recording by its own id; the player with its video download where the browser makes videos
  Object.assign(CONN, { record: false, ask: true, app: '', clipboard: false });
  const own0 = STATE.user.record_own, role0 = STATE.user.role;
  STATE.user.record_own = true;
  await go('#/conns');
  await go('#/s/c1');
  const form = appEl.querySelector('.overlay').querySelector('form');
  const pw = form.querySelector('[name=password]');
  if (pw) pw.value = 'pw';
  submit(form);
  await settle();
  const wk = workers.at(-1);
  const status = (j) => wk.onmessage({ data: { t: 'frame', type: 3, d: new TextEncoder().encode(JSON.stringify(j)) } });
  status({ token: 't9' });
  status({ connected: true });
  await settle();
  const recBtn = () => appEl.querySelector('.bar').querySelectorAll('button').find((b) => [t('rec_start'), t('rec_stop')].includes(b.getAttribute('title')));
  const offered = recBtn()?.getAttribute('title') === t('rec_start') && !recBtn().hidden;
  const n0 = calls.length;
  recBtn()?.click();
  for (let i = 0; i < 3; i++) await settle();
  const asked = calls.slice(n0).find((c) => c.action === 'session.record');
  status({ recording: true, record_by: 'owner' });
  await settle();
  const recording = [recBtn()?.getAttribute('title') === t('rec_stop'), recBtn()?.classList.contains('on'), !appEl.querySelector('.rec-badge').classList.contains('hidden')];
  STATE.user.role = 'user';
  status({ recording: true, record_by: 'policy' });
  await settle();
  const lockedForUser = recBtn()?.hidden === true;
  STATE.user.role = role0;
  status({ recording: true, record_by: 'admin' });
  await settle();
  const adminStops = recBtn()?.hidden === false;
  status({ recording: false, record_error: 'record_failed' });
  await settle();
  const failedSaid = (document.body.querySelector('.toasts')?.textContent || '').includes(t('e_record_failed'));
  STATE.user.record_own = false;
  await go('#/conns');
  await go('#/s/c1');
  const form2 = appEl.querySelector('.overlay').querySelector('form');
  if (form2.querySelector('[name=password]')) form2.querySelector('[name=password]').value = 'pw';
  submit(form2);
  await settle();
  workers.at(-1).onmessage({ data: { t: 'frame', type: 3, d: new TextEncoder().encode('{"token":"t10"}') } });
  await settle();
  const notOffered = !recBtn();
  await go('#/conns');
  // the active sessions
  answers['admin.sessions'] = () => ({ sessions: [{ sid: 's1', user: 'ana', conn: 'Oficina', host: 'pc', ip: '1.2.3.4', started: '2026-11-20T10:00:00Z', record: true, record_by: 'policy' },
    { sid: 's2', user: 'luis', conn: 'Casa', host: 'pc2', ip: '1.2.3.5', started: '2026-11-20T10:00:00Z', record: false }] });
  await go('#/admin/sessions');
  const rows = appEl.querySelector('tbody').children;
  const marks = [rows[0].textContent.includes(t('rec_conn')), rows[1].textContent.includes(t('rec_conn'))];
  const n1 = calls.length;
  byText(t('rec_start'), rows[1])?.click();
  byText(t('rec_stop'), rows[0])?.click();
  for (let i = 0; i < 3; i++) await settle();
  const adminCalls = calls.slice(n1).filter((c) => c.action === 'admin.sessions.record').map((c) => [c.sid, c.on]);
  delete answers['admin.sessions'];
  // the setting
  answers['admin.settings'] = () => ({ settings: { clients: { mode: 'all', list: [] }, targets: { mode: 'all', list: [] }, record_users: false, timezone: '' }, server_timezone: 'UTC' });
  await go('#/admin/settings');
  const box = appEl.querySelectorAll('input').find((i) => i.getAttribute('name') === 'record_users');
  const settingOff = !!box && !box.checked && appEl.textContent.includes(t('s_record_users_help'));
  if (box) box.checked = true;
  const n2 = calls.length;
  byText(t('save'))?.click();
  for (let i = 0; i < 3; i++) await settle();
  const settingSaved = calls.slice(n2).find((c) => c.action === 'admin.settings' && c.settings)?.settings?.record_users;
  delete answers['admin.settings'];
  // the audit: a recording started in a session opened by its own id; its player offers the video where VideoEncoder is
  const sid = 'cd'.repeat(16), rec = 'ef'.repeat(16);
  answers['admin.audit'] = () => ({ rows: [{ t: '2026-11-20T10:00:00.000+01:00', ip: '203.0.113.9', user: 'ana', event: 'recording_start', ok: true, sid, rec, by: 'admin',
    conn: 'PC', recording: true, _d: '2026-11-20', _n: 1 }], total: 1, events: {}, failures: 0 });
  answers['recording.info'] = () => ({ recording: { id: rec, sid, user: 'ana', conn: 'PC', started: '2026-11-20T10:00:00+01:00', ended: '2026-11-20T10:02:05+01:00', seconds: 125,
    sound: true, width: 1280, height: 720 } });
  const video0 = [globalThis.VideoEncoder, globalThis.VideoFrame];
  globalThis.VideoEncoder = class {};
  globalThis.VideoFrame = class {};
  await go('#/admin/audit');
  appEl.querySelector('tbody').children[0].click();
  await settle();
  byText(t('rec_play'), document.body)?.click();
  await settle();
  const recHash = location.hash;
  (winL.hashchange || []).forEach((f) => f());
  for (let i = 0; i < 3; i++) await settle();
  const videoBtn = !!appEl.querySelector('.rec-bar')?.querySelectorAll('button').find((b) => b.getAttribute('title') === t('rec_video'));
  [globalThis.VideoEncoder, globalThis.VideoFrame] = [undefined, undefined];
  await go('#/conns');
  location.hash = '#/rec/' + rec;
  (winL.hashchange || []).forEach((f) => f());
  for (let i = 0; i < 3; i++) await settle();
  const noVideoBtn = !appEl.querySelector('.rec-bar')?.querySelectorAll('button').find((b) => b.getAttribute('title') === t('rec_video'));
  [globalThis.VideoEncoder, globalThis.VideoFrame] = video0;
  delete answers['admin.audit'];
  delete answers['recording.info'];
  STATE.user.record_own = own0;
  await go('#/conns');
  check('recording in a session: the toolbar\'s button for whoever may, following the session; one of the connection or an administrator not stopped by a user; the active sessions; the setting; the audit by its id; the video offered where it can be made',
    offered && asked?.on === true && asked?.sid && recording.every(Boolean) && lockedForUser && adminStops && failedSaid && notOffered
      && JSON.stringify(marks) === '[true,false]' && JSON.stringify(adminCalls) === '[["s2",true],["s1",false]]' && settingOff && settingSaved === true
      && recHash === '#/rec/' + rec && videoBtn && noVideoBtn,
    JSON.stringify({ offered, asked, recording, lockedForUser, adminStops, failedSaid, notOffered, marks, adminCalls, settingOff, settingSaved, recHash, videoBtn, noVideoBtn }));
}
{
  // a user's own recordings, in their own section (Recordings, in the menu of every user, also one who may not record: told
  // so; an administrator's too, theirs there and every one in the administration): listed (one still being recorded marked, with no
  // delete), watched, downloaded as a video (one made in the page: its file, its size said first; another made here, the
  // room it may take said first), deleted only after saying yes; the player goes back to it
  const own0 = STATE.user.record_own, role0 = STATE.user.role;
  Object.assign(STATE.user, { record_own: true, role: 'user' });
  const a = 'a1'.repeat(16), b = 'b2'.repeat(16), v = 'f6'.repeat(16);
  let mine = [{ id: a, conn: 'PC', started: '2026-11-20T10:00:00+01:00', ended: '2026-11-20T10:01:05+01:00', seconds: 65, bytes: 2048, sound: true },
    { id: b, conn: 'PC2', started: '2026-11-20T11:00:00+01:00', seconds: 0, bytes: 10, running: true },
    { id: v, conn: 'PC3', started: '2026-11-20T12:00:00+01:00', ended: '2026-11-20T12:00:09+01:00', seconds: 9, bytes: 3 * 1048576, kind: 'video', mime: 'video/webm' }];
  answers['recordings.mine'] = () => ({ recordings: mine });
  answers['recordings.delete'] = (body) => { mine = mine.filter((r) => r.id !== body.id); return { ok: true }; };
  const video0 = [globalThis.VideoEncoder, globalThis.VideoFrame];
  [globalThis.VideoEncoder, globalThis.VideoFrame] = [class {}, class {}];
  await go('#/conns');
  const navLink = () => appEl.querySelector('nav')?.querySelectorAll('a').find((x) => x.getAttribute('href') === '#/recordings');
  const inMenu = [navLink()?.textContent === t('recordings')];
  await go('#/recordings');
  for (let i = 0; i < 3; i++) await settle();
  const page = () => appEl.querySelector('main');
  const rows = () => page()?.querySelector('tbody')?.children || [];
  const btnIn = (row, title) => row?.querySelectorAll('button').find((x) => x.getAttribute('title') === title);
  const listed = [page()?.querySelector('.toolbar')?.children[0]?.textContent === t('my_recs'), rows().length, rows()[0]?.textContent.includes('1:05'),
    rows()[1]?.textContent.includes(t('rec_badge')), !btnIn(rows()[1], t('delete')), !!btnIn(rows()[0], t('rec_video')),
    !(page()?.textContent || '').includes(t('my_recs_off'))];   // (they may record: no notice)
  // its video: first the room it may take, by how long it lasts; Cancel makes nothing
  const w0 = workers.length, m0 = document.body.querySelectorAll('.modal').length;
  document.body.querySelector('.toasts')?.replaceChildren();
  btnIn(rows()[0], t('rec_video'))?.click();
  await settle();
  const sizeText = document.body.querySelectorAll('.modal').at(-1)?.textContent || '';
  const roomSaid = sizeText.includes(t('rec_video_size').split('{0}')[0]) && /\d (KB|MB)/.test(sizeText);
  byText(t('cancel'), document.body)?.click();
  for (let i = 0; i < 3; i++) await settle();
  const nothingMade = workers.length === w0 && document.body.querySelectorAll('.modal').length === m0 && !(document.body.querySelector('.toasts')?.textContent || '');
  // one made in the page: its file's size said, also where this browser makes no video (nothing to make)
  [globalThis.VideoEncoder, globalThis.VideoFrame] = [undefined, undefined];
  await go('#/conns');
  await go('#/recordings');
  for (let i = 0; i < 3; i++) await settle();
  const pageVideo = [!!btnIn(rows()[2], t('rec_video')), !btnIn(rows()[0], t('rec_video'))];
  const f0 = rdpSent.length;
  btnIn(rows()[2], t('rec_video'))?.click();
  await settle();
  const fileText = document.body.querySelectorAll('.modal').at(-1)?.textContent || '';
  pageVideo.push(fileText.includes(t('rec_file_size', '3.0 MB').split('3.0 MB')[0]) && /3(\.|,)0 MB/.test(fileText));
  byText(t('cancel'), document.body)?.click();
  for (let i = 0; i < 3; i++) await settle();
  pageVideo.push(rdpSent.length === f0 && document.body.querySelectorAll('.modal').length === m0);
  [globalThis.VideoEncoder, globalThis.VideoFrame] = [class {}, class {}];
  const n0 = calls.length;
  btnIn(rows()[0], t('delete'))?.click();
  await settle();
  const asked = (document.body.querySelectorAll('.modal').at(-1)?.textContent || '').includes(t('rec_delete_q'));
  byText(t('cancel'), document.body)?.click();
  for (let i = 0; i < 3; i++) await settle();
  const keptOnNo = !calls.slice(n0).some((c) => c.action === 'recordings.delete') && rows().length === 3;
  btnIn(rows()[0], t('delete'))?.click();
  await settle();
  document.body.querySelectorAll('button').find((x) => x.textContent === t('yes'))?.click();
  for (let i = 0; i < 4; i++) await settle();
  const deleted = calls.slice(n0).find((c) => c.action === 'recordings.delete')?.id === a && rows().length === 2;
  btnIn(rows()[0], t('rec_play'))?.click();
  const played = location.hash === '#/rec/' + b;
  answers['recording.info'] = () => ({ recording: mine[0] });
  (winL.hashchange || []).forEach((f) => f());
  for (let i = 0; i < 3; i++) await settle();
  const backToList = appEl.querySelector('.rec-bar')?.querySelector('a')?.getAttribute('href') === '#/recordings';
  // one made in the page, watched: its video read from the gateway (the recording's frames, read to their end), played by
  // the browser's own player; the download offered once it is there
  const fetch0 = globalThis.fetch;
  const frame = (type, bytes) => { const f = new Uint8Array(9 + bytes.length); const dv = new DataView(f.buffer); dv.setUint8(4, type); dv.setUint32(5, bytes.length, true); f.set(bytes, 9); return f; };
  const replayAsked = [];
  globalThis.fetch = async (url, o = {}) => {
    if (!String(url).includes('a=replay')) return fetch0(url, o);
    replayAsked.push([JSON.parse(o.body).sid, o.signal]);
    const body = [frame(33, new TextEncoder().encode('VIDEO-BYTES')), frame(0, new TextEncoder().encode('end'))];
    return { ok: true, status: 200, body: new ReadableStream({ start(c) { body.forEach((x) => c.enqueue(x)); c.close(); } }) };
  };
  answers['recording.info'] = () => ({ recording: mine[1] });
  await go('#/recordings');
  await go('#/rec/' + v);
  for (let i = 0; i < 6; i++) await settle();
  const player = appEl.querySelector('video');
  const src = player?.getAttribute('src') ?? player?.src;
  const dlBtn = appEl.querySelector('.rec-bar')?.querySelectorAll('button').find((x) => x.getAttribute('title') === t('rec_video'));
  // (each view of the page asks for it: the harness has the page loaded a few times, each one renders it)
  const watched = [replayAsked.length >= 1 && replayAsked.every(([id, sig]) => id === v && sig && !sig.aborted), !!player && String(src).startsWith('blob:'), blobs.get(String(src))?.size === 11,
    !!dlBtn && !dlBtn.disabled, appEl.querySelector('.overlay')?.classList.contains('hidden')];
  await go('#/recordings');
  watched.push(revoked.includes(String(src)));             // (its video let go once the player is left)
  globalThis.fetch = fetch0;
  delete answers['recording.info'];
  STATE.user.record_own = false;                         // may not record, has none: the section there all the same, saying so
  mine = [];
  await go('#/conns');
  inMenu.push(!!navLink());
  await go('#/recordings');
  for (let i = 0; i < 3; i++) await settle();
  const notice = () => page()?.querySelectorAll('.banner').find((x) => x.textContent === t('my_recs_off'));
  inMenu.push((page()?.textContent || '').includes(t('my_recs_none')) && !!notice() && notice().className.includes('warn'));
  Object.assign(STATE.user, { record_own: true, role: 'admin' });   // an administrator too (theirs there; every one in the administration),
  await go('#/conns');                                     // who always may record: no notice
  inMenu.push(!!navLink());
  await go('#/recordings');
  for (let i = 0; i < 3; i++) await settle();
  inMenu.push(!notice());
  [globalThis.VideoEncoder, globalThis.VideoFrame] = video0;
  delete answers['recordings.mine'];
  delete answers['recordings.delete'];
  Object.assign(STATE.user, { record_own: own0, role: role0 });
  await go('#/conns');
  // every recording for administrators (and auditors), in its own tab: who, which connection, who started it, watched and
  // downloaded; deleted by an administrator only after saying yes (not one being recorded; an auditor only reads); the
  // player goes back to that tab
  let all = [
    { id: 'c3'.repeat(16), user: 'ana', conn: 'PC', started: '2026-11-20T10:00:00+01:00', ended: '2026-11-20T10:00:30+01:00', seconds: 30, bytes: 500, started_as: 'admin', by: 'jefa' },
    { id: 'd4'.repeat(16), user: 'luis', conn: 'PC2', started: '2026-11-20T09:00:00+01:00', ended: '2026-11-20T09:00:10+01:00', seconds: 10, bytes: 100, started_as: 'policy' },
    { id: 'e5'.repeat(16), user: 'eva', conn: 'PC3', started: '2026-11-20T08:00:00+01:00', ended: '2026-11-20T08:00:05+01:00', seconds: 5, bytes: 50, started_as: 'owner' },
    { id: 'f7'.repeat(16), user: 'eva', conn: 'PC3', started: '2026-11-20T07:00:00+01:00', bytes: 50, started_as: 'owner', kind: 'video', running: true },
    { id: 'a9'.repeat(16), user: 'eva', conn: 'PC3', started: '2026-11-20T06:00:00+01:00', bytes: 40, started_as: 'owner', kind: 'video' }];   // (never closed: its process killed)
  answers['admin.recordings'] = () => ({ keep_days: 30, recordings: all });
  answers['admin.recordings.delete'] = (body) => { all = all.filter((r) => r.id !== body.id); return { ok: true }; };
  STATE.user.role = 'admin';
  [globalThis.VideoEncoder, globalThis.VideoFrame] = [class {}, class {}];
  await go('#/admin/recordings');
  for (let i = 0; i < 3; i++) await settle();
  const allRows = () => appEl.querySelector('tbody')?.children || [];
  const del = (row) => row?.querySelectorAll('button').find((x) => x.getAttribute('title') === t('delete'));
  const allListed = [allRows().length, allRows()[0]?.textContent.includes(t('rec_by_admin', 'jefa')), allRows()[1]?.textContent.includes(t('rec_by_policy')),
    allRows()[2]?.textContent.includes(t('rec_by_owner')), !!allRows()[0]?.querySelectorAll('button').find((x) => x.getAttribute('title') === t('rec_video')),
    !!del(allRows()[0]), !del(allRows()[3]), allRows()[3]?.textContent.includes(t('rec_badge')), !!del(allRows()[4]), !allRows()[4]?.textContent.includes(t('rec_badge'))];
  const d0 = calls.length;
  del(allRows()[1])?.click();
  await settle();
  const adminAsked = (document.body.querySelectorAll('.modal').at(-1)?.textContent || '').includes(t('rec_delete_q'));
  byText(t('cancel'), document.body)?.click();
  for (let i = 0; i < 3; i++) await settle();
  const adminKept = !calls.slice(d0).some((c) => c.action === 'admin.recordings.delete') && allRows().length === 5;
  del(allRows()[1])?.click();
  await settle();
  document.body.querySelectorAll('button').find((x) => x.textContent === t('yes'))?.click();
  for (let i = 0; i < 4; i++) await settle();
  const adminDeleted = calls.slice(d0).find((c) => c.action === 'admin.recordings.delete')?.id === 'd4'.repeat(16) && allRows().length === 4;
  allRows()[1]?.querySelectorAll('button').find((x) => x.getAttribute('title') === t('rec_play'))?.click();
  answers['recording.info'] = () => ({ recording: { id: 'e5'.repeat(16), user: 'eva', conn: 'PC3', started: '2026-11-20T08:00:00+01:00', ended: '2026-11-20T08:00:05+01:00', seconds: 5 } });
  (winL.hashchange || []).forEach((f) => f());
  for (let i = 0; i < 3; i++) await settle();
  const backToTab = appEl.querySelector('.rec-bar')?.querySelector('a')?.getAttribute('href') === '#/admin/recordings';
  delete answers['recording.info'];
  STATE.user.role = 'auditor';
  await go('#/conns');
  await go('#/admin/recordings');
  for (let i = 0; i < 3; i++) await settle();
  const auditorReads = allRows().length === 4 && ![...allRows()].some((r) => del(r));
  delete answers['admin.recordings'];
  delete answers['admin.recordings.delete'];
  STATE.user.role = role0;
  [globalThis.VideoEncoder, globalThis.VideoFrame] = video0;
  await go('#/conns');
  check('recordings: every one in its tab for administrators (who started it, watched, a video); deleted by an administrator after yes (not one being recorded; one never closed, its process killed, yes), an auditor only reads; the player back to it',
    JSON.stringify(allListed) === '[5,true,true,true,true,true,true,true,true,true]' && adminAsked && adminKept && adminDeleted && backToTab && auditorReads,
    JSON.stringify({ allListed, adminAsked, adminKept, adminDeleted, backToTab, auditorReads }));
  check('my recordings: a section of their own in the menu of every user, administrators too (a user who may not record told so in a notice; an administrator never), listed (one being recorded marked, not deletable), watched, a video offered (the room it may take said first; one made in the page: its size, also without WebCodecs; Cancel makes nothing), deleted only after yes; one made in the page played by the browser; the player back to the section',
    JSON.stringify(inMenu) === '[true,true,true,true,true]' && JSON.stringify(listed) === '[true,3,true,true,true,true,true]' && roomSaid && nothingMade
      && JSON.stringify(pageVideo) === '[true,true,true,true]' && asked && keptOnNo && deleted && played && backToList && JSON.stringify(watched) === '[true,true,true,true,true,true]',
    JSON.stringify({ inMenu, listed, roomSaid, nothingMade, pageVideo, asked, keptOnNo, deleted, played, backToList, watched }));
}

// ------------------------------------------------------------ signing in with a company account

{
  // the profile of an OpenID Connect account: no second step or passkeys to add, its password is the provider's; a Windows
  // account: its password is changed in Windows. The connection editor in a session without the user's key: why no password
  const user0 = { ...STATE.user };                         // (the page holds this same object: changed in place)
  Object.assign(STATE.user, { role: 'user', source: 'oidc', save_passwords: false, no_key: true });
  await go('#/profile');
  const oidcProfile = [!appEl.textContent.includes(t('twofa')), !appEl.textContent.includes(t('passkeys')), appEl.textContent.includes(t('password_external_oidc')),
    !appEl.querySelector('input[type=password]')];
  await go('#/conns');
  appEl.querySelectorAll('button').find((b) => b.getAttribute('title') === t('edit'))?.click();
  await settle();
  const noKeyText = (document.body.querySelectorAll('.modal').at(-1)?.textContent || '').includes(t('pw_no_key'));   // (the last one opened)
  byText(t('cancel'), document.body)?.click();
  await settle();
  Object.assign(STATE.user, { source: 'ad', save_passwords: true, no_key: false });
  await go('#/profile');
  const adProfile = appEl.textContent.includes(t('twofa')) && appEl.textContent.includes(t('password_external_ad'));
  // signed out: the provider's button; back from it without signing in, the reason said once and the address cleaned
  delete STATE.user.source;
  delete STATE.user.no_key;
  Object.assign(STATE.user, user0);
  Object.defineProperty(El.prototype, 'lang', { get() { return this.querySelector('[name=lang]'); }, configurable: true });   // (form.lang)
  answers.state = answers.logout = () => ({ ...STATE, user: null, oidc: 'Prueba' });
  byText(t('logout')).click();
  await settle();
  const button = appEl.querySelectorAll('a').find((a) => a.getAttribute('href') === 'oidc.php?start=1');
  const cleaned = [];
  globalThis.history = { replaceState: (...a) => cleaned.push(a[2]) };
  Object.assign(location, { search: '?oidc_error=oidc_unknown_user', pathname: '/mudesk/' });
  await go('#/');
  const said = appEl.querySelector('.error')?.textContent;
  location.search = '?oidc_error=' + encodeURIComponent('Llame al 900 000 000');   // a link made up: not shown as given
  await go('#/x');
  await go('#/');
  const madeUp = appEl.querySelector('.error')?.textContent;
  location.search = '';
  answers.state = () => STATE;
  delete answers.logout;
  delete El.prototype.lang;
  check('company account: the provider button on the sign-in screen, its error said once; an OIDC or Windows profile without a password of its own; no saved password without the key',
    button?.textContent === t('oidc_signin', 'Prueba') && said === t('e_oidc_unknown_user') && cleaned[0] === '/mudesk/#/' && madeUp === t('e_oidc_provider')
    && oidcProfile.every(Boolean) && noKeyText && adProfile, JSON.stringify({ button: button?.textContent, said, cleaned, oidcProfile, noKeyText, adProfile }));
}

console.log(failed ? `\n${failed} failed` : '\nAll tests passed');
process.exit(failed ? 1 : 0);
