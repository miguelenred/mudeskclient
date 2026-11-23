<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// Kerberos for the tests' servers (tests/fixtures/kdc_server.php, rdg_server.php, nla_server.php), written from RFC 4120,
// RFC 4121 and RFC 4178: the realm TEST.LOCAL with its keys (every one made from a known secret, so the KDC and the services
// share them), the KDC's tickets, and a service's side of the AP exchange (the AP-REQ in SPNEGO checked, its AP-REP made),
// GSS Wrap and Unwrap as the acceptor, the way Windows sends them in CredSSP (EC 0, RRC 28: the trailer rotated to the front).
// The AES encryption itself is the client's (MuDesk\Rdp\KerberosCrypto, checked against the RFC 3961/3962 vectors). The
// principals of a Windows Server 2003 domain (RC4_PRINCIPALS: their keys, tickets and session keys rc4-hmac) and RFC 4757's
// GSS tokens are written here on their own, from RFC 4757.
declare(strict_types=1);

use MuDesk\Rdp\{Der, KerberosCrypto as KC, Rc4, Reader};
use MuDesk\Text;

final class KrbFixture
{
    public const REALM = 'TEST.LOCAL';
    private const ETYPE = 18;
    /** The principals whose only key is RC4 (as in a Windows Server 2003 domain). */
    public const RC4_PRINCIPALS = ['TERMSRV/viejo.test.local', 'HTTP/viejo.test.local', 'viejo'];

    /** The encryption type of a principal's key. */
    public static function etypeOf(string $principal): int
    {
        return in_array($principal, self::RC4_PRINCIPALS, true) ? 23 : self::ETYPE;
    }

    /** The long-term key of a principal ('krbtgt', 'HTTP/rdg.test.local', or a user with its password). */
    public static function key(string $principal, string $password = ''): string
    {
        $parts  = explode('/', $principal);
        $secret = $password !== '' ? $password : 'secret of ' . $principal;
        return self::etypeOf($principal) === 23 ? hash('md4', Text::toUtf16($secret), true) : KC::stringToKey(self::ETYPE, $secret, self::REALM . implode('', $parts));
    }

    public static function app(int $n, string $c): string
    {
        return Der::tlv(0x60 | $n, $c);
    }

    public static function str(string $s): string
    {
        return Der::tlv(0x1B, $s);
    }

    public static function time(int $t): string
    {
        return Der::tlv(0x18, gmdate('YmdHis', $t) . 'Z');
    }

    public static function principal(int $type, string ...$parts): string
    {
        return Der::seq(Der::ctx(0, Der::int($type)), Der::ctx(1, Der::seq(...array_map([self::class, 'str'], $parts))));
    }

    /**
     * rc4-hmac (RFC 4757 4): K1 = HMAC-MD5(key, T as 4 bytes LE), the checksum HMAC-MD5(K1, confounder | data), RC4 with
     * HMAC-MD5(K1, checksum); $t the RFC's own usage number (8 for the AS-REP's part, as Windows encrypts it).
     */
    public static function rc4hmac(string $key, int $t, string $data, bool $encrypt): string
    {
        $k1 = hash_hmac('md5', pack('V', $t === 3 ? 8 : $t), $key, true);
        if ($encrypt) {
            $p  = random_bytes(8) . $data;
            $ck = hash_hmac('md5', $p, $k1, true);
            return $ck . (new Rc4(hash_hmac('md5', $ck, $k1, true)))->crypt($p);
        }
        $ck = substr($data, 0, 16);
        $p  = (new Rc4(hash_hmac('md5', $ck, $k1, true)))->crypt(substr($data, 16));
        if (!hash_equals(hash_hmac('md5', $p, $k1, true), $ck)) {
            throw new RuntimeException('rc4 checksum');
        }
        return substr($p, 8);
    }

    /** The HMAC-MD5 checksum (-138, RFC 4757 4) of $data with an RC4 key. */
    public static function rc4Checksum(string $key, int $usage, string $data): string
    {
        return hash_hmac('md5', md5(pack('V', $usage) . $data, true), hash_hmac('md5', "signaturekey\0", $key, true), true);
    }

