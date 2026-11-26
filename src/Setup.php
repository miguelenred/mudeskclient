<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk;

/**
 * The guided first run: what the server can tell about itself before the administrator exists (PHP, its functions and
 * limits, the data folder, HTTPS, a proxy in front, how short requests reach a session), each with what to do when it is
 * not right; the page adds what only a browser sees (a long request kept open, how many requests the server takes at
 * once) and, if asked, a remote computer reached. Only while there is no user, and each request with the setup code
 * (api.php: setup.check, setup.wait, setup.stream, setup.rdp): what it tells is the server's, for whoever installs it.
 */
final class Setup
{
    /** Functions the gateway uses that a hosting may disable (disable_functions). */
    private const FUNCTIONS = ['stream_socket_client', 'stream_socket_enable_crypto', 'stream_socket_server', 'stream_select', 'set_time_limit',
        'ignore_user_abort', 'flock', 'random_bytes', 'hash_hmac', 'openssl_encrypt', 'password_hash', 'flush'];

    /**
     * The server's checks, in the order the page shows them: ['id' => ..., 'state' => 'ok' | 'warn' | 'fail', 'value' => what
     * was found]. 'fail': something will not work; 'warn': it works, worse or less safely.
     * @return list<array{id: string, state: string, value: string}>
     */
    public static function checks(): array
    {
        $out = [];
        $add = static function (string $id, string $state, string $value = '') use (&$out): void {
            $out[] = ['id' => $id, 'state' => $state, 'value' => $value];
        };
        $add('php', PHP_VERSION_ID >= 80100 && PHP_VERSION_ID < 80600 ? 'ok' : 'warn', PHP_VERSION);
        $missing = array_values(array_filter(self::FUNCTIONS, static fn(string $f): bool => !function_exists($f)));
        $add('functions', $missing === [] ? 'ok' : 'fail', implode(', ', $missing));
        $mem = self::bytes((string)ini_get('memory_limit'));
        $add('memory', $mem < 0 || $mem >= 256 << 20 ? 'ok' : ($mem >= 128 << 20 ? 'warn' : 'fail'), (string)ini_get('memory_limit'));
        $add('time_limit', function_exists('set_time_limit') && @set_time_limit(0) ? 'ok' : 'fail', (string)ini_get('max_execution_time'));
        $shown = !in_array(strtolower(trim((string)ini_get('display_errors'))), ['', '0', 'off', 'false', 'no', 'none', 'stderr'], true);
        $add('display_errors', $shown ? 'warn' : 'ok', (string)ini_get('display_errors'));
        $unwritable = array_values(array_filter(['', '/run', '/sessions', '/tmp', '/files', '/audit', '/connections', '/hosts'],
            static fn(string $d): bool => !is_writable(Config::dir(ltrim($d, '/')))));
        $add('data_writable', $unwritable === [] ? 'ok' : 'fail', $unwritable === [] ? '' : implode(', ', array_map(static fn($d) => 'data' . $d, $unwritable)));
        $add('tmp_writable', is_writable(sys_get_temp_dir()) ? 'ok' : 'fail', '');
        $exposure = Exposure::state()['state'];
        $add('data_protected', $exposure === 'ok' ? 'ok' : ($exposure === 'exposed' ? 'fail' : 'warn'));   // (warn: not known yet)
        $local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
        $add('https', Http::isHttps() ? 'ok' : ($local ? 'warn' : 'fail'), '');
        $proxies = (array)Config::get('trusted_proxies');
        $add('proxy', !empty($_SERVER['HTTP_X_FORWARDED_FOR']) && !in_array(self::canon((string)($_SERVER['REMOTE_ADDR'] ?? '')), $proxies, true) ? 'warn' : 'ok',
            (string)($_SERVER['REMOTE_ADDR'] ?? ''));
        $add('input', self::input(), '');
        $opcache = function_exists('opcache_get_status') && is_array($o = @opcache_get_status(false)) && !empty($o['opcache_enabled']);
        $add('opcache', $opcache ? 'ok' : 'warn', '');
        return $out;
    }

    /**
     * How a session's short requests (keyboard, mouse) reach it: a Unix socket, or on Windows a local TCP port ('ok'); a
     * file read every 20 ms ('warn', a little slower), where the hosting allows neither or config.php asks for it.
     */
    private static function input(): string
    {
        if (Config::get('ipc') === 'file' || !function_exists('stream_socket_server')) {
            return 'warn';
        }
        $path = Config::dir('run/setup-' . bin2hex(random_bytes(4)) . '.sock');
        $srv  = @stream_socket_server(PHP_OS_FAMILY === 'Windows' ? 'tcp://127.0.0.1:0' : 'unix://' . $path, $en, $es);
        if ($srv === false) {
            return 'warn';
        }
        fclose($srv);
        @unlink($path);
        return 'ok';
    }

