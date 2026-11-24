<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);
// Unit tests: php tests/run.php
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/fixtures/krb_fixture.php';

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
// copies the fixtures do not make, copied whole (not a byte at a time): "MuDesk", then 40,000, 25,000 and 520 bytes
// overlapping what they write (offsets 6, 7, 1000) and 10 literals, to the end of the 64 KB history; then restarted at its
// front, 50 bytes from the history before (offset 65,436), 30 across the end of the history to its front (offset 60) and
// 100 the same, past what was written since (offset 84); 15 ones as a length refused. As the byte-at-a-time copy gave them
$mp  = new MuDesk\Rdp\Mppc();
$mpA = $mp->decompress(hex2bin('4d754465736bf8dfff8e207c7fffa1a8e551fe04181899199a1a9b1bda4840'), 0xA1);
$mpB = $mp->decompress(hex2bin('decb9e97fceef0a7d200'), 0x61);
try {
    $mp->decompress(hex2bin('41f83fffc0'), 0x21);
    $mpC = 'not refused';
} catch (MuDesk\Rdp\ProtocolException $e) {
    $mpC = $e->getMessage();
}
check('MPPC: long copies, copies from before a restart and across the end of the history; a bad length refused',
    [strlen($mpA), md5($mpA), bin2hex(substr($mpB, 0, 4)), bin2hex(substr($mpB, 50, 30)), bin2hex(substr($mpB, 160, 20)), md5($mpB), $mpC],
    [65536, '63e09e5485ac319b0c4578527d7016e3', '736b4d75', '3031323334353637e921736b4d754465736b4d754465736b4d754465736b',
     bin2hex("Desk67\xE9!skMuDeskMuDe"), '980bb5b10f15aedd185891ca1f6c9812', 'MPPC: bad length']);
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
$bc->active = true;                                                              // (updates are drawn once activated)
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
// NCRUSH codes the fixtures do not use (they read through one lookup each): offset classes 288 (before the history's
// start: zeros), 257 (offset 0: zeros) and 287 (offset 32,775: "NCR"), length symbols 12-29 (the longest, 16,385), the
// offset cache entries; length symbol 30 refused. Built from the specification's codes, least significant bit first; as the
// decoder with four lookups gave them
$ncT    = (new ReflectionClass(MuDesk\Rdp\Ncrush::class))->getConstants();
$ncB    = static fn(int $v, int $n): string => $n === 0 ? '' : strrev(sprintf("%0{$n}b", $v));
$ncS    = static fn(string $t, int $sym): string => $ncB($ncT[$t . '_CODE'][$sym], $ncT[$t . '_LEN'][$sym]);
$ncCopy = static fn(int $lec, int $extra, int $lom, int $lenExtra): string => $ncS('LEC', $lec)
    . ($lec < 289 ? $ncB($extra, $ncT['COPY_BITS'][$lec - 257]) : '') . $ncS('LOM', $lom) . $ncB($lenExtra, $ncT['LOM_BITS'][$lom]);
$ncBlock = static fn(string $bits): string => implode('', array_map(static fn(string $b): string => chr(bindec(strrev($b))),
    str_split(str_pad($bits, intdiv(strlen($bits) + 7, 8) * 8, '0'), 8)));
$ncBits = $ncS('LEC', ord('N')) . $ncS('LEC', ord('C')) . $ncS('LEC', ord('R')) . $ncCopy(288, 100, 29, 16383) . $ncCopy(257, 0, 28, 16383)
    . $ncS('LEC', ord('A')) . $ncS('LEC', 0xE9) . $ncCopy(287, 7, 12, 1) . $ncCopy(258, 0, 20, 5)
    . $ncCopy(290, 0, 0, 0) . $ncCopy(291, 0, 1, 0) . $ncCopy(292, 0, 2, 0) . $ncCopy(290, 0, 0, 0);
for ($ncSym = 13; $ncSym <= 27; $ncSym++) {
    $ncBits .= $ncS('LEC', 0x40 + $ncSym) . $ncCopy(289, 0, $ncSym, (1 << $ncT['LOM_BITS'][$ncSym]) - 1);
}
$ncOut = (new MuDesk\Rdp\Ncrush())->decompress($ncBlock($ncBits . $ncS('LEC', 256)), 0xA2);
try {
    (new MuDesk\Rdp\Ncrush())->decompress($ncBlock($ncS('LEC', ord('x')) . $ncS('LEC', 260) . $ncS('LOM', 30) . str_repeat('0', 13)), 0xA2);
    $ncBad = 'not refused';
} catch (MuDesk\Rdp\ProtocolException $e) {
    $ncBad = $e->getMessage();
}
check('NCRUSH: every offset class and length symbol, through one lookup each; a bad length refused',
    [strlen($ncOut), md5($ncOut), bin2hex(substr($ncOut, 32773, 7)), bin2hex(substr($ncOut, -4)), $ncBad],
    [35342, 'cdee3045fc69aae703f9dca2db674ed6', bin2hex("A\xE9NCR\0\0"), '5b5b5b5b', 'NCRUSH: bad length']);
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
// the key stream is made 4 KB at a time: in pieces across those blocks as in one go (RFC 6229: offsets 4080 and 4096; the
// md5 of 10,000 bytes as an independent implementation gives it), and XORed with the data
$rc4 = new Rc4(hex2bin('0102030405'));
$rc4Pieces = implode('', array_map(static fn(int $n): string => $rc4->crypt(str_repeat("\0", $n)), [1, 15, 0, 4079, 1, 4096, 1808]));
check('RC4 in pieces as in one go, past its 4 KB blocks', [bin2hex(substr($rc4Pieces, 4080, 32)), md5($rc4Pieces),
    $rc4Pieces === (new Rc4(hex2bin('0102030405')))->crypt(str_repeat("\0", 10000)), bin2hex((new Rc4(hex2bin('0102030405')))->crypt('MuDeskClient'))],
    ['068326a2118416d21f9d04b2cd1ca050ff25b58995996707e51fbdf08b34d875', '62864d6eae733329d3e8d5105a83f43b', true, 'ff4c27608356834ba5a63c3e']);

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
// (the fixtures' throwaway certificates go in it too, and all of it is removed at the end, also when a check throws: the
// fixtures the tests stop never removed theirs, and thousands were left in the system's temporary folder)
putenv("MUDESK_TEST_TMP=$tmp");
register_shutdown_function(static function () use ($tmp, $cnf): void {
    is_dir($tmp) && MuDesk\Files::remove($tmp);
    is_file($cnf) && @unlink($cnf);
});
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
// first run: the setup code works a day, then another one takes its place in its file (a copy of data/ that went astray
// before the installation was finished gave the administrator for good); an address already locked is refused without
// writing the failures again (each refused request wrote that file)
$setupFile = "$tmp/data/setup-code.php";
$setupRead = static fn(): string => trim(substr((string)file_get_contents($setupFile), 15));
$_SERVER['REMOTE_ADDR'] = '10.23.0.1';
MuDesk\Auth::setupCode();
$setupA = $setupRead();
touch($setupFile, time() - 86400 - 5);
$setupOld = MuDesk\Auth::setupAllowed($setupA);
$setupB   = $setupRead();
$setupNew = MuDesk\Auth::setupAllowed($setupB);
// ... expired, and a new one cannot take its place (the file read-only: Windows does not replace it): refused all the same
touch($setupFile, time() - 86400 - 5);
chmod($setupFile, 0444);
$setupStale = MuDesk\Auth::setupAllowed($setupB);
chmod($setupFile, 0644);
MuDesk\Store::update('ratelimit', static function (array &$d): void {
    $d['ip:10.23.0.2'] = ['n' => 5, 'first' => time(), 'until' => time() + 900];
});
$_SERVER['REMOTE_ADDR'] = '10.23.0.2';
touch("$tmp/data/ratelimit.json", $rlPast = time() - 50);
$rlBefore = (string)file_get_contents("$tmp/data/ratelimit.json");
$setupLocked = MuDesk\Auth::setupAllowed($setupB);
clearstatcache();
check('first run: the setup code works a day, then a new one (refused when expired, also if no new one could be written); an address locked refused without writing', [preg_match('/^(\w{5}-){3}\w{5}$/', $setupA),
    $setupOld, $setupB !== $setupA, $setupNew, $setupStale, $setupLocked, file_get_contents("$tmp/data/ratelimit.json") === $rlBefore, filemtime("$tmp/data/ratelimit.json"),
    glob("$tmp/data/setup-code.php.*") ?: []], [1, false, true, true, false, false, true, $rlPast, []]);
@unlink($setupFile);
MuDesk\Store::update('ratelimit', static function (array &$d): void {
    unset($d['ip:10.23.0.1'], $d['ip:10.23.0.2'], $d['u:#setup']);
});
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
// sign-in hours of a user: checked in the time zone of the settings (Madrid is UTC+1 in November), one span a day or across
// midnight (counted from the day it starts), the whole day when from and to are equal, never for administrators; outside
// them the right password opens no session, and it is audited
$hw  = ['days' => [1, 2, 3, 4, 5], 'from' => '08:00', 'to' => '18:00'];
$at  = static fn(string $local): int => (new DateTimeImmutable($local, new DateTimeZone('Europe/Madrid')))->getTimestamp();
$in  = static fn(array $h, string $local, string $role = 'user'): bool => MuDesk\Users::withinHours(['hours' => $h, 'role' => $role], $at($local));
MuDesk\Settings::save(['timezone' => 'Europe/Madrid']);
$night = ['days' => [5], 'from' => '22:00', 'to' => '06:00'];
check('sign-in hours: valid ones kept (days sorted, once), the rest refused; empty is any time; a time zone must exist', [
    MuDesk\Users::hours(['days' => [3, '1', 3], 'from' => '08:00', 'to' => '18:30']), MuDesk\Users::hours(null), MuDesk\Users::hours(''),
    MuDesk\Users::hours(['days' => [], 'from' => '08:00', 'to' => '18:00']), MuDesk\Users::hours(['days' => [8], 'from' => '08:00', 'to' => '18:00']),
    MuDesk\Users::hours(['days' => [1], 'from' => '8:00', 'to' => '18:00']), MuDesk\Users::hours(['days' => [1], 'from' => '08:00', 'to' => '24:00']),
    MuDesk\Users::hours(['days' => [[1]], 'from' => '08:00', 'to' => '18:00']), MuDesk\Users::hours('every day'),
    MuDesk\Settings::save(['timezone' => 'Mars/Olympus']), MuDesk\Settings::timeZone()],
    [['days' => [1, 3], 'from' => '08:00', 'to' => '18:30'], null, null, false, false, false, false, false, false, 'invalid_timezone', 'Europe/Madrid']);
check('sign-in hours: within a working day, outside it, at the weekend, across midnight, the whole day, administrators always', [
    $in($hw, '2026-11-18 08:00'), $in($hw, '2026-11-18 17:59'), $in($hw, '2026-11-18 18:00'), $in($hw, '2026-11-18 07:59'), $in($hw, '2026-11-21 10:00'),
    $in($night, '2026-11-20 23:00'), $in($night, '2026-11-21 05:59'), $in($night, '2026-11-21 06:00'), $in($night, '2026-11-21 23:00'), $in($night, '2026-11-20 03:00'),
    $in(['days' => [6], 'from' => '00:00', 'to' => '00:00'], '2026-11-21 23:59'), $in($hw, '2026-11-21 10:00', 'admin'),
    MuDesk\Users::withinHours(['role' => 'user'], $at('2026-11-21 10:00'))],
    [true, true, false, false, false, true, true, false, false, false, true, true, true]);
$ph = 'Hours-user-' . bin2hex(random_bytes(6));
$uh = MuDesk\Users::create('hoursuser', $ph, 'user', false);
$notToday = ['days' => [((int)(new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid')))->format('N')) % 7 + 1], 'from' => '00:00', 'to' => '00:00'];
MuDesk\Users::update($uh['id'], static fn(array &$x) => $x['hours'] = $notToday);
$_SESSION = [];
$outside = $try('hoursuser', $ph);
$hoursSession = $_SESSION['uid'] ?? null;
MuDesk\Users::update($uh['id'], static fn(array &$x) => $x['hours'] = null);
$_SESSION = [];
$_SESSION = ['reauth' => time(), 'via' => 'oidc', 'totp_setup' => 'x'];
$cleanSignIn = $try('hoursuser', $ph);
check('sign-in: a new sign-in starts from a clean session (a password just confirmed, a provider sign-in, a second step being set up are not kept)', [
    $cleanSignIn, isset($_SESSION['reauth']) || isset($_SESSION['via']) || isset($_SESSION['totp_setup'])], ['ok', false]);
$_SESSION = [];
check('sign-in hours: the right password outside them opens no session and is audited; inside them it signs in', [$outside, $hoursSession,
    array_column(MuDesk\Audit::search(['event' => 'login_fail', 'user' => 'hoursuser'])['rows'], 'reason'), $try('hoursuser', $ph)],
    ['outside_hours', null, ['outside_hours'], 'ok']);
$_SESSION = [];
// signing in with a Windows account, checked against a computer of the domain with NLA, here a test server that knows one
// account (tests/fixtures/nla_server.php): the right password is "ok" and its credentials are never delegated (no session
// there), a wrong one refused; the gateway's own connection delegates them, as before. Then the sign-in: an unknown account
// refused unless users may be made for it (made as a Windows account, without a local password), a wrong password refused, a
// local user typed as DOMAIN\name never checked against Windows, and the Windows password changed: a new data key, the saved
// RDP passwords forgotten and said
$nlaSrv = proc_open([PHP_BINARY, __DIR__ . '/fixtures/nla_server.php', 'ana.win', 'TEST', 'Clave-Windows-1', $cnf], [1 => ['pipe', 'w'], 2 => ['file', $nul ?? (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'), 'w']], $nlaPipes);
$nlaPort = (int)trim((string)fgets($nlaPipes[1]));
MuDesk\Settings::save(['ad_host' => '127.0.0.1', 'ad_port' => $nlaPort, 'ad_domain' => 'TEST', 'ad_create' => false]);
$noPin  = [MuDesk\WindowsLogin::check('ana.win', 'Clave-Windows-1'), trim((string)fgets($nlaPipes[1]))];   // nothing pinned: refused
$winFp  = [MuDesk\WindowsLogin::fingerprint(), trim((string)fgets($nlaPipes[1]))];
MuDesk\Settings::pin('ad_cert', str_repeat('0', 64));
$otherPc = [MuDesk\WindowsLogin::check('ana.win', 'Clave-Windows-1'), trim((string)fgets($nlaPipes[1]))];   // another certificate
$_SESSION = [];
$_SERVER['REMOTE_ADDR'] = '10.24.0.2';
$notCounted = $try('ana.win', 'Clave-Windows-1');
fgets($nlaPipes[1]);
$notCountedLock = MuDesk\Store::read('ratelimit')['u:ana.win'] ?? null;
MuDesk\Settings::pin('ad_cert', (string)$winFp[0]);
$winOk  = [MuDesk\WindowsLogin::check('ana.win', 'Clave-Windows-1'), trim((string)fgets($nlaPipes[1]))];
$winBad = [MuDesk\WindowsLogin::check('ana.win', 'Otra-clave-99'), trim((string)fgets($nlaPipes[1]))];
$dt = new MuDesk\Rdp\Transport('127.0.0.1', $nlaPort);                          // the gateway's own NLA: credentials delegated
$dt->send("\x03\x00\x00\x13\x0e\xe0\x00\x00\x00\x00\x00" . pack('CCvV', 1, 0, 8, 3));
$dt->recvPdu();
MuDesk\Rdp\CredSsp::authenticate($dt, MuDesk\Rdp\Der::subjectPublicKey($dt->startTls()), 'ana.win', 'TEST', 'Clave-Windows-1', '127.0.0.1');
$winDelegated = trim((string)fgets($nlaPipes[1]));
$_SESSION = [];
$_SERVER['REMOTE_ADDR'] = '10.24.0.1';
$unknown = $try('TEST\\ana.win', 'Clave-Windows-1');
fgets($nlaPipes[1]);
MuDesk\Settings::save(['ad_host' => '127.0.0.1', 'ad_port' => $nlaPort, 'ad_domain' => 'TEST', 'ad_create' => true]);
$_SESSION = [];
$made     = $try('TEST\\ana.win', 'Clave-Windows-1');
fgets($nlaPipes[1]);
$winUser  = MuDesk\Users::byName('ana.win');
$_SESSION = [];
$wrongWin = $try('ana.win', 'Otra-clave-99');
fgets($nlaPipes[1]);
$winLocks = array_keys(array_filter(MuDesk\Store::read('ratelimit'), static fn($k) => str_starts_with((string)$k, 'u:') && str_contains((string)$k, 'ana.win'), ARRAY_FILTER_USE_KEY));
$_SESSION = [];
$localAsWin = $try('TEST\\hoursuser', $ph);                                   // a local user: its name is not a Windows account
proc_terminate($nlaSrv);
proc_close($nlaSrv);
MuDesk\Connections::save($winUser['id'], ['name' => 'Con clave', 'host' => 'pc', 'password' => 'guardada'], MuDesk\Crypto::unwrapKey('Clave-Windows-1', $winUser['dek'], $winUser['id']));
MuDesk\WindowsLogin::$check = static fn(string $u, string $d, string $p): string => $p === 'Clave-Nueva-22' ? 'ok' : 'auth_failed';
$_SESSION = [];
$changed  = $try('ana.win', 'Clave-Nueva-22');                                 // changed in Windows meanwhile
$after    = MuDesk\Users::byName('ana.win');
MuDesk\WindowsLogin::$check = null;
MuDesk\Settings::save(['ad_host' => '']);
$_SESSION = [];
check('Windows accounts: only the computer pinned checks them (none pinned or another one: refused, not counted); one lock however the domain is typed', [
    // (the test server sees the connection end during or right after TLS: "tls" or "drop")
    [$noPin[0], in_array($noPin[1], ['tls', 'drop'], true)], [strlen((string)$winFp[0]), in_array($winFp[1], ['tls', 'drop'], true)], [$otherPc[0], in_array($otherPc[1], ['tls', 'drop'], true)],
    $notCounted, $notCountedLock, MuDesk\Settings::save(['ad_host' => 'pc.test', 'ad_domain' => ''])],
    [['ad_cert', true], [64, true], ['ad_cert', true], 'ad_unavailable', null, 'ad_domain']);
check('Windows accounts: checked with NLA without delegating the password; made when allowed; a password changed in Windows forgets the saved ones', [
    $winOk, $winBad, $winDelegated, $unknown, $made, $winUser['source'] ?? null, password_verify('Clave-Windows-1', $winUser['pass'] ?? ''), $wrongWin, $localAsWin,
    $changed, MuDesk\Crypto::unwrapKey('Clave-Nueva-22', $after['dek'], $after['id']) !== null, array_column(MuDesk\Connections::all($after['id']), 'pass'),
    array_column(MuDesk\Audit::search(['event' => 'saved_passwords_lost', 'user' => 'ana.win'])['rows'], 'reason'),
    in_array('no_user', array_column(MuDesk\Audit::search(['event' => 'login_fail', 'user' => 'ana.win'])['rows'], 'reason'), true), MuDesk\WindowsLogin::userName('ana.win@test.local'),
    ($after['sv'] ?? 1) > ($winUser['sv'] ?? 1), $winLocks],
    [['ok', 'ok none'], ['auth_failed', 'bad none'], 'ok creds', 'invalid', 'ok', 'ad', false, 'invalid', 'invalid',
     'ok', true, [null], ['windows_password_changed'], true, 'ana.win', true, ['u:ana.win']]);
// OpenID Connect: the provider's ID token checked (RS256 and ES256 with the provider's published keys, fetched again for a
// new key id; the issuer, the audience, the times and the nonce); anything else refused
$rsa  = openssl_pkey_new(['private_key_bits' => 2048, 'config' => $cnf]);
$ecK  = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'config' => $cnf]);
$b64u = static fn(string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
$jwks = static function (bool $withEc) use ($rsa, $ecK, $b64u): array {
    $r = openssl_pkey_get_details($rsa)['rsa'];
    $e = openssl_pkey_get_details($ecK)['ec'];
    return ['keys' => array_merge([['kty' => 'RSA', 'kid' => 'r1', 'n' => $b64u($r['n']), 'e' => $b64u($r['e'])]],
        $withEc ? [['kty' => 'EC', 'kid' => 'e1', 'crv' => 'P-256', 'x' => $b64u(str_pad($e['x'], 32, "\0", STR_PAD_LEFT)), 'y' => $b64u(str_pad($e['y'], 32, "\0", STR_PAD_LEFT))]] : [])];
};
$idpHits = 0;
$idpEc   = false;
MuDesk\Oidc::$http = static function (string $url, ?array $post) use (&$idpHits, &$idpEc, $jwks): array {
    $idpHits++;
    return str_ends_with($url, '/.well-known/openid-configuration')
        ? [200, json_encode(['issuer' => 'https://idp.test', 'authorization_endpoint' => 'https://idp.test/a', 'token_endpoint' => 'https://idp.test/t', 'jwks_uri' => 'https://idp.test/k'])]
        : [200, json_encode($jwks($idpEc))];
};
MuDesk\Settings::save(['oidc_issuer' => 'https://idp.test', 'oidc_client_id' => 'cliente', 'oidc_secret' => 's3creto']);
$jwt = static function (array $claims, string $alg = 'RS256', string $kid = 'r1') use ($rsa, $ecK, $b64u): string {
    $h = $b64u(json_encode(['alg' => $alg, 'kid' => $kid])) . '.' . $b64u(json_encode($claims + ['iss' => 'https://idp.test', 'aud' => 'cliente', 'exp' => time() + 60, 'iat' => time(), 'nonce' => 'n1']));
    openssl_sign($h, $sig, $alg === 'ES256' ? $ecK : $rsa, OPENSSL_ALGO_SHA256);
    if ($alg === 'ES256') {                                                       // DER to r || s, as JOSE wants it
        $rs = [];
        for ($p = 2, $k = 0; $k < 2; $k++, $p += 2 + ord($sig[$p + 1])) {          // SEQUENCE { INTEGER r, INTEGER s }
            $rs[] = str_pad(ltrim(substr($sig, $p + 2, ord($sig[$p + 1])), "\0"), 32, "\0", STR_PAD_LEFT);
        }
        $sig = implode('', $rs);
    }
    return $h . '.' . $b64u($sig);
};
$v = static fn(string $t, string $nonce = 'n1') => is_array($r = MuDesk\Oidc::verify($t, $nonce)) ? ($r['email'] ?? 'ok') : $r;
$good  = $v($jwt(['email' => 'eva@empresa.com']));
$idpEc = true;                                                                     // a new key id: the keys fetched again
$ecOk  = $v($jwt(['email' => 'ec@empresa.com'], 'ES256', 'e1'));
$forged = $jwt(['email' => 'eva@empresa.com']);
$forged = substr($forged, 0, strrpos($forged, '.')) . '.' . $b64u(str_repeat("\1", 256));
check('OpenID Connect: ID tokens checked (RS256, ES256, keys fetched again for a new key) and refused for anything wrong', [
    $good, $ecOk, $v($forged), $v($jwt(['email' => 'x@y', 'aud' => 'otro'])), $v($jwt(['email' => 'x@y', 'iss' => 'https://otro.test'])),
    $v($jwt(['email' => 'x@y', 'exp' => time() - 600])), $v($jwt(['email' => 'x@y']), 'otro-nonce'), $v($jwt(['email' => 'x@y'], 'HS256')), $v('a.b'),
    $v($jwt(['email' => 'x@y'], 'RS256', 'desconocida')), MuDesk\Oidc::validIssuer('http://idp.test'), MuDesk\Oidc::validIssuer('http://127.0.0.1:8080/realms/x'),
    MuDesk\Settings::save(['oidc_issuer' => 'ftp://x'])],
    ['eva@empresa.com', 'ec@empresa.com', 'oidc_token', 'oidc_token', 'oidc_token', 'oidc_token', 'oidc_token', 'oidc_token', 'oidc_token', 'oidc_token', false, true, 'oidc_issuer']);
// "iat" required and a number, "nbf" a number, "azp" (when there) this application's: a missing or text "iat" passed as now,
// a text "nbf" as 0, and "azp" was only checked for several audiences
check('OpenID Connect: an ID token with no "iat", a time that is no number or issued to another application ("azp") refused', [
    $v($jwt(['email' => 'x@y', 'iat' => null])), $v($jwt(['email' => 'x@y', 'iat' => 'ayer'])), $v($jwt(['email' => 'x@y', 'nbf' => 'ya'])),
    $v($jwt(['email' => 'x@y', 'azp' => 'otra-app'])), $v($jwt(['email' => 'x@y', 'azp' => 'cliente'])),
    $v($jwt(['email' => 'x@y', 'aud' => ['cliente', 'otra'], 'azp' => 'cliente'])), $v($jwt(['email' => 'x@y', 'nbf' => time() - 5]))],
    ['oidc_token', 'oidc_token', 'oidc_token', 'oidc_token', 'x@y', 'x@y', 'x@y']);
// settings saved by two administrators at once: each request writes only what it changes, onto the settings as stored now
// (the whole copy read at its start written back lost the other's change; so did the certificate pinned after a save, and a
// save undone)
$setKeep = MuDesk\Store::read('settings');
$other   = static function (string $k, mixed $val): void {
    MuDesk\Store::update('settings', static function (array &$d) use ($k, $val): void { $d[$k] = $val; });
};
MuDesk\Settings::all();
$other('audit_retention_days', 123);
MuDesk\Settings::save(['record_days' => 9]);
$other('reconnect_attempts', 7);
MuDesk\Settings::pin('ad_cert', 'f00');
$setBefore = MuDesk\Settings::all();
MuDesk\Settings::save(['login_lock_minutes' => 33]);
$other('rdp_idle_minutes', 44);
MuDesk\Settings::restore($setBefore);
$st = MuDesk\Store::read('settings');
check('settings: two administrators at once, each request writes only what it changes (a save, a certificate pinned, a save undone)',
    [$st['audit_retention_days'], $st['record_days'], $st['reconnect_attempts'], $st['ad_cert'], $st['login_lock_minutes'], $st['rdp_idle_minutes']],
    [123, 9, 7, 'f00', $setBefore['login_lock_minutes'], 44]);
MuDesk\Store::update('settings', static function (array &$d) use ($setKeep): void { $d = $setKeep; });
(new ReflectionProperty(MuDesk\Settings::class, 'cache'))->setValue(null, null);
$secretOf = static fn(): ?string => MuDesk\Crypto::decrypt(MuDesk\Crypto::appKey(), (string)MuDesk\Settings::get('oidc_secret'), 'oidc');
try {
    MuDesk\Settings::restore(MuDesk\Settings::all());                            // put back as stored: not encrypted twice
    $restored = $secretOf();
} catch (Throwable $e) {
    $restored = get_class($e);
}
MuDesk\Settings::save(['oidc_issuer' => 'https://idp.test/']);                  // "/" kept, compared exactly; another issuer: no secret
$slash = [MuDesk\Settings::get('oidc_issuer'), MuDesk\Settings::get('oidc_secret')];
MuDesk\Store::delete('oidc');
$asked = [];
MuDesk\Oidc::$http = static function (string $url, ?array $post) use (&$asked): array {
    $asked[] = $url;
    return [200, json_encode(['issuer' => 'https://idp.test/', 'authorization_endpoint' => 'https://idp.test/a', 'token_endpoint' => 'https://idp.test/t', 'jwks_uri' => 'https://idp.test/k'])];
};
try {
    $slashStart = str_starts_with(MuDesk\Oidc::start(), 'https://idp.test/a?');
} catch (RuntimeException) {
    $slashStart = false;
}
check('OpenID Connect settings: put back as stored (not encrypted twice); an issuer ending in "/" kept and found; another issuer drops the old secret', [
    $restored, $slash, $slashStart, $asked],
    ['s3creto', ['https://idp.test/', null], true, ['https://idp.test/.well-known/openid-configuration']]);
MuDesk\Oidc::$http = null;
MuDesk\Settings::save(['oidc_issuer' => '']);
MuDesk\Store::delete('oidc');
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
// ... an IPv6 proxy written otherwise than the request's address (upper case, not shortened): the same address (before, it
// was not trusted); an entry that is no address (a range, a name, a list), refused at once with a page that says so (before,
// it matched nothing, and every client had the proxy's address, without a word)
file_put_contents("$tmp/proxy.php", '<?php return ["data_dir" => ' . var_export("$tmp/data", true) . ', "trusted_proxies" => ["2001:DB8:0:0:0:0:0:2"]];');
MuDesk\Config::load("$tmp/proxy.php");
[$ra0, $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR']] = [$_SERVER['REMOTE_ADDR'], '2001:db8::2', '203.0.113.10'];
$ips[] = MuDesk\Http::clientIp();
[$_SERVER['REMOTE_ADDR']] = [$ra0];
unset($_SERVER['HTTP_X_FORWARDED_FOR']);
$badProxy = [];
foreach (['["10.0.0.0/8"]', '["proxy.local"]', '[["10.0.0.2"]]', '[" 10.0.0.2 ", "[::1]"]'] as $pl) {
    file_put_contents("$tmp/proxy.php", '<?php return ["data_dir" => ' . var_export("$tmp/data", true) . ', "trusted_proxies" => ' . $pl . '];');
    $pp = proc_open([PHP_BINARY, '-r', 'require ' . var_export(dirname(__DIR__) . '/src/bootstrap.php', true) . '; MuDesk\Config::load(' . var_export("$tmp/proxy.php", true) . '); echo "loaded";'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $ppp);
    $badProxy[] = [trim((string)stream_get_contents($ppp[1])), str_contains((string)stream_get_contents($ppp[2]), 'MuDeskClient: config_proxies'), proc_close($pp)];
}
@unlink("$tmp/proxy.php");
MuDesk\Config::load("$tmp/config.php");
check('client IP: right-most address that is not a trusted proxy; an IPv6 proxy however written; one that is no address refused, said', [$ips, $badProxy],
    [['203.0.113.7', '203.0.113.7', '203.0.113.8', '203.0.113.9', '203.0.113.10'], [...array_fill(0, 3, ['', true, 1]), ['loaded', false, 0]]]);

// the public address fixed in config.php (public_url): OpenID Connect's return address and the passkeys' RP ID from it,
// never from the request's Host header (a proxy that did not pass the public name on broke them); without it, or with
// something that is no http(s) address, from the request as before
$host0 = $_SERVER['HTTP_HOST'] ?? null;
$_SERVER['HTTP_HOST'] = 'otro.test';
$pubCfg = static function (string $url) use ($tmp): void {
    file_put_contents("$tmp/pub.php", '<?php return ["data_dir" => ' . var_export("$tmp/data", true) . ', "public_url" => ' . var_export($url, true) . '];');
    MuDesk\Config::load("$tmp/pub.php");
};
$pubCfg('https://Mudesk.Example.com:8443/remoto/');
$pubSet = [MuDesk\Oidc::redirectUri(), MuDesk\WebAuthn::rpId()];
$pubCfg('javascript:alert(1)');
$pubBad = MuDesk\WebAuthn::rpId();
MuDesk\Config::load("$tmp/config.php");
$pubNone = MuDesk\WebAuthn::rpId();
if ($host0 === null) {
    unset($_SERVER['HTTP_HOST']);
} else {
    $_SERVER['HTTP_HOST'] = $host0;
}
check('public address: OpenID Connect and passkeys from public_url when set (not from the Host header), else from the request',
    [$pubSet, $pubBad, $pubNone], [['https://mudesk.example.com:8443/remoto/oidc.php', 'mudesk.example.com'], 'otro.test', 'otro.test']);

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
// a turn of the stream loop takes new input connections when stream_select found the listening socket readable (trying in
// every turn cost 8-11 µs on Windows), and a message waiting is taken in the next turn that finds it; or 20 ms after the
// last look, whatever woke the loop (TLS data already decrypted: stream_select gives that stream alone; before, input waited)
$rdyS    = bin2hex(random_bytes(16));
$rdySess = MuDesk\Sessions::register($rdyS, ['uid' => 'u-ready', 'token' => 't']);
$rdySent = MuDesk\Sessions::send($rdyS, pack('V', 0) . "\x06");
usleep(50000);
$rdyLook = new ReflectionProperty(MuDesk\Sessions::class, 'lookedAt');
$rdyLook->setValue($rdySess, microtime(true));          // (looked for a moment ago)
$rdyNone = $rdySess->read([]);                          // (another stream woke the loop)
$rdyGot  = [];
for ($i = 0; $i < 25 && $rdyGot === []; $i++) {
    $rdyR = $rdySess->streams();
    $rdyW = $rdyE = null;
    @stream_select($rdyR, $rdyW, $rdyE, 0, 20000);
    $rdyGot = $rdySess->read($rdyR);
}
$rdySent2 = MuDesk\Sessions::send($rdyS, pack('V', 1) . "\x07");
usleep(50000);
$rdyLook->setValue($rdySess, microtime(true) - 0.05);   // (looked for 50 ms ago)
$rdyLate = $rdySess->read([]);
check('sessions: input connections taken in the turns where the listening socket is readable, or 20 ms after the last look',
    [$rdySent, $rdyNone, $rdyGot, $rdySent2, $rdyLate], [true, [], ["\x06"], true, ["\x07"]]);
$rdySess->close();
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
// a first run), one that is no object or list ("null" was taken for no data); the failed sign-ins start again (a damaged one
// stopped every sign-in), the damaged file kept aside. A store deleted under its lock, which stays
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
$dmg    = [$damaged('storetest', '{"a":'), $damaged('storetest', ''), $damaged('storetest', 'null'), $damaged('storetest', '"x"'), $damaged('ratelimit', '{"ip:1'), count(glob("$tmp/data/ratelimit.json.damaged-*") ?: [])];
ini_set('error_log', (string)$elog);
file_put_contents("$tmp/data/ratelimit.json", $rlKeep);
unlink("$tmp/data/storetest.json");
MuDesk\Store::update('storetest', static function (array &$d): void { $d = ['a' => 1]; });
MuDesk\Store::delete('storetest');
check('data files: a damaged or empty one, or one that is no object or list, names itself, failed sign-ins start again; deleted under its lock',
    [$dmg, MuDesk\Store::read('storetest'), file_exists("$tmp/data/storetest.json"), file_exists("$tmp/data/storetest.json.lock")],
    [['Data file storetest.json damaged (Syntax error): restore it from a backup', 'Data file storetest.json damaged (empty file): restore it from a backup',
      'Data file storetest.json damaged (no object or list): restore it from a backup', 'Data file storetest.json damaged (no object or list): restore it from a backup', '[]', 1],
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
// IPv4-mapped; private networks are allowed. In the list, a name rule (exact or *.domain) allows what it resolves to, but
// those addresses only when listed themselves too.
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
// a name rule (the name itself or *.domain) trusts its DNS: one resolving to this computer (or link-local, cloud metadata)
// is refused, as with "Allow all", unless that address is listed too (before, the name itself listed reached whatever its
// DNS said). (Linux and this Windows resolve any *.localhost name to 127.0.0.1, which proves it; a system that resolves
// none only shows nothing is reached)
$mlh = (gethostbynamel('mudesk.localhost') ?: [null])[0];
check('targets, list: what is listed, loopback too; names before the IP check; a name rule never to this computer unless its address is listed',
    [$targets('list', ['127.0.0.1', 'localhost'], ['127.0.0.1', 'LOCALHOST']),
     $targets('list', ['10.0.0.0/8', '*.localhost'], ['mudesk.localhost', 'localhost', '10.1.2.3', '11.0.0.1']),
     $targets('list', ['mudesk.localhost'], ['mudesk.localhost', 'localhost']), $targets('list', ['mudesk.localhost', '127.0.0.0/8'], ['mudesk.localhost']),
     $targets('list', ['*.localhost', '127.0.0.1'], ['mudesk.localhost']), $mlh],
    [['127.0.0.1', '127.0.0.1'], [null, null, '10.1.2.3', null], [null, null], [$mlh], [$mlh], $mlh === null ? null : '127.0.0.1']);
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
// "Start in full screen" (connect dialog): on by default; turned off and remembered with the options, also for a connection of
// the administrator (the user's own); a change in the connection's form keeps it (the form has no such box)
$fsc = MuDesk\Connections::save($ua, ['name' => 'Pantalla', 'host' => 'pc-fs'], null);
$fs0 = MuDesk\Connections::get($ua, $fsc['id'])['full'] ?? null;
MuDesk\Connections::setOptions($ua, $fsc['id'], ['full' => false]);
MuDesk\Connections::save($ua, ['id' => $fsc['id'], 'name' => 'Pantalla 2', 'host' => 'pc-fs'], null);
MuDesk\Connections::setOptions($ua, $m1['id'], ['full' => false]);
$fs1 = MuDesk\Connections::get($ua, $fsc['id']);
check('connections: "Start in full screen" on by default, remembered off, kept by a change in the form; of a connection of the administrator, the user\'s own',
    [$fs0, $fs1['full'] ?? null, $fs1['name'], MuDesk\Connections::get($ua, $m1['id'])['full'] ?? null, MuDesk\Connections::get($ub, $m2['id'])['full'] ?? null],
    [true, false, 'Pantalla 2', false, true]);
// "Use all my screens" the same: on by default (shown with several screens), off remembered, kept by the form
$ms0 = MuDesk\Connections::get($ua, $fsc['id'])['multi'] ?? null;
MuDesk\Connections::setOptions($ua, $fsc['id'], ['multi' => false]);
MuDesk\Connections::save($ua, ['id' => $fsc['id'], 'name' => 'Pantalla 3', 'host' => 'pc-fs'], null);
MuDesk\Connections::setOptions($ua, $m1['id'], ['multi' => false]);
check('connections: "Use all my screens" on by default, remembered off, kept by a change in the form; of a connection of the administrator, the user\'s own',
    [$ms0, MuDesk\Connections::get($ua, $fsc['id'])['multi'] ?? null, MuDesk\Connections::get($ua, $m1['id'])['multi'] ?? null,
     MuDesk\Connections::get($ub, $m2['id'])['multi'] ?? null], [true, false, false, true]);
// the administrator changing the user's own connection (its port): the user's choices of the connect dialog stay
MuDesk\Connections::setOptions($ua, $fsc['id'], ['full' => false, 'multi' => false]);
$byAdmin = MuDesk\Connections::adminUpdate($ua, ['id' => $fsc['id'], 'name' => 'Pantalla 3', 'host' => 'pc-fs', 'port' => 3390], false);
$kept = MuDesk\Connections::get($ua, $fsc['id']);
check('connections: a change by the administrator keeps the user\'s "Start in full screen" and "Use all my screens" off',
    [is_array($byAdmin), $kept['port'] ?? null, $kept['full'] ?? null, $kept['multi'] ?? null], [true, 3390, false, false]);
MuDesk\Connections::delete($ua, $fsc['id']);
// {username} in the remote user of a connection of the administrator: each user connects as themselves; the administrator
// keeps the text as typed, and a user's own connection takes it literally
$mv  = MuDesk\Connections::saveManaged($mc(['name' => 'Escritorio', 'host' => 'rds', 'user' => 'EJEMPLO\\{username}']), [$ua, $ub]);
$own = MuDesk\Connections::save($ua, ['name' => 'Propia', 'host' => 'pc', 'user' => '{username}'], null);
check('managed connections: {username} in the remote user is the MuDeskClient user of whoever connects; literal elsewhere',
    [MuDesk\Connections::get($ua, $mv['id'])['user'], MuDesk\Connections::get($ub, $mv['id'])['user'], MuDesk\Connections::managed()[$mv['id']]['user'],
     MuDesk\Connections::get($ua, $own['id'])['user'], MuDesk\Connections::splitUser(MuDesk\Connections::get($ub, $mv['id'])['user'], '')],
    ['EJEMPLO\\mana', 'EJEMPLO\\manb', 'EJEMPLO\\{username}', '{username}', ['manb', 'EJEMPLO']]);
MuDesk\Connections::deleteManaged($mv['id']);
MuDesk\Connections::delete($ua, $own['id']);
// the certificates of the computers reached with manual connections (not saved: kept in the web session) are the user's,
// apart from their connections: a computer's by its name and port, its gateway's and a broker's computers' as a connection's;
// the latest 256 kept, its gateways' all; a saved connection's go on in it; a user removed takes them along
$occ   = ['id' => 'o0123456789abcdef', 'host' => 'PC.Uno', 'port' => 3390];
$hosts = MuDesk\Store::read('hosts/' . $ua);
MuDesk\Connections::trust($ua, $occ, 'F1');
MuDesk\Connections::trust($ua, $occ, 'G1', 'rdg.uno', true);
MuDesk\Connections::trust($ua, $occ, 'B1', 'rdsh.uno');
$occPins = MuDesk\Connections::pins($ua, $occ);
for ($i = 0; $i < 300; $i++) {
    MuDesk\Connections::trust($ua, ['id' => 'o1', 'host' => "pc$i", 'port' => 3389], "X$i");
}
$occKept = MuDesk\Store::read('hosts/' . $ua);
for ($i = 0; $i < 70; $i++) {
    MuDesk\Connections::trust($ua, ['id' => 'o1', 'host' => 'pc', 'port' => 3389], "G$i", "rdg$i", true);
}
$occGws = array_keys(array_filter(MuDesk\Store::read('hosts/' . $ua), static fn($k): bool => str_starts_with((string)$k, 'gw:'), ARRAY_FILTER_USE_KEY));
$occSaved = MuDesk\Connections::save($ua, ['name' => 'Guardada', 'host' => 'pc.uno', 'port' => 3390], null);
MuDesk\Connections::trust($ua, $occSaved, 'S1');
$occUser = MuDesk\Users::create('occ-gone', 'Occ-gone-' . bin2hex(random_bytes(6)), 'user', false);
MuDesk\Connections::trust($occUser['id'], $occ, 'F2');
$occGone = [is_file(MuDesk\Config::dir() . '/hosts/' . $occUser['id'] . '.json'), MuDesk\Users::delete($occUser['id']), is_file(MuDesk\Config::dir() . '/hosts/' . $occUser['id'] . '.json')];
check('manual connections: the certificates of their computers the user\'s (by name and port; gateway and broker as a connection\'s), the latest kept, gateways apart; a saved connection\'s in it; gone with the user', [
    $hosts, $occPins[0], [$occPins[1]['gw:rdg.uno'] ?? null, $occPins[1]['rdsh.uno'] ?? null], count($occKept), $occKept['gw:rdg.uno'] ?? null, isset($occKept['rdp:pc.uno:3390']),
    $occKept['rdp:pc299:3389'] ?? null, [count($occGws), $occGws[0] ?? null, end($occGws)], MuDesk\Connections::pins($ua, $occSaved),
    isset(MuDesk\Store::read('hosts/' . $ua)['rdp:pc.uno:3390']), $occGone],
    [[], 'F1', ['G1', 'B1'], 257, 'G1', false, 'X299', [64, 'gw:rdg6', 'gw:rdg69'], ['S1', []], false, [true, true, false]]);
MuDesk\Connections::delete($ua, $occSaved['id']);
MuDesk\Store::delete('hosts/' . $ua);
// ... a manual connection is the web session's and its user's (another user signed in on that browser does not find it), never
// among the saved ones; the 20 latest kept
$_SESSION  = [];
$occMine   = MuDesk\Connections::occasional(['host' => 'pc.mio'], $ua);
$occLookup = [MuDesk\Connections::get($ua, $occMine['id'])['host'] ?? null, MuDesk\Connections::get($ub, $occMine['id']), isset(MuDesk\Connections::all($ua)[$occMine['id']])];
for ($i = 0; $i < 25; $i++) {
    MuDesk\Connections::occasional(['host' => "pc$i"], $ua);
}
check('manual connections: the web session\'s and its user\'s, never among the saved ones; the 20 latest kept',
    [$occLookup, count(MuDesk\Connections::occasionals($ua)), isset(MuDesk\Connections::occasionals($ua)[$occMine['id']])], [['pc.mio', null, false], 20, false]);
$_SESSION = [];
// the gateway needs only PHP with OpenSSL and sessions: no function of another extension a hosting may lack (mbstring, ctype,
// iconv, intl, gmp, bcmath, sodium, zlib, curl...) in its code (one slipped into the CSV import)
$extCalls = [];
foreach (array_merge(glob(__DIR__ . '/../src/*.php'), glob(__DIR__ . '/../src/Rdp/*.php'), glob(__DIR__ . '/../public/*.php')) as $f) {
    if (preg_match_all('/(?<![\w$>:])(mb_\w+|ctype_\w+|iconv\w*|grapheme_\w+|idn_\w+|normalizer_\w+|gmp_\w+|bc(?:add|sub|mul|div|mod|pow|comp)|sodium_\w+|gz\w+|zlib_\w+|curl_\w+)\s*\(/', (string)file_get_contents($f), $m)) {
        $extCalls[basename($f)] = array_values(array_unique($m[1]));
    }
}
check('requirements: no function of a PHP extension the gateway does not require', $extCalls, []);
// every file of code names the project and its address in a comment (the header line of each one)
$noUrl = [];
$codeDirs = ['src', 'src/Rdp', 'public', 'public/assets', 'tests', 'tests/fixtures', 'docker', '.github/workflows'];
foreach ($codeDirs as $d) {
    foreach (glob(__DIR__ . "/../$d/*") ?: [] as $f) {
        if (is_file($f) && preg_match('~\.(php|js|mjs|css|html|sh|ini|conf|ya?ml)$~', $f) && !str_contains((string)file_get_contents($f), 'miguelenred.es/mudeskclient')) {
            $noUrl[] = "$d/" . basename($f);
        }
    }
}
if (is_file(__DIR__ . '/../Dockerfile') && !str_contains((string)file_get_contents(__DIR__ . '/../Dockerfile'), 'miguelenred.es/mudeskclient')) {
    $noUrl[] = 'Dockerfile';
}
check('every file of code carries the project\'s address (miguelenred.es/mudeskclient) in its header', $noUrl, []);
// Docker Compose runs the image of this version (a tag that does not change), not "latest": a new version reached the
// server without anyone asking for it (and not the code that was checked)
if (is_file(__DIR__ . '/../compose.yaml')) {
    preg_match('~^\s*image:\s*ghcr\.io/miguelenred/mudeskclient:(\S+)\s*$~m', (string)file_get_contents(__DIR__ . '/../compose.yaml'), $cm);
    check('Docker Compose: the image of this version, not the latest', $cm[1] ?? null, MuDesk\VERSION);
}
// Wake-on-LAN of a connection: the MAC address in any usual form kept as AA:BB:..., where the packet goes as an IPv4 address
// with an optional port; anything else refused; the packet is 6 bytes 0xFF and the MAC 16 times, and it arrives
$wsrv  = stream_socket_server('udp://127.0.0.1:0', $en, $es, STREAM_SERVER_BIND);
$wport = (int)substr((string)strrchr((string)stream_socket_get_name($wsrv, false), ':'), 1);
$wsent = MuDesk\WakeOnLan::send('0A:1B:2C:3D:4E:5F', '127.0.0.1', $wport);
$wpkt  = stream_socket_recvfrom($wsrv, 200);
fclose($wsrv);
$wsave = static fn(array $more): array|string => MuDesk\Connections::save('u-wol', ['host' => 'pc'] + $more, null);
check('Wake-on-LAN: MAC and address checked and kept; the packet as the standard says, sent', [
    $wsave(['wol' => '0a1b.2c3d.4e5f', 'wol_ip' => '192.168.1.255:7'])['wol'] ?? null, $wsave(['wol' => '0A:1B:2C:3D:4E'])['wol'] ?? null,
    $wsave(['wol' => 'zz:1b:2c:3d:4e:5f']), $wsave(['wol' => '0a1b2c3d4e5f', 'wol_ip' => '192.168.1.256']), $wsave(['wol' => '0a1b2c3d4e5f', 'wol_ip' => '10.0.0.255:70000']),
    $wsave(['wol' => '0a1b2c3d4e5f', 'wol_ip' => ['x']]), MuDesk\WakeOnLan::target(''), MuDesk\WakeOnLan::target('fe80::1'),
    $wsent, $wpkt === str_repeat("\xFF", 6) . str_repeat("\x0A\x1B\x2C\x3D\x4E\x5F", 16)],
    ['0A:1B:2C:3D:4E:5F', null, 'wol_mac', 'wol_ip', 'wol_ip', 'invalid_input', ['255.255.255.255', 9], null, true, true]);
MuDesk\Store::delete('connections/u-wol');
// Hyper-V console: the Preconnection PDU byte for byte as the two examples of MS-RDPEPS 4 ("TestVM", and a machine id with
// ";EnhancedMode=1": cchPCB counts the final null); the machine id of a connection kept as an upper-case GUID (braces and
// case as Hyper-V shows it taken), anything else refused, no RemoteApp nor a broker's load balance info with it (before, a
// console with load balance info was saved and sent the routing token to the Hyper-V host), port 3389 becoming Hyper-V's 2179
$pcbVm = 'BA1B6DBD-89AC-4630-A737-C4BCC3BB99FB;EnhancedMode=1';
check('Hyper-V: the Preconnection PDU as in MS-RDPEPS; the machine id checked, kept and given the console port', [
    bin2hex(MuDesk\Rdp\Client::preconnection('TestVM')),
    bin2hex(MuDesk\Rdp\Client::preconnection($pcbVm)) === '7a000000' . '00000000' . '02000000' . '00000000' . '3400' . bin2hex(MuDesk\Text::toUtf16($pcbVm)) . '0000',
    bin2hex(MuDesk\Rdp\Client::preconnection('')),
    array_intersect_key((array)$wsave(['vm' => '{ba1b6dbd-89ac-4630-a737-c4bcc3bb99fb}', 'vm_enhanced' => 1]), ['vm' => 0, 'vm_enhanced' => 0, 'port' => 0]),
    $wsave(['vm' => 'BA1B6DBD-89AC-4630-A737-C4BCC3BB99FB', 'port' => 22179])['port'] ?? null, $wsave(['vm' => 'not-a-guid']),
    $wsave(['vm' => 'BA1B6DBD-89AC-4630-A737-C4BCC3BB99FB', 'app' => 'notepad']), $wsave(['vm' => ['x']]),
    $wsave(['vm' => 'BA1B6DBD-89AC-4630-A737-C4BCC3BB99FB', 'lb' => 'tsv://MS Terminal Services Plugin.1.Granja'])],
    ['200000000000000002000000000000000700540065007300740056004d000000', true, '12000000000000000200000000000000' . '0000',
     ['port' => 2179, 'vm' => 'BA1B6DBD-89AC-4630-A737-C4BCC3BB99FB', 'vm_enhanced' => true], 22179, 'vm_id', 'vm_app', 'invalid_input', 'vm_lb']);
MuDesk\Store::delete('connections/u-wol');
// CSV import of connections of the administrator: the separator a spreadsheet used (semicolon here, with its BOM; tab;
// comma with a quoted cell holding a comma and a line break), rows numbered as the spreadsheet shows them, every valid
// one added in one write and given to the users named (unknown ones said), the options as yes/no, refused rows said with
// their reason, empty rows skipped, unknown columns listed; a file without host, empty or too long adds nothing
$before = count(MuDesk\Connections::managed());
$imp = MuDesk\Connections::importCsv("\xEF\xBB\xBFname;group;host;port;users;audio;mic;security;notes\n"
    . "Oficina;Sede;pc1.local;;mana MANB nadie;sí;1;NLA;x\nSin equipo;;;;;;;;\nTodos;;10.0.0.5;3390;*;quizá;;;\n\n;;;;;;;;\nPuerto;;pc2;99999;;;;;\n");
$byName = array_column(MuDesk\Connections::managed(), null, 'name');
$tab = MuDesk\Connections::importCsv("host\tname\tusers\nrds.tab\tTabulado\t*\n");
$com = MuDesk\Connections::importCsv("name,host,app,app_args\n\"Hoja, contable\",srv.csv,EXCEL.EXE,\"/r\nlínea\"\n");
// (and the columns of a Remote Desktop Gateway and a broker's load balance info: the gateway kept in its one form; one that is
// not a gateway, or info longer than the X.224 request takes, refused by its row; its name in lower case, without the default port 443)
$gwl = MuDesk\Connections::importCsv("name;host;gw;lb\nGranja;broker.corp;RDG.corp:443;tsv://MS Terminal Services Plugin.1.Escritorios\nMal;pc;no es;\nLargo;pc;;" . str_repeat('t', 239) . "\n");
$gwlOne = array_column(MuDesk\Connections::managed(), null, 'name')['Granja'] ?? [];
$big = MuDesk\Connections::importCsv("host\n" . str_repeat("pc\n", MuDesk\Connections::CSV_MAX + 1));
// from the code audit (1.1): a data file never replaced by an empty one when its data cannot be encoded; a name that is not
// UTF-8 refused; the quota counter emptied (not deleted) when the quota is turned off; a user's own connection moved to another
// computer forgets its certificate and password; a user who may not make connections keeps its Hyper-V machine and Wake-on-LAN
// too; a computer recorded through one connection is recorded through any other; CSV of old Mac line ends, a "sep=" line,
// VERDADERO, users separated by commas
MuDesk\Store::update('audit-json', static function (array &$d): void {
    $d = ['ok' => 1];
});
try {
    MuDesk\Store::update('audit-json', static function (array &$d): void {
        $d['bad'] = "\xff";
    });
    $jsonThrown = false;
} catch (RuntimeException) {
    $jsonThrown = true;
}
try {
    $jsonKept = MuDesk\Store::read('audit-json');
} catch (RuntimeException) {
    $jsonKept = 'damaged';
}
MuDesk\Store::delete('audit-json');
MuDesk\Settings::save(['files_quota_mb' => 1]);
MuDesk\Files::quotaLeft('ctest');
MuDesk\Settings::save(['files_quota_mb' => 0]);
MuDesk\Files::quotaLeft('ctest');
$cfile = MuDesk\Config::dir('files/ctest.quota');
clearstatcache(true, $cfile);
$counterKept = [is_file($cfile), is_file($cfile) ? filesize($cfile) : -1];
MuDesk\Settings::save(['files_quota_mb' => 500]);
$own = MuDesk\Connections::save('owntest', ['name' => 'Mía', 'host' => 'pc.uno', 'vm' => '', 'wol' => ''], null);
MuDesk\Store::update('connections/owntest', static function (array &$d) use ($own): void {
    $d[$own['id']]['cert'] = 'huella';
    $d[$own['id']]['pass'] = 'cifrada';
});
$moved = MuDesk\Connections::save('owntest', ['id' => $own['id'], 'name' => 'Mía', 'host' => 'pc.dos'], null);
$wolLocked = MuDesk\Connections::save('owntest', ['id' => $own['id'], 'name' => 'Mía', 'host' => 'pc.dos', 'wol' => 'AA:BB:CC:DD:EE:FF'], null, false);
$vmLocked  = MuDesk\Connections::save('owntest', ['id' => $own['id'], 'name' => 'Mía', 'host' => 'pc.dos', 'vm' => '0F1E2D3C-4B5A-6978-8796-A5B4C3D2E1F0'], null, false);
MuDesk\Store::update('connections/owntest', static function (array &$d) use ($own): void {
    $d[$own['id']]['record'] = true;
});
$recTarget = [MuDesk\Connections::recordedTarget('owntest', ['host' => 'PC.DOS', 'port' => 3389]), MuDesk\Connections::recordedTarget('owntest', ['host' => 'pc.dos', 'port' => 3390])];
MuDesk\Store::delete('connections/owntest');
$mac = MuDesk\Connections::importCsv("sep=;\rname;host;users;clipboard\rMac;mac.local;nadie,otro;VERDADERO\r");
check('audit fixes, storage: data never emptied, names in UTF-8 only, the quota counter emptied, a moved connection forgets, locked machine and Wake-on-LAN, recorded by computer, CSV from Excel', [
    $jsonThrown, $jsonKept, MuDesk\Files::clean("nul.\xff"), $counterKept, [$moved['cert'], $moved['pass']], $wolLocked, $vmLocked, $recTarget,
    [count($mac['created'] ?? []), $mac['errors'] ?? null, $mac['unknown'][0] ?? null, array_column(MuDesk\Connections::managed(), 'clipboard', 'name')['Mac'] ?? null]],
    [true, ['ok' => 1], null, [true, 0], ['', null], 'conns_locked', 'conns_locked', [true, false], [1, [], [2, 'nadie otro'], true]]);
MuDesk\Connections::deleteManaged(array_column(MuDesk\Connections::managed(), 'id', 'name')['Mac'] ?? '');
check('CSV import: a gateway and a broker\'s load balance info taken, in their one form; a gateway that is not one or info too long refused by row', [
    $gwl, [$gwlOne['gw'] ?? null, $gwlOne['lb'] ?? null]],
    [['created' => ['Granja'], 'errors' => [[3, 'gw'], [4, 'lb']], 'unknown' => [], 'ignored' => []], ['rdg.corp', 'tsv://MS Terminal Services Plugin.1.Escritorios']]);
isset($gwlOne['id']) && MuDesk\Connections::deleteManaged($gwlOne['id']);
check('CSV import: separators, row numbers, users, options, refused rows and columns; nothing added for a bad file', [
    $imp, [$byName['Oficina']['group'] ?? null, $byName['Oficina']['users'] ?? null, $byName['Oficina']['audio'] ?? null, $byName['Oficina']['mic'] ?? null,
     $byName['Oficina']['security'] ?? null, $byName['Oficina']['port'] ?? null, $byName['Oficina']['perf']['smoothing'] ?? null],
    $tab['created'], $byName['Tabulado'] ?? 'not yet', ($m = array_column(MuDesk\Connections::managed(), null, 'name'))['Tabulado']['users'] ?? null,
    $com['created'], $m['Hoja, contable']['app'] ?? null, $m['Hoja, contable']['app_args'] ?? null,
    MuDesk\Connections::importCsv("name;port\nx;1\n"), MuDesk\Connections::importCsv("\n\n"), $big, count(MuDesk\Connections::managed()) - $before],
    [['created' => ['Oficina'], 'errors' => [[3, 'host'], [4, 'csv_bool'], [7, 'port']], 'unknown' => [[2, 'nadie']], 'ignored' => ['notes']],
     ['Sede', [$ua, $ub], true, true, 'nla', 3389, true], ['Tabulado'], 'not yet', ['*'], ['Hoja, contable'], 'EXCEL.EXE', '/rlínea',   // (one cell; line breaks left out, as the editor does)
     'csv_no_host', 'csv_empty', 'too_many_rows', 3]);
foreach (['Oficina', 'Tabulado', 'Hoja, contable'] as $n) {
    MuDesk\Connections::deleteManaged($m[$n]['id']);
}
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
// print jobs count in the user's quota (as clipboard files do) until the session ends: several sessions printing at once
// took 200 MB each of the server's disk, out of any quota
$q0 = MuDesk\Settings::get('files_quota_mb');
MuDesk\Settings::save(['files_quota_mb' => 1]);
$pq   = 'printquota';
$pdev = new Rdpdr($client, $pq, false, true, $dir);
$pdev->onData($irp(0, 6, 0x00, str_repeat("\0", 32)));
$half = str_repeat('x', 600 * 1024);
$pqs  = [$st($pdev, $irp(1, 7, 0x04, pack('VP', strlen($half), 0) . str_repeat("\0", 20) . $half)),
    $st($pdev, $irp(1, 8, 0x04, pack('VP', strlen($half), 0) . str_repeat("\0", 20) . $half))];
$pdev->release();
check('print jobs: counted in the quota', [$pqs, MuDesk\Files::quotaLeft($pq) <= 1048576 - 600 * 1024, filesize("$dir/print-1")], [[0, 0xC000007F], true, 600 * 1024]);
MuDesk\Files::charge($pq, -600 * 1024);
MuDesk\Settings::save(['files_quota_mb' => $q0]);
MuDesk\Files::remove($dir);
MuDesk\Files::removeAll($pq);

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

// a folder of this computer (the browser answers its requests): shared with the shared folder's option only; announced with
// the devices, or on its own once they were; each request passed to the browser numbered (F_DRIVE: u32 its number, then the
// request from its DeviceId on), a change notification left waiting here; the browser's answer sent only for a request
// waiting (of this device, with its number: one of a folder or a connection before is left out); the files read, written,
// created, deleted and renamed audited; no longer shared: what was owed answered, the drive removed; a request to it then
// refused
$rdOut = static function () use ($mem): array {                         // the rdpdr PDUs written: [packet id, data after the header]
    rewind($mem);
    $o = (string)stream_get_contents($mem);
    ftruncate($mem, 0);
    rewind($mem);
    $pdus = [];
    for ($p = 0; $p + 4 <= strlen($o);) {                                 // TPKT, X.224, MCS Send Data, the channel's header
        $pkt = substr($o, $p, unpack('n', $o, $p + 2)[1]);
        $p  += strlen($pkt);
        $q   = 4 + 3 + 6;
        $q  += ord($pkt[$q]) & 0x80 ? 2 + 8 : 1 + 8;
        $pdus[] = [unpack('v', $pkt, $q + 2)[1], substr($pkt, $q + 4)];
    }
    return $pdus;
};
$ldp  = static fn(int $file, int $cid, int $major, string $rest, int $minor = 0) => pack('vv', 0x4472, 0x4952) . pack('VVVVV', 3, $file, $cid, $major, $minor) . $rest;
$ldNo = static function () use (&$frames): int {                        // the number of the request passed to the browser last
    $f = end($frames);
    return $f && $f[0] === Client::F_DRIVE ? unpack('V', $f[1])[1] : 0;
};
ftruncate($mem, 0);
rewind($mem);
$frames = [];
$noDrive = (new Rdpdr($client, 'test', false, true, $dir))->addLocal('Fotos', false);
$ld = new Rdpdr($client, 'test', true, false, $dir, 'lsid', 'lconn', 'Carpeta compartida');
$ldShared = $ld->addLocal("Fotos \xC3\xB1", false);                                 // before the devices: with them
$ld->onData(pack('vvvvV', 0x4472, 0x4343, 1, 12, 7));                               // client id confirm -> the devices
$ldList = $rdOut();
$ld->onData($ldp(0, 21, 0x00, $open('\a.txt', 0x2, 2, 0x40)));                     // create: to the browser, nothing to the server
$n21   = $ldNo();
$ldFwd = [$frames, $rdOut()];
$frames = [];
$ld->localReply(pack('V', $n21) . pack('VVVVC', 3, 99, 0, 1, 1));                   // answers no request waiting
$ld->localReply(pack('V', $n21) . pack('VVVVC', 2, 21, 0, 1, 1));                   // ... another device's
$ld->localReply(pack('V', $n21 + 1) . pack('VVVVC', 3, 21, 0, 1, 1));               // ... another request's number (one before)
$ldStray = $rdOut();
$ld->localReply(pack('V', $n21) . pack('VVVVC', 3, 21, 0, 9, 2));                                     // FileId 9, created
$ldCreated = $rdOut();
$ld->localReply(pack('V', $n21) . pack('VVVVC', 3, 21, 0, 9, 2));                                     // the same answer again: not waiting any more
$ldTwice = $rdOut();
$ld->onData($ldp(9, 22, 0x04, pack('VP', 5, 0) . str_repeat("\0", 20) . 'hello'));
$ld->localReply(pack('V', $ldNo()) . pack('VVVVC', 3, 22, 0, 5, 0));
$ld->onData($ldp(9, 23, 0x03, pack('VP', 100, 0) . str_repeat("\0", 20)));
$ld->localReply(pack('V', $ldNo()) . pack('VVVV', 3, 23, 0, 3) . 'hel');
$ld->onData($ldp(9, 24, 0x02, str_repeat("\0", 32)));
$ld->localReply(pack('V', $ldNo()) . pack('VVVVC', 3, 24, 0, 0, 0));
$ld->onData($ldp(0, 25, 0x00, $open('\b.txt', 0x10000, 1, 0x40)));
$ld->localReply(pack('V', $ldNo()) . pack('VVVVC', 3, 25, 0, 10, 1));
$ld->onData($ldp(10, 26, 0x06, pack('VV', 13, 1) . str_repeat("\0", 24) . "\1"));   // deleted when closed
$ld->localReply(pack('V', $ldNo()) . pack('VVVV', 3, 26, 0, 1));
$ld->onData($ldp(10, 27, 0x02, str_repeat("\0", 32)));
$ld->localReply(pack('V', $ldNo()) . pack('VVVVC', 3, 27, 0, 0, 0));
$ldTo = Text::toUtf16('\d.txt') . "\0\0";
$ld->onData($ldp(0, 28, 0x00, $open('\c.txt', 0x10000, 1, 0x40)));
$ld->localReply(pack('V', $ldNo()) . pack('VVVVC', 3, 28, 0, 11, 1));
$ld->onData($ldp(11, 29, 0x06, pack('VV', 10, 6 + strlen($ldTo)) . str_repeat("\0", 24) . "\0\0" . pack('V', strlen($ldTo)) . $ldTo));
$ld->localReply(pack('V', $ldNo()) . pack('VVVV', 3, 29, 0, 6 + strlen($ldTo)));
$rdOut();
// ... a folder listed: its first name from the browser with the rest of them, which the gateway then answers itself (a
// request each name was a round trip to the browser), no more names at their end, and again; listed anew: asked again;
// names given for another FileId than the request's: not kept (asked one by one)
$ldQuery = static fn(int $file, int $cid, bool $initial) => $ldp($file, $cid, 0x0C, pack('VCV', 3, $initial ? 1 : 0, 6) . str_repeat("\0", 23) . Text::toUtf16('\*') . "\0\0", 1);
$frames = [];
$ld->onData($ldQuery(12, 40, true));
$ldListFwd = count($frames);
$ld->localList(pack('V', $ldNo()) . pack('V', 17) . pack('VVVVC', 3, 40, 0, 1, 0x61) . pack('VVV', 12, 3, 2) . pack('V', 2) . 'bb' . pack('V', 3) . 'ccc');
$ldListFirst = $rdOut();
$frames = [];
$ldListNext = [];
foreach ([41, 42, 43, 44] as $q) {
    $ld->onData($ldQuery(12, $q, false));
    $ldListNext[] = $rdOut();
}
$ldListLocal = count($frames);
$ld->onData($ldQuery(12, 45, true));                                               // listed anew: to the browser
$ldListAgain = count($frames);
$ld->localReply(pack('V', $ldNo()) . pack('VVVVC', 3, 45, 0, 1, 0x61));
$ld->onData($ldQuery(13, 47, true));                                               // another folder's listing, answered with
$ld->localList(pack('V', $ldNo()) . pack('V', 17) . pack('VVVVC', 3, 47, 0, 1, 0x61) . pack('VVV', 12, 3, 1) . pack('V', 1) . 'x');   // names said to be FileId 12's
$rdOut();
$ld->onData($ldQuery(12, 46, false));                                               // FileId 12's next: still the browser's
$ldListOther = count($frames);
$ld->localReply(pack('V', $ldNo()) . pack('VVVVC', 3, 46, 0x80000006, 0, 0));
$rdOut();
check('a folder of this computer: a listing\'s other names given with its first one, answered by the gateway, no more at their end; listed anew, asked again; names of another FileId not kept', [
    $ldListFwd, $ldListFirst, $ldListNext, $ldListLocal, $ldListAgain, $ldListOther], [
    1, [[0x4943, pack('VVVVC', 3, 40, 0, 1, 0x61)]],
    [[[0x4943, pack('VVVV', 3, 41, 0, 2) . 'bb']], [[0x4943, pack('VVVV', 3, 42, 0, 3) . 'ccc']], [[0x4943, pack('VVVVC', 3, 43, 0x80000006, 0, 0)]],
     [[0x4943, pack('VVVVC', 3, 44, 0x80000006, 0, 0)]]], 0, 1, 3]);
$frames = [];
$ld->onData($ldp(0, 30, 0x0C, str_repeat("\0", 32), 2));                            // a change notification: kept here
$ldNotify = $frames;
$ld->removeLocal();
$ldRemoved = $rdOut();
$ld->onData($ldp(0, 31, 0x00, $open('\a.txt', 0x1, 1, 0x40)));                     // no longer shared: refused
$ldAfter = $rdOut();
$ld->addLocal('Otra', true);                                                        // the devices announced: on its own, at once
$ldLate = $rdOut();
$ldAudit = array_map(static fn($r) => [$r['event'], $r['folder'] ?? null, $r['path'] ?? ($r['from'] ?? '') . '>' . ($r['to'] ?? ''), $r['read'] ?? null,
    $r['written'] ?? null, $r['created'] ?? null, $r['read_only'] ?? null], array_reverse(array_slice(MuDesk\Audit::search(['event' => 'local_'])['rows'], 0, 7)));
$devEntry = static fn(string $label) => pack('VV', 8, 3) . "LOCAL\0\0\0" . pack('V', strlen($label) + 1) . $label . "\0";
check('a folder of this computer: shared with the shared folder only, announced, its requests passed to the browser and only its answers to them sent, audited, removed',
    [$noDrive, $ldShared, $ldList, $ldFwd, $ldStray, $ldCreated, $ldTwice, $ldNotify, $ldRemoved, $ldAfter, $ldLate, $ldAudit],
    [false, true,
     [[0x4441, pack('V', 2) . pack('VV', 8, 1) . str_pad('MUDESK', 8, "\0") . pack('V', 19) . "Carpeta compartida\0" . $devEntry('Fotos')]],
     [[[Client::F_DRIVE, pack('V', $n21) . substr($ldp(0, 21, 0x00, $open('\a.txt', 0x2, 2, 0x40)), 4)]], []],
     [], [[0x4943, pack('VVVVC', 3, 21, 0, 9, 2)]], [], [],
     [[0x4943, pack('VVV', 3, 30, 0xC000000E) . "\0\0\0\0\0"], [0x444D, pack('VV', 1, 3)]],
     [[0x4943, pack('VVV', 3, 31, 0xC000000E) . "\0\0\0\0\0"]],
     [[0x4441, pack('V', 1) . $devEntry('Otra')]],
     [['local_folder_shared', 'Fotos', '>', null, null, null, false], ['local_file', 'Fotos', '/a.txt', 3, 5, true, null],
      ['local_delete', 'Fotos', '/b.txt', null, null, null, null], ['local_rename', 'Fotos', '/c.txt>/d.txt', null, null, null, null],
      ['local_folder_unshared', 'Fotos', '>', null, null, null, null], ['local_folder_shared', 'Otra', '>', null, null, null, true]]]);
// ... change notifications: not counted with the requests the browser owes (4,096 of them, then a request still passed on:
// a program watching thousands of folders stopped the drive), forgotten when their file closes (only the others answered
// when the folder goes)
$nf = new Rdpdr($client, 'test', true, false, $dir, 'nsid', 'nconn');
$nf->addLocal('N', false);
$nf->onData(pack('vvvvV', 0x4472, 0x4343, 1, 12, 7));
$rdOut();
for ($i = 0; $i < 4096; $i++) {
    $nf->onData($ldp(50 + ($i & 1), 1000 + $i, 0x0C, str_repeat("\0", 32), 2));       // on FileIds 50 and 51
}
$frames = [];
$nf->onData($ldp(0, 900, 0x00, $open('\x.txt', 0x1, 1, 0x40)));
$nfPassed = [count($frames), $rdOut()];
$nf->onData($ldp(50, 901, 0x02, str_repeat("\0", 32)));                           // FileId 50 closed
$nf->localReply(pack('V', $ldNo()) . pack('VVVVC', 3, 901, 0, 0, 0));
$rdOut();
$nf->removeLocal();
$nfOwed = array_map(static fn($p) => $p[0] === 0x4943 ? unpack('V', $p[1], 4)[1] : -1, $rdOut());
check('a folder of this computer: change notifications not counted with the requests owed, forgotten when their file closes',
    [$nfPassed, count($nfOwed), in_array(900, $nfOwed, true), in_array(1000, $nfOwed, true), in_array(1001, $nfOwed, true), in_array(5095, $nfOwed, true)],
    [[1, []], 1 + 2048 + 1, true, false, true, true]);
// ... the audit: a deletion the browser refused (a folder not empty) not recorded; one asked when opening (FILE_DELETE_ON_CLOSE)
// recorded; a file emptied when opened, or cut to nothing, recorded as overwritten; the files still open when the session
// ends (or its connection is made again) audited, the browser saves them all the same, with the end of the share; nothing sent
$au   = new Rdpdr($client, 'test', true, false, $dir, 'ausid', 'auconn');
$au->addLocal('A', false);
$au->onData(pack('vvvvV', 0x4472, 0x4343, 1, 12, 7));
$auDo = static function (int $file, int $cid, int $major, string $rest, int $status, string $answer) use ($au, $ldp, $ldNo): void {
    $au->onData($ldp($file, $cid, $major, $rest));
    $au->localReply(pack('V', $ldNo()) . pack('VVV', 3, $cid, $status) . $answer);
};
$auDo(0, 1, 0x00, $open('\sub', 0x10000, 1, 0x1), 0, pack('VC', 20, 1));          // a folder opened
$auDo(20, 2, 0x06, pack('VV', 13, 1) . str_repeat("\0", 24) . "\1", 0xC0000101, pack('V', 0));   // to delete: not empty
$auDo(20, 3, 0x02, str_repeat("\0", 32), 0, pack('VC', 0, 0));
$auDo(0, 4, 0x00, $open('\t.tmp', 0x10000, 2, 0x1040), 0, pack('VC', 21, 2));     // created, deleted when closed
$auDo(21, 5, 0x02, str_repeat("\0", 32), 0, pack('VC', 0, 0));
$auDo(0, 6, 0x00, $open('\o.txt', 0x2, 5, 0x40), 0, pack('VC', 22, 3));          // emptied when opened
$auDo(22, 7, 0x02, str_repeat("\0", 32), 0, pack('VC', 0, 0));
$auDo(0, 8, 0x00, $open('\e.txt', 0x2, 1, 0x40), 0, pack('VC', 23, 1));
$auDo(23, 9, 0x06, pack('VV', 20, 8) . str_repeat("\0", 24) . pack('P', 0), 0, pack('V', 8));   // cut to nothing
$auDo(23, 10, 0x02, str_repeat("\0", 32), 0, pack('VC', 0, 0));
$auDo(0, 11, 0x00, $open('\open.txt', 0x2, 2, 0x40), 0, pack('VC', 24, 2));
$auDo(24, 12, 0x04, pack('VP', 4, 0) . str_repeat("\0", 20) . 'data', 0, pack('VC', 4, 0));
$rdOut();
$au->removeLocal(false);                                                           // the session ended, /open.txt still open
$auSent = $rdOut();
$auRows = array_values(array_filter(array_reverse(MuDesk\Audit::search(['event' => 'local_'])['rows']), static fn($r) => ($r['sid'] ?? '') === 'ausid'));
check('a folder of this computer: deletions audited as done, files emptied as overwritten, those open at the end audited, nothing sent then', [
    array_map(static fn($r) => [$r['event'], $r['path'] ?? $r['folder'], $r['overwritten'] ?? null, $r['written'] ?? null], $auRows), $auSent], [
    [['local_folder_shared', 'A', null, null], ['local_delete', '/t.tmp', null, null], ['local_file', '/o.txt', true, 0], ['local_file', '/e.txt', true, 0],
     ['local_file', '/open.txt', false, 4], ['local_folder_unshared', 'A', null, null]], []]);
// ... a hostile server's paths kept for the audit cut (64 KB each held hundreds of MB with 4,096 requests waiting); the
// listings kept bounded in bytes too, past them the names asked one by one
$mx = new Rdpdr($client, 'test', true, false, $dir, 'mxsid', 'mxconn');
$mx->addLocal('M', false);
$mx->onData(pack('vvvvV', 0x4472, 0x4343, 1, 12, 7));
$mx->onData($ldp(0, 1, 0x00, $open('\\' . str_repeat('a', 30000), 0x1, 1, 0x40)));
$mxPath = strlen((string)(new ReflectionProperty(Rdpdr::class, 'localWaiting'))->getValue($mx)[1]['p']);
(new ReflectionProperty(Rdpdr::class, 'localListBytes'))->setValue($mx, 32 * 1048576 - 4);
$mx->onData($ldQuery(12, 2, true));
$mx->localList(pack('V', $ldNo()) . pack('V', 17) . pack('VVVVC', 3, 2, 0, 1, 0x61) . pack('VVV', 12, 3, 2) . pack('V', 2) . 'bb' . pack('V', 3) . 'ccc');
$frames = [];
$mx->onData($ldQuery(12, 3, false));
$mxAsked = count($frames);
$rdOut();
check('a folder of this computer: the paths kept for the audit cut, the listings kept bounded in bytes', [$mxPath, $mxAsked], [1024, 1]);

// from the code audit (1.1): print jobs of a session connected again are numbered after the earlier ones (print-1 was
// emptied); a dynamic channel closed or created again mid-message forgets it; a share-control PDU of 128 bytes before the
// licensing is not taken for a licensing one; a channel message larger than the memory can hold is left out (not a fatal
// error); the trace counts at most 500 kinds of messages; the clipboard takes at most 1000 formats and refuses a file
// offset past 2^63 (it read from the start)
$pd = sys_get_temp_dir() . '/mudesk-print-' . bin2hex(random_bytes(4));
$p1 = new Rdpdr($client, 'test', false, true, $pd);
$p1->onData(pack('vvvvV', 0x4472, 0x4343, 1, 12, 7));
$p1->onData($irp(0, 1, 0x00, str_repeat("\0", 32)));
$p1->onData($irp(1, 2, 0x04, pack('VP', 4, 0) . str_repeat("\0", 20) . 'uno1'));
$p2 = new Rdpdr($client, 'test', false, true, $pd);                            // the same session connected again
$p2->onData(pack('vvvvV', 0x4472, 0x4343, 1, 12, 7));
$p2->onData($irp(0, 3, 0x00, str_repeat("\0", 32)));
$printAfter = [@file_get_contents("$pd/print-1"), is_file("$pd/print-2")];
MuDesk\Files::remove($pd);
$dvc8 = new MuDesk\Rdp\Drdynvc($client, false);
$cpm8 = new ReflectionMethod(Client::class, 'channelPdu');
$dvcRefused = static function (callable $f): string {
    try {
        $f();
        return 'taken';
    } catch (MuDesk\Rdp\ProtocolException $e) {
        return $e->key;
    }
};
for ($i = 1; $i <= 8; $i++) {
    $dvc8->onData(chr(0x28) . chr($i) . pack('V', 100) . str_repeat('x', 10));      // data first: 10 of 100 bytes
    $dvc8->onData(chr(0x40) . chr($i));                                            // closed mid-message
}
$after8 = $dvcRefused(static fn() => $dvc8->onData(chr(0x28) . chr(9) . pack('V', 100) . str_repeat('y', 10)));
$lic = new Client(['width' => 800, 'height' => 600, 'user' => 'u', 'clientName' => 'c'], static fn() => null);
$share128 = pack('vvv', 0x80, 0x17, 1001) . pack('VCCvCCv', 0x10000, 0, 1, 0x80, 0x99, 0, 0) . str_repeat("\0", 0x80 - 18);
$licTaken = $dvcRefused(static fn() => (new ReflectionMethod(Client::class, 'ioPdu'))->invoke($lic, $share128));
$memSink = new class implements MuDesk\Rdp\Channel {
    public int $got = 0;
    public function name(): string
    {
        return 'test';
    }
    public function onData(string $data): void
    {
        $this->got += strlen($data);
    }
};
$mc = new Client(['width' => 800, 'height' => 600], static fn() => null);
(new ReflectionProperty(Client::class, 'handlers'))->setValue($mc, [1004 => $memSink]);
$limit0 = ini_get('memory_limit');
ini_set('memory_limit', (string)(memory_get_usage() + (48 << 20)));
$cpm8->invoke($mc, 1004, pack('VV', 60 << 20, 1) . str_repeat("\0", 1600));         // announced 60 MB: over what is left / 6
$cpm8->invoke($mc, 1004, pack('VV', 60 << 20, 2) . str_repeat("\0", 1600));
ini_set('memory_limit', $limit0);
$cpm8->invoke($mc, 1004, pack('VV', 3200, 1) . str_repeat("\0", 1600));             // the next one is taken
$cpm8->invoke($mc, 1004, pack('VV', 3200, 2) . str_repeat("\0", 1600));
$tr500 = new MuDesk\Rdp\Trace(bin2hex(random_bytes(16)), []);
for ($i = 0; $i < 600; $i++) {
    $tr500->count('dvc:' . $i, 1);
}
$trKeys = count((new ReflectionProperty(MuDesk\Rdp\Trace::class, 'stats'))->getValue($tr500));
$tr500->close([]);
(new ReflectionProperty(Client::class, 'channelIds'))->setValue($client, ['rdpdr' => 1004, 'cliprdr' => 1005]);
(new ReflectionProperty(Client::class, 'channelOpts'))->setValue($client, ['rdpdr' => 0x80800000, 'cliprdr' => 0xC0200000]);
$cr = new MuDesk\Rdp\Cliprdr($client, str_repeat('a', 32));
$fmts = '';
for ($i = 0; $i < 2000; $i++) {
    $fmts .= pack('V', 0xC000 + $i) . str_repeat("\0", 32);
}
$cr->onData(pack('vvV', 2, 0, strlen($fmts)) . $fmts);
$crFormats = count((new ReflectionProperty(MuDesk\Rdp\Cliprdr::class, 'remote'))->getValue($cr));
$cf = sys_get_temp_dir() . '/mudesk-cf-' . bin2hex(random_bytes(4));
file_put_contents($cf, 'HELLO');
(new ReflectionProperty(MuDesk\Rdp\Cliprdr::class, 'localFiles'))->setValue($cr, [0 => ['path' => $cf, 'name' => 'h.txt', 'size' => 5]]);
ftruncate($mem, 0);
rewind($mem);
$cr->onData(pack('vvV', 8, 0, 28) . pack('VlVVVV', 7, 0, 2, 0, 0x80000000, 5));   // offset 2^63: refused
rewind($mem);
$crOut = (string)stream_get_contents($mem);
unlink($cf);
check('audit fixes, protocol: print jobs numbered on, a closed dynamic channel forgets its message, licensing told apart, a message over the memory left out, trace and clipboard capped, a file offset past 2^63 refused', [
    $printAfter, $after8, $licTaken, $memSink->got, $trKeys, $crFormats, str_contains($crOut, pack('vv', 9, 2)), str_contains($crOut, 'HELLO')],
    [['uno1', true], 'taken', 'taken', 3200, 501, 1000, true, false]);
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

// connection broker: the cluster block says redirection is supported (version 5), and after a redirection the session to join
// (only when the broker gave one: 0 is none); the Server Redirection PDU (enhanced security) read field by field, as
// MS-RDPBCGR 2.2.13.1 orders them; where it sends the client: with a routing token and no target address, back to the same
// computer (1.3.8; before, to the first of its addresses), else its addresses first and then its names (by name first through
// a gateway), the NetBIOS name too; the next connection's options (the routing token only back to the server that sent it,
// the session, its user and domain when not empty, the password cookie); an informing one (LB_NOREDIRECT) not followed; a
// routing token too long for the X.224 request refused (before, its one-byte length wrapped and the request was broken)
$coreOf = static function (Client $c) use ($mem): string {
    ftruncate($mem, 0);
    rewind($mem);
    try {
        (new ReflectionMethod(Client::class, 'mcsConnect'))->invoke($c, 2);
    } catch (MuDesk\Rdp\ProtocolException) {
    }
    rewind($mem);
    $o = (string)stream_get_contents($mem);
    return substr($o, (int)strpos($o, pack('vv', 0xC004, 12)), 12);
};
$clusterNew = $coreOf($cl);
$rcOf = static fn(array $more): Client => new Client(['width' => 800, 'height' => 600, 'bpp' => 16, 'kbd' => 0x040A, 'clientName' => 'c', 'channels' => [], 'monitors' => []] + $more, static fn() => null);
$rc = $rcOf(['redirectSession' => 7]);
$rc->t = $cl->t;
$clusterRedirected = $coreOf($rc);
$rz = $rcOf(['redirectSession' => 0]);
$rz->t = $cl->t;
$clusterZero = $coreOf($rz);
$u16 = static fn(string $s): string => Text::toUtf16($s) . "\0\0";
$redirBody = static function (int $flags, array $fields, int $session = 5) : string {
    $b = '';
    foreach ($fields as $f) {
        $b .= pack('V', strlen($f)) . $f;
    }
    return "\0\0" . pack('vvVV', 0x0400, 12 + strlen($b), $session, $flags) . $b;
};
$addrs = pack('V', 2) . pack('V', strlen($u16('10.0.0.21'))) . $u16('10.0.0.21') . pack('V', strlen($u16('10.0.0.22'))) . $u16('10.0.0.22');
$red = Client::redirection($redirBody(0x1 | 0x2 | 0x4 | 0x8 | 0x10 | 0x100 | 0x800,
    [$u16('10.0.0.20'), "Cookie: msts=3640205228.15629.0000\r\n", $u16('ana'), $u16('EMPRESA'), "\x01\x02cookie\0\0", $u16('rdsh2.empresa.local'), $addrs]));
$redAddrOnly = Client::redirection($redirBody(0x2 | 0x800, ["Cookie: msts=1\r\n", $addrs]));   // a token, no target address
$redSame = Client::redirection($redirBody(0x2, ["Cookie: msts=1\r\n"]));
$redNames = Client::redirection($redirBody(0x200 | 0x800, [$u16('RDSH3'), $addrs]));       // no token: addresses, the NetBIOS name
$redPk = Client::redirection($redirBody(0x10 | 0x4000, ["\x01\x02"]));
$redEmpty = Client::redirection($redirBody(0x4 | 0x8, [$u16(''), $u16('')], 0));            // no session, empty user and domain
$base = ['host' => 'broker', 'ip' => '10.0.0.1', 'user' => 'otro', 'domain' => 'DOM', 'lb' => 'tsv://x', 'port' => 3389];
$next = Client::redirected($base, $red, '10.0.0.20', '10.0.0.20');
$nextToken = Client::redirected($base, $redAddrOnly, 'broker', '10.0.0.1');
$nextEmpty = Client::redirected($base + ['redirectSession' => 9], $redEmpty, 'broker', '10.0.0.1');
$thrown = null;
try {
    (new ReflectionMethod(Client::class, 'ioPdu'))->invoke($rc, pack('vvv', 6 + strlen($b = $redirBody(0x2, ["Cookie: msts=9\r\n"])), 0x1A, 1002) . $b);
} catch (MuDesk\Rdp\Redirection $e) {
    $thrown = $e->info['lb'];
}
$informed = 'not thrown';
try {
    (new ReflectionMethod(Client::class, 'ioPdu'))->invoke($rc, pack('vvv', 6 + strlen($b = $redirBody(0x80, [])), 0x1A, 1002) . $b);
} catch (MuDesk\Rdp\Redirection) {
    $informed = 'thrown';
}
$gwPair = (new ReflectionClass(MuDesk\Rdp\Gateway::class))->newInstanceWithoutConstructor();
(new ReflectionMethod(MuDesk\Rdp\Gateway::class, 'pair'))->invoke($gwPair);
$tooLong = 'sent';
try {
    $rcOf(['host' => 'pc', 'port' => 3389, 'user' => 'ana', 'security' => 'auto', 'lb' => str_repeat('t', 239), 'gateway' => static fn() => $gwPair])->connect(static fn() => null);
} catch (Throwable $e) {                                       // (sent: the bare tunnel fails on, said as what failed)
    $tooLong = $e instanceof MuDesk\Rdp\ProtocolException ? $e->key : get_class($e);
}
$gwPair->close();
check('connection broker: redirection supported (cluster block), the Server Redirection PDU read, where it sends the client and the next connection, an informing one not followed, a token too long refused', [
    bin2hex($clusterNew), bin2hex($clusterRedirected), bin2hex($clusterZero),
    [$red['session'], $red['address'], $red['lb'], $red['user'], $red['domain'], $red['cookie'], $red['fqdn'], $red['addresses']],
    [Client::redirectTargets($red, false), Client::redirectTargets($red, true), Client::redirectTargets($redAddrOnly, false), Client::redirectTargets($redSame, true),
     Client::redirectTargets($redNames, false), Client::redirectTargets($redNames, true), $redPk['cookie']],
    [$next['host'], $next['ip'], $next['lb'], $next['redirectSession'], $next['user'], $next['domain'], $next['redirectCookie']],
    [$nextToken['host'], $nextToken['lb'], $nextToken['redirectSession'], $nextToken['user']],
    [isset($nextEmpty['redirectSession']), $nextEmpty['user'], $nextEmpty['domain']], $thrown, $informed, $tooLong],
    ['04c00c001100000000000000', '04c00c001300000007000000', '04c00c001100000000000000',
     [5, '10.0.0.20', "Cookie: msts=3640205228.15629.0000\r\n", 'ana', 'EMPRESA', "\x01\x02cookie\0\0", 'rdsh2.empresa.local', ['10.0.0.21', '10.0.0.22']],
     [['10.0.0.20', '10.0.0.21', '10.0.0.22', 'rdsh2.empresa.local'], ['rdsh2.empresa.local', '10.0.0.20', '10.0.0.21', '10.0.0.22'], [], [],
      ['10.0.0.21', '10.0.0.22', 'RDSH3'], ['RDSH3', '10.0.0.21', '10.0.0.22'], null],
     ['10.0.0.20', '10.0.0.20', '', 5, 'ana', 'EMPRESA', "\x01\x02cookie\0\0"],
     ['broker', "Cookie: msts=1\r\n", 5, 'otro'],
     [false, 'otro', 'DOM'], "Cookie: msts=9\r\n", 'not thrown', 'routing_token']);
// a field of a long run of zeros before its end (a hostile broker's or server's): read at once (before, the pattern that cut
// the trailing zeros gave up on it: an internal error ended the session, or minutes of CPU went without the PCRE JIT)
$redT0 = microtime(true);
try {
    $redZeros = Client::redirection($redirBody(0x1 | 0x4, [str_repeat("\0", 60000) . "A\0", $u16('ana') . str_repeat("\0", 30000) . "B\0"]));
    $redZeros = [$redZeros['address'], $redZeros['user']];
} catch (Throwable $e) {
    $redZeros = get_class($e);
}
check('connection broker: redirection fields of a long run of zeros read at once', [$redZeros, microtime(true) - $redT0 < 1], [['', 'ana'], true]);
// the broker's redirection: the target's certificate it vouches for read (a Target Certificate Container in base64, the
// certificate found among other elements); its password, when it is text and the user's own is not known (signed in on its
// logon screen, without NLA), used to sign in with NLA there (before, an empty password); an opaque cookie never taken for a
// password; an empty domain taken with a user of the form user@domain (before, the old domain stayed with it)
$tcDer   = (string)base64_decode((string)preg_replace('~-----[^-]+-----|\s+~', '', (string)file_get_contents(__DIR__ . '/fixtures/pss-cert.pem')));
$redCert = Client::redirection($redirBody(0x1 | 0x10000, [$u16('10.0.0.30'), $u16(base64_encode(pack('VVV', 1, 1, 3) . 'abc' . pack('VVV', 32, 1, strlen($tcDer)) . $tcDer))]));
$redPw   = Client::redirection($redirBody(0x1 | 0x4 | 0x8 | 0x10, [$u16('10.0.0.30'), $u16('ana@empresa.com'), $u16(''), $u16('Clave-Broker-1')]));
$redBin  = Client::redirection($redirBody(0x1 | 0x10, [$u16('10.0.0.30'), "\x02\x00\x00\x80DSHL" . str_repeat("\x07", 110) . "\0\0"]));
$noPw    = ['password' => ''] + $base;
$nextPw  = Client::redirected($noPw, $redPw, '10.0.0.30', '10.0.0.30');
check('connection broker: the target\'s certificate it vouches for; its password for NLA when the user\'s is not known, never an opaque cookie; a user@domain with its empty domain', [
    $redCert['cert'] === hash('sha256', $tcDer), Client::targetCertificate(base64_encode(pack('VVV', 32, 1, 99) . 'x')),
    $nextPw['password'], Client::redirected(['password' => 'Propia-1'] + $base, $redPw, '10.0.0.30', '10.0.0.30')['password'],
    Client::redirected($noPw, $redBin, '10.0.0.30', '10.0.0.30')['password'], Client::cookiePassword($u16("a\x01b")),
    [$nextPw['user'], $nextPw['domain'], Client::redirected($noPw, $redEmpty, 'b', 'b')['domain']]],
    [true, null, 'Clave-Broker-1', 'Propia-1', '', null, ['ana@empresa.com', '', 'DOM']]);

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
for ($w0 = microtime(true); microtime(true) - $w0 < 0.07;) {   // (usleep() on Windows may wake early)
    usleep(5000);
}
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
$fc->active = true;
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
unset($fc, $tooBig, $longer);                                                       // (megabytes the tests after need: 128 MB in all)
$sink->msg = '';
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

// a diagnostic trace keeps no content: what is copied either way, files through the clipboard and what is printed are only
// counted (the trace's channel statistics), never written down, not even in hexadecimal
$secret = 'SecretoDelUsuario-' . bin2hex(random_bytes(4));
$gsid   = bin2hex(random_bytes(16));
$gc     = new Client(['width' => 800, 'height' => 600], static fn() => null);
$gc->t  = $tr;
(new ReflectionProperty(Client::class, 'channelIds'))->setValue($gc, ['cliprdr' => 1006, 'rdpdr' => 1004]);
(new ReflectionProperty(Client::class, 'channelOpts'))->setValue($gc, ['cliprdr' => 0xC0000000, 'rdpdr' => 0x80800000]);
$gc->trace = new MuDesk\Rdp\Trace($gsid, []);
$gclip     = new MuDesk\Rdp\Cliprdr($gc, 'tracetest');
(new ReflectionProperty(MuDesk\Rdp\Cliprdr::class, 'ready'))->setValue($gclip, true);
$gclip->fromBrowser('T' . $secret);                                                       // copied in the browser
$gclip->onData(pack('vvV', 4, 0, 4) . pack('V', 13));                                     // ... and pasted remotely
$gclip->onData(pack('vvV', 2, 0, 36) . pack('V', 13) . $name(''));                         // copied remotely
$gclip->onData(pack('vvV', 5, 1, strlen(Text::toUtf16($secret)) + 2) . Text::toUtf16($secret) . "\0\0");
$gclip->onData(pack('vvV', 9, 1, 4 + strlen($secret)) . pack('V', 1) . $secret);        // file contents
$gdir = "$tmp/trace-print";
$gdev = new Rdpdr($gc, 'tracetest', false, true, $gdir);
$gdev->onData(pack('vvvvV', 0x4472, 0x4343, 1, 12, 7));
$gdev->onData($irp(0, 1, 0x00, str_repeat("\0", 32)));
$gdev->onData($irp(1, 2, 0x04, pack('VP', strlen($secret), 0) . str_repeat("\0", 20) . $secret));   // printed
$gdev->onData($irp(1, 3, 0x02, str_repeat("\0", 32)));
$gc->trace->close([]);
$glog = (string)file_get_contents("$tmp/data/trace/$gsid.log");
check('trace: no clipboard, file or print content in it', [substr_count($glog, ' clipboard ') >= 3, str_contains($glog, ' rdpdr '),
    array_filter([$secret, Text::toUtf16($secret), bin2hex($secret), bin2hex(Text::toUtf16($secret)), bin2hex(substr(Text::toUtf16($secret), 0, 16)),
        bin2hex(substr($secret, 0, 8))],
        static fn($s) => str_contains($glog, $s))], [true, true, []]);
unlink("$tmp/data/trace/$gsid.log");
array_map('unlink', glob("$gdir/*") ?: []);
is_dir($gdir) && rmdir($gdir);
$out();

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
$mi_heard1   = $mdvc->micData(str_repeat("\x11", 2000));
$mi_data1    = $dvcOut();
$mi_heard2   = $mdvc->micData(str_repeat("\x22", 646));                        // 236 waiting + 646 = one more packet
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
$mi_heardAfter = $mdvc->micData(str_repeat("\x55", 2000));
$mi_after    = $dvcOut();
check('microphone: what the remote computer was sent of it given back, whole packets (as a recording keeps it); nothing once closed or without the option', [
    $mi_heard1 === str_repeat("\x11", 1764), $mi_heard2 === str_repeat("\x11", 236) . str_repeat("\x22", 646), $mi_heardAfter,
    (new MuDesk\Rdp\Drdynvc($mc, false))->micData(str_repeat("\x11", 2000))], [true, true, '', '']);
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
// camera (MS-RDPECAM), with the microphone when the browser has one: its enumeration channel taken only so; the client's
// version (2) first, then its camera by name and channel; the camera's channel only after that; activated; one stream; the
// formats (Motion JPEG, four sizes) and the current one; started at a size offered (the browser told: 640 x 480 at 30), else
// refused; each Sample Request answered once: the newest picture (an older one waiting replaced), or when the next comes;
// the browser's failure answers the requests waiting, and the next, with an error; version 2's properties: none; a request
// too short or unknown answered with an error; stopped, the browser lets go
$camReq = static function ($dvc, int $id, string $m) use ($dvcOut): array {
    $dvc->onData("\x30" . chr($id) . $m);
    return array_map(static fn($x) => bin2hex($x[2]), $dvcOut());
};
$mframes = [];
(new MuDesk\Rdp\Drdynvc($mc, false, true))->onData("\x10\x08" . MuDesk\Rdp\Rdpecam::ENUMERATOR . "\0");
$camRefused = $dvcOut();
$cdvc = new MuDesk\Rdp\Drdynvc($mc, false, true, false, 'Cámara de MuDesk');
$cdvc->onData("\x10\x09" . MuDesk\Rdp\Rdpecam::DEVICE . "\0");                     // its channel before the enumeration: refused
$camEarly = $dvcOut();
$cdvc->onData("\x10\x08" . MuDesk\Rdp\Rdpecam::ENUMERATOR . "\0");
$camEnum = array_map(static fn($x) => bin2hex($x[2]), $dvcOut());
$camAdded = $camReq($cdvc, 8, pack('CC', 2, 0x04));                                 // Select Version Response: 2
$cdvc->onData("\x10\x09" . MuDesk\Rdp\Rdpecam::DEVICE . "\0");
$camDev = array_map(static fn($x) => bin2hex($x[2]), $dvcOut());
$camMt = static fn(int $w, int $h) => bin2hex(pack('CVVVVVVC', 2, $w, $h, 30, 1, 1, 1, 1));
$camStart = static fn(int $w, int $h, int $f = 2) => pack('CC', 2, 0x0F) . pack('CCVVVVVVC', 0, $f, $w, $h, 30, 1, 1, 1, 1);
$camR = [$camReq($cdvc, 9, pack('CC', 2, 0x07)), $camReq($cdvc, 9, pack('CC', 2, 0x09)), $camReq($cdvc, 9, pack('CCC', 2, 0x0B, 0)),
    $camReq($cdvc, 9, pack('CCC', 2, 0x0B, 1)), $camReq($cdvc, 9, pack('CCC', 2, 0x0D, 0)), $camReq($cdvc, 9, $camStart(800, 600)),
    $camReq($cdvc, 9, $camStart(640, 480, 1)), $camReq($cdvc, 9, pack('CCC', 2, 0x11, 0))];
$mframes = [];
$camGo = [$camReq($cdvc, 9, $camStart(640, 480)), $mframes];
$camRun = ord($mframes[0][1][6] ?? "\0");                                            // the start's number: its pictures say it
$camS = [$camReq($cdvc, 9, pack('CCC', 2, 0x11, 0))];                                 // none yet: answered when it comes
$cdvc->camData(chr($camRun) . 'JPEG1');
$camS[] = array_map(static fn($x) => bin2hex($x[2]), $dvcOut());
$cdvc->camData(chr($camRun) . 'JPEG2');
$cdvc->camData(chr($camRun) . 'JPEG3');                                              // two before the next request: the newest
$camS[] = $camReq($cdvc, 9, pack('CCC', 2, 0x11, 0));
$camS[] = $camReq($cdvc, 9, pack('CCC', 2, 0x11, 0)) + $camReq($cdvc, 9, pack('CCC', 2, 0x11, 0));   // two waiting
$cdvc->camData(chr($camRun));                                                        // the browser could not open its camera
$camS[] = array_map(static fn($x) => bin2hex($x[2]), $dvcOut());
$camS[] = $camReq($cdvc, 9, pack('CCC', 2, 0x11, 0));
$camO = [$camReq($cdvc, 9, pack('CC', 2, 0x14)), $camReq($cdvc, 9, pack('CCCC', 2, 0x16, 1, 2)), $camReq($cdvc, 9, "\2"), $camReq($cdvc, 9, pack('CC', 2, 0x30))];
$mframes = [];
$camStop = [$camReq($cdvc, 9, pack('CC', 2, 0x10)), $mframes];
$mframes = [];
$cdvc->onData("\x40\x09");
$camClosed = [$dvcOut(), $mframes];
$camHex = static fn(string $s) => bin2hex($s);
check('camera: with the microphone and a camera; version, the camera announced, its channel after; streams, formats, start; one answer to each request, the newest picture; failure, properties, malformed, stop', [
    $camRefused[0][2] ?? null, $camEarly[0][2] ?? null, $camEnum, $camAdded, $camDev, $camR, $camGo, $camS, $camO, $camStop, $camClosed], [
    pack('V', 0xC0000001), pack('V', 0xC0000001), [bin2hex(pack('V', 0)), '0203'],
    [$camHex(pack('CC', 2, 5) . Text::toUtf16('Cámara de MuDesk') . "\0\0RDCamera_Device_0\0")], [bin2hex(pack('V', 0))],
    [['0201'], ['020a0100010100'], ['020c' . $camMt(640, 480) . $camMt(1280, 720) . $camMt(640, 360) . $camMt(320, 240)], [$camHex(pack('CCV', 2, 2, 5))],
     ['020e' . $camMt(640, 480)], [$camHex(pack('CCV', 2, 2, 6))], [$camHex(pack('CCV', 2, 2, 6))], [$camHex(pack('CCCV', 2, 0x13, 0, 4))]],
    [['0201'], [[Client::F_CAM, pack('CvvCC', 1, 640, 480, 30, $camRun)]]],
    [[], ['021200' . bin2hex('JPEG1')], ['021200' . bin2hex('JPEG3')], [], [$camHex(pack('CCCV', 2, 0x13, 0, 1)), $camHex(pack('CCCV', 2, 0x13, 0, 1))],
     [$camHex(pack('CCCV', 2, 0x13, 0, 1))]],
    [['0215'], [$camHex(pack('CCV', 2, 2, 8))], [$camHex(pack('CCV', 2, 2, 2))], [$camHex(pack('CCV', 2, 2, 2))]],
    [['0201'], [[Client::F_CAM, "\0"]]], [[[4, 9, '']], []]]);
// ... the camera's channel opened twice, as Windows 11 does (it spreads its requests across them): one camera, its state
// shared, each request answered on the channel it came on (before, only the last one opened was answered: Windows waited on
// the first); a picture to the oldest request waiting, on whichever channel; the camera on while a channel has it active
$ch2 = new MuDesk\Rdp\Drdynvc($mc, false, true, false, 'Cámara de MuDesk');
$ch2->onData("\x10\x08" . MuDesk\Rdp\Rdpecam::ENUMERATOR . "\0");
$ch2->onData("\x30\x08" . pack('CC', 2, 0x04));
$ch2->onData("\x10\x09" . MuDesk\Rdp\Rdpecam::DEVICE . "\0");
$ch2->onData("\x10\x0A" . MuDesk\Rdp\Rdpecam::DEVICE . "\0");
$dvcOut();
$ch2At = static function (int $ch, string $m) use ($ch2, $dvcOut): array {
    $ch2->onData("\x30" . chr($ch) . $m);
    return array_map(static fn($x) => [$x[1], bin2hex($x[2])], $dvcOut());
};
$mframes = [];
$two = [$ch2At(9, pack('CC', 2, 0x07)), $ch2At(10, pack('CC', 2, 0x07)), $ch2At(9, pack('CC', 2, 0x09)), $ch2At(10, $camStart(320, 240)),
    $ch2At(10, pack('CCC', 2, 0x11, 0)), $ch2At(9, pack('CCC', 2, 0x11, 0))];   // (two waiting: on 10, then on 9)
$ch2->camData(($mframes[0][1][6] ?? "\0") . 'PIC');
$two[] = array_map(static fn($x) => [$x[1], bin2hex($x[2])], $dvcOut());
$twoStart = $mframes;
$mframes = [];
$twoDeact9 = [$ch2At(9, pack('CC', 2, 0x08)), $mframes];                     // deactivated on 9: 10 still has it, the camera stays on
$ch2->onData("\x40\x09");                                                    // the one not streaming closed: the camera stays on
$twoClose9 = [$dvcOut(), $mframes];
$ch2->onData("\x40\x0A");                                                    // the one streaming: off
$twoClose10 = [$dvcOut(), $mframes];
check('camera: its channel opened twice, one camera: each request answered on its own channel, a picture to the oldest request, off when no channel has it active', [
    $two, $twoStart, $twoDeact9, $twoClose9, $twoClose10], [
    [[[9, '0201']], [[10, '0201']], [[9, '020a0100010100']], [[10, '0201']], [], [], [[10, '021200' . bin2hex('PIC')]]],
    [[Client::F_CAM, pack('CvvC', 1, 320, 240, 30) . ($twoStart[0][1][6] ?? '')]], [[[9, '0201']], []], [[[4, 9, '']], []], [[[4, 10, '']], [[Client::F_CAM, "\0"]]]]);
// ... a start after the browser could not open its camera asks it again (the user may allow it now), at the same size too;
// each start numbered: a picture of one before (another size, still on its way) left out; the connection given up (another
// made for a guest, a broker's redirection): the browser lets go of its camera; a gateway's policy without Plug and Play
// devices: no camera, as Windows' client
$cr = new MuDesk\Rdp\Drdynvc($mc, false, true, false, 'Cámara de MuDesk');
$cr->onData("\x10\x08" . MuDesk\Rdp\Rdpecam::ENUMERATOR . "\0");
$cr->onData("\x30\x08" . pack('CC', 2, 0x04));
$cr->onData("\x10\x09" . MuDesk\Rdp\Rdpecam::DEVICE . "\0");
$camReq($cr, 9, pack('CC', 2, 0x07));
$mframes = [];
$camReq($cr, 9, $camStart(640, 480));
$crRun1 = ord($mframes[0][1][6] ?? "\0");
$cr->camData(chr($crRun1));                                                          // it could not open its camera
$camReq($cr, 9, $camStart(640, 480));                                               // started again, the same size
$crAgain = array_map(static fn($x) => [$x[0], substr($x[1], 0, 6)], $mframes);
$crRun2 = ord($mframes[1][1][6] ?? "\0");
$crWait = $camReq($cr, 9, pack('CCC', 2, 0x11, 0));
$cr->camData(chr($crRun1) . 'OLD');                                                  // of the start before: left out
$crOld = $dvcOut();
$cr->camData(chr($crRun2) . 'NEW');
$crNew = array_map(static fn($x) => bin2hex($x[2]), $dvcOut());
$mframes = [];
$cr->stopCapture();
$crStop = $mframes;
$mc->denied = ['pnp'];
(new MuDesk\Rdp\Drdynvc($mc, false, true, false, 'Cámara de MuDesk'))->onData("\x10\x08" . MuDesk\Rdp\Rdpecam::ENUMERATOR . "\0");
$crPnp = $dvcOut();
$mc->denied = [];
check('camera: asked again after it could not be opened, each start numbered (a picture of one before left out), let go when the connection is given up; not with a gateway\'s policy against Plug and Play devices', [
    $crAgain, $crRun1 !== $crRun2, $crWait, $crOld, $crNew, $crStop, $crPnp[0][2] ?? null], [
    [[Client::F_CAM, pack('Cvv', 1, 640, 480) . chr(30)], [Client::F_CAM, pack('Cvv', 1, 640, 480) . chr(30)]], true, [], [], ['021200' . bin2hex('NEW')],
    [[Client::F_CAM, "\0"]], pack('V', 0xC0000001)]);
// multitouch (MS-RDPEI on "Microsoft::Windows::RDS::Input"): the variable-length integers as the specification's examples;
// the channel taken only with the option; SC_READY answered with CS_READY (16 bytes: touch visuals, version 1.0.1 or the
// server's 1.0.0, 10 fingers) and the browser told; a frame of the browser as one touch event PDU (encodeTime 0, one frame,
// its fingers with the allowed flags, x and y signed, the first frameOffset 0); nothing sent while suspended, after a
// malformed frame or a length that does not match; resumed and closed, the browser told
$R = MuDesk\Rdp\Rdpei::class;
$encs = [$R::u16(0x1A1B), $R::u16(5), $R::s16(-0x1A1B), $R::s16(-2), $R::u32(0x1A1B1C), $R::s32(-0x1A1B1C), $R::s32(-2), $R::u64(0x001A1B1C1D1E1F2A),
    $R::s32(1919), $R::u32(0x3FFFFFFF)];
$mframes = [];
$ti_create = "\x10\x07" . $R::NAME . "\0";
(new MuDesk\Rdp\Drdynvc($mc, false))->onData($ti_create);
$ti_refused = $dvcOut();
$tdvc = new MuDesk\Rdp\Drdynvc($mc, false, false, true);
$tdvc->onData($ti_create);
$ti_accepted = $dvcOut();
$tdvc->touchData("\x01\x00\x00\x0A\x00\x14\x00");                              // before SC_READY: not sent
$ti_early = $dvcOut();
$tdvc->onData("\x30\x07" . pack('vVVV', 1, 14, 0x00030000, 1));                // SC_READY version 3.0.0
$ti_ready = [$dvcOut(), $mframes];
$mframes = [];
$tdvc->touchData("\x02" . pack('CCvv', 0, 0, 100, 200) . pack('CCvv', 1, 1, 1919, 0xFFFE));   // a finger down, another moved (x 1919, y -2)
$ti_frame1 = $dvcOut();
$tdvc->touchData("\x01" . pack('CCvv', 0, 2, 100, 200));
$ti_frame2 = $dvcOut();
$tdvc->onData("\x30\x07" . pack('vV', 4, 6));                                   // SUSPEND_INPUT
$tdvc->touchData("\x01" . pack('CCvv', 0, 0, 1, 1));
$ti_suspended = [$dvcOut(), $mframes];
$mframes = [];
$tdvc->onData("\x30\x07" . pack('vV', 5, 6));                                   // RESUME_INPUT
$tdvc->onData("\x30\x07" . pack('vV', 4, 9));                                   // a length that does not match: left out
$tdvc->touchData("\x01" . pack('CCvv', 0, 7, 1, 1));                            // a state that does not exist
$tdvc->touchData("\x0B" . str_repeat(pack('CCvv', 0, 1, 1, 1), 11));             // more than ten fingers
$ti_bad = [$dvcOut(), $mframes];
$mframes = [];
$tdvc->onData("\x40\x07");                                                      // closed
$ti_closed = [$dvcOut(), $mframes];
$ti_v100 = new MuDesk\Rdp\Drdynvc($mc, false, false, true);
$ti_v100->onData($ti_create);
$dvcOut();
$ti_v100->onData("\x30\x07" . pack('vVV', 1, 10, 0x00010000));
$ti_old = $dvcOut();
$mframes = [];
$frame1 = $ti_frame1[0][2] ?? '';
check('multitouch: encodings as MS-RDPEI, the channel with the option only, ready answered, frames sent as touch events, suspended and malformed left out', [
    array_map('bin2hex', $encs), bin2hex($ti_refused[0][2] ?? ''), bin2hex($ti_accepted[0][2] ?? ''), $ti_early,
    bin2hex($ti_ready[0][0][2] ?? ''), $ti_ready[1],
    bin2hex(substr($frame1, 0, 6)), bin2hex(substr($frame1, 6)),
    bin2hex(substr($ti_frame2[0][2] ?? '', 6, 3)), ord(substr($ti_frame2[0][2] ?? '', 9, 1)) >> 5 > 0 || ord(substr($ti_frame2[0][2] ?? '', 9, 1)) > 0,
    bin2hex(substr($ti_frame2[0][2] ?? '', -6)),
    $ti_suspended, $ti_bad, $ti_closed, bin2hex($ti_old[0][2] ?? '')],
    [['9a1b', '05', 'da1b', '42', '9a1b1c', 'ba1b1c', '22', 'da1b1c1d1e1f2a', '477f', 'ffffffff'], '010000c0', '00000000', [],
     '02001000000001000000010001000a00', [[Client::F_STATUS, '{"touch":true}']],
     '030017000000', '00' . '01' . '02' . '00' . '00' . '00' . '4064' . '40c8' . '19' . '01' . '00' . '477f' . '22' . '1a',
     '000101', true, '00' . '4064' . '40c8' . '04',
     [[], [[Client::F_STATUS, '{"touch":false}']]], [[], [[Client::F_STATUS, '{"touch":true}']]], [[[4, 7, '']], [[Client::F_STATUS, '{"touch":false}']]],
     '02001000000001000000000001000a00']);
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
$mi_earlyHeard = $mi_early->micData(str_repeat("\x01", 2000));
check('microphone: a format change before the open left out (nothing of the audio sent, nor given back to be recorded)', [$dvcOut(), $mframes, $mi_earlyHeard], [[], [], '']);
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
    $tsKey = MuDesk\Rdp\RdpSecurity::publicKey(pack('VVV', 2, 1, strlen($tsDer)) . $tsDer . str_repeat("\0", 12));
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

// Standard RDP Security (Windows XP, Server 2003): the session keys of 40, 56 and 128-bit and FIPS from two randoms, a MAC
// and its salted form, the RC4 stream and the key renewed after 4096 PDUs, all as values worked out from MS-RDPBCGR 5.3 on
// their own (in another language); each side's PDUs opened by the other (the server's side made as the tests' server does),
// past 4096 PDUs each way, FIPS's padding; a changed byte refused as tampering
$lsCr = implode('', array_map('chr', range(0x10, 0x2F)));
$lsSr = implode('', array_map('chr', range(0xA0, 0xBF)));
$lsKeys = static function (int $m) use ($lsCr, $lsSr): array {
    $s = new MuDesk\Rdp\RdpSecurity($m, $lsCr, $lsSr);
    $g = static fn(string $p): string => bin2hex((new ReflectionProperty(MuDesk\Rdp\RdpSecurity::class, $p))->getValue($s));
    return [$g('macKey'), $g('encKey'), $g('decKey')];
};
$lsS = new MuDesk\Rdp\RdpSecurity(2, $lsCr, $lsSr);
[$lsSig, $lsC] = $lsS->encrypt('MuDeskClient standard security');
$lsSalted = (new MuDesk\Rdp\RdpSecurity(2, $lsCr, $lsSr))->encrypt('MuDeskClient standard security', true)[0];
$lsUpd = new ReflectionMethod(MuDesk\Rdp\RdpSecurity::class, 'update');
$lsS40 = new MuDesk\Rdp\RdpSecurity(1, $lsCr, $lsSr);
$lsInit = static fn(MuDesk\Rdp\RdpSecurity $s): string => (new ReflectionProperty(MuDesk\Rdp\RdpSecurity::class, 'encInitial'))->getValue($s);
$lsRound = [];
foreach ([1, 8, 2, 16] as $m) {
    [$cl, $sv] = [new MuDesk\Rdp\RdpSecurity($m, $lsCr, $lsSr), new MuDesk\Rdp\RdpSecurity($m, $lsCr, $lsSr, true)];
    $ok = true;
    for ($i = 0; $i < 4200 && $ok; $i++) {                  // (past the renewal at 4096)
        $msg = str_repeat(chr($i & 0xFF), $i % 37);       // (empty ones too: FIPS lost its chain after one)
        $ok = $sv->open($cl->seal(0x40, $msg)) === [0x48, $msg] && $cl->open($sv->seal($i % 2 ? 0x0800 : 0, $msg))[1] === $msg
            && $sv->fastOpen(implode('', $cl->fastSeal($msg)), false) === $msg;
    }
    $bad = 'not refused';
    try {
        $t = $sv->seal(0, 'abcdefgh');
        $cl->open(substr($t, 0, -1) . chr(ord($t[-1]) ^ 1));
    } catch (MuDesk\Rdp\ProtocolException $e) {
        $bad = $e->key;
    }
    $lsRound[] = [$ok, $bad];
}
check('Standard RDP Security: session keys (40, 56, 128-bit, FIPS), MAC, salted MAC, RC4, key renewal as worked out from the specification; both sides past 4096 PDUs; tampering refused', [
    $lsKeys(2), $lsKeys(1), $lsKeys(8), [bin2hex($lsSig), bin2hex($lsC)], bin2hex($lsSalted),
    [bin2hex($lsUpd->invoke($lsS, $lsInit($lsS), $lsInit($lsS))), bin2hex($lsUpd->invoke($lsS40, $lsInit($lsS40), $lsInit($lsS40)))],
    bin2hex((new ReflectionProperty(MuDesk\Rdp\RdpSecurity::class, 'macKey'))->getValue(new MuDesk\Rdp\RdpSecurity(16, $lsCr, $lsSr))), $lsRound],
    [['d946762b434a57c0e8eece5aad58fabd', 'ecb3818bedf8686cf562e3256938d9e7', 'cbcdb95b3dcb900462481d62fb8223cc'],
     ['d1269e2b434a57c0', 'd1269e8bedf8686c', 'd1269e5b3dcb9004'], ['d146762b434a57c0', 'd1b3818bedf8686c', 'd1cdb95b3dcb9004'],
     ['af60fec74a3f923a', '32931ce56694e80d5aedc0f8b190c26463cad522d953d5cdb456b5ca1702'], '748b6c9829561ceb',
     ['adacaa8fe818666cb7e3ea0c910e7455', 'd1269ec87b0deac8'], '30208ef97c98de4abc54690100a58721abb6d88b',
     array_fill(0, 4, [true, 'mitm'])]);
// ... the renewal itself: after 4096 PDUs the next one in the renewed key, from the start of its RC4 stream (with 128 and
// 40-bit keys: the renewed keys above, the data in RC4 worked out on its own; both sides renewing at the wrong time, or not
// at all, still open each other's PDUs); FIPS's chain over an empty PDU: the next one's IV the last block before it
$lsRenew = [];
foreach ([2, 1] as $m) {
    $lsR = new MuDesk\Rdp\RdpSecurity($m, $lsCr, $lsSr);
    for ($i = 0; $i < 4096; $i++) {
        $lsR->encrypt('');
    }
    $lsRenew[] = bin2hex($lsR->encrypt('MuDeskClient, renewed')[1]);
}
$lsFips = new MuDesk\Rdp\RdpSecurity(16, $lsCr, $lsSr);
$lsFipsKey = (new ReflectionProperty(MuDesk\Rdp\RdpSecurity::class, 'encKey'))->getValue($lsFips);
$lsC1 = $lsFips->encrypt('12345678')[1];
$lsFips->encrypt('');
$lsC3 = $lsFips->encrypt('abcdefgh')[1];
check('Standard RDP Security: the PDU after 4096 in the renewed key (128 and 40-bit); FIPS\'s chain kept over an empty PDU', [$lsRenew,
    bin2hex($lsC3) === bin2hex((string)openssl_encrypt('abcdefgh', 'des-ede3-cbc', $lsFipsKey, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, substr($lsC1, -8)))],
    [['44f8b91bbfa00dd768d058bd508bf5c3811b2188ed', 'ea329f5dba446db03bd9e4ed74d6229178dde9a8e2'], true]);

// ... and a whole connection with it (tests/fixtures/legacy_server.php as Windows XP and Server 2003 answer): no negotiation
// in the X.224 answer (XP), TLS refused (SSL_NOT_ALLOWED_BY_SERVER: connected again without it), PROTOCOL_RDP chosen; the
// server's key from a proprietary certificate or an X.509 chain, pinned as a certificate; the Client Info, licensing, the
// activation, a fast-path update and the client's input encrypted with each method (level 1: the server's in the clear);
// a License Request asking for the client's licensing PDUs encrypted; a Standard Security redirection; with the connection's
// security set to TLS, refused; a server whose TLS the client cannot share (2003's RC4 and triple DES): connected again with
// Standard RDP Security. Before, every one ended "the server only supports legacy RDP security" (and a refusal read as
// "closed": the server's answer, sent just before it closed, was thrown away)
$legacy = static function (string $mode, int $method, int $level, string $cert, string $security = 'auto') use ($cnf): array {
    $p = proc_open([PHP_BINARY, __DIR__ . '/fixtures/legacy_server.php', $mode, (string)$method, (string)$level, $cert, $cnf], [1 => ['pipe', 'w'], 2 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w']], $pp);
    $port = (int)trim((string)fgets($pp[1]));
    $got = [];
    $c = new Client(['host' => 'xp.test', 'ip' => '127.0.0.1', 'port' => $port, 'user' => 'ana', 'domain' => '', 'password' => 'Clave-XP-1',
        'security' => $security, 'width' => 800, 'height' => 600, 'bpp' => 16, 'kbd' => 0x040A, 'clientName' => 'c', 'monitors' => [], 'channels' => [],
        'audio' => false, 'mic' => false, 'orders' => false, 'gfx' => 'auto', 'compression' => 0, 'perf' => 0, 'tzBias' => 0, 'app' => '', 'vm' => '', 'lb' => ''],
        static function (int $type, string $d) use (&$got): void { $type === Client::F_UPDATE && $got[] = bin2hex($d); });
    $pin = '';
    try {
        $c->connect(static function (string $fp) use (&$pin): void { $pin = $fp; });
        for ($i = 0; $i < 100 && $got === []; $i++) {
            $c->t->wait(0.05);
            $c->poll();
        }
        $c->input("\x00\x1e\x01\x1e", 2);                 // 'a' down and up
        $r = 'connected';
    } catch (MuDesk\Rdp\Redirection $e) {
        $r = 'redirected to ' . $e->info['address'];
    } catch (MuDesk\Rdp\ProtocolException $e) {
        $r = $e->key;
    } finally {
        isset($c->t) && $c->disconnect();
    }
    $said = [];
    while (($l = fgets($pp[1])) !== false) {
        $said[] = trim($l);
    }
    proc_close($p);
    return [$r, $pin !== '' ? 'pinned' : 'none', $got, $said, $c->rdpSecurity];
};
check('Standard RDP Security: XP (no negotiation), 2003 refusing TLS or sharing no TLS (TLS 1.2, then 1.0, then without), PROTOCOL_RDP chosen; proprietary and X.509 keys; each method; licensing asked encrypted; redirection; TLS-only connection refused', [
    $legacy('xp', 2, 2, 'proprietary'), $legacy('refuse', 16, 3, 'x509'), $legacy('select0', 1, 1, 'proprietary'), $legacy('licreq', 8, 2, 'x509'),
    $legacy('redirect', 2, 3, 'proprietary'), $legacy('xp', 2, 2, 'proprietary', 'tls'), $legacy('tlsfail', 2, 2, 'proprietary')],
    [['connected', 'pinned', ['05'], ['x224 3', 'methods 0x1b', 'info ana Clave-XP-1', 'confirm ok', 'input 001e011e events 2'], '128-bit'],
     ['connected', 'pinned', ['05'], ['x224 3', 'x224 none', 'methods 0x1b', 'info ana Clave-XP-1', 'confirm ok', 'input 001e011e events 2'], 'FIPS'],
     ['connected', 'pinned', ['05'], ['x224 3', 'methods 0x1b', 'info ana Clave-XP-1', 'confirm ok', 'input 001e011e events 2'], '40-bit'],
     ['connected', 'pinned', ['05'], ['x224 3', 'methods 0x1b', 'info ana Clave-XP-1', 'license reply encrypted', 'confirm ok', 'input 001e011e events 2'], '56-bit'],
     ['redirected to 10.9.8.7', 'pinned', [], ['x224 3', 'methods 0x1b', 'info ana Clave-XP-1'], '128-bit'],
     ['rdp_security', 'none', [], ['x224 1', 'methods none', 'error closed'], null],
     ['connected', 'pinned', ['05'], ['x224 3', 'x224 3', 'x224 none', 'methods 0x1b', 'info ana Clave-XP-1', 'confirm ok', 'input 001e011e events 2'], '128-bit']]);
// ... and a License Request that does not ask for the client's licensing PDUs encrypted: answered in the clear, as the
// specification has it (an encrypted one is not read as a licensing PDU there)
check('Standard RDP Security: a License Request not asking for encryption answered in the clear', $legacy('licplain', 2, 2, 'x509'),
    ['connected', 'pinned', ['05'], ['x224 3', 'methods 0x1b', 'info ana Clave-XP-1', 'license reply plain', 'confirm ok', 'input 001e011e events 2'], '128-bit']);
// a server refusing TLS as it has no certificate (SSL_CERT_NOT_ON_SERVER): asked again without TLS, as for one that does
// not allow it (before, the session ended)
check('Standard RDP Security: a server without a TLS certificate asked again without TLS', $legacy('nocert', 2, 2, 'proprietary'),
    ['connected', 'pinned', ['05'], ['x224 3', 'x224 none', 'methods 0x1b', 'info ana Clave-XP-1', 'confirm ok', 'input 001e011e events 2'], '128-bit']);
// ... and one without the server's certificate: answered with the key of Standard RDP Security's server data (before, a
// truncated PDU: the session ended at licensing)
check('Standard RDP Security: a License Request without the server\'s certificate answered with its key', $legacy('licnocert', 2, 2, 'proprietary'),
    ['connected', 'pinned', ['05'], ['x224 3', 'methods 0x1b', 'info ana Clave-XP-1', 'license reply plain', 'confirm ok', 'input 001e011e events 2'], '128-bit']);
// a server (or a man in the middle posing as an old one) that encrypts nothing: refused before anything is sent (before, the
// password went in the clear and no key was checked); a redirection sent as 0x0C00 (encrypted, without SEC_ENCRYPT) read
// (before: refused as a bad one); a PDU or a fast-path update in the clear at a level that encrypts everything the server
// sends: refused (before, taken: text in the clipboard, files in the shared folder from someone who cannot break the key)
check('Standard RDP Security: a server that encrypts nothing refused before the password; a redirection as 0x0C00 read; a PDU in the clear refused where all is encrypted', [
    $legacy('none', 2, 2, 'proprietary'), $legacy('noblock', 2, 2, 'proprietary'), $legacy('redir0c00', 2, 2, 'proprietary'),
    $legacy('plain', 2, 2, 'proprietary'), $legacy('plainfast', 8, 3, 'x509')],
    [['rdp_unencrypted', 'none', [], ['x224 3', 'methods 0x1b', 'error closed'], null],
     ['rdp_unencrypted', 'none', [], ['x224 3', 'methods 0x1b', 'error closed'], null],
     ['redirected to 10.9.8.7', 'pinned', [], ['x224 3', 'methods 0x1b', 'info ana Clave-XP-1'], '128-bit'],
     ['protocol', 'pinned', [], ['x224 3', 'methods 0x1b', 'info ana Clave-XP-1', 'error not a Send Data Request: 0300000902f0802180'], '128-bit'],   // (its disconnection)
     ['protocol', 'pinned', [], ['x224 3', 'methods 0x1b', 'info ana Clave-XP-1', 'confirm ok', 'input clear 000902f0802180 events 0'], '56-bit']]);
// the server's key: its pin not the same for two keys whose modulus and exponent run together the same way (before,
// "n \0 e": a modulus with 00 01 more and an exponent of 1 gave the pin of the real key); a key with an exponent of 1 or
// even, an even modulus, or one under 512 bits refused (an exponent of 1 sent the client random as it was)
$lsKey = static function (string $n, string $e): string {
    $mod  = strrev($n) . str_repeat("\0", 8);
    $blob = 'RSA1' . pack('VVV', strlen($mod), strlen($n) * 8, strlen($n) - 1) . strrev(str_pad($e, 4, "\0", STR_PAD_LEFT)) . $mod;
    try {
        return bin2hex(implode('/', MuDesk\Rdp\RdpSecurity::publicKey(pack('VVVvv', 1, 1, 1, 6, strlen($blob)) . $blob . pack('vv', 8, 72) . str_repeat("\0", 72))));
    } catch (MuDesk\Rdp\ProtocolException $e) {
        return $e->getMessage();
    }
};
$lsN = "\xC3" . str_repeat("\x5A", 62) . "\x0B";
check('Standard RDP Security: the pins of two keys that ran together told apart; exponents of 1 or even, an even modulus, one under 512 bits refused', [
    MuDesk\Rdp\RdpSecurity::pin($lsN, "\x01\x00\x01") === MuDesk\Rdp\RdpSecurity::pin($lsN . "\x00\x01", "\x01"),
    $lsKey($lsN, "\x01\x00\x01") === bin2hex($lsN . '/' . "\x01\x00\x01"), $lsKey($lsN, "\x01"), $lsKey($lsN, "\x01\x00\x00"),
    $lsKey(substr($lsN, 0, -1) . "\x0A", "\x01\x00\x01"), $lsKey(substr($lsN, 1), "\x01\x00\x01")],
    [false, true, 'Invalid server key', 'Invalid server key', 'Invalid server key', 'Invalid server key']);

// CredSSP version 3, as Windows 7 and Server 2008 R2 without updates speak it (no nonce: the public key bound as it is, the
// server's answer with its first byte plus one): signed in, the credentials delegated
$v3p = proc_open([PHP_BINARY, __DIR__ . '/fixtures/nla_server.php', 'ana', 'TEST', 'Clave-Win7-1', $cnf, 'v3'], [1 => ['pipe', 'w'], 2 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w']], $v3pp);
$v3c = new Client(['host' => 'win7', 'ip' => '127.0.0.1', 'port' => (int)trim((string)fgets($v3pp[1])), 'user' => 'ana', 'domain' => 'TEST', 'password' => 'Clave-Win7-1',
    'security' => 'nla', 'width' => 800, 'height' => 600, 'bpp' => 16, 'kbd' => 0x040A, 'clientName' => 'c', 'monitors' => [], 'channels' => [], 'audio' => false,
    'mic' => false, 'orders' => false, 'gfx' => 'auto', 'compression' => 0, 'perf' => 0, 'tzBias' => 0, 'app' => '', 'vm' => '', 'lb' => ''], static fn() => null);
try {
    $v3c->connect(static fn() => null);
    $v3r = 'connected';
} catch (MuDesk\Rdp\ProtocolException $e) {
    $v3r = $e->key;
} finally {
    isset($v3c->t) && $v3c->disconnect();
}
$v3said = trim((string)fgets($v3pp[1]));
proc_terminate($v3p);
proc_close($v3p);
check('NLA: CredSSP version 3 (Windows 7, Server 2008 R2): the key bound without a nonce, the credentials delegated', [$v3r, $v3said], ['closed', 'ok creds']);

// TLS 1.2 or later first: TLS 1.0 and triple DES only to a computer never seen with TLS 1.2 (on a new connection, as Windows'
// own client), and then at once for a day (not after a failed handshake each time); a computer seen with TLS 1.2 is never
// offered less (someone in between who made the first handshake fail took any computer back to TLS 1.0)
$t10p = proc_open([PHP_BINARY, __DIR__ . '/fixtures/nla_server.php', 'ana', 'TEST', 'Clave-Win7-1', $cnf, 'tls10'], [1 => ['pipe', 'w'], 2 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w']], $t10pp);
$t10port = (int)trim((string)fgets($t10pp[1]));
$t10c = static function (string $host) use ($t10port): string {
    $c = new Client(['host' => $host, 'ip' => '127.0.0.1', 'port' => $t10port, 'user' => 'ana', 'domain' => 'TEST', 'password' => 'Clave-Win7-1',
        'security' => 'nla', 'width' => 800, 'height' => 600, 'bpp' => 16, 'kbd' => 0x040A, 'clientName' => 'c', 'monitors' => [], 'channels' => [], 'audio' => false,
        'mic' => false, 'orders' => false, 'gfx' => 'auto', 'compression' => 0, 'perf' => 0, 'tzBias' => 0, 'app' => '', 'vm' => '', 'lb' => ''], static fn() => null);
    try {
        $c->connect(static fn() => null);
        return 'connected';
    } catch (MuDesk\Rdp\ProtocolException $e) {
        return $e->key;
    } finally {
        isset($c->t) && $c->disconnect();
    }
};
$t10said = static function () use ($t10pp): array {
    $l = [];
    while (($s = trim((string)fgets($t10pp[1]))) !== '') {
        $l[] = $s;
        if ($s !== 'tls') {
            break;
        }
    }
    return $l;
};
$t10a = [$t10c('viejo2008'), $t10said(), Transport::tlsSeen("viejo2008:$t10port")];
$t10b = [$t10c('viejo2008'), $t10said()];
Transport::tlsWorked("nuevo2019:$t10port", 'TLSv1.3');   // seen with TLS 1.3 before
$t10m = [$t10c('nuevo2019'), trim((string)fgets($t10pp[1]))];   // (its line, once the server wrote it)
proc_terminate($t10p);
$t10m[] = trim((string)stream_get_contents($t10pp[1]));   // no other connection after it
proc_close($t10p);
check('TLS: 1.2 first, 1.0 only to a computer never seen with 1.2 (then at once), never to one seen with it', [$t10a, $t10b, $t10m],
    [['closed', ['tls', 'ok creds'], 'legacy'], ['closed', ['ok creds']], ['tls', 'tls', '']]);
// ... remembered: changed, or once a day (not written on every connection); TLS 1.0 asked again after a day, TLS 1.2 kept a year
$tlsFile = $tmp . '/data/tls.json';
$tlsAt   = static fn(string $k, string $kind, int $ago) => MuDesk\Store::update('tls', static function (array &$d) use ($k, $kind, $ago): void {
    $d[$k] = [$kind, time() - $ago];
});
touch($tlsFile, $tlsPast = time() - 100);
Transport::tlsWorked("viejo2008:$t10port", 'TLSv1');    // (as it went an instant ago)
clearstatcache();
$tlsUnwritten = filemtime($tlsFile) === $tlsPast;
$tlsAt('a:1', 'legacy', 86400 + 5);
$tlsAt('b:1', 'modern', 364 * 86400);
$tlsAt('c:1', 'modern', 366 * 86400);
// ... by the version agreed, not by what was offered (TLS 1.0 offered after a handshake that failed once, TLS 1.2 agreed)
Transport::tlsWorked('e:1', 'TLSv1.2');
Transport::tlsWorked('f:1', 'TLSv1.1');
$tlsKinds = [$tlsUnwritten, Transport::tlsSeen('a:1'), Transport::tlsSeen('b:1'), Transport::tlsSeen('c:1'), Transport::tlsSeen('d:1'), Transport::tlsSeen('e:1'),
    Transport::tlsSeen('f:1'), decoct(fileperms($tlsFile) & 0777) === '600' || PHP_OS_FAMILY === 'Windows'];
// ... its file damaged (a crash, edited by hand), or one that cannot be written: never a reason for a connection to fail
file_put_contents($tlsFile, '{"cut');
$tlsDamaged = [Transport::tlsSeen('b:1'), count(glob("$tlsFile.damaged-*") ?: [])];
array_map('unlink', glob("$tlsFile.damaged-*") ?: []);
@unlink($tlsFile);
mkdir($tlsFile);                                         // (a folder where the file goes: neither read nor written)
$tlsBlocked = [Transport::tlsSeen('b:1')];
try {
    Transport::tlsWorked('g:1', 'TLSv1.3');
    $tlsBlocked[] = 'no error';
} catch (Throwable $e) {
    $tlsBlocked[] = get_class($e);
}
rmdir($tlsFile);
check('TLS: how it went remembered, written when it changed or once a day, by the version agreed; TLS 1.0 asked again after a day, TLS 1.2 kept a year; its file damaged or unwritable never fails a connection',
    [...$tlsKinds, $tlsDamaged, $tlsBlocked], [true, null, 'modern', null, null, 'modern', 'legacy', true, [null, 1], [null, 'no error']]);

// TLS 1.0 with AES128-SHA, all Windows 7 and Server 2008 R2 offer without updates: the handshake made; and with
// ECDHE-RSA-AES128-SHA, what they choose first with their cipher suites put in another order (its key exchange signed with
// MD5 and SHA-1, as TLS 1.0 signs: OpenSSL 3 takes that only at security level 0)
$t10cert = (static function () use ($cnf): string {
    $k = openssl_pkey_new(['private_key_bits' => 2048, 'config' => $cnf]);
    openssl_x509_export(openssl_csr_sign(openssl_csr_new(['commonName' => 'win7'], $k, ['config' => $cnf]), null, $k, 1, ['config' => $cnf, 'digest_alg' => 'sha1']), $p);
    openssl_pkey_export($k, $kp, null, ['config' => $cnf]);
    file_put_contents($f = sys_get_temp_dir() . '/mudesk-tls10-' . bin2hex(random_bytes(4)) . '.pem', $p . $kp);
    return $f;
})();
$t10hand = static function (string $cipher, bool $legacy = true) use ($t10cert): array|string {
    $t10 = proc_open([PHP_BINARY, '-r', '$ctx = stream_context_create(["ssl" => ["local_cert" => $argv[1], "security_level" => 0, "ciphers" => $argv[2] . ":@SECLEVEL=0",
        "crypto_method" => STREAM_CRYPTO_METHOD_TLSv1_0_SERVER]]); $s = stream_socket_server("tcp://127.0.0.1:0", $en, $es, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
        echo substr(strrchr(stream_socket_get_name($s, false), ":"), 1), "\n"; $c = stream_socket_accept($s, 10); stream_set_blocking($c, true);
        @stream_socket_enable_crypto($c, true, STREAM_CRYPTO_METHOD_TLSv1_0_SERVER); fread($c, 1);', $t10cert, $cipher], [1 => ['pipe', 'w']], $t10p);
    $t10t = new Transport('127.0.0.1', (int)trim((string)fgets($t10p[1])));
    try {
        $t10t->startTls($legacy);
        $r = $t10t->tlsInfo();
    } catch (MuDesk\Rdp\ProtocolException $e) {
        $r = $e->getMessage();
    }
    $t10t->close();
    proc_close($t10);
    return $r;
};
$t10r = [$t10hand('AES128-SHA'), $t10hand('ECDHE-RSA-AES128-SHA'), is_string($t10hand('AES128-SHA', false))];
@unlink($t10cert);
check('TLS 1.0 with AES128-SHA (Windows 7, Server 2008 R2), and with ECDHE-RSA-AES128-SHA; only when offered (legacy)', $t10r,
    [['protocol' => 'TLSv1', 'cipher' => 'AES128-SHA'], ['protocol' => 'TLSv1', 'cipher' => 'ECDHE-RSA-AES128-SHA'], true]);

// a server's answer read when it closed straight after it, both there before the client reads (2003 refusing TLS: its
// X.224 answer, then its close); then the close (before, the close was seen first, "closed", and the answer thrown away)
$byeS = stream_socket_server('tcp://127.0.0.1:0');
$byeT = new Transport('127.0.0.1', (int)substr(strrchr(stream_socket_get_name($byeS, false), ':'), 1));
$byeC = stream_socket_accept($byeS, 5);
fwrite($byeC, "\x03\x00\x00\x13\x0e\xd0\x00\x00\x12\x34\x00\x03\x00\x08\x00\x02\x00\x00\x00");
fclose($byeC);
fclose($byeS);
usleep(200000);
$byeR = [];
foreach ([static fn() => bin2hex($byeT->recvPdu()), static fn() => bin2hex($byeT->recv(1))] as $f) {
    try {
        $byeR[] = $f();
    } catch (MuDesk\Rdp\ProtocolException $e) {
        $byeR[] = $e->key;
    }
}
$byeT->close();
check('transport: a server\'s answer read when it closed straight after it; then its close', $byeR,
    ['030000130ed000001234000300080002000000', 'closed']);

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
foreach (['rdp.php', 'files.php', 'api.php', 'oidc.php', 'health.php'] as $f) {
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
// (the tests rewrite config.php between requests: never an older copy from OPcache, on by itself in PHP's web server since 8.5)
\function_exists('opcache_invalidate') && \opcache_invalidate(__DIR__ . '/../config.php', true);
Config::load(__DIR__ . '/../config.php');
PHP;
file_put_contents("$app/src/bootstrap.php", str_replace('SRC', var_export(realpath(__DIR__ . '/../src'), true), $stub));
// (a computer never seen trusted at once, as before 1.2, but where its confirmation is the point: see $askCerts)
$askCerts = static function (bool $on) use ($app, $tmp): void {
    file_put_contents("$app/config.php", '<?php return ["data_dir" => ' . var_export("$tmp/data", true) . ', "confirm_new_certificates" => ' . var_export($on, true) . '];');
};
$askCerts(false);
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
// its body read only once the request is the session's, and never past its largest: rdp.php run with a 4 MB memory limit
// is sent 6 MB (no token, a wrong guest link, the right token), which ended in a fatal error once read whole
file_put_contents("$app/public/send-4m.php", '<?php ini_set("memory_limit", "4M"); require __DIR__ . "/rdp.php";');
$sendBig = static function (string $header, int $size) use ($wport, $tsid): int {
    $s = fsockopen('127.0.0.1', $wport, $en, $es, 5);
    fwrite($s, "POST /send-4m.php?a=send&sid=$tsid HTTP/1.0\r\nHost: 127.0.0.1\r\n{$header}Content-Type: application/octet-stream\r\n"
        . "Content-Length: $size\r\n\r\n" . pack('V', 0) . "\x06" . str_repeat("\0", $size - 5));
    $r = (string)stream_get_contents($s);
    fclose($s);
    return (int)substr($r, 9, 3);
};
check('input endpoint: its body read only for the session (token or guest link), and never past 4 MB', [$sendBig('', 6 << 20),
    $sendBig("X-MuDesk-Share: wrong\r\n", 6 << 20), $sendBig("X-MuDesk-Token: secret-token\r\n", 6 << 20),
    $sendBig("X-MuDesk-Token: secret-token\r\n", 5)], [404, 404, 404, 204]);

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
// Wake-on-LAN: a computer that does not answer is sent the magic packet (to the address given) and waited for; the browser
// is told, it is audited, and the connection goes on once the computer answers. Here a helper plays the computer: off
// (its port closed) until the packet arrives, then on after 3 s, closing each connection at once
$wol = proc_open([PHP_BINARY, '-r', '$u = stream_socket_server("udp://127.0.0.1:0", $e, $s, STREAM_SERVER_BIND); $t = stream_socket_server("tcp://127.0.0.1:0");'
    . ' $tp = (int)substr(strrchr(stream_socket_get_name($t, false), ":"), 1); fclose($t); echo substr(strrchr(stream_socket_get_name($u, false), ":"), 1), " $tp\n";'
    . ' $r = [$u]; $w = $x = null; if (!stream_select($r, $w, $x, 20)) { echo "none 0\n"; exit; }'   // (no packet: the check fails, never waits)
    . ' $p = stream_socket_recvfrom($u, 200); echo bin2hex(substr($p, 0, 12)), " ", strlen($p), "\n"; sleep(3); $t = stream_socket_server("tcp://127.0.0.1:$tp");'
    . ' for ($i = 0; $i < 3 && ($c = @stream_socket_accept($t, 15)); $i++) { echo "in\n"; fclose($c); }'], [1 => ['pipe', 'w']], $wolPipes);
[$wudp, $wtcp] = array_map('intval', explode(' ', trim((string)fgets($wolPipes[1]))));
$wc = MuDesk\Connections::save($wu['id'], ['host' => '127.0.0.1', 'port' => $wtcp, 'security' => 'tls', 'clipboard' => false, 'drive' => false, 'printer' => false,
    'audio' => false, 'wol' => 'aa-bb-cc-dd-ee-01', 'wol_ip' => "127.0.0.1:$wudp"], null);
$t0  = microtime(true);
$ws  = $stream($wc['id'], bin2hex(random_bytes(16)));
$got = trim((string)fgets($wolPipes[1]));
$ins = 0;
while (($l = fgets($wolPipes[1])) !== false && $ins < 3) {
    $ins += trim($l) === 'in' ? 1 : 0;
    if ($ins >= 2) {
        break;
    }
}
[, $wbody] = $wres($ws);
$wtook = microtime(true) - $t0;
proc_terminate($wol);
proc_close($wol);
$wstat = [];
for ($o = 0; $o + 5 <= strlen($wbody); $o += 5 + $len) {
    ['t' => $type, 'n' => $len] = unpack('Ct/Vn', $wbody, $o);
    if ($type === MuDesk\Rdp\Client::F_STATUS) {
        $wstat[] = json_decode(substr($wbody, $o + 5, $len), true);
    }
}
$wolLog = MuDesk\Audit::search(['event' => 'wol_sent', 'user' => 'webuser'])['rows'][0] ?? [];
check('Wake-on-LAN: a computer that does not answer is sent the packet, waited for, then connected; the browser told; audited', [
    $wc['wol'], $got, in_array(['waking' => true], $wstat, true), $ins >= 2, $wolLog['mac'] ?? null, $wolLog['to'] ?? null, $wolLog['ok'] ?? null, $wtook < 20],
    ['AA:BB:CC:DD:EE:01', 'ffffffffffffaabbccddee01 102', true, true, 'AA:BB:CC:DD:EE:01', "127.0.0.1:$wudp", true, true]);
MuDesk\Connections::delete($wu['id'], $wc['id']);
// a gateway that is not an allowed target said as such (before, as the computer: "the administrator does not allow this
// computer"); a stored gateway that is not one (edited by hand) ends the session (before, it went straight to the computer)
$endsOf = static function (string $body): array {
    $ends = [];
    for ($o = 0; $o + 5 <= strlen($body); $o += 5 + $len) {
        ['t' => $type, 'n' => $len] = unpack('Ct/Vn', $body, $o);
        if ($type === MuDesk\Rdp\Client::F_STATUS && isset(($j = json_decode(substr($body, $o + 5, $len), true))['end'])) {
            $ends[] = $j['end'];
        }
    }
    return $ends;
};
$gwNo  = MuDesk\Connections::save($wu['id'], ['host' => '127.0.0.1', 'port' => 9, 'gw' => '10.255.255.1', 'security' => 'tls', 'clipboard' => false, 'drive' => false, 'printer' => false, 'audio' => false], null);
$gwBad = MuDesk\Connections::save($wu['id'], ['host' => '127.0.0.1', 'port' => 9, 'security' => 'tls', 'clipboard' => false, 'drive' => false, 'printer' => false, 'audio' => false], null);
MuDesk\Store::update('connections/' . $wu['id'], static function (array &$d) use ($gwBad): void {
    $d[$gwBad['id']]['gw'] = 'no es una puerta';
});
[, $gwNoBody]  = $wres($stream($gwNo['id'], bin2hex(random_bytes(16))));
[, $gwBadBody] = $wres($stream($gwBad['id'], bin2hex(random_bytes(16))));
MuDesk\Connections::delete($wu['id'], $gwNo['id']);
MuDesk\Connections::delete($wu['id'], $gwBad['id']);
check('RD Gateway: one not allowed said as the gateway; a stored one that is not a gateway never goes straight to the computer', [$endsOf($gwNoBody), $endsOf($gwBadBody)],
    [['gateway_not_allowed'], ['gateway']]);
// outside the user's sign-in hours a remote desktop is refused before anything is opened (a session already signed in)
MuDesk\Users::update($wu['id'], static fn(array &$x) => $x['hours'] = $notToday);
[$hst, $hbody] = $wres($stream($wconn(9), bin2hex(random_bytes(16))));
MuDesk\Users::update($wu['id'], static fn(array &$x) => $x['hours'] = null);
check('sign-in hours: a remote desktop outside them refused before connecting, and audited', [$hst, json_decode($hbody, true)['error'] ?? $hbody,
    in_array('outside_hours', array_column(MuDesk\Audit::search(['event' => 'rdp_denied', 'user' => 'webuser'])['rows'], 'reason'), true)],
    [403, 'outside_hours', true]);
$host   = static function (string $then) use (&$hpipes) {                  // a host on this computer: [process, port]
    $p = proc_open([PHP_BINARY, '-r', '$s = stream_socket_server("tcp://127.0.0.1:0"); echo stream_socket_get_name($s, false), "\n";'
        . ' $c = stream_socket_accept($s, 30); echo "in\n";' . $then], [1 => ['pipe', 'w']], $hpipes);
    $a = trim((string)fgets($hpipes[1]));
    return [$p, (int)substr($a, (int)strrpos($a, ':') + 1)];
};
// Hyper-V console over the gateway: the host is sent the Preconnection PDU with the machine id first and then, without any
// X.224 request, the TLS handshake (a ClientHello record: 0x16, 0x03)
$vmId = '0F1E2D3C-4B5A-6978-8796-A5B4C3D2E1F0';
[$hp, $hport] = $host(' $d = ""; while (strlen($d) < 95 && ($x = fread($c, 95 - strlen($d))) !== false && $x !== "") { $d .= $x; } echo bin2hex($d), "\n"; fclose($c);');
$vc = MuDesk\Connections::save($wu['id'], ['host' => '127.0.0.1', 'port' => $hport, 'vm' => $vmId, 'clipboard' => false, 'drive' => false, 'printer' => false, 'audio' => false], null);
$vs = $stream($vc['id'], bin2hex(random_bytes(16)));
fgets($hpipes[1]);
$vmSeen = hex2bin(trim((string)fgets($hpipes[1])));
$wres($vs);
proc_close($hp);
check('Hyper-V: the gateway sends the Preconnection PDU with the machine id, then TLS at once (no X.224 negotiation)', [
    substr($vmSeen, 0, 92) === MuDesk\Rdp\Client::preconnection($vmId), bin2hex(substr($vmSeen, 92, 2))], [true, '1603']);
MuDesk\Connections::delete($wu['id'], $vc['id']);

// Remote Desktop Gateway (MS-TSGU over WebSocket) against a gateway played by tests/fixtures/rdg_server.php: signed in with
// NTLM and the channel binding of its certificate (computed there on its own), the tunnel and the channel to the computer
// opened, the bytes relayed both ways and its keep-alive answered; a wrong password and a computer the gateway refuses said
// apart; a 401 with a chunked body; a gateway that offers only Negotiate; an IPv6 gateway in brackets in the Host header
// (before, a bare address: refused); a quarantined client given its tunnel all the same; the channel's last bytes and its
// end in one write (the bytes given to the client, then the end, as a remote computer's: before, the tunnel stopped
// first); a channel slow to open (9 s, over the 8 s it was waited for) taken, the browser checked meanwhile
[$echoP, $echoPort] = (static function (): array {
    $p = proc_open([PHP_BINARY, '-r', '$s = stream_socket_server("tcp://127.0.0.1:0"); echo substr(strrchr(stream_socket_get_name($s, false), ":"), 1), "\n";'
        . ' $c = stream_socket_accept($s, 30); while (($d = fread($c, 8192)) !== false && $d !== "") { fwrite($c, strtoupper($d)); }'], [1 => ['pipe', 'w']], $pp);
    return [$p, (int)trim((string)fgets($pp[1]))];
})();
$rdg = proc_open([PHP_BINARY, __DIR__ . '/fixtures/rdg_server.php', 'ana', 'TEST', 'Clave-Gw-1', $cnf, (string)$echoPort], [1 => ['pipe', 'w'], 2 => ['file', $nul, 'w']], $rdgPipes);
$rdgPort  = (int)trim((string)fgets($rdgPipes[1]));
$rdgLines = static function () use (&$rdgPipes): array {          // one connection's lines, up to its last
    $out = [];
    while (($l = fgets($rdgPipes[1])) !== false) {
        $out[] = $l = trim($l);
        if (preg_match('/^(relayed|auth bad|bad |tls)/', $l)) {
            break;
        }
    }
    return $out;
};
$gwOpen = static function (string $pw, string $target, string $gwHost = '127.0.0.1', string $client = 'MuDesk') use ($rdgPort): string|MuDesk\Rdp\Gateway {
    try {
        return new MuDesk\Rdp\Gateway($gwHost, '127.0.0.1', $rdgPort, 'ana', 'TEST', $pw, $target, 3389, $client);
    } catch (MuDesk\Rdp\ProtocolException $e) {
        return $e->key;
    }
};
$untilEnd = static function (string|MuDesk\Rdp\Gateway $g): array|string {   // what the client's end reads, whether it ends, pump()
    if (is_string($g)) {
        return $g;
    }
    $got = '';
    for ($t = microtime(true) + 3; microtime(true) < $t && !feof($g->inner());) {
        $g->pump();
        usleep(10000);
        $got .= (string)@fread($g->inner(), 100);
    }
    $r = [$got, feof($g->inner()), $g->pump()];
    $g->close();
    return $r;
};
$g = $gwOpen('Clave-Gw-1', 'pc.test');
$echoed = '';
if ($g instanceof MuDesk\Rdp\Gateway) {
    fwrite($g->inner(), 'hola por el tunel');
    for ($i = 0; $i < 200 && strlen($echoed) < 17; $i++) {
        $g->pump();
        usleep(10000);
        $echoed .= (string)@fread($g->inner(), 100);
    }
    for ($i = 0; $i < 10; $i++) {                                    // (the keep-alive's answer out)
        $g->pump();
        usleep(10000);
    }
    (new ReflectionProperty(MuDesk\Rdp\Gateway::class, 'lastSent'))->setValue($g, microtime(true) - 61);   // a quiet minute:
    for ($i = 0; $i < 10; $i++) {                                    // a keep-alive of its own
        $g->pump();
        usleep(10000);
    }
    $g->close();
}
$gwSeen   = [$rdgLines()];
$badPw    = $gwOpen('Otra-clave', 'pc.test');
$gwSeen[] = $rdgLines();
$denied   = $gwOpen('Clave-Gw-1', 'denied.test');
$gwSeen[] = $rdgLines();
$negEnd   = $untilEnd($gwOpen('Clave-Gw-1', 'bye.test', 'negotiate.test'));
$gwSeen[] = $rdgLines();
$quarEnd  = $untilEnd($gwOpen('Clave-Gw-1', 'bye.test', '::1', 'QUARANTINE'));
$gwSeen[] = $rdgLines();
$waits    = 0;
$waiting0 = MuDesk\Rdp\Transport::$waiting;
MuDesk\Rdp\Transport::$waiting = static function () use (&$waits): void {
    $waits++;
};
$slow = $gwOpen('Clave-Gw-1', 'slow.test', 'mfa.test');
MuDesk\Rdp\Transport::$waiting = $waiting0;
$slowOk = $slow instanceof MuDesk\Rdp\Gateway;
$slowOk && $slow->close();
$gwSeen[] = $rdgLines();
$cookieEnd = $untilEnd($gwOpen('Clave-Gw-1', 'bye.test', 'cookie.test'));
$gwSeen2   = [$rdgLines()];
$napEnd    = $gwOpen('Clave-Gw-1', 'pc.test', 'nocap.test');
$gwSeen2[] = $rdgLines();
$capsEnd   = $gwOpen('Clave-Gw-1', 'pc.test', 'caps.test');
$gwSeen2[] = $rdgLines();
$pol       = $gwOpen('Clave-Gw-1', 'bye.test', '127.0.0.1', 'POLICY');
$polDenied = $pol instanceof MuDesk\Rdp\Gateway ? $pol->denied : $pol;
$polEnd    = $untilEnd($pol);
$gwSeen2[] = $rdgLines();
$authRefused = $gwOpen('Clave-Gw-1', 'pc.test', '127.0.0.1', 'REFUSED');
$authRefused instanceof MuDesk\Rdp\Gateway && $authRefused->close();
$authRefusedSeen = $rdgLines();
$certOther = $gwOpen('Clave-Gw-1', 'pc.test', 'legacycert.test');
$certOther instanceof MuDesk\Rdp\Gateway && $certOther->close();
$certOtherSeen = $rdgLines();
// the channel's close answered late, on the WebSocket and on the older transport's two connections (kept open after it: the
// client ends them once it has its answer, 250 ms after it asked, not at its second's end)
$lateClose = [];
foreach (['127.0.0.1', 'legacy.test'] as $gwHost) {
    $g    = $gwOpen('Clave-Gw-1', 'lateclose.test', $gwHost);
    $took = null;
    if ($g instanceof MuDesk\Rdp\Gateway) {
        for ($i = 0; $i < 10; $i++) {                                    // (the keep-alive's answer out)
            $g->pump();
            usleep(10000);
        }
        $t = microtime(true);
        $g->close();
        $took = microtime(true) - $t;
    }
    $lateClose[] = [$rdgLines(), $took !== null && $took < 0.75];
}
proc_terminate($echoP);
proc_close($echoP);
proc_terminate($rdg);
proc_close($rdg);
check('RD Gateway: signed in with the channel binding, tunnel and channel, bytes both ways, keep-alive answered and sent; refusals said apart; Negotiate, chunked, IPv6, quarantine; its last bytes, then its end; a slow tunnel and channel waited for', [
    $echoed, $badPw, $denied, $negEnd, $quarEnd, [$slowOk, $waits >= 5], $gwSeen],
    ['HOLA POR EL TUNEL', 'gateway_auth', 'gateway_denied', ['adios', true, false], ['adios', true, false], [true, true],
     [['auth ok bindings ok', 'channel pc.test:3389', 'keepalive answered', 'keepalive', 'relayed 17 17'], ['auth bad'],
      ['auth ok bindings ok', 'denied denied.test', 'relayed 0 0'],
      ['auth ok bindings ok negotiate', 'channel bye.test:3389', 'keepalive answered', 'close answered', 'relayed 0 5'],
      ['auth ok bindings ok', 'quarantined', 'channel bye.test:3389', 'keepalive answered', 'close answered', 'relayed 0 5'],
      ['auth ok bindings ok', 'channel slow.test:3389', 'relayed 0 0']]]);
// its cookies sent back with the second round (a balancer in front of several gateways keeps the sign-in on one; before, none
// were); a gateway that wants a capability this client lacks said as unsupported (before, as a failure); a user
// its policies refuse at the tunnel said as not allowed; its policy of devices read from the tunnel's authorization
check('RD Gateway: its cookies sent back; one that wants a capability this client lacks said as unsupported; a user its policies refuse said as not allowed; its policy of devices read', [
    $cookieEnd, $napEnd, $capsEnd, $polDenied, $polEnd, $gwSeen2],
    [['adios', true, false], 'gateway_denied', 'gateway_unsupported', ['drive', 'clipboard'], ['adios', true, false],
     [['cookie ok', 'auth ok bindings ok', 'channel bye.test:3389', 'keepalive answered', 'close answered', 'relayed 0 5'],
      ['auth ok bindings ok', 'tunnel refused', 'relayed 0 0'], ['auth ok bindings ok', 'tunnel refused', 'relayed 0 0'],
      ['auth ok bindings ok', 'policy', 'channel bye.test:3389', 'keepalive answered', 'close answered', 'relayed 0 5']]]);
// a tunnel authorization the gateway refuses: no channel asked for; the older transport's second connection with another
// certificate than the first's (a man in the middle on it): refused
// ... the end: the channel closed, its answer waited for (within a second) before the connections are (before, they were at
// once: the older transport's gateway could take the OUT one's end before the close the IN one carried, and a connection
// closed with something unread is reset, which can lose what came last on it)
check('RD Gateway: the channel\'s close answered before its connections are closed (late too: ended at its answer), on the WebSocket and the older transport', $lateClose, [
    [['auth ok bindings ok', 'channel lateclose.test:3389', 'keepalive answered', 'client waited', 'relayed 0 0'], true],
    [['auth ok bindings ok', 'auth ok bindings ok', 'legacy in', 'channel lateclose.test:3389', 'keepalive answered', 'client waited', 'relayed 0 0'], true]]);
check('RD Gateway: a tunnel authorization refused; the older transport\'s second connection with another certificate refused', [
    is_string($authRefused) ? $authRefused : 'open', $authRefusedSeen, is_string($certOther) ? $certOther : 'open', $certOtherSeen],
    ['gateway_denied', ['auth ok bindings ok', 'refused', 'relayed 0 0'], 'gateway', ['auth ok bindings ok', 'bad in']]);

// the gateway of the RPC transport (Windows Server 2008 and 2008 R2: MS-TSGU over RPC over HTTP), played by
// tests/fixtures/rpc_gateway.php: the HTTP transport refused (404), both channels signed in with NTLM and the channel
// binding, the bind with NTLM at packet integrity (every request's signature checked there), tunnel, authorization and
// channel; 300 KB each way within its flow control; its consent message (accepted, and refused) and a service message; its
// device policy; a user and a computer it refuses (their codes); the OUT channel made again in the middle of the stream
// (OUT_R2: before, the old channel's end was taken as the tunnel's); Windows Server 2008 (no messages); a wrong password;
// the IN channel made again (IN_R1, IN_R2)
$rpcEcho = proc_open([PHP_BINARY, '-r', '$s = stream_socket_server("tcp://127.0.0.1:0"); echo substr(strrchr(stream_socket_get_name($s, false), ":"), 1), "\n";'
    . ' while ($c = @stream_socket_accept($s, 30)) { while (($d = fread($c, 65536)) !== false && $d !== "") { for ($w = 0; $w < strlen($d); $w += $n) { if (!($n = @fwrite($c, strtoupper(substr($d, $w))))) { break 2; } } } fclose($c); }'],
    [1 => ['pipe', 'w']], $rpcEchoPipes);
$rpcEchoPort = (int)trim((string)fgets($rpcEchoPipes[1]));
$rpcRun = static function (string $mode, int $bytes = 20000, string $pw = 'Clave-Gw-1', bool $agree = true, ?callable $before = null) use ($cnf, $nul, $rpcEchoPort): array {
    $p     = proc_open([PHP_BINARY, __DIR__ . '/fixtures/rpc_gateway.php', 'ana', 'TEST', 'Clave-Gw-1', $cnf, (string)$rpcEchoPort, $mode], [1 => ['pipe', 'w'], 2 => ['file', $nul, 'w']], $pp);
    $port  = (int)trim((string)fgets($pp[1]));
    $asked = [];
    try {
        $g = new MuDesk\Rdp\Gateway('127.0.0.1', '127.0.0.1', $port, 'ana', 'TEST', $pw, 'pc.test', 3389, 'MuDesk', null,
            static function (string $text) use (&$asked, $agree): bool {
                $asked[] = $text;
                return $agree;
            });
        $send = substr(bin2hex(random_bytes(intdiv($bytes, 2) + 1)), 0, $bytes);
        $got  = '';
        stream_set_blocking($g->inner(), false);
        for ($sent = 0, $end = microtime(true) + 20; strlen($got) < $bytes && microtime(true) < $end;) {
            $sent < $bytes && $sent += (int)@fwrite($g->inner(), substr($send, $sent, 16384));
            $g->pump();
            $got .= (string)@fread($g->inner(), 65536);
            $g->wait(0.01);
        }
        for ($i = 0; $i < 20; $i++) {                                    // (a service message after the channel)
            $g->pump();
            $g->wait(0.01);
        }
        $r = ["$g->transport/$g->auth", $got === strtoupper($send), $g->denied, $asked, $g->messages];
        $before && $before($g);                                          // (something set before the end)
        $g->close();
    } catch (MuDesk\Rdp\ProtocolException $e) {
        $r = [$e->key, preg_match('~0x[0-9a-f]{8}~', $e->getMessage(), $m) ? $m[0] : '', $asked];
        isset($g) && $g->close();                                        // (refused once open: its channel closed)
    }
    $lines = [];
    while (($l = fgets($pp[1])) !== false) {
        $lines[] = $l = trim($l);
        if (preg_match('/^(relayed|auth bad|bad signature)/', $l)) {
            break;
        }
    }
    proc_terminate($p);
    proc_close($p);
    return [$r, $lines];
};
$rpcSeen = [];
foreach ([['', 300000], ['consent'], ['policy'], ['nap'], ['rapdenied'], ['recycle', 300000], ['old2008'], ['consent', 20000, 'Clave-Gw-1', false], ['', 20000, 'Otra-clave']] as $a) {
    $rpcSeen[] = $rpcRun(...$a);
}
$rpcNapFull = $rpcRun('napfull');
// the end: the channel's close answered late (the client waits for it, its channels open till then), after more than the
// client's window (acknowledged meanwhile), answered by asking for a new OUT channel, or sent as the IN channel is half used
// (no channel made again while closing)
$rpcLateClose = $rpcRun('lateclose');
$rpcFullClose = $rpcRun('closefull');
$rpcCloseNew  = $rpcRun('closerecycle');
$rpcCloseIn   = $rpcRun('', 20000, 'Clave-Gw-1', true, static function (MuDesk\Rdp\Gateway $g): void {
    (new ReflectionProperty($g, 'inSent'))->setValue($g, (new ReflectionProperty(MuDesk\Rdp\Gateway::class, 'inLifetime'))->getValue() >> 1);
});
$rpcCert = $rpcRun('recyclecert', 300000);
$rpcLate = $rpcRun('recyclelate', 300000);
// (the IN channel's length lowered to the least the specification allows, 128 KB, all the gateway takes in these modes: a
// client that never makes it again declares 1 GiB, and goes past it)
$rpcLife = property_exists(MuDesk\Rdp\Gateway::class, 'inLifetime') ? new ReflectionProperty(MuDesk\Rdp\Gateway::class, 'inLifetime') : null;
$rpcLife?->setValue(null, 131072);
try {
    $rpcIn = [$rpcRun('inrecycle', 300000), $rpcRun('inrecycle1', 300000)];
} finally {
    $rpcLife?->setValue(null, 0x40000000);
}
proc_terminate($rpcEcho);
proc_close($rpcEcho);
$rpcOpen = ['probe 404', 'auth ok in bindings ok', 'auth ok out bindings ok', 'rpc auth ok'];
$rpcChan = ['authorize MuDesk', 'message call', 'channel pc.test:3389', 'pipe'];
$rpcText = "Uso solo autorizado.\nTodo queda registrado.";
check('RD Gateway, RPC transport: both channels and the bind signed in, tunnel, authorization, channel, 300 KB each way; consent and service messages; device policy; a user (also in a whole answer) and a computer refused, their codes',
    [...array_slice($rpcSeen, 0, 5), $rpcNapFull], [
    [['rpc/ntlm', true, [], [], []], [...$rpcOpen, 'tunnel caps e', ...$rpcChan, 'close channel', 'relayed 300000 300000']],
    [['rpc/ntlm', true, [], [$rpcText], ['Mantenimiento a las 22:00']], [...$rpcOpen, 'tunnel caps e', ...$rpcChan, 'message call', 'close channel', 'relayed 20000 20000']],
    [['rpc/ntlm', true, ['drive', 'clipboard'], [], []], [...$rpcOpen, 'tunnel caps e', ...$rpcChan, 'close channel', 'relayed 20000 20000']],
    [['gateway_denied', '0x800759db', []], [...$rpcOpen, 'tunnel caps e', 'relayed 0 0']],
    [['gateway_denied', '0x800759da', []], [...$rpcOpen, 'tunnel caps e', 'authorize MuDesk', 'message call', 'channel pc.test:3389', 'relayed 0 0']],
    [['gateway_denied', '0x800759db', []], [...$rpcOpen, 'tunnel caps e', 'relayed 0 0']]]);
check('RD Gateway, RPC transport: its OUT channel made again mid-stream (the data whole); Windows Server 2008 without messages; a consent refused; a wrong password', array_slice($rpcSeen, 5), [
    [['rpc/ntlm', true, [], [], []], [...$rpcOpen, 'tunnel caps e', ...$rpcChan, 'auth ok out bindings ok', 'recycled', 'close channel', 'relayed 300000 300000']],
    [['rpc/ntlm', true, [], [], []], [...$rpcOpen, 'tunnel caps 0', 'authorize MuDesk', 'channel pc.test:3389', 'pipe', 'close channel', 'relayed 20000 20000']],
    [['gateway_consent', '', [$rpcText]], [...$rpcOpen, 'tunnel caps e', 'relayed 0 0']],
    [['gateway_auth', '', []], ['probe 404', 'auth bad']]]);
// ... made again as the client's first acknowledgement waits to go (on the IN channel): it goes there, the new OUT channel
// signed in (before, it went to the new OUT channel ahead of its request: refused, and the session ended)
check('RD Gateway, RPC transport: its OUT channel made again while an acknowledgement waits for the IN channel', $rpcLate,
    [['rpc/ntlm', true, [], [], []], [...$rpcOpen, 'tunnel caps e', ...$rpcChan, 'auth ok out bindings ok', 'recycled', 'close channel', 'relayed 300000 300000']]);
// ... its IN channel made again, and again (128 KB each, 300 KB up), as the same gateway takes the new one (IN_R2) and as
// another of a farm would (IN_R1, its OUT channel made again meanwhile too): the data whole both ways, nothing past an IN
// channel's length or after its last PDU, the cookies right (before, it was never made again: past its length, 1 GiB, the
// gateway read the rest as a new request, and the session ended)
$rpcInSum = static fn(array $run): array => [$run[0], [...array_slice($run[1], 0, 3), ...array_values(array_diff(array_slice($run[1], 3),
    ['auth ok in bindings ok', 'auth ok out bindings ok', 'in recycled', 'recycled']))], count(array_keys($run[1], 'in recycled')) >= 2,
    count(array_keys($run[1], 'recycled'))];
check('RD Gateway, RPC transport: its IN channel made again (IN_R2; IN_R1 with the OUT channel made again too), 300 KB each way', array_map($rpcInSum, $rpcIn), [
    [['rpc/ntlm', true, [], [], []], [...$rpcOpen, 'tunnel caps e', ...$rpcChan, 'close channel', 'relayed 300000 300000'], true, 0],
    [['rpc/ntlm', true, [], [], []], [...$rpcOpen, 'tunnel caps e', ...$rpcChan, 'close channel', 'relayed 300000 300000'], true, 1]]);
// ... the end: the channel closed, its answer waited for before both channels are (before, they were at once, and the
// gateway could take the OUT channel's end before the close the IN channel carried: lost, at times); none made again
// meanwhile, the gateway's answer or not within a second
check('RD Gateway, RPC transport: the channel\'s close answered before its channels are closed (late too, after a full window); none made again while closing',
    [$rpcLateClose, $rpcFullClose, $rpcCloseNew, $rpcCloseIn], [
    [['rpc/ntlm', true, [], [], []], [...$rpcOpen, 'tunnel caps e', ...$rpcChan, 'close channel', 'close answered', 'relayed 20000 20000']],
    [['rpc/ntlm', true, [], [], []], [...$rpcOpen, 'tunnel caps e', ...$rpcChan, 'close channel', 'close answered', 'relayed 20000 20000']],
    [['rpc/ntlm', true, [], [], []], [...$rpcOpen, 'tunnel caps e', ...$rpcChan, 'close channel', 'relayed 20000 20000']],
    [['rpc/ntlm', true, [], [], []], [...$rpcOpen, 'tunnel caps e', ...$rpcChan, 'close channel', 'relayed 20000 20000']]]);
// ... its OUT channel made again at a gateway with another certificate (a man in the middle on the new one): refused
check('RD Gateway, RPC transport: its OUT channel made again with another certificate refused',
    [$rpcCert[0], preg_replace('~^relayed .*~', 'relayed', $rpcCert[1])], [['gateway', '', []], [...$rpcOpen, 'tunnel caps e', ...$rpcChan, 'close channel', 'relayed']]);
// what no call waits for, from a gateway of the RPC transport: fragments of calls never made kept for none (before, each
// call's were kept up to 1 MB, for any number of calls: the memory gone); a SendToServer refused, the end; a new OUT
// channel whose HTTP answer never ends, refused past 64 KB (before, it kept growing)
$rpcBare = (new ReflectionClass(MuDesk\Rdp\Gateway::class))->newInstanceWithoutConstructor();
$rpcProp = static fn(string $name): ReflectionProperty => new ReflectionProperty(MuDesk\Rdp\Gateway::class, $name);
$rpcFeed = static function (string $in) use ($rpcBare, $rpcProp): string {
    $rpcProp('in')->setValue($rpcBare, $in);
    try {
        (new ReflectionMethod(MuDesk\Rdp\Gateway::class, 'rpcIncoming'))->invoke($rpcBare);
        return 'read';
    } catch (MuDesk\Rdp\ProtocolException $e) {
        return $e->key;
    }
};
$rpcFrag = static fn(int $call, int $flags, string $stub): string => pack('CCCCVvvV', 5, 0, 0x02, $flags, 0x10, 24 + strlen($stub), 0, $call) . pack('VvCC', strlen($stub), 0, 0, 0) . $stub;
$rpcProp('rpc')->setValue($rpcBare, true);
$rpcProp('rpcState')->setValue($rpcBare, 2);
$rpcProp('pipeCall')->setValue($rpcBare, 5);
$rpcMany = '';
for ($i = 10; $i < 3010; $i++) {
    $rpcMany .= $rpcFrag($i, 0x01, str_repeat('m', 1000));
}
$rpcStray = [$rpcFeed($rpcMany), count($rpcProp('rpcParts')->getValue($rpcBare)), $rpcProp('closed')->getValue($rpcBare)];
$rpcStray[] = [$rpcFeed($rpcFrag(7, 0x03, pack('V', 0x800759F8))), $rpcProp('closed')->getValue($rpcBare)];
$rpcProp('rpcHeaders')->setValue($rpcBare, true);
$rpcStray[] = [$rpcFeed(str_repeat('x', 40000)), $rpcFeed(str_repeat('x', 70000))];
check('RD Gateway, RPC transport: fragments of calls never made kept for none; a refused SendToServer, the end; a new OUT channel\'s endless HTTP answer refused', $rpcStray,
    ['read', 0, false, ['read', true], ['read', 'gateway']]);
// ... and the answer of a call waited for kept up to 1 MB: its fragments past that refused (a gateway that never sends the
// last one took the memory)
$rpcBig = (new ReflectionClass(MuDesk\Rdp\Gateway::class))->newInstanceWithoutConstructor();
foreach (['rpc' => true, 'rpcState' => 2, 'pipeCall' => 5, 'rpcWaiting' => [9 => true]] as $rpcBigK => $rpcBigV) {
    $rpcProp($rpcBigK)->setValue($rpcBig, $rpcBigV);
}
$rpcBigIn = $rpcFrag(9, 0x01, str_repeat('g', 4000));
for ($i = 0; $i < 270; $i++) {
    $rpcBigIn .= $rpcFrag(9, 0x00, str_repeat('g', 4000));
}
$rpcProp('in')->setValue($rpcBig, $rpcBigIn);
try {
    (new ReflectionMethod(MuDesk\Rdp\Gateway::class, 'rpcIncoming'))->invoke($rpcBig);
    $rpcBigR = 'read';
} catch (MuDesk\Rdp\ProtocolException $e) {
    $rpcBigR = $e->getMessage();
}
check('RD Gateway, RPC transport: the answer of a call refused past 1 MB', [$rpcBigR, $rpcProp('rpcDone')->getValue($rpcBig)], ['Gateway: RPC answer too large', []]);
// the tunnel call answered with a fault, or with too few bytes for a status: a gateway error, without a notice (PHP 8.5: the
// missing status looked up as a null array key, deprecated)
$rpcNotes = [];
set_error_handler(static function (int $no, string $msg) use (&$rpcNotes): bool {
    $rpcNotes[] = $msg;
    return true;
});
$rpcRefused = [];
foreach ([[null, 0x1C010003], ['abc', null]] as [$rpcAnswer, $rpcFault]) {
    try {
        (new ReflectionMethod(MuDesk\Rdp\Gateway::class, 'rpcTunnel'))->invoke($rpcBare, $rpcAnswer, $rpcFault, null);
        $rpcRefused[] = 'taken';
    } catch (MuDesk\Rdp\ProtocolException $e) {
        $rpcRefused[] = [$e->key, $e->getMessage()];
    }
}
restore_error_handler();
check('RD Gateway, RPC transport: a tunnel call faulted or cut short, a gateway error without notices', [$rpcRefused, $rpcNotes],
    [[['gateway', 'Gateway: tunnel refused (0x1c010003)'], ['gateway', 'Gateway: tunnel refused (0x00000000)']], []]);
// ... bind answers of calls no one waits for not kept (before, any number of them: the memory gone); a proxy that ends its
// chunked OUT stream right after OUT_R2/B3: the new OUT channel taken, the tunnel not closed (before, it was); a message
// with a long run of nulls after it: its text (before, the pattern cutting them gave up, and the message was left out)
$rpcProp('rpcHeaders')->setValue($rpcBare, false);
$rpcAcks = '';
for ($i = 100; $i < 600; $i++) {
    $rpcAcks .= pack('CCCCVvvV', 5, 0, 0x0C, 0x03, 0x10, 1016, 0, $i) . str_repeat('b', 1000);
}
$rpcMore = [$rpcFeed($rpcAcks), count($rpcProp('rpcDone')->getValue($rpcBare))];
$rpcPair = static fn(): array => stream_socket_pair(PHP_OS_FAMILY === 'Windows' ? STREAM_PF_INET : STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
[[$rpcOldS, $rpcOldP], [$rpcNewS, $rpcNewP]] = [$rpcPair(), $rpcPair()];
$rpcB3  = (new ReflectionClass(MuDesk\Rdp\Gateway::class))->newInstanceWithoutConstructor();
$rpcB3p = pack('CCCCVvvV', 5, 0, 0x14, 0x03, 0x10, 24, 0, 0) . pack('vv', 0x0020, 1) . pack('V', 10);   // OUT_R2/B3
foreach (['rpc' => true, 'rpcState' => 2, 'sock' => $rpcOldS, 'succ' => $rpcNewS, 'recycle' => 2, 'outChunked' => true, 'chunkLeft' => -1,
          'in' => sprintf("%X\r\n", strlen($rpcB3p)) . $rpcB3p . "\r\n0\r\n\r\n"] as $rpcK => $rpcV) {
    $rpcProp($rpcK)->setValue($rpcB3, $rpcV);
}
(new ReflectionMethod(MuDesk\Rdp\Gateway::class, 'rpcIncoming'))->invoke($rpcB3);
$rpcMore[] = [$rpcProp('sock')->getValue($rpcB3) === $rpcNewS, $rpcProp('closed')->getValue($rpcB3)];
foreach ([$rpcOldP, $rpcNewS, $rpcNewP] as $rpcS) {
    @fclose($rpcS);
}
$rpcMore[] = (new ReflectionMethod(MuDesk\Rdp\Gateway::class, 'text'))->invoke(null, MuDesk\Text::toUtf16('Aviso') . str_repeat("\0", 64000));
check('RD Gateway, RPC transport: bind answers no one waits for not kept; a chunked OUT stream ended after OUT_R2/B3; a message with nulls after it', $rpcMore,
    ['read', 0, [true, false], 'Aviso']);
// ... an IN channel with room for two PDUs of three: those two written to it, the third kept for its new one (the channel
// not offered to a select meanwhile: a select on it would not wait); at IN_R2/A4, IN_R1/A5 (the new one's cookie) its last
// PDU, the third on the new one
$rpcSw = (new ReflectionClass(MuDesk\Rdp\Gateway::class))->newInstanceWithoutConstructor();
[[$rpcOldIn, $rpcOldPeer], [$rpcNewIn, $rpcNewPeer]] = [$rpcPair(), $rpcPair()];
$rpcSwCookie = random_bytes(16);
$rpcSwPdus   = [$rpcFrag(9, 0x03, str_repeat('1', 36)), $rpcFrag(10, 0x03, str_repeat('2', 36)), $rpcFrag(11, 0x03, str_repeat('3', 36))];
foreach (['rpc' => true, 'rpcState' => 2, 'inSock' => $rpcOldIn, 'inNext' => [$rpcNewIn, $rpcSwCookie, 88], 'inSent' => 0x40000000 - 40 - 130,
          'out' => implode('', $rpcSwPdus)] as $rpcK => $rpcV) {
    $rpcProp($rpcK)->setValue($rpcSw, $rpcV);
}
foreach ([$rpcOldIn, $rpcOldPeer, $rpcNewIn, $rpcNewPeer] as $rpcS) {
    stream_set_blocking($rpcS, false);
}
$rpcFlush = new ReflectionMethod(MuDesk\Rdp\Gateway::class, 'flush');
$rpcSwSeen = [$rpcSw->writable() === [$rpcOldIn], $rpcFlush->invoke($rpcSw), $rpcSw->writable()];
$rpcProp('in')->setValue($rpcSw, pack('CCCCVvvV', 5, 0, 0x14, 0x03, 0x10, 28, 0, 0) . pack('vvVV', 0, 1, 13, 0));   // IN_R2/A4
(new ReflectionMethod(MuDesk\Rdp\Gateway::class, 'rpcIncoming'))->invoke($rpcSw);
$rpcSwSeen[] = $rpcSw->writable() === [$rpcOldIn];
$rpcSwSeen[] = $rpcFlush->invoke($rpcSw);
$rpcSwRead = static function ($s, int $n): string {
    for ($d = '', $end = microtime(true) + 2; strlen($d) < $n && microtime(true) < $end; usleep(1000)) {
        $d .= (string)fread($s, 65536);
    }
    return $d;
};
$rpcSwA5    = pack('CCCCVvvV', 5, 0, 0x14, 0x03, 0x10, 40, 0, 0) . pack('vvV', 0, 1, 3) . $rpcSwCookie;
$rpcSwSeen[] = $rpcSwRead($rpcOldPeer, 160) === $rpcSwPdus[0] . $rpcSwPdus[1] . $rpcSwA5;
$rpcSwSeen[] = $rpcSwRead($rpcNewPeer, 60) === $rpcSwPdus[2];
$rpcSwSeen[] = [$rpcProp('inSock')->getValue($rpcSw) === $rpcNewIn, $rpcProp('inSent')->getValue($rpcSw), $rpcProp('inNext')->getValue($rpcSw)];
foreach ([$rpcOldIn, $rpcOldPeer, $rpcNewIn, $rpcNewPeer] as $rpcS) {
    fclose($rpcS);
}
check('RD Gateway, RPC transport: an IN channel\'s last PDUs within its length, IN_R1/A5 after them, the rest on its new one', $rpcSwSeen,
    [true, true, [], true, true, true, true, [true, 88 + 60, []]]);

// the gateway's frames and packets read in one pass (each cut from the whole buffer: 4 MB of small frames took minutes of
// CPU); with much waiting for a gateway that takes nothing, the client's bytes stay in the pair, its writes wait (before, the
// session ended at 8 MB waiting)
$gwBare = (new ReflectionClass(MuDesk\Rdp\Gateway::class))->newInstanceWithoutConstructor();
$gwProp = static fn(object $g, string $name): ReflectionProperty => new ReflectionProperty(MuDesk\Rdp\Gateway::class, $name);
$gwProp($gwBare, 'in')->setValue($gwBare, str_repeat("\x82\x40" . MuDesk\Rdp\Gateway::packet(0x0A, pack('v', 54) . str_repeat('x', 54)), 16384));
$t0 = microtime(true);
(new ReflectionMethod(MuDesk\Rdp\Gateway::class, 'unframe'))->invoke($gwBare);
(new ReflectionMethod(MuDesk\Rdp\Gateway::class, 'packets'))->invoke($gwBare);
$framesTook = microtime(true) - $t0;
$framed     = count($gwProp($gwBare, 'queue')->getValue($gwBare));
$sink   = stream_socket_server('tcp://127.0.0.1:0');
$toSink = stream_socket_client('tcp://' . stream_socket_get_name($sink, false));
$sunk   = stream_socket_accept($sink);                           // (never read)
stream_set_blocking($toSink, false);
$gwFull = (new ReflectionClass(MuDesk\Rdp\Gateway::class))->newInstanceWithoutConstructor();
$gwFull->sock = $toSink;
(new ReflectionMethod(MuDesk\Rdp\Gateway::class, 'pair'))->invoke($gwFull);
$chunk = str_repeat('y', 65536);
$fullErr = null;
$zeros = $sentFull = 0;
try {
    for ($i = 0; $i < 3000 && $zeros < 50; $i++) {
        $n = (int)@fwrite($gwFull->inner(), $chunk);
        $sentFull += $n;
        $zeros = $n === 0 ? $zeros + 1 : 0;
        $gwFull->pump();
    }
} catch (MuDesk\Rdp\ProtocolException $e) {
    $fullErr = $e->key;
}
$fullWaiting = strlen($gwProp($gwFull, 'out')->getValue($gwFull));
$gwFull->close();
fclose($sunk);
fclose($sink);
check('RD Gateway: frames read in one pass; a gateway that takes nothing makes the client wait, not end', [
    $framed, $framesTook < 0.5, $fullErr, $zeros >= 50, $fullWaiting < 2 << 20, $sentFull > 0], [16384, true, null, true, true, true]);

// a gateway's policy of devices (the redirection flags of its tunnel's authorization): none forbidden when it enables all,
// all when it disables all, else each one; their channels not offered (the clipboard's; the drives' and printers' when both
// are forbidden) and the paste through the clipboard left (typed instead); a drive forbidden left out of the devices
// announced, a printer allowed kept (before, the policy was not read: all of them offered)
$gwPol = (new ReflectionClass(MuDesk\Rdp\Gateway::class))->newInstanceWithoutConstructor();
(new ReflectionMethod(MuDesk\Rdp\Gateway::class, 'pair'))->invoke($gwPol);
$gwPol->denied = ['drive', 'printer', 'clipboard'];
$gwProp($gwPol, 'closed')->setValue($gwPol, true);                  // (a tunnel already ended: the connection fails at once)
$gwProp($gwPol, 'eof')->setValue($gwPol, true);
$polClient = new Client(['width' => 800, 'height' => 600, 'bpp' => 16, 'kbd' => 0x040A, 'clientName' => 'c', 'monitors' => [], 'host' => 'pc', 'port' => 3389,
    'user' => 'ana', 'security' => 'tls', 'gateway' => static fn() => $gwPol,
    'channels' => [['cliprdr', 0xC0200000], ['rdpdr', 0x80000000], ['rdpsnd', 0xC0000000], ['drdynvc', 0xC0000000]]], static fn() => null);
$polClient->paster = static fn() => null;
try {
    $polClient->connect(static fn() => null);
} catch (Throwable) {
}
$polChannels = array_column((new ReflectionProperty(Client::class, 'o'))->getValue($polClient)['channels'], 0);
$gwPol->close();
$polRd = new Rdpdr($polClient, $wu['id'], true, true, sys_get_temp_dir());
$polClient->denied = ['drive'];
$polRd->onData(pack('vvvvV', 0x4472, 0x496E, 1, 0x000C, 7));        // the server's announce
check('RD Gateway: its policy of devices: their channels not offered, the paste typed instead, a drive forbidden not announced', [
    MuDesk\Rdp\Gateway::deniedBy(0x80000000 | 0x8), MuDesk\Rdp\Gateway::deniedBy(0x40000000), MuDesk\Rdp\Gateway::deniedBy(0x12),
    $polChannels, $polClient->paster === null,
    [(new ReflectionProperty(Rdpdr::class, 'drive'))->getValue($polRd), (new ReflectionProperty(Rdpdr::class, 'printer'))->getValue($polRd)]],
    [[], ['drive', 'printer', 'port', 'clipboard', 'pnp'], ['printer', 'pnp'], ['rdpsnd', 'drdynvc'], true, [false, true]]);

// Kerberos encryption (RFC 3961, RFC 3962), in plain PHP: n-fold, string-to-key, AES with ciphertext stealing, the keys of a
// usage, the whole encryption profile and its checksum, against the RFCs' vectors and MIT's known answers
$kc   = MuDesk\Rdp\KerberosCrypto::class;
$ctsK = hex2bin('636869636b656e207465726979616b69');
$ctsV = [['4920776f756c64206c696b652074686520', 'c6353568f2bf8cb4d8a580362da7ff7f97'],
    ['4920776f756c64206c696b65207468652047656e6572616c20476175277320', 'fc00783e0efdb2c1d445d4c8eff7ed2297687268d6ecccc0c07b25e25ecfe5'],
    ['4920776f756c64206c696b65207468652047656e6572616c2047617527732043', '39312523a78662d5be7fcbcc98ebf5a897687268d6ecccc0c07b25e25ecfe584'],
    ['4920776f756c64206c696b65207468652047656e6572616c20476175277320436869636b656e2c20706c656173652c', '97687268d6ecccc0c07b25e25ecfe584b3fffd940c16a18c1b5549d2f838029e39312523a78662d5be7fcbcc98ebf5'],
    ['4920776f756c64206c696b65207468652047656e6572616c20476175277320436869636b656e2c20706c656173652c20', '97687268d6ecccc0c07b25e25ecfe5849dad8bbb96c4cdc03bc103e1a194bbd839312523a78662d5be7fcbcc98ebf5a8'],
    ['4920776f756c64206c696b65207468652047656e6572616c20476175277320436869636b656e2c20706c656173652c20616e6420776f6e746f6e20736f75702e', '97687268d6ecccc0c07b25e25ecfe58439312523a78662d5be7fcbcc98ebf5a84807efe836ee89a526730dbc2f7bc8409dad8bbb96c4cdc03bc103e1a194bbd8']];
$mitV = [[17, 0, '', '5a5c0f0ba54f3828b2195e66ca24a289', '49ff8e11c173d9583a3254fbe7b1f1df36c538e8416784a1672e6676'],
    [17, 1, '1', '98450e3f3baa13f5c99beb936981b06f', 'f86742f537b35dc2174a4dbaa920faf9042090b065e1ebb1cad9a65394'],
    [17, 2, '9 bytesss', '9062430c8cda3388922e6d6a509f5b7a', '68fb9679601f45c78857b2bf820fd6e53eca8d42fd4b1d7024a09205abb7cd2ec26c355d2f'],
    [17, 3, '13 bytes byte', '033ee6502c54fd23e27791e987983827', 'ec366d0327a933bf49330e650e49bc6b974637fe80bf532fe51795b4809718e6194724db948d1fd637'],
    [17, 4, '30 bytes bytes bytes bytes byt', 'dceeb70b3de76562e689226c76429148', 'c96081032d5d8eeb7e32b4089f789d0faa481dea74c0f97cbf3146ddfcf8e800156ecb532fc203e30ff600b63b350939fece510f02d7ff1e7bac'],
    [18, 0, '', '17f275f2954f2ed1f90c377ba7f4d6a369aa0136e0bf0c927ad6133c693759a9', 'e5094c55ee7b38262e2b044280b069379a95bf95bd8376fb3281b435'],
    [18, 1, '1', 'b9477e1ff0329c0050e20ce6c72d2dff27e8fe541ab0954429a9cb5b4f7b1e2a', '406150b97aeb76d43b36b62cc1ecdfbe6f40e95755e0beb5c27825f3a4'],
    [18, 2, '9 bytesss', 'b1ae4cd8462aff1677053cc9279aac30b796fb81ce21474dd3ddbcfea4ec76d7', '09957aa25fcaf88f7b39e4406e633012d5fea21853f6478da7065caef41fd454a40824eec5'],
    [18, 3, '13 bytes byte', 'e5a72be9b7926c1225bafef9c1872e7ba4cdb2b17893d84abd90acdd8764d966', 'd8f1aafeec84587cc3e700a774e56651a6d693e174ec4473b5e6d96f80297a653fb818ad893e719f96'],
    [18, 4, '30 bytes bytes bytes bytes byt', 'f1c795e9248a09338d82c3f8d5b567040b0110736845041347235b1404231398', 'd1137a4d634cfece924dbc3bf6790648bd5cff7de0e7b99460211d0daef3d79a295c688858f3b34b9cbd6eebae81daf6b734d4d498b6714f1c1d']];
$mitOut = [];
foreach ($mitV as [$et, $use, $plain, $key, $ct]) {
    [$key, $ct] = [hex2bin($key), hex2bin($ct)];
    $conf = substr($kc::ctsDecrypt($kc::derive($key, pack('N', $use) . "\xAA"), substr($ct, 0, -12)), 0, 16);
    $mitOut[] = [$kc::decrypt($key, $use, $ct) === $plain, $kc::encrypt($key, $use, $plain, $conf) === $ct];
}
$tampered = 'not refused';
try {
    $kc::decrypt(hex2bin('033ee6502c54fd23e27791e987983827'), 3, hex2bin('ec366d0327a933bf49330e650e49bc6b974637fe80bf532fe51795b4809718e6194724db948d1fd638'));
} catch (MuDesk\Rdp\ProtocolException $e) {
    $tampered = $e->key;
}
$k128 = hex2bin('42263c6e89f4fc28b8df68ee09799f15');
$k256 = hex2bin('fe697b52bc0d3ce14432ba036a92e65bbb52280990a2fa27883998d72af30161');
check('Kerberos: n-fold, string-to-key, CTS, derived keys, the encryption profile and its checksum, as the RFC 3961/3962 vectors and MIT\'s known answers', [
    array_map(static fn(array $v): string => bin2hex($kc::nfold($v[0], $v[1])), [['012345', 8], ['password', 7], ['Rough Consensus, and Running Code', 8],
        ['password', 21], ['MASSACHVSETTS INSTITVTE OF TECHNOLOGY', 24], ['Q', 21], ['ba', 21], ['kerberos', 8], ['kerberos', 16], ['kerberos', 21], ['kerberos', 32]]),
    array_map(static fn(array $v): string => bin2hex($kc::stringToKey($v[0], $v[1], $v[2], $v[3])), [[17, 'password', 'ATHENA.MIT.EDUraeburn', 1],
        [18, 'password', 'ATHENA.MIT.EDUraeburn', 1], [17, 'password', 'ATHENA.MIT.EDUraeburn', 2], [18, 'password', 'ATHENA.MIT.EDUraeburn', 1200],
        [18, 'password', hex2bin('1234567878563412'), 5], [18, str_repeat('X', 64), 'pass phrase equals block size', 1200],
        [18, str_repeat('X', 65), 'pass phrase exceeds block size', 1200], [17, "\xf0\x9d\x84\x9e", 'EXAMPLE.COMpianist', 50]]),
    array_map(static fn(array $v): string => bin2hex($kc::ctsEncrypt($ctsK, hex2bin($v[0]))), $ctsV),
    array_map(static fn(array $v): string => bin2hex($kc::ctsDecrypt($ctsK, hex2bin($v[1]))), $ctsV),
    [bin2hex($kc::derive($k128, "\0\0\0\2\x99")), bin2hex($kc::derive($k128, "\0\0\0\2\xAA")), bin2hex($kc::derive($k256, "\0\0\0\2\x55"))],
    $mitOut, $tampered,
    [bin2hex($kc::checksum(hex2bin('9062430c8cda3388922e6d6a509f5b7a'), 3, 'eight nine ten eleven twelve thirteen')),
     bin2hex($kc::checksum(hex2bin('b1ae4cd8462aff1677053cc9279aac30b796fb81ce21474dd3ddbcfea4ec76d7'), 4, 'fourteen'))]],
    [['be072631276b1955', '78a07b6caf85fa', 'bb6ed30870b7f0e0', '59e4a8ca7c0385c3c37b3f6d2000247cb6e6bd5b3e', 'db3b0d8f0b061e603282b308a50841229ad798fab9540c1b',
      '518a54a215a8452a518a54a215a8452a518a54a215', 'fb25d531ae8974499f52fd92ea9857c4ba24cf297e', '6b65726265726f73', '6b65726265726f737b9b5b2b93132b93',
      '8372c236344e5f1550cd0747e15d62ca7a5a3bcea4', '6b65726265726f737b9b5b2b93132b935c9bdcdad95c9899c4cae4dee6d6cae4'],
     ['42263c6e89f4fc28b8df68ee09799f15', 'fe697b52bc0d3ce14432ba036a92e65bbb52280990a2fa27883998d72af30161', 'c651bf29e2300ac27fa469d693bdda13',
      '55a6ac740ad17b4846941051e1e8b0a7548d93b0ab30a8bc3ff16280382b8c2a', '97a4e786be20d81a382d5ebc96d5909cabcdadc87ca48f574504159f16c36e31',
      '89adee3608db8bc71f1bfbfe459486b05618b70cbae22092534e56c553ba4b34', 'd78c5c9cb872a8c9dad4697f0bb5b2d21496c82beb2caeda2112fceea057401b',
      'f149c1f2e154a73452d43e7fe62a56e5'],
     array_column($ctsV, 1), array_column($ctsV, 0),
     ['34280a382bc92769b2da2f9ef066854b', '5b14fc4e250e14ddf9dccf1af6674f53', '97151b4c76945063e2eb0529dc067d97d7bba90776d8126d91f34f3101aea8ba'],
     array_fill(0, 10, [true, true]), 'kerberos_integrity', ['01a4b088d45628f6946614e3', 'e08739e3279e2903ec8e3836']]);

// the Kerberos client against a KDC (tests/fixtures/kdc_server.php, written from RFC 4120 as Active Directory answers): the
// user's ticket with pre-authentication (the salt from its PA-ETYPE-INFO2), the service's ticket (the checksum over the
// request's body); a wrong password, a locked account said as such and never tried with NTLM (it would count again against
// the account), an unknown user or service left to NTLM, a clock an hour off corrected, user@domain as an enterprise name;
// the realm found from a DNS domain, a user@domain or the computer's name, never for an address or localhost; Kerberos
// turned off in config.php
$kdcP = proc_open([PHP_BINARY, __DIR__ . '/fixtures/kdc_server.php', 'ana', 'Clave-Krb-1'], [1 => ['pipe', 'w'], 2 => ['file', $nul, 'w']], $kdcPipes);
$kdcPort = (int)trim((string)fgets($kdcPipes[1]));
$cfgProp = new ReflectionProperty(MuDesk\Config::class, 'c');
$cfg0    = $cfgProp->getValue();
$cfgProp->setValue(null, ['kerberos_kdc' => ['TEST.LOCAL' => "127.0.0.1:$kdcPort"]] + $cfg0);
$krbTry = static function (string $user, string $pw, string $spn): string {
    try {
        $k = new MuDesk\Rdp\Kerberos($user, 'TEST.LOCAL', $pw, $spn);
        $k->tickets();
        return 'ok ' . $k->realm;
    } catch (MuDesk\Rdp\ProtocolException $e) {
        return $e->key;
    }
};
$krbRes  = [$krbTry('ana', 'Clave-Krb-1', 'HTTP/rdg.test.local'), $krbTry('ana', 'mala', 'HTTP/rdg.test.local'), $krbTry('locked', 'Clave-Krb-1', 'HTTP/rdg.test.local'),
    $krbTry('nadie', 'x', 'HTTP/rdg.test.local'), $krbTry('ana', 'Clave-Krb-1', 'HTTP/otro.test.local'), $krbTry('skewed', 'Clave-Krb-1', 'TERMSRV/pc.test.local'),
    $krbTry('ana@test.local', 'Clave-Krb-1', 'TERMSRV/pc.test.local')];
$kdcSaid = [];
for ($i = 0; $i < 17; $i++) {
    $kdcSaid[] = trim((string)fgets($kdcPipes[1]));
}
$forSvc  = static function (string $u, string $d, string $pw, string $host): string {
    try {
        return MuDesk\Rdp\Kerberos::forService($u, $d, $pw, 'TERMSRV', $host) === null ? 'ntlm' : 'kerberos';
    } catch (MuDesk\Rdp\ProtocolException $e) {
        return $e->key;
    }
};
$forRes = [$forSvc('ana', 'TEST.LOCAL', 'Clave-Krb-1', 'pc.test.local'), $forSvc('ana', 'TEST', 'Clave-Krb-1', 'pc.test.local'),
    $forSvc('ana', 'TEST', 'Clave-Krb-1', 'pc'), $forSvc('ana', 'TEST.LOCAL', 'Clave-Krb-1', '10.0.0.5'), $forSvc('ana', 'TEST.LOCAL', 'Clave-Krb-1', 'localhost'),
    $forSvc('ana', 'TEST.LOCAL', 'mala', 'pc.test.local'), $forSvc('nadie', 'TEST.LOCAL', 'x', 'pc.test.local')];
$cfgProp->setValue(null, ['kerberos' => false, 'kerberos_kdc' => ['TEST.LOCAL' => "127.0.0.1:$kdcPort"]] + $cfg0);
$forOff = $forSvc('ana', 'TEST.LOCAL', 'Clave-Krb-1', 'pc.test.local');
$cfgProp->setValue(null, ['kerberos_kdc' => ['TEST.LOCAL' => "127.0.0.1:$kdcPort"]] + $cfg0);
check('Kerberos: tickets from a KDC (pre-authentication, the service\'s), a wrong password and a locked account said and never retried with NTLM, unknown user or service left to NTLM, a clock off corrected, user@domain; when it is tried', [
    $krbRes, $kdcSaid, $forRes, $forOff,
    [MuDesk\Rdp\Kerberos::realmsFor('ana', 'EMPRESA', 'rdsh1.empresa.local'), MuDesk\Rdp\Kerberos::realmsFor('ana@empresa.com', '', 'rdsh1'),
     MuDesk\Rdp\Kerberos::realmsFor('ana', 'empresa.local', 'pc'), MuDesk\Rdp\Kerberos::realmsFor('ana', 'EMPRESA', 'pc'),
     MuDesk\Rdp\Kerberos::realmsFor('ana', 'empresa.local', '[fd00::5]'), MuDesk\Rdp\Kerberos::realmsFor('ana', 'empresa.local', 'LOCALHOST')]],
    [['ok TEST.LOCAL', 'auth_failed', 'account_locked', 'kerberos', 'kerberos', 'ok TEST.LOCAL', 'ok TEST.LOCAL'],
     ['as ana preauth', 'as ana ok', 'tgs HTTP/rdg.test.local ok', 'as ana preauth', 'as ana bad password', 'as locked preauth', 'as locked locked',
      'as nadie unknown', 'as ana preauth', 'as ana ok', 'tgs HTTP/otro.test.local unknown', 'as skewed preauth', 'as skewed skew', 'as skewed ok',
      'tgs TERMSRV/pc.test.local ok', 'as ana@test.local unknown', 'as ana preauth'],
     ['kerberos', 'kerberos', 'ntlm', 'ntlm', 'ntlm', 'auth_failed', 'ntlm'], 'ntlm',
     [['EMPRESA.LOCAL'], ['EMPRESA.COM'], ['EMPRESA.LOCAL'], [], [], []]]);

// rc4-hmac (RFC 4757) for an account whose only key is RC4 (its password older than the domain's AES keys): its key the NT
// hash (that of "password" is known), the AS-REP's part as Windows encrypts it (usage 3 as 8), a changed byte refused; the
// account signed in (the type offered in the request, the timestamp and the reply in RC4, the session keys AES). An
// alternate UPN suffix: user@empresa.com as an enterprise name, found through the domain's or the computer's KDC (before:
// its suffix had no KDC, so never Kerberos), and moved to the realm the KDC names (KDC_ERR_WRONG_REALM); the name before the
// @ asked only in the realm the @ names (another realm's "ana" is not ana@otra.com). A service in another domain (the KDC
// gives a referral): NTLM, not that ticket sent to the service. A KDC's iteration count of 1 (an easy timestamp to break),
// a session key shorter than its type, ignored or refused; a locked account's status in a METHOD-DATA (older Windows). A
// silent KDC: NTLM after Kerberos::$wait, not WAIT per read. No realm for a local account (no domain, ".", the computer's
// own name) nor for a NetBIOS domain that is not the host's DNS suffix: a domain account of the same name would be asked
// (the KDC's lines read by count: a pipe on Windows has no non-blocking read; the 11 the checks above left first)
$kdcLines = static function (int $n) use (&$kdcPipes): array {
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $out[] = trim((string)fgets($kdcPipes[1]));
    }
    return $out;
};
$kdcLines(11);
$rc4Key = MuDesk\Rdp\KerberosCrypto::userKey(23, 'password', 'ignored');
$rc4Enc = MuDesk\Rdp\KerberosCrypto::rc4Encrypt($rc4Key, 3, 'parte cifrada', '12345678');
$rc4Bad = 'not refused';
try {
    MuDesk\Rdp\KerberosCrypto::rc4Decrypt($rc4Key, 3, substr($rc4Enc, 0, -1) . chr(ord($rc4Enc[-1]) ^ 1));
} catch (MuDesk\Rdp\ProtocolException $e) {
    $rc4Bad = $e->key;
}
$silent = stream_socket_server('tcp://127.0.0.1:0');      // (it takes the connection and never answers)
$silentPort = (int)substr((string)strrchr((string)stream_socket_get_name($silent, false), ':'), 1);
$cfgProp->setValue(null, ['kerberos_kdc' => ['TEST.LOCAL' => "127.0.0.1:$kdcPort", 'PRIMERO.LOCAL' => "127.0.0.1:$kdcPort", 'LENTO.LOCAL' => "127.0.0.1:$silentPort"],
    'kerberos_dns' => false] + $cfg0);
MuDesk\Settings::save(['kerberos_rc4' => true]);              // (the RC4 checks: allowed by the administrator)
$krb2 = static function (string $user, string $realm, string $spn): string {
    try {
        $k = new MuDesk\Rdp\Kerberos($user, $realm, 'Clave-Krb-1', $spn);
        $k->tickets();
        return 'ok ' . $k->realm;
    } catch (MuDesk\Rdp\ProtocolException $e) {
        return $e->key;
    }
};
$krb2Res = [$krb2('viejo', 'TEST.LOCAL', 'TERMSRV/pc.test.local'), $krb2('ana@empresa.com', 'TEST.LOCAL', 'TERMSRV/pc.test.local'),
    $krb2('ana@empresa.com', 'PRIMERO.LOCAL', 'TERMSRV/pc.test.local'), $krb2('ana@otra.com', 'TEST.LOCAL', 'TERMSRV/pc.test.local'),
    $krb2('ana', 'TEST.LOCAL', 'TERMSRV/pc.otro.local'), $krb2('iter1', 'TEST.LOCAL', 'TERMSRV/pc.test.local'),
    $krb2('cortaclave', 'TEST.LOCAL', 'TERMSRV/pc.test.local'), $krb2('locked2', 'TEST.LOCAL', 'TERMSRV/pc.test.local')];
$krb2Said = $kdcLines(19);
$forUpn = [$forSvc('ana@empresa.com', '', 'Clave-Krb-1', 'pc.test.local'), $forSvc('ana@empresa.com', '', 'Clave-Krb-1', 'pc')];
MuDesk\Rdp\Kerberos::$wait = 1.0;
$silentT = microtime(true);
$silentRes = [$forSvc('ana', 'LENTO', 'Clave-Krb-1', 'pc.lento.local'), round(microtime(true) - $silentT) <= 1];
MuDesk\Rdp\Kerberos::$wait = 8.0;
fclose($silent);
// RC4 off, as by default (Administration -> Settings): not offered (a 2003 domain's account left to NTLM); a KDC, or someone
// in between, saying the account has only RC4 gets no timestamp encrypted with it; a session key of RC4 refused. Allowed,
// the same taken
$kdcLines(3);                                  // (the KDC's lines of the alternate suffix's sign-in above)
$rc4Off = [MuDesk\Settings::DEFAULTS['kerberos_rc4']];
$cfgProp->setValue(null, ['kerberos_kdc' => ['TEST.LOCAL' => "127.0.0.1:$kdcPort"], 'kerberos_dns' => false] + $cfg0);
foreach ([false, true] as $on) {
    MuDesk\Settings::save(['kerberos_rc4' => $on]);
    $rc4Off[] = [$krb2('viejo', 'TEST.LOCAL', 'TERMSRV/pc.test.local'), $krb2('falso', 'TEST.LOCAL', 'TERMSRV/pc.test.local'),
        $krb2('rc4sesion', 'TEST.LOCAL', 'TERMSRV/pc.test.local')];
    $rc4Off[] = $kdcLines($on ? 9 : 4);
}
check('Kerberos: RC4 off unless the administrator allows it: not offered, an account said to have only RC4 sent no timestamp, an RC4 session key refused; allowed, all taken',
    $rc4Off, [false, ['kerberos', 'kerberos', 'kerberos'], ['as viejo etype', 'as falso preauth', 'as rc4sesion preauth', 'as rc4sesion ok'],
    ['ok TEST.LOCAL', 'ok TEST.LOCAL', 'ok TEST.LOCAL'], ['as viejo preauth', 'as viejo ok', 'tgs TERMSRV/pc.test.local ok', 'as falso preauth', 'as falso ok',
     'tgs TERMSRV/pc.test.local ok', 'as rc4sesion preauth', 'as rc4sesion ok', 'tgs TERMSRV/pc.test.local ok']]);
// (allowed from here: the RC4 checks below)
$cfgProp->setValue(null, ['kerberos_kdc' => ['TEST.LOCAL' => "127.0.0.1:$kdcPort"]] + $cfg0);
// (what is to be refused or read, read so that it is a failed check if it throws, not the end of the tests)
$krbRead = static function (callable $f): string {
    try {
        return $f();
    } catch (MuDesk\Rdp\ProtocolException $e) {
        return 'refused: ' . $e->getMessage();
    }
};
check('Kerberos: an RC4 account, an alternate UPN suffix (and the realm the KDC names), a service in another domain left to NTLM, a KDC\'s bad salt or key refused, a silent KDC given up; no realm for a local account', [
    bin2hex($rc4Key), $krbRead(static fn() => MuDesk\Rdp\KerberosCrypto::rc4Decrypt($rc4Key, 8, $rc4Enc)), $rc4Bad, $krb2Res, $krb2Said, $forUpn, $silentRes,
    [MuDesk\Rdp\Kerberos::realmsFor('admin', '', 'pc1.empresa.local'), MuDesk\Rdp\Kerberos::realmsFor('admin', 'PC1', 'pc1.empresa.local'),
     MuDesk\Rdp\Kerberos::realmsFor('admin', '.', 'pc1.empresa.local'), MuDesk\Rdp\Kerberos::realmsFor('ana', 'CONTOSO', 'pc.empresa.local'),
     MuDesk\Rdp\Kerberos::realmsFor('ana@empresa.com', '', 'rdsh1.corp.local'), MuDesk\Rdp\Kerberos::realmsFor('ana', 'corp', 'rdsh1.ad.corp.es')]],
    ['8846f7eaee8fb117ad06bdd830b7586c', 'parte cifrada', 'kerberos_integrity',
     ['ok TEST.LOCAL', 'ok TEST.LOCAL', 'ok TEST.LOCAL', 'kerberos', 'kerberos', 'kerberos', 'kerberos', 'account_locked'],
     ['as viejo preauth', 'as viejo ok', 'tgs TERMSRV/pc.test.local ok', 'as ana@empresa.com preauth', 'as ana@empresa.com ok', 'tgs TERMSRV/pc.test.local ok',
      'as ana@empresa.com referral', 'as ana@empresa.com preauth', 'as ana@empresa.com ok', 'tgs TERMSRV/pc.test.local ok', 'as ana@otra.com unknown',
      'as ana preauth', 'as ana ok', 'tgs TERMSRV/pc.otro.local referral', 'as iter1 preauth', 'as cortaclave preauth', 'as cortaclave ok',
      'as locked2 preauth', 'as locked2 locked'],
     ['kerberos', 'ntlm'], ['ntlm', true],
     [[], [], [], [], ['EMPRESA.COM', 'CORP.LOCAL'], ['AD.CORP.ES']]]);

// GSS-API with the service (RFC 4121, as Windows answers in CredSSP): the AP-REQ in SPNEGO (flags 0x32, the GSS checksum's
// channel bindings, and with them the hint AD-AUTH-DATA-AP-OPTIONS), the AP-REP's subkey taken as the context's key; its
// mechListMIC checked and ours sent only when asked for (accept-incomplete); Wrap as Windows expects it (EC 0, RRC 28) and
// Unwrap of Windows' (RRC 28, with or without EC filler), a tampered token refused
$gssK  = new MuDesk\Rdp\Kerberos('ana', 'TEST.LOCAL', 'Clave-Krb-1', 'TERMSRV/pc.test.local');
$gssB  = random_bytes(16);
$gssT  = $gssK->initToken($gssB);
[$gssUser, $gssBind, $gssKey, $gssSeq, $gssOur, $gssReply, $gssMech, $gssHint, $gssFlags] = KrbFixture::accept($gssT, 'TERMSRV/pc.test.local', $gssB, true);
$gssMine  = $gssK->accept($gssReply);
$gssMicOk = $gssMine !== null && hash_equals(MuDesk\Rdp\KerberosCrypto::checksum($gssKey, 25, $gssMech . substr($m = MuDesk\Rdp\Der::value(MuDesk\Rdp\Der::fields(
    MuDesk\Rdp\Der::value(MuDesk\Rdp\Der::value($gssMine)))[3]), 0, 16)), substr($m, 16));
$gssWrapped = $gssK->wrap('clave publica');
$gssIn = [$krbRead(static fn() => $gssK->unwrap(KrbFixture::wrap($gssKey, $gssOur + 1, 'respuesta'))),
          $krbRead(static fn() => $gssK->unwrap(KrbFixture::wrap($gssKey, $gssOur + 2, 'con relleno', 16)))];
$gssBad = 'not refused';
try {
    $t = KrbFixture::wrap($gssKey, 9, 'x');
    $gssK->unwrap(substr($t, 0, -1) . chr(ord($t[-1]) ^ 1));
} catch (MuDesk\Rdp\ProtocolException $e) {
    $gssBad = $e->key;
}
// (a Windows Server 2003 domain: its user's and its service's keys RC4, so the context key too; RFC 4757's tokens: the
// mechListMIC checked and ours made, Wrap and Unwrap, a changed byte and a token in the initiator's direction refused)
$g4 = new MuDesk\Rdp\Kerberos('viejo', 'TEST.LOCAL', 'Clave-Krb-1', 'TERMSRV/viejo.test.local');
$g4B = random_bytes(16);
[$g4User, $g4Bind, $g4Key, , $g4Our, $g4Reply, $g4Mech, , , $g4Type] = KrbFixture::accept($g4->initToken($g4B), 'TERMSRV/viejo.test.local', $g4B, true);
$g4Mine = $g4->accept($g4Reply);
$g4MicOk = KrbFixture::rc4MicOk($g4Key, MuDesk\Rdp\Der::value(MuDesk\Rdp\Der::fields(MuDesk\Rdp\Der::value(MuDesk\Rdp\Der::value($g4Mine)))[3]), $g4Mech);
$g4Out = KrbFixture::rc4Unwrap($g4Key, $g4->wrap('clave publica'), false);
$g4In  = $krbRead(static fn() => $g4->unwrap(KrbFixture::rc4Wrap($g4Key, $g4Our + 1, 'respuesta', true)));
$g4Bad = [];
foreach ([static fn(string $t): string => substr($t, 0, -1) . chr(ord($t[-1]) ^ 1), static fn(string $t): string => $t] as $i => $spoil) {
    try {
        $g4->unwrap($spoil(KrbFixture::rc4Wrap($g4Key, 7, 'x', $i === 0)));
        $g4Bad[] = 'not refused';
    } catch (MuDesk\Rdp\ProtocolException $e) {
        $g4Bad[] = $e->key;
    }
}
$gssNoMic = new MuDesk\Rdp\Kerberos('ana', 'TEST.LOCAL', 'Clave-Krb-1', 'TERMSRV/pc.test.local');
$gssNoMicReply = $gssNoMic->accept(KrbFixture::accept($gssNoMic->initToken(), 'TERMSRV/pc.test.local', null)[5]);
check('Kerberos: GSS with the service: the AP-REQ (flags, channel bindings and their hint), the AP-REP\'s subkey, the mechListMIC checked and sent when asked for, Wrap and Unwrap as Windows has them; RC4\'s (RFC 4757) for a 2003 domain', [
    [$gssUser, $gssBind, $gssHint, $gssFlags], $gssK->established(), $gssMicOk,
    [bin2hex(substr($gssWrapped, 0, 8)), KrbFixture::unwrap($gssKey, $gssWrapped)], $gssIn, $gssBad, $gssNoMicReply, $gssNoMic->established(),
    [$g4User, $g4Bind, $g4Type, $g4->established(), $g4MicOk, $g4Out, $g4In, $g4Bad]],
    [['ana', true, true, 0x32], true, true, ['050406ff0000001c', 'clave publica'], ['respuesta', 'con relleno'], 'mitm', null, true,
     ['viejo', true, 23, true, true, 'clave publica', 'respuesta', ['mitm', 'mitm']]]);
// the answers checked as a man in the middle could change them: the service's AP-REP changed (not made with the ticket's
// session key), the AP-REP of an earlier request sent again (its time is that request's), its mechListMIC changed: each
// refused as a man in the middle (not as a ticket refused, which would sign in with NTLM instead); a KDC's reply part to
// another request (another nonce) refused
$krbChanged = static fn(string $t): string => substr($t, 0, -1) . chr(ord($t[-1]) ^ 1);
$krbAnswer  = static function (string $reply) use ($gssNoMic): string {
    try {
        $gssNoMic->accept($reply);
        return 'taken';
    } catch (MuDesk\Rdp\ProtocolException $e) {
        return $e->key;
    }
};
$krbOld     = KrbFixture::accept($gssNoMic->initToken(), 'TERMSRV/pc.test.local', null)[5];
$krbChecked = [$krbAnswer($krbChanged(KrbFixture::accept($gssNoMic->initToken(), 'TERMSRV/pc.test.local', null)[5]))];
$gssNoMic->initToken();
$krbChecked[] = $krbAnswer($krbOld);
$krbChecked[] = $krbAnswer($krbChanged(KrbFixture::accept($gssNoMic->initToken(), 'TERMSRV/pc.test.local', null, true)[5]));
try {
    (new ReflectionMethod(MuDesk\Rdp\Kerberos::class, 'repPart'))->invoke(null, KrbFixture::app(25, MuDesk\Rdp\Der::seq(MuDesk\Rdp\Der::ctx(0, MuDesk\Rdp\Der::seq(
        MuDesk\Rdp\Der::ctx(0, MuDesk\Rdp\Der::int(18)), MuDesk\Rdp\Der::ctx(1, MuDesk\Rdp\Der::octet(random_bytes(32))))), MuDesk\Rdp\Der::ctx(2, MuDesk\Rdp\Der::int(8)))), 7);
    $krbChecked[] = 'taken';
} catch (MuDesk\Rdp\ProtocolException $e) {
    $krbChecked[] = $e->key;
}
check('Kerberos: the service\'s AP-REP changed or sent again, its mechListMIC changed, refused as a man in the middle; a KDC\'s reply to another request refused',
    $krbChecked, ['mitm', 'mitm', 'mitm', 'kerberos']);
// the KDCs of a realm from the DNS (its SRV records: a realm anyone can name, typing user@domain): only on Kerberos' port 88,
// and never this computer, a link-local address or a cloud's metadata (in a process of its own, its DNS answers made up)
$kdcDns = proc_open([PHP_BINARY, '-r', 'namespace MuDesk\Rdp; function dns_get_record(string $name, int $type): array {
        return $name !== "_kerberos._tcp.empresa.test" ? [] : array_map(static fn(array $r): array => ["target" => $r[0], "port" => $r[1], "pri" => 0, "weight" => 0],
            [["192.0.2.10", 389], ["127.0.0.1", 88], ["169.254.169.254", 88], ["192.0.2.11", 88]]); }
    require $argv[1]; echo json_encode(Kerberos::kdcs("EMPRESA.TEST"));', __DIR__ . '/../src/bootstrap.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $kdcDnsPipes);
$kdcFromDns = [stream_get_contents($kdcDnsPipes[1]), stream_get_contents($kdcDnsPipes[2])];
proc_close($kdcDns);
// ... and an answer whose length is past 1 MB not read (1 GB was asked of the memory: the worker gone); the client in a
// process of its own, this one its KDC
$bigKdc  = stream_socket_server('tcp://127.0.0.1:0');
$bigP    = proc_open([PHP_BINARY, '-d', 'memory_limit=256M', '-r', 'require $argv[1]; try { (new MuDesk\Rdp\Kerberos("ana", "TEST.LOCAL", "x", "TERMSRV/pc.test.local",
    [["127.0.0.1", (int)$argv[2]]]))->tickets(); echo "tickets"; } catch (MuDesk\Rdp\ProtocolException $e) { echo $e->getMessage(); }', __DIR__ . '/../src/bootstrap.php',
    substr((string)strrchr((string)stream_socket_get_name($bigKdc, false), ':'), 1)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $bigPipes);
$bigC = stream_socket_accept($bigKdc, 10);
$bigC && fwrite($bigC, pack('N', 1 << 30) . 'krb5');
$bigOut = [preg_replace('~:\d+ ~', ':<port> ', (string)stream_get_contents($bigPipes[1])), stream_get_contents($bigPipes[2])];
proc_close($bigP);
$bigC && fclose($bigC);
fclose($bigKdc);
check('Kerberos: KDCs from the DNS only on port 88, not this computer, link-local or a cloud\'s metadata; a KDC\'s answer past 1 MB not read', [$kdcFromDns, $bigOut],
    [['[["192.0.2.11",88]]', ''], ['Kerberos: KDC unreachable (127.0.0.1:<port> no answer)', '']]);

// the gateway with Kerberos and with the older transport (tests/fixtures/rdg_server.php): signed in with a ticket for
// HTTP/<gateway> (its channel bindings and their hint), on both connections of the older transport (Windows Server 2012 R2:
// 200 instead of the WebSocket, its few random bytes, the client's packets in chunks of a second connection), also through a
// proxy that chunks the stream (and 100 random bytes); a ticket the gateway refuses: NTLM on the same connection; a consent
// message shown, accepted (the tunnel goes on, its service message kept to show) or declined (no tunnel), and the
// capabilities that say this client takes them
[$echo2P, $echo2Port] = (static function (): array {
    $p = proc_open([PHP_BINARY, '-r', '$s = stream_socket_server("tcp://127.0.0.1:0"); echo substr(strrchr(stream_socket_get_name($s, false), ":"), 1), "\n";'
        . ' while ($c = stream_socket_accept($s, 60)) { while (($d = fread($c, 8192)) !== false && $d !== "") { fwrite($c, strtoupper($d)); } fclose($c); }'], [1 => ['pipe', 'w']], $pp);
    return [$p, (int)trim((string)fgets($pp[1]))];
})();
$rdg2 = proc_open([PHP_BINARY, __DIR__ . '/fixtures/rdg_server.php', 'ana', 'TEST', 'Clave-Krb-1', $cnf, (string)$echo2Port], [1 => ['pipe', 'w'], 2 => ['file', $nul, 'w']], $rdg2Pipes);
$rdg2Port  = (int)trim((string)fgets($rdg2Pipes[1]));
$rdg2Lines = static function () use (&$rdg2Pipes): array {
    $out = [];
    while (($l = fgets($rdg2Pipes[1])) !== false) {
        $out[] = $l = trim($l);
        if (preg_match('/^(relayed|auth bad$|bad )/', $l)) {
            break;
        }
    }
    return $out;
};
$gw2 = static function (string $host, string $domain, ?callable $consent = null) use ($rdg2Port): array {
    try {
        $g = new MuDesk\Rdp\Gateway($host, '127.0.0.1', $rdg2Port, 'ana', $domain, 'Clave-Krb-1', 'pc.test', 3389, 'MuDesk', null, $consent);
        fwrite($g->inner(), 'hola por el tunel');
        $got = '';
        for ($i = 0; $i < 300 && strlen($got) < 17; $i++) {
            $g->pump();
            usleep(10000);
            $got .= (string)@fread($g->inner(), 100);
        }
        for ($i = 0; $i < 10; $i++) {
            $g->pump();
            usleep(10000);
        }
        $r = [$g->transport, $g->auth, $got, $g->messages];
        $g->close();
        return $r;
    } catch (MuDesk\Rdp\ProtocolException $e) {
        return [$e->key];
    }
};
$shown = [];
$gw2Res = [$gw2('rdg.test.local', 'TEST.LOCAL'), $gw2('legacy.test.local', 'TEST.LOCAL'), $gw2('legacy.test', 'TEST'), $gw2('legacyc.test', 'TEST'),
    $gw2('krbbad.test.local', 'TEST.LOCAL')];
$gw2Seen = [$rdg2Lines(), $rdg2Lines(), $rdg2Lines(), $rdg2Lines(), $rdg2Lines()];
$gw2Res[] = $gw2('consent.test', 'TEST', static function (string $text) use (&$shown): bool {
    $shown[] = $text;
    return true;
});
$gw2Seen[] = $rdg2Lines();
$gw2Res[] = $gw2('consent.test', 'TEST', static fn(): bool => false);
$gw2Seen[] = $rdg2Lines();
$gw2Res[] = $gw2('consent.test', 'TEST');
$gw2Seen[] = $rdg2Lines();
check('RD Gateway: Kerberos on both transports, the older transport (also through a chunking proxy), a refused ticket then NTLM, a consent message accepted, declined or with nobody to answer, its service message kept', [
    $gw2Res, $gw2Seen, $shown],
    [[['websocket', 'kerberos', 'HOLA POR EL TUNEL', []], ['http', 'kerberos', 'HOLA POR EL TUNEL', []], ['http', 'ntlm', 'HOLA POR EL TUNEL', []],
      ['http', 'ntlm', 'HOLA POR EL TUNEL', []], ['websocket', 'ntlm', 'HOLA POR EL TUNEL', []],
      ['websocket', 'ntlm', 'HOLA POR EL TUNEL', ['Mantenimiento a las 22:00']], ['gateway_consent'], ['gateway_consent']],
     [['auth ok kerberos bindings ok cbt', 'channel pc.test:3389', 'keepalive answered', 'relayed 17 17'],
      ['auth ok kerberos bindings ok cbt', 'auth ok kerberos bindings ok cbt', 'legacy in', 'channel pc.test:3389', 'keepalive answered', 'relayed 17 17'],
      ['auth ok bindings ok', 'auth ok bindings ok', 'legacy in', 'channel pc.test:3389', 'keepalive answered', 'relayed 17 17'],
      ['auth ok bindings ok', 'auth ok bindings ok', 'legacy in', 'channel pc.test:3389', 'keepalive answered', 'relayed 17 17'],
      ['auth bad kerberos', 'auth ok bindings ok', 'channel pc.test:3389', 'keepalive answered', 'relayed 17 17'],
      ['auth ok bindings ok', 'caps 12', 'consent sent', 'tunnel auth', 'channel pc.test:3389', 'keepalive answered', 'relayed 17 17'],
      ['auth ok bindings ok', 'caps 12', 'consent sent', 'relayed 0 0'], ['auth ok bindings ok', 'caps 12', 'consent sent', 'relayed 0 0']],
     ["Uso solo autorizado.\nTodo queda registrado."]]);
// the consent message of a tunnel response: found after the fields its flags announce (tunnel id, capabilities, and a
// statement-of-health request whose nonce is 16 bytes by the specification and 20 by other clients), its ending null off
$u16z = static fn(string $t): string => Text::toUtf16($t) . "\0\0";
$soh  = static fn(int $nonce): string => pack('vVvv', 0, 0, 0x17, 0) . pack('VV', 7, 0x0C) . str_repeat("\x5A", $nonce) . pack('v', 6) . 'certif'
    . pack('v', strlen($u16z('Hola'))) . $u16z('Hola');
check('RD Gateway: the consent message read after the fields before it (a 16- or 20-byte nonce; padding after it: the specification\'s 16), its ending null off; none without its flag', [
    MuDesk\Rdp\Gateway::consentMessage(pack('vVvv', 0, 0, 0x13, 0) . pack('VV', 7, 0x0C) . pack('v', strlen($u16z('Línea 1'))) . $u16z('Línea 1')),
    MuDesk\Rdp\Gateway::consentMessage($soh(16)), MuDesk\Rdp\Gateway::consentMessage($soh(20)), MuDesk\Rdp\Gateway::consentMessage(pack('vVvvV', 0, 0, 0x1, 0, 7)),
    MuDesk\Rdp\Gateway::consentMessage($soh(16) . "\0")],
    ['Línea 1', 'Hola', 'Hola', null, 'Hola']);

// a hostile HTTP answer from a gateway (before its certificate is pinned: trusted on first use): a chunk size of 16 hex
// digits (it wrapped to a negative size and moved the parser backwards: a worker held at full CPU for ever), one that is
// not hex (notices), a body of more than 1 MB (cut, and its rest read as the next answer): refused at once, in a process
// of its own given 15 seconds
$gwHostile = static function (string $answer): string {
    $p = proc_open([PHP_BINARY, '-r', 'require $argv[1]; $g = (new ReflectionClass(MuDesk\Rdp\Gateway::class))->newInstanceWithoutConstructor();
        [$a, $b] = stream_socket_pair(PHP_OS_FAMILY === "Windows" ? STREAM_PF_INET : STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        (new ReflectionProperty($g, "sock"))->setValue($g, $a); fwrite($b, $argv[2]);
        try { (new ReflectionMethod($g, "response"))->invoke($g); echo "read"; } catch (MuDesk\Rdp\ProtocolException $e) { echo $e->key; }',
        __DIR__ . '/../src/bootstrap.php', $answer], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp);
    for ($end = microtime(true) + 15; proc_get_status($p)['running'] && microtime(true) < $end;) {
        usleep(20000);
    }
    if (proc_get_status($p)['running']) {
        proc_terminate($p);
        proc_close($p);
        return 'held';
    }
    $out = stream_get_contents($pp[1]) . (trim((string)stream_get_contents($pp[2])) !== '' ? ' +stderr' : '');
    proc_close($p);
    return $out;
};
$gw401 = "HTTP/1.1 401 Unauthorized\r\nTransfer-Encoding: chunked\r\n\r\n";
check('RD Gateway: a hostile HTTP answer (negative chunk size, a size that is not hex, a body too large) refused at once', [
    $gwHostile($gw401 . "7E5\r\n" . str_repeat('x', 2021) . "\r\nFFFFFFFFFFFFF800\r\n"), $gwHostile($gw401 . "zz\r\nab\r\n0\r\n\r\n"),
    $gwHostile("HTTP/1.1 401 Unauthorized\r\nContent-Length: 5000000\r\n\r\n" . str_repeat('x', 100)), $gwHostile($gw401 . "3;x=1\r\nabc\r\n0\r\n\r\n")],
    ['gateway', 'gateway', 'gateway', 'read']);
// ... and one that sends pings and keep-alives without reading our answers: its socket no longer read once 2 MB wait for it
// (each was answered, and the answers grew until the memory ran out)
$gwFlood = (new ReflectionClass(MuDesk\Rdp\Gateway::class))->newInstanceWithoutConstructor();
[$gwFa, $gwFb] = stream_socket_pair(PHP_OS_FAMILY === 'Windows' ? STREAM_PF_INET : STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
(new ReflectionProperty($gwFlood, 'sock'))->setValue($gwFlood, $gwFa);
$gwFloodOut = new ReflectionProperty($gwFlood, 'out');
$gwFloodOut->setValue($gwFlood, str_repeat('x', 100));
$gwFloodR = [count($gwFlood->readable())];
$gwFloodOut->setValue($gwFlood, str_repeat('x', 2 << 20));
$gwFloodR[] = count($gwFlood->readable());
fclose($gwFa);
fclose($gwFb);
check('RD Gateway: not read while 2 MB wait for it (a flood of pings unanswered by it)', $gwFloodR, [1, 0]);
// ... and the rest of what a gateway sends, bounded: a chunked body of more than 1 MB in all, a body that says it is more
// than 1 MB (all of it there), an HTTP head still coming past 64 KB, a WebSocket frame or a gateway packet over 16 MB, a
// packet shorter than its header, the older transport's stream without its handshake in its first 128 bytes: refused at
// once; a frame of a negative length (it moved the reading back for ever), in a process of its own given 10 seconds; at most
// 8 service messages kept; nor its socket read by pump() while 2 MB wait for it
$gwPair  = static fn(): array => stream_socket_pair(PHP_OS_FAMILY === 'Windows' ? STREAM_PF_INET : STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
$gwBound = static function (string $method, array $props, string $coming = '') use ($gwPair): string {
    $g = (new ReflectionClass(MuDesk\Rdp\Gateway::class))->newInstanceWithoutConstructor();
    [$a, $b] = $gwPair();
    fwrite($b, $coming);
    foreach (['sock' => $a] + $props as $p => $v) {
        (new ReflectionProperty(MuDesk\Rdp\Gateway::class, $p))->setValue($g, $v);
    }
    try {
        (new ReflectionMethod(MuDesk\Rdp\Gateway::class, $method))->invoke($g);
        $r = 'read';
    } catch (MuDesk\Rdp\ProtocolException $e) {
        $r = $e->getMessage();
    }
    fclose($a);
    fclose($b);
    return $r;
};
$gwBounds = [
    $gwBound('response', ['in' => "HTTP/1.1 401 Unauthorized\r\nTransfer-Encoding: chunked\r\n\r\n80000\r\n" . str_repeat('a', 0x80000) . "\r\n80001\r\n"
        . str_repeat('b', 0x80001) . "\r\n0\r\n\r\n"]),
    $gwBound('response', ['in' => "HTTP/1.1 401 Unauthorized\r\nContent-Length: 1048577\r\n\r\n" . str_repeat('c', 1048577)]),
    $gwBound('response', ['in' => "HTTP/1.1 401 Unauthorized\r\n" . str_repeat('X-Relleno: ' . str_repeat('r', 1000) . "\r\n", 70)], "\r\n"),
    $gwBound('unframe', ['in' => "\x82\x7F" . pack('J', (16 << 20) + 1)]),
    $gwBound('packets', ['pkt' => pack('vvV', 0x0A, 0, (16 << 20) + 1)]),
    $gwBound('packets', ['pkt' => pack('vvV', 0x0A, 0, 7) . 'x']),
    $gwBound('stream', ['seed' => true, 'in' => str_repeat('z', 200)]),
];
$gwNeg = proc_open([PHP_BINARY, '-d', 'max_execution_time=10', '-d', 'display_errors=0', '-d', 'log_errors=0', '-r', 'require $argv[1];
    $g = (new ReflectionClass(MuDesk\Rdp\Gateway::class))->newInstanceWithoutConstructor(); (new ReflectionProperty($g, "in"))->setValue($g, (string)hex2bin($argv[2]));
    try { (new ReflectionMethod($g, "unframe"))->invoke($g); echo "read"; } catch (MuDesk\Rdp\ProtocolException $e) { echo $e->getMessage(); }',
    __DIR__ . '/../src/bootstrap.php', bin2hex("\x82\x7F" . pack('J', PHP_INT_MIN) . 'xy')], [1 => ['pipe', 'w'], 2 => ['file', $nul, 'w']], $gwNegPipes);
$gwBounds[] = stream_get_contents($gwNegPipes[1]);
proc_close($gwNeg);
$gwMsgs = (new ReflectionClass(MuDesk\Rdp\Gateway::class))->newInstanceWithoutConstructor();
for ($i = 0; $i < 12; $i++) {
    (new ReflectionMethod(MuDesk\Rdp\Gateway::class, 'control'))->invoke($gwMsgs, 0x0B, pack('v', 4) . "h\0i\0");
}
$gwBounds[] = count($gwMsgs->messages);
// (pump(): its writes to the gateway's socket taken no more, 2 MB of them waiting; the gateway's next bytes left in its socket)
[$gwPa, $gwPb] = $gwPair();
stream_set_blocking($gwPa, false);
for ($i = $zeros = 0; $i < 3000 && $zeros < 50; $i++) {
    $zeros = (int)@fwrite($gwPa, str_repeat('w', 65536)) === 0 ? $zeros + 1 : 0;
}
fwrite($gwPb, "\x82");
$gwPumped = (new ReflectionClass(MuDesk\Rdp\Gateway::class))->newInstanceWithoutConstructor();
$gwPumped->sock = $gwPa;
(new ReflectionProperty(MuDesk\Rdp\Gateway::class, 'out'))->setValue($gwPumped, str_repeat('x', 2 << 20));
usleep(100000);
$gwPumped->pump();
$gwBounds[] = [$zeros >= 50, (new ReflectionProperty(MuDesk\Rdp\Gateway::class, 'in'))->getValue($gwPumped), (string)fread($gwPa, 10)];
fclose($gwPa);
fclose($gwPb);
check('RD Gateway: a chunked body or one said past 1 MB, an endless head, a frame or a packet over 16 MB or under its header, a stream without its handshake, refused; a frame of a negative length; 8 messages kept; not read by pump() while 2 MB wait', $gwBounds,
    ['Gateway: HTTP body too large', 'Gateway: HTTP body too large', 'Gateway: no HTTP answer', 'Gateway: frame of 16777217 bytes', 'Gateway: packet of 16777217 bytes',
     'Gateway: packet of 7 bytes', 'Gateway: no handshake on its stream', 'Gateway: frame of -9223372036854775808 bytes', 8, [true, '', "\x82"]]);
proc_terminate($rdg2);
proc_close($rdg2);
proc_terminate($echo2P);
proc_close($echo2P);

// NLA with Kerberos (tests/fixtures/nla_server.php as Windows: its AP-REP with a mechListMIC asking for ours, its tokens
// sealed with RRC 28): the credentials delegated, for a DNS domain and for user@domain; the graphics pipeline taken (no
// version from NTLM); a wrong password refused by the KDC, with no NLA tried (not one more failure counted)
$nlaK = proc_open([PHP_BINARY, __DIR__ . '/fixtures/nla_server.php', 'ana', 'TEST', 'Clave-Krb-1', $cnf], [1 => ['pipe', 'w'], 2 => ['file', $nul, 'w']], $nlaKPipes);
$nlaKPort = (int)trim((string)fgets($nlaKPipes[1]));
$nlaKTry  = static function (string $user, string $domain, string $pw) use ($nlaKPort): array {
    $c = new Client(['host' => 'pc.test.local', 'ip' => '127.0.0.1', 'port' => $nlaKPort, 'user' => $user, 'domain' => $domain, 'password' => $pw, 'security' => 'nla',
        'width' => 800, 'height' => 600, 'bpp' => 16, 'kbd' => 0x040A, 'clientName' => 'c', 'monitors' => [], 'channels' => [], 'audio' => false, 'mic' => false,
        'orders' => false, 'gfx' => 'auto', 'compression' => 0, 'perf' => 0, 'tzBias' => 0, 'app' => '', 'vm' => '', 'lb' => ''], static fn() => null);
    try {
        $c->connect(static fn() => null);
        $r = 'connected';
    } catch (MuDesk\Rdp\ProtocolException $e) {
        $r = $e->key;
    } finally {
        isset($c->t) && $c->disconnect();
    }
    return [$r, $c->kerberos, $c->gfx];
};
$nlaKRes = [$nlaKTry('ana', 'TEST.LOCAL', 'Clave-Krb-1'), $nlaKTry('ana@test.local', '', 'Clave-Krb-1'), $nlaKTry('ana', 'TEST.LOCAL', 'mala')];
$nlaKSaid = [trim((string)fgets($nlaKPipes[1])), trim((string)fgets($nlaKPipes[1])), trim((string)fgets($nlaKPipes[1]))];   // (the third: the wrong password's connection, closed before NLA)
// (a ticket the server refuses, here one for another computer's name: connected again and signed in with NTLM, as Windows
// does; before, "wrong password", though the KDC had just said it is right)
$nlaKOther = (static function () use ($nlaKPort): array {
    $c = new Client(['host' => 'pc2.test.local', 'ip' => '127.0.0.1', 'port' => $nlaKPort, 'user' => 'ana', 'domain' => 'TEST', 'password' => 'Clave-Krb-1',
        'security' => 'nla', 'width' => 800, 'height' => 600, 'bpp' => 16, 'kbd' => 0x040A, 'clientName' => 'c', 'monitors' => [], 'channels' => [], 'audio' => false,
        'mic' => false, 'orders' => false, 'gfx' => 'auto', 'compression' => 0, 'perf' => 0, 'tzBias' => 0, 'app' => '', 'vm' => '', 'lb' => ''], static fn() => null);
    try {
        $c->connect(static fn() => null);
        $r = 'connected';
    } catch (MuDesk\Rdp\ProtocolException $e) {
        $r = $e->key;
    } finally {
        isset($c->t) && $c->disconnect();
        isset($c->t) && $c->disconnect();                     // (twice: nothing thrown, as rdp.php's finally does)
    }
    return [$r, $c->kerberos];
})();
$nlaKSaid[] = preg_replace('/^(bad kerberos).*/', '$1', trim((string)fgets($nlaKPipes[1])));
$nlaKSaid[] = trim((string)fgets($nlaKPipes[1]));
$nlaKOld = (static function () use ($nlaKPort): array {         // (a 2003 domain's computer: everything RC4)
    $c = new Client(['host' => 'viejo.test.local', 'ip' => '127.0.0.1', 'port' => $nlaKPort, 'user' => 'viejo', 'domain' => 'TEST.LOCAL', 'password' => 'Clave-Krb-1',
        'security' => 'nla', 'width' => 800, 'height' => 600, 'bpp' => 16, 'kbd' => 0x040A, 'clientName' => 'c', 'monitors' => [], 'channels' => [], 'audio' => false,
        'mic' => false, 'orders' => false, 'gfx' => 'auto', 'compression' => 0, 'perf' => 0, 'tzBias' => 0, 'app' => '', 'vm' => '', 'lb' => ''], static fn() => null);
    try {
        $c->connect(static fn() => null);
        $r = 'connected';
    } catch (MuDesk\Rdp\ProtocolException $e) {
        $r = $e->key;
    } finally {
        isset($c->t) && $c->disconnect();
    }
    return [$r, $c->kerberos];
})();
$nlaKSaid[] = trim((string)fgets($nlaKPipes[1]));
$nlaK2 = MuDesk\WindowsLogin::class;
proc_terminate($nlaK);
proc_close($nlaK);
check('NLA with Kerberos: credentials delegated (DNS domain, user@domain) with Windows\' tokens, the graphics pipeline taken; a wrong password stopped at the KDC; a ticket refused by the server: NTLM', [
    $nlaKRes, $nlaKSaid, $nlaKOther, $nlaKOld],
    [[['closed', true, true], ['closed', true, true], ['auth_failed', false, false]], ['ok creds kerberos ana password ok', 'ok creds kerberos ana password ok', 'drop',
      'bad kerberos', 'ok creds', 'ok creds kerberos viejo password ok'], ['closed', false], ['closed', true]]);
$cfgProp->setValue(null, $cfg0);
MuDesk\Settings::save(['kerberos_rc4' => false]);
proc_terminate($kdcP);
proc_close($kdcP);

// the channel binding of a gateway certificate signed with RSASSA-PSS and SHA-384: hashed with SHA-384, the hash its
// signature's parameters name (before, with SHA-256: a gateway with Extended Protection refused the sign-in)
$pssPem = (string)file_get_contents(__DIR__ . '/fixtures/pss-cert.pem');
$pssDer = (string)base64_decode((string)preg_replace('~-----[^-]+-----|\s+~', '', $pssPem));
$pssApp = 'tls-server-end-point:' . hash('sha384', $pssDer, true);
check('RD Gateway: the channel binding of a certificate signed with RSASSA-PSS and SHA-384', [
    MuDesk\Rdp\Gateway::bindings(openssl_x509_read($pssPem)) === md5(pack('VVVVV', 0, 0, 0, 0, strlen($pssApp)) . $pssApp, true)], [true]);

// a session through the gateway reaches the computer behind it, with TLS and NLA through the tunnel (before, the client's end
// of the tunnel took no TLS: every session through a gateway failed there); the X.224 request carries the broker's routing
// token; the gateway's certificate pinned; audited
$nlaGw = proc_open([PHP_BINARY, __DIR__ . '/fixtures/nla_server.php', 'ana', 'TEST', 'Clave-Gw-1', $cnf, 'x224'], [1 => ['pipe', 'w'], 2 => ['file', $nul, 'w']], $nlaGwPipes);
$nlaGwPort = (int)trim((string)fgets($nlaGwPipes[1]));
$rdg = proc_open([PHP_BINARY, __DIR__ . '/fixtures/rdg_server.php', 'ana', 'TEST', 'Clave-Gw-1', $cnf, (string)$nlaGwPort], [1 => ['pipe', 'w'], 2 => ['file', $nul, 'w']], $rdgPipes);
$rdgPort = (int)trim((string)fgets($rdgPipes[1]));
$gc = MuDesk\Connections::save($wu['id'], ['host' => 'pc.test', 'port' => 3389, 'user' => 'ana', 'domain' => 'TEST', 'gw' => "127.0.0.1:$rdgPort",
    'lb' => 'tsv://MS Terminal Services Plugin.1.Prueba', 'clipboard' => false, 'drive' => false, 'printer' => false, 'audio' => false], null);
$gs = $wreq('rdp.php?a=stream', json_encode(['c' => $gc['id'], 'sid' => bin2hex(random_bytes(16)), 'w' => 800, 'h' => 600, 'password' => 'Clave-Gw-1']));
$x224    = (string)hex2bin(substr(trim((string)fgets($nlaGwPipes[1])), 5));
$nlaSaid = trim((string)fgets($nlaGwPipes[1]));
$wres($gs);
$gwLines = [trim((string)fgets($rdgPipes[1])), trim((string)fgets($rdgPipes[1]))];
proc_terminate($nlaGw);
proc_close($nlaGw);
proc_terminate($rdg);
proc_close($rdg);
$gwPinned = MuDesk\Connections::all($wu['id'])[$gc['id']]['certs']['gw:127.0.0.1'] ?? '';   // (kept apart from a broker's computers)
$connectLog = MuDesk\Audit::search(['event' => 'rdp_connect', 'user' => 'webuser'])['rows'][0] ?? [];
check('RD Gateway: a session through it reaches the computer behind it with TLS and NLA (the X.224 request with the broker\'s routing token); the gateway\'s certificate pinned; audited', [
    substr($x224, 0, 2), str_contains($x224, "tsv://MS Terminal Services Plugin.1.Prueba\r\n"), str_contains($x224, 'mstshash'), $nlaSaid, $gwLines, strlen($gwPinned),
    [$connectLog['gateway'] ?? null, $connectLog['routing_token'] ?? null]],
    ["\x03\x00", true, false, 'ok creds', ['auth ok bindings ok', 'channel pc.test:3389'], 64, ['127.0.0.1', true]]);
// a gateway never seen is asked about as a computer is (cert_new, with its name) before the user's password goes to it, and
// nothing is pinned until the user trusts it (before 1.2, trusted at once)
$askCerts(true);
$rdgN = proc_open([PHP_BINARY, __DIR__ . '/fixtures/rdg_server.php', 'ana', 'TEST', 'Clave-Gw-1', $cnf, (string)$nlaGwPort], [1 => ['pipe', 'w'], 2 => ['file', $nul, 'w']], $rdgNPipes);
$rdgNPort = (int)trim((string)fgets($rdgNPipes[1]));
$gcN = MuDesk\Connections::save($wu['id'], ['host' => 'pc.test', 'port' => 3389, 'user' => 'ana', 'domain' => 'TEST', 'gw' => "127.0.0.1:$rdgNPort",
    'clipboard' => false, 'drive' => false, 'printer' => false, 'audio' => false], null);
[, $gwNewBody] = $wres($wreq('rdp.php?a=stream', json_encode(['c' => $gcN['id'], 'sid' => bin2hex(random_bytes(16)), 'w' => 800, 'h' => 600, 'password' => 'Clave-Gw-1'])));
$gwNew = [$endsOf($gwNewBody), str_contains($gwNewBody, '"host":"127.0.0.1"'), MuDesk\Connections::all($wu['id'])[$gcN['id']]['certs']['gw:127.0.0.1'] ?? ''];
$askCerts(false);
proc_terminate($rdgN);
proc_close($rdgN);
MuDesk\Connections::delete($wu['id'], $gcN['id']);
check('RD Gateway: one never seen asked about before the password goes to it (its name said), nothing pinned meanwhile', $gwNew, [['cert_new'], true, '']);
// a connection broker's redirection followed by the web gateway: its computer's first address does not answer, so the next one
// it named is taken (before, only the first was tried: the session failed); the next connection carries the session, the user
// and the password the broker gave; the target's certificate the broker vouched for is the one taken there (pinned for it), and
// another one is refused as a changed certificate, even on a first use; audited
MuDesk\Settings::save(['targets' => ['mode' => 'list', 'list' => ['127.0.0.1', '127.0.0.2', '127.0.0.3']]]);
$brokerRun = static function (string $mode, ?string $pinned = null, ?string $fixed = null) use ($cnf, $nul, $wu, $wreq, $wres, $endsOf): array {
    $bp   = proc_open([PHP_BINARY, __DIR__ . '/fixtures/broker_server.php', $cnf, $mode], [1 => ['pipe', 'w'], 2 => ['file', $nul, 'w']], $bpipes);
    $port = (int)trim((string)fgets($bpipes[1]));
    $bc   = MuDesk\Connections::save($wu['id'], ['host' => '127.0.0.1', 'port' => $port, 'user' => 'otro', 'security' => 'tls',
        'clipboard' => false, 'drive' => false, 'printer' => false, 'audio' => false], null);
    $pinned !== null && MuDesk\Connections::trust($wu['id'], $bc, $pinned, '127.0.0.2');   // the target's, pinned before
    $fixed !== null && MuDesk\Connections::fixCert('127.0.0.2', $port, $fixed, 'admin');    // the target's, fixed by an administrator
    [, $body] = $wres($wreq('rdp.php?a=stream', json_encode(['c' => $bc['id'], 'sid' => bin2hex(random_bytes(16)), 'w' => 800, 'h' => 600])));
    $lines = [];
    while (($l = fgets($bpipes[1])) !== false && ($l = trim($l)) !== 'done') {
        $lines[] = $l;
    }
    proc_close($bp);
    $certs = array_keys(MuDesk\Connections::all($wu['id'])[$bc['id']]['certs'] ?? []);
    MuDesk\Connections::delete($wu['id'], $bc['id']);
    $fixed !== null && MuDesk\Connections::fixCert('127.0.0.2', $port, '', 'admin');
    return [$lines, $endsOf($body), $certs];
};
$brokerOk    = $brokerRun('');
$redirLog    = array_slice(MuDesk\Audit::search(['event' => 'rdp_redirect', 'user' => 'webuser'])['rows'], 0, 2);
$vouchLog    = MuDesk\Audit::search(['event' => 'cert_trusted', 'user' => 'webuser'])['rows'][0] ?? [];
$brokerWrong = $brokerRun('wrongcert');
$brokerPinned = $brokerRun('', str_repeat('ab', 32));
$brokerFixed = $brokerRun('', null, str_repeat('cd', 32));
$brokerRdstls = $brokerRun('rdstls');
$brokerLate = $brokerRun('late');
$lateLog = array_values(array_filter(MuDesk\Audit::search(['event' => 'rdp_redirect', 'user' => 'webuser'])['rows'],
    static fn(array $r): bool => isset($r['session'])))[0] ?? [];   // (its redirection, not the next address taken)
$brokerRdstlsBad = $brokerRun('rdstlsbad');
$brokerLoop = $brokerRun('loop');
$brokerLateLoop = $brokerRun('lateloop');
$brokerFqdn = $brokerRun('fqdn');
$fqdnLog = array_slice(MuDesk\Audit::search(['event' => 'rdp_redirect', 'user' => 'webuser'])['rows'], 0, 2);
MuDesk\Settings::save(['targets' => ['mode' => 'list', 'list' => ['127.0.0.1']]]);
// ... a redirection after the activation audited as late, one when connecting not; the computers a broker names that may
// not be reached (the list holds the broker alone): refused, said so, the bytes of the broker's connection counted once in
// the audit (before, twice: added when it redirected, and again at the end)
$brokerDenied = $brokerRun('count');
$deniedEnd    = MuDesk\Audit::search(['event' => 'rdp_end', 'user' => 'webuser'])['rows'][0] ?? [];
$deniedSent   = (int)substr($brokerDenied[0][1] ?? 'sent 0', 5);
check('connection broker: a redirection after the activation audited as late, one when connecting not; a computer it names that may not be reached refused, the bytes of its connection counted once',
    [$lateLog['late'] ?? null, $redirLog[1]['late'] ?? null, $brokerDenied[0][0] ?? null, $brokerDenied[1], $deniedSent > 300, $deniedEnd['in'] ?? null],
    [true, null, 'redirect sent', ['target_denied'], true, $deniedSent]);
check('connection broker: followed by the web gateway to the next address when the first does not answer, with its session, user and password; the target\'s certificate it vouched for taken, another refused; audited', [
    $brokerOk[0], $brokerOk[2], array_map(static fn(array $r): array => [$r['to'] ?? null, $r['instead_of'] ?? null, $r['reason'] ?? null], $redirLog),
    [$vouchLog['host'] ?? null, $vouchLog['vouched_by_broker'] ?? null], $brokerWrong[0], $brokerWrong[1], $brokerWrong[2]],
    [['redirect sent', 'target Cookie: mstshash=ana cluster=13 session=42 user=ana password=Galleta-1'], ['127.0.0.2'],
     [['127.0.0.2', '127.0.0.3', 'unreachable'], ['127.0.0.3', null, null]], ['127.0.0.2', true],
     ['redirect sent', 'target gone'], ['cert_changed'], []]);
// ... the certificate the broker vouches for never replaces one pinned for that computer before: a changed certificate (the
// user decides; a broker taken over could vouch for an impostor)
check('connection broker: its word does not replace a certificate pinned before', [$brokerPinned[1], $brokerPinned[2]], [['cert_changed'], ['127.0.0.2']]);
check('connection broker: the computer it sends to accepts only the certificate an administrator fixed for it (not the word of the broker, nor the user)',
    [$brokerFixed[1], $brokerFixed[2], MuDesk\Connections::fixedCerts()], [['cert_unexpected'], [], []]);
// ... a password the broker encrypted for its computer (LB_PASSWORD_IS_PK_ENCRYPTED): given to it with RDSTLS, as it came,
// with the redirection's GUID (before, the session went on without it, to the target's logon screen); refused by the target:
// said so (RDSTLS_RESULT_LOGON_FAILURE: a wrong password)
// ... a redirection after the session was activated (a session host that finds the user's session elsewhere after the
// logon): followed the same way, to the computer the broker named, with its session, user and password
check('connection broker: a redirection after the activation followed, with its session, user and password', $brokerLate[0],
    ['activated', 'redirect sent', 'target Cookie: mstshash=ana cluster=13 session=42 user=ana password=Galleta-1']);
check('connection broker: a password encrypted for its computer given there with RDSTLS, with the redirection GUID; refused, said so', [
    $brokerRdstls[0], $brokerRdstlsBad[0], $brokerRdstlsBad[1]],
    [['redirect sent', 'rdstls requested=5 guid=ok user=ana password=ok', 'target Cookie: mstshash=ana cluster=13 session=42 user=ana password='],
     ['redirect sent', 'rdstls requested=5 guid=ok user=ana password=ok'], ['auth_failed']]);
// a connection whose security is NLA, sent by a broker with a password encrypted for the computer: RDSTLS taken there (the
// broker's own sign-in was NLA; before, the computer choosing RDSTLS was refused as no NLA, and the session ended)
$rdP    = proc_open([PHP_BINARY, __DIR__ . '/fixtures/broker_server.php', $cnf, 'rdstls'], [1 => ['pipe', 'w'], 2 => ['file', $nul, 'w']], $rdPipes);
$rdPort = (int)trim((string)fgets($rdPipes[1]));
$rdC    = new Client(['host' => 'rdsh2', 'ip' => '127.0.0.2', 'port' => $rdPort, 'user' => 'ana', 'domain' => '', 'password' => '', 'security' => 'nla',
    'rdstls' => ['guid' => 'g', 'password' => 'p'], 'width' => 800, 'height' => 600, 'bpp' => 16, 'kbd' => 0x040A, 'clientName' => 'c', 'monitors' => [],
    'channels' => [], 'audio' => false, 'mic' => false, 'orders' => false, 'gfx' => 'auto', 'compression' => 0, 'perf' => 0, 'tzBias' => 0, 'app' => '',
    'vm' => '', 'lb' => ''], static fn() => null);
try {
    $rdC->connect(static fn() => null);
    $rdR = 'connected';
} catch (MuDesk\Rdp\ProtocolException $e) {
    $rdR = $e->key;
} finally {
    isset($rdC->t) && $rdC->disconnect();
}
usleep(200000);
proc_terminate($rdP);
$rdSaid = (string)stream_get_contents($rdPipes[1]);
proc_close($rdP);
check('connection broker: a connection whose security is NLA takes RDSTLS at the computer the broker sends it to',
    [$rdR !== 'nla_unavailable', str_contains($rdSaid, 'rdstls requested=5 guid=bad user=ana password=bad')], [true, true]);
// ... a computer named by its address and its name (TargetFQDN): the name taken for it (Kerberos, TLS, its certificate's
// pin), the address checked connected to (before, the address: Kerberos had no realm for it, NTLM instead)
check('connection broker: a computer named by its address and its name: the name taken, the address connected to', [$brokerFqdn[0], $brokerFqdn[2],
    array_map(static fn(array $r): array => [$r['to'] ?? null, $r['target_ip'] ?? null, $r['instead_of'] ?? null], $fqdnLog)],
    [['redirect sent', 'target Cookie: mstshash=ana cluster=13 session=42 user=ana password=Galleta-1'], ['rdsh2.empresa.test'],
     [['rdsh2.empresa.test', '127.0.0.2', 'rdsh2.empresa.test'], ['rdsh2.empresa.test', '127.0.0.3', null]]]);
// ... a broker that sends the client on and on (each computer redirecting it again): followed 3 times, then ended, when
// connecting and once the session is up
check('connection broker: redirections in a loop followed 3 times, then ended (when connecting, and after the activation)',
    [$brokerLoop[0], $brokerLoop[1], $brokerLateLoop[0], $brokerLateLoop[1]],
    [array_fill(0, 4, 'redirect sent'), ['redirect'], array_merge(...array_fill(0, 4, ['activated', 'redirect sent'])), ['redirect']]);
// a gateway's consent message through the web gateway (rdp.php): shown on the page (a status with the text), the session
// waiting for its answer (message 17 from the page), then going on when accepted (the computer reached) or ending as
// declined; each answer audited
$consentRun = static function (bool $accept) use ($cnf, $nul, $wu, $wreq, $endsOf): array {
    $nlaC = proc_open([PHP_BINARY, __DIR__ . '/fixtures/nla_server.php', 'consent', 'TEST', 'Clave-Gw-1', $cnf], [1 => ['pipe', 'w'], 2 => ['file', $nul, 'w']], $nlaCPipes);
    $nlaCPort = (int)trim((string)fgets($nlaCPipes[1]));
    $rdgC = proc_open([PHP_BINARY, __DIR__ . '/fixtures/rdg_server.php', 'ana', 'TEST', 'Clave-Gw-1', $cnf, (string)$nlaCPort], [1 => ['pipe', 'w'], 2 => ['file', $nul, 'w']], $rdgCPipes);
    $rdgCPort = (int)trim((string)fgets($rdgCPipes[1]));
    $cc  = MuDesk\Connections::save($wu['id'], ['host' => 'pc.test', 'port' => 3389, 'user' => 'consent', 'domain' => 'TEST', 'gw' => "127.0.0.1:$rdgCPort",
        'clipboard' => false, 'drive' => false, 'printer' => false, 'audio' => false], null);
    $sid = bin2hex(random_bytes(16));
    $s   = $wreq('rdp.php?a=stream', json_encode(['c' => $cc['id'], 'sid' => $sid, 'w' => 800, 'h' => 600, 'password' => 'Clave-Gw-1']));
    $buf = $token = '';
    $asked = null;
    for ($end = microtime(true) + 20; microtime(true) < $end && $asked === null;) {
        $d = fread($s, 8192);
        if ($d === false || $d === '') {
            break;
        }
        $buf .= $d;
        if ($token === '' && preg_match('~"token":"([0-9a-f]+)"~', $buf, $m)) {
            $token = $m[1];
        }
        if (preg_match('~"gateway_message":"((?:[^"\\\\]|\\\\.)*)","consent":true~', $buf, $m)) {
            $asked = json_decode('"' . $m[1] . '"');
        }
    }
    // the page's answer, as rdp.php?a=send queues it (that request checked elsewhere: the test's web server takes one
    // request at a time, and this one waits on the stream)
    $sentCode = $token !== '' && MuDesk\Sessions::send($sid, pack('V', 0) . "\x11" . ($accept ? "\x01" : "\x00")) ? 204 : 0;
    $buf .= (string)stream_get_contents($s);
    fclose($s);
    $nlaSaid = $accept ? trim((string)fgets($nlaCPipes[1])) : '';
    foreach ([$nlaC, $rdgC] as $p) {
        proc_terminate($p);
        proc_close($p);
    }
    MuDesk\Connections::delete($wu['id'], $cc['id']);
    $log = MuDesk\Audit::search(['event' => 'gateway_consent', 'user' => 'webuser'])['rows'][0] ?? [];
    return [$asked, $sentCode, $endsOf(substr($buf, (int)strpos($buf, "\r\n\r\n") + 4)), $nlaSaid, $log['accepted'] ?? null];
};
check('RD Gateway: its consent message through the web gateway: shown on the page, the session waiting for the answer, going on when accepted and ending when declined; audited', [
    $consentRun(true), $consentRun(false)],
    [["Uso solo autorizado.\nTodo queda registrado.", 204, ['closed'], 'ok creds', true],
     ["Uso solo autorizado.\nTodo queda registrado.", 204, ['gateway_consent'], '', false]]);
// the certificates of a connection's gateway and of its broker's computers: kept when the user saves the connection again or the
// administrator changes it (before, lost on every save: trusted again silently on the next connection); forgotten with "Forget
// certificate", and when it moves to another computer or gateway (also one of the administrator's, for every user); the latest
// trusted kept last
MuDesk\Connections::setCertFor($wu['id'], $gc['id'], 'RDSH2.empresa.local', 'CC:DD');
MuDesk\Connections::setCertFor($wu['id'], $gc['id'], '127.0.0.1', $gwPinned, true);
$certsOf = static fn(string $id): array => MuDesk\Connections::all($wu['id'])[$id]['certs'] ?? ['none'];
$gcIn    = ['id' => $gc['id'], 'host' => 'pc.test', 'port' => 3389, 'user' => 'ana', 'domain' => 'TEST', 'gw' => "127.0.0.1:$rdgPort", 'lb' => 'tsv://x'];
MuDesk\Connections::save($wu['id'], $gcIn + ['name' => 'Granja'], null);
$certsSaved = array_keys($certsOf($gc['id']));
MuDesk\Connections::adminUpdate($wu['id'], $gcIn + ['name' => 'Granja 2'], false);
$certsAdmin = array_keys($certsOf($gc['id']));
// (a farm of 40 computers: the 32 latest kept, the gateway's never dropped; one of them named as the gateway is not it)
for ($i = 0; $i < 40; $i++) {
    MuDesk\Connections::setCertFor($wu['id'], $gc['id'], "rdsh$i.empresa.local", 'EE:' . $i);
}
MuDesk\Connections::setCertFor($wu['id'], $gc['id'], '127.0.0.1', 'FF:FF');
$certsFarm = $certsOf($gc['id']);
$certsFarm = [count($certsFarm), $certsFarm['gw:127.0.0.1'] === $gwPinned, $certsFarm['127.0.0.1'] ?? null, isset($certsFarm['rdsh8.empresa.local']), isset($certsFarm['rdsh9.empresa.local'])];
MuDesk\Connections::setCert($wu['id'], $gc['id'], '');
$certsForgot = $certsOf($gc['id']);
MuDesk\Connections::setCertFor($wu['id'], $gc['id'], '127.0.0.1', $gwPinned, true);
MuDesk\Connections::save($wu['id'], ['gw' => 'otra-puerta.test'] + $gcIn, null);
$certsMoved = $certsOf($gc['id']);
$gm = MuDesk\Connections::saveManaged(['name' => 'Granja', 'host' => 'agente.test', 'port' => 3389, 'user' => '', 'domain' => '', 'gw' => 'gw.test'], [$wu['id']]);
MuDesk\Connections::setCertFor($wu['id'], $gm['id'], 'gw.test', 'AB:CD', true);
$certsManaged = array_keys($certsOf($gm['id']));
MuDesk\Connections::saveManaged(['id' => $gm['id'], 'name' => 'Granja', 'host' => 'agente.test', 'port' => 3389, 'user' => '', 'domain' => '', 'gw' => 'gw2.test'], [$wu['id']]);
$certsManagedMoved = $certsOf($gm['id']);
MuDesk\Connections::deleteManaged($gm['id']);
check('certificates of a gateway and a broker\'s computers: kept on saving again, forgotten with the certificate and when the connection moves; a farm\'s 32 latest kept, the gateway\'s apart and never dropped', [
    $certsSaved, $certsAdmin, $certsForgot, $certsMoved, $certsManaged, $certsManagedMoved, $certsFarm],
    [['gw:127.0.0.1', 'rdsh2.empresa.local'], ['gw:127.0.0.1', 'rdsh2.empresa.local'], [], [], ['gw:gw.test'], [], [33, true, 'FF:FF', false, true]]);
MuDesk\Connections::delete($wu['id'], $gc['id']);

// connections saved before the gateway existed (no "gw"): the same target (before, taken for another computer: renaming one
// dropped its saved password and certificate, a user who may not make connections could not save it at all, and renaming one
// of the administrator's dropped what every user saved for it); the load balance info kept as it is by such a user, and of 238
// characters at most (the X.224 request's length is one byte); a gateway kept in one form; control characters out of the
// names (a line break went into an exported .rdp file); a computer name with a line break refused
$dek2 = random_bytes(32);
$old  = MuDesk\Connections::save($wu['id'], ['host' => 'pc.antiguo', 'port' => 3389, 'user' => 'ana', 'name' => 'Antigua', 'password' => 'clave-vieja'], $dek2);
MuDesk\Connections::setCert($wu['id'], $old['id'], 'AA:BB');
$oldM = MuDesk\Connections::saveManaged(['name' => 'Antigua de todos', 'host' => 'rds.antiguo', 'port' => 3389, 'user' => '', 'domain' => ''], [$wu['id']]);
MuDesk\Connections::save($wu['id'], ['id' => $oldM['id'], 'password' => 'clave-suya'], $dek2);
$newKeys = array_flip(['gw', 'lb', 'certs', 'wol', 'wol_ip', 'vm', 'vm_enhanced', 'record', 'touch', 'mic']);
MuDesk\Store::update('connections/' . $wu['id'], static function (array &$d) use ($old, $newKeys): void {
    $d[$old['id']] = array_diff_key($d[$old['id']], $newKeys);       // as 1.0 saved it
});
MuDesk\Store::update('managed_connections', static function (array &$d) use ($oldM, $newKeys): void {
    $d[$oldM['id']] = array_diff_key($d[$oldM['id']], $newKeys);
});
$oldIn    = ['id' => $old['id'], 'host' => 'pc.antiguo', 'port' => 3389, 'user' => 'ana', 'domain' => ''];
$oldSaved = MuDesk\Connections::save($wu['id'], $oldIn + ['name' => 'Antigua 2'], null, false);
$oldKept  = MuDesk\Connections::get($wu['id'], $old['id']);
MuDesk\Connections::adminUpdate($wu['id'], $oldIn + ['name' => 'Antigua 3'], false);
$oldAdmin = MuDesk\Connections::get($wu['id'], $old['id']);
MuDesk\Connections::saveManaged(['id' => $oldM['id'], 'name' => 'Antigua de todos 2', 'host' => 'rds.antiguo', 'port' => 3389, 'user' => '', 'domain' => ''], [$wu['id']]);
$oldMKept = MuDesk\Connections::get($wu['id'], $oldM['id']);
$lbOwn    = MuDesk\Connections::save($wu['id'], $oldIn + ['name' => 'Antigua', 'lb' => 'tsv://MS Terminal Services Plugin.1.Otra'], null, false);
$lbLong   = MuDesk\Connections::save($wu['id'], ['host' => 'pc.x', 'lb' => str_repeat('t', 239)], null);
$lbMax    = MuDesk\Connections::save($wu['id'], ['host' => 'pc.x', 'lb' => str_repeat('t', 238)], null);
$gwForms  = array_map(static fn(string $gw) => ($c = MuDesk\Connections::save($wu['id'], ['host' => 'pc.x', 'gw' => $gw], null)) && is_array($c) ? $c['gw'] : $c,
    ['RDG.Empresa.com:00443', 'rdg.empresa.com:443', '[::1]:8443', '[::1]:443', '::1', 'rdg.empresa.com:8443']);
$ctl      = MuDesk\Connections::save($wu['id'], ['host' => 'pc.x', 'name' => "Ofi\r\ncina", 'group' => "G\x07", 'user' => "ana\nfull address:s:otro", 'domain' => "DOM\r"], null);
$hostNl   = MuDesk\Connections::save($wu['id'], ['host' => "pc.x\n"], null);
foreach (MuDesk\Connections::own($wu['id']) as $id => $c) {
    in_array($c['host'], ['pc.x', 'pc.antiguo'], true) && MuDesk\Connections::delete($wu['id'], (string)$id);
}
MuDesk\Connections::deleteManaged($oldM['id']);
check('connections from before the gateway: the same target, their password and certificate kept; load balance info locked and bounded; a gateway in one form; no control characters', [
    is_array($oldSaved), MuDesk\Connections::password($oldKept, $dek2), $oldKept['cert'], MuDesk\Connections::password($oldAdmin, $dek2), $oldAdmin['cert'],
    MuDesk\Connections::password($oldMKept, $dek2), $lbOwn, $lbLong, is_array($lbMax), $gwForms,
    is_array($ctl) ? [$ctl['name'], $ctl['group'], $ctl['user'], $ctl['domain']] : $ctl, $hostNl],
    [true, 'clave-vieja', 'AA:BB', 'clave-vieja', 'AA:BB', 'clave-suya', 'conns_locked', 'lb', true,
     ['rdg.empresa.com', 'rdg.empresa.com', '[::1]:8443', '[::1]', 'gw', 'rdg.empresa.com:8443'], ['Oficina', 'G', 'anafull address:s:otro', 'DOM'], 'host']);

// sharing a session: a link (only its secret's SHA-256 in the session's file); a guest's view over the web without an account,
// sent the session from its admission on; the guest's input with control on a lane of its own, refused for view only and for
// anything but keyboard, mouse and touch; a revoked link lets its guest go at once; the owner's page cannot send what is not
// the browser's; the owner makes, lists and revokes links (not of another user's session, a RemoteApp or a queue-file one)
$shSid  = bin2hex(random_bytes(16));
$shSess = MuDesk\Sessions::register($shSid, ['uid' => $wu['id'], 'user' => 'webuser', 'conn' => 'Shared PC', 'host' => '127.0.0.1', 'token' => hash('sha256', 'owner-token')]);
$shView = MuDesk\Sessions::share($shSid, 'view');
$shCtl  = MuDesk\Sessions::share($shSid, 'control');
$shFile = (string)file_get_contents("$tmp/data/run/$shSid.json");
$anon   = static function (string $query, string $body, string $header = '') use ($wport) {   // a browser not signed in
    $s = fsockopen('127.0.0.1', $wport, $en, $es, 5);
    fwrite($s, "POST /$query HTTP/1.0\r\nHost: 127.0.0.1\r\nUser-Agent: test\r\n{$header}Content-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n\r\n$body");
    return $s;
};
$pump   = static function (callable $until) use ($shSess): array {           // the session's loop: what it gets, until $until (5 s)
    $got = [];
    for ($end = microtime(true) + 5; microtime(true) < $end && !$until($got); usleep(20000)) {
        array_push($got, ...$shSess->read());
    }
    return $got;
};
$frames = static function (string $b): array {                                 // [type, payload] of a stream
    $f = [];
    for ($o = 0; $o + 5 <= strlen($b); $o += 5 + $n) {
        ['t' => $t, 'n' => $n] = unpack('Ct/Vn', $b, $o);
        $f[] = [$t, substr($b, $o + 5, $n)];
    }
    return $f;
};
[$badSt, $badBody] = $wres($anon('rdp.php?a=watch', json_encode(['link' => "$shSid.wrong-secret"])));
$g       = $anon('rdp.php?a=watch', json_encode(['link' => "$shSid.{$shView['secret']}"]));
$joinEv  = $pump(static fn($got) => $got !== []);
$shSess->toViewers('not-yet');                                                  // (a guest not admitted yet is sent nothing)
$watchN  = [$shSess->viewerCount(), $shSess->admitViewers()];
$shSess->toViewers(pack('CV', 6, 0));                                           // what the session sends from then on
$okUn    = MuDesk\Sessions::unshare($shSid, $shView['id']);
$revEv   = $pump(static fn($got) => $got !== []);
$leftN   = $shSess->dropViewers($shView['id']);
[$gSt, $gBody] = $wres($g);
$gFrames = $frames($gBody);
$gStatus = json_decode($gFrames[1][1] ?? 'null', true);
check('sharing: only the secret\'s SHA-256 kept; a wrong link refused; a guest\'s view without an account, sent the session from its admission; revoked: let go', [
    str_contains($shFile, $shView['secret']), str_contains($shFile, hash('sha256', $shView['secret'])), MuDesk\Sessions::findShare("$shSid.{$shCtl['secret']}")[2] ?? null,
    MuDesk\Sessions::findShare("$shSid.wrong-secret"), $badSt, json_decode($badBody, true)['error'] ?? null,
    $joinEv, $watchN, $gSt, array_column($gFrames, 0), $gStatus, $okUn, $revEv, $leftN, MuDesk\Sessions::unshare($shSid, $shView['id'])],
    [false, true, 'control', null, 404, 'share_ended', ["\x21" . $shView['id']], [0, 1], 200, [0, 3, 6],
     ['watch' => 'view', 'owner' => 'webuser', 'conn' => 'Shared PC', 'sid' => $shSid, 'recording' => false], true, ["\x20" . $shView['id']], 0, false]);
$shareLog = static fn(string $ev): array => array_map(static fn($r) => [$r['owner'] ?? null, $r['share'] ?? null, $r['mode'] ?? null],
    MuDesk\Audit::search(['event' => $ev])['rows']);
check('sharing: a guest joining and leaving is audited (whose session, which link)', [$shareLog('share_join')[0] ?? null, $shareLog('share_leave')[0] ?? null],
    [['webuser', $shView['id'], 'view'], ['webuser', $shView['id'], 'view']]);
// a guest's input: a mouse move (input message, 1 event); with control only, on its own lane; nothing but keyboard, mouse, touch
$gsend = static fn(string $secret, string $payload): int => $wres($anon("rdp.php?a=send&sid=$shSid", pack('V', 7) . $payload, "X-MuDesk-Share: $secret\r\n"))[0];
$mouse = "\x01\x01\x20\x00\x08\x10\x00\x20\x00";
$shView2 = MuDesk\Sessions::share($shSid, 'view');                            // (a valid one: the first was revoked)
$gst   = [$gsend($shView2['secret'], $mouse), $gsend($shCtl['secret'], "\x02\x00\x04\x00\x03"), $gsend($shCtl['secret'], "\x09"), $gsend('wrong', $mouse),
          $gsend($shCtl['secret'], "\x06"), $gsend($shCtl['secret'], $mouse)];
$gIn   = $pump(static fn($got) => $got !== []);
$own   = [$wres($anon("rdp.php?a=send&sid=$shSid", pack('V', 0) . "\x21" . $shCtl['id'], "X-MuDesk-Token: owner-token\r\n"))[0],
          $wres($anon("rdp.php?a=send&sid=$shSid", pack('V', 0) . "\x22" . $mouse, "X-MuDesk-Token: owner-token\r\n"))[0],
          $wres($anon("rdp.php?a=send&sid=$shSid", pack('V', 0) . "\x06", "X-MuDesk-Token: owner-token\r\n"))[0]];
$ownIn = $pump(static fn($got) => $got !== []);
check('sharing: a guest with control sends keyboard, mouse and touch on its own lane (with its link, whose control it is); view only, other messages, a wrong secret refused; the owner cannot pose as one', [
    $gst, $gIn, MuDesk\Rdp\Client::mouseAt(substr($mouse, 2)), MuDesk\Rdp\Client::mouseAt("\x00\x1e\x20\x00\x08\x01\x00\x02\x00\x00\x9e\x60"), MuDesk\Rdp\Client::mouseAt("\x00\x1e"),
    $own, $ownIn],
    [[404, 404, 404, 404, 404, 204], ["\x22" . $shCtl['id'] . $mouse], pack('vv', 16, 32), pack('vv', 1, 2), null, [404, 404, 204], ["\x06"]]);
// the owner's out-of-order lane (Sessions::OUT_OF_BAND): its camera's pictures and its folder's answers only, each taken as
// it comes, with no sequence number (no key waits behind a picture); anything else on it, or on the guests' lane, refused
$laneSend = static fn(int $seq, string $payload): int => $wres($anon("rdp.php?a=send&sid=$shSid", pack('V', $seq) . $payload, "X-MuDesk-Token: owner-token\r\n"))[0];
$lane = [$laneSend(0xFFFFFFFF, "\x12JPEG"), $laneSend(0xFFFFFFFF, "\x14\x03\x00\x00\x00"), $laneSend(0xFFFFFFFF, "\x15\x01"), $laneSend(0xFFFFFFFF, "\x16\x01\x00PIECE"),
         $laneSend(0xFFFFFFFF, "\x06"), $laneSend(0xFFFFFFFF, "\x13\x00"), $laneSend(0xFFFFFFFE, $mouse), $laneSend(0xFFFFFFFE, "\x12JPEG"), $laneSend(0xFFFFFFFE, "\x16\x01\x00PIECE")];
$laneIn = $pump(static fn($got) => count($got) >= 4);
check('the browser\'s out-of-order lane: the camera\'s pictures, the folder\'s answers and a recording\'s pieces only, taken as they come; nothing else on it, nor on the guests\' lane',
    [$lane, $laneIn], [[204, 204, 204, 204, 404, 404, 404, 404, 404], ["\x12JPEG", "\x14\x03\x00\x00\x00", "\x15\x01", "\x16\x01\x00PIECE"]]);
// a link for a while: refused past its time, which the session finds (to let its guests go); a guest given control that has
// not used it for the administrator's minutes loses it: its link's mode is view from then on (its input refused), and its
// guests are told in their stream (not those of another link); 0 minutes: never. The setting: 15 minutes as it comes
$tSid  = bin2hex(random_bytes(16));
$tSess = MuDesk\Sessions::register($tSid, ['uid' => $wu['id'], 'user' => 'webuser', 'conn' => 'Por un rato', 'host' => '127.0.0.1', 'token' => hash('sha256', 't')]);
$tA    = MuDesk\Sessions::share($tSid, 'control', 15);
$tB    = MuDesk\Sessions::share($tSid, 'view');
$tC    = MuDesk\Sessions::share($tSid, 'control');
$tExp  = (int)(MuDesk\Sessions::info($tSid)['shares'][$tA['id']]['expires'] ?? 0) - time();
$tj    = json_decode((string)file_get_contents("$tmp/data/run/$tSid.json"), true);
$tj['shares'][$tA['id']]['expires'] = time() - 1;
file_put_contents("$tmp/data/run/$tSid.json", json_encode($tj));
$tLink = [$tExp >= 895 && $tExp <= 900, MuDesk\Sessions::findShare("$tSid.{$tA['secret']}"), MuDesk\Sessions::expiredShares($tSid), MuDesk\Sessions::findShare("$tSid.{$tB['secret']}")[2] ?? null];
$cB = MuDesk\Sessions::watch($tSid, $tB['id']);
$cC = MuDesk\Sessions::watch($tSid, $tC['id']);
for ($end = microtime(true) + 5, $joined = 0; microtime(true) < $end && $joined < 2; usleep(20000)) {
    $joined += count($tSess->read());
}
$tSess->admitViewers();
$at    = [$tC['id'] => time() - 901, 'f00df00df00df00d' => time() - 10];
$kept  = [$tSess->endIdleControls($tSid, $at, time(), 0, static fn(): string => 'X'), count($at)];   // (0: never)
$ended = $tSess->endIdleControls($tSid, $at, time(), 900, static fn(string $id): string => "ENDED:$id");
$readOf = static function ($c): string {
    stream_set_blocking($c, false);
    $r = '';
    for ($end = microtime(true) + 1; microtime(true) < $end; usleep(20000)) {
        $r .= (string)fread($c, 65536);
    }
    return $r;
};
$tSeen = [$readOf($cC), $readOf($cB)];
$tCtl  = [MuDesk\Sessions::findShare("$tSid.{$tC['secret']}")[2] ?? null, MuDesk\Sessions::controlEnd($tSid, $tC['id']), MuDesk\Sessions::controlEnd($tSid, $tB['id']),
    $wres($anon("rdp.php?a=send&sid=$tSid", pack('V', 7) . $mouse, "X-MuDesk-Share: {$tC['secret']}\r\n"))[0]];
fclose($cB);
fclose($cC);
$tSess->dropViewers();
MuDesk\Sessions::cleanup($tSid);
check('sharing: a link for a while refused past its time (the session finds it); a guest given control unused for the administrator\'s minutes watches on, told in its stream (not other links\' guests), its input refused; never with 0',
    [$tLink, $kept, $ended, array_keys($at), $tSeen, $tCtl, MuDesk\Settings::get('share_control_idle_minutes')],
    [[true, null, [$tA['id']], 'view'], [[], 2], [$tC['id']], ['f00df00df00df00d'], ["ENDED:{$tC['id']}", ''], ['view', false, false, 404], 15]);
// at most MAX_VIEWERS guests at once (the next ones are let go at once); all let go with the session
$many = [];
for ($i = 0; $i <= MuDesk\Sessions::MAX_VIEWERS; $i++) {
    $many[] = MuDesk\Sessions::watch($shSid, $shCtl['id']);
}
$pump(static fn($got) => count($got) >= MuDesk\Sessions::MAX_VIEWERS);
$admittedMax = $shSess->admitViewers();
$shSess->toViewers('x');
$gotX = array_count_values(array_map(static function ($c): string {   // the one past the limit: closed at once
    stream_set_timeout($c, 2);
    return (string)fread($c, 1);
}, $many));
ksort($gotX);
// the owner over the web: links made, listed and revoked; not for another user's session, nor a RemoteApp or queue-file one
$shApi = static fn(array $b): array => (static function (array $r): array { return [$r[0], json_decode($r[1], true)]; })($wres($wreq('api.php', json_encode($b))));
[$mkSt, $mk] = $shApi(['action' => 'session.share', 'sid' => $shSid, 'mode' => 'control']);
[, $listed]  = $shApi(['action' => 'session.shares', 'sid' => $shSid]);
[, $mk15]    = $shApi(['action' => 'session.share', 'sid' => $shSid, 'mode' => 'view', 'minutes' => 15]);
[, $mk7]     = $shApi(['action' => 'session.share', 'sid' => $shSid, 'mode' => 'view', 'minutes' => 7]);
$untilOf     = static fn(array $j, string $id): ?string => array_column($j['shares'] ?? [], 'expires', 'id')[$id] ?? null;
$shareTimes  = [abs(strtotime((string)$untilOf($mk15, $mk15['id'] ?? '')) - (time() + 900)) <= 5, $untilOf($mk7, $mk7['id'] ?? '')];
$shApi(['action' => 'session.unshare', 'sid' => $shSid, 'id' => $mk15['id'] ?? '']);
$shApi(['action' => 'session.unshare', 'sid' => $shSid, 'id' => $mk7['id'] ?? '']);
$pump(static fn($got) => count($got) >= 2);
[$unSt, $un] = $shApi(['action' => 'session.unshare', 'sid' => $shSid, 'id' => $mk['id'] ?? '']);
$unEv        = $pump(static fn($got) => $got !== []);
$otherSid    = bin2hex(random_bytes(16));
$otherSess   = MuDesk\Sessions::register($otherSid, ['uid' => 'someone-else', 'user' => 'other', 'conn' => 'X', 'host' => 'h', 'token' => '']);
$appSid      = bin2hex(random_bytes(16));
$appSess     = MuDesk\Sessions::register($appSid, ['uid' => $wu['id'], 'user' => 'webuser', 'conn' => 'App', 'host' => 'h', 'token' => '', 'app' => true]);
$fileSid     = bin2hex(random_bytes(16));
file_put_contents("$tmp/data/run/$fileSid.json", json_encode(['uid' => $wu['id'], 'user' => 'webuser', 'conn' => 'Q', 'sid' => $fileSid, 'token' => '']));
file_put_contents("$tmp/data/run/$fileSid.mode", 'file');
$refused = array_map(static fn(array $r): array => [$r[0], $r[1]['error'] ?? null],
    array_map(static fn(string $sid): array => $shApi(['action' => 'session.share', 'sid' => $sid, 'mode' => 'view']), [$otherSid, $appSid, $fileSid]));
$activeRow = array_values(array_filter(MuDesk\Sessions::active(), static fn($s) => ($s['sid'] ?? '') === $shSid))[0] ?? [];
$otherSess->close();
$appSess->close();
MuDesk\Sessions::cleanup($fileSid);
$shSess->close();
$gone = array_map(static fn($c): bool => fread($c, 1) === '' && feof($c), $many);
check('sharing: at most 5 guests at once; the owner makes, lists and revokes links (for a while too: listed with its end; another time, as long as the session; another user\'s, RemoteApp and queue-file sessions refused); all end with the session', [
    $admittedMax, $gotX, $mkSt, str_starts_with($mk['link'] ?? '', "$shSid."), $mk['mode'] ?? null, array_column($listed['shares'] ?? [], 'mode'), $unSt,
    array_column($un['shares'] ?? [], 'id'), $unEv, $refused, $activeRow['shared'] ?? null, array_key_exists('shares', $activeRow), $gone,
    MuDesk\Sessions::findShare("$shSid.{$shCtl['secret']}"), array_map(static fn($r) => $r['mode'] ?? null, MuDesk\Audit::search(['event' => 'share_create'])['rows']),
    count(MuDesk\Audit::search(['event' => 'share_revoke'])['rows']), $shareTimes],
    [5, ['' => 1, 'x' => 5], 200, true, 'control', ['control', 'view', 'control'], 200, [$shCtl['id'], $shView2['id']], ["\x20" . ($mk['id'] ?? '')],
     [[404, 'not_found'], [400, 'share_unavailable'], [400, 'share_unavailable']], 2, false, [true, true, true, true, true, true], null, ['view', 'view', 'control'], 3, [true, null]]);

// a guest joining a session that a connection broker sent to another computer: the session connected again to that computer
// (its session, user and password from the broker), not to the broker, which would have put it in a new session
MuDesk\Settings::save(['targets' => ['mode' => 'list', 'list' => ['127.0.0.1', '127.0.0.2', '127.0.0.3']]]);
$rjP    = proc_open([PHP_BINARY, __DIR__ . '/fixtures/broker_server.php', $cnf, 'rejoin'], [1 => ['pipe', 'w'], 2 => ['file', $nul, 'w']], $rjPipes);
$rjPort = (int)trim((string)fgets($rjPipes[1]));
$rjC    = MuDesk\Connections::save($wu['id'], ['host' => '127.0.0.1', 'port' => $rjPort, 'user' => 'otro', 'security' => 'tls',
    'clipboard' => false, 'drive' => false, 'printer' => false, 'audio' => false], null);
$rjSid  = bin2hex(random_bytes(16));
$rjS    = $wreq('rdp.php?a=stream', json_encode(['c' => $rjC['id'], 'sid' => $rjSid, 'w' => 800, 'h' => 600]));
$rjSaid = [trim((string)fgets($rjPipes[1])), trim((string)fgets($rjPipes[1]))];   // the redirection, then the computer
$rjShare = MuDesk\Sessions::share($rjSid, 'view');
// (the guest's connection made here, as rdp.php?a=watch makes it: PHP's test server on Windows serves one request at a time)
$rjG    = MuDesk\Sessions::watch($rjSid, $rjShare['id']);
$rjSaid[] = trim((string)fgets($rjPipes[1]));                                   // connected again: where
$wres($rjS);
is_resource($rjG) && fclose($rjG);
proc_close($rjP);
MuDesk\Connections::delete($wu['id'], $rjC['id']);
MuDesk\Settings::save(['targets' => ['mode' => 'list', 'list' => ['127.0.0.1']]]);
check('connection broker: a guest joining connects the session again to the computer the broker sent it to, not to the broker', $rjSaid,
    ['redirect sent', 'target Cookie: mstshash=ana cluster=13 session=42 user=ana password=Galleta-1',
     'target Cookie: mstshash=ana cluster=13 session=42 user=ana password=Galleta-1']);

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
// (a session of its own registered first: whether one of the tests before is still running depends on their timing)
$holdSid = str_repeat('ef', 16);
file_put_contents("$tmp/data/run/$holdSid.json", json_encode(['uid' => 'u1', 'token' => hash('sha256', 'hold')]));
MuDesk\Settings::save(['max_sessions_total' => count(MuDesk\Sessions::active())]);
$bsid = bin2hex(random_bytes(16));
[$bcode, $bbody] = $wres($stream($wconn(9), $bsid));
MuDesk\Settings::save(['max_sessions_total' => 0]);
@unlink("$tmp/data/run/$holdSid.json");
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
// a document printed on MuDeskPDF comes from the remote computer: never shown as a page of this site (the page fetches it)
@mkdir(MuDesk\Rdp\Cliprdr::tmpDir($csid), 0700, true);
file_put_contents(MuDesk\Rdp\Cliprdr::tmpDir($csid) . '/print-1', '%PDF-1.4 x');
$pr = (string)stream_get_contents($prs = $wreq("rdp.php?a=print&sid=$csid&job=1"));
fclose($prs);
check('printed documents: never shown as a page of this site (sandboxed, a download)', [(int)substr($pr, 9, 3),
    str_contains($pr, "Content-Security-Policy: default-src 'none'; sandbox"), str_contains($pr, 'Content-Disposition: attachment')], [200, true, true]);
// a move and a clipboard file wait for the user's uploads (their lock), held here: a file uploaded meanwhile to the move's
// destination was replaced by it (checked for just before), and clipboard files copied at once each wrote up to the room left
$ulock = fopen(MuDesk\Config::dir('tmp/upload-' . $wu['id'] . '.lock'), 'c');
$uroot = MuDesk\Files::root($wu['id']);
$cpath = MuDesk\Rdp\Cliprdr::tmpDir($csid) . '/0';
file_put_contents("$uroot/mv-a.txt", 'a');
// (one request at a time: PHP's web server takes the next only once one is answered, on Windows)
flock($ulock, LOCK_EX);
$mvs = $wreq('files.php?a=move&p=mv-a.txt&to=mv-b.txt');
usleep(400000);
file_put_contents("$uroot/mv-b.txt", 'uploaded');                              // what an upload holding the lock wrote
$early = @file_get_contents("$uroot/mv-a.txt");
flock($ulock, LOCK_UN);
$late = [$wres($mvs)[0], @file_get_contents("$uroot/mv-a.txt"), file_get_contents("$uroot/mv-b.txt")];
flock($ulock, LOCK_EX);
$cfs = $wreq("rdp.php?a=clipfile&sid=$csid&n=0&o=0", str_repeat('k', 1000));    // copied again: the 400 KB one's room back
usleep(400000);
clearstatcache();
$early = [$early, filesize($cpath)];
flock($ulock, LOCK_UN);
fclose($ulock);
$late[] = $wres($cfs)[0];
clearstatcache();
check('shared folder: a move and a clipboard file wait for the uploads of the user', [$early, $late, filesize($cpath)],
    [['a', 400000], [400, 'a', 'uploaded', 200], 1000]);
@unlink("$uroot/mv-a.txt");
@unlink("$uroot/mv-b.txt");
$wres($wreq("rdp.php?a=clipfile&sid=$csid&n=0&o=0", str_repeat('c', 400000)));   // as it was, for the quota's checks below
$wdrive->release();
$csess->close();
unlink(MuDesk\Files::root($wu['id']) . '/up.bin');                          // removed by other means
$before = MuDesk\Files::quotaLeft($wu['id']);
$files('list', '', '&count=1');
$q0 = MuDesk\Settings::get('files_quota_mb');
MuDesk\Settings::save(['files_quota_mb' => 0]);
$noQuota = [$files('upload', 'rep.txt', '', 'primera'), $files('upload', 'rep.txt', '', 'segunda'), @file_get_contents(MuDesk\Files::root($wu['id']) . '/rep.txt')];
@unlink(MuDesk\Files::root($wu['id']) . '/rep.txt');
MuDesk\Settings::save(['files_quota_mb' => $q0]);
check('file manager without a quota: a file replaced (it was deleted, then a 500)', $noQuota, [200, 200, 'segunda']);
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
// the pages' policy: their own scripts, styles and requests only; images and frames from blob: too (pictures, the print
// preview), and media (a recording made in the page, played from its video read whole)
$cspS = fsockopen('127.0.0.1', $wport, $en, $es, 5);
fwrite($cspS, "POST /api.php HTTP/1.0\r\nHost: 127.0.0.1\r\nContent-Type: application/json\r\nContent-Length: 18\r\n\r\n{\"action\":\"state\"}");
$cspR = (string)stream_get_contents($cspS);
fclose($cspS);
$csp = preg_match('/^Content-Security-Policy: (.*)$/mi', $cspR, $cspM) ? trim($cspM[1]) : '';
// ... the browser features they never use denied (not the camera, microphone, clipboard, full screen, screens or passkeys
// they use), and their answers not taken in by another site
$perm = preg_match('/^Permissions-Policy: (.*)$/mi', $cspR, $cspM) ? array_map('trim', explode(',', trim($cspM[1]))) : [];
$corp = preg_match('/^Cross-Origin-Resource-Policy: (.*)$/mi', $cspR, $cspM) ? trim($cspM[1]) : '';
check('page policy: scripts, styles and requests of its own only; blob: images, frames and media (a recording made in the page played); features never used denied, answers kept to its own site', [
    str_contains($csp, "default-src 'self'"), str_contains($csp, "script-src 'self';"), str_contains($csp, "media-src 'self' blob:"), str_contains($csp, "frame-ancestors 'none'"),
    array_values(array_filter($perm, static fn(string $p): bool => !str_ends_with($p, '=()'))),
    array_values(array_intersect(['geolocation=()', 'usb=()', 'payment=()'], $perm)),
    array_values(array_filter($perm, static fn(string $p): bool => (bool)preg_match('/^(camera|microphone|clipboard-read|clipboard-write|fullscreen|window-management|publickey-credentials-get|publickey-credentials-create|autoplay)=/', $p))),
    $corp], [true, true, true, true, [], ['geolocation=()', 'usb=()', 'payment=()'], [], 'same-origin']);
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

// a computer never seen (here one that answers with TLS and a certificate of its own, then lets the connection go): the
// connection stops before anything is sent, its fingerprints given to the page (SHA-256, SHA-1 as Windows shows it); trusted
// once the user says so (the next connection carries it), not asked again. An administrator who trusts it for every user
// (the page's box) fixes it for any connection to that computer, by its name or its address: a user's own connection to it
// is not asked; another certificate there is refused whatever the user trusts, and only an administrator puts a renewed one in
// its place. Fixed and forgotten in the editor too (its SHA-1 as Windows writes it), only by an administrator. With
// confirm_new_certificates off in config.php, trusted at once as before (before, it always was: a man in the middle of that
// first connection got the password)
$fxKey  = openssl_pkey_new(['config' => $cnf, 'private_key_bits' => 2048]);
$fxCert = openssl_csr_sign(openssl_csr_new(['commonName' => 'huella'], $fxKey, ['config' => $cnf]), null, $fxKey, 1, ['config' => $cnf, 'digest_alg' => 'sha256']);
openssl_x509_export($fxCert, $fxPem);
openssl_pkey_export($fxKey, $fxKeyPem, null, ['config' => $cnf]);
file_put_contents("$tmp/huella.pem", $fxPem . $fxKeyPem);
[$fx256, $fx1] = [openssl_x509_fingerprint($fxCert, 'sha256'), openssl_x509_fingerprint($fxCert, 'sha1')];
$fxP = proc_open([PHP_BINARY, '-r', '$s = stream_socket_server("tcp://127.0.0.1:0", $e, $m, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, stream_context_create(["ssl" => ["local_cert" => '
    . var_export("$tmp/huella.pem", true) . ', "verify_peer" => false]])); echo substr(strrchr(stream_socket_get_name($s, false), ":"), 1), "\n";'
    . ' while ($c = @stream_socket_accept($s, 30)) { fread($c, 4096); fwrite($c, "\x03\x00\x00\x13\x0e\xd0\x00\x00\x12\x34\x00\x02\x00\x08\x00\x01\x00\x00\x00");'
    . ' stream_set_blocking($c, true); @stream_socket_enable_crypto($c, true, STREAM_CRYPTO_METHOD_TLS_SERVER); @fread($c, 4096); fclose($c); }'],
    [1 => ['pipe', 'w'], 2 => ['file', $nul, 'w']], $fxPipes);
$fxPort  = (int)trim((string)fgets($fxPipes[1]));
$endInfo = static function (string $body): array {                         // the last status with an end
    $last = [];
    for ($o = 0; $o + 5 <= strlen($body); $o += 5 + $len) {
        ['t' => $type, 'n' => $len] = unpack('Ct/Vn', $body, $o);
        if ($type === MuDesk\Rdp\Client::F_STATUS && isset(($j = json_decode(substr($body, $o + 5, $len), true))['end'])) {
            $last = $j;
        }
    }
    return $last;
};
$fxConn = static fn(string $uid, string $host = '127.0.0.1'): array => MuDesk\Connections::save($uid, ['name' => 'Huella', 'host' => $host, 'port' => $fxPort,
    'security' => 'tls', 'clipboard' => false, 'drive' => false, 'printer' => false, 'audio' => false], null);
$fxGo   = static function (array $who, array $c, array $more = []) use ($wport, $endInfo): array {   // a session asked for as $who
    $body = json_encode(['c' => $c['id'], 'sid' => bin2hex(random_bytes(16)), 'w' => 800, 'h' => 600] + $more);
    $s = fsockopen('127.0.0.1', $wport, $en, $es, 5);
    fwrite($s, "POST /rdp.php?a=stream HTTP/1.0\r\nHost: 127.0.0.1\r\nUser-Agent: test\r\nCookie: MUDESKSID={$who[0]}\r\nX-CSRF: {$who[1]}\r\n"
        . "Content-Type: application/octet-stream\r\nContent-Length: " . strlen($body) . "\r\n\r\n$body");
    $r = (string)stream_get_contents($s);
    fclose($s);
    return $endInfo(substr($r, (int)strpos($r, "\r\n\r\n") + 4));
};
$fxEnd  = static fn(array $e): string => in_array($e['end'] ?? '', ['cert_new', 'cert_changed', 'cert_unexpected'], true) ? $e['end'] : 'went on';
$fxPin  = static fn(string $uid, array $c): string => MuDesk\Connections::pins($uid, $c)[0];
$fxFix  = static fn(): ?string => MuDesk\Connections::fixedCert('localhost', $fxPort);
[$fxU, $fxA] = [[$wsid, $wcsrf], [$asid, $acsrf]];
$askCerts(true);
$fxSeen = [];
$fu  = $fxConn($wu['id']);                                                  // a user's own, by the computer's address
$e1  = $fxGo($fxU, $fu);
$fxSeen[] = [$e1['end'] ?? null, $e1['detail'] ?? null, $e1['sha1'] ?? null, isset($e1['host']), $fxPin($wu['id'], $fu)];
$fxSeen[] = [$fxEnd($fxGo($fxU, $fu, ['trust' => $fx256, 'trust_all' => true])), $fxPin($wu['id'], $fu), MuDesk\Connections::fixedCerts()];   // (a user's box: not theirs)
$fxSeen[] = [$fxEnd($fxGo($fxU, $fu))];
$fa  = $fxConn($wa['id'], 'localhost');                                     // an administrator's, by its name
$fxSeen[] = [$fxEnd($fxGo($fxA, $fa)), $fxEnd($fxGo($fxA, $fa, ['trust' => str_repeat('0', 64), 'trust_all' => true])), $fxFix(),   // (not the one shown: nothing)
    $fxEnd($fxGo($fxA, $fa, ['trust' => $fx256, 'trust_all' => true])), $fxFix()];
$fxSeen[] = count(array_filter(MuDesk\Audit::search(['event' => 'cert_fixed'])['rows'], static fn($r) => ($r['fingerprint'] ?? '') === $fx256));
$fxGo($fxA, $fa, ['trust' => $fx256, 'trust_all' => true]);                 // connected again with the same: not fixed again
$fxSeen[] = count(array_filter(MuDesk\Audit::search(['event' => 'cert_fixed'])['rows'], static fn($r) => ($r['fingerprint'] ?? '') === $fx256));
$fu2 = $fxConn($wu['id']);                                                  // another of the user's, never seen by them: not asked
$fxSeen[] = [$fxEnd($fxGo($fxU, $fu2)), $fxPin($wu['id'], $fu2)];
$asAdmin(['action' => 'admin.cert.fix', 'host' => 'LOCALHOST', 'port' => $fxPort, 'fingerprint' => str_repeat('0', 64)]);   // another one fixed
$e5  = $fxGo($fxU, $fu, ['trust' => $fx256, 'trust_all' => true]);
$fxSeen[] = [$e5['end'] ?? null, $e5['detail'] ?? null, $fxFix()];
$fxSeen[] = [$fxEnd($fxGo($fxA, $fa, ['trust' => $fx256, 'trust_all' => true])), $fxFix()];   // a renewed one, by an administrator
$fixAs = static fn(callable $as, string $fp): array => $as(['action' => 'admin.cert.fix', 'host' => 'localhost', 'port' => $fxPort, 'fingerprint' => $fp]);
$fxSeen[] = [$fixAs($asAdmin, strtoupper(implode(':', str_split($fx1, 2))))[0], $fxFix(), $fxEnd($fxGo($fxU, $fu)),
    $fixAs($asAdmin, 'xyz')[1]['error'] ?? null, $fixAs($asAdmin, str_repeat('a', 50))[1]['error'] ?? null, $fixAs($asUser, '')[0],
    $asUser(['action' => 'admin.certs'])[0], array_keys($asAdmin(['action' => 'admin.certs'])[1]['certs'] ?? []), $fixAs($asAdmin, '')[0], $fxFix()];
$askCerts(false);
$fo  = $fxConn($wu['id'], 'localhost');
$fxSeen[] = [$fxEnd($fxGo($fxU, $fo)), $fxPin($wu['id'], $fo)];
foreach ([[$wu['id'], $fu], [$wu['id'], $fu2], [$wu['id'], $fo], [$wa['id'], $fa]] as [$uid, $c]) {
    MuDesk\Connections::delete($uid, $c['id']);
}
proc_terminate($fxP);
proc_close($fxP);
@unlink("$tmp/huella.pem");
check('certificate of a computer never seen: confirmed by the user (its SHA-256 and SHA-1 shown) before anything is sent; fixed for every user by an administrator (by name or address; once), another refused, a renewed one only by an administrator; trusted at once with the option off',
    $fxSeen, [['cert_new', $fx256, $fx1, false, ''], ['went on', $fx256, []], ['went on'], ['cert_new', 'cert_new', null, 'went on', $fx256], 1, 1, ['went on', $fx256],
    ['cert_unexpected', $fx256, str_repeat('0', 64)], ['went on', $fx256], [200, $fx1, 'went on', 'cert_fixed', 'cert_fixed', 403, 403, ["rdp:localhost:$fxPort"], 200, null],
    ['went on', $fx256]]);

// the administration's status: what runs now (sessions, guests, recordings, the PHP processes and memory they take, the disk,
// yesterday's and today's problems, the server) for administrators and auditors; the use of the last 30 days from the audit
// (here two days of it written apart: sessions a day, hours, most at once, a session across midnight split between its days,
// the computers used most; an attempt that never started not counted); what a session says of itself (its memory, its guests; nothing else); health.php for a monitor:
// working or not, nothing of the installation, its problem said when the data folder or a store cannot be used
$day20 = date('Y-m-d', strtotime('-20 days'));
$day19 = date('Y-m-d', strtotime('-19 days'));
$aLine = static fn(string $at, string $event, array $more): string => json_encode(['t' => date('c', strtotime($at)), 'ip' => '10.0.0.1', 'user' => 'ana', 'event' => $event, 'ok' => true] + $more) . "\n";
file_put_contents("$tmp/data/audit/$day20.jsonl",
    $aLine("$day20 09:00:00", 'rdp_start', ['sid' => 's1', 'conn' => 'A', 'host' => 'grafico-a.test'])
    . $aLine("$day20 10:00:00", 'rdp_start', ['sid' => 's2', 'conn' => 'B', 'host' => 'grafico-b.test'])
    . $aLine("$day20 11:00:00", 'rdp_end', ['sid' => 's1', 'conn' => 'A', 'seconds' => 7200, 'reason' => 'user'])   // 09:00-11:00
    . $aLine("$day20 10:30:00", 'rdp_end', ['sid' => 's2', 'conn' => 'B', 'seconds' => 1800, 'reason' => 'user'])   // 10:00-10:30
    . $aLine("$day20 23:00:00", 'rdp_start', ['sid' => 's3', 'conn' => 'A', 'host' => 'grafico-a.test'])
    . $aLine("$day20 12:00:00", 'rdp_end', ['sid' => 's4', 'conn' => 'grafico-c', 'seconds' => 3000, 'reason' => 'auth_failed']));   // never started
file_put_contents("$tmp/data/audit/$day19.jsonl", $aLine("$day19 01:00:00", 'rdp_end', ['sid' => 's3', 'conn' => 'A', 'seconds' => 7200, 'reason' => 'user']));   // 23:00-01:00
$use   = MuDesk\Status::usage(30);
$byDay = array_column($use['days'], null, 'day');
$useH  = array_values(array_filter($use['hosts'], static fn($x) => str_starts_with($x['host'], 'grafico-')));
@unlink("$tmp/data/audit/$day20.jsonl");
@unlink("$tmp/data/audit/$day19.jsonl");
$rsid = bin2hex(random_bytes(16));                                         // a session's own report: memory and guests only
file_put_contents("$tmp/data/run/$rsid.json", json_encode(['uid' => 'u1', 'token' => 'x', 'conn' => 'C', 'sid' => $rsid]));
file_put_contents("$tmp/data/run/$rsid.mode", 'file');
$reported = [MuDesk\Sessions::report($rsid, ['mem' => '5000000', 'mem_peak' => 9000000, 'viewers' => 2, 'uid' => 'otro', 'token' => 'y']),
    array_intersect_key(MuDesk\Sessions::info($rsid) ?? [], array_flip(['uid', 'token', 'mem', 'mem_peak', 'viewers'])), MuDesk\Sessions::report($rsid, ['uid' => 'otro'])];
$stNow = MuDesk\Status::now();
$stMine = array_values(array_filter(MuDesk\Sessions::active(), static fn($s) => ($s['sid'] ?? '') === $rsid))[0] ?? [];
MuDesk\Sessions::cleanup($rsid);
[$stA, $stJ] = $asAdmin(['action' => 'admin.status']);
[$usA, $usJ] = $asAdmin(['action' => 'admin.usage']);
$healthOf = static function () use ($wport): array {
    $s = fsockopen('127.0.0.1', $wport, $en, $es, 5);
    fwrite($s, "GET /health.php HTTP/1.0\r\nHost: 127.0.0.1\r\n\r\n");
    $r = (string)stream_get_contents($s);
    fclose($s);
    return [(int)substr($r, 9, 3), substr($r, (int)strpos($r, "\r\n\r\n") + 4), (bool)preg_match('/^Cache-Control: no-store/mi', $r)];
};
$health = $healthOf();
// a problem answered with 503 and said (a monitor reads the code): here its users cannot be read
$usersKeep = (string)file_get_contents("$tmp/data/users.json");
file_put_contents("$tmp/data/users.json", '{"roto":');
$health[] = array_slice($healthOf(), 0, 2);
file_put_contents("$tmp/data/users.json", $usersKeep);
// its problems, in a data folder of its own: a store that cannot be read, a folder that is not there
@mkdir("$tmp/hdata/run", 0700, true);
file_put_contents("$tmp/hcfg.php", '<?php return ["data_dir" => ' . var_export("$tmp/hdata", true) . '];');
MuDesk\Config::load("$tmp/hcfg.php");
$hp = [MuDesk\Status::problem()];
file_put_contents("$tmp/hdata/settings.json", '{"roto":');
$hp[] = MuDesk\Status::problem();
MuDesk\Files::remove("$tmp/hdata");
$hp[] = MuDesk\Status::problem();
MuDesk\Config::load("$tmp/config.php");
@unlink("$tmp/hcfg.php");
check('status: now (sessions, guests, processes, memory, disk, problems, server) for administrators and auditors, not users; 30 days of use from the audit (a session across midnight split, the computers used most); a session reports its memory and guests only; health.php',
    [$byDay[$day20] ?? null, $byDay[$day19] ?? null, count($use['days']), $useH, $reported, [$stMine['viewers'] ?? null, $stNow['processes'] - $stNow['sessions'] >= 2,
     $stNow['memory']['largest'] >= 9000000, $stNow['memory']['sessions'] >= 5000000, is_int($stNow['problems']['login_fail']), $stNow['server']['php'] === PHP_VERSION],
     [$stA, isset($stJ['status']['disk']['recordings']), $usA, count($usJ['days'] ?? []), $asUser(['action' => 'admin.status'])[0], $asUser(['action' => 'admin.usage'])[0]],
     $health, $hp, [MuDesk\Status::bytes('128M'), MuDesk\Status::bytes('1G'), MuDesk\Status::bytes('512k'), MuDesk\Status::bytes('-1'), MuDesk\Status::bytes('2048')]],
    [['day' => $day20, 'sessions' => 3, 'hours' => 3.5, 'peak' => 2], ['day' => $day19, 'sessions' => 0, 'hours' => 1.0, 'peak' => 1], 30,
     [['hours' => 4.0, 'host' => 'grafico-a.test', 'sessions' => 2], ['hours' => 0.5, 'host' => 'grafico-b.test', 'sessions' => 1]],
     [true, ['uid' => 'u1', 'token' => 'x', 'mem' => 5000000, 'mem_peak' => 9000000, 'viewers' => 2], false], [2, true, true, true, true, true],
     [200, true, 200, 30, 403, 403], [200, '{"status":"ok"}', true, [503, '{"status":"error","problem":"store"}']], [null, 'store', 'data'], [134217728, 1073741824, 524288, 0, 2048]]);

// the data folder readable from the web: nothing works (every entry point answers 503 data_exposed, its page saying what to
// do) until it is protected. The gateway asks itself for data/web-check.txt beside the page and one folder above (here two
// servers of its own: one that gives the data folder, one that does not, and a port where nothing answers); a browser's word
// counts only with the file's code now (a false one, or one read before, does nothing); looked at again soon (30 s, 5 minutes
// after a browser), 10 minutes while protected; health.php says so too
$expoRoot = "$tmp/expo";
@mkdir("$expoRoot/open/data", 0700, true);
@mkdir("$expoRoot/closed/public", 0700, true);
$expoSrv = [];
foreach (['open', 'closed'] as $k) {
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $expoPort[$k] = (int)substr((string)strrchr((string)stream_socket_get_name($probe, false), ':'), 1);
    fclose($probe);
    $expoSrv[$k] = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$expoPort[$k]}", '-t', "$expoRoot/$k"], [1 => ['file', $nul, 'w'], 2 => ['file', $nul, 'w']], $expoPipes);
}
for ($i = 0; $i < 100 && !($ws = @fsockopen('127.0.0.1', $expoPort['closed'])); $i++) {
    usleep(50000);
}
$ws && fclose($ws);
$st0 = MuDesk\Exposure::save('ok', 'server');
file_put_contents("$expoRoot/open/data/web-check.txt", (string)file_get_contents("$tmp/data/web-check.txt"));
$srvOf = static fn(int $port, string $uri = '/public/api.php', ?string $host = null): array => ['SERVER_ADDR' => '127.0.0.1', 'SERVER_PORT' => $port,
    'HTTP_HOST' => $host ?? "127.0.0.1:$port", 'REQUEST_URI' => $uri, 'SCRIPT_NAME' => (string)preg_replace('~\.php/.*$~', '.php', $uri)];
$probeNew = static function (array $srv): ?string {                         // (nothing found before)
    MuDesk\Exposure::save('ok', 'server');
    return MuDesk\Exposure::probe($srv)['state'] ?? null;
};
$dead  = stream_socket_server('tcp://127.0.0.1:0');
$deadP = (int)substr((string)strrchr((string)stream_socket_get_name($dead, false), ':'), 1);
fclose($dead);
$expoSeen = [
    [MuDesk\Exposure::path('/mudesk/public/../data/web-check.txt'), MuDesk\Exposure::path('/../../data/x'), MuDesk\Exposure::path('/a/./b//c')],
    [MuDesk\Exposure::fetch($srvOf($expoPort['open']), '/data/web-check.txt'), MuDesk\Exposure::fetch($srvOf($expoPort['closed']), '/data/web-check.txt'),
     MuDesk\Exposure::fetch($srvOf($deadP), '/data/web-check.txt')],
    [$probeNew($srvOf($expoPort['open'])), $probeNew($srvOf($expoPort['closed'])), $probeNew($srvOf($deadP)), $probeNew($srvOf($expoPort['open'], '/api.php'))],
];
// a request from elsewhere never finds it protected alone: its folder is the script's (not a path the client adds after it),
// and where it was found readable is asked again with it (another site's Host, another folder, another server); protected
// there, it works again
$expoSeen[] = [$probeNew($srvOf($expoPort['open'], '/public/api.php/a/b/c/')), MuDesk\Exposure::probe($srvOf($expoPort['closed']))['state'] ?? null,
    MuDesk\Exposure::probe($srvOf($expoPort['open'], '/x/y/api.php', 'otro.test'))['state'] ?? null, MuDesk\Exposure::state()['where']['dir'] ?? null,
    rename("$expoRoot/open/data/web-check.txt", "$expoRoot/open/data/web-check.old") ? MuDesk\Exposure::probe($srvOf($expoPort['closed']))['state'] ?? null : 'not moved'];
$now = time();
$expoSeen[] = [MuDesk\Exposure::due(['state' => 'ok', 'at' => $now - 599, 'by' => 'server'], $now), MuDesk\Exposure::due(['state' => 'ok', 'at' => $now - 600, 'by' => 'server'], $now),
    MuDesk\Exposure::due(['state' => 'exposed', 'at' => $now - 30, 'by' => 'server'], $now), MuDesk\Exposure::due(['state' => 'exposed', 'at' => $now - 100, 'by' => 'browser'], $now),
    MuDesk\Exposure::due(['state' => 'exposed', 'at' => $now - 300, 'by' => 'browser'], $now)];
// a browser's word: what it read, sent as the page does (anonymous, with the session's CSRF token)
$st1  = MuDesk\Exposure::save('ok', 'server');
$read = (string)file_get_contents("$tmp/data/web-check.txt");
$expoSeen[] = [str_contains($read, $st1['token']), $asUser(['action' => 'data.exposed', 'proof' => MuDesk\Config::WEB_CHECK . "\n" . str_repeat('0', 32)])[1],
    $asUser(['action' => 'data.exposed', 'proof' => $read])[1], MuDesk\Exposure::state()['state'], MuDesk\Exposure::state()['by']];
// stopped: the API, the page and health.php say so; protected again, all of it works
$apiStopped = $asUser(['action' => 'state']);
$pageOf = static function (string $path, string $accept) use ($wport): array {
    $s = fsockopen('127.0.0.1', $wport, $en, $es, 5);
    fwrite($s, "GET /$path HTTP/1.0\r\nHost: 127.0.0.1\r\nAccept: $accept\r\nAccept-Language: es\r\n\r\n");
    $r = (string)stream_get_contents($s);
    fclose($s);
    return [(int)substr($r, 9, 3), substr($r, (int)strpos($r, "\r\n\r\n") + 4)];
};
file_put_contents("$app/public/index.php", (string)file_get_contents(__DIR__ . '/../public/index.php'));
$pageStopped = $pageOf('index.php', 'text/html');
$healthStopped = $pageOf('health.php', 'application/json');
MuDesk\Exposure::save('ok', 'server');
$expoSeen[] = [$apiStopped, $pageStopped[0], str_contains($pageStopped[1], '<h1>La carpeta de datos se puede descargar</h1>'), $healthStopped,
    $asUser(['action' => 'state'])[0], $asUser(['action' => 'data.exposed', 'proof' => $read])[1]];   // (the code read before: nothing)
// PHP's built-in server (the tests' own) never asks itself: a code written for the browsers all the same, new every 10
// minutes (before, it never had one there, and no browser could stop it); stopped by a browser, it works again after 5
// minutes (the next page proves it again while it is readable)
@unlink(MuDesk\Config::dir('run/exposure.json'));
$cliFirst = $asUser(['action' => 'state'])[0];
$cliState = MuDesk\Exposure::state();
$cliProof = $asUser(['action' => 'data.exposed', 'proof' => (string)file_get_contents("$tmp/data/web-check.txt")])[1];
$cliStop  = $asUser(['action' => 'state'])[0];
file_put_contents(MuDesk\Config::dir('run/exposure.json'), json_encode(['at' => time() - 301] + MuDesk\Exposure::state()));
$cliLater = $asUser(['action' => 'state'])[0];
$expoSeen[] = [$cliFirst, $cliState['state'], strlen($cliState['token']), $cliProof, $cliStop, $cliLater, MuDesk\Exposure::state()['state'],
    MuDesk\Exposure::state()['token'] !== $cliState['token']];
// what it knows is no session (before, a fresh one was taken for one: listed without its id or user, and counted against the
// limits of sessions)
$expoSeen[] = [array_filter(MuDesk\Sessions::active(), static fn(array $s): bool => isset($s['state'])), is_file(MuDesk\Config::dir('run/exposure.json'))];
@unlink("$app/public/index.php");
@unlink(MuDesk\Config::dir('run/exposure.json'));
foreach ($expoSrv as $p) {
    proc_terminate($p);
    proc_close($p);
}
MuDesk\Files::remove($expoRoot);
check('data folder readable from the web: found by the gateway itself (beside the page and above it) or proved by a browser with the file\'s code now; never protected by a request from elsewhere alone; nothing works meanwhile (API, page, health.php), all of it again once protected; looked at again soon; a code on PHP\'s built-in server too',
    $expoSeen, [['/mudesk/data/web-check.txt', '/data/x', '/a/b/c'], [true, false, null], ['exposed', 'ok', 'unknown', 'exposed'],
    ['exposed', 'exposed', 'exposed', '/public', 'ok'], [false, true, true, false, true],
    [true, ['stopped' => false], ['stopped' => true], 'exposed', 'browser'],
    [[503, ['error' => 'data_exposed']], 503, true, [503, '{"error":"data_exposed"}'], 200, ['stopped' => false]],
    [200, 'unknown', 32, ['stopped' => true], 503, 200, 'unknown', true], [[], true]]);

// a backup: what it carries (the users, settings, connections, certificates, the audit and the data's keys; the users'
// folders and recordings when asked; never what passes, nor another path); written in chunks of 1 MB, each authenticated with
// its number and whether it is the last, read back whole (a 3 MB file across chunks); a wrong password, a byte changed,
// a chunk cut off, bytes after it, not a backup, a newer version, a name out of the data folder: refused, nothing written
// out of the folder given. Restored: what it carries in place (what it does not, as it was), every web session ended; a move
// that fails puts back what was moved

/** A disk that fills up: the bytes it still takes (for Backup::extract). */
final class BkFullDisk
{
    public static int $room = 0;
    public $context;
    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool { return true; }
    public function stream_write(string $data): int { $n = min(strlen($data), self::$room); self::$room -= $n; return $n; }
    public function stream_close(): void {}
    public function stream_flush(): bool { return true; }
    public function mkdir(string $path, int $mode, int $options): bool { return true; }
    public function url_stat(string $path, int $flags): array|false { return false; }
    public function stream_metadata(string $path, int $option, mixed $value): bool { return true; }
}

/** A file of 3 MB that grows shorter while it is read: half of it there (for Backup::write). */
final class BkShrinking
{
    public $context;
    private int $at = 0;
    public function stream_open(string $path, string $mode, int $options, ?string &$opened): bool { return true; }
    public function stream_read(int $n): string { $d = str_repeat('x', max(0, min($n, 1572864 - $this->at))); $this->at += strlen($d); return $d; }
    public function stream_eof(): bool { return $this->at >= 1572864; }
    public function stream_stat(): array { return ['size' => 3145728, 'mode' => 0100644]; }
    public function url_stat(string $path, int $flags): array { return ['size' => 3145728, 'mode' => 0100644]; }
    public function stream_close(): void {}
}

stream_wrapper_register('bkfull', BkFullDisk::class);
stream_wrapper_register('bkshrink', BkShrinking::class);
$bk = "$tmp/bk";
@mkdir("$bk/data", 0700, true);
file_put_contents("$bk/config.php", '<?php return ["data_dir" => ' . var_export("$bk/data", true) . '];');
MuDesk\Config::load("$bk/config.php");
MuDesk\Crypto::forgetAppKey();
MuDesk\Settings::forget();
MuDesk\Crypto::appKey();                                                    // (its secret.php made)
file_put_contents("$bk/data/users.json", '{"u1":{"id":"u1","username":"ana"}}');
file_put_contents("$bk/data/settings.json", '{"max_sessions_total":20}');
file_put_contents("$bk/data/ratelimit.json", '{}');
@mkdir("$bk/data/connections", 0700, true);
file_put_contents("$bk/data/connections/u1.json", '{"c1":{"host":"pc"}}');
@mkdir("$bk/data/audit", 0700, true);
file_put_contents("$bk/data/audit/2026-11-01.jsonl", "{\"event\":\"x\"}\n");
@mkdir("$bk/data/files/u1/docs", 0700, true);
$bigData = random_bytes(3 * 1048576 + 123);
file_put_contents("$bk/data/files/u1/docs/grande.bin", $bigData);
file_put_contents("$bk/data/run/x.json", '{}');
file_put_contents("$bk/data/users.json.lock", '');
$names = static fn(array $e): array => (static function (array $n): array { sort($n); return $n; })(array_column($e, 0));
$bkSalt  = random_bytes(16);
$bkKey   = MuDesk\Backup::key('Clave-de-copia-1', $bkSalt);
$bkWrite = static function (string $file, array $entries, array $manifest = ['version' => MuDesk\VERSION]) use ($bkKey, $bkSalt): int {
    $h = fopen($file, 'wb');
    $n = MuDesk\Backup::write($h, $bkKey, $bkSalt, $manifest, $entries);
    fclose($h);
    return $n;
};
$bkSeen = [array_map([MuDesk\Backup::class, 'allowed'], ['users.json', 'secret.php', 'audit-key.php', 'audit/2026-11-01.jsonl', 'files/u1/a b.txt',
    'ratelimit.json', 'tls.json', 'run/x.json', 'users.json.lock', '../x', 'files/../x', 'files//x', 'C:/x', 'files/a\\b', 'sessions/sess_1', 'tmp/a',
    'files/u1/yarn.lock', 'files/u1/x.0123abcd.tmp', 'files/u1/' . str_repeat('carpeta/', 200) . 'b.txt', 'files/u1.quota', 'connections/u1.json.lock'])];
$bkSeen[] = [$names(MuDesk\Backup::entries([])), in_array('files/u1/docs/grande.bin', array_column(MuDesk\Backup::entries(['files']), 0), true)];
@unlink("$bk/data/run/x.json");                                          // (only to be left out)
$all = MuDesk\Backup::entries(['files', 'recordings']);
$size = $bkWrite("$bk/b1", $all);
@mkdir("$bk/out1");
$m1 = MuDesk\Backup::extract("$bk/b1", 'Clave-de-copia-1', "$bk/out1");
$same = array_map(static fn($e) => is_file("$bk/out1/{$e[0]}") && hash_file('sha256', "$bk/out1/{$e[0]}") === hash_file('sha256', $e[1]), $all);
$raw1 = (string)file_get_contents("$bk/b1");
$try = static function (string $bytes, string $pw = 'Clave-de-copia-1') use ($bk): array {
    file_put_contents("$bk/bx", $bytes);
    MuDesk\Files::remove("$bk/outx");
    @mkdir("$bk/outx");
    $r = MuDesk\Backup::extract("$bk/bx", $pw, "$bk/outx");
    return [is_string($r) ? $r : 'read', count(glob("$bk/outx/*") ?: [])];
};
$hdr = strlen(MuDesk\Backup::MAGIC) + 20;
$c0  = unpack('N', $raw1, $hdr)[1];                                          // the first chunk's length: where the second begins
$bkSeen[] = [$size === strlen($raw1), str_starts_with($raw1, MuDesk\Backup::MAGIC), ($m1['version'] ?? null), ($m1['files'] ?? null), array_values(array_unique($same)),
    !str_contains($raw1, 'max_sessions_total'), !str_contains($raw1, substr($bigData, 1000, 64))];
// what it writes, for the server's account only (before, the process's mask: a restored secret.php readable by the other
// accounts of a shared server); a disk that fills up: refused, nothing taken as read (before, put in place cut short)
BkFullDisk::$room = 200000;
$bkSeen[] = [PHP_OS_FAMILY === 'Windows' || (fileperms("$bk/out1/secret.php") & 0777) === 0600, MuDesk\Backup::extract("$bk/b1", 'Clave-de-copia-1', 'bkfull://out')];
// a file gone meanwhile left out (as in a backup made a moment later); one that cannot be read, or grows shorter while it is
// read: the backup ends there, without its last chunk, refused whole (before, zeros in its place, and taken for good)
$bkWrite("$bk/b5", [['users.json', "$bk/data/users.json"], ['hosts/ido.json', "$bk/data/hosts/ido.json"]]);
@mkdir("$bk/out9");
MuDesk\Backup::extract("$bk/b5", 'Clave-de-copia-1', "$bk/out9");
$bkCut = static function (array $entries) use ($bkKey, $bkSalt, $bk, $try): array {
    $h = fopen("$bk/b6", 'wb');
    try {
        MuDesk\Backup::write($h, $bkKey, $bkSalt, ['version' => MuDesk\VERSION], $entries);
        return ['written'];
    } catch (RuntimeException $e) {
        fflush($h);
        return [$e->getMessage(), $try((string)file_get_contents("$bk/b6"))[0]];
    } finally {
        fclose($h);
    }
};
$bkSeen[] = [is_file("$bk/out9/users.json"), file_exists("$bk/out9/hosts/ido.json"), $bkCut([['users.json', "$bk/data/users.json"], ['hosts/carpeta.json', "$bk/data/audit"]]),
    $bkCut([['users.json', "$bk/data/users.json"], ['files/u1/grande.bin', 'bkshrink://grande']])];
$bkSeen[] = [$try($raw1, 'Otra-clave-999'), $try(substr_replace($raw1, chr(ord($raw1[$hdr + 5 + 12 + $c0 + 16 + 5 + 40]) ^ 1), $hdr + 5 + 12 + $c0 + 16 + 5 + 40, 1))[0],
    $try(substr($raw1, 0, $hdr + 5 + 12 + $c0 + 16))[0], $try($raw1 . 'x')[0], $try('PK' . str_repeat("\0", 100))[0]];
// cut right after a chunk that ends with a whole record (its last one, empty, gone): only the last chunk's flag says that
// something is missing
$exactJson = (string)json_encode(['version' => MuDesk\VERSION, 'files' => 1], MuDesk\Http::JSON);
file_put_contents("$bk/justo.bin", str_repeat('j', 1048576 - (2 + 9 + 8 + strlen($exactJson)) - (2 + strlen('files/u1/justo.bin') + 8)));
$bkWrite("$bk/b7", [['files/u1/justo.bin', "$bk/justo.bin"]]);
$raw7 = (string)file_get_contents("$bk/b7");
$c7   = unpack('N', $raw7, $hdr)[1];
$bkSeen[] = [$c7 === 1048576, strlen($raw7) === $hdr + 5 + 12 + $c7 + 16 + 5 + 12 + 16, $try($raw7)[0], $try(substr($raw7, 0, $hdr + 5 + 12 + $c7 + 16))[0]];
@unlink("$bk/justo.bin");
$bkWrite("$bk/b2", [['users.json', "$bk/data/users.json"]], ['version' => '99.0.0']);
$bkWrite("$bk/b3", [['../fuera.txt', "$bk/data/users.json"]]);
$bkSeen[] = [$try((string)file_get_contents("$bk/b2"))[0], $try((string)file_get_contents("$bk/b3"))[0], is_file("$bk/fuera.txt")];
// restored: what it carries in place, the users' folders kept when it does not carry them, every web session ended
$bkWrite("$bk/b4", MuDesk\Backup::entries([]));
file_put_contents("$bk/data/users.json", '{"u2":{"id":"u2","username":"otro"}}');
file_put_contents("$bk/data/files/u1/docs/grande.bin", 'cambiado');
@mkdir("$bk/data/sessions", 0700, true);
file_put_contents("$bk/data/sessions/sess_abc", 'x');
file_put_contents("$bk/data/certs.json", '{"rdp:pc:3389":{"fp":"' . str_repeat('ab', 32) . '"}}');   // (fixed after it: it goes)
@mkdir("$bk/out4");
MuDesk\Backup::extract("$bk/b4", 'Clave-de-copia-1', "$bk/out4");
$bkSess = MuDesk\Sessions::register(bin2hex(random_bytes(16)), ['uid' => 'u1', 'user' => 'ana', 'token' => 't']);   // a session running
$r4 = MuDesk\Backup::restore("$bk/out4");
$bkKilled = $bkSess->read();                                                // (told to end: it took 5 s to give up waiting)
$bkSess->close();
$bkSeen[] = [$r4, (string)file_get_contents("$bk/data/users.json"), (string)file_get_contents("$bk/data/files/u1/docs/grande.bin"), is_file("$bk/data/sessions/sess_abc"),
    is_file("$bk/data/ratelimit.json"), glob("$bk/data/tmp/restore-old-*") ?: [], is_file("$bk/data/certs.json"), $bkKilled];
// a move that fails half way: what was moved goes back, nothing of the backup stays
file_put_contents("$bk/data/users.json", '{"u3":{}}');
@mkdir("$bk/out5");
MuDesk\Backup::extract("$bk/b4", 'Clave-de-copia-1', "$bk/out5");
$moves = 0;
MuDesk\Backup::$move = static function (string $a, string $b) use (&$moves): bool {
    return ++$moves !== 4 && @rename($a, $b);          // (the second item's, into place; the moves back work)
};
$r5 = MuDesk\Backup::restore("$bk/out5");
MuDesk\Backup::$move = null;
$bkSeen[] = [$r5, (string)file_get_contents("$bk/data/users.json"), is_file("$bk/data/settings.json"), is_dir("$bk/data/connections"), glob("$bk/data/tmp/restore-old-*") ?: []];
// a folder made again meanwhile where one goes (by a request, before Config::load waited for the restore): that move fails,
// and what was there goes back all the same (before, it was left aside and removed: every connection lost)
file_put_contents("$bk/data/connections/u1.json", '{"c1":{"host":"antes"}}');
@mkdir("$bk/out7");
MuDesk\Backup::extract("$bk/b4", 'Clave-de-copia-1', "$bk/out7");
MuDesk\Backup::$move = static function (string $a, string $b): bool {
    if (str_ends_with(str_replace('\\', '/', $a), '/out7/connections')) {
        @mkdir($b, 0700);
        return false;
    }
    return @rename($a, $b);
};
$r7 = MuDesk\Backup::restore("$bk/out7");
$bkSeen[] = [$r7, (string)@file_get_contents("$bk/data/connections/u1.json"), (string)@file_get_contents("$bk/data/users.json"), glob("$bk/data/restore-kept-*") ?: []];
// what cannot go back is never removed: kept in the data folder, said so
@mkdir("$bk/out8");
MuDesk\Backup::extract("$bk/b4", 'Clave-de-copia-1', "$bk/out8");
MuDesk\Backup::$move = static function (string $a, string $b): bool {
    $a = str_replace('\\', '/', $a);
    return !str_ends_with($a, '/out8/users.json') && !str_contains($a, '/restore-old-') && @rename($a, $b);
};
$elog = ini_set('error_log', "$bk/errors.log");
$r8   = MuDesk\Backup::restore("$bk/out8");
ini_set('error_log', (string)$elog);
MuDesk\Backup::$move = null;
$kept8 = (glob("$bk/data/restore-kept-*") ?: [''])[0];
$bkSeen[] = [$r8, $kept8 !== '' && is_file("$kept8/users.json") && is_file("$kept8/connections/u1.json"), is_file("$bk/data/users.json"),
    $kept8 !== '' && str_contains((string)@file_get_contents("$bk/errors.log"), basename($kept8))];
foreach ($kept8 !== '' ? array_diff(scandir($kept8) ?: [], ['.', '..']) : [] as $item) {   // (back in place by hand)
    MuDesk\Files::remove("$bk/data/$item");
    rename("$kept8/$item", "$bk/data/$item");
}
$kept8 !== '' && rmdir($kept8);
// while a restore moves them, the data folder's folders are not made again by a request (they stood in the way)
file_put_contents("$bk/data/run/restoring", (string)time());
MuDesk\Files::remove("$bk/data/hosts");
MuDesk\Config::load("$bk/config.php");
$hostsMeanwhile = is_dir("$bk/data/hosts");
unlink("$bk/data/run/restoring");
MuDesk\Config::load("$bk/config.php");
$bkSeen[] = [$hostsMeanwhile, is_dir("$bk/data/hosts")];
// another installation's backup (a move to a new server): its key and settings from then on, in the same request too (the
// audit's next line is signed with the backup's key)
$bkKeyThere = MuDesk\Crypto::appKey();
\mkdir("$bk/data2", 0700, true);
file_put_contents("$bk/config2.php", '<?php return ["data_dir" => ' . var_export("$bk/data2", true) . '];');
MuDesk\Config::load("$bk/config2.php");
MuDesk\Crypto::forgetAppKey();
MuDesk\Settings::forget();
$keyHere = MuDesk\Crypto::appKey();
MuDesk\Settings::save(['max_sessions_total' => 2]);
\mkdir("$bk/out6");
MuDesk\Backup::extract("$bk/b4", 'Clave-de-copia-1', "$bk/out6");
$r6 = MuDesk\Backup::restore("$bk/out6");
$bkSeen[] = [$r6, $keyHere !== $bkKeyThere, MuDesk\Crypto::appKey() === $bkKeyThere, MuDesk\Settings::get('max_sessions_total')];
MuDesk\Config::load("$tmp/config.php");
MuDesk\Crypto::forgetAppKey();
MuDesk\Settings::forget();
check('backup: what it carries (not what passes, nor another path; a user\'s files whatever their names); written only from what it read (a file that cannot be read, or grows shorter: never a backup taken for good); encrypted in authenticated chunks, read back whole for the server\'s account only; wrong password, changed, cut, longer, not one, newer, a name out of the folder, a full disk: refused; restored in place (a store it did not have goes, the folders it does not carry kept, web sessions ended), a failed move undone (a folder made again meanwhile too), what cannot go back kept; folders not made again meanwhile; another installation\'s: its key and settings at once',
    $bkSeen, [[true, true, true, true, true, false, false, false, false, false, false, false, false, false, false, false, true, true, true, false, false],
    [['audit/2026-11-01.jsonl', 'connections/u1.json', 'secret.php', 'settings.json', 'users.json'], true],
    [true, true, MuDesk\VERSION, 6, [true], true, true], [true, 'backup_restore'],
    [true, false, ['hosts/carpeta.json cannot be read', 'backup_damaged'], ['files/u1/grande.bin grew shorter while it was read', 'backup_damaged']],
    [['backup_password', 0], 'backup_damaged', 'backup_damaged', 'backup_damaged', 'backup_format'], [true, true, 'read', 'backup_damaged'],
    ['backup_version', 'backup_damaged', false],
    [true, '{"u1":{"id":"u1","username":"ana"}}', 'cambiado', false, true, [], false, ["\x09killed"]], ['backup_restore', '{"u3":{}}', true, true, []],
    ['backup_restore', '{"c1":{"host":"antes"}}', '{"u3":{}}', []], ['backup_kept', true, false, true], [false, true], [true, true, true, 20]]);
unset($bigData, $raw1, $raw7, $same, $bkSeen);                                     // (megabytes the tests after need: 128 MB in all)

// ... over the web, in an installation of its own (its users and sessions are restored): prepared with its password (10
// characters at least), downloaded once, by an administrator only; sent in pieces (in order), restored with its password
// (a wrong one keeps the file for another try); every web session ended; meanwhile the gateway answers "restoring"
$ba = "$tmp/bkapp";
@mkdir("$ba/public", 0700, true);
@mkdir("$ba/src", 0700, true);
@mkdir("$ba/data", 0700, true);
foreach (['api.php', 'backup.php'] as $f) {
    copy(__DIR__ . "/../public/$f", "$ba/public/$f");
}
file_put_contents("$ba/src/bootstrap.php", str_replace('SRC', var_export(realpath(__DIR__ . '/../src'), true), $stub));
file_put_contents("$ba/config.php", '<?php return ["data_dir" => ' . var_export("$ba/data", true) . '];');
MuDesk\Config::load("$ba/config.php");
MuDesk\Crypto::forgetAppKey();
MuDesk\Settings::forget();
$baAdmin = MuDesk\Users::create('bkadmin', 'Bk-admin-' . bin2hex(random_bytes(6)), 'admin', false);
$baUser  = MuDesk\Users::create('bkuser', 'Bk-user-' . bin2hex(random_bytes(6)), 'user', false);
MuDesk\Settings::save(['max_sessions_total' => 9]);
$baSess = static function (array $u) use ($ba, $ser): array {
    [$sid, $csrf] = [bin2hex(random_bytes(16)), bin2hex(random_bytes(16))];
    file_put_contents("$ba/data/sessions/sess_$sid", 'uid|' . $ser($u['id']) . 'username|' . $ser($u['username']) . 'sv|i:1;since|i:' . time()
        . ';seen|i:' . time() . ';csrf|' . $ser($csrf) . 'bind|' . $ser(hash('sha256', 'test')));
    return [$sid, $csrf];
};
[$baA, $baU] = [$baSess($baAdmin), $baSess($baUser)];
MuDesk\Config::load("$tmp/config.php");
MuDesk\Crypto::forgetAppKey();
MuDesk\Settings::forget();
$probe = stream_socket_server('tcp://127.0.0.1:0');
$baPort = (int)substr((string)strrchr((string)stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);
$baWeb = proc_open([PHP_BINARY, '-S', "127.0.0.1:$baPort", '-t', "$ba/public"], [1 => ['file', $nul, 'w'], 2 => ['file', $nul, 'w']], $baPipes);
for ($i = 0; $i < 100 && !($ws = @fsockopen('127.0.0.1', $baPort)); $i++) {
    usleep(50000);
}
$ws && fclose($ws);
$baReq = static function (array $who, string $method, string $path, string $body = '') use ($baPort): array {
    $s = fsockopen('127.0.0.1', $baPort, $en, $es, 30);
    fwrite($s, "$method /$path HTTP/1.0\r\nHost: 127.0.0.1\r\nUser-Agent: test\r\nCookie: MUDESKSID={$who[0]}\r\nX-CSRF: {$who[1]}\r\n"
        . "Content-Type: application/octet-stream\r\nContent-Length: " . strlen($body) . "\r\n\r\n$body");
    $r = (string)stream_get_contents($s);
    fclose($s);
    return [(int)substr($r, 9, 3), substr($r, (int)strpos($r, "\r\n\r\n") + 4)];
};
$baApi = static fn(array $who, array $b): array => (static fn(array $r): array => [$r[0], json_decode($r[1], true)])($baReq($who, 'POST', 'api.php', json_encode($b)));
$baSeen = [[$baApi($baA, ['action' => 'admin.backup.prepare', 'password' => 'corta'])[1]['error'] ?? null, $baApi($baU, ['action' => 'admin.backup.prepare', 'password' => 'Clave-larga-1'])[0]]];
// a file the web server cannot read: said when the backup is asked for, not a backup cut short (where a file can be made so:
// not on Windows, nor for root)
file_put_contents("$ba/data/hosts/ilegible.json", '{}');
@chmod("$ba/data/hosts/ilegible.json", 0);
clearstatcache();
$baSeen[0][] = is_readable("$ba/data/hosts/ilegible.json") ? 'backup_unreadable'
    : ($baApi($baA, ['action' => 'admin.backup.prepare', 'password' => 'Clave-de-copia-1'])[1]['error'] ?? null);
@chmod("$ba/data/hosts/ilegible.json", 0600);
@unlink("$ba/data/hosts/ilegible.json");
[, $prep] = $baApi($baA, ['action' => 'admin.backup.prepare', 'password' => 'Clave-de-copia-1', 'with' => ['files', 'nada']]);
[$dlSt, $dl] = $baReq($baA, 'GET', 'backup.php?a=download&id=' . ($prep['id'] ?? ''));
$baSeen[] = [$dlSt, str_starts_with($dl, MuDesk\Backup::MAGIC), $baReq($baA, 'GET', 'backup.php?a=download&id=' . ($prep['id'] ?? ''))[0],
    $baReq($baU, 'GET', 'backup.php?a=download&id=' . ($prep['id'] ?? ''))[0]];
// after it: another setting and another user (the restore takes them back)
MuDesk\Config::load("$ba/config.php");
MuDesk\Settings::save(['max_sessions_total' => 3]);
MuDesk\Users::create('despues', 'Despues-' . bin2hex(random_bytes(6)), 'user', false);
MuDesk\Config::load("$tmp/config.php");
$upId = bin2hex(random_bytes(8));
$half = intdiv(strlen($dl), 2);
$up = [$baReq($baA, 'POST', "backup.php?a=upload&id=$upId&o=0", substr($dl, 0, $half))[0], json_decode($baReq($baA, 'POST', "backup.php?a=upload&id=$upId&o=5", 'x')[1], true)['error'] ?? null,
    $baReq($baA, 'POST', "backup.php?a=upload&id=$upId&o=$half", substr($dl, $half))[0], $baReq($baU, 'POST', "backup.php?a=upload&id=$upId&o=0", 'x')[0]];
$baSeen[] = $up;
$wrongPw = $baApi($baA, ['action' => 'admin.backup.restore', 'id' => $upId, 'password' => 'Otra-clave-99']);
file_put_contents("$ba/data/run/restoring", (string)time());                // a restore under way: the gateway waits
$busy = $baApi($baU, ['action' => 'state']);
file_put_contents("$ba/data/run/restoring", (string)(time() - 400));       // (one cut off long ago: no longer)
$stale = $baApi($baU, ['action' => 'state'])[0];
@unlink("$ba/data/run/restoring");
$okRestore = $baApi($baA, ['action' => 'admin.backup.restore', 'id' => $upId, 'password' => 'Clave-de-copia-1']);
$after = $baApi($baA, ['action' => 'state'])[1];
MuDesk\Config::load("$ba/config.php");
MuDesk\Crypto::forgetAppKey();
MuDesk\Settings::forget();
$baSeen[] = [$wrongPw[1]['error'] ?? null, $busy, $stale, $okRestore, $after['user'] ?? 'signed out', MuDesk\Settings::get('max_sessions_total'), MuDesk\Users::byName('despues'),
    glob("$ba/data/tmp/restore-*") ?: [], array_column(MuDesk\Audit::search(['event' => 'backup_'])['rows'], 'event')];
MuDesk\Config::load("$tmp/config.php");
MuDesk\Crypto::forgetAppKey();
MuDesk\Settings::forget();
proc_terminate($baWeb);
proc_close($baWeb);
MuDesk\Files::remove($ba);
MuDesk\Files::remove($bk);
check('backup over the web: prepared with a password of 10 characters at least (a file the server cannot read said then), downloaded once, by an administrator only; sent in pieces in order, restored with its password (a wrong one keeps it), every session ended; "restoring" meanwhile',
    $baSeen, [['backup_weak', 403, 'backup_unreadable'], [200, true, 404, 403], [200, 'offset', 200, 403],
    ['backup_password', [503, ['error' => 'restoring']], 200, [200, ['ok' => true]], 'signed out', 9, null, [], ['backup_restore']]]);

// users who are not administrators make no connections unless the administrator allows it (off by default): no new one, no
// .rdp import (the same request), and their own keep their target (options and name may change); administrators always can
$cc0  = $asUser(['action' => 'state'])[1]['user']['create_conns'] ?? null;
check('state: the version told to a signed-in user', is_string($asUser(['action' => 'state'])[1]['version'] ?? null), true);   // (this test server's own)
$ccA  = $asUser(['action' => 'conns.save', 'conn' => ['name' => 'Nueva', 'host' => 'pc.nueva']]);
$ccOwn = MuDesk\Connections::save($wu['id'], ['name' => 'Mía', 'host' => 'pc.mia', 'audio' => true], null);
$ccOpt = $asUser(['action' => 'conns.save', 'conn' => ['id' => $ccOwn['id'], 'name' => 'Mía (otra)', 'host' => 'pc.mia', 'audio' => false]]);
$ccMove = $asUser(['action' => 'conns.save', 'conn' => ['id' => $ccOwn['id'], 'name' => 'Mía', 'host' => 'otro.pc']]);
$ccAdm = $asAdmin(['action' => 'conns.save', 'conn' => ['name' => 'Del admin', 'host' => 'pc.admin']]);
$asAdmin(['action' => 'admin.settings', 'settings' => ['users_create_conns' => true]]);
$cc1  = $asUser(['action' => 'state'])[1]['user']['create_conns'] ?? null;
$ccB  = $asUser(['action' => 'conns.save', 'conn' => ['name' => 'Nueva', 'host' => 'pc.nueva']]);
$asAdmin(['action' => 'admin.settings', 'settings' => ['users_create_conns' => false]]);
check('connections by users: off by default (none new, none pointed elsewhere; options kept adjustable); allowed by the administrator; administrators always', [
    $cc0, $ccA[0], $ccA[1]['error'] ?? null, $ccOpt[0], [$ccOpt[1]['conn']['name'] ?? null, $ccOpt[1]['conn']['audio'] ?? null], $ccMove[0], $ccMove[1]['error'] ?? null,
    MuDesk\Connections::all($wu['id'])[$ccOwn['id']]['host'] ?? null, $ccAdm[0], $cc1, $ccB[0], $ccB[1]['conn']['host'] ?? null],
    [false, 403, 'conns_locked', 200, ['Mía (otra)', false], 403, 'conns_locked', 'pc.mia', 200, true, 200, 'pc.nueva']);
MuDesk\Connections::delete($wu['id'], $ccOwn['id']);
MuDesk\Connections::delete($wu['id'], $ccB[1]['conn']['id'] ?? '');
// a manual connection, not saved (only for whoever may make connections): kept in the web session, never among the user's
// connections nor in their file, named after its computer, with no password (asked for when connecting) and not recorded by
// itself; listed apart for its tab, in that web session only; its session refused once the administrator stops allowing
// them (an unknown connection), else it goes on as a saved one's (here to a computer not allowed)
$ocNo   = $asUser(['action' => 'conns.occasional', 'conn' => ['host' => 'pc.ocasional']]);
$asAdmin(['action' => 'admin.settings', 'settings' => ['users_create_conns' => true]]);
$ocA    = $asUser(['action' => 'conns.occasional', 'conn' => ['host' => 'PC.Ocasional', 'port' => 3390, 'name' => 'Otro nombre', 'record' => true,
                                                               'password' => 'no-se-guarda', 'ask' => false]]);
$ocBad  = $asUser(['action' => 'conns.occasional', 'conn' => ['host' => 'no es un equipo']]);
$ocId   = (string)($ocA[1]['conn']['id'] ?? '');
$ocList = $asUser(['action' => 'conns.list'])[1];
$ocFile = (string)@file_get_contents("$tmp/data/connections/{$wu['id']}.json");
[, $ocOn] = $wres($stream($ocId, bin2hex(random_bytes(16))));
$ocAdmin = $asAdmin(['action' => 'conns.list'])[1];
$asAdmin(['action' => 'admin.settings', 'settings' => ['users_create_conns' => false]]);
[, $ocOff] = $wres($stream($ocId, bin2hex(random_bytes(16))));
$ocC    = $ocA[1]['conn'] ?? [];
$ocLog  = MuDesk\Audit::search(['event' => 'rdp_denied', 'user' => 'webuser'])['rows'];
check('manual connections: only for whoever may make connections; kept in the web session, not saved; named after the computer, no password, not recorded; their session refused once not allowed', [
    $ocNo[0], $ocNo[1]['error'] ?? null, $ocA[0], [$ocC['name'] ?? null, $ocC['host'] ?? null, $ocC['port'] ?? null, $ocC['record'] ?? null, $ocC['ask'] ?? null,
    $ocC['occasional'] ?? null, $ocC['has_pass'] ?? null], $ocId[0] ?? '', $ocBad[1]['error'] ?? null, in_array($ocId, array_column($ocList['conns'] ?? [], 'id'), true),
    array_column($ocList['occasional'] ?? [], 'id'), str_contains($ocFile, 'ocasional'), $endsOf($ocOn), $ocAdmin['occasional'] ?? null, $endsOf($ocOff),
    [$ocLog[1]['occasional'] ?? null, $ocLog[1]['reason'] ?? null]],
    [403, 'conns_locked', 200, ['pc.ocasional', 'pc.ocasional', 3390, false, true, true, false], 'o', 'host', false, [$ocId], false, ['target_denied'], [],
     ['invalid_request'], [true, 'target_not_allowed']]);
// ... its computer's certificate pinned as a saved connection's is, the user's for that computer (the next manual connection
// to it checks it), and another one refused as a changed certificate
$asAdmin(['action' => 'admin.settings', 'settings' => ['users_create_conns' => true]]);
$ocPin = static function (string $seed) use ($cnf, $nul, $wu, $asUser, $wreq, $wres, $endsOf): array {
    $bp   = proc_open([PHP_BINARY, __DIR__ . '/fixtures/broker_server.php', $cnf, 'count'], [1 => ['pipe', 'w'], 2 => ['file', $nul, 'w']], $bpipes);
    $port = (int)trim((string)fgets($bpipes[1]));
    $seed !== '' && MuDesk\Store::update('hosts/' . $wu['id'], static function (array &$d) use ($port, $seed): void {
        $d["rdp:127.0.0.1:$port"] = $seed;
    });
    $id = $asUser(['action' => 'conns.occasional', 'conn' => ['host' => '127.0.0.1', 'port' => $port, 'user' => 'otro', 'security' => 'tls',
        'clipboard' => false, 'drive' => false, 'printer' => false, 'audio' => false]])[1]['conn']['id'] ?? '';
    [, $body] = $wres($wreq('rdp.php?a=stream', json_encode(['c' => $id, 'sid' => bin2hex(random_bytes(16)), 'w' => 800, 'h' => 600])));
    while (($l = fgets($bpipes[1])) !== false && trim($l) !== 'done') {
    }
    proc_close($bp);
    return [$endsOf($body), strlen(MuDesk\Store::read('hosts/' . $wu['id'])["rdp:127.0.0.1:$port"] ?? '')];
};
$ocFirst   = $ocPin('');
$ocChanged = $ocPin(str_repeat('A', 64));
$asAdmin(['action' => 'admin.settings', 'settings' => ['users_create_conns' => false]]);
MuDesk\Store::delete('hosts/' . $wu['id']);
check('manual connections: the computer\'s certificate pinned for the user (first use), another one refused as changed', [$ocFirst, $ocChanged],
    [[['target_denied'], 64], [['cert_changed'], 64]]);

// the auditor role: given by an administrator (an unknown role is a plain user); reads the audit log (search, export key,
// chain check) and the active sessions, and nothing else of the administration: no users, connections, settings or traces,
// and no session ended
[, $na] = $asAdmin(['action' => 'admin.user.save', 'username' => 'auditora', 'password' => 'Solo-lectura-' . bin2hex(random_bytes(4)), 'role' => 'auditor']);
[, $nb] = $asAdmin(['action' => 'admin.user.save', 'username' => 'rolraro', 'password' => 'Otra-clave-' . bin2hex(random_bytes(4)), 'role' => 'root']);
$au = MuDesk\Users::byName('auditora');
MuDesk\Users::update($au['id'], static fn(array &$x) => $x['must_change'] = false);
[$dsid, $dcsrf] = [bin2hex(random_bytes(16)), bin2hex(random_bytes(16))];
file_put_contents("$tmp/data/sessions/sess_$dsid", 'uid|' . $ser($au['id']) . 'username|' . $ser('auditora') . 'sv|i:1;since|i:' . time()
    . ';seen|i:' . time() . ';csrf|' . $ser($dcsrf) . 'bind|' . $ser(hash('sha256', 'test')));
$asAuditor = static fn(array $b): array => $areq([$dsid, $dcsrf], 'api.php', json_encode($b));
$audCodes  = array_map(static fn(array $b): int => $asAuditor($b)[0], [
    ['action' => 'admin.audit', 'filter' => ['from' => date('Y-m-d'), 'to' => date('Y-m-d')]], ['action' => 'admin.audit.key'], ['action' => 'admin.audit.verify', 'day' => date('Y-m-d')],
    ['action' => 'admin.sessions'], ['action' => 'admin.sessions.kill', 'sid' => str_repeat('ab', 16)], ['action' => 'admin.users'],
    ['action' => 'admin.user.save', 'id' => $au['id'], 'role' => 'admin'], ['action' => 'admin.conns'], ['action' => 'admin.settings'], ['action' => 'admin.traces']]);
check('auditor role: given by an administrator; reads the audit and the sessions only, changes nothing; a plain user reads neither', [
    $na['user']['role'] ?? null, $nb['user']['role'] ?? null, $audCodes, MuDesk\Users::get($au['id'])['role'],
    $asUser(['action' => 'admin.audit', 'filter' => []])[0], $asUser(['action' => 'admin.sessions'])[0]],
    ['auditor', 'user', [200, 200, 200, 200, 403, 403, 403, 403, 403, 403], 'auditor', 403, 403]);

// signing in with OpenID Connect over the web, against a provider played by PHP's server (tests/fixtures/oidc_provider.php:
// RS256, the client's secret and PKCE checked): oidc.php sends the browser there with state, nonce and challenge, kept in a
// cookie of its own (the provider's redirect brings Lax cookies, not the Strict session); back with the code, the session
// opens for the user of that e-mail, without a data key (no saved RDP passwords) and with the provider's second step counted;
// a user that does not exist refused unless allowed (then made as an OIDC account, its password changed nowhere here); a
// state that is wrong or used, a domain not allowed and an unverified e-mail refused; off, it says so; audited
$idpDir = "$tmp/idp";
mkdir($idpDir);
openssl_pkey_export_to_file(openssl_pkey_new(['private_key_bits' => 2048, 'config' => $cnf]), "$idpDir/key.pem", null, ['config' => $cnf]);
$probe = stream_socket_server('tcp://127.0.0.1:0');
$iport = (int)substr((string)strrchr((string)stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);
$idp = proc_open([PHP_BINARY, '-S', "127.0.0.1:$iport", __DIR__ . '/fixtures/oidc_provider.php'], [1 => ['file', $nul, 'w'], 2 => ['file', $nul, 'w']], $ipipes, $idpDir,
    ['IDP_KEY' => "$idpDir/key.pem", 'IDP_ISS' => "http://127.0.0.1:$iport", 'IDP_CLIENT' => 'mudesk', 'IDP_SECRET' => 'secreto-idp'] + getenv());
for ($i = 0; $i < 100 && !($ws = @fsockopen('127.0.0.1', $iport)); $i++) {
    usleep(50000);
}
fclose($ws);
MuDesk\Settings::save(['oidc_issuer' => "http://127.0.0.1:$iport", 'oidc_client_id' => 'mudesk', 'oidc_secret' => 'secreto-idp', 'oidc_label' => 'Prueba',
    'oidc_create' => false, 'oidc_domains' => ['empresa.com'], 'totp_required' => true]);
$get = static function (string $path, array $cookies = []) use ($wport): array {   // [status, headers: name => [values], body]
    $s = fsockopen('127.0.0.1', $wport, $en, $es, 15);
    $c = implode('; ', array_map(static fn($k, $v) => "$k=$v", array_keys($cookies), $cookies));
    fwrite($s, "GET /$path HTTP/1.0\r\nHost: 127.0.0.1:$wport\r\nUser-Agent: test\r\n" . ($c !== '' ? "Cookie: $c\r\n" : '') . "\r\n");
    $r = (string)stream_get_contents($s);
    fclose($s);
    [$head, $body] = explode("\r\n\r\n", $r, 2) + [1 => ''];
    $h = [];
    foreach (array_slice(explode("\r\n", $head), 1) as $l) {
        [$k, $v] = explode(':', $l, 2) + [1 => ''];
        $h[strtolower(trim($k))][] = trim($v);
    }
    return [(int)substr($r, 9, 3), $h, $body];
};
$cookiesOf = static function (array $h): array {
    $out = [];
    foreach ($h['set-cookie'] ?? [] as $c) {
        [$k, $v] = explode('=', explode(';', $c, 2)[0], 2) + [1 => ''];
        $out[$k] = $v;
    }
    return $out;
};
$b64u  = static fn(string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
$round = static function (array $claims, ?string $state = null, ?array $flowCookie = null) use ($get, $cookiesOf, $b64u): array {
    [, $h] = $get('oidc.php?start=1');
    parse_str((string)parse_url($h['location'][0] ?? '', PHP_URL_QUERY), $q);
    $flow = $flowCookie ?? $cookiesOf($h);
    $code = $b64u(json_encode(['nonce' => $q['nonce'] ?? '', 'challenge' => $q['code_challenge'] ?? '', 'claims' => $claims]));
    [$st, $back] = $get('oidc.php?code=' . $code . '&state=' . rawurlencode($state ?? ($q['state'] ?? '')), $flow);
    return [$st, $back['location'][0] ?? '', $cookiesOf($back), $q, $flow];
};
[, $loc0, , $q0]  = $round(['email' => 'nuevo@empresa.com', 'email_verified' => true]);
MuDesk\Settings::save(['oidc_create' => true]);
[$st1, $loc1, $ck1, , $flow1] = $round(['email' => 'nuevo@empresa.com', 'email_verified' => true]);
$sessCookie = ['MUDESKSID' => $ck1['MUDESKSID'] ?? ''];
$ost = static function (array $body) use ($wport, $sessCookie): array {
    $s = fsockopen('127.0.0.1', $wport, $en, $es, 15);
    $b = json_encode($body);
    fwrite($s, "POST /api.php HTTP/1.0\r\nHost: 127.0.0.1\r\nUser-Agent: test\r\nCookie: MUDESKSID={$sessCookie['MUDESKSID']}\r\nX-CSRF: " . ($body['_csrf'] ?? '')
        . "\r\nContent-Type: application/json\r\nContent-Length: " . strlen($b) . "\r\n\r\n$b");
    $r = (string)stream_get_contents($s);
    fclose($s);
    return [(int)substr($r, 9, 3), json_decode(substr($r, (int)strpos($r, "\r\n\r\n") + 4), true)];
};
[, $oState] = $ost(['action' => 'state']);
[$oList]    = $ost(['action' => 'conns.list', '_csrf' => $oState['csrf'] ?? '']);
[, $oPw]    = $ost(['action' => 'profile.password', 'old' => 'x', 'new' => 'Otra-clave-larga-1', '_csrf' => $oState['csrf'] ?? '']);
[, $loc2] = $round(['email' => 'nuevo@empresa.com', 'email_verified' => true], 'estado-falso');
[, $loc3] = $round(['email' => 'nuevo@empresa.com', 'email_verified' => true], null, $flow1);   // the flow of a sign-in already used
[, $loc4] = $round(['email' => 'eva@otra.com', 'email_verified' => true]);
[, $loc5] = $round(['email' => 'sinverificar@empresa.com', 'email_verified' => false]);
[, $loc6] = $round(['email' => 'nuevo@empresa.com', 'email_verified' => true, 'sub' => 'otra-persona']);   // the e-mail given to another account
[, $loc7] = $round(['email' => 'sinmarca@empresa.com']);                                                       // no email_verified (Entra ID)
$jefe = MuDesk\Users::create('jefe@empresa.com', 'Jefe-clave-larga-' . bin2hex(random_bytes(4)), 'admin', false);
[, $loc8] = $round(['email' => 'jefe@empresa.com', 'email_verified' => true, 'sub' => 'jefe-1']);                // an administrator: never linked
$pre = MuDesk\Users::create('pre@empresa.com', 'Pre-clave-larga-' . bin2hex(random_bytes(4)), 'user', false);
[, $loc9] = $round(['email' => 'pre@empresa.com', 'email_verified' => true, 'sub' => 'pre-1']);                  // linked on its first sign-in
[, $loc10] = $round(['email' => 'pre@empresa.com', 'email_verified' => true, 'sub' => 'pre-2']);
$preLink = MuDesk\Users::get($pre['id'])['oidc']['sub'] ?? null;
MuDesk\Users::delete($jefe['id']);                                              // (the next tests count the administrators)
MuDesk\Settings::save(['oidc_issuer' => '', 'totp_required' => false]);
[, $offH] = $get('oidc.php?start=1');
proc_terminate($idp);
proc_close($idp);
$made = MuDesk\Users::byName('nuevo@empresa.com');
check('OpenID Connect over the web: to the provider and back, the user signed in without a data key, made when allowed, refused otherwise; audited', [
    [$q0['client_id'] ?? null, $q0['code_challenge_method'] ?? null, isset($q0['nonce'], $q0['state']), str_ends_with($q0['redirect_uri'] ?? '', '/oidc.php')], $loc0,
    $st1, $loc1, $made['source'] ?? null, $oState['user']['username'] ?? null, $oState['user']['save_passwords'] ?? null, $oState['user']['no_key'] ?? null, $oList,
    $oPw['error'] ?? null, $loc2, $loc3, $loc4, $loc5, $offH['location'][0] ?? null, $oState['user']['totp_required'] ?? null,
    [$loc6, $loc7, $loc8, $loc9, $loc10, $preLink, $made['oidc']['sub'] ?? null],
    array_values(array_intersect(['user_create', 'login_ok'], array_column(MuDesk\Audit::search(['user' => 'nuevo@empresa.com'])['rows'], 'event')))],
    [['mudesk', 'S256', true, true], './?oidc_error=oidc_unknown_user', 302, './', 'oidc', 'nuevo@empresa.com', false, true, 200, 'external_password',
     './?oidc_error=oidc_state', './?oidc_error=oidc_state', './?oidc_error=oidc_domain', './?oidc_error=oidc_claim', './?oidc_error=oidc_off', false,
     ['./?oidc_error=oidc_unknown_user', './?oidc_error=oidc_claim', './?oidc_error=oidc_unknown_user', './', './?oidc_error=oidc_unknown_user', 'pre-1', 'subject-1'],
     ['user_create', 'login_ok']]);

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
// a user changed through the API with some fields only: the others stay as the user has them (before, a field left out
// was taken as its default: any hour, the default sessions, enabled again, the role taken away); null hours still clear them
$keep  = MuDesk\Users::create('conhorario', 'Con-horario-' . bin2hex(random_bytes(6)), 'auditor', false);
$hrs   = ['days' => [1, 2, 3, 4, 5], 'from' => '08:00', 'to' => '18:00'];
$asAdmin(['action' => 'admin.user.save', 'id' => $keep['id'], 'role' => 'auditor', 'enabled' => false, 'max_sessions' => 2, 'hours' => $hrs]);
$fields = static fn(): array => array_intersect_key(MuDesk\Users::get($keep['id']), array_flip(['role', 'enabled', 'max_sessions', 'hours']));
$asAdmin(['action' => 'admin.user.save', 'id' => $keep['id']]);
$keepNone = $fields();
$asAdmin(['action' => 'admin.user.save', 'id' => $keep['id'], 'max_sessions' => 4]);
$keepMax = $fields();
$asAdmin(['action' => 'admin.user.save', 'id' => $keep['id'], 'hours' => null]);
$keepClear = $fields();
MuDesk\Users::delete($keep['id']);
check('users: changed through the API with some fields only, the others kept (hours, sessions, enabled, role); null hours clear them',
    [$keepNone, $keepMax, $keepClear], [
    ['role' => 'auditor', 'enabled' => false, 'max_sessions' => 2, 'hours' => $hrs], ['role' => 'auditor', 'enabled' => false, 'max_sessions' => 4, 'hours' => $hrs],
    ['role' => 'auditor', 'enabled' => false, 'max_sessions' => 4, 'hours' => null]]);
// CSV import over the web: the administrator's (audited with what was added and how many rows were refused), never a user's
[$icode, $ij] = $asAdmin(['action' => 'admin.conns.import', 'csv' => "name,host,users\nImportada,imp.empresa,webuser\nMala,,\n"]);
$impEvent = MuDesk\Audit::search(['event' => 'admin_conns_import'])['rows'][0] ?? [];
$impId    = array_column(MuDesk\Connections::managed(), 'id', 'name')['Importada'] ?? '';
check('CSV import over the web: by the administrator, audited, the connection given to the user named; refused to users', [$icode, $ij['created'] ?? null,
    $ij['errors'] ?? null, $impEvent['created'] ?? null, $impEvent['rows_refused'] ?? null, isset(MuDesk\Connections::all($wu['id'])[$impId]),
    $asUser(['action' => 'admin.conns.import', 'csv' => "host\nx.y\n"])[0], $asAdmin(['action' => 'admin.conns.import', 'csv' => ['host']])[0]],
    [200, ['Importada'], [[3, 'host']], 1, 1, true, 403, 400]);
MuDesk\Connections::deleteManaged($impId);
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


// session recording: the picture's frames with their times, encrypted in pieces bound to their recording and their place
// (a changed, swapped or moved piece is refused), read back as written; the clipboard and sound left out; full at the largest
// size (said in its details); removed after the days of the settings
$recFrames = static function (string $b): array {                             // [type, payload, ms] of a recording
    $f = [];
    for ($o = 0; $o + 9 <= strlen($b); $o += 9 + $n) {
        ['ms' => $ms, 't' => $t, 'n' => $n] = unpack('Vms/Ct/Vn', $b, $o);
        $f[] = [$t, substr($b, $o + 9, $n), $ms];
    }
    return $f;
};
$recRead = static function (string $sid): array {                             // [read whole?, what was given]
    $all = '';
    $ok  = MuDesk\Recording::read($sid, static function (string $p) use (&$all): void { $all .= $p; });
    return [$ok, $all];
};
$rsid = bin2hex(random_bytes(16));
$rec  = MuDesk\Recording::start($rsid, ['uid' => $wu['id'], 'user' => 'webuser', 'conn' => 'Rec PC', 'host' => 'h']);
$rec->frame(2, pack('vvC', 1280, 720, 32));
$rec->frame(1, "\x01PICTURE-ONE");
$rec->frame(4, 'CLIPBOARD-SECRET');
$rec->frame(7, 'SOUND');
$rec->frame(2, pack('vvC', 1024, 900, 32));
$rec->frame(3, '{"connected":true}');
for ($w0 = microtime(true); microtime(true) - $w0 < 0.03;) {             // (usleep() on Windows may wake early)
    usleep(2000);
}
$rec->frame(15, pack('vv', 5, 6));
$rec->frame(13, pack('CVC', 1, 44100, 2));
$rec->frame(MuDesk\Recording::MIC, 'MIC-PCM');
$rec->close(['reason' => 'user']);
$micOnly = MuDesk\Recording::newId();
$micRec  = MuDesk\Recording::start($micOnly, ['user' => 'webuser']);
$micRec->frame(MuDesk\Recording::MIC, 'MIC-PCM');
$micRec->close();
$micSound = MuDesk\Recording::info($micOnly)['sound'] ?? null;
$pgId  = MuDesk\Recording::newId();
$pgRec = MuDesk\Recording::start($pgId, ['user' => 'webuser', 'kind' => 'video']);
$pgRec->frame(1, "\x01PICTURE");                               // (the stream's: not kept)
$pgRec->frame(MuDesk\Recording::MIC, 'MIC');
$pgRec->describe(['mime' => 'video/webm;codecs=vp8,opus', 'w' => 1280, 'h' => 720, 'sound' => true]);
$pgRec->frame(MuDesk\Recording::VIDEO, 'WEBM-1');
$pgRec->frame(MuDesk\Recording::VIDEO, 'WEBM-2');
$pgRec->close(['stopped_by' => 'webuser']);
$pgRead = '';
MuDesk\Recording::read($pgId, static function (string $p) use (&$pgRead): void { $pgRead .= $p; });
$pgInfo = MuDesk\Recording::info($pgId);
$pgOdd  = MuDesk\Recording::newId();                            // what a page says it records, out of bounds or of another kind
$pgOddR = MuDesk\Recording::start($pgOdd, ['kind' => 'video']);
$pgOddR->describe(['mime' => 'text/html', 'w' => 99999, 'h' => 720, 'sound' => 0]);   // (wider than RDP allows)
$pgOddR->close();
$pgOddI = MuDesk\Recording::info($pgOdd);
$pgOddR2 = MuDesk\Recording::start($pgOdd2 = MuDesk\Recording::newId(), ['kind' => 'video']);
$pgWarned = false;
set_error_handler(static function () use (&$pgWarned): bool { $pgWarned = true; return true; });
$pgOddR2->describe(['mime' => [], 'w' => [], 'h' => null, 'sound' => []]);   // (a page's JSON of another shape: no warning)
restore_error_handler();
$pgOddR2->close();
$pgRate = MuDesk\Recording::start($pgRateId = MuDesk\Recording::newId(), ['kind' => 'video']);
$pgRate->frame(MuDesk\Recording::VIDEO, str_repeat('v', 5 << 20));
$pgSlow = $pgRate->overRate();
$pgRate->frame(MuDesk\Recording::VIDEO, str_repeat('v', 6 << 20));        // (11 MB in its first second: no video makes it)
$pgFast = $pgRate->overRate();
$pgRate->close();
$stRec  = MuDesk\Recording::newId();                            // one of the stream's frames does not take a video's
$stRecR = MuDesk\Recording::start($stRec, ['user' => 'webuser']);
$stRecR->frame(MuDesk\Recording::VIDEO, 'WEBM');
$stRecR->close();
$stSize = MuDesk\Recording::info($stRec)['bytes'] ?? null;
foreach ([$pgId, $pgOdd, $stRec, $pgOdd2, $pgRateId] as $pgX) {
    @unlink("$tmp/data/recordings/$pgX.rec");
    @unlink("$tmp/data/recordings/$pgX.json");
}
check('recording made in the page: only its video kept (nothing of the stream\'s frames), in order; what the page records in its details, within bounds (no warning for another shape); one of the stream takes no video; one growing faster than a video can is told', [
    array_map(static fn(array $f): string => $f[0] . ':' . $f[1], $recFrames($pgRead)),
    [$pgInfo['kind'] ?? null, $pgInfo['mime'] ?? null, $pgInfo['width'] ?? null, $pgInfo['height'] ?? null, $pgInfo['sound'] ?? null, $pgInfo['stopped_by'] ?? null],
    [$pgOddI['mime'] ?? null, $pgOddI['width'] ?? null, $pgOddI['height'] ?? null, $pgOddI['sound'] ?? null], $stSize, $pgWarned, [$pgSlow, $pgFast]],
    [['33:WEBM-1', '33:WEBM-2'], ['video', 'video/webm', 1280, 720, true, 'webuser'], ['video/webm', null, null, null], 7, false, [false, true]]);
@unlink("$tmp/data/recordings/$micOnly.rec");
@unlink("$tmp/data/recordings/$micOnly.json");
$rraw = (string)file_get_contents("$tmp/data/recordings/$rsid.rec");
[$rok, $rback] = $recRead($rsid);
$rfr  = $recFrames($rback);
$rinf = MuDesk\Recording::info($rsid);
check('recording: the picture\'s, the sound\'s and the microphone\'s frames with their times, encrypted, read back as written; the clipboard left out; its details, the largest desktop', [
    $rok, array_map(static fn($f) => [$f[0], $f[1]], $rfr), ($rfr[5][2] ?? 0) - ($rfr[0][2] ?? 0) >= 25, str_contains($rraw, 'PICTURE'), str_contains($rraw, 'CLIPBOARD'),
    substr($rraw, 0, 7), [$rinf['user'] ?? null, $rinf['conn'] ?? null, $rinf['reason'] ?? null, ($rinf['bytes'] ?? 0) === strlen($rraw), isset($rinf['ended'])],
    [$rinf['id'] ?? null, $rinf['sid'] ?? null, $rinf['width'] ?? null, $rinf['height'] ?? null, $micSound],
    MuDesk\Recording::info(str_repeat('0', 32)), MuDesk\Recording::info('../x')],
    [true, [[2, pack('vvC', 1280, 720, 32)], [1, "\x01PICTURE-ONE"], [7, 'SOUND'], [2, pack('vvC', 1024, 900, 32)], [3, '{"connected":true}'], [15, pack('vv', 5, 6)], [13, pack('CVC', 1, 44100, 2)], [MuDesk\Recording::MIC, 'MIC-PCM']],
     true, false, false, "MDREC1\n", ['webuser', 'Rec PC', 'user', true, true], [$rsid, $rsid, 1280, 900, true], null, null]);
// two pieces (each over 1 MB of picture): changed, moved and swapped into another recording, refused
$r2sid = bin2hex(random_bytes(16));
$r2 = MuDesk\Recording::start($r2sid, ['user' => 'webuser']);
$r2->frame(9, str_repeat('A', 1 << 20));
$r2->frame(9, str_repeat('B', 1 << 20));
$r2->close();
$r4sid = bin2hex(random_bytes(16));
$r4 = MuDesk\Recording::start($r4sid, ['user' => 'webuser']);
$r4->frame(9, $big4 = random_bytes(2700000));   // a graphics message over 2 MB: in pieces of 1 MB
$r4->close();
$r4read = $recRead($r4sid);
$r2file = "$tmp/data/recordings/$r2sid.rec";
$r2raw  = (string)file_get_contents($r2file);
$p1len  = unpack('V', $r2raw, 7)[1];
$pieces = [substr($r2raw, 7, 4 + $p1len), substr($r2raw, 11 + $p1len)];
$whole  = $recRead($r2sid);
file_put_contents($r2file, substr($r2raw, 0, 7) . $pieces[1] . $pieces[0]);    // moved
$moved  = $recRead($r2sid);
$bad    = $r2raw;
$bad[100] = chr(ord($bad[100]) ^ 1);                                             // a byte changed
file_put_contents($r2file, $bad);
$changed = $recRead($r2sid);
file_put_contents($r2file, substr($r2raw, 0, 7) . $pieces[0]);                    // cut after the first piece
$cut = $recRead($r2sid);
file_put_contents("$tmp/data/recordings/$rsid.rec", $r2raw);                      // another recording's pieces
$swapped = $recRead($rsid);
check('recording: a changed, moved or swapped piece is refused (what came before it is given); a cut one gives what it has', [
    $whole[0], strlen($whole[1]), $moved, $changed, [$cut[0], strlen($cut[1])], $swapped, [$r4read[0], $recFrames($r4read[1])[0][1] ?? null] === [true, $big4]],
    [true, 2 * (9 + (1 << 20)), [false, ''], [false, ''], [true, 1 << 20], [false, ''], true]);   // (pieces of 1 MB: a frame may span two)
unset($whole, $cut, $r4read, $big4, $r2raw, $pieces, $bad, $moved, $changed, $swapped);   // (megabytes the tests after need: 128 MB in all)
// a frame whose header comes up to a piece's end (5 bytes short of it), then one of 1.5 MB: read back whole (before, the
// room left was negative and the frame went whole into a piece the reader refuses)
$edgeId = MuDesk\Recording::newId();
$edge   = MuDesk\Recording::start($edgeId, ['user' => 'webuser']);
$edge->frame(9, str_repeat('a', (1 << 20) - 14));
$edge->frame(9, $edgeBig = str_repeat('b', 1536 * 1024));
$edge->close();
$edgeRead = $recRead($edgeId);
$edgeOk   = [$edgeRead[0], array_map(static fn(array $f): int => strlen($f[1]), $recFrames($edgeRead[1])), ($recFrames($edgeRead[1])[1][1] ?? '') === $edgeBig];
@unlink("$tmp/data/recordings/$edgeId.rec");
@unlink("$tmp/data/recordings/$edgeId.json");
check('recording: a frame\'s header up to a piece\'s end, then a large frame: read back whole', $edgeOk, [true, [(1 << 20) - 14, 1536 * 1024], true]);
unset($edgeRead, $edgeBig, $edge);
// full: at the largest size (config.php record_max_mb) nothing more is kept, and its details say so; old ones removed
$cfg = new ReflectionProperty(MuDesk\Config::class, 'c');
$cfg0 = $cfg->getValue();
$cfg->setValue(null, ['record_max_mb' => 1] + $cfg0);
$r3sid = bin2hex(random_bytes(16));
$r3 = MuDesk\Recording::start($r3sid, ['user' => 'webuser']);
$r3->frame(9, str_repeat('C', 700000));
$r3->frame(9, str_repeat('D', 700000));                                         // a piece of 1.4 MB: over 1 MB, not kept
$r3->frame(9, 'E');
$r3->close();
$cfg->setValue(null, $cfg0);
$r3inf = MuDesk\Recording::info($r3sid);
$r3read = $recRead($r3sid);
touch("$tmp/data/recordings/$r3sid.rec", time() - 4000 * 86400);
touch("$tmp/data/recordings/$r2sid.rec", time() - 29 * 86400);
$recDays0 = MuDesk\Settings::get('record_days');
MuDesk\Recording::cleanup();                                                   // (by default: kept until deleted by hand)
$keptOld  = MuDesk\Recording::info($r3sid) !== null;
MuDesk\Settings::save(['record_days' => 30]);
MuDesk\Recording::cleanup();
$recDays30 = MuDesk\Settings::get('record_days');
MuDesk\Settings::save(['record_days' => 0]);
check('recording: full at the largest size (said in its details); kept until deleted by hand (0, by default), else removed after the days of the settings', [
    $r3inf['truncated'] ?? null, ($r3inf['bytes'] ?? 0) < (1 << 20), $r3read, $recDays0, $keptOld, MuDesk\Recording::info($r3sid), is_file("$tmp/data/recordings/$r3sid.json"),
    MuDesk\Recording::info($r2sid) !== null, $recDays30, MuDesk\Settings::get('record_days')],
    [true, true, [true, ''], 0, true, null, false, true, 30, 0]);

// over the web: the administrator records a connection (a user cannot stop it, nor record one); the page is told before
// anything is shown and it is audited; no recording, no session; played only by administrators and auditors, audited
$recConn = MuDesk\Connections::save($wu['id'], ['host' => '127.0.0.1', 'port' => 9, 'security' => 'tls', 'clipboard' => false, 'drive' => false, 'printer' => false,
    'audio' => false, 'record' => true], null);
$userSet = $recConn['record'];
$recConn = MuDesk\Connections::adminUpdate($wu['id'], ['id' => $recConn['id'], 'host' => '127.0.0.1', 'port' => 9, 'security' => 'tls', 'clipboard' => false,
    'drive' => false, 'printer' => false, 'audio' => false, 'record' => true], false);
$kept    = MuDesk\Connections::save($wu['id'], ['id' => $recConn['id'], 'host' => '127.0.0.1', 'port' => 9, 'security' => 'tls', 'clipboard' => false,
    'drive' => false, 'printer' => false, 'audio' => false, 'record' => false], null)['record'];
$frameStatus = static function (string $body): array {
    $st = [];
    for ($o = 0; $o + 5 <= strlen($body); $o += 5 + $len) {
        ['t' => $type, 'n' => $len] = unpack('Ct/Vn', $body, $o);
        if ($type === MuDesk\Rdp\Client::F_STATUS) {
            $st[] = array_keys((array)json_decode(substr($body, $o + 5, $len), true));
        }
    }
    return $st;
};
$recSid = bin2hex(random_bytes(16));
[, $recBody] = $wres($stream($recConn['id'], $recSid));
$recInfo = MuDesk\Recording::info($recSid);
[, $recPlain] = $recRead($recSid);
$recConnect = MuDesk\Audit::search(['event' => 'rdp_connect', 'user' => 'webuser'])['rows'][0] ?? [];
// the recordings folder cannot be written: the session does not start
rename("$tmp/data/recordings", "$tmp/data/recordings-away");
file_put_contents("$tmp/data/recordings", 'not a folder');
[, $noRecBody] = $wres($stream($recConn['id'], bin2hex(random_bytes(16))));
unlink("$tmp/data/recordings");
rename("$tmp/data/recordings-away", "$tmp/data/recordings");
$denied = MuDesk\Audit::search(['event' => 'rdp_denied', 'user' => 'webuser'])['rows'][0] ?? [];
// played back: [u32 ms][u8 type][u32 length][payload]... for an administrator; refused to a user
$replay = static function (array $who, string $sid) use ($wport): array {
    $s = fsockopen('127.0.0.1', $wport, $en, $es, 5);
    $b = json_encode(['sid' => $sid]);
    fwrite($s, "POST /rdp.php?a=replay HTTP/1.0\r\nHost: 127.0.0.1\r\nUser-Agent: test\r\nCookie: MUDESKSID={$who[0]}\r\nX-CSRF: {$who[1]}\r\n"
        . "Content-Type: application/json\r\nContent-Length: " . strlen($b) . "\r\n\r\n$b");
    $r = (string)stream_get_contents($s);
    fclose($s);
    return [(int)substr($r, 9, 3), substr($r, (int)strpos($r, "\r\n\r\n") + 4)];
};
$asUserReplay  = $replay([$wsid, $wcsrf], $recSid);
$asAdminReplay = $replay([$asid, $acsrf], $recSid);
$viewLog = MuDesk\Audit::search(['event' => 'recording_view'])['rows'][0] ?? [];
[$infoSt, $infoJ] = $asAdmin(['action' => 'admin.recording', 'sid' => $recSid]);
check('recording over the web: set by the administrator only; the page told first; audited; no recording, no session; played by administrators only (not by its user: not found), audited', [
    $userSet, $recConn['record'] ?? null, $kept, $frameStatus($recBody)[1] ?? null, ($recInfo['user'] ?? null) === 'webuser', $recFrames($recPlain)[0][0] ?? null,
    str_contains($recPlain, '"token"'), $recConnect['recording'] ?? null, $frameStatus($noRecBody), $denied['reason'] ?? null,
    $asUserReplay[0], $asAdminReplay[0], $asAdminReplay[1] === $recPlain . pack('VCV', 0, 0, 3) . 'end', [$viewLog['user'] ?? null, $viewLog['owner'] ?? null, $viewLog['sid'] ?? null],
    [$infoSt, $infoJ['recording']['conn'] ?? null], $asUser(['action' => 'admin.recording', 'sid' => $recSid])[0]],
    [false, true, true, ['recording', 'record_by'], true, MuDesk\Rdp\Client::F_STATUS, false, true, [['token', 'trace'], ['end']], 'record_failed',
     404, 200, true, ['webadmin', 'webuser', $recSid], [200, $recConn['name']], 404]);
MuDesk\Connections::delete($wu['id'], $recConn['id']);
// an administrator's own connection, from their own editor: "Record the sessions" set and cleared there; a save without it
// (several .rdp files imported at once) keeps it; a user's own one, never (the same request)
$recOwn = static fn(callable $as, array $c) => $as(['action' => 'conns.save', 'conn' => $c + ['host' => '127.0.0.1', 'port' => 9, 'name' => 'Grabada']]);
[$roSt, $roJ] = $recOwn($asAdmin, ['record' => true]);
$roId   = $roJ['conn']['id'] ?? '';
$roKept = $recOwn($asAdmin, ['id' => $roId, 'name' => 'Grabada 2'])[1]['conn']['record'] ?? null;
$roOff  = $recOwn($asAdmin, ['id' => $roId, 'record' => false])[1]['conn']['record'] ?? null;
$ruId   = MuDesk\Connections::save($wu['id'], ['host' => '127.0.0.1', 'port' => 9, 'name' => 'De un usuario'], null)['id'];   // (users may not make them here)
[$ruSt, $ruJ] = $recOwn($asUser, ['id' => $ruId, 'name' => 'De un usuario', 'record' => true]);
check('recording: an administrator sets it in their own editor too (kept by a save without it, cleared there); a user cannot',
    [$roSt, $roJ['conn']['record'] ?? null, $roKept, $roOff, $ruSt, $ruJ['conn']['record'] ?? null], [200, true, true, false, 200, false]);
$asAdmin(['action' => 'conns.delete', 'id' => $roId]);
MuDesk\Connections::delete($wu['id'], $ruId);
// the recorded computer reached by another name or by its address: recorded too (before, only by the same name)
$recA = MuDesk\Connections::save($wu['id'], ['host' => '127.0.0.1', 'port' => 3389, 'name' => 'Por IP'], null);
MuDesk\Connections::adminUpdate($wu['id'], ['id' => $recA['id'], 'host' => '127.0.0.1', 'port' => 3389, 'name' => 'Por IP', 'record' => true], false);
check('recording: the recorded computer by another name or its address is recorded too; another port, not',
    [MuDesk\Connections::recordedTarget($wu['id'], ['host' => 'localhost', 'port' => 3389]), MuDesk\Connections::recordedTarget($wu['id'], ['host' => '::ffff:127.0.0.1', 'port' => 3389]),
     MuDesk\Connections::recordedTarget($wu['id'], ['host' => 'localhost', 'port' => 3390]), MuDesk\Connections::recordedTarget($wu['id'], ['host' => '127.0.0.2', 'port' => 3389])],
    [true, true, false, false]);
MuDesk\Connections::delete($wu['id'], $recA['id']);

// a recording started and stopped in a running session: by an administrator, any session; by its user, their own when the
// settings allow it (record_users) and an administrator's or the connection's never stopped by them; RemoteApp, no; another
// user's session, not found. The session is told (Sessions::record), as the page's own input never can
$rsSid  = bin2hex(random_bytes(16));
$rsSess = MuDesk\Sessions::register($rsSid, ['uid' => $wu['id'], 'user' => 'webuser', 'conn' => 'Rec on demand', 'host' => '127.0.0.1', 'token' => hash('sha256', 'rs-token')]);
$rsApp  = bin2hex(random_bytes(16));
$rsAppS = MuDesk\Sessions::register($rsApp, ['uid' => $wu['id'], 'user' => 'webuser', 'conn' => 'App', 'host' => '127.0.0.1', 'app' => true, 'token' => hash('sha256', 'x')]);
$rsOther = bin2hex(random_bytes(16));
$rsOtherS = MuDesk\Sessions::register($rsOther, ['uid' => 'someone-else', 'user' => 'otro', 'conn' => 'X', 'host' => '127.0.0.1', 'token' => hash('sha256', 'y')]);
$rsMsgs = static function () use ($rsSess): array {
    $got = [];
    for ($end = microtime(true) + 3; microtime(true) < $end && $got === []; usleep(20000)) {
        array_push($got, ...$rsSess->read());
    }
    return array_map(static fn(string $m) => [ord($m[0]), json_decode(substr($m, 1), true)], $got);
};
$rsOff    = $asUser(['action' => 'session.record', 'sid' => $rsSid, 'on' => true])[0];
MuDesk\Settings::save(['record_users' => true]);
$rsState  = $asUser(['action' => 'state'])[1]['user']['record_own'] ?? null;
$rsOn     = [$asUser(['action' => 'session.record', 'sid' => $rsSid, 'on' => true])[0], $rsMsgs()];
MuDesk\Sessions::recording($rsSid, 'policy');
$rsListed = array_values(array_filter(MuDesk\Sessions::active(), static fn($s) => $s['sid'] === $rsSid))[0] ?? [];
$rsLocked = $asUser(['action' => 'session.record', 'sid' => $rsSid, 'on' => false]);
$rsAdmin  = [$asAdmin(['action' => 'admin.sessions.record', 'sid' => $rsSid, 'on' => false])[0], $rsMsgs()];
MuDesk\Sessions::recording($rsSid, 'owner');
$rsOwnStop = [$asUser(['action' => 'session.record', 'sid' => $rsSid, 'on' => false])[0], $rsMsgs()];
$rsAppSt  = $asUser(['action' => 'session.record', 'sid' => $rsApp, 'on' => true]);
$rsOthSt  = $asUser(['action' => 'session.record', 'sid' => $rsOther, 'on' => true])[0];
$rsUserAdm = $asUser(['action' => 'admin.sessions.record', 'sid' => $rsSid, 'on' => true])[0];
MuDesk\Settings::save(['record_users' => false]);
$rsSess->close();
$rsAppS->close();
$rsOtherS->close();
check('recording in a session: an administrator any session, its user when allowed (never an administrator\'s or the connection\'s stopped); RemoteApp and others\' sessions refused', [
    $rsOff, $rsState, $rsOn, [$rsListed['record'] ?? null, $rsListed['record_by'] ?? null], [$rsLocked[0], $rsLocked[1]['error'] ?? null], $rsAdmin, $rsOwnStop,
    [$rsAppSt[0], $rsAppSt[1]['error'] ?? null], $rsOthSt, $rsUserAdm],
    [403, true, [200, [[0x23, ['on' => true, 'by' => 'webuser', 'as' => 'owner']]]], [true, 'policy'], [403, 'record_locked'],
     [200, [[0x23, ['on' => false, 'by' => 'webadmin', 'as' => 'admin']]]], [200, [[0x23, ['on' => false, 'by' => 'webuser', 'as' => 'owner']]]],
     [400, 'record_unavailable'], 404, 403]);
// a user's own recordings (those they started in their own sessions): listed for them, watched, deleted (audited; not while
// it is being recorded); an administrator's or a connection's recording of their session, or another user's, never theirs
$myRec = static function (string $uid, string $as, bool $close = true, ?string $sid = null): string {
    $id = MuDesk\Recording::newId();
    $r  = MuDesk\Recording::start($id, ['sid' => $sid ?? bin2hex(random_bytes(16)), 'uid' => $uid, 'user' => 'u', 'conn' => "Rec $as", 'host' => 'h', 'started_as' => $as]);
    $r->frame(3, '{"connected":true}');
    $close && $r->close(['stopped_by' => 'u']);
    return $id;
};
$mrLiveSid = bin2hex(random_bytes(16));                                  // (its session runs: its file in data/run, fresh)
file_put_contents("$tmp/data/run/$mrLiveSid.json", json_encode(['sid' => $mrLiveSid, 'uid' => $wu['id'], 'user' => 'webuser']));
[$mrOwn, $mrAdmin, $mrPolicy, $mrOther, $mrLive] = [$myRec($wu['id'], 'owner'), $myRec($wu['id'], 'admin'), $myRec($wu['id'], 'policy'),
    $myRec('someone-else', 'owner'), $myRec($wu['id'], 'owner', false, $mrLiveSid)];
// an administrator's own: one they started in their own session (as an administrator); not one another administrator
// started in it, nor their connection's
$mrAdmOwn = MuDesk\Recording::newId();
MuDesk\Recording::start($mrAdmOwn, ['sid' => bin2hex(random_bytes(16)), 'uid' => $wa['id'], 'user' => 'webadmin', 'by' => 'webadmin', 'started_as' => 'admin'])->close();
$mrAdmBy  = MuDesk\Recording::newId();
MuDesk\Recording::start($mrAdmBy, ['sid' => bin2hex(random_bytes(16)), 'uid' => $wa['id'], 'user' => 'webadmin', 'by' => 'otra-jefa', 'started_as' => 'admin'])->close();
$mrAdmPol = MuDesk\Recording::newId();
MuDesk\Recording::start($mrAdmPol, ['sid' => bin2hex(random_bytes(16)), 'uid' => $wa['id'], 'user' => 'webadmin', 'started_as' => 'policy'])->close();
$mrAdmList = array_values(array_intersect([$mrAdmOwn, $mrAdmBy, $mrAdmPol], array_column($asAdmin(['action' => 'recordings.mine'])[1]['recordings'] ?? [], 'id')));
$mrAdmUser = array_values(array_intersect([$mrAdmOwn, $mrAdmBy, $mrAdmPol], array_column($asUser(['action' => 'recordings.mine'])[1]['recordings'] ?? [], 'id')));
foreach ([$mrAdmOwn, $mrAdmBy, $mrAdmPol] as $mrId) {
    @unlink("$tmp/data/recordings/$mrId.rec");
    @unlink("$tmp/data/recordings/$mrId.json");
}
check('recordings: an administrator\'s own are those they started in their own session (not another administrator\'s in it, nor their connection\'s); none of them a user\'s',
    [$mrAdmList, $mrAdmUser], [[$mrAdmOwn], []]);
$mrLeft  = $myRec($wu['id'], 'owner', false);                          // never closed: its process killed (no session runs)
$mrStale = $myRec($wu['id'], 'owner', false, $mrStaleSid = bin2hex(random_bytes(16)));   // its session's file left, not touched for long
file_put_contents("$tmp/data/run/$mrStaleSid.json", '{}');
touch("$tmp/data/run/$mrStaleSid.json", time() - 600);
$mrUnclosed = [MuDesk\Recording::running(MuDesk\Recording::info($mrLive)), MuDesk\Recording::running(MuDesk\Recording::info($mrLeft)),
    MuDesk\Recording::running(MuDesk\Recording::info($mrStale)), array_column($asUser(['action' => 'recordings.mine'])[1]['recordings'] ?? [], 'running', 'id')[$mrLeft] ?? null,
    $asUser(['action' => 'recordings.delete', 'id' => $mrLeft])[0], $asUser(['action' => 'recordings.delete', 'id' => $mrStale])[0]];
@unlink("$tmp/data/run/$mrStaleSid.json");
check('recordings: one not closed is being recorded while its session runs; one left by a process killed (no session, or its file stale) is not, and can be deleted',
    $mrUnclosed, [true, false, false, false, 200, 200]);
$mrList   = array_column($asUser(['action' => 'recordings.mine'])[1]['recordings'] ?? [], 'id');
$mrAll    = array_column($asAdmin(['action' => 'admin.recordings'])[1]['recordings'] ?? [], 'started_as', 'id');   // every one, for an administrator
$mrAllAs  = [$mrAll[$mrOwn] ?? null, $mrAll[$mrAdmin] ?? null, $mrAll[$mrPolicy] ?? null, $mrAll[$mrOther] ?? null, $mrAll[$mrLive] ?? null,
    $asUser(['action' => 'admin.recordings'])[0]];
$mrInfo   = [$asUser(['action' => 'recording.info', 'sid' => $mrOwn])[0], $asUser(['action' => 'recording.info', 'sid' => $mrAdmin])[0],
    $asUser(['action' => 'recording.info', 'sid' => $mrOther])[0], $asAdmin(['action' => 'recording.info', 'sid' => $mrAdmin])[0]];
$mrPlay   = [$replay([$wsid, $wcsrf], $mrOwn)[0], $replay([$wsid, $wcsrf], $mrPolicy)[0]];
$mrDelete = [$asUser(['action' => 'recordings.delete', 'id' => $mrAdmin]), $asUser(['action' => 'recordings.delete', 'id' => $mrOther])[0],
    $asUser(['action' => 'recordings.delete', 'id' => $mrLive]), $asUser(['action' => 'recordings.delete', 'id' => $mrOwn])[0]];
$mrGone   = [is_file("$tmp/data/recordings/$mrOwn.rec"), is_file("$tmp/data/recordings/$mrOwn.json"), is_file("$tmp/data/recordings/$mrAdmin.rec"), is_file("$tmp/data/recordings/$mrLive.rec")];
$mrAudit  = MuDesk\Audit::search(['event' => 'recording_delete', 'user' => 'webuser'])['rows'][0] ?? [];
check('recordings: a user\'s own listed for them, watched and deleted (asked of them, audited; not while being recorded); an administrator\'s or a connection\'s of their session, or another user\'s, never; every one for an administrator',
    [array_values(array_intersect([$mrOwn, $mrAdmin, $mrPolicy, $mrOther, $mrLive], $mrList)), $mrAllAs, $mrInfo, $mrPlay,
     [$mrDelete[0][0], $mrDelete[1], $mrDelete[2][0], $mrDelete[2][1]['error'] ?? null, $mrDelete[3]], $mrGone, [$mrAudit['rec'] ?? null, $mrAudit['conn'] ?? null]],
    [[$mrOwn, $mrLive], ['owner', 'admin', 'policy', 'owner', 'owner', 403], [200, 404, 404, 200], [200, 404], [404, 404, 400, 'recording_running', 200], [false, false, true, true], [$mrOwn, 'Rec owner']]);
// an administrator deletes any recording (asked of them in the page; not one being recorded; audited, whose it was); an
// auditor only reads, a user only theirs
$adDel   = [$asAuditor(['action' => 'admin.recordings.delete', 'id' => $mrAdmin])[0], $asUser(['action' => 'admin.recordings.delete', 'id' => $mrAdmin])[0],
    $asAdmin(['action' => 'admin.recordings.delete', 'id' => $mrLive]), $asAdmin(['action' => 'admin.recordings.delete', 'id' => $mrOther])[0],
    $asAdmin(['action' => 'admin.recordings.delete', 'id' => str_repeat('0', 32)])[0]];
$adGone  = [is_file("$tmp/data/recordings/$mrOther.rec"), is_file("$tmp/data/recordings/$mrOther.json"), is_file("$tmp/data/recordings/$mrAdmin.rec"),
    is_file("$tmp/data/recordings/$mrLive.rec")];
$adAudit = MuDesk\Audit::search(['event' => 'recording_delete', 'user' => 'webadmin'])['rows'][0] ?? [];
check('recordings: an administrator deletes any one (not one being recorded; audited, whose it was), an auditor or a user cannot', [
    [$adDel[0], $adDel[1], $adDel[2][0], $adDel[2][1]['error'] ?? null, $adDel[3], $adDel[4]], $adGone,
    [$adAudit['rec'] ?? null, $adAudit['owner'] ?? null, $adAudit['as'] ?? null]],
    [[403, 403, 400, 'recording_running', 200, 404], [false, false, true, true], [$mrOther, 'u', 'admin']]);
foreach ([$mrAdmin, $mrPolicy, $mrOther, $mrLive] as $mrId) {
    @unlink("$tmp/data/recordings/$mrId.rec");
    @unlink("$tmp/data/recordings/$mrId.json");
}
@unlink("$tmp/data/run/$mrLiveSid.json");

// ... and in whole sessions (Standard RDP Security, as Windows XP), the test server opening the microphone as Windows does
// when a program records. A connection always recorded: its stream's frames, with what the remote computer heard of the
// microphone (a whole packet of 882 bytes; the rest waits for the next)
$rsSrv  = proc_open([PHP_BINARY, __DIR__ . '/fixtures/legacy_server.php', 'twicemic', '2', '2', 'proprietary', $cnf], [1 => ['pipe', 'w'], 2 => ['file', $nul, 'w']], $rsPipes);
$rsPort = (int)trim((string)fgets($rsPipes[1]));
$rsConn = MuDesk\Connections::save($wu['id'], ['host' => '127.0.0.1', 'port' => $rsPort, 'security' => 'auto', 'user' => 'ana',
    'clipboard' => false, 'drive' => false, 'printer' => false, 'audio' => false, 'mic' => true, 'record' => true], null, true, true);
is_array($rsConn) || print("recording test connection: $rsConn\n");
$rsLine = static function (string $want) use ($rsPipes): array {          // the server's lines up to $want (30 s at most)
    $seen = [];
    stream_set_blocking($rsPipes[1], false);
    for ($end = microtime(true) + 30; microtime(true) < $end; usleep(20000)) {
        while (($l = fgets($rsPipes[1])) !== false) {
            $seen[] = trim($l);
            if (trim($l) === $want) {
                return $seen;
            }
        }
    }
    return $seen;
};
$rsWait = static function (callable $until): bool {                       // (the session's own loop acts within a second; 30 s on a busy computer)
    for ($end = microtime(true) + 30; microtime(true) < $end; usleep(50000)) {
        if ($until()) {
            return true;
        }
    }
    return false;
};
$rsFrames   = static fn(string $id): array => $recFrames($recRead($id)[1]);
$rsSidP     = bin2hex(random_bytes(16));
$rsReqP     = $stream($rsConn['id'], $rsSidP);
$rsFirst    = $rsLine('confirm ok');
$rsMicOpen  = $rsWait(static fn() => in_array(MuDesk\Rdp\Client::F_MIC, array_column($rsFrames($rsSidP), 0), true));
MuDesk\Sessions::send($rsSidP, pack('V', 0) . "\x0f" . str_repeat(pack('v', 4000), 800));
$rsHeard    = $rsLine('mic 882');
$rsMic      = $rsWait(static fn() => in_array(MuDesk\Recording::MIC, array_column($rsFrames($rsSidP), 0), true));
$rsMicBytes = array_map(static fn(array $f): string => $f[1], array_values(array_filter($rsFrames($rsSidP), static fn(array $f): bool => $f[0] === MuDesk\Recording::MIC)));
MuDesk\Sessions::kill($rsSidP);
$wres($rsReqP);
$rsPInfo    = MuDesk\Recording::info($rsSidP);
check('recording of a connection always recorded: its frames, with what the remote computer heard of the microphone (the test server opening it as Windows does)', [
    end($rsFirst), $rsMicOpen, end($rsHeard), $rsMic, $rsMicBytes === [str_repeat(pack('v', 4000), 441)], $rsPInfo['sound'] ?? null, $rsPInfo['started_as'] ?? null, $rsPInfo['kind'] ?? null],
    ['confirm ok', true, 'mic 882', true, true, true, 'policy', null]);
@unlink("$tmp/data/recordings/$rsSidP.rec");
@unlink("$tmp/data/recordings/$rsSidP.json");

// ... one started in a session (the connection no longer always recorded): made at once in the user's page, the page told
// its number (record_video), no new connection; the pieces of its video the page sends on its out-of-order lane kept in
// order (one sent twice, or of another recording, left out), nothing of the stream's frames; what the page records said
// in its details; stopped, the page told, the recording closed once the page sent the rest; one the page cannot make
// stopped, said why; audited with the recording's own id
$rsConn = MuDesk\Connections::save($wu['id'], ['id' => $rsConn['id'], 'host' => '127.0.0.1', 'port' => $rsPort, 'security' => 'auto', 'user' => 'ana',
    'clipboard' => false, 'drive' => false, 'printer' => false, 'audio' => false, 'mic' => true, 'record' => false], null, true, true);
$rsSid2  = bin2hex(random_bytes(16));
$rsReq   = $stream($rsConn['id'], $rsSid2);
$rsSecond = $rsLine('confirm ok');
$rsPiece = static fn(int $gen, int $n, int $flags, string $bytes, ?array $head = null): string => pack('V', MuDesk\Sessions::OUT_OF_BAND) . "\x16"
    . pack('vVC', $gen, $n, $flags) . ($head !== null ? pack('v', strlen((string)json_encode($head))) . json_encode($head) : '') . $bytes;
$rsNewRec = static function (string $before) use ($rsWait): string {      // the id of the recording that started after $before
    $id = '';
    $rsWait(static function () use ($before, &$id): bool {
        $id = (string)(MuDesk\Audit::search(['event' => 'recording_start', 'user' => 'webuser'])['rows'][0]['rec'] ?? '');
        return $id !== '' && $id !== $before;
    });
    return $id;
};
$rsLastRec = (string)(MuDesk\Audit::search(['event' => 'recording_start', 'user' => 'webuser'])['rows'][0]['rec'] ?? '');
$rsStarted = MuDesk\Sessions::record($rsSid2, true, 'webadmin', true);
$rsRecOn   = $rsWait(static fn() => (MuDesk\Sessions::info($rsSid2)['record_by'] ?? null) === 'admin');
$rsRec     = $rsNewRec($rsLastRec);
$rsVideo   = static fn(): array => array_map(static fn(array $f): string => $f[0] . ':' . $f[1], $rsFrames($rsRec));
MuDesk\Sessions::send($rsSid2, $rsPiece(1, 0, 1, 'AAAA', ['mime' => 'video/webm;codecs=vp9,opus', 'w' => 800, 'h' => 600, 'sound' => true]));
MuDesk\Sessions::send($rsSid2, $rsPiece(1, 1, 0, 'BBBB'));
MuDesk\Sessions::send($rsSid2, $rsPiece(1, 1, 0, 'XXXX'));                // (sent twice)
MuDesk\Sessions::send($rsSid2, $rsPiece(9, 2, 0, 'ZZZZ'));                // (of another recording)
$rsKept    = $rsWait(static fn() => $rsVideo() === ['33:AAAA', '33:BBBB']);
$rsStopped = MuDesk\Sessions::record($rsSid2, false, 'webadmin', true);
$rsRecOff  = $rsWait(static fn() => array_key_exists('record', MuDesk\Sessions::info($rsSid2) ?? []) && empty(MuDesk\Sessions::info($rsSid2)['record']));
$rsOpen    = !isset(MuDesk\Recording::info($rsRec)['ended']);            // (until the page sent the rest)
MuDesk\Sessions::send($rsSid2, $rsPiece(1, 2, 2, 'CCCC'));
$rsClosed  = $rsWait(static fn() => isset(MuDesk\Recording::info($rsRec)['ended']));
$rsInfo    = MuDesk\Recording::info($rsRec);
[$rsWhole] = $recRead($rsRec);
$rsAll     = $rsVideo();
$rsStart   = MuDesk\Audit::search(['event' => 'recording_start', 'user' => 'webuser'])['rows'][0] ?? [];
$rsStop    = MuDesk\Audit::search(['event' => 'recording_stop', 'user' => 'webuser'])['rows'][0] ?? [];
// ... started by its user, in a page that cannot record: stopped, the page told why
MuDesk\Sessions::record($rsSid2, true, 'webuser', false);
$rsOwnOn   = $rsWait(static fn() => (MuDesk\Sessions::info($rsSid2)['record_by'] ?? null) === 'owner');
$rsRec2    = $rsNewRec($rsRec);
MuDesk\Sessions::send($rsSid2, $rsPiece(2, 0, 1 | 4, '', ['mime' => '', 'w' => 0, 'h' => 0, 'sound' => false]));
$rsFailed  = $rsWait(static fn() => isset(MuDesk\Recording::info($rsRec2)['ended']));
$rsInfo2   = MuDesk\Recording::info($rsRec2);
$rsStop2   = MuDesk\Audit::search(['event' => 'recording_stop', 'user' => 'webuser'])['rows'][0] ?? [];
// ... stopped, and the page answers that it cannot finish it (4): closed at once, failed (before, it stayed open)
MuDesk\Sessions::record($rsSid2, true, 'webadmin', true);
$rsWait(static fn() => (MuDesk\Sessions::info($rsSid2)['record_by'] ?? null) === 'admin');
$rsRec3    = $rsNewRec($rsRec2);
MuDesk\Sessions::send($rsSid2, $rsPiece(3, 0, 1, 'Q0', ['mime' => 'video/webm', 'w' => 800, 'h' => 600, 'sound' => false]));
$rsWait(static fn() => $rsFrames($rsRec3) !== []);
MuDesk\Sessions::record($rsSid2, false, 'webadmin', true);
$rsWait(static fn() => empty(MuDesk\Sessions::info($rsSid2)['record']));
MuDesk\Sessions::send($rsSid2, $rsPiece(3, 1, 4, ''));
$rsClosed3 = $rsWait(static fn() => isset(MuDesk\Recording::info($rsRec3)['ended']));
$rsInfo3   = MuDesk\Recording::info($rsRec3);
// ... a piece missing (lost on the way): what came after it cannot be read, so it ends there, whole up to it, said why
MuDesk\Sessions::record($rsSid2, true, 'webadmin', true);
$rsWait(static fn() => (MuDesk\Sessions::info($rsSid2)['record_by'] ?? null) === 'admin');
$rsRec4    = $rsNewRec($rsRec3);
MuDesk\Sessions::send($rsSid2, $rsPiece(4, 0, 1, 'P0', ['mime' => 'video/webm', 'w' => 800, 'h' => 600, 'sound' => false]));
MuDesk\Sessions::send($rsSid2, $rsPiece(4, 2, 0, 'P2'));
$rsGap     = $rsWait(static fn() => isset(MuDesk\Recording::info($rsRec4)['ended']));
$rsInfo4   = MuDesk\Recording::info($rsRec4);
$rsVideo4  = array_map(static fn(array $f): string => $f[1], $rsFrames($rsRec4));
// ... a page sending more than a video makes (24 MB in its first seconds: filling the disk): stopped, said why
MuDesk\Sessions::record($rsSid2, true, 'webadmin', true);
$rsWait(static fn() => (MuDesk\Sessions::info($rsSid2)['record_by'] ?? null) === 'admin');
$rsRec5    = $rsNewRec($rsRec4);
$rsBig = str_repeat('F', (4 << 20) - 200);
for ($i = 0; $i < 6; $i++) {                                              // (24 MB at once: past 1 MB a second and 10 s more)
    MuDesk\Sessions::send($rsSid2, $rsPiece(5, $i, $i === 0 ? 1 : 0, $rsBig, $i === 0 ? ['mime' => 'video/webm', 'w' => 8, 'h' => 8, 'sound' => false] : null));
}
unset($rsBig);
$rsFast    = $rsWait(static fn() => isset(MuDesk\Recording::info($rsRec5)['ended']));
$rsInfo5   = MuDesk\Recording::info($rsRec5);
@unlink("$tmp/data/recordings/$rsRec5.rec");
@unlink("$tmp/data/recordings/$rsRec5.json");
MuDesk\Sessions::kill($rsSid2);
[, $rsBody] = $wres($rsReq);
stream_set_blocking($rsPipes[1], false);
$rsLater = (string)stream_get_contents($rsPipes[1]);                      // (what the server said since: no new connection)
proc_terminate($rsSrv);
proc_close($rsSrv);
$rsStatus = [];
for ($o = 0; $o + 5 <= strlen($rsBody); $o += 5 + $len) {
    ['t' => $type, 'n' => $len] = unpack('Ct/Vn', $rsBody, $o);
    $j = $type === MuDesk\Rdp\Client::F_STATUS ? (array)json_decode(substr($rsBody, $o + 5, $len), true) : [];
    array_key_exists('recording', $j) && $rsStatus[] = [$j['recording'], $j['record_by'] ?? null, $j['record_video'] ?? null, $j['record_error'] ?? null];
}
check('recording in a session, made in the page: started at once (no new connection), the page told its number; its pieces kept in order (one sent twice, or of another recording, left out), nothing of the stream; stopped, closed once the page sent the rest (or said it cannot); one the page cannot make, with a piece missing, or growing faster than a video can, stopped, said why, whole up to there; audited with its id', [
    end($rsSecond), $rsStarted, $rsRecOn, $rsKept, $rsStopped, $rsRecOff, $rsOpen, $rsClosed, $rsWhole, $rsAll, $rsStatus,
    [$rsInfo['kind'] ?? null, $rsInfo['mime'] ?? null, $rsInfo['width'] ?? null, $rsInfo['height'] ?? null, $rsInfo['sound'] ?? null, $rsInfo['sid'] ?? null,
     $rsInfo['started_as'] ?? null, $rsInfo['stopped_by'] ?? null, isset($rsInfo['failed'])],
    [$rsStart['rec'] ?? null, $rsStart['by'] ?? null, $rsStart['as'] ?? null, $rsStop['rec'] ?? null, $rsStop['by'] ?? null, $rsRec !== $rsSid2, strlen($rsRec)],
    [$rsOwnOn, $rsFailed, $rsInfo2['failed'] ?? null, $rsInfo2['started_as'] ?? null, $rsStop2['rec'] ?? null, $rsStop2['reason'] ?? null],
    [$rsClosed3, $rsInfo3['failed'] ?? null, $rsInfo3['stopped_by'] ?? null, $rsGap, $rsInfo4['failed'] ?? null, $rsVideo4, $rsFast, $rsInfo5['failed'] ?? null],
    str_contains($rsLater, 'confirm ok') || str_contains($rsLater, 'x224')],
    ['confirm ok', true, true, true, true, true, true, true, true, ['33:AAAA', '33:BBBB', '33:CCCC'],
     [[true, 'admin', 1, null], [false, null, null, null], [true, 'owner', 2, null], [false, null, null, 'record_page'], [true, 'admin', 3, null],
      [false, null, null, null], [true, 'admin', 4, null], [false, null, null, 'record_page'], [true, 'admin', 5, null], [false, null, null, 'record_page']],
     ['video', 'video/webm', 800, 600, true, $rsSid2, 'admin', 'webadmin', false],
     [$rsRec, 'webadmin', 'admin', $rsRec, 'webadmin', true, 32],
     [true, true, true, 'owner', $rsRec2, 'record_page'], [true, true, 'webadmin', true, true, ['P0'], true, true], false]);
MuDesk\Connections::delete($wu['id'], $rsConn['id']);

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
    mkdir("$papp/$copy/src/Rdp");
    foreach (array_merge(glob(__DIR__ . '/../src/*.php'), glob(__DIR__ . '/../src/Rdp/*.php')) as $f) {   // (the housekeeping uses Rdp\Trace)
        copy($f, "$papp/$copy/src/" . (str_contains(str_replace('\\', '/', $f), '/Rdp/') ? 'Rdp/' : '') . basename($f));
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
check('housekeeping: started by the interface\'s requests (its hourly mark made by the first one)', is_file("$papp/ok/data/run/maintenance"), true);
check('state: which version runs not told before signing in', array_key_exists('version', (array)json_decode($j1, true)) && json_decode($j1, true)['version'] === null, true);
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
foreach (['data_dir', 'tmp_dir', 'php', 'https', 'client_ip', 'config_proxies', 'internal', 'other'] as $k) {
    foreach (['es', 'en'] as $l) {
        $h         = MuDesk\ErrorPage::html(403, $k, $l);
        $texts[]   = preg_match('#^<!doctype html><html lang="' . $l . '">.*<h1>[^<]+</h1><p>[^<]+</p><p class="muted">[^<]+</p>#', $h) === 1;
        $retries[] = str_contains($h, 'class="btn primary" href=""');
    }
}
check('error page: every error in both languages; "try again" only where reloading can help', [array_unique($texts), $retries],
    [[true], [true, true, false, false, true, true, false, false, false, false, false, false, true, true, true, true]]);

// PHP cannot write to its temporary folder (upload_tmp_dir and sys_temp_dir point nowhere): it drops a request body over
// 16 KB, with a warning, before the script starts. The answer says so (and the log), instead of going on with an empty
// body: an uploaded file was saved empty and answered as done. A small body still goes (PHP keeps it in memory).
// display_startup_errors off: when on (XAMPP, or PHP 8 without it in php.ini) PHP itself prints that warning, headers and
// all, before any script runs; nothing can take it back, and the page reads that answer as a failed upload anyway.
// dropped.php raises PHP's warning by hand, so the answer to it is checked everywhere; where PHP still finds a temporary
// folder, the dropped body itself is said not checkable, with the settings the server saw.
file_put_contents("$papp/ok/public/dropped.php", '<?php @trigger_error("PHP Request Startup: POST data can\'t be buffered; all data discarded", E_USER_WARNING);'
    . ' require __DIR__ . "/../src/bootstrap.php"; echo "went on";');
file_put_contents("$papp/ok/public/tmpini.php", '<?php echo json_encode([ini_get("upload_tmp_dir"), ini_get("sys_temp_dir"), sys_get_temp_dir(), ini_get("display_startup_errors")]);');
$probe = stream_socket_server('tcp://127.0.0.1:0');
$tport = (int)substr((string)strrchr((string)stream_socket_get_name($probe, false), ':'), 1);
fclose($probe);
// (the paths quoted: unquoted, a short Windows name like RUNNER~1 is read as the ~ operator of php.ini values, which cut the
// path and dropped the settings after it at GitHub)
$tweb = proc_open([PHP_BINARY, '-d', "upload_tmp_dir=\"$papp/no-such-folder\"", '-d', "sys_temp_dir=\"$papp/no-such-folder\"", '-d', 'display_errors=1', '-d', 'display_startup_errors=0',
    '-d', 'error_reporting=-1', '-d', 'log_errors=0', '-S', "127.0.0.1:$tport", '-t', $papp], [1 => ['file', $nul, 'w'], 2 => ['file', "$papp/tmp-server.log", 'w']], $tpipes);
for ($i = 0; $i < 100 && !($ws = @fsockopen('127.0.0.1', $tport)); $i++) {
    usleep(50000);
}
fclose($ws);
$tpost = static function (string $body, string $path = 'ok/public/api.php') use ($tport): array {   // [status, body]
    $s = fsockopen('127.0.0.1', $tport, $en, $es, 5);
    fwrite($s, "POST /$path HTTP/1.0\r\nHost: 127.0.0.1\r\nAccept: */*\r\nContent-Type: application/json\r\nContent-Length: "
        . strlen($body) . "\r\n\r\n$body");
    $r = (string)stream_get_contents($s);
    fclose($s);
    return [(int)substr($r, 9, 3), substr($r, (int)strpos($r, "\r\n\r\n") + 4)];
};
[$ts1, $tb1] = $tpost('{"action":"state","pad":"' . str_repeat('x', 20000) . '"}');
[$ts2, $tb2] = $tpost('{"action":"state"}');
[$ts3, $tb3] = $tpost(str_repeat('x', 100), 'ok/public/dropped.php');
[, $tini]    = $tpost('', 'ok/public/tmpini.php');
proc_terminate($tweb);
proc_close($tweb);
$tlog    = (string)file_get_contents("$papp/tmp-server.log");
$dropped = !str_contains($tb1, '"build"');
if (!$dropped) {
    echo "NOTE body dropped by PHP not checkable here: it found a temporary folder anyway (upload_tmp_dir, sys_temp_dir, system, display_startup_errors: $tini)\n";
}
check('PHP without a writable temporary folder: a body over 16 KB it dropped is refused with tmp_dir (and logged), a small one goes',
    [$dropped ? $ts1 : 500, $dropped ? $tb1 : '{"error":"tmp_dir"}', $ts3, $tb3, str_contains($tlog, 'cannot write to its temporary folder'), $ts2,
        isset(json_decode($tb2, true)['build'])],
    [500, '{"error":"tmp_dir"}', 500, '{"error":"tmp_dir"}', true, 200, true]);

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

// the hosting diagnostics (tools/check.php copied to public/): closed while its token is the one it comes with, nothing of
// it shown; with the token changed, its checks answer that token only; a day after it was put there it does nothing (a
// copy forgotten on the server)
$cdir = "$tmp/check";
mkdir($cdir);
$csrc = (string)file_get_contents(__DIR__ . '/../tools/check.php');
file_put_contents("$cdir/as-is.php", $csrc);
file_put_contents("$cdir/mine.php", str_replace("const TOKEN = 'CAMBIAR';", "const TOKEN = 'clave-de-prueba';", $csrc));
file_put_contents("$cdir/old.php", str_replace(["const TOKEN = 'CAMBIAR';", 'const CADUCA = 86400;'], ["const TOKEN = 'clave-de-prueba';", 'const CADUCA = -1;'], $csrc));
$cprobe = stream_socket_server('tcp://127.0.0.1:0');
$cport  = (int)substr((string)strrchr((string)stream_socket_get_name($cprobe, false), ':'), 1);
fclose($cprobe);
$cweb = proc_open([PHP_BINARY, '-S', "127.0.0.1:$cport", '-t', $cdir], [1 => ['file', $nul, 'w'], 2 => ['file', $nul, 'w']], $cpipes);
for ($i = 0; $i < 100 && !($cs = @fsockopen('127.0.0.1', $cport)); $i++) {
    usleep(50000);
}
fclose($cs);
$cget = static function (string $path, string $token = '') use ($cport): array {
    $s = fsockopen('127.0.0.1', $cport, $en, $es, 5);
    fwrite($s, "GET /$path HTTP/1.0\r\nHost: 127.0.0.1\r\n" . ($token !== '' ? "X-Token: $token\r\n" : '') . "\r\n");
    $r = (string)stream_get_contents($s);
    fclose($s);
    return [(int)substr($r, 9, 3), substr($r, (int)strpos($r, "\r\n\r\n") + 4)];
};
[$c1, $b1c] = $cget('as-is.php');
$checks = [$c1, str_contains($b1c, 'Cambia TOKEN'), str_contains($b1c, '<fieldset'), $cget('as-is.php?a=env', 'CAMBIAR')[0],
    $cget('mine.php?a=ping', 'clave-de-prueba'), $cget('mine.php?a=ping', 'otra')[0], $cget('old.php')[0], $cget('old.php?a=ping', 'clave-de-prueba')[0]];
proc_terminate($cweb);
proc_close($cweb);
check('hosting diagnostics: closed with the token it comes with; its checks with the right token only; nothing a day after it was put there',
    $checks, [403, true, false, 403, [200, 'ok'], 403, 410, 410]);

// a session given up as stale (its process killed by the hosting): what its clipboard files and print jobs took of its user's
// quota goes back with them, as its own end would have given it back (before, until the file manager counted it again)
$qs0   = MuDesk\Settings::get('files_quota_mb');
MuDesk\Settings::save(['files_quota_mb' => 1]);
$qsSid = bin2hex(random_bytes(16));
$qsUid = 'quotastale';
$qsReg = MuDesk\Sessions::register($qsSid, ['uid' => $qsUid, 'user' => 'q', 'conn' => 'c', 'host' => 'h', 'token' => hash('sha256', 'x')]);
@mkdir("$tmp/data/tmp/$qsSid", 0700, true);
file_put_contents("$tmp/data/tmp/$qsSid/0", str_repeat('a', 1000));
file_put_contents("$tmp/data/tmp/$qsSid/print-1", str_repeat('b', 500));
MuDesk\Files::charge($qsUid, 1500);
$qsBefore = MuDesk\Files::quotaLeft($qsUid);
touch("$tmp/data/run/$qsSid.json", time() - 120);
MuDesk\Sessions::active();
check('sessions: one given up as stale gives its clipboard files\' and print jobs\' quota back', [MuDesk\Files::quotaLeft($qsUid) - $qsBefore, is_dir("$tmp/data/tmp/$qsSid")], [1500, false]);
$qsReg->close();
MuDesk\Settings::save(['files_quota_mb' => $qs0]);
MuDesk\Files::removeAll($qsUid);

// housekeeping (no scheduled task on a shared hosting): what a killed process leaves, and web sessions past their life
// where the hosting turned PHP's cleanup off, removed once an hour; never a user's file, a recent write or a running session
$ago    = time() - 2 * MuDesk\Maintenance::OLD;
$mk     = static function (string $f, bool $old) use ($ago): string {
    @mkdir(dirname($f), 0700, true);
    file_put_contents($f, 'x');
    $old && touch($f, $ago);
    return $f;
};
$deadS  = str_repeat('d', 32);
$liveS  = str_repeat('e', 32);
$keepU  = MuDesk\Users::create('limpieza', 'Limpieza-' . bin2hex(random_bytes(6)), 'user', false)['id'];
$left   = [
    $mk(MuDesk\Config::dir('users.json.0a1b2c3d.tmp'), true), $mk(MuDesk\Config::dir('connections/x.json.0a1b2c3d.tmp'), true),
    $mk(MuDesk\Config::dir('run/' . $liveS . '.json.0a1b2c3d.tmp'), true), $mk(MuDesk\Config::dir('tmp/cnf9F2A.tmp'), true),
    $mk(MuDesk\Config::dir("run/$deadS.mode"), true), $mk(MuDesk\Config::dir("run/$deadS.q"), true), $mk(MuDesk\Config::dir("tmp/$deadS/clip.bin"), true),
    $mk(MuDesk\Config::dir('sessions/sess_viejo'), true), $mk(MuDesk\Config::dir('tmp/upload-noexiste.lock'), true),
    $mk(MuDesk\Config::dir('connections/noexiste.json.lock'), true), $mk(MuDesk\Sessions::tmpSock($deadS), true),
    $mk(sys_get_temp_dir() . "/mudesk-$deadS.sock", true),                       // named as before: nobody listens on it
    $mk(MuDesk\Config::dir('tmp/restore-0123456789abcdef.mudeskbackup'), true),  // a backup sent and never restored
    $mk(MuDesk\Config::dir('tmp/restore-done-0123456789ab/users.json'), true),     // what a restore that worked could not remove
];
touch(MuDesk\Config::dir('tmp/restore-done-0123456789ab'), $ago);
touch(MuDesk\Config::dir("tmp/$deadS"), $ago);
$kept   = [
    $mk(MuDesk\Config::dir('settings.json.4e5f6a7b.tmp'), false),                       // being written now
    $mk(MuDesk\Config::dir("files/$keepU/informe.0a1b2c3d.tmp"), true),                  // a user's own file
    $mk(MuDesk\Config::dir("run/$liveS.json"), false), $mk(MuDesk\Config::dir("run/$liveS.mode"), true),   // running
    $mk(MuDesk\Config::dir('sessions/sess_reciente'), false), $mk(MuDesk\Config::dir("tmp/upload-$keepU.lock"), true),
    // another installation's on this server (a long data folder too): its own business, never taken for this one's
    $mk(sys_get_temp_dir() . '/mudesk-0123456789ab-' . $deadS . '.sock', true),
    // the data a restore set aside and could not put back, or one cut off half way: never removed by itself
    $mk(MuDesk\Config::dir('tmp/restore-old-0123456789ab/users.json'), true),
];
touch(MuDesk\Config::dir('tmp/restore-old-0123456789ab'), $ago);
// ... and one named as before on which a session still listens (another installation's, not upgraded yet): kept (only
// where PHP has Unix sockets: Linux, macOS)
$legacyAt   = sys_get_temp_dir() . '/mudesk-' . str_repeat('f', 32) . '.sock';
$legacyLive = in_array('unix', stream_get_transports(), true) ? @stream_socket_server('unix://' . $legacyAt, $en, $es) : false;
$legacyLive !== false && touch($legacyAt, $ago);
$legacyLive === false && print("NOTE no Unix sockets in this PHP: a live socket named as before not checked here\n");
@unlink(MuDesk\Config::dir('run/maintenance'));
MuDesk\Maintenance::maybe();                     // due: runs
$legacyKept = $legacyLive === false || file_exists($legacyAt);
if ($legacyLive !== false) {
    fclose($legacyLive);
    @unlink($legacyAt);
}
$swept  = array_map('file_exists', $left);
$stayed = array_map('file_exists', $kept);
$mk(MuDesk\Config::dir('hosts/x.json.0a1b2c3d.tmp'), true);
MuDesk\Maintenance::maybe();                     // an hour not gone by: nothing
$notYet = is_file(MuDesk\Config::dir('hosts/x.json.0a1b2c3d.tmp'));
// (the temporary folder's names of this installation start with its own: another installation's differ)
$tmpOwn = [str_starts_with(basename(MuDesk\Sessions::tmpSock($deadS)), MuDesk\Sessions::tmpPrefix()), MuDesk\Sessions::tmpPrefix() !== 'mudesk-0123456789ab-',
    (bool)preg_match('/^mudesk-[0-9a-f]{12}-$/', MuDesk\Sessions::tmpPrefix())];
@unlink(sys_get_temp_dir() . '/mudesk-0123456789ab-' . $deadS . '.sock');
check('housekeeping: unfinished writes, dead sessions (their files, folder and socket), expired web sessions and deleted users\' locks removed; a recent write, a user\'s file, a running session, another installation\'s socket kept; once an hour',
    [array_keys(array_filter($swept)), array_values($stayed), is_dir(MuDesk\Config::dir("tmp/$deadS")), $notYet, $tmpOwn, $legacyKept],
    [[], array_fill(0, count($kept), true), false, true, [true, true, true], true]);
@unlink(MuDesk\Config::dir('hosts/x.json.0a1b2c3d.tmp'));
MuDesk\Files::remove(MuDesk\Config::dir('tmp/restore-old-0123456789ab'));
@unlink(MuDesk\Config::dir("run/$liveS.json"));
@unlink(MuDesk\Config::dir("run/$liveS.mode"));
MuDesk\Users::delete($keepU);
unlink($cnf);
MuDesk\Files::remove($tmp);

echo $fail === 0 ? "\nAll tests passed\n" : "\n$fail test(s) failed\n";
exit($fail === 0 ? 0 : 1);
