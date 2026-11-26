// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// .rdp files (Remote Desktop Connection): read into a connection and written from one: node tests/rdpfile_unit.mjs
import { decodeRdp, encodeRdp, parseRdp, toRdp } from '../public/assets/rdpfile.js';

let failed = 0;
const check = (name, ok, info = '') => { console.log((ok ? 'OK   ' : 'FAIL ') + name + (info ? ' ' + info : '')); if (!ok) failed++; };
const utf16 = (s) => encodeRdp(s);                       // with its BOM, as Remote Desktop Connection saves it

// a file as Remote Desktop Connection (Windows 11) saves it: UTF-16 LE with BOM, CRLF, an encrypted password and a gateway
const mstsc = ['screen mode id:i:2', 'use multimon:i:0', 'desktopwidth:i:1920', 'desktopheight:i:1080', 'session bpp:i:32',
  'winposstr:s:0,3,0,0,800,600', 'compression:i:1', 'keyboardhook:i:2', 'audiocapturemode:i:0', 'videoplaybackmode:i:1',
  'connection type:i:7', 'networkautodetect:i:1', 'bandwidthautodetect:i:1', 'displayconnectionbar:i:1', 'enableworkspacereconnect:i:0',
  'disable wallpaper:i:0', 'allow font smoothing:i:1', 'allow desktop composition:i:0', 'disable full window drag:i:1',
  'disable menu anims:i:1', 'disable themes:i:0', 'disable cursor setting:i:0', 'bitmapcachepersistenable:i:1',
  'full address:s:pc-oficina.ejemplo.local:3390', 'audiomode:i:0', 'redirectprinters:i:0', 'redirectcomports:i:0',
  'redirectsmartcards:i:1', 'redirectwebauthn:i:1', 'redirectclipboard:i:1', 'redirectposdevices:i:0', 'drivestoredirect:s:*',
  'autoreconnection enabled:i:1', 'authentication level:i:2', 'prompt for credentials:i:0', 'negotiate security layer:i:1',
  'remoteapplicationmode:i:0', 'alternate shell:s:', 'shell working directory:s:', 'gatewayhostname:s:rdg.ejemplo.com',
  'gatewayusagemethod:i:1', 'gatewaycredentialssource:i:4', 'gatewayprofileusagemethod:i:1', 'promptcredentialonce:i:0',
  'gatewaybrokeringtype:i:0', 'use redirection server name:i:0', 'rdgiskdcproxy:i:0', 'kdcproxyname:s:',
  'loadbalanceinfo:s:tsv://MS Terminal Services Plugin.1.Escritorios',
  'username:s:EJEMPLO\\maría', 'password 51:b:01000000D08C9DDF0115D1118C7A00C04FC297EB0100000034ABCD'].join('\r\n') + '\r\n';
const a = parseRdp(decodeRdp(utf16(mstsc)), 'Oficina María.rdp');
check('.rdp from Remote Desktop Connection (UTF-16 LE): address and port, DOMAIN\\user, colours, appearance; nothing of this computer shared',
  JSON.stringify(a.conn) === JSON.stringify({ name: 'Oficina María', group: '', host: 'pc-oficina.ejemplo.local', port: 3390, user: 'maría',
    domain: 'EJEMPLO', kbd: 0x040A, bpp: 32, gfx: 'auto', security: 'nla', resize: 'dynamic', clipboard: false, paste: 'auto', printer: false,
    drive: false, audio: true, mic: false, ask: true, app: '', app_args: '', perf: { wallpaper: true, themes: true, smoothing: true, animations: false, drag: false },
    gw: 'rdg.ejemplo.com', lb: 'tsv://MS Terminal Services Plugin.1.Escritorios' }),
  JSON.stringify(a.conn));
check('.rdp: what MuDesk does not take is said (what it asks to share, its gateway, the saved password); the password is never read; the gateway and the broker\'s load balance info taken and written back',
  JSON.stringify(a.notes) === JSON.stringify([['rdp_asked', ['clipboard', 'drive']], ['rdp_gateway', 'rdg.ejemplo.com'], ['rdp_file_password', '']]) && !JSON.stringify(a).includes('D08C9DDF')
    && toRdp(a.conn).includes('gatewayhostname:s:rdg.ejemplo.com\r\ngatewayusagemethod:i:1') && toRdp(a.conn).includes('loadbalanceinfo:s:tsv://MS Terminal Services Plugin.1.Escritorios'),
  JSON.stringify(a.notes));

