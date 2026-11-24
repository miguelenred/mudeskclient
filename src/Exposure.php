<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk;

/**
 * Whether the web server lets browsers read the data folder (users, connections, the audit...). While it does, nothing of
 * MuDeskClient works: every entry point answers a page that says so (503 data_exposed), until the folder is protected.
 *
 * Found in two ways. The server asks itself, as a browser would, for data/web-check.txt beside the page asked for (every 10
 * minutes while it is not readable, every 30 s while it is: it works again soon after the folder is protected). Where it
 * cannot (PHP's built-in server, which answers one request at a time; a hosting that does not let it reach itself), the
 * page's browser reads that file on each load and proves it could: the file carries a random code that changes, and only that
 * code, sent back, stops the gateway (nobody stops it with a false word, nor with a code seen before). Stopped so, it is
 * looked at again after 5 minutes (the next page that loads proves it again while it is still readable). The code changes
 * each time it is looked at; on PHP's built-in server, which never asks itself, every 10 minutes (before, it never had one
 * there, and no browser could stop it).
 *
 * Where it was found readable (the address, port, name and folder) is kept, and asked again with the request's: a request
 * with another name or path (the Host of another site, a path after the script's) never finds it protected alone.
 */
final class Exposure
{
    public const AGAIN_OK = 600, AGAIN_EXPOSED = 30, PROOF_FOR = 300;

    /** Called by Http::guard on every request: stops it while the data folder can be read from the web. */
    public static function guard(): void
    {
        $s = self::state();
        if (self::due($s, time())) {
            $s = PHP_SAPI === 'cli' || PHP_SAPI === 'cli-server' ? self::save('unknown', 'server') : (self::probe($_SERVER) ?? $s);
        }
        if ($s['state'] === 'exposed') {
            ErrorPage::send(503, 'data_exposed');
        }
    }

    /**
     * What is known: ['state' => 'ok'|'exposed'|'unknown', 'at' => when, 'by' => 'server'|'browser', 'token' => the file's code,
     * 'where' => where it was found readable (see target), or null].
     */
    public static function state(): array
    {
        $none = ['state' => 'unknown', 'at' => 0, 'by' => 'server', 'token' => '', 'where' => null];
        $s    = json_decode((string)@file_get_contents(Config::dir('run/exposure.json')), true);
        return is_array($s) && in_array($s['state'] ?? '', ['ok', 'exposed', 'unknown'], true) ? $s + $none : $none;
    }

    /**
     * Where request $srv ($_SERVER) came: the address and port it came to, TLS, its Host, and its script's folder (from
     * SCRIPT_NAME, the server's: never a path the client adds after the script).
     */
    public static function target(array $srv): array
    {
        $script = (string)($srv['SCRIPT_NAME'] ?? '');
        $path   = $script !== '' ? $script : (string)parse_url((string)($srv['REQUEST_URI'] ?? '/'), PHP_URL_PATH) . 'x';
        return ['SERVER_ADDR' => (string)($srv['SERVER_ADDR'] ?? ''), 'SERVER_PORT' => (int)($srv['SERVER_PORT'] ?? 0),
            'HTTPS' => (string)($srv['HTTPS'] ?? ''), 'HTTP_HOST' => (string)($srv['HTTP_HOST'] ?? ''),
            'dir' => rtrim(str_replace('\\', '/', dirname('/' . ltrim($path, '/'))), '/')];
    }

    /** Whether the server should look again now: after 10 minutes, or 30 s while exposed (5 minutes when a browser proved it). */
    public static function due(array $s, int $now): bool
    {
        if ($s['state'] === 'exposed') {
            return $now - (int)$s['at'] >= ($s['by'] === 'browser' ? self::PROOF_FOR : self::AGAIN_EXPOSED);
        }
        return $now - (int)$s['at'] >= self::AGAIN_OK;
    }

