<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// A connection broker for the tests, and the computer it sends the client to, written from MS-RDPBCGR: the X.224 negotiation
// (TLS), the MCS connection (its response, the user attached, the channels joined) and the Client Info PDU; then the first
// connection gets an Enhanced Security Server Redirection PDU (2.2.13.3.1) with a session, a user, a password, a target address
// and the target's addresses (127.0.0.3, where nothing answers, then 127.0.0.2) and the target's certificate, and the second
// one is the computer it sent the client to.
//   php broker_server.php <openssl.cnf> [wrongcert|rdstls|rdstlsbad]   (wrongcert: the certificate given for the target is
//   another one; rdstls: the password encrypted for the target (LB_PASSWORD_IS_PK_ENCRYPTED) with a redirection GUID, the
//   target asking for RDSTLS and checking them, "rdstls requested=<protocols> guid=<ok|bad> user=<user> password=<ok|bad>";
//   rdstlsbad: the target refuses them, RDSTLS_RESULT_LOGON_FAILURE; late: the broker activates the session first (licensing,
//   Demand Active, the client's answers: "activated") and redirects it after, as when a session host finds the user's session
//   elsewhere after the logon; rejoin: the target activates it and keeps it until the client connects again (a guest joining a
//   shared session), which must come to the target, not the broker: three connections; loop: every connection a broker's,
//   sending the client on, at most 6 connections; lateloop: the same once each session is activated; fqdn: the computer's
//   name given too, TargetFQDN rdsh2.empresa.test; count: the broker alone, then "sent <bytes>", all it wrote to the client)
// Listens on 127.0.0.1 and 127.0.0.2 (the same port), prints the port, then "redirect sent" for the first connection and
// "target <X.224 cookie> cluster=<flags> session=<id> user=<user> password=<password>" for the second (or "target gone" if it
// left before its Client Info), and last "done".
declare(strict_types=1);

use MuDesk\Text;

require __DIR__ . '/../../src/bootstrap.php';
ini_set('display_errors', 'stderr');

$cnf  = $argv[1];
$mode = $argv[2] ?? '';
$rdstls = str_starts_with($mode, 'rdstls');
$loop   = str_ends_with($mode, 'loop');
$guid = Text::toUtf16('{8F2D3A10-7B11-4C6E-9D2F-5A6B7C8D9E0F}') . "\0\0";
$pk   = random_bytes(120);                                 // (the password encrypted for the target: opaque to the client)
$o    = ['config' => $cnf, 'private_key_bits' => 2048, 'digest_alg' => 'sha256'];
$key  = openssl_pkey_new($o);
$cert = openssl_csr_sign(openssl_csr_new(['commonName' => 'rdsh-test'], $key, $o), null, $key, 1, $o);
openssl_x509_export($cert, $certPem);
openssl_pkey_export($key, $keyPem, null, $o);
$pem = (getenv('MUDESK_TEST_TMP') ?: sys_get_temp_dir()) . '/mudesk-broker-' . bin2hex(random_bytes(4)) . '.pem';   // (the tests' folder: removed with it)
file_put_contents($pem, $certPem . $keyPem);
$der = (string)base64_decode((string)preg_replace('~-----[^-]+-----|\s+~', '', $certPem));
if ($mode === 'wrongcert') {
    $der = substr($der, 0, -1) . chr(ord($der[strlen($der) - 1]) ^ 1);
}
$ctx = static fn() => stream_context_create(['ssl' => ['local_cert' => $pem, 'verify_peer' => false]]);
$one = stream_socket_server('tcp://127.0.0.1:0', $en, $es, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx());
$port = (int)substr((string)strrchr((string)stream_socket_get_name($one, false), ':'), 1);
$two = stream_socket_server("tcp://127.0.0.2:$port", $en, $es, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx());
echo $port, "\n";

