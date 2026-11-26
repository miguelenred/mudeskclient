// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// A folder of this computer as a drive of the remote session, chosen while the session runs (with the shared folder's option):
// "<name> on MuDesk" there. Chrome and Edge read and write it (File System Access); Firefox, and any browser without it, read
// it only (the folder's files as an upload of a folder gives them). The gateway passes each request of the server here (a
// Device I/O Request of MS-RDPEFS, from its DeviceId on) and sends the answer back; the folder's files go nowhere else.
'use strict';

const ST = {
  OK: 0, NO_MORE_FILES: 0x80000006, UNSUCCESSFUL: 0xC0000001, INVALID_PARAMETER: 0xC000000D, NO_SUCH_FILE: 0xC000000F,
  ACCESS_DENIED: 0xC0000022, NAME_INVALID: 0xC0000033, COLLISION: 0xC0000035, PATH_NOT_FOUND: 0xC000003A, DISK_FULL: 0xC000007F,
  WRITE_PROTECTED: 0xC00000A2, IS_DIRECTORY: 0xC00000BA, NOT_SUPPORTED: 0xC00000BB, NOT_EMPTY: 0xC0000101, NOT_DIRECTORY: 0xC0000103,
  TOO_MANY_FILES: 0xC000011F,
};
const DEVICE = 3;                                        // the gateway's DeviceId for this folder (Rdpdr::LOCAL)
const READ_MAX = 1 << 20;
const LIST_BYTES = 3 << 20;                              // a listing sent whole with its first name up to this size (a message: 4 MB)
const HANDLES_MAX = 1024;
const WRITE_ACCESS = 0x40000000 | 0x2 | 0x4 | 0x10000;   // GENERIC_WRITE, FILE_WRITE_DATA, FILE_APPEND_DATA, DELETE
const SIZE = 2 ** 40;                                    // the drive's size and free space: the browser cannot know them
const FT = (ms) => BigInt(Math.max(0, Math.floor(ms))) * 10000n + 116444736000000000n;   // a FILETIME
const enc = (s) => { const b = new Uint8Array(s.length * 2); for (let i = 0; i < s.length; i++) { b[2 * i] = s.charCodeAt(i) & 0xff; b[2 * i + 1] = s.charCodeAt(i) >> 8; } return b; };
const dec = (b) => { let s = ''; for (let i = 0; i + 1 < b.length; i += 2) s += String.fromCharCode(b[i] | (b[i + 1] << 8)); return s.replace(/\0+$/, ''); };

