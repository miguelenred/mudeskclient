<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// A Remote Desktop Gateway of the RPC transport for the tests, as Windows Server 2008 R2 has it (MS-TSGU over MS-RPCH RPC
// over HTTP v2, MS-RPCE), written from the specifications: the HTTP transport refused (404), then two HTTPS connections
// signed in with NTLM (the NTLMv2 proof and the channel binding checked, computed here), the virtual connection (CONN/B1,
// A1; A3 and C2 sent), the bind with NTLM at packet integrity (every request's signature checked with keys derived here),
// TsProxyCreateTunnel, AuthorizeTunnel, MakeTunnelCall, CreateChannel, SetupReceivePipe, SendToServer, CloseChannel; the
// channel's bytes relayed to 127.0.0.1:<relay port>, the gateway's to the client in response PDUs of the pipe, with flow
// control (no more than the client's window unacknowledged: a client that never acknowledges stalls). Several clients at
// once, in one loop.
//   php rpc_gateway.php <user> <domain> <password> <openssl.cnf> <relay port> [mode]
// Modes: consent (a consent message in the tunnel's answer, a service message after the channel), policy (the clipboard and
// drives forbidden), nap (the user refused by its policies; napfull: in a whole answer, the code at its end), rapdenied (the
// computer refused), recycle (the OUT channel made
// again after 1500 bytes, OUT_R2; recyclelate: after 32 KB, as the client's first acknowledgement waits), recyclecert
// (the same, the new OUT channel with another certificate: a man in the middle), old2008 (no messaging: Windows Server
// 2008), inrecycle (each IN channel takes 128 KB at most, the least the specification allows, whatever its Content-Length
// says: the client makes it again, IN_R2 as this gateway knows the connection; its balancer's cookie set and wanted back),
// inrecycle1 (the same with IN_R1, as another gateway of a farm would take the new IN channel; and the OUT channel made
// again after 32 KB), lateclose (the channel's close answered 200 ms later; "close answered" once it goes), closefull (the
// channel's close answered after 72 KB more of the computer's bytes, more than the client's window: only once it
// acknowledges them), closerecycle (the channel's close answered by asking for a new OUT channel, never otherwise). A request with bytes before its request line
// is refused (400, "bad request"), as IIS does; bytes past an IN channel's length too ("in overrun": IIS reads them as a new
// request), and that ends the connection.
// Prints its port, then one line per event: "probe 404", "auth ok <in|out> <bindings ok|bindings bad|no bindings>",
// "auth bad", "rpc auth ok", "bad signature", "tunnel caps <hex>", "authorize <name>", "message call", "channel <name>:<port>",
// "pipe", "ping", "recycled", "in recycled", "in recycle bad", "in lifetime bad", "in overrun", "in after its end",
// "close channel", "close answered", and when a client's channel ends "relayed <bytes up> <bytes down>".
declare(strict_types=1);

use MuDesk\Rdp\{Ntlm, Rc4};
use MuDesk\Text;

require __DIR__ . '/../../src/bootstrap.php';
ini_set('display_errors', 'stderr');

[, $user, $domain, $password, $cnf, $relay] = $argv;
$mode = $argv[6] ?? '';
$inRecycle = $mode === 'inrecycle' || $mode === 'inrecycle1';
$inMax     = $inRecycle ? 131072 : PHP_INT_MAX;               // what an IN channel takes
$affinity  = $inRecycle ? 'RdgAffinity=' . bin2hex(random_bytes(8)) : '';   // (a balancer's cookie: each request must bring it)
$o    = ['config' => $cnf, 'private_key_bits' => 2048, 'digest_alg' => 'sha256'];
$key  = openssl_pkey_new($o);
openssl_x509_export(openssl_csr_sign(openssl_csr_new(['commonName' => 'rdg2008-test'], $key, $o), null, $key, 1, $o), $certPem);
openssl_pkey_export($key, $keyPem, null, $o);
$pem = (getenv('MUDESK_TEST_TMP') ?: sys_get_temp_dir()) . '/mudesk-rpcgw-' . bin2hex(random_bytes(4)) . '.pem';   // (the tests' folder: removed with it)
file_put_contents($pem, $certPem . $keyPem);
$der      = (string)base64_decode((string)preg_replace('~-----[^-]+-----|\s+~', '', $certPem));
$app      = 'tls-server-end-point:' . hash('sha256', $der, true);
$bindings = md5(str_repeat("\0", 16) . pack('V', strlen($app)) . $app, true);
$srv = stream_socket_server('tcp://127.0.0.1:0', $en, $es, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
    stream_context_create(['ssl' => ['local_cert' => $pem, 'verify_peer' => false]]));
