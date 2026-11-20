<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);
// Unit tests: php tests/run.php
require __DIR__ . '/../src/bootstrap.php';

use MuDesk\Rdp\{Client, Ntlm, Rc4, Der, Rdpdr, Transport};
use MuDesk\{Text, Totp, Access};

$fail = 0;
function check(string $name, mixed $got, mixed $want): void
{
    global $fail;
    $ok = $got === $want;
    $fail += $ok ? 0 : 1;
    echo ($ok ? 'OK   ' : 'FAIL ') . $name . ($ok ? '' : "\n     got  " . var_export($got, true) . "\n     want " . var_export($want, true)) . "\n";
}

// MS-NLMP 4.2.4 NTLMv2 test vectors
$u16  = static fn($s) => Text::toUtf16($s);
$info = pack('vv', 2, 12) . $u16('Domain') . pack('vv', 1, 12) . $u16('Server') . "\0\0\0\0";
$name = $u16('Server');
$chal = "NTLMSSP\0" . pack('V', 2) . pack('vvV', strlen($name), strlen($name), 56) . pack('V', 0xE28A8233)
      . hex2bin('0123456789abcdef') . str_repeat("\0", 8) . pack('vvV', strlen($info), strlen($info), 56 + strlen($name))
      . hex2bin('0601b01d0000000f') . $name . $info;
$n = new Ntlm('User', 'Domain', 'Password');
$n->negotiate();
$auth = $n->authenticate($chal, ['time' => str_repeat("\0", 8), 'client' => str_repeat("\xaa", 8), 'key' => str_repeat("\x55", 16)]);
$f = static fn($off) => substr($auth, unpack('V', $auth, $off + 4)[1], unpack('v', $auth, $off)[1]);
check('NTLMv2 LMv2 response', bin2hex($f(12)), '86c35097ac9cec102554764a57cccc19aaaaaaaaaaaaaaaa');
check('NTLMv2 NTProofStr', bin2hex(substr($f(20), 0, 16)), '68cd0ab851e51c96aabc927bebef6a1c');
check('NTLMv2 SessionBaseKey', bin2hex($n->sessionBaseKey), '8de40ccadbc14a82f15cb0ad0de95ca3');
check('NTLMv2 EncryptedRandomSessionKey', bin2hex($f(52)), 'c5dad2544fc9799094ce1ce90bc9d03e');
$sealed = $n->seal($u16('Plaintext'));
check('NTLMv2 seal data', bin2hex(substr($sealed, 16)), '54e50165bf1936dc996020c1811b0f06fb5f');
check('NTLMv2 seal signature', bin2hex(substr($sealed, 0, 16)), '010000007fb38ec5c55d497600000000');
check('NTLM server version from the challenge', $n->serverVersion, [6, 1, 7600]);

// ".\user": the domain becomes the server's NetBIOS name (AvNbComputerName) and the result equals typing it
$fixed = ['time' => str_repeat("\0", 8), 'client' => str_repeat("\xaa", 8), 'key' => str_repeat("\x55", 16)];
$local = new Ntlm('User', '.', 'Password');
$local->negotiate();
$a1 = $local->authenticate($chal, $fixed);
$named = new Ntlm('User', 'Server', 'Password');
$named->negotiate();
check('NTLM local account domain', $local->domain, 'Server');
check('NTLM local account message', bin2hex($a1), bin2hex($named->authenticate($chal, $fixed)));

// NTOWFv2 takes the user name in Unicode uppercase, as Windows: "josé" is the account "JOSÉ" (strtoupper left "JOSé"
// and NLA failed for user names with accents); a short challenge is a protocol error, not PHP warnings
$lower = new Ntlm('josé', 'Domain', 'Password');
$lower->negotiate();
$lower->authenticate($chal, $fixed);
$upper = new Ntlm('JOSÉ', 'Domain', 'Password');
$upper->negotiate();
$upper->authenticate($chal, $fixed);
try {
    (new Ntlm('u', 'd', 'p'))->authenticate("NTLMSSP\0" . pack('V', 2) . str_repeat("\0", 30));
    $e = null;
} catch (Throwable $e) {
}
check('NTLM user name uppercase beyond ASCII, short challenge refused', [bin2hex($lower->sessionBaseKey) === bin2hex($upper->sessionBaseKey),
    $e instanceof MuDesk\Rdp\ProtocolException], [true, true]);
// the upper case of every UTF-16 code unit as Windows 11 has it (tests/fixtures/windows-upcase.txt, from its ntdll): Greek,
// Armenian, Vietnamese, fullwidth... too, and the letters it leaves as they are (final sigma, Georgian) left; only Latin
// and Cyrillic were before, so a Greek user name in lower case was refused
$winUp = [];
foreach (file(__DIR__ . '/fixtures/windows-upcase.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if ($line[0] !== '#') {
        [$a, $b] = explode(' ', $line);
        $winUp[hexdec($a)] = hexdec($b);
    }
}
$ntUpper = new ReflectionMethod(Ntlm::class, 'upper');
$all     = pack('v*', ...range(0, 0xFFFF));
$want    = pack('v*', ...array_map(static fn($c) => $winUp[$c] ?? $c, range(0, 0xFFFF)));
$greekLo = new Ntlm('αλέξης', 'Domain', 'Password');
$greekLo->negotiate();
$greekLo->authenticate($chal, $fixed);
$greekUp = new Ntlm('ΑΛΈΞΗς', 'Domain', 'Password');
$greekUp->negotiate();
$greekUp->authenticate($chal, $fixed);
check('NTLM user name: the upper case of all 65,536 code units as Windows, a Greek name in lower case as in capitals',
    [count($winUp), $ntUpper->invoke(null, $all) === $want, bin2hex($greekLo->sessionBaseKey) === bin2hex($greekUp->sessionBaseKey)], [973, true, true]);

// user name forms
check('user DOMAIN\\user', MuDesk\Connections::splitUser('CORP\\ana', 'X'), ['ana', 'CORP']);
check('user HOST\\user', MuDesk\Connections::splitUser('pc-01.corp.local\\ana', ''), ['ana', 'pc-01.corp.local']);
check('user .\\user', MuDesk\Connections::splitUser('.\\ana', 'X'), ['ana', '.']);
// a RemoteApp program typed by the name it was published with gets the "||" Windows expects (people took them for a
// mistake and removed them); a path, a file name or a name that already has them stays as typed
check('RemoteApp program: the published name gets "||", a path or file name does not',
    array_map([MuDesk\Connections::class, 'program'], ['Excel', ' Mi programa ', '||Excel', 'C:\\Windows\\notepad.exe', '%windir%\\system32\\mspaint.exe',
        'notepad.exe', 'calc.EXE', 'compmgmt.msc', '\\\\srv\\app\\x', '']),
    ['||Excel', '||Mi programa', '||Excel', 'C:\\Windows\\notepad.exe', '%windir%\\system32\\mspaint.exe', 'notepad.exe', 'calc.EXE', 'compmgmt.msc', '\\\\srv\\app\\x', '']);
check('user UPN', MuDesk\Connections::splitUser('ana@corp.example', 'X'), ['ana@corp.example', '']);
check('user plain', MuDesk\Connections::splitUser('ana', 'CORP'), ['ana', 'CORP']);

// MPPC 64 KB: packets compressed by FreeRDP from a deterministic sample (history across packets, AT_FRONT)
$sample = '';
$x      = 12345;
$words  = ['remote', 'desktop', 'screen', 'bitmap', 'cache', 'window', 'pixel', 'MuDesk', 'session', 'cursor'];
while (strlen($sample) < 140000) {
    $x       = ($x * 1103515245 + 12345) % 2147483648;
    $sample .= $words[$x % 10] . ($x % 7 === 0 ? "\n" : ' ') . ($x % 13 === 0 ? chr(($x >> 8) % 256) . chr(($x >> 16) % 256) : '');
}
$mppc = new MuDesk\Rdp\Mppc();
$got  = '';
foreach (file(__DIR__ . '/fixtures/mppc-vectors.txt', FILE_IGNORE_NEW_LINES) as $line) {
    [$flags, $hex] = explode(' ', $line);
    $got .= $mppc->decompress(hex2bin($hex), (int)$flags);
}
check('MPPC 64K decompression', md5($got), md5(substr($sample, 0, 140000)));
// a block of copies of 65535 bytes each: its size was only checked once decoded (30 KB took 754 MB and 29 s)
$bits = '01000001' . str_repeat('11111000001' . str_repeat('1', 14) . '0' . str_repeat('1', 15), 500);
$bomb = implode('', array_map(static fn($b) => chr(bindec($b)), str_split(str_pad($bits, intdiv(strlen($bits) + 7, 8) * 8, '0'), 8)));
$peak = memory_get_peak_usage();
try {
    (new MuDesk\Rdp\Mppc())->decompress($bomb, 0x21);
    $e = null;
} catch (Throwable $e) {
}
check('MPPC: history overflow found at the copy', [$e instanceof MuDesk\Rdp\ProtocolException, memory_get_peak_usage() - $peak < 4 << 20], [true, true]);
// NCRUSH (RDP 6.0) and XCRUSH (RDP 6.1) against blocks compressed by FreeRDP's compressors (first line: md5 and length
// of the original data); the XCRUSH set wraps its 2,000,000-byte history once
foreach (['ncrush' => 2, 'xcrush' => 3] as $name => $type) {
    $lines = file(__DIR__ . "/fixtures/$name-vectors.txt", FILE_IGNORE_NEW_LINES);
    [, $md5, $len] = explode(' ', array_shift($lines));
    $bulk = new MuDesk\Rdp\Bulk();
    $got  = '';
    $wrap = false;
    foreach ($lines as $line) {
        [$flags, $hex] = explode(' ', $line);
        $wrap = $wrap || ($type === 3 && (hexdec(substr($hex, 0, 2)) & 0x04));
        $got .= ((int)$flags & 0x20) ? $bulk->decompress(hex2bin($hex), (int)$flags) : hex2bin($hex);
    }
    check(strtoupper($name) . ' decompression', [md5($got), strlen($got), $type === 2 || $wrap], [$md5, (int)$len, true]);
}
// compressed fast-path updates: forwarded at once and run through the history only after (catchUp), so the frames and
// the input of a batch do not wait for its decompression (15-150 ms); a compressed slow-path PDU catches the history up
// first, so it reads the same as decompressing everything in order
$mv   = array_map(static fn($l) => explode(' ', $l), array_slice(file(__DIR__ . '/fixtures/mppc-vectors.txt', FILE_IGNORE_NEW_LINES), 0, 3));
$bc   = new Client(['width' => 800, 'height' => 600], static function () use (&$bcFrames) { $bcFrames++; });
$bcFrames = 0;
$bulkP = new ReflectionProperty(Client::class, 'bulk');
$bulkP->setValue($bc, new MuDesk\Rdp\Bulk());
$fast = static fn(int $flags, string $data): string => "\x00\x00" . chr(0x01 | 0x80) . chr($flags) . pack('v', strlen($data)) . $data;
(new ReflectionMethod(Client::class, 'fastPath'))->invoke($bc, $fast((int)$mv[0][0], hex2bin($mv[0][1])));
$queued = count((new ReflectionProperty(Client::class, 'backlog'))->getValue($bc));
$framesAtOnce = $bcFrames;
(new ReflectionMethod(Client::class, 'dataPdu'))->invoke($bc, new MuDesk\Rdp\Reader(str_repeat("\0", 8) . "\x02" . chr((int)$mv[1][0]) . "\0\0" . hex2bin($mv[1][1])));
$ref = new MuDesk\Rdp\Bulk();
$ref->decompress(hex2bin($mv[0][1]), (int)$mv[0][0]);
$ref->decompress(hex2bin($mv[1][1]), (int)$mv[1][0]);
check('bulk: fast-path updates forwarded before their decompression; the history caught up, in order, before a slow-path PDU',
    [$queued, $framesAtOnce, count((new ReflectionProperty(Client::class, 'backlog'))->getValue($bc)),
     md5($bulkP->getValue($bc)->decompress(hex2bin($mv[2][1]), (int)$mv[2][0])) === md5($ref->decompress(hex2bin($mv[2][1]), (int)$mv[2][0]))],
    [1, 1, 0, true]);
// NCRUSH literals past the end of its 64 KB history (66,000 of them, then the end): refused, as the browser refuses them
// (taken, the gateway and the page no longer agreed on the history); 1,000 of them are fine
$ncr = static function (int $n): string {
    try {
        return (string)strlen((new MuDesk\Rdp\Ncrush())->decompress(str_repeat("\x04\x41\x10", intdiv($n, 4)) . "\xff\x17", 0x80 | 0x20 | 2));
    } catch (MuDesk\Rdp\ProtocolException $e) {
        return $e->getMessage();
    }
};
check('NCRUSH: literals past the end of the history refused', [$ncr(1000), $ncr(66000)], ['1000', 'NCRUSH: history overflow']);
try {
    (new MuDesk\Rdp\Bulk())->decompress('xx', 0x24);
    check('unknown bulk compression type refused', false, true);
} catch (MuDesk\Rdp\ProtocolException) {
    check('unknown bulk compression type refused', true, true);
}
check('compression setting -> level announced', array_map([MuDesk\Rdp\Bulk::class, 'level'], [true, 'mppc', 'rdp6', 'rdp61', false, 'x']), [1, 1, 2, 3, 0, 0]);

// no arguments in the stack traces (a password passed down would end up in the error log), whatever php.ini says
$proc = proc_open([PHP_BINARY, '-d', 'zend.exception_ignore_args=0', '-r', 'require $argv[1]; $e = (static fn(string $s): Throwable => new RuntimeException("x"))("pw-in-trace");'
    . ' echo ini_get("zend.exception_ignore_args"), isset($e->getTrace()[0]["args"]) ? " args" : " none";', __DIR__ . '/../src/bootstrap.php'],
    [1 => ['pipe', 'w']], $pipes);
$argsOut = stream_get_contents($pipes[1]);
fclose($pipes[1]);
proc_close($proc);
check('errors: no arguments in stack traces', $argsOut, '1 none');

// RC4 (RFC 6229 key 0102030405)
check('RC4', bin2hex((new Rc4(hex2bin('0102030405')))->crypt(str_repeat("\0", 16))), 'b2396305f03dc027ccc3524a0a1118a8');

// UTF-16 round trip
$s = "Ñandú €𝄞 test";
check('UTF-16 round trip', Text::fromUtf16(Text::toUtf16($s)), $s);
check('UTF-16 lone surrogates become U+FFFD (valid UTF-8, next character kept)', Text::fromUtf16("\x00\xD8A\x00\x00\xDCB\x00\x00\xD8"), "\u{FFFD}A\u{FFFD}B\u{FFFD}");
// text that is not UTF-8 (a file name on Linux, a cut text) to UTF-16: each bad sequence becomes U+FFFD, the rest kept (a
// cut sequence took the bytes after it and gave another letter: "a\xC3" was "aÀ", "\xF0\x9F" a tile of mahjong)
check('UTF-8 cut or invalid to UTF-16: U+FFFD for each bad sequence', array_map(static fn($s) => bin2hex(Text::toUtf16($s)), ["a\xC3", "a\xC3b", "\xF0\x9F", "ñ"]),
    ['6100fdff', '6100fdff6200', 'fdff', 'f100']);
check('Text::cut', Text::cut('áéíóú', 3), 'áéí');

// DER
check('DER int', bin2hex(Der::int(6)) . '/' . bin2hex(Der::int(128)) . '/' . bin2hex(Der::int(-1)), '020106/02020080/0201ff');
check('DER long length', bin2hex(substr(Der::octet(str_repeat('a', 300)), 0, 4)), '0482012c');

// TOTP RFC 6238 (SHA-1 secret "12345678901234567890", T=59 -> 94287082, last 6 digits)
check('TOTP', Totp::code('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 1), '287082');

// CIDR
check('CIDR v4', Access::ipMatches('192.168.1.77', '192.168.1.0/24') && !Access::ipMatches('192.168.2.1', '192.168.1.0/24'), true);
check('CIDR v6', Access::ipMatches('2001:db8::5', '2001:db8::/32') && !Access::ipMatches('2001:db9::5', '2001:db8::/32'), true);
check('CIDR odd prefix', Access::ipMatches('10.0.0.130', '10.0.0.128/25') && !Access::ipMatches('10.0.0.127', '10.0.0.128/25'), true);

// Virtual printer: device announce and a print job (create / write / close) as the server sends them


// passkeys: CBOR, COSE EC2 key -> PEM, and an ES256 signature checked with it
$cnf = sys_get_temp_dir() . '/mudesk-test-' . bin2hex(random_bytes(4)) . '.cnf'; // PHP for Windows has no default OpenSSL configuration
file_put_contents($cnf, "[req]\ndefault_bits = 2048\ndistinguished_name = dn\n[dn]\n");
$ec  = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'config' => $cnf]);
$xy  = openssl_pkey_get_details($ec)['ec'];
$x   = str_pad($xy['x'], 32, "\0", STR_PAD_LEFT);
$y   = str_pad($xy['y'], 32, "\0", STR_PAD_LEFT);
$pos = 0;
$cose = MuDesk\Cbor::decode("\xa5\x01\x02\x03\x26\x20\x01\x21\x58\x20" . $x . "\x22\x58\x20" . $y, $pos);
check('CBOR COSE key', [$cose[1], $cose[3], $cose[-1], $pos], [2, -7, 1, 77]);
$pem = MuDesk\WebAuthn::coseToPem($cose);
openssl_sign('signed data', $sig, $ec, OPENSSL_ALGO_SHA256);
check('COSE EC2 key verifies an ES256 signature', $pem !== null && openssl_verify('signed data', $sig, $pem, OPENSSL_ALGO_SHA256) === 1, true);
check('COSE key with a wrong curve refused', MuDesk\WebAuthn::coseToPem([1 => 2, 3 => -7, -1 => 2, -2 => $x, -3 => $y]), null);
check('CBOR truncated input', MuDesk\Cbor::decode("\xa1\x01\x58\x20abc"), null);
check('CBOR text map keys and booleans', MuDesk\Cbor::decode("\xa2\x63fmt\x64none\x61x\xf5"), ['fmt' => 'none', 'x' => true]);

// data folder under the web root: protective files and the server key migrated into an exiting PHP file (on a copy)
$tmp = sys_get_temp_dir() . '/mudesk-test-' . bin2hex(random_bytes(4));
mkdir("$tmp/data", 0700, true);
$old = random_bytes(32);
file_put_contents("$tmp/data/secret.key", $old);
file_put_contents("$tmp/config.php", '<?php return ["data_dir" => ' . var_export("$tmp/data", true) . '];');
MuDesk\Config::load("$tmp/config.php");
check('drawing orders on by default (validated with xrdp and Windows 11)', MuDesk\Config::get('drawing_orders'), true);
check('data .htaccess denies', file_get_contents("$tmp/data/.htaccess"), MuDesk\Config::DENY);
check('data index.html', is_file("$tmp/data/index.html"), true);
check('data web.config (IIS) denies', file_get_contents("$tmp/data/web.config"), MuDesk\Config::DENY_IIS);
check('server key kept after migration', MuDesk\Crypto::appKey(), $old);
check('server key file exits at once', str_starts_with((string)file_get_contents("$tmp/data/secret.php"), '<?php exit; ?>'), true);
check('old key file removed', is_file("$tmp/data/secret.key"), false);
// audit: daily files, chain, tamper detection, migration of monthly files, search. Nothing purged meanwhile: log() purges
// once in 500 lines, and the day of 2020 is older than the retention (it went now and then, and the test failed at GitHub)
MuDesk\Settings::save(['audit_retention_days' => 0]);
file_put_contents("$tmp/data/audit/2020-01.jsonl", '{"t":"2020-01-05T10:00:00+00:00","ip":"1.2.3.4","user":"old","event":"login_ok"}' . "\n");
for ($i = 0; $i < 5; $i++) {
    MuDesk\Audit::log('login_' . ($i % 2 ? 'fail' : 'ok'), ['n' => $i], 'u' . $i, $i % 2 === 0);
}
$day = "$tmp/data/audit/" . date('Y-m-d') . '.jsonl';
check('audit: monthly file split into days', [is_file("$tmp/data/audit/2020-01.jsonl"), is_file("$tmp/data/audit/2020-01-05.jsonl")], [false, true]);
$v = MuDesk\Audit::verify();
check('audit: chain intact', [$v['ok'], $v['lines'], $v['unchained']], [true, 6, 1]);
$found = MuDesk\Audit::search(['from' => date('Y-m-d'), 'to' => date('Y-m-d'), 'ok' => '0']);
check('audit: search failures', [$found['total'], $found['failures'], array_column($found['rows'], 'user')], [2, 2, ['u3', 'u1']]);
$lines = file($day);
file_put_contents($day, str_replace('"user":"u2"', '"user":"zz"', implode('', $lines)));
check('audit: edited line detected', MuDesk\Audit::verify()['problems'], [[date('Y-m-d'), 3, 'changed']]);
unset($lines[1]);
file_put_contents($day, implode('', $lines));
check('audit: removed line detected', MuDesk\Audit::verify()['problems'], [[date('Y-m-d'), 2, 'missing_before']]);
MuDesk\Settings::save(['audit_retention_days' => 30]);
MuDesk\Audit::purge();
check('audit: old days purged and the purge recorded', [is_file("$tmp/data/audit/2020-01-05.jsonl"), MuDesk\Audit::search(['event' => 'audit_purge'])['total']], [false, 1]);
// the text looked for in what the viewer shows (user, IP, event, browser, each detail as key=value), in any case of any
// alphabet: never in the field names, the outcome or the hashes (searched in the raw line, "event" and "true" found every
// line, a piece of a hash its line), and found with quotes, a backslash, "key=value" or "JOSÉ" for "josé" (it was not)
MuDesk\Audit::log('login_fail', ['reason' => 'unknown_user'], 'josé', false);
MuDesk\Audit::log('conn_save', ['conn' => 'Sala "Norte"', 'path' => 'C:\\Docs\\a.txt'], 'ana');
MuDesk\Audit::log('rdp_connect', ['audio' => true, 'options' => ['audio' => true, 'mic' => false]], 'u9', true);
MuDesk\Audit::log('login_ok', [], 'u8', true);
$dayLines = file($day, FILE_IGNORE_NEW_LINES);
$hashOf   = substr((string)(json_decode((string)end($dayLines), true)['h'] ?? ''), 3, 10);
$whoFound = static fn(array $f): array => array_column(MuDesk\Audit::search($f + ['from' => date('Y-m-d'), 'to' => date('Y-m-d')])['rows'], 'user');
check('audit: text found in what is shown, never in field names or hashes; quotes, backslash, key=value, any case', [
    $whoFound(['q' => 'event']), $whoFound(['q' => 'true']), $whoFound(['q' => $hashOf]), $whoFound(['q' => 'JOSÉ']), $whoFound(['q' => '"Norte"']),
    $whoFound(['q' => 'reason=unknown']), $whoFound(['q' => 'Docs\\a']), $whoFound(['q' => 'audio=true']), $whoFound(['q' => 'mic']),
    $whoFound(['user' => 'JOSÉ']), $whoFound(['q' => 'u9']), $whoFound(['q' => 'Sala']),
], [[], ['u9'], [], ['josé'], ['ana'], ['josé'], ['ana'], ['u9'], ['u9'], ['josé'], ['u9'], ['ana']]);

// sign-in (attempt() itself: no 1 s wait; no real session in the CLI, hence the @ for cookies and session ids)
$att = new ReflectionMethod(MuDesk\Auth::class, 'attempt');
$try = static fn(string $n, string $p, string $c = ''): string => @$att->invoke(null, $n, $p, $c);
$_SESSION = [];
$pa = 'Mallory-' . bin2hex(random_bytes(6));
$pv = 'Victim-' . bin2hex(random_bytes(6));
$ua = MuDesk\Users::create('mallory', $pa, 'user', false);
MuDesk\Users::create('victim', $pv, 'user', false);
MuDesk\Users::update($ua['id'], static fn(array &$x) => $x['totp'] = ['enabled' => true, 'last' => 0,
    'secret' => MuDesk\Crypto::encrypt(MuDesk\Crypto::appKey(), MuDesk\Totp::secret(), 'totp:' . $ua['id'])]);
$_SERVER['REMOTE_ADDR'] = '10.20.0.1';
$step = $try('mallory', $pa);
$_SESSION['pending']['exp'] = time() - 1;                       // the second step of mallory expired
for ($i = 0; $i < 6; $i++) {                                   // other IPs, a code: still password attempts on victim
    $_SERVER['REMOTE_ADDR'] = '10.20.' . ($i + 1) . '.1';
    $try('victim', 'wrong-guess-' . $i, '000000');
}
$_SERVER['REMOTE_ADDR'] = '10.20.9.1';
check('sign-in: an expired second step does not move the failures to another user',
    [$step, isset(MuDesk\Store::read('ratelimit')['u:victim']), $try('victim', $pv)], ['second', true, 'invalid']);
$_SERVER['REMOTE_ADDR'] = '10.21.0.1';
$try(str_repeat('x', 100000), 'whatever-password-1');
$lines = file($day);
check('sign-in: a huge user name is neither a lock key nor written whole to the audit',
    [filesize("$tmp/data/ratelimit.json") < 2000, strlen((string)end($lines)) < 2000], [true, true]);
$long = str_repeat('Abcdefgh-', 8) . 'XYZ12345';                 // 80 bytes: bcrypt only checks the first 72
$ub   = MuDesk\Users::create('longpass', $long, 'user', false);
$_SERVER['REMOTE_ADDR'] = '10.22.0.1';
check('sign-in: a typo after byte 72 is a wrong password (no reset), such passwords no longer accepted',
    [$try('longpass', substr($long, 0, 75) . 'Q' . substr($long, 76)), MuDesk\Users::get($ub['id'])['sv'], $try('longpass', $long),
     MuDesk\Users::validPassword($long), MuDesk\Users::validPassword("Abcdefgh-123\0x")], ['invalid', 1, 'ok', false, false]);
// a data key that the right password cannot open (a damaged users file): the user signs in with a new one, and the loss of
// the saved RDP passwords is in the audit and the log (it was done in silence)
$pk_ = 'Key-lost-' . bin2hex(random_bytes(6));
$uk  = MuDesk\Users::create('keylost', $pk_, 'user', false);
MuDesk\Users::update($uk['id'], static fn(array &$x) => $x['dek']['key'] = base64_encode(random_bytes(60)));
$elog = ini_set('error_log', "$tmp/key-errors.log");
$_SERVER['REMOTE_ADDR'] = '10.22.1.1';
$klost = [$try('keylost', $pk_), array_column(MuDesk\Audit::search(['event' => 'saved_passwords_lost', 'user' => 'keylost'])['rows'], 'reason'),
    str_contains((string)@file_get_contents("$tmp/key-errors.log"), 'keylost')];
ini_set('error_log', (string)$elog);
check('sign-in: a data key the right password cannot open is replaced, and that is audited and logged', $klost, ['ok', ['key_unreadable'], true]);
// a data key wrapped as up to 1.0.0 (hash_pbkdf2, 200,000 rounds): still opened (OpenSSL gives the same key), wrapped again
// with 400,000 at the next sign-in, once, with the same data key inside (the saved RDP passwords stay readable); a wrong
// password changes nothing; a damaged round count is no minutes of work
$po  = 'Old-wrap-' . bin2hex(random_bytes(6));
$uo  = MuDesk\Users::create('oldwrap', $po, 'user', false);
$dek0 = random_bytes(32);
$salt0 = random_bytes(16);
$old0 = ['salt' => base64_encode($salt0), 'iter' => 200000,
    'key' => MuDesk\Crypto::encrypt(hash_pbkdf2('sha256', $po, $salt0, 200000, 32, true), $dek0, 'dek:' . $uo['id'])];
MuDesk\Users::update($uo['id'], static fn(array &$x) => $x['dek'] = $old0);
$_SERVER['REMOTE_ADDR'] = '10.22.2.1';
$wrongOld = [$try('oldwrap', $po . 'x'), MuDesk\Users::get($uo['id'])['dek'] === $old0];
$okOld    = $try('oldwrap', $po);
$new0     = MuDesk\Users::get($uo['id'])['dek'];
$okAgain  = $try('oldwrap', $po);
check('sign-in: a data key of 200,000 rounds opened and wrapped again with 400,000, once, the same key inside', [
    $wrongOld, $okOld, $new0['iter'], MuDesk\Crypto::unwrapKey($po, $new0, $uo['id']) === $dek0, MuDesk\Crypto::needsRewrap($new0),
    $okAgain, MuDesk\Users::get($uo['id'])['dek'] === $new0, MuDesk\Crypto::unwrapKey($po, ['iter' => 2000000000] + $new0, $uo['id']),
    MuDesk\Crypto::needsRewrap(MuDesk\Crypto::wrapKey('Another-pass-1', $dek0, 'u'))],
    [['invalid', true], 'ok', 400000, true, false, 'ok', true, null, false]);
