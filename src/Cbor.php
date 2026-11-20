<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk;

/** Minimal CBOR decoder (RFC 8949) for WebAuthn: integers, byte and text strings, arrays, maps, true/false/null. */
final class Cbor
{
    /** Decodes the item at $pos (advanced past it). Returns null on malformed input. */
    public static function decode(string $s, int &$pos = 0): mixed
    {
        try {
            return self::item($s, $pos, 0);
        } catch (\RangeException) {
            return null;
        }
    }

    private static function item(string $s, int &$p, int $depth): mixed
    {
        if ($p >= strlen($s) || $depth > 16) {
            throw new \RangeException();
        }
        $b     = ord($s[$p++]);
        $major = $b >> 5;
        $info  = $b & 31;
        if ($major === 7) {
            return match ($info) { 20 => false, 21 => true, 22 => null, default => throw new \RangeException() };
        }
        $n = match (true) {
            $info < 24  => $info,
            $info === 24 => ord(self::take($s, $p, 1)),
            $info === 25 => unpack('n', self::take($s, $p, 2))[1],
            $info === 26 => unpack('N', self::take($s, $p, 4))[1],
            $info === 27 => unpack('J', self::take($s, $p, 8))[1],
            default      => throw new \RangeException(),          // indefinite lengths are not used by WebAuthn
        };
        switch ($major) {
            case 0:
                return $n;
            case 1:
                return -1 - $n;
            case 2:
            case 3:
                return self::take($s, $p, $n);
            case 4:
                $a = [];
                for ($i = 0; $i < $n; $i++) {
                    $a[] = self::item($s, $p, $depth + 1);
                }
                return $a;
            case 5:
                $m = [];
                for ($i = 0; $i < $n; $i++) {
                    $k = self::item($s, $p, $depth + 1);
                    if (!is_int($k) && !is_string($k)) {
                        throw new \RangeException();
                    }
                    $m[$k] = self::item($s, $p, $depth + 1);
                }
                return $m;
            default:                                            // tags are not used by WebAuthn
                throw new \RangeException();
        }
    }

    private static function take(string $s, int &$p, int $n): string
    {
        if ($n < 0 || $p + $n > strlen($s)) {
            throw new \RangeException();
        }
        $r  = substr($s, $p, $n);
        $p += $n;
        return $r;
    }
}