echo substr((string)strrchr((string)stream_socket_get_name($srv, false), ':'), 1), "\n";
$upper = new ReflectionMethod(Ntlm::class, 'upper');

// ------------------------------------------------------------------ NTLM, the server's side
$challengeMsg = static function () use ($domain): array {
    $ch     = random_bytes(8);
    $info   = pack('vv', 2, strlen(Text::toUtf16($domain))) . Text::toUtf16($domain) . pack('vv', 1, 6) . Text::toUtf16('RDG')
            . pack('vv', 7, 8) . pack('P', (time() + 11644473600) * 10000000) . "\0\0\0\0";
    $target = Text::toUtf16($domain);
    return ["NTLMSSP\0" . pack('V', 2) . pack('vvV', strlen($target), strlen($target), 56) . pack('V', 0xE2898235) . $ch . str_repeat("\0", 8)
        . pack('vvV', strlen($info), strlen($info), 56 + strlen($target)) . "\x0a\x00\x63\x45\x00\x00\x00\x0f" . $target . $info, $ch];
};
// an AUTHENTICATE checked: [user, exported session key, channel binding] or null
$verify = static function (string $auth, string $ch) use ($user, $password, $upper): ?array {
    $field = static fn(int $off): string => strlen($auth) >= $off + 8 ? substr($auth, unpack('V', $auth, $off + 4)[1], unpack('v', $auth, $off)[1]) : '';
    $nt    = $field(20);
    $who   = Text::fromUtf16($field(36));
    $ntowf = hash_hmac('md5', $upper->invoke(null, Text::toUtf16($who)) . $field(28), hash('md4', Text::toUtf16($password), true), true);
    if (strlen($nt) < 48 || !hash_equals(hash_hmac('md5', $ch . substr($nt, 16), $ntowf, true), substr($nt, 0, 16)) || strcasecmp($who, $user) !== 0) {
        return null;
    }
    $cbt = null;
    for ($p = 44; $p + 4 <= strlen($nt);) {
        ['id' => $id, 'n' => $n] = unpack('vid/vn', $nt, $p);
        if ($id === 0) {
            break;
        }
        $id === 10 && $cbt = substr($nt, $p + 4, $n);
        $p += 4 + $n;
    }
    $base = hash_hmac('md5', substr($nt, 0, 16), $ntowf, true);
    return [$who, (new Rc4($base))->crypt($field(52)), $cbt];
};
// the server's side of the session security: it signs with the server's keys, checks with the client's
$serverNtlm = static function (string $exported) use ($user, $domain, $password): Ntlm {
    $s   = new Ntlm($user, $domain, $password);
    $set = static function (string $name, mixed $v) use ($s): void {
        (new ReflectionProperty(Ntlm::class, $name))->setValue($s, $v);
    };
    $set('flags', 0xE2088235);
    $set('sendSign', md5($exported . "session key to server-to-client signing key magic constant\0", true));
    $set('recvSign', md5($exported . "session key to client-to-server signing key magic constant\0", true));
    $set('sendSeal', new Rc4(md5($exported . "session key to server-to-client sealing key magic constant\0", true)));
    $set('recvSeal', new Rc4(md5($exported . "session key to client-to-server sealing key magic constant\0", true)));
    return $s;
};

// ------------------------------------------------------------------ PDUs
$hdr  = static fn(int $type, int $flags, int $len, int $auth, int $call): string => pack('CCCCVvvV', 5, 0, $type, $flags, 0x10, $len, $auth, $call);
$rts  = static function (int $flags, array $cmds) use ($hdr): string {
    $b = pack('vv', $flags, count($cmds));
    foreach ($cmds as [$t, $v]) {
        $b .= pack('V', $t) . $v;
    }
    return $hdr(0x14, 0x03, 16 + strlen($b), 0, 0) . $b;
};
$uuid = static fn(string $id): string => strrev((string)hex2bin(substr($h = str_replace('-', '', $id), 0, 8))) . strrev((string)hex2bin(substr($h, 8, 4)))
    . strrev((string)hex2bin(substr($h, 12, 4))) . hex2bin(substr($h, 16));
// a response of call $call: its stub, padded to 16 bytes as Windows pads it, signed with the server's keys
$resp = static function (array &$vc, int $call, string $stub, int $flags = 0x03) use ($hdr): string {
    $pad  = (16 - strlen($stub) % 16) % 16;
    $data = $hdr(0x02, $flags, 24 + strlen($stub) + $pad + 8 + 16, 16, $call) . pack('VvCC', strlen($stub), 0, 0, 0) . $stub . str_repeat("\0", $pad)
          . pack('CCCCV', 0x0A, 5, $pad, 0, 0);
    return $data . $vc['srv']->sign($data);
};

