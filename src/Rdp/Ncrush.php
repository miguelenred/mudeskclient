<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk\Rdp;

/**
 * RDP 6.0 bulk decompression, "NCRUSH" (MS-RDPEGDI 3.1.8.1): Huffman-coded literals, copy offsets with a 4-entry
 * offset cache and match lengths over a 64 KB history. The Huffman codes and tables are those of the specification;
 * the decoding tables are built from them once.
 */
final class Ncrush
{
    public const TYPE = 2;
    private const SIZE = 65536;
    private const LEC_LEN = [
        6, 6, 6, 7, 7, 7, 7, 7, 7, 7, 7, 8, 8, 8, 8, 8, 8, 8, 9, 8, 9, 9, 9, 9,
        8, 8, 9, 9, 9, 9, 9, 9, 8, 9, 9, 10, 9, 9, 9, 9, 9, 9, 9, 10, 9, 10, 10, 10,
        9, 9, 10, 9, 10, 9, 10, 9, 9, 9, 10, 10, 9, 10, 9, 9, 8, 9, 9, 9, 9, 10, 10, 10,
        9, 9, 10, 10, 10, 10, 10, 10, 9, 9, 10, 10, 10, 10, 10, 10, 10, 9, 10, 10, 10, 10, 10, 10,
        8, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 9, 10, 10, 10, 10, 10, 10, 10,
        9, 10, 10, 10, 10, 10, 10, 9, 7, 9, 9, 10, 9, 10, 10, 10, 9, 10, 10, 10, 10, 10, 10, 10,
        9, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10,
        10, 10, 10, 13, 10, 10, 10, 10, 10, 10, 11, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10,
        9, 10, 10, 10, 10, 10, 9, 10, 10, 10, 10, 10, 9, 10, 10, 10, 9, 10, 10, 10, 10, 10, 10, 10,
        10, 10, 10, 10, 10, 10, 10, 10, 9, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 10, 9, 10,
        8, 9, 9, 10, 9, 10, 10, 10, 9, 10, 10, 10, 9, 9, 8, 7, 13, 13, 7, 7, 10, 7, 7, 6,
        6, 6, 6, 5, 6, 6, 6, 5, 6, 5, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6, 6,
        8, 5, 6, 7, 7, 13,
    ];
    private const LEC_CODE = [
        4, 36, 20, 17, 81, 49, 113, 9, 73, 41, 105, 21, 149, 85, 213, 53, 181, 117, 29, 245, 285, 157, 413, 93,
        13, 141, 349, 221, 477, 61, 317, 189, 77, 445, 125, 107, 381, 253, 509, 3, 259, 131, 387, 619, 67, 363, 875, 235,
        323, 195, 747, 451, 491, 35, 1003, 291, 163, 419, 27, 539, 99, 283, 355, 227, 205, 483, 19, 275, 147, 795, 155, 667,
        403, 83, 411, 923, 91, 603, 347, 859, 339, 211, 219, 731, 475, 987, 59, 571, 315, 467, 827, 187, 699, 443, 955, 123,
        45, 635, 379, 891, 251, 763, 507, 1019, 7, 519, 263, 775, 135, 647, 391, 903, 51, 71, 583, 327, 839, 199, 711, 455,
        307, 967, 39, 551, 295, 807, 167, 179, 25, 435, 115, 679, 371, 423, 935, 103, 243, 615, 359, 871, 231, 743, 487, 999,
        499, 23, 535, 279, 791, 151, 663, 407, 919, 87, 599, 343, 855, 215, 727, 471, 983, 55, 567, 311, 823, 183, 695, 439,
        951, 119, 631, 2047, 375, 887, 247, 759, 503, 1015, 1023, 15, 527, 271, 783, 143, 655, 399, 911, 79, 591, 335, 847, 207,
        11, 719, 463, 975, 47, 559, 267, 303, 815, 175, 687, 431, 139, 943, 111, 623, 395, 367, 879, 239, 751, 495, 1007, 31,
        543, 287, 799, 159, 671, 415, 927, 95, 75, 607, 351, 863, 223, 735, 479, 991, 63, 575, 319, 831, 191, 703, 331, 447,
        173, 203, 459, 959, 43, 127, 639, 383, 299, 895, 255, 767, 171, 427, 109, 89, 6143, 4095, 57, 121, 511, 5, 69, 52,
        12, 44, 28, 0, 60, 2, 34, 16, 18, 8, 50, 10, 42, 26, 58, 6, 38, 22, 54, 14, 46, 30, 62, 1,
        237, 24, 33, 37, 101, 8191,
    ];
    private const LOM_LEN = [
        4, 2, 3, 4, 3, 4, 4, 5, 4, 5, 5, 6, 6, 7, 7, 8, 7, 8, 8, 9, 9, 8, 9, 9,
        9, 9, 9, 9, 9, 9, 9, 9,
    ];
    private const LOM_CODE = [
        1, 0, 2, 9, 6, 5, 13, 11, 3, 27, 7, 23, 55, 15, 79, 111, 47, 239, 31, 95, 351, 159, 223, 479,
        63, 319, 191, 447, 127, 383, 255, 511,
    ];
    private const COPY_BITS = [
        0, 0, 0, 0, 1, 1, 2, 2, 3, 3, 4, 4, 5, 5, 6, 6, 7, 7, 8, 8, 9, 9, 10, 10,
        11, 11, 12, 12, 13, 13, 14, 14,
    ];
    private const COPY_BASE = [
        1, 2, 3, 4, 5, 7, 9, 13, 17, 25, 33, 49, 65, 97, 129, 193, 257, 385, 513, 769, 1025, 1537, 2049, 3073,
        4097, 6145, 8193, 12289, 16385, 24577, 32769, 49153,
    ];
    private const LOM_BITS = [
        0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 1, 1, 2, 2, 2, 2, 3, 3, 3, 3, 4, 4, 4, 4,
        6, 6, 8, 8, 14, 14,
    ];
    private const LOM_BASE = [
        2, 3, 4, 5, 6, 7, 8, 9, 10, 12, 14, 16, 18, 22, 26, 30, 34, 42, 50, 58, 66, 82, 98, 114,
        130, 194, 258, 514, 2, 2,
    ];
    /**
     * Decoding tables: index = next 13 (LEC) or 9 (LOM) bits, value = code length | what the symbol says << 4 (0 = invalid):
     * LEC its symbol, and for an offset class (257-288) its extra bits << 9 and base - 1 << 13; LOM its extra bits and
     * base << 4 (no entry for 30 and 31). One lookup gives what took four.
     */
    private static array $lec = [];
    private static array $lom = [];
    /** Each byte as a string */
    private static array $chr = [];

