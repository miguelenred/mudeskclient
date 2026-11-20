<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// Copy to config.php and adjust. Every key is optional.
return [
    // Folder for JSON data, sessions and shared folders (keep it outside the web root).
    // 'data_dir' => __DIR__ . '/data',

    // Reject plain HTTP (localhost is always allowed).
    // 'force_https' => true,

    // Reverse proxies whose X-Forwarded-For / X-Forwarded-Proto headers are trusted.
    // 'trusted_proxies' => ['127.0.0.1'],

    // How short requests reach the running session: 'auto' (Unix socket) or 'file' (restricted hostings).
    // 'ipc' => 'auto',

    // Copy/fill drawing commands and caches instead of bitmaps (less bandwidth). Validated with xrdp and Windows 11.
    // 'drawing_orders' => true,
    // 'bulk_compression' => 'rdp61', // highest bulk compression offered: 'rdp61' (RDP 6.1, XCRUSH), 'rdp6' (NCRUSH), 'mppc' or false
];
