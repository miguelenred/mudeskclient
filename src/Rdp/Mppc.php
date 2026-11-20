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

    /** Decompresses one block; $flags is the compression byte that came with it. */
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
        $h         = $this->cur;                 // history up to the write position, then the new bytes
        $this->cur = '';                         // ($h alone holds it: appended in place, not copied)
        $start     = strlen($h);
        $n     = strlen($data);
        $bits  = $n * 8;
        $p     = 0;                                  // bit position
        $acc   = 0;                                  // up to 56 bits, MSB first
        $have  = 0;
        $src   = 0;                                  // next byte to load
        while (true) {
            while ($have <= 48) {                    // keep at least 49 bits (zero padded past the end)
                $acc   = (($acc << 8) | ($src < $n ? ord($data[$src]) : 0)) & 0xFFFFFFFFFFFFFF;
                $have += 8;
                $src++;
            }
            if ($bits - $p < 8) {
                break;
            }
            $top = ($acc >> ($have - 32)) & 0xFFFFFFFF;  // next 32 bits, aligned to bit 31
            if (!($top & 0x80000000)) {               // literal 0xxxxxxx
                $h .= chr(($top >> 24) & 0x7F);
                $have -= 8;
                $p    += 8;
                continue;
            }
            if (($top & 0xC0000000) === 0x80000000) { // literal 10 1xxxxxxx
                $h .= chr((($top >> 23) & 0x7F) | 0x80);
                $have -= 9;
                $p    += 9;
                continue;
            }
            if (($top & 0xF8000000) === 0xF8000000) {
                [$off, $used] = [($top >> 21) & 0x3F, 11];
            } elseif (($top & 0xF8000000) === 0xF0000000) {
                [$off, $used] = [(($top >> 19) & 0xFF) + 64, 13];
            } elseif (($top & 0xF0000000) === 0xE0000000) {
                [$off, $used] = [(($top >> 17) & 0x7FF) + 320, 15];
            } elseif (($top & 0xE0000000) === 0xC0000000) {
                [$off, $used] = [(($top >> 13) & 0xFFFF) + 2368, 19];
            } else {
                throw new ProtocolException('MPPC: bad copy offset', 'protocol');
            }
            $have -= $used;
            $p    += $used;
            $top   = ($have >= 32 ? $acc >> ($have - 32) : $acc << (32 - $have)) & 0xFFFFFFFF;   // at least 30 bits left
            if (!($top & 0x80000000)) {
                [$len, $used] = [3, 1];
            } else {
                $k = 0;                                // number of leading 1 bits (length class)
                while ($k < 15 && ($top << $k) & 0x80000000) {
                    $k++;
                }
                if ($k > 14) {
                    throw new ProtocolException('MPPC: bad length', 'protocol');
                }
                $used = 2 * $k + 2;                    // k ones, a zero, k + 1 value bits
                $len  = (($top >> (32 - $used)) & ((1 << ($k + 1)) - 1)) + (1 << ($k + 1));
            }
            $have -= $used;
            $p    += $used;
            $this->copy($h, $off, $len);
        }
        if (strlen($h) > self::SIZE) {
            throw new ProtocolException('MPPC: history overflow', 'protocol');
        }
        $this->cur = $h;
        return substr($h, $start);
    }

    /** Appends $len bytes copied from $off bytes back (64 KB ring; may overlap what is being written). */
    private function copy(string &$h, int $off, int $len): void
    {
        $n = strlen($h);
        if ($n + $len > self::SIZE) {                // checked at each copy: a few KB of copies expanded to hundreds of MB
            throw new ProtocolException('MPPC: history overflow', 'protocol');
        }
        $from = ($n - $off) & 0xFFFF;
        if ($from + $len <= $n) {                     // inside the bytes written so far
            if ($off >= $len) {
                $h .= substr($h, $from, $len);
            } else {                                  // overlapping: the last $off bytes repeat
                $h .= substr(str_repeat(substr($h, $from, $off), intdiv($len, $off) + 1), 0, $len);
            }
            return;
        }
        for ($i = 0; $i < $len; $i++) {              // reaches older history past the write position
            $q  = ($from + $i) & 0xFFFF;
            $h .= $q < strlen($h) ? $h[$q] : ($this->old[$q] ?? "\0");
        }
    }
}
