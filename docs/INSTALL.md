# Installation manual

[Español](es/INSTALACION.md)

This manual explains how to get MuDeskClient running **without any computer knowledge**, step by step, on a hosting or on a computer of your local network with Apache and PHP. A list of common problems is at the end.

MuDeskClient is **copy and paste**: nothing to build, no database, no programs to install. You copy the folder to the server and open it in the browser.

## Summary for those who already know

1. PHP 8.1–8.5 with `openssl` and `session`, on Apache (or LiteSpeed) with `AllowOverride All` and HTTPS.
2. Copy the whole folder into the site (for example `/mudesk/`) and let PHP write to `data/`, the only folder of the project it writes to; PHP's own temporary folder must be writable too, as it already is ([3.3](#33-permissions-of-the-data-folder)).
3. Open `https://your-site/mudesk/`, type the code of `data/setup-code.php` and create the administrator.
4. Check that `https://your-site/mudesk/data/web-check.txt` answers 403 or 404.
5. The web server must reach the RDP port of each remote computer (3389 by default): outgoing firewall of the hosting, the router's and the Windows one.

With Docker, one command and nothing else to install: [section 12](#12-option-d-docker-to-try-it-or-on-a-server-with-docker). On Kubernetes, a Helm chart: [section 13](#13-option-e-kubernetes-helm); on Proxmox VE, an LXC container made with one command: [section 14](#14-option-f-proxmox-ve-an-lxc-container).

More technical details (Nginx, IIS, PHP-FPM, capacity): [HOSTING](HOSTING.md).

## 1. What you need

**A place to install it**, one of these three:

| Option | For | Section |
|---|---|---|
| **A. A hosting** with PHP (cPanel, Plesk, DirectAdmin…) | To connect from anywhere on the Internet | [Section 3](#3-option-a-on-a-hosting) |
| **B. A Windows PC** of your local network, with XAMPP | To use it in the office or at home, without a hosting | [Section 4](#4-option-b-on-a-windows-pc-of-the-local-network-xampp) |
| **C. A Linux computer** (Ubuntu or Debian) with Apache | For a server of your own on the local network | [Section 5](#5-option-c-on-a-linux-computer-ubuntu-or-debian) |

**And the computers to connect to**: Windows 10 or 11 **Pro**, Enterprise or Education, or Windows Server, with Remote Desktop turned on (see [section 6](#6-prepare-the-windows-computers-you-will-connect-to)). Windows **Home** does not accept Remote Desktop connections.

Server requirements:

| Item | Needed |
|---|---|
| PHP | 8.1, 8.2, 8.3, 8.4 or 8.5, 64-bit |
| PHP extensions | `openssl` and `session` (enabled in almost every PHP) |
| Web server | Apache or LiteSpeed (everything works by itself with them); Nginx and IIS need a few settings: see [HOSTING](HOSTING.md) |
| HTTPS | Required. On a hosting it is usually free (Let's Encrypt); on the local network a self-signed certificate works |
| Outgoing connection | From the web server to the RDP port of each remote computer |
| OPcache | Recommended (see below) |

**OPcache** is PHP's code cache, part of PHP. With it, each keystroke, mouse move and acknowledgement of the screen costs the server about 5 ms instead of 15 (measured on Windows with PHP's built-in server). To turn it on: on a hosting, in its PHP settings (in cPanel, *Select PHP Version*, the `opcache` extension); in XAMPP, in `C:\xampp\php\php.ini`, the lines `zend_extension=opcache` and `opcache.enable=1` without a `;` in front, then restart Apache; on Ubuntu or Debian, `sudo apt install php-opcache` (it may be installed already) and `sudo systemctl restart apache2`. The Docker image has it on.

## 2. Download MuDeskClient

1. Download the MuDeskClient ZIP from [miguelenred.es/mudeskclient](https://miguelenred.es/mudeskclient) or from its GitHub page (**Code → Download ZIP**).
2. Unzip it. You get a folder named `MuDeskClient` (or `mudeskclient-main` if it comes from GitHub).
3. Rename it to **`mudesk`**. This manual uses that name; you may use another one, and then write yours instead of `mudesk` in every address.

Inside are the folders `public`, `src`, `data`, `docs`, `tests`, `tools` and some files. **Copy the whole folder**: only `public` is seen from the Internet, but the others are needed.

**With Composer** (for those who use it; it is not needed): `composer create-project miguelenred/mudeskclient mudesk` downloads the latest version into the folder `mudesk`, ready to use the same way. MuDeskClient has no dependencies: Composer only checks the PHP version (8.1 to 8.5) and the OpenSSL extension, and adds a `vendor` folder that the application does not use (the bundled rules keep it out of reach, like the other private folders). To update, follow section 9 with the files of the new version.

## 3. Option A: on a hosting

### 3.1 Check the hosting

In the hosting's control panel:

1. **PHP version**: look for *Select PHP version*, *PHP Selector* or *PHP settings* and choose **8.1 to 8.5** (8.3 recommended).
2. **HTTPS**: look for *SSL/TLS*, *Let's Encrypt* or *AutoSSL* and turn on the free certificate for your domain. Opening `https://your-domain` must show the padlock.

### 3.2 Upload the files

With the panel's **File Manager**:

1. Go into the site's public folder (usually `public_html`, `httpdocs` or `www`).
2. Upload the MuDeskClient ZIP and use **Extract**. Rename the resulting folder to `mudesk`.

Or with an FTP program (for example FileZilla): copy the whole `mudesk` folder into `public_html`.

When done it must look like this: `public_html/mudesk/public`, `public_html/mudesk/src`, `public_html/mudesk/data`…

### 3.3 Permissions of the `data` folder

Folders that need **write** permission:

| Folder | PHP needs |
|---|---|
| `mudesk/data` and everything inside it | read and write (the application creates its subfolders by itself) |
| PHP's temporary folder (`upload_tmp_dir` in php.ini, or the system's one) | write; it already has it on hostings, XAMPP (`C:\xampp\tmp`) and Linux (`/tmp`). PHP keeps there anything over 16 KB sent to the server (uploaded files, long pastes) while it arrives |
| everything else (`public`, `src`, `config.php`…) | read only |

PHP must be able to **write** to `mudesk/data`. On most hostings it already can. If opening the application shows "Cannot save to the data folder", select `data` in the File Manager, press **Permissions** and set `755` (or `775` if it still fails with `755`). Never `777`.

If uploading a file shows "PHP cannot write to its temporary folder", ask the hosting provider to fix PHP's temporary folder (the server error log names it).

### 3.4 First run

1. Open **`https://your-domain/mudesk/`** in the browser.
2. The screen asks for a **setup code**. It is in the file `mudesk/data/setup-code.php`, made at that moment. Open it with the File Manager (**View** or **Edit**; do not open it in the browser: it would show nothing). Its second line is the code, which looks like `1A2B3-C4D5E-F6A7B-8C9D0`. Copy it.
3. Type the code and press **Check the server**. MuDeskClient checks the server step by step, each check green, amber or red with what to do when it is not green: PHP's version, functions and memory, the data folder (writable, and not readable from the web), HTTPS, a proxy in front, how the keyboard and mouse reach a session; then, from your browser, a long request like a session's (12 s) and how many PHP requests the server takes at once. Optionally, type a remote computer's address and port and press **Try**: it says whether it answers, with NLA or TLS, or why not (often a firewall). Red checks can be fixed afterwards; MuDeskClient will not work well until they are.
4. Press **Continue** and type the administrator's name and a password of **at least 12 characters** (4 different ones at least; not a common password and not containing the name). When the server takes fewer requests at once than 20 sessions need, a ticked box sets *RDP sessions at once* to what it takes. The code works only once, and the file is removed when done. It works for a day: after that a new code takes its place in the same file (open it again).

The code is there so that nobody else can take over a freshly copied installation before you do.

### 3.5 Check that the data is protected

Open **`https://your-domain/mudesk/data/web-check.txt`**. It must give a **403 (Forbidden)** or **404 (Not Found)** error. If you see a text starting with "MuDeskClient data folder" instead, your hosting does not apply the `.htaccess` files and the data folder can be downloaded; the application finds it too, and does not work meanwhile (every page says "The data folder can be downloaded"). Then ask the provider to enable `AllowOverride` for your site, or use layout B of [HOSTING](HOSTING.md) (only `public` inside the web folder).

### 3.6 Check that the hosting reaches your computers

Some hostings **do not allow outgoing connections** to other ports, the Remote Desktop one among them. To check it first:

1. Open `mudesk/tools/check.php` with the File Manager's editor and change `CAMBIAR` in the line `const TOKEN = 'CAMBIAR';` to a secret of yours of 16 characters or more.
2. Copy that file into the `mudesk/public/` folder.
3. Open `https://your-domain/mudesk/public/check.php`, type your secret word in *Token* and, in the outgoing RDP test, the address of your remote computer and **its port** (3389 unless it was changed; if your computer uses another one, type that one). Run the test.
4. If it cannot connect, see [section 7, firewalls](#7-firewalls-that-must-let-it-through).
5. **Delete `public/check.php` when done.** It works for a day after it was copied, and while it is there the administration says so in red.

## 4. Option B: on a Windows PC of the local network (XAMPP)

XAMPP is a free package that installs Apache and PHP on Windows with one installer.

### 4.1 Install XAMPP

1. Download XAMPP for Windows from [apachefriends.org](https://www.apachefriends.org/) (a version with PHP 8.1 to 8.5).
2. Install it with the default options (folder `C:\xampp`). Only **Apache** and **PHP** are needed.
3. Open the **XAMPP Control Panel** and press **Start** next to **Apache**.
4. If Windows asks about the firewall, allow access on **private networks**.
5. To start Apache with Windows, tick Apache's **Service** box in the Control Panel run as administrator.

### 4.2 Copy MuDeskClient

Copy the `mudesk` folder into **`C:\xampp\htdocs`**. It must look like `C:\xampp\htdocs\mudesk\public`, `C:\xampp\htdocs\mudesk\data`…

XAMPP already has what MuDeskClient needs: `.htaccess` files applied, `mod_rewrite`, and HTTPS with a self-signed certificate.

### 4.3 First run

1. On that same PC, open **`https://localhost/mudesk/`**.
2. The browser warns that the certificate is not trusted (it is XAMPP's own certificate). Press **Advanced** and then **Continue** or **Proceed** to the site. Once per browser is enough.
3. Follow [section 3.4](#34-first-run): the setup code is in `C:\xampp\htdocs\mudesk\data\setup-code.php`; open it with Notepad.

From **other computers of the network**, open `https://PC-ADDRESS/mudesk/` (the address of the XAMPP PC is shown by the command `ipconfig`, for example `192.168.1.20`) and accept the certificate warning there too.

If `data` cannot be written ("Cannot save to the data folder"), see *Write permissions* in [HOSTING](HOSTING.md): with Apache as a service, the service's account needs the *Modify* permission on `data` and on `C:\xampp\tmp` (PHP's temporary folder in XAMPP).

## 5. Option C: on a Linux computer (Ubuntu or Debian)

Open a terminal and type these commands (it will ask for your password).

### 5.1 Install Apache and PHP

```bash
sudo apt update
sudo apt install apache2 php libapache2-mod-php unzip
sudo a2enmod rewrite headers ssl
sudo a2ensite default-ssl
```

`php -v` shows the PHP version: Ubuntu 22.04 has 8.1, Ubuntu 24.04 has 8.3 and Debian 12 has 8.2; all of them work.

### 5.2 Turn on the `.htaccess` files

Ubuntu and Debian ship with them turned off. Open the configuration file:

```bash
sudo nano /etc/apache2/apache2.conf
```

Find the `<Directory /var/www/>` block and change `AllowOverride None` to **`AllowOverride All`**. Save (Ctrl+O, Enter) and exit (Ctrl+X). Then:

```bash
sudo systemctl restart apache2
```

### 5.3 Copy MuDeskClient

With the ZIP in your home folder (one downloaded from GitHub's releases: check it first against the `SHA256SUMS` of the same release, next to it, with `sha256sum -c --ignore-missing SHA256SUMS`, which must say `OK`):

```bash
sudo unzip MuDeskClient.zip -d /var/www/html/
sudo mv /var/www/html/MuDeskClient /var/www/html/mudesk
sudo chown -R www-data:www-data /var/www/html/mudesk/data
```

If the `ufw` firewall is on, let HTTPS in: `sudo ufw allow 443/tcp`.

### 5.4 First run

Open `https://COMPUTER-ADDRESS/mudesk/` (the address is shown by `hostname -I`), accept the warning about the self-signed certificate and follow [section 3.4](#34-first-run). The code is in `/var/www/html/mudesk/data/setup-code.php`:

```bash
sudo cat /var/www/html/mudesk/data/setup-code.php
```

Optional, for less delay with the keyboard and the mouse and fewer Apache processes: MuDeskClient's WebSocket service, set up as [HOSTING](HOSTING.md#websocket-optional) says (`deploy/lxc/install.sh`, [section 14](#14-option-f-proxmox-ve-an-lxc-container), sets it up by itself).

## 6. Prepare the Windows computers you will connect to

On each computer you want to connect to:

1. **Turn on Remote Desktop**: *Settings → System → Remote Desktop → On*. Windows opens its firewall for Remote Desktop at the same time.
2. **The Windows account must have a password**: Remote Desktop does not take accounts without one.
3. **Write down its address**: its IP (command `ipconfig`, for example `192.168.1.30`) or its name.
4. **Write down the port**: **3389** unless someone changed it. If it was changed, use that computer's port in the MuDeskClient connection and in the `check.php` test.
5. **Do not turn it off or let it sleep**: a computer that is off or asleep does not answer.
6. **Only if you will use the microphone or the camera** (the box *This computer's microphone and camera on the remote one*, off by default): Windows 10 and 11 allow both as they come (the camera from Windows 10 1803 / Server 2019); on Windows Server start its *Windows Audio* service for the microphone, and if a policy forbids either, allow it. Step by step in [HOSTING › Remote computers](HOSTING.md#remote-computers). The browser only gives the microphone and the camera to pages opened with `https://`.

To connect from an **Internet hosting** to a computer of your office or home, the router there must let the connection through: see the next section.

## 7. Firewalls that must let it through

There may be several firewalls between the MuDeskClient server and each remote computer. **All** of them must let the connection to the RDP port through (3389, or the one that computer uses):

| Firewall | What to do |
|---|---|
| **The Windows one**, on the remote computer | Turning Remote Desktop on opens it. If you use another antivirus or firewall, allow Remote Desktop in it |
| **The router's**, at the office or at home (when MuDeskClient is on an Internet hosting) | Add a *port forwarding* (NAT) rule from port 3389 to the computer's address. For safety allow only the **hosting's IP address** as the source; never leave the port open to the whole Internet |
| **The company network's** | Ask whoever runs the network to let the MuDeskClient server reach the RDP port of the computers |
| **The hosting's outgoing one** | Some providers block outgoing connections to other ports. Check it with `check.php` ([section 3.6](#36-check-that-the-hosting-reaches-your-computers)) and, if it fails, ask the provider to open the way out to that port |
| **The MuDeskClient server's own** (local network) | It must let HTTPS **in** (port 443) from the computers that use it: XAMPP asks when Apache starts; on Linux, `sudo ufw allow 443/tcp` |

When a connection fails with "Cannot reach the remote computer", it is almost always one of these firewalls, the address or the port.

## 8. First connection

1. Sign in to MuDeskClient as the administrator.
2. **Connections → New connection**: a name, the computer's address, its **port** and the Windows user. Save.
3. Press **Connect** and type the Windows password. The first time, the computer's certificate fingerprints are shown ("First connection to this computer"): compare them with the computer's and press **Trust and connect** (an administrator's box *Valid for every user* fixes it for everyone).

For more users: *Administration → Users → New user*. The **Administrator rights** box makes the user an administrator; there can be several. The full guide is [USAGE](USAGE.md).

## 9. Update to a new version

1. Back up the `data` folder (see below).
2. Copy the files of the new version over the old ones, **without deleting the `data` folder** or your `config.php` if you have one.
3. Open pages say that there is a new version and offer to reload.

## 10. Backups

Everything of yours is in the **`data`** folder: users, connections, audit, shared files and the server's key (`data/secret.php`). *Administration → Backup* downloads it encrypted with a password you choose (the users' folders and recordings when ticked) and restores it; or copy the folder whole. Do it regularly, to a safe place. **Without `secret.php`** the codes of authenticator apps stop working and the audit cannot be verified. Keep that copy, and the backup's password, confidential.

## 11. Common problems

| What you see | What to do |
|---|---|
| "Cannot save to the data folder" | Let PHP write to `data` ([3.3](#33-permissions-of-the-data-folder), or [HOSTING](HOSTING.md) on Windows) |
| "PHP cannot write to its temporary folder" | Let PHP write to its temporary folder ([3.3](#33-permissions-of-the-data-folder)); on a hosting, ask the provider |
| "A secure connection is required" | Open the address with `https://`. On a hosting, turn on the free certificate; on the local network, XAMPP and Linux already have one |
| "PHP version not supported" | Choose PHP 8.1 to 8.5 in the hosting's panel |
| "The data folder can be downloaded", and nothing works | Your server does not apply `.htaccess`: [3.5](#35-check-that-the-data-is-protected) |
| "Wrong setup code." | Copy the whole second line of `data/setup-code.php`, with its hyphens |
| "Cannot reach the remote computer" | Computer on, address, **port** and [firewalls](#7-firewalls-that-must-let-it-through) |
| "Wrong remote username or password." | The **Windows** user and password of the remote computer (not the MuDeskClient ones). For a local account, type `.\user`; for a domain account, `DOMAIN\user` (a domain that blocks NTLM needs Kerberos: the domain by its DNS name; README, *Limitations*) |
| "The server only supports legacy RDP security (Windows XP, Server 2003)…" | Set the connection's *Security* to *Automatic* (in *Advanced options*); its risks are in [SECURITY](../SECURITY.md) |
| "The server does not encrypt the connection…" | The computer is set to encrypt nothing, or someone on the network poses as it: check its encryption level ([HOSTING](HOSTING.md#remote-computers)) and the network in between |
| "The computer uses a version of CredSSP without the 2018 security update…" | Update Windows on that computer (Windows 7 and Server 2008 R2 without the updates of May 2018). While it cannot be, an administrator can allow it with `'credssp_unpatched' => true` in `config.php`, at the risk [SECURITY](../SECURITY.md) explains |
| "The server is busy: too many sessions are open" | The limit of sessions at once was reached: try later, or raise it in *Administration → Settings* if the hosting allows ([HOSTING](HOSTING.md), *Capacity*) |
| The session drops every minute and a half and connects again | A proxy in front (for example Cloudflare) cuts long connections: use a direct connection |
| Blank page or error 500 | Look at the hosting's PHP error log (on XAMPP, `C:\xampp\php\logs\php_error_log`) |

If none of this solves it, the *Diagnostic mode* of the connect window saves a technical trace of the session that the administrator can download ([USAGE](USAGE.md)).

## 12. Option D: Docker (to try it, or on a server with Docker)

The image `ghcr.io/miguelenred/mudeskclient` (the same on Docker Hub as `miguelenred/mudeskclient`, from 1.3.0) has Apache, PHP 8.4 and MuDeskClient ready, with HTTPS. One command:

```bash
docker run -d --name mudesk --restart unless-stopped -p 8443:443 -v mudesk-data:/var/www/mudesk/data ghcr.io/miguelenred/mudeskclient
```

1. Open **`https://localhost:8443`** (or `https://SERVER-ADDRESS:8443` from another computer) and accept the certificate warning: it is a self-signed certificate made at the first start.
2. The setup code: `docker exec mudesk cat /var/www/mudesk/data/setup-code.php` (its second line).
3. Your data stays in the `mudesk-data` volume: updating is `docker pull ghcr.io/miguelenred/mudeskclient`, then removing the container (`docker rm -f mudesk`) and running the same command again.

**With Docker Compose**: the project has a `compose.yaml` (in the zip and in the repository) with the same settings: port 8443, the data in the `mudesk-data` volume, the certificate in `mudesk-tls`, started again on its own. In its folder:

```bash
docker compose up -d
```

The setup code: `docker compose exec mudesk cat /var/www/mudesk/data/setup-code.php`. `compose.yaml` names the image of its version (`ghcr.io/miguelenred/mudeskclient:1.3.0`, say), which does not change by itself. Updating: put the new version's number there, then `docker compose pull` and `docker compose up -d`. It runs the published image; to build the image from the code of that folder instead, uncomment the line `build: .` in `compose.yaml` and start it with `docker compose up -d --build`. The name for the certificate (`MUDESK_HOSTNAME`) and your own certificate are set in that file too.

Options:

- **Your own certificate**: mount a folder with `cert.pem` and `key.pem` at `/etc/mudesk/tls` (`-v /path/to/tls:/etc/mudesk/tls:ro`). Without one, `-e MUDESK_HOSTNAME=name` puts that name in the self-signed certificate (also as its subjectAltName, which browsers check). That folder is a volume of its own, so the self-signed certificate stays when the container is made again for an update (give it a name, `-v mudesk-tls:/etc/mudesk/tls`, to keep it with `docker rm`). The code is read-only for the web server (only `data/` is its to write), and OPcache is on.
- **Behind your reverse proxy** (Nginx, Caddy, Traefik): point it to `https://container:443`. Put its address in `trusted_proxies` of a `config.php` mounted at `/var/www/mudesk/config.php`, and keep long requests open (see [HOSTING](HOSTING.md)).
- **A version**: `ghcr.io/miguelenred/mudeskclient:1.3.0` instead of the latest.
- **WebSocket**: the image also runs MuDeskClient's WebSocket service behind its Apache, and the page uses it when it answers (less delay with the keyboard and the mouse, fewer Apache processes: [HOSTING](HOSTING.md#websocket-optional)); `'websocket' => false` in a mounted `config.php` turns it off. Behind your reverse proxy, let WebSockets through too (Nginx: the `Upgrade` and `Connection` headers) and keep the site's `Host`; otherwise the page stays on HTTP.
- **In production**: the image checks its own health every 30 s (`health.php` asked from inside; `docker ps` shows *healthy*, and orchestrators can use it; with a list of allowed clients, add `127.0.0.1`). `-e MUDESK_WORKERS=N` sets how many Apache processes may run at once (256 by default, from 10 to 20000: each session holds one, and up to 4 more while its keyboard and mouse are used, none with the WebSocket service; see [HOSTING](HOSTING.md), *Capacity*). `compose.yaml` rotates the container's logs (3 files of 10 MB), runs it with `no-new-privileges`, and has memory and CPU limits to uncomment and size for the sessions you expect.

The container must reach the RDP port of the remote computers; Wake-on-LAN broadcasts only leave it with `--network host` (Linux).

## 13. Option E: Kubernetes (Helm)

The chart is in `deploy/helm/mudeskclient` (in the zip and in the repository). It runs the Docker image in **one pod** (each RDP session lives in a PHP process of that pod, and the data folder is written with file locks, so it never runs two), with its data on a volume that stays when the release is uninstalled:

```bash
helm install mudesk deploy/helm/mudeskclient
kubectl port-forward svc/mudesk-mudeskclient 8443:443
kubectl exec deploy/mudesk-mudeskclient -- cat /var/www/mudesk/data/setup-code.php
```

Open `https://localhost:8443` while the port is forwarded, then read the setup code in another terminal (its second line: the page makes it when it first opens) and type it. The values (`deploy/helm/mudeskclient/values.yaml`) set:

- **How it is reached**: `service.type` `LoadBalancer` with `service.externalTrafficPolicy: Local` keeps each client's own address (the sign-in locks and the allowed clients use it). With `ingress.enabled` the chart makes an Ingress with what a session needs from ingress-nginx (HTTPS to the pod, requests open for hours, nothing buffered, bodies up to 8 MB; WebSockets pass as they are, for the image's WebSocket service); then list the ingress controller's addresses in `trusted_proxies`, or every client counts as one address.
- **Your certificate**: `tls.existingSecret`, a secret of type `kubernetes.io/tls`; without one, a self-signed certificate is made at the first start and kept on a small volume of its own.
- **`config.php`**: `config`, its whole text (see `config.sample.php`).
- **Size**: `resources`, `workers` (Apache processes at once) and `persistence.data.size` (see [HOSTING](HOSTING.md), *Capacity*).

The pod is ready while `health.php` says the gateway works (with a list of allowed clients, list the nodes' addresses: the kubelet asks from there), and alive while Apache answers, so a restore under way is never cut by a restart. It must reach the RDP port of the remote computers; Wake-on-LAN does not leave a pod's network.

## 14. Option F: Proxmox VE (an LXC container)

On the Proxmox host, as root, in an unpacked MuDeskClient (the release zip, or a clone of the repository):

```bash
bash deploy/proxmox/create-lxc.sh --hostname mudesk --storage local-lvm --bridge vmbr0
```

It makes an unprivileged Debian 12 container (2 cores, 2 GB, 8 GB of disk by default; `--cores`, `--memory`, `--disk`, `--ip 192.168.1.50/24 --gw 192.168.1.1` for a fixed address, `--id`, `--version`, `--workers`), starts it with the host, and installs MuDeskClient in it (the one it is run from; with `--version`, that release from GitHub) with `deploy/lxc/install.sh`: Apache and PHP from Debian, HTTPS with a self-signed certificate (or yours in `/etc/mudesk/tls`), only `public/` served, plain HTTP sent to HTTPS, the Docker image's Apache and PHP settings, and the WebSocket service (the systemd service `mudesk-ws`). At the end it prints the address and the setup code.

`deploy/lxc/install.sh` also works alone, as root, on any Debian 12 or later or Ubuntu 22.04 or later (another LXC container, a virtual machine, a server): `bash install.sh` downloads the latest release (`--version X.Y.Z` for another, `--from FOLDER` for an unpacked one) and checks its ZIP against the `SHA256SUMS` published with it (it stops if they differ). Run it again to update: the code is replaced, `data/` and `config.php` stay (in the container made above: `pct exec ID -- bash /root/mudesk-install.sh`).
