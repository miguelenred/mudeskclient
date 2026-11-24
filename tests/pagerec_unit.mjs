// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// A recording made in the page (pagerec.js) on browser doubles: what it draws, records and sends; its video read back
// and made seekable (webm.js). Run with: node tests/pagerec_unit.mjs
'use strict';

let failures = 0;
const check = (name, ok, detail = '') => {
  console.log((ok ? 'OK   ' : 'FAIL ') + name + (ok || !detail ? '' : ' ' + detail));
  if (!ok) failures++;
};
const settle = async () => { for (let i = 0; i < 20; i++) await new Promise((r) => setImmediate(r)); };

// ------------------------------------------------------------ browser doubles
const drawn = [];                                        // what each canvas of the recording was given
class Ctx2d {
  constructor(canvas) { this.canvas = canvas; this.ops = []; }
  fillRect(...a) { this.ops.push(['fill', ...a]); }
  drawImage(src, ...a) { this.ops.push(['image', src.name || 'pointer', ...a.map((x) => Math.round(x))]); }
  save() {} restore() {} translate() {} scale() {} beginPath() {} moveTo() {} lineTo() {} closePath() {} fill() { this.ops.push(['arrow']); } stroke() {}
}
class Track {
  constructor(kind) { this.kind = kind; this.frames = 0; this.stopped = false; }
  requestFrame() { this.frames++; }
  stop() { this.stopped = true; }
}
class Stream {
  constructor(tracks) { this.tracks = tracks; }
  getVideoTracks() { return this.tracks.filter((t) => t.kind === 'video'); }
  getAudioTracks() { return this.tracks.filter((t) => t.kind === 'audio'); }
  getTracks() { return this.tracks; }
  addTrack(t) { this.tracks.push(t); }
}
globalThis.HTMLCanvasElement = class {
  constructor() { this.width = 300; this.height = 150; this.ctx = null; }
  getContext() { this.ctx ??= new Ctx2d(this); drawn.push(this.ctx); return this.ctx; }
  captureStream(fps) {
    this.fps = fps;
    const s = new Stream([this.track = new Track('video')]);
    if (firefoxLike) { this.track.requestFrame = undefined; s.frames = 0; s.requestFrame = () => s.frames++; }   // (Firefox: on the stream)
    return s;
  }
};
let firefoxLike = false;
globalThis.document = { createElement: () => new HTMLCanvasElement() };
const recorders = [];
let supported = (t) => t.startsWith('video/webm;codecs=vp8');
globalThis.MediaRecorder = class {
  static isTypeSupported(t) { return supported(t); }
  constructor(stream, o) { Object.assign(this, { stream, o, state: 'inactive', mimeType: o.mimeType || 'video/webm' }); recorders.push(this); }
  start(slice) { this.slice = slice; this.state = 'recording'; }
  stop() { this.state = 'inactive'; this.ondataavailable?.({ data: new Blob(['LAST']) }); this.onstop?.(); }
  give(text) { this.ondataavailable?.({ data: new Blob([text]) }); }
};
const contexts = [];
let ctxState = 'running';                                // what a new AudioContext is (suspended: the page not used yet)
globalThis.AudioContext = class {
  constructor() { this.state = ctxState; this.currentTime = 0; this.played = []; this.closed = false; this.silent = []; contexts.push(this); }
  resume() { return Promise.resolve(); }
  close() { this.closed = true; return Promise.resolve(); }
  createMediaStreamDestination() { return { stream: new Stream([new Track('audio')]), name: 'dest' }; }
  createConstantSource() { const ctx = this; return { offset: { value: 1 }, connect(to) { this.to = to; }, start() { ctx.silent.push([this.offset.value, this.to?.name]); }, stop() { ctx.silent.push('stopped'); }, disconnect() {} }; }
  createBuffer(ch, n, rate) { return { duration: n / rate, copyToChannel() {} }; }
  createBufferSource() { const ctx = this; return { connect(to) { this.to = to; }, start(at) { ctx.played.push([this.to?.name, +at.toFixed(3)]); } }; }
};
let timers = [];
globalThis.setInterval = (f, ms) => { timers.push({ f, ms }); return timers.length; };
globalThis.clearInterval = (id) => { if (timers[id - 1]) timers[id - 1].f = null; };
const tick = () => timers.forEach((x) => x.f?.());

