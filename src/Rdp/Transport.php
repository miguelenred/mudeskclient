<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk\Rdp;

/** TCP/TLS socket with framing helpers (TPKT, fast-path and DER). */
final class Transport
{
    /**
     * Seconds the connection phase waits for each step of the host (TCP connection, TLS handshake, each answer of X.224, NLA
     * and MCS): a Windows answers the whole handshake in 1-3 s, and a host that goes silent holds the PHP worker that long
     * (it was 20 s). The wait for the activation (licensing, logon) has its own limit in Client.
     */
    public const WAIT = 8;

    /**
     * Called about once a second while the connection phase waits for the host: rdp.php checks there that the browser is
     * still there (PHP only sees it gone when writing to it) and throws to give the worker back at once.
     */
    public static ?\Closure $waiting = null;

    /** @var resource */
    public $sock;
    private string $buf = '';
    public int $bytesIn = 0;
    public int $bytesOut = 0;

    /** The Remote Desktop Gateway this connection goes through (its socket is then the client's end of a loopback pair) */
    public ?Gateway $gw = null;

    /** TLS 1.0 made by MuDesk itself (startTls() with $legacy), when PHP's cannot reach the computer: see LegacyTls */
    private ?LegacyTls $tls = null;

    /*
     * TLS offered: 1.2 or later without triple DES first (PHP's); TLS 1.0 ("legacy": Windows 7, Server 2008 R2, 2008 and
     * Vista without their TLS 1.2 update) made by MuDesk itself (LegacyTls), only to a computer never seen with the first
     * (see tlsSeen()): someone in between who made the first handshake fail took any computer back to the weaker ones.
     * The certificate is checked by its pinned fingerprint (OpenSSL's security level 0: a SHA-1 or 1024-bit certificate of
     * those Windows). Before, TLS 1.0 was PHP's too: it never reached Windows 7 or Server 2008 R2 as first released.
     */
    private const TLS_STORE = 'tls';                 // data/tls.json: how TLS went with each computer and gateway
    private const TLS_KEEP  = 5000;                  // computers remembered (the latest)
    private const TLS_AGAIN = 86400;                 // seconds: a computer seen with TLS 1.0 only is offered TLS 1.2 first again after a day
    private const TLS_DAYS  = 365;                   // days a computer seen with TLS 1.2 is remembered after its last use

