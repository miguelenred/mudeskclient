// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// .rdp files, as Remote Desktop Connection (mstsc) and other clients save them: one "name:type:value" setting per line
// (type i: number, s: text, b: binary). Read into a connection of ours, and a connection written as one.
'use strict';

export const RDP_MAX = 65536;                            // a .rdp file is a few KB: anything larger is not one

/** Text of a .rdp file: UTF-16 LE with its BOM (as Remote Desktop Connection saves it), UTF-16 BE, UTF-8 or Windows-1252. */
export function decodeRdp(bytes) {
  const u8 = bytes instanceof Uint8Array ? bytes : new Uint8Array(bytes);
  if (u8[0] === 0xff && u8[1] === 0xfe) return new TextDecoder('utf-16le').decode(u8.subarray(2));
  if (u8[0] === 0xfe && u8[1] === 0xff) return new TextDecoder('utf-16be').decode(u8.subarray(2));
  if (u8[0] === 0xef && u8[1] === 0xbb && u8[2] === 0xbf) return new TextDecoder().decode(u8.subarray(3));
  if (u8.length >= 4 && u8[1] === 0 && u8[3] === 0 && u8[0] !== 0) return new TextDecoder('utf-16le').decode(u8);   // no BOM
  try { return new TextDecoder('utf-8', { fatal: true }).decode(u8); } catch { return new TextDecoder('windows-1252').decode(u8); }
}

/** The settings of a .rdp file: name (lower case) -> number or text. Binary values (an encrypted password) are left out. */
function settings(text) {
  const v = {};
  for (const line of text.split(/\r\n|\r|\n/)) {
    const m = /^\s*([^:]+?)\s*:\s*([is])\s*:(.*)$/i.exec(line);
    if (m) v[m[1].toLowerCase()] = m[2].toLowerCase() === 'i' ? Number.parseInt(m[3].trim(), 10) : m[3].trim();
  }
  return v;
}

/** "host", "host:port", "[ipv6]:port" or a bare IPv6 address -> [host, port or null]. */
function address(a) {
  const b = /^\[([^\]]+)\](?::(\d+))?$/.exec(a);
  if (b) return [b[1], b[2] ? +b[2] : null];
  const m = /^([^:]+):(\d+)$/.exec(a);
  return m ? [m[1], +m[2]] : [a, null];
}

/**
 * A connection of ours from a .rdp file (name: the file's name). Returns { conn, notes }: notes are the settings it has that
 * MuDesk does not take (keys of i18n.js with their value), or throws Error('rdp_no_address').
 */
export function parseRdp(text, name = '') {
  const v = settings(text);
  const [host, port] = address(String(v['full address'] || v['alternate full address'] || '').trim());
  if (!host) throw new Error('rdp_no_address');
  let user = String(v.username || ''), domain = String(v.domain || '');
  if (user.includes('\\')) [domain, user] = [user.slice(0, user.indexOf('\\')), user.slice(user.indexOf('\\') + 1)];
  const off = (k) => v[k] === 0, on = (k) => v[k] === 1;
  const conn = {
    name: name.replace(/\.rdp$/i, '').trim().slice(0, 80) || host,
    group: '', host, port: port || (Number.isInteger(v['server port']) ? v['server port'] : 3389), user, domain,
    kbd: 0x040A, bpp: [15, 16, 24, 32].includes(v['session bpp']) ? v['session bpp'] : 16, gfx: 'auto',
    security: off('enablecredsspsupport') ? 'tls' : 'nla',   // (NLA only, as a new connection: Automatic is chosen by hand)
    resize: on('smart sizing') && !on('dynamic resolution') ? 'fit' : 'dynamic',
    // what the file asks this computer to share there (see the notes below): never turned on by an import
    clipboard: false, paste: 'auto', printer: false, drive: false,
    audio: (v.audiomode ?? 0) === 0, mic: false, ask: true,
    app: on('remoteapplicationmode') ? String(v.remoteapplicationprogram || '') : '',    // RemoteApp: its program alone
    app_args: on('remoteapplicationmode') ? String(v.remoteapplicationcmdline || '') : '',
    perf: { wallpaper: off('disable wallpaper'), themes: off('disable themes'), smoothing: v['allow font smoothing'] !== 0,
      animations: off('disable menu anims'), drag: off('disable full window drag') },
  };
  const notes = [];
  if (on('remoteapplicationmode') && !v.remoteapplicationprogram) notes.push(['rdp_remoteapp', String(v.remoteapplicationname || '')]);
  // what the file asks to share from this computer (its drives there: the shared folder here; the microphone and the camera
  // are one option here): said, and left off, as Windows has done since April 2026 for a file it cannot verify. A file sent
  // by someone else may name his own computer, which would then read the shared folder and the clipboard (CERT-UA and CISA,
  // October 2024). Before, turned on as the file said, without a word
  const asked = [!off('redirectclipboard') && 'clipboard', (!!v.drivestoredirect || on('redirectdrives')) && 'drive',
    (on('audiocapturemode') || !!String(v.camerastoredirect ?? '').trim()) && 'mic', !off('redirectprinters') && 'printer'].filter(Boolean);
  if (asked.length) notes.push(['rdp_asked', asked]);
  // a Remote Desktop Gateway (always used, or detected: 1, 2), said too, and a connection broker's load balance info, taken
  if (v.gatewayhostname && v.gatewayusagemethod !== 0 && v.gatewayusagemethod !== 4) conn.gw = String(v.gatewayhostname).trim().toLowerCase();
  if (conn.gw) notes.push(['rdp_gateway', conn.gw]);
  if (v.loadbalanceinfo) conn.lb = String(v.loadbalanceinfo).trim().slice(0, 512);
  if (v['alternate shell'] && !on('remoteapplicationmode')) notes.push(['rdp_shell', String(v['alternate shell'])]);
  if (/^\s*password 51\s*:\s*b\s*:/im.test(text)) notes.push(['rdp_file_password', '']);
  return { conn, notes };
}

