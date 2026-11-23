// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// A folder of this computer as a drive of the session: the server's requests (MS-RDPEFS) answered on a double of the
// browser's File System Access (read and write) and on a folder's files as an upload gives them (read only):
// node tests/localdrive_unit.mjs
'use strict';

let failed = 0;
const check = (name, ok, info = '') => { console.log((ok ? 'OK   ' : 'FAIL ') + name + (info ? ' ' + info : '')); if (!ok) failed++; };
globalThis.window = {};
const { LocalFolder, HandleFs, ListFs } = await import('../public/assets/localdrive.js');

// ------------------------------------------------------------ a folder in memory, as File System Access gives one
const err = (name) => new DOMException(name, name);
const writables = [];                                    // each writer made: [file, whether it kept the file's data]
class FakeFile {
  constructor(name, data = new Uint8Array(0)) { Object.assign(this, { kind: 'file', name, data, time: 1700000000000 }); }
  async getFile() { if (this.unreadable) throw err('NotReadableError'); return new File([this.data], this.name, { lastModified: this.time }); }
  async createWritable({ keepExistingData } = {}) {
    writables.push([this.name, !!keepExistingData]);
    let buf = keepExistingData ? this.data.slice() : new Uint8Array(0);
    const file = this;
    return {
      async abort() { buf = null; },
      async write(arg) {                                 // ({type: 'write', position, data}, or the data alone, as the browser takes)
        const { position, data } = arg instanceof Blob || ArrayBuffer.isView(arg) ? { data: arg } : arg;
        const d = data instanceof Blob ? new Uint8Array(await data.arrayBuffer()) : new Uint8Array(data.buffer ? data.buffer.slice(data.byteOffset, data.byteOffset + data.byteLength) : data);
        const at = position ?? buf.length;
        if (at + d.length > buf.length) { const b = new Uint8Array(at + d.length); b.set(buf); buf = b; }
        buf.set(d, at);
      },
      async truncate(n) { const b = new Uint8Array(n); b.set(buf.subarray(0, n)); buf = b; },
      async close() { file.data = buf; file.time = 1800000000000; },
    };
  }
}
/** A folder (ci: its names in any case, as on Windows and macOS). */
class FakeDir {
  constructor(name, ci = false) { Object.assign(this, { kind: 'directory', name, kids: new Map(), ci }); }
  #k(name) { return this.ci ? name.toLowerCase() : name; }
  async getDirectoryHandle(name, { create } = {}) {
    const k = this.kids.get(this.#k(name));
    if (k) { if (k.kind !== 'directory') throw err('TypeMismatchError'); return k; }
    if (!create) throw err('NotFoundError');
    const d = new FakeDir(name, this.ci); this.kids.set(this.#k(name), d); return d;
  }
  async getFileHandle(name, { create } = {}) {
    if (/[\\/]/.test(name) || name === '..' || name === '.') throw new TypeError('name');
    const k = this.kids.get(this.#k(name));
    if (k) { if (k.kind !== 'file') throw err('TypeMismatchError'); return k; }
    if (!create) throw err('NotFoundError');
    const f = new FakeFile(name); this.kids.set(this.#k(name), f); return f;
  }
  async removeEntry(name) {
    const k = this.kids.get(this.#k(name));
    if (!k) throw err('NotFoundError');
    if (k.kind === 'directory' && k.kids.size) throw err('InvalidModificationError');
    this.kids.delete(this.#k(name));
  }
  async *entries() { for (const h of this.kids.values()) yield [h.name, h]; }
}

// ------------------------------------------------------------ the server's requests, as the gateway passes them
const u16 = (s) => { const b = new Uint8Array(s.length * 2 + 2); for (let i = 0; i < s.length; i++) { b[2 * i] = s.charCodeAt(i); b[2 * i + 1] = s.charCodeAt(i) >> 8; } return b; };
/** As F_DRIVE gives it: u32 its number (here 1000 + its CompletionId), then the request from its DeviceId on. */
function irp(file, cid, major, body = new Uint8Array(32), minor = 0) {
  const d = new Uint8Array(24 + body.length), v = new DataView(d.buffer);
  [1000 + cid, 3, file, cid, major, minor].forEach((x, i) => v.setUint32(i * 4, x, true));
  d.set(body, 24);
  return d;
}
const le = (...fields) => {                              // [bytes, value] pairs: 1, 4 or 8 bytes little-endian
  const out = [];
  for (const [n, x] of fields) { const b = new Uint8Array(n), v = new DataView(b.buffer); if (n === 1) v.setUint8(0, x); else if (n === 4) v.setUint32(0, x, true); else v.setBigUint64(0, BigInt(x), true); out.push(...b); }
  return Uint8Array.from(out);
};
const cat = (...parts) => { const r = new Uint8Array(parts.reduce((n, p) => n + p.length, 0)); let o = 0; for (const p of parts) { r.set(p, o); o += p.length; } return r; };
const create = (path, access, disp, opts, attrs = 0) => { const p = u16(path); return cat(le([4, access], [8, 0], [4, attrs], [4, 7], [4, disp], [4, opts], [4, p.length]), p); };
const rw = (len, off, data = new Uint8Array(0)) => cat(le([4, len], [8, off]), new Uint8Array(20), data);
const info = (cls, buf = new Uint8Array(0)) => cat(le([4, cls], [4, buf.length]), new Uint8Array(24), buf);
const dir = (cls, path, initial = 1) => { const p = u16(path); return cat(le([4, cls], [1, initial], [4, p.length]), new Uint8Array(23), p); };

const replies = [];
const folder = new LocalFolder({ send: (no, b) => replies.push([no, b]) });
let cid = 0;
/** A folder's asker: sends one request, returns its answer: {no (its request's number), cid, status, v (a view after the status), bytes}. */
const asker = (f, out) => async (file, major, body, minor = 0) => {
  f.onRequest(irp(file, ++cid, major, body, minor));
  await f.queue;
  const [no, r] = out.shift(), v = new DataView(r.buffer, r.byteOffset, r.byteLength);
  return { no, dev: v.getUint32(0, true), cid: v.getUint32(4, true), status: v.getUint32(8, true), v: new DataView(r.buffer, r.byteOffset + 12), bytes: r.subarray(12) };
};
const ask = asker(folder, replies);
const id = (a) => a.v.getUint32(0, true);
const hex = (n) => '0x' + (n >>> 0).toString(16);

// ------------------------------------------------------------ read and write (Chrome, Edge)
const root = new FakeDir('Fotos');
const docs = await root.getDirectoryHandle('docs', { create: true });
(await docs.getFileHandle('old.txt', { create: true })).data = new TextEncoder().encode('antes');
folder.use(new HandleFs(root));
const notShared0 = [folder.shared, folder.name, folder.readonly];
const c1 = await ask(0, 0x00, create('\\docs\\a.txt', 0x2, 3, 0x40));               // created (OPEN_IF)
const w1 = await ask(id(c1), 0x04, rw(4, 0, new TextEncoder().encode('hola')));
const w2 = await ask(id(c1), 0x04, rw(2, 6, new TextEncoder().encode('!!')));       // past the end: a gap of zeros
const r1 = await ask(id(c1), 0x03, rw(100, 0));                                      // read: what this handle wrote, saved first
const cl1 = await ask(id(c1), 0x02);
const saved = new TextDecoder().decode((await docs.getFileHandle('a.txt')).data);
check('a file created, written (also past its end), read back and closed: saved in the folder, the right answers',
  c1.status === 0 && c1.bytes[4] === 2 && c1.cid === 1 && c1.no === 1001 && c1.dev === 3 && w1.v.getUint32(0, true) === 4 && w2.status === 0
  && new TextDecoder().decode(r1.bytes.subarray(4)) === 'hola\0\0!!' && cl1.status === 0 && saved === 'hola\0\0!!' && JSON.stringify(notShared0) === '[true,"Fotos",false]',
  JSON.stringify({ c1: [c1.status, c1.bytes[4]], saved }));

const c2 = await ask(0, 0x00, create('\\docs\\old.txt', 0x1, 1, 0x40));              // opened
const q4 = await ask(id(c2), 0x05, info(4)), q5 = await ask(id(c2), 0x05, info(5)), q9 = await ask(id(c2), 0x05, info(9));
const r2 = await ask(id(c2), 0x03, rw(3, 1));
const r3 = await ask(id(c2), 0x03, rw(10, 100));                                      // past the end: nothing
await ask(id(c2), 0x02);
check('a file opened: its times and attributes, its size, a class not supported; a piece read, nothing past its end',
  c2.bytes[4] === 1 && q4.v.getUint32(0, true) === 36 && q4.v.getUint32(36, true) === 0x80 && q4.v.getBigUint64(4, true) === 1700000000000n * 10000n + 116444736000000000n
  && q5.v.getBigUint64(12, true) === 5n && q5.bytes[4 + 21] === 0 && hex(q9.status) === '0xc00000bb'
  && new TextDecoder().decode(r2.bytes.subarray(4)) === 'nte' && r3.v.getUint32(0, true) === 0, JSON.stringify({ q9: hex(q9.status) }));

const refusals = [
  await ask(0, 0x00, create('\\docs\\a.txt', 0x2, 2, 0x40)),                       // CREATE of one there: collision
  await ask(0, 0x00, create('\\nada\\b.txt', 0x2, 2, 0x40)),                       // in a folder not there
  await ask(0, 0x00, create('\\docs\\..\\..\\x.txt', 0x1, 1, 0x40)),               // out of the folder
  await ask(0, 0x00, create('\\docs\\z.txt', 0x1, 1, 0x40)),                       // OPEN of one not there
  await ask(0, 0x00, create('\\docs', 0x1, 1, 0x40)),                              // a folder opened as a file
  await ask(0, 0x00, create('\\docs\\a.txt', 0x1, 1, 0x1)),                        // a file opened as a folder
  await ask(77, 0x03, rw(10, 0)),                                                    // a handle not open
].map((a) => hex(a.status));
check('refused: a collision, a folder not there, a path out of the folder, a file not there, a folder as a file and the other way round, a handle not open',
  JSON.stringify(refusals) === JSON.stringify(['0xc0000035', '0xc000003a', '0xc0000033', '0xc000000f', '0xc00000ba', '0xc0000103', '0xc0000001']), JSON.stringify(refusals));

// a listing, one name at a time with "." and "..", then no more; a pattern; a folder made
const md = await ask(0, 0x00, create('\\docs\\fotos', 0x1, 2, 0x1));
await ask(id(md), 0x02);
const hd = await ask(0, 0x00, create('\\docs', 0x1, 1, 0x1));
const names = [];
for (let i = 0, first = 1; i < 8; i++, first = 0) {
  const a = await ask(id(hd), 0x0C, dir(3, '\\docs\\*', first), 1);
  if (a.status !== 0) { names.push(hex(a.status)); break; }
  const n = a.v.getUint32(4 + 60, true);
  names.push(new TextDecoder('utf-16le').decode(a.bytes.subarray(4 + 93, 4 + 93 + n)) + (a.v.getUint32(4 + 56, true) & 0x10 ? '/' : ''));
}
const txt = [];
for (let i = 0, first = 1; i < 4; i++, first = 0) {
  const a = await ask(id(hd), 0x0C, dir(1, '\\docs\\*.TXT', first), 1);
  if (a.status !== 0) break;
  const n = a.v.getUint32(4 + 60, true);
  txt.push(new TextDecoder('utf-16le').decode(a.bytes.subarray(4 + 64, 4 + 64 + n)));
}
await ask(id(hd), 0x02);
check('a folder listed one name at a time (FileBothDirectoryInformation: ".", "..", its files and folders), then no more; a pattern; a folder made',
  JSON.stringify(names) === JSON.stringify(['./', '../', 'old.txt', 'a.txt', 'fotos/', '0x80000006']) && JSON.stringify(txt) === JSON.stringify(['old.txt', 'a.txt'])
  && md.bytes[4] === 2, JSON.stringify({ names, txt }));

// with the gateway's help: the first name of a listing comes with all the others, written as the next requests would have
// them (the gateway answers those itself)
{
  const lists = [];
  folder.list = (no, answer, rest) => lists.push({ answer, rest });
  const lh = await ask(0, 0x00, create('\\docs', 0x1, 1, 0x1));
  folder.onRequest(irp(id(lh), ++cid, 0x0C, dir(3, '\\docs\\*', 1), 1));
  await folder.queue;
  const got = lists.shift(), rv = new DataView(got.rest.buffer, got.rest.byteOffset, got.rest.byteLength);
  const restNames = [];
  for (let o = 12, k = 0; k < rv.getUint32(8, true); k++) {
    const len = rv.getUint32(o, true), e = got.rest.subarray(o + 4, o + 4 + len);
    restNames.push(new TextDecoder('utf-16le').decode(e.subarray(93, 93 + new DataView(e.buffer, e.byteOffset).getUint32(60, true))));
    o += 4 + len;
  }
  const firstName = new TextDecoder('utf-16le').decode(got.answer.subarray(16 + 93, 16 + 93 + new DataView(got.answer.buffer).getUint32(16 + 60, true)));
  delete folder.list;
  await ask(id(lh), 0x02);
  check('a listing\'s first name sent with all the others (for the gateway to answer), the same as one by one',
    rv.getUint32(0, true) === id(lh) && rv.getUint32(4, true) === 3 && firstName === '.' && JSON.stringify(restNames) === JSON.stringify(['..', 'old.txt', 'a.txt', 'fotos'])
    && replies.length === 0, JSON.stringify({ firstName, restNames }));
}

// renamed (in place of another only when asked), shortened, deleted when closed; a folder deleted only when empty
const target = u16('\\docs\\b.txt');
const rn = await ask(0, 0x00, create('\\docs\\a.txt', 0x10000, 1, 0x40));
const ren = await ask(id(rn), 0x06, info(10, cat(le([1, 0], [1, 0], [4, target.length]), target)));
const eof = await ask(id(rn), 0x06, info(20, le([8, 2])));
await ask(id(rn), 0x02);
const clash = await ask(0, 0x00, create('\\docs\\b.txt', 0x10000, 1, 0x40));
const old = u16('\\docs\\old.txt');
const noReplace = await ask(id(clash), 0x06, info(10, cat(le([1, 0], [1, 0], [4, old.length]), old)));
const del = await ask(id(clash), 0x06, info(13, le([1, 1])));
await ask(id(clash), 0x02);
const full = await ask(0, 0x00, create('\\docs', 0x10000, 1, 0x1));
const notEmpty = await ask(id(full), 0x06, info(13, le([1, 1])));
await ask(id(full), 0x02);
check('renamed (not over another unless asked), shortened, deleted when closed; a folder not empty not deleted',
  ren.status === 0 && eof.status === 0 && hex(noReplace.status) === '0xc0000035' && del.status === 0 && !docs.kids.has('a.txt') && !docs.kids.has('b.txt')
  && docs.kids.has('old.txt') && hex(notEmpty.status) === '0xc0000101' && root.kids.has('docs'), JSON.stringify([...docs.kids.keys()]));

// written, not closed yet: its size as written told (its information, a listing) and the same file opened twice shares what
// was written, saved when the last handle closes (before, each handle saved its own over the other's, and the server was
// told the size of before: its next write went to the start); emptied when opened without copying the old file first
{
  const hA = await ask(0, 0x00, create('\\docs\\w.bin', 0x2, 3, 0x40));
  await ask(id(hA), 0x04, rw(10, 0, new Uint8Array(10).fill(7)));
  const qA = await ask(id(hA), 0x05, info(5));
  const hB = await ask(0, 0x00, create('\\docs\\w.bin', 0x2, 1, 0x40));
  const qB = await ask(id(hB), 0x05, info(5));
  await ask(id(hB), 0x04, rw(2, 10, Uint8Array.of(8, 9)));
  const hl = await ask(0, 0x00, create('\\docs', 0x1, 1, 0x1)), listed = {};
  for (let first = 1; ; first = 0) {
    const a = await ask(id(hl), 0x0C, dir(3, '\\docs\\*', first), 1);
    if (a.status !== 0) break;
    listed[new TextDecoder('utf-16le').decode(a.bytes.subarray(4 + 93, 4 + 93 + a.v.getUint32(4 + 60, true)))] = Number(a.v.getBigUint64(4 + 40, true));
  }
  await ask(id(hl), 0x02);
  await ask(id(hA), 0x02);
  const savedEarly = docs.kids.get('w.bin').data.length;                         // (the other handle still open)
  await ask(id(hB), 0x02);
  const savedLast = Array.from(docs.kids.get('w.bin').data);
  writables.length = 0;
  const hC = await ask(0, 0x00, create('\\docs\\w.bin', 0x2, 5, 0x40));          // overwritten: emptied
  const qC = await ask(id(hC), 0x05, info(5));
  await ask(id(hC), 0x04, rw(1, 0, Uint8Array.of(1)));
  await ask(id(hC), 0x02);
  check('written, not closed yet: its size as written told, in its information and a listing; one file opened twice shares what was written, saved at the last close; emptied when opened, its old data not copied',
    qA.v.getBigUint64(12, true) === 10n && qB.v.getBigUint64(12, true) === 10n && listed['w.bin'] === 12 && savedEarly === 0
    && JSON.stringify(savedLast) === JSON.stringify([7, 7, 7, 7, 7, 7, 7, 7, 7, 7, 8, 9]) && hC.bytes[4] === 3 && qC.v.getBigUint64(12, true) === 0n
    && JSON.stringify(writables) === '[["w.bin",false]]' && JSON.stringify(Array.from(docs.kids.get('w.bin').data)) === '[1]',
    JSON.stringify({ qA: String(qA.v.getBigUint64(12, true)), listed: listed['w.bin'], savedEarly, savedLast, writables }));
}

// a file deleted when its last handle closes (its writes left out); a rename over another one that fails: the other one
// back in its place (before, it was removed first: lost); without move(), a change of letter case only refused (the copy
// was the same file, then removed: lost)
{
  const d1 = await ask(0, 0x00, create('\\docs\\d.txt', 0x10002, 2, 0x40)), d2 = await ask(0, 0x00, create('\\docs\\d.txt', 0x1, 1, 0x40));
  await ask(id(d1), 0x04, rw(3, 0, Uint8Array.of(1, 2, 3)));
  await ask(id(d1), 0x06, info(13, le([1, 1])));
  await ask(id(d1), 0x02);
  const stillThere = docs.kids.has('d.txt');
  await ask(id(d2), 0x02);
  const gone = !docs.kids.has('d.txt');
  (await docs.getFileHandle('dst.txt', { create: true })).data = new TextEncoder().encode('DEST');
  const src = await docs.getFileHandle('src.txt', { create: true });
  src.data = new TextEncoder().encode('SRC');
  const sh = await ask(0, 0x00, create('\\docs\\src.txt', 0x10000, 1, 0x40));
  src.unreadable = true;                                                           // (its copy fails)
  const dst = u16('\\docs\\dst.txt');
  const failed = await ask(id(sh), 0x06, info(10, cat(le([1, 1], [1, 0], [4, dst.length]), dst)));
  await ask(id(sh), 0x02);
  const kept = new TextDecoder().decode(docs.kids.get('dst.txt')?.data ?? new Uint8Array(0));
  const ciRoot = new FakeDir('Ci', true), ciOut = [], ciFolder = new LocalFolder({ send: (no, b) => ciOut.push([no, b]) }), ask2 = asker(ciFolder, ciOut);
  (await ciRoot.getFileHandle('report.txt', { create: true })).data = new TextEncoder().encode('R');
  ciFolder.use(new HandleFs(ciRoot));
  const rh = await ask2(0, 0x00, create('\\report.txt', 0x10000, 1, 0x40));
  const up = u16('\\Report.txt');
  const caseOnly = await ask2(id(rh), 0x06, info(10, cat(le([1, 0], [1, 0], [4, up.length]), up)));
  await ask2(id(rh), 0x02);
  check('deleted when the last handle closes; a failed rename over another leaves it in place; a change of case only refused without move()',
    stillThere && gone && failed.status !== 0 && kept === 'DEST' && docs.kids.has('src.txt') && ![...docs.kids.keys()].some((k) => k.startsWith('~mudesk'))
    && hex(caseOnly.status) === '0xc00000bb' && new TextDecoder().decode(ciRoot.kids.get('report.txt')?.data ?? new Uint8Array(0)) === 'R',
    JSON.stringify({ stillThere, gone, failed: hex(failed.status), kept, names: [...docs.kids.keys()], caseOnly: hex(caseOnly.status) }));
  docs.kids.delete('src.txt');
  docs.kids.delete('dst.txt');
  docs.kids.delete('w.bin');
}

// the drive's name, size and kind; a device control not supported; a lock taken
const vh = await ask(0, 0x00, create('\\', 0x1, 1, 0x1));
const vol = await ask(id(vh), 0x0A, info(1)), attr = await ask(id(vh), 0x0A, info(5)), devi = await ask(id(vh), 0x0A, info(4));
const ioctl = await ask(id(vh), 0x0E), lock = await ask(id(vh), 0x11);
const label = new TextDecoder('utf-16le').decode(vol.bytes.subarray(4 + 18, 4 + 18 + vol.v.getUint32(4 + 12, true)));
check('the drive: its label the folder\'s name, NTFS, a disk; device controls not supported, locks taken',
  label === 'Fotos' && attr.v.getUint32(4, true) === 7 && devi.v.getUint32(4, true) === 7 && devi.v.getUint32(8, true) === 0
  && hex(ioctl.status) === '0xc00000bb' && lock.status === 0, JSON.stringify({ label }));

// another folder chosen while requests wait here: those left out (the gateway answered them: no longer waited for), never
// run on the new folder (an overwrite queued for the old one emptied the new one's file of that name)
{
  const other = new FakeDir('Otra');
  (await other.getFileHandle('r.txt', { create: true })).data = new TextEncoder().encode('keep');
  folder.onRequest(irp(0, ++cid, 0x00, create('\\r.txt', 0x2, 5, 0x40)));
  folder.use(new HandleFs(other));
  await folder.queue;
  check('another folder chosen: the requests still waiting left out, not run on it', replies.length === 0
    && new TextDecoder().decode(other.kids.get('r.txt').data) === 'keep', JSON.stringify({ answers: replies.length }));
  replies.length = 0;
}

// ------------------------------------------------------------ read only (Firefox: the folder's files as an upload gives them)
const files = [['Fotos/leeme.txt', 'hola'], ['Fotos/viaje/playa.jpg', 'JPEG']].map(([p, s]) => {
  const f = new File([s], p.split('/').pop(), { lastModified: 1600000000000 });
  Object.defineProperty(f, 'webkitRelativePath', { value: p });
  return f;
});
folder.use(new ListFs(files));
const roName = [folder.name, folder.readonly];
const ro1 = await ask(0, 0x00, create('\\LEEME.TXT', 0x1, 1, 0x40));                 // (any case)
const ror = await ask(id(ro1), 0x03, rw(10, 0));
const row = await ask(id(ro1), 0x04, rw(2, 0, Uint8Array.of(1, 2)));
await ask(id(ro1), 0x02);
const roRefused = [
  await ask(0, 0x00, create('\\nuevo.txt', 0x2, 2, 0x40)),                          // a new file
  await ask(0, 0x00, create('\\leeme.txt', 0x40000000, 1, 0x40)),                   // opened to write
  await ask(0, 0x00, create('\\leeme.txt', 0x1, 5, 0x40)),                          // overwritten
].map((a) => hex(a.status));
const roDir = await ask(0, 0x00, create('\\viaje', 0x1, 1, 0x1));
const roList = await ask(id(roDir), 0x0C, dir(3, '\\viaje\\*'), 1);
const roVol = await ask(id(roDir), 0x0A, info(5));
check('read only: a file read (in any case), not written, created, opened to write or overwritten; its folders listed; the drive said read only',
  JSON.stringify(roName) === '["Fotos",true]' && new TextDecoder().decode(ror.bytes.subarray(4)) === 'hola' && hex(row.status) === '0xc00000a2'
  && JSON.stringify(roRefused) === '["0xc00000a2","0xc00000a2","0xc00000a2"]' && roDir.status === 0 && roList.status === 0
  && (roVol.v.getUint32(4, true) & 0x00080000) !== 0, JSON.stringify({ row: hex(row.status), roRefused }));

// no longer shared: a request answered "no such device"; the files open forgotten when the connection is made again
folder.stop();
const gone = await ask(0, 0x00, create('\\leeme.txt', 0x1, 1, 0x40));
check('no longer shared: answered that there is no such device', !folder.shared && hex(gone.status) === '0xc000000e', hex(gone.status));
console.log(failed ? `${failed} failed` : 'All tests passed');
process.exit(failed ? 1 : 0);
