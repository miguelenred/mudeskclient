// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// MuDeskClient web interface.
'use strict';

import { t, setLang, getLang, browserLang, LANGS } from './i18n.js';
import { qrSvg } from './qr.js';
import { RdpSession } from './session.js';
import { ClipboardBridge } from './clipboard.js';
import { AudioPlayer } from './audio.js';
import { MicCapture } from './mic.js';
import { crc32, zipBlob } from './zip.js';
import { keyboardRows } from './osk.js';
import { RDP_MAX, decodeRdp, encodeRdp, parseRdp, toRdp } from './rdpfile.js';

const app = document.getElementById('app');
let S = null;              // server state (csrf, user...)
let loginName = '';

// ------------------------------------------------------------------ helpers

const ICONS = {
  monitor: 'M3 4h18v12H3zM8 20h8M12 16v4',
  plus: 'M12 5v14M5 12h14',
  edit: 'M4 20h4L19 9l-4-4L4 16zM14 6l4 4',
  trash: 'M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13',
  play: 'M7 4l13 8-13 8z',
  full: 'M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5',
  keyboard: 'M2 6h20v12H2zM6 10h0M10 10h0M14 10h0M18 10h0M7 14h10',
  clip: 'M9 4h6v3H9zM7 5H5v16h14V5h-2',
  folder: 'M3 6h6l2 2h10v11H3z',
  power: 'M12 3v8M6.3 6.3a8 8 0 1 0 11.4 0',
  win: 'M3 5l8-1v7H3zM13 4l8-1v8h-8zM3 13h8v7l-8-1zM13 13h8v8l-8-1z',
  lock: 'M6 11h12v9H6zM8 11V7a4 4 0 0 1 8 0v4',
  up: 'M6 15l6-6 6 6',
  down: 'M6 9l6 6 6-6',
  x: 'M6 6l12 12M18 6L6 18',
  move: 'M12 3v18M3 12h18M9 6l3-3 3 3M9 18l3 3 3-3M6 9l-3 3 3 3M18 9l3 3-3 3',
  grip: 'M9 6h0M15 6h0M9 12h0M15 12h0M9 18h0M15 18h0',
  upload: 'M12 16V4M7 9l5-5 5 5M4 20h16',
  download: 'M12 4v12M7 11l5 5 5-5M4 20h16',
  refresh: 'M20 12a8 8 0 1 1-2.3-5.6M20 4v5h-5',
  file: 'M6 3h8l4 4v14H6zM14 3v4h4',
  sound: 'M4 9h4l5-4v14l-5-4H4zM16 9a4 4 0 0 1 0 6M18.5 6.5a8 8 0 0 1 0 11',
  mute: 'M4 9h4l5-4v14l-5-4H4zM17 9l5 6M22 9l-5 6',
  keys: 'M3 7h4v4H3zM10 7h4v4h-4zM17 7h4v4h-4zM3 14h18v4H3z',
  info: 'M12 3a9 9 0 1 0 0 18a9 9 0 0 0 0-18zM12 11v6M12 7.5v.5',
  right: 'M9 6l6 6-6 6',
  home: 'M3 11l9-7 9 7M5 9.5V20h5v-6h4v6h5V9.5',
  arrowUp: 'M12 19V5M6 11l6-6 6 6',
  folderPlus: 'M3 6h6l2 2h10v11H3zM12 11v5M9.5 13.5h5',
  folderUp: 'M3 6h6l2 2h10v11H3zM12 16.5V11M9.5 13.5L12 11l2.5 2.5',
  rows: 'M4 6h16M4 12h16M4 18h16',
  grid: 'M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z',
  check: 'M4 12l5 5L20 6',
  camera: 'M4 8h3l2-3h6l2 3h3v11H4zM12 16.5a3.5 3.5 0 1 0 0-7a3.5 3.5 0 0 0 0 7z',
  crop: 'M6 2v14a2 2 0 0 0 2 2h14M2 6h14a2 2 0 0 1 2 2v14',
  copy: 'M9 9h11v11H9zM5 15H4V4h11v1',
};
const icon = (name) => {
  const s = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  s.setAttribute('viewBox', '0 0 24 24');
  s.setAttribute('class', 'ico');
  s.setAttribute('fill', 'none');
  s.setAttribute('stroke', 'currentColor');
  s.setAttribute('stroke-width', '2');
  s.setAttribute('stroke-linecap', 'round');
  s.setAttribute('stroke-linejoin', 'round');
  const p = document.createElementNS('http://www.w3.org/2000/svg', 'path');
  p.setAttribute('d', ICONS[name]);
  s.append(p);
  return s;
};

function h(tag, attrs = {}, ...children) {
  const el = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs || {})) {
    if (v === null || v === undefined || v === false) continue;
    if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
    else if (k === 'class') el.className = v;
    else if (k === 'value') el.value = v;
    else if (k === 'style') el.style.cssText = v;
    else if (k === 'checked' || k === 'selected' || k === 'disabled') el[k] = true;
    else el.setAttribute(k, v === true ? '' : v);
  }
  for (const c of children.flat(9)) if (c !== null && c !== undefined && c !== false) el.append(c instanceof Node ? c : String(c));
  return el;
}
/** replaceChildren with the same child rules as h() (arrays flattened, false/null skipped). */
const fill = (el, ...children) => el.replaceChildren(...children.flat(9).filter((c) => c !== null && c !== undefined && c !== false));
const btn = (label, onclick, cls = '', ic = null, title = null) =>
  h('button', { type: 'button', class: 'btn ' + cls, onclick, title }, ic && icon(ic), label);
const field = (label, input, hint = null) => h('label', { class: 'field' }, h('span', {}, label), input, hint && h('small', { class: 'muted' }, hint));
const check = (label, attrs) => h('label', { class: 'check' }, h('input', { type: 'checkbox', ...attrs }), label);
const select = (name, options, value) =>
  h('select', { name }, Object.entries(options).map(([v, l]) => h('option', { value: v, selected: String(v) === String(value) }, l)));
/** Quality indicator of the session toolbar, always 29 characters (monospace): the toolbar does not change width. */
const statsText = (fps, ms, kbs) => {
  const pad = (v, n) => String(v).padStart(n);
  const rate = kbs < 999.5 ? pad(Math.round(kbs), 4) + ' KB/s' : pad((kbs / 1024).toFixed(kbs < 10189 ? 1 : 0), 4) + ' MB/s';
  return `${pad(Math.min(999, Math.round(fps)), 3)} fps · ${pad(Math.min(9999, Math.round(ms)), 4)} ms · ${rate}`;
};
const fmtSize = (n) => n < 1024 ? n + ' B' : n < 1048576 ? (n / 1024).toFixed(1) + ' KB' : n < 1073741824 ? (n / 1048576).toFixed(1) + ' MB' : (n / 1073741824).toFixed(2) + ' GB';
const fmtDate = (s) => s ? new Date(s).toLocaleString(getLang()) : t('never');

/** The corner where notices appear (read out by screen readers). */
/** Where notices and dialogs go: in full screen only that element is shown. */
const front = () => document.fullscreenElement || document.body;
const toastBox = () => {
  const box = document.querySelector('.toasts') || h('div', { class: 'toasts', 'aria-live': 'polite' });
  if (box.parentNode !== front()) front().appendChild(box);
  return box;
};
function toast(msg, err = false, ms = 0) {
  const el = h('div', { class: 'toast' + (err ? ' err' : '') }, msg);
  toastBox().append(el);
  setTimeout(() => el.remove(), ms || (err ? 6000 : 3000));
  return el;
}
/** Error line of a form, announced when it changes. */
const errBox = () => h('div', { class: 'error', role: 'alert' });
/** Saves a Blob as a download and frees it afterwards. */
const saveBlob = (blob, name) => {
  const url = URL.createObjectURL(blob);
  Object.assign(document.createElement('a'), { href: url, download: name }).click();
  setTimeout(() => URL.revokeObjectURL(url), 5000);
};
const errText = (code) => t('e_' + code) !== 'e_' + code ? t('e_' + code) : t('e_generic', code);

class ApiError extends Error {}

/** The fingerprint of this page's files (index.php); the gateway's, newer after an update, makes it offer to reload. */
const BUILD = document.documentElement.dataset?.build || '';
let updateShown = false;
function checkBuild(build) {
  if (!build || !BUILD || build === BUILD || updateShown) return;
  updateShown = true;
  const bar = h('div', { class: 'update-bar', role: 'status' }, h('span', {}, t('update_ready')),
    btn(t('update_reload'), () => location.reload(), 'small primary', 'refresh'), btn('', () => bar.remove(), 'small', 'x', t('close')));
  document.body.append(bar);
}

async function api(action, data = {}) {
  const r = await fetch('api.php', {
    method: 'POST', cache: 'no-store',
    headers: { 'Content-Type': 'application/json', 'X-CSRF': S?.csrf || '' },
    body: JSON.stringify({ action, ...data }),
  });
  const j = await r.json().catch(() => ({ error: 'internal' }));
  if ('setup' in j) { S = j; applyLang(); sessionState(); checkBuild(j.build); }   // e.g. after signing in: the language saved in the profile
  if (j.error) {
    // the session ended while signed in: said in a window, then the sign-in screen
    if ((j.error === 'auth' || j.error === 'csrf') && action !== 'login' && S?.user) { sessionEnded(j.reason); throw new ApiError(j.error); }
    // a step pending (password change, a second factor the administrator has just required): the screen that asks for it
    if ((j.error === 'auth' && action !== 'login') || j.error === 'pending') { await loadState(); render(); }
    throw new ApiError(j.error);
  }
  touched();
  return j;
}

/** Runs an action and shows errors as toast; returns undefined on error. */
async function attempt(fn, errBox = null) {
  try {
    if (errBox) errBox.textContent = '';
    return await fn();
  } catch (e) {
    if (ending && e instanceof ApiError && (e.message === 'auth' || e.message === 'csrf')) return;   // the window that says so is up
    const msg = e instanceof ApiError ? errText(e.message) : String(e.message || e);
    if (errBox) errBox.textContent = msg; else toast(msg, true);
  }
}

function modal(title, body, actions) {
  return new Promise((resolve) => {
    const before = document.activeElement;
    const close = (v) => { back.remove(); before?.focus?.(); resolve(v); };
    const dialog = h('div', { class: 'modal', role: 'dialog', 'aria-modal': 'true', 'aria-label': title, tabindex: -1 }, h('h2', {}, title), body,
      h('div', { class: 'row end', style: 'margin-top:1rem' }, actions(close)));
    const back = h('div', { class: 'modal-back', onclick: (e) => e.target === back && close(null), onkeydown: (e) => e.key === 'Escape' && !e.isComposing && close(null) }, dialog);
    front().append(back);
    (back.querySelector('input,select,textarea') || dialog).focus();
  });
}
const confirmDialog = (text) => modal(text, '', (close) => [btn(t('cancel'), () => close(false)), btn(t('yes'), () => close(true), 'primary')]);
const formData = (form) => Object.fromEntries(new FormData(form).entries());

