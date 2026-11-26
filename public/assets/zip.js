// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// Minimal ZIP writer (stored, no compression) for downloading several remote files at once.
'use strict';

const TABLE = new Uint32Array(256).map((_, n) => {
  let c = n;
  for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
  return c >>> 0;
});

/** Incremental CRC-32: crc = crc32(chunk, crc) */
export function crc32(u8, crc = 0) {
  let c = ~crc >>> 0;
  for (let i = 0; i < u8.length; i++) c = TABLE[(c ^ u8[i]) & 0xff] ^ (c >>> 8);
  return ~c >>> 0;
}

/** Entries a ZIP holds (without ZIP64, which this writer does not make; the gateway's ZipStream has the same limits). */
export const ZIP_MAX_ENTRIES = 0xffff;

/**
 * files: [{name, parts: Uint8Array[], crc, dir}] -> Blob. Throws past 65,535 entries or 4 GB: the counts and offsets of a
 * ZIP are 16 and 32 bits, and they wrapped around into a broken file.
 */
/**
 * A path made safe as a name inside a ZIP (as Files::zipName on the gateway, which already did it for a remote computer's
 * names: here once more): no control characters, nothing Windows refuses (< > : " | ? *, a trailing dot or space, a device
 * name), none of the look-alikes of / \ : . that unzippers using Windows' older text functions turn into the real ones;
 * each made "_", "." and ".." left out.
 */
export function zipName(path) {
  return String(path).split(/[\\/]+/).map((p) => p.replace(/[\x00-\x1f\x7f<>:"|?*．／＼：∕⁄﹨¥₩]/g, '_').replace(/[. ]+$/, ''))
    .filter((p) => p !== '').map((p) => (/^(con|prn|aux|nul|com[0-9¹²³]|lpt[0-9¹²³]|conin\$|conout\$|clock\$)(\..*)?$/i.test(p) ? '_' + p : p)).join('/');
}

export function zipBlob(files) {
  if (files.length > ZIP_MAX_ENTRIES) throw new Error('ZIP: more than 65,535 entries');
  const enc = new TextEncoder();
  const out = [], central = [];
  let offset = 0;
  const dosTime = (() => {
    const d = new Date();
    return [(d.getHours() << 11) | (d.getMinutes() << 5) | (d.getSeconds() >> 1),
      ((d.getFullYear() - 1980) << 9) | ((d.getMonth() + 1) << 5) | d.getDate()];
  })();
  for (const f of files) {
    const name = enc.encode((zipName(f.name) || '_') + (f.dir ? '/' : ''));
    const size = f.dir ? 0 : f.parts.reduce((n, x) => n + x.length, 0);   // what arrived (a file may shrink after copying)
    if (size > 0xffffffff || offset > 0xffffffff) throw new Error('ZIP: over 4 GB');
    const head = (sig, extra) => {
      const b = new DataView(new ArrayBuffer(extra ? 46 : 30));
      let p = 0;
      const w32 = (v) => { b.setUint32(p, v, true); p += 4; };
      const w16 = (v) => { b.setUint16(p, v, true); p += 2; };
      w32(sig);
      if (extra) w16(20);
      w16(20); w16(0x0800); w16(0); w16(dosTime[0]); w16(dosTime[1]);
      w32(f.dir ? 0 : f.crc); w32(size); w32(size); w16(name.length); w16(0);
      if (extra) { w16(0); w16(0); w16(0); w32(f.dir ? 0x10 : 0); w32(offset); }
      return new Uint8Array(b.buffer);
    };
    const local = head(0x04034b50, false);
    central.push(head(0x02014b50, true), name);
    out.push(local, name, ...(f.dir ? [] : f.parts));
    offset += local.length + name.length + size;
  }
  const cdSize = central.reduce((n, p) => n + p.length, 0);
  if (offset > 0xffffffff) throw new Error('ZIP: over 4 GB');
  const end = new DataView(new ArrayBuffer(22));
  end.setUint32(0, 0x06054b50, true);
  end.setUint16(8, files.length, true);
  end.setUint16(10, files.length, true);
  end.setUint32(12, cdSize, true);
  end.setUint32(16, offset, true);
  return new Blob([...out, ...central, new Uint8Array(end.buffer)], { type: 'application/zip' });
}
