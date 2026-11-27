# Roadmap and design notes

[Español](es/HOJA-DE-RUTA.md)

## 1. Validation with Windows

Done with Windows 11 Pro (see [TESTING.md › Windows 11 Pro](TESTING.md#windows-11-pro-23h2-nla)): NLA, Display Control, compression, drawing orders, graphics pipeline with H.264, two monitors, clipboard, drive, audio, microphone, camera and a folder of this computer, with five bugs fixed. Since then (see [TESTING](TESTING.md)): Windows Server 2022 (RDS licensing per device, MuDeskPDF, RemoteApp, its RD Gateway and broker, a domain), 2012 R2, 2012, 2008 R2, 2008 and 2003. Left: per-user RDS licensing and CALs, a broker sending the session to another session host, and Windows Server gateways before 2022.

## 2. Screen speed

What is already done:

- Screen stream decoded in a Web Worker; lookup tables and inlined hot paths in the RLE decoder.
- Input without PHP session, up to 4 requests in flight with sequence numbers (one the hosting refuses sent again, one at a time meanwhile), `TCP_NODELAY`.
- The gateway does not decode bitmaps (0.19 s CPU for 34 MB).
- Bulk compression on both links: MPPC ≈3.5× less traffic, XCRUSH (RDP 6.1, offered by default) about a third less than MPPC with less gateway CPU; bitmap cache + MemBlt (with `drawing_orders`): 1.6 MB instead of 10.9 MB together in the xrdp test.
- Graphics pipeline with H.264 (used by the automatic mode with Windows 8.1 or later): 21 KB/s instead of 4.2 MB/s on the same animated screen (FreeRDP). With Windows 11 the version 10.7 is offered (with 8.1 Windows does not use H.264) and the animation goes from 18 to about 45 frames/s.
- LAN connection type: Windows sends 20 % more frames.
- RemoteFX progressive decoding 5.6 times faster (79 → 14 ms per full-screen frame) and opaque graphics pipeline blocks painted without blending (2.6 times faster).
- Measurements per codec and quality, PHP and browser apart: [PERFORMANCE](PERFORMANCE.md).
- The speed review of 1.1: the gateway's decompression 1.2 to 2.5 times faster and its RC4 1.35 to 1.86 times, the browser's decoders 9 to 54 % less time, the bitmaps of an update delivered to the page together and an operation of several areas drawn once; on a computer held to a sixth of the CPU an animation goes from 9.7 to 12.3 frames per second ([PERFORMANCE](PERFORMANCE.md#fourth-review-11-decoders-delivery-to-the-page-and-slow-computers)). Left there: a burst of hundreds of full-screen scrolls from an old server queues on the page (holding the server back cost 12–25 % of throughput).

- Automatic graphics mode (the default): the Windows version in the NTLM challenge decides (signed in with Kerberos, which gives none: a domain's Windows, the pipeline); Windows 8.1 or later → graphics pipeline with H.264 when the browser supports it; xrdp, TLS only and older Windows → bitmaps. A RemoteApp connection always uses bitmaps and drawing orders (no graphics pipeline).

Done: AVC444 with full-resolution colour (v1 and v2, checked against the protocol's layout; with Windows 11 its luma picture shown through it). Left: see Windows send the second picture (with its graphics card encoding, it sent the first one only).

What is left (gains still to be measured):

| Option | Gain | Notes |
|---|---|---|
| HiDPI (DesktopScaleFactor) | Sharper picture on high-density screens | Costs more bandwidth |

## 3. Printing

MuDeskPDF uses the "Microsoft Print To PDF" driver on the remote side. If Windows uses Easy Print instead (policy), the client would need the Easy Print (XPS) channel. A PostScript driver would require a PostScript-to-PDF converter, not feasible in plain PHP.

## 4. Security and robustness

Done (second review, see the [changelog](../CHANGELOG.md)):

- One shared-folder quota per user for the drive of all their sessions, the file manager and clipboard files.
- The shared folder as a file explorer: folder tree, drag and drop of files and folders (uploads and moves), ZIP downloads made as they are sent.
- Chinese, Japanese or Korean IME on a computer: the text is sent once, when confirmed; dead keys are not doubled.
- Drawing orders with any ROP (MemBlt and PatBlt).
- User panel in the profile: own remote sessions and trusted browsers; the failed attempts since the last sign-in shown at sign-in.
- Signed audit export (RSA 3072, checked with `openssl`), streamed row by row.
- Installable app on phones and computers.
- Limit of RDP sessions of all users at once, which `tools/check.php` helps to set.
- Fuzz tests of the gateway and the browser decoders; CI with PHP 8.1, 8.2, 8.3, 8.4 and 8.5 on Linux and Windows and the current and previous Node.js LTS.
- Error pages of our own, with no PHP messages; clipboard history like Win+V; an on-screen keyboard of our own when there is a mouse; dropped files pasted where they are dropped; full screen on all the screens; an administrator option to allow saving RDP passwords or not; the shared folder as a file explorer; session screenshot; a remote monitor only while its window is open; a *Session expired* window with the reason.
- Drawing orders on by default: dragging a window 150 → 60 KB/s.
- RemoteApp (MS-RDPERP): one program of the remote computer, its windows over the page with a Windows panel and a taskbar, also on several screens; the session ends when the program closes.
- .rdp files imported (one into the editor, several at once) and exported.
- Connections of the administrator for chosen users or for all, each user with their own password, certificate and options.
- Graphics surfaces limited to 4 times the output (at most the pixels of 8192 × 8192, 32766 a side) and the bitmap cache to 100 MB.

Left:

- Try a real Chinese, Japanese or Korean IME on a computer.
- A hostile server can still make the browser hold graphics surfaces of up to 4 times the output, about 1 GB at worst.
- RemoteApp with the graphics pipeline or H.264 (each window on a surface of its own), icons in the Windows panel and the taskbar, the program's notification area.

## 5. Next and other ideas

- **Done: the microphone** in RDP sessions (audio input, the AUDIO_INPUT dynamic channel): one more box in the connection options, off by default; the remote Windows must allow audio recording redirection ([HOSTING](HOSTING.md#remote-computers)). Left: a compressed format (IMA ADPCM would send a quarter of the 176 KB/s of PCM).
- **Done: the camera** (1.1), with the microphone in one box (MS-RDPECAM, Motion JPEG from the page's own camera, in Chrome, Edge and Firefox), and **a folder of this computer** as a drive of the session (read and write in Chrome and Edge, read only elsewhere; see [USAGE](USAGE.md#a-folder-of-this-computer)). Decided: the camera goes with the microphone (one decision about this computer's devices, off by default); the folder needs the connection's shared folder, so an administrator who turns that off turns both off. Left out: H.264 pictures of the camera (less bandwidth; Motion JPEG works in every browser) and the folder's changes told to Windows as they happen (the browser cannot watch a folder).
- **Done: session recording** (1.1), played back in the browser with the same decoders, opened from the audit. Decided: off unless the administrator records a connection; the user is told before anything is shown and while the session lasts; encrypted with a key of the server; kept 30 days (a setting) and at most 2 GB each (`record_max_mb`); watched only by administrators and auditors, each viewing audited. **Done since 1.1:** its sound and the microphone's (not the keyboard or the clipboard); started and stopped at any moment, by administrators (in the session or in *Active sessions*) and by users when allowed; downloaded as a WebM video made in the browser (WebCodecs and a WebM writer of the project's own); one started in the session made by the user's browser, of what it shows (MediaRecorder: no new connection); kept until deleted by hand by default; deleted by administrators; users' own in **Recordings**. Left: skipping still stretches.
- **Done: sharing a session** (1.1) with a link, view only or with control (see [USAGE](USAGE.md#sharing-a-session)).
- **Done: Remote Desktop Gateway and connection broker** (1.1): a connection through an RD Gateway (MS-TSGU over a WebSocket, over two HTTPS connections, or RPC over HTTP for Windows Server 2008 and 2008 R2; in plain PHP) and with a farm's load balance info, the broker's redirections followed (its password for the next computer given with RDSTLS); tested through an open-source gateway in front of Windows 11 (RPC over HTTP against a gateway written from the specifications), and since 1.3 against Windows Server 2022's own gateway (its three transports) and broker (one session host: not yet a redirection to another).
- **Done: older Windows** (1.1): Standard RDP Security for Windows XP and Server 2003 (only with the security *Automatic*), TLS 1.0 and CredSSP 3 for Windows 7 and Server 2008 R2 (CredSSP 3 now only with `credssp_unpatched`: CVE-2018-0886), Kerberos with RC4 for Windows Server 2003 domains; their limits in [SECURITY](../SECURITY.md). After 1.2.0, tried on Windows Server 2003, 2008, 2008 R2, 2012 and 2012 R2 themselves, and TLS 1.0 made by MuDeskClient for Windows 7 and 2008 R2 as first released (PHP's cannot reach them).
- **Done in 1.2**: the first connection to a computer confirmed by its fingerprints (SHA-256 and SHA-1), or one an administrator fixed for everyone; *Administration → Status* with charts of the last 30 days and `health.php` for monitors; encrypted backups and their restore; links for a while and guests' control lost when unused; nothing works while the data folder can be read from the web.
- **Done in 1.3**: a guided first run that checks the server step by step; an optional WebSocket service for servers of your own (less delay and fewer processes; each session a copy of the service); a Helm chart, an installer for Debian and Ubuntu and an LXC container for Proxmox VE; the Docker image ready for production; keys and clicks a hosting refuses sent again; CredSSP without the 2018 update refused (CVE-2018-0886); fuzzing every night; AVC444 in full colour and Mem3Blt. Tested against Windows Server 2022: its own RD Gateway with its three transports, the Session Host with two users at once, RemoteApp and the MuDeskPDF printer.

Other ideas: persistent bitmap cache, copy of the audit to an external syslog server (stronger proof than the HMAC chain, whose key lives on the same server). Smart cards are not possible: a browser cannot let the gateway use the card's private key.

## Decisions taken (and why)

| Decision | Reason |
|---|---|
| One long HTTP request per session, a WebSocket only where there is one | Shared hosting: no daemons, no WebSocket. On a server of your own an optional service carries the same session over a WebSocket (less delay, fewer processes), run by the same `rdp.php`, so nothing depends on it |
| Bitmaps forwarded, decoded in JavaScript | Low CPU and memory on the hosting |
| OpenSSL extension required | RDP requires TLS; TLS in plain PHP would be slow and unsafe. OpenSSL is bundled with PHP |
| Kerberos when a domain controller can be reached, else NTLM | From a hosting the domain controllers are usually out of reach: NTLM then, as Windows does without a KDC (not where the domain blocks it). Kerberos with AES and, for Windows Server 2003 domains and passwords older than their domain's AES keys, RC4 (offered last), only if the administrator allows it (off by default: someone in the middle could make it be used, and the password then breaks far faster); passwords only (no smart cards) |
| Standard RDP Security only with the security *Automatic* | Windows XP and Server 2003 have nothing else, but it proves nothing of the server and its RC4 is weak: it is never used by a connection set to *NLA only* or *TLS only*, the server's key is pinned as a certificate (a TLS computer that falls back to it is a changed certificate), and a server that encrypts nothing is refused |
| Remote passwords encrypted with the user's password | Not even the administrator can read them |
| Server certificates confirmed by the user the first time (or fixed by an administrator for everyone) | RDP servers use self-signed certificates: nothing else can tell who a computer never seen is (before 1.2, trusted on first use) |
| Allow-lists for client IPs and targets | Requested: "IPs and domains, or everything allowed" |
| Multi-monitor as separate windows | Browsers allow one window per click and only Chromium exposes the screens |
| A remote monitor only while its window is open | Otherwise programs open on a screen nobody sees |
| Drawing orders on by default | Validated with xrdp and Windows 11: identical picture, no leftovers on repaint, 2.5 times less traffic when dragging windows |
| Compression decoded twice (gateway and browser) | The gateway needs the shared history to read slow-path PDUs; forwarding the compressed bytes keeps the browser link small too. Cost measured with Windows 11: 6–15 % of a core with text or windows and 35–52 % with full-screen animation (with the graphics pipeline, 0.4–2 %) |
| Every sign-in answer takes 1 s (a locked IP's, at once) | Hides which step failed and whether the user exists (bcrypt 12 + PBKDF2 take ≈0.78 s on the test machine) |
| Passkeys replace the password only with PRF | The saved RDP passwords are encrypted with a key that needs a secret from the user: the password, or the passkey's PRF output |
| Known browsers exempt from user and global locks, not from the IP lock | An attacker cannot lock the owner out, and a single IP is still stopped |
| XCRUSH offered first, MPPC kept | XCRUSH gives the least traffic with less gateway CPU than MPPC; servers that only know MPPC (xrdp) keep using it |
| Graphics in automatic mode by default | The graphics pipeline needs 32 bpp: servers without it (xrdp) would get 32-bit bitmaps. So it is only used when NLA shows Windows 8.1 or later; without NLA there is no version and bitmaps stay |
| Phone keyboard text that arrives in one go is pasted through the remote clipboard, with a per-connection option ("Automatic": on for phones and tablets) | Windows 11 Notepad, Terminal and Explorer garble injected input: they repeat the last letter and lose capitals, Enter and even Ctrl+V. Neither pauses (100 or 150 ms) nor keys of the layout avoid it; pasting does (8 of 8 lines), as other programs with the same bug do. Measured: Notepad's spell check and autocorrection drop a key when a control key or a paste arrives right behind it, and Windows gives no sign of when they are done; an empty check paste did not help and was dropped. Each step waits for a sign from Windows (answer to the announcement, screen change, request for the text). What was copied in Windows is read whole first and put back, out of the history; with files, or more than 16 MB, the text is typed so they are not lost. Off by default on computers because Ctrl+V is not paste in every program. Details in [MOBILE-KEYBOARD](MOBILE-KEYBOARD.md) |
| LAN connection type, no network auto-detection | Without it Windows gives fewer frames. Auto-detection (MS-RDPBCGR auto-detect) would only measure the gateway–server link, usually the same network; the browser's link already counts: with the graphics pipeline the frame acknowledgements leave the browser after decoding (when it is slow, Windows sends less) and with bitmaps TCP back-pressure slows the server down |
| Drawing on the main thread (no OffscreenCanvas) | With full-screen animation (Windows 11, graphics pipeline) the main thread spends 84 ms per second painting (8 %), with no long tasks, and input stays around 20 ms. H.264 colour is already converted by the GPU when the decoded frames are painted; moving the drawing to the worker would not save CPU, only move it to another thread |
| No auto-reconnect cookie | Automatic reconnection already reuses the password (a typed one stays in the tab's memory, a saved one is decrypted by the gateway) and Windows goes back to the user's disconnected session; with NLA the cookie does not spare presenting credentials |
| H.264 SPS rewritten in the browser | Browsers ignore the colour space requested from the decoder (Firefox) and assume limited range; RDP uses BT.709 full range |
| Limit of RDP sessions of all users at once (20 by default) | Each session holds a PHP process while it lasts; past what the server has, the web and the keyboard would wait too. A full gateway answers "busy" at once |
| Names resolved by the system resolver, not one of its own | An own resolver would break the `hosts` file, search domains and Windows name resolution. The system resolver's time-out applies, and the session limit bounds how many processes can wait |
| Audit export signed with an RSA key | RSA can sign a SHA-256 computed piece by piece while the rows go out, so the export needs no copy in memory (about 20 MB for 20 000 rows) and `openssl dgst -verify` checks it |
| Each known browser has its own lock | A stolen device cookie gives no more attempts than the per-user lock, and the owner is still not locked out by others |
