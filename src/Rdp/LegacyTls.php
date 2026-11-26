<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk\Rdp;

use MuDesk\Config;

/**
 * TLS 1.0, client side (RFC 2246, with RFC 4492's ECDHE and RFC 5746's renegotiation SCSV), for the computers that have
 * nothing newer: Windows 7, Server 2008 R2 and 2008 without their TLS 1.2 update, and Vista. PHP's own TLS (OpenSSL 3)
 * cannot reach Windows 7 and Server 2008 R2 as first released: OpenSSL refuses a server without secure renegotiation
 * (their update of August 2010), and PHP always sends TLS 1.0's empty records before data, which their TLS answers with
 * an alert; neither can be changed from PHP. Here:
 * - key exchange: ECDHE on P-256 or P-384 offered first (each session's own key: what is recorded cannot be read later with
 *   the server's key), else RSA (the server chooses: Windows 7 and Server 2008 R2 put RSA first in their own order); the
 *   server's ECDHE parameters checked against its certificate's key (MD5 and SHA-1, as TLS 1.0);
 * - AES-128, AES-256 or triple DES in CBC with HMAC-SHA1; padding and MAC checked, any record changed on the way refused;
 * - renegotiation never done (the SCSV says so, a server's HelloRequest is ignored, its renegotiation_info must be empty);
 * - data split 1/n-1 (one byte, then the rest) instead of empty records (BEAST), once the connection's security is done
 *   ($split, set by Transport::splitRecords()): NLA's messages go whole, as Windows 7 and Server 2008 R2 as first released
 *   answer one split into records with an alert (internal error).
 * The certificate is not trusted here: the caller pins it (Client), as with PHP's TLS. OpenSSL does the cipher, the hashes,
 * RSA and ECDH (PHP's openssl functions); this class the protocol.
 */
final class LegacyTls
{
    private const VERSION = "\x03\x01";
    private const RECORD_MAX = 16384 + 2048;            // a TLS 1.0 record's largest body (encrypted)
    private const HANDSHAKE_MAX = 262144;               // a server's whole handshake (certificates: a few KB)

    /** suite => [key exchange, cipher, key bytes, block bytes] */
    private const SUITES = [
        0xC013 => ['ecdhe', 'aes-128-cbc', 16, 16],     // TLS_ECDHE_RSA_WITH_AES_128_CBC_SHA
        0xC014 => ['ecdhe', 'aes-256-cbc', 32, 16],
        0x002F => ['rsa', 'aes-128-cbc', 16, 16],       // TLS_RSA_WITH_AES_128_CBC_SHA
        0x0035 => ['rsa', 'aes-256-cbc', 32, 16],
        0x000A => ['rsa', 'des-ede3-cbc', 24, 8],       // TLS_RSA_WITH_3DES_EDE_CBC_SHA, last
    ];
    private const NAMES = [0xC013 => 'ECDHE-RSA-AES128-SHA', 0xC014 => 'ECDHE-RSA-AES256-SHA', 0x002F => 'AES128-SHA',
        0x0035 => 'AES256-SHA', 0x000A => 'DES-CBC3-SHA'];
    /** named curve => [OpenSSL's name, its OID (DER), coordinate bytes] */
    private const CURVES = [23 => ['prime256v1', "\x06\x08\x2A\x86\x48\xCE\x3D\x03\x01\x07", 32], 24 => ['secp384r1', "\x06\x05\x2B\x81\x04\x00\x22", 48]];

    private string $in = '';                           // bytes received, not yet a whole record
    private string $hsIn = '';                         // handshake bytes received, not yet a whole message
    private string $hs = '';                           // the handshake's messages so far (for the Finished messages)
    private string $cipher = '';
    private int $block = 0;
    private string $wMac = '';
    private string $rMac = '';
    private string $wKey = '';
    private string $rKey = '';
    private string $wIv = '';
    private string $rIv = '';
    private int $wSeq = 0;
    private int $rSeq = 0;
    private bool $wOn = false;                         // records encrypted, each way (after ChangeCipherSpec)
    private bool $rOn = false;
    private bool $done = false;
    /** The server closed the connection (close_notify) */
    public bool $closed = false;
    /** The cipher suite agreed, by OpenSSL's name (for the diagnostic trace) */
    public string $name = '';
    /** Application data split 1/n-1 (see the class comment): off until the connection's security is done */
    public bool $split = false;

