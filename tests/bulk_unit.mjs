// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// Unit tests of the browser side of RDP bulk compression: node tests/bulk_unit.mjs
import { readFileSync } from 'node:fs';
import { createHash } from 'node:crypto';
import { Bulk, CompressedStream, Mppc, Ncrush } from '../public/assets/bulk.js';

let failed = 0;
const check = (name, ok) => { console.log((ok ? 'OK   ' : 'FAIL ') + name); if (!ok) failed++; };

// MPPC 64 KB: packets compressed by FreeRDP from the deterministic sample of tests/run.php
const words = ['remote', 'desktop', 'screen', 'bitmap', 'cache', 'window', 'pixel', 'MuDesk', 'session', 'cursor'];
const sample = [];
for (let x = 12345; sample.length < 140000;) {
  x = Number((BigInt(x) * 1103515245n + 12345n) % 2147483648n);
  for (const c of words[x % 10] + (x % 7 === 0 ? '\n' : ' ')) sample.push(c.charCodeAt(0));
  if (x % 13 === 0) sample.push((x >> 8) % 256, (x >> 16) % 256);
}
const m = new Mppc();
const out = [];
for (const line of readFileSync(new URL('./fixtures/mppc-vectors.txt', import.meta.url), 'utf8').trim().split('\n')) {
  const [flags, hex] = line.split(' ');
  out.push(Buffer.from(m.decompress(new Uint8Array(Buffer.from(hex, 'hex')), +flags)));
}
const md5 = (b) => createHash('md5').update(b).digest('hex');
check('MPPC 64K decompression', md5(Buffer.concat(out)) === md5(Buffer.from(sample.slice(0, 140000))));

// NCRUSH (RDP 6.0) and XCRUSH (RDP 6.1): blocks compressed by FreeRDP (first line: md5 and length of the original data)
for (const [name, type] of [['ncrush', 2], ['xcrush', 3]]) {
  const lines = readFileSync(new URL(`./fixtures/${name}-vectors.txt`, import.meta.url), 'utf8').trim().split('\n');
  const [, want, len] = lines.shift().split(' ');
  const b = new Bulk(), parts = [];
  for (const line of lines) {
    const [flags, hex] = line.split(' ');
    const d = new Uint8Array(Buffer.from(hex, 'hex'));
    parts.push(Buffer.from(+flags & 0x20 ? b.decompress(d, +flags) : d));
  }
  const all = Buffer.concat(parts);
  check(`${name.toUpperCase()} decompression`, md5(all) === want && all.length === +len && type > 0);
}
let refused = false;
try { new Bulk().decompress(Uint8Array.of(1, 2), 0x24); } catch { refused = true; }
check('unknown bulk compression type refused', refused);

// fast-path fragments are reassembled after decompression (flags 0: not compressed)
const s = new CompressedStream();
const f = (code, frag, ...bytes) => Uint8Array.of(code, frag, 0, ...bytes);
check('fragment first', s.update(f(1, 2, 10, 11)) === null);
check('fragment next', s.update(f(1, 3, 12)) === null);
check('fragment last', s.update(f(1, 1, 13)).join() === '1,10,11,12,13');
check('single update', s.update(f(6, 0)).join() === '6');

// slow-path updates take the fast-path form the gateway uses
check('slow-path bitmap', s.slow(Uint8Array.of(0, 0x02, 1, 0, 7, 0, 9)).join() === '1,1,0,7,0,9');
check('slow-path orders', s.slow(Uint8Array.of(0, 0x02, 0, 0, 0, 0, 3, 0, 0, 0, 0xaa)).join() === '0,3,0,170');
check('slow-path hidden pointer', s.slow(Uint8Array.of(0, 0x1b, 1, 0, 0, 0, 0, 0, 0, 0)).join() === '5');
check('slow-path other PDU', s.slow(Uint8Array.of(0, 0x2f, 1, 0, 0, 0)) === null);

// a literal past the end of the 64 KB history is an error (it was dropped without a word, so the block came out short)
const attempt = (fn) => { try { fn(); return ''; } catch (e) { return e.message; } };
const mo = new Mppc();
mo.pos = 65530;
check('MPPC: history overflow', attempt(() => mo.decompress(new Uint8Array(10).fill(0x41), 0x21)) === 'MPPC: history overflow');
const no = new Ncrush();
check('NCRUSH: literal then end of stream', no.decompress(Uint8Array.of(0xc4, 0xff, 0x05, 0x00), 0x22).join() === '0');
no.pos = 65540;
check('NCRUSH: history overflow', attempt(() => no.decompress(Uint8Array.of(0xc4, 0xff, 0x05, 0x00), 0x22)) === 'NCRUSH: history overflow');

// fast-path reassembly stops at the MaxRequestSize the gateway announces (16 MB)
const big = new Uint8Array(3 + (1 << 24));
big[0] = 1; big[1] = 3;
s.update(f(1, 2, 10));
check('fast-path update over 16 MB refused', attempt(() => s.update(big)) === 'fast-path update too large');

console.log(failed ? `\n${failed} failed` : '\nAll tests passed');
process.exit(failed ? 1 : 0);