const { PageRecorder, REC, REC_FIRST, REC_LAST, REC_FAILED, pickType, pageBitrate, videoSize, drawPicture, loadPageVideo, canRecordPage } = await import('../public/assets/pagerec.js');
const { el, WebmRemux } = await import('../public/assets/webm.js');

/** A message the page sends: [22][u16 gen][u32 n][u8 flags](first: u16 + JSON) + bytes. */
const read = (m) => {
  const dv = new DataView(m.buffer, m.byteOffset);
  let o = 8, head = null;
  if (m[7] & REC_FIRST) { const n = dv.getUint16(8, true); head = JSON.parse(new TextDecoder().decode(m.subarray(10, 10 + n))); o = 10 + n; }
  return { type: m[0], gen: dv.getUint16(1, true), n: dv.getUint32(3, true), flags: m[7], head, bytes: new TextDecoder().decode(m.subarray(o)) };
};

{
  // started: the desktop and its pointer drawn into a canvas of its own (scaled into 3840 x 2160), a picture asked for
  // only when something changed or a second went by; the session's sound and microphone on a line of their own (also with
  // the page muted: the recording's own player); each second's piece sent in order, the first saying what it records;
  // stopped, the rest with REC_LAST and everything let go
  const surface = { name: 'desktop', width: 1280, height: 800 };
  const session = { surface, ptr: { css: 'default', img: { name: 'ptr', width: 32, height: 32 }, hx: 1, hy: 2 }, mouseAt: [100, 50], counts: { frames: 5 },
    soundFormats: new Uint8Array([70, ...new TextEncoder().encode(JSON.stringify([{ tag: 1, ch: 2, rate: 44100, bits: 16, align: 4 }]))]),
    micFormatMsg: Uint8Array.of(1, 0x44, 0xac, 0, 0, 1) };
  const sent = [];
  const r = new PageRecorder({ session, gen: 7, send: (m) => sent.push(read(m)), sound: true });
  const started = r.start();
  const mr = recorders.at(-1), track = r.track, canvas = r.canvas;
  const first = [started, canvas.width, canvas.height, mr.slice, mr.mimeType, mr.o.videoBitsPerSecond, mr.o.audioBitsPerSecond, mr.stream.getTracks().map((t) => t.kind).join(), track.frames];
  const ops0 = canvas.ctx.ops.map((x) => x.join(':'));
  tick();                                                // nothing changed: no picture
  const still = track.frames;
  session.counts.frames++;                               // drawn: a picture
  tick();
  session.mouseAt = [200, 60];                           // the mouse moved: a picture
  tick();
  const pictures = track.frames;
  // the remote computer's sound and the microphone it hears, played on the recording's own line
  const ctx = contexts.at(-1);
  r.onSound(new Uint8Array([87, 0, 0, ...new Uint8Array(17640)]));
  r.micData(new Uint8Array(8820));
  const sound = ctx.played.map((x) => x.join(':'));
  mr.give('ONE');
  mr.give('TWO');
  await settle();
  const before = sent.map((s) => [s.type, s.gen, s.n, s.flags, s.bytes].join(':'));
  const head = sent[0]?.head;
  r.stop();
  await settle();
  r.onSound(new Uint8Array([87, 0, 0, ...new Uint8Array(17640)]));   // (after it stopped: not played)
  const after = sent.slice(2).map((s) => [s.n, s.flags, s.bytes].join(':'));
  const released = [track.stopped, ctx.closed, timers.every((x) => !x.f), ctx.played.length === sound.length, ctx.silent.join() === '0,dest,stopped'];
  check('page recording: the desktop and pointer drawn into its own canvas, a picture only when something changed; sound and microphone on its own line; pieces in order, the first saying what it records, the rest with REC_LAST; all let go',
    JSON.stringify(first) === JSON.stringify([true, 1280, 800, 1000, 'video/webm;codecs=vp8,opus', pageBitrate(1280, 800), 64000, 'video,audio', 1])
      && ops0.join() === 'fill:0:0:1280:800,image:desktop:0:0:1280:800:0:0:1280:800,image:ptr:99:48:32:32' && still === 1 && pictures === 3
      && sound.join() === 'dest:0.06,dest:0.06' && before.join() === `22:7:0:${REC_FIRST}:ONE,22:7:1:0:TWO`
      && JSON.stringify(head) === JSON.stringify({ mime: 'video/webm;codecs=vp8,opus', w: 1280, h: 800, sound: true })
      && after.join() === `2:0:LAST,3:${REC_LAST}:` && released.every(Boolean),
    JSON.stringify({ first, ops0, still, pictures, sound, before, head, after, released }));
}

