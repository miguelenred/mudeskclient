// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// A recording's video: the WebM file (its structure read back as a player reads it), the sound taken to 48 kHz and placed
// on its line of time. Run with: node tests/video_unit.mjs
import { WebmWriter, opusHead } from '../public/assets/webm.js';
import { Resampler, SoundLine, videoEstimate } from '../public/assets/recvideo.js';

let failed = 0;
const check = (name, ok, info = '') => { console.log((ok ? 'OK   ' : 'FAIL ') + name + (info ? ' ' + info : '')); if (!ok) failed++; };

// ------------------------------------------------------------ WebM, read back

/** EBML: [id, data start, data length] of the elements in u8 from `at` to `end`. */
function elements(u8, at = 0, end = u8.length) {
  const out = [];
  const vint = (p, keepMarker) => {
    const first = u8[p];
    let n = 1;
    while (n <= 8 && !(first & (0x80 >> (n - 1)))) n++;
    let v = keepMarker ? first : first & (0xff >> n);
    for (let i = 1; i < n; i++) v = v * 256 + u8[p + i];
    return [v, n];
  };
  while (at < end) {
    const [id, il] = vint(at, true);
    const [len, ll] = vint(at + il, false);
    out.push([id, at + il + ll, len, at]);
    at += il + ll + len;
  }
  return out;
}
const uintOf = (u8, at, len) => { let v = 0; for (let i = 0; i < len; i++) v = v * 256 + u8[at + i]; return v; };
const child = (u8, [, at, len], id) => elements(u8, at, at + len).find((e) => e[0] === id);
const children = (u8, [, at, len], id) => elements(u8, at, at + len).filter((e) => e[0] === id);
const text = (u8, e) => new TextDecoder().decode(u8.subarray(e[1], e[1] + e[2]));

