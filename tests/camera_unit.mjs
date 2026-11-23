// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// The camera in the browser, on doubles of the browser's media, video and canvas objects: node tests/camera_unit.mjs
'use strict';

let failed = 0;
const check = (name, ok, info = '') => { console.log((ok ? 'OK   ' : 'FAIL ') + name + (info ? ' ' + info : '')); if (!ok) failed++; };
const wait = (ms) => new Promise((r) => setTimeout(r, ms));
const until = async (ok, ms = 3000) => { for (const end = Date.now() + ms; !ok() && Date.now() < end;) await wait(5); };   // (a busy computer: no fixed wait)

const log = { asked: [], drawn: [], stopped: 0, quality: [] };
let devices = [{ kind: 'audioinput' }, { kind: 'videoinput' }], refuse = null;
Object.defineProperty(globalThis, 'navigator', { configurable: true, value: {
    mediaDevices: {
      enumerateDevices: async () => { if (devices === 'throw') throw new Error('no'); return devices; },
      getUserMedia: async (c) => {
        log.asked.push(c);
        if (refuse) throw Object.assign(new Error('refused'), { name: refuse });
        return { getTracks: () => [{ stop: () => log.stopped++ }] };
      },
    },
} });
Object.assign(globalThis, {
  window: { isSecureContext: true },
  document: {
    createElement: (tag) => (tag === 'video'
      ? { readyState: 4, videoWidth: 1280, videoHeight: 720, play: async () => {}, srcObject: null }
      : { width: 0, height: 0, getContext: () => ({ drawImage: (...a) => log.drawn.push(a.slice(1)) }),
        toBlob: (cb, type, q) => { log.quality.push([type, q]); cb(new Blob([Uint8Array.of(0xFF, 0xD8, 0xFF, 0xD9)])); } }),
  },
});
const { CamCapture } = await import('../public/assets/camera.js');

// whether there is a camera: its kind is known before any permission
const present = [await CamCapture.present()];
devices = [{ kind: 'audioinput' }];
present.push(await CamCapture.present());
devices = 'throw';
present.push(await CamCapture.present());
check('a camera present: known from the devices\' kinds, without permission; none, or an error: none', JSON.stringify(present) === '[true,false,false]', JSON.stringify(present));

// started at the size and rate the server chose: the camera asked for them, its picture cut to their proportions in the
// middle and scaled, a JPEG sent for each picture; stopped: the camera let go, no more pictures
const sent = [], nos = [], fails = [], errors = [];
const cam = new CamCapture({ send: (j, no) => { sent.push(j); nos.push(no); }, fail: (no) => fails.push(no), onError: (k) => errors.push(k) });
/** F_CAM: start at this size and rate, numbered `no` (each picture says it). */
const start = (w, h, fps, no = 5) => { const d = new Uint8Array(7), v = new DataView(d.buffer); d[0] = 1; v.setUint16(1, w, true); v.setUint16(3, h, true); d[5] = fps; d[6] = no; cam.onMessage(d); };
start(640, 480, 30);
await until(() => sent.length >= 3);
const n = sent.length;
cam.onMessage(Uint8Array.of(0));
await wait(150);
const c = log.asked[0]?.video;
check('started at the size and rate chosen: asked of the camera, cut to 4:3 in its middle and scaled, a JPEG each picture; stopped: let go, nothing more',
  c?.width?.ideal === 640 && c?.height?.ideal === 480 && c?.frameRate?.ideal === 30 && log.asked[0].audio === false
  && JSON.stringify(log.drawn[0]) === JSON.stringify([160, 0, 960, 720, 0, 0, 640, 480]) && n >= 3 && sent.every((j) => j[0] === 0xFF && j[1] === 0xD8)
  && log.quality[0]?.[0] === 'image/jpeg' && log.stopped === 1 && sent.length === n && !fails.length && nos.every((x) => x === 5),
  JSON.stringify({ c, drawn: log.drawn[0], n, after: sent.length, stopped: log.stopped }));

// a camera the browser does not allow, none, in use: the program there told (no picture will come) and the user too
const why = [];
for (const name of ['NotAllowedError', 'NotFoundError', 'NotReadableError', 'OverconstrainedError']) {
  refuse = name;
  start(320, 240, 15, 9);
  await until(() => errors.length > why.length);
  why.push(errors.at(-1));
}
refuse = null;
check('a camera not allowed, missing or in use: the gateway told (its requests answered), the user told why',
  JSON.stringify(why) === JSON.stringify(['cam_denied', 'cam_none', 'cam_busy', 'cam_failed']) && JSON.stringify(fails) === '[9,9,9,9]', JSON.stringify({ why, fails }));

// a start while the one before is still waiting for the camera: the older gives up (one camera open, the newest size)
let release;
globalThis.navigator.mediaDevices.getUserMedia = (c) => new Promise((r) => { log.asked.push(c); release = () => r({ getTracks: () => [{ stop: () => log.stopped++ }] }); });
const stopped0 = log.stopped;
start(640, 480, 30);
const first = release;
await wait(5);
cam.onMessage(Uint8Array.of(0));
first();
await until(() => log.stopped > stopped0);
await wait(20);
check('stopped while asking for the camera: let go as soon as it comes, never sending', log.stopped === stopped0 + 1 && !cam.live, JSON.stringify({ stopped: log.stopped - stopped0 }));
cam.stop();

// paced by a worker's timer (a hidden page's run once a second at most): a picture at each of its ticks, none without them;
// stopped, the worker ended; a worker that could not load: the page's own timer instead
const workers = [];
globalThis.Worker = class { constructor(url) { Object.assign(this, { url: String(url), posted: [], ended: false }); workers.push(this); } postMessage(m) { this.posted.push(m); } terminate() { this.ended = true; } };
globalThis.navigator.mediaDevices.getUserMedia = async () => ({ getTracks: () => [{ stop: () => {} }] });
sent.length = 0;
start(640, 480, 30, 11);
await until(() => workers.length === 1 && workers[0].onmessage);
await wait(150);
const untick = sent.length;
for (let i = 0; i < 3 && workers[0]?.onmessage; i++) { workers[0].onmessage({ data: 0 }); await until(() => sent.length > i); }
const ticked = sent.length;
cam.onMessage(Uint8Array.of(0));
start(640, 480, 30, 12);
await until(() => workers.length === 2 && workers[1].onerror);
workers[1]?.onerror?.();
await until(() => sent.length > ticked + 2);
cam.stop();
check('paced by a worker\'s timer: a picture each tick, none without; stopped, it ends; one that could not load: the page\'s own timer',
  workers.length === 2 && workers[0].url.endsWith('/tick-worker.js') && Math.abs(workers[0].posted[0] - 1000 / 30) < 0.01 && untick === 0 && ticked === 3 && workers[0].ended
  && workers[1].ended && sent.length > ticked + 2 && nos.at(-1) === 12, JSON.stringify({ workers: workers.length, untick, ticked, after: sent.length, ended: workers.map((w) => w.ended) }));
delete globalThis.Worker;
console.log(failed ? `${failed} failed` : 'All tests passed');
process.exit(failed ? 1 : 0);