// the rule counts characters, not bytes: three equal emojis are 12 bytes with 4 different ones; twelve accented letters, of
// 24 bytes, are 12 characters; text that is not UTF-8 is refused
check('password rule: characters, not bytes',
    array_map(static fn(string $p): bool => MuDesk\Users::validPassword($p), [str_repeat('😀', 3), str_repeat('ñ', 6) . str_repeat('é', 6),
        'ñandú-pingüé', 'áéíóúñ-çàèü', "Abcdefgh-12\xff", 'Abcdefgh-123']),
    [false, false, true, false, false, true]);
$_SERVER['REMOTE_ADDR'] = '10.23.0.1';
for ($i = 0; $i < 5; $i++) {
    $try('nobody' . $i, 'wrong-password-1');
}
$t = microtime(true);
check('sign-in: a locked IP is answered at once', [@MuDesk\Auth::login('nobody', 'wrong-password-1'), microtime(true) - $t < 0.5], ['invalid', true]);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

// client IP behind trusted proxies: they append what they saw, the left part comes from the client
file_put_contents("$tmp/proxy.php", '<?php return ["data_dir" => ' . var_export("$tmp/data", true) . ', "trusted_proxies" => ["127.0.0.1", "10.0.0.2"]];');
MuDesk\Config::load("$tmp/proxy.php");
$ips = [];
foreach (['6.6.6.6, 203.0.113.7', '6.6.6.6, 203.0.113.7, 10.0.0.2', '203.0.113.8', 'junk, 203.0.113.9'] as $xff) {
    $_SERVER['HTTP_X_FORWARDED_FOR'] = $xff;
    $ips[] = MuDesk\Http::clientIp();
}
unset($_SERVER['HTTP_X_FORWARDED_FOR']);
MuDesk\Config::load("$tmp/config.php");
check('client IP: right-most address that is not a trusted proxy', $ips, ['203.0.113.7', '203.0.113.7', '203.0.113.8', '203.0.113.9']);

// running sessions: the list has no input tokens; disabling, deleting or resetting a user ends them
$ksid = bin2hex(random_bytes(16));
$sess = MuDesk\Sessions::register($ksid, ['uid' => 'u-kill', 'user' => 'k', 'token' => 'input-token']);
$list = MuDesk\Sessions::active('u-kill');
MuDesk\Sessions::killUser('u-kill');
check('sessions: listed without the input token, ended with their user', [count($list), array_key_exists('token', $list[0] ?? []), $sess->read()], [1, false, ["\x09"]]);
$sess->close();
// a session whose process died without cleaning up (memory, hosting time limit): its uploads and print jobs go too
$dsid = bin2hex(random_bytes(16));
MuDesk\Sessions::register($dsid, ['uid' => 'u-dead', 'user' => 'd', 'token' => 't']);
mkdir("$tmp/data/tmp/$dsid", 0700, true);
file_put_contents("$tmp/data/tmp/$dsid/print-1", '%PDF');
touch("$tmp/data/run/$dsid.json", time() - 120);
MuDesk\Sessions::active();
check('sessions: a dead session is removed with its temporary files', [is_file("$tmp/data/run/$dsid.json"), is_dir("$tmp/data/tmp/$dsid")], [false, false]);
// a session's file read while it is written (empty, fresh) is not a dead one: it was removed with its queue, and the
// session's input got 404
$wsid0 = bin2hex(random_bytes(16));
file_put_contents("$tmp/data/run/$wsid0.json", '');
file_put_contents("$tmp/data/run/$wsid0.mode", 'file');
file_put_contents("$tmp/data/run/$wsid0.q", '');
MuDesk\Sessions::active();
check('sessions: a file being written is not taken for a dead session', [is_file("$tmp/data/run/$wsid0.json"), is_file("$tmp/data/run/$wsid0.q")], [true, true]);
MuDesk\Sessions::cleanup($wsid0);
// limits of a user and of all the users together (each session holds a PHP worker); requests at the same time can not both
// take the last place: counting and registering share one lock (another process registering waits while it is held)
$lim  = [];
$room = count(MuDesk\Sessions::active()) + 2;
foreach (['lim-a', 'lim-a', 'lim-b', 'lim-c'] as $luid) {
    $r     = MuDesk\Sessions::register(bin2hex(random_bytes(16)), ['uid' => $luid, 'token' => 't'], 1, $room);
    $lim[] = is_string($r) ? $r : 'ok';
}
$hold = fopen("$tmp/data/run/register.lock", 'c');
flock($hold, LOCK_EX);
$rp = proc_open([PHP_BINARY, '-r', 'require $argv[1]; MuDesk\Config::load($argv[2]); echo "ready\n"; $t = microtime(true);'
    . ' MuDesk\Sessions::register(bin2hex(random_bytes(16)), ["uid" => "lim-w", "token" => "t"]); echo microtime(true) - $t;',
    __DIR__ . '/../src/bootstrap.php', "$tmp/config.php"], [1 => ['pipe', 'w']], $rpipes);
fgets($rpipes[1]);
usleep(600000);
flock($hold, LOCK_UN);
fclose($hold);
$lockWait = (float)stream_get_contents($rpipes[1]);
fclose($rpipes[1]);
proc_close($rp);
check('sessions: limits per user and of all users, counted and registered under one lock', [$lim, $lockWait > 0.3],
    [['ok', 'too_many_sessions', 'ok', 'busy'], true]);
foreach (MuDesk\Sessions::active() as $s) {
    if (str_starts_with($s['uid'], 'lim-')) {
        MuDesk\Sessions::cleanup($s['sid']);
    }
}

// the remote sessions of a web session end with it (signed out, expired, closed by the gateway): before, they went on until
// their own idle limit, with nobody signed in; an own password change ends those of the user's other web sessions, saying why
$w1 = bin2hex(random_bytes(16));
$w2 = bin2hex(random_bytes(16));
$s1 = MuDesk\Sessions::register($w1, ['uid' => 'u-web', 'user' => 'w', 'token' => 't', 'wid' => 'web-1']);
$s2 = MuDesk\Sessions::register($w2, ['uid' => 'u-web', 'user' => 'w', 'token' => 't', 'wid' => 'web-2']);
MuDesk\Sessions::killUser('u-web', 'web-1');                                // password changed from web-1
$kept = [$s1->read(), $s2->read()];
MuDesk\Sessions::killWeb('web-1');                                          // web-1 signed out
MuDesk\Sessions::killWeb('');                                               // (no web session: nothing)
check('sessions: ended with their web session; a password change ends the other web sessions\' ones', [$kept, $s1->read(), $s2->read()],
    [[[], ["\x09signed_out"]], ["\x09signed_out"], []]);
$s1->close();
$s2->close();
// a session's message queue (no sockets: Windows) holds at most 16 MB: while it connects nothing reads it, and the browser could
// fill the disk
$qs = bin2hex(random_bytes(16));
$qq = MuDesk\Sessions::register($qs, ['uid' => 'u-queue', 'user' => 'q', 'token' => 't']);
file_put_contents("$tmp/data/run/$qs.mode", 'file');
$sent = [MuDesk\Sessions::send($qs, str_repeat('a', 10 << 20)), MuDesk\Sessions::send($qs, str_repeat('b', 10 << 20))];
clearstatcache();
check('sessions: a queue of at most 16 MB', [$sent, filesize("$tmp/data/run/$qs.q") <= 16 << 20], [[true, false], true]);
$qq->close();
MuDesk\Sessions::cleanup($qs);
// a session id that is a path: nothing written or removed out of the folder of the sessions
file_put_contents("$tmp/data/decoy.json", '{}');
file_put_contents("$tmp/data/decoy.mode", 'file');
file_put_contents("$tmp/data/decoy.q", '');
MuDesk\Sessions::cleanup('../decoy');
check('sessions: an id that is a path neither written nor removed', [MuDesk\Sessions::send('../decoy', 'input'), (string)file_get_contents("$tmp/data/decoy.q"),
    file_exists("$tmp/data/decoy.json"), file_exists("$tmp/data/decoy.mode")], [false, '', true, true]);

// input of a session through a socket: a Unix one, or on Windows a TCP port of this computer whose messages must start
// with the session's secret (the queue file used there was read every 20 ms: each key waited up to 20 ms); a connection
// without the secret is dropped
$ts     = bin2hex(random_bytes(16));
$tsess  = MuDesk\Sessions::register($ts, ['uid' => 'u-tcp', 'token' => 't']);
$tmode  = (string)file_get_contents("$tmp/data/run/$ts.mode");
$sentT  = [MuDesk\Sessions::send($ts, pack('V', 0) . "\x01a"), MuDesk\Sessions::send($ts, pack('V', 1) . "\x01" . str_repeat('b', 2000))];
if (str_starts_with($tmode, 'tcp:')) {
    $rogue = @stream_socket_client('tcp://127.0.0.1:' . explode(':', $tmode)[1]);
    $evil  = pack('V', 2) . "\x01evil";
    fwrite($rogue, pack('V', strlen($evil)) . $evil);
    fclose($rogue);
}
$gotT = [];
for ($i = 0; $i < 25; $i++) {
    usleep(20000);
    $gotT = array_merge($gotT, $tsess->read());
}
check('sessions: input through a socket (on Windows a local TCP port with the session\'s secret; none without it)',
    [PHP_OS_FAMILY === 'Windows' ? (bool)preg_match('/^tcp:\d+:[0-9a-f]{32}$/', $tmode) : $tmode === 'sock', $sentT, array_map('strlen', $gotT)],
    [true, [true, true], [2, 2001]]);
