// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// Microphone in the browser: the capture worklet (mixed to one channel, resampled, 16-bit PCM) and the capture itself on
// doubles of the browser's media and audio objects: node tests/mic_unit.mjs
'use strict';

let failed = 0;
const check = (name, ok, info = '') => { console.log((ok ? 'OK   ' : 'FAIL ') + name + (info ? ' ' + info : '')); if (!ok) failed++; };
const tick = () => new Promise((r) => setTimeout(r, 0));

// ------------------------------------------------------------ the worklet, run as the audio thread would

let Processor = null;
Object.assign(globalThis, {
  sampleRate: 48000,
  AudioWorkletProcessor: class { constructor() { this.port = { posted: [], postMessage(b) { this.posted.push(new Int16Array(b)); } }; } },
  registerProcessor: (name, cls) => { if (name === 'mudesk-mic') Processor = cls; },
});
await import('../public/assets/mic-worklet.js');

/** Runs `secs` of a sine of `hz` (amplitude `amp`) at the page's rate in blocks of 128 samples, on `inputs` channels. */
function run(rate, channels, hz, secs, amp = 0.5, inputs = 1, pageRate = 48000) {
  globalThis.sampleRate = pageRate;
  const p = new Processor({ processorOptions: { rate, channels } });
  const n = Math.round(pageRate * secs);
  for (let at = 0; at < n; at += 128) {
    const block = Array.from({ length: inputs }, (_, c) => Float32Array.from({ length: 128 }, (_, i) => (c === 0 ? amp : -amp * 0.2) * Math.sin((2 * Math.PI * hz * (at + i)) / pageRate)));
    p.process([block]);
  }
  const all = new Int16Array(p.port.posted.reduce((s, b) => s + b.length, 0));
  let o = 0;
  for (const b of p.port.posted) { all.set(b, o); o += b.length; }
  return { posted: p.port.posted, all };
}
/** Level of a frequency in a mono signal (0..1 of full scale), by correlation with a sine and a cosine. */
function level(x, rate, hz) {
  let s = 0, c = 0;
  for (let i = 0; i < x.length; i++) { s += x[i] * Math.sin((2 * Math.PI * hz * i) / rate); c += x[i] * Math.cos((2 * Math.PI * hz * i) / rate); }
  return (2 * Math.hypot(s, c)) / x.length / 32768;
}
const mono44 = run(44100, 1, 1000, 1);
check('worklet: 48 kHz in, 44.1 kHz 16-bit out, posted every 20 ms (882 samples), as many samples as the time',
  mono44.posted.length === 50 && mono44.posted.every((b) => b.length === 882) && mono44.all.length === 44100,
  `${mono44.posted.length} pieces of ${mono44.posted[0]?.length}, ${mono44.all.length} samples`);
const l1k = level(mono44.all, 44100, 1000), l3k = level(mono44.all, 44100, 3000);
check('worklet: the tone keeps its frequency and level after resampling (1 kHz at -6 dB, nothing at 3 kHz)',
  Math.abs(l1k - 0.5) < 0.01 && l3k < 0.005, `1 kHz ${l1k.toFixed(4)}, 3 kHz ${l3k.toFixed(4)}`);
// two input channels (a stereo microphone): mixed to one; two channels out: the same sample twice
const st = run(22050, 2, 440, 0.5, 0.5, 2);
let same = true;
for (let i = 0; i < st.all.length; i += 2) if (st.all[i] !== st.all[i + 1]) { same = false; break; }
const left = st.all.filter((_, i) => i % 2 === 0), lmix = level(left, 22050, 440);
check('worklet: two channels in mixed to one, two channels out the same sample twice (interleaved, 20 ms pieces of 441 frames)',
  same && st.posted.every((b) => b.length === 882) && Math.abs(lmix - 0.2) < 0.01, `mix level ${lmix.toFixed(4)}`);
