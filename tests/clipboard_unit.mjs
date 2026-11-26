// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// Clipboard unit tests: node tests/clipboard_unit.mjs
import { ClipboardBridge, cfHtml, fromCfHtml, HISTORY, HISTORY_TEXT } from '../public/assets/clipboard.js';
import { zipBlob, zipName } from '../public/assets/zip.js';

let failed = 0;
const check = (name, ok, info = '') => { console.log((ok ? 'OK   ' : 'FAIL ') + name + (info ? ' ' + info : '')); if (!ok) failed++; };
const text = (u8) => new TextDecoder().decode(u8);
const field = (u8, k) => +new RegExp(k + ':(\\d+)').exec(text(u8))[1];

// formatted text as Windows expects it ("HTML Format"): CRLF header with byte offsets, UTF-8, ending in NUL
const html = '<html><head><style>p{margin:0}</style></head><body><!--StartFragment--><b>Negrita</b> <span style="color:#c00000">rojo ñ €</span><!--EndFragment--></body></html>';
const cf = cfHtml(html);
check('CF_HTML: header with CRLF and the four offsets', /^Version:0\.9\r\nStartHTML:\d{10}\r\nEndHTML:\d{10}\r\nStartFragment:\d{10}\r\nEndFragment:\d{10}\r\n<html>/.test(text(cf)));
check('CF_HTML: byte offsets right with multibyte characters', text(cf.subarray(field(cf, 'StartFragment'), field(cf, 'EndFragment')))
  === '<b>Negrita</b> <span style="color:#c00000">rojo ñ €</span>');
check('CF_HTML: the document keeps its styles and ends in NUL', text(cf.subarray(field(cf, 'StartHTML'), field(cf, 'EndHTML'))) === html && cf.at(-1) === 0);
const bare = cfHtml('<i>cursiva</i>');
check('CF_HTML: a bare fragment gets a body and the fragment marks',
  text(bare.subarray(field(bare, 'StartFragment'), field(bare, 'EndFragment'))) === '<i>cursiva</i>' && text(bare).includes('<html><body><!--StartFragment-->'));
const body = cfHtml('<html><body class="x"><p>uno</p></body></html>');
check('CF_HTML: fragment marks around the body content', text(body.subarray(field(body, 'StartFragment'), field(body, 'EndFragment'))) === '<p>uno</p>');
const open = cfHtml('<html><body><p>abc</p>');
check('CF_HTML: <body> without </body> keeps the whole fragment', text(open.subarray(field(open, 'StartFragment'), field(open, 'EndFragment'))) === '<p>abc</p>');

// what Windows (Word, browsers) puts on the clipboard -> HTML for the browser
check('CF_HTML read back', fromCfHtml(cf) === html);
check('CF_HTML from Windows with a CRLF header and a NUL', fromCfHtml(new TextEncoder().encode(
  'Version:0.9\r\nStartHTML:0000000105\r\nEndHTML:0000000177\r\nStartFragment:0000000139\r\nEndFragment:0000000143\r\n<html><body>\r\n<!--StartFragment-->hola<!--EndFragment-->\r\n</body></html>\0'))
  === '<html><body>\r\n<!--StartFragment-->hola<!--EndFragment-->\r\n</body></html>');
check('CF_HTML: broken header gives nothing', fromCfHtml(new TextEncoder().encode('Version:0.9\r\nStartHTML:0000000999\r\nEndHTML:0000001999\r\n<b>x</b>')) === '');

// messages from the gateway, with minimal stand-ins for the browser's clipboard, canvas and DOM parser
let local = null;                                       // what the local clipboard holds
const uploads = [];
const bitmaps = { closed: 0 };                          // images decoded for the remote clipboard, closed once drawn
const ctx = { putImageData() {}, drawImage() {}, getImageData: (x, y, w, h) => ({ data: new Uint8ClampedArray(w * h * 4) }) };
Object.assign(globalThis, {
  window: { ClipboardItem: true },
  ClipboardItem: class { constructor(o) { this.o = o; this.types = Object.keys(o); } async getType(t) { return this.o[t]; } },
  document: { createElement: () => ({ getContext: () => ctx, toBlob: (cb) => cb(new Blob(['png'], { type: 'image/png' })) }) },
  ImageData: class { constructor(w, h) { this.data = new Uint8ClampedArray(w * h * 4); } },
  createImageBitmap: async () => ({ width: 1, height: 1, close() { bitmaps.closed++; } }),
  DOMParser: class { parseFromString(h) { return { body: { textContent: h.replace(/<[^>]*>/g, '') } }; } },
  fetch: async (url) => { uploads.push(url); return { ok: true }; },
});
Object.defineProperty(globalThis.navigator, 'clipboard', { configurable: true, value: {
  writeText: async (t) => { local = [new ClipboardItem({ 'text/plain': new Blob([t], { type: 'text/plain' }) })]; },
  write: async (items) => { local = items; }, read: async () => local } });