    /**
     * Looks now, from the request $srv describes ($_SERVER): data/web-check.txt asked for at the address and port the request
     * came to, with its Host, next to the page's folder and one above it (where the project's root is served, or public/
     * under it). One reader at a time (the others keep what is known: null). The file gets a new code each time.
     */
    public static function probe(array $srv): ?array
    {
        $lock = @fopen(Config::dir('run/exposure.lock'), 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            $lock && fclose($lock);
            return null;
        }
        try {
            // here, and where it was found readable before: protected again only when that place says so too
            $was   = self::state();
            $where = [self::target($srv)];
            if ($was['state'] === 'exposed' && is_array($was['where']) && $was['where'] != $where[0]) {
                $where[] = $was['where'];
            }
            [$states, $found] = [[], null];
            foreach ($where as $w) {
                $seen = [];
                foreach (array_unique([self::path("{$w['dir']}/../data/web-check.txt"), self::path("{$w['dir']}/data/web-check.txt")]) as $path) {
                    $seen[] = self::fetch($w, $path);
                }
                $states[] = in_array(true, $seen, true) ? 'exposed' : (in_array(false, $seen, true) ? 'ok' : 'unknown');
                $found ??= in_array(true, $seen, true) ? $w : null;
            }
            $state = in_array('exposed', $states, true) ? 'exposed' : (in_array('unknown', $states, true) ? 'unknown' : 'ok');
            return self::save($state, 'server', $found);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * A browser's proof: what it read at data/web-check.txt. Only the code the file has now stops the gateway (a false word,
     * or a code read before, does nothing). True when it did.
     */
    public static function proof(string $read, ?array $srv = null): bool
    {
        $token = self::state()['token'];
        if ($token === '' || !str_contains($read, Config::WEB_CHECK . "\n" . $token)) {
            return false;
        }
        self::save('exposed', 'browser', self::target($srv ?? $_SERVER));   // (the page's place: where its browser read it)
        return true;
    }

    /** Keeps what was found and writes the file again with a new code (the old one no longer proves anything). */
    public static function save(string $state, string $by, ?array $where = null): array
    {
        $s = ['state' => $state, 'at' => time(), 'by' => $by, 'token' => bin2hex(random_bytes(16)), 'where' => $state === 'exposed' ? $where : null];
        @file_put_contents(Config::dir('web-check.txt'), Config::WEB_CHECK . "\n" . $s['token'] . "\n", LOCK_EX);
        @file_put_contents(Config::dir('run/exposure.json'), json_encode($s), LOCK_EX);
        return $s;
    }

    /** A URL path with its "." and ".." resolved (never above the root). */
    public static function path(string $p): string
    {
        $out = [];
        foreach (explode('/', $p) as $seg) {
            if ($seg === '..') {
                array_pop($out);
            } elseif ($seg !== '' && $seg !== '.') {
                $out[] = $seg;
            }
        }
        return '/' . implode('/', $out);
    }

    /**
     * Asks the server the request came to ($srv: SERVER_ADDR, SERVER_PORT, HTTPS, HTTP_HOST) for $path: true when it gives the
     * check file, false when it answers anything else, null when it could not be asked (2 s at most).
     */
    public static function fetch(array $srv, string $path): ?bool
    {
        $addr = (string)($srv['SERVER_ADDR'] ?? '');
        $addr = $addr === '' || $addr === '0.0.0.0' || $addr === '::' ? '127.0.0.1' : $addr;
        $tls  = ($srv['HTTPS'] ?? '') !== '' && ($srv['HTTPS'] ?? '') !== 'off';
        $host = (string)($srv['HTTP_HOST'] ?? '') !== '' ? (string)$srv['HTTP_HOST'] : $addr;
        $name = (string)preg_replace('~:\d+$~', '', trim($host, '[]'));
        $ctx  = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true, 'SNI_enabled' => true,
            'peer_name' => $name, 'security_level' => 0]]);
        $s = @stream_socket_client(($tls ? 'tls://' : 'tcp://') . (str_contains($addr, ':') ? "[$addr]" : $addr) . ':' . ((int)($srv['SERVER_PORT'] ?? 0) ?: ($tls ? 443 : 80)),
            $en, $es, 2, STREAM_CLIENT_CONNECT, $ctx);
        if ($s === false) {
            return null;
        }
        stream_set_timeout($s, 2);
        fwrite($s, "GET $path HTTP/1.1\r\nHost: $host\r\nUser-Agent: MuDeskClient data check\r\nAccept: */*\r\nConnection: close\r\n\r\n");
        $r = '';
        for ($end = microtime(true) + 2; strlen($r) < 65536 && !feof($s) && microtime(true) < $end;) {
            $d = fread($s, 8192);
            if ($d === false || ($d === '' && stream_get_meta_data($s)['timed_out'])) {
                break;
            }
            $r .= $d;
        }
        fclose($s);
        if (!preg_match('~^HTTP/\d(?:\.\d)? (\d{3})~', $r, $m)) {
            return null;
        }
        return (int)$m[1] === 200 && str_contains($r, Config::WEB_CHECK);
    }
}
