// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// Clipboard bridge between the local browser and the remote session (text, formatted text, images and files).
'use strict';

import { crc32, zipBlob, ZIP_MAX_ENTRIES } from './zip.js';

const enc = new TextEncoder();

/**
 * Clipboard history, like Win+V: the last HISTORY texts and images that went through the shared clipboard either way, in
 * the memory of the session window only (never sent to the gateway nor stored; gone when the window closes). Texts up to
 * HISTORY_TEXT bytes of UTF-8 (the most a paste message carries), images up to HISTORY_IMAGE, as Windows keeps them.
 */
export const HISTORY = 25, HISTORY_TEXT = 1 << 20, HISTORY_IMAGE = 4 << 20;
/** How long a drop waits for the application there to ask for the files (ms): measured 1.8 s on the Windows 11 desktop,
 *  longer when Windows is busy; without it, they are pasted by hand (a late answer still says they were pasted). */
export const PASTE_WAIT = 10000;
const fits = (text) => text.length <= HISTORY_TEXT / 3 || enc.encode(text).length <= HISTORY_TEXT;
const canvas = (w, h) => Object.assign(document.createElement('canvas'), { width: w, height: h });

/** A size as people read it: bytes, KB, MB, GB. */
const size = (n) => (n < 1024 ? n + ' B' : n < 1048576 ? (n / 1024).toFixed(1) + ' KB' : n < 1073741824 ? (n / 1048576).toFixed(1) + ' MB' : (n / 1073741824).toFixed(1) + ' GB');

/** BITMAPINFO (DIB) -> PNG Blob */
async function dibToPng(u8) {
  const dv = new DataView(u8.buffer, u8.byteOffset, u8.byteLength);
  const hs = dv.getUint32(0, true), w = dv.getInt32(4, true), hRaw = dv.getInt32(8, true);
  const bpp = dv.getUint16(14, true), comp = dv.getUint32(16, true);
  const h = Math.abs(hRaw), topDown = hRaw < 0;
  let off = hs + (comp === 3 && hs === 40 ? 12 : 0);
  const colors = bpp <= 8 ? (dv.getUint32(32, true) || 1 << bpp) : 0;
  const pal = off;
  off += colors * 4;
  const stride = Math.ceil(w * bpp / 32) * 4;            // (not in 32 bits: 2^26 x 32 bits was a negative row)
  // what the header says must be there (a 40-byte image claiming 16000 x 16000 made 1 GB and a long loop on the page)
  if (w <= 0 || h <= 0 || w * h > 1 << 26 || ![1, 4, 8, 16, 24, 32].includes(bpp) || off + stride * h > u8.length) throw new Error('DIB');
  const img = new ImageData(w, h);
  for (let y = 0; y < h; y++) {
    const row = off + (topDown ? y : h - 1 - y) * stride;
    for (let x = 0; x < w; x++) {
      let r, g, b;
      if (bpp === 32 || bpp === 24) { const p = row + x * (bpp >> 3); b = u8[p]; g = u8[p + 1]; r = u8[p + 2]; }
      else if (bpp === 16) {
        const v = u8[row + x * 2] | (u8[row + x * 2 + 1] << 8);
        if (comp === 3) { r = (v >> 11) << 3; g = ((v >> 5) & 0x3f) << 2; b = (v & 0x1f) << 3; }
        else { r = ((v >> 10) & 0x1f) << 3; g = ((v >> 5) & 0x1f) << 3; b = (v & 0x1f) << 3; }
      } else {
        const per = 8 / bpp, byte = u8[row + Math.floor(x / per)];
        const idx = (byte >> ((per - 1 - (x % per)) * bpp)) & ((1 << bpp) - 1);
        b = u8[pal + idx * 4]; g = u8[pal + idx * 4 + 1]; r = u8[pal + idx * 4 + 2];
      }
      const o = (y * w + x) * 4;
      img.data[o] = r; img.data[o + 1] = g; img.data[o + 2] = b; img.data[o + 3] = 255;
    }
  }
  const c = canvas(w, h);
  c.getContext('2d').putImageData(img, 0, 0);
  return new Promise((resolve, reject) => c.toBlob((b) => (b ? resolve(b) : reject(new Error('png'))), 'image/png'));
}

