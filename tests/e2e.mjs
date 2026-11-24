// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// End-to-end browser test against a running MuDeskClient + RDP server.
// node tests/e2e.mjs <baseUrl> <admin> <adminPass> <rdpHost> <rdpPort> <rdpUser> <rdpPass> <outDir>
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT || 'playwright-core');

const [base, admin, adminPass, host, port, user, pass, out] = process.argv.slice(2);
const log = (...a) => console.log(new Date().toISOString().slice(11, 19), ...a);

const browser = await chromium.launch({ executablePath: process.env.CHROME || '/usr/bin/google-chrome', args: ['--no-sandbox'] });
const ctx = await browser.newContext({ viewport: { width: 1200, height: 800 }, permissions: ['clipboard-read', 'clipboard-write'] });
const page = await ctx.newPage();
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
page.on('console', (m) => m.type() === 'error' && errors.push(m.text()));

await page.goto(base + '/index.php');
await page.waitForSelector('input[name=username]');
if (await page.$('input[name=password2]')) {   // first run: SETUP_CODE = content of data/setup-code.php after its first line
  await page.fill('input[name=setup_code]', process.env.SETUP_CODE || '');
  await page.fill('input[name=username]', admin);
  await page.fill('input[name=password]', adminPass);
  await page.fill('input[name=password2]', adminPass);
} else {
  await page.fill('input[name=username]', admin);
  await page.fill('input[name=password]', adminPass);
}
await page.click('button.primary');
await page.waitForSelector('header.top');
log('logged in');
await page.screenshot({ path: out + '/01-conns.png' });

// create connection
await page.click('text=' + (await page.$('text=Nueva conexión') ? 'Nueva conexión' : 'New connection'));
await page.fill('.modal input[name=name]', 'Prueba ' + port);
await page.fill('.modal input[name=host]', host);
await page.fill('.modal input[name=port]', port);
await page.fill('.modal input[name=user]', user);
await page.fill('.modal input[name=password]', pass);
await page.screenshot({ path: out + '/02-edit.png' });
await page.click('.modal .btn.primary');
await page.waitForSelector('.conn');
log('connection saved');

const href = await page.getAttribute('.conn:has-text("Prueba ' + port + '") a.btn.primary >> nth=0', 'href');
const s = await ctx.newPage();
s.on('pageerror', (e) => errors.push('session: ' + e.message));
s.on('console', (m) => m.type() === 'error' && errors.push('session: ' + m.text()));
await s.goto(base + '/index.php' + href);
await s.waitForSelector('.overlay form');                 // connect dialog with the session options
log('connect dialog:', (await s.$$eval('.overlay form input[type=checkbox]', (l) => l.map((i) => i.name + '=' + i.checked))).join(' '));
await s.click('.overlay form button.primary');
// a computer never seen: its certificate's fingerprints shown first, trusted here (the test's own computer)
await s.waitForSelector('.overlay.hidden, .overlay .cert-prints', { state: 'attached', timeout: 30000 }).catch(() => {});
if (await s.$('.overlay:not(.hidden) .cert-prints')) await s.click('.overlay button.primary');
await s.waitForSelector('.overlay.hidden', { state: 'attached', timeout: 30000 }).catch(async (e) => { await s.screenshot({ path: out + '/03-fail.png' }); console.log(await s.textContent('.overlay')); throw e; });
log('session connected');
await s.waitForTimeout(2500);
await s.screenshot({ path: out + '/03-session.png' });
const size1 = await s.evaluate(() => [document.querySelector('canvas').width, document.querySelector('canvas').height]);
log('remote size', size1);

// keyboard: click into the xterm and type
await s.mouse.click(300, 250);
await s.keyboard.type('echo hola-MuDesk 123', { delay: 30 });
await s.keyboard.press('Enter');
await s.waitForTimeout(1500);
await s.screenshot({ path: out + '/04-typed.png' });

// clipboard local -> remote: paste event with text
await s.evaluate(() => navigator.clipboard.writeText('texto desde el navegador ñ €'));
await s.keyboard.press('Control+V');
await s.waitForTimeout(1500);

// input latency: 120 mouse moves, then the send requests are measured
await s.evaluate(() => {
  const f = window.fetch;
  window.__sends = [];
  window.fetch = (u, o) => { const t0 = performance.now(); const p = f(u, o); if (String(u).includes('a=send')) p.then(() => window.__sends.push(performance.now() - t0)); return p; };
});
const tMove = Date.now();
for (let i = 0; i < 120; i++) { await s.mouse.move(200 + i * 4, 300 + (i % 20)); await s.waitForTimeout(8); }
await s.waitForTimeout(1500);
const sends = await s.evaluate(() => window.__sends);
log('input: moves 120 in', Date.now() - tMove - 1500, 'ms; requests', sends.length, 'avg', (sends.reduce((a, b) => a + b, 0) / Math.max(1, sends.length)).toFixed(1), 'ms max', Math.max(0, ...sends).toFixed(1), 'ms');

// clipboard both ways through the panel (needs XCLIP="command prefix to run xclip on the remote display")
if (process.env.XCLIP) {
  const { execSync } = await import('node:child_process');
  execSync(`echo -n 'desde el remoto ñ €' | ${process.env.XCLIP} xclip -i -selection clipboard > /dev/null 2>&1`);
  await s.waitForTimeout(2500);
  await s.click('.bar button[data-p=clip]');
  await s.waitForTimeout(500);
  log('remote->local:', JSON.stringify(await s.inputValue('.panel textarea')));
  await s.fill('.panel textarea', 'desde el navegador áé');
  await s.click('.panel .btn.primary');
  await s.waitForTimeout(2500);
  log('local->remote:', JSON.stringify(execSync(`${process.env.XCLIP} timeout 3 xclip -o -selection clipboard`).toString()));
  await s.click('.bar button[data-p=clip]');
}

// resize the window -> new remote resolution
await s.setViewportSize({ width: 1000, height: 640 });
await s.waitForTimeout(6000);
const size2 = await s.evaluate(() => [document.querySelector('canvas').width, document.querySelector('canvas').height]);
log('after resize', size2);
await s.screenshot({ path: out + '/05-resized.png' });

const state = await s.evaluate(() => document.querySelector('.overlay')?.className);
log('overlay', state);
console.log(JSON.stringify({ size1, size2, errors }));
await browser.close();
