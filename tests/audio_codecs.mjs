// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// Compares the audio decoders with sox: node tests/audio_codecs.mjs (needs sox)
import { execSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { decodeAudio } from '../public/assets/audio.js';

execSync('sox -n -r 22050 -c 2 -b 16 /tmp/ac_ref.wav synth 1 sine 440 sine 660 gain -3');
const wav = (f) => {
  const b = readFileSync(f);
  let p = 12, fmt = null, data = null;
  while (p < b.length) {
    const id = b.toString('ascii', p, p + 4), len = b.readUInt32LE(p + 4);
    if (id === 'fmt ') fmt = { tag: b.readUInt16LE(p + 8), ch: b.readUInt16LE(p + 10), rate: b.readUInt32LE(p + 12), align: b.readUInt16LE(p + 20), bits: b.readUInt16LE(p + 22) };
    if (id === 'data') data = new Uint8Array(b.buffer, b.byteOffset + p + 8, len);
    p += 8 + len + (len & 1);
  }
  return { fmt, data };
};
const ref = wav('/tmp/ac_ref.wav');
const refL = decodeAudio(ref.fmt, ref.data)[0];
let fail = 0;
for (const [name, opt] of [['IMA ADPCM', '-e ima-adpcm'], ['A-law', '-e a-law'], ['mu-law', '-e u-law'], ['PCM 8-bit', '-e unsigned -b 8']]) {
  execSync(`sox /tmp/ac_ref.wav ${opt} /tmp/ac_t.wav`);
  const t = wav('/tmp/ac_t.wav');
  const got = decodeAudio(t.fmt, t.data);
  const n = Math.min(got[0].length, refL.length);
  let sig = 0, err = 0;
  for (let i = 0; i < n; i++) { sig += refL[i] ** 2; err += (refL[i] - got[0][i]) ** 2; }
  const snr = 10 * Math.log10(sig / Math.max(err, 1e-12));
  const ok = snr > 20 && got.length === 2 && n > refL.length * 0.95;
  fail += ok ? 0 : 1;
  console.log(`${ok ? 'OK  ' : 'FAIL'} ${name}: tag 0x${t.fmt.tag.toString(16)}, ${n} samples, SNR ${snr.toFixed(1)} dB`);
}
process.exit(fail ? 1 : 0);
