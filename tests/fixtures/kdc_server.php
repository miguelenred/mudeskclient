<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// A Kerberos KDC for the tests (RFC 4120, as Active Directory answers), realm TEST.LOCAL, over TCP: the AS exchange with
// pre-authentication required (KDC_ERR_PREAUTH_REQUIRED with PA-ETYPE-INFO2, then the encrypted timestamp checked), the
// TGS exchange (the user's ticket and the authenticator checked, the checksum over the request's body too), and the errors
// Windows gives: a wrong password (24), an unknown user (6) or service (7), a locked account (18 with its NTSTATUS), a
// clock too far (37, with the KDC's time: the client corrects it and asks again).
//   php kdc_server.php <user> <password>
// Prints its port, then one line per request: "as <user> <ok|preauth|bad password|unknown|locked|skew|referral|etype>",
// "tgs <spn> <ok|unknown|referral>".
// User "locked": its account locked ("locked2": its NTSTATUS as older Windows sends it, a PA-DATA of type 3 in a METHOD-DATA).
// User "skewed": its timestamp answered with KRB_AP_ERR_SKEW, the KDC an hour ahead, until the client's has that hour.
// User "viejo": an account of a Windows
// Server 2003 domain (RFC 4757: its key, its session keys and its tickets' all RC4; the request must offer type 23), with
// the services TERMSRV/viejo.test.local and HTTP/viejo.test.local (RC4 keys only). User "iter1": a salt with
// an iteration count of 1. User "cortaclave": a session key of 16 bytes said to be AES256. User "falso": as "viejo", but
// its RC4 said whatever the client offers (as someone between it and the KDC would). User "rc4sesion": an AES key, its
// session key RC4 whatever the client offers. "<user>@empresa.com": an alternate
// UPN suffix of the user's account (an enterprise name). A request for another realm with an enterprise name:
// KDC_ERR_WRONG_REALM, this realm in its crealm. Service TERMSRV/pc.otro.local: a referral to OTRO.LOCAL's KDC.
declare(strict_types=1);

use MuDesk\Rdp\{Der, KerberosCrypto as KC, Rc4, Reader};
use MuDesk\Text;

require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/krb_fixture.php';
ini_set('display_errors', 'stderr');

[, $user, $password] = $argv;
$srv = stream_socket_server('tcp://127.0.0.1:0', $en, $es);
echo substr((string)strrchr((string)stream_socket_get_name($srv, false), ':'), 1), "\n";
$services = ['HTTP/rdg.test.local', 'TERMSRV/pc.test.local', 'HTTP/legacy.test.local', 'HTTP/krbbad.test.local', 'TERMSRV/pc2.test.local', 'TERMSRV/viejo.test.local', 'HTTP/viejo.test.local'];
$skewed = false;

$error = static function (int $code, string $edata = '', ?int $stime = null, ?string $crealm = null): string {
    return KrbFixture::app(30, Der::seq(Der::ctx(0, Der::int(5)), Der::ctx(1, Der::int(30)), Der::ctx(4, KrbFixture::time($stime ?? time())),
        Der::ctx(5, Der::int(0)), Der::ctx(6, Der::int($code)), $crealm !== null ? Der::ctx(7, KrbFixture::str($crealm)) : '',
        Der::ctx(9, KrbFixture::str(KrbFixture::REALM)), Der::ctx(10, KrbFixture::principal(2, 'krbtgt', KrbFixture::REALM)),
        $edata !== '' ? Der::ctx(12, Der::octet($edata)) : ''));
};
// rc4-hmac (RFC 4757 4), written here from the RFC on its own: K1 = HMAC-MD5(key, T as 4 bytes LE), the checksum HMAC-MD5(K1,
// confounder | data), RC4 with HMAC-MD5(K1, checksum); $t the RFC's own usage number (8 for the AS-REP's part)
$rc4hmac = static function (string $key, int $t, string $data, bool $encrypt): string {
    $k1 = hash_hmac('md5', pack('V', $t), $key, true);
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
};
$names = static function (string $principalSeq): array {
    $f = Der::fields(Der::value($principalSeq));
    $out = [];
    $r = new Reader(Der::value($f[1]));
    while ($r->left() > 0) {
        $out[] = Der::read($r)[1];
    }
    return $out;
};
// a KDC-REP: the ticket and the reply's part (EncASRepPart 25, or as Windows does for both, 26) encrypted with $key ($seal:
// encrypted so instead, an EncryptedData)
$reply = static function (int $app, string $cname, string $ticket, string $key, int $usage, string $session, int $nonce, array $sname,
                          ?Closure $seal = null, int $sessionEtype = 18): string {
    $part = KrbFixture::app(26, Der::seq(
        Der::ctx(0, Der::seq(Der::ctx(0, Der::int($sessionEtype)), Der::ctx(1, Der::octet($session)))),
        Der::ctx(1, Der::seq(Der::seq(Der::ctx(0, Der::int(0)), Der::ctx(1, KrbFixture::time(time()))))),
        Der::ctx(2, Der::int($nonce)),
        Der::ctx(4, Der::tlv(0x03, "\0\x40\xE1\0\0")),
        Der::ctx(5, KrbFixture::time(time())),
        Der::ctx(7, KrbFixture::time(time() + 36000)),
        Der::ctx(9, KrbFixture::str(KrbFixture::REALM)),
        Der::ctx(10, KrbFixture::principal(2, ...$sname)),
    ));
    return KrbFixture::app($app, Der::seq(Der::ctx(0, Der::int(5)), Der::ctx(1, Der::int($app)), Der::ctx(3, KrbFixture::str(KrbFixture::REALM)),
        Der::ctx(4, KrbFixture::principal(1, $cname)), Der::ctx(5, $ticket), Der::ctx(6, $seal !== null ? $seal($part) : KrbFixture::enc($key, $usage, $part))));
};

