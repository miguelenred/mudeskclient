# Hosting and deployment

[Español](es/HOSTING.md)

This is the technical reference. For a step-by-step guide for every level (a hosting, Windows with XAMPP, Linux with Apache, firewalls, common problems), see the [installation manual](INSTALL.md).

## Before deploying: `tools/check.php`

The first run makes most of these checks by itself, before the administrator is created ([INSTALL](INSTALL.md), first run). `tools/check.php` is for checking a hosting before installing, or again later:

1. Edit `TOKEN` at the top of `tools/check.php`: 16 characters or more (with `CAMBIAR`, as it comes, or a shorter one, it does nothing: it has no limit of tries).
2. Copy it into `public/` on the hosting (the `tools/` folder is denied to the web) and open it in the browser. It works for a day after it was copied (or changed): then it refuses, in case it was forgotten there; copy it again to use it again.
3. Run the six tests:
   - **PHP environment**: version, OpenSSL, disabled functions, limits, Unix sockets. It warns about PHP outside 8.1–8.5, `display_errors` on and `memory_limit` below 128M.
   - **Outgoing RDP**: connects to a real remote computer and reports TLS/NLA, TLS version and certificate. Not to this server itself, link-local addresses or cloud metadata, which the application does not reach either; each test is written in PHP's error log with the address that asked for it.
   - **Long request**: keep it running 600 s; it must reach the end and the buffering delay must stay low.
   - **Latency**: round trip to the hosting.
   - **Data folder reachable from the web**: writes a test file in the data folder and tries to download it through the web; it must fail.
   - **PHP processes at once**: sends requests of 1 s at once (20 by default) and counts how many the server handled together (N); `max_sessions_total` must be N − 2 at most (see *Capacity*); where the server refuses the ones past its limit (508: an "entry processes" limit; 503, 429), a fifth of N at most, since each session needs up to 5 at once. Over HTTP/1.1 the browser opens 6 connections at most, so it cannot measure more.
4. **Delete the file** afterwards. While `public/check.php` is there, the administration says so in red.

## Requirements

| Item | Needed |
|---|---|
| PHP | 8.1, 8.2, 8.3, 8.4 or 8.5 |
| Extensions | `openssl`, `session` (both bundled and enabled in practically every build) |
| Functions | `stream_socket_client`, `stream_socket_enable_crypto`, `stream_select`, `set_time_limit`, `ignore_user_abort`; `stream_socket_server` recommended |
| OPcache | recommended (PHP's code cache, `opcache.enable=1`): each short request of input and frame acknowledgement then takes PHP about 5 ms instead of 15 (measured on Windows with PHP's built-in server), which every keystroke and mouse move waits for |
| HTTPS | required (a self-signed certificate works after accepting it once in the browser) |
| Outgoing TCP | from the web server to the RDP port of each remote computer |
| Optional | outgoing HTTPS to the OpenID Connect provider, with its certificate checked (`openssl.cafile` on Windows); the RDP port of the computer that checks Windows accounts; the HTTPS port of a Remote Desktop Gateway (443 by default) for the connections that go through one, instead of their computers' RDP port |

## File layout

Two layouts work. Only `public/` has to be reachable from the web.

**A. Whole project inside the web root** (the usual one on shared hosting):

```
/home/account/public_html/mudesk/            whole project; open https://your-domain/mudesk/
/home/account/public_html/mudesk/data        writable by PHP
```

The project's `.htaccess` files (and `web.config` for IIS) keep everything except `public/` out of reach: the root one sends every request to `public/`, and `data/`, `src/`, `tests/`, `tools/` and `docs/` each deny all access and listings (Apache 2.2 and 2.4). If the `.htaccess` of `data/` is deleted, the application writes it again. Check after uploading (each must answer **403**): `/mudesk/data/web-check.txt`, `/mudesk/config.php`, `/mudesk/src/bootstrap.php`. When the server ignores `.htaccess` (`AllowOverride None`), the data folder can be downloaded, and the application stops: every page says so ("The data folder can be downloaded") until it is protected.

**B. Only `public/` in the web root** (safest when the hosting allows it):

```
/home/account/mudesk/src
/home/account/mudesk/data          writable by PHP
/home/account/mudesk/config.php    optional
/home/account/public_html          <- contents of public/
```

If `public_html` is not next to `src/`, as here, change the first `require` of `index.php`, `api.php`, `rdp.php`, `files.php`, `oidc.php`, `backup.php` and `health.php` (here to `require __DIR__ . '/../mudesk/src/bootstrap.php';`).

### Protecting the private folders, per web server