    private string $hist = '';          // history up to the write position (unwritten bytes read as zeros)
    private array $cache = [0, 0, 0, 0];

    public function __construct()
    {
        if (self::$lec === []) {
            $lec = range(0, count(self::LEC_LEN) - 1);
            foreach (self::COPY_BITS as $k => $b) {
                $lec[257 + $k] |= $b << 9 | (self::COPY_BASE[$k] - 1) << 13;
            }
            $lom = [];
            foreach (self::LOM_BITS as $k => $b) {
                $lom[$k] = $b | self::LOM_BASE[$k] << 4;
            }
            self::$lec = self::table(self::LEC_LEN, self::LEC_CODE, 13, $lec);
            self::$lom = self::table(self::LOM_LEN, self::LOM_CODE, 9, $lom);
            self::$chr = array_map('chr', range(0, 255));
        }
    }

    private static function table(array $lengths, array $codes, int $bits, array $values): array
    {
        $t = array_fill(0, 1 << $bits, 0);
        foreach ($lengths as $sym => $len) {
            for ($hi = 0; isset($values[$sym]) && $hi < 1 << ($bits - $len); $hi++) {
                $t[$codes[$sym] | ($hi << $len)] = $len | $values[$sym] << 4;
            }
        }
        return $t;
    }

    /** Decompresses one block: the bits read 32 at a time from the block unpacked into words (1.2-1.4 times faster). */
    public function decompress(string $data, int $flags): string
    {
        if ($flags & Mppc::AT_FRONT) {                     // keep the last 32 KB at the front
            if (strlen($this->hist) <= 32768) {
                throw new ProtocolException('NCRUSH: bad history move', 'protocol');
            }
            $this->hist = substr($this->hist, -32768);
        }
        if ($flags & Mppc::FLUSHED) {
            $this->hist  = '';
            $this->cache = [0, 0, 0, 0];
        }
        if (!($flags & Mppc::COMPRESSED)) {
            return $data;
        }
        $n     = strlen($data);
        $chr   = self::$chr;
        $lec   = self::$lec;
        $lom   = self::$lom;
        $w     = unpack('V*', $data . str_repeat("\0", 4 + (-$n & 3)));   // 32-bit words, least significant bit first
        $wi    = 1;
        $h     = $this->hist;
        $this->hist = '';                                   // ($h alone holds it: appended in place, not copied)
        $start = strlen($h);
        $bits  = 0;                                         // next bits, least significant first (up to 63)
        $nbits = 0;
        [$c0, $c1, $c2, $c3] = $this->cache;
        while (true) {
            if ($nbits < 32) {                              // 32 more bits (zeros past the end): enough for a literal
                $bits  |= ($w[$wi++] ?? 0) << $nbits;
                $nbits += 32;
            }
            $e = $lec[$bits & 0x1FFF];
            if ($e === 0) {
                throw new ProtocolException('NCRUSH: bad code', 'protocol');
            }
            $bits  >>= $e & 15;
            $nbits -= $e & 15;
            if ($e < 0x1000) {                              // a literal
                $h .= $chr[$e >> 4];
                continue;
            }
            $sym = ($e >> 4) & 0x1FF;
            if ($sym === 256) {                             // end of stream
                break;
            }
            if ($sym < 289) {                               // copy offset class: base + extra bits; the cache shifts down
                $b      = ($e >> 13) & 15;
                $off    = ($e >> 17) + ($bits & ((1 << $b) - 1));
                $bits  >>= $b;
                $nbits -= $b;
                $c3     = $c2;
                $c2     = $c1;
                $c1     = $c0;
                $c0     = $off;
            } elseif ($sym === 289) {                       // offset cache entry, moved to the front
                $off = $c0;
            } elseif ($sym === 290) {
                $off = $c1;
                $c1  = $c0;
                $c0  = $off;
            } elseif ($sym === 291) {
                $off = $c2;
                $c2  = $c0;
                $c0  = $off;
            } elseif ($sym === 292) {
                $off = $c3;
                $c3  = $c0;
                $c0  = $off;
            } else {
                throw new ProtocolException('NCRUSH: bad symbol', 'protocol');
            }
            if ($nbits < 32) {                              // a length needs up to 23 more bits
                $bits  |= ($w[$wi++] ?? 0) << $nbits;
                $nbits += 32;
            }
            $e = $lom[$bits & 0x1FF];
            if ($e === 0) {
                throw new ProtocolException('NCRUSH: bad length', 'protocol');
            }
            $bits  >>= $e & 15;
            $nbits -= $e & 15;
            $b      = ($e >> 4) & 15;
            $len    = ($e >> 8) + ($bits & ((1 << $b) - 1));
            $bits  >>= $b;
            $nbits -= $b;
            $pos = strlen($h);
            if ($len < 2 || $pos + $len >= self::SIZE - 1) {
                throw new ProtocolException('NCRUSH: history overflow', 'protocol');
            }
            $from = ($pos - $off) & 0xFFFF;
            if ($off === 0 || $from >= $pos) {               // before the start of the history: zeros
                $h .= str_repeat("\0", $len);
            } elseif ($off >= $len) {
                $h .= substr($h, $from, $len);
            } else {                                        // overlapping: the last $off bytes repeat
                $h .= substr(str_repeat(substr($h, $from, $off), intdiv($len, $off) + 1), 0, $len);
            }
        }
        if (($wi - 1) * 32 - $nbits > $n * 8) {             // the end marker was read from the zero padding
            throw new ProtocolException('NCRUSH: truncated block', 'protocol');
        }
        if (strlen($h) > self::SIZE) {                      // literals past the history's end: the browser refuses them too
            throw new ProtocolException('NCRUSH: history overflow', 'protocol');
        }
        $this->cache = [$c0, $c1, $c2, $c3];
        $this->hist  = $h;
        return substr($h, $start);
    }
}