    /** An EncryptedData of $plain with $key, of type $etype (AES256, or RC4). */
    public static function enc(string $key, int $usage, string $plain, int $kvno = 0, int $etype = self::ETYPE): string
    {
        return Der::seq(Der::ctx(0, Der::int($etype)), $kvno ? Der::ctx(1, Der::int($kvno)) : '',
            Der::ctx(2, Der::octet($etype === 23 ? self::rc4hmac($key, $usage, $plain, true) : KC::encrypt($key, $usage, $plain))));
    }

    /** The plaintext of an EncryptedData (its type says which key it is). */
    public static function dec(string $key, int $usage, string $encData): string
    {
        $e = Der::fields(Der::value($encData));
        return Der::toInt($e[0]) === 23 ? self::rc4hmac($key, $usage, Der::value($e[2]), false) : KC::decrypt($key, $usage, Der::value($e[2]));
    }

    /** The content of [APPLICATION n] SEQUENCE {...}: its context fields. */
    public static function fields(string $element, int $app): array
    {
        [$tag, $c] = Der::read(new Reader($element));
        if ($tag !== (0x60 | $app)) {
            throw new RuntimeException(sprintf('[APPLICATION %d] expected, 0x%x', $app, $tag));
        }
        return Der::fields(Der::value($c));
    }

    /**
     * A ticket for $sname (its parts) to $cname, with session key $session (of type $sessionEtype), encrypted with the
     * service's key (of its principal's type).
     */
    public static function ticket(string $serviceKey, array $sname, string $cname, string $session, int $end, int $sessionEtype = self::ETYPE): string
    {
        $part = self::app(3, Der::seq(
            Der::ctx(0, Der::tlv(0x03, "\0\x40\xE1\0\0")),                                      // flags
            Der::ctx(1, Der::seq(Der::ctx(0, Der::int($sessionEtype)), Der::ctx(1, Der::octet($session)))),
            Der::ctx(2, self::str(self::REALM)),
            Der::ctx(3, self::principal(1, $cname)),
            Der::ctx(4, Der::seq(Der::ctx(0, Der::int(1)), Der::ctx(1, Der::octet('')))),     // transited
            Der::ctx(5, self::time(time())),
            Der::ctx(7, self::time($end)),
        ));
        return self::app(1, Der::seq(Der::ctx(0, Der::int(5)), Der::ctx(1, self::str(self::REALM)), Der::ctx(2, self::principal(2, ...$sname)),
            Der::ctx(3, self::enc($serviceKey, 2, $part, 1, self::etypeOf(implode('/', $sname))))));
    }

    /** [client name, session key, its type] of a ticket, decrypted with the service's key. */
    public static function openTicket(string $ticket, string $serviceKey): array
    {
        $f = self::fields($ticket, 1);
        $p = self::fields(self::dec($serviceKey, 2, $f[3]), 3);
        $k = Der::fields(Der::value($p[1]));
        $n = Der::fields(Der::value($p[3]));
        $names = [];
        $r = new Reader(Der::value($n[1]));
        while ($r->left() > 0) {
            $names[] = Der::read($r)[1];
        }
        return [implode('/', $names), Der::value($k[1]), Der::toInt($k[0])];
    }