Only `public/` may be served. Check after deploying: `data/web-check.txt`, `config.php` and `src/bootstrap.php` must answer **403** (or 404), never 200. The application checks the data folder by itself: while it can be read from the web, nothing works and every page says so ("The data folder can be downloaded"), until it is protected (checked again every 30 s meanwhile, or 5 minutes after a browser found it). The server asks itself for it at the address and port the request came to, and again where it found it readable (a request with another site's name or another path never finds it protected alone); where it cannot (PHP's built-in server, a hosting that does not let it reach itself) the browsers check it, proving it with a random code of the file, new each time it is looked at (every 10 minutes on PHP's built-in server).

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
- **IIS**: the root `web.config` hides `data`, `src`, `tests`, `tools`, `docs`, `config.php`, `config.sample.php`, `LICENSE`, `NOTICE` and `.md`/`.json`/`.lock`/`.log` files with *request filtering*, and each private folder has a `web.config` that refuses every file (the application writes it again in `data/` if it is missing). Only core IIS features are used, no URL Rewrite module. Open the application at `.../public/` or point the site to `public/`. Tested on Windows Server 2022's IIS (its PHP handler must not buffer: see *Web server notes* below).

### Write permissions

| Folder / file | PHP needs | Why |
|---|---|---|
| `data/` and everything inside | read + write | users, settings, connections, audit, sessions, shared files, run-time files; PHP creates `audit/`, `connections/`, `files/`, `hosts/`, `recordings/`, `run/`, `sessions/`, `tmp/`, `trace/`, `.htaccess`, `web.config`, `index.html`, `web-check.txt`, `secret.php`, `audit-key.php` (key of the signed audit exports) and, on first run, `setup-code.php` |
| PHP's temporary folder (`upload_tmp_dir` in php.ini or, when it is not set, the system's: `sys_temp_dir`, `TMPDIR`/`TMP`, `/tmp`) | write | outside the project, and already writable on hostings, XAMPP (`C:\xampp\tmp`) and Linux. PHP keeps there anything over 16 KB sent to the server (uploaded files, long pastes) while it arrives, and drops it when it cannot write there. On Linux and macOS the sockets of the sessions also go there when the path of `data/run/` reaches 100 characters |
| `public/`, `src/`, `config.php`, `.htaccess` files | read only | code and configuration: nothing is written there |

Folders are created with mode `0700` and files with `0600` when the hosting allows it. If the hosting runs PHP as another user than the FTP account, give that user write access to `data/` only.

On **Windows** (XAMPP, IIS) the account that runs the web server needs the *Modify* permission on `data` (and on PHP's temporary folder, when it was taken away). That account is the one of the Apache service or of the IIS application pool (Services → the service → *Log On*), or your own account when Apache is started from the XAMPP panel. Task Manager → Details shows it in the *User name* column of `httpd.exe` or `w3wp.exe`. From a console run as administrator:

```
icacls "C:\xampp\htdocs\mudesk\data" /grant "ACCOUNT:(OI)(CI)M"
```

When `data/` is not writable, the application shows the page "Cannot save to the data folder". The server error log says which folder could not be created. When PHP's temporary folder is not writable, a request with more than 16 KB is answered "PHP cannot write to its temporary folder" (an upload stops with that message) instead of being taken as empty, and the error log names the folder.

### Errors

The application never shows PHP's own messages, server paths or traces to the browser, even with `display_errors` on (XAMPP ships it on). Warnings, exceptions and fatal errors go to the server error log: php.ini's `error_log` (in XAMPP, `php\logs\php_error_log`) or, without one, the Apache or IIS log. The one exception is what PHP prints at the start of a request, before the application runs, when `display_startup_errors` is on (XAMPP ships it on, and PHP 8 turns it on when php.ini does not set it): for example, that it could not keep a request body because its temporary folder is not writable, or that a request is larger than `post_max_size` (the page sends files, clipboard files and backups in pieces of 2 MB). Turn it off on a server that other people use.

When it cannot go on, whoever opens the address sees a page with the look of the sign-in screen, in the browser's language (Spanish or English). The page says what happened and what the administrator must do, and carries the error code. The cases are:

- data folder not writable;
- PHP's temporary folder not writable;
- PHP not supported or without OpenSSL;
- HTTPS required;
- IP not allowed;
- the data folder can be downloaded (503, until it is protected);
- a backup being restored (503, a few seconds);
- `trusted_proxies` with an entry that is not an IP address (a range or a name);
- internal error, out of memory included.

The interface's own requests get `{"error": key}` and show the same reason with "Try again".

Even if the data folder were ever served, the server key is stored in `data/secret.php`, which starts with `<?php exit; ?>`: PHP shows nothing. RDP passwords are encrypted with a key that only opens with each user's password, and login passwords are bcrypt hashes.

The server key is made the first time it is needed, whole or not at all (written aside, then given its name). A `secret.php` found empty or cut (an earlier version could leave it so when PHP was stopped while writing it, or the disk was full) is set aside as `secret.php.damaged-<date>` and made again, said in PHP's error log, as long as nothing kept needs it: no audit lines, recordings, two-step codes or OIDC secret. If something does, every page shows "Something went wrong" and the error log says `Data file secret.php damaged (needed by …): restore it from a backup`: put back `secret.php` from a backup of this installation (`data/` as it was, or the backup's own copy). Without one, moving the damaged file away makes a new key, and then two-step codes must be set up again, earlier recordings cannot be played, the OIDC secret must be typed again and the audit's earlier lines show as changed. `audit-key.php` (the key of the signed exports) found so is always made again.

