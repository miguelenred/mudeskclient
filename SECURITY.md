# Security policy

## Reporting a vulnerability

Please do not open a public issue. Use GitHub's **private vulnerability reporting** (Security tab → *Report a vulnerability*) with the steps to reproduce, the affected version and the impact. You will receive an answer as soon as possible.

## Supported versions

Only the latest release (1.1.0 now) receives security fixes.

## Hardening checklist

- Serve the application over HTTPS only (`force_https` is on by default).
- Prefer keeping `src/`, `data/` and `config.php` outside the web root; inside it, check that `data/web-check.txt`, `config.php` and `src/bootstrap.php` answer 403 (or 404), never 200 (see [HOSTING](docs/HOSTING.md)).
- Keep the web idle time-out short (30 minutes by default; every session ends after 12 hours, and the page says so).
- Open the RDP port of the remote computers only to the IP of the web server, and keep NLA enabled.
- Set the security of the connections to computers that have NLA to *NLA only*: *Automatic* also takes TLS alone and, for computers without TLS (Windows XP, Server 2003), *Standard RDP Security*.
- Use the allow-lists (client IPs and RDP targets) when the set of users or computers is known.
- Behind a reverse proxy, list its IP in `trusted_proxies` and have it send `X-Forwarded-Proto: https` (see [HOSTING](docs/HOSTING.md)).
- Turn on two-step verification (authenticator app or passkey) for administrator accounts; *Require 2FA for every user* makes it mandatory for all.
- Turn off saving RDP passwords (*Allow saving the password of RDP connections*) when users can type them each time.
- Leave the microphone and camera box unticked (the default) on connections that do not need them.
- Back up `data/`: it contains `secret.php`, the server key (without it TOTP seeds cannot be read, the audit log no longer verifies and trusted browsers are forgotten), and `audit-key.php`, the key of the signed audit exports.

## Known limits: what MuDeskClient does not protect against

- **The first connection to a remote computer.** RDP servers use self-signed certificates, so the gateway cannot check who a computer it has never seen is: it saves the certificate's fingerprint on the first connection and warns when it changes (trust on first use). Someone in the middle of the network between the gateway and that computer, on that first connection, can pose as it:
  - with the security *Automatic* or *TLS only*, they can answer that the computer only speaks TLS and receive the Windows password;
  - with *NLA only*, they cannot get the password (NLA binds it to the real computer's key and stops before sending it), but they get a challenge answer from which a weak password can be guessed offline.

  Choose *NLA only* where the computers have it (every current Windows), make the first connection from a network you trust, and keep the RDP port reachable only from the gateway's IP.
- **The data folder inside the web root** is protected by the `.htaccess` and `web.config` files; a web server that ignores them (Nginx without the rules of [HOSTING](docs/HOSTING.md), Apache with `AllowOverride None`) serves it. The application checks it and warns the administrator in red, but it keeps working. Prefer keeping `data/` out of the web root.
- **"Allow all" targets** let any signed-in user reach any computer and port the web server reaches, private networks included (never the gateway itself, link-local or cloud metadata addresses). Use the target list when users should only reach some computers.
- **The input token** of a running session is a secret held by its browser tab: whoever has it (a proxy that logs request headers, a malicious browser extension) can type into that session until it ends. The server keeps only its SHA-256.
- **A stolen session cookie** is taken from a browser with the same User-Agent: that check stops casual reuse, not someone who copies the header too. Short idle time-outs and two-step verification limit the damage.
- **Sign-in time**: every sign-in answer takes a fixed second, so that it never tells which user names exist, and holds a PHP process meanwhile; many attempts at once keep processes busy. A locked IP is answered at once.
- **Old TLS towards old computers.** To reach Windows 7 and Server 2008 R2 without their TLS 1.2 update, the gateway accepts TLS 1.0 and OpenSSL's security level 0 (old ciphers and short keys) on its link to the remote computer, and to a Remote Desktop Gateway (an older one, such as Windows Server 2008 R2's): that link is as strong as the computer or the gateway allows. Install the TLS 1.2 update on those computers, keep them on networks you trust, and prefer NLA (it binds the password to the computer's key). The browser's link to the gateway is HTTPS, as the web server serves it.
- **Standard RDP Security towards Windows XP and Server 2003.** These computers have no TLS (or none OpenSSL 3 shares with Server 2003), only RDP's own encryption: RC4 with keys of up to 128 bits, or triple DES on a "FIPS compliant" server, with no proof of who the server is (the key Microsoft signs its certificates with is public, and a server's own key may be as short as 512 bits). MuDeskClient uses it only when the connection's security is *Automatic*, pins the server's key as it pins a certificate (a computer that answered with TLS before and now offers only this is warned about as a changed certificate), refuses a key RSA cannot be trusted with (an exponent of 1 or an even one, a modulus under 512 bits) and records it in the audit (`rdp_security` in `rdp_start`). A server that encrypts nothing is refused before the password is sent ("The server does not encrypt the connection"); from the *Client Compatible* encryption level up, anything else the server sends in the clear (but for licensing) is refused; at the *Low* level only what the gateway sends is encrypted, and what the server sends (the screen, its clipboard) goes in the clear. Anyone who can read the network between the gateway and such a computer may break RC4 or pose as it on the first connection. Keep those computers on networks you trust, at the *High* level, or choose *TLS only* / *NLA only* for the connections that must never use it.
- **Kerberos domain controllers from the DNS.** When `config.php` names none, they are looked up in the DNS of the user's domain, which a user can type (user@domain): only port 88 is taken, and never this computer, link-local or cloud metadata addresses. The lookups use the system's resolver (`'kerberos_dns' => false` turns them off) and the whole sign-in to the KDC has 8 seconds.
- **Kerberos with RC4.** Off by default. Allowed in *Administration → Settings* (for a Windows Server 2003 domain, or an account whose password is older than its domain's AES keys; without it those accounts sign in with NTLM), someone in the middle between this server and a domain controller can make it be used, and the pre-authentication then sent lets the password be guessed offline thousands of times faster than with AES: allow it only if you need it, and reach the domain controllers over networks you trust.
- **The RPC transport of Windows Server 2008 and 2008 R2 gateways** signs each request of MuDeskClient with NTLM, but the signatures of the gateway's answers are not checked: what protects them is the TLS connection to the gateway, whose certificate is pinned as on the other transports. Its sign-in is NTLM only (no Kerberos on that transport).
- **A malicious RDP server** is treated as untrusted (every decoder checks sizes and limits), but it draws what it wants, sees what is typed into its session and copied to its clipboard, and can fill the shared folder up to the user's quota.
- **The microphone and the camera** are off by default (one box) and go only to connections where it is ticked. There, the remote computer decides when it records: once the browser has allowed the microphone for the gateway's address (browsers remember it), any program of that computer that records hears it, and any that uses the camera sees it, with no further question. The browser shows its own sign meanwhile. Tick it only for computers you trust, and remove the permissions from the browser (the icon next to the address) on shared computers.
- **A folder of this computer**, shared from the session's *Files* panel, can be read (and, in Chrome and Edge, changed, filled and emptied) by any program of the remote computer while it is shared, as a drive of the computer you connect from would be with Remote Desktop Connection; a guest given control of a shared session can use it through that session too. Nothing of it is kept on the web server, and the files read, written, created, emptied, deleted and renamed are audited (also those still open when the session ends). Share only a folder that holds what the remote computer may have, and stop sharing it when done. An administrator who does not want it turns off the connection's shared folder.

See [docs/ARCHITECTURE.md › Security model](docs/ARCHITECTURE.md#security-model).