    /**
     * A service's side of the AP exchange: $token (SPNEGO NegTokenInit or a bare Kerberos token) with an AP-REQ for $spn;
     * its ticket and authenticator decrypted, the GSS checksum's channel bindings compared with $bindings (null: not
     * checked). Returns [user, bindings ok, context key (the acceptor's subkey), the client's sequence number, our sequence
     * number, the answer to send (SPNEGO NegTokenResp with the AP-REP; $mic: with a mechListMIC too), the client's mechTypes,
     * whether its authenticator says it sends channel bindings, the GSS flags, the context key's type].
     */
    public static function accept(string $token, string $spn, ?string $bindings, bool $mic = false): array
    {
        $mechTypes = '';
        [$tag, $c] = Der::read(new Reader($token));
        $r = new Reader($c);
        [, $oid] = Der::read($r);
        if ($oid === "\x2B\x06\x01\x05\x05\x02") {                      // SPNEGO: NegTokenInit [0] { [0] mechTypes, [2] mechToken }
            [, $init] = Der::read($r);
            $f = Der::fields(Der::value($init));
            $mechTypes = $f[0];
            [, $c] = Der::read(new Reader(Der::value($f[2])));
            $r = new Reader($c);
            Der::read($r);                                             // (the krb5 OID)
        }
        if ($r->bytes(2) !== "\x01\x00") {
            throw new RuntimeException('not an AP-REQ');
        }
        $ap = self::fields($r->bytes($r->left()), 14);
        [$user, $session, $etype] = self::openTicket($ap[3], self::key($spn));
        $a = self::fields(self::dec($session, 11, $ap[4]), 2);
        $ck = Der::fields(Der::value($a[3]));
        $gss = Der::value($ck[1]);
        $sub = Der::fields(Der::value($a[6]));
        $subkey = Der::value($sub[1]);
        $clientSeq = Der::toInt($a[7]) & 0xFFFFFFFF;
        $ourKey = random_bytes($etype === 23 ? 16 : 32);               // (Windows gives its own subkey, of the ticket's type)
        $ourSeq = random_int(0, 0x3FFFFFFF);
        $repPart = self::app(27, Der::seq(Der::ctx(0, $a[5]), Der::ctx(1, $a[4]),
            Der::ctx(2, Der::seq(Der::ctx(0, Der::int($etype)), Der::ctx(1, Der::octet($ourKey)))), Der::ctx(3, Der::int($ourSeq))));
        $apRep = self::app(15, Der::seq(Der::ctx(0, Der::int(5)), Der::ctx(1, Der::int(15)), Der::ctx(2, self::enc($session, 12, $repPart, 0, $etype))));
        $gssRep = Der::tlv(0x60, Der::tlv(0x06, "\x2A\x86\x48\x86\xF7\x12\x01\x02\x02") . "\x02\x00" . $apRep);
        $micToken = '';
        if ($mic && $etype === 23) {                                    // RFC 4757's MIC token over the client's mechTypes
            $micToken = Der::ctx(3, Der::octet(self::rc4Mic($ourKey, $ourSeq++, $mechTypes, true)));
        } elseif ($mic) {                                               // its mechListMIC over the client's mechTypes
            $hdr = "\x04\x04\x05\xFF\xFF\xFF\xFF\xFF" . pack('J', $ourSeq++);
            $micToken = Der::ctx(3, Der::octet($hdr . KC::checksum($ourKey, 23, $mechTypes . $hdr)));
        }
        // (with a MIC: accept-incomplete, the client's own MIC asked for)
        $reply = Der::ctx(1, Der::seq(Der::ctx(0, Der::tlv(0x0A, $mic ? "\1" : "\0")), Der::ctx(1, Der::tlv(0x06, "\x2A\x86\x48\x82\xF7\x12\x01\x02\x02")),
            Der::ctx(2, Der::octet($gssRep)), $micToken));
        // (the last: whether its authenticator carries the hint that it sends channel bindings, AD-AUTH-DATA-AP-OPTIONS 0x4000)
        $hint = isset($a[8]) && str_contains($a[8], Der::seq(Der::ctx(0, Der::int(143)), Der::ctx(1, Der::octet(pack('V', 0x4000)))));
        return [$user, $bindings === null || substr($gss, 4, 16) === $bindings, $ourKey, $clientSeq, $ourSeq, $reply, $mechTypes, $hint, unpack('V', $gss, 20)[1], $etype];
    }

    /** GSS Wrap as the acceptor, as Windows sends it in CredSSP: sealed with our subkey, rotated by RRC 28 ($ec bytes of filler). */
    public static function wrap(string $key, int $seq, string $plain, int $ec = 0): string
    {
        $hdr  ="\x05\x04\x07\xFF" . pack('nn', $ec, 0) . pack('J', $seq);
        $c    = KC::encrypt($key, 22, $plain . str_repeat("\xFF", $ec) . $hdr);
        $c    = substr($c, -28) . substr($c, 0, -28);                    // rotated right by 28
        return substr($hdr, 0, 6) . pack('n', 28) . substr($hdr, 8) . $c;
    }