/** Image Blob -> 32-bit bottom-up DIB */
async function imageToDib(blob) {
  const bmp = await createImageBitmap(blob);
  const { width: w, height: h } = bmp;
  const c = canvas(w, h);
  const ctx = c.getContext('2d');
  ctx.drawImage(bmp, 0, 0);
  bmp.close();                                           // (its pixels are in the canvas now: its memory freed at once, not left to the collector)
  const px = ctx.getImageData(0, 0, w, h).data;
  const out = new Uint8Array(40 + w * h * 4);
  const dv = new DataView(out.buffer);
  dv.setUint32(0, 40, true); dv.setInt32(4, w, true); dv.setInt32(8, h, true);
  dv.setUint16(12, 1, true); dv.setUint16(14, 32, true); dv.setUint32(20, w * h * 4, true);
  for (let y = 0; y < h; y++) {
    for (let x = 0; x < w; x++) {
      const s = (y * w + x) * 4, d = 40 + ((h - 1 - y) * w + x) * 4;
      out[d] = px[s + 2]; out[d + 1] = px[s + 1]; out[d + 2] = px[s]; out[d + 3] = px[s + 3];
    }
  }
  return out;
}

const pad = (n) => String(n).padStart(10, '0');
const header = (a, b, c, d) => `Version:0.9\r\nStartHTML:${pad(a)}\r\nEndHTML:${pad(b)}\r\nStartFragment:${pad(c)}\r\nEndFragment:${pad(d)}\r\n`;

/**
 * HTML -> CF_HTML, the Windows "HTML Format": UTF-8 with a header giving the byte offsets of the document and of the
 * fragment that gets pasted (between <!--StartFragment--> and <!--EndFragment-->, added around the body when missing).
 */
export function cfHtml(html) {
  if (!html.includes('<!--StartFragment-->') || !html.includes('<!--EndFragment-->')) {
    const body = /<body[^>]*>/i.exec(html), at = body ? body.index + body[0].length : -1;
    html = at >= 0 ? html.slice(0, at) + '<!--StartFragment-->' + html.slice(at) : '<html><body><!--StartFragment-->' + html + '</body></html>';
    let end = html.toLowerCase().lastIndexOf('</body>');
    if (end < 0) end = html.length;                     // <body> without </body>
    html = html.slice(0, end) + '<!--EndFragment-->' + html.slice(end);
  }
  const n = header(0, 0, 0, 0).length, doc = enc.encode(html);
  const sf = n + enc.encode(html.slice(0, html.indexOf('<!--StartFragment-->') + 20)).length;
  const ef = n + enc.encode(html.slice(0, html.indexOf('<!--EndFragment-->'))).length;
  const out = new Uint8Array(n + doc.length + 1);   // ends with a NUL, as Windows writes it
  out.set(enc.encode(header(n, n + doc.length, sf, ef)));
  out.set(doc, n);
  return out;
}

/** CF_HTML -> HTML document ('' when the header is not valid). */
export function fromCfHtml(u8) {
  const head = new TextDecoder().decode(u8.subarray(0, 300));
  const num = (k) => +(new RegExp(k + ':(\\d+)').exec(head)?.[1] ?? -1);
  let [a, b] = [num('StartHTML'), num('EndHTML')];
  if (a < 0 || b <= a) [a, b] = [num('StartFragment'), num('EndFragment')];
  return a >= 0 && b > a && b <= u8.length ? new TextDecoder().decode(u8.subarray(a, b)) : '';
}

const download = (blob, name) => {
  const a = Object.assign(document.createElement('a'), { href: URL.createObjectURL(blob), download: name });
  document.body.append(a);
  a.click();
  setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 5000);
};

export class ClipboardBridge {
  /** @param ui {toast, t, onChange}  @param history the session window's clipboard history (kept across reconnections) */
  constructor(session, ui, history = []) {
    this.s = session;
    this.ui = ui;
    this.history = history;
    this.remoteText = '';
    this.remoteImage = null;
    this.remoteFiles = [];
    this.sent = null;                                      // the files sent last to the remote clipboard: { files: [{name, size, done}], state }
    this.progress = null;
    this.lastSent = '';
    this.ack = null;
    this.pasteWait = PASTE_WAIT;                           // (a field: the tests shorten it)
  }

