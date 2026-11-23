<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// A Remote Desktop Gateway for the tests (MS-TSGU, HTTP transport), written from the specification: TLS, the sign-in over
// HTTP (NTLM with the user's NTLMv2 proof and the channel binding of its certificate checked, computed here and not with the
// client's code; or Kerberos, tests/fixtures/krb_fixture.php), the upgrade to a WebSocket (or the older transport of two
// connections), then the handshake, tunnel, tunnel authorization and channel; the channel's bytes relayed to 127.0.0.1:<relay port>.
//   php rdg_server.php <user> <domain> <password> <openssl.cnf> <relay port>
// Prints its port, then one line per event: "auth ok <bindings ok|bindings bad>[ negotiate]", "auth ok kerberos <bindings
// ok|bindings bad>", "auth bad", "bad host", "quarantined", "policy", "refused", "tunnel refused", "channel <host>:<port>", "denied
// <host>", "keepalive answered" (the client's answer to its own), "keepalive" (one the client sent on its own), "close
// answered", "cookie ok", "bad cookie", "legacy in", "caps <n>", "consent sent", "tunnel auth", and last, for each connection
// signed in, "relayed <bytes up> <bytes down>".
// What it does differently, to test the client:
//   - the 401 of the first round has a chunked body;
//   - a Host header of a bare IPv6 address (no brackets) is refused (400, "bad host");
//   - Host "negotiate.*": only the Negotiate scheme is offered (the first round with NTLM gets a 401 without a challenge,
//     its schemes in one header: "Negotiate, Basic realm=...");
//   - Host "cookie.*": the first 401 sets a cookie, which the second round must bring back (else 401, "bad cookie");
//   - Host "legacy.*": a gateway without WebSockets (Windows Server 2012 R2): 200 to the signed-in RDG_OUT_DATA, 10 random
//     bytes, then its packets as a stream; the client's come on a second connection (RDG_IN_DATA, signed in too, with the
//     same RDG-Connection-Id) in HTTP chunks ("legacy in" once it is open);
//   - Host "legacyc.*": the same through a proxy that chunks the stream (its first random bytes: 100, in a chunk);
//   - Host "legacycert.*": the older transport, its second connection with another certificate (a man in the middle);
//   - Host "krbbad.*": a Kerberos ticket refused (401: the client signs in with NTLM on the same connection);
//   - Host "mfa.*": the tunnel answered after 9 s (a second factor approved meanwhile);
//   - Host "nocap.*" / "caps.*": the tunnel refused, E_PROXY_NAP_ACCESSDENIED / E_PROXY_CAPABILITYMISMATCH;
//   - Host "consent.*" or user "consent" (the same password): the tunnel response carries a consent message (after the
//     tunnel id and the capabilities), and after the channel a service message comes ("caps <n>", "consent sent", and
//     "tunnel auth" when the client goes on);
//   - "Authorization: Negotiate" with a Kerberos AP-REQ: checked with the key of HTTP/<host>, answered with its AP-REP;
//   - client name "POLICY": the tunnel authorization forbids the clipboard and the drives (with an idle timeout after);
//   - client name "QUARANTINE": the tunnel authorization answers E_PROXY_QUARANTINE_ACCESSDENIED (a tunnel all the same);
//   - client name "REFUSED": the tunnel authorization refused, E_PROXY_RAP_ACCESSDENIED ("refused");
//   - computer "denied.test": the channel refused; "bye.test": the gateway itself sends "adios" and closes the channel in
//     the same write; "slow.test": the channel answered after 9 s;
//   - a keep-alive sent right after the channel is opened.
declare(strict_types=1);

use MuDesk\Rdp\Ntlm;
use MuDesk\Text;

require __DIR__ . '/../../src/bootstrap.php';
require __DIR__ . '/krb_fixture.php';
ini_set('display_errors', 'stderr');