$tsess->close();
// socket mode (Linux): each request of the browser is a connection with one message. A large one (a paste) arriving in
// pieces while another request came whole was mixed with it, and the session's input stopped for good. Connections made
// by hand here (socket pairs: Windows has no Unix sockets for PHP), a whole one between the two halves of a large one
$sk  = (new ReflectionClass(MuDesk\Sessions::class))->newInstanceWithoutConstructor();
$skp = static fn(string $n) => new ReflectionProperty(MuDesk\Sessions::class, $n);
$skp('server')->setValue($sk, stream_socket_server('tcp://127.0.0.1:0'));
$pair = static function () {
    $p = stream_socket_pair(PHP_OS_FAMILY === 'Windows' ? STREAM_PF_INET : STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
    stream_set_blocking($p[0], false);
    return $p;
};
[$bigIn, $bigOut]     = $pair();
[$smallIn, $smallOut] = $pair();
[$deadIn, $deadOut]   = $pair();
$skp('conns')->setValue($sk, [$bigIn, $smallIn, $deadIn]);
$bigMsg = pack('V', 0) . "\x04" . str_repeat('A', 40000);   // (within any socket buffer: nothing reads it meanwhile)
$frame  = static fn(string $m): string => pack('V', strlen($m)) . $m;
fwrite($bigOut, substr($frame($bigMsg), 0, 20000));
fwrite($smallOut, $frame(pack('V', 1) . "\x01mouse"));
fclose($smallOut);
fwrite($deadOut, substr($frame(pack('V', 2) . "\x01lost"), 0, 6));   // a sender that died halfway
fclose($deadOut);
usleep(50000);
$got = $sk->read();
fwrite($bigOut, substr($frame($bigMsg), 20000));
fclose($bigOut);
usleep(50000);
$got = array_merge($got, $sk->read());
check('sessions: messages arriving in pieces at the same time are not mixed (socket mode)',
    [array_map(static fn(string $m): string => strlen($m) > 20 ? substr($m, 0, 2) . strlen($m) : $m, $got), strlen($skp('pending')->getValue($sk))],
    [["\x04A40001", "\x01mouse"], 0]);

// shared folder: Windows device names refused, with any extension or trailing dots (CON, NUL, COM1, LPT¹, CONIN$...): a file
// named so could not be read back or removed, and writing "nul" lost the data
check('shared folder: device names refused', array_map([MuDesk\Files::class, 'clean'],
    ['con', 'CON.txt', 'a/nul', 'Aux.', 'com1.log', 'LPT¹', 'conin$', 'CLOCK$.x', 'prn .txt', 'nul  ', 'console', 'com10', 'nul2.txt', 'a/conx/b']),
    [null, null, null, null, null, null, null, null, null, null, 'console', 'com10', 'nul2.txt', 'a/conx/b']);

// audit of refused requests by someone not signed in: at most 100 lines an hour from an address, then one line saying so
// (each such request wrote a line, without limit: a day's file could outgrow what reading it could hold)
$was = $_SERVER['REMOTE_ADDR'] ?? null;
$_SERVER['REMOTE_ADDR'] = '203.0.113.77';
$anon = [];
for ($i = 0; $i < 103; $i++) {
    $anon[] = MuDesk\Audit::anonymous();
}
$_SERVER['REMOTE_ADDR'] = '203.0.113.78';
$other = MuDesk\Audit::anonymous();
$_SERVER['REMOTE_ADDR'] = $was;
if ($was === null) {
    unset($_SERVER['REMOTE_ADDR']);
}
check('audit: at most 100 lines an hour about someone not signed in, per address, then one line saying so', [count(array_filter($anon)), $anon[99], $anon[100],
    $other, MuDesk\Audit::search(['event' => 'audit_limited'])['total']], [100, true, false, true, 1]);
MuDesk\Store::update('auditlimit', static function (array &$a): void { $a = []; });

// audit read a line at a time, from the end in blocks of 1 MB (a day of hundreds of MB went whole into memory and stopped
// sign-ins): the same lines with their numbers, also those across blocks, and little memory for a day of 40 MB
$bw  = "$tmp/backwards.jsonl";
$fh  = fopen($bw, 'w');
$all = [];
for ($i = 1, $len = 0; $len < 2600000; $i++) {
    $l = str_repeat(chr(97 + $i % 26), 1 + ($i * 7919) % 3000);
    fwrite($fh, $l . ($i % 5 ? "\n" : "\r\n") . ($i % 11 ? '' : "\n"));   // CRLF lines and empty lines too
    $all[$i] = $l;
    $len    += strlen($l) + 2;
}
fwrite($fh, 'last');                                                          // no newline at the end
$all[$i] = 'last';
fclose($fh);
$back = new ReflectionMethod(MuDesk\Audit::class, 'backwards');
check('audit: read backwards in blocks, the same numbered lines', iterator_to_array($back->invoke(null, $bw), true) === array_reverse($all, true), true);
// the lines counted in blocks (a pass through every line took 40 % of a search): as many as lines() gives, with empty
// lines, lines of only CRs, a first line that is empty, no newline at the end and blocks of 1 MB cut anywhere
$cnt   = new ReflectionMethod(MuDesk\Audit::class, 'count');
$lns   = new ReflectionMethod(MuDesk\Audit::class, 'lines');
$cases = [$bw, "\nA\n\r\n\r\r\nB\r\n\n" . 'C', "\r\nx", '', "\n\n", 'solo', str_repeat('y', 1048575) . "\n\nz\n", str_repeat('y', 1048576) . "\n\r\nz",
    str_repeat("ab\n", 400000) . "\n"];
$counts = [];
foreach ($cases as $i => $c) {
    $f = $i === 0 ? $bw : "$tmp/count-$i.txt";
    if ($i > 0) {
        file_put_contents($f, $c);
    }
    $counts[] = [$cnt->invoke(null, $f), iterator_count($lns->invoke(null, $f))];
    if ($i > 0) {
        unlink($f);
    }
}
check('audit: lines counted in blocks as lines() gives them', array_map(static fn($c) => $c[0] === $c[1], $counts), array_fill(0, count($cases), true));
unlink($bw);
mkdir("$tmp/big/data/audit", 0700, true);
file_put_contents("$tmp/big/config.php", '<?php return ["data_dir" => ' . var_export("$tmp/big/data", true) . '];');
$fh = fopen("$tmp/big/data/audit/" . date('Y-m-d') . '.jsonl', 'w');
$row = json_encode(['t' => date('Y-m-d\TH:i:s.vP'), 'ip' => '203.0.113.5', 'user' => 'bob', 'event' => 'login_fail', 'ok' => false, 'pad' => str_repeat('x', 300)]);
for ($i = 0; $i < 110000; $i++) {
    fwrite($fh, $row . "\n");
}
fclose($fh);
$proc = proc_open([PHP_BINARY, '-r', 'require $argv[1]; MuDesk\Config::load($argv[2]); $m = memory_get_usage();'
    . ' $s = MuDesk\Audit::search(["event" => "login_fail"]); $l = MuDesk\Audit::sinceLogin("bob", time() - 3600); $v = MuDesk\Audit::verify();'
    . ' echo $s["total"], " ", count($s["rows"]), " ", $v["lines"], " ", memory_get_peak_usage() - $m;', __DIR__ . '/../src/bootstrap.php', "$tmp/big/config.php"],
    [1 => ['pipe', 'w']], $pipes);
[$bt, $br, $bl, $bpeak] = explode(' ', (string)stream_get_contents($pipes[1])) + ['', '', '', '0'];
fclose($pipes[1]);
proc_close($proc);
check('audit: a day of 40 MB searched, checked and read at sign-in in little memory', [$bt, $br, $bl, (int)$bpeak < 16 << 20], ['110000', '100', '110000', true]);
MuDesk\Files::remove("$tmp/big");

// shared folder: no file the web server or PHP reads as configuration (Apache, IIS, PHP-FPM), whatever the separator
check('shared folder: configuration file names refused', array_map([MuDesk\Files::class, 'clean'],
    ['.htaccess', 'sub/.HTaccess', 'sub\\.htpasswd', 'web.config', 'a\\Web.Config', 'web.config.', 'a/.user.ini', 'a.htm', 'b/x.htaccess.txt']),
    [null, null, null, null, null, null, null, 'a.htm', 'b/x.htaccess.txt']);
check('shared folder: names over 255 bytes refused (file systems do not take them)', array_map([MuDesk\Files::class, 'clean'],
    [str_repeat('a', 255), 'd/' . str_repeat('b', 256), str_repeat('ñ', 128)]), [str_repeat('a', 255), null, null]);

// file manager: folders moved and renamed (never into themselves, never over something), the tree of folders, folders of an
// uploaded folder created at once, folders downloaded as a ZIP made as it is sent
$fr = MuDesk\Files::root('fxtest');
mkdir("$fr/a/b/c", 0777, true);
mkdir("$fr/Z");
file_put_contents("$fr/a/x.txt", 'xx');
file_put_contents("$fr/t.txt", 'tt');
$moves = [MuDesk\Files::move('fxtest', 'a/x.txt', 'Z/y.txt'), MuDesk\Files::move('fxtest', 't.txt', 'Z/y.txt'), MuDesk\Files::move('fxtest', 'a', 'a/b/a'),
    MuDesk\Files::move('fxtest', 'a', 'a/b'), MuDesk\Files::move('fxtest', '', 'q'), MuDesk\Files::move('fxtest', 'a', '../q'), MuDesk\Files::move('fxtest', 'no', 'q'),
    MuDesk\Files::move('fxtest', 't.txt', 'none/t.txt'), MuDesk\Files::move('fxtest', 't.txt', 'T.TXT'), MuDesk\Files::move('fxtest', 'a/b', 'Z/b2')];
check('file manager: moved and renamed; never into itself, over something or out of the folder; a change of case is a rename', [$moves,
    is_file("$fr/Z/y.txt"), is_dir("$fr/Z/b2/c"), is_dir("$fr/a/b"), in_array('T.TXT', scandir($fr), true)],
    [[null, 'exists', 'into_itself', 'into_itself', 'invalid_path', 'invalid_path', 'not_found', 'not_found', null, null], true, true, false, true]);
mkdir("$fr/a/b10");
mkdir("$fr/a/b9");
file_put_contents("$fr/a/file.txt", 'f');
check('file manager: the folders of the open folders of the tree (natural order, files and missing folders left out, cut at the most asked for)',
    [MuDesk\Files::subdirs('fxtest', ['', 'a', 'nothere', '../x', 'Z/b2/c', 7], 100), MuDesk\Files::subdirs('fxtest', ['a'], 2)],
    [[['', ['a', 'Z'], false], ['a', ['b9', 'b10'], false], ['Z/b2/c', [], false]], [['a', ['b9', 'b10'], false]]]);
mkdir("$fr/a/b11");
check('file manager: a folder with more folders than asked for says so', MuDesk\Files::subdirs('fxtest', ['a'], 2), [['a', ['b9', 'b10'], true]]);
$mk = [MuDesk\Files::mkdirs('fxtest', 'a', ['up', 'up/one/two', 'up/empty', 'b9']), MuDesk\Files::mkdirs('fxtest', 'Z', ['y.txt/in']),
    MuDesk\Files::mkdirs('fxtest', '', ['ok', '../out']), MuDesk\Files::mkdirs('fxtest', 'nothere', ['x']), MuDesk\Files::mkdirs('fxtest', '', ['.htaccess'])];
check('file manager: the folders of an uploaded folder created at once (parents too, existing kept); never a file in the way or a bad name',
    [$mk, is_dir("$fr/a/up/one/two"), is_dir("$fr/a/up/empty"), is_dir("$fr/ok"), file_exists(dirname($fr) . '/out')],
    [[null, 'invalid_name', 'invalid_name', 'not_found', 'invalid_name'], true, true, true, false]);
file_put_contents("$fr/a/up/one/two/ñ.bin", random_bytes(70000));
file_put_contents("$fr/a/up/e.txt", '');
$entries = MuDesk\Files::zipEntries('fxtest', 'a', ['up', 'b9']);
$zmem    = fopen('php://memory', 'w+b');
MuDesk\ZipStream::write($entries, $zmem);
rewind($zmem);
$zip = (string)stream_get_contents($zmem);
// read back from its central directory, each entry's local header, name, data and CRC compared
$unzip = static function (string $z): array {
    $end = strrpos($z, pack('V', 0x06054b50));
    $e   = unpack('vdisk/vcdisk/vn/vtotal/Vsize/Voffset', substr($z, $end + 4, 16));
    $out = [];
    for ($i = 0, $p = $e['offset']; $i < $e['total']; $i++) {
        $c    = unpack('Vsig/vmade/vneed/vflags/vmethod/Vdos/Vcrc/Vcsize/Vsize/vnlen/vxlen/vclen/vdisk/vint/Vext/Voffset', substr($z, $p, 46));
        $name = substr($z, $p + 46, $c['nlen']);
        $l    = unpack('Vsig/vneed/vflags/vmethod/Vdos/Vcrc/Vcsize/Vsize/vnlen/vxlen', substr($z, $c['offset'], 30));
        $data = substr($z, $c['offset'] + 30 + $l['nlen'], $l['size']);
        $out[$name] = [$c['crc'] === $l['crc'] && $c['size'] === $l['size'] && $l['flags'] === 0x0800 && crc32($data) === $c['crc'], $c['ext'], strlen($data), $data];
        $p += 46 + $c['nlen'] + $c['xlen'] + $c['clen'];
    }
    return $out;
};
$unz = $unzip($zip);
check('file manager: a ZIP of folders and files made as it is sent, its length known before, each entry read back with its CRC',
    [array_map(static fn($x) => array_slice($x, 0, 3), $unz), $unz['up/one/two/ñ.bin'][3] === file_get_contents("$fr/a/up/one/two/ñ.bin"),
     MuDesk\ZipStream::length($entries) === strlen($zip), MuDesk\Files::zipEntries('fxtest', 'a', ['none']), MuDesk\Files::zipEntries('fxtest', 'a', ['up/one']),
     MuDesk\ZipStream::length([['big', null, 0xFFFFFFFF]])],
    [['up/' => [true, 0x10, 0], 'up/e.txt' => [true, 0, 0], 'up/empty/' => [true, 0x10, 0], 'up/one/' => [true, 0x10, 0], 'up/one/two/' => [true, 0x10, 0],
      'up/one/two/ñ.bin' => [true, 0, 70000], 'b9/' => [true, 0x10, 0]], true, true, null, null, null]);
// a ZIP of up to 65,535 entries (more cannot be said without ZIP64): more is refused before walking on; DOS dates kept
// to what they can say (before 1980 the year went negative and the date said 2107 or garbage)
$dos = new ReflectionMethod(MuDesk\ZipStream::class, 'dosTime');
check('file manager: a ZIP over its entry limit refused, dates before 1980 and after 2107 clamped', [MuDesk\Files::zipEntries('fxtest', 'a', ['up', 'b9'], 3),
    $dos->invoke(null, 0), $dos->invoke(null, mktime(12, 0, 0, 1, 1, 2200)), $dos->invoke(null, mktime(10, 30, 20, 6, 15, 2026))],
    [false, 0x00210000, 0xFF9FBF7D, (10 << 11 | 30 << 5 | 10) | ((46 << 9 | 6 << 5 | 15) << 16)]);
MuDesk\Files::removeAll('fxtest');

// password reset by the administrator: the data key is new, so passkeys no longer sign in alone; an own change keeps them
$up = MuDesk\Users::create('pkuser', 'Pk-user-' . bin2hex(random_bytes(6)), 'user', false);
MuDesk\Users::update($up['id'], static fn(array &$x) => $x['passkeys'] = [['id' => 'k1', 'pem' => '', 'count' => 0, 'name' => 'k', 'created' => '', 'used' => null, 'dek' => 'wrapped']]);
MuDesk\Users::setPassword($up['id'], 'Own-change-' . bin2hex(random_bytes(6)), random_bytes(32), false);
$own = MuDesk\Users::get($up['id'])['passkeys'][0]['dek'];
MuDesk\Users::setPassword($up['id'], 'Admin-reset-' . bin2hex(random_bytes(6)), null, true);
check('password reset: passkeys stop wrapping the lost data key', [$own, MuDesk\Users::get($up['id'])['passkeys'][0]['dek']], ['wrapped', null]);

// passkey signature counter: a clone goes backwards, back to 0 too
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['HTTPS']     = 'on';
$assert = static function (int $count) use ($ec, $pem): ?int {
    $o  = MuDesk\WebAuthn::getOptions([], 'login');
    $cd = json_encode(['type' => 'webauthn.get', 'challenge' => $o['challenge'], 'origin' => 'https://localhost']);
    $ad = hash('sha256', 'localhost', true) . "\x05" . pack('N', $count);
    openssl_sign($ad . hash('sha256', $cd, true), $s, $ec, OPENSSL_ALGO_SHA256);
    return MuDesk\WebAuthn::verify(['pem' => $pem, 'count' => 5], 'login', $cd, $ad, $s);
};
check('passkey counter: forward accepted, same or back to 0 refused', [$assert(6), $assert(5), $assert(0)], [6, null, null]);
// lists where a passkey's data has text (authData, the challenge, a key coordinate): refused without a warning (they
// were "Array", with an "Array to string conversion" warning; any signed-in user could send them)
$wnotes = [];
set_error_handler(static function (int $no, string $msg) use (&$wnotes): bool {
    $wnotes[] = $msg;
    return true;
});
$wcd  = static fn(string $type, $chal) => json_encode(['type' => $type, 'challenge' => $chal, 'origin' => 'https://localhost']);
$wo   = MuDesk\WebAuthn::createOptions($up);
$wr1  = MuDesk\WebAuthn::register($up, $wcd('webauthn.create', $wo['challenge']), "\xa1\x68authData\x80");
MuDesk\WebAuthn::createOptions($up);
$wr2  = MuDesk\WebAuthn::register($up, $wcd('webauthn.create', []), "\xa0");
$wo   = MuDesk\WebAuthn::createOptions($up);
$wad  = hash('sha256', 'localhost', true) . "\x45" . "\0\0\0\0" . str_repeat("\0", 16) . "\x00\x01" . 'X' . "\xa5\x01\x02\x03\x26\x20\x01\x21\x80\x22\x41y";
$wr3  = MuDesk\WebAuthn::register($up, $wcd('webauthn.create', $wo['challenge']), "\xa1\x68authData\x58" . chr(strlen($wad)) . $wad);
restore_error_handler();
check('passkeys: lists where text goes refused, no warning', [$wr1, $wr2, $wr3, $wnotes], [null, null, null, []]);
unset($_SERVER['HTTP_HOST'], $_SERVER['HTTPS']);

// server key on hostings that disable link()
mkdir("$tmp/nolink/data", 0700, true);
file_put_contents("$tmp/nolink/config.php", '<?php return ["data_dir" => ' . var_export("$tmp/nolink/data", true) . '];');
$proc = proc_open([PHP_BINARY, '-d', 'disable_functions=link', '-r', 'require $argv[1]; MuDesk\Config::load($argv[2]); echo strlen(MuDesk\Crypto::appKey());',
    __DIR__ . '/../src/bootstrap.php', "$tmp/nolink/config.php"], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$keyLen = stream_get_contents($pipes[1]);
fclose($pipes[1]);
fclose($pipes[2]);
proc_close($proc);
check('server key created where link() is disabled', $keyLen, '32');

// audit, fresh folder: unchained lines at the start, lines removed at the end, a cut line, a value JSON can not hold
array_map('unlink', glob("$tmp/data/audit/*.jsonl") ?: []);
@unlink("$tmp/data/audit/.last");
@unlink("$tmp/data/audit/.legacy");
for ($i = 0; $i < 4; $i++) {
    MuDesk\Audit::log('conn_options', ['options' => ['audio' => true]], 'u' . $i, true);
}
$lines = file($day);
file_put_contents($day, preg_replace('/,"h":"[0-9a-f]{32}"\}$/', '}', $lines[0]) . implode('', array_slice($lines, 1)));
$head = MuDesk\Audit::verify()['problems'];
file_put_contents($day, implode('', array_slice($lines, 0, 3)));
$tail = MuDesk\Audit::verify()['problems'];
file_put_contents($day, implode('', $lines) . '{"t":"2026-01-01T00:00:00.000+00:00","ip":"1.2.3.4","user":"x","event":"login_o' . "\n");
try {
    $cut = MuDesk\Audit::search([])['total'];
} catch (Throwable $e) {
    $cut = get_class($e);
}
file_put_contents($day, implode('', $lines));
try {
    MuDesk\Audit::log('conn_options', ['options' => ['audio' => INF]], 'u', true);   // what json_decode('1e999') gives
    $logged = MuDesk\Audit::verify()['ok'];
} catch (Throwable $e) {
    $logged = get_class($e);
}
check('audit: a value JSON can not hold is still logged', $logged, true);
check('audit: unchained lines only for those of earlier versions', $head, [[date('Y-m-d'), 1, 'changed']]);
check('audit: lines removed at the end detected', $tail, [[date('Y-m-d'), 4, 'missing_after']]);
check('audit: a cut line does not break the search (the line itself is skipped)', $cut, 4);

// audit check while a line is being written: the end of the check is taken under the writers' lock (the half-written
// line was reported as changed). The writer is another process that holds the lock between the two halves.
$before = file_get_contents($day);
$lastBefore = file_get_contents("$tmp/data/audit/.last");
MuDesk\Audit::log('conn_options', ['options' => ['audio' => false]], 'writer', true);
file_put_contents("$tmp/line", substr(file_get_contents($day), strlen($before)));
file_put_contents("$tmp/last", file_get_contents("$tmp/data/audit/.last"));
file_put_contents($day, $before);
file_put_contents("$tmp/data/audit/.last", $lastBefore);
$proc = proc_open([PHP_BINARY, '-r', '[, $dir, $day, $line, $last] = $argv; $l = fopen("$dir/.lock", "c"); flock($l, LOCK_EX); $s = file_get_contents($line);'
    . ' file_put_contents($day, substr($s, 0, 40), FILE_APPEND); echo "half\n"; usleep(500000); file_put_contents($day, substr($s, 40), FILE_APPEND);'
    . ' copy($last, "$dir/.last"); flock($l, LOCK_UN);', "$tmp/data/audit", $day, "$tmp/line", "$tmp/last"], [1 => ['pipe', 'w']], $pipes);
$half = fgets($pipes[1]);
$v    = MuDesk\Audit::verify();
fclose($pipes[1]);
proc_close($proc);
check('audit: checked while a line is written, no false alarm', [$half, $v['ok'], $v['problems']], ["half\n", true, []]);

// audit export: the rows go out one by one (20,000 rows took about 45 MB kept together); peak memory of a separate process
mkdir("$tmp/exp/data/audit", 0700, true);
file_put_contents("$tmp/exp/config.php", '<?php return ["data_dir" => ' . var_export("$tmp/exp/data", true) . '];');
$fh = fopen("$tmp/exp/data/audit/" . date('Y-m-d') . '.jsonl', 'w');
for ($i = 0; $i < 20500; $i++) {
    fwrite($fh, json_encode(['t' => date('Y-m-d\TH:i:s.vP'), 'ip' => '203.0.113.' . ($i % 250), 'user' => 'user' . ($i % 40), 'event' => 'conn_update',
        'ok' => true, 'conn' => 'Server ' . $i, 'host' => '10.0.' . ($i % 250) . '.9', 'changes' => ['bpp' => [16, 32], 'gfx' => ['auto', 'on']],
        'ua' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36',
        'ws' => substr(md5((string)$i), 0, 12), 'p' => md5('p' . $i), 'h' => md5('h' . $i)]) . "\n");
}
fclose($fh);
$proc = proc_open([PHP_BINARY, '-r', 'require $argv[1]; MuDesk\Config::load($argv[2]); MuDesk\Audit::publicKey(); $m = memory_get_usage();'
    . ' $r = MuDesk\Audit::export([], static function (string $s): void {}, true); echo $r["rows"], " ", strlen((string)$r["sig"]) > 60 ? "signed" : "-", " ",'
    . ' memory_get_peak_usage() - $m;', __DIR__ . '/../src/bootstrap.php', "$tmp/exp/config.php"], [1 => ['pipe', 'w']], $pipes);
[$sent, $signed, $peak] = explode(' ', (string)stream_get_contents($pipes[1])) + ['', '', '0'];
fclose($pipes[1]);
proc_close($proc);
check('audit export: 20,000 rows sent one by one and signed, under 32 MB (80 MB kept together)', [$sent, $signed, (int)$peak < 32 << 20], ['20000', 'signed', true]);
// signed export: the exact bytes sent, their SHA-256 computed as they go out and signed with the server key made the first
// time (RSA 3072, PKCS#1 v1.5: what "openssl dgst -sha256 -verify" checks), behind exit; one byte changed and it no longer matches
$file = '';
$res  = MuDesk\Audit::export(['event' => 'conn_options'], static function (string $s) use (&$file): void {
    $file .= $s;
}, true);
$pub  = MuDesk\Audit::publicKey();
$doc  = json_decode($file, true);
check('audit export: signed file, its rows and totals, key behind exit', [openssl_verify($file, (string)$res['sig'], $pub, OPENSSL_ALGO_SHA256),
    openssl_verify(substr_replace($file, 'X', 20, 1), (string)$res['sig'], $pub, OPENSSL_ALGO_SHA256), $res['sha256'] === hash('sha256', $file),
    count($doc['rows'] ?? []) === $res['rows'] && $res['rows'] > 0 && !isset($doc['rows'][0]['_d']), $doc['total'] ?? null,
    str_starts_with((string)file_get_contents("$tmp/data/audit-key.php"), "<?php exit; ?>\n-----BEGIN PRIVATE KEY-----"),
    array_intersect_key(openssl_pkey_get_details(openssl_pkey_get_public($pub)), ['type' => 1, 'bits' => 1])],
    [1, 0, true, true, $res['rows'], true, ['bits' => 3072, 'type' => OPENSSL_KEYTYPE_RSA]]);

// sign-in from a known browser (device cookie): exempt from the user and global locks with a count and lock of its own,
// so a stolen cookie gives no more tries than the user lock; the 20 browsers used most recently are kept, a new one is
// audited; earlier versions kept a list of ids
$po  = 'Owner-' . bin2hex(random_bytes(6));
$uo  = MuDesk\Users::create('owner', $po, 'user', false);
$dev = static fn(string $id): string => $uo['id'] . '.' . $id . '.' . hash_hmac('sha256', $uo['id'] . '.' . $id, MuDesk\Crypto::appKey());
MuDesk\Users::update($uo['id'], static fn(array &$x) => $x['devices'] = ['mine']);
$_COOKIE = [];
for ($i = 0; $i < 5; $i++) {                                    // someone locks the user from other IPs
    $_SERVER['REMOTE_ADDR'] = '10.31.' . $i . '.1';
    $try('owner', 'wrong-guess-' . $i);
}
$_COOKIE['MUDESKD'] = $dev('mine');
$_SERVER['REMOTE_ADDR'] = '10.30.0.2';
$owner = $try('owner', $po);                                    // the owner's browser goes on
$prev1 = $_SESSION['previous'] ?? null;
for ($i = 0; $i < 5; $i++) {                                    // the same cookie stolen: its own failures
    $_SERVER['REMOTE_ADDR'] = '10.32.' . $i . '.1';
    $try('owner', 'wrong-guess-' . $i);
}
$_SERVER['REMOTE_ADDR'] = '10.30.0.3';
check('known browser: exempt from the user lock until its own lock', [$owner, $try('owner', $po)], ['ok', 'invalid']);
MuDesk\Users::update($uo['id'], static fn(array &$x) => $x['devices'] = ['old1', 'old2']);   // earlier versions: a list
$_COOKIE['MUDESKD'] = $dev('old2');
$_SERVER['REMOTE_ADDR'] = '10.30.0.4';
$old   = $try('owner', $po);
$prev2 = $_SESSION['previous'] ?? null;
$d     = MuDesk\Users::get($uo['id'])['devices'];
check('known browser: a cookie of the earlier list still known, the list kept with the last use', [$old, array_keys($d), ($d['old2']['used'] ?? 0) >= time() - 60],
    ['ok', ['old1', 'old2'], true]);
// after signing in, once: the previous sign-in and the failed attempts with the user name since then, from the audit log
check('sign-in: failures since the previous sign-in, and that sign-in; reading stops at its deadline', [[$prev1['login'], $prev1['failures'], $prev1['failed']['ip'] ?? null],
    [$prev2['ip'], $prev2['failures'], $prev2['failed']['ip'] ?? null, isset($prev2['login'])], MuDesk\Auth::takePrevious() !== null, MuDesk\Auth::takePrevious(),
    array_intersect_key(MuDesk\Audit::sinceLogin('owner', time() - 86400, hrtime(true) - 1), ['failures' => 1, 'partial' => 1])],
    [[null, 5, '10.31.4.1'], ['10.30.0.2', 6, '10.30.0.3', true], true, null, ['failures' => 0, 'partial' => true]]);
MuDesk\Users::update($uo['id'], static function (array &$x): void {
    $x['devices'] = ['k00' => time() - 5];                      // added first, used last
    for ($i = 1; $i < 20; $i++) {
        $x['devices'][sprintf('k%02d', $i)] = time() - 1000 + $i;
    }
});
$_COOKIE = [];
$_SERVER['REMOTE_ADDR'] = '10.30.0.5';
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36 Edg/140.0.0.0';
$try('owner', $po);
unset($_SERVER['HTTP_USER_AGENT']);
$d   = MuDesk\Users::get($uo['id'])['devices'];
$new = array_diff_key($d, array_flip(array_map(static fn($i) => sprintf('k%02d', $i), range(0, 19))));
check('known browser: over 20 the one used least recently is forgotten, each new one audited with its browser and address',
    [count($d), isset($d['k00']), isset($d['k01']), MuDesk\Audit::search(['event' => 'device_add', 'user' => 'owner'])['total'],
     array_intersect_key(current($new) ?: [], ['ua' => 1, 'ip' => 1])], [20, true, false, 1, ['ua' => 'Edge 140 · Windows', 'ip' => '10.30.0.5']]);
// the owner's list: this browser marked; one forgotten, then all the others (their cookies stop working)
$_COOKIE['MUDESKD'] = $dev('k00');
$list = MuDesk\Auth::browsers(MuDesk\Users::get($uo['id']));
$one  = MuDesk\Auth::forget(MuDesk\Users::get($uo['id']), 'k05');
$rest = MuDesk\Auth::forget(MuDesk\Users::get($uo['id']), null);
check('known browsers: listed for their owner, this one marked, forgotten one by one or all the others',
    [count($list), $list[0]['id'], $list[0]['current'], count(array_filter(array_column($list, 'current'))), array_keys($one), count($rest),
     array_keys(MuDesk\Users::get($uo['id'])['devices'])], [20, (string)key($new), false, 1, ['k05'], 18, ['k00']]);
// two used in the same second: this browser first (they came in any order, and the sign-in test failed now and then)
$now = time();
MuDesk\Users::update($uo['id'], static function (array &$x) use ($now): void {
    $x['devices'] = ['k01' => ['used' => $now], 'k00' => ['used' => $now], 'k02' => ['used' => $now - 1]];
});
check('known browsers: this one first among those used in the same second',
    array_column(MuDesk\Auth::browsers(MuDesk\Users::get($uo['id'])), 'id'), ['k00', 'k01', 'k02']);
$_COOKIE = [];
check('known browsers: a short name from the User-Agent', array_map([MuDesk\Http::class, 'browser'], [
    'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1',
    'Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/26.0 Chrome/122.0.0.0 Mobile Safari/537.36',
    'Mozilla/5.0 (X11; Linux x86_64; rv:132.0) Gecko/20100101 Firefox/132.0', 'curl/8.4']),
    ['Safari 18 · iPhone', 'Samsung Internet 26 · Android', 'Firefox 132 · Linux', '']);

// damaged data files: an error naming the file (it was an anonymous JSON error), an empty one too (no users would have meant
// a first run); the failed sign-ins start again (a damaged one stopped every sign-in), the damaged file kept aside. A store
// deleted under its lock, which stays
$damaged = static function (string $name, string $raw) use ($tmp): string {
    file_put_contents("$tmp/data/$name.json", $raw);
    try {
        return json_encode(MuDesk\Store::read($name));
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }
};
$rlKeep = (string)@file_get_contents("$tmp/data/ratelimit.json");
$elog   = ini_set('error_log', "$tmp/store-errors.log");
$dmg    = [$damaged('storetest', '{"a":'), $damaged('storetest', ''), $damaged('ratelimit', '{"ip:1'), count(glob("$tmp/data/ratelimit.json.damaged-*") ?: [])];
ini_set('error_log', (string)$elog);
file_put_contents("$tmp/data/ratelimit.json", $rlKeep);
unlink("$tmp/data/storetest.json");
MuDesk\Store::update('storetest', static function (array &$d): void { $d = ['a' => 1]; });
MuDesk\Store::delete('storetest');
check('data files: a damaged or empty one names itself, failed sign-ins start again; deleted under its lock',
    [$dmg, MuDesk\Store::read('storetest'), file_exists("$tmp/data/storetest.json"), file_exists("$tmp/data/storetest.json.lock")],
    [['Data file storetest.json damaged (Syntax error): restore it from a backup', 'Data file storetest.json damaged (empty file): restore it from a backup', '[]', 1],
     [], false, true]);

// the stores written on every failed sign-in or refused request compact; the others indented (read by a person)
MuDesk\Store::update('ratelimit', static function (array &$d): void { $d['x'] = ['n' => 1, 'first' => time(), 'until' => 0]; });
MuDesk\Store::update('auditlimit', static function (array &$d): void { $d['hour'] ??= 0; });
check('data files: the ones written often compact, the others indented', [str_contains((string)file_get_contents("$tmp/data/ratelimit.json"), "\n"),
    str_contains((string)file_get_contents("$tmp/data/auditlimit.json"), "\n"), str_contains((string)file_get_contents("$tmp/data/users.json"), "\n    ")], [false, false, true]);
MuDesk\Store::update('ratelimit', static function (array &$d): void { unset($d['x']); });
// ids of users and connections never all decimal: such a key ("1234567890123456", about 1 in 1,800 ids) came back from the
// data file as an integer, and a check of the user's connections failed now and then at GitHub. 20,000 ids: with the old
// ones, some 11 all-decimal; and such a key read back is an integer (why it matters)
$ids = array_map(static fn() => MuDesk\Store::newId(), range(1, 20000));
check('data files: new ids of 16 hex digits, never all decimal (such a key comes back as an integer)', [
    count(array_filter($ids, static fn($i) => preg_match('/^[0-9a-f]{16}$/', $i) === 1)), count(array_filter($ids, 'ctype_digit')),
    is_int(array_key_first(json_decode('{"1234567890123456":1}', true))), is_string(MuDesk\Users::create('idcheck', 'Id-check-user-1', 'user', false)['id'] ?? null)],
    [20000, 0, true, true]);

// TOTP: the code that has just turned it on (its step already used) is refused at sign-in with its own answer, wait for the next one
$pt  = 'Totp-' . bin2hex(random_bytes(6));
$ut  = MuDesk\Users::create('totpuser', $pt, 'user', false);
$sec = Totp::secret();
$now = intdiv(time(), 30);
MuDesk\Users::update($ut['id'], static fn(array &$x) => $x['totp'] = ['enabled' => true, 'last' => $now,
    'secret' => MuDesk\Crypto::encrypt(MuDesk\Crypto::appKey(), $sec, 'totp:' . $ut['id'])]);
$_SERVER['REMOTE_ADDR'] = '10.33.0.1';
check('TOTP: a used code answered apart from a wrong one, refused and not counted as a failure (only the wrong one is)', [$try('totpuser', $pt), $try('totpuser', '', Totp::code($sec, $now)),
    $try('totpuser', '', Totp::code($sec, $now + 9)), MuDesk\Store::read('ratelimit')['u:totpuser']['n'] ?? 0, $try('totpuser', '', Totp::code($sec, $now + 1)),
    array_column(MuDesk\Audit::search(['event' => 'login_fail', 'user' => 'totpuser'])['rows'], 'reason')],
    ['second', 'code_used', 'invalid', 1, 'ok', ['bad_code', 'code_used']]);
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

// RDP targets: with all allowed, never the gateway itself, link-local (cloud metadata) or unspecified addresses, also
// IPv4-mapped; private networks are allowed. In the list, a name rule (exact or *.domain) allows it whatever it resolves to.
$targets = static function (string $mode, array $list, array $hosts): array {
    MuDesk\Settings::save(['targets' => ['mode' => $mode, 'list' => $list]]);
    return array_map([Access::class, 'resolveTarget'], $hosts);
};
check('targets, all allowed: loopback, link-local and unspecified refused', $targets('all', [], ['127.0.0.1', 'localhost', '[::1]', '::ffff:127.0.0.1',
    '::ffff:7f00:1', '169.254.169.254', '::ffff:169.254.169.254', 'fe80::1', '0.0.0.0', '0.1.2.3', '::']), array_fill(0, 11, null));
check('targets, all allowed: private and public addresses', $targets('all', [], ['10.0.0.5', '172.16.0.1', '192.168.1.10', '8.8.8.8', 'fd00::1', '2001:db8::1']),
    ['10.0.0.5', '172.16.0.1', '192.168.1.10', '8.8.8.8', 'fd00::1', '2001:db8::1']);
check('targets, all allowed: IPv4 inside IPv6 (NAT64, compatible) and cloud metadata addresses refused', $targets('all', [], ['64:ff9b::7f00:1',
    '64:ff9b::a9fe:a9fe', '::7f00:1', '::a9fe:a9fe', '::0.0.0.5', 'fd00:ec2::254', '100.100.100.200', '64:ff9b::808:808', '100.100.100.201']),
    [null, null, null, null, null, null, null, '64:ff9b::808:808', '100.100.100.201']);
// a *.domain rule trusts the DNS of every name under it: one resolving to this computer (or link-local, cloud metadata) is
// refused, as with "Allow all"; the exact name listed reaches it. (Linux and this Windows resolve any *.localhost name to
// 127.0.0.1, which proves it; a system that resolves none only shows nothing is reached)
$mlh = (gethostbynamel('mudesk.localhost') ?: [null])[0];
check('targets, list: what is listed, loopback too; exact names before the IP check; *.domain never to this computer',
    [$targets('list', ['127.0.0.1', 'localhost'], ['127.0.0.1', 'LOCALHOST']),
     $targets('list', ['10.0.0.0/8', '*.localhost'], ['mudesk.localhost', 'localhost', '10.1.2.3', '11.0.0.1']),
     $targets('list', ['mudesk.localhost'], ['mudesk.localhost']), $mlh],
    [['127.0.0.1', '127.0.0.1'], [null, null, '10.1.2.3', null], [$mlh], $mlh === null ? null : '127.0.0.1']);
check('settings: a list where a number or a rule goes refused, nothing stored', [MuDesk\Settings::save(['login_max_attempts' => [1]]),
    MuDesk\Settings::save(['targets' => ['mode' => 'all', 'list' => [['x']]]]), MuDesk\Settings::save(['clients' => 'all']), MuDesk\Settings::get('targets')['mode']],
    ['invalid_input', 'invalid_input', 'invalid_input', 'list']);
MuDesk\Settings::save(['targets' => ['mode' => 'all', 'list' => []]]);
check('connections: a list where text or a number goes, or no computer, refused', [MuDesk\Connections::save('u-types', ['host' => ['pc']], null),
    MuDesk\Connections::save('u-types', ['host' => 'pc', 'port' => [3389]], null), MuDesk\Connections::save('u-types', ['host' => ' [] '], null),
    MuDesk\Store::read('connections/u-types')], ['invalid_input', 'invalid_input', 'host', []]);
// connections of the administrator: given to some users or to all (also users created later); their users connect with
// them but cannot change or delete them, and keep their own password, certificate and options for them; a new target
// (host, port, user, domain, security) forgets what users saved for the old one; deleted, what users kept goes too
$ua  = MuDesk\Users::create('mana', 'Mana-user-' . bin2hex(random_bytes(6)), 'user', false)['id'];
$ub  = MuDesk\Users::create('manb', 'Manb-user-' . bin2hex(random_bytes(6)), 'user', false)['id'];
$dek = random_bytes(32);
$mc  = static fn(array $more = []): array => $more + ['name' => 'Oficina', 'host' => 'pc.oficina', 'port' => 3389, 'user' => '', 'domain' => ''];
$m1  = MuDesk\Connections::saveManaged($mc(), [$ua, 'no-such-user']);
$m2  = MuDesk\Connections::saveManaged($mc(['name' => 'Para todos', 'host' => 'rds']), ['*']);
$uc  = MuDesk\Users::create('manc', 'Manc-user-' . bin2hex(random_bytes(6)), 'user', false)['id'];   // created later: sees the one for all
$seen = static fn(string $uid): array => array_values(array_map(static fn($c) => $c['name'] . (empty($c['managed']) ? '' : '*'),
    MuDesk\Connections::all($uid)));
check('managed connections: given to the users chosen or to all (also later users), unknown users left out',
    [$m1['users'], $m2['users'], str_starts_with($m1['id'], 'm'), $seen($ua), $seen($ub), $seen($uc)],
    [[$ua], ['*'], true, ['Oficina*', 'Para todos*'], ['Para todos*'], ['Para todos*']]);
$pw  = MuDesk\Connections::save($ua, ['id' => $m1['id'], 'password' => 'secreto-de-ana'], $dek);
MuDesk\Connections::setOptions($ua, $m1['id'], ['audio' => false, 'bpp' => 32]);
MuDesk\Connections::setCert($ua, $m1['id'], 'AA:BB');
$mine = MuDesk\Connections::get($ua, $m1['id']);
check('managed connections: the user keeps their own password, options and certificate; cannot change or delete it; others see none of it',
    [MuDesk\Connections::password($mine, $dek), $mine['audio'], $mine['bpp'], $mine['cert'], $mine['managed'] ?? false,
     MuDesk\Connections::save($ua, $mc(['id' => $m1['id'], 'host' => 'otro']), $dek), MuDesk\Connections::delete($ua, $m1['id']),
     MuDesk\Connections::save($ub, ['id' => $m1['id'], 'password' => 'x'], $dek), MuDesk\Connections::managed()[$m1['id']]['audio'] ?? true,
     is_array($pw)], ['secreto-de-ana', false, 32, 'AA:BB', true, 'managed', false, 'not_found', true, true]);
MuDesk\Connections::saveManaged($mc(['id' => $m1['id'], 'name' => 'Oficina 2']), [$ua]);        // renamed: what she saved stays
$renamed = MuDesk\Connections::get($ua, $m1['id']);
MuDesk\Connections::saveManaged($mc(['id' => $m1['id'], 'name' => 'Oficina 2', 'host' => 'otro.equipo']), [$ua]);   // another target
$moved = MuDesk\Connections::get($ua, $m1['id']);
check('managed connections: renamed, the user keeps their password; another target forgets it and the certificate (options stay)',
    [$renamed['name'], MuDesk\Connections::password($renamed, $dek), $moved['host'], $moved['pass'], $moved['cert'], $moved['audio']],
    ['Oficina 2', 'secreto-de-ana', 'otro.equipo', null, '', false]);
// the remote user changed from "0123" to "123" (another person's account): compared loosely, those texts were equal, and
// the saved password and the pinned certificate went on to the other account; also for a user's own connection
$num = MuDesk\Connections::saveManaged(['host' => 'srv1.corp.local', 'user' => '0123'], [$ua]);
MuDesk\Connections::save($ua, ['id' => $num['id'], 'password' => 'pw-de-0123'], $dek);
MuDesk\Connections::saveManaged(['id' => $num['id'], 'host' => 'srv1.corp.local', 'user' => '123'], [$ua]);
$ownN = MuDesk\Connections::save($ua, ['host' => 'srv2', 'user' => '007', 'password' => 'pw-de-007'], $dek);
MuDesk\Connections::adminUpdate($ua, ['id' => $ownN['id'], 'host' => 'srv2', 'user' => '7'], false);
check('connections: a remote user changed from "0123" to "123" (or "007" to "7") forgets the saved password',
    [MuDesk\Connections::password(MuDesk\Connections::get($ua, $num['id']), $dek), MuDesk\Connections::password(MuDesk\Connections::get($ua, $ownN['id']), $dek)],
    [null, null]);
// a user taken out of a connection of the administrator: what they kept of it goes (their password stayed, and came back
// when they were given it again); a user still in it keeps theirs
$two = MuDesk\Connections::saveManaged(['host' => 'srv3.corp.local', 'user' => 'u'], [$ua, $ub]);
MuDesk\Connections::save($ua, ['id' => $two['id'], 'password' => 'pw-a'], $dek);
MuDesk\Connections::save($ub, ['id' => $two['id'], 'password' => 'pw-b'], $dek);
MuDesk\Connections::saveManaged(['id' => $two['id'], 'host' => 'srv3.corp.local', 'user' => 'u'], [$ub]);   // $ua taken out
MuDesk\Connections::saveManaged(['id' => $two['id'], 'host' => 'srv3.corp.local', 'user' => 'u'], [$ua, $ub]);   // and given it again
check('managed connections: a user taken out of one forgets what they kept of it; the others keep theirs',
    [isset(MuDesk\Store::read('connections/' . $ua)[$two['id']]['pass']), MuDesk\Connections::password(MuDesk\Connections::get($ub, $two['id']), $dek)],
    [false, 'pw-b']);
MuDesk\Connections::deleteManaged($two['id']);
MuDesk\Connections::deleteManaged($num['id']);
MuDesk\Connections::delete($ua, $ownN['id']);
MuDesk\Connections::save($ua, ['id' => $m1['id'], 'password' => 'otra'], $dek);
$forgot = MuDesk\Connections::forgetPasswords($ua);
MuDesk\Connections::setOptions($ua, $m1['id'], ['audio' => true]);
$gone = MuDesk\Connections::deleteManaged($m1['id']);
check('managed connections: forgetting all passwords includes them; deleted, it goes with what each user kept of it',
    [$forgot, $gone['name'] ?? null, $seen($ua), isset(MuDesk\Store::read('connections/' . $ua)[$m1['id']])], [1, 'Oficina 2', ['Para todos*'], false]);
// a user's own connection changed by the administrator: the user's password cannot be set, only forgotten, and goes by itself
// with another target (it must never reach another server); a managed one is not theirs to change this way
$own = MuDesk\Connections::save($ub, $mc(['name' => 'Mía', 'host' => 'casa', 'password' => 'clave-de-b']), $dek);
$a1  = MuDesk\Connections::adminUpdate($ub, $mc(['id' => $own['id'], 'name' => 'Mía (revisada)', 'host' => 'casa', 'password' => 'puesta-por-admin']), false);
$p1  = MuDesk\Connections::password(MuDesk\Connections::get($ub, $own['id']), $dek);
$a2  = MuDesk\Connections::adminUpdate($ub, $mc(['id' => $own['id'], 'name' => 'Mía', 'host' => 'otra-casa']), false);
check('admin changes a user\'s own connection: the password stays (never set by them), goes with another target or when forgotten',
    [$a1['name'], $p1, $a2['pass'], MuDesk\Connections::adminUpdate($ub, $mc(['id' => $m2['id']]), false), array_keys(MuDesk\Connections::own($ub)) === [$own['id']]],
    ['Mía (revisada)', 'clave-de-b', null, 'not_found', true]);
MuDesk\Connections::forgetUser($ub);
MuDesk\Connections::saveManaged($mc(['id' => $m2['id'], 'name' => 'Para todos', 'host' => 'rds']), [$ub, $uc]);
MuDesk\Connections::forgetUser($ub);
check('managed connections: a deleted user is taken out of them; users must be a list',
    [MuDesk\Connections::managed()[$m2['id']]['users'], MuDesk\Connections::saveManaged($mc(), 'todos'), MuDesk\Connections::saveManaged($mc(), ['x' => '*'])],
    [[$uc], 'invalid_input', 'invalid_input']);
MuDesk\Connections::deleteManaged($m2['id']);
// saving RDP passwords: allowed by default; turned off, no password can be saved (forgetting one can), and the saved ones
// of every user are erased (the administration API does it when the setting goes off)
$pu1 = MuDesk\Users::create('pwone', 'Pw-one-' . bin2hex(random_bytes(6)), 'user', false);
$pu2 = MuDesk\Users::create('pwtwo', 'Pw-two-' . bin2hex(random_bytes(6)), 'user', false);
$pdek = random_bytes(32);
$byDefault = MuDesk\Settings::get('save_passwords');
$pc1 = MuDesk\Connections::save($pu1['id'], ['host' => 'pc1', 'password' => 'Remote-1'], $pdek)['id'];
MuDesk\Connections::save($pu1['id'], ['host' => 'pc2', 'password' => 'Remote-2'], $pdek);
MuDesk\Connections::save($pu2['id'], ['host' => 'pc3', 'password' => 'Remote-3'], $pdek);
MuDesk\Settings::save(['save_passwords' => false]);
$refused = MuDesk\Connections::save($pu1['id'], ['id' => $pc1, 'host' => 'pc1', 'password' => 'Remote-9'], $pdek);
$forgot  = MuDesk\Connections::save($pu1['id'], ['id' => $pc1, 'host' => 'pc1', 'password' => ''], $pdek);
$erased  = MuDesk\Connections::forgetAllPasswords();
$left    = array_filter(MuDesk\Connections::all($pu1['id']) + MuDesk\Connections::all($pu2['id']), static fn(array $c): bool => $c['pass'] !== null);
MuDesk\Settings::save(['save_passwords' => true]);
check('saving RDP passwords turned off: none saved, one can be forgotten, the saved ones of every user erased',
    [$byDefault, $refused, is_array($forgot) && $forgot['pass'] === null, $erased, count($left)], [true, 'save_passwords_off', true, 2, 0]);

// virtual printer (after the data folder tests: it writes to the audit of the test copy)
$frames = [];
$client = new Client(['width' => 800, 'height' => 600], static function (int $t, string $p) use (&$frames): void {
    $frames[] = [$t, $p];
});
$mem = fopen('php://memory', 'w+b');
$tr  = (new ReflectionClass(Transport::class))->newInstanceWithoutConstructor();
$tr->sock = $mem;
$client->t = $tr;
foreach (['channelIds' => ['rdpdr' => 1004], 'channelOpts' => ['rdpdr' => 0x80800000]] as $prop => $val) {
    (new ReflectionProperty(Client::class, $prop))->setValue($client, $val);
}
$dir = sys_get_temp_dir() . '/mudesk-test-' . bin2hex(random_bytes(4));
$dev = new Rdpdr($client, 'test', false, true, $dir);
$irp = static fn(int $file, int $cid, int $major, string $rest) => pack('vv', 0x4472, 0x4952) . pack('VVVVV', 2, $file, $cid, $major, 0) . $rest;
$dev->onData(pack('vvvvV', 0x4472, 0x4343, 1, 12, 7));                 // client id confirm -> device list
$dev->onData($irp(0, 1, 0x00, str_repeat("\0", 32)));                     // create
$dev->onData($irp(1, 2, 0x04, pack('VP', 13, 0) . str_repeat("\0", 20) . '%PDF-1.7 test')); // write
$dev->onData($irp(1, 3, 0x02, str_repeat("\0", 32)));                     // close
rewind($mem);
$out = (string)stream_get_contents($mem);
check('printer announced as MuDeskPDF', str_contains($out, Text::toUtf16('MuDeskPDF')) && str_contains($out, 'PRN2'), true);
check('print job reported', $frames[0][0] ?? null, Client::F_PRINT);
check('print job content', [json_decode($frames[0][1] ?? '{}', true), @file_get_contents($dir . '/print-1')], [['job' => 1, 'size' => 13, 'type' => 'pdf'], '%PDF-1.7 test']);
check('print job audited', MuDesk\Audit::search(['event' => 'print_job'])['rows'][0]['size'] ?? null, 13);
MuDesk\Files::remove($dir);
// printer capability (CAP_PRINTER_TYPE, version 1) sent with the printer; all the print jobs of a session together up to the limit
$st = static function (Rdpdr $d, string $irp) use ($mem): int {             // status of the answer to one request
    ftruncate($mem, 0);
    rewind($mem);
    $d->onData($irp);
    rewind($mem);
    $o = (string)stream_get_contents($mem);
    return unpack('V', $o, (int)strrpos($o, pack('vv', 0x4472, 0x4943)) + 12)[1];
};
ftruncate($mem, 0);
rewind($mem);
$dev = new Rdpdr($client, 'test', false, true, $dir);
$dev->onData(pack('vvvv', 0x4472, 0x5350, 0, 0));                         // server capabilities -> ours
rewind($mem);
$caps = (string)stream_get_contents($mem);
$caps = substr($caps, (int)strpos($caps, pack('vv', 0x4472, 0x4350)) + 4);
check('printer capability announced', [unpack('v', $caps)[1], substr($caps, 48, 8) === pack('vvV', 2, 8, 1)], [3, true]);
(new ReflectionProperty(Rdpdr::class, 'printed'))->setValue($dev, 200 * 1048576 - 8);   // earlier jobs of the session
$dev->onData($irp(0, 4, 0x00, str_repeat("\0", 32)));
check('print jobs of a session limited together', $st($dev, $irp(1, 5, 0x04, pack('VP', 13, 0) . str_repeat("\0", 20) . '%PDF-1.7 test')), 0xC000007F);
MuDesk\Files::remove($dir);

// shared drive announce: Windows reads the drive name (DeviceData) as 8-bit text (in UTF-16 it showed "M") and shows it as
// "<drive> on <client name>": "Carpeta compartida en MuDesk", in the language of the page (before: "MuDesk en MUDESK")
$announced = static function (string ...$name) use ($client, $mem, $dir): string {
    ftruncate($mem, 0);
    rewind($mem);
    $drv = new Rdpdr($client, 'audtest', true, false, $dir, '', '', ...$name);
    $drv->onData(pack('vvvvV', 0x4472, 0x496E, 1, 12, 7));   // the server's announce: the client's name
    $drv->onData(pack('vvvvV', 0x4472, 0x4343, 1, 12, 7));   // its client id confirmed: the devices
    rewind($mem);
    return (string)stream_get_contents($mem);
};
[$es, $en, $odd] = [$announced(), $announced('Shared folder'), $announced('Carpeta ñ compartida')];
check('shared drive shown as "Carpeta compartida en MuDesk" (or "Shared folder on MuDesk"), its name 8-bit text without accents', [
    str_contains($es, "MUDESK\0\0" . pack('V', 19) . "Carpeta compartida\0"), str_contains($es, MuDesk\Text::toUtf16('MuDesk') . "\0\0"),
    str_contains($en, "MUDESK\0\0" . pack('V', 14) . "Shared folder\0"), str_contains($odd, pack('V', 20) . "Carpeta  compartida\0")],
    [true, true, true, true]);
ftruncate($mem, 0);
rewind($mem);
$drv = new Rdpdr($client, 'audtest', true, false, $dir);
$drv->onData(pack('vvvvV', 0x4472, 0x4343, 1, 12, 7));

// shared drive: every file operation audited with its path and bytes, metadata-only opens are not
$dirp = static fn(int $file, int $cid, int $major, string $rest) => pack('vv', 0x4472, 0x4952) . pack('VVVVV', 1, $file, $cid, $major, 0) . $rest;
$open = static fn(string $path, int $access, int $disp, int $opts) => pack('VPVVVV', $access, 0, 0, 7, $disp, $opts)
    . pack('V', strlen(Text::toUtf16($path) . "\0\0")) . Text::toUtf16($path) . "\0\0";
$drv->onData($dirp(0, 1, 0x00, $open('\docs', 0x1, 2, 0x1)));            // 1: mkdir
$drv->onData($dirp(1, 2, 0x02, ''));
$drv->onData($dirp(0, 3, 0x00, $open('\docs\a.txt', 0x2, 2, 0x40)));     // 2: new file, write 5 bytes
$drv->onData($dirp(2, 4, 0x04, pack('VP', 5, 0) . str_repeat("\0", 20) . 'hello'));
$drv->onData($dirp(2, 5, 0x02, ''));
$drv->onData($dirp(0, 6, 0x00, $open('\docs\a.txt', 0x1, 1, 0x40)));     // 3: read it
$drv->onData($dirp(3, 7, 0x03, pack('VP', 100, 0)));
$drv->onData($dirp(3, 8, 0x02, ''));
$drv->onData($dirp(0, 9, 0x00, $open('\docs\a.txt', 0x80, 1, 0x40)));    // 4: attributes only
$drv->onData($dirp(4, 10, 0x02, ''));
$to = Text::toUtf16('\docs\b.txt') . "\0\0";
$drv->onData($dirp(0, 11, 0x00, $open('\docs\a.txt', 0x10000, 1, 0x40))); // 5: rename
$drv->onData($dirp(5, 12, 0x06, pack('VV', 10, 6 + strlen($to)) . str_repeat("\0", 24) . "\0\0" . pack('V', strlen($to)) . $to));
$drv->onData($dirp(5, 13, 0x02, ''));
$drv->onData($dirp(0, 14, 0x00, $open('\docs\b.txt', 0x10000, 1, 0x1040))); // 6: delete on close
$drv->onData($dirp(6, 15, 0x02, ''));
$ops = array_map(static fn($r) => [$r['event'], $r['path'] ?? $r['from'] . '>' . $r['to'], $r['read'] ?? null, $r['written'] ?? null],
    array_reverse(MuDesk\Audit::search(['event' => 'drive_'])['rows']));
check('drive operations audited', $ops, [['drive_mkdir', '/docs', null, null], ['drive_file', '/docs/a.txt', 0, 5], ['drive_file', '/docs/a.txt', 5, 0],
    ['drive_rename', '/docs/a.txt>/docs/b.txt', null, null], ['drive_delete', '/docs/b.txt', null, null]]);
// directory listing as FileBothDirectoryInformation: Windows reads it without the Reserved byte after ShortNameLength (93-byte header,
// as FreeRDP); with it, Windows could not open the drive ("invalid address")
$drv->onData($dirp(0, 16, 0x00, $open('\\', 0x100001, 1, 0x21)));        // 7: the root
ftruncate($mem, 0);
rewind($mem);
$drv->onData(pack('vv', 0x4472, 0x4952) . pack('VVVVV', 1, 7, 17, 0x0C, 1) . pack('VCV', 3, 1, 6) . str_repeat("\0", 23) . Text::toUtf16('\*') . "\0\0");
rewind($mem);
$out = (string)stream_get_contents($mem);
$rsp = substr($out, (int)strrpos($out, pack('vv', 0x4472, 0x4943)) + 16);
check('drive listing: FileBothDirectoryInformation layout', [unpack('V', $rsp)[1], unpack('V', $rsp, 64)[1], substr($rsp, 97, 2)], [95, 2, ".\0"]);
// drive off (printer only): the server's drive requests are refused, the user's folder stays out of reach
$prn = new Rdpdr($client, 'audtest', false, true, $dir);
check('drive off: its requests refused', [$st($prn, $dirp(0, 18, 0x00, $open('\docs', 0x1, 1, 0x21))),
    $st($prn, $dirp(0, 19, 0x00, $open('\planted.exe', 0x2, 2, 0x40))), is_file(MuDesk\Files::root('audtest') . '/planted.exe')], [0xC000000E, 0xC000000E, false]);
// drive quota (1 MB): offsets of 2^63 and more (negative in PHP) and an end of file past the quota refused, a deleted file gives
// its quota back, two sessions of the user share it (each one counted it on its own: together they could pass it), and with no
// quota (0) an existing file can be overwritten
MuDesk\Settings::save(['files_quota_mb' => 1]);
$q   = new Rdpdr($client, 'quotatest', true, false, $dir);
$q2  = new Rdpdr($client, 'quotatest', true, false, $dir);                  // another session of the same user at the same time
$fid = static function () use ($mem): int {                              // file id of the last create answered
    rewind($mem);
    $o = (string)stream_get_contents($mem);
    return unpack('V', $o, (int)strrpos($o, pack('vv', 0x4472, 0x4943)) + 16)[1];
};
$put = static function (Rdpdr $d, string $name, int $bytes) use ($st, $dirp, $open, $fid): int {   // status of writing a new file
    $st($d, $dirp(0, 20, 0x00, $open($name, 0x2, 2, 0x40)));
    $f = $fid();
    $s = $st($d, $dirp($f, 21, 0x04, pack('VP', $bytes, 0) . str_repeat("\0", 20) . str_repeat('a', $bytes)));
    $st($d, $dirp($f, 22, 0x02, ''));
    return $s;
};
$st($q, $dirp(0, 20, 0x00, $open('\q.bin', 0x2, 2, 0x40)));
$f = $fid();
$st($q, $dirp($f, 21, 0x04, pack('VP', 600000, 0) . str_repeat("\0", 20) . str_repeat('a', 600000)));
$neg = $st($q, $dirp($f, 22, 0x04, pack('VVV', 60000, 0xffffffff, 0xffffffff) . str_repeat("\0", 20) . str_repeat('a', 60000)));
$eof = $st($q, $dirp($f, 23, 0x06, pack('VV', 20, 8) . str_repeat("\0", 24) . pack('P', 50 * 1048576)));
$st($q, $dirp($f, 24, 0x02, ''));
clearstatcache();
check('drive quota: offsets past 2^63 and end of file past the quota refused', [$neg, $eof, filesize(MuDesk\Files::root('quotatest') . '/q.bin')],
    [0xC000000D, 0xC000007F, 600000]);
$st($q, $dirp(0, 25, 0x00, $open('\q.bin', 0x10000, 1, 0x1040)));         // delete on close
$st($q, $dirp($fid(), 26, 0x02, ''));
check('drive quota: a deleted file gives its quota back', $put($q, '\r.bin', 600000), 0);
clearstatcache();
check('drive quota: two sessions of the same user together stay within it', [$put($q2, '\s.bin', 600000), $put($q2, '\t.bin', 400000),
    MuDesk\Files::usage('quotatest') <= 1048576], [0xC000007F, 0, true]);
$q->release();
$q2->release();
MuDesk\Settings::save(['files_quota_mb' => 0]);
$q    = new Rdpdr($client, 'quotatest', true, false, $dir);
$over = $st($q, $dirp(0, 30, 0x00, $open('\r.bin', 0x40000000, 5, 0x40)));  // FILE_OVERWRITE_IF, as when saving over a file
$st($q, $dirp($fid(), 31, 0x02, ''));
clearstatcache();
check('drive quota unlimited: an existing file is overwritten', [$over, filesize(MuDesk\Files::root('quotatest') . '/r.bin')], [0, 0]);
MuDesk\Settings::save(['files_quota_mb' => 500]);
MuDesk\Files::removeAll('quotatest');
// drive: a rename to the same file under another case gives no quota back (it made the quota endless: the "replaced" file was
// itself); a session opens at most 1024 handles and creates at most 20,000 entries; a mask with more than 4 '*' matches
// nothing (its regular expression backtracked for every name); at most 2,000 audit lines a session, then one line saying so
MuDesk\Settings::save(['files_quota_mb' => 1]);
$q  = new Rdpdr($client, 'aliastest', true, false, $dir);
$put($q, '\Big.bin', 600000);
$to = Text::toUtf16(PHP_OS_FAMILY === 'Windows' ? '\big.bin' : '\Big.bin') . "\0\0";   // (the same file: another case where names ignore it)
$st($q, $dirp(0, 40, 0x00, $open('\Big.bin', 0x10000, 1, 0x40)));
$rf = $fid();
$ren = $st($q, $dirp($rf, 41, 0x06, pack('VV', 10, 6 + strlen($to)) . str_repeat("\0", 24) . "\1\0" . pack('V', strlen($to)) . $to));
$st($q, $dirp($rf, 42, 0x02, ''));
clearstatcache();
check('drive quota: a rename to the same file under another case gives nothing back', [$ren, MuDesk\Files::usage('aliastest'), $put($q, '\c.bin', 600000)],
    [0, 600000, 0xC000007F]);
$q->release();
MuDesk\Settings::save(['files_quota_mb' => 500]);
$h  = new Rdpdr($client, 'aliastest', true, false, $dir);
$hs = [];
for ($i = 0; $i < 1025; $i++) {
    $hs[] = $st($h, $dirp(0, 50, 0x00, $open('\\', 0x100001, 1, 0x21)));
}
$st($h, $dirp(1, 51, 0x02, ''));                                         // one closed: room for another
check('drive: at most 1024 handles open', [count(array_filter(array_slice($hs, 0, 1024), static fn($s) => $s === 0)), $hs[1024],
    $st($h, $dirp(0, 52, 0x00, $open('\\', 0x100001, 1, 0x21)))], [1024, 0xC000011F, 0]);
$h->release();
$c = new Rdpdr($client, 'aliastest', true, false, $dir);
(new ReflectionProperty(Rdpdr::class, 'created'))->setValue($c, 20000);
$made = $st($c, $dirp(0, 53, 0x00, $open('\new.txt', 0x2, 2, 0x40)));
check('drive: at most 20,000 new entries a session (an existing file still opens)', [$made, is_file(MuDesk\Files::root('aliastest') . '/new.txt'),
    $st($c, $dirp(0, 54, 0x00, $open('\c.bin', 0x1, 1, 0x40)))], [0xC0000022, false, 0]);
$c->release();
$m = new Rdpdr($client, 'aliastest', true, false, $dir);
touch(MuDesk\Files::root('aliastest') . '/a.b.c.d.e');
$query = static function (string $mask) use ($m, $st, $dirp, $open, $fid): int {
    $st($m, $dirp(0, 55, 0x00, $open('\\', 0x100001, 1, 0x21)));
    $d = $fid();
    $p = Text::toUtf16('\\' . $mask) . "\0\0";
    $s = $st($m, pack('vv', 0x4472, 0x4952) . pack('VVVVV', 1, $d, 56, 0x0C, 1) . pack('VCV', 3, 1, strlen($p)) . str_repeat("\0", 23) . $p);
    $st($m, $dirp($d, 57, 0x02, ''));
    return $s;
};
check('drive: a mask with more than 4 "*" matches nothing', [$query('*.*.*.*e'), $query('*.*.*.*.*')], [0, 0x80000006]);
$m->release();
$a = new Rdpdr($client, 'aliastest', true, false, $dir);
(new ReflectionProperty(Rdpdr::class, 'audited'))->setValue($a, 1999);
foreach (['\cut1', '\cut2', '\cut3'] as $i => $n) {
    $st($a, $dirp(0, 60 + $i, 0x00, $open($n, 0x1, 2, 0x1)));
    $st($a, $dirp($fid(), 63 + $i, 0x02, ''));
}
$a->release();
$cut = array_column(MuDesk\Audit::search(['event' => 'drive_'])['rows'], 'event', 'path');
check('drive: at most 2,000 audit lines a session, then one line saying so', [isset($cut['/cut1']), isset($cut['/cut2']), isset($cut['/cut3']),
    count(MuDesk\Audit::search(['event' => 'drive_audit_cut'])['rows'])], [true, false, false, 1]);
MuDesk\Files::removeAll('aliastest');
// drive read at offsets past the end or of 2^63 and more (negative in PHP): no data from another place, no notice (fseek() was
// not checked: Windows refused the read near 2^63, elsewhere the data came from the previous position) [tests/fuzz.php]
$notes = [];
set_error_handler(static function (int $no, string $msg) use (&$notes): bool {
    $notes[] = $msg;
    return true;
});
$rd = new Rdpdr($client, 'readtest', true, false, $dir);
file_put_contents(MuDesk\Files::root('readtest') . '/rd.txt', 'hello');
$st($rd, $dirp(0, 40, 0x00, $open('\rd.txt', 0x1, 1, 0x40)));
$f     = $fid();
$reads = [];
foreach ([[0, 2], [0, 5], [0, 6], [0x7fffffff, 0xffffffff], [0xffffffff, 0xffffffff]] as [$hi, $lo]) {
    $reads[] = [$st($rd, $dirp($f, 41, 0x03, pack('VVV', 100, $lo, $hi))), $fid()];   // [status, bytes read]
}
$st($rd, $dirp($f, 42, 0x02, ''));
// writes ending past 2^63 (the session ended with a TypeError) or at a position the system refuses: refused, nothing written
$st($rd, $dirp(0, 43, 0x00, $open('\rd.txt', 0x2, 1, 0x40)));
$wf     = $fid();
$writes = [];
foreach ([[0x7fffffff, 0xfffffffe], [0, 5]] as [$hi, $lo]) {
    try {
        $writes[] = $st($rd, $dirp($wf, 44, 0x04, pack('VVV', 4, $lo, $hi) . str_repeat("\0", 20) . 'more'));
    } catch (Throwable $e) {
        $writes[] = get_class($e);
    }
}
$st($rd, $dirp($wf, 45, 0x02, ''));
restore_error_handler();
check('drive read: past the end nothing (also at the end), 2^63 and more refused, no notice', [$reads, $notes], [[[0, 3], [0, 0], [0, 0], [0, 0], [0xC000000D, 0]], []]);
check('drive write: its end past 2^63 refused (no TypeError), nothing written there, the file as it was',
    [$writes, (string)file_get_contents(MuDesk\Files::root('readtest') . '/rd.txt')], [[0xC000000D, 0], 'hellomore']);

// a link in a user's folder to a folder out of it (nothing of MuDeskClient makes one: a restored backup, a program on the
// server): never followed, over the web or from the remote session; deleting the folder that holds it, its ZIP and the
// quota leave what it leads to alone. A symbolic link, or on Windows (no right to make one) a junction, which is_link() misses
$lroot = MuDesk\Files::root('linktest');
$lout  = "$tmp/outside";
@mkdir($lout);
file_put_contents("$lout/secret.txt", 'outside');
file_put_contents("$lroot/own.txt", 'own');
@mkdir("$lroot/box");
$link = static function (string $at) use ($lout): bool {
    if (@symlink($lout, $at)) {
        return true;
    }
    if (PHP_OS_FAMILY !== 'Windows') {
        return false;
    }
    exec('cmd /c mklink /J ' . escapeshellarg(strtr($at, '/', '\\')) . ' ' . escapeshellarg(strtr($lout, '/', '\\')) . ' 2>NUL', $o, $rc);
    return $rc === 0;
};
$made = $link("$lroot/lnk") && $link("$lroot/box/in");
$lk   = new Rdpdr($client, 'linktest', true, false, $dir);
$lks  = [$st($lk, $dirp(0, 50, 0x00, $open('\lnk\secret.txt', 0x1, 1, 0x40))), $st($lk, $dirp(0, 51, 0x00, $open('\lnk\new.txt', 0x2, 2, 0x40))),
         $st($lk, $dirp(0, 52, 0x00, $open('\own.txt', 0x1, 1, 0x40)))];
$lgot = !$made ? 'no link could be made here' : [MuDesk\Files::path('linktest', 'lnk/secret.txt'), MuDesk\Files::path('linktest', 'lnk'),
    MuDesk\Files::list('linktest', 'lnk'), MuDesk\Files::move('linktest', 'own.txt', 'lnk/own.txt'), MuDesk\Files::mkdirs('linktest', '', ['lnk/sub']),
    $lks, file_exists("$lout/new.txt") || file_exists("$lout/sub") || file_exists("$lout/own.txt"),
    array_column(MuDesk\Files::zipEntries('linktest', '', ['box']) ?: [], 0), MuDesk\Files::size($lroot) === 3,
    MuDesk\Files::path('linktest', 'own.txt') === "$lroot/own.txt"];
if ($made) {
    MuDesk\Files::remove("$lroot/box");
    $lgot[] = [is_dir("$lroot/box"), (string)@file_get_contents("$lout/secret.txt")];
}
check('shared folder: a link out of it never followed (web, remote drive, delete, ZIP, quota), the rest as before', $lgot,
    !$made ? 'no link could be made here' : [null, null, null, 'invalid_path', 'invalid_name', [0xC0000022, 0xC0000022, 0], false, ['box/'], true, true, [false, 'outside']]);
MuDesk\Files::removeAll('readtest');

// audio formats answered in the server's order (Windows 11 never sent audio when the client moved IMA ADPCM before PCM)
(new ReflectionProperty(Client::class, 'channelIds'))->setValue($client, ['rdpsnd' => 1005]);
(new ReflectionProperty(Client::class, 'channelOpts'))->setValue($client, ['rdpsnd' => 0xC0000000]);
ftruncate($mem, 0);
rewind($mem);
$pcm  = pack('vvVVvvv', 1, 2, 44100, 176400, 4, 16, 0);
$ima  = pack('vvVVvvvv', 0x11, 2, 44100, 44251, 2048, 4, 2, 2041);
$body = pack('VVVvvCvC', 0, 0, 0, 0, 3, 0xff, 8, 0) . $pcm . pack('vvVVvvv', 0xA106, 2, 44100, 24000, 4, 16, 0) . $ima;
(new MuDesk\Rdp\Rdpsnd($client))->onData(pack('CCv', 7, 0, strlen($body)) . $body);
rewind($mem);
$out = (string)stream_get_contents($mem);
check('audio formats in the server order', [str_contains($out, $pcm . $ima), str_contains($out, pack('vv', 0xA106, 2))], [true, false]);
// formats the page cannot play are not taken: no channels, 3 channels, a rate of 0 or past the Web Audio API, an IMA ADPCM
// block of 0, odd or shorter than its headers (the page threw on every chunk of them, or decoded garbage)
ftruncate($mem, 0);
rewind($mem);
$alaw = pack('vvVVvvv', 6, 1, 8000, 8000, 1, 8, 0);
$bad  = [pack('vvVVvvv', 1, 0, 44100, 0, 0, 16, 0), pack('vvVVvvv', 7, 3, 8000, 24000, 3, 8, 0), pack('vvVVvvv', 1, 2, 0, 0, 4, 16, 0),
         pack('vvVVvvv', 6, 1, 1000000, 1000000, 1, 8, 0), pack('vvVVvvvv', 0x11, 1, 22050, 11100, 0, 4, 2, 0),
         pack('vvVVvvvv', 0x11, 2, 22050, 11100, 2047, 4, 2, 0), pack('vvVVvvvv', 0x11, 2, 22050, 11100, 8, 4, 2, 0)];
$body = pack('VVVvvCvC', 0, 0, 0, 0, 2 + count($bad), 0xff, 8, 0) . $pcm . implode('', $bad) . $alaw;
(new MuDesk\Rdp\Rdpsnd($client))->onData(pack('CCv', 7, 0, strlen($body)) . $body);
rewind($mem);
$out = (string)stream_get_contents($mem);
check('audio formats the page cannot play not taken', [str_contains($out, $pcm . $alaw), array_map(static fn($b) => str_contains($out, $b), $bad)],
    [true, array_fill(0, count($bad), false)]);

// client core data: connection type LAN with its "valid" early capability flag (Windows sent about 20 % fewer frames without it)
$cl = new Client(['width' => 800, 'height' => 600, 'bpp' => 16, 'kbd' => 0x40A, 'clientName' => 'test', 'channels' => [], 'monitors' => []], static fn() => null);
$cl->t = $tr;
ftruncate($mem, 0);
rewind($mem);
try {
    (new ReflectionMethod(Client::class, 'mcsConnect'))->invoke($cl, 2);  // stops waiting for the answer
} catch (MuDesk\Rdp\ProtocolException) {
}
rewind($mem);
$out  = (string)stream_get_contents($mem);
$core = substr($out, (int)strpos($out, pack('v', 0xC001), (int)strpos($out, 'Duca')) + 4);
check('client core data: connection type LAN', [unpack('v', $core, 140)[1] & 0x20, ord($core[206])], [0x20, 6]);

// typed text: the gateway sends one character at a time and what comes next waits its pause, in order (Windows 11 apps mixed up
// characters that the browser had spaced out, because HTTP requests arrive unevenly)
$cl->active = true;
ftruncate($mem, 0);
rewind($mem);
$cl->input("\x00\x1e\x01\x1e", 2, 60);                                  // 'a' then 60 ms
$cl->input("\x00\x1c\x01\x1c", 2);                                      // Enter right behind it
rewind($mem);
$first = (string)stream_get_contents($mem);
$due   = $cl->sendInput();
usleep(70000);
$late = $cl->sendInput();
rewind($mem);
$all = (string)stream_get_contents($mem);
check('typed text: next input waits the pause, in order', [bin2hex($first), $due > 0.04 && $due <= 0.06, $late,
    bin2hex(substr($all, strlen($first)))], ['0806001e011e', true, null, '0806001c011c']);

// fast-path input over 32 KB is not sent: its 15-bit length wrapped and the rest of the browser's bytes reached the server as PDUs
ftruncate($mem, 0);
rewind($mem);
$cl->input(str_repeat("\x00\x1e", 0x4010), 1);
rewind($mem);
check('fast-path input: oversized message not sent', strlen((string)stream_get_contents($mem)), 0);

// input during a deactivation-reactivation waits for the new activation: a key release dropped there left the key down
ftruncate($mem, 0);
rewind($mem);
(new ReflectionProperty(Client::class, 'licensed'))->setValue($cl, true);
$cl->input("\x00\x2a", 1);                                                          // Shift down
(new ReflectionMethod(Client::class, 'ioPdu'))->invoke($cl, pack('vvvV', 10, 0x16, 1002, 1));   // Deactivate All
$cl->input("\x01\x2a", 1);                                                          // Shift up meanwhile
$cl->sendInput();
rewind($mem);
$inactive = (string)stream_get_contents($mem);
$cl->active = true;                                                                 // Demand Active, Confirm Active
$cl->sendInput();
rewind($mem);
check('input kept across a reactivation', [bin2hex($inactive), bin2hex((string)stream_get_contents($mem))], ['0404002a', '0404002a0404012a']);

// text that cannot be pasted is typed at 100 ms per character with all input waiting behind it: limited (1 MB took hours and
// exhausted the memory)
check('typed text limited', count(Client::unicodeText(str_repeat('a', 100000))), 2000);

// what the server sends in pieces is limited: a fast-path update to the 16 MB announced, a channel message to its announced
// length; a channel message is joined once (joining at every chunk took 3.2 s for 4 MB and 17 s for 8 MB)
$fc    = new Client(['width' => 800, 'height' => 600], static fn() => null);
$piece = str_repeat("\0", 16000);
$frag  = static fn(int $f) => "\x00" . pack('n', 0x8000 | (6 + strlen($piece))) . chr(0x01 | ($f << 4)) . pack('v', strlen($piece)) . $piece;
$fpm   = new ReflectionMethod(Client::class, 'fastPath');
$cpm   = new ReflectionMethod(Client::class, 'channelPdu');
try {
    $fpm->invoke($fc, $frag(2));
    for ($i = 0; $i < 1100; $i++) {
        $fpm->invoke($fc, $frag(3));
    }
    $tooBig = null;
} catch (Throwable $tooBig) {
}
try {
    $cpm->invoke($fc, 1004, pack('VV', 1600, 1) . str_repeat("\0", 1600));
    $cpm->invoke($fc, 1004, pack('VV', 1600, 0) . str_repeat("\0", 1600));
    $longer = null;
} catch (Throwable $longer) {
}
$sink = new class implements MuDesk\Rdp\Channel {
    public string $msg = '';

    public function name(): string
    {
        return 'test';
    }

    public function onData(string $data): void
    {
        $this->msg = $data;
    }
};
(new ReflectionProperty(Client::class, 'handlers'))->setValue($fc, [1004 => $sink]);
$chunks = 2622;                                                                     // 4 MB in 1600-byte chunks
$t0     = microtime(true);
for ($i = 0; $i < $chunks; $i++) {
    $cpm->invoke($fc, 1004, pack('VV', $chunks * 1600, ($i === 0 ? 1 : 0) | ($i === $chunks - 1 ? 2 : 0)) . str_repeat(chr($i & 0xff), 1600));
}
check('server pieces limited, channel message joined once', [$tooBig instanceof MuDesk\Rdp\ProtocolException, $longer instanceof MuDesk\Rdp\ProtocolException,
    strlen($sink->msg) === $chunks * 1600 && $sink->msg[1600] === "\1", microtime(true) - $t0 < 1], [true, true, true, true]);
// a channel message is at most 64 MB, its length the one its first chunk announced (each chunk announcing 4 GB grew the
// memory to the limit); dynamic channel messages too, and at most 8 of them being joined at a time
$cl = new Client(['width' => 800, 'height' => 600], static fn() => null);
(new ReflectionProperty(Client::class, 'handlers'))->setValue($cl, [1004 => $sink]);
$refused = static function (callable $f): string {
    try {
        $f();
        return 'taken';
    } catch (MuDesk\Rdp\ProtocolException $e) {
        return $e->key;
    }
};
check('channel: a message over 64 MB, or longer than its first chunk said, refused', [
    $refused(static fn() => $cpm->invoke($cl, 1004, pack('VV', 100 << 20, 1) . str_repeat("\0", 1600))),
    $refused(static function () use ($cpm, $cl): void {
        $cpm->invoke($cl, 1004, pack('VV', 3200, 1) . str_repeat("\0", 1600));
        $cpm->invoke($cl, 1004, pack('VV', 0xFFFFFFFF, 0) . str_repeat("\0", 1600));
        $cpm->invoke($cl, 1004, pack('VV', 0xFFFFFFFF, 2) . str_repeat("\0", 1600));
    })], ['protocol', 'protocol']);
$dyn = new MuDesk\Rdp\Drdynvc($cl, false);
check('dynamic channels: a message over 64 MB refused, at most 8 joined at a time', [
    $refused(static fn() => $dyn->onData("\x28\x01" . pack('V', 100 << 20) . 'x')),
    $refused(static function () use ($dyn): void {
        for ($i = 2; $i < 10; $i++) {
            $dyn->onData("\x20" . chr($i) . chr(100) . 'x');                  // DataFirst of 100 bytes, 1 arrived
        }
    }),
    $refused(static fn() => $dyn->onData("\x20\x0a" . chr(100) . 'x'))], ['protocol', 'taken', 'protocol']);
// XCRUSH: the history starts again at its front only once it is (nearly) full: a server resetting it at every block made each
// one copy 2 MB (a core busy with 2 KB/s)
$xc = new MuDesk\Rdp\Xcrush();
$xc->decompress("\x02\x00" . str_repeat('a', 100), 0);
check('XCRUSH: an early history reset refused', [$refused(static fn() => $xc->decompress("\x06\x00" . 'b', 0)),
    $refused(static fn() => (new MuDesk\Rdp\Xcrush())->decompress("\x06\x00" . 'b', 0))], ['protocol', 'taken']);
// a desktop the browser could not hold (over 32766 a side, or over 8192 x 8192 pixels in all) ends the connection
$da = static function (int $w, int $h): string {
    $cap = pack('vv', 2, 28) . pack('vvvvvv', 32, 1, 1, 1, $w, $h) . str_repeat("\0", 12);
    $c   = new Client(['width' => 800, 'height' => 600, 'bpp' => 32, 'orders' => false, 'kbd' => 0x409, 'perf' => 0, 'audio' => false,
        'monitors' => [], 'compression' => false], static fn() => null);
    try {
        (new ReflectionMethod(Client::class, 'demandActive'))->invoke($c, new MuDesk\Rdp\Reader(pack('Vvv', 1, 4, 4 + strlen($cap)) . "RDP\0" . pack('vv', 1, 0) . $cap));
    } catch (Throwable $e) {
        return str_starts_with($e->getMessage(), 'Desktop of') ? 'refused' : 'past the check';   // (no connection to answer on)
    }
    return 'past the check';
};
check('demand active: a desktop over 32766 a side or 2^26 pixels refused', [$da(40000, 100), $da(8192, 8193), $da(0, 600), $da(3840, 2160)],
    ['refused', 'refused', 'refused', 'past the check']);
// X.224: a protocol the server picks that was not asked for (RDSTLS, NLA extended) ends the connection
$x224 = static function (int $chosen): string {
    $proc = proc_open([PHP_BINARY, '-r', '$s = stream_socket_server("tcp://127.0.0.1:0"); echo stream_socket_get_name($s, false), "\n"; $c = stream_socket_accept($s, 10);'
        . ' fread($c, 200); fwrite($c, hex2bin($argv[1])); usleep(1500000);',
        bin2hex("\x03\x00\x00\x13\x0e\xd0\x00\x00\x12\x34\x00\x02\x00\x08\x00" . pack('V', $chosen))], [1 => ['pipe', 'w']], $pipes);
    $addr = trim((string)fgets($pipes[1]));
    $c    = new Client(['ip' => '127.0.0.1', 'port' => (int)substr($addr, (int)strrpos($addr, ':') + 1), 'security' => 'nla', 'user' => 'u',
        'width' => 800, 'height' => 600], static fn() => null);
    try {
        $c->connect(static fn() => null);
        $key = 'connected';
    } catch (Throwable $e) {
        $key = $e->getMessage();
    }
    proc_terminate($proc);
    fclose($pipes[1]);
    proc_close($proc);
    return $key;
};
check('X.224: a protocol not asked for refused', [$x224(8), $x224(4), $x224(10)], ['Server chose protocol 8, not offered', 'Server chose protocol 4, not offered',
    'Server chose protocol 10, not offered']);
// the socket is read 4 MB at a time (the main loop comes back for the rest): a server sending without pause kept the worker
// reading and the browser's input waiting
$proc = proc_open([PHP_BINARY, '-r', '$s = stream_socket_server("tcp://127.0.0.1:0"); echo stream_socket_get_name($s, false), "\n"; $c = stream_socket_accept($s, 10);'
    . ' $b = str_repeat("x", 1 << 20); for ($i = 0; $i < 24; $i++) { @fwrite($c, $b); } usleep(500000);'], [1 => ['pipe', 'w']], $pipes);
$addr = trim((string)fgets($pipes[1]));
$dt   = new Transport('127.0.0.1', (int)substr($addr, (int)strrpos($addr, ':') + 1));
usleep(300000);
$dt->drain();
$first = $dt->bytesIn;
$dt->close();
proc_terminate($proc);
fclose($pipes[1]);
proc_close($proc);
check('transport: the socket read 4 MB at a time', [$first > 0, $first <= (4 << 20) + 65536], [true, true]);

// RemoteApp (MS-RDPERP): after the server's handshake, ours, the client status and the system parameters (the work area:
// the browser window); the program (Client Execute) once the browser reports the server's windows sent (the server only
// takes it then), or after 5 s without that report, once more if the server was not watching the desktop yet; reconnected,
// the program is not started again and the server is told; a program the server refuses ends the session with its reason;
// the browser's window commands, only those allowed
$rframes = [];
$rc   = new Client(['width' => 800, 'height' => 600], static function (int $t, string $p) use (&$rframes): void { $rframes[] = [$t, $p]; });
$rmem = fopen('php://memory', 'w+b');
$rtr  = (new ReflectionClass(Transport::class))->newInstanceWithoutConstructor();
$rtr->sock = $rmem;
$rc->t = $rtr;
(new ReflectionProperty(Client::class, 'channelIds'))->setValue($rc, ['rail' => 1008]);
(new ReflectionProperty(Client::class, 'channelOpts'))->setValue($rc, ['rail' => 0xC0200000]);
$railOut = static function () use ($rmem): array {                      // the rail PDUs written: [type, body]
    rewind($rmem);
    $o = (string)stream_get_contents($rmem);
    ftruncate($rmem, 0);
    rewind($rmem);
    $pdus = [];
    for ($p = 0; $p + 4 <= strlen($o);) {
        $pkt = substr($o, $p, unpack('n', $o, $p + 2)[1]);                 // TPKT
        $q   = 4 + 3 + 6;                                                  // X.224, MCS send data request (initiator, channel, flags)
        $q  += ord($pkt[$q]) & 0x80 ? 2 + 8 : 1 + 8;                       // its PER length, the channel PDU header
        $d   = substr($pkt, $q);
        $pdus[] = [unpack('v', $d)[1], substr($d, 4)];
        $p     += strlen($pkt);
    }
    return $pdus;
};
$railIn = static fn(int $type, string $body): string => pack('vv', $type, 4 + strlen($body)) . $body;
$rail = new MuDesk\Rdp\Rail($rc, '||Notepad', 'nota.txt');
$rail->command('{"a":"activate","id":5}');                                  // before the handshake: nothing
$early = $railOut();
$rail->onData($railIn(0x0013, pack('VV', 0x1DB0, 0)));                     // HandshakeEx
$sent  = $railOut();
$params = array_map(static fn($x) => unpack('V', $x[1])[1], array_filter($sent, static fn($x) => $x[0] === 3));
$rail->tick();                                                               // before 5 s: not yet
$waited = $railOut();
$rail->command('{"a":"ready"}');                                             // the browser read the desktop order "arc completed"
$exec  = $railOut()[0] ?? [0, ''];
$rail->command('{"a":"ready"}');
$once  = $railOut();
check('RemoteApp: handshake, client status, the system parameters (work area = the window); the program with its arguments once the server sent its windows, once',
    [$early, array_column($sent, 0), unpack('V', $sent[0][1])[1], unpack('V', $sent[1][1])[1], array_values($params),
     bin2hex(substr(array_values(array_filter($sent, static fn($x) => $x[0] === 3 && unpack('V', $x[1])[1] === 0x2F))[0][1] ?? '', 4)),
     bin2hex(substr(array_values(array_filter($sent, static fn($x) => $x[0] === 3 && unpack('V', $x[1])[1] === 0x43))[0][1] ?? '', 4)), $waited,
     $exec[0], unpack('v4', $exec[1]), substr($exec[1], 8, 18) === Text::toUtf16('||Notepad'), substr($exec[1], 26) === Text::toUtf16('nota.txt'), $once],
    [[], [5, 0x0B, 3, 3, 3, 3, 3, 3], 0x1DB0, 0, [0x43, 0x21, 0x45, 0x25, 0x100B, 0x2F], '000000002003' . '3002', '7e000000020000000000', [],   // (work area: 600 - the page's taskbar, 40)
     1, [1 => 0x8, 2 => 18, 3 => 0, 4 => 16], true, true, []]);
$late = new MuDesk\Rdp\Rail($rc, '||Notepad');
$late->onData($railIn(0x0005, pack('V', 0x1DB0)));
$railOut();
(new ReflectionProperty(MuDesk\Rdp\Rail::class, 'execAt'))->setValue($late, microtime(true) - 1);   // 5 s went by without the report
$late->tick();
$byTime = array_column($railOut(), 0);
$late->onData($railIn(0x0080, pack('vvVvv', 0, 1, 0, 0, 0)));                // RAIL_EXEC_E_HOOK_NOT_LOADED: asked for again
(new ReflectionProperty(MuDesk\Rdp\Rail::class, 'execAt'))->setValue($late, microtime(true) - 1);
$late->tick();
$retry = array_column($railOut(), 0);
try {
    $late->onData($railIn(0x0080, pack('vvVvv', 0, 1, 0, 0, 0)));
    $second = 'ok';
} catch (MuDesk\Rdp\ProtocolException $e) {
    $second = $e->key;
}
check('RemoteApp: without the report, the program after 5 s; once more if the server was not watching the desktop yet',
    [$byTime, $retry, $second], [[1], [1], 'app_failed']);
$rail->onData($railIn(0x0005, pack('V', 0x1DB0)));                          // a second handshake: nothing again
$twice = $railOut();
$rail->workArea(800, 600);                                                  // the same size: nothing
$rail->workArea(1280, 720);
$wa = $railOut();
$rail->command('{"a":"activate","id":7}');
$rail->command('{"a":"sys","id":7,"c":61536}');                              // SC_CLOSE
$rail->command('{"a":"sys","id":7,"c":4660}');                               // not one of ours
$rail->command('{"a":"sys","id":"7","c":61536}');
$rail->command('[1,2');
$cmds = $railOut();
$again = new MuDesk\Rdp\Rail($rc, '||Notepad', '', true);
$again->onData($railIn(0x0005, pack('V', 0x1DB0)));
$re   = $railOut();
check('RemoteApp: the handshake answered once; the work area follows the window; window commands checked; reconnected, the server told so and nothing started at the handshake',
    [$twice, array_map(static fn($x) => [$x[0], bin2hex($x[1])], $wa), array_map(static fn($x) => [$x[0], bin2hex($x[1])], $cmds),
     unpack('V', $re[1][1])[1], in_array(1, array_column($re, 0), true)],
    [[], [[3, '2f000000' . '000000000005a802']], [[2, '0700000001'], [4, '0700000060f0']], 2, false]);
// reconnected after the connection dropped: with its windows back the program is still running (not started again);
// without them it was closed meanwhile (started again); without the browser's report it is left as it was. Connected again
// from a page where it was open (Reconnect): the same, but without the report it is asked for. A new connection starts it
// whatever is there
$railRun = static function (bool $again, ?string $cmd, bool $late = false, bool $resume = false) use ($rc, $railIn, $railOut): bool {
    $r = new MuDesk\Rdp\Rail($rc, '||Notepad', '', $again, $resume);
    $r->onData($railIn(0x0005, pack('V', 0x1DB0)));
    $railOut();
    if ($cmd !== null) {
        $r->command($cmd);
    }
    if ($late) {
        (new ReflectionProperty(MuDesk\Rdp\Rail::class, 'execAt'))->setValue($r, microtime(true) - 1);
        $r->tick();
    }
    return in_array(1, array_column($railOut(), 0), true);
};
check('RemoteApp: connected again (dropped connection or Reconnect), the program started again only if its windows did not come back; a first connection always',
    [$railRun(true, '{"a":"ready","open":2}', true), $railRun(true, '{"a":"ready","open":0}'), $railRun(true, '{"a":"ready"}'),
     $railRun(true, null, true), $railRun(false, '{"a":"ready","open":3}'), $railRun(true, '{"a":"ready","open":"2"}'),
     $railRun(false, '{"a":"ready","open":1}', true, true), $railRun(false, '{"a":"ready","open":0}', false, true), $railRun(false, null, true, true)],
    [false, true, true, false, true, true, false, true, true]);
// connected again from a page where the program was open: asked for as soon as the browser reports the desktop (no other
// wait: a session without a program is ended by the server some 12 s later); not asked for again on the page's say (it
// connects again instead, see session_unit)
$wr = new MuDesk\Rdp\Rail($rc, '||Notepad', '', false, true);
$wr->onData($railIn(0x0005, pack('V', 0x1DB0)));
$railOut();
$wr->command('{"a":"wait"}');                               // (a wait was asked for once: not a command)
$waits = (new ReflectionProperty(MuDesk\Rdp\Rail::class, 'execAt'))->getValue($wr) - microtime(true);
$wr->command('{"a":"ready","open":0}');
$first = array_column($railOut(), 0);
$wr->command('{"a":"exec"}');
$again = array_column($railOut(), 0);
check('RemoteApp: connected again, the program asked for as soon as the desktop is reported (no other wait), once',
    [$waits <= 5.0, $first, $again], [true, [1], []]);

// several screens: the work area is the page's monitor, the primary one (the page makes its screen so), its taskbar left
// out, as Display Control takes it (even width, 200 to 8192); given before the handshake, kept for it
$pre = new MuDesk\Rdp\Rail($rc, '||Notepad');
$pre->workArea(...MuDesk\Rdp\Rail::mainMonitor([[-1000, 0, 1000, 700, false], [0, 0, 1025, 768, true]]));
$early = $railOut();
$pre->onData($railIn(0x0005, pack('V', 0x1DB0)));
$areas = array_values(array_filter($railOut(), static fn($x) => $x[0] === 3 && unpack('V', $x[1])[1] === 0x2F));
check('RemoteApp on several screens: the work area is the page\'s monitor (the primary), its taskbar left out; kept for the handshake',
    [$early, array_map(static fn($x) => bin2hex(substr($x[1], 4)), $areas), MuDesk\Rdp\Rail::mainMonitor([[0, 0, 800, 600, false]]),
     MuDesk\Rdp\Rail::mainMonitor([[0, 0, 9000, 100, true]])],
    [[], ['000000000004d802'], null, [8192, 200]]);
$refused = [];
foreach ([0, 3, 5, 7, 6, 9] as $code) {
    try {
        $rail->onData($railIn(0x0080, pack('vvVvv', 0, $code, 0, 0, 0)));
        $refused[] = 'ok';
    } catch (MuDesk\Rdp\ProtocolException $e) {
        $refused[] = $e->key;
    }
}
$cf = [];
$rail->onData($railIn(0x0015, pack('VC', 9, 1)));                             // a window cloaked
check('RemoteApp: a program the server refuses ends the session with its reason; a cloaked window told to the browser',
    [$refused, $rframes], [['ok', 'app_not_allowed', 'app_not_found', 'app_locked', 'app_failed', 'app_failed'], [[Client::F_RAIL, '{"cloak":9,"on":true}']]]);
// the client asks for RemoteApp only with a program: INFO_RAIL in the Client Info PDU, the Remote Programs and Window List
// capability sets in the Confirm Active PDU
$capsOf = static function (array $o) use ($rmem, $rtr): array {
    $c = new Client(['width' => 800, 'height' => 600, 'bpp' => 16, 'orders' => false, 'kbd' => 0x409, 'perf' => 0, 'audio' => true, 'monitors' => [],
        'compression' => 0, 'tzBias' => 0, 'domain' => '', 'user' => 'u', 'password' => 'p'] + $o, static fn() => null);
    $c->t = $rtr;
    rewind($rmem);
    ftruncate($rmem, 0);
    (new ReflectionMethod(Client::class, 'sendClientInfo'))->invoke($c);
    (new ReflectionMethod(Client::class, 'confirmActive'))->invoke($c);
    rewind($rmem);
    $o2 = (string)stream_get_contents($rmem);
    $i  = strpos($o2, pack('vvV', 0x40, 0, 0));
    return [unpack('V', $o2, $i + 8)[1] & 0x8000, str_contains($o2, pack('vvV', 23, 8, 1)), str_contains($o2, pack('vvVCv', 24, 11, 2, 3, 12))];
};
check('RemoteApp: INFO_RAIL and the RemoteApp capabilities only with a program', [$capsOf(['app' => '||Notepad']), $capsOf([])],
    [[0x8000, true, true], [0, false, false]]);
// a server that does not offer RemoteApp (no Remote Programs or Window List capability set, or not supported there) ends the
// connection with its reason; the icon caches never above the server's
$demand = static function (array $caps): string {
    $c    = new Client(['width' => 800, 'height' => 600, 'bpp' => 16, 'orders' => false, 'kbd' => 0x409, 'perf' => 0, 'audio' => true, 'monitors' => [],
        'compression' => 0, 'tzBias' => 0, 'app' => '||Notepad'], static fn() => null);
    $mem  = fopen('php://memory', 'w+b');
    $tr   = (new ReflectionClass(Transport::class))->newInstanceWithoutConstructor();
    $tr->sock = $mem;
    $c->t = $tr;
    $body = pack('vv', 2, 28) . pack('vvvvvv', 16, 1, 1, 1, 1024, 768) . str_repeat("\0", 12) . implode('', array_map(static fn($k, $v) => pack('vv', $k, 4 + strlen($v)) . $v, array_keys($caps), $caps));
    try {
        (new ReflectionMethod(Client::class, 'demandActive'))->invoke($c, new MuDesk\Rdp\Reader(pack('Vvv', 1, 4, 4 + strlen($body)) . "RDP\0" . pack('vv', count($caps) + 1, 0) . $body));
    } catch (MuDesk\Rdp\ProtocolException $e) {
        return $e->key;
    } catch (Throwable) {
    }
    rewind($mem);
    $o = (string)stream_get_contents($mem);
    $i = strpos($o, pack('vvV', 24, 11, 2));
    return $i === false ? 'no window list' : bin2hex(substr($o, $i + 8, 3));
};
check('RemoteApp: a server without it refused; the icon caches never above the server\'s',
    [$demand([]), $demand([23 => pack('V', 0), 24 => pack('VCv', 2, 3, 12)]), $demand([23 => pack('V', 1), 24 => pack('VCv', 0, 3, 12)]),
     $demand([23 => pack('V', 0x81), 24 => pack('VCv', 2, 2, 10)]), $demand([23 => pack('V', 1), 24 => pack('VCv', 2, 5, 40)])],
    ['app_unsupported', 'app_unsupported', 'app_unsupported', '020a00', '030c00']);

// graphics pipeline: a Display Control resize brings no reactivation, and Refresh Rect covered only the old size
$gc         = new Client(['width' => 800, 'height' => 600], static fn() => null);
$gc->t      = $tr;
$gc->gfx    = true;
$gc->active = true;
ftruncate($mem, 0);
rewind($mem);
try {
    $gc->resized(1600, 900);
} catch (Throwable) {
}
$gc->refresh();
rewind($mem);
check('graphics pipeline: refresh covers the size asked through Display Control', str_ends_with((string)stream_get_contents($mem), pack('vvvv', 0, 0, 1599, 899)), true);

// activation: one limit for the whole wait (a server sending other PDUs and no Demand Active kept it waiting for ever)
$proc = proc_open([PHP_BINARY, '-r', '$s = stream_socket_server("tcp://127.0.0.1:0"); echo stream_socket_get_name($s, false), "\n"; $c = stream_socket_accept($s, 10);'
    . ' for ($i = 0; $i < 25 && @fwrite($c, "\x03\x00\x00\x0f\x02\xf0\x80\x68\x00\x01\x03\xf0\x70\x01\x00"); $i++) { usleep(200000); }'], [1 => ['pipe', 'w']], $pipes);
$addr  = trim((string)fgets($pipes[1]));
$ac    = new Client(['width' => 800, 'height' => 600], static fn() => null);
$ac->t = new Transport('127.0.0.1', (int)substr($addr, (int)strrpos($addr, ':') + 1));
$t0    = microtime(true);
try {
    (new ReflectionMethod(Client::class, 'activate'))->invoke($ac, 0.5);
    $e = null;
} catch (Throwable $e) {
}
check('activation: the wait is limited as a whole', [$e instanceof MuDesk\Rdp\ProtocolException ? $e->key : null, microtime(true) - $t0 < 2], ['timeout', true]);
$ac->t->close();
proc_terminate($proc);
fclose($pipes[1]);
proc_close($proc);

// clipboard with formatting: the browser's text + CF_HTML are announced as text and "HTML Format", and the remote
// "HTML Format" is requested after the text and forwarded to the browser
$clipFrames = [];
$cc = new Client(['width' => 800, 'height' => 600], static function (int $t, string $p) use (&$clipFrames): void {
    $clipFrames[] = $p;
});
$cc->t = $tr;
(new ReflectionProperty(Client::class, 'channelIds'))->setValue($cc, ['cliprdr' => 1006]);
(new ReflectionProperty(Client::class, 'channelOpts'))->setValue($cc, ['cliprdr' => 0xC0000000]);
$clip = new MuDesk\Rdp\Cliprdr($cc, 'cliptest');
(new ReflectionProperty(MuDesk\Rdp\Cliprdr::class, 'ready'))->setValue($clip, true);   // Monitor Ready received
$cfh  = "Version:0.9\r\nStartHTML:0000000105\r\n...<b>Negrita</b>\0";
$out  = static function () use ($mem): string {
    rewind($mem);
    $o = (string)stream_get_contents($mem);
    ftruncate($mem, 0);
    rewind($mem);
    return $o;
};
$out();
$clip->fromBrowser('H' . pack('V', 7) . 'Negrita' . $cfh);
$announce = $out();
$clip->onData(pack('vvV', 4, 0, 4) . pack('V', 0xC0F4));                       // Windows asks for the HTML
$served = $out();
$name = static fn(string $n) => substr(str_pad(Text::toUtf16($n), 32, "\0"), 0, 32);
$clip->onData(pack('vvV', 2, 0, 72) . pack('V', 13) . $name('') . pack('V', 0xC1A0) . $name('HTML Format'));
$askText = $out();
$clip->onData(pack('vvV', 5, 1, 10) . Text::toUtf16('hola') . "\0\0");
$askHtml = $out();
$clip->onData(pack('vvV', 5, 1, strlen($cfh)) . $cfh);
check('clipboard: formatted text both ways', [
    str_contains($announce, pack('V', 13) . $name('') . pack('V', 0xC0F4) . $name('HTML Format')),
    str_contains($served, pack('vvV', 5, 1, strlen($cfh)) . $cfh),
    str_contains($askText, pack('vvV', 4, 0, 4) . pack('V', 13)), str_contains($askHtml, pack('vvV', 4, 0, 4) . pack('V', 0xC1A0)),
    array_map(static fn($f) => $f[0], $clipFrames), end($clipFrames)], [true, true, true, true, ['L', 'T', 'H'], 'H' . $cfh]);
$out();
$clip->onData(pack('vvV', 2, 0, 36) . pack('V', 0xC1A1) . $name('HTML Format'));   // HTML only (PowerShell Set-Clipboard -AsHtml)
check('clipboard: HTML without plain text is requested too', str_contains($out(), pack('vvV', 4, 0, 4) . pack('V', 0xC1A1)), true);

// phone keyboard through the clipboard (option): text in one go is pasted. What was copied on the remote side is read
// first (all its formats) and offered again after; Ctrl+V once Windows takes the announcement and the screen shows the
// last input, held until the application asks for the text; the input behind goes out once the paste is drawn.
// (Windows 11 Notepad, Terminal and Explorer read injected keys late and mix up characters; Notepad's spell checker
// dropped keys that arrived while it went through the text.)
$mkPaste = static function (bool $paster = true) use ($tr): array {
    $pc         = new Client(['width' => 800, 'height' => 600], static fn() => null);
    $pc->t      = $tr;
    $pc->active = true;
    (new ReflectionProperty(Client::class, 'channelIds'))->setValue($pc, ['cliprdr' => 1006]);
    (new ReflectionProperty(Client::class, 'channelOpts'))->setValue($pc, ['cliprdr' => 0xC0000000]);
    $pclip = new MuDesk\Rdp\Cliprdr($pc, 'pastetest');
    (new ReflectionProperty(MuDesk\Rdp\Cliprdr::class, 'long'))->setValue($pclip, true);
    (new ReflectionProperty(MuDesk\Rdp\Cliprdr::class, 'ready'))->setValue($pclip, true);
    if ($paster) {
        $pc->paster = $pclip->typeText(...);
    }
    return [$pc, $pclip];
};
$lname  = static fn(string $n) => Text::toUtf16($n) . "\0\0";                    // long format names
$flist  = static fn(string $body) => pack('vvV', 2, 0, strlen($body)) . $body;
$resp   = static fn(string $data) => pack('vvV', 5, 1, strlen($data)) . $data;
$ask    = static fn(int $id) => pack('vvV', 4, 0, 4) . pack('V', $id);
$answer = pack('vvV', 3, 1, 0);
$ctrlV  = "\x08\x06\x00\x1d\x00\x2f";
$keysUp = "\x08\x06\x01\x2f\x01\x1d";
[$pc, $pclip] = $mkPaste();
$redraw = static function () use (&$pc): void {                                  // the screen changes
    (new ReflectionMethod(Client::class, 'fastPath'))->invoke($pc, "\x00\x02");
};
$pclip->onData($flist(pack('V', 13) . "\0\0" . pack('V', 0xC1A0) . $lname('HTML Format')));   // copied on the remote side
$pclip->onData($resp(Text::toUtf16('ORIGINAL') . "\0\0"));                     // (its text and HTML go to the browser)
$pclip->onData($resp('<b>O</b>'));
$out();
$pc->pasteText('Hola ñ');
$reading = $out();
$pc->input("\x00\x2d\x01\x2d", 2);                                               // 'x' typed right behind
$pclip->onData($resp(Text::toUtf16('ORIGINAL') . "\0\0"));                     // the remote clipboard, read again whole
$pclip->onData($resp('<b>O</b>'));
$announced = $out();
$pclip->onData($answer);                                                         // Windows took the announcement ...
$notYet = $out();
$redraw();                                                                       // ... and the screen is up to date
$pclip->tick();
$v1 = $out();
$pclip->onData($ask(13));                                                        // the application asks for the text
$pc->sendInput();
$pasted = $out();
$redraw();                                                                       // the application draws the pasted text
$pc->sendInput();
$x = $out();
$pclip->onData($ask(0xC100));                                                    // a later paste gets the original back
$html = $out();
$pclip->onData($ask(13));
$text = $out();
check('phone keyboard by pasting: remote clipboard read first, Ctrl+V after the answer and the redraw, text, keys up', [
    str_contains($reading, $ask(13)), str_contains($announced, $ask(0xC1A0)),
    str_contains($announced, pack('V', 13) . "\0\0") && str_contains($announced, $lname('ExcludeClipboardContentFromMonitorProcessing')),
    str_contains($announced . $notYet, "\x00\x2d") || str_contains($notYet, $ctrlV), str_contains($v1, $ctrlV),
    strpos($pasted, Text::toUtf16('Hola ñ')) < strpos($pasted, $keysUp)], [true, true, true, false, true, true]);
check('phone keyboard by pasting: next key after the redraw, the remote clipboard back whole and out of the history', [
    str_contains($pasted, "\x00\x2d"), bin2hex($x),
    str_contains($pasted, pack('V', 0xC100) . $lname('HTML Format')) && str_contains($pasted, $lname('CanIncludeInClipboardHistory')),
    str_contains($html, $resp('<b>O</b>')), str_contains($text, Text::toUtf16('ORIGINAL'))], [false, '0806002d012d', true, true, true]);
// Windows answers each format list in order: the answer to the list that put the clipboard back is not this paste's
$pc->pasteText('nuevo');
$out();
$pclip->onData($answer);
$redraw();
$pclip->tick();
$early = $out();
$pclip->onData($answer);
$pclip->tick();
check('phone keyboard by pasting: Ctrl+V only after the answer to its own announcement', [str_contains($early, $ctrlV),
    str_contains($out(), $ctrlV)], [false, true]);
foreach ([$ask(13), $answer] as $m) {                                            // paste, answer to the clipboard put back
    $pclip->onData($m);
}
$redraw();
$pc->sendInput();
$out();
// text right behind a paste is pasted at once, the clipboard goes back after the last one
$pc->pasteText('uno ');
$pc->pasteText('dos');
$pclip->onData($answer);
$pclip->tick();
$pclip->onData($ask(13));
$first = $out();
$redraw();
$pc->sendInput();
$pclip->onData($answer);                                                         // to the clipboard put back
$pclip->onData($answer);                                                         // to 'dos'
$redraw();
$pclip->tick();
$pclip->onData($ask(13));
$second = $out();
check('phone keyboard by pasting: text right behind goes in the next paste, the clipboard back after it', [
    str_contains($first, $resp(Text::toUtf16('uno dos') . "\0\0")) || (str_contains($first, Text::toUtf16('uno ')) && str_contains($second, Text::toUtf16('dos'))),
    str_contains($first . $second, pack('V', 0xC100) . $lname('HTML Format'))], [true, true]);
$pclip->onData($answer);
$redraw();
$pc->sendInput();
$out();
$pc->pasteText('ab');
$out();
$typing = new ReflectionProperty(MuDesk\Rdp\Cliprdr::class, 'typing');
$typing->setValue($pclip, ['until' => 0] + $typing->getValue($pclip));         // Windows never answered
$pclip->tick();
check('phone keyboard by pasting: typed instead when Windows does not answer', str_contains($out(), "\x08\x08\x80a\0\x81a\0"), true);
// an image copied on the remote side is put back; files cannot be, so the text is typed instead of pasted
[$pc, $pclip] = $mkPaste();
$pclip->onData($flist(pack('V', 8) . "\0\0"));
$pclip->onData($resp('DIBDATA'));
$pc->pasteText('ef');
$pclip->onData($resp('DIBDATA'));
$pclip->onData($answer);
(new ReflectionMethod(Client::class, 'fastPath'))->invoke($pc, "\x00\x02");
$pclip->tick();
$pclip->onData($ask(13));
$out();
$pclip->onData($ask(8));
check('phone keyboard by pasting: an image copied on the remote side is put back', str_contains($out(), $resp('DIBDATA')), true);
[$pc, $pclip] = $mkPaste();
$pclip->onData($flist(pack('V', 0xC0A0) . $lname('FileGroupDescriptorW') . pack('V', 0xC0A1) . $lname('FileContents')));
$out();
$pc->pasteText('cd');
$files = $out();
[$pc, $pclip] = $mkPaste(false);                                                 // option off: typed as before
$pc->pasteText('gh');
check('phone keyboard by pasting: files copied on the remote side, or the option off: typed', [str_contains($files, "\x80c\0\x81c\0"),
    str_contains($files, $ctrlV), str_contains($out(), "\x80g\0\x81g\0")], [true, false, true]);
check('phone keyboard by pasting: stored modes', array_map([MuDesk\Connections::class, 'pasteMode'], ['on', 'off', 'auto', 'x', null, true]),
    ['on', 'off', 'auto', 'auto', 'auto', 'auto']);
// the gateway now always has the paster with the clipboard channel: the phone keyboard option travels with each text
// (typed when it is off, as before), and a text of the clipboard history (message 12) is always pasted
[$pc, $pclip] = $mkPaste();
$pc->pasteText('ij', false);
$typed = $out();
[$pc, $pclip] = $mkPaste();                                                       // (a new session: the typed text paces itself)
$pc->pasteText('kl');
$fromHistory = $out();
check('clipboard history: always pasted; phone keyboard text with the option off still typed', [str_contains($typed, "\x80i\0\x81i\0"),
    str_contains($typed, pack('V', 13)), str_contains($fromHistory, "\x80k\0"), str_contains($fromHistory, pack('V', 13))], [true, false, false, true]);
// two pastes in a row: Windows answers a format list before it takes it and did not take one equal to the clipboard it
// had, so the second Ctrl+V pasted the first text again, kept by Windows once read ("control control" in the Run box).
// The clipboard is put back right after each paste (nothing copied: an empty text), and two lists in a row differ.
[$pc, $pclip] = $mkPaste();
$pc->pasteText('control ');
$l1 = $out();
$pclip->onData($answer);
$redraw();
$pclip->tick();
$pc->pasteText('printers');                                                      // the next word, waiting behind
$pclip->onData($ask(13));
$redraw();
$pc->sendInput();
$l2    = $out();
$turns = [];
foreach ([1, 2] as $n) {
    for ($p = 0; ($p = strpos($l1 . $l2, $lname("MuDeskClient $n"), $p)) !== false; $p++) {
        $turns[$p] = $n;
    }
}
ksort($turns);
$afterPaste = substr($l2, (int)strpos($l2, Text::toUtf16('control ')));
check('phone keyboard by pasting: the clipboard put back after each paste, two lists in a row differ', [array_values($turns),
    str_contains($afterPaste, pack('V', 13) . "\0\0")], [[1, 2, 1], true]);

// clipboard channel: client that keeps the frames for the browser (first byte: 'L' new remote list, 'T', 'H', 'F', 'R', 'A'...)
$mkClip = static function () use ($tr): array {
    $frames = new ArrayObject();
    $c      = new Client(['width' => 800, 'height' => 600], static function (int $t, string $p) use ($frames): void {
        $frames[] = $p;
    });
    $c->t      = $tr;
    $c->active = true;
    (new ReflectionProperty(Client::class, 'channelIds'))->setValue($c, ['cliprdr' => 1006]);
    (new ReflectionProperty(Client::class, 'channelOpts'))->setValue($c, ['cliprdr' => 0xC0000000]);
    $k = new MuDesk\Rdp\Cliprdr($c, 'cliptest');
    foreach (['long', 'ready'] as $p) {
        (new ReflectionProperty(MuDesk\Rdp\Cliprdr::class, $p))->setValue($k, true);
    }
    $c->paster = $k->typeText(...);
    return [$c, $k, $frames];
};
$fdesc = static fn(string $name) => pack('V', 0x44) . str_repeat("\0", 32) . pack('V', 0x80) . str_repeat("\0", 24) . pack('VV', 0, 3)
    . substr(str_pad($name, 520, "\0"), 0, 520);                               // FILEDESCRIPTORW of a 3-byte file
// an answer to a request made for the previous remote list is dropped: the HTML of a Word copy, arriving after an image was
// copied, was taken for a file list (garbage list in the browser, the clipboard contents as "file names" in the audit)
[$kc, $k, $kf] = $mkClip();
$k->onData($flist(pack('V', 13) . "\0\0" . pack('V', 0xC09E) . $lname('HTML Format')));
$k->onData($resp(Text::toUtf16('hola') . "\0\0"));                             // its text: the HTML is asked for
$k->onData($flist(pack('V', 8) . "\0\0"));                                      // an image copied before the HTML arrives
$k->onData($resp('Version:0.9 ' . str_repeat('salary list ', 60)));             // the HTML of the previous copy
check('clipboard: an answer for the previous remote list is dropped', array_map(static fn($p) => $p[0], (array)$kf), ['L', 'T', 'L']);
// remote files: names stay inside the copy (no ".." in the browser's ZIP), an unpaired surrogate does not lose the list;
// a download stopped by a new remote copy is reported (the browser kept its progress bar for ever)
[$kc, $k, $kf] = $mkClip();
$k->onData($flist(pack('V', 0xC0A0) . $lname('FileGroupDescriptorW')));
$k->onData($resp(pack('V', 2) . $fdesc(Text::toUtf16('..\..\Startup\run.bat')) . $fdesc("\x00\xDC" . Text::toUtf16('x.txt'))));
check('clipboard: remote file names stay inside the copy', array_column(json_decode(substr((string)$kf[count($kf) - 1], 1), true) ?? [], 'name'),
    ['Startup\run.bat', "\u{FFFD}x.txt"]);
$k->fromBrowser('D');
$n = count($kf);
$k->onData($flist(pack('V', 13) . "\0\0"));                                     // copied on the remote side during the download
check('clipboard: a download stopped by a remote copy is reported', array_map(static fn($p) => $p[0], array_slice((array)$kf, $n)), ['R', 'L']);
// the browser gets 'A' only for the lists it announced: the answer to the list that puts the clipboard back after a paste
// let a Ctrl+V go before Windows had taken the browser's text
[$kc, $k, $kf] = $mkClip();
$k->onData($flist(pack('V', 13) . "\0\0"));
$k->onData($resp(Text::toUtf16('A') . "\0\0"));
$kc->pasteText('hola');
$k->onData($resp(Text::toUtf16('A') . "\0\0"));                                // read
$k->onData($answer);
(new ReflectionMethod(Client::class, 'fastPath'))->invoke($kc, "\x00\x02");
$k->tick();
$k->onData($ask(13));                                                           // pasted: the clipboard is put back
$n = count($kf);
$k->onData($answer);                                                            // answer to the list that put it back
$k->fromBrowser('Xlocal');
$k->onData($answer);                                                            // answer to the browser's list
check('clipboard: the browser gets the answer to its own lists only', array_map(static fn($p) => $p[0], array_slice((array)$kf, $n)), ['A']);
// phone keyboard: copied on the remote side while its clipboard is read, the new copy is read for the next text (it was marked
// as impossible to put back, so every later text was typed)
[$kc, $k] = $mkClip();
$k->onData($flist(pack('V', 13) . "\0\0"));
$k->onData($resp(Text::toUtf16('A') . "\0\0"));
$kc->pasteText('uno');
$k->onData($flist(pack('V', 13) . "\0\0"));                                    // copied again meanwhile: 'uno' is typed
$k->onData($resp(Text::toUtf16('A') . "\0\0"));
$k->onData($resp(Text::toUtf16('B') . "\0\0"));
check('phone keyboard by pasting: a remote copy during the read leaves the next one readable',
    (new ReflectionProperty(MuDesk\Rdp\Cliprdr::class, 'unkeepable'))->getValue($k), false);
// phone keyboard: the remote clipboard is read up to 16 MB, not whole before deciding (four 33 MB formats were all held;
// 64 MB, held three times, passed a memory limit of 128 MB)
$limit = ini_get('memory_limit');
ini_set('memory_limit', '-1');
[$kc, $k] = $mkClip();
$k->onData($flist(pack('V', 8) . "\0\0" . pack('V', 0xC0A1) . $lname('PNG') . pack('V', 0xC0A2) . $lname('JFIF') . pack('V', 0xC0A3) . $lname('Art')));
$k->onData($resp('DIB'));
$out();
$kc->pasteText('hola');
for ($asked = 0; $asked < 4 && str_contains($out(), pack('vvV', 4, 0, 4)); $asked++) {
    $k->onData($resp(str_repeat('x', 9 * 1048576)));
}
ini_set('memory_limit', $limit);
check('phone keyboard by pasting: the remote clipboard read up to 16 MB', $asked, 2);
// no format list before Monitor Ready (the browser's clipboard while Windows logs on): Windows did not answer it, and every
// later paste waited for that answer
[$kc, $k] = $mkClip();
(new ReflectionProperty(MuDesk\Rdp\Cliprdr::class, 'ready'))->setValue($k, false);
$out();
$k->fromBrowser('Xhola');
$early = $out();
$k->onData(pack('vvV', 1, 0, 0));                                               // Monitor Ready: capabilities and our list
$k->onData($answer);
$kc->pasteText('ab');
$k->onData($answer);                                                            // answer to the paste's list
check('clipboard: no format list before Monitor Ready', [$early, (new ReflectionProperty(MuDesk\Rdp\Cliprdr::class, 'typing'))->getValue($k)['step'] ?? null],
    ['', 'ready']);
// a browser file list entry that is not an object is skipped (a warning, or a TypeError that ended the session); a remote file
// of 2^63 bytes or more keeps integer positions and an empty answer ends it (it was asked for again, with float positions)
// [tests/fuzz.php]
$notes = [];
set_error_handler(static function (int $no, string $msg) use (&$notes): bool {
    $notes[] = $msg;
    return true;
});
[$kc, $k, $kf] = $mkClip();
@mkdir(MuDesk\Rdp\Cliprdr::tmpDir('cliptest'), 0700, true);
file_put_contents(MuDesk\Rdp\Cliprdr::tmpDir('cliptest') . '/0', 'abc');
file_put_contents(MuDesk\Rdp\Cliprdr::tmpDir('cliptest') . '/1', 'def');
try {
    $k->fromBrowser('F[1,"x"]');
    $k->fromBrowser('F[{"name":"a.txt","size":3},null]');
    $bad = null;
} catch (Throwable $bad) {
}
$local = array_column((new ReflectionProperty(MuDesk\Rdp\Cliprdr::class, 'localFiles'))->getValue($k), 'name');
$k->onData($flist(pack('V', 0xC0A0) . $lname('FileGroupDescriptorW')));
$k->onData($resp(pack('V', 1) . pack('V', 0x44) . str_repeat("\0", 32) . pack('V', 0x80) . str_repeat("\0", 24) . pack('VV', 0xffffffff, 0)
    . str_pad(Text::toUtf16('big'), 520, "\0")));
$k->fromBrowser('D');
$n = count($kf);
$k->onData(pack('vvV', 9, 1, 4) . pack('V', (new ReflectionProperty(MuDesk\Rdp\Cliprdr::class, 'dl'))->getValue($k)['stream']));   // empty
restore_error_handler();
check('clipboard: odd browser file lists and remote files of 2^63 bytes, no warning', [$bad, $local, array_map(static fn($p) => $p[0], array_slice((array)$kf, $n)), $notes],
    [null, ['a.txt'], ['E', 'Z'], []]);
// files dropped on the session are pasted where they were dropped: the gateway tells the browser ('P') when an
// application asks for their list, not for other formats
[$kc, $k, $kf] = $mkClip();
$k->fromBrowser('F[{"name":"a.txt","size":3}]');
$n = count($kf);
$k->onData(pack('vvV', 4, 0, 4) . pack('V', 13));                              // a text asked for: no
$beforeList = array_map(static fn($p) => $p[0], array_slice((array)$kf, $n));
$k->onData(pack('vvV', 4, 0, 4) . pack('V', 0xC0F1));                          // the file list (FileGroupDescriptorW)
check('clipboard: the browser told when its files are pasted (their list asked for), not for other formats',
    [$beforeList, array_map(static fn($p) => $p[0], array_slice((array)$kf, $n))], [[], ['P']]);
MuDesk\Files::remove(MuDesk\Rdp\Cliprdr::tmpDir('cliptest'));

// graphics mode: 'auto' uses the pipeline (at 32 bpp) only with Windows 8.1 / 2012 R2 or later seen during NLA
$modes = [];
foreach ([['auto', [10, 0, 22631]], ['auto', [6, 3, 9600]], ['auto', [6, 1, 7601]], ['auto', null], ['on', null], ['off', [10, 0, 22631]], [true, null], [false, null]] as [$mode, $ver]) {
    $cl = new Client(['width' => 800, 'height' => 600, 'bpp' => 16, 'gfx' => $mode], static fn() => null);
    $cl->serverVersion = $ver;
    (new ReflectionMethod(Client::class, 'graphics'))->invoke($cl);
    $modes[] = $cl->gfx ? 32 : 0;
}
check('graphics mode: automatic by server version', $modes, [32, 32, 0, 0, 32, 0, 32, 0]);
check('graphics mode: stored values', array_map([MuDesk\Connections::class, 'gfxMode'], [true, false, 'on', 'off', 'auto', 'x', null]), ['on', 'auto', 'on', 'off', 'auto', 'auto', 'auto']);

// graphics pipeline capabilities: with H.264 in the browser, 10.7 is offered first (Windows only uses H.264 with 10.x)
(new ReflectionProperty(Client::class, 'channelIds'))->setValue($client, ['drdynvc' => 1007]);
(new ReflectionProperty(Client::class, 'channelOpts'))->setValue($client, ['drdynvc' => 0xC0000000]);
$caps = [];
foreach ([false, true] as $avc) {
    ftruncate($mem, 0);
    rewind($mem);
    $client->gfx = true;
    (new MuDesk\Rdp\Drdynvc($client, $avc))->onData("\x10\x05Microsoft::Windows::RDS::Graphics\0");
    rewind($mem);
    $out    = (string)stream_get_contents($mem);
    $caps[] = [str_contains($out, pack('VVV', 0x000A0701, 4, 0x80)), str_contains($out, pack('VVV', 0x00080105, 4, $avc ? 0x10 : 0))];
}
check('graphics capabilities: 10.7 only with H.264', $caps, [[false, true], [true, true]]);
// dynamic channel capabilities shorter than 4 bytes with the diagnostic trace on: answered, no warning or ValueError (that one
// ended the session) [tests/fuzz.php]
$notes = [];
set_error_handler(static function (int $no, string $msg) use (&$notes): bool {
    $notes[] = $msg;
    return true;
});
$client->trace = new MuDesk\Rdp\Trace(str_repeat('cd', 16), []);
$short         = [];
foreach (["\x50", "\x50\x00", "\x50\x00\x02"] as $pdu) {
    ftruncate($mem, 0);
    rewind($mem);
    try {
        (new MuDesk\Rdp\Drdynvc($client, false))->onData($pdu);
        rewind($mem);
        $short[] = str_ends_with((string)stream_get_contents($mem), "\x50\x00\x02\x00");
    } catch (Throwable $e) {
        $short[] = get_class($e);
    }
}
$client->trace->close([]);
$client->trace = null;
restore_error_handler();
check('dynamic channels: short capabilities answered, no warning', [$short, $notes], [[true, true, true], []]);
// numbers of the browser past the int range (1e20 in the JSON): no warning (PHP 8.5 warns when such a float is cast); a
// desktop of several monitors resized with the graphics pipeline keeps its whole width for Refresh Rect and Suppress
// Output (it was cut to 8192: three 4K screens side by side were not drawn again past x 8192)
$fnotes = [];
set_error_handler(static function (int $no, string $msg) use (&$fnotes): bool {
    $fnotes[] = $msg;
    return true;
});
$bigs = [MuDesk\Http::int(1e20), MuDesk\Http::int('-1e30'), MuDesk\Http::int('x', 7), MuDesk\Http::int(800),
    MuDesk\Rdp\Drdynvc::monitors([[0, 0, 1e20, 800, 1], [1e20, 0, 800, 600, 0]]), MuDesk\Rdp\Drdynvc::monitors([[0, 0, 1024.0, 768, 1], [1024, 0, 800, 600, 0]])];
restore_error_handler();
$wide = new Client(['width' => 800, 'height' => 600], static fn() => null);
$wide->gfx = true;
$wide->resized(11520, 2160);
check('browser numbers past the int range: no warning; a wide desktop of several monitors keeps its width',
    [$bigs, $fnotes, [$wide->width, $wide->height]],
    [[(int)1e15, (int)-1e15, 7, 800, null, [[0, 0, 1024, 768, true], [1024, 0, 800, 600, false]]], [], [11520, 2160]]);
// the same for a connection and the settings saved with such numbers (any signed-in user could send them)
$fnotes = [];
set_error_handler(static function (int $no, string $msg) use (&$fnotes): bool {
    $fnotes[] = $msg;
    return true;
});
$keepMax = MuDesk\Settings::get('login_max_attempts');
$c30 = MuDesk\Connections::save('u-float', ['host' => 'h.test', 'port' => 1e30, 'kbd' => 1e30, 'bpp' => 1e30], null);
$s30 = MuDesk\Settings::save(['login_max_attempts' => 1e30]);
$max30 = MuDesk\Settings::get('login_max_attempts');
restore_error_handler();
MuDesk\Settings::save(['login_max_attempts' => $keepMax]);
check('connections and settings with numbers past the int range: no warning', [$fnotes, $max30], [[], 100]);
// several monitors resized to their windows (message 13): the browser's list checked (one primary at 0,0, 2 to 16
// monitors of 200..8192 px) and sent as one Display Control layout with every monitor, widths even
$layouts = [];
foreach ([[[0, 0, 1024, 768, 1], [1024, 0, 1001, 700, 0]], [[0, 0, 1024, 768, 1]], [[0, 0, 1024, 768, 0], [1024, 0, 1000, 700, 0]],
          [[0, 0, 1024, 768, 1], [1024, 0, 100, 700, 0]], [[0, 0, 1024, 768, 1], [1024, 0, 1000, 700, 1]], 'x', [[0, 0, ['a'], 768, 1], [1024, 0, 1000, 700, 0]]] as $l) {
    $layouts[] = MuDesk\Rdp\Drdynvc::monitors((array)$l);
}
$dvc = new MuDesk\Rdp\Drdynvc($client, false);
(new ReflectionProperty(MuDesk\Rdp\Drdynvc::class, 'dispId'))->setValue($dvc, 9);
ftruncate($mem, 0);
rewind($mem);
$sentLayout = $dvc->resizeMonitors($layouts[0]);
rewind($mem);
$pdu = (string)stream_get_contents($mem);
check('several monitors: the browser list checked, one Display Control layout with all of them, the desktop their bounding box', [
    $layouts[0], array_slice($layouts, 1), $sentLayout,
    str_contains($pdu, pack('VVVV', 2, 96, 40, 2) . pack('VllVVVVVVV', 1, 0, 0, 1024, 768, 0, 0, 0, 100, 100) . pack('VllVVVVVVV', 0, 1024, 0, 1000, 700, 0, 0, 0, 100, 100)),
    MuDesk\Rdp\Drdynvc::bounds([[0, 0, 1024, 768, true], [-1000, 100, 1000, 700, false]])],
    [[[0, 0, 1024, 768, true], [1024, 0, 1001, 700, false]], [null, null, null, null, null, null], true, true, [2024, 800]]);

// microphone (MS-RDPEAI on the AUDIO_INPUT dynamic channel): taken only with the option; the gateway answers the version,
// the formats (16-bit PCM alone, mono first, highest rate first), the open (the format confirmed, then success) and a format
// change; the browser's audio goes in packets of the size asked for, each after an Incoming Data PDU, one longer than a chunk as
// Data First + Data; at most a second of it waits; a malformed message changes nothing and never ends the session; closed,
// the browser stops
$mframes = [];
$mc   = new Client(['width' => 800, 'height' => 600], static function (int $t, string $mi_p) use (&$mframes): void { $mframes[] = [$t, $mi_p]; });
$mmem = fopen('php://memory', 'w+b');
$mtr  = (new ReflectionClass(Transport::class))->newInstanceWithoutConstructor();
$mtr->sock = $mmem;
$mc->t = $mtr;
(new ReflectionProperty(Client::class, 'channelIds'))->setValue($mc, ['drdynvc' => 1007]);
(new ReflectionProperty(Client::class, 'channelOpts'))->setValue($mc, ['drdynvc' => 0xC0000000]);
$dvcOut = static function () use ($mmem): array {                       // the dynamic channel messages written: [cmd, id, message]
    rewind($mmem);
    $mi_o = (string)stream_get_contents($mmem);
    ftruncate($mmem, 0);
    rewind($mmem);
    $mi_msgs = [];
    $mi_part = [];
    for ($mi_p = 0; $mi_p + 4 <= strlen($mi_o);) {
        $mi_pkt = substr($mi_o, $mi_p, unpack('n', $mi_o, $mi_p + 2)[1]);
        $mi_q   = 4 + 3 + 6;
        $mi_q  += ord($mi_pkt[$mi_q]) & 0x80 ? 2 + 8 : 1 + 8;
        $mi_d   = substr($mi_pkt, $mi_q);
        $mi_p  += strlen($mi_pkt);
        $mi_h   = ord($mi_d[0]);
        $mi_var = static fn(int $cb, int $at) => match ($cb) { 0 => [ord($mi_d[$at]), 1], 1 => [unpack('v', $mi_d, $at)[1], 2], default => [unpack('V', $mi_d, $at)[1], 4] };
        [$mi_id, $mi_n] = $mi_var($mi_h & 3, 1);
        $mi_cmd = $mi_h >> 4;
        if ($mi_cmd === 2) {                                                  // Data First: its total length, then the rest in Data PDUs
            [$mi_len, $mi_m] = $mi_var(($mi_h >> 2) & 3, 1 + $mi_n);
            $mi_part[$mi_id] = [$mi_len, substr($mi_d, 1 + $mi_n + $mi_m), strlen($mi_pkt)];
            continue;
        }
        if ($mi_cmd === 3 && isset($mi_part[$mi_id])) {
            $mi_part[$mi_id][1] .= substr($mi_d, 1 + $mi_n);
            if (strlen($mi_part[$mi_id][1]) >= $mi_part[$mi_id][0]) {
                $mi_msgs[] = [2, $mi_id, $mi_part[$mi_id][1]];
                unset($mi_part[$mi_id]);
            }
            continue;
        }
        $mi_msgs[] = [$mi_cmd, $mi_id, substr($mi_d, 1 + $mi_n)];
    }
    return $mi_msgs;
};
$mi_create = "\x10\x07" . MuDesk\Rdp\Audin::NAME . "\0";
(new MuDesk\Rdp\Drdynvc($mc, false))->onData($mi_create);
$mi_refused = $dvcOut();
$mdvc = new MuDesk\Rdp\Drdynvc($mc, false, true);
$mdvc->onData($mi_create);
$mi_accepted = $dvcOut();
$mdvc->onData("\x30\x07" . pack('CV', 1, 2));                               // Version 2
$mi_version  = $dvcOut();
$mi_fmt      = static fn(int $tag, int $ch, int $rate, int $bits, string $extra = '') => pack('vvVVvvv', $tag, $ch, $rate, $rate * $ch * $bits / 8, $ch * $bits / 8, $bits, strlen($extra)) . $extra;
$mi_offer    = [$mi_fmt(1, 2, 44100, 16), $mi_fmt(1, 1, 22050, 16), $mi_fmt(2, 1, 22050, 4, str_repeat("\x01", 32)), $mi_fmt(1, 1, 44100, 16), $mi_fmt(1, 2, 44100, 8),
    $mi_fmt(0xA106, 2, 48000, 16), $mi_fmt(1, 1, 96000, 16), $mi_fmt(1, 1, 44100, 16)];
$mdvc->onData("\x30\x07" . pack('CVV', 2, count($mi_offer), 0) . implode('', $mi_offer));
$mi_formats  = $dvcOut();
$mdvc->onData("\x30\x07" . pack('CVV', 3, 441, 0) . $mi_fmt(1, 2, 44100, 16));   // Open: 441 frames, the first format agreed
$mi_opened   = [$dvcOut(), $mframes];
$mframes  = [];
$mdvc->micData(str_repeat("\x11", 2000));
$mi_data1    = $dvcOut();
$mdvc->micData(str_repeat("\x22", 646));                                       // 236 waiting + 646 = one more packet
$mi_data2    = $dvcOut();
$mdvc->onData("\x30\x07" . pack('CV', 7, 2));                                 // Format Change to the third: stereo 44.1 kHz
$mi_changed  = [$dvcOut(), $mframes];
$mdvc->micData(str_repeat("\x33", 1764 * 3));                                 // 1764 bytes a packet now
$mi_data3    = $dvcOut();
$mdvc->onData("\x30\x07" . pack('CVV', 3, 8820, 2));                          // opened again with 8820 frames: 35 280-byte packets
$dvcOut();
$mdvc->micData(str_repeat("\x44", 176400 * 3));                               // 3 s at once: 1 s kept
$mi_big      = $dvcOut();
$mdvc->onData("\x30\x07" . pack('CVV', 3, 441, 9));                           // a format not agreed
$mi_badOpen  = $dvcOut();
$mframes  = [];
$mdvc->onData("\x30\x07" . pack('CVV', 2, 3, 0) . $mi_fmt(1, 1, 8000, 16));     // formats cut short: left out, no exception
$mi_short    = [$dvcOut(), $mframes];
$mdvc->onData("\x40\x07");                                                    // closed
$mi_closed   = [$dvcOut(), $mframes];
$mdvc->micData(str_repeat("\x55", 2000));
$mi_after    = $dvcOut();
$mi_infoFlags = static function (bool $mic): int {                               // the Client Info flags sent
    $mi_mem = fopen('php://memory', 'w+b');
    $mi_tr  = (new ReflectionClass(Transport::class))->newInstanceWithoutConstructor();
    $mi_tr->sock = $mi_mem;
    $mi_c   = new Client(['width' => 800, 'height' => 600, 'user' => 'u', 'domain' => '', 'password' => 'p', 'audio' => true, 'mic' => $mic, 'tzBias' => 0, 'perf' => 0], static fn() => null);
    $mi_c->t = $mi_tr;
    (new ReflectionMethod(Client::class, 'sendClientInfo'))->invoke($mi_c);
    rewind($mi_mem);
    $mi_o = (string)stream_get_contents($mi_mem);
    $mi_q = 4 + 3 + 6;
    $mi_q += ord($mi_o[$mi_q]) & 0x80 ? 2 : 1;
    return unpack('V', $mi_o, $mi_q + 8)[1];
};
$mi_pkt = static fn(array $mi_msgs) => array_map(static fn($mi_m) => [$mi_m[0], ord($mi_m[2][0] ?? "\0"), strlen($mi_m[2])], $mi_msgs);
check('microphone: taken only with the option, the negotiation answered, packets of the size asked for, at most a second waiting', [
    $mi_refused[0][2] ?? null, $mi_accepted[0][2] ?? null, $mi_version[0][2] ?? null,
    array_map(static fn($mi_m) => bin2hex($mi_m[2]), $mi_formats),
    array_map(static fn($mi_m) => bin2hex($mi_m[2]), $mi_opened[0]), $mi_opened[1],
    $mi_pkt($mi_data1), $mi_pkt($mi_data2), [array_map(static fn($mi_m) => bin2hex($mi_m[2]), $mi_changed[0]), $mi_changed[1]], $mi_pkt($mi_data3),
    [count($mi_big), $mi_big[1][0] ?? null, strlen($mi_big[1][2] ?? ''), count($mi_big) / 2 * 35280],
    array_map(static fn($mi_m) => bin2hex($mi_m[2]), $mi_badOpen), $mi_short, $mi_closed, $mi_after,
    [$mi_infoFlags(false) & 0x00200000, $mi_infoFlags(true) & 0x00200000],
], [
    pack('V', 0xC0000001), pack('V', 0), pack('CV', 1, 1),
    ['05', bin2hex(pack('CVV', 2, 3, 9 + 3 * 18) . $mi_fmt(1, 1, 44100, 16) . $mi_fmt(1, 1, 22050, 16) . $mi_fmt(1, 2, 44100, 16))],
    [bin2hex(pack('CV', 7, 0)), bin2hex(pack('CV', 4, 0))], [[Client::F_MIC, pack('CVC', 1, 44100, 1)]],
    [[3, 5, 1], [3, 6, 883], [3, 5, 1], [3, 6, 883]], [[3, 5, 1], [3, 6, 883]],
    [[bin2hex(pack('CV', 7, 2))], [[Client::F_MIC, pack('CVC', 1, 44100, 2)]]], [[3, 5, 1], [2, 6, 1765], [3, 5, 1], [2, 6, 1765], [3, 5, 1], [2, 6, 1765]],
    [10, 2, 35281, 176400],
    [bin2hex(pack('CV', 4, 0x80070057))], [[], []], [[[4, 7, '']], [[Client::F_MIC, "\0"]]], [],
    [0, 0x00200000],
]);
// a dynamic channel message that fits in one 1600-byte chunk (1598 bytes with a one-byte id) in one Data PDU; a longer one as Data First with its length (2 bytes up to
// 65535, 4 past it) and Data PDUs, each within a channel chunk of 1600 bytes
$mi_sendData = new ReflectionMethod(MuDesk\Rdp\Drdynvc::class, 'sendData');
$mi_sizes = [];
foreach ([[7, 1598], [7, 1599], [300, 1597], [300, 1598], [7, 70000]] as [$mi_id, $mi_len]) {
    $mi_msg = random_bytes($mi_len);
    $mi_sendData->invoke($mdvc, $mi_id, $mi_msg);
    rewind($mmem);
    $mi_raw = (string)stream_get_contents($mmem);
    $mi_got = $dvcOut();
    $mi_chunks = [];
    for ($mi_p = 0; $mi_p + 4 <= strlen($mi_raw); $mi_p += unpack('n', $mi_raw, $mi_p + 2)[1]) {
        $mi_pk = substr($mi_raw, $mi_p, unpack('n', $mi_raw, $mi_p + 2)[1]);
        $mi_q  = 4 + 3 + 6;
        $mi_q += ord($mi_pk[$mi_q]) & 0x80 ? 2 + 8 : 1 + 8;
        $mi_chunks[] = strlen($mi_pk) - $mi_q;
    }
    $mi_sizes[] = [count($mi_got), ($mi_got[0][2] ?? '') === $mi_msg, max($mi_chunks) <= 1600, count($mi_chunks)];
}
// a server asking for 1 frame a packet gets 10 ms ones: two messages for every 2 bytes were 88,200 a second of audio, and
// took the gateway's time [tests/fuzz.php]
$mi_tiny = new MuDesk\Rdp\Drdynvc($mc, false, true);
$mi_tiny->onData($mi_create);
$mi_tiny->onData("\x30\x07" . pack('CVV', 2, 1, 0) . $mi_fmt(1, 1, 44100, 16));
$mi_tiny->onData("\x30\x07" . pack('CVV', 3, 1, 0) . $mi_fmt(1, 1, 44100, 16));
$dvcOut();
$mi_tiny->micData(str_repeat("\x01", 88200));
$mi_sent = $dvcOut();
check('microphone: packets of 10 ms at least, whatever the server asks', [count($mi_sent), strlen($mi_sent[1][2] ?? '')], [200, 883]);
// a Format Change before the Open: left out (there was no packet size yet: the browser's audio looped for ever) [tests/fuzz.php]
$mi_early = new MuDesk\Rdp\Drdynvc($mc, false, true);
$mi_early->onData($mi_create);
$mi_early->onData("\x30\x07" . pack('CVV', 2, 1, 0) . $mi_fmt(1, 1, 44100, 16));
$dvcOut();
$mframes = [];
$mi_early->onData("\x30\x07" . pack('CV', 7, 0));
$mi_early->micData(str_repeat("\x01", 2000));
check('microphone: a format change before the open left out', [$dvcOut(), $mframes], [[], []]);
check('dynamic channels: a long message split within 1600-byte chunks and joined back the same', $mi_sizes,
    [[1, true, true, 1], [1, true, true, 2], [1, true, true, 1], [1, true, true, 2], [1, true, true, 44]]);

// diagnostic trace: header, notes, statistics, size limit, permissions, cleanup of old traces
$tr = new MuDesk\Rdp\Trace('0123456789abcdef0123456789abcdef', ['user' => 'u1']);
$tr->log('x224', ['selected' => 1]);
for ($i = 0; $i < 5; $i++) {
    $tr->once('k', 'unknown', ['n' => $i]);
    $tr->count('fast:bitmap', 100);
}
$tr->close(['reason' => 'user']);
$log = (string)file_get_contents("$tmp/data/trace/0123456789abcdef0123456789abcdef.log");
check('trace: lines written', [substr_count($log, ' unknown '), str_contains($log, '"fast:bitmap":"5 msgs / 500 B"'), str_contains($log, ' end {"reason":"user"}'),
    PHP_OS_FAMILY === 'Windows' || decoct(fileperms("$tmp/data/trace/0123456789abcdef0123456789abcdef.log") & 0777) === '600'], [3, true, true, true]);
$big = new MuDesk\Rdp\Trace('fedcba9876543210fedcba9876543210', []);
for ($i = 0; $i < 70000; $i++) {
    $big->log('filler', ['x' => str_repeat('a', 100)]);
}
$big->close([]);
check('trace: size limited to 8 MB', filesize("$tmp/data/trace/fedcba9876543210fedcba9876543210.log") < 8 * 1048576 + 200, true);
touch("$tmp/data/trace/fedcba9876543210fedcba9876543210.log", time() - 8 * 86400);
MuDesk\Rdp\Trace::cleanup();
check('trace: removed after 7 days', [is_file("$tmp/data/trace/fedcba9876543210fedcba9876543210.log"), is_file("$tmp/data/trace/0123456789abcdef0123456789abcdef.log")], [false, true]);
// traces: 256 MB of them in all at most (any user can ask for one, 8 MB each): a new one is not made past that
$room = [MuDesk\Rdp\Trace::room()];
$fill = fopen("$tmp/data/trace/" . str_repeat('9', 32) . '.log', 'w');
ftruncate($fill, 250 << 20);
fclose($fill);
$room[] = MuDesk\Rdp\Trace::room();
unlink("$tmp/data/trace/" . str_repeat('9', 32) . '.log');
check('trace: none made past 256 MB of traces in all', $room, [true, false]);

// licensing: a terminal server certificate whose RSA key is declared as md5WithRSAEncryption, as Windows license servers may
// issue it: OpenSSL gave no key and the connection ended as an internal error; a platform challenge before the license request
$rsaKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 1024, 'config' => $cnf]);
$rsaOpt = ['config' => $cnf, 'digest_alg' => 'sha256'];
openssl_x509_export(openssl_csr_sign(openssl_csr_new(['commonName' => 'ts'], $rsaKey, $rsaOpt), null, $rsaKey, 1, $rsaOpt), $tsPem);
$tsDer = base64_decode(preg_replace('~-----[^-]+-----|\s~', '', $tsPem));
$tsDer = substr_replace($tsDer, "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x04", (int)strpos($tsDer, "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01"), 11);
try {
    $tsKey = (new ReflectionMethod(MuDesk\Rdp\Licensing::class, 'publicKey'))->invoke(null, pack('VVV', 2, 1, strlen($tsDer)) . $tsDer . str_repeat("\0", 12));
} catch (Throwable $e) {
    $tsKey = get_class($e);
}
try {
    (new MuDesk\Rdp\Licensing('u', 'm'))->handle(0x02, new MuDesk\Rdp\Reader(pack('Vvv', 0, 9, 4) . 'abcd' . str_repeat("\0", 16)));
    $e = null;
} catch (Throwable $e) {
}
$rsa = openssl_pkey_get_details($rsaKey)['rsa'];
check('licensing: X.509 RSA key whatever its algorithm OID, platform challenge first refused', [$tsKey, $e instanceof MuDesk\Rdp\ProtocolException],
    [[ltrim($rsa['n'], "\0"), ltrim($rsa['e'], "\0")], true]);