{
  const w = new WebmWriter({ codec: 'V_VP9', width: 1280, height: 720 }, { channels: 2, head: null, preSkip: 312 });
  // the video's frames and the sound's packets as the encoders give them: each track in its own time order, the sound late
  w.addVideo(0, Uint8Array.of(1, 1), true);
  w.addVideo(66.7, Uint8Array.of(2), false);
  w.addVideo(5000, Uint8Array.of(3, 3, 3), true);
  w.addVideo(40000, Uint8Array.of(4), false);
  for (let ms = 0; ms <= 40020; ms += 20) w.addAudio(ms, Uint8Array.of(9));
  const blob = w.finish(41000);
  const u8 = new Uint8Array(await blob.arrayBuffer());
  const top = elements(u8);
  const [ebml, seg] = top;
  const docType = text(u8, child(u8, ebml, 0x4282));
  const segData = seg[1];
  const segWhole = seg[1] + seg[2] === u8.length;
  const inSeg = elements(u8, seg[1], seg[1] + seg[2]);
  const ids = inSeg.map((e) => e[0].toString(16));
  // the seek head points at the info, the tracks and the cues
  const seeks = children(u8, inSeg[0], 0x4dbb).map((s) => {
    const idE = child(u8, s, 0x53ab), posE = child(u8, s, 0x53ac);
    return [uintOf(u8, idE[1], idE[2]), uintOf(u8, posE[1], posE[2])];
  });
  const seekOk = seeks.every(([id, pos]) => elements(u8, segData + pos, segData + pos + 16)[0]?.[0] === id) && seeks.length === 3;
  const info = inSeg.find((e) => e[0] === 0x1549a966);
  const durE = child(u8, info, 0x4489);
  const duration = new DataView(u8.buffer, durE[1], 8).getFloat64(0);
  const scale = uintOf(u8, child(u8, info, 0x2ad7b1)[1], child(u8, info, 0x2ad7b1)[2]);
  const tracks = children(u8, inSeg.find((e) => e[0] === 0x1654ae6b), 0xae);
  const codecs = tracks.map((t) => text(u8, child(u8, t, 0x86)));
  const video = child(u8, tracks[0], 0xe0);
  const size = [uintOf(u8, child(u8, video, 0xb0)[1], child(u8, video, 0xb0)[2]), uintOf(u8, child(u8, video, 0xba)[1], child(u8, video, 0xba)[2])];
  const head = child(u8, tracks[1], 0x63a2);
  const headOk = text(u8, [0, head[1], 8]) === 'OpusHead' && u8[head[1] + 9] === 2 && (u8[head[1] + 10] | (u8[head[1] + 11] << 8)) === 312;
  const delay = uintOf(u8, child(u8, tracks[1], 0x56aa)[1], child(u8, tracks[1], 0x56aa)[2]);
  // the clusters: each block's time, its track and whether it is a key; in time order, a new cluster at each key frame
  const clusters = inSeg.filter((e) => e[0] === 0x1f43b675);
  const blocks = [];
  const clusterAt = [];
  for (const c of clusters) {
    const tcE = child(u8, c, 0xe7), tc = uintOf(u8, tcE[1], tcE[2]);
    clusterAt.push([tc, c[3] - segData]);
    for (const b of children(u8, c, 0xa3)) {
      const track = u8[b[1]] & 0x7f, rel = new DataView(u8.buffer, b[1] + 1, 2).getInt16(0), key = !!(u8[b[1] + 3] & 0x80);
      blocks.push([track, tc + rel, key, u8[b[1] + 4]]);
    }
  }
  const ordered = blocks.every((b, i) => i === 0 || b[1] >= blocks[i - 1][1]);
  const videoBlocks = blocks.filter((b) => b[0] === 1).map((b) => [b[1], b[2], b[3]]);
  const relOk = clusters.length >= 3 && blocks.length === 4 + 2002;
  // the cues point at the clusters that start with a key frame
  const cues = children(u8, inSeg.find((e) => e[0] === 0x1c53bb6b), 0xbb).map((p) => {
    const t = child(u8, p, 0xb3), pos = child(u8, child(u8, p, 0xb7), 0xf1);
    return [uintOf(u8, t[1], t[2]), uintOf(u8, pos[1], pos[2])];
  });
  const cuesOk = cues.length === 2 && cues.every(([t, pos]) => clusterAt.some(([ct, cp]) => ct === t && cp === pos));
  check('WebM: EBML header (webm), a segment of the file\'s length, its seek head pointing at the info, tracks and cues',
    docType === 'webm' && segWhole && ids[0] === '114d9b74' && seekOk && blob.type === 'video/webm', JSON.stringify({ docType, segWhole, ids, seeks }));
  check('WebM: millisecond times, its duration; a VP9 track of its size and an Opus one (OpusHead with its pre-skip, codec delay)',
    scale === 1e6 && duration === 41000 && codecs.join() === 'V_VP9,A_OPUS' && size.join() === '1280,720' && headOk && delay === 6500000,
    JSON.stringify({ scale, duration, codecs, size, headOk, delay }));
  check('WebM: both tracks\' blocks in time order, the video\'s at their times (rounded) with their key frames; cues at the key frames\' clusters',
    ordered && relOk && JSON.stringify(videoBlocks) === JSON.stringify([[0, true, 1], [67, false, 2], [5000, true, 3], [40000, false, 4]]) && cuesOk,
    JSON.stringify({ ordered, relOk, videoBlocks, cues, clusterAt: clusterAt.slice(0, 4) }));

  // without sound: one track; a cluster never longer than 30 s (a block's time within it is 16 bits)
  const v = new WebmWriter({ codec: 'V_VP8', width: 640, height: 480 });
  v.addVideo(0, Uint8Array.of(1), true);
  v.addVideo(50000, Uint8Array.of(2), false);
  v.addVideo(100000, Uint8Array.of(3), false);
  const vu = new Uint8Array(await v.finish().arrayBuffer());
  const vseg = elements(vu)[1], vin = elements(vu, vseg[1], vseg[1] + vseg[2]);
  const vtracks = children(vu, vin.find((e) => e[0] === 0x1654ae6b), 0xae).map((t) => text(vu, child(vu, t, 0x86)));
  const vclusters = vin.filter((e) => e[0] === 0x1f43b675).map((c) => uintOf(vu, child(vu, c, 0xe7)[1], child(vu, c, 0xe7)[2]));
  check('WebM: video only (VP8); a new cluster past 30 s; the duration at least its last frame', vtracks.join() === 'V_VP8' && vclusters.join() === '0,50000,100000',
    JSON.stringify({ vtracks, vclusters }));
  check('WebM: OpusHead as RFC 7845 has it', [...opusHead(1, 312)].join() === [...new TextEncoder().encode('OpusHead'), 1, 1, 56, 1, 128, 187, 0, 0, 0, 0, 0].join());
}

