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

    private static ?string $appKey = null;

    /** Server key for secrets that must be readable without the user's password (TOTP seeds). */
    public static function appKey(): string
    {
        $key = &self::$appKey;
        if ($key === null) {
            // a PHP file that exits at once: even if the data folder were served, PHP would show nothing
            $read = static fn(string $c): string => (string)base64_decode(trim(substr($c, strlen(self::KEY_HEAD))), true);
            $old  = Config::dir('secret.key');                    // earlier versions
            $key  = $read(self::keyFile(Config::dir('secret.php'), static function () use ($old): string {
                $raw = is_file($old) ? (string)file_get_contents($old) : random_bytes(32);
                return strlen($raw) === 32 ? self::KEY_HEAD . base64_encode($raw) . "\n" : throw new RuntimeException('Invalid secret.key');
            }, static fn(string $c): bool => strlen($read($c)) === 32, [self::class, 'appKeyUse']));
            is_file($old) && @unlink($old);
        }
        return $key;
    }

    /**
     * What the server key's secrets are kept in (null: nothing yet): the audit's lines (their MACs), session recordings,
     * two-step codes, the OIDC provider's secret. A secret.php found damaged is made again only when nothing is.
     */
    public static function appKeyUse(): ?string
    {
        $users = array_filter(Store::read('users'), static fn($u): bool => is_array($u) && !empty($u['totp']['secret']));
        return match (true) {
            Audit::days() !== []                                => 'the audit',
            (glob(Recording::dir() . '/*') ?: []) !== []         => 'session recordings',
            $users !== []                                       => 'two-step codes',
            (string)(Store::read('settings')['oidc_secret'] ?? '') !== '' => 'the OIDC secret',
            default                                             => null,
        };
    }

    /**
     * The contents of a key file of the data folder (secret.php, audit-key.php), made the first time by $make; $valid says
     * whether contents are whole. Made under a lock, in a temporary file that then takes its name (a reader never sees it
     * part-written), the name only once it is whole. Before, opened with 'x' and then written: a process killed or a full
     * disk in between left it empty or cut, and every request after failed for good ("Invalid secret.php") until someone
     * removed it by hand. One found damaged (as an earlier version could leave it) is kept aside and made again when
     * nothing kept depends on it ($inUse() returns null); else it stops, naming what does, to be restored from a backup.
     */
    public static function keyFile(string $file, callable $make, callable $valid, ?callable $inUse = null): string
    {
        $c = (string)@file_get_contents($file);
        if ($valid($c)) {
            return $c;
        }
        $l = fopen($file . '.lock', 'c');
        if ($l === false || !flock($l, LOCK_EX)) {
            throw new RuntimeException('Cannot lock ' . basename($file));
        }
        try {
            clearstatcache(true, $file);
            $c = (string)@file_get_contents($file);
            if ($valid($c)) {
                return $c;                                        // made meanwhile by another request
            }
            $name = basename($file);
            if (is_file($file)) {
                $why = $inUse === null ? null : $inUse();
                if ($why !== null) {
                    throw new RuntimeException("Data file $name damaged (needed by $why): restore it from a backup");
                }
                if (!@rename($file, $file . '.damaged-' . date('Ymd-His'))) {
                    throw new RuntimeException("Data file $name damaged and it cannot be set aside");
                }
                error_log("MuDeskClient: data file $name damaged (" . ($c === '' ? 'empty' : 'cut') . '), kept aside and made again');
            }
            $c   = $make();
            $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
            $ok  = file_put_contents($tmp, $c) === strlen($c);    // (a full disk: a part written is no key)
            @chmod($tmp, 0600);                                   // before it takes the name: never readable by others
            if (!$ok || !@rename($tmp, $file)) {
                @unlink($tmp);
                throw new RuntimeException("Cannot write $name");
            }
            return $c;
        } finally {
            flock($l, LOCK_UN);
            fclose($l);
        }
    }

    /** The server key read again next time (a backup restored put its own in place). */
    public static function forgetAppKey(): void
    {
        self::$appKey = null;
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