  // ------------------------------------------------------------ remote -> local

  onMessage(d) {
    const kind = String.fromCharCode(d[0]), body = d.subarray(1);
    switch (kind) {
      case 'A': this.ack?.(); this.ack = null; break;
      case 'L': this.#replaced(); break;                  // a new remote clipboard
      case 'T': {
        let text = new TextDecoder('utf-16le').decode(body);
        text = text.replace(/\0[\s\S]*$/, '');               // up to its NUL: the data block can be larger than the text
        this.remoteText = text;
        this.lastSent = 'T' + text;
        navigator.clipboard?.writeText(text).catch(() => {});
        this.#remember({ text }, 'remote');
        this.ui.onChange();
        break;
      }
      case 'H': {                                          // formatted text (CF_HTML), right after its plain text if there is one
        const html = fromCfHtml(body);
        if (!html) break;
        if (this.prev !== 'T') {                           // HTML only: its text for the plain format and the panel
          this.remoteText = new DOMParser().parseFromString(html, 'text/html').body.textContent.trim();
          this.lastSent = 'T' + this.remoteText;
        }
        this.#remember({ text: this.remoteText, html }, 'remote');   // the text item, now with its formatting
        this.ui.onChange();
        const blob = (x, type) => new Blob([x], { type });
        if (window.ClipboardItem) navigator.clipboard?.write([new ClipboardItem({ 'text/html': blob(html, 'text/html'), 'text/plain': blob(this.remoteText, 'text/plain') })]).catch(() => {});
        break;
      }
      case 'I':
        this.lastSent = 'I*';                              // the local clipboard gets the remote image: not sent back (syncFromLocal)
        dibToPng(body).then((png) => {
          if (this.remoteImage) URL.revokeObjectURL(this.remoteImage);
          this.remoteImage = URL.createObjectURL(png);
          if (window.ClipboardItem) navigator.clipboard?.write([new ClipboardItem({ 'image/png': png })]).catch(() => {});
          this.#remember({ image: png }, 'remote');
          this.ui.onChange();
        }).catch(() => {});
        break;
      case 'F':
        this.lastSent = '';                                // the remote clipboard holds files: any local text goes
        this.remoteFiles = JSON.parse(new TextDecoder().decode(body));
        this.ui.toast(this.ui.t('remote_files') + ': ' + this.remoteFiles.length);
        this.#refreshFiles();
        break;
      case 'C': {
        const i = body[0] | (body[1] << 8) | (body[2] << 16) | (body[3] << 24);
        const f = this.remoteFiles[i];
        const chunk = body.slice(4);
        if (!f || f.got + chunk.length > f.size) {          // never more than announced (it was all kept in memory)
          this.progress = null;
          this.ui.toast('Error', true);
          this.#refreshFiles();
          break;
        }
        f.parts.push(chunk);
        f.crc = crc32(chunk, f.crc);
        f.got += chunk.length;
        this.#progress(f);
        break;
      }
      case 'Z': this.#saveDownload(); break;
      case 'P':                                            // an application asked for the files: pasted (also by hand, Ctrl+V)
        this.onPasted?.(true);
        if (this.sent?.state === 'ready') { this.sent.state = 'pasted'; this.#refreshFiles(); }
        break;
      case 'R': this.progress = null; this.ui.toast('Error', true); this.#refreshFiles(); break;
    }
    this.prev = kind;
  }

  #progress(f) {
    const total = this.remoteFiles.reduce((n, x) => n + (x.dir ? 0 : x.size), 0) || 1;
    const got = this.remoteFiles.reduce((n, x) => n + (x.got || 0), 0);
    this.progress = { name: f.name, pct: Math.round(got * 100 / total) };
    this.#refreshFiles();
  }

