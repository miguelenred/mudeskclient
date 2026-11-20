// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// KeyboardEvent.code -> PC/AT set 1 scancode. Values >= 0x100 carry the extended flag.
'use strict';

const E = 0x100;
export const SCANCODES = {
  Escape: 0x01, Digit1: 0x02, Digit2: 0x03, Digit3: 0x04, Digit4: 0x05, Digit5: 0x06, Digit6: 0x07, Digit7: 0x08,
  Digit8: 0x09, Digit9: 0x0a, Digit0: 0x0b, Minus: 0x0c, Equal: 0x0d, Backspace: 0x0e, Tab: 0x0f,
  KeyQ: 0x10, KeyW: 0x11, KeyE: 0x12, KeyR: 0x13, KeyT: 0x14, KeyY: 0x15, KeyU: 0x16, KeyI: 0x17, KeyO: 0x18, KeyP: 0x19,
  BracketLeft: 0x1a, BracketRight: 0x1b, Enter: 0x1c, ControlLeft: 0x1d,
  KeyA: 0x1e, KeyS: 0x1f, KeyD: 0x20, KeyF: 0x21, KeyG: 0x22, KeyH: 0x23, KeyJ: 0x24, KeyK: 0x25, KeyL: 0x26,
  Semicolon: 0x27, Quote: 0x28, Backquote: 0x29, ShiftLeft: 0x2a, Backslash: 0x2b,
  KeyZ: 0x2c, KeyX: 0x2d, KeyC: 0x2e, KeyV: 0x2f, KeyB: 0x30, KeyN: 0x31, KeyM: 0x32,
  Comma: 0x33, Period: 0x34, Slash: 0x35, ShiftRight: 0x36, NumpadMultiply: 0x37, AltLeft: 0x38, Space: 0x39,
  CapsLock: 0x3a, F1: 0x3b, F2: 0x3c, F3: 0x3d, F4: 0x3e, F5: 0x3f, F6: 0x40, F7: 0x41, F8: 0x42, F9: 0x43, F10: 0x44,
  NumLock: 0x45, ScrollLock: 0x46, Numpad7: 0x47, Numpad8: 0x48, Numpad9: 0x49, NumpadSubtract: 0x4a,
  Numpad4: 0x4b, Numpad5: 0x4c, Numpad6: 0x4d, NumpadAdd: 0x4e, Numpad1: 0x4f, Numpad2: 0x50, Numpad3: 0x51,
  Numpad0: 0x52, NumpadDecimal: 0x53, IntlBackslash: 0x56, F11: 0x57, F12: 0x58, IntlRo: 0x73, IntlYen: 0x7d,
  NumpadEnter: E | 0x1c, ControlRight: E | 0x1d, NumpadDivide: E | 0x35, PrintScreen: E | 0x37, AltRight: E | 0x38,
  Home: E | 0x47, ArrowUp: E | 0x48, PageUp: E | 0x49, ArrowLeft: E | 0x4b, ArrowRight: E | 0x4d, End: E | 0x4f,
  ArrowDown: E | 0x50, PageDown: E | 0x51, Insert: E | 0x52, Delete: E | 0x53,
  MetaLeft: E | 0x5b, MetaRight: E | 0x5c, OSLeft: E | 0x5b, OSRight: E | 0x5c, ContextMenu: E | 0x5d,
};

/**
 * Windows 11 apps (Notepad, search box, terminal) mix up or drop text that arrives close together ("ñá" typed at once becomes
 * "áá"): the gateway types text one character every TEXT_GAP ms, keeping the order with the input that comes after it. With
 * 100 ms WordPad gets every character; the new Notepad still drops or repeats one now and then while its spell checker is
 * busy, also with 150 ms. Keys of the keyboard layout instead of Unicode were tried: they lost capitals and spaces at 50 ms
 * and depend on the remote layout.
 */
export const TEXT_GAP = 100;

/** Typed text for the gateway as [pause ms, event count, byte length, Unicode events] per character. */
export function typedText(text) {
  const out = [];
  for (const ch of text) {
    const cp = ch.codePointAt(0);
    const units = cp > 0xffff ? [0xd800 + ((cp - 0x10000) >> 10), 0xdc00 + ((cp - 0x10000) & 0x3ff)] : [cp];
    out.push(TEXT_GAP, units.length * 2, units.length * 6, ...units.flatMap((u) => [...ev.unicode(u, false), ...ev.unicode(u, true)]));
  }
  return new Uint8Array(out);
}

// fast-path input event builders (MS-RDPBCGR 2.2.8.1.2.2)
export const ev = {
  key: (sc, up) => new Uint8Array([(sc & E ? 0x02 : 0) | (up ? 0x01 : 0), sc & 0xff]),
  pause: (up) => [new Uint8Array([0x04 | (up ? 1 : 0), 0x1d]), new Uint8Array([up ? 1 : 0, 0x45])], // E1 1D 45
  unicode: (cp, up) => new Uint8Array([0x80 | (up ? 1 : 0), cp & 0xff, cp >> 8]),
  sync: (flags) => new Uint8Array([0x60 | flags]),
  mouse: (flags, x, y) => new Uint8Array([0x20, flags & 0xff, flags >> 8, x & 0xff, x >> 8, y & 0xff, y >> 8]),
  mouseX: (flags, x, y) => new Uint8Array([0x40, flags & 0xff, flags >> 8, x & 0xff, x >> 8, y & 0xff, y >> 8]),
};