// ------------------------------------------------------------ sound at 48 kHz

{
  const sine = (rate, hz, n, from = 0) => Float32Array.from({ length: n }, (_, i) => 0.5 * Math.sin(2 * Math.PI * hz * (i + from) / rate));
  const zeroCrossings = (a) => { let n = 0; for (let i = 1; i < a.length; i++) if ((a[i - 1] < 0) !== (a[i] < 0)) n++; return n; };
  const rms = (a) => Math.sqrt(a.reduce((s, x) => s + x * x, 0) / a.length);
  // a second of 1 kHz at 44.1 kHz: a second at 48 kHz, the same tone and level (the filter's start and end left out)
  const r = new Resampler(44100);
  const out = r.push([sine(44100, 1000, 44100)])[0];
  const mid = out.subarray(2400, 45600);
  const zc = zeroCrossings(mid), level = rms(mid);
  // in pieces as it comes (441 samples), the same as all at once: nothing lost or doubled between them
  const r2 = new Resampler(44100), parts = [];
  for (let o = 0; o < 44100; o += 441) parts.push(r2.push([sine(44100, 1000, 441, o)])[0]);
  const joined = Float32Array.from(parts.flatMap((p) => [...p]));
  let diff = 0;
  for (let i = 0; i < Math.min(joined.length, out.length); i++) diff = Math.max(diff, Math.abs(joined[i] - out[i]));
  // mono and stereo kept apart; 22.05 and 8 kHz; 48 kHz as it is
  const st = new Resampler(22050).push([sine(22050, 440, 22050), sine(22050, 880, 22050)]);
  const r8 = new Resampler(8000), e1 = r8.push([sine(8000, 300, 8000)])[0], e2 = r8.flush()?.[0] ?? new Float32Array(0);
  const eight = Float32Array.from([...e1, ...e2]);   // (its last samples given by flush(): they waited for more)
  const same = new Resampler(48000).push([Float32Array.of(0.1, 0.2)])[0];
  check('sound: 44.1 kHz to 48 kHz keeps its tone and level; in pieces the same as at once',
    Math.abs(out.length - 48000) <= 8 && Math.abs(zc - 1800) <= 2 && Math.abs(level - 0.5 / Math.SQRT2) < 0.01 && diff < 1e-6,
    JSON.stringify({ len: out.length, zc, level, diff, joined: joined.length }));
  check('sound: stereo channels kept apart, 22.05 and 8 kHz taken to 48 (all of it once flushed), 48 kHz untouched',
    Math.abs(zeroCrossings(st[0].subarray(4800, 43200)) - 704) <= 2 && Math.abs(zeroCrossings(st[1].subarray(4800, 43200)) - 1408) <= 2
    && Math.abs(eight.length - 48000) <= 8 && Math.abs(zeroCrossings(eight.subarray(4800, 43200)) - 480) <= 2 && same[1] === Math.fround(0.2),
    JSON.stringify({ l: zeroCrossings(st[0].subarray(4800, 43200)), r: zeroCrossings(st[1].subarray(4800, 43200)), eight: eight.length }));
}

// ------------------------------------------------------------ the sound's line of time

