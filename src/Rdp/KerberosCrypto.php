<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk\Rdp;

use MuDesk\Text;

/**
 * Kerberos encryption types aes128-cts-hmac-sha1-96 (17) and aes256-cts-hmac-sha1-96 (18), RFC 3961 and RFC 3962, in plain
 * PHP with OpenSSL's AES: n-fold, key derivation, string-to-key (PBKDF2-HMAC-SHA1), encryption (a confounder, AES in CBC mode
 * with ciphertext stealing, an HMAC-SHA1-96 of the plaintext) and its checksum. And rc4-hmac (23, RFC 4757): a Windows
 * Server 2003 domain has no other keys, and an account whose password was last set before its domain had AES has no other.
 */
final class KerberosCrypto
{
    public const AES128 = 17;
    public const AES256 = 18;
    public const RC4 = 23;
    private const BLOCK = 16;
    private const MAC = 12;

    /** The key length of an encryption type (16 or 32 bytes); throws for one this client does not support. */
    public static function keyLength(int $etype): int
    {
        return match ($etype) {
            self::AES128, self::RC4 => 16,
            self::AES256            => 32,
            default                 => throw new ProtocolException("Kerberos: encryption type $etype not supported", 'kerberos'),
        };
    }

    /** n-fold (RFC 3961 5.1): $in stretched or folded to $bytes bytes, with 13-bit rotations and one's-complement addition. */
    public static function nfold(string $in, int $bytes): string
    {
        $inLen = strlen($in);
        [$a, $b] = [$bytes, $inLen];
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }
        $lcm  = intdiv($bytes * $inLen, $a);
        $out  = array_fill(0, $bytes, 0);
        $bits = $inLen << 3;
        $carry = 0;
        for ($i = $lcm - 1; $i >= 0; $i--) {
            $msbit  = (($bits - 1) + (($bits + 13) * intdiv($i, $inLen)) + (($inLen - ($i % $inLen)) << 3)) % $bits;
            $carry += (((ord($in[(($inLen - 1) - ($msbit >> 3)) % $inLen]) << 8) | ord($in[($inLen - ($msbit >> 3)) % $inLen]))
                      >> (($msbit & 7) + 1)) & 0xff;
            $carry += $out[$i % $bytes];
            $out[$i % $bytes] = $carry & 0xff;
            $carry >>= 8;
        }
        for ($i = $bytes - 1; $carry && $i >= 0; $i--) {   // the carry left over, added back in
            $carry  += $out[$i];
            $out[$i] = $carry & 0xff;
            $carry >>= 8;
        }
        return pack('C*', ...$out);
    }

    /** One AES block encrypted with $key (ECB: one block at a time). */
    private static function block(string $key, string $block): string
    {
        return (string)openssl_encrypt($block, strlen($key) === 32 ? 'aes-256-ecb' : 'aes-128-ecb', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
    }

    /** DK(key, constant) (RFC 3961 5.1): the constant n-folded to a block, encrypted again and again to the key's length. */
    public static function derive(string $key, string $constant): string
    {
        $in  = strlen($constant) === self::BLOCK ? $constant : self::nfold($constant, self::BLOCK);
        $out = '';
        while (strlen($out) < strlen($key)) {
            $in   = self::block($key, $in);
            $out .= $in;
        }
        return substr($out, 0, strlen($key));
    }

    /** The key of a password (RFC 3962 4): PBKDF2-HMAC-SHA1 with the salt and the iteration count, then DK with "kerberos". */
    public static function stringToKey(int $etype, #[\SensitiveParameter] string $password, string $salt, int $iterations = 4096): string
    {
        if ($iterations < 1 || $iterations > 1 << 24) {
            throw new ProtocolException('Kerberos: invalid iteration count', 'kerberos');
        }
        $tkey = hash_pbkdf2('sha1', $password, $salt, $iterations, self::keyLength($etype), true);
        return self::derive($tkey, 'kerberos');
    }

    /** The keys of a key usage: [Ke, Ki] (RFC 3961 5.3: the usage as 4 bytes and 0xAA, 0x55). */
    private static function usageKeys(string $key, int $usage): array
    {
        return [self::derive($key, pack('N', $usage) . "\xAA"), self::derive($key, pack('N', $usage) . "\x55")];
    }

    /** AES in CBC mode with ciphertext stealing (RFC 3962 5: the last two blocks swapped, the last one cut), IV zero. */
    public static function ctsEncrypt(string $key, string $plain): string
    {
        $n      = strlen($plain);
        $cipher = strlen($key) === 32 ? 'aes-256-cbc' : 'aes-128-cbc';
        if ($n <= self::BLOCK) {
            return (string)openssl_encrypt(str_pad($plain, self::BLOCK, "\0"), $cipher, $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, str_repeat("\0", 16));
        }
        $padded = str_pad($plain, (int)ceil($n / self::BLOCK) * self::BLOCK, "\0");
        $c      = (string)openssl_encrypt($padded, $cipher, $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, str_repeat("\0", 16));
        $last   = $n % self::BLOCK ?: self::BLOCK;
        $blocks = str_split($c, self::BLOCK);
        $k      = count($blocks);
        return implode('', array_slice($blocks, 0, $k - 2)) . $blocks[$k - 1] . substr($blocks[$k - 2], 0, $last);
    }

    public static function ctsDecrypt(string $key, string $data): string
    {
        $n      = strlen($data);
        $cipher = strlen($key) === 32 ? 'aes-256-cbc' : 'aes-128-cbc';
        $dec    = static fn(string $b): string => (string)openssl_decrypt($b, strlen($key) === 32 ? 'aes-256-ecb' : 'aes-128-ecb', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
        if ($n < self::BLOCK) {
            throw new ProtocolException('Kerberos: ciphertext too short', 'kerberos');
        }
        if ($n === self::BLOCK) {
            return $dec($data);
        }
        $last   = $n % self::BLOCK ?: self::BLOCK;
        $full   = $n - $last - self::BLOCK;                  // the blocks before the last two
        $prev   = $full > 0 ? substr($data, $full - self::BLOCK, self::BLOCK) : str_repeat("\0", 16);
        $cn1    = substr($data, $full, self::BLOCK);         // (Cn, encrypted last: the full one)
        $cn     = substr($data, $full + self::BLOCK);        // (the cut one)
        $d      = $dec($cn1);                                // = Pn (padded) XOR C(n-1) (whole)
        $cPrev  = $cn . substr($d, strlen($cn));             // the whole C(n-1): the cut bytes, and the rest from D
        $pn     = substr($d ^ $cPrev, 0, $last);
        $pPrev  = $dec($cPrev) ^ $prev;
        $head   = $full > 0 ? (string)openssl_decrypt(substr($data, 0, $full), $cipher, $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, str_repeat("\0", 16)) : '';
        return $head . $pPrev . $pn;
    }

    /** Encrypts $plain for key usage $usage (RFC 3961 5.3): confounder and plaintext in CTS, then their HMAC-SHA1-96. */
    public static function encrypt(string $key, int $usage, string $plain, ?string $confounder = null): string
    {
        [$ke, $ki] = self::usageKeys($key, $usage);
        $p = ($confounder ?? random_bytes(self::BLOCK)) . $plain;
        return self::ctsEncrypt($ke, $p) . substr(hash_hmac('sha1', $p, $ki, true), 0, self::MAC);
    }

    /** Decrypts what encrypt() made with the same key and usage; throws when its integrity check fails. */
    public static function decrypt(string $key, int $usage, string $data): string
    {
        if (strlen($data) < self::BLOCK + self::MAC) {
            throw new ProtocolException('Kerberos: encrypted data too short', 'kerberos');
        }
        [$ke, $ki] = self::usageKeys($key, $usage);
        $p = self::ctsDecrypt($ke, substr($data, 0, -self::MAC));
        if (!hash_equals(substr(hash_hmac('sha1', $p, $ki, true), 0, self::MAC), substr($data, -self::MAC))) {
            throw new ProtocolException('Kerberos: integrity check failed', 'kerberos_integrity');
        }
        return substr($p, self::BLOCK);
    }

    /** The checksum hmac-sha1-96-aes128/256 (types 15, 16) of $data for key usage $usage (Kc: the usage and 0x99). */
    public static function checksum(string $key, int $usage, string $data): string
    {
        return substr(hash_hmac('sha1', $data, self::derive($key, pack('N', $usage) . "\x99"), true), 0, self::MAC);
    }

    /** The user's key of a type: rc4-hmac's (RFC 4757 2: the MD4 of the password in UTF-16LE), else AES's from its salt. */
    public static function userKey(int $etype, #[\SensitiveParameter] string $password, string $salt, int $iterations = 4096): string
    {
        return $etype === self::RC4 ? hash('md4', Text::toUtf16($password), true) : self::stringToKey($etype, $password, $salt, $iterations);
    }

    /** RFC 4757's key usage number: the AS-REP's part (3) as Windows encrypts it, with the TGS-REP's (8). */
    private static function rc4Usage(int $usage): string
    {
        return pack('V', $usage === 3 ? 8 : $usage);
    }

    /**
     * Encrypts for rc4-hmac (RFC 4757 4): K1 the HMAC-MD5 of the usage with the key, the checksum the HMAC-MD5 of an 8-byte
     * confounder and the plaintext with K1, those two in RC4 with the HMAC-MD5 of the checksum with K1; the checksum first.
     */
    public static function rc4Encrypt(string $key, int $usage, string $plain, ?string $confounder = null): string
    {
        $k1 = hash_hmac('md5', self::rc4Usage($usage), $key, true);
        $p  = ($confounder ?? random_bytes(8)) . $plain;
        $ck = hash_hmac('md5', $p, $k1, true);
        return $ck . (new Rc4(hash_hmac('md5', $ck, $k1, true)))->crypt($p);
    }

    /** Decrypts what rc4Encrypt() made with the same key and usage; throws when its checksum does not match. */
    public static function rc4Decrypt(string $key, int $usage, string $data): string
    {
        if (strlen($data) < 16 + 8) {
            throw new ProtocolException('Kerberos: encrypted data too short', 'kerberos');
        }
        $k1 = hash_hmac('md5', self::rc4Usage($usage), $key, true);
        $ck = substr($data, 0, 16);
        $p  = (new Rc4(hash_hmac('md5', $ck, $k1, true)))->crypt(substr($data, 16));
        if (!hash_equals(hash_hmac('md5', $p, $k1, true), $ck)) {
            throw new ProtocolException('Kerberos: integrity check failed', 'kerberos_integrity');
        }
        return substr($p, 8);
    }

    /** The checksum type that goes with an encryption type (rc4-hmac's: HMAC-MD5, -138). */
    public static function checksumType(int $etype): int
    {
        return match ($etype) {
            self::AES256 => 16,
            self::RC4    => -138,
            default      => 15,
        };
    }

    /** Encrypts with a key of type $etype (AES or RC4: their keys are both 16 bytes long for AES128, so the type says which). */
    public static function encryptFor(int $etype, string $key, int $usage, string $plain): string
    {
        return $etype === self::RC4 ? self::rc4Encrypt($key, $usage, $plain) : self::encrypt($key, $usage, $plain);
    }

    public static function decryptFor(int $etype, string $key, int $usage, string $data): string
    {
        return $etype === self::RC4 ? self::rc4Decrypt($key, $usage, $data) : self::decrypt($key, $usage, $data);
    }

    /**
     * The checksum of $data with a key of type $etype: AES's, or rc4-hmac's (RFC 4757 4: HMAC-MD5 with the key's
     * "signaturekey" HMAC, over the MD5 of the usage and the data).
     */
    public static function checksumFor(int $etype, string $key, int $usage, string $data): string
    {
        if ($etype !== self::RC4) {
            return self::checksum($key, $usage, $data);
        }
        return hash_hmac('md5', md5(self::rc4Usage($usage) . $data, true), hash_hmac('md5', "signaturekey\0", $key, true), true);
    }
}