    /**
     * A remote computer reached as a session would (TCP, the X.224 negotiation, TLS): ['ok' => bool, 'ms' => connection
     * time, 'protocol' => 'nla' | 'tls' | 'rdp', 'tls' => version, 'cn' => the certificate's name, 'problem' => what failed (not 'error', which the page takes for a refused request):
     * 'address' | 'tcp' | 'not_rdp' | 'negotiation' | 'tls'].
     */
    public static function rdp(string $host, int $port): array
    {
        if ($host === '' || strlen($host) > 253 || !preg_match('~^[A-Za-z0-9.:_\[\]-]+$~', $host) || $port < 1 || $port > 65535) {
            return ['ok' => false, 'problem' => 'address'];
        }
        $t = microtime(true);
        $s = @stream_socket_client('tcp://' . (str_contains($host, ':') && $host[0] !== '[' ? "[$host]" : $host) . ":$port", $en, $es, 5,
            STREAM_CLIENT_CONNECT, stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'capture_peer_cert' => true]]));
        if ($s === false) {
            return ['ok' => false, 'problem' => 'tcp'];
        }
        $out = ['ok' => true, 'ms' => (int)round((microtime(true) - $t) * 1000)];
        try {
            stream_set_timeout($s, 5);
            fwrite($s, "\x03\x00\x00\x13\x0e\xe0\x00\x00\x00\x00\x00\x01\x00\x08\x00\x03\x00\x00\x00");   // X.224 request: TLS and NLA
            $r = '';
            while (strlen($r) < 19 && !feof($s) && ($c = fread($s, 19 - strlen($r))) !== false && $c !== '') {
                $r .= $c;
            }
            if (strlen($r) < 11 || $r[0] !== "\x03" || $r[5] !== "\xd0") {
                return ['ok' => false, 'problem' => 'not_rdp'];
            }
            if (strlen($r) < 19) {
                return $out + ['protocol' => 'rdp'];               // (no negotiation: Standard RDP Security only)
            }
            $val = unpack('V', $r, 15)[1];
            if (ord($r[11]) === 3) {
                return ['ok' => false, 'problem' => 'negotiation'];
            }
            $out['protocol'] = $val === 0 ? 'rdp' : ($val & 2 ? 'nla' : 'tls');
            if ($val !== 0) {
                if (!@stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    return ['ok' => false, 'problem' => 'tls', 'protocol' => $out['protocol']];
                }
                $crypto = stream_get_meta_data($s)['crypto'] ?? [];
                $cert   = stream_context_get_params($s)['options']['ssl']['peer_certificate'] ?? null;
                $out   += ['tls' => (string)($crypto['protocol'] ?? ''), 'cn' => $cert ? (string)(openssl_x509_parse($cert)['subject']['CN'] ?? '') : ''];
            }
            return $out;
        } finally {
            fclose($s);
        }
    }

    /**
     * A long request, as a session's: a line every second for $seconds (at most 20), sent at once, so the browser sees
     * whether the server or a proxy holds it back or cuts it.
     */
    public static function stream(int $seconds): never
    {
        $seconds = max(1, min(20, $seconds));
        Auth::release();                                   // (the PHP session's lock: the page's other requests go on)
        @set_time_limit(0);
        @ini_set('zlib.output_compression', '0');
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store, no-transform');
        header('X-Accel-Buffering: no');
        echo str_repeat(' ', 2048), "\n";
        flush();
        $t0 = microtime(true);
        for ($i = 1; $i <= $seconds && !connection_aborted(); $i++) {
            usleep((int)max(0, ($t0 + $i - microtime(true)) * 1e6));
            echo $i, "\n";
            flush();
        }
        exit;
    }

    /** A request that takes a second: the page sends several at once and counts how many the server took together. */
    public static function wait(): never
    {
        Auth::release();
        $t = microtime(true);
        // a whole second by the clock it is told with (on Windows a sleep of 1 s could end some milliseconds short of it)
        while (($left = $t + 1 - microtime(true)) > 0) {
            usleep((int)ceil($left * 1e6));
        }
        Http::json(['start' => $t, 'end' => microtime(true)]);
    }

    private static function bytes(string $v): int
    {
        $v = trim($v);
        if ($v === '' || $v === '-1') {
            return -1;
        }
        $n = (int)$v;
        return match (strtolower(substr($v, -1))) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n };
    }

    private static function canon(string $ip): string
    {
        $b = @inet_pton($ip);
        return $b === false ? $ip : (string)inet_ntop($b);
    }
}
