# MuDeskClient

Remote desktop (RDP) client that runs in the browser. The browser only uses HTML, CSS and JavaScript; a gateway written in plain PHP speaks RDP to the remote computers. Nothing has to be installed on the remote computers and no database is needed: all data is kept in JSON files.

[Leer en español](README.es.md)

## Features

- Bulk compression (MPPC, NCRUSH, XCRUSH) end to end and drawing orders with the bitmap cache: both links carry compressed data.
- RDP with TLS and NLA (CredSSP/NTLMv2), RDS licensing, 15/16/24/32-bit color. User names as `user`, `DOMAIN\user`, `HOST\user`, `.\user` (local account of the server) or `user@domain` (UPN).
- Sign-in: users and administrator, optional TOTP and **passkeys** (passwordless, or a second step after the password: the device and browser decide, and MuDesk says which when adding one), one-time setup code, locks per IP / user / site that never lock the owner out of a known browser, constant-time answers.
- Graphics in **automatic** mode (the default): with Windows 8.1 / Server 2012 R2 or later, detected when signing in, the graphics pipeline is used; with xrdp and older servers, bitmaps. RDPGFX graphics pipeline: AVC420 video decoded by the browser with WebCodecs, plus RemoteFX, RemoteFX progressive, ClearCodec, planar and the surface cache. When the browser cannot decode H.264 the other codecs are used. With H.264 the pipeline version 10.7 is offered too, the only one with which Windows 10/11 uses H.264 (in animated areas); AVC444 frames are shown from their 4:2:0 picture.
- Screen drawn on a `<canvas>`; keyboard (scancodes, any layout, dead keys, and Chinese, Japanese or Korean IME, which sends the text once confirmed), mouse, wheel and touch (tap, long press = right click, two-finger scroll).
- The remote resolution follows the browser window (Display Control channel; reconnects with the new size when the server lacks it), or, per connection, the picture is scaled to the window (*Scale the picture*). Full-screen mode with keyboard capture where the browser allows it.
- Clipboard in both directions: text, formatted text (bold, colours… as HTML, the way Word and browsers copy it), images and files (download as ZIP when several files are copied remotely). Drag & drop files onto the session: they are pasted where they are dropped (on the desktop or in an open folder). Clipboard history like Win+V: what was copied either way, to paste it again on the remote computer.
- Screenshot of the remote desktop, whole or of an area framed with the mouse, saved as PNG or copied to the local or the remote clipboard.
- **MuDeskPDF** virtual printer: documents printed remotely arrive in the browser as PDF, ready to print on any local printer or to download.
- Remote audio (IMA ADPCM, A-law, μ-law or PCM, played with the Web Audio API) with a mute button.
- Microphone (off by default, one more box of the connection): the remote computer hears this computer's microphone while a program there records (the remote Windows must allow audio recording redirection, see [HOSTING](docs/HOSTING.md#remote-computers); the page must be opened with `https://`).
- Connect dialog to choose audio, microphone, clipboard, phone keyboard through the clipboard, shared folder, MuDeskPDF printer, graphics, colour depth and several screens for each session (all but the screens can be remembered).
- Several screens: each local screen whose window is open is a separate remote monitor (not one stretched desktop); opening or closing that window adds or removes it without reconnecting. Chrome and Edge list the screens (Window Management API); Firefox looks for the other one by opening a window beside this one.
- Session toolbar that can be moved to a side (kept for the next sessions), with a quality indicator: frames per second, input latency and KB/s received. Its Files panel lists the files sent to the remote clipboard with their state and takes files dropped on it. The other screens' windows have a bar (full screen, close) and close with the session's window.
- Phones and tablets: tap, long press = right click, pinch to zoom, two-finger pan/scroll, on-screen keyboard (opens by itself when tapping a text box; with a mouse, the button opens an on-screen keyboard of our own) and a special-keys panel (Esc, Tab, sticky Ctrl/Alt/Shift/Win, arrows, Home/End, PgUp/PgDn, Del, Backspace, Enter, F1–F12).
- Shared folder: each user's folder on the web server appears in the session as the **Shared folder on MuDesk** drive (in the language of the page); the web interface manages it as a file explorer (folder tree, details or icons with thumbnails, files and folders dragged in from the computer or between folders, several items or folders downloaded as one ZIP).
- Users with an administrator role, optional two-step verification (TOTP, QR code), forced password change, brute-force lock-out.
- Profile: each user sees their open remote sessions and can end them, and their trusted browsers, which they can forget. At sign-in, the last sign-in (date and IP) and the failed attempts with the user since then are shown.
- Installable as an app on phones and computers (web app manifest and icons, no service worker).
- Each user keeps their own connections; remote passwords are encrypted (AES-256-GCM) with a key derived from the user's password, so not even the administrator can read them. Connections are imported from and exported to **.rdp files** of Remote Desktop Connection (never their password), also by the administrator. A connection can be a **RemoteApp**: one program of the remote computer, in its own windows over the page (also on several screens), with a taskbar and a list of its windows.
- Server certificate pinning on first use (warning when it changes).
- Administration: users, connections for some users or all of them (they cannot change or delete them) and every user's own ones, allow-lists (client IPs and RDP targets: IPs, CIDR ranges, host names, `*.domain`), limits (also RDP sessions of all users at once), whether the password of RDP connections may be saved, active sessions (with forced termination), detailed audit log (sign-ins, changes, RDP sessions, clipboard, shared drive, printing) with search, export (CSV, or JSON signed with a server key that anyone can check with `openssl`), configurable retention (90 days by default, or unlimited) and tamper detection; diagnostic mode with a technical trace of a session (no contents or passwords).
- Spanish and English interface: the browser's language is used (English when it is neither), and each user can change it with the selector in the header (also on the sign-in screen and in the profile), which saves it in the profile; responsive, light and dark themes.