$tpkt = static fn(string $d): string => pack('CCn', 3, 0, 4 + strlen($d)) . $d;
$read = static function ($c): ?string {                    // one TPKT
    $h = (string)fread($c, 4);
    if (strlen($h) < 4 || ord($h[0]) !== 3) {
        return null;
    }
    $n = unpack('n', $h, 2)[1] - 4;
    $b = '';
    while (strlen($b) < $n && ($d = fread($c, $n - strlen($b))) !== false && $d !== '') {
        $b .= $d;
    }
    return $h . $b;
};
$u16  = static fn(string $s): string => Text::toUtf16($s) . "\0\0";
$per  = static fn(int $n): string => $n < 128 ? chr($n) : pack('n', 0x8000 | $n);
$sdin = static fn(string $data): string => $tpkt("\x02\xf0\x80\x68" . pack('nn', 1002, 1003) . "\x70" . $per(strlen($data)) . $data);
// (what is written to a connection counted, as the client counts what it reads)
$sent = 0;
$put  = static function ($c, string $d) use (&$sent): void {
    $sent += strlen($d);
    fwrite($c, $d);
};
// the session activated: licensing done (STATUS_VALID_CLIENT), the Demand Active (16 bpp, 800 x 600), the client's Confirm
// Active, synchronize, two controls and font list read
$activate = static function ($c) use ($sdin, $read, $put): void {
    $put($c, $sdin(pack('vv', 0x0080, 0) . pack('CCv', 0xFF, 0x83, 16) . pack('VVvv', 7, 2, 4, 0)));
    $caps = pack('vv', 2, 28) . pack('vvvvvvvvvCCvv', 16, 1, 1, 1, 800, 600, 0, 1, 1, 0, 0, 1, 0);
    $da   = pack('VvvA4', 0x103EA, 4, strlen($caps) + 4, 'RDP') . pack('vv', 1, 0) . $caps . pack('V', 0);
    $put($c, $sdin(pack('vvv', strlen($da) + 6, 0x11, 1002) . $da));
    for ($j = 0; $j < 5; $j++) {
        $read($c);
    }
};

