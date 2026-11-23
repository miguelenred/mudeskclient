<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk\Rdp;

/**
 * Standard RDP Security (MS-RDPBCGR 5.3): what Windows XP and Server 2003 speak, and later Windows set to "RDP Security
 * Layer". The server's key (its proprietary certificate or an X.509 chain), the client random encrypted with it, the
 * session keys made from both randoms, and every PDU after that encrypted: RC4 with 40, 56 or 128-bit keys and a MAC of
 * MD5 and SHA-1 (renewed every 4096 PDUs), or for "FIPS compliant" servers triple DES in CBC mode and an HMAC-SHA1.
 *
 * It does not authenticate the server (the key Microsoft signs proprietary certificates with is public): the caller pins
 * the server's key, as it pins a TLS certificate.
 */
final class RdpSecurity
{
    public const SEC_EXCHANGE_PKT    = 0x0001;
    public const SEC_ENCRYPT         = 0x0008;
    public const SEC_INFO_PKT        = 0x0040;
    public const SEC_LICENSE_PKT     = 0x0080;
    public const SEC_LICENSE_ENCRYPT = 0x0200;   // SEC_LICENSE_ENCRYPT_CS: the client's licensing PDUs encrypted too
    public const SEC_REDIRECTION_PKT = 0x0400;
    public const SEC_SECURE_CHECKSUM = 0x0800;

    public const FIPS = 0x10;
    private const PAD1 = 40;                     // (0x36 bytes in the MAC and the key update)
    private const PAD2 = 48;                     // (0x5C bytes)
    private const FIPS_IV = "\x12\x34\x56\x78\x90\xAB\xCD\xEF";

    private string $macKey;
    private string $encInitial;
    private string $decInitial;
    private string $encKey;
    private string $decKey;
    private ?Rc4 $enc = null;
    private ?Rc4 $dec = null;
    private int $encUses = 0;                    // PDUs encrypted with the current key (renewed at 4096)
    private int $decUses = 0;
    private int $encCount = 0;                   // all the PDUs encrypted (a salted MAC's count, FIPS's)
    private int $decCount = 0;
    private string $encIv = self::FIPS_IV;       // (FIPS: the CBC chain goes on from PDU to PDU)
    private string $decIv = self::FIPS_IV;
    /**
     * The server's encryption level (2: client compatible, 3: high, 4: FIPS): from 2 on everything it sends is encrypted, and a
     * PDU that is not (but for licensing) refused. Before, one in the clear was taken at any level: a man in the middle who
     * could not break the key could still put text in the clipboard, files in the shared folder or pictures on the screen.
     */
    public int $level = 0;

    /**
     * $method: the server's choice (1: 40-bit, 2: 128-bit, 8: 56-bit, 0x10: FIPS). $server: the server's side (its keys are
     * the client's the other way round: the tests' servers).
     */
    public function __construct(public readonly int $method, string $clientRandom, string $serverRandom, bool $server = false)
    {
        if ($method === self::FIPS) {
            $t = static fn(string $c, string $s): string => ($h = sha1($c . $s, true)) . $h[0];
            $encT = $t(substr($clientRandom, 16, 16), substr($serverRandom, 16, 16));
            $decT = $t(substr($clientRandom, 0, 16), substr($serverRandom, 0, 16));
            [$this->encKey, $this->decKey] = $server ? [self::expand($decT), self::expand($encT)] : [self::expand($encT), self::expand($decT)];
            $this->macKey = sha1(substr($decT, 0, 20) . substr($encT, 0, 20), true);
            return;
        }
        if (!in_array($method, [1, 2, 8], true)) {
            throw new ProtocolException("Standard RDP Security: encryption method $method", 'rdp_security');
        }
        // MS-RDPBCGR 5.3.5.1: the master secret from both randoms, the session key blob from it
        $salted = static fn(string $s, string $i): string => md5($s . sha1($i . $s . $clientRandom . $serverRandom, true), true);
        $pre    = substr($clientRandom, 0, 24) . substr($serverRandom, 0, 24);
        $master = $salted($pre, 'A') . $salted($pre, 'BB') . $salted($pre, 'CCC');
        $blob   = $salted($master, 'X') . $salted($master, 'YY') . $salted($master, 'ZZZ');
        $final  = static fn(string $k): string => md5($k . $clientRandom . $serverRandom, true);
        [$mac, $dec, $enc] = [substr($blob, 0, 16), $final(substr($blob, 16, 16)), $final(substr($blob, 32, 16))];
        if ($method !== 2) {                    // 40 and 56 bits: the first 8 bytes, salted
            [$mac, $dec, $enc] = [self::salt($mac, $method), self::salt($dec, $method), self::salt($enc, $method)];
        }
        [$enc, $dec] = $server ? [$dec, $enc] : [$enc, $dec];
        [$this->macKey, $this->encInitial, $this->decInitial, $this->encKey, $this->decKey] = [$mac, $enc, $dec, $enc, $dec];
        $this->enc = new Rc4($enc);
        $this->dec = new Rc4($dec);
    }