{
  // the sound mixed in the session's own AudioContext when it runs (kept running once the recording ends); with none
  // running (the page not used yet), the picture alone: an audio track that gives no data held Chrome's whole recording back
  const surface = { name: 'desktop', width: 640, height: 480 };
  const own = new AudioContext();
  const shared = { surface, ptr: null, mouseAt: null, counts: { frames: 0 }, audio: { ctx: own } };
  const n0 = contexts.length;
  const r1 = new PageRecorder({ session: shared, gen: 1, send() {}, sound: true });
  r1.start();
  const usedShared = [contexts.length === n0, recorders.at(-1).stream.getTracks().map((t) => t.kind).join(), own.silent.join()];
  r1.dispose();
  usedShared.push(own.closed, own.silent.join());
  ctxState = 'suspended';
  const sent = [];
  const r2 = new PageRecorder({ session: { surface, ptr: null, mouseAt: null, counts: { frames: 0 }, audio: { ctx: { state: 'suspended' } } }, gen: 2, send: (m) => sent.push(read(m)), sound: true });
  r2.start();
  const mr2 = recorders.at(-1);
  const alone = [mr2.stream.getTracks().map((t) => t.kind).join(), mr2.mimeType, contexts.at(-1).closed, mr2.o.audioBitsPerSecond];
  mr2.give('V');
  await settle();
  alone.push(sent[0]?.head?.sound);
  r2.dispose();
  ctxState = 'running';
  // the microphone's own context, while it records (a session with the microphone and no sound)
  const micCtx = new AudioContext();
  const n1 = contexts.length;
  const r3 = new PageRecorder({ session: { surface, ptr: null, mouseAt: null, counts: { frames: 0 }, audio: null, mic: { live: { ctx: micCtx } } }, gen: 3, send() {}, sound: true });
  r3.start();
  usedShared.push(contexts.length === n1, micCtx.silent.join());
  r3.dispose();
  check('page recording: the sound mixed in the session\'s own AudioContext when it runs (a silent source keeping it going; left running after); none running: the picture alone, said',
    JSON.stringify(usedShared) === JSON.stringify([true, 'video,audio', '0,dest', false, '0,dest,stopped', true, '0,dest'])
      && JSON.stringify(alone) === JSON.stringify(['video', 'video/webm;codecs=vp8', true, undefined, false]),
    JSON.stringify({ usedShared, alone }));
}