Object.defineProperty(globalThis.navigator, 'permissions', { configurable: true, value: { query: async () => ({ state: 'granted' }) } });
const sent = [];
const bridge = () => new ClipboardBridge({ sid: 's', csrf: 'c', sendClip: (b) => sent.push(String.fromCharCode(b[0])) }, { toast() {}, t: (k) => k, onChange() {} });
const msg = (kind, b) => { const m = new Uint8Array(b.length + 1); m[0] = kind.charCodeAt(0); m.set(b, 1); return m; };
const utf16 = (s) => { const u = new Uint8Array(s.length * 2 + 2); for (let i = 0; i < s.length; i++) u[2 * i] = s.charCodeAt(i); return u; };
const tick = () => new Promise((r) => setTimeout(r, 10));

let c = bridge();                                       // a data block larger than the text: garbage after its NUL
const t = utf16('hola'), g = new Uint8Array(t.length + 6);
g.set(t); g.set([0x41, 0, 0x42, 0], t.length);
c.onMessage(msg('T', g));
check('remote text: up to its NUL', c.remoteText === 'hola', JSON.stringify(c.remoteText));

c.onMessage(msg('F', new TextEncoder().encode('[{"name":"a.txt","size":3,"dir":false}]')));   // then files copied on the remote side
sent.length = 0;
c.sendText('hola');                                     // the same text copied locally again and pasted in the session
check('local text equal to an older remote text is sent once the remote side copied files', sent.includes('X'));

c = bridge();                                           // an HTML-only copy after a text copy ('L': new remote list)
c.onMessage(msg('T', utf16('texto viejo')));
c.onMessage(msg('L', new Uint8Array(0)));
c.onMessage(msg('H', cfHtml('<b>nuevo</b>')));
await tick();
check('HTML-only remote copy: its own plain text', c.remoteText === 'nuevo' && await local[0].o['text/plain'].text() === 'nuevo', JSON.stringify(c.remoteText));

c = bridge();                                           // a remote image goes to the local clipboard: not sent back on focus
const dib = new Uint8Array(44), dv = new DataView(dib.buffer);   // 1x1 pixel, 32 bits
dv.setUint32(0, 40, true); dv.setInt32(4, 1, true); dv.setInt32(8, 1, true); dv.setUint16(12, 1, true); dv.setUint16(14, 32, true);
c.onMessage(msg('I', dib));
await tick();
sent.length = 0;
await c.syncFromLocal();
check('remote image not sent back when the window gets the focus', uploads.length === 0 && !sent.includes('B'), `uploads=${uploads.length} sent=${sent}`);

// a remote image whose header claims more than it brings is refused (44 bytes claiming 16000 x 16000 made 1 GB on the page;
// 100 x 100 read past its data); a remote file chunk past its announced size ends the download (it was all kept in memory)
{
  const toasts = [];
  const b = new ClipboardBridge({ sid: 's', csrf: 'c', sendClip() {} }, { toast: (m, e) => toasts.push(m + (e ? '!' : '')), t: (k) => k, onChange() {} });
  const fake = (w, h) => { const d = new Uint8Array(44), v = new DataView(d.buffer); v.setUint32(0, 40, true); v.setInt32(4, w, true); v.setInt32(8, h, true); v.setUint16(12, 1, true); v.setUint16(14, 32, true); return d; };
  b.onMessage(msg('I', fake(16000, 16000)));
  b.onMessage(msg('I', fake(100, 100)));
  await tick();
  const image = b.remoteImage;
  b.onMessage(msg('F', new TextEncoder().encode('[{"name":"a.txt","size":3,"dir":false}]')));
  b.remoteFiles[0].got = 0; b.remoteFiles[0].parts = []; b.remoteFiles[0].crc = 0;   // (a download asked for: as #download sets it)
  b.onMessage(msg('C', new Uint8Array([0, 0, 0, 0, 1, 2, 3, 4, 5])));
  check('remote image with a header claiming more than it brings refused; a file chunk past its size ends the download',
    !image && b.remoteFiles[0].got === 0 && toasts.includes('Error!') && b.progress === null, JSON.stringify({ image, got: b.remoteFiles[0].got, toasts }));
}

