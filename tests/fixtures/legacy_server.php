<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);
// A server with Standard RDP Security for the tests (MS-RDPBCGR 5.3), as Windows XP and Server 2003 answer: the X.224
// answer without negotiation (xp), a refusal of TLS (refuse: RDP_NEG_FAILURE SSL_NOT_ALLOWED_BY_SERVER, then as xp), TLS
// chosen but no TLS the client shares (tlsfail: Server 2003's RC4 and triple DES, which OpenSSL 3 leaves out; then as xp)
// or PROTOCOL_RDP chosen (select0); its key in a proprietary certificate or an X.509 chain; the client random decrypted with
// its private key, the session keys made (the server's side of MuDesk\Rdp\RdpSecurity, whose key derivation run.php checks
// against values written from the specification on their own); the Client Info, licensing, the Demand Active, the client's
// Confirm Active, a fast-path update and the client's fast-path input, all encrypted as the method says (level 1: only what
// the client sends). licreq: a License Request asking for the client's licensing PDUs encrypted; licplain: one that does not
// ask for it. redirect: a Standard Security Server Redirection after the Client Info (redir0c00: sent with the flags 0x0C00,
// encrypted with a salted MAC, without SEC_ENCRYPT). none: encryption method and level 0 (noblock: no security data at
// all): nothing encrypted. plain, plainfast: the Demand Active, or the fast-path update, in the clear at a level that
// encrypts what the server sends. nocert: TLS refused as SSL_CERT_NOT_ON_SERVER (no certificate), then as xp. licnocert: a License Request without the server's certificate (its blob empty).
// joinother: as xp, each channel join confirmed for another channel than the one asked for.
// twice: as xp, then up to two more whole connections (the session's connection made again: a guest came, a recording starts).
// twicemic: as twice, the client's static channels given their ids and, on its dynamic channel, the microphone opened as
// Windows does when a program records (MS-RDPEAI: 44.1 kHz mono, 441 frames a packet); then what the client sends is read
// until it closes, and the microphone's audio counted.
//   php legacy_server.php <mode> <method 1|2|8|16> <level 1|2|3> <cert proprietary|x509> <openssl.cnf>
// Prints its port, then: "x224 <requested protocols|none>", "methods 0x..", "info <user> <password>", "license reply
// <encrypted|plain>", "confirm ok", "input <hex>", "mic <bytes of a packet of audio>" or "error <what>".
use MuDesk\Rdp\{Der, RdpSecurity, Reader};
use MuDesk\Text;

require __DIR__ . '/../../src/bootstrap.php';

[, $mode, $method, $level, $certType, $cnf] = $argv;
[$method, $level] = [(int)$method, (int)$level];
$twice = $mode === 'twice' || $mode === 'twicemic';
ini_set('display_errors', 'stderr');
$o   = ['config' => $cnf, 'private_key_bits' => 1024, 'digest_alg' => 'sha256'];
$key = openssl_pkey_new($o);
$rsa = openssl_pkey_get_details($key)['rsa'];
[$n, $e] = [ltrim($rsa['n'], "\0"), ltrim($rsa['e'], "\0")];
if ($certType === 'x509') {                                  // version 2: a chain of X.509 certificates, the server's last
    openssl_x509_export(openssl_csr_sign(openssl_csr_new(['commonName' => 'ts'], $key, $o), null, $key, 1, $o), $pem);
    $der  = base64_decode((string)preg_replace('~-----[^-]+-----|\s~', '', $pem));
    $cert = pack('VV', 0x80000002, 2) . pack('V', strlen($der)) . $der . pack('V', strlen($der)) . $der;
} else {                                                     // version 1: the proprietary certificate (RSA1 blob, little-endian)
    $mod  = strrev($n) . str_repeat("\0", 8);
    $blob = 'RSA1' . pack('VVV', strlen($mod), strlen($n) * 8, strlen($n) - 1) . strrev(str_pad($e, 4, "\0", STR_PAD_LEFT)) . $mod;
    $cert = pack('VVVvv', 1, 1, 1, 6, strlen($blob)) . $blob . pack('vv', 8, 72) . str_repeat("\0", 72);
}
$srv = stream_socket_server('tcp://127.0.0.1:0');
echo substr((string)strrchr((string)stream_socket_get_name($srv, false), ':'), 1), "\n";

