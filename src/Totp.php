<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk;

/** RFC 6238 TOTP (SHA-1, 6 digits, 30 s). */
final class Totp
{
    private const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function secret(): string
    {
        $bytes = random_bytes(20);
        $bits  = '';
        foreach (str_split($bytes) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::B32[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    private static function decode(string $b32): string
    {
        $bits = '';
        foreach (str_split(strtoupper($b32)) as $c) {
            $p = strpos(self::B32, $c);
            if ($p !== false) {
                $bits .= str_pad(decbin($p), 5, '0', STR_PAD_LEFT);
            }
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }

    public static function code(string $secret, int $step): string
    {
        $h = hash_hmac('sha1', pack('J', $step), self::decode($secret), true);
        $o = ord($h[19]) & 0xf;
        $v = (unpack('N', substr($h, $o, 4))[1] & 0x7fffffff) % 1000000;
        return str_pad((string)$v, 6, '0', STR_PAD_LEFT);
    }

    /** Returns the matched time step (to block replays) or null. */
    public static function verify(string $secret, string $code, int $lastStep = 0): ?int
    {
        if (!preg_match('/^\d{6}$/', $code)) {
            return null;
        }
        $now = intdiv(time(), 30);
        for ($s = $now - 1; $s <= $now + 1; $s++) {
            if ($s > $lastStep && hash_equals(self::code($secret, $s), $code)) {
                return $s;
            }
        }
        return null;
    }
}