/** Bytes written little-endian, one field after another. */
class Out {
  constructor() { this.parts = []; this.n = 0; }
  u8(v) { return this.#put(1, (d) => d.setUint8(0, v)); }
  u32(v) { return this.#put(4, (d) => d.setUint32(0, v >>> 0, true)); }
  u64(v) { return this.#put(8, (d) => d.setBigUint64(0, BigInt(v), true)); }
  bytes(b) { this.parts.push(b); this.n += b.length; return this; }
  #put(n, f) { const b = new Uint8Array(n); f(new DataView(b.buffer)); return this.bytes(b); }
  done() { const r = new Uint8Array(this.n); let o = 0; for (const p of this.parts) { r.set(p, o); o += p.length; } return r; }
}

/** The status of an error of the browser's file system. */
function statusOf(e) {
  return { NotFoundError: ST.NO_SUCH_FILE, TypeMismatchError: ST.NOT_DIRECTORY, NotAllowedError: ST.ACCESS_DENIED, SecurityError: ST.ACCESS_DENIED,
    NoModificationAllowedError: ST.ACCESS_DENIED, InvalidModificationError: ST.NOT_EMPTY, QuotaExceededError: ST.DISK_FULL, TypeError: ST.NAME_INVALID,
    Status: e?.status }[e?.name] ?? ST.UNSUCCESSFUL;
}
const fail = (status) => Object.assign(new Error('status'), { name: 'Status', status });

/**
 * A name the remote computer may not create in this computer's folder: one Windows' Explorer acts on when it only shows the
 * folder (desktop.ini, autorun.inf, a shortcut .lnk, .url, .scf, .library-ms, .searchconnector-ms: an icon or target on
 * another computer's share sends this computer's Windows sign-in to it), a device name, or one Windows trims (a trailing dot
 * or space). Existing files are read and written as before.
 */
const risky = (name) => /^(desktop\.ini|autorun\.inf)$|\.(lnk|url|scf|library-ms|searchconnector-ms|website)$|[. ]$/i.test(name)
  || /^(con|prn|aux|nul|com[0-9¹²³]|lpt[0-9¹²³]|conin\$|conout\$|clock\$)(\..*)?$/i.test(name);

/** A folder by its handle (File System Access: Chrome, Edge): read and write. Folders have no times there: when it was chosen. */
class HandleFs {
  constructor(root) { Object.assign(this, { root, name: root.name, readonly: false, at: Date.now() }); }
  async #dir(parts) { let d = this.root; for (const p of parts) d = await d.getDirectoryHandle(p); return d; }
  /** {dir, size, time, handle} of a path, or null when it is not there (a folder on the way missing: PATH_NOT_FOUND). */
  async stat(parts) {
    if (!parts.length) return { dir: true, size: 0, time: this.at, handle: this.root };
    let parent;
    try { parent = await this.#dir(parts.slice(0, -1)); } catch (e) { throw fail(e?.name === 'NotFoundError' || e?.name === 'TypeMismatchError' ? ST.PATH_NOT_FOUND : statusOf(e)); }
    const name = parts[parts.length - 1];
    try { const h = await parent.getFileHandle(name), f = await h.getFile(); return { dir: false, size: f.size, time: f.lastModified, handle: h }; } catch (e) {
      if (e?.name !== 'TypeMismatchError' && e?.name !== 'NotFoundError') throw fail(statusOf(e));
    }
    try { return { dir: true, size: 0, time: this.at, handle: await parent.getDirectoryHandle(name) }; } catch (e) {
      if (e?.name === 'NotFoundError' || e?.name === 'TypeMismatchError') return null;
      throw fail(statusOf(e));
    }
  }
  async list(parts) {
    const out = [];
    for await (const [name, h] of (await this.#dir(parts)).entries()) {
      if (h.kind === 'file') { const f = await h.getFile(); out.push({ name, dir: false, size: f.size, time: f.lastModified }); }
      else out.push({ name, dir: true, size: 0, time: this.at });
    }
    return out;
  }
  async create(parts, dir) {
    if (!parts.length || risky(parts[parts.length - 1])) throw fail(ST.ACCESS_DENIED);
    const parent = await this.#dir(parts.slice(0, -1)), name = parts[parts.length - 1];
    return dir ? parent.getDirectoryHandle(name, { create: true }) : parent.getFileHandle(name, { create: true });
  }
  async read(st, off, len) { const f = await st.handle.getFile(); return new Uint8Array(await f.slice(off, off + len).arrayBuffer()); }
  /** Its writer (what it writes saved when closed; `keep` false: it starts empty, the old file not copied first). */
  writer(st, keep = true) { return st.handle.createWritable({ keepExistingData: keep }); }
  async remove(parts) {
    if (!parts.length) throw fail(ST.ACCESS_DENIED);   // (the folder itself: before, removeEntry(undefined) took one named "undefined")
    await (await this.#dir(parts.slice(0, -1))).removeEntry(parts[parts.length - 1]);
  }
  /**
   * Moved or renamed: by move() where the browser has it, else (a file) copied and the old one removed; not then a change
   * of letter case only (the copy would be the same file, removed after).
   */
  async rename(from, to, st) {
    if (!from.length || !to.length || risky(to[to.length - 1])) throw fail(ST.ACCESS_DENIED);
    const dest = await this.#dir(to.slice(0, -1)), name = to[to.length - 1];
    if (typeof st.handle.move === 'function') { await st.handle.move(dest, name); return; }
    if (st.dir || from.join('\\').toLowerCase() === to.join('\\').toLowerCase()) throw fail(ST.NOT_SUPPORTED);
    const w = await (await dest.getFileHandle(name, { create: true })).createWritable();
    await w.write(await st.handle.getFile());
    await w.close();
    await this.remove(from);
  }
}

/** A folder as an upload of a folder gives it (Firefox, and browsers without File System Access): its files, read only. */
class ListFs {
  constructor(files) {
    this.readonly = true;
    this.at = Date.now();
    this.top = { dir: true, kids: new Map() };
    for (const f of files) {
      const parts = String(f.webkitRelativePath || f.name).split('/').filter(Boolean);
      this.name ??= parts.length > 1 ? parts[0] : '';
      let n = this.top;
      for (const p of parts.slice(1, -1)) {
        const k = p.toLowerCase();
        if (!n.kids.has(k)) n.kids.set(k, { name: p, dir: true, kids: new Map() });
        n = n.kids.get(k);
        if (!n.dir) break;
      }
      if (n.dir) n.kids.set(parts[parts.length - 1].toLowerCase(), { name: parts[parts.length - 1], dir: false, file: f });
    }
  }
  #node(parts) { let n = this.top; for (const p of parts) { n = n.dir ? n.kids.get(p.toLowerCase()) : null; if (!n) return null; } return n; }
  async stat(parts) {
    const n = this.#node(parts);
    if (!n) { const up = this.#node(parts.slice(0, -1)); if (!up || !up.dir) throw fail(ST.PATH_NOT_FOUND); return null; }
    return n.dir ? { dir: true, size: 0, time: this.at, node: n } : { dir: false, size: n.file.size, time: n.file.lastModified, node: n };
  }
  async list(parts) {
    const n = this.#node(parts);
    if (!n?.dir) throw fail(ST.NOT_DIRECTORY);
    return [...n.kids.values()].map((k) => (k.dir ? { name: k.name, dir: true, size: 0, time: this.at } : { name: k.name, dir: false, size: k.file.size, time: k.file.lastModified }));
  }
  async read(st, off, len) { return new Uint8Array(await st.node.file.slice(off, off + len).arrayBuffer()); }
}

/** Whether a name matches a pattern of * and ? (as the server asks: case aside). */
function matches(name, mask) {
  if (mask === '*' || mask === '*.*') return true;
  const re = new RegExp('^' + mask.replace(/[.+^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*').replace(/\?/g, '.') + '$', 'i');
  return re.test(name);
}

export class LocalFolder {
  /**
   * o: {send(number, Uint8Array answer), list(number, answer, rest) (a listing's first answer with its other names),
   * onChange() (shared or not), onRefused() (the session has no shared folder)}
   */
  constructor(o) {
    Object.assign(this, o);
    this.fs = null;
    this.handles = new Map();
    this.files = new Map();                              // the files open, by path in lower case (see #open)
    this.next = 1;
    this.queue = Promise.resolve();
    this.gen = 0;                                        // each folder shared and each connection: requests of one before left out
  }

  /** Whether this browser can write the folder chosen (Chrome, Edge); else it is read only. */
  static writable() { return typeof window.showDirectoryPicker === 'function'; }

  get shared() { return !!this.fs; }
  get name() { return this.fs?.name || ''; }
  get readonly() { return !!this.fs?.readonly; }

  /** Asks for a folder (in the click that asked): true when one was chosen. */
  async pick() {
    let fs = null;
    if (LocalFolder.writable()) {
      try { fs = new HandleFs(await window.showDirectoryPicker({ id: 'mudesk-folder', mode: 'readwrite' })); } catch (e) {
        if (e?.name === 'AbortError') return false;
        throw e;
      }
    } else {
      const files = await new Promise((resolve) => {
        const input = Object.assign(document.createElement('input'), { type: 'file', multiple: true });
        input.setAttribute('webkitdirectory', '');
        input.addEventListener('change', () => resolve([...input.files]), { once: true });
        input.addEventListener('cancel', () => resolve(null), { once: true });
        input.click();
      });
      if (!files) return false;
      fs = new ListFs(files);
      fs.name ||= 'Folder';
    }
    this.use(fs);
    return true;
  }

  /** Shares this folder (a HandleFs or a ListFs). */
  use(fs) {
    this.reset();
    this.fs = fs;
    this.onChange?.();
  }

  /** Shares a folder by its File System Access handle (read and write). */
  useHandle(handle) { this.use(new HandleFs(handle)); }

  /** Stops sharing it. */
  stop() { this.reset(); this.fs = null; this.onChange?.(); }

  /** The gateway refused it (no shared folder in this session): not shared. */
  refused() { this.stop(); this.onRefused?.(); }

  /**
   * Its open files forgotten (the connection made again, or another folder: the server opens them anew), their writes
   * kept; the requests still waiting here left out (the gateway answered them, or they were of the connection before).
   */
  reset() {
    this.gen++;
    for (const f of this.files.values()) f.writer?.close().catch(() => {});
    this.files.clear();
    this.handles.clear();
  }

  /** A request of the server (F_DRIVE: u32 its number, then the request from its DeviceId on): answered in turn, one at a time. */
  onRequest(d) {
    const req = new Uint8Array(d), gen = this.gen;       // (its own copy: the frame's buffer is used again)
    this.queue = this.queue.then(() => gen === this.gen && this.#answer(req, gen)).catch(() => {});
  }

  async #answer(m, gen) {
    if (m.length < 24) return;
    const no = new DataView(m.buffer, m.byteOffset, 4).getUint32(0, true), d = m.subarray(4);
    const v = new DataView(d.buffer, d.byteOffset, d.byteLength);
    const file = v.getUint32(4, true), cid = v.getUint32(8, true), major = v.getUint32(12, true), minor = v.getUint32(16, true);
    const head = (status) => new Out().u32(DEVICE).u32(cid).u32(status);
    const pad5 = (status) => head(status).u32(0).u8(0).done();
    const c = { fs: this.fs, gen, head };                // (the folder of when it came, all through)
    if (!c.fs) { this.send(no, pad5(0xC000000E)); return; }   // NO_SUCH_DEVICE: no longer shared
    let reply;
    try {
      reply = await ({
        0x00: () => this.#create(c, v, d),
        0x02: () => this.#close(c, file),
        0x03: () => this.#read(c, v, file),
        0x04: () => this.#write(c, v, d, file),
        0x05: () => this.#queryInfo(c, v, file),
        0x06: () => this.#setInfo(c, v, d, file),
        0x0A: () => this.#volumeInfo(c, v),
        0x0C: () => (minor === 1 ? this.#queryDir(c, v, d, file) : null),
        0x11: () => pad5(ST.OK),
        0x0E: () => head(ST.NOT_SUPPORTED).u32(0).done(),
      }[major] ?? (() => pad5(ST.NOT_SUPPORTED)))();
    } catch (e) {
      const status = statusOf(e);
      reply = major === 0x03 || major === 0x05 || major === 0x06 || major === 0x0A ? head(status).u32(0).done() : pad5(status);
    }
    if (gen !== this.gen) return;                        // (another folder, or another connection, meanwhile: not waited for)
    if (reply?.rest && this.list) this.list(no, reply.answer, reply.rest);   // (the listing's other names with its first one)
    else if (reply) this.send(no, reply.answer ?? reply);
  }

  /** The server's path ("\dir\file") as its names; none may be "." or ".." or hold what Windows refuses in a name. */
  static parts(path) {
    const parts = path.split('\\').filter(Boolean);
    if (parts.some((p) => p === '.' || p === '..' || /[\x00-\x1f<>:"/|?*]/.test(p))) throw fail(ST.NAME_INVALID);
    return parts;
  }

  /**
   * A file opened once more: one record however many handles it has, {writer, size, users, delete}. Its writes go to one
   * writer, shared, saved when the last handle closes (or before it is read: the browser reads only what is saved); its size
   * as written is what the server is told meanwhile, so it sees what it wrote.
   */
  #open(parts) {
    const key = parts.join('\\').toLowerCase();
    let f = this.files.get(key);
    if (!f) this.files.set(key, f = { key, writer: null, size: 0, users: 0, delete: false });
    f.users++;
    return f;
  }

  #let(f) { if (--f.users <= 0) this.files.delete(f.key); }

  /** Its writer, made the first time (what the file has kept: it is copied first) with the file's size then. */
  async #writing(c, h) {
    const f = h.file;
    if (!f.writer) {
      f.size = (await c.fs.stat(h.parts))?.size ?? 0;
      f.writer = await c.fs.writer(h.st, true);
    }
    return f;
  }

  async #save(f) {
    const w = f.writer;
    f.writer = null;
    await w?.close();
  }

  async #create(c, v, d) {
    const access = v.getUint32(20, true), attrs = v.getUint32(32, true), disp = v.getUint32(40, true), opts = v.getUint32(44, true);
    const len = v.getUint32(48, true), parts = LocalFolder.parts(dec(d.subarray(52, 52 + len)));
    const out = (status, id = 0, info = 0) => c.head(status).u32(id).u8(info).done();
    if (this.handles.size >= HANDLES_MAX) return out(ST.TOO_MANY_FILES);
    const fs = c.fs;
    let st = await fs.stat(parts);
    const wantDir = !!(opts & 0x1) || (!st && !!(attrs & 0x10));
    if (st && disp === 2) return out(ST.COLLISION);
    if (!st && (disp === 1 || disp === 4)) return out(ST.NO_SUCH_FILE);
    if (st && (opts & 0x1) && !st.dir) return out(ST.NOT_DIRECTORY);
    if (st && (opts & 0x40) && st.dir) return out(ST.IS_DIRECTORY);
    const truncate = st && !st.dir && (disp === 0 || disp === 4 || disp === 5);
    if (fs.readonly && (!st || truncate || (access & WRITE_ACCESS) || (opts & 0x1000))) return out(ST.WRITE_PROTECTED);
    let info = 1, empty = null;
    if (!st) {
      if (!parts.length) return out(ST.ACCESS_DENIED);
      await fs.create(parts, wantDir);
      st = await fs.stat(parts);
      info = 2;
    } else if (truncate) {                               // emptied: a writer that starts empty, saved when the file closes
      if (!this.files.get(parts.join('\\').toLowerCase())?.writer) empty = await fs.writer(st, false);
      info = 3;
    }
    if (c.gen !== this.gen) { empty?.close().catch(() => {}); return null; }   // (no longer waited for: not kept open)
    const file = st.dir ? null : this.#open(parts);
    if (truncate) {
      if (empty) file.writer = empty; else await file.writer.truncate(0);
      file.size = 0;
    }
    const id = this.next++;
    this.handles.set(id, { parts, st, file, delete: !!(opts & 0x1000), list: null });
    return out(ST.OK, id, info);
  }

  /**
   * Closed: when it was the file's last handle, what was written saved, or the file deleted if asked (by any of its handles;
   * a folder only when empty).
   */
  async #close(c, file) {
    const h = this.handles.get(file), f = h?.file;
    this.handles.delete(file);
    let status = ST.OK;
    if (f) {
      f.delete ||= h.delete;
      this.#let(f);
      if (f.users > 0) return c.head(status).u32(0).u8(0).done();
      if (!f.delete) await this.#save(f);
      else if (f.writer) { try { await f.writer.abort(); } catch { /* (deleted all the same) */ } f.writer = null; }
    }
    if (h && (f ? f.delete : h.delete)) {
      try { await c.fs.remove(h.parts); } catch (e) { status = statusOf(e); }
    }
    return c.head(status).u32(0).u8(0).done();
  }

  async #read(c, v, file) {
    const h = this.handles.get(file), len = Math.min(v.getUint32(20, true), READ_MAX), off = Number(v.getBigUint64(24, true));
    if (!h || h.st.dir) return c.head(ST.UNSUCCESSFUL).u32(0).done();
    if (h.file?.writer) await this.#save(h.file);       // (what was written, saved first: then it is read)
    const data = off < Number.MAX_SAFE_INTEGER ? await c.fs.read(h.st, off, len) : new Uint8Array(0);
    return c.head(ST.OK).u32(data.length).bytes(data).done();
  }

  async #write(c, v, d, file) {
    const h = this.handles.get(file), len = v.getUint32(20, true), off = Number(v.getBigUint64(24, true));
    const out = (status, n = 0) => c.head(status).u32(n).u8(0).done();
    if (c.fs.readonly) return out(ST.WRITE_PROTECTED);
    if (!h || h.st.dir) return out(ST.ACCESS_DENIED);
    if (off > Number.MAX_SAFE_INTEGER) return out(ST.INVALID_PARAMETER);
    const data = d.subarray(52, 52 + len), f = await this.#writing(c, h);
    await f.writer.write({ type: 'write', position: off, data });
    f.size = Math.max(f.size, off + data.length);
    return out(ST.OK, data.length);
  }

  async #queryInfo(c, v, file) {
    const h = this.handles.get(file), cls = v.getUint32(20, true);
    const st = h && await c.fs.stat(h.parts);
    if (!st) return c.head(ST.NO_SUCH_FILE).u32(0).done();
    const attrs = (st.dir ? 0x10 : 0x80) | (c.fs.readonly ? 0x1 : 0), t = FT(st.time), size = h.file?.writer ? h.file.size : st.size;
    const buf = {
      4: () => new Out().u64(t).u64(t).u64(t).u64(t).u32(attrs).done(),
      5: () => new Out().u64(size).u64(size).u32(1).u8(h.delete || h.file?.delete ? 1 : 0).u8(st.dir ? 1 : 0).done(),
      35: () => new Out().u32(attrs).u32(0).done(),
    }[cls]?.();
    return buf ? c.head(ST.OK).u32(buf.length).bytes(buf).done() : c.head(ST.NOT_SUPPORTED).u32(0).done();
  }

  async #setInfo(c, v, d, file) {
    const h = this.handles.get(file), cls = v.getUint32(20, true), len = v.getUint32(24, true), buf = d.subarray(52);
    const out = (status) => c.head(status).u32(len).done();
    const fs = c.fs;
    if (!h) return out(ST.UNSUCCESSFUL);
    if (fs.readonly && [10, 13, 19, 20].includes(cls)) return out(ST.WRITE_PROTECTED);
    const bv = new DataView(buf.buffer, buf.byteOffset, buf.byteLength);
    if (cls === 19 || cls === 20) {                      // allocation (only shrinks) and end of file
      if (buf.length < 8) return out(ST.INVALID_PARAMETER);
      if (!h.file) return out(ST.ACCESS_DENIED);
      const size = Number(bv.getBigUint64(0, true)), cur = h.file.writer ? h.file.size : (await fs.stat(h.parts))?.size ?? 0;
      if (cls === 20 || size < cur) {
        const f = await this.#writing(c, h);
        await f.writer.truncate(size);
        f.size = size;
      }
    } else if (cls === 13) {                             // deleted when closed (a folder only when empty)
      const del = len === 0 || (buf[0] ?? 1) !== 0;
      if (del && h.st.dir && (await fs.list(h.parts)).length) return out(ST.NOT_EMPTY);
      h.delete = del;
      if (h.file && !del) h.file.delete = false;
    } else if (cls === 10) {                             // renamed or moved, within the folder
      if (buf.length < 6) return out(ST.INVALID_PARAMETER);
      const replace = buf[0] !== 0, to = LocalFolder.parts(dec(buf.subarray(6, 6 + bv.getUint32(2, true))));
      const there = await fs.stat(to);
      const same = to.join('\\').toLowerCase() === h.parts.join('\\').toLowerCase();
      if (there && !same && (!replace || there.dir)) return out(there.dir && replace ? ST.ACCESS_DENIED : ST.COLLISION);
      if (h.file?.writer) await this.#save(h.file);
      // the one replaced moved aside first, removed once the rename is done: back in its place if it fails
      const aside = there && !same ? [...to.slice(0, -1), `~mudesk-${Date.now().toString(36)}${Math.random().toString(36).slice(2, 8)}.tmp`] : null;
      if (aside) await fs.rename(to, aside, there);
      try {
        await fs.rename(h.parts, to, h.st);
      } catch (e) {
        if (aside) await fs.rename(aside, to, await fs.stat(aside)).catch(() => {});
        throw e;
      }
      if (aside) await fs.remove(aside).catch(() => {});
      if (h.file) { this.#let(h.file); h.file = this.#open(to); }
      h.parts = to;
      h.st = await fs.stat(to) ?? h.st;
    }
    return out(ST.OK);                                   // (the times and the rest: taken, nothing to change here)
  }

  async #volumeInfo(c, v) {
    const cls = v.getUint32(20, true), label = enc(c.fs.name || 'Folder'), fsName = enc('NTFS');
    const units = Math.floor(SIZE / 4096), free = c.fs.readonly ? 0 : units;
    const buf = {
      1: () => new Out().u64(FT(c.fs.at)).u32(0x4D554446).u32(label.length).u8(0).u8(0).bytes(label).done(),
      3: () => new Out().u64(units).u64(free).u32(8).u32(512).done(),
      5: () => new Out().u32(0x00000007 | (c.fs.readonly ? 0x00080000 : 0)).u32(255).u32(fsName.length).bytes(fsName).done(),   // FILE_READ_ONLY_VOLUME
      7: () => new Out().u64(units).u64(free).u64(free).u32(8).u32(512).done(),
      4: () => new Out().u32(7).u32(c.fs.readonly ? 0x2 : 0).done(),   // FILE_DEVICE_DISK; FILE_READ_ONLY_DEVICE
    }[cls]?.();
    return buf ? c.head(ST.OK).u32(buf.length).bytes(buf).done() : c.head(ST.NOT_SUPPORTED).u32(0).done();
  }

  /**
   * One name of a folder's listing at a time, as the server asks (the first time, its pattern: * and ?). With the first
   * one, the rest of its names already written (`rest`): the gateway answers the next requests itself. A file being written
   * is listed at its size as written.
   */
  async #queryDir(c, v, d, file) {
    const h = this.handles.get(file), cls = v.getUint32(20, true), initial = d[24] !== 0, plen = v.getUint32(25, true);
    const none = (status) => c.head(status).u32(0).u8(0).done();
    if (!h) return none(ST.UNSUCCESSFUL);
    let fresh = false;
    if (initial || !h.list) {
      const path = dec(d.subarray(52, 52 + plen)), i = path.lastIndexOf('\\');
      const mask = path.slice(i + 1) || '*';
      if (mask.length > 255 || (mask.match(/\*/g) || []).length > 4) { h.list = { names: [], i: 0 }; return none(ST.NO_MORE_FILES); }
      const dir = LocalFolder.parts(path.slice(0, Math.max(0, i)));
      const all = [{ name: '.', dir: true, size: 0, time: c.fs.at }, { name: '..', dir: true, size: 0, time: c.fs.at }, ...await c.fs.list(dir)];
      for (const x of all) {
        const f = !x.dir && this.files.size ? this.files.get([...dir, x.name].join('\\').toLowerCase()) : null;
        if (f?.writer) x.size = f.size;
      }
      h.list = { names: all.filter((x) => matches(x.name, mask)), i: 0 };
      fresh = true;
    }
    const x = h.list.names[h.list.i++];
    if (!x) return none(ST.NO_MORE_FILES);
    const buf = this.#entry(c, x, cls);
    if (!buf) return none(ST.NOT_SUPPORTED);
    const answer = c.head(ST.OK).u32(buf.length).bytes(buf).done();
    if (!fresh || h.list.names.length < 2) return answer;
    const rest = h.list.names.slice(1).map((y) => this.#entry(c, y, cls));
    if (rest.reduce((s, b) => s + b.length + 4, 12) > LIST_BYTES) return answer;   // (a huge folder: one by one, as before)
    const out = new Out().u32(file).u32(cls).u32(rest.length);
    for (const b of rest) out.u32(b.length).bytes(b);
    return { answer, rest: out.done() };
  }

  /** A name's information of class cls (FileDirectory, FileFullDirectory, FileBothDirectory, FileNames), or null. */
  #entry(c, x, cls) {
    const n = enc(x.name), t = FT(x.time), attrs = (x.dir ? 0x10 : 0x80) | (c.fs.readonly ? 0x1 : 0);
    const common = () => new Out().u32(0).u32(0).u64(t).u64(t).u64(t).u64(t).u64(x.size).u64(x.size).u32(attrs);
    return {
      1: () => common().u32(n.length).bytes(n).done(),
      2: () => common().u32(n.length).u32(0).bytes(n).done(),
      3: () => common().u32(n.length).u32(0).u8(0).bytes(new Uint8Array(24)).bytes(n).done(),   // (no Reserved byte: as Windows reads it)
      12: () => new Out().u32(0).u32(0).u32(n.length).bytes(n).done(),
    }[cls]?.() ?? null;
  }
}

export { HandleFs, ListFs };