    private static function salt(string $key, int $method): string
    {
        return ($method === 1 ? "\xD1\x26\x9E" : "\xD1") . substr($key, $method === 1 ? 3 : 1, $method === 1 ? 5 : 7);
    }

    /** A FIPS key of 21 bytes made the 24 of triple DES: a parity bit after every 7 bits (as Windows does, bits reversed). */
    private static function expand(string $in): string
    {
        $rev = static function (int $b): int {
            $r = 0;
            for ($i = 0; $i < 8; $i++) {
                $r = ($r << 1) | (($b >> $i) & 1);
            }
            return $r;
        };
        $buf = array_map($rev, array_values(unpack('C*', $in)));
        $buf[] = 0;
        $out = '';
        for ($i = 0, $b = 0; $i < 24; $i++, $b += 7) {
            [$p, $r] = [intdiv($b, 8), $b % 8];
            $c = $r <= 1 ? ($buf[$p] << $r) & 0xFE : ((($buf[$p] << $r) | ($buf[$p + 1] >> (8 - $r))) & 0xFE);
            $c = $rev($c);
            // odd parity in the lowest bit (triple DES does not use it, but it is the key Windows has)
            $c = ($c & 0xFE) | (substr_count(decbin($c & 0xFE), '1') % 2 === 0 ? 1 : 0);
            $out .= chr($c);
        }
        return $out;
    }

    /** MS-RDPBCGR 5.3.7: the key after 4096 PDUs, from the first one and the current one. */
    private function update(string $initial, string $current): string
    {
        $tmp = md5($initial . str_repeat("\x5C", self::PAD2) . sha1($initial . str_repeat("\x36", self::PAD1) . $current, true), true);
        $tmp = substr($tmp, 0, strlen($initial));
        $new = (new Rc4($tmp))->crypt($tmp);
        return $this->method === 2 ? $new : self::salt(str_pad($new, 16, "\0"), $this->method);
    }

    /** The MAC of $data: MD5 over SHA-1 of the MAC key, its pads, the length and the data (and the count, salted). */
    private function mac(string $data, ?int $count): string
    {
        $sha = sha1($this->macKey . str_repeat("\x36", self::PAD1) . pack('V', strlen($data)) . $data . ($count !== null ? pack('V', $count) : ''), true);
        return substr(md5($this->macKey . str_repeat("\x5C", self::PAD2) . $sha, true), 0, 8);
    }

    /**
     * Encrypts a PDU's data: [its 8-byte signature, the encrypted data, FIPS's padding length]. Non-FIPS: RC4 (the key
     * renewed every 4096 PDUs) and the MAC (unsalted: what every Windows takes); FIPS: triple DES, the data padded to 8 bytes.
     */
    public function encrypt(string $data, bool $salted = false): array
    {
        if ($this->method === self::FIPS) {
            $sig = substr(hash_hmac('sha1', $data . pack('V', $this->encCount++), $this->macKey, true), 0, 8);
            $pad = (8 - strlen($data) % 8) % 8;
            $c   = $data === '' ? '' : (string)openssl_encrypt($data . str_repeat("\0", $pad), 'des-ede3-cbc', $this->encKey, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $this->encIv);
            $this->encIv = $c !== '' ? substr($c, -8) : $this->encIv;   // (nothing encrypted: the chain goes on from the last block)
            return [$sig, $c, $pad];
        }
        if ($this->encUses === 4096) {
            $this->encKey  = $this->update($this->encInitial, $this->encKey);
            $this->enc     = new Rc4($this->encKey);
            $this->encUses = 0;
        }
        $sig = $this->mac($data, $salted ? $this->encCount : null);
        $this->encUses++;
        $this->encCount++;
        return [$sig, $this->enc->crypt($data), 0];
    }