{
  const given = [];                                      // [first sample, frames, left max, right max]
  const left = new Float32Array(48000 * 5), right = new Float32Array(48000 * 5);   // what was given, sample by sample
  const line = new SoundLine((planar, n, first) => {
    let l = 0, r = 0;
    for (let i = 0; i < n; i++) { l = Math.max(l, Math.abs(planar[i])); r = Math.max(r, Math.abs(planar[n + i])); }
    left.set(planar.subarray(0, n), first);
    right.set(planar.subarray(n), first);
    given.push([first, n, +l.toFixed(2), +r.toFixed(2)]);
  });
  const level = (ms) => +Math.abs(left[ms * 48]).toFixed(2);
  const fmtMsg = new Uint8Array([70, ...new TextEncoder().encode(JSON.stringify([{ tag: 1, ch: 1, rate: 48000, bits: 16, align: 2 }]))]);
  const wave = (n, v) => { const d = new Uint8Array(3 + n * 2), dv = new DataView(d.buffer); d[0] = 87; for (let i = 0; i < n; i++) dv.setInt16(3 + i * 2, v, true); return d; };
  line.onMessage(fmtMsg, 0);
  line.onMessage(wave(4800, 16384), 1000);               // 100 ms at 1 s
  line.onMessage(wave(4800, 16384), 1050);               // the next 100 ms, come in time: right after it
  line.upTo(1150);                                       // the pictures reached 1.15 s: up to there final
  const first = given.splice(0);
  line.onMessage(wave(48000, 8192), 3000);               // after a pause: at its own time (a second of it)
  line.onMessage(wave(2400, 8192), 3000);                // the next one comes over 0.5 s before its turn: at its own time, over it
  line.finish(3100);
  const rest = given.splice(0);
  const total = [...first, ...rest].reduce((n, g) => n + g[1], 0);
  const at = (ms) => [...first, ...rest].find((g) => g[0] <= ms * 48 && ms * 48 < g[0] + g[1]);
  check('sound line: pieces placed one after the other, silence where there was none, given in order up to where the pictures reached; mono on both sides',
    first.reduce((n, g) => n + g[1], 0) === 55200 && first.every((g, i) => i === 0 || g[0] === first[i - 1][0] + first[i - 1][1])
    && level(500) === 0 && level(1100) === 0.5 && +Math.abs(right[1100 * 48]).toFixed(2) === 0.5, JSON.stringify({ first, rest }));
  check('sound line: after a pause at its own time; one too far ahead placed at its time (both heard together); to the end of what was placed',
    level(2500) === 0 && level(3020) === 0.5 && level(3500) === 0.25 && level(3990) === 0.25 && total === 192000 && rest.every((g, i) => i === 0 || g[0] === rest[i - 1][0] + rest[i - 1][1]),
    JSON.stringify({ rest, total, l: [level(2500), level(3020), level(3500), level(3990)] }));
  // the sound made final at its own time, not only when a picture is taken: a long one on a still screen is given as it comes
  const parts = [];
  const still = new SoundLine((planar, n, first) => parts.push([first, n]));
  still.onMessage(fmtMsg, 0);
  for (let ms = 0; ms < 5000; ms += 100) still.onMessage(wave(4800, 1000), ms);
  check('sound line: given at its own time on a still screen (no picture taken), not held', parts.reduce((n, p) => n + p[1], 0) >= 48000 * 4.8,
    JSON.stringify(parts.slice(-3)));
}

// ------------------------------------------------------------ the user's microphone in it

{
  // its format from frame 13 (1, u32 rate, u8 channels; 0: stopped; one the page would not play, none); what it sent placed
  // on its own line, added to the session's sound where both sound at once
  const { micFormat } = await import('../public/assets/audio.js');
  const f13 = (rate, ch) => { const d = new Uint8Array(6); d[0] = 1; new DataView(d.buffer).setUint32(1, rate, true); d[5] = ch; return d; };
  const formats = [micFormat(f13(48000, 1)), micFormat(Uint8Array.of(0)), micFormat(f13(4000, 1)), micFormat(f13(44100, 3))];
  const left = new Float32Array(48000 * 3);
  const line = new SoundLine((planar, n, first) => left.set(planar.subarray(0, n), first));
  const pcm = (n, v) => { const d = new Uint8Array(n * 2), dv = new DataView(d.buffer); for (let i = 0; i < n; i++) dv.setInt16(i * 2, v, true); return d; };
  line.onMessage(new Uint8Array([70, ...new TextEncoder().encode(JSON.stringify([{ tag: 1, ch: 1, rate: 48000, bits: 16, align: 2 }]))]), 0);
  line.micFormat(f13(48000, 1));
  line.onMessage(new Uint8Array([87, 0, 0, ...pcm(48000, 8192)]), 500);     // the session's sound: a second from 0.5 s
  for (let ms = 1000; ms < 2000; ms += 100) line.micData(pcm(4800, 4096), ms);   // the microphone: a second from 1 s, in pieces
  line.finish(2500);
  const at = (ms) => +left[ms * 48].toFixed(3);
  check('microphone: its format from frame 13 (none when stopped or not playable); in the video, on its own line and added to the session\'s sound',
    JSON.stringify(formats) === JSON.stringify([{ tag: 1, ch: 1, rate: 48000, bits: 16, align: 2 }, null, null, null])
      && at(700) === 0.25 && at(1200) === 0.375 && at(1700) === 0.125 && at(2200) === 0,
    JSON.stringify({ formats, a: [at(700), at(1200), at(1700), at(2200)] }));
}

