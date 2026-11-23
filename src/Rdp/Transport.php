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

    public function __construct(string $ip, int $port)
    {
        $ctx  = stream_context_create(['socket' => ['tcp_nodelay' => true], 'ssl' => [
            'verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true,
            'capture_peer_cert' => true, 'security_level' => 0, 'SNI_enabled' => false,
            // (triple DES last, where OpenSSL has it: Windows Server 2003's TLS has no AES; without it, Client falls back to
            // Standard RDP Security)
            'ciphers' => 'DEFAULT:DES-CBC3-SHA',
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

    public function startTls(): \OpenSSLCertificate
    {
        $m = STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
           | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0);
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

    /** TLS version and cipher, for the diagnostic trace. */
    public function tlsInfo(): array
    {
        $c = stream_get_meta_data($this->sock)['crypto'] ?? [];
        return ['protocol' => $c['protocol'] ?? '?', 'cipher' => $c['cipher_name'] ?? '?'];
    }

    public function send(string $data): void
    {
        if (!is_resource($this->sock)) {                       // (closed: a second disconnect, say)
            throw new ProtocolException('Connection closed', 'closed');
        }
        $this->bytesOut += strlen($data);
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
                return !feof($this->sock);
            }
            $got           += strlen($d);
            $this->bytesIn += strlen($d);
            $this->buf     .= $d;
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