[, $user, $domain, $password, $cnf, $relay] = $argv;
$o    = ['config' => $cnf, 'private_key_bits' => 2048, 'digest_alg' => 'sha256'];
$key  = openssl_pkey_new($o);
$cert = openssl_csr_sign(openssl_csr_new(['commonName' => 'rdg-test'], $key, $o), null, $key, 1, $o);
openssl_x509_export($cert, $certPem);
openssl_pkey_export($key, $keyPem, null, $o);
$pem = (getenv('MUDESK_TEST_TMP') ?: sys_get_temp_dir()) . '/mudesk-rdg-' . bin2hex(random_bytes(4)) . '.pem';   // (the tests' folder: removed with it)
file_put_contents($pem, $certPem . $keyPem);
// RFC 5929 tls-server-end-point for this certificate (signed with SHA-256: hashed with it), in the GSS structure NTLM's
// MsvAvChannelBindings (and Kerberos' GSS checksum) carries the MD5 of: four zero fields, the application data's length, the data
$der      = (string)base64_decode((string)preg_replace('~-----[^-]+-----|\s+~', '', $certPem));
$app      = 'tls-server-end-point:' . hash('sha256', $der, true);
$bindings = md5(str_repeat("\0", 16) . pack('V', strlen($app)) . $app, true);
$srv = stream_socket_server('tcp://127.0.0.1:0', $en, $es, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
    stream_context_create(['ssl' => ['local_cert' => $pem, 'verify_peer' => false]]));
echo substr((string)strrchr((string)stream_socket_get_name($srv, false), ':'), 1), "\n";
$upper = new ReflectionMethod(Ntlm::class, 'upper');

$httpRequest = static function ($c): ?array {                // [request line, headers]
    $buf = '';
    while (!str_contains($buf, "\r\n\r\n")) {
        $d = @fread($c, 1);
        if ($d === false || $d === '') {
            return null;
        }
        $buf .= $d;
    }
    $lines = explode("\r\n", trim($buf));
    $h     = [];
    foreach (array_slice($lines, 1) as $l) {
        [$k, $v] = explode(':', $l, 2) + [1 => ''];
        $h[strtolower(trim($k))] = trim($v);
    }
    return [$lines[0], $h];
};
$send = static function ($c, string $data): void {          // all of it, also on a non-blocking TLS socket (retried as is)
    for ($end = microtime(true) + 10; $data !== '' && microtime(true) < $end;) {
        $n = @fwrite($c, $data);
        if ($n === false) {
            return;
        }
        if ($n === 0) {
            $r = $e = null;
            $w = [$c];
            @stream_select($r, $w, $e, 0, 50000);
            continue;
        }
        $data = substr($data, $n);
    }
};
$frame = static function (string $data): string {            // a server frame: binary, not masked
    $n = strlen($data);
    return "\x82" . ($n < 126 ? chr($n) : ($n < 65536 ? chr(126) . pack('n', $n) : chr(127) . pack('J', $n))) . $data;
};
$packet = static fn(int $t, string $body): string => pack('vvV', $t, 0, 8 + strlen($body)) . $body;
$token = static function (array $h, string $scheme): string {
    return preg_match('/^' . $scheme . '\s+(\S+)$/i', $h['authorization'] ?? '', $m) ? (string)base64_decode($m[1]) : '';
};

/**
 * The sign-in on connection $c, from its first request: Kerberos (one round) or NTLM (two). Returns [the last request, the
 * user, the final answer's extra headers], or null (refused: said, and answered 401).
 */