{
  // a browser that cannot record (no MediaRecorder): said at once, as the first piece; one that fails while recording:
  // REC_FAILED; the session over: nothing more sent
  const surface = { name: 'desktop', width: 800, height: 600 };
  const session = { surface, ptr: null, mouseAt: null, counts: { frames: 0 } };
  const MR = globalThis.MediaRecorder;
  globalThis.MediaRecorder = undefined;
  const sent = [];
  const r = new PageRecorder({ session, gen: 3, send: (m) => sent.push(read(m)) });
  const ok = r.start();
  await settle();
  globalThis.MediaRecorder = MR;
  const cannot = [ok, canRecordPage(), sent.length, sent[0]?.flags, sent[0]?.n, JSON.stringify(sent[0]?.head)];
  const sent2 = [];
  const r2 = new PageRecorder({ session, gen: 4, send: (m) => sent2.push(read(m)) });
  r2.start();
  const mr = recorders.at(-1);
  mr.give('A');
  mr.state = 'inactive';
  mr.onerror();                                          // (it failed: the browser stopped it)
  await settle();
  const failed = sent2.map((s) => [s.n, s.flags, s.bytes].join(':'));
  const sent3 = [];
  const r3 = new PageRecorder({ session, gen: 5, send: (m) => sent3.push(m) });
  r3.start();
  recorders.at(-1).give('B');
  r3.dispose();
  await settle();
  // given up by the page (its pieces could not reach the gateway): stopped at once, REC_FAILED as the next piece, nothing after
  const sent4 = [];
  const r4 = new PageRecorder({ session, gen: 6, send: (m) => sent4.push(read(m)) });
  r4.start();
  const mr4 = recorders.at(-1);
  mr4.give('C');
  await settle();
  r4.abandon();
  mr4.give('D');
  r4.abandon();
  await settle();
  const abandoned = sent4.map((s) => [s.n, s.flags, s.bytes].join(':'));
  check('page recording: a browser that cannot record says so at once; one that fails while recording sends REC_FAILED; the session over: nothing more; given up by the page: REC_FAILED next, nothing after',
    JSON.stringify(cannot) === JSON.stringify([false, true, 1, REC_FIRST | REC_FAILED, 0, JSON.stringify({ mime: '', w: 0, h: 0, sound: false })])
      && failed.join() === `0:${REC_FIRST}:A,1:${REC_FAILED}:` && sent3.length === 0 && abandoned.join() === `0:${REC_FIRST}:C,1:${REC_FAILED}:`,
    JSON.stringify({ cannot, failed, sent3: sent3.length, abandoned }));
}

{
  // its file type: WebM with VP9, else VP8 (with Opus when there is sound), else MP4 (Safari); the bit rate by its size;
  // a desktop larger than 3840 x 2160 scaled into it; one that grew drawn smaller, one that shrank in the middle (never
  // larger); no pointer before the first one
  const types = [pickType(true, (t) => true), pickType(false, (t) => t.includes('vp8')), pickType(true, (t) => t.startsWith('video/mp4')), pickType(true, () => false)];
  const sizes = [videoSize(1280, 800), videoSize(7680, 2160), videoSize(1001, 601)];
  const out = new HTMLCanvasElement();
  Object.assign(out, { width: 1920, height: 1080 });
  const c = out.getContext('2d');
  drawPicture(c, { name: 'two', width: 3840, height: 1080 }, { css: 'default' }, [3840, 0]);
  const grew = c.ops.splice(0).map((x) => x.join(':'));
  drawPicture(c, { name: 'one', width: 1280, height: 720 }, null, [5, 5]);
  const shrank = c.ops.splice(0).map((x) => x.join(':'));
  // Firefox asks for a picture on the stream, not the track: asked there (without it, no picture until the browser took one)
  firefoxLike = true;
  const rf = new PageRecorder({ session: { surface: { name: 'desktop', width: 800, height: 600 }, ptr: null, mouseAt: null, counts: { frames: 0 } }, gen: 9, send() {} });
  rf.start();
  rf.s.counts.frames++;
  tick();
  const ffFrames = rf.stream.frames;
  rf.dispose();
  firefoxLike = false;
  check('page recording: a picture asked of the stream where the browser has it there (Firefox)', ffFrames === 2, String(ffFrames));
  check('page recording: its file type, bit rate and size; a desktop that grew drawn smaller, one that shrank in the middle; no pointer before the first',
    JSON.stringify(types) === JSON.stringify(['video/webm;codecs=vp9,opus', 'video/webm;codecs=vp8', 'video/mp4;codecs=avc1,mp4a.40.2', ''])
      && JSON.stringify(sizes) === '[[1280,800],[3840,1080],[1002,602]]' && pageBitrate(1920, 1080) === 1244160 && pageBitrate(100, 100) === 500000
      && pageBitrate(8000, 8000) === 6000000
      && grew.join() === 'fill:0:0:1920:1080,image:two:0:0:3840:1080:0:270:1920:540,arrow' && shrank.join() === 'fill:0:0:1920:1080,image:one:0:0:1280:720:320:180:1280:720',
    JSON.stringify({ types, sizes, grew, shrank }));
}

// ------------------------------------------------------------ its video read back, made seekable