  download() {
    if (this.remoteFiles.length > ZIP_MAX_ENTRIES) {      // a ZIP holds 65,535 at most: past that it was a broken file
      this.ui.toast(this.ui.t('zip_too_big'), true);
      return;
    }
    this.remoteFiles.forEach((f) => Object.assign(f, { parts: [], crc: 0, got: 0 }));
    this.progress = { name: '', pct: 0 };
    this.#refreshFiles();
    this.s.sendClip(new Uint8Array([68]));                // 'D'
  }

  #saveDownload() {
    const files = this.remoteFiles;
    this.progress = null;
    if (files.length === 1 && !files[0].dir) download(new Blob(files[0].parts), files[0].name.split('\\').pop());
    else {
      try {
        download(zipBlob(files), 'MuDesk-' + new Date().toISOString().slice(0, 19).replace(/[T:]/g, '-') + '.zip');
      } catch {
        this.ui.toast(this.ui.t('zip_too_big'), true);    // past 4 GB
      }
    }
    files.forEach((f) => { f.parts = []; });
    this.#refreshFiles();
  }

  filesView() {
    this.view = document.createElement('div');
    this.#refreshFiles();
    return this.view;
  }

  #refreshFiles() {
    if (!this.view) return;
    const { t } = this.ui;
    const v = this.view;
    v.replaceChildren();
    if (this.sent) v.append(this.#sentView());
    if (!this.remoteFiles.length) return;
    const title = document.createElement('p');
    title.className = 'small muted';
    title.textContent = t('remote_files');
    const list = document.createElement('ul');
    list.className = 'small';
    this.remoteFiles.slice(0, 50).forEach((f) => {
      const li = document.createElement('li');
      li.textContent = f.name + (f.dir ? '\\' : ' (' + (f.size / 1024).toFixed(1) + ' KB)');
      list.append(li);
    });
    v.append(title, list);
    if (this.progress) {
      const bar = document.createElement('div');
      bar.className = 'progress';
      const fill = document.createElement('div');
      fill.style.width = this.progress.pct + '%';
      bar.append(fill);
      v.append(document.createTextNode(t('receiving', this.progress.name)), bar);
    } else {
      const b = document.createElement('button');
      b.className = 'btn small primary';
      b.textContent = t('download_zip');
      b.onclick = () => this.download();
      v.append(b);
    }
  }

  /** The files sent last: their state (being sent, on the remote clipboard, pasted, failed, replaced) and each one. */
  #sentView() {
    const { t } = this.ui, s = this.sent;
    const box = document.createElement('div');
    box.className = 'sent-files';
    const head = document.createElement('p');
    head.className = 'small' + (s.state === 'failed' || s.state === 'replaced' ? ' muted' : '');
    head.textContent = t('files_sent_' + s.state, s.files.length);
    const list = document.createElement('ul');
    list.className = 'small';
    s.files.slice(0, 50).forEach((f) => {
      const li = document.createElement('li');
      li.textContent = `${f.name} (${size(f.size)})` + (s.state === 'uploading' ? (f.done ? ' ✓' : ' …') : '');
      list.append(li);
    });
    if (s.files.length > 50) list.append(Object.assign(document.createElement('li'), { textContent: `+${s.files.length - 50}` }));
    box.append(head, list);
    return box;
  }

  /** The remote clipboard now holds something else: the files sent before cannot be pasted any more. */
  #replaced() {
    if (this.sent && this.sent.state !== 'uploading' && this.sent.state !== 'failed' && this.sent.state !== 'replaced') {
      this.sent.state = 'replaced';
      this.#refreshFiles();
    }
  }

  // ------------------------------------------------------------ local -> remote

  #announce(bytes) {
    return new Promise((resolve) => {
      this.ack = resolve;
      this.s.sendClip(bytes);
      setTimeout(() => { if (this.ack === resolve) { this.ack = null; resolve(); } }, 2000);
    });
  }

  sendText(text) {
    this.#remember({ text }, 'local');
    if (!text || this.lastSent === 'T' + text) return Promise.resolve();
    this.lastSent = 'T' + text;
    this.#replaced();
    const b = enc.encode(text);
    const m = new Uint8Array(b.length + 1);
    m[0] = 88;                                             // 'X'
    m.set(b, 1);
    return this.#announce(m);
  }

  /** Text with its formatting (HTML): the server gets both and each application pastes the one it prefers. */
  sendHtml(text, html) {
    this.#remember({ text, html }, 'local');
    if (this.lastSent === 'T' + text) return Promise.resolve();   // also stops echoing back what came from the server
    this.lastSent = 'T' + text;
    this.#replaced();
    const t = enc.encode(text), h = cfHtml(html);
    const m = new Uint8Array(5 + t.length + h.length);
    m[0] = 72;                                             // 'H'
    new DataView(m.buffer).setUint32(1, t.length, true);
    m.set(t, 5);
    m.set(h, 5 + t.length);
    return this.#announce(m);
  }

  async #upload(name, blob) {
    const chunk = 2 * 1048576;
    for (let o = 0; o < blob.size || o === 0; o += chunk) {
      const r = await fetch(`rdp.php?a=clipfile&sid=${this.s.sid}&n=${name}&o=${o}`, {
        method: 'POST', body: blob.slice(o, o + chunk), headers: { 'X-CSRF': this.s.csrf, 'Content-Type': 'application/octet-stream' },
      });
      if (!r.ok) throw new Error('upload');
      if (o + chunk >= blob.size) break;
    }
  }

  async sendImage(blob) {
    this.#remember({ image: blob }, 'local');
    const key = 'I' + blob.size + ':' + blob.type;
    if (this.lastSent === key) return;
    this.lastSent = key;
    this.#replaced();
    try {
      await this.#upload('image.dib', new Blob([await imageToDib(blob)]));
    } catch (e) {
      this.lastSent = null;                                // not on the remote clipboard: pasted again, it is sent again
      throw e;
    }
    await this.#announce(new Uint8Array([66]));            // 'B'
  }

  /**
   * Files from this computer to the remote clipboard. Dropped on the remote screen (at: the remote point), they are pasted
   * there as a drop would leave them: a click on that point (the desktop, an Explorer folder, a mail being written...) and
   * Ctrl+V. The gateway says when the application asks for them ('P'); without it, they stay on the clipboard and the
   * notice says to paste them by hand (Windows may take a moment to apply a new clipboard).
   */
  async sendFiles(files, at = null) {
    if (!files.length || !this.s.sid) return;
    const { t } = this.ui;
    const sent = this.sent = { files: files.map((f) => ({ name: f.name, size: f.size, done: false })), state: 'uploading' };   // shown in the Files panel
    this.#refreshFiles();
    try {
      for (let i = 0; i < files.length; i++) {
        this.ui.toast(t('uploading', files[i].name));
        await this.#upload(i, files[i]);
        sent.files[i].done = true;
        this.#refreshFiles();
      }
    } catch (e) {
      sent.state = 'failed';
      this.#refreshFiles();
      this.ui.toast(t('files_sent_failed', files.length), true);
      return false;                                        // (a Ctrl+V that brought them presses no V: see the session's paste)
    }
    this.lastSent = '';
    const list = enc.encode('F' + JSON.stringify(files.map((f) => ({ name: f.name, size: f.size }))));
    await this.#announce(list);
    sent.state = 'ready';
    this.#refreshFiles();
    if (!at) {
      this.ui.toast(t('files_ready', files.length), false, 10000);   // they are on the remote clipboard, not in a folder yet
      return;
    }
    const n = files.length;
    const pasted = new Promise((resolve) => { this.onPasted = resolve; setTimeout(() => resolve(false), this.pasteWait); });
    this.s.clickAt(at);
    await new Promise((r) => setTimeout(r, 400));          // the click first: the window there takes the focus
    this.s.combo(['ControlLeft', 'KeyV']);
    const busy = this.ui.toast(t('files_pasting', n), false, this.pasteWait);
    const ok = await pasted;
    busy?.remove?.();
    this.ui.toast(t(ok ? 'files_pasted' : 'files_not_pasted', n), !ok, 10000);
    const done = () => { if (this.sent === sent && sent.state === 'ready') { sent.state = 'pasted'; this.#refreshFiles(); } };
    if (ok) done();
    this.onPasted = ok ? null : () => { this.onPasted = null; done(); this.ui.toast(t('files_pasted', n), false, 6000); };   // pasted later
  }

  /** Data from a local paste event (Ctrl+V while the session has focus). */
  async fromPasteEvent(dt) {
    if (!dt) return;
    if (dt.files?.length) {
      const images = [...dt.files].filter((f) => f.type.startsWith('image/'));
      if (images.length === dt.files.length && images.length === 1 && !dt.getData('text/uri-list')) return this.sendImage(images[0]);
      return this.sendFiles([...dt.files]);
    }
    const text = dt.getData('text/plain'), html = dt.getData('text/html');
    if (html) return this.sendHtml(text, html);
    if (text) return this.sendText(text);
  }

  // ------------------------------------------------------------ history

  /** Puts a text ({text, html?}) or an image ({image}) on top of the history; the same one again moves up instead. */
  #remember(item, from) {
    if (item.image ? item.image.size > HISTORY_IMAGE : !item.text || !fits(item.text)) return;
    const key = item.image ? 'I' + item.image.size + ':' + item.image.type : 'T' + item.text;
    const old = this.history.findIndex((x) => x.key === key);
    const origin = old >= 0 ? this.forget(this.history[old]).from : from;   // copied again: it keeps where it came from
    this.history.unshift({ ...item, key, from: origin, at: Date.now(), url: item.image ? URL.createObjectURL(item.image) : null });
    while (this.history.length > HISTORY) this.forget(this.history[HISTORY]);
    if (from === 'local') this.ui.onChange();             // what comes from the remote side refreshes the panel itself
  }

  /** Removes an item from the history; returns it. */
  forget(item) {
    const i = this.history.indexOf(item);
    if (i >= 0) this.history.splice(i, 1);
    if (item.url) URL.revokeObjectURL(item.url);
    return item;
  }

  clearHistory() {
    [...this.history].forEach((x) => this.forget(x));
  }

  /** The session's view left: its pictures freed (an object URL holds its image as long as the page lasts). */
  dispose() {
    this.clearHistory();
    if (this.remoteImage) URL.revokeObjectURL(this.remoteImage);
    this.remoteImage = null;
  }

  /** Pastes a text of the history into the active remote application (the gateway puts it on the remote clipboard, presses
   *  Ctrl+V and then puts back what the clipboard held: see Cliprdr::typeText). */
  pasteItem(item) {
    if (!item.image) this.s.pasteText(item.text);
  }

  /** Makes an item of the history the current clipboard, here and on the remote computer (then Ctrl+V pastes it). */
  async copyItem(item) {
    if (item.image) {
      if (window.ClipboardItem) navigator.clipboard?.write([new ClipboardItem({ [item.image.type]: item.image })]).catch(() => {});
      this.lastSent = '';                                  // also when the image came from there: it becomes current again
      return this.sendImage(item.image);
    }
    if (window.ClipboardItem && item.html) {
      const blob = (x, type) => new Blob([x], { type });
      navigator.clipboard?.write([new ClipboardItem({ 'text/html': blob(item.html, 'text/html'), 'text/plain': blob(item.text, 'text/plain') })]).catch(() => {});
    } else navigator.clipboard?.writeText(item.text).catch(() => {});
    this.lastSent = '';
    return item.html ? this.sendHtml(item.text, item.html) : this.sendText(item.text);
  }

  /** When the window regains focus, mirror the local clipboard if the browser already allows reading it. */
  async syncFromLocal() {
    try {
      const p = await navigator.permissions?.query({ name: 'clipboard-read' });
      if (p?.state !== 'granted' || !navigator.clipboard?.read) return;
      for (const item of await navigator.clipboard.read()) {
        const img = item.types.find((x) => x.startsWith('image/'));
        if (img) return this.lastSent === 'I*' ? undefined : this.sendImage(await item.getType(img));
        const get = async (type) => (item.types.includes(type) ? (await item.getType(type)).text() : '');
        const text = await get('text/plain'), html = await get('text/html');
        if (html) return this.sendHtml(text, html);
        if (text) return this.sendText(text);
      }
    } catch { /* not allowed: Ctrl+V and the panel still work */ }
  }
}