// ------------------------------------------------------------ the room a video may take

{
  // an hour at 1920 x 1080 with sound: up to the encoder's bit rate (2.5 Mbit/s) and a tenth more, plus Opus (96 kbit/s);
  // about 16 KB a second per megapixel with an office desktop; the recording measured live (374 s at 928 x 917, no sound:
  // its video took 4,974,977 bytes) within a tenth of its estimate; a recording still going counted up to now
  const hour = videoEstimate({ seconds: 3600, ended: 'x', width: 1920, height: 1080, sound: true });
  const quiet = videoEstimate({ seconds: 3600, ended: 'x', width: 1920, height: 1080 });
  const live = videoEstimate({ seconds: 374, ended: 'x', width: 928, height: 917 });
  const going = videoEstimate({ started: new Date(Date.now() - 60000).toISOString(), width: 1024, height: 768 });
  check('video room: up to the bit rate (and sound), about an office desktop\'s; the recording measured within a tenth; one still going up to now',
    Math.abs(hour.most - 3600 * (2488320 / 8 * 1.1 + 12000)) < 3600 && Math.abs(hour.about - 3600 * (2.0736 * 16000 + 12000)) < 3600
      && quiet.about < hour.about && Math.abs(live.about - 4974977) / 4974977 < 0.1 && going.most > 59 * (1e6 / 8 * 1.1) && going.most < 62 * (1e6 / 8 * 1.1),
    JSON.stringify({ hour, quiet, live, going }));
}

// ------------------------------------------------------------ a picture the encoder refused

{
  // the encoder throwing on a picture (closed, out of memory): the picture closed all the same (a VideoFrame holds a whole
  // picture of the desktop until closed; before, it was left to the collector)
  const { recordingVideo } = await import('../public/assets/recvideo.js');
  const frames = [], workers = [];
  const el = () => ({ style: {}, width: 1024, height: 768, append() {}, remove() {}, addEventListener() {}, getBoundingClientRect: () => ({ width: 1024, height: 768 }),
    getContext: () => new Proxy({}, { get: () => () => {} }) });
  Object.assign(globalThis, {
    document: { createElement: el, body: { append() {} }, addEventListener() {} },
    ResizeObserver: class { observe() {} disconnect() {} },
    location: { href: 'https://gateway.test/' },
    Worker: class { constructor() { workers.push(this); } postMessage() {} terminate() {} },
    VideoEncoder: class {
      static async isConfigSupported() { return { supported: true }; }
      constructor() { Object.assign(this, { encodeQueueSize: 0, state: 'configured' }); }
      configure() {}
      encode() { throw new Error('encoder closed'); }
      async flush() {}
      close() { this.state = 'closed'; }
    },
    VideoFrame: class { constructor() { frames.push(this); } close() { this.closed = true; } },
  });
  const made = recordingVideo({ sid: 's', info: { width: 1024, height: 768 }, csrf: 'x' }).then(() => 'made', (e) => e.message);
  for (let i = 0; i < 5 && !workers.length; i++) await new Promise((r) => setTimeout(r, 0));
  const wk = workers.at(-1);
  wk?.onmessage({ data: { t: 'cap', ms: 0 } });          // a picture asked for: the encoder throws
  await new Promise((r) => setTimeout(r, 0));
  wk?.onmessage({ data: { t: 'end' } });                 // (the page stopped the stream)
  const result = await made;
  check('video: a picture the encoder refused is closed all the same, and the video is not made', frames.length === 1 && frames[0].closed === true && result !== 'made',
    JSON.stringify({ frames: frames.length, closed: frames[0]?.closed, result }));
}

console.log(failed ? `\n${failed} failed` : '\nAll tests passed');
process.exit(failed ? 1 : 0);
