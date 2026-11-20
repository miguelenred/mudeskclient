// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// Graphics pipeline, main thread: surfaces, the surface cache and their composition onto the desktop canvas.
'use strict';

/** True when every pixel is opaque: the block can be written without blending. */
const opaque = (px) => { for (let i = 0; i < px.length; i++) if (px[i] < 0xff000000) return false; return true; };

export class GfxSurfaces {
  /** out(): {ctx, canvas} of the desktop; dirty(x, y, w, h) after each change; reset(w, h); ack(frameId, decoded); log(error); note(info). */
  constructor({ out, dirty, reset, ack, log, note }) {
    Object.assign(this, { out, dirty, reset, ack, log, note });
    this.surfaces = new Map();
    this.cache = new Map();
    this.glyphs = new Map();                            // ClearCodec glyph cache: index -> canvas
    this.decoded = 0;
  }

  /** A scratch canvas of at least w x h, cleared there (to copy an area of a surface onto itself). */
  #scratch(w, h) {
    const t = this.tmp;
    if (t && t.c.width >= w && t.c.height >= h) { t.ctx.clearRect(0, 0, w, h); return t; }
    const c = this.#canvas(Math.max(w, t?.c.width || 0), Math.max(h, t?.c.height || 0));
    return (this.tmp = { c, ctx: c.getContext('2d') });
  }

  #canvas(w, h) {
    const c = document.createElement('canvas');
    c.width = w;
    c.height = h;
    return c;
  }

  /** Copies a rectangle of a surface to the desktop if the surface is mapped. */
  #show(s, x, y, w, h) {
    if (!s.mapped || w <= 0 || h <= 0) return;
    const { ctx } = this.out();
    ctx.drawImage(s.c, x, y, w, h, s.ox + x, s.oy + y, w, h);
    this.dirty(s.ox + x, s.oy + y, w, h);
  }

  apply(o) {
    const s = this.surfaces.get(o.id);
    switch (o.op) {
      case 'create': {
        const c = this.#canvas(o.w, o.h);
        this.surfaces.set(o.id, { c, ctx: c.getContext('2d'), w: o.w, h: o.h, mapped: false, ox: 0, oy: 0 });
        break;
      }
      case 'delete': this.surfaces.delete(o.id); break;
      case 'map': if (s) { Object.assign(s, { mapped: true, ox: o.x, oy: o.y }); this.#show(s, 0, 0, s.w, s.h); } break;
      case 'reset': this.reset(o.w, o.h); for (const t of this.surfaces.values()) t.mapped = false; break;
      case 'px': {
        if (!s || !o.w || !o.h) break;                   // an empty rectangle draws nothing (ImageData of 0 threw)
        const img = new ImageData(new Uint8ClampedArray(o.px.buffer), o.w, o.h);
        if (o.parts) {
          // progressive: only the areas its tiles wrote, all opaque, put as they are (blended through a new canvas for
          // each update, the page's thread spent 7 s on an animation that now takes it 2.5 s)
          for (let i = 0; i < o.parts.length; i += 4) s.ctx.putImageData(img, o.x, o.y, o.parts[i], o.parts[i + 1], o.parts[i + 2], o.parts[i + 3]);
        } else if (o.blend && !opaque(o.px)) {           // transparent pixels keep the surface content
          const t = this.#canvas(o.w, o.h);              // a new small canvas: reusing one was 20 times slower (Chrome)
          t.getContext('2d').putImageData(img, 0, 0);
          s.ctx.drawImage(t, o.x, o.y);
        } else s.ctx.putImageData(img, o.x, o.y);        // opaque: 2.6 times faster than blending
        if (o.glyph >= 0) {
          const g = this.#canvas(o.w, o.h);
          g.getContext('2d').drawImage(s.c, o.x, o.y, o.w, o.h, 0, 0, o.w, o.h);
          this.glyphs.set(o.glyph, g);
        }
        this.#show(s, o.x, o.y, o.w, o.h);
        break;
      }
      case 'glyph': {
        const g = this.glyphs.get(o.idx);
        if (s && g) { s.ctx.drawImage(g, 0, 0, o.w, o.h, o.x, o.y, o.w, o.h); this.#show(s, o.x, o.y, o.w, o.h); }
        break;
      }
      case 'video':
        if (o.frame) {
          if (s) {
            for (const r of o.rects) {
              const w = Math.min(r.r, s.w) - r.l, h = Math.min(r.b, s.h) - r.t;
              if (w > 0 && h > 0) { s.ctx.drawImage(o.frame, r.l, r.t, w, h, r.l, r.t, w, h); this.#show(s, r.l, r.t, w, h); }
            }
          }
          o.frame.close();
        }
        break;
      case 'fill':
        if (s) {
          s.ctx.fillStyle = `rgb(${o.color & 255},${(o.color >> 8) & 255},${(o.color >> 16) & 255})`;
          for (const r of o.rects) { s.ctx.fillRect(r.l, r.t, r.r - r.l, r.b - r.t); this.#show(s, r.l, r.t, r.r - r.l, r.b - r.t); }
        }
        break;
      case 's2s': {
        const src = this.surfaces.get(o.src), dst = this.surfaces.get(o.dst);
        if (!src || !dst) break;
        const w = o.rect.r - o.rect.l, h = o.rect.b - o.rect.t;
        if (w <= 0 || h <= 0) break;
        for (const [x, y] of o.pts) {
          if (src === dst) {                             // through a scratch canvas: a canvas drawn onto itself is copied whole first
            const t = this.#scratch(w, h);
            t.ctx.drawImage(src.c, o.rect.l, o.rect.t, w, h, 0, 0, w, h);
            dst.ctx.drawImage(t.c, 0, 0, w, h, x, y, w, h);
          } else dst.ctx.drawImage(src.c, o.rect.l, o.rect.t, w, h, x, y, w, h);
          this.#show(dst, x, y, w, h);
        }
        break;
      }
      case 's2c':
        if (s) {
          const w = o.rect.r - o.rect.l, h = o.rect.b - o.rect.t, c = this.#canvas(w, h);
          c.getContext('2d').drawImage(s.c, o.rect.l, o.rect.t, w, h, 0, 0, w, h);
          this.cache.set(o.slot, c);
        }
        break;
      case 'c2s': {
        const c = this.cache.get(o.slot);
        if (!c || !s) break;
        for (const [x, y] of o.pts) { s.ctx.drawImage(c, x, y); this.#show(s, x, y, c.width, c.height); }
        break;
      }
      case 'evict': this.cache.delete(o.slot); break;
      case 'alpha':
        if (s && o.w && o.h) {
          const img = s.ctx.getImageData(o.x, o.y, o.w, o.h);
          for (let i = 0; i < o.a.length; i++) img.data[i * 4 + 3] = o.a[i];
          s.ctx.putImageData(img, o.x, o.y);
          this.#show(s, o.x, o.y, o.w, o.h);
        }
        break;
      case 'end': this.ack(o.frame, ++this.decoded); break;
      case 'log': this.log(o.msg); break;
      case 'note': this.note(o.msg); break;
    }
  }
}