// an image whose upload failed (network, quota) was never sent again: pasted again, it was taken for the one already on the
// remote clipboard, and the remote Ctrl+V pasted the old content without a word
{
  const fetch0 = globalThis.fetch, closed0 = bitmaps.closed;
  let fail = true, ups = 0;
  globalThis.fetch = async () => { ups++; return { ok: !fail }; };
  const sentB = [];
  const b = new ClipboardBridge({ sid: 's', csrf: 'c', sendClip: (m) => sentB.push(String.fromCharCode(m[0])) }, { toast() {}, t: (k) => k, onChange() {} });
  const img = new Blob([new Uint8Array(100)], { type: 'image/png' });
  const r1 = await b.sendImage(img).then(() => 'ok', () => 'failed');
  fail = false;
  const r2 = await b.sendImage(img).then(() => 'ok', () => 'failed');
  globalThis.fetch = fetch0;
  check('an image whose upload failed is sent again when pasted again', r1 === 'failed' && r2 === 'ok' && ups === 2 && sentB.includes('B'),
    JSON.stringify({ r1, r2, ups, sentB }));
  // each image decoded to be sent (an ImageBitmap) closed once drawn: its memory held until the collector took it
  check('an image sent to the remote clipboard: its decoded bitmap closed', bitmaps.closed - closed0 === 2, String(bitmaps.closed - closed0));
}

// files pasted with Ctrl+V whose upload failed: said so to the session (false), which then presses no V (it pasted what
// the remote clipboard had before); the session's view left: the history's images and the remote image freed
{
  const fetch0 = globalThis.fetch, revoked = [], revoke = URL.revokeObjectURL;
  globalThis.fetch = async () => ({ ok: false });
  URL.revokeObjectURL = (u) => { revoked.push(u); revoke(u); };
  const b = new ClipboardBridge({ sid: 's', csrf: 'c', sendClip() {} }, { toast() {}, t: (k) => k, onChange() {} });
  const pasted = await b.fromPasteEvent({ files: [new File(['abc'], 'a.txt', { type: 'text/plain' })], getData: () => '' });
  globalThis.fetch = fetch0;
  b.onMessage(msg('I', dib));                             // the remote side copies an image
  await tick();
  const urls = [b.remoteImage, ...b.history.map((x) => x.url)];
  b.dispose?.();
  URL.revokeObjectURL = revoke;
  check('files pasted whose upload failed: said to the session; its view left, the clipboard\'s images freed',
    pasted === false && urls.length === 2 && urls.every((u) => u && revoked.includes(u)) && b.history.length === 0 && b.remoteImage === null,
    JSON.stringify({ pasted, urls, revoked }));
}

// a remote image whose row length, in 32 bits, overflowed to a negative number (2^26 pixels of 32 bits) passed the check
// and made a 256 MB image; more than 65,535 remote files are not put in a ZIP (its count is 16 bits: a broken file)
{
  const toasts = [];
  const b = new ClipboardBridge({ sid: 's', csrf: 'c', sendClip() {} }, { toast: (m, e) => toasts.push(m + (e ? '!' : '')), t: (k) => k, onChange() {} });
  const d = new Uint8Array(44), v = new DataView(d.buffer);
  v.setUint32(0, 40, true); v.setInt32(4, 1 << 26, true); v.setInt32(8, 1, true); v.setUint16(12, 1, true); v.setUint16(14, 32, true);
  b.onMessage(msg('I', d));
  await tick();
  const image = b.remoteImage;
  const { zipBlob } = await import('../public/assets/zip.js');
  let zipErr = '';
  try { zipBlob(Array.from({ length: 65536 }, (_, i) => ({ name: 'f' + i, parts: [], crc: 0 }))); } catch (e) { zipErr = e.message; }
  b.remoteFiles = Array.from({ length: 65536 }, (_, i) => ({ name: 'f' + i, size: 1, dir: false }));
  const asked = [];
  b.s.sendClip = (m) => asked.push(m);
  b.download();
  check('remote image with a row past 32 bits refused; no ZIP of more than 65,535 files (said, not asked for)',
    !image && /65,535/.test(zipErr) && asked.length === 0 && toasts.includes('zip_too_big!'), JSON.stringify({ image: !!image, zipErr, asked: asked.length, toasts }));
}