$targets = 0;
for ($n = 0, $max = $mode === 'rejoin' ? 3 : ($loop ? 6 : ($mode === 'count' ? 1 : 2)); $n < $max;) {
    $r = [$one, $two];
    $w = $e = null;
    if (!@stream_select($r, $w, $e, $loop ? 3 : 30)) {   // (a client that stopped following: the end)
        break;
    }
    $isBroker = array_values($r)[0] === $one || $loop;     // (127.0.0.1: the broker; 127.0.0.2: the computer it sends to)
    $c = @stream_socket_accept(array_values($r)[0], 5);   // (the listener ready, whichever it is: select keeps the keys)
    if ($c === false) {
        continue;
    }
    $n++;
    $sent = 0;
    $targets += $isBroker ? 0 : 1;
    stream_set_timeout($c, 10);
    $cr = $read($c);
    $cookie = $cr !== null && ($p = strpos($cr, "\r\n", 11)) !== false ? substr($cr, 11, $p - 11) : '';
    $asked  = $cr !== null && strlen($cr) >= 19 ? unpack('V', $cr, strlen($cr) - 4)[1] : 0;
    $useRdstls = $rdstls && !$isBroker && ($asked & 4);
    $put($c, $tpkt("\x0e\xd0\x00\x00\x12\x34\x00" . pack('CCvV', 2, 0, 8, $useRdstls ? 4 : 1)));   // TLS (or RDSTLS) selected
    if (!@stream_socket_enable_crypto($c, true, STREAM_CRYPTO_METHOD_TLS_SERVER)) {
        echo $isBroker ? "broker gone\n" : "target gone\n";
        fclose($c);
        continue;
    }
    if ($useRdstls) {                                          // RDSTLS: capabilities, the client's password credentials, the result
        $put($c, pack('v4', 1, 1, 1, 1));
        $req = (string)fread($c, 8192);
        $at  = 6;
        $take = static function () use ($req, &$at): string {
            $len = unpack('v', $req, $at)[1];
            $v   = substr($req, $at + 2, $len);
            $at += 2 + $len;
            return $v;
        };
        [$g, $u, $dm, $pw] = [$take(), $take(), $take(), $take()];
        printf("rdstls requested=%d guid=%s user=%s password=%s\n", $asked, $g === $guid ? 'ok' : 'bad', Text::fromUtf16(str_ends_with($u, "\0\0") ? substr($u, 0, -2) : $u) ?: '-',
            $pw === $pk ? 'ok' : 'bad');
        $put($c, pack('vvvV', 1, 4, 1, $mode === 'rdstlsbad' ? 0x52E : 0));
        if ($mode === 'rdstlsbad') {
            fclose($c);
            continue;
        }
    }
    $ci = (string)$read($c);                                   // MCS Connect Initial: the cluster block
    $at = strpos($ci, pack('vv', 0xC004, 12));
    [$flags, $session] = $at !== false ? array_values(unpack('V2', $ci, $at + 4)) : [0, 0];
    $blocks = pack('vvV', 0x0C01, 8, 0x00080004) . pack('vvVV', 0x0C02, 12, 0, 0) . pack('vvvv', 0x0C03, 8, 1003, 0);
    $put($c, $tpkt("\x02\xf0\x80\x7f\x66\x00McDn" . $per(strlen($blocks)) . $blocks));
    $read($c);                                                 // Erect Domain
    $read($c);                                                 // Attach User
    $put($c, $tpkt("\x02\xf0\x80\x2e\x00\x00\x06"));         // Attach User Confirm: user 6 (channel 1007)
    for ($j = 0; $j < 2; $j++) {                               // its own channel and the I/O channel joined
        $jr = (string)$read($c);
        $ch = strlen($jr) >= 12 ? unpack('n', $jr, 10)[1] : 0;  // (after the TPKT, X.224 and the user: the channel)
        $put($c, $tpkt("\x02\xf0\x80\x3e\x00\x00\x06" . pack('nn', $ch, $ch)));
    }
    $info = (string)$read($c);                                 // Client Info: after the MCS header and the security header
    $i    = 4 + 3 + 6 + (ord($info[13] ?? "\0") & 0x80 ? 2 : 1) + 4;
    if (strlen($info) < $i + 18) {
        echo $isBroker ? "broker gone\n" : "target gone\n";
        fclose($c);
        continue;
    }
    ['d' => $cd, 'u' => $cu, 'p' => $cp] = unpack('x8/vd/vu/vp', $info, $i);
    $user = Text::fromUtf16(substr($info, $i + 18 + $cd + 2, $cu));
    $pass = Text::fromUtf16(substr($info, $i + 18 + $cd + 2 + $cu + 2, $cp));
    if ($isBroker) {                                           // the broker: to the computer of the session
        if ($mode === 'late' || $mode === 'lateloop') {
            $activate($c);
            echo "activated\n";
        }
        $addrs  = pack('V', 2) . pack('V', strlen($u16('127.0.0.3'))) . $u16('127.0.0.3') . pack('V', strlen($u16('127.0.0.2'))) . $u16('127.0.0.2');
        $to     = $u16($loop ? '127.0.0.2' : '127.0.0.3');      // (a loop: straight to the computer that answers)
        $tcert  = $u16(base64_encode(pack('VVV', 1, 1, 4) . 'xxxx' . pack('VVV', 32, 1, strlen($der)) . $der));
        $fields = '';
        $named  = $mode === 'fqdn' ? [$u16('rdsh2.empresa.test')] : [];
        foreach ($rdstls ? [$to, $u16('ana'), $pk, $guid, $tcert, $addrs] : [$to, $u16('ana'), $u16('Galleta-1'), ...$named, $tcert, $addrs] as $f) {
            $fields .= pack('V', strlen($f)) . $f;
        }
        $body = "\0\0" . pack('vvVV', 0x0400, 12 + strlen($fields), 42, 0x1 | 0x4 | 0x10 | 0x10000 | 0x800 | ($rdstls ? 0x4000 | 0x8000 : 0)
            | ($named !== [] ? 0x100 : 0)) . $fields;
        $put($c, $sdin(pack('vvv', 6 + strlen($body), 0x1A, 1002) . $body));
        echo "redirect sent\n";
        while (($d = fread($c, 8192)) !== false && $d !== '') {   // until the client leaves
        }
        $mode === 'count' && print("sent $sent\n");
    } else {
        printf("target %s cluster=%x session=%d user=%s password=%s\n", $cookie, $flags, $session, $user, $pass);
        if ($mode === 'rejoin' && $targets === 1) {         // the session kept, until the client connects again
            $activate($c);
            while (($d = fread($c, 8192)) !== false && $d !== '') {
            }
        }
    }
    fclose($c);
}
echo "done\n";
@unlink($pem);
