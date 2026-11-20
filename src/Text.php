<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk;

/** UTF-8 helpers in plain PHP (no mbstring/iconv dependency). */
final class Text
{
    /** First $n characters of a UTF-8 string (invalid UTF-8 gives ''). */
    public static function cut(string $s, int $n): string
    {
        return preg_match('/^.{0,' . $n . '}/us', $s, $m) ? $m[0] : '';
    }

    /** $s as valid UTF-8: each bad sequence (a file name on Linux, a cut text) becomes U+FFFD. */
    public static function valid(string $s): string
    {
        return preg_match('//u', $s) ? $s : htmlspecialchars_decode(htmlspecialchars($s, ENT_SUBSTITUTE | ENT_NOQUOTES, 'UTF-8'), ENT_NOQUOTES);
    }

    public static function toUtf16(string $s): string
    {
        // not UTF-8: read as it was, a cut sequence took the bytes after it and gave another letter ("a\xC3": "aÀ")
        $s = self::valid($s);
        $out = '';
        $len = strlen($s);
        for ($i = 0; $i < $len;) {
            $c = ord($s[$i]);
            if ($c < 0x80) {
                $cp = $c;
                $i++;
            } elseif ($c < 0xE0) {
                $cp = (($c & 0x1F) << 6) | (ord($s[$i + 1] ?? "\0") & 0x3F);
                $i += 2;
            } elseif ($c < 0xF0) {
                $cp = (($c & 0x0F) << 12) | ((ord($s[$i + 1] ?? "\0") & 0x3F) << 6) | (ord($s[$i + 2] ?? "\0") & 0x3F);
                $i += 3;
            } else {
                $cp = (($c & 0x07) << 18) | ((ord($s[$i + 1] ?? "\0") & 0x3F) << 12) | ((ord($s[$i + 2] ?? "\0") & 0x3F) << 6) | (ord($s[$i + 3] ?? "\0") & 0x3F);
                $i += 4;
            }
            if ($cp >= 0x10000) {
                $cp  -= 0x10000;
                $out .= pack('vv', 0xD800 | ($cp >> 10), 0xDC00 | ($cp & 0x3FF));
            } else {
                $out .= pack('v', $cp);
            }
        }
        return $out;
    }

    /** Decodes UTF-16LE up to the first NUL character. */
    public static function fromUtf16(string $b): string
    {
        $out = '';
        $len = strlen($b) - 1;
        for ($i = 0; $i < $len; $i += 2) {
            $cp = ord($b[$i]) | (ord($b[$i + 1]) << 8);
            if ($cp === 0) {
                break;
            }
            if ($cp >= 0xD800 && $cp < 0xE000) {          // surrogates: a pair is one character, a lone one is U+FFFD
                $lo = $cp < 0xDC00 && $i + 3 < strlen($b) ? ord($b[$i + 2]) | (ord($b[$i + 3]) << 8) : 0;
                if ($lo >= 0xDC00 && $lo < 0xE000) {
                    $cp = 0x10000 + (($cp - 0xD800) << 10) + ($lo - 0xDC00);
                    $i += 2;
                } else {
                    $cp = 0xFFFD;
                }
            }
            $out .= match (true) {
                $cp < 0x80    => chr($cp),
                $cp < 0x800   => chr(0xC0 | ($cp >> 6)) . chr(0x80 | ($cp & 0x3F)),
                $cp < 0x10000 => chr(0xE0 | ($cp >> 12)) . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F)),
                default       => chr(0xF0 | ($cp >> 18)) . chr(0x80 | (($cp >> 12) & 0x3F)) . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F)),
            };
        }
        return $out;
    }
}