    /** Decrypts the server's data and checks its signature ($salted: SEC_SECURE_CHECKSUM, the count in its MAC; $pad: FIPS's). */
    public function decrypt(string $sig, string $data, bool $salted = false, int $pad = 0): string
    {
        if ($this->method === self::FIPS) {
            if (strlen($data) % 8 !== 0 || $pad > 7 || $pad > strlen($data)) {
                throw new ProtocolException('Standard RDP Security: bad FIPS data', 'protocol');
            }
            $p = (string)openssl_decrypt($data, 'des-ede3-cbc', $this->decKey, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $this->decIv);
            $this->decIv = $data !== '' ? substr($data, -8) : $this->decIv;
            $p = substr($p, 0, strlen($p) - $pad);
            if (!hash_equals(substr(hash_hmac('sha1', $p . pack('V', $this->decCount++), $this->macKey, true), 0, 8), $sig)) {
                throw new ProtocolException('Standard RDP Security: bad signature', 'mitm');
            }
            return $p;
        }
        if ($this->decUses === 4096) {
            $this->decKey  = $this->update($this->decInitial, $this->decKey);
            $this->dec     = new Rc4($this->decKey);
            $this->decUses = 0;
        }
        $p = $this->dec->crypt($data);
        $this->decUses++;
        if (!hash_equals($this->mac($p, $salted ? $this->decCount : null), $sig)) {
            throw new ProtocolException('Standard RDP Security: bad signature', 'mitm');
        }
        $this->decCount++;
        return $p;
    }

    /**
     * A slow-path PDU's data with its security header: flags (SEC_ENCRYPT added; SEC_SECURE_CHECKSUM: a salted MAC),
     * signature, encrypted data.
     */
    public function seal(int $flags, string $data): string
    {
        [$sig, $c, $pad] = $this->encrypt($data, (bool)($flags & self::SEC_SECURE_CHECKSUM));
        $flags |= self::SEC_ENCRYPT;
        return $this->method === self::FIPS
            ? pack('vvvCC', $flags, 0, 0x10, 1, $pad) . $sig . $c       // TS_SECURITY_HEADER2
            : pack('vv', $flags, 0) . $sig . $c;                         // TS_SECURITY_HEADER1
    }

    /** A slow-path PDU's data from the server: [its security flags, the data] (decrypted when SEC_ENCRYPT says so). */
    public function open(string $pdu): array
    {
        if (strlen($pdu) < 4) {
            throw new ProtocolException('Standard RDP Security: no security header', 'protocol');
        }
        $flags = unpack('v', $pdu)[1];
        // (a redirection decrypted with or without SEC_ENCRYPT, as other clients do: it may come as 0x0C00; before, it was
        // then taken as plain text and refused as a bad redirection)
        if (!($flags & (self::SEC_ENCRYPT | self::SEC_REDIRECTION_PKT))) {
            if ($this->level >= 2 && !($flags & self::SEC_LICENSE_PKT)) {
                throw new ProtocolException('Standard RDP Security: a PDU in the clear', 'protocol');
            }
            return [$flags, substr($pdu, 4)];
        }
        if ($this->method === self::FIPS) {
            if (strlen($pdu) < 16) {
                throw new ProtocolException('Standard RDP Security: short FIPS header', 'protocol');
            }
            return [$flags, $this->decrypt(substr($pdu, 8, 8), substr($pdu, 16), false, ord($pdu[7]))];
        }
        if (strlen($pdu) < 12) {
            throw new ProtocolException('Standard RDP Security: short security header', 'protocol');
        }
        return [$flags, $this->decrypt(substr($pdu, 4, 8), substr($pdu, 12), (bool)($flags & self::SEC_SECURE_CHECKSUM))];
    }

    /**
     * The fast-path fields between the length and the data, and the data encrypted: [fipsInformation and dataSignature,
     * the encrypted data] (the header's FASTPATH_*_ENCRYPTED flag is the caller's).
     */
    public function fastSeal(string $data, bool $salted = false): array
    {
        [$sig, $c, $pad] = $this->encrypt($data, $salted);
        return [($this->method === self::FIPS ? pack('vCC', 0x10, 1, $pad) : '') . $sig, $c];
    }

    /** A fast-path PDU's data after its length, decrypted ($salted: FASTPATH_OUTPUT_SECURE_CHECKSUM). */
    public function fastOpen(string $rest, bool $salted): string
    {
        if ($this->method === self::FIPS) {
            if (strlen($rest) < 12) {
                throw new ProtocolException('Standard RDP Security: short fast-path header', 'protocol');
            }
            return $this->decrypt(substr($rest, 4, 8), substr($rest, 12), false, ord($rest[3]));
        }
        if (strlen($rest) < 8) {
            throw new ProtocolException('Standard RDP Security: short fast-path header', 'protocol');
        }
        return $this->decrypt(substr($rest, 0, 8), substr($rest, 8), $salted);
    }

