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
            // a computer or gateway never seen confirmed by the user (its fingerprints shown) before anything is sent to it;
            // false: trusted on its first use, as before 1.2 (a man in the middle of that first connection is not noticed)
            'confirm_new_certificates' => true,
            'ipc'              => 'auto', // 'auto' (Unix socket if possible) or 'file' (queue file, for restricted hostings)
            'drawing_orders'   => true,   // copy/fill commands and caches instead of bitmaps (less bandwidth); validated with xrdp and Windows 11
            'bulk_compression' => 'rdp61', // highest bulk compression offered: 'rdp61' (XCRUSH), 'rdp6' (NCRUSH), 'mppc' or false
        ];
        // the proxies trusted with X-Forwarded-For: addresses, kept as PHP writes them (compared with the request's as such).
        // Before, an entry that was none (a range, a name) matched nothing, and nobody was told: every client had the
        // proxy's address, for the sign-in locks and the allow-list of clients
        $proxies = [];
        foreach ((array)self::$c['trusted_proxies'] as $p) {
            $b = is_string($p) ? @inet_pton(trim($p, " []")) : false;
            if ($b === false) {
                error_log('MuDeskClient: config.php, trusted_proxies: ' . (is_string($p) ? $p : gettype($p)) . ' is not an IP address (ranges and names are not taken)');
                ErrorPage::send(500, 'config_proxies');
            }
            $proxies[] = (string)inet_ntop($b);
        }
        self::$c['trusted_proxies'] = $proxies;
        $dir = self::$c['data_dir'];
        foreach (['', '/connections', '/hosts', '/audit', '/run', '/sessions', '/files', '/tmp'] as $sub) {
            if (!is_dir($dir . $sub) && in_array($sub, ['/connections', '/hosts', '/audit', '/files'], true)
                && time() - (int)@file_get_contents("$dir/run/restoring") < 300) {
                continue;                     // a restore moving it into place (Backup::restore): made again, it stood in the way
            }
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

    /**
     * The site's public address from 'public_url' (scheme, host, port and path of public/, no trailing slash), or null when
     * it is not set or not a plain http(s) address: then each request's own Host is used (a proxy that does not pass the
     * public one on broke signing in with OpenID Connect or a passkey).
     */
    public static function publicUrl(): ?array
    {
        $u = self::get('public_url');
        $p = is_string($u) ? parse_url(rtrim($u, '/')) : false;
        if (!is_array($p) || !in_array($p['scheme'] ?? '', ['http', 'https'], true) || ($p['host'] ?? '') === ''
            || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment'])) {
            return null;
        }
        $host = strtolower($p['host']) . (isset($p['port']) ? ':' . $p['port'] : '');
        return ['origin' => $p['scheme'] . '://' . $host, 'host' => strtolower($p['host']), 'path' => rtrim($p['path'] ?? '', '/')];
    }

    public static function dir(string $sub = ''): string
    {
        return self::$c['data_dir'] . ($sub === '' ? '' : '/' . $sub);
    }
}
