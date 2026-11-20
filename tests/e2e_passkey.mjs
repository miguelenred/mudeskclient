// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// Passkeys in Chrome with a virtual authenticator, against a copy with an EMPTY data folder (served on "localhost":
// WebAuthn does not accept IP addresses):
//   node tests/e2e_passkey.mjs http://localhost:8200 /path/to/data <rdpHost> <rdpPort> <rdpUser> <rdpPass>
// Passwordless sign-in (PRF) must open the saved RDP password; a passkey without PRF only works as a second step;
// a replayed assertion is refused.
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT || 'playwright-core');

const [base, data, rdpHost, rdpPort, rdpUser, rdpPass] = process.argv.slice(2);
let failed = 0;
const check = (name, ok, info = '') => { console.log((ok ? 'OK   ' : 'FAIL ') + name + (info ? ` (${info})` : '')); if (!ok) failed++; };
const pass = 'Sierra-Clara-' + Math.random().toString(36).slice(2, 8) + '!';

const browser = await chromium.launch({ executablePath: process.env.CHROME || '/usr/bin/google-chrome', args: ['--no-sandbox'] });
const ctx = await browser.newContext({ viewport: { width: 1100, height: 800 } });
const page = await ctx.newPage();
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
if (process.env.DEBUG) page.on('response', async (r) => r.url().includes('api.php') && console.log('  api', r.status(), (await r.text().catch(() => '')).slice(0, 160)));
if (process.env.DEBUG) page.on('console', (m) => console.log('  console', m.text()));
const cdp = await ctx.newCDPSession(page);
await cdp.send('WebAuthn.enable');
const addAuthenticator = (prf) => cdp.send('WebAuthn.addVirtualAuthenticator', { options: {
  protocol: 'ctap2', ctap2Version: 'ctap2_1', transport: 'internal', hasResidentKey: true, hasUserVerification: true,
  isUserVerified: true, hasPrf: prf, automaticPresenceSimulation: true } });
const api = (action, body = {}) => page.evaluate(async ([a, b]) => {
  const s = await (await fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'state' }) })).json();
  const r = await fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF': s.csrf }, body: JSON.stringify({ action: a, ...b }) });
  return { status: r.status, json: await r.json() };
}, [action, body]);
// adding a passkey asks for the current password: the password field of the card that holds "Add passkey"
const passkeyPassword = () => page.locator('.card', { has: page.locator('text=/Añadir passkey|Add passkey/') }).locator('input[type=password]').fill(pass);

// first run with the setup code, then a connection with a saved RDP password
await page.goto(base + '/index.php');
await page.waitForSelector('input[name=setup_code]');
await page.fill('input[name=setup_code]', readFileSync(data + '/setup-code.php', 'utf8').split('\n')[1]);
await page.fill('input[name=username]', 'admin');
await page.fill('input[name=password]', pass);
await page.fill('input[name=password2]', pass);
await page.click('button.primary');
await page.waitForSelector('header.top');
const saved = await api('conns.save', { conn: { name: 'pk', host: rdpHost, port: +rdpPort, user: rdpUser, password: rdpPass, security: 'tls', ask: false, audio: false } });
check('connection with saved password', saved.status === 200);

// passkey with PRF: added from the profile, then passwordless sign-in
const a1 = await addAuthenticator(true);
await page.goto(base + '/index.php#/profile');
await page.fill('input[placeholder^="Nombre"], input[placeholder^="Name"]', 'Virtual PRF');
await passkeyPassword();
await page.click('text=/Añadir passkey|Add passkey/');
await page.waitForSelector('.badge.on >> text=/Sin contraseña|Passwordless/', { timeout: 15000 });
check('passkey with PRF can sign in without password', true);
await page.click('text=/Salir|Sign out/');
await page.waitForSelector('text=/Entrar con passkey|Sign in with a passkey/');

let captured = null;
page.on('request', (r) => { if (r.postData()?.includes('"passkey.login"')) captured = r.postData(); });
await page.click('text=/Entrar con passkey|Sign in with a passkey/');
await page.waitForSelector('header.top', { timeout: 15000 });
check('passwordless sign-in', true);

// the data key came from the passkey: the saved RDP password opens and the session connects
const { json: list } = await api('conns.list');
const conn = list.conns.find((c) => c.name === 'pk');
const s = await ctx.newPage();
await s.goto(base + '/index.php#/s/' + conn.id);
const t0 = Date.now();
await s.waitForSelector('.overlay.hidden', { state: 'attached', timeout: 30000 }).catch(() => {});
await s.waitForTimeout(4000);
const colours = await s.evaluate(() => {                 // the remote desktop is drawn: many different colours
  const c = document.querySelector('canvas');
  const d = c.getContext('2d').getImageData(0, 0, c.width, c.height).data;
  const set = new Set();
  for (let i = 0; i < d.length; i += 4 * 97) set.add((d[i] << 16) | (d[i + 1] << 8) | d[i + 2]);
  return set.size;
});
if (process.env.DEBUG) console.log('  overlay:', await s.evaluate(() => document.querySelector('.overlay')?.className + ' ' + document.querySelector('.overlay')?.textContent));
check('RDP session with the saved password after passwordless sign-in', colours > 8, `${colours} colours, ${Date.now() - t0} ms`);
await s.close();

// replaying the captured assertion is refused (one-time challenge)
const replay = await page.evaluate(async (body) => {
  const s = await (await fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'state' }) })).json();
  return (await fetch('api.php', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF': s.csrf }, body })).status;
}, captured);
check('replayed passkey assertion refused', replay === 401, `HTTP ${replay}`);

// passkey without PRF: only a second step after the password
await cdp.send('WebAuthn.removeVirtualAuthenticator', { authenticatorId: a1.authenticatorId });
await addAuthenticator(false);
await page.goto(base + '/index.php#/profile');
await page.waitForSelector('text=/Añadir passkey|Add passkey/');
await page.fill('input[placeholder^="Nombre"], input[placeholder^="Name"]', 'Virtual no PRF');
await passkeyPassword();
await page.click('text=/Añadir passkey|Add passkey/');
await page.waitForSelector('.badge >> text=/Segundo paso|Second step/', { timeout: 15000 });
check('passkey without PRF stored as second step', true);
await page.click('text=/Salir|Sign out/');
await page.click('text=/Entrar con passkey|Sign in with a passkey/');
const msg = await page.waitForSelector('.error:not(:empty)', { timeout: 15000 }).then((e) => e.textContent(), () => '');
check('...and cannot sign in alone', /segundo paso|second step/i.test(msg), msg);
await page.fill('input[name=username]', 'admin');
await page.fill('input[name=password]', pass);
await page.click('button.primary');
await page.waitForSelector('text=/Usar una passkey|Use a passkey/', { timeout: 15000 });
await page.click('text=/Usar una passkey|Use a passkey/');
await page.waitForSelector('header.top', { timeout: 15000 });
check('password + passkey as second step', true);

check('no page errors', errors.length === 0, errors.join(' | '));
await browser.close();
console.log(failed ? `\n${failed} failed` : '\nAll tests passed');
process.exit(failed ? 1 : 0);