// fewer samples out (8 kHz): a tone above its limit (4 kHz) does not come back as a lower one
const alias = run(8000, 1, 7000, 1, 0.5);
const lAlias = level(alias.all, 8000, 1000), lpass = level(run(8000, 1, 500, 1, 0.5).all, 8000, 500);
check('worklet: down to 8 kHz, a 7 kHz tone filtered (it would come back at 1 kHz) and a 500 Hz one kept',
  lAlias < 0.05 && lpass > 0.4, `7 kHz as 1 kHz ${lAlias.toFixed(4)}, 500 Hz ${lpass.toFixed(4)}`);
// full scale and past it: clipped, never wrapped around
const loud = run(48000, 1, 50, 0.1, 3);
check('worklet: input past full scale clipped to -32768..32767, not wrapped',
  Math.max(...loud.all) === 32767 && Math.min(...loud.all) === -32768, `${Math.min(...loud.all)}..${Math.max(...loud.all)}`);
// a page at 44.1 kHz and the remote computer at 48 kHz: more samples out
const up = run(48000, 1, 1000, 1, 0.5, 1, 44100);
check('worklet: 44.1 kHz in, 48 kHz out', Math.abs(up.all.length - 48000) < 1000 && Math.abs(level(up.all, 48000, 1000) - 0.5) < 0.01, `${up.all.length}`);

// ------------------------------------------------------------ the capture, on doubles

const log = [];
let allow = 'yes', pending = null;
class Track { stop() { this.stopped = true; log.push('track stop'); } }
const media = {
  getUserMedia: (c) => {
    log.push('getUserMedia ' + JSON.stringify(c));
    if (allow === 'no') return Promise.reject(Object.assign(new Error('denied'), { name: 'NotAllowedError' }));
    if (allow === 'none') return Promise.reject(Object.assign(new Error('none'), { name: 'NotFoundError' }));
    const stream = { tracks: [new Track()], getTracks() { return this.tracks; } };
    if (allow === 'later') return new Promise((r) => { pending = () => r(stream); });
    return Promise.resolve(stream);
  },
};
let lastNode = null;
const contexts = [];
Object.assign(globalThis, {
  window: globalThis, isSecureContext: true,
  AudioContext: class {
    constructor(o) { this.o = o; this.state = 'suspended'; this.destination = { dest: true }; this.audioWorklet = { addModule: (u) => { log.push('module ' + String(u).split('/').pop()); return Promise.resolve(); } }; contexts.push(this); }
    resume() { this.state = 'running'; log.push('resume'); return Promise.resolve(); }
    close() { this.state = 'closed'; log.push('close'); return Promise.resolve(); }
    createMediaStreamSource() { return { connect: (n) => log.push('source connected ' + (n === lastNode)) }; }
  },
  AudioWorkletNode: class {
    constructor(ctx, name, o) { this.name = name; this.o = o; this.port = {}; lastNode = this; log.push(`node ${name} ${JSON.stringify(o.processorOptions)}`); }
    connect(d) { log.push('node connected ' + !!d.dest); }
  },
});
Object.defineProperty(globalThis, 'navigator', { value: { mediaDevices: media }, configurable: true });
const { MicCapture } = await import('../public/assets/mic.js');
const open = (rate, ch) => { const d = new Uint8Array(6); d[0] = 1; new DataView(d.buffer).setUint32(1, rate, true); d[5] = ch; return d; };