// other encodings and forms: UTF-8 (with and without BOM), Windows-1252, UTF-16 without BOM; IPv6 in brackets and bare; the
// port as "server port"; credentials as user@domain; RemoteApp and a start program; TLS without NLA; smart sizing
const one = (text) => parseRdp(decodeRdp(text), 'x.rdp').conn;
const latin1 = Uint8Array.from('full address:s:equipo\r\nusername:s:José\r\n', (ch) => ch.charCodeAt(0));
const noBom = encodeRdp('full address:s:sin-bom\r\n').subarray(2);
const forms = [one(new TextEncoder().encode('\ufefffull address:s:[fe80::1]:3391\nusername:s:ana@ejemplo.com\n')),
  one(new TextEncoder().encode('full address:s:2001:db8::5\nserver port:i:3392\nenablecredsspsupport:i:0\nsmart sizing:i:1\nsession bpp:i:8\n')),
  one(latin1), one(noBom)];
check('.rdp: UTF-8, Windows-1252 and UTF-16 without BOM; IPv6 with and without brackets; server port; user@domain; TLS; smart sizing',
  JSON.stringify(forms.map((c) => [c.host, c.port, c.user, c.domain, c.security, c.resize, c.bpp])) === JSON.stringify([
    ['fe80::1', 3391, 'ana@ejemplo.com', '', 'nla', 'dynamic', 16], ['2001:db8::5', 3392, '', '', 'tls', 'fit', 16],
    ['equipo', 3389, 'José', '', 'nla', 'dynamic', 16], ['sin-bom', 3389, '', '', 'nla', 'dynamic', 16]]), JSON.stringify(forms));
const app = parseRdp('full address:s:rds\r\nremoteapplicationmode:i:1\r\nremoteapplicationname:s:Excel\r\nremoteapplicationprogram:s:||excel\r\nremoteapplicationcmdline:s:/r informe.xlsx\r\n', 'a');
const noProgram = parseRdp('full address:s:rds\r\nremoteapplicationmode:i:1\r\nremoteapplicationname:s:Excel\r\n', 'c');
const shell = parseRdp('full address:s:rds\r\nalternate shell:s:C:\\app\\inicio.exe\r\n', 'b');
let missing = '';
try { parseRdp('screen mode id:i:2\r\nusername:s:x\r\n'); } catch (e) { missing = e.message; }
check('.rdp: a RemoteApp taken with its program and arguments (said when it has none); a start program said; a file without address refused',
  app.conn.app === '||excel' && app.conn.app_args === '/r informe.xlsx' && JSON.stringify(app.notes) === JSON.stringify([['rdp_asked', ['clipboard', 'printer']]])
  && noProgram.conn.app === '' && JSON.stringify([...noProgram.notes, ...shell.notes].filter(([k]) => k !== 'rdp_asked')) === JSON.stringify([['rdp_remoteapp', 'Excel'], ['rdp_shell', 'C:\\app\\inicio.exe']]) && missing === 'rdp_no_address',
  JSON.stringify({ app: app.conn.app, args: app.conn.app_args, notes: [app.notes, noProgram.notes, shell.notes], missing }));
const out = decodeRdp(encodeRdp(toRdp({ ...app.conn, name: 'Excel remoto' })));
check('.rdp written: a RemoteApp with its program, name and arguments, read back the same',
  out.includes('\r\nremoteapplicationmode:i:1\r\nremoteapplicationprogram:s:||excel\r\nremoteapplicationname:s:Excel remoto\r\nremoteapplicationcmdline:s:/r informe.xlsx\r\n')
  && parseRdp(out, 'x').conn.app === '||excel' && !toRdp({ ...app.conn, app: '' }).includes('remoteapplication'), out.slice(-160));

// written: UTF-16 LE with BOM and CRLF, read back as the same connection (Automatic security, CredSSP on in the file, read
// back as NLA only, as every imported connection); IPv6 in brackets; the user with its domain; no
// password and no drives of the computer (the shared folder is MuDesk's own)
const conns = [
  { name: 'PC', host: 'pc.local', port: 3389, user: 'ana', domain: 'CASA', bpp: 24, security: 'tls', resize: 'fit', clipboard: false, printer: true,
    drive: true, audio: false, mic: true, perf: { wallpaper: true, themes: false, smoothing: true, animations: true, drag: false } },
  { name: 'v6', host: 'fe80::2', port: 3390, user: 'luis@ejemplo.com', domain: 'X', bpp: 32, security: 'auto', resize: 'dynamic', clipboard: true,
    printer: false, drive: false, audio: true, perf: {} }];
