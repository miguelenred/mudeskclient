# Hosting and deployment

[Español](es/HOSTING.md)

This is the technical reference. For a step-by-step guide for every level (a hosting, Windows with XAMPP, Linux with Apache, firewalls, common problems), see the [installation manual](INSTALL.md).

## Before deploying: `tools/check.php`

1. Edit `TOKEN` at the top of `tools/check.php`.
2. Copy it into `public/` on the hosting (the `tools/` folder is denied to the web) and open it in the browser.
3. Run the six tests:
   - **PHP environment**: version, OpenSSL, disabled functions, limits, Unix sockets. It warns about PHP outside 8.1–8.5, `display_errors` on and `memory_limit` below 128M.
   - **Outgoing RDP**: connects to a real remote computer and reports TLS/NLA, TLS version and certificate.
   - **Long request**: keep it running 600 s; it must reach the end and the buffering delay must stay low.
   - **Latency**: round trip to the hosting.
   - **Data folder reachable from the web**: writes a test file in the data folder and tries to download it through the web; it must fail.
   - **PHP processes at once**: sends requests of 1 s at once (20 by default) and counts how many the server handled together (N); `max_sessions_total` must be N − 2 at most (see *Capacity*). Over HTTP/1.1 the browser opens 6 connections at most, so it cannot measure more.
4. **Delete the file** afterwards.

## Requirements

| Item | Needed |
|---|---|
| PHP | 8.1, 8.2, 8.3, 8.4 or 8.5 |
| Extensions | `openssl`, `session` (both bundled and enabled in practically every build) |
| Functions | `stream_socket_client`, `stream_socket_enable_crypto`, `stream_select`, `set_time_limit`, `ignore_user_abort`; `stream_socket_server` recommended |
| HTTPS | required (a self-signed certificate works after accepting it once in the browser) |
| Outgoing TCP | from the web server to the RDP port of each remote computer |

## File layout

Two layouts work. Only `public/` has to be reachable from the web.

**A. Whole project inside the web root** (the usual one on shared hosting):

```
/home/account/public_html/mudesk/            whole project; open https://your-domain/mudesk/
/home/account/public_html/mudesk/data        writable by PHP
```

The project's `.htaccess` files (and `web.config` for IIS) keep everything except `public/` out of reach: the root one sends every request to `public/`, and `data/`, `src/`, `tests/`, `tools/` and `docs/` each deny all access and listings (Apache 2.2 and 2.4). If the `.htaccess` of `data/` is deleted, the application writes it again. Check after uploading (each must answer **403**): `/mudesk/data/web-check.txt`, `/mudesk/config.php`, `/mudesk/src/bootstrap.php`. When the server ignores `.htaccess` (`AllowOverride None`), the application shows a red warning on the first-run screen and in the administration section, because the data folder can be downloaded.

**B. Only `public/` in the web root** (safest when the hosting allows it):

```
/home/account/mudesk/src
/home/account/mudesk/data          writable by PHP
/home/account/mudesk/config.php    optional
/home/account/public_html          <- contents of public/
```

If `public_html` is not next to `src/`, as here, change the first `require` of `index.php`, `api.php`, `rdp.php` and `files.php` (here to `require __DIR__ . '/../mudesk/src/bootstrap.php';`).

### Protecting the private folders, per web server

Only `public/` may be served. Check after deploying: `data/web-check.txt`, `config.php` and `src/bootstrap.php` must answer **403** (or 404), never 200; the application also warns the administrator when the data folder can be read.

- **Apache** (2.2 and 2.4): the bundled `.htaccess` files do it; they need `AllowOverride All` (or at least `AuthConfig FileInfo Indexes Limit Options`; the root one uses `DirectoryIndex`). Tested.
- **LiteSpeed Enterprise** (most shared hostings with cPanel/LiteSpeed): reads `.htaccess` like Apache; nothing else to do.
- **OpenLiteSpeed**: does not apply access directives from `.htaccess`, only rewrite rules. The private folders also carry `RewriteRule ^ - [F]`, which OpenLiteSpeed applies when rewriting is enabled for the virtual host (*Rewrite → Enable Rewrite: Yes* and *Auto Load from .htaccess: Yes*); restart it after uploading. Safer: add a *Context* per folder (`/mudesk/data/`, `/mudesk/src/`, `/mudesk/tests/`, `/mudesk/tools/`, `/mudesk/docs/`) with *Accessible: No*, or point the document root to `public/`.
- **Nginx**: ignores `.htaccess`. Point `root` to `public/` (best), or, with the whole project under the web root (here in `/mudesk/`), add to the `server` block (tested):

  ```nginx
  location ~ ^/mudesk/(data|src|tests|tools|docs|vendor)(/|$) { deny all; }
  location ~ ^/mudesk/(config(\.sample)?\.php|web\.config|\.htaccess|[^/]+\.md|composer\.(json|lock)|LICENSE|NOTICE)$ { deny all; }
  location = /mudesk/ { return 302 /mudesk/public/; }
  ```

  Without the `/mudesk` prefix when the project is at the root of the site.
