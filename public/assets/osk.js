// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// On-screen keyboard of the session window, for computers with a mouse (phones and tablets open their own). Each key is
// sent as its scancode, as from a physical keyboard: the keyboard layout of the remote session gives it its character,
// dead keys and AltGr included. Labels for the Spanish and US layouts; with other layouts, the browser's own (Chromium)
// or the US ones.
'use strict';

// [label, label with Shift] of the keys whose character depends on the layout (letters are labelled by their code)
const ES = {
  Backquote: ['º', 'ª'], Digit1: ['1', '!'], Digit2: ['2', '"'], Digit3: ['3', '·'], Digit4: ['4', '$'], Digit5: ['5', '%'], Digit6: ['6', '&'],
  Digit7: ['7', '/'], Digit8: ['8', '('], Digit9: ['9', ')'], Digit0: ['0', '='], Minus: ["'", '?'], Equal: ['¡', '¿'], BracketLeft: ['`', '^'],
  BracketRight: ['+', '*'], Semicolon: ['Ñ', ''], Quote: ['´', '¨'], Backslash: ['Ç', ''], IntlBackslash: ['<', '>'], Comma: [',', ';'],
  Period: ['.', ':'], Slash: ['-', '_'],
};
const US = {
  Backquote: ['`', '~'], Digit1: ['1', '!'], Digit2: ['2', '@'], Digit3: ['3', '#'], Digit4: ['4', '$'], Digit5: ['5', '%'], Digit6: ['6', '^'],
  Digit7: ['7', '&'], Digit8: ['8', '*'], Digit9: ['9', '('], Digit0: ['0', ')'], Minus: ['-', '_'], Equal: ['=', '+'], BracketLeft: ['[', '{'],
  BracketRight: [']', '}'], Semicolon: [';', ':'], Quote: ["'", '"'], Backslash: ['\\', '|'], Comma: [',', '<'], Period: ['.', '>'], Slash: ['/', '?'],
};
const SPANISH = [0x040A, 0x0C0A];
const letters = (s) => [...s].map((c) => 'Key' + c);

/**
 * Rows of keys [code, label, label with Shift, width] for the keyboard layout of the connection; t() gives the names of
 * the special keys. local: the browser's layout map (navigator.keyboard.getLayoutMap()), used for other layouts.
 */
export function keyboardRows(kbd, t, local = null) {
  const es = SPANISH.includes(kbd);
  const labels = es ? ES : US, mine = !es && kbd !== 0x0409 && local;   // other layouts: the browser's labels, when it gives them
  const key = (code, w = 0) => {
    if (mine && local.get(code)) return [code, local.get(code).toUpperCase(), '', w];
    if (code.startsWith('Key')) return [code, code.slice(3), '', w];
    return [code, ...labels[code], w];
  };
  const special = (code, label, w = 0) => [code, label, '', w];
  return [
    [special('Escape', 'Esc'), ...Array.from({ length: 12 }, (_, i) => special('F' + (i + 1), 'F' + (i + 1))), special('Delete', t('key_del'))],
    [...['Backquote', 'Digit1', 'Digit2', 'Digit3', 'Digit4', 'Digit5', 'Digit6', 'Digit7', 'Digit8', 'Digit9', 'Digit0', 'Minus', 'Equal'].map((c) => key(c)),
      special('Backspace', '⌫', 1.6)],
    [special('Tab', '⇥', 1.5), ...letters('QWERTYUIOP').map((c) => key(c)), key('BracketLeft'), key('BracketRight'),
      es ? special('Enter', '⏎', 1.3) : key('Backslash', 1.3)],
    [special('CapsLock', t('key_caps'), 1.8), ...letters('ASDFGHJKL').map((c) => key(c)), key('Semicolon'), key('Quote'),
      es ? key('Backslash') : special('Enter', '⏎', 2)],
    [special('ShiftLeft', '⇧ ' + t('key_shift'), es ? 1.3 : 2.3), ...(es ? [key('IntlBackslash')] : []), ...letters('ZXCVBNM').map((c) => key(c)),
      key('Comma'), key('Period'), key('Slash'), special('ShiftRight', '⇧ ' + t('key_shift'), 2.4)],
    [special('ControlLeft', 'Ctrl', 1.3), special('MetaLeft', 'Win', 1.1), special('AltLeft', 'Alt', 1.1), special('Space', '', 6.5),
      special('AltRight', es ? 'Alt Gr' : 'Alt', 1.1), special('ArrowLeft', '←'), special('ArrowUp', '↑'), special('ArrowDown', '↓'), special('ArrowRight', '→')],
  ];
}
