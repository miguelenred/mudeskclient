<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);
// A Windows computer for the tests of WindowsLogin: the server side of RDP's NLA (X.224 negotiation, TLS, CredSSP v6 with
// NTLMv2) for one account, without a real Windows. It checks the NTLMv2 proof with the password it knows, answers the
// client's key binding, and says what came next: "creds" (the client delegated its credentials) or "none". A first token
// with Kerberos (SPNEGO, an AP-REQ for TERMSRV/pc.test.local of tests/fixtures/krb_fixture.php's realm, or for
// TERMSRV/viejo.test.local, a Windows Server 2003 domain's computer: RC4, RFC 4757's tokens) is taken as Windows
// does: its AP-REP sent with a mechListMIC, the client's mechListMIC checked, the bindings and the credentials sealed with
// GSS Wrap as Windows seals them (EC 16, RRC 28): "ok creds kerberos <user> <password ok|password bad>".
//   php nla_server.php user domain password openssl.cnf [x224|v3|tls10]   (prints the port, then one line per connection:
//   ok|bad, creds|none, or "tls" when the TLS handshake failed; with x224, first a line "x224 <hex>" with the X.224 request
//   it got; with v3, CredSSP version 3 as Windows 7 and Server 2008 R2 without updates speak it: the public key itself bound,
//   the server's with its first byte plus one; with tls10, only TLS 1.0 with AES128-SHA, as they offer TLS too, and a CredSSP
//   message split into TLS records answered as they answer it: the connection closed, "split")
use MuDesk\Rdp\{Der, KerberosCrypto, Ntlm, Rc4};
use MuDesk\Text;

require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/krb_fixture.php';

[, $user, $domain, $password, $cnf] = $argv;
$showX224 = ($argv[5] ?? '') === 'x224';
$v3       = ($argv[5] ?? '') === 'v3';
$tls10    = ($argv[5] ?? '') === 'tls10';
ini_set('display_errors', 'stderr');                    // (stdout: one line per connection, read by the test)
$o    = ['config' => $cnf, 'private_key_bits' => 2048, 'digest_alg' => 'sha256'];
$key  = openssl_pkey_new($o);
$cert = openssl_csr_sign(openssl_csr_new(['commonName' => 'nla-test'], $key, $o), null, $key, 1, $o);
openssl_x509_export($cert, $certPem);
openssl_pkey_export($key, $keyPem, null, $o);
$pem = (getenv('MUDESK_TEST_TMP') ?: sys_get_temp_dir()) . '/mudesk-nla-' . bin2hex(random_bytes(4)) . '.pem';   // (the tests' folder: removed with it)
file_put_contents($pem, $certPem . $keyPem);
$pubKey = Der::subjectPublicKey(openssl_x509_read($certPem));
$srv    = stream_socket_server('tcp://127.0.0.1:0', $en, $es, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
    stream_context_create(['ssl' => ['local_cert' => $pem, 'verify_peer' => false]
        + ($tls10 ? ['security_level' => 0, 'ciphers' => 'AES128-SHA:@SECLEVEL=0'] : [])]));
echo substr((string)strrchr((string)stream_socket_get_name($srv, false), ':'), 1), "\n";

$readDer = static function ($c) use ($tls10): string {
    // (each read up to what is asked for: a message can come in several TLS records)
    $read = static function (int $n) use ($c): string {
        for ($s = ''; strlen($s) < $n && ($d = @fread($c, $n - strlen($s))) !== false && $d !== '';) {
            $s .= $d;
        }
        return $s;
    };
    $h = $tls10 ? (string)@fread($c, 2) : '';               // (a read, one TLS record at most)
    if ($tls10 && strlen($h) === 1) {                        // the message's first record one byte (1/n-1): refused
        echo "split\n";                                      // (then closed, as any message missing)
        return '';
    }
    $h .= $read(2 - strlen($h));                             // (a connection cut during TLS warned on some versions)
    if (strlen($h) < 2) {
        return '';
    }
    $n = ord($h[1]);
    if ($n & 0x80) {
        $ext = $read($n & 0x7F);
        $h  .= $ext;
        $n   = (int)hexdec(bin2hex($ext));
    }
    $body = '';
    while (strlen($body) < $n && ($d = @fread($c, $n - strlen($body))) !== false && $d !== '') {
        $body .= $d;
    }
    return $h . $body;
};
$tsRequest = static fn(string ...$fields): string => Der::seq(Der::ctx(0, Der::int($v3 ? 3 : 6)), ...$fields);
$upper     = new ReflectionMethod(Ntlm::class, 'upper');