/** A WebM as MediaRecorder makes it: the segment and its clusters of unknown length, no duration nor cues. */
function recorderWebm(clusters) {
  const unknown = (id) => { const b = el(id, new Uint8Array(0)); const h = b.subarray(0, b.length - 1); return new Uint8Array([...h, 0x01, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF]); };
  const ebml = el(0x1A45DFA3, [el(0x4282, new TextEncoder().encode('webm'))]);
  const info = el(0x1549A966, [el(0x2AD7B1, Uint8Array.of(0x0F, 0x42, 0x40)), el(0x4D80, new TextEncoder().encode('Chrome'))]);
  const tracks = el(0x1654AE6B, [el(0xAE, [el(0xD7, Uint8Array.of(1)), el(0x83, Uint8Array.of(2)), el(0x86, new TextEncoder().encode('A_OPUS'))]),
    el(0xAE, [el(0xD7, Uint8Array.of(2)), el(0x83, Uint8Array.of(1)), el(0x86, new TextEncoder().encode('V_VP8'))])]);
  const parts = [ebml, unknown(0x18538067), info, tracks];
  for (const [time, blocks] of clusters) {
    parts.push(unknown(0x1F43B675), el(0xE7, Uint8Array.of(time >> 8, time & 255)));
    for (const [track, rel, key, data] of blocks) {
      const b = new Uint8Array(4 + data.length);
      b[0] = 0x80 | track;
      new DataView(b.buffer).setInt16(1, rel);
      b[3] = key ? 0x80 : 0;
      b.set(data, 4);
      parts.push(el(0xA3, b));
    }
  }
  const all = new Uint8Array(parts.reduce((n, p) => n + p.length, 0));
  let o = 0;
  for (const p of parts) { all.set(p, o); o += p.length; }
  return all;
}

/** The elements of a WebM (id, its data's length known), top level and a segment's and its clusters' children. */
function elements(b) {
  const out = [];
  const walk = (o, end, depth) => {
    while (o < end) {
      let n = 1;
      while (!(b[o] & (0x100 >> n))) n++;
      let id = 0;
      for (let i = 0; i < n; i++) id = id * 256 + b[o + i];
      const s = o + n;
      let m = 1;
      while (!(b[s] & (0x100 >> m))) m++;
      let size = b[s] & ((0x100 >> m) - 1);
      for (let i = 1; i < m; i++) size = size * 256 + b[s + i];
      out.push({ id, size, at: o, depth, data: b.subarray(s + m, s + m + size) });
      if (id === 0x18538067 || id === 0x1F43B675 || id === 0x1C53BB6B || id === 0xBB || id === 0xB7 || id === 0x1549A966) walk(s + m, s + m + size, depth + 1);
      o = s + m + size;
    }
  };
  walk(0, b.length, 0);
  return out;
}