$signIn = static function ($c, array $req, string $hostHeader) use ($httpRequest, $send, $token, $user, $domain, $password, $bindings, $upper): ?array {
    $neg = $token($req[1], 'Negotiate');
    if ($neg !== '' && !str_contains($neg, "NTLMSSP\0")) {         // Kerberos in SPNEGO
        try {
            if (str_starts_with($hostHeader, 'krbbad.')) {
                throw new RuntimeException('its ticket refused');
            }
            [$who, $bindOk, , , , $reply, , $hint] = KrbFixture::accept($neg, 'HTTP/' . preg_replace('~:\d+$~', '', $hostHeader), $bindings);
            echo 'auth ok kerberos ', $bindOk ? 'bindings ok' : 'bindings bad', $hint ? ' cbt' : '', "\n";
            return [$req, $who, 'WWW-Authenticate: Negotiate ' . base64_encode($reply) . "\r\n"];
        } catch (Throwable) {                                     // its ticket refused: NTLM, on the same connection
            echo "auth bad kerberos\n";
            $send($c, "HTTP/1.1 401 Unauthorized\r\nWWW-Authenticate: Negotiate\r\nWWW-Authenticate: NTLM\r\nContent-Length: 0\r\n\r\n");
            $req = $httpRequest($c);
            if ($req === null) {
                return null;
            }
        }
    }
    $scheme = 'NTLM';
    if (str_starts_with($hostHeader, 'negotiate.')) {             // only Negotiate: a 401 without a challenge to NTLM
        $scheme = 'Negotiate';
        if ($token($req[1], 'Negotiate') === '') {
            $send($c, "HTTP/1.1 401 Unauthorized\r\nWWW-Authenticate: Negotiate, Basic realm=\"rdg\"\r\nContent-Length: 0\r\n\r\n");
            $req = $httpRequest($c);
            if ($req === null || $token($req[1], 'Negotiate') === '') {
                echo "bad request\n";
                return null;
            }
        }
    }
    // NEGOTIATE → 401 with a CHALLENGE (with the time: NTLMv2 with a MIC and the channel binding), in a chunked body
    $challenge = random_bytes(8);
    $info      = pack('vv', 2, strlen(Text::toUtf16($domain))) . Text::toUtf16($domain) . pack('vv', 1, 6) . Text::toUtf16('RDG')
               . pack('vv', 7, 8) . pack('P', (time() + 11644473600) * 10000000) . "\0\0\0\0";
    $target    = Text::toUtf16($domain);
    $msg       = "NTLMSSP\0" . pack('V', 2) . pack('vvV', strlen($target), strlen($target), 56) . pack('V', 0xE2898235) . $challenge
               . str_repeat("\0", 8) . pack('vvV', strlen($info), strlen($info), 56 + strlen($target)) . "\x0a\x00\x63\x45\x00\x00\x00\x0f" . $target . $info;
    $offer     = $scheme === 'NTLM' ? "WWW-Authenticate: Negotiate\r\nWWW-Authenticate: NTLM " . base64_encode($msg) : 'WWW-Authenticate: Negotiate ' . base64_encode($msg);
    $cookie = str_starts_with($hostHeader, 'cookie.') ? "Set-Cookie: RDGAFF=\"n1-x\"; Path=/; Secure; HttpOnly\r\nSet-Cookie: bad cookie\r\n" : '';
    $send($c, "HTTP/1.1 401 Unauthorized\r\n$offer\r\n{$cookie}Transfer-Encoding: chunked\r\n\r\n6\r\nDenied\r\n3;x=y\r\n...\r\n0\r\n\r\n");
    // AUTHENTICATE: the NTLMv2 proof checked with the password, and the channel binding with this certificate
    $req = $httpRequest($c);
    if ($cookie !== '') {                                          // (the cookie it set, back)
        if (($req[1]['cookie'] ?? '') !== 'RDGAFF="n1-x"') {
            echo "bad cookie\n";
            $send($c, "HTTP/1.1 401 Unauthorized\r\nContent-Length: 0\r\n\r\n");
            return null;
        }
        echo "cookie ok\n";
    }
    $auth  = $req !== null ? $token($req[1], $scheme) : '';
    $field = static fn(int $off): string => strlen($auth) >= $off + 8 ? substr($auth, unpack('V', $auth, $off + 4)[1], unpack('v', $auth, $off)[1]) : '';
    $nt    = $field(20);
    $who   = Text::fromUtf16($field(36));
    $ntowf = hash_hmac('md5', $upper->invoke(null, Text::toUtf16($who)) . $field(28), hash('md4', Text::toUtf16($password), true), true);   // (its domain as the client gave it)
    if (strlen($nt) < 48 || !hash_equals(hash_hmac('md5', $challenge . substr($nt, 16), $ntowf, true), substr($nt, 0, 16))
        || (strcasecmp($who, $user) !== 0 && strcasecmp($who, 'consent') !== 0)) {
        echo "auth bad\n";
        $send($c, "HTTP/1.1 401 Unauthorized\r\nContent-Length: 0\r\n\r\n");
        return null;
    }
    $cbt = null;
    for ($p = 44; $p + 4 <= strlen($nt);) {                  // the AV pairs of the NTLMv2 blob: 10 = channel bindings
        ['id' => $id, 'n' => $n] = unpack('vid/vn', $nt, $p);
        if ($id === 0) {
            break;
        }
        $id === 10 && $cbt = substr($nt, $p + 4, $n);
        $p += 4 + $n;
    }
    echo 'auth ok ', $cbt === $bindings ? 'bindings ok' : 'bindings bad', $scheme === 'Negotiate' ? ' negotiate' : '', "\n";
    return [$req, strtolower($who), ''];
};

