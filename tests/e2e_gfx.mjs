// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// End-to-end test of the graphics pipeline (H.264): connects with "H.264 graphics" on, then compares the canvas with a
// capture of the remote screen and reports the stream size. NOAVC=1 hides H.264 support (other codecs are used);
// RDP_USER / RDP_PASS (default x / x); REFCMD: command that writes the reference PPM once the session is drawn.
// node tests/e2e_gfx.mjs <baseUrl> <admin> <adminPass> <rdpHost> <rdpPort> <reference.ppm> <outDir> [gfx=1|0]
import { readFileSync, writeFileSync } from 'node:fs';
import { execSync } from 'node:child_process';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT || 'playwright-core');

const [base, admin, adminPass, host, port, ref, out, gfxArg = '1'] = process.argv.slice(2);
const gfx = gfxArg === '1';
const log = (...a) => console.log(new Date().toISOString().slice(11, 19), ...a);

const browser = await chromium.launch({ executablePath: process.env.CHROME || '/usr/bin/google-chrome', args: ['--no-sandbox'] });
const ctx = await browser.newContext({ viewport: { width: 1024, height: 800 } });
const page = await ctx.newPage();
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));

await page.goto(base + '/index.php');
await page.waitForSelector('input[name=username]');
await page.fill('input[name=username]', admin);
await page.fill('input[name=password]', adminPass);
if (await page.$('input[name=password2]')) {   // first run: SETUP_CODE = content of data/setup-code.php after its first line
  await page.fill('input[name=password2]', adminPass);
  await page.fill('input[name=setup_code]', process.env.SETUP_CODE || '');
}
await page.click('button.primary');
await page.waitForSelector('header.top');

const name = `GFX ${port} ${gfx ? 'on' : 'off'}${process.env.BPP ? ' ' + process.env.BPP : ''}`;
if (!(await page.$(`.conn:has-text("${name}")`))) {
  await page.click('text=' + (await page.$('text=Nueva conexión') ? 'Nueva conexión' : 'New connection'));
  await page.fill('.modal input[name=name]', name);
  await page.fill('.modal input[name=host]', host);
  await page.fill('.modal input[name=port]', port);
  await page.fill('.modal input[name=user]', process.env.RDP_USER || 'x');
  await page.fill('.modal input[name=password]', process.env.RDP_PASS || 'x');
  await page.click('.modal summary');
  await page.selectOption('.modal select[name=security]', 'tls');
  await page.selectOption('.modal select[name=bpp]', process.env.BPP || '32');
  await page.selectOption('.modal select[name=gfx]', gfx ? 'on' : 'off');
  await page.click('.modal .btn.primary');
  await page.waitForSelector(`.conn:has-text("${name}")`);
}
const href = await page.getAttribute(`.conn:has-text("${name}") a.btn.primary >> nth=0`, 'href');
const s = await ctx.newPage();
if (process.env.NOAVC) await s.addInitScript(() => { delete window.VideoDecoder; });   // as a browser without H.264
s.on('pageerror', (e) => errors.push('session: ' + e.message));
s.on('console', (m) => (m.type() === 'error' || m.type() === 'warning') && errors.push('session: ' + m.text()));
let bytes = 0;
s.on('requestfinished', async (r) => { if (r.url().includes('a=stream')) bytes += (await r.sizes()).responseBodySize; });
await s.goto(base + '/index.php' + href);
await s.waitForSelector('.overlay form');
await s.click('.overlay form button.primary');
const t0 = Date.now();
await s.waitForSelector('.overlay.hidden', { state: 'attached', timeout: 30000 });
log('connected in', Date.now() - t0, 'ms');
await s.mouse.move(1000, 790);                           // pointer out of the compared area
await s.waitForTimeout(4000);

// compare the canvas with the reference capture (P6 PPM)
if (process.env.REFCMD) execSync(process.env.REFCMD);
const ppm = readFileSync(ref);
const [, W, H, maxval] = ppm.toString('latin1', 0, 64).replace(/#.*\n/g, '').match(/P6\s+(\d+)\s+(\d+)\s+(\d+)/).map(Number);
const wide = maxval > 255;                                 // 16-bit samples (ImageMagick without -depth 8): keep the high byte
const refPx = wide ? ppm.subarray(ppm.length - W * H * 6).filter((_, i) => i % 2 === 0) : ppm.subarray(ppm.length - W * H * 3);
const canvasPx = Buffer.from(await s.evaluate(([w, h]) => {
  const c = document.querySelector('canvas');
  const t = document.createElement('canvas');
  t.width = w; t.height = h;
  t.getContext('2d').drawImage(c, 0, 0);
  return [...t.getContext('2d').getImageData(0, 0, w, h).data];
}, [W, H]));
let sum = 0, max = 0, bad = 0, se = 0;
for (let i = 0, j = 0; i < W * H; i++, j += 4) {
  for (let k = 0; k < 3; k++) {
    const d = Math.abs(canvasPx[j + k] - refPx[i * 3 + k]);
    sum += d; se += d * d; if (d > max) max = d;
    if (d > 48) bad++;
  }
}
const mse = se / (W * H * 3);
log(`diff: mean ${(sum / (W * H * 3)).toFixed(3)} max ${max} psnr ${mse ? (10 * Math.log10(255 * 255 / mse)).toFixed(1) : 'inf'} dB, channels >48: ${bad}`);
await s.screenshot({ path: out + `/gfx-${gfx ? 'on' : 'off'}.png` });
const out2 = Buffer.alloc(W * H * 3);
for (let i = 0; i < W * H; i++) for (let k = 0; k < 3; k++) out2[i * 3 + k] = canvasPx[i * 4 + k];
writeFileSync(out + `/canvas-${gfx ? 'on' : 'off'}.ppm`, Buffer.concat([Buffer.from(`P6\n${W} ${H}\n255\n`), out2]));

// optional: keep the session open (e.g. while the remote screen animates) to compare traffic
const extra = +(process.env.SECONDS || 0);
if (extra) await s.waitForTimeout(extra * 1000);
log('errors:', errors.length ? errors : 'none');
await s.click('button[aria-label="Desconectar"], button[aria-label="Disconnect"]').catch(() => {});
await s.waitForTimeout(1500);
log('stream bytes', bytes, extra ? `(${(bytes / (extra + 6) / 1024).toFixed(1)} KB/s)` : '');
await browser.close();