/** @var array<int,array> $conns  socket id => [s, buf, w, kind, ch, vc, method] */
$conns = [];
/** @var array<string,array> $vcs  virtual connection cookie => state */
$vcs = [];
$queueOut = static function (array &$vc, string $pdu, bool $counted) use (&$conns): void {
    $vc['q'][] = [$pdu, $counted];
};
$text16 = static fn(string $t): array => [$w = Text::toUtf16($t) . "\0\0", intdiv(strlen($w), 2), str_repeat("\0", (4 - strlen($w) % 4) % 4)];

// a request of the client (signature checked), answered
$request = static function (array &$vc, string $p) use ($resp, $mode, $relay, $text16, $hdr): void {
    $len  = strlen($p);
    $call = unpack('V', $p, 12)[1];
    if (!$vc['srv']->verify(substr($p, 0, $len - 16), substr($p, -16))) {
        echo "bad signature\n";
        $vc['q'][] = [$hdr(0x03, 0x03, 32, 0, $call) . pack('VvCCVV', 0, 0, 0, 0, 0x1C00001F, 0), true];
        return;
    }
    $op   = unpack('v', $p, 22)[1];
    $pad  = ord($p[$len - 16 - 8 + 2]);
    $stub = substr($p, 24, $len - 24 - 16 - 8 - $pad);
    if ($op === 1) {                                     // TsProxyCreateTunnel
        $caps = strlen($stub) >= 48 ? unpack('V', $stub, 44)[1] : 0;
        $neg  = $caps & ($mode === 'old2008' ? 0 : 0x0E);
        echo sprintf("tunnel caps %x\n", $neg), $mode === 'old2008' ? '' : '';
        if ($mode === 'nap') {
            $vc['q'][] = [$resp($vc, $call, pack('VV', 0, 0x800759DB)), true];
            return;
        }
        $pid = $neg & 0x04 ? 0x4350 : 0x4552;
        $msg = $mode === 'consent' && $pid === 0x4350;
        $s   = pack('VVVV', 0x00020000, $pid, $pid, 0x00020004) . pack('VVV', 0, 0, 0) . random_bytes(16) . pack('V', 0x00020008);
        if ($pid === 0x4350) {                           // (the message slot always there, as Windows sends it: empty without one)
            $s .= pack('VVVVV', 1, 1, $msg ? 1 : 0, 1, 0x0002000C);
        }
        $s .= pack('vvV', 0x5452, 0x5643, 0x00020010) . pack('V', 1) . pack('vvvv', 1, 1, 0, 0) . pack('V', 1) . pack('VVV', 1, 1, $neg);
        if ($msg) {
            [$w, $n, $padW] = $text16("Uso solo autorizado.\nTodo queda registrado.");
            $s .= pack('VVVV', 1, 1, $n, 0x00020014) . pack('VVV', $n, 0, $n) . $w . $padW;
        } elseif ($pid === 0x4350) {
            $s .= pack('VVVV', 0, 0, 0, 0);
        }
        $vc['tunnel'] = pack('V', 0) . random_bytes(16);
        $vc['q'][] = [$resp($vc, $call, $s . $vc['tunnel'] . pack('VV', 3, $mode === 'napfull' ? 0x800759DB : 0)), true];
    } elseif ($op === 2) {                               // TsProxyAuthorizeTunnel: the device policy
        $n = unpack('V', $stub, 60)[1];
        echo 'authorize ', Text::fromUtf16(substr($stub, 64, max(0, 2 * $n - 2))), "\n";
        $pol = $mode === 'policy' ? [0, 0, 1, 0, 0, 0, 1, 0] : [1, 0, 0, 0, 0, 0, 0, 0];
        $vc['q'][] = [$resp($vc, $call, pack('VVVV', 0x00020000, 0x5052, 0x5052, 0x00020004) . pack('VVVV', 0x5152, 0, 0, 0) . pack('l8', ...$pol) . pack('V', 0)), true];
    } elseif ($op === 3) {                               // TsProxyMakeTunnelCall: left waiting
        $vc['msgCall'] = $call;
        echo "message call\n";
        if ($vc['pipe'] !== 0 && $mode === 'consent' && !$vc['msgSent']) {
            $vc['msgSent'] = true;
            [$w, $n, $padW] = $text16('Mantenimiento a las 22:00');
            $vc['q'][] = [$resp($vc, $call, pack('VVVV', 0x00020000, 0x4750, 0x4750, 0x00020004) . pack('VVVVV', 1, 2, 1, 2, 0x00020008)
                . pack('VVVV', 1, 0, $n, 0x0002000C) . pack('VVV', $n, 0, $n) . $w . $padW . pack('V', 0)), true];
        }
    } elseif ($op === 4) {                               // TsProxyCreateChannel: to the relay
        $port = unpack('v', $stub, 38)[1];
        $n    = unpack('V', $stub, 56)[1];
        echo 'channel ', Text::fromUtf16(substr($stub, 60, max(0, 2 * $n - 2))), ":$port\n";
        if ($mode === 'rapdenied') {
            $vc['q'][] = [$resp($vc, $call, str_repeat("\0", 20) . pack('VV', 0, 0x800759DA)), true];
            return;
        }
        $vc['relay'] = stream_socket_client("tcp://127.0.0.1:$relay", $en, $es, 5);
        stream_set_blocking($vc['relay'], false);
        $vc['channel'] = pack('V', 0) . random_bytes(16);
        $vc['q'][] = [$resp($vc, $call, $vc['channel'] . pack('VV', 7, 0)), true];
    } elseif ($op === 8) {                               // TsProxySetupReceivePipe
        $vc['pipe'] = $call;
        echo "pipe\n";
        if ($mode === 'consent' && $vc['msgCall'] !== 0 && !$vc['msgSent']) {   // (the service message, now that the channel is there)
            $vc['msgSent'] = true;
            [$w, $n, $padW] = $text16('Mantenimiento a las 22:00');
            $vc['q'][] = [$resp($vc, $vc['msgCall'], pack('VVVV', 0x00020000, 0x4750, 0x4750, 0x00020004) . pack('VVVVV', 1, 2, 1, 2, 0x00020008)
                . pack('VVVV', 1, 0, $n, 0x0002000C) . pack('VVV', $n, 0, $n) . $w . $padW . pack('V', 0)), true];
        }
    } elseif ($op === 9) {                               // TsProxySendToServer: big-endian lengths, the bytes to the relay
        ['t' => $total, 'n' => $num] = unpack('Nt/Nn', $stub, 20);
        $lens = array_values(unpack('N' . $num, $stub, 28));
        $at   = 28 + 4 * $num;
        foreach ($lens as $l) {
            $d = substr($stub, $at, $l);
            $at += $l;
            for ($w = 0; $w < strlen($d);) {
                $k = @fwrite($vc['relay'], substr($d, $w));
                $w += $k ?: 0;
                $k || usleep(1000);
            }
            $vc['up'] += strlen($d);
        }
        $vc['q'][] = [$resp($vc, $call, pack('V', 0)), true];
    } elseif ($op === 6) {                               // TsProxyCloseChannel: the pipe's end, then its answer
        echo "close channel\n";
        $say    = $mode === 'lateclose' || $mode === 'closefull' ? 'close answered' : null;   // (said once it goes)
        $answer = [[$resp($vc, $vc['pipe'], pack('V', 0x4CA), 0x02), true, $say], [$resp($vc, $call, str_repeat("\0", 20) . pack('V', 0)), true]];
        if ($mode === 'lateclose') {                     // (later: whether the client waits for it, its channels still open)
            $vc['late'] = [microtime(true) + 0.2, $answer];
        } elseif ($mode === 'closerecycle') {            // (a new OUT channel wanted instead: none made while closing)
            $vc['askNew'] = true;
        } else {
            for ($i = 0; $mode === 'closefull' && $i < 18; $i++) {   // (the computer's last bytes first, past the window)
                $vc['q'][] = [$resp($vc, $vc['pipe'], str_repeat('x', 4000), 0x00), true];
            }
            array_push($vc['q'], ...$answer);
        }
        $vc['closing'] = true;
    } elseif ($op === 7) {
        $vc['q'][] = [$resp($vc, $call, str_repeat("\0", 20) . pack('V', 0)), true];
    }
};