for ($last = time(); time() - $last < 60;) {                   // (on Windows an accept can fail on a connection reset meanwhile: the next one is waited for)
    if (($c = @stream_socket_accept($srv, 5)) === false) {
        continue;
    }
    $last = time();
    stream_set_timeout($c, 5);
    $cr = (string)fread($c, 512);                       // X.224 Connection Request: answered with NLA (PROTOCOL_HYBRID) selected
    if (strlen($cr) < 7) {
        fclose($c);
        continue;
    }
    $showX224 && print('x224 ' . bin2hex($cr) . "\n");
    $cc = "\x0e\xd0\x00\x00\x12\x34\x00" . pack('CCvV', 2, 0, 8, 2);
    fwrite($c, "\x03\x00" . pack('n', 4 + strlen($cc)) . $cc);
    stream_set_blocking($c, true);
    if (!@stream_socket_enable_crypto($c, true, $tls10 ? STREAM_CRYPTO_METHOD_TLSv1_0_SERVER : STREAM_CRYPTO_METHOD_TLS_SERVER)) {
        echo "tls\n";
        fclose($c);
        continue;
    }
    // NEGOTIATE: answered with a CHALLENGE (domain and computer names, the time: NTLMv2 with a MIC); none: only its certificate read
    $first = $readDer($c);
    if ($first === '') {
        echo "drop\n";
        fclose($c);
        continue;
    }
    $negoToken = static fn(string $tsReq): string => Der::value(Der::fields(Der::value(Der::value(Der::fields(Der::value($tsReq))[1])))[0]);
    try {
        $tok = $negoToken($first);
    } catch (Throwable) {                                       // (not a TSRequest: a client that gave up before NLA, its disconnect)
        echo "drop\n";
        fclose($c);
        continue;
    }
    if ($tok[0] === "\x60") {                                         // Kerberos in SPNEGO
        try {
            try {
                [$who, , $ctxKey, , $ourSeq, $reply, $mechTypes, , , $etype] = KrbFixture::accept($tok, 'TERMSRV/pc.test.local', null, true);
            } catch (Throwable) {                                       // (a 2003 domain's computer: RC4)
                [$who, , $ctxKey, , $ourSeq, $reply, $mechTypes, , , $etype] = KrbFixture::accept($tok, 'TERMSRV/viejo.test.local', null, true);
            }
            fwrite($c, $tsRequest(Der::ctx(1, Der::seq(Der::seq(Der::ctx(0, Der::octet($reply)))))));
            $req = $readDer($c);
            $f   = Der::fields(Der::value($req));
            // the client's mechListMIC (NegTokenResp [1] { [3] mechListMIC }), over its mechTypes, with the context's key
            $mic = isset($f[1]) ? Der::value(Der::fields(Der::value(Der::value($negoToken($req))))[3] ?? Der::octet('')) : '';
            if (!isset($f[3], $f[5]) || strlen($mic) < 28 || !($etype === 23 ? KrbFixture::rc4MicOk($ctxKey, $mic, $mechTypes)
                : hash_equals(KerberosCrypto::checksum($ctxKey, 25, $mechTypes . substr($mic, 0, 16)), substr($mic, 16)))) {
                throw new RuntimeException('no mechListMIC of the client');
            }
            $nonce = Der::value($f[5]);
            if (KrbFixture::unwrap($ctxKey, Der::value($f[3])) !== hash('sha256', "CredSSP Client-To-Server Binding Hash\0" . $nonce . $pubKey, true)) {
                throw new RuntimeException('binding');
            }
            $sBind = hash('sha256', "CredSSP Server-To-Client Binding Hash\0" . $nonce . $pubKey, true);
            fwrite($c, $tsRequest(Der::ctx(3, Der::octet($etype === 23 ? KrbFixture::rc4Wrap($ctxKey, $ourSeq, $sBind, true) : KrbFixture::wrap($ctxKey, $ourSeq, $sBind)))));
            $next = $readDer($c);
            if ($next === '' || !isset(($f = Der::fields(Der::value($next)))[2])) {
                echo "ok none kerberos $who\n";
            } else {
                $creds = Der::fields(Der::value(KrbFixture::unwrap($ctxKey, Der::value($f[2]))));
                $pw    = Der::fields(Der::value(Der::value($creds[1])));
                echo "ok creds kerberos $who ", Text::fromUtf16(Der::value($pw[2])) === $password ? 'password ok' : 'password bad', "\n";
            }
        } catch (Throwable $e) {
            echo 'bad kerberos ', $e->getMessage(), "\n";
            fwrite($c, $tsRequest(Der::ctx(4, Der::int(0xC000006D))));
        }
        fclose($c);
        continue;
    }
    $challenge = random_bytes(8);
    $info      = pack('vv', 2, strlen(Text::toUtf16($domain))) . Text::toUtf16($domain) . pack('vv', 1, 4) . Text::toUtf16('PC')
               . pack('vv', 7, 8) . pack('P', (time() + 11644473600) * 10000000) . "\0\0\0\0";
    $target    = Text::toUtf16($domain);
    $msg       = "NTLMSSP\0" . pack('V', 2) . pack('vvV', strlen($target), strlen($target), 56) . pack('V', 0xE2898235) . $challenge
               . str_repeat("\0", 8) . pack('vvV', strlen($info), strlen($info), 56 + strlen($target)) . "\x0a\x00\x63\x45\x00\x00\x00\x0f" . $target . $info;
    fwrite($c, $tsRequest(Der::ctx(1, Der::seq(Der::seq(Der::ctx(0, Der::octet($msg)))))));
    // AUTHENTICATE with the key binding: the NTLMv2 proof checked with the password; right, the binding answered. None (the
    // client gave up after the CHALLENGE, an old CredSSP refused: nothing more, or its disconnection): "drop"
    $second = $readDer($c);
    try {
        $f = Der::fields(Der::value($second));
    } catch (Throwable) {
        $f = [];
    }
    if (!isset($f[1], $f[3])) {
        echo "drop\n";
        fclose($c);
        continue;
    }
    $auth  = Der::value(Der::fields(Der::value(Der::value($f[1])))[0]);
    $field = static fn(int $off): string => substr($auth, unpack('V', $auth, $off + 4)[1], unpack('v', $auth, $off)[1]);
    $nt    = $field(20);
    $ntowf = hash_hmac('md5', $upper->invoke(null, Text::toUtf16($user)) . Text::toUtf16($domain), hash('md4', Text::toUtf16($password), true), true);
    $proof = substr($nt, 0, 16);
    if (!hash_equals(hash_hmac('md5', $challenge . substr($nt, 16), $ntowf, true), $proof)
        || strcasecmp(Text::fromUtf16($field(36)), $user) !== 0) {
        fwrite($c, $tsRequest(Der::ctx(4, Der::int(0xC000006D))));   // STATUS_LOGON_FAILURE
        echo "bad none\n";
        fclose($c);
        continue;
    }
    $base     = hash_hmac('md5', $proof, $ntowf, true);
    $exported = (new Rc4($base))->crypt($field(52));
    $server   = new Ntlm($user, $domain, $password);             // its sealing, with the server's side of the keys
    $set      = static function (string $name, mixed $v) use ($server): void {
        (new ReflectionProperty(Ntlm::class, $name))->setValue($server, $v);
    };
    $set('flags', 0xE2088235);
    $set('sendSign', md5($exported . "session key to server-to-client signing key magic constant\0", true));
    $set('recvSign', md5($exported . "session key to client-to-server signing key magic constant\0", true));
    $set('sendSeal', new Rc4(md5($exported . "session key to server-to-client sealing key magic constant\0", true)));
    $set('recvSeal', new Rc4(md5($exported . "session key to client-to-server sealing key magic constant\0", true)));
    $nonce  = isset($f[5]) ? Der::value($f[5]) : null;
    $client = $server->unseal(Der::value($f[3]));
    if ($v3 ? $nonce !== null || $client !== $pubKey : $client !== hash('sha256', "CredSSP Client-To-Server Binding Hash\0" . $nonce . $pubKey, true)) {
        echo "binding none\n";
        fclose($c);
        continue;
    }
    fwrite($c, $tsRequest(Der::ctx(3, Der::octet($server->seal($v3 ? chr((ord($pubKey[0]) + 1) & 0xFF) . substr($pubKey, 1)
        : hash('sha256', "CredSSP Server-To-Client Binding Hash\0" . $nonce . $pubKey, true))))));
    $next = $readDer($c);                                          // the credentials, or the client closing
    echo 'ok ', $next !== '' && isset(Der::fields(Der::value($next))[2]) ? 'creds' : 'none', "\n";
    fclose($c);
}
@unlink($pem);
