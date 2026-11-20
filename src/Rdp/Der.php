<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk\Rdp;

/** Minimal ASN.1 DER/BER encoder and decoder (CredSSP, MCS, certificates). */
final class Der
{
    public static function len(int $n): string
    {
        if ($n < 0x80) {
            return chr($n);
        }
        $b = ltrim(pack('N', $n), "\0");
        return chr(0x80 | strlen($b)) . $b;
    }

    public static function tlv(int $tag, string $content): string
    {
        return ($tag > 0xff ? pack('n', $tag) : chr($tag)) . self::len(strlen($content)) . $content;
    }

    public static function seq(string ...$items): string
    {
        return self::tlv(0x30, implode('', $items));
    }

    public static function ctx(int $n, string $content): string
    {
        return self::tlv(0xA0 | $n, $content);
    }

    public static function int(int $v): string
    {
        $b = pack('J', $v);
        while (strlen($b) > 1 && (($b[0] === "\0" && ord($b[1]) < 0x80) || ($b[0] === "\xff" && ord($b[1]) >= 0x80))) {
            $b = substr($b, 1);
        }
        return self::tlv(0x02, $b);
    }

    public static function octet(string $s): string
    {
        return self::tlv(0x04, $s);
    }

    /** Reads one element: [tag, content]. Multi-byte tags (0x7f65) are returned as 16-bit values. */
    public static function read(Reader $r): array
    {
        $tag = $r->u8();
        if (($tag & 0x1f) === 0x1f) {
            $tag = ($tag << 8) | $r->u8();
        }
        $len = $r->berLength();
        return [$tag, $r->bytes($len)];
    }

    /** Context-tagged fields of a SEQUENCE body: [n => inner content]. */
    public static function fields(string $seqBody): array
    {
        $r   = new Reader($seqBody);
        $out = [];
        while ($r->left() > 0) {
            [$tag, $content] = self::read($r);
            if (($tag & 0xE0) === 0xA0) {
                $out[$tag & 0x1f] = $content;
            }
        }
        return $out;
    }

    /** Unwraps a primitive element and returns its content. */
    public static function value(string $element): string
    {
        return self::read(new Reader($element))[1];
    }

    public static function toInt(string $element): int
    {
        $b = self::value($element);
        if ($b === '' || strlen($b) > 8) {
            throw new ProtocolException('Invalid DER integer', 'protocol');
        }
        $v = ord($b[0]) & 0x80 ? -1 : 0;
        foreach (str_split($b) as $c) {
            $v = ($v << 8) | ord($c);
        }
        return $v;
    }

    /** SubjectPublicKey (BIT STRING contents) of a certificate, as CredSSP binds to it. */
    public static function subjectPublicKey(\OpenSSLCertificate $cert): string
    {
        $pem = openssl_pkey_get_details(openssl_pkey_get_public($cert))['key'];
        $der = base64_decode(preg_replace('~-----[^-]+-----|\s~', '', $pem));
        $spki = new Reader(self::value($der));
        self::read($spki);                    // AlgorithmIdentifier
        [, $bits] = self::read($spki);        // BIT STRING
        return substr($bits, 1);
    }
}