$newVc = static fn(): array => ['in' => null, 'out' => null, 'succ' => null, 'outCookie' => '', 'ch' => '', 'srv' => null, 'q' => [], 'sent' => 0, 'acked' => 0,
    'total' => 0, 'relay' => null, 'pipe' => 0, 'msgCall' => 0, 'msgSent' => false, 'tunnel' => '', 'channel' => '', 'up' => 0, 'down' => 0, 'recycled' => 0,
    'closing' => false, 'ended' => false, 'stalled' => false, 'inCookie' => '', 'inSucc' => null, 'inSuccCookie' => '', 'late' => null, 'askNew' => false];
$commands = static function (string $p): array {
    $out = [];
    for ($o = 20, $i = 0, $n = unpack('v', $p, 18)[1]; $i < $n && $o + 4 <= strlen($p); $i++) {
        $t = unpack('V', $p, $o)[1];
        $s = [0 => 4, 1 => 24, 2 => 4, 3 => 16, 4 => 4, 5 => 4, 6 => 4, 7 => 0, 9 => 0, 10 => 0, 12 => 16, 13 => 4, 14 => 4][$t] ?? 0;
        $out[] = [$t, substr($p, $o + 4, $s)];
        $o += 4 + $s;
    }
    return $out;
};

$accepted = 0;                                           // (connections taken)
for ($idle = microtime(true); microtime(true) - $idle < 60;) {
    $r = [$srv];
    $w = [];
    foreach ($conns as $c) {
        $r[] = $c['s'];
        $c['w'] !== '' && $w[] = $c['s'];
    }
    foreach ($vcs as $vc) {
        is_resource($vc['relay']) && $r[] = $vc['relay'];
    }
    $e = null;
    if (@stream_select($r, $w, $e, 0, array_filter(array_column($vcs, 'late')) !== [] ? 20000 : 200000) === false) {
        break;
    }
    foreach ($r as $s) {
        if ($s === $srv) {
            $c = @stream_socket_accept($srv, 1);
            if ($c === false) {
                continue;
            }
            // (recyclecert: the fourth connection, after the probe and both channels, the new OUT channel: another certificate)
            $other = $mode === 'recyclecert' && ++$accepted === 4;
            if ($other) {
                $k2 = openssl_pkey_new($o);
                openssl_x509_export(openssl_csr_sign(openssl_csr_new(['commonName' => 'rdg2008-test'], $k2, $o), null, $k2, 1, $o), $otherPem);
                openssl_pkey_export($k2, $otherKey, null, $o);
                file_put_contents($pem2 = $pem . '-2.pem', $otherPem . $otherKey);
                stream_context_set_option($c, 'ssl', 'local_cert', $pem2);
            }
            stream_set_blocking($c, true);
            $tls = @stream_socket_enable_crypto($c, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
            if ($other) {
                stream_context_set_option($c, 'ssl', 'local_cert', $pem);
                @unlink($pem2);
            }
            if (!$tls) {
                fclose($c);
                continue;
            }
            stream_set_blocking($c, false);
            // (an IN channel: its length, what is left of it, plugged while it waits to be the default, its request's cookies)
            $conns[(int)$c] = ['s' => $c, 'buf' => '', 'w' => '', 'kind' => 'http', 'ch' => '', 'vc' => '', 'gone' => false, 'length' => 0, 'left' => PHP_INT_MAX,
                'plugged' => false, 'cookie' => ''];
            $idle = microtime(true);
            continue;
        }
        foreach ($vcs as $k => $vc) {                    // the computer's bytes: into the pipe, in chunks of at most 4000
            if ($s === $vc['relay']) {
                $d = (string)@fread($s, 65536);
                if ($d === '' && feof($s)) {
                    fclose($s);
                    $vcs[$k]['relay'] = null;
                    if ($vc['pipe'] !== 0 && !$vc['closing']) {
                        $vcs[$k]['q'][] = [$resp($vcs[$k], $vc['pipe'], pack('V', 0xA0), 0x02), true];
                    }
                    continue 2;
                }
                foreach (str_split($d, 4000) as $chunk) {
                    if ($chunk !== '') {
                        $vcs[$k]['q'][] = [$resp($vcs[$k], $vc['pipe'], $chunk, 0x00), true];
                        $vcs[$k]['down'] += strlen($chunk);
                    }
                }
                $idle = microtime(true);
                continue 2;
            }
        }
        $id = (int)$s;
        if (!isset($conns[$id])) {
            continue;
        }
        $d = @fread($s, 65536);
        if (($d === false || $d === '') && feof($s)) {
            $conns[$id]['gone'] = true;
            continue;
        }
        $conns[$id]['buf'] .= (string)$d;
        $idle = microtime(true);
    }

    foreach (array_keys($conns) as $id) {
        $c = &$conns[$id];
        for ($more = true; $more;) {
            $more = false;
            if ($c['kind'] === 'http') {
                if (($at = strpos($c['buf'], "\r\n\r\n")) === false) {
                    break;
                }
                $lines = explode("\r\n", substr($c['buf'], 0, $at));
                $c['buf'] = substr($c['buf'], $at + 4);
                $method = strtok($lines[0], ' ');
                if (!preg_match('~^[A-Z_]+ \S+ HTTP/1\.[01]$~', $lines[0])) {   // (bytes before a request: refused, as IIS does)
                    echo "bad request\n";
                    $c['w'] .= "HTTP/1.1 400 Bad Request\r\nContent-Length: 0\r\n\r\n";
                    $c['kind'] = 'dead';
                    continue;
                }
                $h = [];
                foreach (array_slice($lines, 1) as $l) {
                    [$k, $v] = explode(':', $l, 2) + [1 => ''];
                    $h[strtolower(trim($k))] = trim($v);
                }
                if ($method === 'RDG_OUT_DATA' || $method === 'RDG_IN_DATA') {   // (no HTTP transport here)
                    echo "probe 404\n";
                    $c['w'] .= "HTTP/1.1 404 Not Found\r\nContent-Length: 0\r\n\r\n";
                    $more = true;
                    continue;
                }
                $tok = preg_match('/^(NTLM|Negotiate)\s+(\S+)$/i', $h['authorization'] ?? '', $m) ? (string)base64_decode($m[2]) : '';
                if (strlen($tok) < 12 || !str_starts_with($tok, "NTLMSSP\0")) {
                    $c['w'] .= "HTTP/1.1 401 Unauthorized\r\nWWW-Authenticate: NTLM\r\nContent-Length: 0\r\n\r\n";
                    $more = true;
                    continue;
                }
                if (unpack('V', $tok, 8)[1] === 1) {             // NEGOTIATE: the challenge
                    [$msg, $c['ch']] = $challengeMsg();
                    $c['w'] .= "HTTP/1.1 401 Unauthorized\r\nWWW-Authenticate: Negotiate\r\nWWW-Authenticate: NTLM " . base64_encode($msg)
                        . ($affinity !== '' ? "\r\nSet-Cookie: $affinity; Path=/" : '') . "\r\nContent-Length: 6\r\n\r\nDenied";
                    $more = true;
                    continue;
                }
                $ok = $verify($tok, $c['ch']);
                if ($ok === null) {
                    echo "auth bad\n";
                    $c['w'] .= "HTTP/1.1 401 Unauthorized\r\nContent-Length: 0\r\n\r\n";
                    $c['kind'] = 'dead';
                    continue;
                }
                $c['kind'] = $method === 'RPC_IN_DATA' ? 'in' : 'out';
                echo 'auth ok ', $c['kind'], ' ', $ok[2] === null ? 'no bindings' : ($ok[2] === $bindings ? 'bindings ok' : 'bindings bad'), "\n";
                $c['length'] = (int)($h['content-length'] ?? 0);
                [$c['left'], $c['cookie']] = [min($c['length'], $inMax), $h['cookie'] ?? ''];
                $more = true;
                continue;
            }
            // an IN channel's bytes past its length (the next PDU does not fit in what is left): read as a new request by IIS,
            // refused, and the connection's end
            if ($c['kind'] === 'in' && strlen($c['buf']) > $c['left'] && ($c['plugged'] || $c['left'] < 16 || unpack('v', $c['buf'], 8)[1] > $c['left'])) {
                echo "in overrun\n";
                $c['w'] .= "HTTP/1.1 400 Bad Request\r\nContent-Length: 0\r\n\r\n";
                $c['kind'] = 'dead';
                if (($outId = $vcs[$c['vc']]['out'] ?? null) !== null && isset($conns[$outId])) {
                    $conns[$outId]['kind'] = 'dead';
                }
                break;
            }
            if (($c['kind'] !== 'in' && $c['kind'] !== 'out') || strlen($c['buf']) < 16 || $c['plugged']) {
                break;                                           // (a new IN channel's PDUs kept until the old one's last)
            }
            $n = unpack('v', $c['buf'], 8)[1];
            if (strlen($c['buf']) < $n) {
                break;
            }
            $p = substr($c['buf'], 0, $n);
            $c['buf'] = substr($c['buf'], $n);
            if ($c['kind'] === 'in') {
                $c['left'] -= $n;
            }
            $more = true;
            $type = ord($p[2]);
            if ($c['kind'] === 'out') {                          // CONN/A1, or OUT_R1/A3 (a new OUT channel), or its C1
                if ($type !== 0x14) {
                    continue;
                }
                $cmd = $commands($p);
                $vcKey = bin2hex($cmd[1][1] ?? '');
                if (!isset($vcs[$vcKey])) {
                    continue;
                }
                $vc = &$vcs[$vcKey];
                if ((unpack('v', $p, 16)[1] & 0x0004) && count($cmd) === 5) {   // OUT_R1/A3: the new channel; OUT_R2/A6 on the old one
                    $vc['succ'] = $id;
                    $c['vc'] = $vcKey;
                    $conns[$vc['out']]['w'] .= $rts(0, [[13, pack('V', 0)], [10, '']]);
                    $vc['succCookie'] = $cmd[3][1];
                    unset($vc);
                    continue;
                }
                $c['vc'] = $vcKey;
                $vc['out'] = $id;
                $vc['outCookie'] = $cmd[2][1];
                $c['w'] .= "HTTP/1.1 200 Success\r\nContent-Type: application/rpc\r\nContent-Length: 1073741824\r\n\r\n"
                    . $rts(0, [[2, pack('V', 120000)]]) . $rts(0, [[6, pack('V', 1)], [0, pack('V', 65536)], [2, pack('V', 120000)]]);
                unset($vc);
                continue;
            }
            // the IN channel: CONN/B1 (or a new IN channel's IN_R1/A1), RTS, bind, rpc_auth_3, requests
            if ($type === 0x14) {
                $cmd   = $commands($p);
                $flags = unpack('v', $p, 16)[1];
                $vcKey = bin2hex($cmd[1][1] ?? '');
                if ($c['vc'] === '' && ($flags & 0x0004)) {      // IN_R1/A1 (IN_R2/A1 the same): version, the connection's cookie, the
                    // old IN channel's (the default's), the new one's; one at a time, with the balancer's cookie
                    if (!isset($vcs[$vcKey]) || count($cmd) !== 4 || $cmd[0] !== [6, pack('V', 1)] || $cmd[2] !== [3, $vcs[$vcKey]['inCookie']]
                        || $cmd[3][0] !== 3 || $vcs[$vcKey]['inSucc'] !== null || !str_contains($c['cookie'], $affinity)) {
                        echo "in recycle bad\n";
                        $c['kind'] = 'dead';
                        continue;
                    }
                    [$c['vc'], $c['plugged'], $vcs[$vcKey]['inSucc'], $vcs[$vcKey]['inSuccCookie']] = [$vcKey, true, $id, $cmd[3][1]];
                    // IN_R2 (this gateway knows the connection: IN_R2/A2 to the server, its A3 to the outbound proxy), or IN_R1 as
                    // another gateway of a farm (a new connection to the server, IN_R1/A2, A3 with the inbound proxy's receive
                    // window and timeout): A4 to the client on its OUT channel
                    $conns[$vcs[$vcKey]['out']]['w'] .= $mode === 'inrecycle1'
                        ? $rts(0, [[13, pack('V', 0)], [6, pack('V', 1)], [0, pack('V', 65536)], [2, pack('V', 120000)]]) : $rts(0, [[13, pack('V', 0)]]);
                    continue;
                }
                if ($c['vc'] === '') {                           // CONN/B1: its lifetime the request's length
                    $vcs[$vcKey] ??= $newVc();
                    [$vcs[$vcKey]['in'], $vcs[$vcKey]['inCookie']] = [$id, $cmd[2][1] ?? ''];
                    $c['vc'] = $vcKey;
                    if (($cmd[3] ?? null) !== [4, pack('V', $c['length'])]) {
                        echo "in lifetime bad\n";
                    }
                    continue;
                }
                $vc = &$vcs[$c['vc']];
                if ($flags === 0 && count($cmd) === 1 && $cmd[0][0] === 3 && $vc['inSucc'] !== null) {
                    // IN_R1/A5 (R2's the same) on the old IN channel, its last PDU: the new one's cookie. IN_R1: A6, what this
                    // proxy queued and B1 to the server, its B2 to the new one; IN_R2: the old one closed. The new one unplugged.
                    if ($vc['in'] !== $id || $cmd[0][1] !== $vc['inSuccCookie']) {
                        echo "in recycle bad\n";
                    } else {
                        $conns[$vc['inSucc']]['plugged'] = false;
                        [$vc['in'], $vc['inCookie'], $vc['inSucc']] = [$vc['inSucc'], $vc['inSuccCookie'], null];
                        $c['kind'] = 'closing';
                        if ($c['buf'] !== '') {
                            echo "in after its end\n";               // (lost: the connection closed)
                        }
                        echo "in recycled\n";
                    }
                } elseif ($flags === 0x0001) {
                    echo "ping\n";
                } elseif ($flags === 0x0002 && isset($cmd[1]) && $cmd[1][0] === 1) {   // FlowControlAckWithDestination
                    $vc['acked'] = unpack('V', $cmd[1][1])[1];
                } elseif ($flags === 0x0010 && $vc['succ'] !== null) {   // OUT_R2/A7: the old OUT channel ended (B3), the new one's answer
                    $conns[$vc['out']]['w'] .= $rts(0x0020, [[10, '']]);
                    $conns[$vc['out']]['kind'] = 'closing';
                    $vc['out'] = $vc['succ'];
                    $vc['succ'] = null;
                    $vc['outCookie'] = $vc['succCookie'];
                    [$vc['sent'], $vc['acked']] = [0, 0];
                    $conns[$vc['out']]['w'] .= "HTTP/1.1 200 Success\r\nContent-Type: application/rpc\r\nContent-Length: 1073741824\r\n\r\n";
                    $vc['recycled'] = 1;
                    echo "recycled\n";
                }
                unset($vc);
                continue;
            }
            $vc = &$vcs[$c['vc']];
            $call = unpack('V', $p, 12)[1];
            $authLen = unpack('v', $p, 10)[1];
            if ($type === 0x0B) {                                // bind: its NEGOTIATE; bind_ack with the CHALLENGE
                [$msg, $vc['ch']] = $challengeMsg();
                $ndr  = $uuid('8a885d04-1ceb-11c9-9fe8-08002b104860') . pack('V', 2);
                $body = pack('vvV', 4088, 4088, 0x12345) . pack('v', 5) . "3388\0" . "\0" . pack('CCCC', 2, 0, 0, 0) . pack('vv', 0, 0) . $ndr
                      . pack('vv', 3, 3) . str_repeat("\0", 20) . pack('CCCCV', 0x0A, 5, 0, 0, 0);
                $vc['q'][] = [$hdr(0x0C, 0x17, 16 + strlen($body) + strlen($msg), strlen($msg), $call) . $body . $msg, true];
            } elseif ($type === 0x10) {                          // rpc_auth_3: the AUTHENTICATE
                $ok = $verify(substr($p, -$authLen), $vc['ch']);
                if ($ok === null) {
                    echo "rpc auth bad\n";
                } else {
                    $vc['srv'] = $serverNtlm($ok[1]);
                    echo "rpc auth ok\n";
                }
            } elseif ($type === 0x00 && $vc['srv'] !== null) {
                $request($vc, $p);
            }
            unset($vc);
        }
        unset($c);
    }

    // what waits for each OUT channel, within the client's window (RPC PDUs counted; RTS not)
    foreach ($vcs as $k => &$vc) {
        if ($vc['out'] === null || !isset($conns[$vc['out']]) || $vc['succ'] !== null) {
            continue;
        }
        if ($vc['late'] !== null && microtime(true) >= $vc['late'][0] && !$vc['ended']) {   // (lateclose: the close answered now)
            array_push($vc['q'], ...$vc['late'][1]);
            $vc['late'] = null;
        }
        if ($vc['askNew']) {                             // (closerecycle: OUT_R2/A2, a new OUT channel wanted)
            $conns[$vc['out']]['w'] .= $rts(0x0004, [[13, pack('V', 0)]]);
            [$vc['askNew'], $vc['recycled']] = [false, -1];
        }
        while ($vc['q'] !== []) {
            [$pdu, $counted, $say] = $vc['q'][0] + [2 => null];
            if ($counted && $vc['sent'] - $vc['acked'] + strlen($pdu) > 65536) {
                $vc['stalled'] = true;                           // (a client that never acknowledges stops here)
                break;
            }
            array_shift($vc['q']);
            $conns[$vc['out']]['w'] .= $pdu;
            $say !== null && print("$say\n");
            if ($counted) {
                $vc['sent'] += strlen($pdu);
                $vc['total'] += strlen($pdu);
            }
            if (in_array($mode, ['recycle', 'recyclecert', 'recyclelate', 'inrecycle1'], true) && $vc['recycled'] === 0 && $vc['succ'] === null
                && $vc['total'] > ($mode === 'recyclelate' || $mode === 'inrecycle1' ? 32768 : 1500)) {   // (32 KB: as the client's first acknowledgement)
                $conns[$vc['out']]['w'] .= $rts(0x0004, [[13, pack('V', 0)]]);   // OUT_R2/A2: a new OUT channel wanted
                $vc['recycled'] = -1;
                break;
            }
        }
    }
    unset($vc);

    foreach (array_keys($conns) as $id) {
        $c = &$conns[$id];
        if ($c['w'] !== '') {
            $n = @fwrite($c['s'], $c['w']);
            if ($n) {
                $c['w'] = substr($c['w'], $n);
            }
        }
        if (($c['gone'] || (($c['kind'] === 'dead' || $c['kind'] === 'closing') && $c['w'] === ''))) {
            @fclose($c['s']);
            $vcKey = $c['vc'];
            unset($conns[$id]);
            if ($vcKey !== '' && isset($vcs[$vcKey]) && ($vcs[$vcKey]['in'] === $id || $vcs[$vcKey]['out'] === $id) && !$vcs[$vcKey]['ended']) {
                $vcs[$vcKey]['ended'] = true;
                echo "relayed {$vcs[$vcKey]['up']} {$vcs[$vcKey]['down']}\n";
                is_resource($vcs[$vcKey]['relay']) && fclose($vcs[$vcKey]['relay']);
                $vcs[$vcKey]['relay'] = null;
            }
        }
        unset($c);
    }
}
@unlink($pem);