$read = static function ($c, int $n): string {
    $b = '';
    while (strlen($b) < $n && ($d = fread($c, $n - strlen($b))) !== false && $d !== '') {
        $b .= $d;
    }
    if (strlen($b) < $n) {
        throw new RuntimeException('closed');
    }
    return $b;
};
// one PDU of the client: TPKT, or fast-path (its length in one or two bytes)
$pdu = static function ($c) use ($read): string {
    $h = $read($c, 2);
    if ($h[0] === "\x03") {
        $h .= $read($c, 2);
        return $h . $read($c, unpack('n', $h, 2)[1] - 4);
    }
    $len = ord($h[1]);
    if ($len & 0x80) {
        $h  .= $read($c, 1);
        $len = (($len & 0x7F) << 8) | ord($h[2]);
    }
    return $h . $read($c, $len - strlen($h));
};
$tpkt   = static fn(string $x): string => "\x03\x00" . pack('n', strlen($x) + 4) . $x;
$perLen = static fn(int $n): string => $n < 0x80 ? chr($n) : pack('n', 0x8000 | $n);
// the data of an MCS Send Data Request
$sdrq = static function (string $p): array {
    $r = new Reader($p);
    $r->skip(7);
    if ($r->u8() !== 0x64) {
        throw new RuntimeException(sprintf('not a Send Data Request: %s', bin2hex(substr($p, 0, 12))));
    }
    $r->skip(2);
    $ch = $r->u16be();
    $r->skip(1);
    return [$ch, $r->bytes($r->perLength())];
};
$sdin = static fn(int $ch, string $d): string => $tpkt("\x02\xf0\x80\x68\x00\x01" . pack('n', $ch) . "\x70" . $perLen(strlen($d)) . $d);

