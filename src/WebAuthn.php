<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk;

use MuDesk\Rdp\Der;

/**
 * Passkeys (WebAuthn level 2) with plain PHP + OpenSSL: ES256 and RS256 keys, attestation "none", user verification
 * required. The PRF extension gives a per-passkey secret that wraps the user's data key, so a passkey can replace the
 * password (otherwise it is only a second factor).
 */
final class WebAuthn
{
    /** PRF input sent by the browser (any fixed value works: the authenticator mixes it with its own secret). */
    public const PRF_SALT = 'MuDeskClient passkey data key v1';
    private const TIMEOUT = 120000;

    public static function rpId(): string
    {
        return Config::publicUrl()['host'] ?? strtolower((string)preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
    }

    /** (public_url of config.php when set: never taken from the request) */
    private static function origin(): string
    {
        return Config::publicUrl()['origin'] ?? (Http::isHttps() ? 'https://' : 'http://') . strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    }

    public static function b64u(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    public static function unb64u(string $s): string
    {
        return (string)base64_decode(strtr($s, '-_', '+/'), true);
    }

    /** New challenge for $purpose, kept in the session for 2 minutes. */
    private static function challenge(string $purpose): string
    {
        $c = random_bytes(32);
        $_SESSION['wa'] = ['c' => $c, 'p' => $purpose, 'exp' => time() + 120];
        return self::b64u($c);
    }

    // ---- registration ----

    public static function createOptions(array $u): array
    {
        return [
            'challenge'              => self::challenge('create:' . $u['id']),
            'rp'                     => ['id' => self::rpId(), 'name' => 'MuDeskClient'],
            'user'                   => ['id' => self::b64u($u['id']), 'name' => $u['username'], 'displayName' => $u['username']],
            'pubKeyCredParams'       => [['type' => 'public-key', 'alg' => -7], ['type' => 'public-key', 'alg' => -257]],
            'authenticatorSelection' => ['residentKey' => 'required', 'requireResidentKey' => true, 'userVerification' => 'required'],
            'excludeCredentials'     => array_map(static fn($p) => ['type' => 'public-key', 'id' => $p['id']], $u['passkeys'] ?? []),
            'attestation'            => 'none',
            'timeout'                => self::TIMEOUT,
            'extensions'             => ['prf' => new \stdClass()],   // asks the authenticator for PRF (hmac-secret) support
        ];
    }

    /** Verifies navigator.credentials.create() data; returns the passkey record or null. */
    public static function register(array $u, string $clientData, string $attestation): ?array
    {
        if (!self::clientData($clientData, 'webauthn.create', 'create:' . $u['id'])) {
            return null;
        }
        $att = Cbor::decode($attestation);
        $ad  = is_array($att) && is_string($att['authData'] ?? null) ? self::authData($att['authData'], true) : null;   // (a list there: "Array", with a warning)
        if ($ad === null || !isset($ad['credId'])) {
            return null;
        }
        $pem = self::coseToPem($ad['key']);
        return $pem === null ? null : [
            'id' => self::b64u($ad['credId']), 'pem' => $pem, 'count' => $ad['count'], 'name' => '',
            'created' => date('c'), 'used' => null, 'dek' => null,
        ];
    }

    // ---- authentication ----

    /** $creds: allowed passkeys (second factor) or [] (passwordless: the browser offers the passkeys it holds). */
    public static function getOptions(array $creds, string $purpose): array
    {
        return [
            'challenge'        => self::challenge($purpose),
            'rpId'             => self::rpId(),
            'allowCredentials' => array_map(static fn($p) => ['type' => 'public-key', 'id' => $p['id']], $creds),
            'userVerification' => 'required',
            'timeout'          => self::TIMEOUT,
            'extensions'       => ['prf' => ['eval' => ['first' => self::b64u(self::PRF_SALT)]]],
        ];
    }

    /** Verifies an assertion made with $pk; returns the new signature counter or null. */
    public static function verify(array $pk, string $purpose, string $clientData, string $authData, string $signature): ?int
    {
        if (!self::clientData($clientData, 'webauthn.get', $purpose)) {
            return null;
        }
        $ad = self::authData($authData, false);
        // a cloned authenticator goes backwards (back to 0 too); passkeys that always send 0 keep 0
        if ($ad === null || (($ad['count'] !== 0 || $pk['count'] !== 0) && $ad['count'] <= $pk['count'])) {
            return null;
        }
        $ok = openssl_verify($authData . hash('sha256', $clientData, true), $signature, $pk['pem'], OPENSSL_ALGO_SHA256);
        return $ok === 1 ? $ad['count'] : null;
    }

    /** Key derived from the PRF output of a passkey, which wraps the data key. */
    public static function prfKey(string $prf, string $credId): string
    {
        return hash_hkdf('sha256', $prf, 32, 'MuDeskClient passkey', $credId);
    }

    // ---- internals ----

    private static function clientData(string $json, string $type, string $purpose): bool
    {
        $wa = $_SESSION['wa'] ?? null;
        unset($_SESSION['wa']);                          // each challenge is used once
        $c = json_decode($json, true);
        return is_array($wa) && is_array($c) && $wa['exp'] >= time() && hash_equals($wa['p'], $purpose)
            && ($c['type'] ?? '') === $type && is_string($c['challenge'] ?? null) && is_string($c['origin'] ?? null)   // (lists: warnings)
            && hash_equals(self::b64u($wa['c']), $c['challenge']) && hash_equals(self::origin(), $c['origin']) && empty($c['crossOrigin']);
    }

    /** Parses authenticator data; checks the RP id hash and the user present + verified flags. */
    private static function authData(string $d, bool $withCredential): ?array
    {
        if (strlen($d) < 37 || !hash_equals(hash('sha256', self::rpId(), true), substr($d, 0, 32))) {
            return null;
        }
        $flags = ord($d[32]);
        if (($flags & 0x05) !== 0x05) {                 // UP and UV
            return null;
        }
        $r = ['count' => unpack('N', substr($d, 33, 4))[1]];
        if ($withCredential) {
            if (!($flags & 0x40) || strlen($d) < 55) {
                return null;
            }
            $n = unpack('n', substr($d, 53, 2))[1];
            $r['credId'] = substr($d, 55, $n);
            $pos         = 55 + $n;
            $r['key']    = Cbor::decode($d, $pos);
            if (strlen($r['credId']) !== $n || !is_array($r['key'])) {
                return null;
            }
        }
        return $r;
    }

    /** COSE public key (EC2 P-256 / RSA) to PEM. */
    public static function coseToPem(array $k): ?string
    {
        if (($k[1] ?? null) === 2 && ($k[3] ?? null) === -7 && ($k[-1] ?? null) === 1
            && is_string($k[-2] ?? null) && is_string($k[-3] ?? null) && strlen($k[-2]) === 32 && strlen($k[-3]) === 32) {
            $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $k[-2] . $k[-3];
        } elseif (($k[1] ?? null) === 3 && ($k[3] ?? null) === -257 && is_string($k[-1] ?? null) && is_string($k[-2] ?? null)) {
            $int = static function (string $b): string {
                $b = ltrim($b, "\0");
                return Der::tlv(0x02, ($b === '' || ord($b[0]) & 0x80 ? "\0" : '') . $b);
            };
            $der = Der::seq(Der::seq(Der::tlv(0x06, hex2bin('2a864886f70d010101')), "\x05\x00"),
                            Der::tlv(0x03, "\0" . Der::seq($int($k[-1]), $int($k[-2]))));
        } else {
            return null;
        }
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
        return openssl_pkey_get_public($pem) === false ? null : $pem;
    }
}