{
  // MediaRecorder's WebM made seekable: given in pieces cut anywhere; the segment and clusters with their lengths, the
  // duration (its last block and a frame), the tracks and blocks as they were, cues at the clusters with a key frame of the
  // video (its track number read from the tracks); one cut short ends at its last whole element; not a WebM: null
  const v = (t) => new TextEncoder().encode(t);
  const src = recorderWebm([[0, [[2, 0, true, v('KEY0')], [1, 10, true, v('aud0')], [2, 66, false, v('d1')]]],
    [1000, [[1, 0, true, v('aud1')], [2, 33, false, v('d2')]]], [2000, [[2, 0, true, v('KEY2')], [2, 500, false, v('d3')]]]]);
  const remux = (bytes, step) => { const r = new WebmRemux(); for (let o = 0; o < bytes.length; o += step) r.push(bytes.subarray(o, o + step)); return r.finish(); };
  const outs = [];
  for (const step of [1, 7, 4096]) outs.push(new Uint8Array(await remux(src, step).arrayBuffer()));
  const same = outs.every((o) => o.length === outs[0].length && o.every((x, i) => x === outs[0][i]));
  const e = elements(outs[0]);
  const seg = e.find((x) => x.id === 0x18538067);
  const dv = (d) => new DataView(d.buffer, d.byteOffset, d.byteLength);
  const duration = dv(e.find((x) => x.id === 0x4489).data).getFloat64(0);
  const clusters = e.filter((x) => x.id === 0x1F43B675);
  const blocks = e.filter((x) => x.id === 0xA3).map((x) => new TextDecoder().decode(x.data.subarray(4)));
  const cueTimes = e.filter((x) => x.id === 0xB3).map((x) => x.data.reduce((a, b) => a * 256 + b, 0));
  const cueAt = e.filter((x) => x.id === 0xF1).map((x) => x.data.reduce((a, b) => a * 256 + b, 0));
  const segStart = seg.at + 12;                          // (its id and an 8-byte length)
  const cuesPointAtClusters = cueAt.every((p) => clusters.some((c) => c.at === segStart + p));
  const cueTrack = e.filter((x) => x.id === 0xF7).map((x) => x.data[0]);
  const lengths = seg.at + 12 + seg.size === outs[0].length;
  const cut = new WebmRemux();
  cut.push(src.subarray(0, src.length - 3));             // (its last block cut)
  const cutBlocks = elements(new Uint8Array(await cut.finish().arrayBuffer())).filter((x) => x.id === 0xA3).map((x) => new TextDecoder().decode(x.data.subarray(4)));
  const bigBlock = new Uint8Array(4 + (12 << 20));
  bigBlock.set([0x82, 0, 0, 0x80]);
  const bigSrc = new Uint8Array([...recorderWebm([[0, []]]), ...el(0xA3, bigBlock), ...el(0xA3, Uint8Array.of(0x82, 0, 10, 0, ...v('END')))]);
  const bigR = new WebmRemux();
  const tb = performance.now();
  for (let o = 0; o < bigSrc.length; o += 4096) bigR.push(bigSrc.subarray(o, o + 4096));
  const bigMs = performance.now() - tb;
  const bigBlocks = elements(new Uint8Array(await bigR.finish().arrayBuffer())).filter((x) => x.id === 0xA3).map((x) => x.data.length);
  const notWebm = new WebmRemux();
  notWebm.push(v('ftypisom....'));
  // a piece lost on the way: what follows starts in the middle of a block; an element said to be huge is not waited for
  // (each piece joined again to the rest made a long recording hang the tab): the file ends at the last whole block
  const lost = new WebmRemux();
  lost.push(src.subarray(0, 200));
  const huge = new Uint8Array([0x1F, 0x43, 0xB6, 0x75, 0x01, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xA3, 0x08, 0x40, 0, 0, 0, 0, 0, 0]);   // (a block said to be 2^54 bytes)
  lost.push(huge);
  const t0 = performance.now();
  for (let i = 0; i < 2000; i++) lost.push(new Uint8Array(4096));
  const lostMs = performance.now() - t0;
  const lostBad = lost.bad;                              // (nothing more kept: it is not a video's)
  const lostOut = lost.finish();
  const lostBlocks = lostOut ? elements(new Uint8Array(await lostOut.arrayBuffer())).filter((x) => x.id === 0xA3).length : -1;
  // a Void and a CRC-32 inside a cluster of a known length: its blocks kept
  const known = el(0x1F43B675, [el(0xBF, Uint8Array.of(1, 2, 3, 4)), el(0xE7, Uint8Array.of(0)), el(0xEC, new Uint8Array(3)),
    el(0xA3, Uint8Array.of(0x82, 0, 0, 0x80, ...v('KEY'))), el(0xA3, Uint8Array.of(0x82, 0, 40, 0, ...v('D2')))]);
  const firstCluster = src.findIndex((x, i) => x === 0x1F && src[i + 1] === 0x43 && src[i + 2] === 0xB6 && src[i + 3] === 0x75);
  const head = src.subarray(0, firstCluster);
  const withVoid = new WebmRemux();
  withVoid.push(head);
  withVoid.push(known);
  const voidBlocks = elements(new Uint8Array(await withVoid.finish().arrayBuffer())).filter((x) => x.id === 0xA3).map((x) => new TextDecoder().decode(x.data.subarray(4)));
  check('remux: MediaRecorder\'s WebM given in pieces made seekable: lengths, duration, tracks and blocks as they were, cues at the clusters with a key frame of the video; one cut short to its last whole block; not a WebM: nothing; garbage after a piece lost not waited for (no hang); a large block in small pieces joined once; a Void and a CRC-32 in a cluster',
    same && lengths && Math.abs(duration - 2567) < 1 && clusters.length === 3 && blocks.join() === 'KEY0,aud0,d1,aud1,d2,KEY2,d3'
      && cueTimes.join() === '0,2000' && cuesPointAtClusters && cueTrack.join() === '2,2' && cutBlocks.join() === 'KEY0,aud0,d1,aud1,d2,KEY2'
      && notWebm.finish() === null && lostMs < 1500 && lostBlocks >= 0 && lostBad && bigMs < 1500 && bigBlocks.join() === `${bigBlock.length},7` && voidBlocks.join() === 'KEY,D2',
    JSON.stringify({ same, lengths, duration, clusters: clusters.length, blocks, cueTimes, cuesPointAtClusters, cueTrack, cutBlocks, lostMs, lostBlocks, voidBlocks, bigMs, bigBlocks }));
}

