// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// Renders a frame dump (tests/rdp_probe.php with DUMP=file) to a PPM image: node tests/gfx_render.mjs dump out.ppm
// Graphics pipeline messages (GFX=1) are rendered too, except H.264 (needs a browser).
import { readFileSync, writeFileSync } from 'node:fs';
import { Screen } from './render.mjs';

const buf = readFileSync(process.argv[2]);
const sc = new Screen();
for (let p = 0; p + 5 <= buf.length;) {
  const len = buf.readUInt32LE(p + 1);
  sc.feed(buf[p], new Uint8Array(buf.buffer, buf.byteOffset + p + 5, len));
  p += 5 + len;
}
writeFileSync(process.argv[3], sc.ppm());
console.log(`${sc.W}x${sc.H}, ${sc.rects} rects, ${sc.ops} orders, ${sc.gfxOps} gfx ops, ${sc.bad} unsupported, ${sc.pointers} pointers`);
