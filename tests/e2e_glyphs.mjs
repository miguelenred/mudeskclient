// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// Glyph drawing in Chrome: text orders parsed by orders.js and drawn with its glyphPath/drawText, compared pixel by pixel
// with the glyph bitmaps placed by hand. node tests/e2e_glyphs.mjs <baseUrl of a running copy>
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PLAYWRIGHT || 'playwright-core');
const [base] = process.argv.slice(2);

const browser = await chromium.launch({ executablePath: process.env.CHROME || '/usr/bin/google-chrome', args: ['--no-sandbox'] });
const page = await browser.newPage();
await page.goto(base + '/index.php');
const result = await page.evaluate(async () => {
  const { OrderParser, glyphPath, drawText } = await import('./assets/orders.js');
  const i16 = (v) => [v & 255, (v >> 8) & 255];
  // glyph 5: 5x3 "frame with a hole", glyph 7: 2x2 block; text in red over an opaque blue rectangle, then fixed pitch text
  const glyph = [0x03, 0, 0, ...i16(1 | (2 << 8)), 3, 5, 0x00, 0x43, 5, 3, 0xf8, 0x88, 0xf8, 0,   // cache 1, 2 glyphs; rows padded to 4 bytes
    7, 0x01, 0x00, 2, 2, 0xc0, 0xc0, 0, 0];
  glyph.splice(1, 2, ...i16(glyph.length - 6 - 7));       // orderLength = body length - 7
  const gi = [0x09, 0x1b, 0xff, 0x3f, 0x38, 1, 0x02, 0, 0, 0xff, 0x00, 0x00, 0x00, 0x00, 0xff,     // back = red (R,G,B), fore = blue
    ...i16(10), ...i16(10), ...i16(40), ...i16(20), ...i16(8), ...i16(8), ...i16(60), ...i16(22), ...i16(10), ...i16(15), 4, 5, 0, 7, 9];
  const fi = [0x09, 0x13, 0xff, 0x7f, 1, 6, 0x02, 0x00, 0xff, 0x00, 0, 0, 0, ...i16(10), ...i16(40), ...i16(70), ...i16(50),
    ...i16(10), ...i16(40), ...i16(10), ...i16(40), ...i16(20), ...i16(45), 3, 5, 7, 5];               // green, no opaque rect (op = bk, empty)
  const bytes = new Uint8Array([3, 0, ...glyph, ...gi, ...fi]);
  const canvas = document.createElement('canvas');
  canvas.width = 80;
  canvas.height = 60;
  const ctx = canvas.getContext('2d', { alpha: false });
  ctx.fillStyle = '#fff';
  ctx.fillRect(0, 0, 80, 60);
  const paths = new Map(), defs = new Map();
  new OrderParser().parse(bytes, 32, (o) => {
    if (o.op === 'glyph') { paths.set(o.key, glyphPath(o)); defs.set(o.key, o); }
    else if (o.op === 'fill') { ctx.fillStyle = `rgb(${o.color & 255},${(o.color >> 8) & 255},${(o.color >> 16) & 255})`; ctx.fillRect(o.x, o.y, o.w, o.h); }
    else if (o.op === 'text') drawText(ctx, o, paths);
  });
  // expected: white, the opaque rectangle 8,8-60,22 in blue, then each glyph's set bits in its colour inside its clip box
  const want = new Uint32Array(80 * 60).fill(0xffffffff);
  const put = (x, y, c) => { if (x >= 0 && y >= 0 && x < 80 && y < 60) want[y * 80 + x] = c; };
  for (let y = 8; y <= 22; y++) for (let x = 8; x <= 60; x++) put(x, y, 0xffff0000);
  const stamp = (key, ox, oy, c, box) => {
    const g = defs.get(key), stride = (g.w + 7) >> 3;
    for (let y = 0; y < g.h; y++) for (let x = 0; x < g.w; x++) {
      const px = ox + x, py = oy + y;
      if (((g.bits[y * stride + (x >> 3)] << (x & 7)) & 0x80) && px >= box[0] && px <= box[2] && py >= box[1] && py <= box[3]) put(px, py, c);
    }
  };
  stamp(261, 10, 12, 0xff0000ff, [10, 10, 40, 20]);           // glyph 5 at x 10 (offset 0,-3), red
  stamp(263, 10 + 9 + 1, 15, 0xff0000ff, [10, 10, 40, 20]);   // glyph 7, delta 9 then offset 1,0
  stamp(261, 20, 42, 0xff00ff00, [10, 40, 70, 50]);           // fixed pitch 6: glyph 5, 7, 5 in green
  stamp(263, 27, 45, 0xff00ff00, [10, 40, 70, 50]);
  stamp(261, 32, 42, 0xff00ff00, [10, 40, 70, 50]);
  const got = new Uint32Array(ctx.getImageData(0, 0, 80, 60).data.buffer);
  let diff = 0;
  for (let i = 0; i < got.length; i++) diff += got[i] !== want[i];
  return { diff, glyphs: paths.size, red: got.filter((v) => v === 0xff0000ff).length, green: got.filter((v) => v === 0xff00ff00).length };
});
await browser.close();
const ok = result.diff === 0 && result.glyphs === 2 && result.red > 0 && result.green > 0;
console.log((ok ? 'OK   ' : 'FAIL ') + 'glyph text drawn pixel-exact in Chrome ' + JSON.stringify(result));
process.exit(ok ? 0 : 1);
