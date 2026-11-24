// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// Clipboard image/file test (run after tests/e2e.mjs created the connection).
// XCLIP="prefix to run commands on the remote X display" node tests/e2e_clip.mjs <baseUrl> <admin> <pass> <connName> <outDir>
import { createRequire } from 'node:module';
import { execSync } from 'node:child_process';
import { rmSync, writeFileSync } from 'node:fs';
const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT || 'playwright-core');
const [base, admin, pass, name, out] = process.argv.slice(2);
const X = process.env.XCLIP;
const log = (...a) => console.log(new Date().toISOString().slice(11, 19), ...a);
// the files this test makes, here and on the remote computer: removed when it ends, also when a step fails
process.on('exit', () => {
  for (const f of ['/tmp/remote.bmp', '/tmp/f1.txt', '/tmp/f2.bin', '/tmp/got.bmp', '/tmp/remote-files.zip', '/tmp/rz']) rmSync(f, { recursive: true, force: true });
  try { execSync(`${X} rm -f /tmp/ra.txt /tmp/rb.bin /tmp/got.bmp /tmp/remote.bmp`); } catch { /* the remote computer is gone */ }
});

const browser = await chromium.launch({ executablePath: '/usr/bin/google-chrome', args: ['--no-sandbox'] });
const ctx = await browser.newContext({ viewport: { width: 1100, height: 750 }, acceptDownloads: true });
const page = await ctx.newPage();
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
page.on('console', (m) => m.type() === 'error' && errors.push(m.text()));
await page.goto(base + '/index.php');
await page.fill('input[name=username]', admin);
await page.fill('input[name=password]', pass);
await page.click('button.primary');
await page.waitForSelector('.conn');
await page.goto(base + '/index.php' + await page.getAttribute(`.conn:has-text("${name}") a.btn.primary >> nth=0`, 'href'));
await page.waitForSelector('.overlay form');
await page.click('.overlay form button.primary');
// a computer never seen: its certificate's fingerprints shown first, trusted here (the test's own computer)
await page.waitForSelector('.overlay.hidden, .overlay .cert-prints', { state: 'attached', timeout: 30000 }).catch(() => {});
if (await page.$('.overlay:not(.hidden) .cert-prints')) await page.click('.overlay button.primary');
await page.waitForSelector('.overlay.hidden', { state: 'attached', timeout: 30000 });
await page.waitForTimeout(1500);
log('connected');

// 1) remote image -> local
execSync('convert -size 64x48 gradient:yellow-green BMP3:/tmp/remote.bmp && chmod 644 /tmp/remote.bmp');
execSync(`${X} xclip -i -selection clipboard -t image/bmp /tmp/remote.bmp > /dev/null 2>&1`);
await page.waitForTimeout(3000);
await page.click('.bar button[data-p=clip]');
const img = await page.$eval('.panel img', (i) => new Promise((r) => (i.complete ? r([i.naturalWidth, i.naturalHeight]) : (i.onload = () => r([i.naturalWidth, i.naturalHeight]))))).catch(() => null);
log('remote image in panel:', img);
await page.click('.bar button[data-p=clip]');

// 2) local image -> remote via a paste event
await page.evaluate(async () => {
  const c = new OffscreenCanvas(40, 30);
  const g = c.getContext('2d');
  g.fillStyle = '#ff0000'; g.fillRect(0, 0, 40, 30); g.fillStyle = '#0000ff'; g.fillRect(0, 0, 20, 15);
  const blob = await c.convertToBlob({ type: 'image/png' });
  const dt = new DataTransfer();
  dt.items.add(new File([blob], 'img.png', { type: 'image/png' }));
  document.querySelector('.kbd').dispatchEvent(new ClipboardEvent('paste', { clipboardData: dt, bubbles: true, cancelable: true }));
});
await page.waitForTimeout(3000);
log('remote targets after image paste:', execSync(`${X} timeout 3 xclip -o -selection clipboard -t TARGETS`).toString().trim().split('\n').join(','));
try {
  execSync(`${X} timeout 3 xclip -o -selection clipboard -t image/bmp > /tmp/got.bmp`);
  log('remote image:', execSync('identify -format "%wx%h %[pixel:p{5,5}] %[pixel:p{30,20}]" /tmp/got.bmp').toString());
} catch (e) { log('image read failed (xrdp does not add the BMP header)'); }

// 3) local files -> remote
writeFileSync('/tmp/f1.txt', 'contenido del archivo uno\n');
writeFileSync('/tmp/f2.bin', Buffer.alloc(300000, 7));
await page.click('.bar button[data-p=files]');
await page.setInputFiles('.panel input[type=file]', ['/tmp/f1.txt', '/tmp/f2.bin']);
await page.waitForTimeout(4000);
const targets = execSync(`${X} timeout 3 xclip -o -selection clipboard -t TARGETS`).toString();
log('remote targets after files:', targets.trim().split('\n').join(','));
try { log('uri-list:', execSync(`${X} timeout 3 xclip -o -selection clipboard -t text/uri-list`).toString().trim()); } catch (e) { log('uri-list failed'); }
for (const f of ['f1.txt', 'f2.bin']) {
  try { log('read', f, execSync(`${X} timeout 10 md5sum /home/rdpuser/thinclient_drives/.clipboard/${f}`).toString().trim(), 'local', execSync(`md5sum /tmp/${f}`).toString().split(' ')[0]); } catch (e) { log('read failed', f); }
}
await page.click('.bar button[data-p=files]');

// 4) remote files -> local download
execSync(`${X} sh -c "printf 'archivo remoto A\\n' > /tmp/ra.txt; head -c 1500000 /dev/urandom > /tmp/rb.bin"`);
execSync(`${X} sh -c "printf 'copy\\nfile:///tmp/ra.txt\\nfile:///tmp/rb.bin' | xclip -i -selection clipboard -t x-special/gnome-copied-files > /dev/null 2>&1"`);
await page.waitForTimeout(3000);
await page.click('.bar button[data-p=files]');
const [dl] = await Promise.all([page.waitForEvent('download', { timeout: 30000 }), page.click('.panel .btn.small.primary >> text=/Descargar|Download/')]);
await dl.saveAs('/tmp/remote-files.zip');
log('download', dl.suggestedFilename(), execSync('unzip -l /tmp/remote-files.zip | tail -4').toString().trim().split('\n').join(' | '));
const got = execSync('cd /tmp && rm -rf rz && mkdir rz && cd rz && unzip -q ../remote-files.zip && md5sum ra.txt rb.bin').toString().split('\n').map((l) => l.slice(0, 32)).join();
const orig = execSync('md5sum /tmp/ra.txt /tmp/rb.bin').toString().split('\n').map((l) => l.slice(0, 32)).join();
log('remote files -> zip:', got === orig ? 'OK' : 'FAIL', got);

console.log(JSON.stringify({ errors }));
await browser.close();
