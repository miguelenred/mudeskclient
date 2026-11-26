// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// Software screen for tests: feeds the frames rdp.php sends to the browser through the browser decoders and keeps the desktop
// in memory (same semantics as session.js / gfxsurfaces.js, except H.264, which needs WebCodecs). Used by gfx_render.mjs and bench.mjs.
import { bitmapUpdate, paletteUpdate, pointerDecode } from '../public/assets/gfx.js';
import { OrderParser, rop3 } from '../public/assets/orders.js';
import { GfxPipeline } from '../public/assets/rdpgfx.js';
import { CompressedStream } from '../public/assets/bulk.js';

export class Screen {
  /** onFrame(frameId): end of a graphics pipeline frame (the caller acknowledges it). */
  constructor(onFrame = () => {}) {
    Object.assign(this, { W: 0, H: 0, fb: null, bpp: 16, rects: 0, ops: 0, gfxOps: 0, video: 0, bad: 0, pointers: 0 });
    this.onFrame = onFrame;
    this.parser = new OrderParser();
    this.bcache = new Map();
    this.palette = new Uint32Array(256);
    this.surfaces = new Map();
    this.cache = new Map();
    this.glyphs = new Map();
    this.bulk = new CompressedStream();
    this.gfx = new GfxPipeline((o) => this.#gfxOp(o));
    this.gfx.stats = {};                           // message and codec counts (as in diagnostic mode)
    this.notes = [];
  }

  /** One frame of the stream: [type, payload]. */
  feed(type, data) {
    if (type === 10 || type === 11) { data = type === 10 ? this.bulk.update(data) : this.bulk.slow(data); type = data ? 1 : 0; }   // bulk compression
    if (type === 2) {                              // a Demand Active: the orders' history starts anew (as stream-worker.js)
      this.W = data[0] | data[1] << 8; this.H = data[2] | data[3] << 8; this.bpp = data[4];
      if (!this.fb || this.fb.length !== this.W * this.H) this.fb = new Uint32Array(this.W * this.H);
      this.parser.resetState();
    }
    if (type === 9) this.gfx.onData(data.slice());
    if (type !== 1) return;
    const code = data[0], body = data.subarray(1), { W, H } = this, max = ((W + 63) & ~63) * ((H + 63) & ~63);   // as stream-worker.js
    if (code === 1) bitmapUpdate(body, this.palette, (x, y, w, h, rgba, cw, ch) => {
      this.rects++;
      for (let j = 0; j < ch && y + j < H; j++) for (let i = 0; i < cw && x + i < W; i++) this.fb[(y + j) * W + x + i] = rgba[j * w + i];
    }, max);
    else if (code === 0) { if (!this.parser.parse(body, this.bpp, (o) => this.#order(o), this.palette, max)) this.bad++; }
    else if (code === 2) paletteUpdate(body, this.palette);
    else if (code === 9 || code === 11 || code === 12) { pointerDecode(body, code); this.pointers++; }
  }

  /** The desktop as a binary PPM image. */
  ppm() {
    const { W, H, fb } = this, out = Buffer.alloc(W * H * 3);
    for (let i = 0; i < W * H; i++) { out[i * 3] = fb[i] & 255; out[i * 3 + 1] = (fb[i] >> 8) & 255; out[i * 3 + 2] = (fb[i] >> 16) & 255; }
    return Buffer.concat([Buffer.from(`P6\n${W} ${H}\n255\n`), out]);
  }

  // software execution of drawing orders (same semantics as the canvas code)
  #order(o) {
    const { W, H, fb } = this;
    this.ops++;
    if (o.op === 'cache') { this.bcache.set(o.id * 65536 + o.idx, o); return; }
    if (o.op === 'glyph') { (this.glyphBits ||= new Map()).set(o.key, o); return; }   // (as session.js's glyph cache)
    if (o.op === 'text') {                               // each glyph's set bits in the colour, within the text's box (drawText)
      const r = Math.min(W, o.x + o.w), b = Math.min(H, o.y + o.h);
      for (let i = 0; i < o.glyphs.length; i += 3) {
        const g = this.glyphBits?.get(o.glyphs[i]), gx = o.glyphs[i + 1], gy = o.glyphs[i + 2];
        if (!g) continue;
        const stride = (g.w + 7) >> 3;
        for (let y = 0; y < g.h; y++) for (let x = 0; x < g.w; x++) {
          const px = gx + x, py = gy + y;
          if (px >= Math.max(0, o.x) && px < r && py >= Math.max(0, o.y) && py < b && g.bits[y * stride + (x >> 3)] & (0x80 >> (x & 7))) fb[py * W + px] = o.color;
        }
      }
      return;
    }
    let x0 = Math.max(0, o.x), y0 = Math.max(0, o.y), x1 = Math.min(W, o.x + o.w), y1 = Math.min(H, o.y + o.h);
    if (o.clip) { x0 = Math.max(x0, o.clip.l); y0 = Math.max(y0, o.clip.t); x1 = Math.min(x1, o.clip.r + 1); y1 = Math.min(y1, o.clip.b + 1); }
    if (x1 <= x0 || y1 <= y0) return;
    if (o.op === 'fill') for (let y = y0; y < y1; y++) fb.fill(o.color, y * W + x0, y * W + x1);
    else if (o.op === 'mem' && o.rop === 0xcc) {
      const b = this.bcache.get(o.id * 65536 + o.idx);
      if (b) for (let y = y0; y < y1; y++) for (let x = x0; x < x1; x++) fb[y * W + x] = b.px[(y - o.y + o.sy) * b.w + (x - o.x + o.sx)];
    }
    else if (o.op === 'mem' || o.op === 'pattern') {     // other ROPs and the brushes: the browser's pixel code (rop3)
      const b = o.op === 'mem' && this.bcache.get(o.id * 65536 + o.idx);
      if (b) { x0 = Math.max(x0, o.x - o.sx); y0 = Math.max(y0, o.y - o.sy); x1 = Math.min(x1, o.x - o.sx + b.w); y1 = Math.min(y1, o.y - o.sy + b.h); }
      const w = x1 - x0, h = y1 - y0;
      if ((o.op === 'mem' && !b) || w <= 0 || h <= 0) return;
      const dst = new Uint32Array(w * h), src = b && new Uint32Array(w * h);
      for (let j = 0; j < h; j++) {
        dst.set(fb.subarray((y0 + j) * W + x0, (y0 + j) * W + x1), j * w);
        if (b) { const s = (y0 + j - o.y + o.sy) * b.w + x0 - o.x + o.sx; src.set(b.px.subarray(s, s + w), j * w); }
      }
      rop3(o, dst, src, x0, y0, w, h);
      for (let j = 0; j < h; j++) fb.set(dst.subarray(j * w, (j + 1) * w), (y0 + j) * W + x0);
    }
    else if (o.op === 'invert') for (let y = y0; y < y1; y++) for (let x = x0; x < x1; x++) fb[y * W + x] = (~fb[y * W + x] | 0xff000000) >>> 0;
    else {
      const tmp = new Uint32Array((x1 - x0) * (y1 - y0));
      for (let y = y0; y < y1; y++) for (let x = x0; x < x1; x++) tmp[(y - y0) * (x1 - x0) + x - x0] = fb[(y - o.y + o.sy) * W + (x - o.x + o.sx)];
      for (let y = y0; y < y1; y++) fb.set(tmp.subarray((y - y0) * (x1 - x0), (y - y0 + 1) * (x1 - x0)), y * W + x0);
    }
  }

  // graphics pipeline surfaces (same semantics as public/assets/gfxsurfaces.js; pixels with alpha 0 are transparent when blending)
  #gfxOp(o) {
    this.gfxOps++;
    const s = this.surfaces.get(o.id);
    const blit = (src, sw, sx, sy, w, h, dst, dw, dx, dy, blend) => {   // rectangle copy, clipped to both buffers
      w = Math.min(w, sw - sx, dw - dx);
      h = Math.min(h, src.length / sw - sy, dst.length / dw - dy);
      if (w <= 0 || h <= 0) return;
      if (src === dst) { const t = new Uint32Array(w * h); blit(src, sw, sx, sy, w, h, t, w, 0, 0); src = t; sw = w; sx = sy = 0; }
      for (let j = 0; j < h; j++) {
        const s = (sy + j) * sw + sx, d = (dy + j) * dw + dx;
        if (!blend) dst.set(src.subarray(s, s + w), d);
        else for (let i = 0; i < w; i++) { const v = src[s + i]; if (v >>> 24) dst[d + i] = v; }
      }
    };
    const show = (t, x, y, w, h) => { if (t?.mapped) blit(t.px, t.w, x, y, w, h, this.fb, this.W, t.ox + x, t.oy + y); };
    switch (o.op) {
      case 'reset': this.W = o.w; this.H = o.h; this.fb = new Uint32Array(o.w * o.h); for (const t of this.surfaces.values()) t.mapped = false; break;
      case 'create': this.surfaces.set(o.id, { w: o.w, h: o.h, px: new Uint32Array(o.w * o.h), mapped: false }); break;
      case 'delete': this.surfaces.delete(o.id); break;
      case 'map': if (s) { Object.assign(s, { mapped: true, ox: o.x, oy: o.y }); show(s, 0, 0, s.w, s.h); } break;
      case 'px':
        if (!s) break;
        blit(o.px, o.w, 0, 0, o.w, o.h, s.px, s.w, o.x, o.y, o.blend);
        if (o.glyph >= 0) { const g = new Uint32Array(o.w * o.h); blit(s.px, s.w, o.x, o.y, o.w, o.h, g, o.w, 0, 0); this.glyphs.set(o.glyph, g); }
        show(s, o.x, o.y, o.w, o.h);
        break;
      case 'glyph': { const g = this.glyphs.get(o.idx); if (s && g) { blit(g, o.w, 0, 0, o.w, o.h, s.px, s.w, o.x, o.y); show(s, o.x, o.y, o.w, o.h); } break; }
      case 'fill': if (s) for (const r of o.rects) { for (let y = r.t; y < r.b; y++) s.px.fill(o.color, y * s.w + r.l, y * s.w + r.r); show(s, r.l, r.t, r.r - r.l, r.b - r.t); } break;
      case 's2s': {
        const a = this.surfaces.get(o.src), b = this.surfaces.get(o.dst), w = o.rect.r - o.rect.l, h = o.rect.b - o.rect.t;
        if (a && b) for (const [x, y] of o.pts) { blit(a.px, a.w, o.rect.l, o.rect.t, w, h, b.px, b.w, x, y); show(b, x, y, w, h); }
        break;
      }
      case 's2c': if (s) { const w = o.rect.r - o.rect.l, h = o.rect.b - o.rect.t, c = new Uint32Array(w * h); blit(s.px, s.w, o.rect.l, o.rect.t, w, h, c, w, 0, 0); this.cache.set(o.slot, { w, h, px: c }); } break;
      case 'c2s': { const c = this.cache.get(o.slot); if (c && s) for (const [x, y] of o.pts) { blit(c.px, c.w, 0, 0, c.w, c.h, s.px, s.w, x, y); show(s, x, y, c.w, c.h); } break; }
      case 'evict': this.cache.delete(o.slot); break;
      case 'alpha': if (s) for (let j = 0; j < o.h; j++) for (let i = 0; i < o.w; i++) { const k = (o.y + j) * s.w + o.x + i; s.px[k] = ((s.px[k] & 0xffffff) | (o.a[j * o.w + i] << 24)) >>> 0; } break;
      case 'video': this.video++; break;
      case 'end': this.onFrame(o.frame); break;
      case 'note': this.notes.push(o.msg); break;
      case 'log': this.bad++; break;
    }
  }
}