### Temporary files

The application removes its own temporary files: a session's files when it ends, clipboard files and print jobs with their session. What a write cut off or a PHP process killed by the hosting leaves (a write never finished, `*.tmp` of over an hour; the files, folder and socket of a session that ended without its cleanup; expired web sessions, also where the hosting's PHP never collects them; the locks of deleted users; after a day, the part of a file an upload was replacing, left half way) is removed once an hour at most, with the requests (there is no background process). The `*.lock` files stay: they are the locks themselves (empty, one per data file and per user).

## Web server notes

The session stream must not be buffered or compressed.

- **Apache + mod_php / LiteSpeed**: `public/.htaccess` disables compression for `rdp.php`. In Apache's virtual host, `LimitRequestBody 8388608` refuses a body over 8 MB before PHP is given it (no request of MuDeskClient sends more than 4 MB; the Docker image has it), as `client_max_body_size` below does on Nginx.
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
- **IIS** (tested on Windows Server 2022 with PHP through FastCGI): the PHP FastCGI handler must not buffer the stream (`responseBufferLimit="0"` on the handler: with IIS's own buffer every session stalls, and the first run's long-request check says so) and the FastCGI application's `requestTimeout` and `activityTimeout` must allow long requests. The project's `web.config` files keep its private folders out of reach.
- **Reverse proxy / CDN** (Cloudflare and similar): long requests are cut (≈100 s on Cloudflare). The client reconnects by itself, but a direct connection is better. Behind your own proxy, list its IP in `trusted_proxies`: only the exact addresses of your own proxies, never a range or an address shared with clients that connect directly (whoever sends from there could pass any address in `X-Forwarded-For` and get past the allowed clients and the sign-in locks). A proxy that does not pass the site's public name on (its `Host`): set the site's address in `public_url` of `config.php` (for example `https://rdp.example.com/mudesk`), which signing in with OpenID Connect and passkeys then use. A proxy on the same machine (127.0.0.1) must also send `X-Forwarded-Proto: https`: once listed it is no longer taken as a local request, so HTTPS is required through it as from anywhere else (before, requests through it skipped the HTTPS check). While requests come from this same machine with an `X-Forwarded-For` and it is not listed, the administration says so in a banner.
- **PHP limits**: the stream calls `set_time_limit(0)`; check that the web server or FPM (`request_terminate_timeout`) does not kill long requests. Downloads and ZIPs of the shared folder (`files.php`, up to 4 GB) are long requests too.
- **Installable app**: the manifest (`manifest.webmanifest`) must be served as `application/manifest+json`. Apache and LiteSpeed: `public/.htaccess` does it; IIS: `public/web.config`. Nginx: if its `mime.types` does not know `.webmanifest`, add `application/manifest+json webmanifest;` to it.
- **Scripts and styles after an update**: the page asks for `app.js` and `app.css` with a fingerprint of the page's files in their address (it changes with any of them), but the modules they import are addressed without it, so the web server must have them revalidated on every load (a 304 when unchanged). Apache and LiteSpeed: `public/.htaccess` does it (with `mod_headers`; without it the rule is skipped); IIS: `public/web.config` and `public/assets/web.config` (tested on Windows Server 2022); Nginx: add `location ~* \.(js|css)$ { add_header Cache-Control "no-cache"; }` to the `server` block. A page left open while MuDesk is updated says so on its next sign-in and offers to reload.

## WebSocket (optional)

On a server of your own (Linux) a session's stream and its keyboard and mouse can go through one WebSocket, to a small service of MuDeskClient's own, instead of HTTP requests. It is optional: without it everything works the same (shared hostings, Windows, IIS: nothing to set up). The page tries it once when it opens and uses it only when it answers, else HTTP as always; `'websocket' => false` in `config.php` turns it off (the page does not try it and the service ends at once). The first run's checks say whether it answered.

What it brings when it is there:

- **Less delay for the keyboard and the mouse**: each message goes on the session's open connection, in order, without a request of its own (no HTTP round trip, no PHP process started for it).
- **Fewer web server processes**: a session holds its connection (with Apache, the process that passes it on) and its own PHP process, and nothing more while typing; over HTTP, up to four more requests at a time while typing or moving the mouse (*Processes and limits*, below). It matters most where processes at once are what runs out.
- **Less CPU and traffic** per key and mouse move: a few bytes of WebSocket frame instead of a request's headers and a PHP start with its checks (the session's token is checked once per connection).
- **The end noticed at once** when the browser goes (its socket closes), without waiting for the next write.

What it needs:

- Linux and PHP's command line (the same version and `php.ini` settings as the web's; its sessions get the service's `memory_limit`, or 256M when it has none, the command line's default: `php -d memory_limit=512M tools/ws-server.php` gives them more). With PHP's `pcntl` and `posix` (Debian's and Ubuntu's command line has them, and so does the Docker image) each session is a copy of the service, which compiled the code once: about 5 to 8 MB less for each session than a PHP started for it, which is what it does without them. The service runs as the web server's user (it reads and writes the same data folder) and listens on 127.0.0.1 only (`websocket_listen` in `config.php`, `127.0.0.1:8091` by default).
- **Docker** and **`deploy/lxc/install.sh`** set it up: the image starts it next to Apache (also in the Kubernetes chart, whose ingress-nginx passes WebSockets on as they come); the script makes it the systemd service `mudesk-ws`.
- On another server, start `php tools/ws-server.php` as a service, as the web server's user; with systemd, for example:

  ```ini
  [Unit]
  Description=MuDeskClient WebSocket service
  After=network.target apache2.service
  JoinsNamespaceOf=apache2.service

  [Service]
  User=www-data
  Group=www-data
  ExecStart=/usr/bin/php /var/www/mudesk/tools/ws-server.php
  Restart=on-failure
  RestartSec=5
  KillMode=mixed
  TimeoutStopSec=30
  SuccessExitStatus=143
  PrivateTmp=true
  NoNewPrivileges=true

  [Install]
  WantedBy=multi-user.target
  ```

  `KillMode=mixed`: stopping it stops the service alone, and its sessions then end as when their browser goes (audited, their files removed). `JoinsNamespaceOf` and `PrivateTmp` give it Apache's temporary folder, which Debian's and Ubuntu's Apache keeps to itself: a session's socket goes there when the data folder's path is too long for one. With another web server, name its PHP service there, or leave both lines out when that service has no `PrivateTmp`. Then pass `<public>/ws` to it (the page's own address plus `ws`: `/ws` when `public/` is the site's root, `/mudesk/public/ws` with the whole project under `/mudesk/`). Apache, with `proxy`, `proxy_http` and `proxy_wstunnel` (`a2enmod proxy proxy_http proxy_wstunnel`):

  ```apache
  <Location /ws>
      ProxyPass ws://127.0.0.1:8091/ws
      ProxyPreserveHost On
      RequestHeader set X-Forwarded-Proto https
  </Location>
  ```

  Nginx:

  ```nginx
  location = /ws {
      proxy_pass http://127.0.0.1:8091;
      proxy_http_version 1.1;
      proxy_set_header Upgrade $http_upgrade;
      proxy_set_header Connection "upgrade";
      proxy_set_header Host $http_host;
      proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
      proxy_set_header X-Forwarded-Proto $scheme;
      proxy_read_timeout 24h;
      proxy_send_timeout 24h;
  }
  ```

- The site's `Host` must reach the service unchanged (`ProxyPreserveHost On`, `$http_host`): it takes only connections from the site's own pages (their `Origin`, against that scheme and `Host`). It takes the client's address from the last `X-Forwarded-For` hop, the one the proxy in front of it on this machine added (and the hops before it as `trusted_proxies` says, as over HTTP), so the allowed clients and the sign-in locks see the same address.
- A connection holds the web server's connection for the whole session, as the stream does over HTTP: the proxy's timeouts must allow hours (a keep-alive frame goes every 10 s, which resets most idle timers).
- It takes 240 connections at once, 60 of them from one client address (an office behind one address: past them, its sessions go over HTTP), and starts a session only for a browser whose MuDeskClient cookie names a web session the server has: another connection is closed before anything is started for it.

## Capacity

### Sessions by hardware