{
  // read back from the gateway: its frames (the video's in order, the rest left out), made seekable; MP4 as it is; one not
  // read to its end rejects with what came before; refused: why
  const frame = (type, bytes) => { const f = new Uint8Array(9 + bytes.length); const d = new DataView(f.buffer); d.setUint8(4, type); d.setUint32(5, bytes.length, true); f.set(bytes, 9); return f; };
  const webm = recorderWebm([[0, [[2, 0, true, new TextEncoder().encode('KEY0')]]]]);
  const answer = (chunks, status = 200, error = null) => async (url, o) => {
    answer.asked = [url, JSON.parse(o.body).sid, o.headers['X-CSRF']];
    return { ok: status === 200, status, json: async () => (error ? { error } : Promise.reject(new SyntaxError('not JSON'))),
      body: new ReadableStream({ start(c) { chunks.forEach((x) => c.enqueue(x)); c.close(); } }) };
  };
  const whole = new Uint8Array([...frame(33, webm.subarray(0, 50)), ...frame(3, new TextEncoder().encode('{}')), ...frame(33, webm.subarray(50)), ...frame(0, new TextEncoder().encode('end'))]);
  const pieces = [whole.subarray(0, 13), whole.subarray(13, 70), whole.subarray(70)];
  globalThis.fetch = answer(pieces);
  let progress = 0;
  const blob = await loadPageVideo({ sid: 'ab'.repeat(16), csrf: 'tok', onProgress: (n) => { progress = n; } });
  const blocks = elements(new Uint8Array(await blob.arrayBuffer())).filter((x) => x.id === 0xA3).length;
  const asked = answer.asked;
  globalThis.fetch = answer([frame(33, new TextEncoder().encode('MP4-BYTES')), frame(0, new TextEncoder().encode('end'))]);
  const mp4 = await loadPageVideo({ sid: 'x', csrf: 't', mime: 'video/mp4' });
  globalThis.fetch = answer([frame(33, webm)]);                 // (no "end": the rest could not be read)
  const damaged = await loadPageVideo({ sid: 'x', csrf: 't' }).then(() => null, (e) => [e.message, e.blob?.size > 0]);
  const refused = [];
  for (const [status, error] of [[404, 'not_found'], [401, 'auth'], [403, 'csrf'], [404, null], [502, null]]) {
    globalThis.fetch = answer([], status, error);
    refused.push(await loadPageVideo({ sid: 'x', csrf: 't' }).then(() => null, (e) => e.message));
  }
  check('page recording read back: its video\'s frames in order made seekable (the rest left out), MP4 as it is; one not read to its end rejects with what came before; refused: why, as the gateway says it (an expired web session, auth)',
    JSON.stringify(asked) === JSON.stringify(['rdp.php?a=replay', 'ab'.repeat(16), 'tok']) && blob.type === 'video/webm' && blocks === 1 && progress === whole.length
      && mp4.type === 'video/mp4' && (await mp4.text()) === 'MP4-BYTES' && JSON.stringify(damaged) === '["recording_damaged",true]' && refused.join() === 'not_found,auth,csrf,not_found,recording_damaged',
    JSON.stringify({ asked, type: blob.type, blocks, progress, damaged, refused }));
}

console.log(failures ? `\n${failures} failed` : '\nAll tests passed');
process.exit(failures ? 1 : 0);