- **IIS**: the root `web.config` hides `data`, `src`, `tests`, `tools`, `docs`, `config.php`, `config.sample.php`, `LICENSE`, `NOTICE` and `.md`/`.json`/`.lock`/`.log` files with *request filtering*, and each private folder has a `web.config` that refuses every file (the application writes it again in `data/` if it is missing). Only core IIS features are used, no URL Rewrite module. Open the application at `.../public/` or point the site to `public/`. Not tested on a real IIS yet.

### Write permissions

| Folder / file | PHP needs | Why |
|---|---|---|
| `data/` and everything inside | read + write | users, settings, connections, audit, sessions, shared files, run-time files; PHP creates `audit/`, `connections/`, `files/`, `run/`, `sessions/`, `tmp/`, `trace/`, `.htaccess`, `web.config`, `index.html`, `web-check.txt`, `secret.php`, `audit-key.php` (key of the signed audit exports) and, on first run, `setup-code.php` |
| `public/`, `src/`, `config.php`, `.htaccess` files | read only | code and configuration: nothing is written there |

Folders are created with mode `0700` and files with `0600` when the hosting allows it. If the hosting runs PHP as another user than the FTP account, give that user write access to `data/` only.

On **Windows** (XAMPP, IIS) the account that runs the web server needs the *Modify* permission on `data`. That account is the one of the Apache service or of the IIS application pool (Services → the service → *Log On*), or your own account when Apache is started from the XAMPP panel. Task Manager → Details shows it in the *User name* column of `httpd.exe` or `w3wp.exe`. From a console run as administrator:

```
icacls "C:\xampp\htdocs\mudesk\data" /grant "ACCOUNT:(OI)(CI)M"
```

When `data/` is not writable, the application shows the page "Cannot save to the data folder". The server error log says which folder could not be created.

### Errors

The application never shows PHP's own messages, server paths or traces to the browser, even with `display_errors` on (XAMPP ships it on). Warnings, exceptions and fatal errors go to the server error log: php.ini's `error_log` (in XAMPP, `php\logs\php_error_log`) or, without one, the Apache or IIS log.

When it cannot go on, whoever opens the address sees a page with the look of the sign-in screen, in the browser's language (Spanish or English). The page says what happened and what the administrator must do, and carries the error code. The cases are:

- data folder not writable;
- PHP not supported or without OpenSSL;
- HTTPS required;
- IP not allowed;
- internal error, out of memory included.

The interface's own requests get `{"error": key}` and show the same reason with "Try again".

Even if the data folder were ever served, the server key is stored in `data/secret.php`, which starts with `<?php exit; ?>`: PHP shows nothing. RDP passwords are encrypted with a key that only opens with each user's password, and login passwords are bcrypt hashes.

## Web server notes

The session stream must not be buffered or compressed.

- **Apache + mod_php / LiteSpeed**: `public/.htaccess` disables compression for `rdp.php`.
- **Apache + PHP-FPM (`mod_proxy_fcgi`)**: add `flushpackets=on` to the `ProxyPass`/handler configuration.
- **Nginx + PHP-FPM**:

  ```nginx
  location = /rdp.php {
      include fastcgi_params;
      fastcgi_param SCRIPT_FILENAME $document_root/rdp.php;
      fastcgi_pass unix:/run/php/php-fpm.sock;
      fastcgi_buffering off;
      fastcgi_read_timeout 24h;
      gzip off;
  }
  client_max_body_size 8m;
  ```

  This assumes `root` points to `public/`; with the whole project under `/mudesk/`, use `location = /mudesk/public/rdp.php` and `$document_root/mudesk/public/rdp.php`, and deny the private folders as in *Protecting the private folders* above. `rdp.php` also sends `X-Accel-Buffering: no`.