// clipboard history, like Win+V: what went through the shared clipboard either way, newest first, in memory only
{
  const pasted = [], revoked = [];
  const revoke = URL.revokeObjectURL;
  URL.revokeObjectURL = (u) => { revoked.push(u); revoke(u); };
  const h = new ClipboardBridge({ sid: 's', csrf: 'c', sendClip: (b) => sent.push(String.fromCharCode(b[0])), pasteText: (x) => pasted.push(x) },
    { toast() {}, t: (k) => k, onChange() {} });
  const items = () => h.history.map((x) => `${x.text.slice(0, 10)}:${x.from}${x.html ? ':html' : ''}`);
  h.onMessage(msg('T', utf16('uno')));
  h.onMessage(msg('H', cfHtml('<b>uno</b>')));          // its formatting, right behind the text: the same item
  const withHtml = items();
  h.sendText('dos');
  h.onMessage(msg('T', utf16('uno')));                  // copied again over there: moves up
  h.sendText('');
  h.sendText('x'.repeat(HISTORY_TEXT / 2) + 'ñ'.repeat(HISTORY_TEXT / 4 + 1));   // 2 bytes more than a paste message carries: left out
  check('history: newest first, the same text moves up, formatting kept with its text, empty or too long left out',
    JSON.stringify(withHtml) === '["uno:remote:html"]' && JSON.stringify(items()) === '["uno:remote","dos:local"]', JSON.stringify([withHtml, items()]));

  h.onMessage(msg('I', dib));
  await tick();
  const image = h.history[0];
  for (let i = 0; i < HISTORY + 5; i++) h.sendText('t' + i);
  check('history: at most HISTORY items, the oldest go (their images freed)',
    h.history.length === HISTORY && h.history[0].text === 't' + (HISTORY + 4) && h.history.at(-1).text === 't5' && !!image.image && revoked.includes(image.url),
    `${h.history.length} ${h.history[0].text} ${h.history.at(-1).text}`);

  const item = h.history.find((x) => x.text === 't7');
  h.pasteItem(item);
  sent.length = 0;
  const copying = h.copyItem(item);                      // the same text the remote side last got: sent again all the same
  h.onMessage(msg('A', new Uint8Array(0)));
  await copying;
  check('history: "Paste" sends the text to be pasted over there; "Copy" makes it the clipboard on both sides and moves it up',
    pasted.join() === 't7' && sent.join() === 'X' && await local[0].o['text/plain'].text() === 't7' && h.history[0].text === 't7',
    `${pasted} ${sent}`);

  h.onMessage(msg('I', dib));
  await tick();
  const last = h.history[0].url;
  h.forget(h.history[1]);
  h.clearHistory();
  check('history: removing an item or clearing it all frees the images', h.history.length === 0 && revoked.includes(last));
  URL.revokeObjectURL = revoke;
}

// files dropped on the remote screen: uploaded and put on the remote clipboard, then a click where they were dropped and
// Ctrl+V; the gateway's 'P' (an application asked for them) confirms it, else the notice says to paste them by hand.
// Not dropped on the screen (the Files panel): only on the clipboard, as before.
{
  const acts = [], notes = [];
  const mk = () => new ClipboardBridge({ sid: 's', csrf: 'c', sendClip: (b) => acts.push('list ' + String.fromCharCode(b[0])),
    clickAt: (p) => acts.push('click ' + p), combo: (c) => acts.push('keys ' + c.join('+')) },
  { toast: (m, err) => notes.push(m + (err ? ' (error)' : '')), t: (k) => k, onChange() {} });
  const file = new File(['abc'], 'a.txt');
  const drop = async (at, answer, wait) => {
    const d = mk();
    d.pasteWait = wait;
    const p = d.sendFiles([file], at);
    await tick();
    d.onMessage(msg('A', new Uint8Array(0)));            // Windows answered the list
    if (answer) {
      await new Promise((r) => setTimeout(r, 450));      // the click, then Ctrl+V
      d.onMessage(msg('P', new Uint8Array(0)));          // the application there asks for the files
    }
    await p;
    return d;
  };
  await drop([100, 200], true, 3000);
  const late = await drop([5, 6], false, 450);
  late.onMessage(msg('P', new Uint8Array(0)));           // Windows was busy: the paste came after the notice
  await drop(null, false, 50);
  check('files dropped on the remote screen: a click there and Ctrl+V, confirmed by the gateway (also late) or else pasted by hand',
    JSON.stringify(acts) === '["list F","click 100,200","keys ControlLeft+KeyV","list F","click 5,6","keys ControlLeft+KeyV","list F"]'
    && JSON.stringify(notes.filter((n) => n.startsWith('files'))) === '["files_pasting","files_pasted","files_pasting","files_not_pasted (error)","files_pasted","files_ready"]',
    JSON.stringify({ acts, notes }));
}