    // ------------------------------------------------------------------ the server's key

    /**
     * The server's RSA public key from its certificate (Server Security Data, or a license request): [modulus, exponent],
     * big-endian. A proprietary certificate (version 1: an RSA1 blob, little-endian) or an X.509 chain (version 2: the last
     * one's key, read from the DER whatever algorithm it declares: Windows license servers' declare md5WithRSAEncryption,
     * and OpenSSL then gives no key).
     */
    public static function publicKey(string $cert): array
    {
        $r = new Reader($cert);
        if (($r->u32() & 0x7fffffff) === 1) {   // proprietary certificate
            $r->skip(8 + 4);
            if ($r->bytes(4) !== 'RSA1') {
                throw new ProtocolException('Invalid server certificate', 'license');
            }
            $keyLen = $r->u32();
            $r->skip(8);
            $e = strrev($r->bytes(4));
            $n = strrev(substr($r->bytes($keyLen), 0, max(0, $keyLen - 8)));
            return self::sane(ltrim($n, "\0"), ltrim($e, "\0"));
        }
        $count = $r->u32();
        if ($count < 1 || $count > 16) {
            throw new ProtocolException('Invalid server certificate', 'license');
        }
        $der = '';
        for ($i = 0; $i < $count; $i++) {
            $der = $r->bytes($r->u32());
        }
        $tbs = new Reader(Der::value(Der::value($der)));            // Certificate -> TBSCertificate
        $f   = [];
        while ($tbs->left() > 0) {
            $f[] = Der::read($tbs);
        }
        $f = ($f[0][0] ?? 0) === 0xA0 ? array_slice($f, 1) : $f;   // optional [0] version
        if (!isset($f[5])) {
            throw new ProtocolException('Invalid server certificate', 'license');
        }
        $spki = new Reader($f[5][1]);                               // serial, signature, issuer, validity, subject, key
        Der::read($spki);                                           // AlgorithmIdentifier: not checked
        $key = new Reader(Der::value(substr(Der::read($spki)[1], 1)));   // BIT STRING -> RSAPublicKey
        return self::sane(ltrim(Der::read($key)[1], "\0"), ltrim(Der::read($key)[1], "\0"));
    }

    /** The pin of a server's key: the SHA-256 of its modulus and exponent, each after its length (big-endian). */
    public static function pin(string $n, string $e): string
    {
        return hash('sha256', 'rdp-security-key' . pack('N', strlen($n)) . $n . pack('N', strlen($e)) . $e);
    }

    /**
     * A key RSA can be trusted with: an odd modulus of 512 bits or more, an odd exponent from 3 up and below the modulus.
     * Before, an exponent of 1 was taken: what it "encrypted" (the client random, the licensing secret) went as it was.
     */
    private static function sane(string $n, string $e): array
    {
        if (strlen($n) < 64 || !(ord($n[-1]) & 1) || $e === '' || !(ord($e[-1]) & 1) || (strlen($e) === 1 && ord($e) < 3)
            || strlen($e) > strlen($n) || (strlen($e) === strlen($n) && strcmp($e, $n) >= 0)) {
            throw new ProtocolException('Invalid server key', 'license');
        }
        return [$n, $e];
    }

    private static function uint(string $be): string
    {
        return Der::tlv(0x02, (ord($be[0]) & 0x80 ? "\0" : "") . $be);
    }

    /** Raw RSA on a little-endian number, the result little-endian with the modulus's length. */
    public static function rsa(string $data, string $n, string $e): string
    {
        $len = strlen($n);
        if ($len < strlen($data) || $len > 1024 || $e === '') {    // (the number must fit; up to 8192 bits)
            throw new ProtocolException('Invalid server key', 'license');
        }
        $spki = Der::seq(Der::seq(Der::tlv(0x06, "\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01"), "\x05\x00"),
                         Der::tlv(0x03, "\0" . Der::seq(self::uint($n), self::uint($e))));
        $pem  = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64) . "-----END PUBLIC KEY-----\n";
        if (!openssl_public_encrypt(str_pad(strrev($data), $len, "\0", STR_PAD_LEFT), $out, $pem, OPENSSL_NO_PADDING)) {
            throw new ProtocolException('RSA failed', 'license');
        }
        return strrev(str_pad($out, $len, "\0", STR_PAD_LEFT));
    }
}