/** A connection of ours as a .rdp file (text; encodeRdp gives its bytes). The password never goes in it. */
export function toRdp(c) {
  const host = c.host.includes(':') ? `[${c.host}]` : c.host;
  const user = c.user && c.domain && !/[\\@]/.test(c.user) ? `${c.domain}\\${c.user}` : c.user || '';
  const p = c.perf || {}, b = (x) => (x ? 1 : 0);
  return [
    `full address:s:${host}${c.port && c.port !== 3389 ? ':' + c.port : ''}`,
    ...(user ? [`username:s:${user}`] : []),
    'screen mode id:i:2', 'use multimon:i:0', `session bpp:i:${c.bpp || 16}`,
    `dynamic resolution:i:${b(c.resize !== 'fit')}`, `smart sizing:i:${b(c.resize === 'fit')}`,
    `audiomode:i:${c.audio ? 0 : 2}`, `audiocapturemode:i:${b(c.mic)}`, `camerastoredirect:s:${c.mic ? '*' : ''}`, `redirectclipboard:i:${b(c.clipboard)}`, `redirectprinters:i:${b(c.printer)}`,
    'drivestoredirect:s:',                                 // the shared folder is MuDesk's own: no drives of the computer
    `enablecredsspsupport:i:${b(c.security !== 'tls')}`, 'authentication level:i:2', 'prompt for credentials:i:0',
    `disable wallpaper:i:${b(!p.wallpaper)}`, `disable themes:i:${b(!p.themes)}`, `allow font smoothing:i:${b(p.smoothing)}`,
    `disable menu anims:i:${b(!p.animations)}`, `disable full window drag:i:${b(!p.drag)}`,
    'compression:i:1', 'autoreconnection enabled:i:1',
    ...(c.app ? ['remoteapplicationmode:i:1', `remoteapplicationprogram:s:${c.app}`, `remoteapplicationname:s:${c.name || c.app}`,
      `remoteapplicationcmdline:s:${c.app_args || ''}`] : []),
    ...(c.gw ? [`gatewayhostname:s:${c.gw}`, 'gatewayusagemethod:i:1', 'gatewaycredentialssource:i:4', 'gatewayprofileusagemethod:i:1', 'promptcredentialonce:i:1'] : []),
    ...(c.lb ? [`loadbalanceinfo:s:${c.lb}`] : []),
  ].map((l) => l.replace(/[\x00-\x1f\x7f]/g, '')).join('\r\n') + '\r\n';   // (a line break in a value made a line of its own)
}

/** The bytes of a .rdp file: UTF-16 LE with its BOM, as Remote Desktop Connection saves it. */
export function encodeRdp(text) {
  const u8 = new Uint8Array(2 + text.length * 2);
  u8[0] = 0xff;
  u8[1] = 0xfe;
  for (let i = 0; i < text.length; i++) {
    const c = text.charCodeAt(i);
    u8[2 + 2 * i] = c & 0xff;
    u8[3 + 2 * i] = c >> 8;
  }
  return u8;
}
