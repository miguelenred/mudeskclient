<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk;

final class Http
{
    /** json_encode flags of every answer. */
    public const JSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    /**
     * Browser features the pages never use, denied to them and to anything in them (the camera, microphone, clipboard,
     * full screen, the screens and passkeys, which they use, are left as the browser has them).
     */
    public const PERMISSIONS = 'accelerometer=(), bluetooth=(), browsing-topics=(), geolocation=(), gyroscope=(), hid=(), magnetometer=(), '
        . 'midi=(), payment=(), serial=(), usb=(), xr-spatial-tracking=()';

    public static function clientIp(): string
    {
        $ip      = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $proxies = (array)Config::get('trusted_proxies');
        if (self::fromProxy() && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            // each proxy appends the address it saw: the client is the right-most one that is not a trusted proxy
            // (what is further left was sent by the client itself)
            foreach (array_reverse(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR'])) as $fwd) {
                $fwd = trim($fwd);
                if (@inet_pton($fwd) === false) {
                    break;
                }
                $ip = $fwd;
                if (!in_array(self::canon($fwd), $proxies, true)) {
                    break;
                }
            }
        }
        return $ip;
    }

    /** Short description of a browser from its User-Agent, e.g. "Chrome 140 · Windows" ('' when nothing is recognised). */
    public static function browser(string $ua): string
    {
        $name = '';
        foreach (['Edge' => 'Edg(?:A|iOS)?', 'Opera' => 'OPR', 'Samsung Internet' => 'SamsungBrowser', 'Firefox' => 'Firefox|FxiOS',
                  'Chrome' => 'CriOS|Chrome', 'Safari' => 'Version(?=/[\d.]+ (?:Mobile/\S+ )?Safari)'] as $b => $re) {
            if (preg_match("~(?:$re)/(\d+)~", $ua, $m)) {
                $name = "$b $m[1]";
                break;
            }
        }
        $os = match (true) {
            str_contains($ua, 'Windows')  => 'Windows',
            str_contains($ua, 'Android')  => 'Android',
            str_contains($ua, 'iPhone')   => 'iPhone',
            str_contains($ua, 'iPad')     => 'iPad',
            str_contains($ua, 'CrOS')     => 'ChromeOS',
            str_contains($ua, 'Mac OS X') => 'macOS',
            str_contains($ua, 'Linux')    => 'Linux',
            default                       => '',
        };
        return implode(' · ', array_filter([$name, $os]));
    }

    public static function isHttps(): bool
    {
        return ($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off'
            || (self::fromProxy() && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }

    /**
     * A reverse proxy on this same machine not listed in trusted_proxies: the request comes from this machine with an
     * X-Forwarded-For. Then every client counts as this machine (its locks and allowed addresses shared, HTTPS not required):
     * said to administrators in the panel, always (before, only by the first run's checks).
     */
    public static function undeclaredProxy(): bool
    {
        $from = self::canon($_SERVER['REMOTE_ADDR'] ?? '');
        return in_array($from, ['127.0.0.1', '::1'], true) && !empty($_SERVER['HTTP_X_FORWARDED_FOR']) && !self::fromProxy();
    }

    /** Whether the request came from a trusted proxy (its address compared as an address, however it is written). */
    private static function fromProxy(): bool
    {
        return in_array(self::canon($_SERVER['REMOTE_ADDR'] ?? ''), (array)Config::get('trusted_proxies'), true);
    }

    /** An IP address as PHP writes it (IPv6 in lower case and shortened); anything else as it is. */
    private static function canon(string $ip): string
    {
        $b = @inet_pton($ip);
        return $b === false ? $ip : (string)inet_ntop($b);
    }

    /** Common headers, HTTPS enforcement and client allow-list. Call first in every entry point. */
    public static function guard(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('X-Frame-Options: DENY');
        header('Permissions-Policy: ' . self::PERMISSIONS);
        header('Cross-Origin-Resource-Policy: same-origin');   // (its answers, files and downloads not taken in by another site)
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; media-src 'self' blob:; style-src 'self'; script-src 'self'; connect-src 'self'; frame-src 'self' blob:; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
        // this computer itself (development) needs no HTTPS, but not a proxy on it: every client would count as local
        $local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true) && !self::fromProxy();
        if (Config::get('force_https') && !self::isHttps() && !$local) {
            Audit::anonymous() && Audit::log('access_denied', ['reason' => 'http_not_https', 'uri' => self::uri()], null, false);
            ErrorPage::send(403, 'https');
        }
        if (self::isHttps()) {
            header('Strict-Transport-Security: max-age=31536000');
        }
        if (!Access::clientAllowed(self::clientIp())) {
            Audit::anonymous() && Audit::log('access_denied', ['reason' => 'client_ip_not_allowed', 'uri' => self::uri()], null, false);
            ErrorPage::send(403, 'client_ip');
        }
        Exposure::guard();                       // nothing works while the data folder can be read from the web
        if (Backup::restoring()) {               // (a backup being put in place: a few seconds)
            ErrorPage::send(503, 'restoring');
        }
    }

    /**
     * Fingerprint of the page's scripts and styles in $dir (their names, sizes and dates): it changes with any of them. It goes
     * in their addresses and in the state the page asks for, so that after an update the browser loads the new files and a
     * page still open says that it is old (the version number alone stays the same between builds).
     */
    public static function build(string $dir): string
    {
        static $memo = [];
        if (!isset($memo[$dir])) {
            $parts = [];
            foreach (@scandir($dir) ?: [] as $f) {
                if (preg_match('~\.(js|css)$~', $f)) {
                    $parts[] = $f . ':' . (int)@filesize("$dir/$f") . ':' . (int)@filemtime("$dir/$f");
                }
            }
            $memo[$dir] = substr(md5(implode('|', $parts)), 0, 10);
        }
        return $memo[$dir];
    }

    /** Requested path and action, for the audit log. */
    public static function uri(): string
    {
        return substr((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), 0, 120)
            . (is_string($_GET['a'] ?? null) ? '?a=' . substr($_GET['a'], 0, 20) : '');
    }

    public static function json(array $data, int $code = 200): never
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, self::JSON);
        exit;
    }

