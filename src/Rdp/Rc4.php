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

    /**
     * The key stream made 4 KB at a time as numbers, packed, and XORed with the data in one go: 1.3-1.9 times faster than a
     * character at a time (up to 3 times with the JIT). Standard RDP Security decrypts everything the server sends with it.
     */
    public function crypt(string $data): string
    {
        $n   = strlen($data);
        $s   = $this->s;
        $i   = $this->i;
        $j   = $this->j;
        $out = '';
        for ($p = 0; $p < $n;) {
            $ks = [];
            for ($e = min($n, $p + 4096); $p < $e; $p++) {
                $i     = ($i + 1) & 255;
                $t     = $s[$i];
                $j     = ($j + $t) & 255;
                $s[$i] = $u = $s[$j];
                $s[$j] = $t;
                $ks[]  = $s[($t + $u) & 255];
            }
            $out .= pack('C*', ...$ks);
        }
        $this->s = $s;
        $this->i = $i;
        $this->j = $j;
        return $data ^ $out;
    }
}
