<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk;

/** Static configuration (config.php). Runtime settings editable by the admin live in Settings. */
final class Config
{
    private static array $c = [];

    public static function load(string $file): void
    {
        $user    = is_file($file) ? (array)require $file : [];
        self::$c = $user + [
            'data_dir'         => dirname(__DIR__) . '/data',
            'trusted_proxies'  => [],     // IPs whose X-Forwarded-For is trusted
            'force_https'      => true,   // refuse plain HTTP (except localhost)
            'ipc'              => 'auto', // 'auto' (Unix socket if possible) or 'file' (queue file, for restricted hostings)
            'drawing_orders'   => true,   // copy/fill commands and caches instead of bitmaps (less bandwidth); validated with xrdp and Windows 11
            'bulk_compression' => 'rdp61', // highest bulk compression offered: 'rdp61' (XCRUSH), 'rdp6' (NCRUSH), 'mppc' or false
        ];
        $dir = self::$c['data_dir'];
        foreach (['', '/connections', '/audit', '/run', '/sessions', '/files', '/tmp'] as $sub) {
            if (!is_dir($dir . $sub) && !mkdir($dir . $sub, 0700, true) && !is_dir($dir . $sub)) {
                error_log("MuDeskClient: the web server cannot create $dir$sub: give the account that runs it permission to modify $dir");
                ErrorPage::send(500, 'data_dir');
            }
        }
        // the data folder may sit under the web root: deny it to the web server, whatever folder it is
        foreach (['/.htaccess' => self::DENY, '/web.config' => self::DENY_IIS, '/index.html' => '', '/web-check.txt' => self::WEB_CHECK] as $f => $content) {
            if (!is_file($dir . $f)) {
                @file_put_contents($dir . $f, $content);
            }
        }
    }

    /** Apache 2.2 and 2.4, LiteSpeed; the rewrite rule also covers OpenLiteSpeed: no access, no listing. */
    public const DENY = "Options -Indexes\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
        . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n"
        . "<IfModule mod_rewrite.c>\n    RewriteEngine On\n    RewriteRule ^ - [F]\n</IfModule>\n";

    /** IIS: no file of the folder is served, no listing (core request filtering, no extra module needed). */
    public const DENY_IIS = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <system.webServer>\n"
        . "    <directoryBrowse enabled=\"false\" />\n    <security>\n      <requestFiltering>\n"
        . "        <fileExtensions allowUnlisted=\"false\" />\n      </requestFiltering>\n    </security>\n"
        . "  </system.webServer>\n</configuration>\n";

    /** Content of data/web-check.txt: if the browser can read it, the data folder is exposed. */
    public const WEB_CHECK = 'MuDeskClient data folder: must not be reachable from the web';

    public static function get(string $key): mixed
    {
        return self::$c[$key] ?? null;
    }

    public static function dir(string $sub = ''): string
    {
        return self::$c['data_dir'] . ($sub === '' ? '' : '/' . $sub);
    }
}