    /**
     * The handshake: $write sends bytes, $read waits for more ('' when the connection ended or its time is up). Returns the
     * server's certificate.
     */
    public function handshake(\Closure $write, \Closure $read): \OpenSSLCertificate
    {
        $clientRandom = random_bytes(32);
        $suites = '';
        foreach (array_keys(self::SUITES) as $s) {
            $suites .= pack('n', $s);
        }
        $suites .= "\x00\xFF";                                  // TLS_EMPTY_RENEGOTIATION_INFO_SCSV
        $ext    = pack('nnnnn', 10, 6, 4, 23, 24)               // supported_groups: secp256r1, secp384r1
                . pack('nnCC', 11, 2, 1, 0);                    // ec_point_formats: uncompressed
        $write($this->record(22, $this->message(1, self::VERSION . $clientRandom . "\x00" . pack('n', strlen($suites)) . $suites
            . "\x01\x00" . pack('n', strlen($ext)) . $ext)));

        [$type, $body] = $this->nextMessage($read);
        if ($type !== 2) {
            throw new ProtocolException('TLS: no ServerHello', 'tls');
        }
        [$serverRandom, $suite] = $this->serverHello($body);
        [$kx, $this->cipher, $keyLen, $this->block] = self::SUITES[$suite];
        $this->name = self::NAMES[$suite];

        [$type, $body] = $this->nextMessage($read);
        if ($type !== 11) {
            throw new ProtocolException('TLS: no server certificate', 'tls');
        }
        $cert = self::certificate($body);
        $key  = openssl_pkey_get_public($cert);
        if ($key === false || (openssl_pkey_get_details($key)['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
            throw new ProtocolException('TLS: the server certificate has no RSA key', 'tls');
        }

        $params = null;
        [$type, $body] = $this->nextMessage($read);
        if ($type === 12) {                                     // ServerKeyExchange (ECDHE only)
            if ($kx !== 'ecdhe') {
                throw new ProtocolException('TLS: key exchange not asked for', 'tls');
            }
            $params = $this->serverKeyExchange($body, $key, $clientRandom . $serverRandom);
            [$type, $body] = $this->nextMessage($read);
        } elseif ($kx === 'ecdhe') {
            throw new ProtocolException('TLS: no ECDHE parameters', 'tls');
        }
        $askedCert = $type === 13;                              // CertificateRequest: answered with none (RFC 2246 7.4.6)
        if ($askedCert) {
            [$type, $body] = $this->nextMessage($read);
        }
        if ($type !== 14 || $body !== '') {
            throw new ProtocolException('TLS: no ServerHelloDone', 'tls');
        }

        if ($params !== null) {                                 // our share of ECDH, and the secret both have
            [$curve, $point] = $params;
            [$premaster, $mine] = self::ecdh($curve, $point);
            $ckx = chr(strlen($mine)) . $mine;
        } else {                                                // a secret of ours, encrypted to the certificate's key
            $premaster = self::VERSION . random_bytes(46);
            if (!openssl_public_encrypt($premaster, $enc, $key, OPENSSL_PKCS1_PADDING)) {
                throw new ProtocolException('TLS: RSA encryption failed', 'tls');
            }
            $ckx = pack('n', strlen($enc)) . $enc;
        }
        $master = self::prf($premaster, 'master secret', $clientRandom . $serverRandom, 48);
        $b      = self::prf($master, 'key expansion', $serverRandom . $clientRandom, 2 * (20 + $keyLen + $this->block));
        [$this->wMac, $this->rMac] = [substr($b, 0, 20), substr($b, 20, 20)];
        [$this->wKey, $this->rKey] = [substr($b, 40, $keyLen), substr($b, 40 + $keyLen, $keyLen)];
        [$this->wIv, $this->rIv]   = [substr($b, 40 + 2 * $keyLen, $this->block), substr($b, 40 + 2 * $keyLen + $this->block, $this->block)];

        $out = ($askedCert ? $this->record(22, $this->message(11, "\x00\x00\x00")) : '') . $this->record(22, $this->message(16, $ckx)) . $this->record(20, "\x01");
        $this->wOn = true;
        $out .= $this->record(22, $this->message(20, self::prf($master, 'client finished', md5($this->hs, true) . sha1($this->hs, true), 12)));
        $write($out);

        [$type, $body] = $this->nextRecord($read);
        if ($type !== 20 || $body !== "\x01") {
            throw $type === 21 ? $this->alert($body, true) : new ProtocolException('TLS: no ChangeCipherSpec', 'tls');
        }
        $this->rOn = true;
        $want = self::prf($master, 'server finished', md5($this->hs, true) . sha1($this->hs, true), 12);
        [$type, $body] = $this->nextMessage($read);
        if ($type !== 20 || !hash_equals($want, $body) || $this->hsIn !== '') {
            throw new ProtocolException('TLS: the server Finished is not right', 'mitm');
        }
        $this->done = true;
        return $cert;
    }

    /** Application data as records (1/n-1 split, see the class comment). */
    public function seal(string $data): string
    {
        $out = '';
        foreach (str_split($data, 16384) as $chunk) {
            $out .= $this->split && strlen($chunk) > 1 ? $this->record(23, $chunk[0]) . $this->record(23, substr($chunk, 1)) : $this->record(23, $chunk);
        }
        return $out;
    }

    /** Takes bytes received; returns the application data of the whole records so far (the rest waits for its end). */
    public function open(string $data): string
    {
        $this->in .= $data;
        $out = '';
        while (!$this->closed && strlen($this->in) >= 5) {
            $len = unpack('n', $this->in, 3)[1];
            if ($len > self::RECORD_MAX) {
                throw new ProtocolException('TLS: record too large', 'tls');
            }
            if (strlen($this->in) < 5 + $len) {
                break;
            }
            [$type, $body] = $this->take($len);
            if ($type === 23) {
                $out .= $body;
            } elseif ($type === 21) {
                $e = $this->alert($body, false);
                if ($e !== null) {
                    throw $e;
                }
            } elseif ($type !== 22 || $body !== "\x00\x00\x00\x00") {   // (a HelloRequest: no renegotiation, ignored)
                throw new ProtocolException('TLS: unexpected record', 'tls');
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------ handshake messages

    /** A handshake message (kept for the Finished messages). */
    private function message(int $type, string $body): string
    {
        $m = chr($type) . substr(pack('N', strlen($body)), 1) . $body;
        $this->hs .= $m;
        return $m;
    }

    /** The next handshake message from the server: [type, body] (kept for the Finished messages). */
    private function nextMessage(\Closure $read): array
    {
        while (true) {
            if (strlen($this->hsIn) >= 4) {
                $len = unpack('N', "\0" . substr($this->hsIn, 1, 3))[1];
                if ($len > self::HANDSHAKE_MAX) {
                    throw new ProtocolException('TLS: handshake message too large', 'tls');
                }
                if (strlen($this->hsIn) >= 4 + $len) {
                    $m          = substr($this->hsIn, 0, 4 + $len);
                    $this->hsIn = substr($this->hsIn, 4 + $len);
                    if (ord($m[0]) !== 20) {                    // (the server's Finished is checked against the rest)
                        $this->hs .= $m;
                    }
                    return [ord($m[0]), substr($m, 4)];
                }
            }
            [$type, $body] = $this->nextRecord($read);
            if ($type === 21) {
                throw $this->alert($body, true);
            }
            if ($type !== 22 || strlen($this->hsIn) + strlen($body) > self::HANDSHAKE_MAX) {
                throw new ProtocolException('TLS: unexpected record in the handshake', 'tls');
            }
            $this->hsIn .= $body;
        }
    }

    /** The next whole record: [type, body]. */
    private function nextRecord(\Closure $read): array
    {
        while (strlen($this->in) < 5 || strlen($this->in) < 5 + unpack('n', $this->in, 3)[1]) {
            if (strlen($this->in) >= 5 && unpack('n', $this->in, 3)[1] > self::RECORD_MAX) {
                throw new ProtocolException('TLS: record too large', 'tls');
            }
            $d = $read();
            if ($d === '') {
                throw new ProtocolException('TLS: the connection ended during the handshake', 'tls');
            }
            $this->in .= $d;
        }
        return $this->take(unpack('n', $this->in, 3)[1]);
    }

    /** The record at the start of what was received, decrypted when encryption is on that way. */
    private function take(int $len): array
    {
        $type     = ord($this->in[0]);
        $body     = substr($this->in, 5, $len);
        $major    = ord($this->in[1]);
        $this->in = substr($this->in, 5 + $len);
        if ($major !== 3) {
            throw new ProtocolException('TLS: not a TLS record', 'tls');
        }
        return [$type, $this->rOn ? $this->decrypt($type, $body) : $body];
    }

    /** [server random, cipher suite]: TLS 1.0, one of ours, no compression, secure renegotiation's extension empty. */
    private function serverHello(string $b): array
    {
        $r = new Reader($b);
        if ($r->bytes(2) !== self::VERSION) {
            throw new ProtocolException('TLS: the server did not choose TLS 1.0', 'tls');
        }
        $random = $r->bytes(32);
        $r->skip($r->u8());                                    // session id
        $suite = $r->u16be();
        if (!isset(self::SUITES[$suite]) || $r->u8() !== 0) {
            throw new ProtocolException('TLS: the server chose a cipher not offered', 'tls');
        }
        if ($r->left() >= 2) {
            $ext = new Reader($r->bytes($r->u16be()));
            while ($ext->left() >= 4) {
                $id = $ext->u16be();
                $v  = $ext->bytes($ext->u16be());
                if ($id === 0xFF01 && $v !== "\x00") {          // renegotiation_info: empty on a first handshake
                    throw new ProtocolException('TLS: renegotiation_info not empty', 'mitm');
                }
            }
        }
        return [$random, $suite];
    }

    /** The server's certificate: the first of its list. */
    private static function certificate(string $b): \OpenSSLCertificate
    {
        $r = new Reader($b);
        $r->skip(3);                                            // the list's length
        $der  = $r->bytes(unpack('N', "\0" . $r->bytes(3))[1]);
        $cert = openssl_x509_read("-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END CERTIFICATE-----\n");
        if ($cert === false) {
            throw new ProtocolException('TLS: the server certificate cannot be read', 'tls');
        }
        return $cert;
    }

    /**
     * ServerKeyExchange of ECDHE: its named curve (one of ours) and point, signed with the certificate's key over both
     * randoms and the parameters (TLS 1.0: MD5 and SHA-1 together, PKCS #1 without a DigestInfo). Returns [curve, point].
     */
    private function serverKeyExchange(string $b, \OpenSSLAsymmetricKey $key, string $randoms): array
    {
        $r = new Reader($b);
        if ($r->u8() !== 3 || !isset(self::CURVES[$curve = $r->u16be()])) {
            throw new ProtocolException('TLS: ECDHE on a curve not offered', 'tls');
        }
        $point  = $r->bytes($r->u8());
        $params = substr($b, 0, $r->pos);
        $sig    = $r->bytes($r->u16be());
        if (strlen($point) !== 1 + 2 * self::CURVES[$curve][2] || $point[0] !== "\x04") {
            throw new ProtocolException('TLS: bad ECDHE point', 'tls');
        }
        $want = md5($randoms . $params, true) . sha1($randoms . $params, true);
        if (!openssl_public_decrypt($sig, $got, $key, OPENSSL_PKCS1_PADDING) || !hash_equals($want, (string)$got)) {
            throw new ProtocolException('TLS: ECDHE parameters not signed by the server', 'mitm');
        }
        return [$curve, $point];
    }

    /** ECDH on $curve with the server's $point: [the shared secret, our point]. */
    private static function ecdh(int $curve, string $point): array
    {
        [$name, $oid, $n] = self::CURVES[$curve];
        // (PHP for Windows has no OpenSSL configuration of its own, and makes no key without one: an empty one, removed)
        $cnf  = (string)tempnam(Config::dir('tmp'), 'cnf');
        $mine = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => $name, 'config' => $cnf, 'private_key_bits' => 2048]);   // (bits: checked for any key)
        @unlink($cnf);
        $spki = Der::seq(Der::seq("\x06\x07\x2A\x86\x48\xCE\x3D\x02\x01" . $oid), Der::tlv(0x03, "\x00" . $point));
        $peer = openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n");
        $secret = $mine !== false && $peer !== false ? openssl_pkey_derive($peer, $mine) : false;
        if ($secret === false || strlen($secret) !== $n) {
            throw new ProtocolException('TLS: ECDH failed', 'tls');
        }
        $xy = openssl_pkey_get_details($mine)['ec'];
        return [$secret, "\x04" . str_pad($xy['x'], $n, "\0", STR_PAD_LEFT) . str_pad($xy['y'], $n, "\0", STR_PAD_LEFT)];
    }

    /**
     * An alert: a close_notify ends the connection (null), a warning is passed over (null), anything else as an exception.
     * After the handshake a fatal one is the server ending the connection ('closed', as PHP's TLS reports it): Windows Server
     * 2008 and 2008 R2 answer a wrong password in NLA with access_denied (49), which was shown as a TLS fault, not asked again.
     */
    private function alert(string $body, bool $handshake): ?ProtocolException
    {
        if (strlen($body) !== 2) {
            return new ProtocolException('TLS: bad alert', 'tls');
        }
        [$level, $what] = [ord($body[0]), ord($body[1])];
        if ($what === 0) {
            $this->closed = true;
            return $handshake ? new ProtocolException('TLS: the server closed the connection', 'tls') : null;
        }
        return $level === 1 && !$handshake ? null : new ProtocolException("TLS alert $what from the server", $handshake ? 'tls' : 'closed');
    }

    // ------------------------------------------------------------------ records

    private function record(int $type, string $data): string
    {
        $body = $this->wOn ? $this->encrypt($type, $data) : $data;
        return chr($type) . self::VERSION . pack('n', strlen($body)) . $body;
    }

    /** MAC-then-encrypt (TLS 1.0): HMAC-SHA1 over the sequence number, the header and the data; padding; CBC on from the last record. */
    private function encrypt(int $type, string $data): string
    {
        $mac = hash_hmac('sha1', pack('J', $this->wSeq++) . chr($type) . self::VERSION . pack('n', strlen($data)) . $data, $this->wMac, true);
        $p   = $data . $mac;
        $pad = ($this->block - (strlen($p) + 1) % $this->block) % $this->block;
        $c   = openssl_encrypt($p . str_repeat(chr($pad), $pad + 1), $this->cipher, $this->wKey, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $this->wIv);
        if ($c === false) {
            throw new ProtocolException('TLS: encryption failed', 'tls');
        }
        $this->wIv = substr($c, -$this->block);
        return $c;
    }

    /** A record decrypted, its padding and MAC checked (both before saying anything: no hint of which one failed). */
    private function decrypt(int $type, string $c): string
    {
        $b = $this->block;
        $p = strlen($c) >= 24 && strlen($c) % $b === 0
            ? openssl_decrypt($c, $this->cipher, $this->rKey, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $this->rIv) : false;
        if ($p === false || strlen($p) < 21) {
            throw new ProtocolException('TLS: bad record', 'tls');
        }
        $this->rIv = substr($c, -$b);
        // padding and MAC checked with the same work whatever the padding says, as far as PHP allows (Lucky13): the padding over
        // its 256 possible bytes without stopping at a difference; with a bad one, the MAC as if there were none (RFC 4346
        // 6.2.3.2); the MAC's hashing made up to the same length (the padding's bytes hashed apart). Before, compared up to the
        // first difference and hashed over a length the padding set. (Each failure ends the connection: no retries to time)
        $n    = strlen($p);
        $pad  = ord($p[$n - 1]);
        $bad  = (($n - 21 - $pad) >> 62) & 1;              // (longer than the record)
        for ($i = 1, $top = min(256, $n); $i <= $top; $i++) {
            $in   = ((($pad + 1 - $i) >> 62) & 1) ^ 1;       // (the byte is within the padding)
            $diff = ord($p[$n - $i]) ^ $pad;
            $bad |= $in & ((($diff | -$diff) >> 62) & 1);
        }
        $len  = $n - 21 - $pad * ($bad ^ 1);
        $data = substr($p, 0, $len);
        $want = hash_hmac('sha1', pack('J', $this->rSeq++) . chr($type) . self::VERSION . pack('n', $len) . $data, $this->rMac, true);
        hash_hmac('sha1', str_repeat("\0", $pad * ($bad ^ 1)), $this->rMac, true);
        if (!hash_equals($want, substr($p, $len, 20)) | $bad) {
            throw new ProtocolException('TLS: a record changed on the way', 'mitm');
        }
        return $data;
    }

    /** TLS 1.0's PRF: P_MD5 of the secret's first half XOR P_SHA1 of its second (RFC 2246 5). */
    public static function prf(string $secret, string $label, string $seed, int $len): string
    {
        $half = (int)ceil(strlen($secret) / 2);
        return self::pHash('md5', substr($secret, 0, $half), $label . $seed, $len) ^ self::pHash('sha1', substr($secret, -$half), $label . $seed, $len);
    }

    private static function pHash(string $algo, string $secret, string $seed, int $len): string
    {
        $out = '';
        for ($a = $seed; strlen($out) < $len;) {
            $a    = hash_hmac($algo, $a, $secret, true);
            $out .= hash_hmac($algo, $a . $seed, $secret, true);
        }
        return substr($out, 0, $len);
    }
}