{
  const sent = [], errors = [], lives = [];
  const m = new MicCapture({ send: (p, max, frame) => sent.push([p, max, frame]), onError: (k) => errors.push(k), onLive: (on) => lives.push(on) });
  log.length = 0;
  m.onMessage(open(44100, 1));
  for (let i = 0; i < 5; i++) await tick();
  check('capture: the microphone asked for (echo cancellation, one channel), the worklet loaded and fed, the audio running',
    log.join(' | ') === 'getUserMedia {"audio":{"channelCount":1,"echoCancellation":true,"noiseSuppression":true,"autoGainControl":true}} | module mic-worklet.js'
      + ' | node mudesk-mic {"rate":44100,"channels":1} | source connected true | node connected true | resume', log.join(' | '));
  // pieces of 20 ms joined: sent at 100 ms, with at most a second (whole frames) waiting
  for (let i = 0; i < 4; i++) lastNode.port.onmessage({ data: new Int16Array(882).fill(i + 1).buffer });
  const before = sent.length;
  lastNode.port.onmessage({ data: new Int16Array(882).fill(5).buffer });
  const [p, max, frame] = sent[0] || [];
  check('capture: 20 ms pieces sent together every 100 ms, in order, with the limit of what may wait (1 s) and the frame size',
    before === 0 && sent.length === 1 && p.length === 8820 && new Int16Array(p.buffer)[0] === 1 && new Int16Array(p.buffer)[4409] === 5 && max === 88200 && frame === 2,
    `${before} then ${sent.length}, ${p?.length} bytes, max ${max}, frame ${frame}`);
  // the remote program stops recording: the microphone let go
  log.length = 0;
  m.onMessage(new Uint8Array([0]));
  lastNode.port.onmessage({ data: new Int16Array(882 * 5).buffer });   // already on its way: dropped
  check('capture: stopped by the gateway, the microphone and the audio let go; what was on its way dropped',
    log.join(' | ') === 'track stop | close' && sent.length === 1, log.join(' | '));
  // stereo at 48 kHz
  m.onMessage(open(48000, 2));
  for (let i = 0; i < 5; i++) await tick();
  check('capture: a new format (48 kHz, two channels) given to the worklet', log.at(-2) === 'node connected true' && lastNode.o.processorOptions.channels === 2 && lastNode.o.processorOptions.rate === 48000, log.at(-4));
  m.stop();
  m.stop();
  check('capture: no error said', errors.length === 0, errors.join());
  // the session told each time the microphone is taken and let go (its own sign while it lasts), once each
  check('capture: the microphone taken and let go said, once each', JSON.stringify(lives) === '[true,false,true,false]', JSON.stringify(lives));
}
{ // refused (said once, not each time a program records), none there, not a secure page, stopped while the browser asks
  const errors = [], m = new MicCapture({ send() {}, onError: (k) => errors.push(k) });
  allow = 'no';
  m.onMessage(open(44100, 1));
  for (let i = 0; i < 5; i++) await tick();
  m.onMessage(new Uint8Array([0]));
  m.onMessage(open(44100, 1));
  for (let i = 0; i < 5; i++) await tick();
  allow = 'none';
  const n = new MicCapture({ send() {}, onError: (k) => errors.push(k) });
  n.onMessage(open(44100, 1));
  for (let i = 0; i < 5; i++) await tick();
  globalThis.isSecureContext = false;
  const media0 = navigator.mediaDevices;
  navigator.mediaDevices = undefined;                    // what browsers do on an http:// page
  new MicCapture({ send() {}, onError: (k) => errors.push(k) }).onMessage(open(44100, 1));
  navigator.mediaDevices = media0;
  globalThis.isSecureContext = true;
  check('capture: refused (said once), no microphone, an http:// page: each said with its own text',
    errors.join() === 'mic_denied,mic_none,mic_https', errors.join());
  allow = 'later';
  log.length = 0;
  const lLives = [];
  const l = new MicCapture({ send() {}, onError: (k) => errors.push(k), onLive: (on) => lLives.push(on) });
  l.onMessage(open(44100, 1));
  await tick();
  l.onMessage(new Uint8Array([0]));                       // the program stopped while the browser was asking
  pending();
  for (let i = 0; i < 5; i++) await tick();
  check('capture: stopped while the browser asked, the microphone given back when it answers, nothing started',
    log.includes('track stop') && !log.some((x) => x.startsWith('node ') || x.startsWith('module ')) && l.live === null && !lLives.length, log.join(' | ') + ' ' + lLives);
  allow = 'yes';
  log.length = 0;
  new MicCapture({ send() {}, onError: (k) => errors.push(k) }).onMessage(open(96000, 1));
  await tick();
  check('capture: a rate the remote computer cannot ask for is not recorded', log.length === 0, log.join(' | '));
}

console.log(failed ? `\n${failed} failed` : '\nAll tests passed');
process.exit(failed ? 1 : 0);