// NLA: the TSRequest length announced by the server is bounded (2 GB were buffered for 20 s, or until the memory ran out)
$dsrv  = stream_socket_server('tcp://127.0.0.1:0');
$dport = (int)substr((string)strrchr((string)stream_socket_get_name($dsrv, false), ':'), 1);
$dt    = new Transport('127.0.0.1', $dport);
$dconn = stream_socket_accept($dsrv, 5);
fwrite($dconn, "\x30\x84\x7f\xff\xff\xff" . str_repeat("\0", 1000));
$t0 = microtime(true);
try {
    $dt->recvDer();
    $e = null;
} catch (Throwable $e) {
}
check('NLA: oversized TSRequest refused at once', [$e instanceof MuDesk\Rdp\ProtocolException ? $e->key : null, microtime(true) - $t0 < 2], ['protocol', true]);
$dt->close();
fclose($dconn);
fclose($dsrv);

// connection phase: a host that goes silent (before X.224, or in the TLS handshake) holds the PHP worker Transport::WAIT
// seconds, not 20 (each such session is a worker the web pages lack); both cases at once in two processes
$silent = [];
foreach (['$t->recvPdu();', '$t->startTls();'] as $step) {
    $ssrv = stream_socket_server('tcp://127.0.0.1:0');
    $sp   = proc_open([PHP_BINARY, '-r', 'require $argv[1]; $t = new MuDesk\Rdp\Transport("127.0.0.1", (int)$argv[2]); $t0 = microtime(true);'
        . " try { $step } catch (MuDesk\\Rdp\\ProtocolException \$e) { echo \$e->key, ' ', microtime(true) - \$t0; }",
        __DIR__ . '/../src/bootstrap.php', substr((string)strrchr((string)stream_socket_get_name($ssrv, false), ':'), 1)], [1 => ['pipe', 'w']], $spipes);
    $silent[] = [$sp, $spipes[1], $ssrv, stream_socket_accept($ssrv, 10)];
}
$waited = [];
foreach ($silent as [$sp, $sout, $ssrv, $sconn]) {
    [$key, $secs] = explode(' ', (string)stream_get_contents($sout)) + ['', 99];
    $waited[] = [$key, (float)$secs > 7 && (float)$secs < 9.5];
    fclose($sout);
    proc_close($sp);
    fclose($sconn);
    fclose($ssrv);
}
check('connection phase: a silent host given up after 8 s', $waited, [['timeout', true], ['tls', true]]);
// NLA: a server that drops the connection refused the credentials; one that goes silent (a slow domain controller, now
// that each step waits 8 s) is not answering, not a wrong password
$nla = new ReflectionMethod(Client::class, 'nlaFailure');
check('NLA: dropped is a wrong password, silent is no answer', array_map(static fn(string $k) => $nla->invoke(null,
    new MuDesk\Rdp\ProtocolException('x', $k))->key, ['closed', 'timeout', 'mitm']), ['auth_failed', 'timeout', 'mitm']);