## Requirements

- PHP 8.1 to 8.5, 64-bit (all tested) with the bundled OpenSSL and session extensions only (no other extensions, no Composer packages).
- HTTPS (required by the browser clipboard API and by the login cookies).
- The web server must be able to keep one request open for the whole session and send its output without buffering (see *Hosting*).
- Outgoing TCP from the web server to the RDP port of the remote computers (3389 by default).
- A current browser (Chrome, Edge, Firefox) on desktop, tablet or phone. Safari has never been tested, and it cannot be here (no Apple device). Several screens: Chrome and Edge list them; Firefox looks for the other one (see *Limitations*).

## Compatibility (RDP and RDS)

**Important:** tested against Windows 11 Pro 23H2 (NLA), xrdp 0.9.24 and the FreeRDP 3 server; the user also tried RemoteApp on a Windows Server 2016 and sound on Windows 10. Windows Server otherwise, the RD Session Host with RDS licensing and older Windows versions are still untested: their rows are what the protocol specifications say.

| Server | Expected | Notes |
|---|---|---|
| Windows 11, 10, 8.1 (Pro/Enterprise) | Yes (tested Windows 11 Pro) | NLA or TLS. Tested on Windows 11: keyboard, mouse, resizing without reconnecting, MPPC/NCRUSH/XCRUSH compression, drawing orders, graphics pipeline with H.264, two monitors, clipboard (text, emoji and files), MuDesk drive, audio, microphone. H.264 graphics need Windows 8.1 or later. |
| Windows Server 2025, 2022, 2019, 2016, 2012 R2 (Remote Desktop for administration or RD Session Host) | Yes | RD Session Host with RDS licensing (per-user mode recommended, see below). No sound or microphone until its Windows Audio service is started (see [HOSTING](docs/HOSTING.md#remote-computers)). |
| Windows 8, Server 2012 | Yes | No H.264 (graphics pipeline 8.0 only); dynamic resize reconnects. |
| Windows 7, Server 2008 R2 | Probably | Only TLS 1.0 unless the TLS 1.2 update is installed (accepted by the gateway). No graphics pipeline, no dynamic resize without reconnecting. |
| Windows Vista, Server 2008 | Probably | Same as above, single monitor. |
| Windows XP, Server 2003 and older | No | They only offer *standard RDP security* (RC4), which is not implemented. |
| xrdp 0.9 or later | Yes (tested) | TLS; without NLA. |
| FreeRDP server 3 | Yes (tested) | TLS/NLA, graphics pipeline with H.264. |

- **Protocol:** RDP core with client version 0x00080004 (RDP 5.0–8.1) over TLS 1.0–1.3 or NLA (CredSSP v6, NTLMv2). Not supported: standard RDP security (RC4), Kerberos, RDSTLS, smart cards.
- **RDS:** the licensing exchange is supported (license request, platform challenge, new or upgraded license); licenses are not stored, so use **per-user** CAL mode (per-device mode is untested and may hand out a new temporary license each time). Not supported: **RD Gateway**, **connection broker redirection** (connect to the session host directly; a farm behind a broker that redirects ends with the `redirect` error), and the graphics pipeline for **RemoteApp** programs (they use bitmaps and drawing orders).
- **Features by server version** (as specified): multi-monitor, Windows 7/2008 R2+; clipboard with files, Vista/2008+; dynamic resize (Display Control), 8.1/2012 R2+ (older ones reconnect with the new size); graphics pipeline, 8/2012+, H.264 (AVC420) 8.1/2012 R2+; MuDeskPDF printer uses the *Microsoft Print To PDF* driver, present in Windows 10/Server 2016+; bulk compression MPPC every version, XCRUSH (RDP 6.1) Vista SP1/2008+.

## Installation

Step by step for every level (a hosting, a Windows PC with XAMPP or a Linux computer with Apache, firewalls and common problems): **[installation manual](docs/INSTALL.md)**. In short:

1. Copy the project (or, with Composer, `composer create-project miguelenred/mudeskclient mudesk`: no dependencies, only PHP 8.1–8.5 with OpenSSL). Two layouts work (details in [HOSTING](docs/HOSTING.md)):
   - **Whole project inside the web root** (`public_html/mudesk/`): the bundled `.htaccess` files (Apache, LiteSpeed) and `web.config` files (IIS) leave only `public/` reachable; Nginx and OpenLiteSpeed need the rules in [HOSTING](docs/HOSTING.md) and deny `data/`, `src/`, `tests/`, `tools/` and `docs/`. Check that `mudesk/data/web-check.txt` answers 403 (or 404), never 200.
   - **Only `public/` in the web root** and the rest outside; if `public_html` is not inside the project folder, edit the `require` line at the top of `public_html/*.php` so it points to `src/bootstrap.php`.
2. Write permissions: **only `data/`** (and everything inside it) must be writable by the user PHP runs as; the application creates its subfolders (`audit/`, `connections/`, `files/`, `run/`, `sessions/`, `tmp/`, `trace/`). Everything else (`public/`, `src/`, `config.php`) only needs to be readable. When `data/` is not writable, the application says so on a page of its own, with no PHP messages; on Windows (XAMPP, IIS) give the web server's account the *Modify* permission (see [HOSTING](docs/HOSTING.md#write-permissions)).
3. Optional: copy `config.sample.php` to `config.php` (in the project folder) and adjust it.
4. Open the site: the first visit asks for the **setup code**, found in `data/setup-code.php` (open it with the hosting file manager), and creates the administrator account. The code works only once.

### Hosting

Each open session keeps one PHP request running and streams the screen through it. Before deploying, upload `tools/check.php`, set its token and run its six tests (PHP environment, outgoing RDP connection, long request, latency, data folder reachable from the web, PHP processes at once). Remove it afterwards.

- Apache: `public/.htaccess` disables compression for the stream and has scripts and styles revalidated on every load (IIS: `public/web.config`), so an update reaches the browser at once. With PHP-FPM behind `mod_proxy_fcgi` add `flushpackets=on` to the proxy configuration.
- Nginx: disable buffering for `rdp.php` (`fastcgi_buffering off;`; the gateway also sends `X-Accel-Buffering: no`) and add `location ~* \.(js|css)$ { add_header Cache-Control "no-cache"; }`: the page asks for its script and style with a fingerprint of its files (`?v=`), but the modules they import carry none. A page left open during an update says so and offers to reload.
- Proxies such as Cloudflare close long requests (≈100 s); the client then reconnects automatically and the Windows session stays alive, but a direct connection is recommended.
- If the hosting forbids local Unix sockets set `'ipc' => 'file'` in `config.php`.
- Each open session counts as one PHP process, plus up to four short requests at a time while typing or moving the mouse (or while a remote program records from the microphone); check the hosting's process limit and keep *RDP sessions at once (all users)* (`max_sessions_total`, 20 by default) below it. When it is reached, the gateway says the server is busy, without connecting.

### Security notes

- Open the RDP port of the remote computers only to the IP address of the web server.
- Use NLA on the remote computers (default on current Windows versions).
- Self-signed server certificates are accepted: the certificate fingerprint is saved on the first connection and any later change is reported (trust on first use). The first connection to a computer cannot check who it is: make it from a network you trust and prefer the security *NLA only* (see *Known limits* in [SECURITY](SECURITY.md)).
- `data/` holds the users, encrypted secrets and `secret.php` (server key); back it up. It can stay inside the web root: `.htaccess` files deny every folder but `public/`, and the administrator is warned if the server ignores them (see [HOSTING](docs/HOSTING.md)).

## Configuration (`config.php`)

| Key | Default | Meaning |
|---|---|---|
| `data_dir` | `<project>/data` | Folder for JSON files, sessions, shared folders |
| `force_https` | `true` | Reject plain HTTP (localhost is always allowed, unless it is a trusted proxy) |
| `trusted_proxies` | `[]` | Reverse proxy IPs whose `X-Forwarded-For` / `X-Forwarded-Proto` are trusted |
| `ipc` | `auto` | How running sessions are reached: `auto` (a Unix socket; on Windows a TCP port of this computer; the queue file if neither can be opened) or `file` (always the queue file) |
| `drawing_orders` | `true` | Let the server send copy/fill commands, text from cached glyphs and use the bitmap cache instead of resending bitmaps (≈3 times less traffic in the xrdp test; with Windows 11, dragging a window goes from 150 to 60 KB/s and PHP CPU from 6.5 to 2.7 %). Validated with xrdp and Windows 11: identical picture and no leftovers on repaint |
| `bulk_compression` | `'rdp61'` | Highest RDP bulk compression offered: `'rdp61'` (XCRUSH, Windows Vista SP1/2008+; servers that only know MPPC, such as xrdp, use MPPC), `'rdp6'` (NCRUSH), `'mppc'`, or `false`. Both links (RDP server → hosting → browser) carry compressed data: with MPPC ≈3.5 times less traffic, with XCRUSH about a third less than MPPC and less gateway CPU (see ARCHITECTURE) |

The rest of the settings (allow-lists, lock-out, time-outs, session limits, reconnection attempts, quotas, 2FA policy, audit retention, diagnostic mode in every session, saving RDP passwords) are edited by the administrator in the web interface.

## Limitations

- Graphics pipeline (RDPGFX): chosen by the automatic mode with Windows 8.1 or later (it can also be forced or turned off per connection; never used for RemoteApp). Tested against FreeRDP and Windows 11 (ClearCodec, RemoteFX progressive with upgrade passes, and AVC420). AVC444 is shown from its 4:2:0 picture only (full-resolution colour not yet). Without it, classic bitmap updates are used, which every Windows version supports.
- When typing from a phone through the clipboard, what was copied in Windows is put back except formats Windows does not hand over RDP (embedded Office OLE objects); when the text cannot be pasted (files copied in Windows, or more than 16 MB), at most 2000 characters are typed.
- Drawing orders: Mem3Blt is not negotiated (Windows sends bitmaps instead); a MemBlt whose blend mode uses a brush gets Windows' default white brush.
- The graphics pipeline keeps all its surfaces within 4 times the graphics output (at least 1920×1080; the output itself at most 8192 × 8192 pixels): a hostile RDP server can still make the browser hold up to about 1 GB; beyond that the browser fails with a logged error.
- **RemoteApp**: the remote computer must allow the program (Windows Server: published with Remote Desktop Services; Windows 10/11 Pro: in the `TSAppAllowList` registry key, see [HOSTING](docs/HOSTING.md#remote-computers)). Drawn with bitmaps and drawing orders (no graphics pipeline or H.264; the Graphics option is not offered); no icons in the Windows panel and no notification area. While it opens, a spinner says so (after 15 s, that it is taking longer); if the server ends the session meanwhile (error info 0x0B), the page connects again by itself; with no window after 90 s the session ends saying so. Closing its last window ends the session 3 s later.
- Phone keyboards on Windows 11: Notepad, Terminal and Explorer garble the text that comes from the on-screen keyboard (repeated letters, lost capitals or a lost Enter). It is a Windows bug, not a MuDeskClient one; details and tests in [Phone keyboards and Windows 11](docs/MOBILE-KEYBOARD.md). The "Phone keyboard through the clipboard" option (per connection; on "Automatic" it turns on by itself on phones and tablets) pastes that text through the remote clipboard and then puts the clipboard back as it was, an image copied in Windows included; when files had been copied in Windows the text is typed instead of pasted. An Enter followed by more text with no pause can still be lost in Notepad; at a phone's real pace it happened in 2 of 24 lines. The physical keyboard is not affected.
- Local printers are reached through the MuDeskPDF printer and the browser print dialog (browsers cannot see printers directly). No smart cards, RD Gateway or connection broker redirection. The microphone goes as 16-bit PCM (the only format Windows 11 offers besides compressed ones: about 176 KB/s upwards while a program records); no camera.
- NTLM only (no Kerberos).
- Browsers cannot put files on the local clipboard: files copied on the remote computer are offered as a download.
- Reading the local clipboard automatically is only possible in Chromium-based browsers after granting permission; elsewhere use Ctrl+V or the clipboard panel.
- Several screens: Chrome and Edge list the screens (Window Management API). Firefox and Safari (never tested) do not: the other screen is looked for by opening a window next to the current one (pop-ups must be allowed), and two are used; when it is to the left of the main one, the first time that window has to be taken to it (see [USAGE](docs/USAGE.md#firefox-and-safari)).

## Project layout

```
public/            web root: index.php (interface), api.php, rdp.php (gateway), files.php, assets/
src/               PHP classes (no dependencies)
src/Rdp/           RDP protocol: Client, Transport, CredSsp, Ntlm, Licensing, Cliprdr, Rdpdr, Rdpsnd, Drdynvc, Audin (microphone), Rail (RemoteApp),
                   Bulk/Mppc/Ncrush/Xcrush (compression), Trace (diagnostics)
public/assets/     browser: rdpgfx.js (graphics pipeline), rfx.js (RemoteFX), gfxsurfaces.js, gfx.js, session.js, rdpfile.js (.rdp files),
                   mic.js and mic-worklet.js (microphone), screen-watch.js (the other screens' windows)…
data/              JSON storage (created at runtime)
tools/check.php    hosting diagnostics
tests/             unit tests and end-to-end tests
```

## Performance

The screen stream is read and decoded in a Web Worker, so input never waits for drawing. Keyboard and mouse events are sent at once (up to four requests in flight, re-ordered on the server by sequence number) through a light endpoint that does not open the PHP session, and the RDP socket uses `TCP_NODELAY`. Measured: the gateway used 0.19 s of CPU and 4 MB of memory to relay 34 MB of full-screen changes in 14 s; with 60 ms of network latency the last key of fast typing arrives one round trip (≈65 ms) after it is pressed.

Bulk compression and the bitmap cache, against xrdp with the same 20 s of scrolling text and changing pictures (16-bit): 10.9 MB with plain bitmaps, 3.2 MB compressed, 3.9 MB with drawing orders and cache, **1.6 MB with both** (6.8 times less); gateway CPU 0.07 s, 0.50 s, 0.04 s and 0.30 s. The picture stays pixel-identical in Chrome and Firefox.

Against Windows 11 (details per codec and quality, PHP and browser apart, in [PERFORMANCE](docs/PERFORMANCE.md)): with the graphics pipeline PHP uses 0.4–2 % of a core per connection, against 6–52 % with compressed bitmaps; dragging a window costs 35 KB/s with the graphics pipeline, 60 KB/s with bitmaps and drawing orders and 150 KB/s with bitmaps alone.

With H.264 graphics, against a FreeRDP server and the same animated screen: 21 KB/s instead of 4.2 MB/s with 32-bit bitmaps (≈200 times less traffic); quality 33 dB PSNR in Chrome and Firefox. The browser acknowledges every decoded frame (only the latest one is sent), so the server adapts the frame rate to what the device can draw.

## Tests

- `php tests/run.php` — protocol test vectors (NTLMv2, RC4, DER, TOTP, CIDR, UTF-16), audit, clipboard, error pages, file manager (move, tree, ZIP), web session expiry, RemoteApp (RAIL), the microphone channel, connections of the administrator, the page's file fingerprint.
- `php tests/auth_e2e.php base dataDir` — sign-in security against a running copy with an empty data folder.
- `php tests/rdp_probe.php host port user password` — connects from the command line and reports what the browser would receive.
- `node tests/bench.mjs host port user password [workload] [seconds]` — measures one connection under a workload (idle, text, window drag, animation, sound): traffic, PHP CPU and memory, CPU and memory of the browser decoders, frames per second (see [Performance](docs/PERFORMANCE.md)).
- `node tests/input_unit.mjs` — typed text (one character at a time) and the text cursor that opens the phone keyboard.
- `node tests/clipboard_unit.mjs` — formatted text (CF_HTML) for the clipboard.
- `node tests/rdpfile_unit.mjs` — .rdp files of Remote Desktop Connection: read into a connection and written from one.
- `node tests/mic_unit.mjs` — the microphone in the browser: resampling and 16-bit PCM, its capture and when it is let go.
- `tests/gfx_unit.mjs`, `tests/rdpgfx_unit.mjs` (ZGFX, ClearCodec, NSCodec, RemoteFX, progressive, H.264 SPS), `tests/bulk_unit.mjs` (MPPC, NCRUSH, XCRUSH, fragments), `tests/orders_unit.mjs` (also windowing orders), `tests/audio_codecs.mjs` (needs sox) — decoder unit tests (Node.js).
- `node tests/session_unit.mjs` (input, reconnection, drawing orders, stream worker, several screens, RemoteApp), `node tests/ui_unit.mjs` (profile, audit, session window, ZIP downloads, screenshot, file explorer, session expired, .rdp files, connections of the administrator, RemoteApp, Files panel, movable toolbar, update notice, the other screens' windows) — browser code on minimal doubles.
- `php tests/fuzz.php`, `node tests/fuzz.mjs` — random and mutated data against the gateway (RDP core and channels, RemoteApp and the microphone too) and the browser decoders; fixed seed, options `--seed`, `--iterations`, `--target`, `--case` and `--strict`.
- `tests/gfx_render.mjs`, `tests/audio_check.mjs` — decode dumps written by `rdp_probe.php` (`DUMP=file`, `AUDIO=1`).
- `tests/e2e.mjs`, `tests/e2e_clip.mjs`, `tests/e2e_gfx.mjs` (H.264: compares the canvas with a capture of the remote), `tests/e2e_passkey.mjs`, `tests/e2e_lang.mjs`, `tests/e2e_glyphs.mjs` — Chrome tests (Node.js + Playwright); `tests/e2e_firefox.py` — Firefox tests (Selenium). Development only.

On GitHub every push runs `tests/run.php` and `tests/fuzz.php` on Linux and Windows with PHP 8.1, 8.2, 8.3, 8.4 and 8.5 (any warning, notice or deprecation fails the run), and every `tests/*_unit.mjs` and `tests/fuzz.mjs` with the current and the previous Node.js LTS.

## Documentation

- [Installation manual (every level)](docs/INSTALL.md)
- [User guide](docs/USAGE.md)
- [Hosting and deployment](docs/HOSTING.md)
- [Architecture and security model](docs/ARCHITECTURE.md)
- [Testing and results](docs/TESTING.md)
- [Performance: bandwidth, CPU and memory per codec](docs/PERFORMANCE.md)
- [Phone keyboards and Windows 11](docs/MOBILE-KEYBOARD.md)
- [Roadmap and design decisions](docs/ROADMAP.md)
- [Changelog](CHANGELOG.md) · [Security policy](SECURITY.md) · [Contributing](CONTRIBUTING.md)

## Authorship and way of working

**Miguel Sánchez** ([miguelenred.es/mudeskclient](https://miguelenred.es/mudeskclient)) is the author of MuDeskClient:

- the idea of the project is his;
- he organizes the work: what is built, and in which order;
- he sets the way of working: security first, performance measured before and after every change, a test that fails without each critical change, documentation kept up to date in English and Spanish, and honesty about what is tested and what is not.

He is assisted by artificial intelligence, which writes code, tests and documentation following his directions; he reviews the results, tries them on real computers and decides what goes in.

For transparency, what is tested, how it was tested and what is **not** tested yet are listed in [Testing](docs/TESTING.md); known limitations are above and in the [roadmap](docs/ROADMAP.md).

## License

MuDeskClient is free software under the **Apache License 2.0**: it can be used, changed and redistributed, also commercially, under its conditions (keep the license and the NOTICE file, and say what you changed). See [LICENSE](LICENSE) and [NOTICE](NOTICE).

It is not required, but if you use it, a **"Powered by MuDeskClient"** mention (with a link to https://miguelenred.es/mudeskclient) is appreciated.