while ($c = @stream_socket_accept($srv, 60)) {
    stream_set_timeout($c, 5);
    if (!@stream_socket_enable_crypto($c, true, STREAM_CRYPTO_METHOD_TLS_SERVER)) {
        echo "tls\n";
        fclose($c);
        continue;
    }
    $req = $httpRequest($c);
    if ($req === null || !str_starts_with($req[0], 'RDG_OUT_DATA /remoteDesktopGateway/ ') || strtolower($req[1]['upgrade'] ?? '') !== 'websocket') {
        echo "bad request\n";
        fclose($c);
        continue;
    }
    $hostHeader = $req[1]['host'] ?? '';
    if (substr_count(preg_replace('~^\[[^\]]*\]~', '', $hostHeader), ':') > 1) {   // a bare IPv6 address: not a valid Host
        echo "bad host\n";
        $send($c, "HTTP/1.1 400 Bad Request\r\nContent-Length: 0\r\n\r\n");
        fclose($c);
        continue;
    }
    $signed = $signIn($c, $req, $hostHeader);
    if ($signed === null) {
        fclose($c);
        continue;
    }
    [$req, $who, $extra] = $signed;
    $consent = str_starts_with($hostHeader, 'consent.') || $who === 'consent';
    $legacy  = str_starts_with($hostHeader, 'legacy');
    $chunked = str_starts_with($hostHeader, 'legacyc.');          // (a proxy that chunks the stream, a seed of 100 bytes)
    $chunk   = static fn(string $d): string => sprintf("%X\r\n", strlen($d)) . $d . "\r\n";
    $i = null;
    if ($legacy) {                                                // no WebSockets: the stream, then the IN channel
        $send($c, $chunked ? "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n{$extra}\r\n" . $chunk(str_repeat("\xAB\xCD", 50))
            : "HTTP/1.1 200 OK\r\n{$extra}\r\n" . str_repeat("\xAB\xCD", 5));
        $i = @stream_socket_accept($srv, 10);
        $other = $i && str_starts_with($hostHeader, 'legacycert.');
        if ($other) {                                             // (another certificate on this connection only)
            $o2 = ['config' => $cnf, 'private_key_bits' => 2048, 'digest_alg' => 'sha256'];
            $k2 = openssl_pkey_new($o2);
            openssl_x509_export(openssl_csr_sign(openssl_csr_new(['commonName' => 'rdg-test'], $k2, $o2), null, $k2, 1, $o2), $otherPem);
            openssl_pkey_export($k2, $otherKey, null, $o2);
            file_put_contents($pem2 = $pem . '-2.pem', $otherPem . $otherKey);
            stream_context_set_option($i, 'ssl', 'local_cert', $pem2);
        }
        $inReq = $i && @stream_socket_enable_crypto($i, true, STREAM_CRYPTO_METHOD_TLS_SERVER) ? $httpRequest($i) : null;
        if ($other) {
            stream_context_set_option($i, 'ssl', 'local_cert', $pem);
            @unlink($pem2);
        }
        $inOk  = $inReq !== null && str_starts_with($inReq[0], 'RDG_IN_DATA /remoteDesktopGateway/ ') && !isset($inReq[1]['upgrade'])
            && ($inReq[1]['rdg-connection-id'] ?? '') === ($req[1]['rdg-connection-id'] ?? '-') && ($in2 = $signIn($i, $inReq, $hostHeader)) !== null;
        if ($inOk) {
            stream_set_timeout($i, 5);
            $send($i, "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n");
            $inReq2 = $httpRequest($i);                           // the request that carries the client's packets, unanswered
            $inOk = $inReq2 !== null && stripos($inReq2[1]['transfer-encoding'] ?? '', 'chunked') !== false && !isset($inReq2[1]['authorization']);
        }
        if (!$inOk) {
            echo "bad in\n";
            $i && fclose($i);
            fclose($c);
            continue;
        }
        echo "legacy in\n";
    } else {
        $accept = base64_encode(sha1(($req[1]['sec-websocket-key'] ?? '') . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        $send($c, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: $accept\r\n$extra\r\n");
    }
    $out = static fn(string $pkt) => $send($c, $legacy ? ($chunked ? $chunk($pkt) : $pkt) : $frame($pkt));   // frames, or the stream
    $src = $legacy ? $i : $c;                                     // the client's: frames, or chunks of the IN channel
    // the gateway's messages; the channel relayed to the "computer"
    stream_set_blocking($src, false);
    $in = $pkts = '';
    $to = null;
    $up = $down = $keepalives = 0;
    $end = microtime(true) + 30;
    while (microtime(true) < $end) {
        $r = array_values(array_filter([$src, $to]));
        $w = $e = null;
        if (@stream_select($r, $w, $e, 1) === false) {
            break;
        }
        $d = @fread($src, 65536);
        if (($d === '' || $d === false) && feof($src)) {
            break;
        }
        $in .= (string)$d;
        if ($legacy) {
            while (($nl = strpos($in, "\r\n")) !== false) {           // whole chunks: [hex size][CRLF][data][CRLF]
                $n = (int)hexdec(substr($in, 0, $nl));
                if (strlen($in) < $nl + 2 + $n + 2) {
                    break;
                }
                $pkts .= substr($in, $nl + 2, $n);
                $in    = substr($in, $nl + 2 + $n + 2);
                if ($n === 0) {
                    break 2;
                }
            }
        }
        while (!$legacy && strlen($in) >= 6) {                  // whole client frames (masked)
            $len = ord($in[1]) & 0x7F;
            $p   = 2;
            if ($len === 126) {
                $len = unpack('n', $in, 2)[1];
                $p   = 4;
            } elseif ($len === 127) {
                $len = unpack('J', $in, 2)[1];
                $p   = 10;
            }
            if (strlen($in) < $p + 4 + $len) {
                break;
            }
            $op    = ord($in[0]) & 0x0F;
            $mask  = substr($in, $p, 4);
            $data  = substr(substr($in, $p + 4, $len) ^ str_repeat($mask, intdiv($len, 4) + 1), 0, $len);
            $in    = substr($in, $p + 4 + $len);
            if ($op === 0x8) {                                // the client's close frame: the end
                break 2;
            }
            $pkts .= $data;
        }
        while (strlen($pkts) >= 8 && strlen($pkts) >= unpack('V', $pkts, 4)[1]) {
            ['t' => $t, 'n' => $n] = unpack('vt/x2/Vn', $pkts);
            $body = substr($pkts, 8, $n - 8);
            $pkts = substr($pkts, $n);
            if ($t === 0x01) {                                // handshake
                $out($packet(0x02, pack('VCCvv', 0, 1, 0, 0, 0)));
            } elseif ($t === 0x04) {                          // tunnel: its id (and a consent message to accept)
                $refused = ['nocap' => 0x800759DB, 'caps' => 0x800759E9][explode('.', $hostHeader)[0]] ?? 0;
                if ($refused) {
                    echo "tunnel refused\n";
                    $out($packet(0x05, pack('vVvv', 0, $refused, 0, 0)));
                    break 2;
                }
                str_starts_with($hostHeader, 'mfa.') && sleep(9);
                if ($consent) {
                    echo 'caps ', unpack('V', $body)[1], "\n";
                    $text = Text::toUtf16("Uso solo autorizado.\nTodo queda registrado.") . "\0\0";
                    $out($packet(0x05, pack('vVvvVV', 0, 0, 0x13, 0, 7, 0x0C) . pack('v', strlen($text)) . $text));
                    echo "consent sent\n";
                } else {
                    $out($packet(0x05, pack('vVvvV', 0, 0, 0x1, 0, 7)));
                }
            } elseif ($t === 0x06) {                          // tunnel authorization (a quarantined client authorized all the same)
                $consent && print("tunnel auth\n");
                $name = Text::fromUtf16(substr($body, 4, max(0, unpack('v', $body, 2)[1] - 2)));
                $name === 'QUARANTINE' && print("quarantined\n");
                $name === 'POLICY' && print("policy\n");
                $name === 'REFUSED' && print("refused\n");
                $out($packet(0x07, $name === 'POLICY' ? pack('VvvVV', 0, 0x3, 0, 0x08 | 0x01, 30)   // redirection flags, idle timeout
                    : pack('Vvv', ['QUARANTINE' => 0x800759ED, 'REFUSED' => 0x800759DA][$name] ?? 0, 0, 0)));
            } elseif ($t === 0x08) {                          // channel: the computer's name and port
                $port = unpack('v', $body, 2)[1];
                $host = Text::fromUtf16(substr($body, 8, max(0, unpack('v', $body, 6)[1] - 2)));   // (its terminating null left out)
                if ($host === 'denied.test') {
                    echo "denied $host\n";
                    $out($packet(0x09, pack('Vvv', 0x800759DA, 0, 0)));
                    break 2;
                }
                echo "channel $host:$port\n";
                $host === 'slow.test' && sleep(9);
                $out($packet(0x09, pack('VvvV', 0, 0x1, 0, 1)));
                $out($packet(0x0D, ''));                      // (and a keep-alive)
                if ($consent) {                               // a service message, as the gateway sends them during the session
                    $text = Text::toUtf16('Mantenimiento a las 22:00') . "\0\0";
                    $out($packet(0x0B, pack('v', strlen($text)) . $text));
                }
                if ($host === 'bye.test') {                   // the last bytes and the channel's end, in one write
                    $out($packet(0x0A, pack('v', 5) . 'adios') . $packet(0x10, pack('V', 0)));
                    $down += 5;
                } elseif ($host !== 'slow.test') {
                    $to = @stream_socket_client("tcp://127.0.0.1:$relay", $en, $es, 5) ?: null;
                }
            } elseif ($t === 0x0A && $to) {                  // data: to the computer
                $data = substr($body, 2, unpack('v', $body)[1]);
                fwrite($to, $data);
                $up += strlen($data);
            } elseif ($t === 0x0D) {
                echo $keepalives++ === 0 ? "keepalive answered\n" : "keepalive\n";
            } elseif ($t === 0x10) {                          // the client closes the channel: answered
                $out($packet(0x11, pack('V', 0)));
                if ($legacy) {
                    break 2;
                }
            } elseif ($t === 0x11) {                          // the channel's close answered: the end
                echo "close answered\n";
                break 2;
            }
        }
        if ($to) {                                            // the computer's bytes: back in data packets
            stream_set_blocking($to, false);
            $d = @fread($to, 16384);
            if ($d !== false && $d !== '') {
                $out($packet(0x0A, pack('v', strlen($d)) . $d));
                $down += strlen($d);
            } elseif (feof($to)) {
                break;
            }
        }
    }
    echo "relayed $up $down\n";
    $to && fclose($to);
    $i && fclose($i);
    fclose($c);
}
@unlink($pem);