- **IIS** (not tested yet): the PHP FastCGI handler must not buffer the stream (`responseBufferLimit="0"`) and the FastCGI application's `requestTimeout` and `activityTimeout` must allow long requests.
- **Reverse proxy / CDN** (Cloudflare and similar): long requests are cut (≈100 s on Cloudflare). The client reconnects by itself, but a direct connection is better. Behind your own proxy, list its IP in `trusted_proxies`. A proxy on the same machine (127.0.0.1) must also send `X-Forwarded-Proto: https`: once listed it is no longer taken as a local request, so HTTPS is required through it as from anywhere else (before, requests through it skipped the HTTPS check).
- **PHP limits**: the stream calls `set_time_limit(0)`; check that the web server or FPM (`request_terminate_timeout`) does not kill long requests. Downloads and ZIPs of the shared folder (`files.php`, up to 4 GB) are long requests too.
- **Installable app**: the manifest (`manifest.webmanifest`) must be served as `application/manifest+json`. Apache and LiteSpeed: `public/.htaccess` does it; IIS: `public/web.config`. Nginx: if its `mime.types` does not know `.webmanifest`, add `application/manifest+json webmanifest;` to it.
- **Scripts and styles after an update**: the page asks for `app.js` and `app.css` with a fingerprint of the page's files in their address (it changes with any of them), but the modules they import are addressed without it, so the web server must have them revalidated on every load (a 304 when unchanged). Apache and LiteSpeed: `public/.htaccess` does it (with `mod_headers`; without it the rule is skipped); IIS: `public/web.config` and `public/assets/web.config` (not tested on a real IIS yet); Nginx: add `location ~* \.(js|css)$ { add_header Cache-Control "no-cache"; }` to the `server` block. A page left open while MuDesk is updated says so on its next sign-in and offers to reload.

## Capacity

- Each open session keeps **one PHP process** busy for its whole duration (a php-fpm child or an Apache process). A remote computer that does not answer holds it up to 8 s per handshake step.
- While typing or moving the mouse, a session adds up to **four short requests at a time**; while a remote program records from the microphone, about ten a second (pieces of 100 ms, within those four).
- Set *Administration → Settings → RDP sessions at once (all users)* (`max_sessions_total`, 20 by default, 0 = no limit) below the PHP processes available: with PHP-FPM, `pm.max_children` ≥ `max_sessions_total` + what the web needs (2 at the very least, as `tools/check.php` counts; about 10 to be comfortable). At the limit a new session gets "server busy" at once, without connecting. `tools/check.php` measures the processes at once: `max_sessions_total` N − 2 at most.
- If you can, serve `rdp.php` from a PHP-FPM pool of its own (its own socket in the `location = /rdp.php` block above), so long sessions never take the processes of the web.
- PHP's built-in server (`php -S`) handles one request at a time: one session blocks everything. Only for tests; `PHP_CLI_SERVER_WORKERS` (several workers) is not supported on Windows.
- Hostings with an "entry processes" limit (for example CloudLinux) must allow at least `5 × concurrent sessions`.
- Bandwidth from the web server to the browser: from a few KB/s (office work) to 2–3 MB/s (full-screen changes at 16 bits). From the browser to the web server, the input is a few KB/s; the microphone adds about 176 KB/s while a remote program records.

## Host names (DNS)

Names of remote computers are resolved with the system resolver, so the `hosts` file, search domains and Windows name resolution keep working; MuDeskClient has no resolver of its own. A DNS server that does not answer holds the PHP process until the system resolver gives up (its own time-out). The session is admitted before the name is resolved, so `max_sessions_total` also bounds how many processes can wait for DNS at once. A name that does not resolve is refused as a target not allowed.

## Restricted hostings

- On Windows the session's input goes through a TCP port of this computer only (127.0.0.1, with a secret of the session). With `stream_socket_server` disabled the gateway uses a queue file polled every 20 ms by itself (each key may wait up to 20 ms); `'ipc' => 'file'` in `config.php` forces it.
- Outgoing port 3389 blocked: ask the provider or use another port on the remote side (configurable per connection).

## Remote computers