    /** Action of the current request (for the audit) and whether failed requests are audited here. */
    public static string $action = '';
    public static bool $auditFailures = true;

    /**
     * A session's request handed over by the WebSocket service (tools/ws-session.php): its body, given instead of php://input,
     * and the service's end of its standard input, whose closing says the browser left (connection_aborted() knows nothing
     * there). Both null: an ordinary web request.
     */
    public static ?string $serviceBody = null;
    /** @var resource|null */
    public static mixed $serviceIn = null;

    /** Whether the browser left: the web server says so, or, for the WebSocket service, its standard input closed. */
    public static function aborted(): bool
    {
        if (self::$serviceIn === null) {
            return connection_aborted() !== 0;
        }
        $c = is_resource(self::$serviceIn) ? @fread(self::$serviceIn, 64) : false;   // (non-blocking: nothing else ever comes)
        return $c === false || ($c === '' && feof(self::$serviceIn));
    }

    /** Answers {"error": key} (plus $more, e.g. why the session closed) and ends the request. */
    public static function fail(string $error, int $code = 400, array $more = []): never
    {
        if (self::$auditFailures && (Auth::username() !== null || Audit::anonymous())) {
            Audit::log('request_failed', ['action' => self::$action ?: self::uri(), 'error' => $error, 'status' => $code], null, false);
        }
        self::json(['error' => $error] + $more, $code);
    }

    /** Largest body of a JSON request: the longest is a ZIP's list of 10,000 names (about 2.6 MB at 255 bytes each). */
    public const BODY_MAX = 4 << 20;

    /**
     * The body of a JSON request, up to BODY_MAX (413 past it). PHP's post_max_size does not limit php://input: an 8 MB
     * list of zeros, decoded twice by api.php before signing in, took 150 MB and ended the request with a fatal error. Read as
     * long as the request says: PHP 8.1 and 8.2 take the memory of the most asked for at once (4 MB for each request).
     */
    public static function body(): string
    {
        static $raw = null;
        if ($raw === null && self::$serviceBody !== null) {   // (a session's request handed over by the WebSocket service)
            $raw = strlen(self::$serviceBody) > self::BODY_MAX ? self::fail('too_large', 413) : self::$serviceBody;
        }
        if ($raw === null) {
            $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
            $raw = $len > self::BODY_MAX ? '' : (string)@file_get_contents('php://input', false, null, 0, $len > 0 ? $len : self::BODY_MAX + 1);
            if ($len > self::BODY_MAX || strlen($raw) > self::BODY_MAX) {
                self::fail('too_large', 413);
            }
        }
        return $raw;
    }

    /**
     * A number of the request as an int, whatever it came as ($default when it is no number): a float past the int range
     * (1e20 in the JSON) gave a warning on PHP 8.5 when cast.
     */
    public static function int(mixed $v, int $default = 0): int
    {
        return is_int($v) ? $v : (is_float($v) || (is_string($v) && is_numeric($v)) ? (int)max(-1e15, min(1e15, (float)$v)) : (is_bool($v) ? (int)$v : $default));
    }

    /**
     * A yes/no field of a request: true or false (1 or 0 too); null for anything else, which the caller refuses. Before,
     * cast: "false", "no", "0.0" or a list were taken as yes.
     */
    public static function yes(mixed $v): ?bool
    {
        return is_bool($v) ? $v : ($v === 1 || $v === 0 ? $v === 1 : null);
    }

    /** The request's JSON object or list ([] when it is not one), decoded once. */
    public static function input(): array
    {
        static $in = null;
        if ($in === null) {
            $in = json_decode(self::body(), true);
            $in = is_array($in) ? $in : [];
        }
        return $in;
    }
}
