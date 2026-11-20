// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// Measures one connection under a workload: traffic, gateway CPU and memory, decoding time and frame rate.
// node tests/bench.mjs host port user password [workload] [seconds] [out.ppm]
//   workload: idle | text (console printing a long listing) | drag (a window moved in circles) | anim (Ribbons screensaver)
//             | sound (with AUDIO=1: a system sound played three times)
//   options (environment, as tests/rdp_probe.php): BPP=15|16|24|32, COMPRESS=0..3, NOORDERS=1, GFX=1|avc, AUDIO=1, W, H;
//   REFRESH_CHECK=1 compares the screen before and after a full repaint by the server (leftovers of drawing orders)
// The gateway side is tests/rdp_probe.php in PIPE mode; frames are decoded with the browser decoders (tests/render.mjs) and the
// graphics pipeline frames are acknowledged as the browser does. H.264 frames are counted but not decoded (WebCodecs).
// Prints one JSON line; the workload setup types into the Run dialog of an es-ES Windows session.
import net from 'node:net';
import { spawn } from 'node:child_process';
import { writeFileSync } from 'node:fs';
import { performance } from 'node:perf_hooks';
import { fileURLToPath } from 'node:url';
import { ev } from '../public/assets/keymap.js';
import { Screen } from './render.mjs';

const [host, port, user, pass, workload = 'idle', seconds = '20', outPpm] = process.argv.slice(2);
// H.264: Node has no WebCodecs; frames come out at once (counted, not decoded) so the pipeline keeps acknowledging them as a browser does
globalThis.VideoDecoder ??= class { constructor(o) { this.o = o; } configure() {} decode() { queueMicrotask(() => this.o.output({ close() {} })); } close() {} };
globalThis.EncodedVideoChunk ??= class { constructor(o) { Object.assign(this, o); } };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const step = (s) => process.env.DEBUG && console.error(`[${(performance.now() / 1000).toFixed(1)}s] ${s}`);
let sock, decodeMs = 0, frames = 0, gfxFrames = 0, audioBytes = 0, logon = false, statsWait = null;
const errors = new Set();
const screen = new Screen((frame) => {           // acknowledge each graphics frame, as session.js does
  gfxFrames++;
  const m = new Uint8Array(9), dv = new DataView(m.buffer);
  m[0] = 10;
  dv.setUint32(1, frame, true);
  dv.setUint32(5, gfxFrames, true);
  send(m);
});
const send = (payload) => { const h = Buffer.alloc(4); h.writeUInt32LE(payload.length); sock?.write(Buffer.concat([h, Buffer.from(payload)])); };
const input = (events) => { const len = events.reduce((n, e) => n + e.length, 2), m = new Uint8Array(len); m[0] = 1; m[1] = events.length; let o = 2; for (const e of events) { m.set(e, o); o += e.length; } send(m); };
const keys = (...codes) => input([...codes.map((c) => ev.key(c, false)), ...codes.reverse().map((c) => ev.key(c, true))]);
const stats = () => new Promise((r) => { statsWait = r; send([12]); });
async function type(text) {                     // one character at a time: Windows mixes up fast Unicode input
  for (const ch of text) { input([ev.unicode(ch.charCodeAt(0), false), ev.unicode(ch.charCodeAt(0), true)]); await sleep(150); }
}
async function run(cmd) { keys(0x15b, 0x13); await sleep(1500); await type(cmd); keys(0x1c); }   // Win+R, command, Enter

const W = +(process.env.W || 1024), H = +(process.env.H || 768);
const workloads = {
  idle: { setup: async () => {}, during: async () => {}, teardown: async () => {} },
  text: { setup: () => run('cmd /c dir /s C:\\Windows'), during: async () => {}, teardown: async () => { keys(0x1d, 0x2e); await sleep(1000); keys(0x38, 0x3e); } },
  drag: {
    setup: async () => { keys(0x15b, 0x12); await sleep(3000); keys(0x15b, 0x14b); await sleep(1000); keys(0x01); },   // Win+E, Win+Left, Esc
    during: async (until) => {
      const cx = Math.round(W * 0.32), cy = 10;      // empty title bar of the window snapped to the left, between its tabs and buttons
      input([ev.mouse(0x0800, cx, cy), ev.mouse(0x9000, cx, cy)]);
      for (let a = 0; performance.now() < until; a += 0.05) { input([ev.mouse(0x0800, Math.round(W / 2 + Math.cos(a) * W / 4), Math.round(H / 3 + Math.sin(a) * H / 5))]); await sleep(30); }
      input([ev.mouse(0x1000, W / 2, H / 3)]);
    },
    teardown: async () => { keys(0x38, 0x3e); },
  },
  anim: { setup: () => run('ribbons.scr /s'), during: async () => {}, teardown: async () => { input([ev.mouse(0x0800, 10, 10)]); await sleep(300); input([ev.mouse(0x0800, 300, 300)]); } },
  sound: {                                       // with AUDIO=1: a system sound played three times (about 12 s)
    setup: () => run("powershell -w h -c 1..3|%{(New-Object Media.SoundPlayer 'C:\\Windows\\Media\\Alarm05.wav').PlaySync()}"),
    during: async () => {}, teardown: async () => {},
  },
};
const wl = workloads[workload];
if (!wl) throw new Error('unknown workload ' + workload);
const mem0 = (({ heapUsed, arrayBuffers }) => heapUsed + arrayBuffers)(process.memoryUsage());   // before any decoder state

