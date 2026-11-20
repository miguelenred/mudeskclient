// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// Microphone capture (an audio worklet): the input at the page's audio rate, mixed to one channel, resampled to the rate the
// remote computer records at and converted to 16-bit PCM (the same sample twice when it asks for two channels). Posted to
// the page every 20 ms.
'use strict';

class MicCapture extends AudioWorkletProcessor {
  constructor(o) {
    super();
    const { rate, channels } = o.processorOptions;
    this.step = sampleRate / rate;                         // input samples per output sample
    this.ch = channels === 2 ? 2 : 1;
    this.t = 0;                                            // where the next output sample is, in this block's samples (-1: the last of the previous one)
    this.prev = 0;
    // fewer samples out than in: a low-pass filter under the new rate's limit first, else what is above it comes back as
    // lower tones (4th-order Butterworth, two biquads, at 45 % of the new rate)
    this.lp = this.step > 1 ? [0.5412, 1.3066].map((q) => {
      const w = (2 * Math.PI * 0.45 * rate) / sampleRate, a = Math.sin(w) / (2 * q), c = Math.cos(w), a0 = 1 + a;
      return { b0: (1 - c) / 2 / a0, b1: (1 - c) / a0, a1: (-2 * c) / a0, a2: (1 - a) / a0, z1: 0, z2: 0 };
    }) : [];
    this.size = Math.max(1, Math.round(rate / 50)) * this.ch;
    this.out = new Int16Array(this.size);
    this.n = 0;
  }

  process(inputs) {
    const inp = inputs[0];
    if (!inp || !inp.length || !inp[0].length) return true;
    const len = inp[0].length, lp = this.lp;
    if (this.x?.length !== len) this.x = new Float32Array(len);   // (one per block was garbage every 3 ms)
    const x = this.x;
    for (let i = 0; i < len; i++) {
      let v = 0;
      for (let c = 0; c < inp.length; c++) v += inp[c][i];
      v /= inp.length;
      for (const f of lp) {                              // (b2 = b0)
        const y = f.b0 * v + f.z1;
        f.z1 = f.b1 * v - f.a1 * y + f.z2;
        f.z2 = f.b0 * v - f.a2 * y;
        v = y;
      }
      x[i] = v;
    }
    let t = this.t;
    while (t <= len - 1) {
      const i = Math.floor(t), f = t - i, a = i < 0 ? this.prev : x[i], b = i + 1 < len ? x[i + 1] : a;
      const v = Math.max(-1, Math.min(1, a + (b - a) * f));
      const s = v < 0 ? Math.round(v * 32768) : Math.round(v * 32767);
      for (let c = 0; c < this.ch; c++) this.out[this.n++] = s;
      if (this.n === this.size) {
        this.port.postMessage(this.out.buffer, [this.out.buffer]);
        this.out = new Int16Array(this.size);
        this.n = 0;
      }
      t += this.step;
    }
    this.t = t - len;
    this.prev = x[len - 1];
    return true;
  }
}

registerProcessor('mudesk-mic', MicCapture);
