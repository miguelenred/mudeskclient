<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk\Rdp;

/** Sequential little/big-endian binary reader. */
final class Reader
{
    public int $pos = 0;

    public function __construct(public readonly string $data)
    {
    }

    public function left(): int
    {
        return strlen($this->data) - $this->pos;
    }

    private function need(int $n): void
    {
        if ($n < 0 || $this->pos + $n > strlen($this->data)) {
            throw new ProtocolException('Truncated PDU');
        }
    }

    public function bytes(int $n): string
    {
        $this->need($n);
        $s          = substr($this->data, $this->pos, $n);
        $this->pos += $n;
        return $s;
    }

    public function rest(): string
    {
        return $this->bytes($this->left());
    }

    public function skip(int $n): void
    {
        $this->need($n);
        $this->pos += $n;
    }

    public function u8(): int
    {
        $this->need(1);
        return ord($this->data[$this->pos++]);
    }

    public function u16(): int
    {
        return unpack('v', $this->bytes(2))[1];
    }

    public function u16be(): int
    {
        return unpack('n', $this->bytes(2))[1];
    }

    public function u32(): int
    {
        return unpack('V', $this->bytes(4))[1];
    }

    public function i32(): int
    {
        return unpack('l', pack('L', $this->u32()))[1];
    }

    public function u64(): int
    {
        return unpack('P', $this->bytes(8))[1];
    }

    /** PER length (MCS): 1 or 2 bytes. */
    public function perLength(): int
    {
        $b = $this->u8();
        return $b & 0x80 ? (($b & 0x7f) << 8) | $this->u8() : $b;
    }

    /** BER length. */
    public function berLength(): int
    {
        $b = $this->u8();
        if ($b < 0x80) {
            return $b;
        }
        $n = 0;
        for ($i = $b & 0x7f; $i > 0; $i--) {
            $n = ($n << 8) | $this->u8();
        }
        return $n;
    }
}