async function loadState() {
  const j = await (await fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{"action":"state"}' })).json()
    .catch(() => ({ error: 'internal' }));
  if (j.error) throw new ApiError(j.error);   // the server cannot start (data folder, HTTPS, IP not allowed...)
  S = j;
  checkBuild(j.build);
  applyLang();
  sessionState();
}

// ------------------------------------------------------------------ the web session ending

// The gateway closes a session after its idle time-out or 12 hours, but the page only learnt it at its next request, and
// then showed the sign-in screen with no word. Now, when the session should be over, the page asks the gateway (a question
// that is not activity, or it would keep the session open: another window may have used it meanwhile) and, closed, says so
// in a window; any request refused for it does the same. OK takes to the sign-in screen, and back here once signed in.
let lastSeen = 0, sessionEnds = 0, expiryTimer = 0, ending = false;
let currentRdp = null, forgetRdp = null;
/** Ends this page's remote session (its view left, signed out, or another one opened) with what its view added to the window. */
function endRdp() {
  currentRdp?.stop();
  currentRdp?.leave?.abort();
  forgetRdp?.();
  currentRdp = forgetRdp = null;
}                 // the remote session of this page, in any state, and how to forget its secrets
// the windows of the other screens of that session: they close with this page (its tab closed or reloaded), as they do
// when the session ends. Before, closing the tab left them open, showing nothing
let screenWindows = [];
addEventListener('pagehide', () => screenWindows.splice(0).forEach((w) => { try { w.close(); } catch { /* already gone */ } }));
/** An error that only says the session has ended (its window says so: no notice beside it). */
const gone = (e) => ending || (e instanceof ApiError && (e.message === 'auth' || e.message === 'csrf'));
/** The other tabs of this browser: signed out in one, the others end their remote sessions and say so. */
const tabs = typeof BroadcastChannel === 'function' ? new BroadcastChannel('mudesk') : null;
if (tabs) tabs.onmessage = (e) => { if (e.data === 'logout' && S?.user) sessionEnded('logout'); };
/**
 * Signed out or the session ended: the remote session of this page ends whatever its state (connecting, reconnecting), and
 * what the account left in the page goes: uploads still queued (they would land in the next account's folder), the folder
 * tree, a drag, the user name of the sign-in form, the remote password typed for the session.
 */
function signedOut() {
  clearTimeout(expiryTimer);
  endRdp();
  UP.jobs = [];
  UP.ctrl?.abort();
  Object.assign(FX, { open: new Set(['']), kids: new Map(), reload: null, root: null });
  fxDrag = null;
  loginName = '';
}
/** The gateway answered a request of this page: the idle time-out starts again. */
function touched() {
  lastSeen = Date.now();
  watchSession();
}
/** A new state from the gateway (signed in or not): when its session ends at the latest. */
function sessionState() {
  lastSeen = Date.now();
  sessionEnds = S?.session ? lastSeen + S.session.max * 1000 : 0;
  watchSession();
}
/** Asks the gateway when the session should end, if it has not ended before. */
function watchSession() {
  clearTimeout(expiryTimer);
  if (!S?.user || !S.session || ending) return;
  expiryTimer = setTimeout(checkSession, Math.max(1000, Math.min(lastSeen + S.session.idle * 1000, sessionEnds) - Date.now() + 2000));
}
async function checkSession() {
  const later = (ms) => { clearTimeout(expiryTimer); expiryTimer = setTimeout(checkSession, ms); };   // (one chain only)
  if (!S?.user || ending) return;
  // (also with a remote session connected here: the web session ends it; an active one keeps the web session open)
  const r = await fetch('api.php', { method: 'POST', cache: 'no-store', headers: { 'Content-Type': 'application/json', 'X-CSRF': S.csrf }, body: '{"action":"session.left"}' })
    .then((x) => x.json(), () => null).catch(() => null);
  if (r?.error === 'auth' || r?.error === 'csrf') return sessionEnded(r.reason || (Date.now() >= sessionEnds ? 'max_duration' : 'idle'));
  if (!r || r.error || !(r.left > 0)) return later(60000);   // the gateway out of reach: asked again later
  later(r.left * 1000 + 2000);                            // still open (used from another window): when it would end now
}
/** The session has ended: a window says why; OK (or closing it) shows the sign-in screen. */
async function sessionEnded(reason) {
  if (ending) return;
  ending = true;
  signedOut();
  closeMenu();
  navigator.keyboard?.unlock?.();
  if (document.fullscreenElement) await document.exitFullscreen?.().catch(() => {});   // the window must be seen
  const why = { idle: t('session_idle', Math.round((S?.session?.idle || 1800) / 60)), max_duration: t('session_max'),
    password_changed: t('session_password'), user_disabled: t('session_disabled'), user_deleted: t('session_disabled'),
    other_browser: t('session_security'), logout: t('session_logout'), reauth: t('session_reauth') }[reason] || t('session_ended');
  await modal(t('session_title'), h('p', {}, why), (close) => [btn(t('accept'), () => close(true), 'primary')]);
  document.body.querySelectorAll('.modal-back').forEach((m) => m.remove());   // what was open behind it
  ending = false;
  await loadState().catch(() => { S = { ...S, user: null }; });
  render();
}
/** This tab's storage, or nothing when the browser blocks it (it threw and the page did not start). */
const tabPref = (k, v) => { try { return v === undefined ? sessionStorage.getItem(k) : sessionStorage.setItem(k, v); } catch { return null; } };
/** The profile's language, else the one picked on the sign-in screen, else the browser's. */
const applyLang = () => setLang(S.user?.lang || tabPref('mudesk-lang') || browserLang());

// ------------------------------------------------------------------ routing

window.addEventListener('hashchange', () => render());

function route() {
  const parts = location.hash.replace(/^#\/?/, '').split('/');
  return { view: parts[0] || 'conns', arg: parts.slice(1).join('/') };
}

// Is the data folder readable from the web (e.g. .htaccess ignored)? Checked once, warned to the administrator.
// (once per browser session: the browser logs the expected 403/404 answers in its console)
let exposed = null;
function dataExposed() {
  const known = tabPref('mudesk-data-check');
  exposed ??= known !== null ? Promise.resolve(known === '1') : Promise.all(['../data/web-check.txt', 'data/web-check.txt'].map((u) =>
    fetch(new URL(u, location.href), { cache: 'no-store', credentials: 'omit' })
      .then(async (r) => r.ok && (await r.text()).startsWith('MuDeskClient data folder'), () => false)))
    .then((a) => { const bad = a.includes(true); tabPref('mudesk-data-check', bad ? '1' : '0'); return bad; });
  return exposed;
}
function warnExposed(where) {
  dataExposed().then((bad) => { if (bad && where.isConnected) where.prepend(h('p', { class: 'error banner' }, t('data_exposed'))); });
}

/** The administrator requires a second factor and the user has none yet: only the profile, to add one. */
const needSecond = () => S.user.totp_required && !S.user.totp && !S.user.passkeys.length;

function render() {
  const r = route();
  if (!S) return;
  // the session's view left by its address (Back, a link, a typed address): the remote session ends, instead of staying
  // connected where nobody sees it until the next session or signing out
  if (r.view !== 's' && currentRdp) endRdp();
  if (S.setup) { authScreen(viewSetup()); return warnExposed(app.querySelector('.auth')); }
  if (!S.user) return authScreen(S.second ? viewSecond() : viewLogin());
  if (S.user.must_change) return authScreen(viewForcePassword());
  if (r.view === 's' && !needSecond()) return viewSession(r.arg);
  const views = { conns: viewConns, files: viewFiles, profile: viewProfile, admin: viewAdmin };
  const view = needSecond() ? 'profile' : (views[r.view] ? r.view : 'conns');
  layout(view, views[view](r.arg));
}

function authScreen(content) {
  fill(app, h('div', { class: 'center' }, h('div', { class: 'auth' },
    h('div', { class: 'brand' }, h('img', { src: 'assets/icon.svg', alt: '' }), t('app')), content)));
  app.querySelector('input')?.focus();
}

function layout(active, content) {
  const nav = [['conns', t('nav_conns')], ['files', t('nav_files')], ['profile', t('nav_profile')]];
  if (S.user.role === 'admin') nav.push(['admin', t('nav_admin')]);
  const admin = S.user.role === 'admin' && active === 'admin';
  const langSel = select('lang', LANGS, getLang());              // saved in the profile, like the choice made there
  Object.assign(langSel, { title: t('language'), ariaLabel: t('language') });
  langSel.addEventListener('change', () => attempt(async () => { await api('profile.lang', { lang: langSel.value }); setLang(langSel.value); render(); }));
  fill(app,
    h('header', { class: 'top' },
      h('a', { class: 'brand', href: '#/conns' }, h('img', { src: 'assets/icon.svg', alt: '' }), t('app')),
      h('nav', {}, nav.map(([k, l]) => h('a', { href: '#/' + k, class: k === active ? 'active' : '' }, l))),
      h('span', { class: 'spacer' }),
      langSel,
      h('span', { class: 'muted small' }, S.user.username),
      btn(t('logout'), () => attempt(async () => {
        signedOut();
        tabs?.postMessage('logout');                     // the other tabs of this browser: their remote sessions end too
        await api('logout');
        location.hash = '';
        render();
      }), 'small')),
    h('main', { class: 'page' }, previousNotice(), admin && S.user.targets_all && h('p', { class: 'warn banner' }, t('targets_all_warn')), content),
    h('footer', { class: 'project small' }, h('a', { href: 'https://miguelenred.es/mudeskclient', target: '_blank', rel: 'noopener' }, 'miguelenred.es/mudeskclient')));
  if (admin) warnExposed(app.querySelector('main'));
}

/** After signing in, until closed: the previous sign-in and the failed attempts with this user name since then. */
function previousNotice() {
  const p = S.previous;
  if (!p || (!p.login && !p.failures)) return null;
  const box = h('div', { class: 'banner row ' + (p.failures ? 'warn' : 'muted'), role: 'status' },
    h('span', { class: 'spacer' }, p.login ? t('prev_login', fmtDate(p.login), p.ip || '-') : '',
      p.failures ? [' ', t(p.partial ? 'prev_failures_partial' : p.failures === 1 ? 'prev_failure' : 'prev_failures', p.failures, fmtDate(p.failed.t), p.failed.ip)] : ''),
    btn('', () => { S.previous = null; box.remove(); }, 'small', 'x', t('close')));
  return box;
}

// ------------------------------------------------------------------ auth views

function viewSetup() {
  const err = errBox();
  const form = h('form', {
    onsubmit: (e) => {
      e.preventDefault();
      const d = formData(form);
      if (d.password !== d.password2) { err.textContent = t('pw_mismatch'); return; }
      attempt(async () => { await api('setup', { setup_code: d.setup_code, username: d.username, password: d.password }); render(); }, err);
    },
  },
  h('h1', {}, t('setup_title')), h('p', { class: 'muted' }, t('setup_text')),
  field(t('setup_code'), h('input', { name: 'setup_code', required: true, autocomplete: 'off', spellcheck: 'false' }), t('setup_code_help')),
  field(t('username'), h('input', { name: 'username', required: true, autocomplete: 'username' })),
  field(t('password'), h('input', { name: 'password', type: 'password', required: true, minlength: 12, autocomplete: 'new-password' }), t('pw_rule')),
  field(t('repeat_password'), h('input', { name: 'password2', type: 'password', required: true, autocomplete: 'new-password' })),
  err, h('button', { class: 'btn primary', style: 'width:100%' }, t('create_admin')));
  return form;
}

// ---- passkeys (WebAuthn): options and results travel as base64url ----
const hasPasskeys = !!window.PublicKeyCredential;
const b64u = (buf) => btoa(String.fromCharCode(...new Uint8Array(buf))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
const unb64u = (s) => Uint8Array.from(atob(s.replace(/-/g, '+').replace(/_/g, '/')), (c) => c.charCodeAt(0));

/** navigator.credentials.get with server options; returns the fields the server verifies (prf when available). */
async function passkeyGet(o) {
  const c = await navigator.credentials.get({ publicKey: {
    ...o, challenge: unb64u(o.challenge), allowCredentials: o.allowCredentials.map((x) => ({ ...x, id: unb64u(x.id) })),
    extensions: { prf: { eval: { first: unb64u(o.extensions.prf.eval.first) } } },
  } });
  const prf = c.getClientExtensionResults().prf?.results?.first;
  return { id: c.id, clientData: b64u(c.response.clientDataJSON), authData: b64u(c.response.authenticatorData),
    signature: b64u(c.response.signature), userHandle: c.response.userHandle ? b64u(c.response.userHandle) : '', prf: prf ? b64u(prf) : '' };
}

async function passkeyCreate(o) {
  const c = await navigator.credentials.create({ publicKey: {
    ...o, challenge: unb64u(o.challenge), user: { ...o.user, id: unb64u(o.user.id) },
    excludeCredentials: o.excludeCredentials.map((x) => ({ ...x, id: unb64u(x.id) })),
  } });
  return { clientData: b64u(c.response.clientDataJSON), attestation: b64u(c.response.attestationObject) };
}

/** Sign-in with a passkey: second step, or passwordless when no password step is pending. */
async function passkeySignIn() {
  const { options } = await api('passkey.begin');
  await api('passkey.login', await passkeyGet(options));
  render();
}

function viewLogin() {
  const err = errBox();
  const form = h('form', {
    onsubmit: (e) => {
      e.preventDefault();
      const d = formData(form);
      loginName = d.username;
      attempt(async () => { await api('login', d); render(); }, err);
    },
  },
  field(t('username'), h('input', { name: 'username', required: true, autocomplete: 'username', value: loginName })),
  field(t('password'), h('input', { name: 'password', type: 'password', required: true, autocomplete: 'current-password' })),
  err, h('button', { class: 'btn primary', style: 'width:100%' }, t('login')),
  hasPasskeys && h('button', { type: 'button', class: 'btn', style: 'width:100%;margin-top:.6rem', onclick: () => attempt(passkeySignIn, err) }, t('passkey_signin')),
  h('div', { class: 'row end', style: 'margin-top:1rem' }, select('lang', LANGS, getLang())));
  form.lang.addEventListener('change', () => { tabPref('mudesk-lang', form.lang.value); setLang(form.lang.value); render(); });
  return form;
}

function viewSecond() {
  const err = errBox();
  const form = h('form', {
    onsubmit: (e) => {
      e.preventDefault();
      const code = form.code.value.trim();
      form.code.value = '';                                // a code works once: the next attempt takes a new one
      attempt(async () => { await api('login', { username: loginName, code }); render(); }, err).then(() => form.code.focus());
    },
  },
  h('h2', {}, t('totp_title')), h('p', { class: 'muted' }, t(S.second.totp ? 'totp_text' : 'passkey_text')),
  S.second.totp && field(t('code'), h('input', { name: 'code', required: true, inputmode: 'numeric', autocomplete: 'one-time-code', pattern: '[0-9]{6}', maxlength: 6 })),
  S.second.passkey && hasPasskeys && h('button', { type: 'button', class: 'btn', style: 'width:100%;margin-bottom:.6rem', onclick: () => attempt(passkeySignIn, err) }, t('passkey_use')),
  err, h('div', { class: 'row' }, btn(t('back'), () => attempt(async () => { await api('logout'); render(); })),
    h('span', { class: 'spacer' }), S.second.totp && h('button', { class: 'btn primary' }, t('verify'))));
  return form;
}

function passwordForm(onDone, intro = null) {
  const err = errBox();
  const form = h('form', {
    onsubmit: (e) => {
      e.preventDefault();
      const d = formData(form);
      if (d.new !== d.new2) { err.textContent = t('pw_mismatch'); return; }
      attempt(async () => { await api('profile.password', { old: d.old, new: d.new }); form.reset(); toast(t('saved')); onDone(); }, err);
    },
  },
  intro,
  field(t('current_password'), h('input', { name: 'old', type: 'password', required: true, autocomplete: 'current-password' })),
  field(t('new_password'), h('input', { name: 'new', type: 'password', required: true, minlength: 12, autocomplete: 'new-password' }), t('pw_rule')),
  field(t('repeat_password'), h('input', { name: 'new2', type: 'password', required: true, autocomplete: 'new-password' })),
  err, h('button', { class: 'btn primary' }, t('change_password')));
  return form;
}

const viewForcePassword = () => passwordForm(render, h('p', {}, t('must_change')));

// ------------------------------------------------------------------ connections

function viewConns() {
  const box = h('div', {}, h('p', { class: 'muted' }, t('loading')));
  const search = h('input', { type: 'search', placeholder: t('search') });
  let data = null;
  const draw = () => {
    const q = search.value.trim().toLowerCase();
    const list = data.conns.filter((c) => !q || `${c.name} ${c.host} ${c.group} ${c.user}`.toLowerCase().includes(q));
    if (!data.conns.length) return fill(box, h('div', { class: 'card muted' }, t('no_conns')));
    const groups = new Map();
    list.forEach((c) => { const g = c.group || ''; if (!groups.has(g)) groups.set(g, []); groups.get(g).push(c); });
    fill(box, ...[...groups].map(([g, items]) => h('section', { class: 'group' },
      h('h3', {}, g || t('ungrouped')),
      h('div', { class: 'grid' }, items.map((c) => h('div', { class: 'card conn' },
        h('div', { class: 'name' }, icon('monitor'), c.name, c.managed && h('span', { class: 'badge', title: t('managed_hint') }, icon('lock'), t('managed_badge'))),
        h('div', { class: 'muted small mono' }, (c.user ? (c.domain ? c.domain + '\\' : '') + c.user + ' @ ' : '') + c.host + (c.port !== 3389 ? ':' + c.port : '')),
        c.app && h('div', { class: 'small app-line', title: t('remote_app') }, h('span', { class: 'badge' }, 'RemoteApp'), ' ', h('span', { class: 'mono' }, appName(c.app))),
        h('div', { class: 'row' },
          h('a', { class: 'btn primary small', href: '#/s/' + c.id, target: '_blank', rel: 'noopener' }, icon('play'), t('connect')),
          h('span', { class: 'spacer' }),
          btn('', () => exportRdp(c), 'small', 'download', t('export_rdp')),
          btn('', () => editConn(c, data.keyboards, { locked: c.managed }).then((ok) => ok && load()), 'small', 'edit', t(c.managed ? 'managed_edit' : 'edit')),
          !c.managed && btn('', () => confirmDialog(t('confirm_delete', c.name)).then((ok) => ok && attempt(async () => { await api('conns.delete', { id: c.id }); load(); })), 'small danger', 'trash', t('delete')))))))));
  };
  const load = () => attempt(async () => { data = await api('conns.list'); draw(); });
  search.addEventListener('input', () => data && draw());
  load();
  const importer = rdpPicker((files) => data && importRdp(files, (c, notes) => editConn(c, data.keyboards, { notes }),
    (conn) => api('conns.save', { conn })).then(load));
  return h('div', {},
    h('div', { class: 'toolbar' }, h('h1', { style: 'margin:0' }, t('nav_conns')), h('span', { class: 'spacer' }), search,
      btn(t('import_rdp'), () => importer.click(), '', 'upload', t('import_rdp_hint')), importer,
      btn(t('new_conn'), () => data && editConn(null, data.keyboards).then((ok) => ok && load()), 'primary', 'plus')),
    box);
}

/** A RemoteApp program as people write it: a published one by its name, without the two bars RDP puts in front ("||Name"). */
const appName = (a) => String(a || '').replace(/^\|\|/, '');
// ------------------------------------------------------------------ .rdp files (Remote Desktop Connection)

/** A hidden file field for .rdp files; onFiles(files) once some are chosen. */
const rdpPicker = (onFiles) => {
  const input = h('input', { type: 'file', accept: '.rdp', multiple: true, class: 'hidden' });
  input.addEventListener('change', () => { const files = [...input.files]; input.value = ''; if (files.length) onFiles(files); });
  return input;
};
/**
 * .rdp files chosen: one is opened in the connection editor, filled in, to check and save (with what MuDesk does not take,
 * such as RemoteApp or a gateway, said there); several are saved at once and the result said. Resolves when done.
 */
async function importRdp(files, edit, save) {
  const read = [];
  for (const f of files) {
    try {
      if (f.size > RDP_MAX) throw new Error('rdp_too_big');
      read.push({ file: f.name, ...parseRdp(decodeRdp(new Uint8Array(await f.arrayBuffer())), f.name) });
    } catch (e) { toast(`${f.name}: ${errText(e.message)}`, true); }
  }
  if (read.length === 1 && files.length === 1) return edit(read[0].conn, read[0].notes);
  let ok = 0;
  for (const r of read) {
    try {
      await save(r.conn);
      ok++;
      r.notes.forEach(([k, v]) => toast(`${r.file}: ${t(k, v)}`, false, 8000));
    } catch (e) { toast(`${r.file}: ${errText(e.message)}`, true); }
  }
  if (ok) toast(t('rdp_imported', ok));
}
/** The connection as a .rdp file for Remote Desktop Connection (without its password: Windows keeps that its own way). */
const exportRdp = (c) => saveBlob(new Blob([encodeRdp(toRdp(c))], { type: 'application/x-rdp' }), (c.name || c.host).replace(/[\\/:*?"<>|]+/g, '_') + '.rdp');

const KB_NAMES = {
  0x040A: 'Español', 0x0409: 'English (US)', 0x0809: 'English (UK)', 0x041D: 'Svenska', 0x040C: 'Français', 0x0407: 'Deutsch',
  0x0410: 'Italiano', 0x0816: 'Português', 0x0416: 'Português (Brasil)', 0x0414: 'Norsk', 0x0406: 'Dansk', 0x040B: 'Suomi',
  0x0413: 'Nederlands', 0x0419: 'Русский', 0x080C: 'Français (Belgique)', 0x0807: 'Deutsch (Schweiz)', 0x100C: 'Français (Suisse)',
  0x0C0C: 'Français (Canada)', 0x080A: 'Español (Latinoamérica)',
};

/** Graphics modes of a connection (src/Connections.php gfxMode): automatic by server, graphics pipeline, bitmaps. */
const GFX_MODES = () => ({ auto: t('gfx_auto'), on: t('gfx_on'), off: t('gfx_off') });
/** Phone keyboard through the clipboard (see Cliprdr::typeText): 'auto' turns it on for touch screens without a mouse. */
const PASTE_MODES = () => ({ auto: t('paste_auto'), on: t('paste_on'), off: t('paste_off') });
const touchOnly = () => matchMedia('(pointer: coarse)').matches && !matchMedia('(any-pointer: fine)').matches;
/** Chrome, Edge: the Window Management API lists the screens. Firefox and Safari: the other screen is looked for (probeScreen). */
const hasScreenApi = () => 'getScreenDetails' in window;
const OTHER_SCREEN = 'mudesk-other-screen';             // localStorage: the other screen found before in this browser
const BAR_X = 'mudesk-bar-x';                            // localStorage: where the session toolbar was left (its centre, 0..1)
const pasteFor = (mode) => mode === 'on' || (mode !== 'off' && touchOnly());
const PASTE_SOURCES = ['https://github.com/Garnet-Owl/nemo-voice-typing/issues/4', 'https://github.com/JohnJohn4/dictatem/issues/278',
  'https://github.com/thtbee/nectarlink/pull/288', 'https://github.com/mohamedmoheyeldin/relay/pull/20',
  'https://github.com/kihyun1998/justrdp/pull/532', 'https://learn.microsoft.com/windows-app/input-keyboard-mouse-touch-pen'];
/** Explains the option: the Windows 11 bug, the tests that found it and the sources (docs/es/TECLADO-MOVIL.md). */
function pasteInfo() {
  const sec = (k) => [h('h3', {}, t(k + '_h')), h('p', {}, t(k))];
  modal(t('paste_typing'), h('div', { class: 'info-text' },
    h('p', {}, t('paste_info_problem')), sec('paste_info_cause'), sec('paste_info_what'),
    sec('paste_info_tests'), h('table', { class: 'list' }, t('paste_info_rows').map(([a, b]) => h('tr', {}, h('td', {}, a), h('td', {}, b)))),
    h('p', {}, t('paste_info_tip')),
    h('h3', {}, t('paste_info_sources_h')), h('ul', {}, t('paste_info_sources').map((l, i) =>
      h('li', {}, h('a', { href: PASTE_SOURCES[i], target: '_blank', rel: 'noopener noreferrer' }, l))))),
    (close) => [btn(t('close'), () => close(true), 'primary')]);
}
/** Small button that opens an explanation (kept out of the label it sits next to). */
const infoBtn = (fn, title = t('paste_info')) => h('button', { type: 'button', class: 'info', title, 'aria-label': title,
  onclick: (e) => { e.preventDefault(); e.stopPropagation(); fn(); } }, icon('info'));
/** Commands of the explanations, the same in every language of Windows. */
const MIC_REG = 'reg add "HKLM\\SOFTWARE\\Policies\\Microsoft\\Windows NT\\Terminal Services" /v fDisableAudioCapture /t REG_DWORD /d 0 /f';
const MIC_AUDIO_SERVICE = 'Set-Service Audiosrv -StartupType Automatic; Start-Service Audiosrv';
/** What the microphone needs: the browser's permission and, on the remote Windows, audio recording allowed. */
function micInfo() {
  const cmd = (c) => h('pre', { class: 'cmd' }, c);
  modal(t('mic'), h('div', { class: 'info-text' },
    h('h3', {}, t('mic_info_browser_h')), h('p', {}, t('mic_info_browser')),
    h('h3', {}, t('mic_info_server_h')), h('p', {}, t('mic_info_server')),
    h('h3', {}, t('mic_info_gpo_h')), h('ol', {}, t('mic_info_gpo').map((s) => h('li', {}, s))),
    h('h3', {}, t('mic_info_reg_h')), h('p', {}, t('mic_info_reg')), cmd(MIC_REG),
    h('p', {}, t('mic_info_service')), cmd(MIC_AUDIO_SERVICE),
    h('p', {}, t('mic_info_after'))),
    (close) => [btn(t('close'), () => close(true), 'primary')]);
}
/** Several screens: what this browser asks (the Window Management permission, or pop-ups allowed), then the others. */
function screensInfo() {
  const [here, other] = hasScreenApi() ? ['screens_info_api', 'screens_info_popup'] : ['screens_info_popup', 'screens_info_api'];
  modal(t('all_screens'), h('div', { class: 'info-text' },
    h('h3', {}, t('screens_info_this_h')), h('p', {}, t(here)),
    h('h3', {}, t('screens_info_other_h')), h('p', {}, t(other)),
    h('p', {}, t('screens_info_general'))),
    (close) => [btn(t('close'), () => close(true), 'primary')]);
}

/**
 * The connection editor. opts: notes (what an imported .rdp file has that MuDesk does not take: [i18n key, value]); locked
 * (a connection of the administrator seen by its user: only their own password can be saved or forgotten); admin ({users,
 * assigned}: a connection of the administrator, given to some users or all, without a password: each user has theirs);
 * owner (a user's own connection changed by the administrator: their password can only be forgotten); save(conn, extra).
 */
function editConn(c, keyboards, opts = {}) {
  const { admin, owner } = opts, locked = !!opts.locked;
  c = c || { port: 3389, kbd: 0x040A, bpp: 16, gfx: 'auto', security: 'auto', resize: 'dynamic', clipboard: true, paste: 'auto', drive: true, audio: true, mic: false, printer: true, ask: true, perf: { smoothing: true } };
  const err = errBox();
  const kb = Object.fromEntries(keyboards.map((k) => [k, KB_NAMES[k] || '0x' + k.toString(16)]));
  const form = h('form', { onsubmit: (e) => e.preventDefault() },
    opts.notes?.length > 0 && h('div', { class: 'notice small' }, opts.notes.map(([k, v]) => h('p', {}, t(k, v)))),
    locked && h('div', { class: 'notice small' }, h('p', {}, t('managed_hint'))),
    h('div', { class: 'cols' },
      field(t('conn_name'), h('input', { name: 'name', value: c.name || '', maxlength: 80 })),
      field(t('conn_group'), h('input', { name: 'group', value: c.group || '', maxlength: 60 })),
      field(t('host'), h('input', { name: 'host', value: c.host || '', required: true })),
      field(t('port'), h('input', { name: 'port', type: 'number', min: 1, max: 65535, value: c.port })),
      field(t('rdp_user'), h('input', { name: 'user', value: c.user || '', autocomplete: 'off', placeholder: t('user_forms') })),
      field(t('domain'), h('input', { name: 'domain', value: c.domain || '', autocomplete: 'off' }))),
    // RemoteApp: unfolded for a connection with a program, folded for a desktop one; the user folds or unfolds it
    h('details', { class: 'rail-fields', open: !!c.app }, h('summary', {}, t('remote_app_section')),
      h('div', { class: 'cols' },
        field(t('remote_app'), h('input', { name: 'app', value: appName(c.app), maxlength: 260, autocomplete: 'off', placeholder: t('app_placeholder') }), t('app_hint')),
        field(t('app_args'), h('input', { name: 'app_args', value: c.app_args || '', maxlength: 1000, autocomplete: 'off' })))),
    admin || owner ? h('p', { class: 'small muted' }, t(admin ? 'admin_pw_note' : 'owner_pw_note'))
      : S.user.save_passwords ? field(t('rdp_password'), h('input', { name: 'password', type: 'password', autocomplete: 'new-password', placeholder: c.has_pass ? t('pw_saved') : t('pw_none') }), t('pw_warn'))
      : h('p', { class: 'small muted' }, t('pw_not_allowed')),
    c.has_pass && check(t('forget_pw'), { name: 'forget' }),
    admin && usersPick(admin.users, admin.assigned),
    h('details', {}, h('summary', {}, t('advanced')),
      h('div', { class: 'cols' },
        field(t('keyboard'), select('kbd', kb, c.kbd)),
        field(t('color'), select('bpp', { 16: t('bpp16'), 15: t('bpp15'), 24: t('bpp24'), 32: t('bpp32') }, c.bpp)),
        field(t('gfx'), select('gfx', GFX_MODES(), c.gfx)),
        field(t('security'), select('security', { auto: t('sec_auto'), nla: t('sec_nla'), tls: t('sec_tls') }, c.security)),
        field(t('resize'), select('resize', { dynamic: t('resize_dynamic'), fit: t('resize_fit') }, c.resize))),
      check(t('clipboard'), { name: 'clipboard', checked: c.clipboard }),
      field([t('paste_typing'), ' ', infoBtn(pasteInfo)], select('paste', PASTE_MODES(), c.paste || 'auto'), t('paste_hint')),
      check(t('drive'), { name: 'drive', checked: c.drive }),
      check(t('audio'), { name: 'audio', checked: c.audio }),
      check(t('mic'), { name: 'mic', checked: !!c.mic }),
      check(t('printer'), { name: 'printer', checked: c.printer }),
      check(t('ask'), { name: 'ask', checked: c.ask }),
      h('p', { class: 'muted small', style: 'margin:.8rem 0 .4rem' }, t('perf')),
      h('div', { class: 'cols' }, ['wallpaper', 'themes', 'smoothing', 'animations', 'drag'].map((k) => check(t('perf_' + k), { name: 'perf_' + k, checked: c.perf?.[k] }))),
      c.id && !admin && !owner && field(t('cert'), h('div', { class: 'row' }, h('span', { class: 'mono small' }, c.cert || t('cert_none')),
        c.cert && btn(t('forget_cert'), (e) => attempt(async () => { await api('conns.forget_cert', { id: c.id }); e.target.closest('.row').firstChild.textContent = t('cert_none'); }), 'small')))),
    err);
  if (locked) form.querySelectorAll('input,select').forEach((el) => { if (!['password', 'forget'].includes(el.getAttribute('name'))) el.disabled = true; });
  let saving = false;
  return modal((c.name || t('new_conn')) + (owner ? ' · ' + owner : ''), form, (close) => [
    btn(t('cancel'), () => close(false)),
    btn(t('save'), () => {
      if (saving || !form.reportValidity()) return;      // a double click saved a new connection twice
      const d = formData(form);
      const once = (f) => { saving = true; attempt(async () => { try { await f(); } finally { saving = false; } }, err); };
      if (locked) {                                       // the administrator's: only the user's own password
        const password = d.password || (d.forget ? '' : null);
        if (password === null) { close(false); return; }
        once(async () => { await api('conns.save', { conn: { id: c.id, password } }); toast(t('saved')); close(true); });
        return;
      }
      const conn = {
        id: c.id, name: d.name, group: d.group, host: d.host, port: +d.port, user: d.user, domain: d.domain, kbd: +d.kbd, bpp: +d.bpp,
        security: d.security, resize: d.resize, clipboard: !!d.clipboard, paste: d.paste, drive: !!d.drive, audio: !!d.audio, mic: !!d.mic, printer: !!d.printer, gfx: d.gfx, ask: !!d.ask,
        app: d.app || '', app_args: d.app_args || '',
        perf: Object.fromEntries(['wallpaper', 'themes', 'smoothing', 'animations', 'drag'].map((k) => [k, !!d['perf_' + k]])),
      };
      if (d.password) conn.password = d.password;
      else if (d.forget && !owner) conn.password = '';
      const users = admin && (d.all_users ? ['*'] : admin.users.filter((u) => d['u_' + u.id]).map((u) => u.id));
      const save = opts.save ? () => opts.save(conn, admin ? users : !!d.forget) : () => api('conns.save', { conn });
      once(async () => { await save(); toast(t('saved')); close(true); });
    }, 'primary'),
  ]);
}
/** Who sees a connection of the administrator: every user (also those created later), or the ones ticked. */
function usersPick(users, assigned) {
  const all = h('input', { type: 'checkbox', name: 'all_users', checked: assigned.includes('*') });
  const list = h('div', { class: 'cols users-pick' }, users.map((u) => check(u.username, { name: 'u_' + u.id, checked: assigned.includes(u.id) })));
  const sync = () => list.querySelectorAll('input').forEach((i) => { i.disabled = all.checked; });
  all.addEventListener('change', sync);
  sync();
  return h('div', { class: 'field' }, h('span', {}, t('conn_users')), h('label', { class: 'check' }, all, t('all_users')), list);
}

// ------------------------------------------------------------------ shared folder: a file explorer

async function filesApi(a, p, opts = {}) {
  const r = await fetch(`files.php?a=${a}&p=${encodeURIComponent(p)}${opts.q || ''}`, {
    method: 'POST', headers: { 'X-CSRF': S.csrf, 'Content-Type': 'application/octet-stream' }, body: opts.body, signal: opts.signal,
  });
  const j = await r.json().catch(() => ({ error: 'internal' }));
  if ((j.error === 'auth' || j.error === 'csrf') && S?.user) sessionEnded(j.reason);
  if (j.error) throw new ApiError(j.error);
  touched();
  return j;
}

/** Uploads a File to the shared folder as path, in chunks of 2 MB; progress(bytes of it sent so far); started() right before
 * its first request goes (from then on the gateway may have it, even if that request is cancelled). */
async function uploadFile(path, file, progress, signal, started) {
  const chunk = 2 * 1048576;
  for (let o = 0; ; o += chunk) {
    const last = o + chunk >= file.size;
    if (o === 0) {
      if (signal?.aborted) throw new DOMException('cancelled', 'AbortError');   // (nothing sent: a file it replaces stays)
      started?.();
    }
    await filesApi('upload', path, { q: `&o=${o}${last ? '&last=1' : ''}`, body: file.slice(o, o + chunk), signal });
    progress?.(Math.min(file.size, o + chunk));
    if (last) break;
  }
}

// kept while the page changes folder; kids: the folders of each folder of the tree read so far (only open ones are read, as
// a file explorer does: walking every folder took seconds with many files)
const FX = { open: new Set(['']), kids: new Map(), dir: '', root: null, reload: null, last: 0 };
const DRAG_TYPE = 'application/x-mudesk-files';          // items of the list being dragged (to a folder: moved)
let fxDrag = null;                                       // what is being dragged from the list: { from, names, key }
/** This browser's key for drags between windows of the application (another web page cannot know it). */
const dragKey = () => {
  try {
    let k = localStorage.getItem('mudesk-drag-key');
    if (!k) localStorage.setItem('mudesk-drag-key', k = [...crypto.getRandomValues(new Uint8Array(16))].map((b) => b.toString(16).padStart(2, '0')).join(''));
    return k;
  } catch { return ''; }
};
const pref = (k, v) => { try { return v === undefined ? localStorage.getItem(k) : localStorage.setItem(k, v); } catch { return null; } };
const joinPath = (d, n) => (d ? d + '/' : '') + n;
const parentOf = (p) => p.slice(0, Math.max(0, p.lastIndexOf('/')));
const baseName = (p) => p.slice(p.lastIndexOf('/') + 1);
const filesHref = (p) => '#/files/' + encodeURIComponent(p);
const fileUrl = (p, more = '') => `files.php?a=download&p=${encodeURIComponent(p)}${more}`;
const zipQuery = (names) => names.map((n) => '&n[]=' + encodeURIComponent(n)).join('');
const zipUrl = (d, names) => `files.php?a=zip&p=${encodeURIComponent(d)}${zipQuery(names)}`;
const extOf = (n) => { const i = n.lastIndexOf('.'); return i > 0 ? n.slice(i + 1).toLowerCase() : ''; };
const norm = (s) => s.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();   // "Ácido" found by "acido"
const KINDS = Object.entries({
  image: 'png jpg jpeg gif webp bmp svg avif ico tif tiff heic', pdf: 'pdf', doc: 'doc docx odt rtf', sheet: 'xls xlsx ods csv',
  slides: 'ppt pptx odp', archive: 'zip rar 7z gz tgz tar bz2 xz', audio: 'mp3 wav ogg flac m4a aac wma', video: 'mp4 mkv avi mov wmv webm',
  code: 'js ts php py html htm css json xml sh bat cmd ps1 c cpp h cs java sql yml yaml ini', text: 'txt md log', exe: 'exe msi dll',
}).map(([k, v]) => [k, v.split(' ')]);
const kindOf = (f) => (f.dir ? 'folder' : KINDS.find(([, e]) => e.includes(extOf(f.name)))?.[0] || 'file');
const THUMB = 'png jpg jpeg gif webp bmp avif'.split(' ');   // what files.php shows as an image (thumbnails, opened in a tab)
const svgEl = (tag, attrs, text = '') => {
  const el = document.createElementNS('http://www.w3.org/2000/svg', tag);
  for (const [k, v] of Object.entries(attrs)) el.setAttribute(k, v);
  if (text) el.textContent = text;
  return el;
};

/** A folder, or a page coloured by the type of file (with its extension when big). */
function fileIcon(f, big = false) {
  const s = svgEl('svg', { viewBox: '0 0 32 32', class: 'fico k-' + kindOf(f), 'aria-hidden': 'true' });
  if (f.dir) {
    s.append(svgEl('path', { class: 'back', d: 'M3 8a2 2 0 0 1 2-2h7l3 3h12a2 2 0 0 1 2 2v2H3z' }), svgEl('path', { class: 'front', d: 'M3 12h26v13a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z' }));
    return s;
  }
  const ext = extOf(f.name).slice(0, 4).toUpperCase();
  s.append(svgEl('path', { class: 'page', d: 'M8 3h11l7 7v17a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z' }), svgEl('path', { class: 'fold', d: 'M19 3v5a2 2 0 0 0 2 2h5' }),
    svgEl('rect', big ? { class: 'label', x: 3, y: 16, width: 22, height: 9, rx: 1.5 } : { class: 'label', x: 9, y: 18, width: 14, height: 4, rx: 1 }));
  if (big && ext) s.append(svgEl('text', { x: 14, y: 22.6, 'text-anchor': 'middle' }, ext));
  return s;
}

/** The files and folders dropped from the computer, walking the folders: { files: [{path, file}], dirs: [path] } (empty folders too). */
async function droppedItems(entries) {
  const files = [], dirs = [];
  const walk = async (entry, path) => {
    if (entry.isFile) files.push({ path, file: await new Promise((ok, ko) => entry.file(ok, ko)) });
    else if (entry.isDirectory) {
      dirs.push(path);
      const reader = entry.createReader();
      for (let batch; (batch = await new Promise((ok, ko) => reader.readEntries(ok, ko))).length;) {   // 100 at a time in Chrome
        for (const e of batch) await walk(e, path + '/' + e.name);
      }
    }
  };
  for (const e of entries) await walk(e, e.name);
  return { files, dirs };
}

/** The context menu: entries [label, icon, action, class] or '-' (a line); false ones left out. */
let menuEl = null;
const closeMenu = () => {
  const back = menuEl?.back;
  if (menuEl?.away) removeEventListener('pointerdown', menuEl.away, true);   // left behind, it closed the next menu
  menuEl?.onClose?.();
  menuEl?.remove();
  menuEl = null;
  if (back?.isConnected) back.focus();                 // the keyboard goes on where it was (it fell to the page)
};
function menu(x, y, entries, onClose = null) {
  closeMenu();
  const m = h('div', { class: 'fx-menu', role: 'menu' }, entries.filter(Boolean).map((e) => e === '-' ? h('hr', {})
    : h('button', { type: 'button', role: 'menuitem', class: e[3] || '', onclick: () => { closeMenu(); e[2](); } }, icon(e[1]), e[0])));
  m.addEventListener('keydown', (e) => {
    const b = [...m.querySelectorAll('button')], i = b.indexOf(document.activeElement);
    if (e.key === 'Escape' || e.key === 'Tab') { e.preventDefault(); closeMenu(); }
    else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); b[(i + (e.key === 'ArrowDown' ? 1 : b.length - 1)) % b.length]?.focus(); }
  });
  document.body.append(m);
  const r = m.getBoundingClientRect();                     // kept inside the window
  m.style.left = Math.max(4, Math.min(x, (window.innerWidth || 1e4) - r.width - 4)) + 'px';
  m.style.top = Math.max(4, Math.min(y, (window.innerHeight || 1e4) - r.height - 4)) + 'px';
  menuEl = Object.assign(m, { onClose, back: document.activeElement });
  const away = (e) => { if (!m.contains?.(e.target)) closeMenu(); if (menuEl !== m) removeEventListener('pointerdown', away, true); };
  m.away = away;
  setTimeout(() => { if (menuEl === m) addEventListener('pointerdown', away, true); });
  m.querySelector('button')?.focus();
}

// uploads: one after another, kept while the page changes folder (shown in the explorer's status bar)
const UP = { jobs: [], busy: false, files: 0, done: 0, bytes: 0, sent: 0, name: '', ctrl: null };
const leaving = (e) => { e.preventDefault(); e.returnValue = ''; };
function drawUp() {
  const box = document.querySelector('.fx-up');
  if (!box) return;
  if (!UP.busy) return fill(box);
  const pct = UP.bytes ? Math.min(100, (UP.sent / UP.bytes) * 100) : 100;
  fill(box, h('span', { class: 'name', title: UP.name }, t('fx_uploading', Math.min(UP.done + 1, UP.files), UP.files, UP.name)),
    h('span', { class: 'fx-meter', 'aria-hidden': 'true' }, h('span', { style: `width:${pct.toFixed(1)}%` })), Math.floor(pct) + ' %',
    btn('', () => UP.ctrl?.abort(), 'small', 'x', t('fx_cancel_upload')));
}
function queueUpload(target, files, dirs) {
  UP.jobs.push({ target, files, dirs });
  UP.files += files.length;
  UP.bytes += files.reduce((n, f) => n + f.file.size, 0);
  if (!UP.busy) runUploads();
  drawUp();
}
/** The explorer shows what changed: the folder being uploaded to at most once a second, everything when a job ends. */
const changed = (target, all = false) => {
  if (all || (target === FX.dir && performance.now() - FX.last > 1000)) { FX.last = performance.now(); FX.reload?.(all); }
};
async function runUploads() {
  Object.assign(UP, { busy: true, ctrl: new AbortController() });
  addEventListener('beforeunload', leaving);
  const failed = [];
  let stop = '', done = 0;
  while (UP.jobs.length && !stop) {
    const job = UP.jobs.shift();
    try {
      for (let i = 0; i < job.dirs.length; i += 500) {
        await filesApi('mkdirs', job.target, { body: JSON.stringify(job.dirs.slice(i, i + 500)), signal: UP.ctrl.signal });
      }
    } catch (e) {
      if (e.name === 'AbortError') stop = 'cancel';
      else if (gone(e)) stop = 'auth';
      else toast(e instanceof ApiError ? errText(e.message) : String(e.message || e), true);
    }
    for (const f of job.files) {
      if (stop) break;
      const path = joinPath(job.target, f.path), base = UP.sent;
      let started = false;                                 // from its first request: what reaches the gateway is removed on cancel
      UP.name = f.path;
      drawUp();
      try {
        await uploadFile(path, f.file, (n) => { UP.sent = base + n; drawUp(); }, UP.ctrl.signal, () => { started = true; });
        done++;
      } catch (e) {
        if (e.name === 'AbortError') {
          stop = 'cancel';
          if (started) filesApi('delete', path).catch(() => {});   // what arrived of it goes
        } else if (e.message === 'quota' || e.message === 'tmp_dir') stop = e.message;   // the next ones would fail the same way
        else if (e.message === 'auth' || e.message === 'csrf') stop = 'auth';   // the session ended: nothing more goes
        else failed.push(f.path);
      }
      UP.sent = base + f.file.size;
      UP.done++;
      changed(job.target);
    }
    changed(job.target, true);
  }
  removeEventListener('beforeunload', leaving);
  Object.assign(UP, { jobs: stop ? [] : UP.jobs, busy: false, files: 0, done: 0, bytes: 0, sent: 0, name: '', ctrl: null });
  drawUp();
  if (stop === 'auth') return;                           // the session ended: its window says so
  if (stop === 'cancel') toast(t('fx_upload_cancelled'));
  else if (stop === 'quota' || stop === 'tmp_dir') toast(errText(stop), true);
  else if (failed.length) toast(t('fx_upload_failed', failed.length, failed.slice(0, 3).join(', ') + (failed.length > 3 ? '…' : '')), true);
  else toast(t('fx_uploaded', done));
}

// a drop outside the drop zones does nothing (the browser would open the file instead of the application)
let dropGuard = false;
function guardDrops() {
  if (dropGuard) return;
  dropGuard = true;
  const off = (e) => {
    if (!FX.root?.isConnected || ![...(e.dataTransfer?.types || [])].some((x) => x === 'Files' || x === DRAG_TYPE)) return;
    e.preventDefault();
    e.dataTransfer.dropEffect = 'none';
  };
  addEventListener('dragover', off);
  addEventListener('drop', off);
}

function viewFiles(arg) {
  let dir = '';
  try { dir = decodeURIComponent(arg || '').replace(/^\/+|\/+$/g, ''); } catch { /* a malformed address: the top folder */ }
  let items = [], shown = [], usage = 0, quota = 0, loaded = false, missing = false, renaming = false;
  let sel = new Set(), anchor = null, cur = null, filter = '', touch = false;
  let sort = (pref('mudesk-files-sort') || 'name:1').split(':');
  sort = [['name', 'mtime', 'size'].includes(sort[0]) ? sort[0] : 'name', Number(sort[1]) === -1 ? -1 : 1];
  let mode = pref('mudesk-files-view') === 'icons' ? 'icons' : 'details';
  const go = (p) => {
    const a = document.activeElement;
    FX.focus = a === list || list.contains?.(a) ? 'list' : tree.contains?.(a) ? 'tree' : null;   // (the view is made again)
    FX.from = dir;
    location.hash = filesHref(p);
  };
  const byName = (n) => items.find((f) => f.name === n);
  const isDir = (n) => !!byName(n)?.dir;
  const label = (p) => (p ? baseName(p) : t('root'));
  const selected = () => shown.filter((f) => sel.has(f.name)).map((f) => f.name);
  const errOf = (e) => (e instanceof ApiError ? errText(e.message) : String(e.message || e));
  for (let p = dir; ; p = parentOf(p)) { FX.open.add(p); if (!p) break; }   // the tree shows where we are, and what is in it
  closeMenu();
  guardDrops();

  // ---- the window: bars, tree, list, status
  const usageEl = h('div', { class: 'fx-usage' });
  const pathEl = h('nav', { class: 'fx-path', 'aria-label': t('fx_location') });
  const search = h('input', { type: 'search', placeholder: t('fx_search'), 'aria-label': t('fx_search'), oninput: () => { filter = search.value; draw(); } });
  const tree = h('nav', { class: 'fx-tree', role: 'tree', 'aria-label': t('fx_tree') });
  const allBox = h('input', { type: 'checkbox', class: 'fx-check', 'aria-label': t('fx_select_all'),
    onclick: () => { sel = allBox.checked ? new Set(shown.map((f) => f.name)) : new Set(); drawSel(); } });
  const cols = h('div', { class: 'fx-cols' });
  const list = h('div', { class: 'fx-items ' + mode, role: 'listbox', 'aria-multiselectable': 'true', 'aria-label': t('files_title'), tabindex: 0 });
  const main = h('div', { class: 'fx-main' }, cols, list);
  const info = h('span', { class: 'spacer', 'aria-live': 'polite' });
  const pick = h('input', { type: 'file', multiple: true, class: 'hidden' });
  const pickDir = h('input', { type: 'file', multiple: true, webkitdirectory: true, class: 'hidden' });
  const act = (text, ic, fn, cls = '', key = '') => btn(h('span', { class: 'lbl' }, text), fn, 'small ' + cls, ic, key ? `${text} (${key})` : text);
  const treeBtn = btn('', () => treeBtn.setAttribute('aria-pressed', String(win.classList.toggle('tree-open'))), 'small fx-tree-toggle', 'folder', t('fx_folders'));
  const bUp = btn('', () => go(parentOf(dir)), 'small', 'arrowUp', t('fx_up'));
  bUp.disabled = !dir;
  const bDownload = act(t('download'), 'download', () => download(selected()));
  const bRename = act(t('fx_rename'), 'edit', () => rename(selected()[0]), '', 'F2');
  const bDelete = act(t('delete'), 'trash', () => remove(selected()), 'danger', t('key_del'));
  const views = [['details', 'rows', t('fx_view_details')], ['icons', 'grid', t('fx_view_icons')]].map(([m, ic, l]) =>
    h('button', { type: 'button', class: 'btn small' + (m === mode ? ' on' : ''), title: l, 'aria-label': l, 'aria-pressed': String(m === mode), 'data-view': m,
      onclick: () => { mode = m; pref('mudesk-files-view', m); views.forEach((b) => { const on = b.getAttribute('data-view') === m; b.classList.toggle('on', on); b.setAttribute('aria-pressed', String(on)); }); draw(); } }, icon(ic)));
  const win = h('div', { class: 'card fx-win' },
    h('div', { class: 'fx-bar' }, treeBtn, bUp,
      btn('', () => reload(true), 'small', 'refresh', t('fx_refresh')), pathEl, search),
    h('div', { class: 'fx-bar' }, act(t('new_folder'), 'folderPlus', () => newFolder()),
      btn(h('span', { class: 'lbl' }, t('upload')), () => pick.click(), 'small primary', 'upload', t('upload')),
      act(t('fx_upload_folder'), 'folderUp', () => pickDir.click()),
      h('span', { class: 'sep' }), bDownload, bRename, bDelete, h('span', { class: 'spacer' }), h('span', { class: 'fx-views', role: 'group' }, views)),
    h('div', { class: 'fx-body' }, tree, main),
    h('div', { class: 'fx-status' }, info, h('div', { class: 'fx-up' })), pick, pickDir);
  const root = h('div', { class: 'fx' },
    h('div', { class: 'fx-head' }, h('h1', {}, t('files_title')), h('span', { class: 'spacer' }), usageEl),
    h('p', { class: 'muted small' }, t('files_hint')), win);
  Object.assign(FX, { dir, root, reload: (all) => (root.isConnected ? reload(all) : null) });

  // ---- drawing
  const order = () => {
    const [k, d] = sort, f = norm(filter.trim());
    shown = items.filter((x) => !f || norm(x.name).includes(f)).sort((a, b) => (b.dir - a.dir)
      || d * (k === 'size' ? a.size - b.size : k === 'mtime' ? a.mtime - b.mtime : 0)
      || d * a.name.localeCompare(b.name, getLang(), { numeric: true, sensitivity: 'base' }));
  };
  const dateFmt = new Intl.DateTimeFormat(getLang(), { dateStyle: 'short', timeStyle: 'short' });
  const itemEl = (f, i) => {
    const p = joinPath(dir, f.name);
    const thumb = mode === 'icons' && !f.dir && THUMB.includes(extOf(f.name)) && f.size < 20 * 1048576
      && h('img', { class: 'fx-thumb', alt: '', loading: 'lazy', draggable: 'false', src: fileUrl(p, '&view=1&m=' + f.mtime), onerror: (e) => e.target.replaceWith(fileIcon(f, true)) });
    return h('div', { class: 'fx-item', id: 'fx-i' + i, role: 'option', 'aria-selected': 'false', draggable: 'true', 'data-name': f.name, title: f.name },
      h('input', { type: 'checkbox', class: 'fx-check', tabindex: -1, 'aria-label': t('fx_select', f.name), onclick: (e) => { e.stopPropagation(); toggle(f.name); } }),
      h('span', { class: 'fx-name' }, thumb || fileIcon(f, mode === 'icons'), h('span', { class: 'fx-label' }, f.name)),
      h('span', { class: 'meta date' }, f.mtime ? dateFmt.format(f.mtime * 1000) : ''),
      h('span', { class: 'meta num' }, f.dir ? '' : fmtSize(f.size)));
  };
  const empty = (...c) => h('div', { class: 'fx-empty' }, ...c);
  const draw = () => {
    if (renaming) return;
    order();
    list.className = 'fx-items ' + mode;
    cols.classList.toggle('hidden', mode !== 'details' || !shown.length);
    sel = new Set([...sel].filter((n) => shown.some((f) => f.name === n)));
    if (!loaded) fill(list);
    else if (missing) fill(list, empty(fileIcon({ dir: true }, true), h('strong', {}, t('fx_not_found')), h('a', { href: filesHref('') }, t('root'))));
    else if (!items.length) fill(list, empty(fileIcon({ dir: true }, true), h('strong', {}, t('empty_folder')), h('span', {}, t('fx_empty_hint'))));
    else if (!shown.length) fill(list, empty(h('span', {}, t('fx_no_match', filter.trim()))));
    else fill(list, shown.map(itemEl));
    fill(cols, h('span', {}, allBox), [['name', t('fx_col_name')], ['mtime', t('fx_col_date')], ['size', t('fx_col_size')]].map(([k, l]) =>
      h('button', { type: 'button', class: { size: 'num', mtime: 'date' }[k] || '', title: t('fx_sort_by', l),
        onclick: () => { sort = [k, sort[0] === k ? -sort[1] : 1]; pref('mudesk-files-sort', sort.join(':')); draw(); } }, l, sort[0] === k && icon(sort[1] > 0 ? 'up' : 'down'))));
    drawSel();
  };
  const statusText = () => {
    const k = sel.size, bytes = items.filter((f) => sel.has(f.name) && !f.dir).reduce((s, f) => s + f.size, 0);
    return [shown.length === 1 ? t('fx_item') : t('fx_items', shown.length),
      k && (k === 1 ? t('fx_selected_one') : t('fx_selected', k)) + (bytes ? ' · ' + fmtSize(bytes) : '')].filter(Boolean).join(' · ');
  };
  const drawSel = () => {
    for (const el of list.querySelectorAll('.fx-item')) {
      const n = el.getAttribute('data-name'), on = sel.has(n);
      el.classList.toggle('sel', on);
      el.classList.toggle('focus', n === cur);
      el.setAttribute('aria-selected', String(on));
      el.querySelector('.fx-check').checked = on;
      if (n === cur) list.setAttribute('aria-activedescendant', el.getAttribute('id'));
    }
    list.classList.toggle('picking', sel.size > 0);
    allBox.checked = shown.length > 0 && shown.every((f) => sel.has(f.name));
    allBox.indeterminate = !allBox.checked && sel.size > 0;
    bDownload.disabled = bDelete.disabled = !sel.size;
    bRename.disabled = sel.size !== 1;
    if (!hot) info.textContent = loaded ? statusText() : '';
  };
  const drawUsage = () => {
    if (!quota) return fill(usageEl, t('used', fmtSize(usage)));
    const pct = Math.min(100, (usage / quota) * 100);
    fill(usageEl, h('span', { class: 'fx-meter' + (pct >= 95 ? ' full' : pct >= 80 ? ' near' : ''), 'aria-hidden': 'true' }, h('span', { style: `width:${pct.toFixed(1)}%` })),
      t('fx_used_of', fmtSize(usage), fmtSize(quota)));
  };
  const drawPath = () => {
    const parts = dir ? dir.split('/') : [];
    fill(pathEl, [['', t('root')], ...parts.map((n, i) => [parts.slice(0, i + 1).join('/'), n])].map(([p, l], i) => {
      const chev = icon('right');
      chev.setAttribute('class', 'ico chev');
      return [i > 0 && chev, h('button', { type: 'button', 'data-path': p, 'aria-current': p === dir ? 'location' : null, onclick: () => go(p) }, i === 0 && icon('home'), l)];
    }));
  };
  const nodeOf = (p) => [...tree.querySelectorAll('.fx-node')].find((x) => x.getAttribute('data-path') === p);
  const toggleNode = async (p, focus = false) => {
    if (FX.open.has(p)) FX.open.delete(p); else FX.open.add(p);
    if (FX.open.has(p) && !FX.kids.has(p)) await loadTree([p]);   // read when first opened
    drawTree();
    if (focus) nodeOf(p)?.focus();
  };
  const drawTree = () => {
    if (renaming) return;                                  // (drawn again once the name is given)
    const node = (p, depth) => {
      const known = FX.kids.get(p), sub = known ? known.names.map((n) => joinPath(p, n)) : [];
      const can = !known || sub.length > 0, open = can && FX.open.has(p) && !!known;   // not read yet: it may have folders
      return [h('div', { class: 'fx-node' + (p === dir ? ' current' : ''), role: 'treeitem', tabindex: p === dir ? 0 : -1, 'data-path': p, title: label(p), draggable: p ? 'true' : null,
        'aria-selected': String(p === dir), 'aria-expanded': can ? String(open) : null, 'aria-level': depth + 1, style: `--depth:${depth}`, onclick: () => go(p) },
        h('span', { class: 'twisty', onclick: (e) => { e.stopPropagation(); toggleNode(p); } }, can && icon(open ? 'down' : 'right')),
        p ? fileIcon({ dir: true }) : icon('home'), h('span', { class: 'label' }, label(p))),
        open && h('div', { role: 'group' }, sub.map((c) => node(c, depth + 1)),
          known.more && h('p', { class: 'muted small fx-more', style: `--depth:${depth + 1}` }, t('fx_tree_more')))];
    };
    const top = tree.scrollTop, had = tree.contains?.(document.activeElement) ? document.activeElement.getAttribute?.('data-path') : null;
    fill(tree, node('', 0));
    tree.scrollTop = top;
    if (had != null) nodeOf(had)?.focus();                // the focused folder drawn again keeps the keyboard
  };
  tree.addEventListener('keydown', (e) => {
    const nodes = [...tree.querySelectorAll('.fx-node')], i = nodes.indexOf(e.target), n = nodes[i];
    if (!n) return;
    const p = n.getAttribute('data-path'), exp = n.getAttribute('aria-expanded');
    const to = (j) => { const m = nodes[Math.max(0, Math.min(nodes.length - 1, j))]; nodes.forEach((x) => x.setAttribute('tabindex', -1)); m.setAttribute('tabindex', 0); m.focus(); };
    const k = { ArrowDown: () => to(i + 1), ArrowUp: () => to(i - 1), Home: () => to(0), End: () => to(nodes.length - 1), Enter: () => go(p), ' ': () => go(p),
      F2: () => p && renameFolder(p), Delete: () => p && removeFolder(p),
      ArrowRight: () => (exp === 'false' ? toggleNode(p, true) : exp === 'true' && to(i + 1)),
      ArrowLeft: () => (exp === 'true' ? toggleNode(p, true) : p && to(nodes.indexOf(nodeOf(parentOf(p))))) }[e.key];
    if (k) { e.preventDefault(); k(); }
  });

  // ---- loading
  /** Reads the folders of the folders paths (all the open ones by default: then the others are read again when opened). */
  const loadTree = (paths = null) => {
    const all = !paths;
    paths = (paths || [...FX.open]).slice(0, 200);
    return filesApi('tree', '', { body: JSON.stringify(paths) }).then((j) => {
      if (all) FX.kids = new Map();
      for (const [p, names, more] of j.dirs) FX.kids.set(p, { names, more });
      for (const p of paths) if (!j.dirs.some((d) => d[0] === p)) FX.open.delete(p);   // gone
      if (all) { drawTree(); tree.querySelector('.current')?.scrollIntoView?.({ block: 'nearest' }); }
    }, () => {});
  };
  const reload = async (all = false) => {
    if (all) loadTree();
    try {
      const j = await filesApi('list', dir, all ? { q: '&count=1' } : {});   // the folder counted again only when opened or refreshed
      [items, usage, quota, missing] = [j.items, j.usage ?? usage, j.quota || 0, false];
      FX.kids.set(dir, { names: j.items.filter((f) => f.dir).map((f) => f.name).sort((a, b) => a.localeCompare(b, getLang(), { numeric: true, sensitivity: 'base' })), more: false });
      drawTree();
    } catch (e) {
      items = [];
      if (e instanceof ApiError && e.message === 'not_found') missing = true; else if (!gone(e)) toast(errOf(e), true);
    }
    const first = !loaded;
    loaded = true;
    drawUsage();
    draw();
    if (first && FX.focus) {                              // where the keyboard was before changing folder
      const back = FX.from && parentOf(FX.from) === dir ? baseName(FX.from) : null;
      if (FX.focus === 'list') {
        if (back && byName(back)) only(back);
        list.focus();
      } else nodeOf(dir)?.focus();
      FX.focus = null;
    }
  };

  // ---- selection: click, Ctrl, Shift, the checkboxes, a frame drawn with the mouse; a tap opens on touch screens
  const toggle = (n) => { if (sel.has(n)) sel.delete(n); else sel.add(n); anchor = cur = n; drawSel(); };
  const only = (n) => { sel = new Set([n]); anchor = cur = n; drawSel(); };
  const range = (to, add) => {
    const a = shown.findIndex((f) => f.name === anchor), b = shown.findIndex((f) => f.name === to);
    if (!add) sel = new Set();
    if (a < 0) sel.add(to); else for (let i = Math.min(a, b); i <= Math.max(a, b); i++) sel.add(shown[i].name);
    cur = to;
    drawSel();
  };
  const itemOf = (e) => e.target.closest?.('.fx-item');
  let band = null, banded = false;
  list.addEventListener('pointerdown', (e) => {
    touch = e.pointerType === 'touch';
    if (e.button !== 0 || e.pointerType !== 'mouse' || e.target !== list || renaming) return;
    const box = list.getBoundingClientRect(), at = (m) => [m.clientX - box.left + list.scrollLeft, m.clientY - box.top + list.scrollTop];
    const [x0, y0] = at(e), base = e.ctrlKey || e.metaKey ? new Set(sel) : new Set();
    const boxes = [...list.querySelectorAll('.fx-item')].map((el) => [el.getAttribute('data-name'), el.offsetLeft, el.offsetTop, el.offsetWidth, el.offsetHeight]);
    const move = (m) => {
      const [x1, y1] = at(m);
      if (!banded && Math.hypot(x1 - x0, y1 - y0) < 5) return;
      banded = true;
      const [l, tp, r, b] = [Math.min(x0, x1), Math.min(y0, y1), Math.max(x0, x1), Math.max(y0, y1)];
      band ??= list.appendChild(h('div', { class: 'fx-band' }));
      Object.assign(band.style, { left: l + 'px', top: tp + 'px', width: r - l + 'px', height: b - tp + 'px' });
      sel = new Set(base);
      for (const [n, x, y, w, ht] of boxes) if (x < r && x + w > l && y < b && y + ht > tp) { if (base.has(n)) sel.delete(n); else sel.add(n); }
      drawSel();
    };
    const end = () => { list.removeEventListener('pointermove', move); band?.remove(); band = null; setTimeout(() => { banded = false; }); };
    list.setPointerCapture?.(e.pointerId);
    list.addEventListener('pointermove', move);
    list.addEventListener('pointerup', end, { once: true });
    list.addEventListener('pointercancel', end, { once: true });
  });
  list.addEventListener('click', (e) => {
    if (renaming) return;
    const it = itemOf(e), n = it?.getAttribute('data-name');
    if (!it) { if (e.target === list && !banded && !e.ctrlKey && !e.metaKey && !e.shiftKey && sel.size) { sel = new Set(); drawSel(); } return; }
    if (e.shiftKey) range(n, e.ctrlKey || e.metaKey);
    else if (e.ctrlKey || e.metaKey || (touch && sel.size)) toggle(n);
    else if (touch) open(n);
    else only(n);
  });
  list.addEventListener('dblclick', (e) => { const it = itemOf(e); if (it && !renaming) open(it.getAttribute('data-name')); });
  let typed = '', typedAt = 0;
  const perRow = () => {
    const els = [...list.querySelectorAll('.fx-item')];
    let n = 0;
    while (n < els.length && els[n].offsetTop === els[0].offsetTop) n++;
    return Math.max(1, n);
  };
  const showCur = () => [...list.querySelectorAll('.fx-item')].find((x) => x.getAttribute('data-name') === cur)?.scrollIntoView?.({ block: 'nearest' });
  list.addEventListener('keydown', (e) => {
    if (renaming || (e.target !== list && !e.target.classList?.contains('fx-check'))) return;   // (a box ticked with the mouse keeps the focus)
    const ctrl = e.ctrlKey || e.metaKey, i = shown.findIndex((f) => f.name === cur), step = mode === 'icons' ? perRow() : 1;
    const focusAt = (j) => {
      const f = shown[Math.max(0, Math.min(shown.length - 1, j))];
      if (!f) return;
      if (e.shiftKey) range(f.name, ctrl);
      else if (ctrl) { cur = f.name; drawSel(); } else only(f.name);
      showCur();
    };
    if ((e.key === 'ArrowUp' && e.altKey) || e.key === 'Backspace') { e.preventDefault(); if (dir) go(parentOf(dir)); return; }
    if (ctrl && e.key.toLowerCase() === 'a') { e.preventDefault(); sel = new Set(shown.map((f) => f.name)); drawSel(); return; }
    const typing = e.key === ' ' && typed && performance.now() - typedAt < 800;   // a space within a name being typed
    const k = typing ? null : {
      ArrowDown: () => focusAt(i < 0 ? 0 : i + step), ArrowUp: () => focusAt(i < 0 ? 0 : i - step),
      ArrowRight: () => mode === 'icons' && focusAt(i + 1), ArrowLeft: () => mode === 'icons' && focusAt(i - 1),
      Home: () => focusAt(0), End: () => focusAt(shown.length - 1),
      Enter: () => { const s = selected(); if (s.length === 1) open(s[0]); else if (s.length) download(s); },
      Delete: () => remove(selected()), F2: () => sel.size === 1 && rename(selected()[0]),
      Escape: () => { sel = new Set(); drawSel(); }, ' ': () => cur && (ctrl ? toggle(cur) : only(cur)),
    }[e.key];
    if (k) { e.preventDefault(); k(); return; }
    if (e.key.length === 1 && !ctrl && !e.altKey) {   // typing jumps to the next name that starts so
      if (typing) e.preventDefault();                    // (the space would scroll the list)
      typed = (performance.now() - typedAt < 800 ? typed : '') + norm(e.key);
      typedAt = performance.now();
      const from = typed.length > 1 ? Math.max(i, 0) : i + 1, f = [...shown.slice(from), ...shown.slice(0, from)].find((x) => norm(x.name).startsWith(typed));
      if (f) { only(f.name); showCur(); }
    }
  });
  list.addEventListener('contextmenu', (e) => {
    if (renaming) return;
    e.preventDefault();
    const it = itemOf(e);
    if (it && !sel.has(it.getAttribute('data-name'))) only(it.getAttribute('data-name'));
    if (!it && sel.size) { sel = new Set(); drawSel(); }
    const names = selected(), one = names.length === 1 ? byName(names[0]) : null;
    const [x, y] = e.clientX || e.clientY ? [e.clientX, e.clientY] : [list.getBoundingClientRect().left + 40, list.getBoundingClientRect().top + 40];   // from the keyboard
    menu(x, y, it ? [
      one && (one.dir || THUMB.includes(extOf(one.name))) && [t('fx_open'), one.dir ? 'folder' : 'file', () => open(one.name)],
      [names.length > 1 || one?.dir ? t('fx_download_zip') : t('download'), 'download', () => download(names)],
      one && [t('fx_rename'), 'edit', () => rename(one.name)],
      [t('delete'), 'trash', () => remove(names), 'danger'],
    ] : [
      [t('new_folder'), 'folderPlus', () => newFolder()], [t('upload'), 'upload', () => pick.click()], [t('fx_upload_folder'), 'folderUp', () => pickDir.click()],
      '-', [t('fx_select_all'), 'check', () => { sel = new Set(shown.map((f) => f.name)); drawSel(); }], [t('fx_refresh'), 'refresh', () => reload(true)],
    ]);
  });

  // ---- actions
  const open = (n) => {
    const f = byName(n);
    if (!f) return;
    if (f.dir) go(joinPath(dir, n));
    else if (THUMB.includes(extOf(n))) window.open(fileUrl(joinPath(dir, n), '&view=1'), '_blank', 'noopener');   // an image: shown in a tab
    else download([n]);
  };
  const saveUrl = (url) => { const a = h('a', { href: url, download: '' }); document.body.append(a); a.click(); a.remove(); };
  const downloadFrom = async (base, names) => {
    if (!names.length) return;
    if (base === dir && names.length === 1 && !isDir(names[0])) return saveUrl(fileUrl(joinPath(dir, names[0])));
    // asked first (too big: said here, not saved as a file), the names in the body (hundreds do not fit in an address)
    const j = await attempt(() => filesApi('zip', base, { q: '&check=1', body: JSON.stringify(names) }));
    if (j?.id) saveUrl('files.php?a=zip&z=' + encodeURIComponent(j.id));
  };
  const download = (names) => downloadFrom(dir, names);
  /** A folder renamed or moved: the tree keeps it open, and the page follows it when it is where we are. */
  const followMove = (from, to) => {
    for (const o of [...FX.open]) if (o === from || o.startsWith(from + '/')) { FX.open.delete(o); FX.open.add(to + o.slice(from.length)); }
    return dir === from || dir.startsWith(from + '/') ? to + dir.slice(from.length) : null;
  };
  /** Renames a folder of the tree in place (F2, or its menu). */
  const renameFolder = (p) => {
    const n = nodeOf(p), old = baseName(p);
    if (!p || !n || renaming) return;
    renaming = true;
    n.setAttribute('draggable', 'false');
    const input = h('input', { class: 'fx-rename', value: old, spellcheck: 'false', 'aria-label': t('fx_rename') });
    let over = false;
    const end = async (save) => {
      if (over) return;
      over = true;
      renaming = false;
      const v = input.value.trim(), to = joinPath(parentOf(p), v);
      if (!save || !v || v === old || /[\\/:*?"<>|]/.test(v)) {
        if (save && v && v !== old) toast(errText('invalid_name'), true);
        drawTree();
        draw();
        nodeOf(p)?.focus();
        return;
      }
      if (!await attempt(() => filesApi('move', p, { q: '&to=' + encodeURIComponent(to) }))) { drawTree(); draw(); nodeOf(p)?.focus(); return; }
      const next = followMove(p, to);
      if (next !== null) go(next); else { await reload(true); nodeOf(to)?.focus(); }
    };
    input.addEventListener('keydown', (e) => {
      e.stopPropagation();
      if (e.key === 'Enter' || e.key === 'Escape') { e.preventDefault(); end(e.key === 'Enter'); }
    });
    input.addEventListener('blur', () => end(true));
    for (const ev of ['click', 'dblclick', 'pointerdown', 'contextmenu', 'dragstart']) input.addEventListener(ev, (e) => e.stopPropagation());
    n.querySelector('.label').replaceWith(input);
    input.focus();
    input.select?.();
  };
  /** Deletes a folder of the tree after asking; when we are in it, the page goes to its parent. */
  const removeFolder = async (p) => {
    if (!p || !await confirmDialog(t('fx_delete_folder', baseName(p)))) return;
    try { await filesApi('delete', p); } catch (e) { if (!gone(e)) toast(errOf(e), true); return; }
    for (const o of [...FX.open]) if (o === p || o.startsWith(p + '/')) FX.open.delete(o);
    if (dir === p || dir.startsWith(p + '/')) go(parentOf(p)); else await reload(true);
  };
  /** A new folder inside a folder of the tree, named there at once. */
  const newFolderIn = async (p) => {
    if (p === dir) return newFolder();
    const j = await attempt(() => filesApi('list', p));
    if (!j) return;
    const have = new Set(j.items.map((f) => f.name.toLowerCase()));
    let name = t('new_folder');
    for (let i = 2; have.has(name.toLowerCase()); i++) name = `${t('new_folder')} (${i})`;
    if (!await attempt(() => filesApi('mkdir', p, { q: '&n=' + encodeURIComponent(name) }))) return;
    FX.open.add(p);
    await loadTree([p]);
    drawTree();
    renameFolder(joinPath(p, name));
  };
  tree.addEventListener('contextmenu', (e) => {
    const n = e.target.closest?.('.fx-node');
    if (!n || renaming) return;
    e.preventDefault();
    const p = n.getAttribute('data-path'), r = n.getBoundingClientRect();
    const [x, y] = e.clientX || e.clientY ? [e.clientX, e.clientY] : [r.left + 24, r.top + r.height];   // from the keyboard
    n.classList.add('target');
    menu(x, y, [
      p !== dir && [t('fx_open'), 'folder', () => go(p)],
      [t('new_folder'), 'folderPlus', () => newFolderIn(p)],
      p && [t('fx_download_zip'), 'download', () => downloadFrom(parentOf(p), [baseName(p)])],
      p && [t('fx_rename'), 'edit', () => renameFolder(p)],
      p && [t('delete'), 'trash', () => removeFolder(p), 'danger'],
      !p && [t('fx_refresh'), 'refresh', () => reload(true)],
    ], () => n.classList.remove('target'));
  });
  const rename = (n) => {
    const it = [...list.querySelectorAll('.fx-item')].find((x) => x.getAttribute('data-name') === n), f = byName(n);
    if (!it || !f || renaming) return;
    renaming = true;
    it.setAttribute('draggable', 'false');
    const input = h('input', { class: 'fx-rename', value: n, spellcheck: 'false', 'aria-label': t('fx_rename') });
    let over = false;
    const end = async (save) => {
      if (over) return;
      over = true;
      renaming = false;
      const v = input.value.trim();
      if (!save || !v || v === n) { draw(); list.focus(); return; }
      if (/[\\/:*?"<>|]/.test(v)) { toast(errText('invalid_name'), true); draw(); list.focus(); return; }
      if (await attempt(() => filesApi('move', joinPath(dir, n), { q: '&to=' + encodeURIComponent(joinPath(dir, v)) }))) {
        sel = new Set([v]);
        anchor = cur = v;
        if (f.dir) followMove(joinPath(dir, n), joinPath(dir, v));
      }
      await reload(f.dir);
      list.focus();
    };
    input.addEventListener('keydown', (e) => {
      e.stopPropagation();
      if (e.key === 'Enter' || e.key === 'Escape') { e.preventDefault(); end(e.key === 'Enter'); }
    });
    input.addEventListener('blur', () => end(true));
    for (const ev of ['click', 'dblclick', 'pointerdown', 'contextmenu']) input.addEventListener(ev, (e) => e.stopPropagation());
    it.querySelector('.fx-label').replaceWith(input);
    input.focus();
    const dot = f.dir ? -1 : n.lastIndexOf('.');
    input.setSelectionRange?.(0, dot > 0 ? dot : n.length);   // the name without its extension, as in a file explorer
  };
  let making = false;
  const newFolder = async () => {
    if (making) return;
    making = true;
    try { await makeFolder(); } finally { making = false; }
  };
  const makeFolder = async () => {
    const have = new Set(items.map((f) => f.name.toLowerCase()));
    let name = t('new_folder');
    for (let i = 2; have.has(name.toLowerCase()); i++) name = `${t('new_folder')} (${i})`;
    if (!await attempt(() => filesApi('mkdir', dir, { q: '&n=' + encodeURIComponent(name) }))) return;
    filter = search.value = '';
    sel = new Set([name]);
    anchor = cur = name;
    await reload(true);
    rename(name);                                          // named at once, as in a file explorer
  };
  const remove = async (names) => {
    if (!names.length) return;
    const dirs = names.filter(isDir).length;
    if (!await confirmDialog(names.length > 1 ? t('fx_delete_many', names.length) : dirs ? t('fx_delete_folder', names[0]) : t('confirm_delete', names[0]))) return;
    const errs = [];
    for (const n of names) await filesApi('delete', joinPath(dir, n)).catch((e) => gone(e) || errs.push(errOf(e)));
    if (errs.length) toast(errs[0] + (errs.length > 1 ? ` (+${errs.length - 1})` : ''), true);
    sel = new Set();
    await reload(dirs > 0);
  };
  const moveTo = async (d, target) => {
    const errs = [];
    let ok = 0, next = null;
    for (const n of d.names) {
      const from = joinPath(d.from, n), to = joinPath(target, n);
      await filesApi('move', from, { q: '&to=' + encodeURIComponent(to) }).then(() => { ok++; next = followMove(from, to) ?? next; }, (e) => gone(e) || errs.push(errOf(e)));
    }
    if (errs.length) toast(errs[0] + (errs.length > 1 ? ` (+${errs.length - 1})` : ''), true);
    if (ok) toast(ok === 1 ? t('fx_moved', label(target)) : t('fx_moved_many', ok, label(target)));
    sel = new Set();
    if (next !== null) go(next); else await reload(true);
  };
  /** Uploads to target what was dropped or picked; asks first when something with the same name is there. */
  const upload = async (target, got) => {
    let list0;
    try { list0 = await got; } catch (e) { if (!gone(e)) toast(errOf(e), true); return; }
    let { files, dirs } = list0;
    if (!files.length && !dirs.length) return;
    const tops = [...new Set([...files.map((f) => f.path), ...dirs].map((p) => p.split('/')[0]))];
    const there = target === dir ? items.map((f) => f.name) : ((await attempt(() => filesApi('list', target)))?.items || []).map((f) => f.name);
    const have = new Set(there.map((n) => n.toLowerCase()));   // Windows does not tell names apart by case
    const clash = tops.filter((n) => have.has(n.toLowerCase()));
    let how = null;
    if (clash.length) {
      how = await modal(t('fx_replace_title'), h('div', {}, h('p', {}, clash.length === 1 ? t('fx_replace_one', clash[0]) : t('fx_replace_many', clash.length)),
        h('p', { class: 'muted small' }, t('fx_replace_note'))),
      (close) => [btn(t('cancel'), () => close(null)), btn(t('fx_skip'), () => close('skip')), btn(t('fx_replace'), () => close('replace'), 'primary')]);
      if (!how) return;
      if (how === 'skip') {
        const skip = new Set(clash.map((n) => n.toLowerCase())), keep = (p) => !skip.has(p.split('/')[0].toLowerCase());
        files = files.filter((f) => keep(f.path));
        dirs = dirs.filter(keep);
      }
    }
    const bytes = files.reduce((n, f) => n + f.file.size, 0);
    if (quota && how !== 'replace' && bytes > quota - usage) { toast(errText('quota'), true); return; }   // the server checks it anyway
    if (files.length || dirs.length) queueUpload(target, files, dirs);
  };
  pick.addEventListener('change', () => {
    const files = [...pick.files].map((f) => ({ path: f.name, file: f }));
    pick.value = '';
    upload(dir, Promise.resolve({ files, dirs: [] }));
  });
  pickDir.addEventListener('change', () => {                // a folder (no empty folders: the browser does not give them)
    const files = [...pickDir.files].map((f) => ({ path: f.webkitRelativePath || f.name, file: f }));
    const dirs = [...new Set(files.flatMap((f) => { const d = f.path.split('/').slice(0, -1); return d.map((_, i) => d.slice(0, i + 1).join('/')); }))];
    pickDir.value = '';
    upload(dir, Promise.resolve({ files, dirs }));
  });

  // ---- dragging: items onto a folder (list, tree, path) are moved; files and folders from the computer are uploaded there
  list.addEventListener('dragstart', (e) => {
    const it = itemOf(e);
    if (!it || renaming) return;
    const n = it.getAttribute('data-name');
    if (!sel.has(n)) only(n);
    const names = selected(), dt = e.dataTransfer, abs = (u) => new URL(u, location.href).href;
    fxDrag = { from: dir, names, key: dragKey() };
    it.addEventListener('dragend', () => { fxDrag = null; highlight(null); }, { once: true });
    dt.effectAllowed = 'copyMove';
    dt.setData(DRAG_TYPE, JSON.stringify(fxDrag));
    // dropped on the desktop of the computer (Chrome, Edge): downloaded there; folders and several items as a ZIP
    const zip = abs(zipUrl(dir, names));                    // (an address of more than 2 KB is refused by IIS: then no download)
    if (names.length === 1 && !isDir(n)) dt.setData('DownloadURL', `application/octet-stream:${n}:${abs(fileUrl(joinPath(dir, n)))}`);
    else if (zip.length < 2000) dt.setData('DownloadURL', `application/zip:${names.length === 1 ? n : baseName(dir) || 'MuDesk'}.zip:${zip}`);
    if (names.length > 1) {
      const ghost = h('div', { class: 'fx-ghost' }, fileIcon({ dir: false, name: '' }), t('fx_n_items', names.length));
      document.body.append(ghost);
      dt.setDragImage?.(ghost, 14, 14);
      setTimeout(() => ghost.remove());
    }
  });
  list.addEventListener('dragend', () => { fxDrag = null; highlight(null); });
  tree.addEventListener('dragstart', (e) => {
    const p = e.target.closest?.('.fx-node')?.getAttribute('data-path');
    if (!p || renaming) return;
    fxDrag = { from: parentOf(p), names: [baseName(p)], key: dragKey() };
    e.target.closest('.fx-node').addEventListener('dragend', () => { fxDrag = null; highlight(null); }, { once: true });
    e.dataTransfer.effectAllowed = 'copyMove';
    e.dataTransfer.setData(DRAG_TYPE, JSON.stringify(fxDrag));
    e.dataTransfer.setData('DownloadURL', `application/zip:${baseName(p)}.zip:${new URL(zipUrl(parentOf(p), [baseName(p)]), location.href).href}`);
  });
  tree.addEventListener('dragend', () => { fxDrag = null; highlight(null); });
  /** What is being moved: this drag's own data (never a drag left over), from this window or, with this browser's key, another. */
  const dragOf = (e) => {
    let raw = '';
    try { raw = e.dataTransfer.getData(DRAG_TYPE); } catch { /* not readable */ }
    if (fxDrag && raw === JSON.stringify(fxDrag)) return fxDrag;
    try {                                                  // from another window of the application
      const d = JSON.parse(raw), key = dragKey();         // any page can set this type: our key or nothing
      return key && d.key === key && typeof d.from === 'string' && Array.isArray(d.names) && d.names.every((n) => typeof n === 'string') ? d : null;
    } catch { return null; }
  };
  const dropKind = (e, target) => {
    const types = [...(e.dataTransfer?.types || [])];
    if (types.includes(DRAG_TYPE)) {
      const d = fxDrag;                                    // (only known here when dragged from this window)
      return d && (target === d.from || d.names.some((n) => { const s = joinPath(d.from, n); return target === s || target.startsWith(s + '/'); })) ? null : 'move';
    }
    return types.includes('Files') ? 'copy' : null;
  };
  let hot = null, hotTimer = 0;
  const highlight = (el, text = '') => {
    if (hot !== el) {
      hot?.classList.remove('drop');
      el?.classList.add('drop');
      hot = el;
      clearTimeout(hotTimer);
      if (el?.getAttribute('aria-expanded') === 'false') hotTimer = setTimeout(() => toggleNode(el.getAttribute('data-path')), 800);   // a closed folder of the tree opens
    }
    if (el === main) main.setAttribute('data-drop', text);
    info.textContent = el ? text : loaded ? statusText() : '';
  };
  const dropZone = (el, resolve) => {
    el.addEventListener('dragover', (e) => {
      const r = resolve(e), how = r && dropKind(e, r[0]);
      if (!how) return;
      e.preventDefault();
      e.stopPropagation();
      e.dataTransfer.dropEffect = how;
      const what = !fxDrag ? '' : fxDrag.names.length === 1 ? `«${fxDrag.names[0]}» ` : t('fx_n_items', fxDrag.names.length) + ' ';
      highlight(r[1], how === 'move' ? t('fx_drop_move', what, label(r[0])) : t('fx_drop_upload', label(r[0])));
    });
    el.addEventListener('dragleave', (e) => { if (!e.relatedTarget || !el.contains?.(e.relatedTarget)) highlight(null); });
    el.addEventListener('drop', (e) => {
      const r = resolve(e), how = r && dropKind(e, r[0]);
      highlight(null);
      if (!how) return;
      e.preventDefault();
      e.stopPropagation();
      if (how === 'move') { const d = dragOf(e); fxDrag = null; if (d) moveTo(d, r[0]); return; }
      const dt = e.dataTransfer;                           // read now: the items go once the event is over
      const entries = Array.from(dt.items || []).filter((x) => x.kind === 'file').map((x) => x.webkitGetAsEntry?.());
      const files = Array.from(dt.files || []).map((f) => ({ path: f.name, file: f }));
      upload(r[0], entries.length && entries.every(Boolean) ? droppedItems(entries) : Promise.resolve({ files, dirs: [] }));
    });
  };
  dropZone(main, (e) => { const it = itemOf(e), f = it && byName(it.getAttribute('data-name')); return f?.dir ? [joinPath(dir, f.name), it] : [dir, main]; });
  dropZone(tree, (e) => { const n = e.target.closest?.('.fx-node'); return n && [n.getAttribute('data-path'), n]; });
  dropZone(pathEl, (e) => { const b = e.target.closest?.('button'); return b && [b.getAttribute('data-path'), b]; });

  drawPath();
  drawTree();
  draw();
  reload(true);
  setTimeout(() => { if (!loaded && list.isConnected) fill(list, h('p', { class: 'muted fx-loading' }, t('loading'))); }, 300);
  setTimeout(drawUp);                                      // an upload started in another folder goes on
  return root;
}

// ------------------------------------------------------------------ profile

function viewProfile() {
  const u = S.user;
  const twofa = h('div', {});
  const draw2fa = () => {
    if (S.user.totp) {
      const err = errBox();
      const pw = h('input', { type: 'password', placeholder: t('current_password'), autocomplete: 'current-password' });
      fill(twofa, h('p', {}, h('span', { class: 'badge on' }, t('twofa_on'))),
        !S.user.totp_required && h('div', { class: 'row' }, pw, btn(t('disable'), () => attempt(async () => { await api('totp.disable', { password: pw.value }); render(); }, err), 'danger')), err);
    } else {
      const err = errBox();
      const pw = h('input', { type: 'password', placeholder: t('current_password'), autocomplete: 'current-password' });
      fill(twofa, needSecond() ? h('p', { class: 'error' }, t('twofa_required')) : h('p', {}, h('span', { class: 'badge' }, t('twofa_off'))),
        h('div', { class: 'row' }, pw, btn(t('enable'), () => attempt(async () => {
          const j = await api('totp.begin', { password: pw.value });
          const code = h('input', { inputmode: 'numeric', maxlength: 6, placeholder: '000000', autocomplete: 'one-time-code' });
          const qr = h('div', { class: 'qr' });
          try { qr.innerHTML = qrSvg(j.uri, 5); } catch { /* too long for a QR code: the key below is typed in by hand */ }
          fill(twofa, h('p', {}, t('twofa_scan')), qr, h('p', { class: 'mono small' }, j.secret),
            field(t('code'), code), err, btn(t('verify'), () => attempt(async () => { await api('totp.enable', { code: code.value.trim() }); toast(t('saved')); render(); }, err), 'primary'));
        }, err), 'primary')), err);
    }
  };
  draw2fa();
  const langSel = select('lang', { '': t('lang_auto', LANGS[browserLang()]), ...LANGS }, u.lang);
  const pkErr = errBox();
  const pkName = h('input', { placeholder: t('passkey_name'), maxlength: 40 });
  const pkPass = h('input', { type: 'password', placeholder: t('current_password'), autocomplete: 'current-password' });
  const passkeys = h('div', {},
    h('p', { class: 'muted small' }, t('passkey_help')),
    u.passkeys.length ? h('table', { class: 'list' }, u.passkeys.map((p) => h('tr', {},
      h('td', {}, p.name), h('td', { class: 'small muted' }, fmtDate(p.created)),
      h('td', {}, h('span', { class: 'badge' + (p.passwordless ? ' on' : '') }, t(p.passwordless ? 'passkey_passwordless' : 'passkey_second'))),
      h('td', {}, btn(t('delete'), () => attempt(async () => { await api('passkey.delete', { id: p.id, password: pkPass.value }); render(); }, pkErr), 'small danger'))))) : null,
    u.passkeys.length || hasPasskeys ? h('div', { class: 'row' }, pkPass) : null,   // asked to add or delete one
    hasPasskeys ? h('div', { class: 'row' }, pkName, btn(t('passkey_add'), () => attempt(async () => {
      const { options } = await api('passkey.create.begin', { password: pkPass.value });
      const made = await api('passkey.create', { name: pkName.value, ...(await passkeyCreate(options)) });
      const a = await passkeyGet(made.options);          // second touch: the PRF output lets the passkey replace the password
      if (a.prf) await api('passkey.prf', { id: made.id, ...a });
      toast(t(a.prf ? 'passkey_added_full' : 'passkey_added_second'));
      await loadState();
      render();
    }, pkErr), 'primary')) : h('p', { class: 'error' }, t('passkey_unsupported')),
    pkErr);
  langSel.addEventListener('change', () => attempt(async () => { await api('profile.lang', { lang: langSel.value }); setLang(langSel.value || browserLang()); render(); }));
  return h('div', {},
    h('h1', {}, t('profile')),
    needSecond() && h('p', { class: 'warn banner', role: 'alert' }, t('twofa_needed')),
    h('div', { class: 'card' }, h('h2', {}, t('language')), langSel),
    h('div', { class: 'card' }, h('h2', {}, t('twofa')), twofa),
    h('div', { class: 'card' }, h('h2', {}, t('passkeys')), passkeys),
    h('div', { class: 'card' }, h('h2', {}, t('change_password')), passwordForm(() => {})),
    profileActivity());
}

/** The user's own open remote sessions and trusted browsers, each one with a button to end or forget it. */
function profileActivity() {
  const sessions = h('div', { class: 'table-wrap' }, t('loading'));
  const browsers = h('div', { class: 'table-wrap' });
  const err = errBox();
  const head = (...cols) => h('thead', {}, h('tr', {}, cols.map((x) => h('th', {}, x))));
  const forget = (what) => attempt(async () => drawBrowsers((await api('profile.browser.forget', what)).browsers), err);
  const drawBrowsers = (list) => fill(browsers, h('p', { class: 'muted small' }, t('browsers_help')),
    list.length ? h('table', { class: 'list' }, head(t('browser'), t('browser_added'), t('browser_used'), ''), h('tbody', {}, list.map((b) => h('tr', {},
      h('td', {}, b.name || t('browser_unknown'), b.current && [' ', h('span', { class: 'badge on' }, t('this_browser'))]),
      h('td', { class: 'small muted' }, b.added ? fmtDate(b.added) : '-', b.ip && ' · ' + b.ip),
      h('td', { class: 'small muted' }, b.used ? fmtDate(b.used) : '-'),
      h('td', { style: 'text-align:right' }, btn(t('forget'), () => forget({ id: b.id }), 'small danger'))))))
      : h('p', { class: 'muted' }, t('no_browsers')),
    list.some((b) => !b.current) && btn(t('forget_others'), () => forget({ others: true }), 'small danger'));
  const load = () => attempt(async () => {
    const j = await api('profile.activity');
    fill(sessions, j.sessions.length ? h('table', { class: 'list' }, head(t('conn_name'), t('host'), t('started'), t('origin'), ''),
      h('tbody', {}, j.sessions.map((s) => h('tr', {}, h('td', {}, s.conn), h('td', { class: 'mono small' }, s.host), h('td', { class: 'small' }, fmtDate(s.started)),
        h('td', { class: 'mono small' }, s.ip), h('td', { style: 'text-align:right' },
          btn(t('terminate'), () => attempt(async () => { await api('profile.session.kill', { sid: s.sid }); setTimeout(load, 1500); }, err), 'small danger'))))))
      : h('p', { class: 'muted' }, t('no_own_sessions')));
    drawBrowsers(j.browsers);
  }, err);
  load();
  return [h('div', { class: 'card' }, h('h2', {}, t('own_sessions')), sessions), h('div', { class: 'card' }, h('h2', {}, t('browsers')), browsers, err)];
}

// ------------------------------------------------------------------ administration

function viewAdmin(tab) {
  tab = tab || 'users';
  const tabs = ['users', 'conns', 'settings', 'sessions', 'audit', 'traces'];
  const body = { users: adminUsers, conns: adminConns, settings: adminSettings, sessions: adminSessions, audit: adminAudit, traces: adminTraces }[tab] || adminUsers;
  return h('div', {}, h('h1', {}, t('nav_admin')),
    h('nav', { class: 'tabs' }, tabs.map((k) => h('a', { href: '#/admin/' + k, class: k === tab ? 'active' : '' }, t(k)))),
    body());
}

function adminUsers() {
  const box = h('div', { class: 'card table-wrap' }, t('loading'));
  const load = () => attempt(async () => {
    const { users } = await api('admin.users');
    fill(box, h('table', { class: 'list' },
      h('thead', {}, h('tr', {}, [t('username'), t('role'), t('enabled'), '2FA', t('last_login'), ''].map((x) => h('th', {}, x)))),
      h('tbody', {}, users.map((u) => h('tr', {},
        h('td', {}, u.username), h('td', {}, t('role_' + u.role)),
        h('td', {}, h('span', { class: 'badge' + (u.enabled ? ' on' : '') }, u.enabled ? t('yes') : t('no'))),
        h('td', {}, u.totp || u.passkeys.length ? h('span', { class: 'badge on' }, [u.totp && 'TOTP', u.passkeys.length && t('passkeys')].filter(Boolean).join(' + ')) : ''),
        h('td', { class: 'small muted' }, fmtDate(u.last_login)),
        h('td', { style: 'text-align:right;white-space:nowrap' }, btn('', () => editUser(u).then((ok) => ok && load()), 'small', 'edit', t('edit')),
          u.id !== S.user.id && btn('', () => confirmDialog(t('confirm_delete', u.username)).then((ok) => ok && attempt(async () => { await api('admin.user.delete', { id: u.id }); load(); })), 'small danger', 'trash', t('delete'))))))));
  });
  load();
  return h('div', {}, h('div', { class: 'toolbar' }, h('span', { class: 'spacer' }), btn(t('new_user'), () => editUser(null).then((ok) => ok && load()), 'primary', 'plus')), box);
}

/**
 * Connections in the administration: those of the administrator, given to some users or to all (they cannot change or
 * delete them), and each user's own ones, which the administrator can change or delete.
 */
function adminConns() {
  const box = h('div', {}, h('div', { class: 'card' }, t('loading')));
  let data = null;
  const load = () => attempt(async () => { data = await api('admin.conns'); draw(); });
  const who = (ids) => (ids.includes('*') ? t('all_users') : ids.map((id) => data.users.find((u) => u.id === id)?.username).filter(Boolean).join(', ') || t('nobody'));
  const target = (c) => (c.user ? (c.domain ? c.domain + '\\' : '') + c.user + ' @ ' : '') + c.host + (c.port !== 3389 ? ':' + c.port : '');
  const managed = (c, notes) => editConn(c, data.keyboards, { notes, admin: { users: data.users, assigned: c?.users ?? ['*'] },
    save: (conn, users) => api('admin.conn.save', { conn, users }) }).then((ok) => ok && load());
  const own = (u, c) => editConn(c, data.keyboards, { owner: u.username, save: (conn, forget) => api('admin.user.conn.save', { uid: u.id, conn, forget }) })
    .then((ok) => ok && load());
  const actions = (...b) => h('td', { style: 'text-align:right;white-space:nowrap' }, ...b);
  const table = (heads, rows) => h('div', { class: 'table-wrap' }, h('table', { class: 'list' }, h('thead', {}, h('tr', {}, heads.map((x) => h('th', {}, x)))), h('tbody', {}, rows)));
  const draw = () => {
    const mine = data.users.flatMap((u) => u.conns.map((c) => [u, c]));
    fill(box,
      h('section', { class: 'card' }, h('h3', {}, t('managed_conns')), h('p', { class: 'small muted' }, t('managed_conns_hint')),
        data.managed.length ? table([t('conn_name'), t('host'), t('conn_users'), ''], data.managed.map((c) => h('tr', {},
          h('td', {}, c.name), h('td', { class: 'mono small' }, target(c)), h('td', { class: 'small' }, who(c.users)),
          actions(btn('', () => exportRdp(c), 'small', 'download', t('export_rdp')), btn('', () => managed(c), 'small', 'edit', t('edit')),
            btn('', () => confirmDialog(t('confirm_delete_managed', c.name)).then((ok) => ok && attempt(async () => { await api('admin.conn.delete', { id: c.id }); load(); })),
              'small danger', 'trash', t('delete'))))))
          : h('p', { class: 'muted small' }, t('no_managed'))),
      h('section', { class: 'card', style: 'margin-top:1rem' }, h('h3', {}, t('users_conns')), h('p', { class: 'small muted' }, t('users_conns_hint')),
        mine.length ? table([t('username'), t('conn_name'), t('host'), ''], mine.map(([u, c]) => h('tr', {},
          h('td', {}, u.username), h('td', {}, c.name), h('td', { class: 'mono small' }, target(c)),
          actions(btn('', () => own(u, c), 'small', 'edit', t('edit')),
            btn('', () => confirmDialog(t('confirm_delete_of', c.name, u.username)).then((ok) => ok && attempt(async () => { await api('admin.user.conn.delete', { uid: u.id, id: c.id }); load(); })),
              'small danger', 'trash', t('delete'))))))
          : h('p', { class: 'muted small' }, t('no_user_conns'))));
  };
  const importer = rdpPicker((files) => data && importRdp(files, (c, notes) => managed(c, notes), (conn) => api('admin.conn.save', { conn, users: [] }))
    .then(() => { if (files.length > 1) toast(t('rdp_imported_admin'), false, 8000); load(); }));
  load();
  return h('div', {}, h('div', { class: 'toolbar' }, h('span', { class: 'spacer' }),
    btn(t('import_rdp'), () => importer.click(), '', 'upload', t('import_rdp_hint')), importer,
    btn(t('new_conn'), () => data && managed(null), 'primary', 'plus')), box);
}

function editUser(u) {
  const err = errBox();
  const form = h('form', { onsubmit: (e) => e.preventDefault() },
    !u && field(t('username'), h('input', { name: 'username', required: true })),
    !u && field(t('temp_password'), h('input', { name: 'password', type: 'text', required: true, minlength: 12, autocomplete: 'off' }), t('pw_rule')),
    field(t('max_sessions'), h('input', { name: 'max_sessions', type: 'number', min: 0, max: 50, value: u?.max_sessions ?? 0 })),
    check(t('enabled'), { name: 'enabled', checked: u ? u.enabled : true }),
    check(t('admin_rights'), { name: 'admin', checked: u?.role === 'admin' }),   // several administrators can be
    u && h('div', { class: 'card', style: 'margin-top:1rem' },
      h('h3', {}, t('reset_password')), h('p', { class: 'small muted' }, t('reset_warning')),
      h('div', { class: 'row' }, h('input', { id: 'np', type: 'text', placeholder: t('temp_password'), autocomplete: 'off', style: 'flex:1' }),
        btn(t('reset_password'), () => attempt(async () => { await api('admin.user.password', { id: u.id, password: document.getElementById('np').value }); toast(t('saved')); }, err))),
      (u.totp || u.passkeys.length > 0) && h('div', { class: 'row', style: 'margin-top:.6rem' }, btn(t('reset_2fa'), () => attempt(async () => { await api('admin.user.totp_reset', { id: u.id }); toast(t('saved')); }, err), 'danger'))),
    err);
  return modal(u ? u.username : t('new_user'), form, (close) => [btn(t('cancel'), () => close(false)), btn(t('save'), () => {
    if (!form.reportValidity()) return;
    const { admin, ...d } = formData(form);
    attempt(async () => { await api('admin.user.save', { id: u?.id, ...d, role: admin ? 'admin' : 'user', enabled: !!d.enabled, max_sessions: +d.max_sessions }); toast(t('saved')); close(true); }, err);
  }, 'primary')]);
}

function adminSettings() {
  const box = h('div', {}, t('loading'));
  attempt(async () => {
    const { settings: s } = await api('admin.settings');
    const err = errBox();
    const list = (k) => h('div', { class: 'card' }, h('h2', {}, t(k + '_list')),
      h('div', { class: 'row', style: 'margin-bottom:.6rem' },
        h('label', { class: 'check' }, h('input', { type: 'radio', name: k + '_mode', value: 'all', checked: s[k].mode === 'all' }), t('all_allowed')),
        h('label', { class: 'check' }, h('input', { type: 'radio', name: k + '_mode', value: 'list', checked: s[k].mode === 'list' }), t('only_list'))),
      h('textarea', { name: k + '_list', rows: 5, class: 'mono', spellcheck: 'false' }, s[k].list.join('\n')),
      h('small', { class: 'muted' }, t('list_hint')));
    const nums = ['login_max_attempts', 'login_lock_minutes', 'login_global_max', 'session_idle_minutes', 'rdp_idle_minutes', 'max_sessions_per_user', 'max_sessions_total',
      'reconnect_attempts', 'audit_retention_days', 'files_quota_mb'];
    const help = { max_sessions_total: t('s_max_sessions_total_help') };
    const form = h('form', { onsubmit: (e) => e.preventDefault() },
      list('clients'), list('targets'),
      h('div', { class: 'card' }, h('div', { class: 'cols' }, nums.map((k) => field(t('s_' + k), h('input', { name: k, type: 'number', value: s[k] }), help[k]))),
        check(t('s_totp_required'), { name: 'totp_required', checked: s.totp_required }),
        check(t('s_trace_all'), { name: 'trace_all', checked: s.trace_all }),
        check(t('s_save_passwords'), { name: 'save_passwords', checked: s.save_passwords }), h('small', { class: 'muted' }, t('s_save_passwords_help'))),
      err, btn(t('save'), () => {
        const d = formData(form);
        const lines = (k) => d[k + '_list'].split(/[\r\n,]+/).map((x) => x.trim()).filter(Boolean);
        const settings = {
          clients: { mode: d.clients_mode, list: lines('clients') }, targets: { mode: d.targets_mode, list: lines('targets') },
          totp_required: !!d.totp_required, trace_all: !!d.trace_all, save_passwords: !!d.save_passwords,
          ...Object.fromEntries(nums.map((k) => [k, +d[k]])),
        };
        attempt(async () => {
          if (s.save_passwords && !settings.save_passwords && !await confirmDialog(t('s_save_passwords_off'))) return;   // erases them
          await api('admin.settings', { settings });
          await loadState();
          toast(t('saved'));
          render();
        }, err);   // the state warns while all targets are allowed
      }, 'primary'));
    fill(box, form);
  });
  return box;
}

function adminSessions() {
  const box = h('div', { class: 'card table-wrap' }, t('loading'));
  const load = () => attempt(async () => {
    const { sessions } = await api('admin.sessions');
    fill(box, sessions.length ? h('table', { class: 'list' },
      h('thead', {}, h('tr', {}, [t('username'), t('conn_name'), t('host'), t('origin'), t('started'), ''].map((x) => h('th', {}, x)))),
      h('tbody', {}, sessions.map((s) => h('tr', {}, h('td', {}, s.user), h('td', {}, s.conn), h('td', { class: 'mono small' }, s.host),
        h('td', { class: 'mono small' }, s.ip), h('td', { class: 'small' }, fmtDate(s.started)),
        h('td', { style: 'text-align:right' }, btn(t('terminate'), () => attempt(async () => { await api('admin.sessions.kill', { sid: s.sid }); setTimeout(load, 1500); }), 'small danger')))))) : h('p', { class: 'muted' }, t('no_sessions')));
  });
  load();
  return h('div', {}, h('div', { class: 'toolbar' }, h('span', { class: 'spacer' }), btn('', load, 'small', 'refresh')), box);
}

function adminAudit() {
  const today = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
  const week = new Date(Date.now() - 6 * 864e5 - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10);
  const form = h('form', { class: 'cols audit-filter', onsubmit: (e) => { e.preventDefault(); load(); } },
    field(t('audit_from'), h('input', { name: 'from', type: 'date', value: week })), field(t('audit_to'), h('input', { name: 'to', type: 'date', value: today })),
    field(t('username'), h('input', { name: 'user', autocomplete: 'off' })), field(t('event'), h('input', { name: 'event', list: 'audit-events', autocomplete: 'off' })),
    field('IP', h('input', { name: 'ip', autocomplete: 'off' })), field(t('audit_text'), h('input', { name: 'q', type: 'search' })),
    field(t('audit_result'), select('ok', { '': t('audit_all'), 1: t('audit_ok'), 0: t('audit_failed') }, '')), h('datalist', { id: 'audit-events' }),
    h('button', { type: 'submit', hidden: true }));
  const sum = h('div', { class: 'audit-sum' });
  const integrity = h('div', {});
  const box = h('div', { class: 'card table-wrap' }, t('loading'));
  let offset = 0, rows = [], total = 0, bad = new Map(), asked = 0;
  const filter = () => formData(form);
  const detail = (r) => {
    const { _d, _n, ...rec } = r;
    modal(r.event, h('div', {}, bad.has(_d + ':' + _n) && h('p', { class: 'error' }, t('audit_' + bad.get(_d + ':' + _n))),
      h('pre', { class: 'mono small audit-json' }, JSON.stringify(rec, null, 2)), h('p', { class: 'small muted' }, `${_d} · #${_n}`)),
      (close) => [btn(t('close'), () => close())]);
  };
  const brief = (r) => Object.entries(r).filter(([k]) => !['t', 'ip', 'user', 'event', 'ok', 'ua', 'ws', 'p', 'h', '_d', '_n'].includes(k))
    .map(([k, v]) => k + '=' + (v !== null && typeof v === 'object' ? JSON.stringify(v) : v)).join(' ');
  const table = () => fill(box, rows.length ? h('table', { class: 'list audit' },
    h('thead', {}, h('tr', {}, [t('when'), t('username'), 'IP', t('event'), t('details')].map((x) => h('th', {}, x)))),
    h('tbody', {}, rows.map((r) => {
      const why = bad.get(r._d + ':' + r._n);
      return h('tr', { class: why ? 'tampered' : '', title: why ? t('audit_' + why) : '', onclick: () => detail(r) },
        h('td', { class: 'small nowrap' }, fmtDate(r.t)), h('td', {}, r.user || ''), h('td', { class: 'mono small nowrap' }, r.ip),
        h('td', { class: 'nowrap' }, h('span', { class: 'badge' + (r.ok === true ? ' on' : r.ok === false ? ' fail' : '') }, r.event)),
        h('td', { class: 'mono small audit-brief' }, brief(r)));
    })))
    : h('p', { class: 'muted' }, t('audit_none')),
  rows.length < total && btn(t('more'), () => load(true), 'small'));
  const load = (more = false) => attempt(async () => {
    const n = ++asked, from = more ? offset + 100 : 0;
    const j = await api('admin.audit', { filter: filter(), offset: from });
    if (n !== asked) return;                             // a later search was started: its answer is the one shown
    offset = from;
    rows = more ? rows.concat(j.rows) : j.rows;
    total = j.total;
    form.querySelector('datalist').replaceChildren(...Object.keys(j.events).map((e) => h('option', { value: e })));
    fill(sum, h('span', {}, t('audit_found', total, j.failures)), ' ',
      Object.entries(j.events).slice(0, 12).map(([e, n]) => h('a', { href: '#', class: 'badge', onclick: (ev) => {
        ev.preventDefault(); form.elements.event.value = e; load(); } }, `${e} ${n}`)),
      h('div', { class: 'small muted' }, j.retention ? t('audit_retention', j.retention) : t('audit_unlimited')));
    table();
  });
  // the gateway sends the export file itself; the JSON one, signed, is followed by its signature in base64: both are
  // kept byte for byte in a ZIP (audit.json and audit.json.sig). The CSV is made here and not signed.
  const save = (fmt) => attempt(async () => {
    const r = await fetch('api.php', { method: 'POST', cache: 'no-store', headers: { 'Content-Type': 'application/json', 'X-CSRF': S.csrf },
      body: JSON.stringify({ action: 'admin.audit', filter: filter(), export: fmt }) });
    if (!r.ok) throw new ApiError((await r.json().catch(() => ({}))).error || 'internal');
    const bytes = new Uint8Array(await r.arrayBuffer());
    const end = fmt === 'json' ? bytes.lastIndexOf(10) + 1 : bytes.length;   // the file ends with a line break
    const file = bytes.subarray(0, end);
    const j = JSON.parse(new TextDecoder().decode(file));
    const name = `audit-${j.from}-${j.to}`;
    if (fmt === 'json') {
      const sig = Uint8Array.from(atob(new TextDecoder().decode(bytes.subarray(end))), (c) => c.charCodeAt(0));
      if (!sig.length) throw new ApiError('sign_missing');
      saveBlob(zipBlob([{ name: 'audit.json', parts: [file], crc: crc32(file) }, { name: 'audit.json.sig', parts: [sig], crc: crc32(sig) }]), name + '.zip');
    } else {
      const q = (v) => '"' + String(v ?? '').replace(/^[=+\-@\t\r]/, "'$&").replace(/"/g, '""') + '"';   // no formulas in spreadsheets
      saveBlob(new Blob(['﻿date;user;ip;event;ok;details\r\n' + j.rows.map((x) => [x.t, x.user, x.ip, x.event, x.ok ?? '', brief(x)].map(q).join(';')).join('\r\n')],
        { type: 'text/csv' }), name + '.csv');
    }
    if (j.rows.length < j.total) toast(t('audit_capped', j.rows.length));
  });
  // public key of the signatures and how to check a file, asked for when opened (the key is made the first time)
  const keyBox = h('div', {});
  const keyInfo = h('details', { class: 'small', ontoggle: () => keyInfo.open && !keyBox.firstChild && attempt(async () => {
    const { key } = await api('admin.audit.key');
    fill(keyBox, h('p', { class: 'muted' }, t('audit_key_text')), h('pre', { class: 'mono audit-json' }, key),
      h('div', { class: 'row' }, btn(t('copy'), () => navigator.clipboard?.writeText(key).then(() => toast(t('copied')), () => toast(t('clip_denied'), true)), 'small'),
        btn(t('download'), () => saveBlob(new Blob([key], { type: 'application/x-pem-file' }), 'mudesk-audit-key.pem'), 'small', 'download')),
      h('p', { class: 'muted' }, t('audit_key_cmd')), h('pre', { class: 'mono audit-json' }, 'openssl dgst -sha256 -verify mudesk-audit-key.pem -signature audit.json.sig audit.json'));
  }) }, h('summary', { class: 'muted' }, t('audit_key_h')), keyBox);
  const verify = () => attempt(async () => {
    fill(integrity, h('p', { class: 'muted' }, t('loading')));
    const v = await api('admin.audit.verify');
    bad = new Map(v.problems.map(([d, n, why]) => [d + ':' + n, why]));
    fill(integrity, h('div', { class: 'card ' + (v.ok ? 'audit-ok' : 'audit-bad') },
      h('strong', {}, v.ok ? t('audit_intact', v.lines, v.first || '-') : t('audit_broken', v.problem_count ?? v.problems.length, v.lines)),
      v.unchained > 0 && h('div', { class: 'small muted' }, t('audit_unchained', v.unchained)),
      v.problems.length > 0 && h('ul', { class: 'small mono' }, v.problems.slice(0, 50).map(([d, n, why]) => h('li', {}, h('a', { href: '#', onclick: (e) => {
        e.preventDefault(); form.elements.from.value = form.elements.to.value = d; load(); } }, `${d} #${n}`), ' ', t('audit_' + why))))));
    load();
  });
  form.addEventListener('change', () => load());
  load();
  return h('div', {},
    h('div', { class: 'card' }, form, h('div', { class: 'toolbar', style: 'margin:0' }, btn(t('audit_search'), () => load(), 'primary'),
      h('span', { class: 'spacer' }), btn('CSV', () => save('csv'), 'small', 'download', t('audit_export')), btn('JSON', () => save('json'), 'small', 'download', t('audit_export_signed')),
      btn(t('audit_verify'), verify, 'small', 'lock')), keyInfo),
    integrity, sum, box);
}

function adminTraces() {
  const box = h('div', { class: 'card table-wrap' }, t('loading'));
  const load = () => attempt(async () => {
    const { traces, keep_days } = await api('admin.traces');
    const get = (sid) => attempt(async () => {
      const j = await api('admin.trace', { sid });
      saveBlob(new Blob([j.text], { type: 'text/plain' }), j.name);
    });
    fill(box, h('p', { class: 'small muted' }, t('traces_hint', keep_days)), traces.length ? h('table', { class: 'list' },
      h('thead', {}, h('tr', {}, [t('started'), t('username'), t('conn_name'), t('host'), t('size'), ''].map((x) => h('th', {}, x)))),
      h('tbody', {}, traces.map((x) => h('tr', {}, h('td', { class: 'small nowrap' }, fmtDate(x.date)), h('td', {}, x.user), h('td', {}, x.conn),
        h('td', { class: 'mono small' }, x.host), h('td', { class: 'small nowrap' }, fmtSize(x.size), x.running && [' ', h('span', { class: 'badge on' }, t('running'))]),
        h('td', { style: 'text-align:right;white-space:nowrap' }, btn('', () => get(x.sid), 'small', 'download', t('download')),
          btn('', () => attempt(async () => { await api('admin.trace', { sid: x.sid, delete: 1 }); load(); }), 'small danger', 'trash', t('delete'))))))) : h('p', { class: 'muted' }, t('no_traces')));
  });
  load();
  return h('div', {}, h('div', { class: 'toolbar' }, h('span', { class: 'spacer' }), btn('', load, 'small', 'refresh')), box);
}

// ------------------------------------------------------------------ remote session

async function viewSession(id) {
  document.title = t('app');
  fill(app, h('div', { class: 'center muted' }, t('loading')));
  const data = await attempt(() => api('conns.list'));
  const conn = data?.conns.find((c) => c.id === id);
  if (!conn) { fill(app, h('div', { class: 'center' }, h('div', { class: 'auth' }, errText('not_found')))); return; }
  document.title = conn.name + ' · ' + t('app');

  const canvas = h('canvas', { width: 1024, height: 768, tabindex: -1 });
  const keyboard = h('textarea', { class: 'kbd', autocapitalize: 'off', autocomplete: 'off', spellcheck: 'false', 'aria-label': conn.name });
  const overlay = h('div', { class: 'overlay' });
  const panel = h('div', { class: 'panel hidden' });
  const bar = h('div', { class: 'bar' });
  // RemoteApp: its windows over the page, with a taskbar at the bottom (a minimized window is restored from there)
  const taskbar = conn.app ? h('div', { class: 'rail-taskbar', role: 'toolbar', 'aria-label': t('rail_windows') }) : null;
  // RemoteApp: in the middle of the page while there is no window of the program (it takes a while to close and to open)
  const railWait = conn.app ? h('div', { class: 'rail-wait hidden', role: 'status' }) : null;
  const root = h('div', { class: 'session' + (conn.app ? ' rail' : '') }, canvas, keyboard, railWait, taskbar, bar, panel, overlay);
  fill(app, root);

  const s = new RdpSession({ container: root, canvas, keyboard, conn, csrf: S.csrf, retries: S.user.reconnect ?? 10, onState });
  let panelOpen = null, extra = {}, clip = null, savePw = null, statsTimer = 0;
  endRdp();                                              // a session left by a previous view of this page
  currentRdp = s;
  s.leave = new AbortController();                       // the window's listeners of this view go with it
  forgetRdp = () => { extra = {}; s.extra = {}; savePw = null; };
  const popups = screenWindows = [], clipHistory = [];  // the clipboard history lives as long as this window
  let osk = null;                                        // the on-screen keyboard, while it is shown
  let oskAt = null;                                      // where the user left it: [left, top] in px, while this window lasts
  let restartAll = false;                                // reconnecting with all the screens of this computer (see offerAllScreens)
  let screenDetails = null;                              // the screens (Window Management API), kept up to date by the browser
  /** Ctrl, Alt, Shift and Win fixed from the key panel or the on-screen keyboard: shown pressed in both. */
  const syncSticky = () => {
    root.querySelectorAll('[data-code]').forEach((b) => b.classList.toggle('on', s.sticky.has(b.dataset.code)));
    const hint = osk?.querySelector('.hint');             // on the keyboard: which keys are held and how to send one alone
    if (!hint) return;
    const keys = [...osk.querySelectorAll('button')];
    const held = [...s.sticky].map((c) => keys.find((b) => b.dataset.code === c)?.textContent.replace('⇧ ', '') || c);
    hint.textContent = held.length ? t(held.length === 1 ? 'osk_held_one' : 'osk_held_many', held.join(' + ')) : '';
  };
  /** On-screen keyboard (computers with a mouse): opened and closed with its toolbar button; it can sit at the top or the bottom. */
  async function toggleOsk(button) {
    if (osk) { osk.remove(); osk = null; button.classList.remove('on'); return; }
    const local = await navigator.keyboard?.getLayoutMap?.().catch(() => null);   // labels for layouts other than es/us (Chromium)
    const press = (code) => { s.tapKey(code); syncSticky(); };
    // dragged with the mouse from its grip or its edge (not from a key) anywhere in the window, never out of it
    const place = (x, y) => {
      const box = root.getBoundingClientRect(), r = osk.getBoundingClientRect();
      oskAt = [Math.round(Math.max(0, Math.min(box.width - r.width, x))), Math.round(Math.max(0, Math.min(box.height - r.height, y)))];
      Object.assign(osk.style, { left: oskAt[0] + 'px', top: oskAt[1] + 'px', bottom: 'auto', transform: 'none' });
    };
    const drag = (e) => {
      if (e.button !== 0 || e.target.closest('button:not(.grip)')) return;
      e.preventDefault();                                  // the session keeps the keyboard focus
      const box = root.getBoundingClientRect(), r = osk.getBoundingClientRect(), [dx, dy] = [e.clientX - r.left, e.clientY - r.top];
      const move = (m) => place(m.clientX - dx - box.left, m.clientY - dy - box.top);
      const end = () => {
        ['pointermove', 'pointerup', 'pointercancel'].forEach((t, i) => osk.removeEventListener(t, i ? end : move));
        osk.classList.remove('moving');
      };
      osk.setPointerCapture?.(e.pointerId);
      osk.classList.add('moving');
      osk.addEventListener('pointermove', move);
      osk.addEventListener('pointerup', end);
      osk.addEventListener('pointercancel', end);
    };
    osk = h('div', { class: 'osk', role: 'group', 'aria-label': t('show_keyboard'), onpointerdown: drag },
      h('div', { class: 'hint', role: 'status' }),
      keyboardRows(conn.kbd, t, local).map((row, i) => h('div', { class: 'r' }, row.map(([code, label, shifted, w]) =>
        h('button', { type: 'button', 'data-code': code, title: label ? null : t('key_space'), style: w ? 'flex-grow:' + w : null,
          onpointerdown: (e) => e.preventDefault(),        // the session keeps the keyboard focus
          onclick: () => press(code) }, shifted && h('span', { class: 's' }, shifted), label)),
        i === 0 && [h('button', { type: 'button', class: 'tool grip', title: t('osk_move'), 'aria-label': t('osk_move') }, icon('move')),
        h('button', { type: 'button', class: 'tool', title: t('close'), 'aria-label': t('close'), onpointerdown: (e) => e.preventDefault(),
          onclick: () => toggleOsk(button) }, icon('x'))])));
    root.append(osk);
    if (oskAt) place(...oskAt);                          // where it was left (still inside the window)
    syncSticky();
    button.classList.add('on');
  }

  const toggle = (name, fn) => { panelOpen = panelOpen === name ? null : name; panel.classList.toggle('hidden', !panelOpen); panel.style.top = bar.offsetHeight + 8 + 'px'; if (panelOpen) fn(); bar.querySelectorAll('[data-p]').forEach((b) => b.classList.toggle('on', b.dataset.p === panelOpen)); };
  /** Title of a toolbar panel, with its close button. */
  const panelHead = (title) => h('div', { class: 'panel-head' }, h('h2', {}, title),
    h('button', { type: 'button', class: 'btn small', title: t('close'), 'aria-label': t('close'), onclick: () => { toggle(panelOpen, null); s.focus(); } }, icon('x')));
  const barBtn = (ic, title, fn, p = null) => h('button', { type: 'button', title, 'aria-label': title, 'data-p': p, onclick: (e) => { fn(e); if (!p) s.focus(); } }, icon(ic));

  function showClip() {
    const txt = h('textarea', { placeholder: t('clip_hint') }, clip.remoteText || '');
    const at = (ms) => new Date(ms).toLocaleTimeString(getLang(), { hour: '2-digit', minute: '2-digit' });
    const preview = (s) => { s = s.replace(/\s+/g, ' ').trim(); return s.length > 160 ? s.slice(0, 160) + '…' : s; };
    fill(panel, panelHead(t('clipboard_panel')), h('p', { class: 'small muted' }, t('clip_hint')), txt,
      h('div', { class: 'row', style: 'margin:.6rem 0' },
        btn(t('clip_copy'), () => navigator.clipboard?.writeText(txt.value).then(() => toast(t('saved')), () => toast(t('clip_denied'), true)), 'small'),
        btn(t('clip_send'), () => clip.sendText(txt.value).then(() => toast(t('saved'))), 'small primary')),
      // like Win+V: what went through the shared clipboard either way, to paste again
      h('h3', {}, t('clip_history')), h('p', { class: 'small muted' }, t('clip_history_hint')),
      clip.history.length ? h('ul', { class: 'clip-history' }, clip.history.map((it) => h('li', {},
        it.image ? h('img', { src: it.url, alt: t('clip_image_item') }) : h('div', { class: 'clip-text', title: it.text.slice(0, 1000) }, preview(it.text)),
        h('div', { class: 'row' },
          h('span', { class: 'small muted' }, t(it.from === 'remote' ? 'clip_from_remote' : 'clip_from_local') + ' · ' + at(it.at)),
          h('span', { class: 'spacer' }),
          !it.image && btn(t('clip_paste'), () => { clip.pasteItem(it); toast(t('clip_pasted')); }, 'small primary', null, t('clip_paste_hint')),
          btn(t('clip_copy_item'), () => clip.copyItem(it).then(() => toast(t('clip_copied'))), 'small', null, t('clip_copy_hint')),
          btn('', () => { clip.forget(it); showClip(); }, 'small', 'x', t('clip_remove'))))))
        : h('p', { class: 'small muted' }, t('clip_history_empty')),
      clip.history.length > 0 && btn(t('clip_clear'), () => { clip.clearHistory(); showClip(); }, 'small danger'));
  }
  // ---- screenshot of the remote desktop: whole (every monitor) or a part framed with the mouse or a finger; saved as PNG,
  // copied to this computer's clipboard or put on the remote one. Its pixels only: no toolbar, panels or pointer.
  let shot = null, shooting = false;                     // the last one: { blob, url, w, h }; being made (PNG can take a second)
  const shotName = () => `${conn.name} ${new Date().toLocaleString('sv').replace(/:/g, '-')}.png`.replace(/[\\/:*?"<>|]+/g, '_');
  async function takeShot(area = null) {
    if (shooting || (area && (area[2] < 2 || area[3] < 2))) return;   // one at a time; a click is not a frame
    shooting = true;
    if (panelOpen === 'shot') showShot(); else toggle('shot', showShot);   // shown at once, "Taking the screenshot…"
    const blob = await s.capture(area).catch(() => null);
    shooting = false;
    if (blob) {
      if (shot) URL.revokeObjectURL(shot.url);
      shot = { blob, url: URL.createObjectURL(blob), w: area ? area[2] : s.surface.width, h: area ? area[3] : s.surface.height };
    } else toast(t('shot_failed'), true);
    if (panelOpen === 'shot') showShot();
  }
  async function copyShot() {
    try {
      await navigator.clipboard.write([new ClipboardItem({ 'image/png': shot.blob })]);
      toast(t('shot_copied'));
    } catch { toast(t('shot_copy_denied'), true); }
  }
  /** Frames a part of the remote screen: drag over it (Esc, right click or Cancel: nothing). */
  function cropShot() {
    if (panelOpen) toggle(panelOpen, null);              // out of the way
    let from = null, frame = null, size = null;
    const done = () => { box.remove(); s.focus(); };
    const box = h('div', { class: 'crop', tabindex: -1, role: 'dialog', 'aria-label': t('shot_crop'),
      onkeydown: (e) => { e.stopPropagation(); if (e.key === 'Escape') { e.preventDefault(); done(); } },
      oncontextmenu: (e) => { e.preventDefault(); done(); } },
    h('div', { class: 'crop-hint' }, t('shot_crop_hint'), btn(t('cancel'), done, 'small')));
    const corners = (e) => { const r = canvas.getBoundingClientRect(), k = (v, a, b) => Math.max(a, Math.min(b, v)); return [k(e.clientX, r.left, r.right ?? r.left + r.width), k(e.clientY, r.top, r.bottom ?? r.top + r.height)]; };
    const draw = (e) => {
      const [x1, y1] = corners(e), rb = root.getBoundingClientRect(), area = s.areaOf(canvas, ...from, x1, y1);
      Object.assign(frame.style, { left: Math.min(from[0], x1) - rb.left + 'px', top: Math.min(from[1], y1) - rb.top + 'px',
        width: Math.abs(x1 - from[0]) + 'px', height: Math.abs(y1 - from[1]) + 'px' });
      size.textContent = `${area[2]} × ${area[3]}`;
      return area;
    };
    box.addEventListener('pointerdown', (e) => {
      if (e.button !== 0 || e.target.closest('.crop-hint')) return;
      e.preventDefault();
      box.setPointerCapture?.(e.pointerId);
      from = corners(e);
      frame ??= box.appendChild(h('div', { class: 'crop-frame' }, size = h('span', { class: 'crop-size' })));
      box.classList.add('framing');
      draw(e);
    });
    box.addEventListener('pointermove', (e) => { if (from) draw(e); });
    box.addEventListener('pointerup', (e) => {
      if (!from) return;
      const area = draw(e);
      from = null;
      if (area[2] < 2 || area[3] < 2) { frame.remove(); frame = null; box.classList.remove('framing'); return; }   // a click: frame again
      done();
      takeShot(area);
    });
    root.append(box);
    box.focus();
  }
  function showShot() {
    fill(panel, panelHead(t('shot')), h('p', { class: 'small muted' }, t('shot_hint')),
      h('div', { class: 'row' }, btn(t('shot_full'), () => takeShot(), 'small primary', 'monitor'), btn(t('shot_crop'), cropShot, 'small', 'crop')),
      shooting && h('p', { class: 'muted shot-wait', role: 'status' }, t('shot_taking')),
      !shooting && shot && [h('figure', { class: 'shot' }, h('img', { src: shot.url, alt: t('shot') }), h('figcaption', { class: 'small muted' }, `${shot.w} × ${shot.h} px`)),
        h('div', { class: 'row' },
          btn(t('shot_save'), () => saveBlob(shot.blob, shotName()), 'small primary', 'download'),
          btn(t('shot_copy'), copyShot, 'small', 'copy', t('shot_copy_hint')),
          clip && btn(t('shot_remote'), () => clip.sendImage(shot.blob).then(() => toast(t('shot_on_remote')), () => toast(t('shot_failed'), true)),
            'small', 'clip', t('shot_remote_hint')))]);
  }

  function showFiles() {
    const input = h('input', { type: 'file', multiple: true, class: 'hidden', onchange: () => { clip.sendFiles([...input.files]); input.value = ''; } });
    fill(panel, panelHead(t('files_panel')),
      h('div', { class: 'drop-zone', 'data-drop': t('drop_files_here') },
        h('p', { class: 'small muted' }, t('send_files_hint')), btn(t('send_files'), () => input.click(), 'small primary', 'upload'), input),
      h('div', { style: 'margin-top:1rem' }, clip.filesView()));
  }
  /** The files of a drop; folders are left out (the clipboard takes files only) and said so. */
  function dropFiles(dt) {
    const entries = [...(dt.items || [])].filter((x) => x.kind === 'file').map((x) => x.webkitGetAsEntry?.());
    const files = [...dt.files].filter((f, i) => !entries[i]?.isDirectory);
    if (files.length < dt.files.length) toast(t('folders_not_sent'), true);
    return files;
  }

  /** Multi-monitor: opens monitor i of the session in a window on that screen. */
  function openScreen(i) {
    const sc = s.screens[i].sc;
    const w = window.open('', `mudesk-${conn.id}-${i}`, `popup,left=${sc.availLeft},top=${sc.availTop},width=${sc.availWidth},height=${sc.availHeight}`);
    if (!w) { toast(t('popup_blocked'), true); return; }
    screenView(w, i);
    popups.push(w);
    if (panelOpen === 'screens') showScreens();
  }
  /**
   * Window w shows monitor i of the session: its canvas, a keyboard field and a bar at the top with full screen (on and off)
   * and close. The bar tucks away and comes back with the pointer at the top edge, as the session toolbar; in full screen
   * there was no way out but Esc, which the remote session may take.
   */
  function screenView(w, i) {
    const d = w.document;
    d.title = `${conn.name} · ${i + 1}`;
    d.body.replaceChildren();
    // RemoteApp: the program's windows over a page like this one's (its canvas is transparent)
    const bg = conn.app ? globalThis.getComputedStyle?.(root)?.backgroundColor || '#0f141d' : '#000';
    d.body.style.cssText = `margin:0;background:${bg};overflow:hidden;height:100vh;display:grid;place-items:center`;
    const c = d.createElement('canvas');
    c.style.cssText = 'display:block;touch-action:none';   // its size: in proportion to the window (RdpSession.addView)
    const k = d.createElement('textarea');
    k.style.cssText = 'position:fixed;left:0;top:0;width:1px;height:1px;opacity:0;border:0;padding:0;font-size:16px';
    const bar = d.createElement('div');
    bar.className = 'screen-bar';
    bar.style.cssText = 'position:fixed;top:0;left:50%;z-index:2;display:flex;gap:8px;padding:8px;background:rgba(15,20,29,.9);'
      + 'border-radius:0 0 10px 10px;box-shadow:0 4px 16px rgba(0,0,0,.4);transform:translateX(-50%);transition:transform .2s';
    const button = (cls, f) => {
      const b = d.createElement('button');
      b.type = 'button';
      b.className = cls;
      b.style.cssText = 'padding:8px 14px;font:14px system-ui;border:0;border-radius:6px;cursor:pointer;background:#fff;color:#111';
      b.addEventListener('click', () => { f(); k.focus({ preventScroll: true }); });
      return b;
    };
    const fs = button('fs', () => (d.fullscreenElement ? d.exitFullscreen() : d.documentElement.requestFullscreen())?.catch?.(() => {}));
    const shut = button('close', () => w.close());          // its monitor leaves the remote desktop (pagehide)
    shut.textContent = t('close_this_screen');
    bar.append(fs, shut);
    let tuck = 0;
    const show = (ms) => {                                  // shown; tucked away after ms (0: kept while the pointer is on it)
      bar.style.transform = 'translateX(-50%)';
      w.clearTimeout(tuck);
      if (ms) tuck = w.setTimeout(() => { bar.style.transform = 'translate(-50%, -100%)'; }, ms);
    };
    const label = () => { fs.textContent = d.fullscreenElement ? t('exit_fullscreen') : t('fullscreen'); };
    d.addEventListener('fullscreenchange', () => { label(); show(3000); });
    d.addEventListener('pointermove', (e) => { if (e.clientY < 12) show(2500); });
    bar.addEventListener('pointerenter', () => show(0));
    bar.addEventListener('pointerleave', () => show(1500));
    label();
    show(4000);
    d.body.append(c, k, bar);
    // it closes itself once this window is gone (closed, crashed or another page), when this page could not close it
    const watch = d.createElement('script');
    watch.src = new URL('assets/screen-watch.js?v=' + BUILD, location.href).href;
    (d.head || d.body).append(watch);
    s.addView(c, k, i);
    w.addEventListener('pagehide', () => { s.removeView(c); if (panelOpen === 'screens') showScreens(); });   // its monitor goes
  }
  /** Where a window's screen is on the desktop (Firefox: screen.left/top; Safari: availLeft/availTop) and its size. */
  const screenRect = (win) => { const sc = win.screen; return { x: sc.left ?? sc.availLeft ?? 0, y: sc.top ?? sc.availTop ?? 0, w: sc.width, h: sc.height }; };
  const sameScreen = (a, b) => a.x === b.x && a.y === b.y && a.w === b.w && a.h === b.h;
  const knownScreen = () => { try { return JSON.parse(localStorage.getItem(OTHER_SCREEN) || 'null'); } catch { return null; } };
  /**
   * Firefox and Safari have no Window Management API to list the screens. A window opened just past an edge of this screen
   * lands on the screen there, if there is one (Firefox puts it back on this screen otherwise), and its window.screen says
   * at once where that screen is and its size (measured in Firefox 160 with two screens). Browsers allow one new window per
   * click, so one place is tried: where the other screen was found before, else the side where the others must be (when
   * this screen is not at the desktop origin), else the right; at the corner of that screen when it can be known, because
   * Firefox keeps window.moveTo inside the first screen. Returns [window, its screen, or null if it stayed here].
   */
  function probeScreen() {
    const a = screenRect(window), k = knownScreen();
    const [x, y] = k && !sameScreen(k, a) ? [k.x, k.y] : a.x > 0 ? [a.x - 400, a.y] : a.y > 0 ? [a.x, a.y - 300]
      : a.x < 0 ? [a.x + a.w, a.y] : a.y < 0 ? [a.x, a.y + a.h] : [a.x + a.w, a.y];
    const w = window.open('', `mudesk-${conn.id}-1`, `popup,left=${x},top=${y},width=400,height=300`);
    if (!w) { toast(t('popup_blocked'), true); return [null, null]; }
    const o = screenRect(w);
    return [w, sameScreen(o, a) ? null : o];
  }
  /** The session on this screen and screen o, as they are on the desktop (primary: the one at its origin); window w shows o. */
  function twoScreens(w, o) {
    const a = screenRect(window), pri = o.x === 0 && o.y === 0 ? o : a;
    try { localStorage.setItem(OTHER_SCREEN, JSON.stringify(o)); } catch { /* found again next time */ }
    const mon = (r) => ({ vx: r.x - pri.x, vy: r.y - pri.y, w: r.w, h: r.h, primary: r === pri, sc: { availLeft: r.x, availTop: r.y, availWidth: r.w, availHeight: r.h } });
    s.setMonitors([mon(a), mon(o)], 0);
    if (w.screenX === o.x && w.screenY === o.y) w.resizeTo(o.w, o.h);   // at its corner: the whole screen (then "Full screen")
    screenView(w, 1);
    popups.push(w);
    if (panelOpen === 'screens') toggle('screens', null);
  }
  /** Without the API, "use all my screens": the other screen looked for (probeScreen); not found, its window says how. */
  function findScreens() {
    const [w, o] = probeScreen();
    if (!w) return false;
    if (!o) { screenByHand(w); return false; }
    twoScreens(w, o);
    root.requestFullscreen?.().catch(() => {});
    return true;
  }
  /** The same once connected: found, the session reconnects with a remote monitor on each screen (see onState). */
  function findScreensNow() {
    if (findScreens()) { restartAll = true; s.stop(); }
  }
  /** Not found beside this screen: the user takes the window to the other one; its place is remembered for next time. */
  function screenByHand(w) {
    const d = w.document, el = (tag, css, text = '') => { const e = d.createElement(tag); e.style.cssText = css; e.textContent = text; return e; };
    d.title = `${conn.name} · 2`;
    d.body.replaceChildren();
    d.body.style.cssText = 'margin:0;height:100vh;display:grid;place-items:center;background:#0f141d;color:#e5e9f2;font:16px/1.45 system-ui,sans-serif;text-align:center';
    const box = el('div', 'max-width:30rem;padding:1.5rem'), note = el('p', 'color:#f59e0b;min-height:1.5em');
    const use = el('button', 'padding:.7rem 1.2rem;font:600 16px system-ui,sans-serif;border:0;border-radius:8px;background:#3b82f6;color:#fff;cursor:pointer',
      t('screen_use_this'));
    use.addEventListener('click', () => {
      const o = screenRect(w);
      if (sameScreen(o, screenRect(window))) { note.textContent = t('screen_same'); return; }
      twoScreens(w, o);
      if (s.sid) { restartAll = true; s.stop(); }         // connected on one screen: back with both
    });
    box.append(el('p', '', t('screen_not_found')), note, use);
    d.body.append(box);
    popups.push(w);
  }
  function showScreens() {
    if (!s.multi) {                                      // Firefox, Safari: the other screen looked for on request
      fill(panel, panelHead(t('screens')), h('p', { class: 'small muted' }, t('screens_find_hint')), btn(t('screens_find'), findScreensNow, 'small primary'));
      return;
    }
    // a screen is part of the remote desktop only while its window is open (closing it brings its windows to the others)
    fill(panel, panelHead(t('screens')), h('p', { class: 'small muted' }, t('screens_hint')),
      s.screens.map((m, i) => {
        const open = s.views.find((v) => v.mon === i);
        return h('div', { class: 'row', style: 'margin:.4rem 0' },
          h('span', { class: 'spacer' }, t('screen_label', i + 1, m.w, m.h) + (m.primary ? ' ★' : '')),
          i === s.views[0].mon ? h('span', { class: 'badge on' }, t('this_window'))
            : open ? btn(t('close_screen'), () => { open.canvas.ownerDocument.defaultView.close(); setTimeout(() => panelOpen === 'screens' && showScreens(), 300); }, 'small', null, t('close_screen_hint'))
              : btn(t('open_screen'), () => openScreen(i), 'small primary', null, t('open_screen_hint')));
      }));
  }

  function showKeys() {
    const keys = [['Escape', 'Esc'], ['Tab', 'Tab'], ['ControlLeft', 'Ctrl'], ['AltLeft', 'Alt'], ['ShiftLeft', t('key_shift')], ['MetaLeft', 'Win'],
      ['ArrowUp', '↑'], ['ArrowDown', '↓'], ['ArrowLeft', '←'], ['ArrowRight', '→'], ['Home', t('key_home')], ['End', t('key_end')],
      ['PageUp', t('key_pgup')], ['PageDown', t('key_pgdn')], ['Delete', t('key_del')], ['Backspace', '⌫'], ['Enter', '⏎'],
      ...Array.from({ length: 12 }, (_, i) => ['F' + (i + 1), 'F' + (i + 1)])];
    const grid = h('div', { class: 'keys' }, keys.map(([code, label]) => h('button', { type: 'button', class: 'btn' + (s.sticky.has(code) ? ' on' : ''),
      'data-code': code, onclick: () => { s.tapKey(code); syncSticky(); } }, label)));
    fill(panel, panelHead(t('special_keys')), h('p', { class: 'small muted' }, t('keys_hint')), grid);
  }

  /** A document printed on MuDeskPDF: print it with the browser dialog or download it. */
  async function printJob(job) {
    const r = await fetch(`rdp.php?a=print&sid=${s.sid}&job=${job.job}`, { method: 'POST', headers: { 'X-CSRF': S.csrf } });
    if (!r.ok) { toast(errText((await r.json().catch(() => ({}))).error || 'not_found'), true); return null; }   // e.g. 'csrf': session expired
    return r.blob();
  }
  function onPrint(job) {
    const name = `MuDeskPDF-${new Date().toISOString().slice(0, 19).replace(/[T:]/g, '-')}.${job.type}`;
    const download = async () => {
      const b = await printJob(job);
      if (b) saveBlob(b, name);
    };
    const print = async () => {
      const b = await printJob(job);
      if (!b) return;
      const f = h('iframe', { style: 'position:fixed;width:1px;height:1px;opacity:0;border:0', src: URL.createObjectURL(b) });
      f.addEventListener('load', () => { try { f.contentWindow.focus(); f.contentWindow.print(); } catch { window.open(f.src); } });
      document.body.append(f);
      setTimeout(() => { f.remove(); URL.revokeObjectURL(f.src); }, 600000);
    };
    const box = h('div', { class: 'toast print' }, h('div', {}, t('print_ready', fmtSize(job.size))),
      job.type !== 'pdf' && h('div', { class: 'small' }, t('print_not_pdf', job.type.toUpperCase())),
      h('div', { class: 'row', style: 'margin-top:.5rem' },
        job.type === 'pdf' && btn(t('print_btn'), () => { print(); box.remove(); }, 'small primary'),
        btn(t('print_download'), () => { download(); box.remove(); }, 'small'),
        btn(t('close'), () => box.remove(), 'small')));
    toastBox().append(box);
  }
  s.onSticky = syncSticky;
  s.onPrint = onPrint;
  /** RemoteApp: the program's windows, as a taskbar would list them (to bring one to the front, minimize, maximize or close). */
  function showWins() {
    const wins = s.railWindows();
    const act = (w, what, ic, title) => btn('', () => { s.railCommand(w.id, what); if (what === 'front') s.focus(); }, 'small', ic, title);
    fill(panel, panelHead(t('rail_windows')),
      wins.length ? h('ul', { class: 'rail-wins' }, wins.map((w) => h('li', { class: w.active ? 'active' : '' },
        h('button', { type: 'button', class: 'rail-title', title: t('rail_front'), onclick: () => { s.railCommand(w.id, 'front'); s.focus(); } },
          w.title, w.minimized && h('span', { class: 'badge' }, t('rail_minimized'))),
        h('div', { class: 'row' }, act(w, 'minimize', 'down', t('rail_minimize')), act(w, 'maximize', 'full', t('rail_maximize')),
          act(w, 'close', 'x', t('rail_close')))))) : h('p', { class: 'muted small' }, t('rail_none')));
  }
  /** The taskbar: a button per window, as Windows has: it brings the window to the front (restored when minimized); the
   *  active one is minimized instead. Without it, minimizing the program's only window left an empty page. */
  /**
   * The program's windows; before its first one, that it is opening (and, after a while, that it is taking longer than
   * usual), also in the middle of the page with a spinner (else it looked stuck).
   */
  function drawTaskbar(wins) {
    const wait = !wins.length && s.railPending?.(), name = appName(conn.app);
    const said = wait && t(wait === 'slow' ? 'rail_slow' : 'rail_opening', name);
    railWait.classList.toggle('hidden', !wait);
    fill(railWait, wait ? [h('div', { class: 'spinner' }), h('p', {}, said)] : []);
    fill(taskbar, wins.length ? wins.map((w) => h('button', {
      type: 'button', class: 'rail-task' + (w.active && !w.minimized ? ' active' : '') + (w.minimized ? ' min' : ''), title: w.title,
      onclick: () => { s.railCommand(w.id, w.active && !w.minimized ? 'minimize' : 'front'); s.focus(); },
    }, w.title))
      : h('span', { class: 'muted small', role: 'status' }, said || t('rail_none')));
  }
  if (taskbar) drawTaskbar([]);
  s.onRail = (wins) => {
    const a = wins.find((w) => w.active);
    document.title = (a ? a.title + ' · ' : '') + conn.name + ' · ' + t('app');   // the program's active window, as its own window would say
    drawTaskbar(wins);
    if (panelOpen === 'wins') showWins();
  };

  /** Enables the features chosen for this session and builds the toolbar. */
  function setup(opts) {
    clip = opts.clipboard ? new ClipboardBridge(s, { toast, t, onChange: () => panelOpen === 'clip' && showClip() }, clipHistory) : null;
    s.clipboard = clip;
    s.audio = opts.audio ? new AudioPlayer() : null;
    s.mic = opts.mic ? new MicCapture({ send: (pcm, max, frame) => s.sendMic(pcm, max, frame), onError: (k) => toast(t(k), true, 10000) }) : null;
    if (s.audio || s.mic) {
      const unlock = () => { s.audio?.unlock(); s.mic?.unlock(); };   // touch screens allow sound from pointerup / touchend, not pointerdown
      for (const type of ['pointerdown', 'pointerup', 'touchend', 'keydown']) root.addEventListener(type, unlock, true);
    }
    const muteBtn = s.audio && barBtn('sound', t('mute'), (e) => {
      s.audio.setMuted(!s.audio.muted);
      const b = e.currentTarget;
      b.replaceChildren(icon(s.audio.muted ? 'mute' : 'sound'));
      b.title = t(s.audio.muted ? 'unmute' : 'mute');
    });
    const stats = h('span', { class: 'stats', title: t('stats_title') }, statsText(0, 0, 0));
    let last = { ...s.counts, at: performance.now() }, kbs = 0, pinged = last.at;
    clearInterval(statsTimer);
    statsTimer = setInterval(() => {                     // quality indicator, once a second
      if (!root.isConnected) { clearInterval(statsTimer); return; }
      const c = s.counts, now = performance.now(), sec = (now - last.at) / 1000;
      if (c.bytesAt > last.bytesAt) {                    // the stream worker's own clock: no jumps from timers out of step
        kbs = (c.bytes >= last.bytes ? c.bytes - last.bytes : c.bytes) / 1.024 / (c.bytesAt - last.bytesAt);   // a reconnection restarts the count
      }
      stats.textContent = statsText((c.frames - last.frames) / sec, c.rtt, kbs);
      bar.dataset.q = c.rtt < 100 ? 'good' : c.rtt < 250 ? 'fair' : 'poor';
      last = { ...c, at: now };
      if (s.inputSeen && now - pinged > 60000) {         // the remote session is in use: keep the web session alive
        s.inputSeen = false;                               // (it expires when idle; reconnecting and printing need it)
        pinged = now;
        fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: '{"action":"state"}' })
          .then((r) => r.json()).then((j) => { if (j && 'setup' in j && !j.user && S?.user) sessionEnded(j.closed || 'idle'); }).catch(() => {});
      }
    }, 1000);
    // phones and tablets: their own keyboard; with a mouse there is none to open, so ours (any server, any layout)
    const kbBtn = barBtn('keyboard', t('show_keyboard'), () => (touchOnly() ? s.showKeyboard() : toggleOsk(kbBtn)));
    fill(bar,
      h('button', { type: 'button', class: 'grip', title: t('move_toolbar'), 'aria-label': t('move_toolbar') }, icon('grip')),
      h('span', { class: 'title' }, conn.name),
      stats,
      barBtn('full', t('fullscreen'), () => {
        if (document.fullscreenElement) { document.exitFullscreen().catch(() => {}); return; }
        if (!s.multi && (hasScreenApi() ? screen.isExtended : knownScreen() && !touchOnly())) { offerAllScreens(); return; }
        fullAndOthers();
      }),
      barBtn('lock', t('cad'), () => s.combo(['ControlLeft', 'AltLeft', 'Delete'])),
      barBtn('win', t('winkey'), () => s.combo(['MetaLeft'])),
      kbBtn,
      barBtn('keys', t('special_keys'), () => toggle('keys', showKeys), 'keys'),
      (s.multi || !hasScreenApi() && !touchOnly()) && barBtn('monitor', t('screens'), () => toggle('screens', showScreens), 'screens'),
      muteBtn,
      conn.app && barBtn('rows', t('rail_windows'), () => toggle('wins', showWins), 'wins'),
      barBtn('camera', t('shot'), () => toggle('shot', showShot), 'shot'),
      clip && barBtn('clip', t('clipboard_panel'), () => toggle('clip', showClip), 'clip'),
      clip && barBtn('folder', t('files_panel'), () => toggle('files', showFiles), 'files'),
      barBtn('power', t('disconnect'), () => { s.stop(); }),
      barBtn('up', t('hide_toolbar'), () => bar.classList.toggle('tucked')),
      h('button', { type: 'button', class: 'handle', 'aria-label': t('show_toolbar'), onclick: () => bar.classList.remove('tucked') }, icon('down')));
    placeBar();                                          // where it was left (its width known now)
    if (clip) {
      root.dataset.drop = t('drop_hint');                  // shown while files are dragged over the session
      // over an open panel: only to the remote clipboard (the Files panel shows them), never pasted on the screen behind it
      const onPanel = (e) => !panel.classList.contains('hidden') && panel.contains(e.target);
      root.addEventListener('dragover', (e) => {
        e.preventDefault();
        const p = onPanel(e);
        root.classList.toggle('drop', !p);
        panel.classList.toggle('drop-over', p);
      });
      root.addEventListener('dragleave', (e) => {
        if (e.relatedTarget && root.contains(e.relatedTarget)) return;
        root.classList.remove('drop');
        panel.classList.remove('drop-over');
      });
      // files dropped on the remote screen are pasted there (a click on that point and Ctrl+V); elsewhere, only put on the clipboard
      root.addEventListener('drop', (e) => {
        e.preventDefault();
        const p = onPanel(e);
        root.classList.remove('drop');
        panel.classList.remove('drop-over');
        const files = dropFiles(e.dataTransfer);
        if (!files.length) return;
        if (p && panelOpen !== 'files') toggle('files', showFiles);
        clip.sendFiles(files, p ? null : s.pointOf(e));
      });
      window.addEventListener('focus', () => clip.syncFromLocal(), { signal: s.leave.signal });
    }
  }
  bar.addEventListener('mouseenter', () => bar.classList.remove('tucked'));
  // the toolbar moved to a side: dragged by its grip or its title (arrow keys on the grip); where is kept for next time
  // (its centre, as a part of the window's width)
  let barX = null;
  try { const v = parseFloat(localStorage.getItem(BAR_X)); barX = v >= 0 && v <= 1 ? v : null; } catch { /* in the middle */ }
  const placeBar = () => {
    const vw = window.innerWidth || 0, half = bar.getBoundingClientRect().width / 2;
    if (barX === null || !vw) { bar.style.left = ''; return; }
    const x = Math.round(Math.max(half, Math.min(vw - half, barX * vw)));
    bar.style.left = x + 'px';
    barX = x / vw;
  };
  const keepBar = () => { try { localStorage.setItem(BAR_X, String(barX)); } catch { /* kept for this page only */ } };
  bar.addEventListener('pointerdown', (e) => {
    const by = e.target.closest?.('.grip') || e.target.closest?.('.title');
    if (!by || e.button !== 0) return;
    e.preventDefault();
    const vw = window.innerWidth || 1, r = bar.getBoundingClientRect(), dx = e.clientX - (r.left + r.width / 2);
    bar.setPointerCapture?.(e.pointerId);
    bar.classList.add('moving');
    const move = (ev) => { barX = Math.max(0, Math.min(1, (ev.clientX - dx) / vw)); placeBar(); };
    const up = () => {
      for (const [ty, f] of [['pointermove', move], ['pointerup', up], ['pointercancel', up]]) bar.removeEventListener(ty, f);
      bar.classList.remove('moving');
      keepBar();
    };
    bar.addEventListener('pointermove', move);
    bar.addEventListener('pointerup', up);
    bar.addEventListener('pointercancel', up);
  });
  bar.addEventListener('keydown', (e) => {
    if (!e.target.closest?.('.grip') || (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight')) return;
    e.preventDefault();
    const vw = window.innerWidth || 1;
    barX = Math.max(0, Math.min(1, (barX ?? 0.5) + (e.key === 'ArrowLeft' ? -40 : 40) / vw));
    placeBar();
    keepBar();
  });
  window.addEventListener('resize', placeBar, { signal: s.leave.signal });

  const box = (...c) => fill(overlay, h('div', { class: 'box' }, ...c));
  /**
   * Closes this tab, the session's. Browsers may refuse it (Firefox, for a tab opened from a link; Chrome, once it has
   * history): then the page goes back to the connections, instead of doing nothing.
   */
  const closeTab = () => {
    s.stop();
    window.close();
    setTimeout(() => { if (!window.closed) location.hash = '#/conns'; }, 300);
  };
  const closeX = () => h('button', { type: 'button', class: 'box-close', title: t('close_window'), 'aria-label': t('close_window'), onclick: closeTab }, icon('x'));
  const credentialFields = () => [
    !conn.user && field(t('rdp_user'), h('input', { name: 'user', required: true, autocomplete: 'off', placeholder: t('user_forms') })),
    field(t('rdp_password'), h('input', { name: 'password', type: 'password', required: true, autocomplete: 'off' }))];

  /** Connect dialog: optional credentials and the features to use in this session. */
  function connectDialog() {
    const ask = !conn.has_pass;
    const form = h('form', {
      onsubmit: async (e) => {
        e.preventDefault();
        const d = formData(form);
        const opts = { audio: !!d.audio, mic: !!d.mic, clipboard: !!d.clipboard, paste: !!d.paste, drive: !!d.drive, printer: !!d.printer, gfx: d.gfx, bpp: +d.bpp };
        const paste = opts.paste === pasteFor('auto') ? 'auto' : opts.paste ? 'on' : 'off';   // what this device would pick stays automatic
        if (d.remember) attempt(() => api('conns.options', { id: conn.id, opts: { ...opts, paste, ask: false } }));
        savePw = ask && d.save_pw ? d.password : null;
        extra = { ...extra, opts: { ...opts, trace: !!d.trace }, ...(ask ? { password: d.password, ...(d.user ? { user: d.user } : {}) } : {}) };
        if (d.multi) await (hasScreenApi() ? useAllScreens() : findScreens());   // (without the API, a window opened in this click)
        setup(opts);
        s.start(extra);
      },
    },
    h('h2', {}, conn.name), h('p', { class: 'muted small mono' }, conn.host),
    ask && credentialFields(),
    ask && S.user.save_passwords && check(t('save_pw'), { name: 'save_pw' }),
    h('p', { class: 'muted small', style: 'margin:.4rem 0' }, t('connect_options')),
    check(t('audio'), { name: 'audio', checked: conn.audio }),
    h('div', { class: 'check-info' }, check(t('mic'), { name: 'mic', checked: !!conn.mic }), infoBtn(micInfo, t('more_info'))),
    check(t('clipboard'), { name: 'clipboard', checked: conn.clipboard }),
    h('div', { class: 'check-info' }, check(t('paste_typing'), { name: 'paste', checked: pasteFor(conn.paste) }), infoBtn(pasteInfo)),
    h('small', { class: 'muted', style: 'display:block;margin:-.2rem 0 .4rem 1.6rem' }, t('paste_hint')),
    check(t('drive'), { name: 'drive', checked: conn.drive }),
    check(t('printer'), { name: 'printer', checked: conn.printer }),
    conn.app && h('p', { class: 'small muted' }, t('rail_session', appName(conn.app))),
    hasScreenApi() ? screen.isExtended !== false   // only with several screens
      && h('div', { class: 'check-info' }, check(t('all_screens'), { name: 'multi' }), infoBtn(screensInfo, t('more_info')))
      : !touchOnly() && [h('div', { class: 'check-info' }, check(t('all_screens'), { name: 'multi', checked: !!knownScreen() }),   // Firefox, Safari: looked for
        infoBtn(screensInfo, t('more_info'))),
        h('p', { class: 'small muted' }, t('screens_other_browsers'))],
    !conn.app && field(t('gfx'), select('gfx', GFX_MODES(), conn.gfx)),
    field(t('color'), select('bpp', { 16: t('bpp16'), 15: t('bpp15'), 24: t('bpp24'), 32: t('bpp32') }, conn.bpp)),
    check(t('remember_opts'), { name: 'remember' }),
    h('details', { class: 'small' }, h('summary', { class: 'muted' }, t('advanced')), check(t('trace_opt'), { name: 'trace' }), h('small', { class: 'muted' }, t('trace_hint'))),
    h('button', { class: 'btn primary', style: 'width:100%' }, t('connect')));
    box(closeX(), form);
    // the first field, else Connect (not the info buttons: Enter opened the phone keyboard's explanation instead of connecting)
    (form.querySelector('input:not([type=checkbox])') || form.querySelector('button:not(.info)')).focus();
  }
  /** Window Management API: every screen becomes a separate monitor of the remote session. */
  /** One remote monitor per screen of this computer (asks for the browser's permission); false if there is only one or no permission. */
  async function useAllScreens() {
    try {
      const sd = screenDetails = await window.getScreenDetails();
      if (sd.screens.length < 2) return false;
      const pri = sd.screens.find((x) => x.isPrimary) || sd.currentScreen;
      const mons = sd.screens.map((sc) => ({ vx: sc.left - pri.left, vy: sc.top - pri.top, w: sc.width, h: sc.height, primary: sc === pri, sc }));
      s.setMonitors(mons, sd.screens.indexOf(sd.currentScreen));
      await fullAndOthers();                             // chosen now: the other screens' windows (and monitors) at once
      return true;
    } catch {
      toast(t('screens_denied'), true);
      return false;
    }
  }
  /**
   * Full screen here and, in the same click, a window on each screen that has none. Chromium allows only one of them per
   * click (full screen takes the click's activation and the window is blocked), except a window opened right after asking for
   * full screen on a given screen (a "companion" window, with the Window Management permission): so the screen is given.
   * Measured in Edge 157: without it the window was blocked; opening the window first left full screen refused.
   */
  function fullAndOthers() {
    const sc = screenDetails?.currentScreen;
    const full = root.requestFullscreen?.(sc ? { screen: sc } : undefined)?.catch(() => {});   // not awaited: the windows open in this same click
    if (s.multi) s.screens.forEach((m, i) => s.views.some((v) => v.mon === i) || openScreen(i));
    navigator.keyboard?.lock?.().catch(() => {});
    return full;
  }
  /** Full screen asked for on a computer with several screens, the session on one: all of them (reconnecting) or only this one. */
  function offerAllScreens() {
    modal(t('screens_offer_title'), h('p', {}, t('screens_offer')), (close) => [
      btn(t('screens_only_this'), () => { close(); root.requestFullscreen?.().catch(() => {}); }),
      btn(t('screens_use_all'), async () => {
        close();
        if (!hasScreenApi()) findScreensNow();               // (its window opened in this click)
        else if (await useAllScreens()) { restartAll = true; s.stop(); }
      }, 'primary')]);
  }
  function askCredentials() {
    const form = h('form', { onsubmit: (e) => {
      e.preventDefault();
      const d = formData(form);
      if (savePw !== null) savePw = d.password;          // "save password" was ticked: keep the one tried now
      extra = { ...extra, ...d };
      s.start(extra);
    } },
      h('h2', {}, t('ask_credentials', conn.host)), credentialFields(),
      h('button', { class: 'btn primary', style: 'width:100%' }, t('connect')));
    box(closeX(), form);
    form.querySelector('input').focus();
  }
  function onState(state, info) {
    overlay.classList.toggle('hidden', state === 'connected');
    if (taskbar) drawTaskbar(state === 'connected' ? s.railWindows() : []);   // RemoteApp: opening it (a spinner) until its window comes
    if (state === 'ended' && ['auth', 'csrf', 'signed_out'].includes(info.end)) {   // the web session is over: said so
      popups.splice(0).forEach((w) => w.close());        // (the other screens' windows too)
      sessionEnded(info.reason);
      return;
    }
    if (state === 'ended') watchSession();               // checked now if it should be over
    if (state === 'connected') {
      if (savePw) {                                      // "save password" ticked in the connect dialog: stored once it worked
        const password = savePw;
        savePw = null;
        attempt(async () => { await api('conns.password', { id: conn.id, password }); conn.has_pass = true; });
      }
      bar.classList.add('tucked');
      s.focus();
      if (s.multi && s.views.length < s.screens.length && panelOpen !== 'screens') toggle('screens', showScreens);
      return;
    }
    if (state === 'ended' && restartAll) {               // stopped to come back with all the screens (after the old stream's stop)
      restartAll = false;
      setTimeout(() => s.start(extra), 400);
      return;
    }
    if (state === 'ended') popups.splice(0).forEach((w) => w.close());
    // RemoteApp connected again quietly (the server ended the session while its program was opening): the spinner stays
    if ((state === 'connecting' || state === 'reconnecting') && info.quiet) { overlay.classList.add('hidden'); return; }
    if (state === 'connecting' || state === 'reconnecting') return box(h('div', { class: 'spinner' }), state === 'connecting' ? t('connecting') : t('reconnecting', info.attempt, S.user.reconnect));
    // ended
    if (info.end === 'cert_changed') {
      return box(h('h2', {}, t('r_cert_changed')), h('p', {}, t('cert_changed')), h('p', { class: 'small' }, t('cert_new')), h('p', { class: 'mono small' }, info.detail),
        h('div', { class: 'row end' }, btn(t('cancel'), closeTab), btn(t('accept_cert'), () => { extra.trust = info.detail; s.start(extra); }, 'primary')));
    }
    if (info.end === 'auth_failed' && !conn.has_pass) return askCredentials();
    const ei = info.errorInfo && t('ei_' + info.errorInfo) !== 'ei_' + info.errorInfo ? t('ei_' + info.errorInfo) : null;
    const msg = ei || (t('r_' + info.end) !== 'r_' + info.end ? t('r_' + info.end) : t('disconnected'));
    box(h('h2', {}, t('disconnected')), h('p', {}, msg), h('div', { class: 'row end' }, btn(t('close'), closeTab), btn(t('reconnect'), () => s.start(extra), 'primary')));
  }

  if (conn.ask || !conn.has_pass) connectDialog();
  else {
    setup(conn);
    extra = { ...extra, opts: { paste: pasteFor(conn.paste) } };   // only the browser knows whether it is a phone
    s.start(extra);
  }
}

// ------------------------------------------------------------------ start

// the server cannot answer: what happened, in the browser's language, and a way to try again
loadState().then(render, (e) => {
  setLang(browserLang());
  authScreen(h('div', {}, h('p', { class: 'error' }, e instanceof ApiError ? errText(e.message) : t('r_network')),
    h('div', { class: 'row end' }, btn(t('retry'), () => location.reload(), 'primary'))));
});