for ($conn = 0; $conn < 3 && ($c = @stream_socket_accept($srv, 30)); $conn++) {   // (tlsfail: TLS 1.2, TLS 1.0, then without TLS)
    stream_set_timeout($c, $twice ? 60 : 10);              // (twice: the test acts in the session meanwhile, on a busy computer too)
    try {
        // X.224 Connection Request: its RDP_NEG_REQ, if any
        $cr  = $pdu($c);
        $neg = strlen($cr) >= 19 && $cr[-8] === "\x01" ? unpack('V', $cr, strlen($cr) - 4)[1] : null;
        echo 'x224 ', $neg ?? 'none', "\n";
        if (($mode === 'refuse' || $mode === 'nocert') && $neg !== null && $neg !== 0) {   // SSL_NOT_ALLOWED_BY_SERVER, SSL_CERT_NOT_ON_SERVER
            fwrite($c, $tpkt("\x0e\xd0\x00\x00\x12\x34\x00" . pack('CCvV', 3, 0, 8, $mode === 'nocert' ? 3 : 2)));
            fclose($c);
            continue;
        }
        if ($mode === 'tlsfail' && $neg !== null && $neg !== 0) {   // TLS chosen, its handshake refused (a TLS alert)
            fwrite($c, $tpkt("\x0e\xd0\x00\x00\x12\x34\x00" . pack('CCvV', 2, 0, 8, 1)));
            fread($c, 5);
            fwrite($c, "\x15\x03\x01\x00\x02\x02\x28");      // alert: handshake_failure
            fclose($c);
            continue;
        }
        fwrite($c, $tpkt($mode === 'select0' ? "\x0e\xd0\x00\x00\x12\x34\x00" . pack('CCvV', 2, 0, 8, 0) : "\x06\xd0\x00\x00\x12\x34\x00"));
        // MCS Connect Initial: the client's encryption methods; the answer with the server's choice, random and key
        $ci = $pdu($c);
        $p  = strpos($ci, "\x02\xc0\x0c\x00");
        echo 'methods ', $p === false ? 'none' : sprintf('0x%x', unpack('V', $ci, $p + 4)[1]), "\n";
        $serverRandom = random_bytes(32);
        $secData = ['none' => pack('vvVV', 0x0C02, 12, 0, 0), 'noblock' => ''][$mode]
            ?? pack('vvVVVV', 0x0C02, 20 + 32 + strlen($cert), $method, $level, 32, strlen($cert)) . $serverRandom . $cert;
        // twicemic: the client's static channels (its Client Network Data: u32 count, then 8-byte names and u32 options) given
        // ids from 1004 on, in its order; otherwise none
        $names = [];
        for ($q = 0; $mode === 'twicemic' && ($q = strpos($ci, "\x03\xc0", $q)) !== false; $q++) {
            $nc = strlen($ci) >= $q + 8 ? unpack('V', $ci, $q + 4)[1] : -1;
            if ($nc >= 0 && $nc <= 31 && unpack('v', $ci, $q + 2)[1] === 8 + 12 * $nc && strlen($ci) >= $q + 8 + 12 * $nc) {
                for ($i = 0; $i < $nc; $i++) {
                    $names[] = rtrim(substr($ci, $q + 8 + 12 * $i, 8), "\0");
                }
                break;
            }
        }
        $ids     = array_map(static fn(int $i): int => 1004 + $i, array_keys($names));
        $netIds  = pack('v*', ...$ids) . (count($ids) % 2 ? "\0\0" : '');
        $blocks  = pack('vvV', 0x0C01, 8, 0x00080004) . pack('vvvv', 0x0C03, 8 + strlen($netIds), 1003, count($ids)) . $netIds . $secData;
        fwrite($c, $tpkt("\x02\xf0\x80\x7f\x66\x00McDn" . $perLen(strlen($blocks)) . $blocks));
        $pdu($c);                                            // Erect Domain
        $pdu($c);                                            // Attach User
        fwrite($c, $tpkt("\x02\xf0\x80\x2e\x00\x00\x06"));  // its confirm: user 1007
        for ($i = 0; $i < 2 + count($ids); $i++) {           // the user's, the I/O channel's and the static channels' joins
            $j  = $pdu($c);
            if (strlen($j) < 12) {                           // (the client's disconnection: a confirm refused)
                throw new RuntimeException('not a join: ' . bin2hex($j));
            }
            $ch = unpack('n', $j, 10)[1];
            fwrite($c, $tpkt("\x02\xf0\x80\x3e\x00\x00\x06" . pack('nn', $ch, $mode === 'joinother' ? $ch + 1 : $ch)));
        }
        // Security Exchange PDU: the client random, decrypted with the private key
        [, $x] = $sdrq($pdu($c));
        if (unpack('v', $x)[1] !== RdpSecurity::SEC_EXCHANGE_PKT) {
            throw new RuntimeException('no security exchange');
        }
        $encRandom = substr($x, 8, unpack('V', $x, 4)[1] - 8);
        openssl_private_decrypt(strrev($encRandom), $plain, $key, OPENSSL_NO_PADDING);
        $clientRandom = substr(strrev((string)$plain), 0, 32);
        $sec = new RdpSecurity($method, $clientRandom, $serverRandom, true);
        $send = static function (int $flags, string $data) use ($c, $sec, $sdin, $level): void {     // (level 1: the server's in the clear)
            fwrite($c, $sdin(1003, $level === 1 ? pack('vv', $flags, 0) . $data : $sec->seal($flags, $data)));
        };
        // Client Info: its user and password
        [$flags, $info] = $sec->open($sdrq($pdu($c))[1]);
        if (!($flags & RdpSecurity::SEC_INFO_PKT) || !($flags & RdpSecurity::SEC_ENCRYPT)) {
            throw new RuntimeException(sprintf('client info flags 0x%x', $flags));
        }
        ['d' => $cbD, 'u' => $cbU, 'p' => $cbP] = unpack('vd/vu/vp', $info, 8);
        $u16 = static fn(int $at, int $len): string => Text::fromUtf16(substr($info, $at, $len));
        echo 'info ', $u16(18 + $cbD + 2, $cbU), ' ', $u16(18 + $cbD + 2 + $cbU + 2, $cbP), "\n";
        if ($mode === 'redirect' || $mode === 'redir0c00') {   // Standard Security Server Redirection: to another address
            $addr = Text::toUtf16('10.9.8.7') . "\0\0";
            $red  = pack('vvVV', 0x0400, 12 + 4 + strlen($addr), 77, 0x1) . pack('V', strlen($addr)) . $addr;
            if ($mode === 'redir0c00') {
                [$sig, $enc] = $sec->encrypt($red, true);
                fwrite($c, $sdin(1003, pack('vv', RdpSecurity::SEC_REDIRECTION_PKT | RdpSecurity::SEC_SECURE_CHECKSUM, 0) . $sig . $enc));
            } else {
                $send(RdpSecurity::SEC_REDIRECTION_PKT, $red);
            }
            fread($c, 1);
            fclose($c);
            continue;
        }
        if ($mode === 'licreq' || $mode === 'licplain' || $mode === 'licnocert') {   // a License Request, the client's answer asked encrypted (or not)
            $lcert = $mode === 'licnocert' ? '' : $cert;
            $req = random_bytes(32) . pack('V', 0x00060000) . pack('V', 4) . "M\0S\0" . pack('V', 4) . "A\0\0\0"
                 . pack('vv', 0x000D, 4) . pack('V', 1) . pack('vv', 0x0003, strlen($lcert)) . $lcert . pack('V', 0);
            $send(RdpSecurity::SEC_LICENSE_PKT | ($mode === 'licreq' ? RdpSecurity::SEC_LICENSE_ENCRYPT : 0), pack('CCv', 0x01, 0x83, strlen($req) + 4) . $req);
            [, $reply] = $sdrq($pdu($c));
            $rf = unpack('v', $reply)[1];
            echo 'license reply ', $rf & RdpSecurity::SEC_ENCRYPT ? 'encrypted' : 'plain', "\n";
            $sec->open($reply);
        }
        // licensing done (STATUS_VALID_CLIENT), the Demand Active (bitmap capability: 16 bpp, 800 x 600)
        $send(RdpSecurity::SEC_LICENSE_PKT, pack('CCv', 0xFF, 0x83, 16) . pack('VVvv', 7, 2, 4, 0));
        $caps = pack('vv', 2, 28) . pack('vvvvvvvvvCCvv', 16, 1, 1, 1, 800, 600, 0, 1, 1, 0, 0, 1, 0);
        $da   = pack('VvvA4', 0x103EA, 4, strlen($caps) + 4, 'RDP') . pack('vv', 1, 0) . $caps . pack('V', 0);
        if ($mode === 'plain') {
            fwrite($c, $sdin(1003, pack('vv', 0, 0) . pack('vvv', strlen($da) + 6, 0x11, 1002) . $da));
        } else {
            $send(0, pack('vvv', strlen($da) + 6, 0x11, 1002) . $da);
        }
        // the client's Confirm Active (share control type 3), synchronize, two controls and font list: all decrypted in order
        for ($i = 0, $ok = true; $i < 5; $i++) {
            [$f, $d] = $sec->open($sdrq($pdu($c))[1]);
            $ok = $ok && ($f & RdpSecurity::SEC_ENCRYPT) && ($i > 0 || (unpack('v', $d, 2)[1] & 0x0F) === 3);
        }
        echo $ok ? "confirm ok\n" : "confirm bad\n";
        // a fast-path update (pointer hidden: code 5, no data), encrypted unless level 1
        $upd = "\x05\x00\x00";
        if ($level === 1 || $mode === 'plainfast') {
            fwrite($c, "\x00" . chr(2 + strlen($upd)) . $upd);
        } else {
            [$s, $enc] = $sec->fastSeal($upd);
            fwrite($c, "\x80" . chr(2 + strlen($s) + strlen($enc)) . $s . $enc);
        }
        $dvcId = array_search('drdynvc', $names, true);
        if ($dvcId !== false) {
            // the microphone, as Windows opens it when a program records: the AUDIO_INPUT dynamic channel (id 7), the version,
            // the formats (16-bit PCM, 44.1 kHz mono) and the open (441 frames a packet: 882 bytes)
            $dvcId = $ids[$dvcId];
            $wave  = pack('vvVVvvv', 1, 1, 44100, 88200, 2, 16, 0);
            foreach (["\x10\x07AUDIO_INPUT\0", "\x30\x07" . pack('CV', 1, 1), "\x30\x07" . pack('CVV', 2, 1, 0) . $wave,
                "\x30\x07" . pack('CVV', 3, 441, 0) . $wave] as $dvc) {
                $chData = pack('VV', strlen($dvc), 3) . $dvc;    // (CHANNEL_FLAG_FIRST | CHANNEL_FLAG_LAST)
                fwrite($c, $sdin($dvcId, $level === 1 ? pack('vv', 0, 0) . $chData : $sec->seal(0, $chData)));
            }
            // what the client sends, decrypted in order until it closes: each packet of the microphone's audio (an Audin
            // Data PDU, 0x06, in a dynamic channel Data PDU) counted
            for (;;) {
                try {
                    $in = $pdu($c);
                } catch (RuntimeException) {
                    break;
                }
                if ($in[0] === "\x03") {
                    [$ch, $d] = $sdrq($in);
                    [, $d]    = $sec->open($d);
                    if ($ch === $dvcId && strlen($d) > 11 && substr($d, 8, 3) === "\x30\x07\x06") {
                        echo 'mic ', strlen($d) - 11, "\n";
                    }
                } elseif (ord($in[0]) & 0x80) {
                    $sec->fastOpen(substr($in, ord($in[1]) & 0x80 ? 3 : 2), false);
                }
            }
            @fclose($c);
            if ($conn > 1) {
                break;
            }
            continue;
        }
        // the client's fast-path input, decrypted
        $in = $pdu($c);
        $hl = ord($in[1]) & 0x80 ? 3 : 2;
        echo 'input ', ord($in[0]) & 0x80 ? bin2hex($sec->fastOpen(substr($in, $hl), false)) : 'clear ' . bin2hex(substr($in, $hl)), ' events ', (ord($in[0]) >> 2) & 0x0F, "\n";
        fread($c, 1);
    } catch (Throwable $t) {
        echo 'error ', $t->getMessage(), "\n";
    }
    @fclose($c);
    if (!$twice || $conn > 1) {
        break;                                          // (a connection refused or failed TLS above went on to the next one)
    }
}