Measured on the Windows test computer (PHP 8.3's built-in server, one session through the graphics pipeline with H.264, 1024 × 768): the PHP process that holds the session took 17 MB (13 MB of its own, against 7–9 MB for a PHP process with nothing open) and no CPU while the desktop was still; with text scrolling without pause, 2.7 % of one core at about 100 KB/s. Relaying 34 MB of full-screen changes took 0.19 s of CPU in 14 s. A session through the graphics pipeline (Windows 8.1 and later, in *Automatic* mode) costs little CPU: what sets how many fit is memory (one process each, larger inside an Apache process), the processes the server allows at once, and bandwidth. With bitmaps (xrdp, older Windows) the gateway takes more: 6–16 % of one core with scrolling text and XCRUSH, 30 % with MPPC ([PERFORMANCE](PERFORMANCE.md)), so count 3 to 10 times fewer sessions per core there.

An estimate for office use from those figures (not a load test): about 50 MB and 3 % of one core per session, plus 300 MB for the system and the web server.

| Server | Sessions at once | What runs out first |
|---|---|---|
| Shared hosting | entry processes ÷ 5, or PHP processes − 2 | the hosting's processes (`tools/check.php`) |
| 1 vCPU, 1 GB | 10–15 | memory |
| 2 vCPU, 2 GB | 25–35 | memory |
| 4 vCPU, 4 GB | 60–70 | memory; from about 50, bandwidth (100 KB/s each: 40 Mbit/s) |
| 8 vCPU, 8 GB | 120–140 | bandwidth (100 Mbit/s for about 120) |

Video or animations on a full screen take 2–3 MB/s each, which fills a 100 Mbit/s link with 4 or 5 sessions. Set `max_sessions_total` (and, in Docker, `MUDESK_WORKERS`: about 7 per session) by the row that fits, and check *Administration → Status* (the memory of the largest session, the PHP processes) with your own use.

### Calculating what you need

What each session open at once takes, as a starting point (office work; measure your own in *Administration → Status*):

| Per session | Graphics pipeline (Windows 8.1, Server 2012 R2 and later) | Bitmaps (xrdp, older Windows) |
|---|---|---|
| Memory | about 50 MB | about 50 MB |
| CPU | about 3 % of one core | about 10–30 % of one core while its screen changes |
| Bandwidth to the browser | about 100 KB/s (0.8 Mbit/s); 2–3 MB/s with video | the same or more |
| PHP processes | 1 for the whole session, up to 4 more while typing over HTTP (none with the WebSocket service) | the same |

Plus, once, about 300 MB for the system and the web server. For **N sessions at once**:

- **Memory** ≈ 300 MB + N × 50 MB, and a quarter more as a margin (large clipboard copies, recordings, peaks): the table above, read from its lower figures.
- **CPU** ≈ N × 0.03 cores through the graphics pipeline, or N × 0.2 with bitmaps (an upper estimate: not every screen changes at once); 1 vCPU at least.
- **Bandwidth** ≈ N × 0.8 Mbit/s for office work; each full-screen video adds 16–24 Mbit/s.
- **PHP processes at once** ≥ N + 10 (PHP-FPM's `pm.max_children`, the `maxInstances` of IIS's PHP FastCGI application); without the WebSocket service and with many people typing at once, up to 5 × N. With PHP inside Apache (the Docker image, `deploy/lxc/install.sh`), about 7 × N Apache processes.

For example, 40 sessions of office work through the graphics pipeline: 300 + 40 × 50 = 2,300 MB, about 2.9 GB with the margin, so 3 GB; 40 × 0.03 = 1.2 cores, so 2 vCPU; 32 Mbit/s; *RDP sessions at once* 40, `pm.max_children` 50 (more without the WebSocket service); in Docker, `MUDESK_WORKERS` 280 and a limit of 3 GB and 2 CPUs.

### Raising memory and sessions

- **MuDeskClient's own limits**: *Administration → Settings → RDP sessions at once (all users)* (`max_sessions_total`, 20 by default) and *Concurrent remote sessions per user*; for one user, *Concurrent sessions* in their editor. Raise them only as far as the server's processes and memory allow (above).
- **PHP's `memory_limit`** (`php.ini`; on a hosting, its PHP settings): the most each PHP process may take, not what it uses; 256M (the Docker image's) lets large clipboard copies through. Restart PHP-FPM or the web server after changing it. With `deploy/lxc/install.sh` it is in `/etc/php/<version>/apache2/conf.d/99-mudesk.ini` (running the script again puts its 256M back).
- **PHP-FPM**: in its pool (`/etc/php/<version>/fpm/pool.d/www.conf` on Debian and Ubuntu, where it comes with 5: some 3 sessions) set `pm.max_children` to the sessions + 10 or more, then `systemctl restart php<version>-fpm`. A child takes memory only while it runs.
- **Apache with PHP inside it (mod_php)**: `MaxRequestWorkers` and `ServerLimit`, the same number, about 7 per session (Debian's `mpm_prefork.conf` comes with 150); restart Apache. With `deploy/lxc/install.sh`: run it again with `--workers N`.
- **IIS**: in *FastCGI Settings*, the PHP application's *Max Instances* (`maxInstances`) at the sessions + 10 or more, with its `requestTimeout` and `activityTimeout` long (above).
- **Docker**: `MUDESK_WORKERS` (in `compose.yaml` under `environment:`, or `docker run -e MUDESK_WORKERS=280`) and the container's memory and CPUs (in `compose.yaml`, uncomment `deploy: resources: limits:`; or `docker run --memory 4g --cpus 2`), then `docker compose up -d`.
- **Kubernetes (the Helm chart)**: `workers` and `resources` in the values (`--set workers=280 --set resources.limits.memory=4Gi`), then `helm upgrade`.
- **Proxmox VE (LXC)**: `create-lxc.sh --memory 4096 --cores 2` for a new container; for one made already, on the host: `pct set <ID> --memory 4096 --cores 2` (at once, without restarting it).
- **Shared hosting**: the plan's PHP processes or entry processes, and its memory, are the ceiling (`tools/check.php` measures them); more sessions need a larger plan.
- **WebSocket service**: 240 connections at once, 60 of them from one client address; past them, sessions go over HTTP and need the processes above. Its sessions get the service's `memory_limit`: start it as `php -d memory_limit=512M tools/ws-server.php` to give them more.

### Processes and limits

- Each sign-in attempt takes 1 s, whatever its answer (so its time says nothing about the account; one that works, a little more on a slow server, where opening the user's data key takes longer): many at once hold that many PHP processes for that second. The locks per address, per user and in all count failures; a locked address is refused at once, without writing anything more (its lock says nothing about the users).
- Each open session keeps **one PHP process** busy for its whole duration (a php-fpm child or an Apache process). A remote computer that does not answer holds it up to 8 s per handshake step.
- While typing or moving the mouse, a session adds up to **four short requests at a time** (none through the WebSocket service, above); while a remote program records from the microphone, about ten a second (pieces of 100 ms, within those four); while a program there uses the camera or this computer's folder, or the session is recorded in the browser, up to two more at a time on a lane of their own.
- Each guest watching a shared session keeps **one more PHP process** busy while it watches (at most 5 per session).
- `memory_limit`: a channel message larger than about a sixth of what the limit leaves free (a clipboard copy of a 4K screenshot is 33 MB) is left out, not pasted, and logged, instead of ending the session with a PHP fatal error; 256M or more lets such copies through.
- Recorded sessions write to `data/recordings/` while they are recorded (some tens of MB an hour for an office desktop, 35 to 110 MB for one started in the session; much more with video), kept until deleted by hand (or the days of the settings): leave room on the disk. One started in the session also takes the user's upload while it records (10 to 30 KB/s for an office desktop, up to 0.5–6 Mbit/s with video).
- Set *Administration → Settings → RDP sessions at once (all users)* (`max_sessions_total`, 20 by default, 0 = no limit) below the PHP processes available: with PHP-FPM, `pm.max_children` ≥ `max_sessions_total` + what the web needs (2 at the very least, as `tools/check.php` counts; about 10 to be comfortable). At the limit a new session gets "server busy" at once, without connecting. `tools/check.php` measures the processes at once: `max_sessions_total` N − 2 at most.
- If you can, serve `rdp.php` from a PHP-FPM pool of its own (its own socket in the `location = /rdp.php` block above), so long sessions never take the processes of the web.
- PHP's built-in server (`php -S`) handles one request at a time: one session blocks everything. Only for tests; `PHP_CLI_SERVER_WORKERS` (several workers) is not supported on Windows.
- Hostings with an "entry processes" limit (for example CloudLinux) must allow at least `5 × concurrent sessions` (`tools/check.php` finds that limit by the requests refused). A keyboard or mouse request the hosting refuses (429, 503, 508) is sent again, up to 5 times in about 1.5 s, one at a time meanwhile: nothing typed is lost, but the session lags while it lasts (before, what it carried was lost, and a key released in it stayed pressed on the remote computer).
- Users are kept in one file (`data/users.json`), read whole when someone signs in and on each request: up to about a thousand users it takes a few milliseconds (7 ms with 1,000 on a desktop computer); 49 ms with 5,000 and 243 ms with 20,000.
- Bandwidth from the web server to the browser: from a few KB/s (office work) to 2–3 MB/s (full-screen changes at 16 bits). From the browser to the web server, the input is a few KB/s; the microphone adds about 176 KB/s while a remote program records, and the camera about 150–185 KB/s with the tests' simulated cameras (an estimated 0.5–1.5 MB/s for a real one) while a program uses it.

## Monitoring

`public/health.php` answers `{"status":"ok"}` (200) while the gateway works, or 503 with what does not: `{"status":"error","problem":...}` (`data`: its data folder cannot be written; `store`: its users or settings cannot be read), or, as every entry point, `{"error":"data_exposed"}` (its data folder can be read from the web) and `{"error":"restoring"}` (a backup being restored). It tells nothing else of the installation. The allowed clients apply: list the monitor's address when they are a list. *Administration → Status* shows the sessions, processes, memory, disk and recent problems, and the use of the last 30 days.

## Host names (DNS)

Names of remote computers are resolved with the system resolver, so the `hosts` file, search domains and Windows name resolution keep working; MuDeskClient has no resolver of its own. A DNS server that does not answer holds the PHP process until the system resolver gives up (its own time-out). The session is admitted before the name is resolved, so `max_sessions_total` also bounds how many processes can wait for DNS at once. A name that does not resolve is refused as a target not allowed.

## Restricted hostings

- On Windows the session's input goes through a TCP port of this computer only (127.0.0.1, with a secret of the session). With `stream_socket_server` disabled the gateway uses a queue file polled every 20 ms by itself (each key may wait up to 20 ms); `'ipc' => 'file'` in `config.php` forces it.
- Outgoing port 3389 blocked: ask the provider or use another port on the remote side (configurable per connection).

## Remote computers

- Open the RDP port only to the IP address of the web server (for example in your firewall: NAT 3389 → computer, source = hosting IP).
- Keep NLA enabled (default on current Windows versions).
- Keep the remote computers updated. On one that several people use at once, without the updates of January and April 2022 (CVE-2022-21893, CVE-2022-24533), another user signed in there could take what a session redirects (clipboard, drives): no client can prevent it, so turn the redirections off for the computers you do not keep updated or do not trust. The other known vulnerabilities of RDP clients, checked: [VULNERABILITIES](VULNERABILITIES.md).
- **Remote Desktop Gateway** (tested against Windows Server 2022's own with each of its three transports, through an open-source gateway, and its RPC transport also against a gateway written from the specifications, which an independent client also went through): MuDeskClient speaks to it over HTTPS with the HTTP transport over a WebSocket (Windows Server 2016 or later) or the older transport of two HTTPS connections (2012, 2012 R2), or, when the gateway has neither (2008, 2008 R2), with RPC over HTTP: two HTTPS connections to `/rpc/rpcproxy.dll`, one carrying what this server sends in the body of a request of 1 GiB (made again once half of it is sent), the other bringing what the gateway sends (made again when the gateway asks), signed in with NTLM only (no Kerberos on that transport). An HTTP error that such a gateway answers on the connection this server sends on is not read: it shows as a gateway that does not answer (after 8 s). On the other two transports it signs in with Kerberos when this server can get a ticket for it (below), else with NTLM, under the NTLM scheme or under Negotiate when that is the only one offered (a gateway that only accepts smart cards cannot be used); with *Extended Protection* on, the channel binding of its certificate goes along. The allow-list of RDP targets applies to the gateway: the computers behind it are those its resource authorization policies (RD RAP) allow the user, and they are named as the gateway knows them. The session's process passes the tunnel's bytes through a TCP port of 127.0.0.1 that it opens itself, so `stream_socket_server` must be allowed.
- **Kerberos** (Active Directory; tested against a Windows Server 2022 domain, a test realm and simulated servers): for NLA, the Remote Desktop Gateway and the sign-in with a Windows account, tried before NTLM when the domain is known by its DNS name. This server needs TCP port 88 to a domain controller, found through the DNS (`_kerberos._tcp` SRV records; only port 88, never this computer or link-local addresses) or given in `config.php` (`kerberos_kdc`); without it, NTLM is used. The sign-in to the KDC has 8 seconds in all (a silent KDC: NTLM after that); the DNS lookups use the system's resolver, with its own waits (`'kerberos_dns' => false` leaves only `kerberos_kdc`). AES keys, and RC4 ones only if allowed in *Administration → Settings* (off by default; a Windows Server 2003 domain has only RC4; see *Known limits* in [SECURITY](../SECURITY.md)). A user@domain whose suffix is not its domain's (an alternate UPN suffix) is looked for through the KDC of the connection's domain or of the computer's DNS name; a local account (no domain, `.`, or the computer's own name) never uses Kerberos. A server that refuses the ticket, or one in another domain of the forest, is signed in to with NTLM, and so is a computer reached by its address (as the one a connection broker sends the session to without a gateway). `'kerberos' => false` turns it off.
- **Connection broker** (tested against Windows Server 2022's with one session host, and its routing tokens against Windows 11; not yet sending the session to another session host): the connection's computer is the broker, with the farm's `loadbalanceinfo`; the servers it sends the session to must be allowed targets too (by their address or their name), unless the connection goes through a gateway; when one of the addresses it names cannot be reached from this server, the next one it named is tried. Without a gateway they are reached by their address first: NLA there uses Kerberos when the broker also gives the server's name, else NTLM. A password the broker encrypted for its server is given there with RDSTLS.
- **Windows XP and Server 2003** (and later Windows set to the *RDP Security Layer*: tried on Server 2008 and 2008 R2, where a computer seen with TLS is refused at first, as someone in between could be lowering it; forget its certificate in the connection if it was changed on purpose): without TLS (Server 2003's shares no cipher with OpenSSL 3; the later ones are set not to use it), MuDeskClient uses *Standard RDP Security* with them, only when the connection's security is *Automatic* (its risks in [SECURITY](../SECURITY.md)). Its encryption level is the computer's: at *Low* only what MuDeskClient sends is encrypted, so prefer *High* (policy *Set client connection encryption level*); a computer that encrypts nothing is refused.
- **Windows 7 and Server 2008 R2** without their TLS 1.2 update speak only TLS 1.0, and as first released without the secure renegotiation OpenSSL 3 requires: MuDeskClient makes TLS 1.0 itself for them (only with a computer never seen with TLS 1.2). Without the CredSSP update of May 2018 their NLA is refused unless `credssp_unpatched` is on: update them where you can.
- For the **MuDeskPDF** printer the remote Windows needs the "Microsoft Print To PDF" driver (Windows 10 / Server 2016 and later) and printer redirection allowed by policy.
- For several monitors on Windows workstations, multi-monitor RDP must be allowed (Pro/Enterprise editions; it is by default).
- For a **RemoteApp** connection (a program on its own), the remote computer must allow that program: on Windows Server, publish it as a RemoteApp program of Remote Desktop Services (its alias goes in the connection as it is, `Alias`: MuDesk adds the `||` before it); on Windows 10/11 Pro, list it under `HKLM\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Terminal Server\TSAppAllowList\Applications`, or allow any program for users who can already sign in by Remote Desktop with the value `fDisabledAllowList` (DWORD) = 1 in `TSAppAllowList`. Windows Home does not accept Remote Desktop connections.
- **Sound on Windows Server**: its *Windows Audio* service is off by default, so a session has no audio device and sends no sound, whatever the browser has (the gateway's trace then shows the `rdpsnd` channel joined and nothing on it). On the server, as administrator: `Set-Service Audiosrv -StartupType Automatic; Start-Service Audiosrv` (and the same for `AudioEndpointBuilder` if it is stopped); the policy *Allow audio and video playback redirection* (Remote Desktop Session Host → Device and Resource Redirection) must not be disabled. Then sign out of the remote session (not just disconnect) and connect again with **Remote computer audio** ticked. On Windows Server 2008 R2 and 2003 the sound is also off in the connection's own settings (*Audio and video playback*; *Audio mapping* on 2003): allow it there, or set `fDisableCam` to 0 under `HKLM\SYSTEM\CurrentControlSet\Control\Terminal Server\WinStations\RDP-Tcp`, for the connections made after it.
- **Microphone** (the box **This computer's microphone and camera on the remote one**, off by default): the remote Windows must allow audio recording redirection. Windows 7 and later and Windows Server 2012 and later allow it unless a policy forbids it; check it, or turn it on, as an administrator on the remote computer:
  1. Run `gpedit.msc` and go to *Computer Configuration → Administrative Templates → Windows Components → Remote Desktop Services → Remote Desktop Session Host → Device and Resource Redirection*.
  2. Open **Allow audio recording redirection**, choose *Enabled* and press OK (*Disabled* forbids it; *Not configured* leaves the Windows default).
  3. Run `gpupdate /force`, then sign out of the remote session (disconnecting is not enough) and connect again with the box ticked.

  Without the policy editor (Windows Home has no Remote Desktop server anyway), the same as a registry value: `reg add "HKLM\SOFTWARE\Policies\Microsoft\Windows NT\Terminal Services" /v fDisableAudioCapture /t REG_DWORD /d 0 /f` (0 allows it, 1 forbids it). On **Windows Server** the *Windows Audio* service must be running as well (see the sound point above): without it the session has no microphone either. Once allowed, the session shows a recording device called *Remote Audio*; Windows opens the microphone only while a program there records (a call, a dictation, *Settings → Sound → Remote Audio → Test your microphone*), and the browser asks once whether this page may use it. Browsers only allow the microphone on pages opened with `https://` (or on the computer of the server itself, `http://localhost`). The audio goes to Windows as it is recorded, 16-bit PCM in the format Windows asks for (44.1 kHz stereo on Windows 11: about 176 KB/s, 1.4 Mbit/s, upwards while a program records; nothing otherwise).
- **Camera** (the same box, **This computer's microphone and camera on the remote one**): Windows 10 1803 and later and Windows Server 2019 and later; Windows allows video capture redirection as it comes. If the camera ("MuDesk camera (redirected)") does not appear in the session, the policy **Do not allow video capture redirection** is enabled, in the same folder of the policy editor: set it to *Not configured* or *Disabled*, or with the registry, `reg add "HKLM\SOFTWARE\Policies\Microsoft\Windows NT\Terminal Services" /v fDisableCameraRedir /t REG_DWORD /d 0 /f` (`/d 1` forbids it). The session's programs reach it through Windows' camera service (*Windows Camera Frame Server*): if they wait forever, that service may be stuck; restarting it (or the computer) clears it.
- **A folder of this computer** (the session's *Files* panel) is one more redirected drive: it needs the connection's shared folder and drive redirection allowed by policy (*Do not allow drive redirection* not enabled), as the shared folder does.
- **H.264 with full colour (AVC444)**: Windows sends H.264 in its 4:2:0 form (AVC420: colour at half resolution, which blurs the coloured edges of small text a little) unless a policy asks it to prefer AVC444. To turn it on, on the remote computer as an administrator:
  1. Run `gpedit.msc` (Windows Pro, Enterprise, Education and Server).
  2. Go to *Computer Configuration → Administrative Templates → Windows Components → Remote Desktop Services → Remote Desktop Session Host → Remote Session Environment*.
  3. Open **Prioritize H.264/AVC 444 graphics mode for Remote Desktop Connections**, choose *Enabled* and press OK. On the same page, **Configure H.264/AVC hardware encoding for Remote Desktop Connections** set to *Enabled* lets the server's graphics card encode it, when it can.
  4. Run `gpupdate /force`, then sign out of the remote session (disconnecting is not enough) and connect again.

  Without the policy editor, the same as a registry value: `reg add "HKLM\SOFTWARE\Policies\Microsoft\Windows NT\Terminal Services" /v AVC444ModePreferred /t REG_DWORD /d 1 /f`. MuDeskClient puts the two pictures of AVC444 (v1 and v2) back together in full colour where the browser gives a decoded picture's planes (Chrome and Edge do; Firefox not tested yet); where it does not, it shows the first one (4:2:0), as before. Windows sends the second picture (the colour the first one leaves out) only when it sees fit: on the Windows 11 test computer, with the policy on and its graphics card encoding, it sent the first one only. The connection's graphics must be *Automatic* or *Graphics pipeline and H.264*.
