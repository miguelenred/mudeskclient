<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk;

/**
 * WebSocket (RFC 6455) for the optional service (tools/ws-server.php): the opening handshake's answer and the frames. A
 * client's frames come masked (refused otherwise), the server's go unmasked; a message may come in fragments (joined, at
 * most MAX_MESSAGE); control frames (close, ping, pong) are at most 125 bytes and never fragmented.
 */
final class WebSocket
{
    public const TEXT = 1, BINARY = 2, CLOSE = 8, PING = 9, PONG = 10;
    /** The largest message taken: an input message as rdp.php?a=send takes it (4 MB) with its number. */
    public const MAX_MESSAGE = 4194304 + 4;

    /** Sec-WebSocket-Accept for a client's Sec-WebSocket-Key, or null when the key is none (16 bytes in base64). */
    public static function accept(string $key): ?string
    {
        $raw = base64_decode($key, true);
        return $raw === false || strlen($raw) !== 16 ? null : base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
    }

    /**
     * The answer to an opening request ($req: its head, without the blank line) from $peer (as stream_socket_accept gives
     * it: "address:port", "[IPv6]:port"): [the request's details as the web server would give them to rdp.php, 101] or
     * [false, an HTTP refusal]: 400 for what is not a WebSocket opening, 403 for a page of another site (its Origin is not
     * the scheme and Host the browser asked for).
     *
     * @return array{0:array<string,string>|false,1:string}
     */
    public static function handshake(string $req, string $peer): array
    {
        $lines = explode("\r\n", $req);
        if (!preg_match('~^GET (/\S*) HTTP/1\.1$~', (string)array_shift($lines), $m)) {
            return [false, "HTTP/1.1 400 Bad Request\r\nConnection: close\r\nContent-Length: 0\r\n\r\n"];
        }
        $hdr = [];
        foreach ($lines as $l) {
            if (str_contains($l, ':')) {
                [$k, $v] = explode(':', $l, 2);
                $hdr[strtolower(trim($k))] = trim($v);
            }
        }
        $accept = self::accept((string)($hdr['sec-websocket-key'] ?? ''));
        if ($accept === null || !str_contains(strtolower((string)($hdr['upgrade'] ?? '')), 'websocket')
            || !str_contains(strtolower((string)($hdr['connection'] ?? '')), 'upgrade') || ($hdr['sec-websocket-version'] ?? '') !== '13') {
            return [false, "HTTP/1.1 400 Bad Request\r\nConnection: close\r\nContent-Length: 0\r\n\r\n"];
        }
        $server = self::server($hdr, (string)(parse_url($m[1], PHP_URL_PATH) ?: '/'), $peer);
        $own    = (($server['HTTPS'] ?? '') === 'on' ? 'https://' : 'http://') . strtolower((string)($hdr['host'] ?? ''));
        if (($hdr['host'] ?? '') === '' || strtolower((string)($hdr['origin'] ?? '')) !== $own) {
            return [false, "HTTP/1.1 403 Forbidden\r\nConnection: close\r\nContent-Length: 0\r\n\r\n"];
        }
        return [$server, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: $accept\r\n\r\n"];
    }

    /**
     * The details of an opening request (its headers by lower-case name, its path, its peer) as the web server in front of
     * the service would have given them to rdp.php. From this machine (the proxy rule): the client's address is the last
     * X-Forwarded-For hop (the one the proxy added; those before it go on as X-Forwarded-For, which rdp.php takes as
     * trusted_proxies says) and the scheme its X-Forwarded-Proto; from elsewhere (websocket_listen not on this machine),
     * neither: the peer's own address, and plain HTTP (the service has no TLS).
     *
     * @param array<string,string> $hdr
     * @return array<string,string>
     */
    public static function server(array $hdr, string $path, string $peer): array
    {
        $fwd   = array_values(array_filter(array_map('trim', explode(',', (string)($hdr['x-forwarded-for'] ?? ''))), static fn(string $a): bool => $a !== ''));
        $peer  = preg_match('~^\[(.*)\]:\d+$~', $peer, $pm) ? $pm[1] : (string)preg_replace('~:\d+$~', '', $peer);
        $local = in_array($peer, ['127.0.0.1', '::1'], true);
        $addr  = $local && $fwd !== [] ? (string)array_pop($fwd) : $peer;
        $https = $local && strtolower(trim((string)array_slice(explode(',', (string)($hdr['x-forwarded-proto'] ?? '')), -1)[0])) === 'https';
        $host  = (string)($hdr['host'] ?? '');
        $base  = rtrim(str_replace('\\', '/', dirname($path)), '/');
        return array_filter([
            'REMOTE_ADDR' => $addr, 'HTTPS' => $https ? 'on' : '', 'HTTP_HOST' => $host, 'SERVER_NAME' => (string)preg_replace('~:\d+$~', '', $host),
            'SERVER_PORT' => preg_match('~:(\d+)$~', $host, $m) ? $m[1] : ($https ? '443' : '80'), 'SERVER_ADDR' => '127.0.0.1',
            'HTTP_USER_AGENT' => (string)($hdr['user-agent'] ?? ''), 'HTTP_ACCEPT_LANGUAGE' => (string)($hdr['accept-language'] ?? ''),
            'HTTP_COOKIE' => (string)($hdr['cookie'] ?? ''), 'HTTP_X_FORWARDED_FOR' => implode(', ', $fwd), 'HTTP_ORIGIN' => (string)($hdr['origin'] ?? ''),
            'SCRIPT_NAME' => "$base/rdp.php", 'REQUEST_URI' => "$base/rdp.php?a=stream",
        ], static fn(string $v): bool => $v !== '');
    }

    /**
     * Whether $cookie (an opening request's Cookie header) names a web session that exists (its file in data/sessions): the
     * service starts a session's PHP only for one (before, the first message of any connection started a PHP, which rdp.php
     * then refused). rdp.php still checks the session itself, its CSRF token and its browser.
     */
    public static function hasSession(string $cookie): bool
    {
        foreach (explode(';', $cookie) as $part) {
            [$k, $v] = array_map('trim', explode('=', $part, 2) + [1 => '']);
            if ($k === 'MUDESKSID') {
                return (bool)preg_match('/^[A-Za-z0-9,-]{22,256}$/', $v) && is_file(Config::dir('sessions') . '/sess_' . $v);
            }
        }
        return false;
    }

    /**
     * The memory_limit of the service's sessions: the service's own (php -d memory_limit=... starts it), or, when it has
     * none (PHP's command line has none by default), the Docker image's 256M, as a session has over HTTP.
     */
    public static function sessionMemory(string $own): string
    {
        return in_array(trim($own), ['', '-1'], true) ? '256M' : trim($own);
    }

    /**
     * A session's request given to rdp.php as the web server would give it: $server (what handshake() found, with the
     * page's CSRF token) as the request's details, POST, $body as its body, its cookies; the browser leaving is said by
     * $in closing (Http::aborted). Only those details: what the command line's environment holds of the same names (a
     * variable named HTTP_X_FORWARDED_FOR, say) is not taken.
     *
     * @param array<string,mixed> $server
     * @param resource $in
     */
    public static function asRequest(array $server, string $body, mixed $in): void
    {
        $keep = ['REMOTE_ADDR', 'HTTPS', 'HTTP_HOST', 'SERVER_NAME', 'SERVER_PORT', 'SERVER_ADDR', 'HTTP_USER_AGENT', 'HTTP_ACCEPT_LANGUAGE', 'HTTP_COOKIE',
                 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED_PROTO', 'HTTP_X_CSRF', 'HTTP_ORIGIN', 'SCRIPT_NAME', 'REQUEST_URI'];
        foreach (array_keys($_SERVER) as $k) {
            if (str_starts_with((string)$k, 'HTTP_') || in_array($k, $keep, true)) {
                unset($_SERVER[$k]);
            }
        }
        foreach ($keep as $k) {
            if (is_string($server[$k] ?? null)) {
                $_SERVER[$k] = $server[$k];
            }
        }
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_TYPE']   = 'application/json';
        $_SERVER['CONTENT_LENGTH'] = (string)strlen($body);
        [$_GET, $_POST, $_COOKIE] = [['a' => 'stream'], [], []];
        foreach (explode(';', (string)($_SERVER['HTTP_COOKIE'] ?? '')) as $pair) {
            [$k, $v] = array_map('trim', explode('=', $pair, 2)) + ['', ''];
            if ($k !== '') {
                $_COOKIE[$k] = urldecode($v);
            }
        }
        Http::$serviceBody = $body;
        stream_set_blocking($in, false);
        Http::$serviceIn = $in;
        if (preg_match('/^[A-Za-z0-9,-]{22,256}$/', (string)($_COOKIE['MUDESKSID'] ?? ''))) {
            session_id($_COOKIE['MUDESKSID']);        // (the PHP session of the page that opened it: Auth::start takes it)
        }
    }

    /** A server's frame: final, unmasked. */
    public static function frame(int $opcode, string $payload): string
    {
        $n = strlen($payload);
        return chr(0x80 | $opcode) . ($n < 126 ? chr($n) : ($n < 65536 ? chr(126) . pack('n', $n) : chr(127) . pack('J', $n))) . $payload;
    }

    /**
     * The whole frames at the start of $buf (taken out of it), as [[opcode, payload], ...]: messages (their fragments joined
     * in $frag meanwhile) and control frames, in their order. Throws on what a client may not send: an unmasked frame, a
     * reserved bit, an unknown opcode, a control frame fragmented or over 125 bytes, a message over MAX_MESSAGE, a
     * continuation with no message begun, or a new message before the last one ended.
     *
     * @param ?array{0:int,1:string} $frag the message whose fragments are being joined
     * @param int $max the largest message taken (MAX_MESSAGE; less before a session is open, whose request is small)
     * @return list<array{0:int,1:string}>
     */
    public static function read(string &$buf, ?array &$frag, int $max = self::MAX_MESSAGE): array
    {
        $out = [];
        while (strlen($buf) >= 2) {
            [$b0, $b1] = [ord($buf[0]), ord($buf[1])];
            $fin = ($b0 & 0x80) !== 0;
            $op  = $b0 & 0x0F;
            if ($b0 & 0x70) {
                throw new \RuntimeException('WebSocket: reserved bits set');
            }
            if (!($b1 & 0x80)) {
                throw new \RuntimeException('WebSocket: unmasked client frame');
            }
            if (!in_array($op, [0, self::TEXT, self::BINARY, self::CLOSE, self::PING, self::PONG], true)) {
                throw new \RuntimeException("WebSocket: opcode $op");
            }
            $n = $b1 & 0x7F;
            $p = 2;
            if ($n === 126) {
                if (strlen($buf) < 4) {
                    break;
                }
                [$n, $p] = [unpack('n', $buf, 2)[1], 4];
            } elseif ($n === 127) {
                if (strlen($buf) < 10) {
                    break;
                }
                [$n, $p] = [unpack('J', $buf, 2)[1], 10];
            }
            if ($op >= 8 && ($n > 125 || !$fin)) {
                throw new \RuntimeException('WebSocket: control frame fragmented or too long');
            }
            if ($n < 0 || $n > $max || ($op === 0 && $frag !== null && strlen($frag[1]) + $n > $max)) {
                throw new \RuntimeException('WebSocket: message too large');
            }
            if (strlen($buf) < $p + 4 + $n) {
                break;                                        // (the rest of the frame still to come)
            }
            $mask = substr($buf, $p, 4);
            $data = $n === 0 ? '' : substr($buf, $p + 4, $n) ^ substr(str_repeat($mask, intdiv($n + 3, 4)), 0, $n);
            $buf  = (string)substr($buf, $p + 4 + $n);
            if ($op >= 8) {
                $out[] = [$op, $data];
            } elseif ($op === 0) {
                if ($frag === null) {
                    throw new \RuntimeException('WebSocket: continuation with no message begun');
                }
                $frag[1] .= $data;
                if ($fin) {
                    $out[] = $frag;
                    $frag  = null;
                }
            } elseif ($frag !== null) {
                throw new \RuntimeException('WebSocket: a new message before the last one ended');
            } elseif ($fin) {
                $out[] = [$op, $data];
            } else {
                $frag = [$op, $data];
            }
        }
        return $out;
    }
}