// input endpoint: the session token is only taken from a header (in the URL it ended up in the access logs of the web server
// and proxies). rdp.php runs on PHP's web server with a bootstrap that loads the test data folder.
$app = "$tmp/app";
mkdir("$app/public", 0700, true);
mkdir("$app/src");
foreach (['rdp.php', 'files.php', 'api.php'] as $f) {
    copy(__DIR__ . "/../public/$f", "$app/public/$f");
}
$stub = <<<'PHP'
<?php
namespace MuDesk;

const VERSION = 'test';
spl_autoload_register(static function (string $class): void {
    $file = SRC . '/' . str_replace('\\', '/', substr($class, 7)) . '.php';
    if (str_starts_with($class, 'MuDesk\\') && is_file($file)) {
        require $file;
    }
});
Config::load(__DIR__ . '/../config.php');
PHP;
file_put_contents("$app/src/bootstrap.php", str_replace('SRC', var_export(realpath(__DIR__ . '/../src'), true), $stub));
file_put_contents("$app/config.php", '<?php return ["data_dir" => ' . var_export("$tmp/data", true) . '];');
$tsid = str_repeat('ab', 16);
file_put_contents("$tmp/data/run/$tsid.json", json_encode(['uid' => 'u1', 'token' => hash('sha256', 'secret-token')]));
file_put_contents("$tmp/data/run/$tsid.mode", 'file');
file_put_contents("$tmp/data/run/$tsid.q", '');
$probe = stream_socket_server('tcp://127.0.0.1:0');
$wport = (int)substr((string)strrchr((string)stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);
$nul = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$web = proc_open([PHP_BINARY, '-S', "127.0.0.1:$wport", '-t', "$app/public"], [1 => ['file', $nul, 'w'], 2 => ['file', $nul, 'w']], $wpipes);
for ($i = 0; $i < 100 && !($ws = @fsockopen('127.0.0.1', $wport)); $i++) {
    usleep(50000);
}
fclose($ws);
$status = static function (string $query, string $header) use ($wport): int {
    $s    = fsockopen('127.0.0.1', $wport, $en, $es, 5);
    $body = pack('V', 0) . "\x06";                                                  // sequence 0, refresh
    fwrite($s, "POST /rdp.php?$query HTTP/1.0\r\nHost: 127.0.0.1\r\n{$header}Content-Type: application/octet-stream\r\nContent-Length: "
        . strlen($body) . "\r\n\r\n$body");
    $r = (string)stream_get_contents($s);
    fclose($s);
    return (int)substr($r, 9, 3);
};
// a session started before the file kept the token's SHA-256 (it kept the token itself) still takes its input
$lsid = str_repeat('cd', 16);
$ltok = bin2hex(random_bytes(16));
file_put_contents("$tmp/data/run/$lsid.json", json_encode(['uid' => 'u1', 'token' => $ltok]));
file_put_contents("$tmp/data/run/$lsid.mode", 'file');
file_put_contents("$tmp/data/run/$lsid.q", '');
check('input endpoint: session token from a header only, checked against its SHA-256 (a session from before: the token)', [$status("a=send&sid=$tsid&t=secret-token", ''),
    $status("a=send&sid=$tsid", "X-MuDesk-Token: wrong\r\n"), $status("a=send&sid=$tsid", "X-MuDesk-Token: secret-token\r\n"),
    $status("a=send&sid=$tsid", 'X-MuDesk-Token: ' . hash('sha256', 'secret-token') . "\r\n"), $status("a=send&sid=$lsid", "X-MuDesk-Token: $ltok\r\n")],
    [404, 404, 204, 404, 204]);

// a browser signed in on the test copy (its session file); RDP hosts on this computer allowed
$wu    = MuDesk\Users::create('webuser', 'Web-user-' . bin2hex(random_bytes(6)), 'user', false);
$wsid  = bin2hex(random_bytes(16));
$wcsrf = bin2hex(random_bytes(16));
$ser   = static fn(string $v): string => 's:' . strlen($v) . ':"' . $v . '";';
file_put_contents("$tmp/data/sessions/sess_$wsid", 'uid|' . $ser($wu['id']) . 'username|' . $ser('webuser') . 'sv|i:1;since|i:' . time()
    . ';seen|i:' . time() . ';csrf|' . $ser($wcsrf) . 'bind|' . $ser(hash('sha256', 'test')));
MuDesk\Settings::save(['targets' => ['mode' => 'list', 'list' => ['127.0.0.1']]]);
$wreq   = static function (string $query, string $body = '') use ($wport, $wsid, $wcsrf) {   // the request sent: its socket
    $s = fsockopen('127.0.0.1', $wport, $en, $es, 5);
    fwrite($s, "POST /$query HTTP/1.0\r\nHost: 127.0.0.1\r\nUser-Agent: test\r\nCookie: MUDESKSID=$wsid\r\nX-CSRF: $wcsrf\r\n"
        . "Content-Type: application/octet-stream\r\nContent-Length: " . strlen($body) . "\r\n\r\n$body");
    return $s;
};
$wres   = static function ($s): array {                                    // [status, body]
    $r = (string)stream_get_contents($s);
    fclose($s);
    return [(int)substr($r, 9, 3), substr($r, (int)strpos($r, "\r\n\r\n") + 4)];
};
$files  = static fn(string $a, string $p, string $more = '', string $body = ''): int => $wres($wreq("files.php?a=$a&p=" . rawurlencode($p) . $more, $body))[0];
$wconn  = static fn(int $port): string => MuDesk\Connections::save($wu['id'], ['host' => '127.0.0.1', 'port' => $port, 'security' => 'tls',
    'clipboard' => false, 'drive' => false, 'printer' => false, 'audio' => false], null)['id'];
$stream = static fn(string $c, string $sid) => $wreq('rdp.php?a=stream', json_encode(['c' => $c, 'sid' => $sid, 'w' => 800, 'h' => 600]));
$host   = static function (string $then) use (&$hpipes) {                  // a host on this computer: [process, port]
    $p = proc_open([PHP_BINARY, '-r', '$s = stream_socket_server("tcp://127.0.0.1:0"); echo stream_socket_get_name($s, false), "\n";'
        . ' $c = stream_socket_accept($s, 30); echo "in\n";' . $then], [1 => ['pipe', 'w']], $hpipes);
    $a = trim((string)fgets($hpipes[1]));
    return [$p, (int)substr($a, (int)strrpos($a, ':') + 1)];
};

// file manager: a name the file system refuses is a 400, never an error 500 with a stack trace in the log; what is not there a 404
$ro = MuDesk\Files::root($wu['id']) . '/ro.txt';
file_put_contents($ro, 'abc');
chmod($ro, 0444);
$roWritable = is_writable($ro);
$roCode     = $files('upload', 'ro.txt', '&o=3', 'more');                  // appending to a file that can not be written
chmod($ro, 0644);
check('file manager: refused names are a 400 (no error 500), what is missing a 404', [$files('upload', str_repeat('n', 256), '', 'data'), $roCode,
    $files('delete', 'nothing.txt'), $files('upload', 'nodir/a.txt', '', 'data'), $files('mkdir', 'nodir', '&n=x')], [400, $roWritable ? 200 : 400, 404, 404, 404]);

// gateway full (max_sessions_total, with the sessions of every user): 503 'busy' before any output and before opening a socket
MuDesk\Settings::save(['max_sessions_total' => count(MuDesk\Sessions::active())]);
$bsid = bin2hex(random_bytes(16));
[$bcode, $bbody] = $wres($stream($wconn(9), $bsid));
MuDesk\Settings::save(['max_sessions_total' => 0]);
check('gateway full: 503 busy before any output, the session not registered', [$bcode, $bbody, MuDesk\Sessions::info($bsid)], [503, '{"error":"busy"}', null]);

// a browser that leaves while the host is silent gives the PHP worker back at once (it was held until the timeout): the host sees
// the gateway close the connection
[$hp, $hport] = $host(' while (($d = fread($c, 8192)) !== false && $d !== "") {} echo "out\n";');
$bsid = bin2hex(random_bytes(16));
$bs   = $stream($wconn($hport), $bsid);
fgets($hpipes[1]);                                                          // connected: the gateway waits for the X.224 answer
// meanwhile: the session's file keeps the SHA-256 of the input token the browser got, never the token
$btok = preg_match('/"token":"([0-9a-f]{32})"/', (string)fread($bs, 65536), $bm) ? $bm[1] : '';
$bfil = (string)@file_get_contents("$tmp/data/run/$bsid.json");
check('input token: the session file keeps its SHA-256, not the token', [$btok !== '', str_contains($bfil, $btok),
    (json_decode($bfil, true)['token'] ?? '') === hash('sha256', $btok)], [true, false, true]);
fclose($bs);
$t0 = microtime(true);
check('browser gone during the connection: the worker is freed at once', [trim((string)fgets($hpipes[1])), microtime(true) - $t0 < 4], ['out', true]);
fclose($hpipes[1]);
proc_close($hp);

// shared folder quota (1 MB here): one counter for the drive of each session, the file manager and clipboard files (each one counted
// on its own: together they passed it); clipboard files give it back with their session; files changed by other means are counted
// again when the file manager lists the folder
MuDesk\Settings::save(['files_quota_mb' => 1]);
$esid = bin2hex(random_bytes(16));                                          // clipboard file of a session that ends
mkdir("$tmp/data/tmp/$esid");
file_put_contents("$tmp/data/tmp/$esid/0", str_repeat('x', 300000));
MuDesk\Files::charge($wu['id'], 300000);
$held = MuDesk\Files::quotaLeft($wu['id']);
[$hp, $hport] = $host(' fclose($c);');
$wres($stream($wconn($hport), $esid));
proc_close($hp);
foreach (['channelIds' => ['rdpdr' => 1004], 'channelOpts' => ['rdpdr' => 0x80800000]] as $prop => $val) {
    (new ReflectionProperty(Client::class, $prop))->setValue($client, $val);
}
$wdrive = new Rdpdr($client, $wu['id'], true, false, $dir);                // a session started before the others write
$csid   = bin2hex(random_bytes(16));
$csess  = MuDesk\Sessions::register($csid, ['uid' => $wu['id'], 'token' => 't']);
check('shared quota: drive, file manager and clipboard files together, clipboard files given back with their session',
    [MuDesk\Files::quotaLeft($wu['id']) - $held, $files('upload', 'up.bin', '', str_repeat('u', 400000)),
     $wres($wreq("rdp.php?a=clipfile&sid=$csid&n=0&o=0", str_repeat('c', 400000)))[0], $put($wdrive, '\d.bin', 400000), $put($wdrive, '\e.bin', 200000)],
    [300000, 200, 200, 0xC000007F, 0]);
$wdrive->release();
$csess->close();
unlink(MuDesk\Files::root($wu['id']) . '/up.bin');                          // removed by other means
$before = MuDesk\Files::quotaLeft($wu['id']);
$files('list', '', '&count=1');
check('shared quota: counted again when the file manager lists the folder', [$before < 100000, MuDesk\Files::quotaLeft($wu['id'])],
    [true, 1048576 - MuDesk\Files::usage($wu['id'])]);
// a file replaced by a smaller version near the quota: the old one's room counts (it was refused, 400 quota)
foreach (glob(MuDesk\Files::root($wu['id']) . '/*') ?: [] as $wf) {
    MuDesk\Files::remove($wf);
}
$files('list', '', '&count=1');                                                // counted again: what was removed by other means
$rep = [$files('upload', 'report.pdf', '&o=0&last=1', str_repeat('a', 900 * 1024)), $files('upload', 'report.pdf', '&o=0&last=1', str_repeat('b', 300 * 1024)),
    (int)@filesize(MuDesk\Files::root($wu['id']) . '/report.pdf')];
check('shared quota: a file replaced by a smaller version near the quota', $rep, [200, 200, 300 * 1024]);
// a list of a folder no longer walks the whole folder (seconds with many files, under the counter's lock): it shows the
// counter; the folder is counted again when the file manager opens or refreshes (count=1)
$listUsage = static function (string $more) use ($wreq, $wres): ?int {
    [, $b] = $wres($wreq('files.php?a=list&p=' . $more));
    return json_decode($b, true)['usage'] ?? null;
};
file_put_contents(MuDesk\Files::root($wu['id']) . '/other.bin', str_repeat('o', 1000));   // by other means
check('shared quota: a list shows the counter; the folder counted again only when asked',
    [$listUsage(''), $listUsage('&count=1'), $listUsage('')], [300 * 1024, 301 * 1024 + 1000 - 1024, 301 * 1024 + 1000 - 1024]);
unlink(MuDesk\Files::root($wu['id']) . '/other.bin');
MuDesk\Files::remove(MuDesk\Files::root($wu['id']) . '/report.pdf');
MuDesk\Settings::save(['files_quota_mb' => 500]);

// file manager over the web: moved (audited), folders created at once, a folder as a ZIP with its length, images shown as
// such for the thumbnails (bitmaps only, in a sandbox, never SVG)
$wroot = MuDesk\Files::root($wu['id']);
mkdir("$wroot/web/in", 0777, true);
file_put_contents("$wroot/web/in/p.png", "\x89PNG\r\n\x1a\nxyz");
file_put_contents("$wroot/web/s.svg", '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
$wget = static function (string $query) use ($wport, $wsid): array {      // [status, headers, body]
    $s = fsockopen('127.0.0.1', $wport, $en, $es, 5);
    fwrite($s, "GET /$query HTTP/1.0\r\nHost: 127.0.0.1\r\nUser-Agent: test\r\nCookie: MUDESKSID=$wsid\r\n\r\n");
    $r = (string)stream_get_contents($s);
    fclose($s);
    $cut = (int)strpos($r, "\r\n\r\n");
    return [(int)substr($r, 9, 3), substr($r, 0, $cut), substr($r, $cut + 4)];
};
$wmoved = [$files('move', 'web/in', '&to=' . rawurlencode('web/out')), $files('move', 'web/out', '&to=' . rawurlencode('web/out/deeper')),
    $files('mkdirs', 'web', '', json_encode(['n1/n2', 'n3'])), $files('mkdirs', 'web', '', '{"a":"x"}')];
[$tc, $tb] = $wres($wreq('files.php?a=tree&p=', json_encode(['', 'web'])));
[$zc, $zh, $zb] = $wget('files.php?a=zip&p=web&n[]=out&n[]=n1');
$zl = preg_match('/Content-Length: (\d+)/i', $zh, $m) ? (int)$m[1] : -1;
[$vc, $vh] = $wget('files.php?a=download&view=1&p=' . rawurlencode('web/out/p.png'));
check('file manager over the web: moved and audited, folders at once, a ZIP with its length, bitmaps shown in a sandbox and SVG never',
    [$wmoved, $tc, json_decode($tb, true)['dirs'][1] ?? null, $files('tree', '', '', '{"a":1}'), is_dir("$wroot/web/n1/n2"), MuDesk\Audit::search(['event' => 'file_move'])['total'] === 1, $zc, $zl === strlen($zb),
     array_keys($unzip($zb)), $vc, (bool)preg_match('~Content-Type: image/png~i', $vh), (bool)preg_match("~Content-Security-Policy: default-src 'none'; sandbox~i", $vh),
     $wget('files.php?a=download&view=1&p=' . rawurlencode('web/s.svg'))[0], $wget('files.php?a=zip&p=web&n[]=nothing')[0]],
    [[200, 400, 200, 400], 200, ['web', ['n1', 'n3', 'out'], false], 400, true, true, 200, true, ['out/', 'out/p.png', 'n1/', 'n1/n2/'], 200, true, true, 404, 404]);

// the web session ending: the page asks how long its session has left without that counting as activity (it would keep
// an idle session open), and a request on a session the gateway has just closed says why (idle, 12 hours), so the page
// can say so; the next request, closed already, has no reason
$sessAt = static function (int $seen, int $since) use ($tmp, $wu, $ser): array {    // a signed-in browser: [sid, csrf]
    [$sid, $csrf] = [bin2hex(random_bytes(16)), bin2hex(random_bytes(16))];
    file_put_contents("$tmp/data/sessions/sess_$sid", 'uid|' . $ser($wu['id']) . 'username|' . $ser('webuser') . "sv|i:1;since|i:$since;seen|i:$seen;csrf|"
        . $ser($csrf) . 'bind|' . $ser(hash('sha256', 'test')));
    return [$sid, $csrf];
};
$areq = static function (array $who, string $path, string $body) use ($wport): array {   // [status, answer]
    $s = fsockopen('127.0.0.1', $wport, $en, $es, 5);
    fwrite($s, "POST /$path HTTP/1.0\r\nHost: 127.0.0.1\r\nUser-Agent: test\r\nCookie: MUDESKSID={$who[0]}\r\nX-CSRF: {$who[1]}\r\n"
        . "Content-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n\r\n$body");
    $r = (string)stream_get_contents($s);
    fclose($s);
    return [(int)substr($r, 9, 3), json_decode(substr($r, (int)strpos($r, "\r\n\r\n") + 4), true)];
};
$seenOf = static fn(array $who): int => preg_match('/seen\|i:(\d+);/', (string)file_get_contents("$tmp/data/sessions/sess_{$who[0]}"), $m) ? (int)$m[1] : -1;
$alive  = $sessAt(time() - 100, time() - 1000);
[$lc, $lj] = $areq($alive, 'api.php', '{"action":"session.left"}');
$seenAfterLeft = $seenOf($alive);
[, $st] = $areq($alive, 'api.php', '{"action":"state"}');
$seenAfterState = $seenOf($alive);
$idle = $sessAt(time() - 31 * 60, time() - 3000);
$long = $sessAt(time() - 10, time() - 43300);
$idleLeft = $sessAt(time() - 31 * 60, time() - 3000);
check('web session: how long it has left asked without counting as activity; a request on a session just closed says why (idle, 12 hours), also past the CSRF check; the next one is a CSRF refusal',
    [$lc, $lj['left'] >= 1690 && $lj['left'] <= 1700, time() - $seenAfterLeft >= 99, $st['session']['idle'], abs($st['session']['max'] - 42200) <= 5,
     time() - $seenAfterState <= 2, $areq($idle, 'api.php', '{"action":"conns.list"}'), $areq($idle, 'api.php', '{"action":"conns.list"}'),
     $areq($long, 'files.php?a=list&p=', ''), $areq($idleLeft, 'api.php', '{"action":"session.left"}')],
    [200, true, true, 1800, true, true, [401, ['error' => 'auth', 'reason' => 'idle']], [403, ['error' => 'csrf']],
     [401, ['error' => 'auth', 'reason' => 'max_duration']], [401, ['error' => 'auth', 'reason' => 'idle']]]);
// the remote sessions of a web session end with it: closed by the gateway (idle) or signed out (they went on, with nobody signed
// in, until their own idle limit)
$withWid = static function (string $wid, int $seen) use ($tmp, $wu, $ser): array {   // a signed-in browser with its sign-in id: [sid, csrf]
    [$sid, $csrf] = [bin2hex(random_bytes(16)), bin2hex(random_bytes(16))];
    file_put_contents("$tmp/data/sessions/sess_$sid", 'uid|' . $ser($wu['id']) . 'username|' . $ser('webuser') . 'sv|i:1;since|i:' . (time() - 3000)
        . ";seen|i:$seen;csrf|" . $ser($csrf) . 'bind|' . $ser(hash('sha256', 'test')) . 'wid|' . $ser($wid));
    return [$sid, $csrf];
};
$rIdle = MuDesk\Sessions::register(bin2hex(random_bytes(16)), ['uid' => $wu['id'], 'user' => 'webuser', 'token' => 't', 'wid' => 'wid-idle']);
$rOut  = MuDesk\Sessions::register(bin2hex(random_bytes(16)), ['uid' => $wu['id'], 'user' => 'webuser', 'token' => 't', 'wid' => 'wid-out']);
$rKeep = MuDesk\Sessions::register(bin2hex(random_bytes(16)), ['uid' => $wu['id'], 'user' => 'webuser', 'token' => 't', 'wid' => 'wid-other']);
$byIdle = $areq($withWid('wid-idle', time() - 31 * 60), 'api.php', '{"action":"conns.list"}');
$byOut  = $areq($withWid('wid-out', time()), 'api.php', '{"action":"logout"}')[0];
check('web session: its remote sessions end when it is closed for being idle or signed out, not those of another sign-in',
    [$byIdle, $rIdle->read(), $byOut, $rOut->read(), $rKeep->read()],
    [[401, ['error' => 'auth', 'reason' => 'idle']], ["\x09signed_out"], 200, ["\x09signed_out"], []]);
foreach ([$rIdle, $rOut, $rKeep] as $r) {
    $r->close();
}

// file manager over the web: a ZIP asked for first with its names in the body (CSRF checked; hundreds of names did not fit in an
// address), then downloaded by the id it got; a thumbnail revalidated (304) and never kept without asking; a list (p[]=x) where
// a text goes is no text (a TypeError, error 500); a request without a session cookie is refused before a session is made
$raw = static function (string $req) use ($wport): array {                  // [status, headers, body]
    $s = fsockopen('127.0.0.1', $wport, $en, $es, 5);
    fwrite($s, $req);
    $r = (string)stream_get_contents($s);
    fclose($s);
    $cut = (int)strpos($r, "\r\n\r\n");
    return [(int)substr($r, 9, 3), substr($r, 0, $cut), substr($r, $cut + 4)];
};
[$zc1, $zj] = $areq([$wsid, $wcsrf], 'files.php?a=zip&check=1&p=web', json_encode(['out', 'n1']));
[$zc2, , $zb2] = $wget('files.php?a=zip&p=web&z=' . ($zj['id'] ?? ''));
[, $vh1] = $wget('files.php?a=download&view=1&p=' . rawurlencode('web/out/p.png'));
$lm   = preg_match('/Last-Modified: ([^\r\n]+)/i', $vh1, $m) ? $m[1] : '-';
$v304 = $raw('GET /files.php?a=download&view=1&p=' . rawurlencode('web/out/p.png') . " HTTP/1.0\r\nHost: 127.0.0.1\r\nUser-Agent: test\r\n"
    . "Cookie: MUDESKSID=$wsid\r\nIf-Modified-Since: $lm\r\n\r\n");
check('file manager over the web: a ZIP asked for with its names in the body, then downloaded by its id; a thumbnail revalidated',
    [$zc1, ($zj['size'] ?? -1) === strlen($zb2), $zc2, array_keys($unzip($zb2)), $areq([$wsid, 'not-the-token'], 'files.php?a=zip&check=1&p=web', '["out"]')[0],
     $areq([$wsid, $wcsrf], 'files.php?a=zip&check=1&p=web', json_encode(array_fill(0, 10001, 'out')))[0], $wget('files.php?a=zip&p=web&z=unknown')[0],
     (bool)preg_match('/Cache-Control: private, no-cache/i', $vh1), $v304[0], $v304[2]],
    [200, true, 200, ['out/', 'out/p.png', 'n1/', 'n1/n2/'], 403, 404, 404, true, 304, '']);
check('file manager over the web: a ZIP id used once (its list of names leaves the session)', $wget('files.php?a=zip&p=web&z=' . ($zj['id'] ?? ''))[0], 404);
$sessCount = static fn(): int => count(glob("$tmp/data/sessions/sess_*") ?: []);
$before = $sessCount();
$nc1 = $raw("POST /files.php?a=list&p= HTTP/1.0\r\nHost: 127.0.0.1\r\nUser-Agent: test\r\nContent-Length: 0\r\n\r\n");
$nc2 = $raw("POST /api.php HTTP/1.0\r\nHost: 127.0.0.1\r\nUser-Agent: test\r\nContent-Type: application/json\r\nContent-Length: 23\r\n\r\n"
    . '{"action":"conns.list"}');
$nc3 = $raw("POST /rdp.php?a=stream HTTP/1.0\r\nHost: 127.0.0.1\r\nUser-Agent: test\r\nContent-Length: 0\r\n\r\n");
// a JSON body past 4 MB, before signing in: refused (413) before decoding it (an 8 MB list took 150 MB, decoded twice)
$bigBody = '[' . str_repeat('0,', (5 << 20) / 2) . '0]';
$nc4 = $raw("POST /api.php HTTP/1.0\r\nHost: 127.0.0.1\r\nUser-Agent: test\r\nContent-Type: application/json\r\nContent-Length: " . strlen($bigBody)
    . "\r\n\r\n$bigBody");
check('requests: a body past 4 MB refused before it is decoded', [$nc4[0], json_decode($nc4[2], true)], [413, ['error' => 'too_large']]);
// a session asked for with a list where a text goes: refused as invalid (it was the text "Array", with a PHP warning)
$lconn = $wconn(9);
$lists = array_map(static fn(array $b): array => [($r = $wres($wreq('rdp.php?a=stream', json_encode($b))))[0], json_decode($r[1], true)['error'] ?? null],
    [['c' => $lconn, 'sid' => [bin2hex(random_bytes(16))]], ['c' => [$lconn], 'sid' => bin2hex(random_bytes(16))],
     ['c' => $lconn, 'sid' => bin2hex(random_bytes(16)), 'password' => ['x']], ['c' => $lconn, 'sid' => bin2hex(random_bytes(16)), 'w' => [800]]]);
check('requests: a list where the gateway takes a text or a number refused', $lists, array_fill(0, 4, [400, 'invalid_input']));
check('requests: a list where a text goes is no text; without a session cookie refused, no session made',
    [$wres($wreq('files.php?a=list&p[]=x'))[0], $wres($wreq('files.php?a[]=list&p=x'))[0], $nc1[0], json_decode($nc1[2], true), $nc2[0],
     json_decode($nc2[2], true), $nc3[0], $sessCount() === $before], [200, 404, 401, ['error' => 'auth'], 403, ['error' => 'csrf'], 401, true]);

// connections of the administrator over the web: created for a user, who sees it marked and cannot delete or change it; the
// administrator lists every user's own connections, changes and deletes them; all of it audited; a user cannot use the
// administration actions
$wa    = MuDesk\Users::create('webadmin', 'Web-admin-' . bin2hex(random_bytes(6)), 'admin', false);
[$asid, $acsrf] = [bin2hex(random_bytes(16)), bin2hex(random_bytes(16))];
file_put_contents("$tmp/data/sessions/sess_$asid", 'uid|' . $ser($wa['id']) . 'username|' . $ser('webadmin') . 'sv|i:1;since|i:' . time()
    . ';seen|i:' . time() . ';csrf|' . $ser($acsrf) . 'bind|' . $ser(hash('sha256', 'test')));
$asAdmin = static fn(array $b): array => $areq([$asid, $acsrf], 'api.php', json_encode($b));
$asUser  = static fn(array $b): array => $areq([$wsid, $wcsrf], 'api.php', json_encode($b));

// the last enabled administrator, counted under the lock of the users: a disabled administrator can be changed and deleted
// while a single one is enabled (both were refused as "last administrator"); the enabled one cannot be demoted; a name
// taken meanwhile is not created twice (two administrators creating it at once made two accounts)
$off = MuDesk\Users::create('offadmin', 'Off-admin-' . bin2hex(random_bytes(6)), 'admin', false);
MuDesk\Users::update($off['id'], static fn(array &$x) => $x['enabled'] = false);
$enabledAdmins = count(array_filter(MuDesk\Users::all(), static fn($u) => $u['role'] === 'admin' && $u['enabled']));
$offSave = $asAdmin(['action' => 'admin.user.save', 'id' => $off['id'], 'role' => 'admin', 'enabled' => false, 'max_sessions' => 3]);
$demote  = $asAdmin(['action' => 'admin.user.save', 'id' => $wa['id'], 'role' => 'user', 'enabled' => true]);
$offDel  = $asAdmin(['action' => 'admin.user.delete', 'id' => $off['id']]);
check('administrators: the last enabled one kept (under the lock), a disabled one changed and deleted, a name never twice',
    [$enabledAdmins, $offSave[0], $offSave[1]['user']['max_sessions'] ?? null, $demote[0], $demote[1]['error'] ?? null, MuDesk\Users::get($wa['id'])['role'],
     $offDel[0], MuDesk\Users::get($off['id']), MuDesk\Users::create('WebAdmin', 'Web-admin-' . bin2hex(random_bytes(6)), 'user', false),
     count(array_filter(MuDesk\Users::all(), static fn($u) => strcasecmp($u['username'], 'webadmin') === 0))],
    [1, 200, 3, 400, 'last_admin', 'admin', 200, null, null, 1]);
[$mcode, $mj] = $asAdmin(['action' => 'admin.conn.save', 'conn' => ['name' => 'Servidor común', 'host' => 'rds.empresa', 'port' => 3389], 'users' => [$wu['id']]]);
$mid   = $mj['conn']['id'] ?? '';
$ownC  = MuDesk\Connections::save($wu['id'], ['name' => 'Propia', 'host' => 'pc.casa'], null);
[, $ul] = $asUser(['action' => 'conns.list']);
$listed = array_values(array_filter($ul['conns'] ?? [], static fn($c) => $c['id'] === $mid));
$del   = $asUser(['action' => 'conns.delete', 'id' => $mid]);
$chg   = $asUser(['action' => 'conns.save', 'conn' => ['id' => $mid, 'name' => 'Mío', 'host' => 'otro']]);
[, $al] = $asAdmin(['action' => 'admin.conns']);
$ofWu  = array_values(array_filter($al['users'] ?? [], static fn($u) => $u['id'] === $wu['id']))[0]['conns'] ?? [];
$upd   = $asAdmin(['action' => 'admin.user.conn.save', 'uid' => $wu['id'], 'conn' => ['id' => $ownC['id'], 'name' => 'Propia (revisada)', 'host' => 'pc.casa']]);
$udel  = $asAdmin(['action' => 'admin.user.conn.delete', 'uid' => $wu['id'], 'id' => $ownC['id']]);
$mdel  = $asAdmin(['action' => 'admin.conn.delete', 'id' => $mid]);
$events = array_column(MuDesk\Audit::search(['event' => 'admin_'])['rows'], 'event');
check('managed connections over the web: given to a user (marked, not deleted or changed by them), each user\'s own listed, changed and deleted by the administrator, audited; no administration for users',
    [$mcode, count($listed), $listed[0]['managed'] ?? null, $del, $chg[0], $chg[1]['error'] ?? null, in_array('Propia', array_column($ofWu, 'name'), true), $upd[0], $upd[1]['conn']['name'] ?? null,
     $udel[0], $mdel[0], array_values(array_intersect(['admin_conn_create', 'admin_user_conn_update', 'admin_user_conn_delete', 'admin_conn_delete'], $events)),
     $asUser(['action' => 'admin.conns'])[0]],
    [200, 1, true, [403, ['error' => 'managed']], 400, 'managed', true, 200, 'Propia (revisada)', 200, 200,
     ['admin_conn_create', 'admin_user_conn_update', 'admin_user_conn_delete', 'admin_conn_delete'], 403]);

proc_terminate($web);
proc_close($web);

// errors a visitor can meet, with display_errors on (as XAMPP ships): a page of ours in the browser's language when a
// browser opens the address, {"error": key} for the interface; never PHP's warning, a server path or a trace (those go
// to the error log). The real bootstrap on PHP's web server, two copies of the application (a config.php rewritten
// between requests could still be served from OPcache, built into PHP 8.5): one with its data folder under a file (it
// cannot be created on any system), one where a script throws and another runs out of memory.
$papp = "$tmp/papp";
foreach (['bad' => "$papp/bad/blocker/data", 'ok' => "$papp/ok/data"] as $copy => $data) {
    mkdir("$papp/$copy/public", 0700, true);
    mkdir("$papp/$copy/src");
    foreach (glob(__DIR__ . '/../src/*.php') as $f) {
        copy($f, "$papp/$copy/src/" . basename($f));
    }
    copy(__DIR__ . '/../public/index.php', "$papp/$copy/public/index.php");
    copy(__DIR__ . '/../public/api.php', "$papp/$copy/public/api.php");
    file_put_contents("$papp/$copy/config.php", '<?php return ["data_dir" => ' . var_export($data, true) . '];');
}
file_put_contents("$papp/bad/blocker", '');
file_put_contents("$papp/ok/public/boom.php", '<?php require __DIR__ . "/../src/bootstrap.php"; throw new RuntimeException("secret detail");');
file_put_contents("$papp/ok/public/oom.php", '<?php require __DIR__ . "/../src/bootstrap.php"; ini_set("memory_limit", "16M"); $s = str_repeat("x", 64 << 20);');
$probe = stream_socket_server('tcp://127.0.0.1:0');
$pport = (int)substr((string)strrchr((string)stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);
$pweb = proc_open([PHP_BINARY, '-d', 'display_errors=1', '-d', 'error_reporting=-1', '-d', 'log_errors=0', '-S', "127.0.0.1:$pport", '-t', $papp],
    [1 => ['file', $nul, 'w'], 2 => ['file', "$papp/server.log", 'w']], $ppipes);
for ($i = 0; $i < 100 && !($ws = @fsockopen('127.0.0.1', $pport)); $i++) {
    usleep(50000);
}
fclose($ws);
$page = static function (string $method, string $path, string $accept, string $lang = '') use ($pport, $papp): array {   // [status, body]
    $s = @fsockopen('127.0.0.1', $pport, $en, $es, 5);
    if ($s === false) {                     // the test server gone: its log in the failed check, not a fatal error
        return [0, 'test web server gone: ' . substr((string)@file_get_contents("$papp/server.log"), -1500)];
    }
    fwrite($s, "$method /$path HTTP/1.0\r\nHost: 127.0.0.1\r\nAccept: $accept\r\nAccept-Language: $lang\r\n"
        . ($method === 'POST' ? "Content-Type: application/json\r\nContent-Length: 18\r\n\r\n{\"action\":\"state\"}" : "\r\n"));
    $r = (string)stream_get_contents($s);
    fclose($s);
    return [(int)substr($r, 9, 3), substr($r, (int)strpos($r, "\r\n\r\n") + 4)];
};
$leaks = static fn(string $b): bool => (bool)preg_match('/Warning|Fatal|Stack trace|secret|mudesk-test-|\.php/i', $b);
[$s1, $b1] = $page('GET', 'bad/public/index.php', 'text/html,application/xhtml+xml', 'es-ES,es;q=0.9');
[$s2, $b2] = $page('GET', 'bad/public/index.php', 'text/html', 'en-GB');
[$s3, $b3] = $page('POST', 'bad/public/api.php', '*/*');
$log = (string)file_get_contents("$papp/server.log");
check('error page: data folder not writable (a page of ours, no PHP warning or path; JSON for the interface; detail in the log)', [
    $s1, str_contains($b1, '<h1>No se puede guardar en la carpeta de datos</h1>'), str_contains($b1, 'href="assets/app.css'), $leaks($b1),
    $s2, str_contains($b2, '<h1>Cannot save to the data folder</h1>'), $s3, $b3, str_contains($log, 'cannot create')],
    [500, true, true, false, 500, true, 500, '{"error":"data_dir"}', true]);
[$s4, $b4] = $page('GET', 'ok/public/boom.php', 'text/html', 'es');
[$s6, $b6] = $page('GET', 'ok/public/boom.php', 'application/json');
// index.php: the page's scripts and styles addressed with the fingerprint of their files (it changes with any of them, not with
// other files), and the state gives the same one: a page loaded before an update knows it is old (the version number stays
// the same between builds, so browsers kept old files)
mkdir("$papp/ok/public/assets");
file_put_contents("$papp/ok/public/assets/app.js", '// one');
file_put_contents("$papp/ok/public/assets/logo.png", 'x');
[, $h1] = $page('GET', 'ok/public/index.php', 'text/html', 'es');
[, $j1] = $page('POST', 'ok/public/api.php', '*/*');
file_put_contents("$papp/ok/public/assets/app.js", '// two, a little longer');
[, $h2] = $page('GET', 'ok/public/index.php', 'text/html', 'es');
file_put_contents("$papp/ok/public/assets/logo.png", 'another image');
[, $h3] = $page('GET', 'ok/public/index.php', 'text/html', 'es');
$fp = static fn(string $h): string => preg_match('~data-build="([0-9a-f]{10})"~', $h, $m) ? $m[1] : '';
check('page: scripts and styles addressed with the fingerprint of their files, the same in the state; it changes with them only', [
    $fp($h1) !== '', str_contains($h1, 'src="assets/app.js?v=' . MuDesk\VERSION . '-' . $fp($h1) . '"'), str_contains($h1, 'href="assets/app.css?v=' . MuDesk\VERSION . '-' . $fp($h1) . '"'),
    (json_decode($j1, true)['build'] ?? null) === $fp($h1), $fp($h2) !== $fp($h1), $fp($h3) === $fp($h2)], [true, true, true, true, true, true]);
// out of memory last: on PHP 8.1 at GitHub the built-in server stopped there, and the checks after it failed with it
[$s5, $b5] = $page('GET', 'ok/public/oom.php', 'text/html', 'en');
proc_terminate($pweb);
proc_close($pweb);
$log = (string)file_get_contents("$papp/server.log");
// (PHP 8.1's built-in server at GitHub, Ubuntu and Windows, stops on running out of memory before any page is sent, also
// without exit() in the shutdown function; it is a development server only. There the out-of-memory page is said not
// checkable instead of failing: it is checked with PHP 8.1 elsewhere and with 8.2 to 8.5 everywhere)
$oomHere = !($s5 === 0 && PHP_VERSION_ID < 80200);
if (!$oomHere) {
    echo "NOTE out-of-memory page not checkable here: PHP " . PHP_VERSION . "'s built-in server stopped on it\n";
}
check('error page: an exception or a fatal error (out of memory) gives our page, never the trace (logged)', [
    $s4, str_contains($b4, '<h1>Algo ha fallado</h1>'), $leaks($b4), $oomHere ? $s5 : 500, !$oomHere || str_contains($b5, '<h1>Something went wrong</h1>'), $oomHere && $leaks($b5),
    $s6, $b6, str_contains($log, 'secret detail'), str_contains($log, 'Allowed memory size')],
    [500, true, false, 500, true, false, 500, '{"error":"internal"}', true, true]);
$texts = [];
foreach (['data_dir', 'tmp_dir', 'php', 'https', 'client_ip', 'internal', 'other'] as $k) {
    foreach (['es', 'en'] as $l) {
        $h         = MuDesk\ErrorPage::html(403, $k, $l);
        $texts[]   = preg_match('#^<!doctype html><html lang="' . $l . '">.*<h1>[^<]+</h1><p>[^<]+</p><p class="muted">[^<]+</p>#', $h) === 1;
        $retries[] = str_contains($h, 'class="btn primary" href=""');
    }
}
check('error page: every error in both languages; "try again" only where reloading can help', [array_unique($texts), $retries],
    [[true], [true, true, false, false, true, true, false, false, false, false, true, true, true, true]]);

// PHP cannot write to its temporary folder (upload_tmp_dir and sys_temp_dir point nowhere): it drops a request body over
// 16 KB, with a warning, before the script starts. The answer says so (and the log), instead of going on with an empty
// body: an uploaded file was saved empty and answered as done. A small body still goes (PHP keeps it in memory).
// display_startup_errors off: when on (XAMPP, or PHP 8 without it in php.ini) PHP itself prints that warning, headers and
// all, before any script runs; nothing can take it back, and the page reads that answer as a failed upload anyway.
$probe = stream_socket_server('tcp://127.0.0.1:0');
$tport = (int)substr((string)strrchr((string)stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);
$tweb = proc_open([PHP_BINARY, '-d', "upload_tmp_dir=$papp/no-such-folder", '-d', "sys_temp_dir=$papp/no-such-folder", '-d', 'display_errors=1', '-d', 'display_startup_errors=0',
    '-d', 'error_reporting=-1', '-d', 'log_errors=0', '-S', "127.0.0.1:$tport", '-t', $papp], [1 => ['file', $nul, 'w'], 2 => ['file', "$papp/tmp-server.log", 'w']], $tpipes);
for ($i = 0; $i < 100 && !($ws = @fsockopen('127.0.0.1', $tport)); $i++) {
    usleep(50000);
}
fclose($ws);
$tpost = static function (string $body) use ($tport): array {   // [status, body]
    $s = fsockopen('127.0.0.1', $tport, $en, $es, 5);
    fwrite($s, "POST /ok/public/api.php HTTP/1.0\r\nHost: 127.0.0.1\r\nAccept: */*\r\nContent-Type: application/json\r\nContent-Length: "
        . strlen($body) . "\r\n\r\n$body");
    $r = (string)stream_get_contents($s);
    fclose($s);
    return [(int)substr($r, 9, 3), substr($r, (int)strpos($r, "\r\n\r\n") + 4)];
};
[$ts1, $tb1] = $tpost('{"action":"state","pad":"' . str_repeat('x', 20000) . '"}');
[$ts2, $tb2] = $tpost('{"action":"state"}');
proc_terminate($tweb);
proc_close($tweb);
$tlog = (string)file_get_contents("$papp/tmp-server.log");
check('PHP without a writable temporary folder: a body over 16 KB it dropped is refused with tmp_dir (and logged), a small one goes',
    [$ts1, $tb1, str_contains($tlog, 'cannot write to its temporary folder'), $ts2, isset(json_decode($tb2, true)['build'])],
    [500, '{"error":"tmp_dir"}', true, 200, true]);

// connection reset by the server while sending: TLS writes then return 0 and the socket stays writable (send() used to spin forever on Windows)
$key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'config' => $cnf]);
$opt = ['config' => $cnf, 'digest_alg' => 'sha256'];
openssl_x509_export(openssl_csr_sign(openssl_csr_new(['commonName' => 'test'], $key, $opt), null, $key, 1, $opt), $pem);
openssl_pkey_export($key, $kpem, null, $opt);
file_put_contents("$tmp/tls.pem", $pem . $kpem);
$srv  = stream_socket_server('tcp://127.0.0.1:0', $en, $es, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, stream_context_create(['ssl' => ['local_cert' => "$tmp/tls.pem"]]));
$port = (int)substr((string)strrchr((string)stream_socket_get_name($srv, false), ':'), 1);
$proc = proc_open([PHP_BINARY, '-r', 'require $argv[1]; $t = new MuDesk\Rdp\Transport("127.0.0.1", (int)$argv[2]); $t->startTls();'
    . ' try { for (;;) { $t->send(str_repeat("x", 65536)); } } catch (MuDesk\Rdp\ProtocolException $e) { echo $e->key; }',
    __DIR__ . '/../src/bootstrap.php', (string)$port], [1 => ['pipe', 'w']], $pipes);
$conn = stream_socket_accept($srv, 10);
stream_set_blocking($conn, true);
stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
usleep(300000);              // the client fills the buffers with data nobody reads
fclose($conn);               // closing with unread data resets the connection
$end = microtime(true) + 5;
while (proc_get_status($proc)['running'] && microtime(true) < $end) {
    usleep(20000);
}
$spinning = proc_get_status($proc)['running'];
if ($spinning) {
    proc_terminate($proc);
}
check('transport: a reset connection ends send()', [$spinning, stream_get_contents($pipes[1])], [false, 'closed']);
fclose($pipes[1]);
proc_close($proc);
fclose($srv);
unlink($cnf);
MuDesk\Files::remove($tmp);

echo $fail === 0 ? "\nAll tests passed\n" : "\n$fail test(s) failed\n";
exit($fail === 0 ? 0 : 1);