while ($c = @stream_socket_accept($srv, 120)) {
    stream_set_timeout($c, 5);
    $h = (string)fread($c, 4);
    $n = strlen($h) === 4 ? unpack('N', $h)[1] : 0;
    $req = '';
    while (strlen($req) < $n && ($d = fread($c, $n - strlen($req))) !== false && $d !== '') {
        $req .= $d;
    }
    try {
        $tag = ord($req[0] ?? "\0");
        $f   = KrbFixture::fields($req, $tag & 0x1F);
        $body = Der::fields(Der::value($f[4]));
        $nonce = Der::toInt($body[7]);
        $padata = [];
        if (isset($f[3])) {
            $r = new Reader(Der::value($f[3]));
            while ($r->left() > 0) {
                $p = Der::fields(Der::read($r)[1]);
                $padata[Der::toInt($p[1])] = Der::value($p[2]);
            }
        }
        if ($tag === 0x6A) {                                           // AS-REQ
            $cname   = $names($body[1])[0];
            $ntype   = Der::toInt(Der::fields(Der::value($body[1]))[0]);
            $realm   = Der::value($body[2]);
            $etypes  = array_map(static fn(string $e): int => Der::toInt(Der::tlv(0x02, $e)), (static function (string $seq): array {
                $out = [];
                for ($r = new Reader(Der::value($seq)); $r->left() > 0;) {
                    $out[] = Der::read($r)[1];
                }
                return $out;
            })($body[8]));
            // the account: an enterprise name of an alternate UPN suffix is the user's own
            $account = $ntype === 10 && strcasecmp($cname, "$user@empresa.com") === 0 ? $user : $cname;
            $rc4     = $account === 'viejo' || $account === 'falso';
            $rc4Ses  = $rc4 || $account === 'rc4sesion';                // (its session key RC4)
            $salt    = KrbFixture::REALM . $account . "#salt";             // (not the default one: the client must take it from PA-ETYPE-INFO2)
            if ($realm !== KrbFixture::REALM) {                        // another realm: an enterprise name referred here
                echo $ntype === 10 ? "as $cname referral\n" : "as $cname unknown\n";
                $out = $ntype === 10 ? $error(68, '', null, KrbFixture::REALM) : $error(6);
            } elseif (!in_array($account, [$user, 'locked', 'locked2', 'skewed', 'viejo', 'iter1', 'cortaclave', 'falso', 'rc4sesion'], true)) {
                echo "as $cname unknown\n";
                $out = $error(6);
            } elseif ($account === 'viejo' && !in_array(23, $etypes, true)) {   // KDC_ERR_ETYPE_NOSUPP: the client offers no key the account has
                echo "as $cname etype\n";
                $out = $error(14);
            } elseif (!isset($padata[2])) {                            // pre-authentication required: the salt and the type
                echo "as $cname preauth\n";
                $info = Der::seq(Der::seq(Der::ctx(0, Der::int($rc4 ? 23 : 18)), $rc4 ? '' : Der::ctx(1, KrbFixture::str($salt)),
                    $account === 'iter1' ? Der::ctx(2, Der::octet(pack('N', 1))) : ''));
                $out  = $error(25, Der::seq(Der::seq(Der::ctx(1, Der::int(19)), Der::ctx(2, Der::octet($info)))));
            } else {
                $e   = Der::fields(Der::value($padata[2]));
                $key = $rc4 ? hash('md4', Text::toUtf16($password), true) : KC::stringToKey(18, $password, $salt);
                try {
                    $ts = Der::fields(Der::value($rc4 ? $rc4hmac($key, 1, Der::value($e[2]), false) : KC::decrypt($key, 1, Der::value($e[2]))));
                } catch (Throwable) {
                    $ts = null;
                }
                if ($ts === null) {
                    echo "as $cname bad password\n";
                    $out = $error(24);
                } elseif ($account === 'locked') {                      // KERB-ERROR-DATA with KERB-EXT-ERROR: STATUS_ACCOUNT_LOCKED_OUT
                    echo "as $cname locked\n";
                    $out = $error(18, Der::seq(Der::ctx(1, Der::int(3)), Der::ctx(2, Der::octet(pack('VVV', 0xC0000234, 0, 1)))));
                } elseif ($account === 'locked2') {                     // the same in a METHOD-DATA: PA-DATA { 3, the status }
                    echo "as $cname locked\n";
                    $out = $error(18, Der::seq(Der::seq(Der::ctx(1, Der::int(3)), Der::ctx(2, Der::octet(pack('VVV', 0xC0000234, 0, 1))))));
                } elseif ($account === 'skewed' && (!$skewed || abs((int)strtotime(Der::value($ts[0] ?? '')) - (time() + 3600)) > 300)) {
                    $skewed = true;                                     // (and again while its time is not this KDC's)
                    echo "as $cname skew\n";
                    $out = $error(37, '', time() + 3600);
                } else {
                    echo "as $cname ok\n";
                    $session = random_bytes($account === 'cortaclave' || $rc4Ses ? 16 : 32);
                    $tgt = KrbFixture::ticket(KrbFixture::key('krbtgt/' . KrbFixture::REALM), ['krbtgt', KrbFixture::REALM], $account, $session, time() + 36000, $rc4Ses ? 23 : 18);
                    $out = $reply(11, $account, $tgt, $key, 3, $session, $nonce, ['krbtgt', KrbFixture::REALM], $rc4
                        ? static fn(string $part): string => Der::seq(Der::ctx(0, Der::int(23)), Der::ctx(2, Der::octet($rc4hmac($key, 8, $part, true))))
                        : null, $rc4Ses ? 23 : 18);
                }
            }
        } else {                                                       // TGS-REQ: PA-TGS-REQ (1) with the AP-REQ
            $sname = $names($body[3]);
            $spn   = implode('/', $sname);
            $ap    = KrbFixture::fields($padata[1], 14);
            [$cname, $tgtKey, $tgtType] = KrbFixture::openTicket($ap[3], KrbFixture::key('krbtgt/' . KrbFixture::REALM));
            $auth  = KrbFixture::fields(KrbFixture::dec($tgtKey, 7, $ap[4]), 2);
            $ck    = Der::fields(Der::value($auth[3]));
            // the checksum over the request's body (HMAC-MD5, -138, with an RC4 session key)
            if (!hash_equals($tgtType === 23 ? KrbFixture::rc4Checksum($tgtKey, 6, $f[4]) : KC::checksum($tgtKey, 6, $f[4]), Der::value($ck[1]))
                || Der::toInt($ck[0]) !== ($tgtType === 23 ? -138 : 16)) {
                throw new RuntimeException('body checksum');
            }
            if ($spn === 'TERMSRV/pc.otro.local') {                    // another domain's: a ticket for its KDC (a referral)
                echo "tgs $spn referral\n";
                $session = random_bytes(32);
                $ticket  = KrbFixture::ticket(KrbFixture::key('krbtgt/OTRO.LOCAL'), ['krbtgt', 'OTRO.LOCAL'], $cname, $session, time() + 36000);
                $out     = $reply(13, $cname, $ticket, $tgtKey, 8, $session, $nonce, ['krbtgt', 'OTRO.LOCAL'],
                    $tgtType === 23 ? static fn(string $p): string => KrbFixture::enc($tgtKey, 8, $p, 0, 23) : null);
            } elseif (!in_array($spn, $services, true)) {
                echo "tgs $spn unknown\n";
                $out = $error(7);
            } else {
                echo "tgs $spn ok\n";
                // (an RC4 service, or a 2003 domain's user: its session key RC4, as such a KDC gives)
                $sType   = KrbFixture::etypeOf($spn) === 23 || $tgtType === 23 ? 23 : 18;
                $session = random_bytes($sType === 23 ? 16 : 32);
                $ticket  = KrbFixture::ticket(KrbFixture::key($spn), $sname, $cname, $session, time() + 36000, $sType);
                $out     = $reply(13, $cname, $ticket, $tgtKey, 8, $session, $nonce, $sname,
                    $tgtType === 23 ? static fn(string $p): string => KrbFixture::enc($tgtKey, 8, $p, 0, 23) : null, $sType);
            }
        }
    } catch (Throwable $e) {
        echo 'error ', $e->getMessage(), "\n";
        $out = $error(60);
    }
    fwrite($c, pack('N', strlen($out)) . $out);
    fclose($c);
}