    /** GSS Unwrap of the client's sealed token (usage 24; an RC4 context's, RFC 4757's); throws on anything wrong. */
    public static function unwrap(string $key, string $token): string
    {
        if ($token !== '' && $token[0] === "\x60") {
            return self::rc4Unwrap($key, $token, false);
        }
        if (substr($token, 0, 2) !== "\x05\x04" || (ord($token[2]) & 0x03) !== 0x02) {
            throw new RuntimeException('bad wrap token');
        }
        ['ec' => $ec, 'rrc' => $rrc] = unpack('nec/nrrc', $token, 4);
        $c = substr($token, 16);
        $rrc %= strlen($c);
        $p = KC::decrypt($key, 24, substr($c, $rrc) . substr($c, 0, $rrc));
        return substr($p, 0, strlen($p) - 16 - $ec);
    }

    // RFC 4757 7's tokens, framed as RFC 1964's ([APPLICATION 0] { the krb5 OID, the token })

    private static function frame(string $t): string
    {
        return Der::tlv(0x60, Der::tlv(0x06, "\x2A\x86\x48\x86\xF7\x12\x01\x02\x02") . $t);
    }

    private static function unframe(string $t): string
    {
        [, $c] = Der::read(new Reader($t));
        $r = new Reader($c);
        Der::read($r);
        return $r->bytes($r->left());
    }

    /** The 8-byte checksum of a token (usage 13 to seal, 15 to sign). */
    private static function rc4Cksum(string $key, int $usage, string $data): string
    {
        return substr(self::rc4Checksum($key, $usage, $data), 0, 8);
    }

    /** RC4 keyed with HMAC-MD5(HMAC-MD5($key, 0 as 4 bytes), $with). */
    private static function rc4With(string $key, string $with): Rc4
    {
        return new Rc4(hash_hmac('md5', $with, hash_hmac('md5', "\0\0\0\0", $key, true), true));
    }

    /** A MIC token (01 01) over $data, from the acceptor ($acceptor) or the initiator. */
    public static function rc4Mic(string $key, int $seq, string $data, bool $acceptor): string
    {
        $hdr = "\x01\x01\x11\x00\xFF\xFF\xFF\xFF";
        $ck  = self::rc4Cksum($key, 15, $hdr . $data);
        return self::frame($hdr . self::rc4With($key, $ck)->crypt(pack('N', $seq) . str_repeat($acceptor ? "\xFF" : "\0", 4)) . $ck);
    }

    /** Whether a MIC token over $data is right (its checksum). */
    public static function rc4MicOk(string $key, string $token, string $data): bool
    {
        $t = self::unframe($token);
        return substr($t, 0, 8) === "\x01\x01\x11\x00\xFF\xFF\xFF\xFF" && hash_equals(self::rc4Cksum($key, 15, substr($t, 0, 8) . $data), substr($t, 16, 8));
    }

    /** A Wrap token (02 01, sealed: the confounder and the data with one byte of padding in RC4). */
    public static function rc4Wrap(string $key, int $seq, string $plain, bool $acceptor): string
    {
        $hdr  = "\x02\x01\x11\x00\x10\x00\xFF\xFF";
        $body = random_bytes(8) . $plain . "\x01";
        $ck   = self::rc4Cksum($key, 13, $hdr . $body);
        $seal = self::rc4With($key ^ str_repeat("\xF0", 16), pack('N', $seq))->crypt($body);
        return self::frame($hdr . self::rc4With($key, $ck)->crypt(pack('N', $seq) . str_repeat($acceptor ? "\xFF" : "\0", 4)) . $ck . $seal);
    }

    /** The data of a Wrap token from the other side; throws on anything wrong. */
    public static function rc4Unwrap(string $key, string $token, bool $fromAcceptor): string
    {
        $t   = self::unframe($token);
        if (substr($t, 0, 8) !== "\x02\x01\x11\x00\x10\x00\xFF\xFF") {
            throw new RuntimeException('bad rc4 wrap token');
        }
        $ck  = substr($t, 16, 8);
        $seq = self::rc4With($key, $ck)->crypt(substr($t, 8, 8));
        if (substr($seq, 4) !== str_repeat($fromAcceptor ? "\xFF" : "\0", 4)) {
            throw new RuntimeException('rc4 wrap direction');
        }
        $body = self::rc4With($key ^ str_repeat("\xF0", 16), substr($seq, 0, 4))->crypt(substr($t, 24));
        if (!hash_equals(self::rc4Cksum($key, 13, substr($t, 0, 8) . $body), $ck)) {
            throw new RuntimeException('rc4 wrap checksum');
        }
        return substr($body, 8, -ord($body[-1]));
    }
}
