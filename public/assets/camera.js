// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// This computer's camera for the remote computer (with the microphone: one connection option, off by default): on only while
// a program there uses it, as the gateway says, at the size and rate the server chose; each picture a JPEG (Motion JPEG).
// The page's own video and canvas: the same in Chrome, Edge and Firefox. Paced by a worker's timer (tick-worker.js): a hidden
// page's timers run once a second at most.
'use strict';

const QUALITY = 0.75;                                    // JPEG quality: 640 x 480 in about 30-50 KB

export class CamCapture {
  /** o: {send(Uint8Array jpeg, start's number), fail(start's number), onError(i18n key)} */
  constructor(o) {
    Object.assign(this, o);
    this.run = 0;                                        // each start; an older one still starting gives up
    this.live = null;
  }

  /** Whether this computer has a camera (its kind is known before any permission is asked). */
  static async present() {
    try {
      return ((await navigator.mediaDevices?.enumerateDevices?.()) ?? []).some((d) => d.kind === 'videoinput');
    } catch {
      return false;
    }
  }

  /**
   * From the gateway (F_CAM): [1, u16 width, u16 height, u8 pictures a second, u8 its number] starts, [0] stops. Each
   * picture says the start it belongs to: one of a start before (another size) is left out there.
   */
  onMessage(d) {
    if (d[0] === 1 && d.length >= 7) {
      const v = new DataView(d.buffer, d.byteOffset, 7);
      this.#start(v.getUint16(1, true), v.getUint16(3, true), d[5], d[6]);
    } else this.stop();
  }

  async #start(w, h, fps, no) {
    this.stop();
    const run = this.run;
    if (!(w >= 16 && w <= 4096 && h >= 16 && h <= 4096)) return;
    fps = Math.max(1, Math.min(30, fps || 15));
    if (!navigator.mediaDevices?.getUserMedia) {
      this.#fail(no, window.isSecureContext ? 'cam_unsupported' : 'cam_https');
      return;
    }
    let stream = null;
    try {
      stream = await navigator.mediaDevices.getUserMedia({ audio: false, video: { width: { ideal: w }, height: { ideal: h }, frameRate: { ideal: fps } } });
      if (run !== this.run) throw new Error('stopped');
      const video = document.createElement('video');
      Object.assign(video, { muted: true, playsInline: true, srcObject: stream });
      await video.play();
      if (run !== this.run) throw new Error('stopped');
      const canvas = document.createElement('canvas');
      Object.assign(canvas, { width: w, height: h });
      const ctx = canvas.getContext('2d');
      let busy = false;
      const tick = () => {
        if (busy || run !== this.run || video.readyState < 2 || !video.videoWidth) return;
        busy = true;                                     // (one picture made at a time: a slow computer sends fewer)
        // the camera's picture filling the size chosen, its middle cut to those proportions, as a camera of that size
        const vw = video.videoWidth, vh = video.videoHeight, s = Math.max(w / vw, h / vh), sw = w / s, sh = h / s;
        ctx.drawImage(video, (vw - sw) / 2, (vh - sh) / 2, sw, sh, 0, 0, w, h);
        canvas.toBlob((b) => {
          if (!b || run !== this.run) { busy = false; return; }
          b.arrayBuffer().then((a) => { busy = false; if (run === this.run) this.send(new Uint8Array(a), no); }, () => { busy = false; });
        }, 'image/jpeg', QUALITY);
      };
      const live = this.live = { stream, video, timer: 0, worker: null };
      const own = () => { live.worker?.terminate(); live.worker = null; live.timer = setInterval(tick, 1000 / fps); };
      try {
        live.worker = new Worker(new URL('./tick-worker.js', import.meta.url));
        live.worker.onmessage = tick;
        live.worker.onerror = own;                       // (no worker: the page's own timer)
        live.worker.postMessage(1000 / fps);
      } catch {
        own();
      }
    } catch (e) {
      stream?.getTracks().forEach((t) => t.stop());
      if (run === this.run) this.#fail(no, e?.name === 'NotAllowedError' ? 'cam_denied' : e?.name === 'NotFoundError' ? 'cam_none'
        : e?.name === 'NotReadableError' ? 'cam_busy' : 'cam_failed');
    }
  }

  /** Lets go of the camera (the remote program stopped using it, or the session ended). */
  stop() {
    this.run++;
    if (!this.live) return;
    clearInterval(this.live.timer);
    this.live.worker?.terminate();
    this.live.stream.getTracks().forEach((t) => t.stop());
    this.live.video.srcObject = null;
    this.live = null;
  }

  #fail(no, key) {
    this.fail(no);                                       // the program there is told (no picture will come), not left waiting
    this.onError(key);
  }
}
