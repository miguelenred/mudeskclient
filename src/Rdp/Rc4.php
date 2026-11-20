<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk\Rdp;

/** RC4 stream cipher (needed by NTLM and licensing; OpenSSL 3 no longer ships it by default). */
final class Rc4
{
    private array $s;
    private int $i = 0;
    private int $j = 0;

    public function __construct(string $key)
    {
        $s = range(0, 255);
        $k = array_values(unpack('C*', $key));
        $n = count($k);
        for ($i = 0, $j = 0; $i < 256; $i++) {
            $j          = ($j + $s[$i] + $k[$i % $n]) & 255;
            [$s[$i], $s[$j]] = [$s[$j], $s[$i]];
        }
        $this->s = $s;
    }

    public function crypt(string $data): string
    {
        $s   = $this->s;
        $i   = $this->i;
        $j   = $this->j;
        $out = '';
        foreach (unpack('C*', $data) ?: [] as $b) {
            $i     = ($i + 1) & 255;
            $j     = ($j + $s[$i]) & 255;
            $t     = $s[$i];
            $s[$i] = $s[$j];
            $s[$j] = $t;
            $out  .= chr($b ^ $s[($s[$i] + $t) & 255]);
        }
        $this->s = $s;
        $this->i = $i;
        $this->j = $j;
        return $out;
    }
}