    /** The TLS versions offered (for stream_socket_enable_crypto()): 1.2 and 1.3, and 1.0 and 1.1 when legacy. */
    public static function tlsMethods(bool $legacy): int
    {
        return STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0)
            | ($legacy ? STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT : 0);
    }

    /** The cipher suites offered (OpenSSL's list): without triple DES, or with it last when legacy (where OpenSSL has it). */
    public static function tlsCiphers(bool $legacy): string
    {
        return $legacy ? 'DEFAULT:DES-CBC3-SHA' : 'DEFAULT:!3DES';
    }

    /**
     * How TLS went with $key (a computer's "host:port", a gateway's "gw:host:port"): 'modern' (TLS 1.2 or later seen there:
     * never offered less), 'legacy' (only TLS 1.0 there, within a day: offered at once, not after a failed handshake each
     * time), or null (not known: TLS 1.2 first, and TLS 1.0 if that fails).
     */
    public static function tlsSeen(string $key): ?string
    {
        try {
            $e = \MuDesk\Store::read(self::TLS_STORE)[$key] ?? null;
        } catch (\RuntimeException $x) {            // (its file cannot be read: as never seen, no connection refused for it)
            error_log('MuDeskClient: ' . $x->getMessage());
            return null;
        }
        if (!is_array($e) || !is_int($e[1] ?? null)) {
            return null;
        }
        return match ($e[0] ?? null) {
            'modern'                  => time() - $e[1] < self::TLS_DAYS * 86400 ? 'modern' : null,
            'tls10', 'none', 'legacy' => time() - $e[1] < self::TLS_AGAIN ? 'legacy' : null,   // ('legacy': written before 1.3)
            default                   => null,
        };
    }

    /**
     * Whether TLS worked with $key within a year (any version): a computer that then offers only Standard RDP Security is
     * refused, not taken (someone in between answering for it). Entries of earlier versions ('legacy': TLS 1.0, or no TLS
     * at all) do not count.
     */
    public static function hadTls(string $key): bool
    {
        try {
            $e = \MuDesk\Store::read(self::TLS_STORE)[$key] ?? null;
        } catch (\RuntimeException) {
            return false;
        }
        return is_array($e) && in_array($e[0] ?? null, ['modern', 'tls10'], true) && is_int($e[1] ?? null) && time() - $e[1] < self::TLS_DAYS * 86400;
    }

    /** Forgets how TLS went with $key (its certificate forgotten on purpose: a computer changed to Standard RDP Security). */
    public static function tlsForget(string $key): void
    {
        try {
            \MuDesk\Store::update(self::TLS_STORE, static function (array &$d) use ($key): void {
                unset($d[$key]);
            });
        } catch (\RuntimeException $x) {
            error_log('MuDeskClient: ' . $x->getMessage());
        }
    }

    /**
     * Remembers how TLS went with $key: $protocol, the version agreed (TLSv1.2 or TLSv1.3: "modern", even when TLS 1.0 was
     * offered too after a handshake that failed). Written when it changed, or once a day: not on every connection; never
     * a reason for a connection to fail (a file that cannot be written: logged).
     */
    public static function tlsWorked(string $key, string $protocol): void
    {
        $kind = in_array($protocol, ['TLSv1.2', 'TLSv1.3'], true) ? 'modern' : ($protocol === 'none' ? 'none' : 'tls10');   // ('none': no TLS shared)
        try {
            $e = \MuDesk\Store::read(self::TLS_STORE)[$key] ?? null;
            if (is_array($e) && ($e[0] ?? null) === $kind && is_int($e[1] ?? null) && time() - $e[1] < 86400) {
                return;
            }
            \MuDesk\Store::update(self::TLS_STORE, static function (array &$d) use ($key, $kind): void {
                unset($d[$key]);                      // (last: those kept are the latest)
                $d[$key] = [$kind, time()];
                $d       = array_slice($d, -self::TLS_KEEP, null, true);
            });
        } catch (\RuntimeException $x) {
            error_log('MuDeskClient: ' . $x->getMessage());
        }
    }

    /** The TLS version agreed on a stream ('?' when none). */
    public static function tlsProtocol($stream): string
    {
        return (string)(stream_get_meta_data($stream)['crypto']['protocol'] ?? '?');
    }

    /** A connection through a Remote Desktop Gateway already open to the remote computer. */
    public static function through(Gateway $gw): self
    {
        $t       = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $t->sock = $gw->inner();
        $t->gw   = $gw;
        return $t;
    }

    /**
     * Moves the gateway's bytes both ways (nothing without one); false once the tunnel ended and all it brought was given to
     * this socket (which then reads it, and then its end, as from a remote computer that closed).
     */
    public function pump(): bool
    {
        return $this->gw === null || $this->gw->pump();
    }

    /** The streams to wait on for reading: the socket, and the gateway's while it can bring something (pump() moves it). */
    public function streams(): array
    {
        return $this->gw === null ? [$this->sock] : [$this->sock, ...$this->gw->readable()];
    }

    /** The streams to wait on for writing: the gateway's while frames wait for it (nothing without one). */
    public function writeStreams(): array
    {
        return $this->gw === null ? [] : $this->gw->writable();
    }

    /**
     * OpenSSL's security level of PHP's TLS here (TLS 1.2 or later: TLS 1.0 is LegacyTls's): 1, keys of 1024 bits or more (a
     * Windows Server 2008 R2 or 2012 gateway's certificate can be one of 1024 bits; a system's level 2 refused it). Before, 0
     * on every connection: keys of 512 bits taken on the first attempt too.
     */
    public const SECURITY_LEVEL = 1;

    public function __construct(string $ip, int $port)
    {
        $ctx  = stream_context_create(['socket' => ['tcp_nodelay' => true], 'ssl' => [
            'verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true,
            'capture_peer_cert' => true, 'security_level' => self::SECURITY_LEVEL, 'SNI_enabled' => false,   // (the ciphers: startTls())
        ]]);
        $addr = str_contains($ip, ':') ? "[$ip]" : $ip;
        // the timeout also limits the TLS handshake (PHP applies a client's connection timeout to it)
        $s    = @stream_socket_client("tcp://$addr:$port", $errno, $err, self::WAIT, STREAM_CLIENT_CONNECT, $ctx);
        if ($s === false) {
            throw new ProtocolException("TCP: $err ($errno)", 'unreachable');
        }
        $this->sock = $s;
        stream_set_blocking($s, false);
    }

    /** Whether $ip:$port takes a TCP connection within $timeout seconds (closed at once: nothing is sent). */
    public static function reachable(string $ip, int $port, float $timeout): bool
    {
        $s = @stream_socket_client('tcp://' . (str_contains($ip, ':') ? "[$ip]" : $ip) . ":$port", $errno, $err, $timeout);
        if ($s === false) {
            return false;
        }
        fclose($s);
        return true;
    }

    /** The TLS handshake: TLS 1.2 or later without triple DES (PHP's), or with $legacy TLS 1.0 (LegacyTls; see tlsSeen()). */
    public function startTls(bool $legacy = false): \OpenSSLCertificate
    {
        if ($legacy) {
            return $this->legacyTls();
        }
        $m = self::tlsMethods(false);
        stream_context_set_option($this->sock, 'ssl', 'ciphers', self::tlsCiphers(false));
        if ($this->gw !== null) {
            // through a gateway: the handshake goes through pump(), so it is made step by step (blocking, nothing pumped it)
            $end = microtime(true) + self::WAIT;
            while (($ok = @stream_socket_enable_crypto($this->sock, true, $m)) === 0) {
                if (microtime(true) > $end) {
                    throw new ProtocolException('TLS handshake through the gateway failed', 'tls');
                }
                $this->pump();                         // (a tunnel that ended: the handshake sees the end and fails)
                $this->waitStreams(0.05);
            }
            if ($ok !== true) {
                throw new ProtocolException('TLS handshake failed: ' . (error_get_last()['message'] ?? ''), 'tls');
            }
        } else {
            stream_set_blocking($this->sock, true);
            if (!@stream_socket_enable_crypto($this->sock, true, $m)) {
                throw new ProtocolException('TLS handshake failed: ' . (error_get_last()['message'] ?? ''), 'tls');
            }
        }
        $cert = stream_context_get_params($this->sock)['options']['ssl']['peer_certificate'] ?? null;
        if (!$cert instanceof \OpenSSLCertificate) {
            throw new ProtocolException('TLS: no server certificate', 'tls');
        }
        stream_set_blocking($this->sock, false);
        return $cert;
    }

    /** TLS 1.0 made here: the handshake (through the gateway's pump() too), what came after it kept for reading. */
    private function legacyTls(): \OpenSSLCertificate
    {
        $end       = microtime(true) + self::WAIT;
        $this->tls = new LegacyTls();
        $cert      = $this->tls->handshake(fn(string $d) => $this->write($d), function () use ($end): string {
            while (true) {
                $d = @fread($this->sock, 65536);
                if ($d !== false && $d !== '') {
                    return $d;
                }
                if (feof($this->sock) || microtime(true) > $end) {
                    return '';
                }
                $this->wait(min(1.0, max(0.0, $end - microtime(true))));   // (moves the gateway's bytes too)
            }
        });
        $this->buf .= $this->tls->open('');
        return $cert;
    }

    /** The connection's security done (NLA's messages sent): TLS 1.0 made here splits its records from now on (LegacyTls). */
    public function splitRecords(): void
    {
        if ($this->tls !== null) {
            $this->tls->split = true;
        }
    }

    /** TLS version and cipher, for the diagnostic trace. */
    public function tlsInfo(): array
    {
        if ($this->tls !== null) {
            return ['protocol' => 'TLSv1', 'cipher' => $this->tls->name];
        }
        $c = stream_get_meta_data($this->sock)['crypto'] ?? [];
        return ['protocol' => $c['protocol'] ?? '?', 'cipher' => $c['cipher_name'] ?? '?'];   // (see tlsProtocol())
    }

    public function send(string $data): void
    {
        $this->bytesOut += strlen($data);
        $this->write($this->tls !== null ? $this->tls->seal($data) : $data);
    }

    /** Writes bytes as they are (the TLS records of LegacyTls, or the stream's own). */
    private function write(string $data): void
    {
        if (!is_resource($this->sock)) {                       // (closed: a second disconnect, say)
            throw new ProtocolException('Connection closed', 'closed');
        }
        $until = microtime(true) + 30;                         // a server reading a byte now and then held the worker for ever
        while ($data !== '') {
            if (microtime(true) > $until) {
                throw new ProtocolException('Write timeout', 'closed');
            }
            $n = @fwrite($this->sock, $data);
            if ($n === false) {
                throw new ProtocolException('Connection lost (write)', 'closed');
            }
            if ($n === 0) {
                if (feof($this->sock)) { // reset by the peer: TLS writes return 0, not false, and the socket stays writable
                    throw new ProtocolException('Connection lost (write)', 'closed');
                }
                if ($this->gw !== null) {               // the pair is full until the tunnel takes it: the gateway waited on
                    if (!$this->pump()) {                // (its socket, not this one: readable as it is, it made this spin)
                        throw new ProtocolException('Gateway closed the tunnel', 'closed');
                    }
                    $this->gw->wait(0.05);
                    continue;
                }
                $r = $e = null;
                $w = [$this->sock];
                if (@stream_select($r, $w, $e, 15) < 1) {
                    throw new ProtocolException('Write timeout', 'closed');
                }
                continue;
            }
            $data = substr($data, $n);
        }
    }

    /** Reads everything currently available. Returns false when the peer closed the connection. */
    public function drain(): bool
    {
        for ($got = 0; $got < 4194304;) {                     // 4 MB at a time: a server sending without pause was read without end
            $d = @fread($this->sock, 65536);
            if ($d === false || $d === '') {
                return !feof($this->sock) && !$this->tls?->closed;
            }
            $got           += strlen($d);
            $this->bytesIn += strlen($d);
            $this->buf     .= $this->tls !== null ? $this->tls->open($d) : $d;
        }
        return true;
    }

    public function wait(float $timeout): bool
    {
        if ($this->gw !== null) {                       // the tunnel's bytes moved while waiting for the remote computer's
            $end = microtime(true) + $timeout;
            do {
                $this->pump();                         // (a tunnel that ended: its last bytes, then its end, read here)
                $r = [$this->sock];
                $w = $e = null;
                if (@stream_select($r, $w, $e, 0) > 0) {
                    return true;
                }
                $this->waitStreams(min(0.05, max(0.0, $end - microtime(true))));
            } while (microtime(true) < $end);
            return false;
        }
        $r = [$this->sock];
        $w = $e = null;
        return @stream_select($r, $w, $e, (int)$timeout, (int)(fmod($timeout, 1) * 1e6)) > 0;
    }

    private function waitStreams(float $timeout): void
    {
        $r = $this->streams();
        $w = $this->writeStreams() ?: null;
        $e = null;
        @stream_select($r, $w, $e, 0, (int)($timeout * 1e6));
    }

    private function fill(int $n, float $timeout = self::WAIT): void
    {
        $end = microtime(true) + $timeout;
        while (strlen($this->buf) < $n) {
            $alive = $this->drain();
            if (strlen($this->buf) >= $n) {
                break;                                     // (what came before the close is read: a server says why, then closes)
            }
            if (!$alive) {
                throw new ProtocolException('Connection closed by server', 'closed');
            }
            $left = $end - microtime(true);
            if ($left <= 0) {
                throw new ProtocolException('Timeout waiting for server', 'timeout');
            }
            if (!$this->wait(min($left, 1.0)) && self::$waiting !== null) {
                (self::$waiting)();
            }
        }
    }

    private function take(int $n): string
    {
        $s         = substr($this->buf, 0, $n);
        $this->buf = substr($this->buf, $n);
        return $s;
    }

    /** Reads exactly $n bytes (RDSTLS's PDUs, of a known size). */
    public function recv(int $n, float $timeout = self::WAIT): string
    {
        $this->fill($n, $timeout);
        return $this->take($n);
    }

    /** Reads a complete DER element (CredSSP TSRequest). */
    public function recvDer(): string
    {
        $this->fill(2);
        $l = ord($this->buf[1]);
        $h = 2;
        if ($l & 0x80) {
            $h = 2 + ($l & 0x7f);
            if ($h > 6) {
                throw new ProtocolException('Invalid TSRequest length', 'protocol');
            }
            $this->fill($h);
            $l = 0;
            for ($i = 2; $i < $h; $i++) {
                $l = ($l << 8) | ord($this->buf[$i]);
            }
        }
        if ($l > 1048576) {                               // TSRequests are a few KB: the length is not trusted further
            throw new ProtocolException('TSRequest too large', 'protocol');
        }
        $this->fill($h + $l);
        return $this->take($h + $l);
    }

    /** Size of the PDU starting at $p (TPKT or fast-path header). */
    private function pduSize(int $p = 0): int
    {
        if (ord($this->buf[$p]) === 3) {
            return unpack("n", $this->buf, $p + 2)[1];
        }
        $b = ord($this->buf[$p + 1]);
        return $b & 0x80 ? (($b & 0x7f) << 8) | ord($this->buf[$p + 2]) : $b;
    }

    /** Blocking read of one PDU (connection phase). */
    public function recvPdu(float $timeout = self::WAIT): string
    {
        $this->fill(4, $timeout);
        $n = $this->pduSize();
        if ($n < 4) {
            throw new ProtocolException('Invalid PDU length', 'protocol');
        }
        $this->fill($n, $timeout);
        return $this->take($n);
    }

    /** Complete PDUs already buffered (after drain()). */
    public function pdus(): array
    {
        $out = [];
        $pos = 0;
        $len = strlen($this->buf);
        while ($len - $pos >= 4) {
            $n = $this->pduSize($pos);
            if ($n < 4) {
                throw new ProtocolException('Invalid PDU length', 'protocol');
            }
            if ($len - $pos < $n) {
                break;
            }
            $out[] = substr($this->buf, $pos, $n);
            $pos  += $n;
        }
        if ($pos > 0) {
            $this->buf = substr($this->buf, $pos);
        }
        return $out;
    }

    public function close(): void
    {
        if (is_resource($this->sock)) {
            @fclose($this->sock);
        }
        $this->gw?->close();
    }
}