const written = conns.map((c) => encodeRdp(toRdp(c)));
const back = written.map((b, i) => parseRdp(decodeRdp(b), conns[i].name).conn);
const text0 = decodeRdp(written[0]);
check('.rdp written: UTF-16 LE with BOM and CRLF, read back the same (drives never; the microphone too), the user with its domain',
  written.every((b) => b[0] === 0xff && b[1] === 0xfe) && text0.includes('\r\nusername:s:CASA\\ana\r\n') && text0.includes('drivestoredirect:s:\r\n')
  && !/password/i.test(text0) && decodeRdp(written[1]).startsWith('full address:s:[fe80::2]:3390\r\n')
  && JSON.stringify(back.map((c) => [c.host, c.port, c.user, c.domain, c.bpp, c.security, c.resize, c.clipboard, c.printer, c.drive, c.audio, c.mic, c.perf]))
    === JSON.stringify([['pc.local', 3389, 'ana', 'CASA', 24, 'tls', 'fit', false, false, false, false, false, { wallpaper: true, themes: false, smoothing: true, animations: true, drag: false }],
      ['fe80::2', 3390, 'luis@ejemplo.com', '', 32, 'nla', 'dynamic', false, false, false, true, false, { wallpaper: false, themes: false, smoothing: false, animations: false, drag: false }]])
  && JSON.stringify(written.map((b, i) => parseRdp(decodeRdp(b), conns[i].name).notes)) === JSON.stringify([[['rdp_asked', ['mic', 'printer']]], [['rdp_asked', ['clipboard']]]]),
  JSON.stringify(back));

// the camera: one box with the microphone, taken from either (a camera redirected: `camerastoredirect` with a device, "*"
// for all), and written to both
const camOnly = parseRdp('full address:s:pc\r\naudiocapturemode:i:0\r\ncamerastoredirect:s:*\r\nredirectclipboard:i:0\r\nredirectprinters:i:0\r\n', 'c').notes;
const camNone = parseRdp('full address:s:pc\r\naudiocapturemode:i:0\r\ncamerastoredirect:s:\r\nredirectclipboard:i:0\r\nredirectprinters:i:0\r\n', 'c').notes;
check('.rdp: the camera asked with the microphone\'s box (camerastoredirect), and written with it',
  JSON.stringify([camOnly, camNone]) === JSON.stringify([[['rdp_asked', ['mic']]], []]) && text0.includes('\r\ncamerastoredirect:s:*\r\n') && text0.includes('\r\naudiocapturemode:i:1\r\n')
  && decodeRdp(written[1]).includes('\r\ncamerastoredirect:s:\r\n'), JSON.stringify([camOnly, camNone]));

// a file as those sent in October 2024 (CERT-UA, CISA): an outside computer through its own gateway, every redirection asked
// for. Imported, nothing of this computer is shared (as Windows since April 2026 with a file it cannot verify), and what it
// asks for and its gateway are said; before, all of it turned on silently
const lure = parseRdp(['full address:s:203.0.113.5', 'gatewayhostname:s:gw.example.net', 'gatewayusagemethod:i:1', 'redirectclipboard:i:1',
  'redirectprinters:i:1', 'drivestoredirect:s:*', 'redirectdrives:i:1', 'audiocapturemode:i:1', 'camerastoredirect:s:*', 'redirectsmartcards:i:1',
  'redirectwebauthn:i:1', 'username:s:'].join('\r\n'), 'AWS Secure Storage Connection Stability Test.rdp');
check('.rdp: a lure file shares nothing of this computer when imported; what it asks for and its gateway said',
  JSON.stringify([lure.conn.clipboard, lure.conn.printer, lure.conn.drive, lure.conn.mic, lure.conn.gw, lure.notes])
    === JSON.stringify([false, false, false, false, 'gw.example.net', [['rdp_asked', ['clipboard', 'drive', 'mic', 'printer']], ['rdp_gateway', 'gw.example.net']]]),
  JSON.stringify(lure));

// a line break in a value (a name or user with one, from a CSV cell or a direct call) never makes a line of its own in the file
const injected = toRdp({ host: 'pc', port: 3389, user: 'ana\r\nfull address:s:otro', name: 'N', app: 'C:\\a.exe', app_args: '-x\r\nalternate shell:s:cmd' });
check('.rdp written: a line break in a value never makes a line of its own', !injected.includes('\r\nfull address:s:otro') && !injected.includes('\r\nalternate shell:s:cmd')
  && injected.includes('username:s:anafull address:s:otro'), injected);

console.log(failed ? `\n${failed} failed` : '\nAll tests passed');
process.exit(failed ? 1 : 0);