// the files sent to the remote clipboard are listed in the Files panel with their state: being sent (each one done in turn),
// on the remote clipboard, pasted where they were dropped, failed, or replaced once something else is copied there
{
  const states = [];
  class Node {                                             // just what the Files view uses
    constructor() { this.kids = []; this.style = {}; this.className = ''; this.ownText = ''; }
    append(...n) { this.kids.push(...n); }
    replaceChildren(...n) { this.kids = n; }
    set textContent(v) { this.ownText = v; this.kids = []; }
    get textContent() { return this.ownText + this.kids.map((k) => (typeof k === 'string' ? k : k.textContent)).join(' '); }
  }
  const create0 = document.createElement;
  document.createElement = () => new Node();
  document.createTextNode = (s) => s;
  const fetch0 = globalThis.fetch;
  let failUpload = false;
  globalThis.fetch = async (url) => { uploads.push(url); return { ok: !failUpload }; };
  const b = new ClipboardBridge({ sid: 's', csrf: 'c', sendClip: (m) => { if (m[0] === 70) setTimeout(() => b.onMessage(msg('A', new Uint8Array(0))), 1); },
    clickAt() {}, combo() {} }, { toast() {}, t: (k, n) => k + (n !== undefined ? ':' + n : ''), onChange() {} });
  b.pasteWait = 50;
  const view = b.filesView();
  const text = () => view.textContent;
  const p = b.sendFiles([new File(['abc'], 'uno.txt'), new File(['x'.repeat(2048)], 'dos.bin')]);
  states.push(text());
  await p;
  states.push(text());
  b.onMessage(msg('L', new Uint8Array(0)));                // copied on the remote side
  states.push(text());
  const q = b.sendFiles([new File(['z'], 'tres.txt')], { x: 1, y: 1 });
  await tick();
  b.onMessage(msg('P', new Uint8Array(0)));                // an application asked for them: pasted
  await q;
  states.push(text());
  await b.sendFiles([new File(['v'], 'cinco.txt')]);      // from the panel, then pasted by hand (Ctrl+V there)
  b.onMessage(msg('P', new Uint8Array(0)));
  states.push(text());
  failUpload = true;
  await b.sendFiles([new File(['w'], 'cuatro.txt')]);
  states.push(text());
  globalThis.fetch = fetch0;
  document.createElement = create0;
  check('files sent: listed with their state (sending, on the remote clipboard, replaced, pasted, failed)',
    states[0].startsWith('files_sent_uploading:2') && states[0].includes('uno.txt (3 B)') && states[0].includes('dos.bin (2.0 KB)')
    && states[1].startsWith('files_sent_ready:2') && states[2].startsWith('files_sent_replaced:2') && states[3].startsWith('files_sent_pasted:1')
    && states[3].includes('tres.txt') && states[4].startsWith('files_sent_pasted:1') && states[4].includes('cinco.txt')
    && states[5].startsWith('files_sent_failed:1'), JSON.stringify(states));
}

// the ZIP of a remote copy: its names safe whatever unzips it (the gateway made them so, the page once more): no "..", no
// look-alikes of / \ : . (some unzippers turn them into the real ones), no streams, devices or what Windows refuses
{
  const names = ['..\\..\\Startup\\run.bat', '\uff0e\uff0e\uff0f\uff0e\uff0e\u2215evil.exe\uff1aZone', 'a/CON.txt/b:stream', 'x\u0007y|z?.lnk. ', '...', '\u65e5\u672c/a.txt'];
  const zip = new Uint8Array(await zipBlob(names.map((name) => ({ name, parts: [new Uint8Array(1)], crc: 0 }))).arrayBuffer());
  const text = new TextDecoder().decode(zip);
  check('clipboard ZIP: names safe whatever unzips it', JSON.stringify(names.map(zipName)) === JSON.stringify(['Startup/run.bat', '______evil.exe_Zone', 'a/_CON.txt/b_stream', 'x_y_z_.lnk', '', '\u65e5\u672c/a.txt'])
    && !text.includes('..') && text.includes('Startup/run.bat') && text.includes('a/_CON.txt/b_stream'), JSON.stringify(names.map(zipName)));
}

console.log(failed ? `\n${failed} failed` : '\nAll tests passed');
process.exit(failed ? 1 : 0);
