<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk\Rdp;

/**
 * RDP 6.1 bulk decompression, "XCRUSH" (MS-RDPEGDI 3.1.8.2): level 2 is MPPC with its own 64 KB history; level 1
 * rebuilds the data from literals and matches that point anywhere in a 2,000,000-byte history.
 */
final class Xcrush
{
    public const TYPE         = 3;
    private const SIZE        = 2000000;
    private const L1_COMPRESSED = 0x01;
    private const L1_NO_COMPRESSION = 0x02;
    private const L1_AT_FRONT = 0x04;

    private Mppc $l2;
    private string $cur = '';     // history from offset 0 to the write position
    private string $old = '';     // the whole history before the last wrap (read past the write position)

    public function __construct()
    {
        $this->l2 = new Mppc();
    }

    public function decompress(string $data, int $flags): string
    {
        if (strlen($data) < 2) {
            throw new ProtocolException('XCRUSH: short block', 'protocol');
        }
        $l1 = ord($data[0]);
        $l2 = ord($data[1]);
        if ($flags & Mppc::FLUSHED) {
            $this->cur = $this->old = '';
        }
        $data = substr($data, 2);
        if ($l2 & Mppc::COMPRESSED) {
            $data = $this->l2->decompress($data, $l2);
        }
        if ($l1 & self::L1_AT_FRONT) {                       // writing starts again at offset 0
            if ($this->cur !== '' && strlen($this->cur) < self::SIZE - 65544) {   // only once the history is (nearly) full
                throw new ProtocolException('XCRUSH: early history reset', 'protocol');
            }
            $this->old = $this->cur . (string)substr($this->old, strlen($this->cur));
            $this->cur = '';
        }
        $start = strlen($this->cur);
        $n     = strlen($data);
        $lit   = 0;                                           // next literal byte
        if (!($l1 & self::L1_NO_COMPRESSION)) {
            if (!($l1 & self::L1_COMPRESSED) || $n < 2) {
                throw new ProtocolException('XCRUSH: bad level 1 flags', 'protocol');
            }
            $count = unpack('v', $data)[1];
            $lit   = 2 + 8 * $count;
            if ($lit > $n) {
                throw new ProtocolException('XCRUSH: bad match count', 'protocol');
            }
            $out = 0;                                         // output offset within this block
            for ($i = 0; $i < $count; $i++) {
                ['len' => $len, 'at' => $at, 'from' => $from] = unpack('vlen/vat/Vfrom', $data, 2 + 8 * $i);
                if ($at < $out || $at - $out > $n - $lit) {
                    throw new ProtocolException('XCRUSH: bad match', 'protocol');
                }
                $this->cur .= substr($data, $lit, $at - $out);   // literals before the match
                $lit       += $at - $out;
                $pos        = strlen($this->cur);
                if ($pos + $len >= self::SIZE || $from + $len >= self::SIZE) {
                    throw new ProtocolException('XCRUSH: history overflow', 'protocol');
                }
                $this->cur .= $this->read($from, $len, $pos);
                $out        = $at + $len;
            }
        }
        if (strlen($this->cur) + $n - $lit >= self::SIZE) {
            throw new ProtocolException('XCRUSH: history overflow', 'protocol');
        }
        $this->cur .= substr($data, $lit);
        return substr($this->cur, $start);
    }

    /** $len bytes from history offset $from, copied forward as if byte by byte to position $pos. */
    private function read(int $from, int $len, int $pos): string
    {
        if ($from < $pos && $from + $len > $pos) {          // overlaps what is being written: the bytes between repeat
            return substr(str_repeat(substr($this->cur, $from, $pos - $from), intdiv($len, $pos - $from) + 1), 0, $len);
        }
        if ($from + $len <= $pos) {
            return substr($this->cur, $from, $len);
        }
        $old = substr($this->old, $from, $len);              // past the write position: from before the wrap (zeros if never written)
        return $old . str_repeat("\0", $len - strlen($old));
    }
}
