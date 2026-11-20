// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// Decodes the audio frames of a probe dump (AUDIO=1 DUMP=file) and reports duration and dominant frequency.
import { readFileSync } from 'node:fs';
import { decodeAudio } from '../public/assets/audio.js';

const buf = readFileSync(process.argv[2]);
let p = 0, formats = [], samples = [], rate = 0, fmtUsed = null;
while (p + 5 <= buf.length) {
  const type = buf[p], len = buf.readUInt32LE(p + 1), d = new Uint8Array(buf.buffer, buf.byteOffset + p + 5, len);
  p += 5 + len;
  if (type !== 7) continue;
  if (d[0] === 0x46) formats = JSON.parse(Buffer.from(d.subarray(1)).toString());
  else {
    const fmt = formats[d[1] | (d[2] << 8)];
    fmtUsed = fmt; rate = fmt.rate;
    samples.push(decodeAudio(fmt, d.subarray(3))[0]);
  }
}
const all = Float32Array.from(samples.flatMap((s) => [...s]));
const voiced = all.filter((v) => Math.abs(v) > 0.05).length;
let crossings = 0;
for (let i = 1; i < all.length; i++) if (all[i - 1] < 0 && all[i] >= 0) crossings++;
const seconds = all.length / rate;
const active = voiced / rate;
console.log(JSON.stringify({ format: fmtUsed, chunks: samples.length, seconds: +seconds.toFixed(2), audibleSeconds: +active.toFixed(2),
  peak: +all.reduce((m, v) => Math.max(m, Math.abs(v)), 0).toFixed(3), approxHz: Math.round(crossings / ((all.findLastIndex((v) => Math.abs(v) > 0.05) - all.findIndex((v) => Math.abs(v) > 0.05)) / rate)) }));
