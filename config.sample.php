<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// Copy to config.php and adjust. Every key is optional.
return [
    // Folder for JSON data, sessions and shared folders (keep it outside the web root).
    // 'data_dir' => __DIR__ . '/data',

    // Reject plain HTTP (localhost is always allowed).
    // 'force_https' => true,

    // The first connection to a computer (or gateway) never seen shows its certificate's fingerprints for the user to
    // confirm before anything is sent; false trusts it at once, as before 1.2.
    // 'confirm_new_certificates' => true,

    // Reverse proxies whose X-Forwarded-For / X-Forwarded-Proto headers are trusted: only the exact addresses of your own
    // proxies, never a public range (anyone there could then pass any address and get past the allowed clients and the
    // sign-in locks).
    // 'trusted_proxies' => ['127.0.0.1'],

    // The site's public address (where index.php is): signing in with OpenID Connect and passkeys use it instead of each
    // request's Host header (behind a proxy that does not pass the public name on).
    // 'public_url' => 'https://mudesk.example.com',

    // How short requests reach the running session: 'auto' (Unix socket) or 'file' (restricted hostings).
    // 'ipc' => 'auto',

    // Copy/fill drawing commands and caches instead of bitmaps (less bandwidth). Validated with xrdp and Windows 11.
    // 'drawing_orders' => true,
    // 'bulk_compression' => 'rdp61', // highest bulk compression offered: 'rdp61' (RDP 6.1, XCRUSH), 'rdp6' (NCRUSH), 'mppc' or false

    // Largest session recording, in MB (connections the administrator records); past it the rest is not kept.
    // 'record_max_mb' => 2048,

    // Kerberos (Active Directory) for NLA and the Remote Desktop Gateway, tried before NTLM when the domain is known by its
    // DNS name (domain, user@domain or the computer's full name). The domain controllers are found in the DNS
    // (_kerberos._tcp SRV records); give them here when this server cannot find them (TCP port 88 must be reachable).
    // 'kerberos' => false, // never: NTLM only
    // 'kerberos_kdc' => ['EMPRESA.LOCAL' => ['dc1.empresa.local', 'dc2.empresa.local:88']],
    // 'kerberos_dns' => false, // the DNS not asked: only the KDCs above
];
