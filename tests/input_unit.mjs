// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// Input unit tests: node tests/input_unit.mjs
import { isTextCursor } from '../public/assets/gfx.js';
import { TEXT_GAP, typedText } from '../public/assets/keymap.js';

let failed = 0;
const check = (name, ok, info = '') => { console.log((ok ? 'OK   ' : 'FAIL ') + name + (info ? ' ' + info : '')); if (!ok) failed++; };

// typed text goes to the gateway as one step per character [pause, count, length, events], which sends them TEXT_GAP ms apart
// (Windows 11 apps mix up characters that arrive together: "ñá" typed at once became "áá")
const steps = (u8) => { const out = []; for (let o = 0; o < u8.length; o += 3 + u8[o + 2]) out.push([u8[o], u8[o + 1], [...u8.subarray(o + 3, o + 3 + u8[o + 2])]]); return out; };
check('typed text: one step per character, each with the pause', JSON.stringify(steps(typedText('ñá'))) === JSON.stringify([
  [TEXT_GAP, 2, [0x80, 0xf1, 0x00, 0x81, 0xf1, 0x00]], [TEXT_GAP, 2, [0x80, 0xe1, 0x00, 0x81, 0xe1, 0x00]]]));
check('typed text: pause of 100 ms or more', TEXT_GAP >= 100);
check('typed text: emoji as a surrogate pair in one step', JSON.stringify(steps(typedText('😀'))) === JSON.stringify([[TEXT_GAP, 4,
  [0x80, 0x3d, 0xd8, 0x81, 0x3d, 0xd8, 0x80, 0x00, 0xde, 0x81, 0x00, 0xde]]]));

// text cursor (opens the phone keyboard after a tap): shapes as Windows 11 sent them ('#' black, 'o' white, '.' transparent)
const shape = (rows) => {
  const w = 32, h = 32, rgba = new Uint32Array(w * h);
  rows.forEach((r, y) => [...r].forEach((c, x) => { if (c !== '.') rgba[y * w + x] = c === 'o' ? 0xffffffff : 0xff000000; }));
  return { w, h, rgba };
};
const ibeam = shape(['', '', '.......###.###', ...Array(14).fill('..........#'), '.......###.###']);
const vresize = shape(['...###', '..##o##', '.##ooo##', '##ooooo##', '#ooooooo#', '####o####', ...Array(11).fill('...#o#'),
  '####o####', '#ooooooo#', '##ooooo##', '.##ooo##', '..##o##', '...###']);
const arrow = shape(['##', '###', '#o##', '#oo##', '#ooo##', '#oooo##', '#ooooo##', '#oooooo##', '#ooooooo##', '#oooooooo##',
  '#ooooooooo##', '#oooooooooo#', '#oooooo#####', '#ooo#oo##', '#oo###oo#', '#o##.#oo##', '###..##oo#', '......#oo#', '.......###']);
const up = shape(['....#', '...###', '..#####', '.#######', '#########', ...Array(12).fill('...###')]);
const ibeam2x = shape(['', ...Array(2).fill('....##########'), ...Array(24).fill('........##'), ...Array(2).fill('....##########')]);
check('text cursor: I-beam yes (also a larger one); vertical resize, arrow and up arrow no',
  JSON.stringify([ibeam, ibeam2x, vresize, arrow, up].map(isTextCursor)) === '[true,true,false,false,false]');

console.log(failed ? `\n${failed} failed` : '\nAll tests passed');
process.exit(failed ? 1 : 0);
