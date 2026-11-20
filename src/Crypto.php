<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk;

use RuntimeException;

/** AES-256-GCM helpers and key handling. */
final class Crypto
{
    /**
     * PBKDF2-SHA256 rounds of the key that wraps a user's data key: 400,000 since 1.0.1 (200,000 before; a key wrapped
     * with fewer is wrapped again at the next sign-in, see needsRewrap()). Computed by OpenSSL, which gives the same key
     * as hash_pbkdf2() in 30 % less time: 400,000 take about as long as 200,000 did, within the second of a sign-in.
     */
    private const KDF_ITER = 400000;

    private const KEY_HEAD = "<?php exit; ?>\n";

    /** Server key for secrets that must be readable without the user's password (TOTP seeds). */
    public static function appKey(): string
    {
        static $key = null;
        if ($key === null) {
            // a PHP file that exits at once: even if the data folder were served, PHP would show nothing
            $file = Config::dir('secret.php');
            if (!is_file($file)) {
                $old = Config::dir('secret.key');                 // earlier versions
                $raw = is_file($old) ? (string)file_get_contents($old) : random_bytes(32);
                $h   = @fopen($file, 'x');                        // first writer wins (no link(): some hostings disable it)
                if ($h !== false) {
                    fwrite($h, self::KEY_HEAD . base64_encode($raw) . "\n");
                    fclose($h);
                    @chmod($file, 0600);
                }
                if (is_file($old) && is_file($file)) {
                    @unlink($old);
                }
            }
            for ($i = 0; strlen($c = (string)@file_get_contents($file)) < strlen(self::KEY_HEAD) + 44 && $i < 100; $i++) {
                usleep(1000);                                     // another request is still writing it
            }
            $key = (string)base64_decode(trim(substr($c, strlen(self::KEY_HEAD))), true);
            if (strlen($key) !== 32) {
                throw new RuntimeException('Invalid secret.php');
            }
        }
        return $key;
    }

    public static function encrypt(string $key, string $plain, string $aad = ''): string
    {
        $iv = random_bytes(12);
        $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, $aad, 16);
        if ($ct === false) {
            throw new RuntimeException('Encryption failed');
        }
        return base64_encode($iv . $tag . $ct);
    }

    public static function decrypt(string $key, string $blob, string $aad = ''): ?string
    {
        $raw = base64_decode($blob, true);
        if ($raw === false || strlen($raw) < 28) {
            return null;
        }
        $pt = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), $aad);
        return $pt === false ? null : $pt;
    }

    /** Wrap a data key with a key derived from the user's password. */
    public static function wrapKey(string $password, string $dek, string $uid): array
    {
        $salt = random_bytes(16);
        return ['salt' => base64_encode($salt), 'iter' => self::KDF_ITER, 'key' => self::encrypt(self::kek($password, $salt, self::KDF_ITER), $dek, 'dek:' . $uid)];
    }

    public static function unwrapKey(string $password, array $w, string $uid): ?string
    {
        $iter = (int)($w['iter'] ?? 0);
        if ($iter < 1 || $iter > 10000000 || !is_string($w['key'] ?? null)) {      // a damaged users file: never minutes of work
            return null;
        }
        return self::decrypt(self::kek($password, (string)base64_decode((string)($w['salt'] ?? '')), $iter), $w['key'], 'dek:' . $uid);
    }

    /** Whether a wrapped data key has fewer rounds than new ones get: then it is wrapped again at the next sign-in. */
    public static function needsRewrap(array $w): bool
    {
        return (int)($w['iter'] ?? 0) < self::KDF_ITER;
    }

    /** The key that wraps a data key: PBKDF2-SHA256 by OpenSSL (the same bytes as hash_pbkdf2(), faster). */
    private static function kek(string $password, string $salt, int $iter): string
    {
        return (string)openssl_pbkdf2($password, $salt, 32, $iter, 'sha256');
    }
}
