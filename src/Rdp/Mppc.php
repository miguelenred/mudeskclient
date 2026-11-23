<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk\Rdp;

/**
 * RDP bulk decompression, MPPC with a 64 KB history (MS-RDPBCGR 3.1.8.4.2, "RDP 5.0").
 * One instance per connection: server-to-client fast-path and slow-path data share the history.
 */
final class Mppc
{
    public const COMPRESSED = 0x20;
    public const AT_FRONT   = 0x40;
    public const FLUSHED    = 0x80;
    private const SIZE      = 65536;

    /*
     * The history as what was written since the last restart ($cur, up to the write position) and the history before it
     * ($old, read past the write position; missing bytes are zeros). Kept as one 64 KB string, each block copied it twice
     * (15.5 µs a 20-byte block; 9 µs this way), with the same output.
     */
    private string $cur = '';
    private string $old = '';
    /** Each byte as a string, and its number of leading 1 bits (a copy's length class) */
    private static array $chr = [];
    private static array $ones = [];

    /**
     * Decompresses one block; $flags is the compression byte that came with it. The bits are read 32 at a time from the
     * block unpacked into words, and a copy that overlaps what it writes or reaches the history before the restart is
     * copied whole, not a byte at a time: 2.1-2.5 times faster on a full-screen animation, the same output.
     */
    public function decompress(string $data, int $flags): string
    {
        if ($flags & self::FLUSHED) {
            $this->cur = $this->old = '';
        } elseif ($flags & self::AT_FRONT) {
            $this->old = $this->cur . (string)substr($this->old, strlen($this->cur));
            $this->cur = '';
        }
        if (!($flags & self::COMPRESSED)) {
            return $data;
        }
        if (self::$chr === []) {
            for ($i = 0; $i < 256; $i++) {
                for ($k = 0; $k < 8 && ($i << $k) & 0x80; $k++);
                self::$chr[]  = chr($i);
                self::$ones[] = $k;
            }
        }
        $chr       = self::$chr;
        $ones      = self::$ones;
        $h         = $this->cur;                 // history up to the write position, then the new bytes
        $this->cur = '';                         // ($h alone holds it: appended in place, not copied)
        $start     = strlen($h);
        $n    = strlen($data);
        $w    = unpack('N*', $data . str_repeat("\0", 16 + (-$n & 3)));   // 32-bit words, MSB first; 4 of zeros after
        $wi   = 1;
        $acc  = 0;                                   // the bits not read yet are its lowest $have (those above: read)
        $have = 0;
        $stop = 8 - 8 * $n;                          // under 8 bits left in the block once $have < $stop (+32 a word)
        while (true) {
            if ($have < 32) {
                $acc   = $acc << 32 | $w[$wi++];
                $have += 32;
                $stop += 32;
            }
            if ($have < $stop) {
                break;
            }
            $top = ($acc >> ($have - 32)) & 0xFFFFFFFF;  // the next 32 bits
            if ($top < 0x80000000) {                 // literal 0xxxxxxx
                $h    .= $chr[$top >> 24];
                $have -= 8;
                continue;
            }
            if ($top < 0xC0000000) {                 // literal 10 1xxxxxxx
                $h    .= $chr[($top >> 23) - 0x80];
                $have -= 9;
                continue;
            }
            if ($top < 0xE0000000) {                 // copy offset: 110 + 16 bits, 1110 + 11, 11110 + 8, 11111 + 6
                $off   = (($top >> 13) & 0xFFFF) + 2368;
                $have -= 19;
            } elseif ($top < 0xF0000000) {
                $off   = (($top >> 17) & 0x7FF) + 320;
                $have -= 15;
            } elseif ($top < 0xF8000000) {
                $off   = (($top >> 19) & 0xFF) + 64;
                $have -= 13;
            } else {
                $off   = ($top >> 21) & 0x3F;
                $have -= 11;
            }
            if ($have < 32) {
                $acc   = $acc << 32 | $w[$wi++];
                $have += 32;
                $stop += 32;
            }
            $top = ($acc >> ($have - 32)) & 0xFFFFFFFF;
            if ($top < 0x80000000) {
                $len   = 3;
                $have -= 1;
            } else {
                $k = $ones[$top >> 24];              // k ones, a zero, k + 1 value bits
                if ($k === 8 && ($k += $ones[($top >> 16) & 0xFF]) > 14) {
                    throw new ProtocolException('MPPC: bad length', 'protocol');
                }
                $len   = (($top >> (30 - 2 * $k)) & ((2 << $k) - 1)) + (2 << $k);
                $have -= 2 * $k + 2;
            }
            $pos = strlen($h);
            if ($pos + $len > self::SIZE) {          // checked at each copy: a few KB of copies expanded to hundreds of MB
                throw new ProtocolException('MPPC: history overflow', 'protocol');
            }
            $from = ($pos - $off) & 0xFFFF;
            if ($from + $len <= $pos) {               // inside the bytes written so far
                $h .= substr($h, $from, $len);
            } elseif ($from < $pos) {                 // overlapping what it writes: those last bytes repeat
                $h .= substr(str_repeat(substr($h, $from, $pos - $from), intdiv($len, $pos - $from) + 1), 0, $len);
            } else {
                $h .= $this->older($h, $from, $len);
            }
        }
        if (strlen($h) > self::SIZE) {
            throw new ProtocolException('MPPC: history overflow', 'protocol');
        }
        $this->cur = $h;
        return substr($h, $start);
    }

    /**
     * A copy from past the write position: the history before the restart (zeros where it was never written) up to the end
     * of the 64 KB ring, then from its front, what was written since the restart (that copy's own bytes once past them).
     */
    private function older(string $h, int $from, int $len): string
    {
        $end  = min($len, self::SIZE - $from);
        $out  = (string)substr($this->old, $from, $end);
        $out .= str_repeat("\0", $end - strlen($out));
        for ($i = $end, $n = strlen($h); $i < $len; $i++) {
            $out .= $i - $end < $n ? $h[$i - $end] : $out[$i - $end - $n];
        }
        return $out;
    }
}