- Open the RDP port only to the IP address of the web server (for example in your firewall: NAT 3389 → computer, source = hosting IP).
- Keep NLA enabled (default on current Windows versions).
- For the **MuDeskPDF** printer the remote Windows needs the "Microsoft Print To PDF" driver (Windows 10 / Server 2016 and later) and printer redirection allowed by policy.
- For several monitors on Windows workstations, multi-monitor RDP must be allowed (Pro/Enterprise editions; it is by default).
- For a **RemoteApp** connection (a program on its own), the remote computer must allow that program: on Windows Server, publish it as a RemoteApp program of Remote Desktop Services (its alias goes in the connection as it is, `Alias`: MuDesk adds the `||` before it); on Windows 10/11 Pro, list it under `HKLM\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Terminal Server\TSAppAllowList\Applications`, or allow any program for users who can already sign in by Remote Desktop with the value `fDisabledAllowList` (DWORD) = 1 in `TSAppAllowList`. Windows Home does not accept Remote Desktop connections.
- **Sound on Windows Server**: its *Windows Audio* service is off by default, so a session has no audio device and sends no sound, whatever the browser has (the gateway's trace then shows the `rdpsnd` channel joined and nothing on it). On the server, as administrator: `Set-Service Audiosrv -StartupType Automatic; Start-Service Audiosrv` (and the same for `AudioEndpointBuilder` if it is stopped); the policy *Allow audio and video playback redirection* (Remote Desktop Session Host → Device and Resource Redirection) must not be disabled. Then sign out of the remote session (not just disconnect) and connect again with **Remote computer audio** ticked.
- **Microphone** (the box **This computer's microphone on the remote one**, off by default): the remote Windows must allow audio recording redirection. Windows 7 and later and Windows Server 2012 and later allow it unless a policy forbids it; check it, or turn it on, as an administrator on the remote computer:
  1. Run `gpedit.msc` and go to *Computer Configuration → Administrative Templates → Windows Components → Remote Desktop Services → Remote Desktop Session Host → Device and Resource Redirection*.
  2. Open **Allow audio recording redirection**, choose *Enabled* and press OK (*Disabled* forbids it; *Not configured* leaves the Windows default).
  3. Run `gpupdate /force`, then sign out of the remote session (disconnecting is not enough) and connect again with the box ticked.

  Without the policy editor (Windows Home has no Remote Desktop server anyway), the same as a registry value: `reg add "HKLM\SOFTWARE\Policies\Microsoft\Windows NT\Terminal Services" /v fDisableAudioCapture /t REG_DWORD /d 0 /f` (0 allows it, 1 forbids it). On **Windows Server** the *Windows Audio* service must be running as well (see the sound point above): without it the session has no microphone either. Once allowed, the session shows a recording device called *Remote Audio*; Windows opens the microphone only while a program there records (a call, a dictation, *Settings → Sound → Remote Audio → Test your microphone*), and the browser asks once whether this page may use it. Browsers only allow the microphone on pages opened with `https://` (or on the computer of the server itself, `http://localhost`). The audio goes to Windows as it is recorded, 16-bit PCM in the format Windows asks for (44.1 kHz stereo on Windows 11: about 176 KB/s, 1.4 Mbit/s, upwards while a program records; nothing otherwise).
- **H.264 with full colour (AVC444)**: Windows sends H.264 in its 4:2:0 form (AVC420: colour at half resolution, which blurs the coloured edges of small text a little) unless a policy asks it to prefer AVC444. To turn it on, on the remote computer as an administrator:
  1. Run `gpedit.msc` (Windows Pro, Enterprise, Education and Server).
  2. Go to *Computer Configuration → Administrative Templates → Windows Components → Remote Desktop Services → Remote Desktop Session Host → Remote Session Environment*.
  3. Open **Prioritize H.264/AVC 444 graphics mode for Remote Desktop Connections**, choose *Enabled* and press OK. On the same page, **Configure H.264/AVC hardware encoding for Remote Desktop Connections** set to *Enabled* lets the server's graphics card encode it, when it can.
  4. Run `gpupdate /force`, then sign out of the remote session (disconnecting is not enough) and connect again.

  Without the policy editor, the same as a registry value: `reg add "HKLM\SOFTWARE\Policies\Microsoft\Windows NT\Terminal Services" /v AVC444ModePreferred /t REG_DWORD /d 1 /f`. **Today MuDeskClient shows AVC444 from its 4:2:0 part**, with the same quality as AVC420: full colour is the next step of the [roadmap](ROADMAP.md), and this policy is what it will need. The connection's graphics must be *Automatic* or *Graphics pipeline and H.264*.