const server = net.createServer((s) => {
  sock = s;
  s.on('error', () => {});                       // the gateway side ending abruptly is reported by its own output
  let buf = Buffer.alloc(0);
  s.on('data', (d) => {
    buf = Buffer.concat([buf, d]);
    let p = 0;
    while (p + 5 <= buf.length && p + 5 + buf.readUInt32LE(p + 1) <= buf.length) {
      const type = buf[p], len = buf.readUInt32LE(p + 1), data = new Uint8Array(buf.subarray(p + 5, p + 5 + len));
      p += 5 + len;
      if (type === 255) { statsWait?.(JSON.parse(Buffer.from(data).toString())); continue; }
      if (type === 3 && Buffer.from(data).toString().includes('"logon"')) logon = true;
      frames++;
      if (type === 7) audioBytes += len;
      const t = performance.now();
      try { screen.feed(type, data); } catch (e) { errors.add(e.message); }
      decodeMs += performance.now() - t;
    }
    buf = buf.subarray(p);
  });
}).listen(0, '127.0.0.1');
await new Promise((r) => server.once('listening', r));

const env = { ...process.env, PIPE: `127.0.0.1:${server.address().port}` };
const php = spawn(process.env.PHP || 'php', [fileURLToPath(new URL('./rdp_probe.php', import.meta.url)), host, port, user, pass, '', '600', 'auto', process.env.BPP || '16'], { env });
let log = '';
php.stdout.on('data', (d) => { log += d; });
php.stderr.on('data', (d) => { log += d; });
process.on('exit', () => php.kill());

for (let t = 0; !logon && t < 300; t++) await sleep(100);   // wait for the desktop (session reconnection takes a few seconds)
step(`logon ${logon}`);
await sleep(3000);
await wl.setup();
step('setup done');
await sleep(3000);
const a = await stats(), f0 = frames, g0 = gfxFrames, d0 = decodeMs, a0 = audioBytes, t0 = performance.now();
step('measuring');
let memPeak = 0;                                 // browser side: JS heap + typed arrays of the decoders and the screen
const sampler = setInterval(() => { const m = process.memoryUsage(); memPeak = Math.max(memPeak, m.heapUsed + m.arrayBuffers); }, 250);
await wl.during(t0 + seconds * 1000);
await sleep(Math.max(0, t0 + seconds * 1000 - performance.now()));
clearInterval(sampler);
const b = await stats(), secs = (performance.now() - t0) / 1000;
step('measured');
if (outPpm) writeFileSync(outPpm, screen.ppm());
await wl.teardown();
await sleep(1500);
let refreshDiff;
if (process.env.REFRESH_CHECK) {                 // leftovers check: once still, the screen must not change when the server repaints it all
  const before = screen.fb.slice(), f1 = frames;
  send([6]);
  await sleep(2500);
  refreshDiff = { px: before.reduce((n, v, i) => n + (v !== screen.fb[i]), 0), messages: frames - f1 };   // messages: the repaint did come
  if (outPpm) {
    const after = screen.fb;
    screen.fb = before;
    writeFileSync(outPpm.replace(/\.ppm$/, '.before-refresh.ppm'), screen.ppm());
    screen.fb = after;
    writeFileSync(outPpm.replace(/\.ppm$/, '.after-refresh.ppm'), screen.ppm());
  }
}
send([5]);
await new Promise((r) => php.on('exit', r));
server.close();
const mode = `${process.env.GFX ? 'gfx' + (process.env.GFX === 'avc' ? '+avc' : '') : 'bitmaps'} ${process.env.BPP || 16}bpp`
  + ` compress=${process.env.COMPRESS || 0}${process.env.NOORDERS ? '' : ' +orders'}`;
console.log(JSON.stringify({
  mode, workload, seconds: +secs.toFixed(1), size: `${screen.W}x${screen.H}`,
  serverKBs: +((b.in - a.in) / 1024 / secs).toFixed(1), browserKBs: +((b.out - a.out) / 1024 / secs).toFixed(1),
  gatewayCpuPct: +((b.cpu - a.cpu) / secs * 100).toFixed(1), gatewayPeakMB: +(b.mem / 1048576).toFixed(1),
  decodeCpuPct: +((decodeMs - d0) / 10 / secs).toFixed(1), decodeMB: +((memPeak - mem0) / 1048576).toFixed(1), messagesPerS: +((frames - f0) / secs).toFixed(1),
  gfxFps: +((gfxFrames - g0) / secs).toFixed(1), audioKBs: +((audioBytes - a0) / 1024 / secs).toFixed(1), video: screen.video,
  ...(refreshDiff !== undefined ? { refreshDiffPx: refreshDiff } : {}), unsupported: screen.bad,
  ...(/ERROR/.test(log) ? { error: log.match(/ERROR.*/)[0] } : {}), ...(errors.size ? { decodeErrors: [...errors] } : {}),
  ...(screen.notes.length ? { gfxCaps: screen.notes[0].replace('caps confirm ', ''), codecs: Object.fromEntries(Object.entries(screen.gfx.stats).filter(([k]) => k.startsWith('wire'))) } : {}),
}));
