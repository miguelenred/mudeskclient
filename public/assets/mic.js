// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// This computer's microphone for the remote computer (a connection option, off by default): recorded only while a program
// there records, as the gateway says (and in which format), in 16-bit PCM.
'use strict';

const SEND_MS = 100;                                     // sent in pieces of about this long: fewer requests, a little more delay

export class MicCapture {
  /** o: {send(Uint8Array pcm, maxBytes, frameBytes), onError(i18n key), onLive(on): the microphone taken or let go} */
  constructor(o) {
    Object.assign(this, o);
    this.run = 0;                                        // each start; an older one still starting gives up
    this.parts = [];
    this.have = 0;
    this.live = null;
  }

  /** From the gateway (F_MIC): [1, u32 rate, u8 channels] records in that format (again, if it changed), [0] stops. */
  onMessage(d) {
    if (d[0] === 1 && d.length >= 6) this.#start(new DataView(d.buffer, d.byteOffset, 6).getUint32(1, true), d[5] === 2 ? 2 : 1);
    else this.stop();
  }

  async #start(rate, ch) {
    this.stop();
    const run = this.run;
    if (!(rate >= 8000 && rate <= 48000)) return;
    if (!navigator.mediaDevices?.getUserMedia || typeof AudioWorkletNode === 'undefined') {
      this.#fail(window.isSecureContext ? 'mic_unsupported' : 'mic_https');
      return;
    }
    let stream = null, ctx = null;
    try {
      stream = await navigator.mediaDevices.getUserMedia({ audio: { channelCount: 1, echoCancellation: true, noiseSuppression: true, autoGainControl: true } });
      if (run !== this.run) throw new Error('stopped');
      ctx = new AudioContext({ latencyHint: 'interactive' });
      await ctx.audioWorklet.addModule(new URL('mic-worklet.js', import.meta.url));
      if (run !== this.run) throw new Error('stopped');
      const node = new AudioWorkletNode(ctx, 'mudesk-mic', { numberOfInputs: 1, numberOfOutputs: 1, processorOptions: { rate, channels: ch } });
      const frame = ch * 2, piece = Math.round((rate * SEND_MS) / 1000) * frame;
      node.port.onmessage = (e) => {
        if (run !== this.run) return;
        this.parts.push(new Uint8Array(e.data));
        this.have += e.data.byteLength;
        if (this.have < piece) return;
        const all = new Uint8Array(this.have);
        let at = 0;
        for (const p of this.parts) { all.set(p, at); at += p.length; }
        this.parts = [];
        this.have = 0;
        this.send(all, rate * frame, frame);             // at most a second waits to be sent
      };
      ctx.createMediaStreamSource(stream).connect(node);
      node.connect(ctx.destination);                     // (silent: kept running by the page's audio output)
      this.live = { stream, ctx };
      this.onLive?.(true);
      this.unlock();
    } catch (e) {
      stream?.getTracks().forEach((t) => t.stop());
      ctx?.close().catch(() => {});
      if (run === this.run) this.#fail(e?.name === 'NotAllowedError' ? 'mic_denied' : e?.name === 'NotFoundError' ? 'mic_none' : 'mic_failed');
    }
  }

  /** The browser may keep the audio paused until the page is touched or a key pressed (called then, as for the sound). */
  unlock() {
    if (this.live?.ctx.state === 'suspended') this.live.ctx.resume().catch(() => {});
  }

  /** Lets go of the microphone (the remote program stopped recording, or the session ended). */
  stop() {
    this.run++;
    this.parts = [];
    this.have = 0;
    if (!this.live) return;
    this.live.stream.getTracks().forEach((t) => t.stop());
    this.live.ctx.close().catch(() => {});
    this.live = null;
    this.onLive?.(false);
  }

  #fail(key) {
    if (this.said === key) return;                       // said once per session, not each time a program there records
    this.said = key;
    this.onError?.(key);
  }
}
