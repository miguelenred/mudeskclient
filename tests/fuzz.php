<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);
/*
 * Fuzz tests of the gateway: php tests/fuzz.php [--iterations=N] [--seed=S] [--target=NAME] [--case=K] [--strict]
 *
 * Random and mutated server PDUs against the RDP core (fast-path, slow-path, licensing, MCS, NLA, bulk decompression)
 * and the channels (clipboard, with browser messages too; drive and printer; audio; dynamic channels; RemoteApp, with the
 * browser's window commands too), on test objects
 * as in tests/run.php. Only ProtocolException may come out: a PHP warning, notice or deprecation, any other exception,
 * or a case that takes over 2 s (it is stopped at 10 s) or 32 MB is a failure, shown with its input (hex) and the
 * command that replays it.
 * Each case is built from the seed, the target and its number: the same seed always gives the same inputs.
 * N cases per target (100 to 300 each by default: a few seconds in all). KNOWN failures (found, not fixed yet) are
 * shown as pending and only fail with --strict.
 */

use MuDesk\{Config, Files, Text};
use MuDesk\Rdp\{Bulk, Client, Cliprdr, CredSsp, Der, Drdynvc, ProtocolException, Rail, Rdpdr, Rdpsnd, Trace, Transport};

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('memory_limit', '512M');
spl_autoload_register(static function (string $class): void {
    $file = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, 7)) . '.php';
    if (str_starts_with($class, 'MuDesk\\') && is_file($file)) {
        require $file;
    }
});

/** Failures found and not fixed yet: regular expression on the failure text => what is wrong. Remove each one once fixed. */
const KNOWN = [];
const SLOW        = 2.0;            // seconds per case
const MEMORY      = 32 << 20;       // bytes per case
const INTERESTING = [0, 1, 2, 3, 4, 7, 8, 0x7f, 0x80, 0xff, 0x100, 0x7fff, 0x8000, 0xffff, 0x10000, 0x7fffffff, 0x80000000, 0xffffffff];
const CHANNELS    = [['cliprdr', 0xC0A00000, 1004], ['rdpdr', 0xC0800000, 1005], ['rdpsnd', 0xC0000000, 1006], ['drdynvc', 0xC0800000, 1007],
                     ['rail', 0xC0200000, 1008]];
const IO          = 0x03EB;
const DISP        = 'Microsoft::Windows::RDS::DisplayControl';
const GFX         = 'Microsoft::Windows::RDS::Graphics';
const STREAM      = 0x5354524D;     // in a file contents response: the stream id of the download under way
const PATHS       = ['\\', '\\a.txt', '\\dir', '\\dir\\b.bin', '\\new', '\\dir\\new.txt', '\\x', '\\dir\\x\\y', '..\\up', '\\a.txt\\sub',
                     '\\web.config', '\\.htaccess', '\\a:b', '\\*', '\\dir\\*', '\\a?.txt', '/dir/b.bin', ''];

// ------------------------------------------------------------------ random data (mt_rand: seeded for each case)

function rnd(int $n): int
{
    return mt_rand(0, $n - 1);
}

function chance(int $percent): bool
{
    return mt_rand(0, 99) < $percent;
}

function pick(array $a): mixed
{
    return $a[mt_rand(0, count($a) - 1)];
}

function bytes(int $n): string
{
    $s = '';
    while (strlen($s) < $n) {
        $s .= pack('N', mt_rand() << 1 | mt_rand(0, 1));
    }
    return substr($s, 0, $n);
}

function mutate(string $s): string
{
    for ($k = 1 + rnd(3); $k > 0; $k--) {
        $n = strlen($s);
        $p = $n ? rnd($n) : 0;
        switch ($n ? rnd(9) : 0) {
            case 0: $s .= bytes(1 + rnd(16)); break;
            case 1: $s[$p] = chr(ord($s[$p]) ^ (1 << rnd(8))); break;
            case 2: $s[$p] = chr(pick([0, 1, 0x7f, 0x80, 0xff])); break;
            case 3: $s = substr_replace($s, pack('v', pick(INTERESTING) & 0xffff), $p, 2); break;
            case 4: $s = substr_replace($s, pack('V', pick(INTERESTING)), $p, 4); break;
            case 5: $s = substr_replace($s, pack('n', pick(INTERESTING) & 0xffff), $p, 2); break;
            case 6: $s = substr_replace($s, '', $p, 1 + rnd(8)); break;
            case 7: $s = substr_replace($s, bytes(1 + rnd(8)), $p, 0); break;
            default: $s = substr($s, 0, $p);
        }
    }
    return $s;
}

/**
 * A well-formed message, mutated in a quarter of the cases or, rarely, replaced by random bytes; parts of a message
 * less often ($percent), so that most messages still get through their headers.
 */
function maybe(string $s, int $percent = 25): string
{
    return chance(3) ? bytes(rnd(64)) : (chance($percent) ? mutate($s) : $s);
}

function utf16(string $s): string
{
    return Text::toUtf16($s);
}

/** Text with the odd unpaired surrogate, control character or invalid UTF-8 byte. */
function text(): string
{
    $s = '';
    for ($i = rnd(12); $i > 0; $i--) {
        $s .= pick(['a', 'Z', ' ', 'ñ', '€', '𝄞', "\0", "\n", '\\', '/', '..', ':', "\xff", "\xed\xa0\x80"]);
    }
    return $s;
}

// ------------------------------------------------------------------ PDU builders

function tpkt(string $x224): string
{
    return "\x03\x00" . pack('n', strlen($x224) + 4) . $x224;
}

/** MCS Send Data Indication on a channel. */
function mcs(int $channel, string $data): string
{
    $n = strlen($data);
    return tpkt("\x02\xf0\x80\x68" . pack('nn', 1, $channel) . "\x70" . ($n < 0x80 ? chr($n) : pack('n', 0x8000 | ($n & 0x7fff))) . $data);
}

/** Compressed blocks of tests/fixtures (made by FreeRDP's compressors) in order from a random one: [flags, data]. */
function packets(): Closure
{
    static $sets = null;
    if ($sets === null) {
        foreach (['mppc', 'ncrush', 'xcrush'] as $name) {
            foreach (file(__DIR__ . "/fixtures/$name-vectors.txt", FILE_IGNORE_NEW_LINES) as $line) {
                [$flags, $hex] = explode(' ', $line) + [1 => ''];
                if ($flags !== '#') {
                    $sets[$name][] = [(int)$flags, (string)hex2bin($hex)];
                }
            }
        }
    }
    $set = $sets[pick(['mppc', 'ncrush', 'xcrush'])];
    $i   = chance(50) ? 0 : rnd(count($set));
    return static function () use ($set, &$i): array {
        return $set[$i++ % count($set)];
    };
}

function fastPath(?Closure $packets): string
{
    $body = '';
    for ($i = 1 + rnd(3); $i > 0 && strlen($body) < 16000; $i--) {
        $code = chance(85) ? pick([0, 1, 2, 3, 4, 5, 6, 8, 9, 10, 11, 12]) : rnd(16);
        $frag = chance(70) ? 0 : rnd(4);
        [$comp, $data] = $packets && chance(60) ? $packets() : [chance(5) ? rnd(256) : 0, bytes(rnd(64))];
        $body .= chr($code | $frag << 4 | ($comp ? 0x80 : 0)) . ($comp ? chr($comp) : '') . pack('v', strlen($data)) . maybe($data, 15);
    }
    return pick(["\x00", "\x04", "\x80"]) . pack('n', 0x8000 | (strlen($body) + 3)) . $body;
}

function shareControl(int $type, string $body): string
{
    return pack('vvv', strlen($body) + 6, 0x10 | $type, 1002) . $body;
}

function demandActive(): string
{
    $caps = '';
    $n    = 0;
    for ($i = rnd(6); $i > 0; $i--, $n++) {
        $type  = chance(50) ? 2 : rnd(31);
        $data  = $type === 2 ? pack('vvvvvvvvvCCvv', pick([8, 15, 16, 24, 32, 300]), 1, 1, 1, pick([640, 1024, 0, 65535]), pick([480, 768, 0, 65535]), 0, 1, 1, 0, 0, 1, 0)
                             : bytes(rnd(40));
        $caps .= maybe(pack('vv', $type, strlen($data) + 4) . $data, 8);
    }
    $src = "RDP\0";
    return pack('Vvv', 0x103EA, strlen($src), strlen($caps) + 4) . $src . pack('vv', chance(80) ? $n : rnd(0x10000), 0) . $caps . pack('V', 0);
}

function dataPdu(?Closure $packets): string
{
    $type2 = chance(85) ? pick([0x02, 0x02, 0x1B, 0x1B, 0x2F, 0x26, 0x1F, 0x14, 0x28, 0x36]) : rnd(256);
    if ($packets && chance(40)) {
        [$comp, $payload] = $packets();
    } else {
        $comp    = chance(5) ? rnd(256) : 0;
        $payload = match ($type2) {
            0x02    => pack('v', pick([0, 0, 1, 2, 3, rnd(8)])) . pack('vvv', 0, rnd(5), 0) . bytes(rnd(48)),
            0x1B    => pack('vv', pick([1, 3, 6, 7, 8, 9, rnd(16)]), 0) . (chance(50) ? pack('V', pick([0, 0x7F00])) : bytes(rnd(64))),
            0x2F    => pack('V', pick([0, 1, 0x1C, rnd(0x10000)])),
            0x26    => pack('V', rnd(5)) . bytes(rnd(32)),
            default => bytes(rnd(24)),
        };
    }
    return pack('VCCvCCv', 0x103EA, 0, 1, strlen($payload), $type2, $comp, strlen($payload)) . maybe($payload, 15);
}

/** Share control PDUs of the I/O channel (MS-RDPBCGR 2.2.8.1.1.1.1). */
function slowPath(?Closure $packets): string
{
    $io = '';
    for ($i = 1 + rnd(3); $i > 0; $i--) {
        $io .= match (rnd(10)) {
            0       => shareControl(1, demandActive()),
            1       => shareControl(6, pack('Vv', 0x103EA, 4) . "RDP\0"),
            2       => chance(50) ? pack('v', 0x8000) . bytes(6) : shareControl(0xA, bytes(rnd(32))),
            default => shareControl(7, dataPdu($packets)),
        };
    }
    return mcs(IO, maybe($io));
}

/** X.509 certificate with an RSA key: only parsed (Licensing reads the key, whatever algorithm it declares), never verified. */
function x509(string $n): string
{
    $alg  = Der::seq(Der::tlv(0x06, "\x2a\x86\x48\x86\xf7\x0d\x01\x01" . pick(["\x01", "\x04", "\x05"])), "\x05\x00");
    $name = Der::seq(Der::tlv(0x31, Der::seq(Der::tlv(0x06, "\x55\x04\x03"), Der::tlv(0x0c, 'fuzz'))));
    $key  = Der::seq($alg, Der::tlv(0x03, "\0" . Der::seq(Der::tlv(0x02, "\0" . $n), Der::tlv(0x02, "\x01\x00\x01"))));
    $tbs  = Der::seq(Der::ctx(0, Der::int(2)), Der::int(rnd(1000)), $alg, $name,
        Der::seq(Der::tlv(0x17, '260101000000Z'), Der::tlv(0x17, '360101000000Z')), $name, $key);
    return Der::seq($tbs, $alg, Der::tlv(0x03, "\0" . bytes(64)));
}

/** Licensing PDU (MS-RDPELE): license request with a proprietary or X.509 certificate, platform challenge, new license, error. */
function license(int $type): string
{
    $n = "\xc5" . substr(hash('sha512', 'fuzz', true), 1, 62) . "\x0f";      // 512-bit modulus, odd
    if ($type === 0x01) {
        if (chance(50)) {                                                     // proprietary certificate
            $blob = 'RSA1' . pack('VVVV', 72, 512, 63, 65537) . strrev($n) . str_repeat("\0", 8);
            $cert = pack('VVVvv', pick([1, 0x80000001]), 1, 1, 6, strlen($blob)) . maybe($blob, 10) . pack('vv', 8, 72) . bytes(72);
        } else {
            $chain = [x509(bytes(64)), maybe(x509($n), 10)];
            $cert  = pack('VV', pick([2, 0x80000002]), count($chain));
            foreach ($chain as $c) {
                $cert .= pack('V', strlen($c)) . $c;
            }
            $cert .= str_repeat("\0", 16);
        }
        $company = utf16('Microsoft Corporation') . "\0\0";
        $product = utf16('A02') . "\0\0";
        $scope   = "microsoft.com\0";
        $body    = bytes(32) . pack('VV', 0x00060000, strlen($company)) . $company . pack('V', strlen($product)) . $product
                 . pack('vvV', 0x0D, 4, 1) . pack('vv', 0x03, strlen($cert)) . $cert . pack('Vvv', 1, 0x0E, strlen($scope)) . $scope;
    } else {
        $body = match ($type) {
            0x02    => pack('Vvv', 0, 0x09, 10) . bytes(10) . bytes(16),
            0xFF    => pack('VVvv', chance(50) ? 7 : rnd(10), 2, 4, 0),
            default => bytes(rnd(64)),
        };
    }
    return maybe(pack('vv', 0x0080, 0) . chr($type) . "\x03" . pack('v', strlen($body) + 4) . $body);
}

/** A licensing exchange as the server sends it, now and then with a message out of place. */
function licensing(): array
{
    $msgs = [license(0x01), ...(chance(60) ? [license(0x02)] : []), license(pick([0x03, 0x04, 0xFF]))];
    if (chance(20)) {
        array_splice($msgs, rnd(count($msgs) + 1), 0, [license(pick([0x01, 0x02, 0x03, 0xFF, rnd(256)]))]);
    }
    return $msgs;
}

/** MCS Connect Response with the server data blocks (core, security, network, message channel, multitransport). */
function connectResponse(): string
{
    $n      = rnd(6);
    $ids    = '';
    for ($i = 0; $i < $n; $i++) {
        $ids .= pack('v', chance(80) ? 1004 + $i : rnd(0x10000));
    }
    $net    = pack('vv', IO, chance(85) ? $n : rnd(0x10000)) . $ids . ($n % 2 ? "\0\0" : '');
    $blocks = [pack('vvVV', 0x0C01, 12, 0x00080004, 1), pack('vvVV', 0x0C02, 12, chance(90) ? 0 : rnd(4), 0), pack('vv', 0x0C03, 4 + strlen($net)) . $net];
    if (chance(40)) {
        $blocks[] = pack('vvv', 0x0C04, 6, 1008);
    }
    if (chance(30)) {
        $blocks[] = pack('vvV', 0x0C08, 8, 0x101);
    }
    $data = '';
    while ($blocks) {
        $data .= maybe(array_splice($blocks, rnd(count($blocks)), 1)[0], 8);
    }
    $gcc = "\x00\x05\x00\x14\x7c\x00\x01\x2a\x14\x76\x0a\x01\x01\x00\x01\xc0\x00McDn" . pack('n', 0x8000 | strlen($data)) . $data;
    $ber = Der::tlv(0x0a, "\0") . Der::int(0) . Der::seq(...array_map([Der::class, 'int'], [34, 3, 0, 1, 0, 1, 65528, 2])) . Der::octet($gcc);
    return tpkt("\x02\xf0\x80" . Der::tlv(0x7f66, maybe($ber, 10)));
}

/** NTLM CHALLENGE message (MS-NLMP 2.2.1.2) with its AV pairs. */
function challenge(): string
{
    $info = '';
    foreach ([[2, utf16('DOMAIN')], [1, utf16('SERVER')], [4, utf16('corp.example')], [3, utf16('server.corp.example')], [7, bytes(8)], [rnd(12), bytes(rnd(12))]] as [$id, $v]) {
        if (chance(80)) {
            $info .= pack('vv', $id, strlen($v)) . $v;
        }
    }
    $info .= "\0\0\0\0";
    $name  = utf16('SERVER');
    return "NTLMSSP\0" . pack('V', 2) . pack('vvV', strlen($name), strlen($name), 56) . pack('V', chance(70) ? 0xE28A8235 : mt_rand())
         . bytes(8) . str_repeat("\0", 8) . pack('vvV', strlen($info), strlen($info), 56 + strlen($name)) . "\x0a\x00\x63\x45\x00\x00\x00\x0f" . $name . $info;
}

function tsRequest(?string $token, ?string $pubKeyAuth = null): string
{
    return Der::seq(Der::ctx(0, Der::int(pick([2, 3, 5, 6, 6]))),
        $token === null ? '' : Der::ctx(1, Der::seq(Der::seq(Der::ctx(0, Der::octet($token))))),
        $pubKeyAuth === null ? '' : Der::ctx(3, Der::octet($pubKeyAuth)),
        chance(5) ? Der::ctx(4, Der::int(pick([0xC000006D, 0xC0000234, -1]))) : '');
}

// clipboard (MS-RDPECLIP)
function clip(int $type, int $flags, string $body): string
{
    return pack('vvV', $type, $flags, strlen($body)) . $body;
}

/** Format list: the formats given (id => name) or random ones. */
function formatList(bool $long, ?array $formats = null): string
{
    if ($formats === null) {
        $formats = [];
        for ($i = rnd(6); $i > 0; $i--) {
            $name = pick(['', 'HTML Format', 'FileGroupDescriptorW', 'FileContents', 'Preferred DropEffect', 'PNG', text()]);
            $formats[$name === '' ? pick([13, 1, 7, 8, 17, 2, rnd(0x10000)]) : 0xC000 + rnd(0x200)] = $name;
        }
    }
    $out = '';
    foreach ($formats as $id => $name) {
        $out .= pack('V', $id) . ($long ? utf16($name) . "\0\0" : substr(str_pad(utf16($name), 32, "\0"), 0, 32));
    }
    return clip(2, 0, $out);
}

/** FILEDESCRIPTORW list of files and folders. */
function fileList(): string
{
    $n   = rnd(4);
    $out = pack('V', chance(90) ? $n : rnd(10));
    for ($i = 0; $i < $n; $i++) {
        $name = substr(str_pad(utf16(pick(['a.txt', 'dir', '..\\..\\x.bat', 'dir\\b.bin', 'C:\\x', text()])), 520, "\0"), 0, 520);
        $out .= pack('V', 0x4064) . str_repeat("\0", 32) . pack('V', pick([0x80, 0x80, 0x10])) . str_repeat("\0", 24)
              . pack('VV', pick([0, 0, 0, 1, 0xffffffff]), pick([0, 3, 600000, 0xffffffff])) . $name;
    }
    return $out;
}

function clipData(): string
{
    return match (rnd(5)) {
        0       => utf16(text()) . "\0\0",
        1       => "Version:0.9\r\nStartHTML:0000000105\r\n<b>" . text() . "</b>\0",
        2       => pack('VllvvVV', 40, 2, 2, 1, 32, 0, 16) . str_repeat("\0", 16) . bytes(16),
        3       => fileList(),
        default => bytes(rnd(40)),
    };
}

/** Any clipboard PDU. */
function cliprdrPdu(bool $long): string
{
    $type = chance(90) ? pick([1, 2, 2, 3, 4, 5, 5, 7, 8, 9, 10, 11]) : rnd(20);
    $body = match ($type) {
        4       => pack('V', pick([13, 8, 17, 1, 0xC0F1, 0xC0F2, 0xC0F3, 0xC0F4, 0xC0F5, 0xC100, 0xC101, rnd(0x10000)])),
        5       => clipData(),
        7       => pack('vvvvVV', 1, 0, 1, 12, 2, pick([0, 2, 0x1e, rnd(256)])),
        8       => pack('VlVVVV', 1, rnd(4) - 1, pick([1, 2]), pick([0, 100, 0xffffffff]), pick([0, 0x80000000]), pick([0, 8, 100, 0x7fffffff])),
        9       => pack('V', chance(70) ? STREAM : rnd(5)) . bytes(rnd(40)),
        default => bytes(rnd(16)),
    };
    return maybe($type === 2 ? formatList($long) : clip($type, $type === 5 || $type === 9 ? pick([1, 1, 2]) : pick([0, 1, 2]), $body));
}

/**
 * A clipboard session: 'S' server PDU, 'B' browser message, 'P' text typed by pasting, 'T' time passes, 'R' the screen
 * changes. Copies on each side, file downloads and pastes as they happen, with stray messages in between.
 */
function clipboard(bool $long): array
{
    $s    = static fn(string $pdu) => 'S' . maybe($pdu);
    $resp = static fn(string $data) => $s(clip(5, chance(95) ? 1 : 2, $data));
    $msgs = chance(50) ? [$s(clip(7, 0, pack('vvvvVV', 1, 0, 1, 12, 2, pick([0x2, 0x1e, 0, rnd(256)])))), $s(clip(1, 0, ''))] : [];
    for ($i = 1 + rnd(3); $i > 0; $i--) {
        array_push($msgs, ...match (rnd(5)) {
            0       => [$s(formatList($long, [13 => ''] + (chance(60) ? [0xC0A0 => 'HTML Format'] : []) + (chance(20) ? [1 => '', 7 => ''] : []))),
                        $resp(utf16(text()) . "\0\0"), $resp("Version:0.9\r\n<b>" . text() . "</b>\0")],
            1       => [$s(formatList($long, [8 => '', 17 => '', 2 => ''])), $resp(clipData())],
            2       => [$s(formatList($long, [0xC0A1 => 'FileGroupDescriptorW', 0xC0A2 => 'FileContents', 0xC0A3 => 'Preferred DropEffect'])),
                        $resp(fileList()), 'BD', ...array_map(static fn() => $s(clip(9, 1, pack('V', STREAM) . bytes(pick([0, 3, 40])))), range(0, rnd(4)))],
            3       => ['B' . pick(['X' . text(), 'H' . pack('V', 3) . 'abc<b>abc</b>', 'B', 'F[{"name":"a.txt"},{"name":"..\\\\b"}]']), $s(clip(3, pick([1, 1, 2]), '')),
                        $s(clip(4, 0, pack('V', pick([13, 0xC0F4, 8, 0xC0F1, 0xC0F3, 0xC0F5])))),
                        $s(clip(8, 0, pack('VlVVVV', 1, pick([0, 1, -1, 5]), pick([1, 2]), pick([0, 50, 0xffffffff]), pick([0, 0x80000000]), pick([8, 100, 0x7fffffff]))))],
            default => ['P' . text(), $resp(utf16(text()) . "\0\0"), $s(clip(3, 1, '')), 'R', 'T', $s(clip(4, 0, pack('V', 13))), 'R'],
        });
    }
    for ($i = rnd(4); $i > 0; $i--) {
        array_splice($msgs, rnd(count($msgs) + 1), 0, [pick(['S' . cliprdrPdu($long), 'S' . cliprdrPdu($long), 'T', 'R', 'B' . text(),
            'BF' . pick(['[1,"x"]', '{"0":null}', text()]), 'BH' . pack('V', pick([0, 0xffffffff])) . text()])]);
    }
    return $msgs;
}

// device redirection (MS-RDPEFS, MS-RDPEPC)
function path(): string
{
    if (chance(70)) {
        return pick(PATHS);
    }
    $p = '';
    for ($i = 1 + rnd(3); $i > 0; $i--) {
        $p .= pick(['\\', '/', '\\\\']) . pick(['a', 'b.x', 'dir', '..', '.', 'a b', ' ', 'x.', "\u{e9}", "\0"]);
    }
    return $p;
}

/** I/O request to a device (1 the drive, 2 the printer) for a file id; a create opens $path (a file read and written, mostly). */
function irp(int $device, int $file, int $major, ?string $path = null): string
{
    $minor = $major === 0x0C ? pick([1, 1, 2]) : 0;
    $u16z  = static fn(string $s) => utf16($s) . "\0\0";
    $off   = static fn() => chance(80) ? rnd(200) : pick([0x7fffffff, PHP_INT_MAX, -1, 1 << 40]);
    $open  = $path === null ? [pick([0x1, 0x2, 0x80, 0x10000, 0x40000000, 0x100001, 0x12019f, mt_rand()]), rnd(6), pick([0, 0x1, 0x40, 0x1040, 0x21, 0x1001, mt_rand()])]
                            : [0x12019f, pick([1, 2, 3, 3, 5]), pick([0x40, 0x40, 0x1040])];   // access, disposition, options
    $body  = match ($major) {
        0x00    => pack('VPVVVV', $open[0], 0, pick([0, 0x10, 0x80]), 7, $open[1], $open[2]) . pack('V', strlen($u16z($p = $path ?? path()))) . $u16z($p),
        0x03    => pack('VP', pick([0, 10, 1000, 0xffffffff]), $off()) . str_repeat("\0", 20),
        0x04    => pack('VP', ($d = bytes(rnd(100))) === '' ? 0 : strlen($d) + pick([0, 0, 50]), $off()) . str_repeat("\0", 20) . $d,
        0x05    => pack('VV', pick([4, 5, 35, rnd(60)]), 0) . str_repeat("\0", 24),
        0x06    => pack('VV', $class = pick([19, 20, 13, 13, 10, 10, rnd(60)]), 8) . str_repeat("\0", 24) . match ($class) {
                       19, 20  => pack('P', $off()),
                       13      => chr(rnd(2)),
                       10      => chr(rnd(2)) . "\0" . pack('V', strlen($u16z($p = path()))) . $u16z($p),
                       default => bytes(rnd(8)),
                   },
        0x0A    => pack('VV', pick([1, 3, 4, 5, 7, rnd(20)]), 0) . str_repeat("\0", 24),
        0x0C    => pack('VCV', pick([1, 2, 3, 12, rnd(20)]), rnd(2), strlen($u16z($p = pick(['\\*', '\\dir\\*', '\\a?.txt', '\\*.txt', path()]))))
                   . str_repeat("\0", 23) . $u16z($p),
        default => str_repeat("\0", 32),
    };
    return maybe(pack('vv', 0x4472, 0x4952) . pack('VVVVV', $device, $file, rnd(100), $major, $minor) . $body);
}

/** Any device redirection PDU. */
function rdpdrPdu(): string
{
    if (chance(75)) {
        return irp(chance(70) ? 1 : (chance(70) ? 2 : rnd(5)), rnd(6), pick([0x00, 0x02, 0x03, 0x04, 0x05, 0x06, 0x0A, 0x0C, 0x0E, 0x11, rnd(32)]));
    }
    $id = pick([0x496E, 0x5350, 0x4343, 0x554C, 0x6472, rnd(0x10000)]);
    return maybe(pack('vv', chance(95) ? 0x4472 : rnd(0x10000), $id) . ($id === 0x496E || $id === 0x4343 ? pack('vvV', 1, 12, rnd(10)) : bytes(rnd(24))));
}

/** A device redirection session: the start-up messages, then files opened, used and closed in turn (ids 1, 2...). */
function devices(): array
{
    $msgs = [];
    if (chance(50)) {
        foreach ([[0x496E, pack('vvV', 1, 12, 7)], [0x5350, pack('vv', 1, 0) . pack('vvV', 1, 44, 2) . str_repeat("\0", 36)], [0x4343, pack('vvV', 1, 12, 7)],
                  [0x554C, ''], [0x6472, pack('VV', 1, 0)]] as [$id, $body]) {
            $msgs[] = maybe(pack('vv', 0x4472, $id) . $body);
        }
    }
    for ($file = 1, $n = 1 + rnd(3); $file <= $n; $file++) {
        $device = chance(75) ? 1 : 2;
        $msgs[] = irp($device, $file, 0x00, chance(75) ? pick(['\\a.txt', '\\dir\\b.bin', '\\new.txt', '\\dir\\new.bin']) : null);
        for ($i = rnd(5); $i > 0; $i--) {
            $msgs[] = irp($device, chance(85) ? $file : rnd(6), pick([0x02, 0x03, 0x04, 0x04, 0x05, 0x06, 0x06, 0x0A, 0x0C, 0x0C, 0x0E, 0x11, rnd(32)]));
        }
        if (chance(80)) {
            $msgs[] = irp($device, $file, 0x02);
        }
    }
    for ($i = rnd(3); $i > 0; $i--) {
        array_splice($msgs, rnd(count($msgs) + 1), 0, [rdpdrPdu()]);
    }
    return $msgs;
}

// audio (MS-RDPEA)
function rdpsndPdu(): array
{
    $type = pick([0x07, 0x07, 0x06, 0x02, 0x02, 0x0D, 0x0D, 0x01, rnd(32)]);
    $more = [];
    if ($type === 0x07) {
        $formats = '';
        $n       = rnd(6);
        for ($i = 0; $i < $n; $i++) {
            $extra    = pick(['', '', pack('v', 2041), bytes(rnd(8))]);
            $formats .= pack('vvVVvvv', pick([1, 1, 2, 6, 7, 0x11, 0x55, 0xA106, rnd(0x10000)]), pick([1, 2, 6, 0]), pick([8000, 22050, 44100, 0]), 176400,
                pick([1, 2, 4, 2048, 0]), pick([4, 8, 16, 24, 0]), strlen($extra)) . $extra;
        }
        $body = pack('VVVvvCvC', 0, 0, 0, 0, chance(90) ? $n : rnd(0x10000), 0xff, pick([5, 6, 8]), 0) . $formats;
    } else {
        $body = match ($type) {
            0x06    => pack('vv', rnd(0x10000), 1024),
            0x02    => pack('vvCCCC', rnd(0x10000), rnd(4), rnd(256), 0, 0, 0) . bytes(4),
            0x0D    => pack('vvCCCCV', rnd(0x10000), rnd(4), rnd(256), 0, 0, 0, 0) . bytes(rnd(64)),
            default => bytes(rnd(16)),
        };
        if ($type === 0x02 && chance(80)) {
            $more[] = maybe("\0\0\0\0" . bytes(rnd(64)));                     // Wave PDU after the Wave Info PDU
        }
    }
    return [maybe(pack('CCv', $type, 0, strlen($body)) . $body), ...$more];
}

// dynamic channels (MS-RDPEDYC)
function dvc(int $cmd, int $id, string $payload, int $sp = 0): string
{
    $cb = $id <= 0xff ? 0 : ($id <= 0xffff ? 1 : 2);
    return maybe(chr($cmd << 4 | $sp << 2 | $cb) . ($cmd === 5 ? '' : number($id, $cb)) . $payload);
}

/** Variable-size field (cbChId, Sp or Len: 0 one byte, 1 two, 2 four). */
function number(int $v, int $size): string
{
    return match ($size) { 0 => chr($v & 0xff), 1 => pack('v', $v), default => pack('V', $v) };
}

/** A dynamic channel session: capabilities, Display Control and graphics channels opened, used and closed, stray messages. */
function dynamic(): array
{
    [$disp, $gfx] = [pick([1, 2, 0x100]), pick([3, 0x1234, 0x10000])];
    $msgs = [dvc(5, 0, chance(15) ? pick(['', "\0"]) : "\0" . pack('v', pick([1, 2, 3])) . pack('vvvv', 936, 3276, 9362, 65535)),
             dvc(1, $disp, DISP . "\0"), dvc(1, $gfx, GFX . (chance(90) ? "\0" : ''))];
    for ($i = rnd(6); $i > 0; $i--) {
        $sp   = rnd(3);
        $data = bytes(rnd(48));
        array_push($msgs, ...match (rnd(6)) {
            0       => [dvc(3, $disp, pack('VV', pick([5, 5, 2, rnd(8)]), 20) . pack('VVV', 16, 3840, 2160))],
            1       => [dvc(2, $gfx, number(strlen($data) + pick([0, 0, 10, 0xffff]), $sp) . substr($data, 0, 20), $sp), dvc(3, $gfx, (string)substr($data, 20))],
            2       => [dvc(3, $gfx, $data)],
            3       => [dvc(4, pick([$disp, $gfx]), '')],
            4       => [dvc(1, rnd(300), pick(['Microsoft::Windows::RDS::Input', text()]) . "\0")],
            default => [maybe(chr(rnd(256)) . bytes(rnd(12)))],
        });
    }
    return $msgs;
}

// ------------------------------------------------------------------ test objects

function prop(object $o, string $name, mixed $value): void
{
    (new ReflectionProperty($o, $name))->setValue($o, $value);
}

/** Client as in tests/run.php: output to memory, the static channels joined; diagnostic trace (to memory) when asked. */
function client(array $o): Client
{
    $c    = new Client(['width' => 800, 'height' => 600, 'bpp' => 16, 'kbd' => 0x409, 'orders' => true, 'user' => 'user', 'clientName' => 'fuzz',
        'channels' => array_map(static fn($ch) => [$ch[0], $ch[1]], CHANNELS), 'monitors' => []], static fn() => null);
    $t    = (new ReflectionClass(Transport::class))->newInstanceWithoutConstructor();
    $t->sock = fopen('php://memory', 'w+b');
    $c->t = $t;
    prop($c, 'channelIds', array_column(CHANNELS, 2, 0));
    prop($c, 'channelOpts', array_column(CHANNELS, 1, 0));
    if ($o['bulk'] ?? false) {
        prop($c, 'bulk', new Bulk());
    }
    if ($o['trace'] ?? false) {
        $c->trace = (new ReflectionClass(Trace::class))->newInstanceWithoutConstructor();
        foreach (['fh' => fopen('php://memory', 'w+b'), 't0' => microtime(true), 'next' => microtime(true) + 10] as $name => $value) {
            prop($c->trace, $name, $value);
        }
    }
    return $c;
}

/** Device redirection of a new user: the same drive in every case (two files and a folder), no print jobs yet. */
function rdpdr(Client $c, bool $drive, bool $printer): Rdpdr
{
    static $n = 0;
    $uid  = 'fuzz' . ++$n;
    $root = Files::root($uid);
    mkdir("$root/dir");
    file_put_contents("$root/a.txt", 'hello fuzz');
    file_put_contents("$root/dir/b.bin", str_repeat("\x55", 100));
    return new Rdpdr($c, $uid, $drive, $printer, Config::dir("print/$uid"), 'sid', 'conn');
}

/** Bytes as received from the server: framed by Transport, then handled. */
function receive(Client $c, string $bytes): void
{
    prop($c->t, 'buf', $bytes);
    $c->poll();
}

// ------------------------------------------------------------------ targets: [options and messages of a case, run them]

$targets = [
    'fastpath' => [300,
        static function (): array {
            $o = ['bulk' => chance(50), 'trace' => chance(30)];
            $z = $o['bulk'] ? packets() : null;
            return [$o, array_map(static fn() => maybe(fastPath($z)), range(0, rnd(4)))];
        },
        static function (array $o, array $msgs): void {
            $c = client($o);
            foreach ($msgs as $m) {
                receive($c, $m);
            }
        },
    ],
    'slowpath' => [300,
        static function (): array {
            $o = ['bulk' => chance(40), 'trace' => chance(30), 'licensed' => chance(85)];
            $z = $o['bulk'] ? packets() : null;
            return [$o, array_map(static fn() => chance(5) ? mutate(slowPath($z)) : slowPath($z), range(0, rnd(4)))];
        },
        static function (array $o, array $msgs): void {
            $c = client($o);
            prop($c, 'licensed', $o['licensed']);
            foreach ($msgs as $m) {
                receive($c, $m);
            }
        },
    ],
    'license' => [300,
        static function (): array {
            $msgs = array_map(static fn(string $pdu) => mcs(IO, $pdu), licensing());
            return [['trace' => chance(30)], chance(30) ? [...$msgs, slowPath(null)] : $msgs];
        },
        static function (array $o, array $msgs): void {
            $c = client($o);
            foreach ($msgs as $m) {
                receive($c, $m);
            }
        },
    ],
    'mcs' => [100,
        static function (): array {
            $o = ['connect' => chance(50), 'protocol' => pick([1, 2, 8]), 'trace' => chance(30)];
            if ($o['connect']) {
                return [$o, [chance(5) ? mutate(connectResponse()) : connectResponse()]];
            }
            $msgs = [];
            for ($i = rnd(3); $i >= 0; $i--) {                                 // a session of a channel, its messages in chunks
                $ch      = pick(CHANNELS);
                $session = match ($ch[0]) {
                    'cliprdr' => array_map(static fn(string $m) => substr($m, 1), array_filter(clipboard(chance(50)), static fn(string $m) => $m[0] === 'S')),
                    'rdpdr'   => devices(),
                    'rdpsnd'  => rdpsndPdu(),
                    default   => dynamic(),
                };
                foreach ($session as $msg) {
                    $parts = str_split($msg, 1 + rnd(max(1, strlen($msg)))) ?: [''];
                    foreach ($parts as $k => $part) {                           // flags: first, last, show protocol
                        $flags  = ($k === 0 ? 1 : 0) | ($k === count($parts) - 1 ? 2 : 0) | pick([0, 0, 0x10]);
                        $msgs[] = maybe(mcs(chance(95) ? $ch[2] : rnd(0x10000), pack('VV', chance(95) ? strlen($msg) : rnd(0x10000), chance(95) ? $flags : rnd(16)) . $part), 5);
                    }
                }
                if (chance(15)) {
                    $msgs[] = maybe(tpkt("\x02\xf0\x80" . chr(pick([8, 9, 11, 15, rnd(64)]) << 2) . bytes(rnd(16))));   // other MCS PDUs
                }
            }
            return [$o, $msgs];
        },
        static function (array $o, array $msgs): void {
            $c = client($o);
            $handlers = [new Cliprdr($c, 'fuzz'), new Rdpdr($c, 'fuzz', false, false, ''), new Rdpsnd($c), new Drdynvc($c, true), new Rail($c, '||App')];   // drive: its own target
            if ($o['connect']) {
                prop($c, 'channelIds', []);
                array_map([$c, 'addChannel'], $handlers);
                prop($c->t, 'buf', $msgs[0]);
                (new ReflectionMethod(Client::class, 'mcsConnect'))->invoke($c, $o['protocol']);
                return;
            }
            prop($c, 'handlers', array_combine(array_column(CHANNELS, 2), $handlers));
            prop($c, 'licensed', true);
            foreach ($msgs as $m) {
                receive($c, $m);
            }
        },
    ],
    'nla' => [300,
        static function (): array {
            $o = ['domain' => pick(['DOMAIN', '.', ''])];
            return [$o, [chance(5) ? mutate(tsRequest(maybe(challenge()))) : tsRequest(maybe(challenge())), maybe(tsRequest(null, bytes(pick([0, 15, 16, 48]))))]];
        },
        static function (array $o, array $msgs): void {
            $c = client($o);
            prop($c->t, 'buf', implode('', $msgs));
            CredSsp::authenticate($c->t, bytes(270), 'user', $o['domain'], 'password', 'host');
        },
    ],
    'bulk' => [150,
        static function (): array {
            $z = packets();
            return [[], array_map(static function () use ($z): string {
                [$flags, $data] = $z();
                return maybe(chr($flags) . $data);
            }, range(0, rnd(6)))];
        },
        static function (array $o, array $msgs): void {
            $b = new Bulk();
            foreach ($msgs as $m) {
                if ($m !== '') {
                    $b->decompress(substr($m, 1), ord($m[0]));
                }
            }
        },
    ],
    'cliprdr' => [200,
        static function (): array {
            $o = ['long' => chance(60), 'ready' => chance(80), 'paster' => chance(50), 'trace' => chance(25)];
            return [$o, clipboard($o['long'])];
        },
        static function (array $o, array $msgs): void {
            $c = client($o);
            $c->active = true;
            $k = new Cliprdr($c, 'fuzz');
            prop($k, 'long', $o['long']);
            prop($k, 'ready', $o['ready']);
            if ($o['paster']) {
                $c->paster = $k->typeText(...);
            }
            $typing = new ReflectionProperty(Cliprdr::class, 'typing');
            foreach ($msgs as $m) {
                $data = substr($m, 1);
                switch ($m[0]) {
                    case 'S':
                        $dl = (new ReflectionProperty(Cliprdr::class, 'dl'))->getValue($k);
                        if ($dl !== null && strlen($data) >= 12 && unpack('v', $data)[1] === 9 && unpack('V', $data, 8)[1] === STREAM) {
                            $data = substr_replace($data, pack('V', $dl['stream']), 8, 4);
                        }
                        $k->onData($data);
                        break;
                    case 'B':
                        $k->fromBrowser($data);
                        break;
                    case 'P':
                        $c->pasteText($data);
                        break;
                    case 'T':                                                   // Windows did not answer in time
                        if ($typing->getValue($k) !== null) {
                            $typing->setValue($k, ['until' => 0] + $typing->getValue($k));
                        }
                        $k->tick();
                        break;
                    case 'R':                                                   // the screen changes
                        (new ReflectionMethod(Client::class, 'fastPath'))->invoke($c, "\x00\x02");
                        $c->sendInput();
                        break;
                }
            }
        },
    ],
    'rdpdr' => [100,
        static function (): array {
            return [['drive' => chance(85), 'printer' => chance(50), 'trace' => chance(20)], devices()];
        },
        static function (array $o, array $msgs): void {
            $d = rdpdr(client($o), $o['drive'], $o['printer']);
            foreach ($msgs as $m) {
                $d->onData($m);
            }
        },
    ],
    'rdpsnd' => [300,
        static function (): array {
            $msgs = [];
            for ($i = rnd(6); $i >= 0; $i--) {
                array_push($msgs, ...rdpsndPdu());
            }
            return [['trace' => chance(30)], $msgs];
        },
        static function (array $o, array $msgs): void {
            $s = new Rdpsnd(client($o));
            foreach ($msgs as $m) {
                $s->onData($m);
            }
        },
    ],
    'drdynvc' => [300,
        static function (): array {
            return [['gfx' => chance(70), 'avc' => chance(50), 'trace' => chance(40)], dynamic()];
        },
        static function (array $o, array $msgs): void {
            $c      = client($o);
            $c->gfx = $o['gfx'];
            $v      = new Drdynvc($c, $o['avc']);
            foreach ($msgs as $m) {
                $v->onData($m);
            }
            $v->gfxAck(pack('VV', 1, 1));
            $v->resize(1920, 1080);
        },
    ],
    'mic' => [300,                                       // microphone: the server's audio input messages and the browser's audio
        static function (): array {
            $fmt  = static function (): string {
                $extra = chance(80) ? 0 : rnd(40);
                return pack('vvVVvvv', [1, 1, 1, 2, 6, 0xA106, rnd(0xFFFF)][rnd(6)], [1, 2, 0, 3, rnd(0xFFFF)][rnd(4)],
                    [44100, 48000, 22050, 8000, 0, 96000, mt_rand()][rnd(6)], mt_rand(), rnd(8), [16, 8, 0, rnd(0xFFFF)][rnd(3)], $extra) . bytes($extra);
            };
            $msgs = [];
            for ($i = rnd(12); $i >= 0; $i--) {
                $n = rnd(6);
                // each input: "d" + a message of the channel, "c" (closed and opened again) or "a" + u32 (bytes of audio from the browser)
                $m = match (rnd(7)) {
                    0       => pack('CV', 1, rnd(3)),
                    1       => pack('CVV', 2, chance(90) ? $n + 1 : mt_rand(), mt_rand()) . implode('', array_map(static fn() => $fmt(), range(0, $n))),
                    2       => pack('CVV', 3, [441, 0, 1, 8820, mt_rand()][rnd(4)], rnd(4)) . $fmt(),
                    3       => pack('CV', 7, rnd(4)),
                    4       => chr(rnd(255)) . bytes(rnd(20)),
                    5       => null,
                    default => 0,
                };
                $msgs[] = match (true) {
                    $m === null => 'c',
                    $m === 0    => 'a' . pack('V', chance(10) ? rnd(400000) : rnd(20000)),
                    default     => 'd' . (chance(15) ? mutate($m) : $m),
                };
            }
            return [['trace' => chance(30)], $msgs];
        },
        static function (array $o, array $msgs): void {
            $v = new Drdynvc(client($o), false, true);
            $v->onData("\x10\x07AUDIO_INPUT\0");
            foreach ($msgs as $m) {
                if ($m[0] === 'c') {
                    $v->onData("\x40\x07");
                    $v->onData("\x10\x07AUDIO_INPUT\0");
                } elseif ($m[0] === 'a') {
                    $v->micData(str_repeat("\x01", unpack('V', $m, 1)[1]));
                } else {
                    $v->onData("\x30\x07" . substr($m, 1));
                }
            }
        },
    ],
    'rail' => [300,                                      // RemoteApp: the server's RAIL PDUs and the browser's window commands
        static function (): array {
            $pdu  = static function (int $type, string $body): string {
                $len = chance(85) ? 4 + strlen($body) : rnd(70000);
                return pack('vv', $type, $len) . $body;
            };
            $msgs = [];
            for ($i = rnd(8); $i >= 0; $i--) {
                $type   = [0x0005, 0x0013, 0x0080, 0x0015, 0x0003, 0x000A, 0x0009, 0x0014, 0x000F, rnd(0xFFFF)][rnd(9)];
                $body   = match ($type) {
                    0x0005  => pack('V', mt_rand()),
                    0x0013  => pack('VV', mt_rand(), mt_rand()),
                    0x0080  => pack('vvVvv', rnd(3), [0, 0, 3, 5, 6, 7, rnd(0xFFFF)][rnd(6)], mt_rand(), 0, rnd(40)) . bytes(rnd(40)),
                    0x0015  => pack('VC', mt_rand(), rnd(1)),
                    default => bytes(rnd(64)),
                };
                $msgs[] = chance(15) ? mutate($pdu($type, $body)) : $pdu($type, $body);
            }
            $cmds = [];
            for ($i = rnd(4); $i >= 0; $i--) {
                $cmds[] = chance(70) ? (string)json_encode(['a' => ['activate', 'sys', 'x'][rnd(2)], 'id' => [rnd(0xFFFF), -1, '7', 1.5, 0x1FFFFFFFF][rnd(4)],
                    'c' => [0xF020, 0xF030, 0xF120, 0xF060, rnd(0xFFFF), 'x'][rnd(5)]]) : bytes(rnd(40));
            }
            return [['trace' => chance(30), 'again' => chance(30)], [$msgs, $cmds]];
        },
        static function (array $o, array $in): void {
            $c = client($o);
            $r = new Rail($c, '||App', chance(50) ? 'arg' : '', $o['again']);
            [$msgs, $cmds] = $in;
            foreach ($msgs as $i => $m) {
                $r->onData($m);
                if (isset($cmds[$i])) {
                    $r->command($cmds[$i]);
                }
            }
            $r->workArea(1 + rnd(4000), 1 + rnd(4000));
        },
    ],
];

// ------------------------------------------------------------------ run


/** Where an error happened: file, line and the gateway method it was in. */
function where(string $file, int $line, array $trace): string
{
    foreach ($trace as $f) {
        if (str_starts_with($f['class'] ?? '', 'MuDesk\\')) {
            return basename($file) . ":$line in " . substr((string)strrchr('\\' . $f['class'], '\\'), 1) . '::' . $f['function'];
        }
    }
    return basename($file) . ":$line";
}

$opt   = getopt('', ['iterations:', 'seed:', 'target:', 'case:', 'strict']);
$seed  = (int)($opt['seed'] ?? 1);
$only  = isset($opt['target']) ? (string)$opt['target'] : null;
$one   = isset($opt['case']) ? (int)$opt['case'] : null;
if (($only !== null && !isset($targets[$only])) || ($one !== null && $only === null)) {
    fwrite(STDERR, "Usage: php tests/fuzz.php [--iterations=N] [--seed=S] [--target=NAME [--case=K]] [--strict]\nTargets: " . implode(', ', array_keys($targets)) . "\n");
    exit(2);
}

// test data folder (never the project's): clipboard files from the browser, a 1 MB drive quota
$tmp = sys_get_temp_dir() . '/mudesk-fuzz-' . bin2hex(random_bytes(4));
mkdir("$tmp/data/tmp/fuzz", 0700, true);
file_put_contents("$tmp/config.php", '<?php return ["data_dir" => ' . var_export("$tmp/data", true) . '];');
Config::load("$tmp/config.php");
file_put_contents(Config::dir('settings.json'), '{"files_quota_mb":1}');
file_put_contents(Cliprdr::tmpDir('fuzz') . '/0', str_repeat('f', 100));
file_put_contents(Cliprdr::tmpDir('fuzz') . '/1', '');
file_put_contents(Cliprdr::tmpDir('fuzz') . '/image.dib', pack('VllvvVV', 40, 1, 1, 1, 32, 0, 4) . str_repeat("\0", 20));

$errors = [];                                                           // [text, kind and place] of the case under way
set_error_handler(static function (int $no, string $msg, string $file, int $line) use (&$errors): bool {
    if (!(error_reporting() & $no)) {
        return false;                                                   // silenced on purpose with @
    }
    $kind     = [E_WARNING => 'Warning', E_NOTICE => 'Notice', E_DEPRECATED => 'Deprecated', E_USER_WARNING => 'Warning',
                 E_USER_NOTICE => 'Notice', E_USER_DEPRECATED => 'Deprecated'][$no] ?? "Error $no";
    $at       = where($file, $line, debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS));
    $errors[] = ["$kind: $msg ($at)", "$kind $at"];
    throw new ErrorException($msg, 0, $no, $file, $line);               // recorded even if the code catches it
});

/** Prints a failure with what replays it. */
$show = static function (string $status, string $text, string $name, int $case, array $o, array $msgs) use ($seed, $one): void {
    echo "$status $name case $case: $text\n     replay: php tests/fuzz.php --seed=$seed --target=$name --case=$case\n     options: " . json_encode($o) . "\n";
    foreach ($msgs as $i => $m) {
        $hex = bin2hex($m);
        echo '     input ', $i + 1, ': ', $one !== null || strlen($hex) <= 400 ? $hex : substr($hex, 0, 400) . '... (' . strlen($m) . ' bytes)', "\n";
    }
};

$now = null;                                                            // the case under way, for fatal errors (time, memory)
register_shutdown_function(static function () use (&$now, $show, $tmp): void {
    $e = error_get_last();
    if ($now !== null && $e !== null && in_array($e['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $show('FAIL', 'fatal: ' . $e['message'], ...$now);
    }
    gc_collect_cycles();
    Files::remove($tmp);
});

echo "Fuzz seed $seed", isset($opt['iterations']) ? ", {$opt['iterations']} cases per target" : '', "\n";
$failed  = $pending = $messages = 0;
$started = microtime(true);
foreach ($only === null ? array_keys($targets) : [$only] as $name) {
    [$cases, $gen, $run] = $targets[$name];
    $cases = $one === null ? max(1, (int)($opt['iterations'] ?? $cases)) : 1;
    $seen  = [];
    $t0    = microtime(true);
    $n     = 0;
    foreach ($one === null ? range(0, $cases - 1) : [$one] as $case) {
        mt_srand(crc32("$seed/$name/$case"));
        [$o, $msgs] = $gen();
        $n     += count($msgs);
        $now    = [$name, $case, $o, $msgs];
        $errors = [];
        gc_collect_cycles();
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }
        $mem  = memory_get_usage();
        $peak = memory_get_peak_usage();
        set_time_limit(10);
        $t = microtime(true);
        try {
            $run($o, $msgs);
        } catch (ProtocolException) {
        } catch (Throwable $e) {
            if (!$e instanceof ErrorException || $errors === []) {
                $at       = where($e->getFile(), $e->getLine(), $e->getTrace());
                $errors[] = [get_class($e) . ': ' . $e->getMessage() . " ($at)", get_class($e) . " $at"];
            }
        }
        $t = microtime(true) - $t;
        if (memory_get_peak_usage() > $peak && memory_get_peak_usage() - $mem > MEMORY) {
            $errors[] = [sprintf('memory: %.0f MB', (memory_get_peak_usage() - $mem) / 1048576), 'memory'];
        }
        if ($t > SLOW) {
            // a runner that stops for a while is no fault of the code (3.4 s at GitHub for a case that takes 0.0 s): the same
            // case, the same input, runs once more, and only slow both times is a failure (what it reports again is left out)
            $keep = $errors;
            set_time_limit(10);
            $again = microtime(true);
            try {
                $run($o, $msgs);
            } catch (Throwable) {
            }
            $again  = microtime(true) - $again;
            $errors = $keep;
            if ($again > SLOW) {
                $errors[] = [sprintf('slow: %.1f s, %.1f s again', $t, $again), 'slow'];
            }
        }
        foreach ($errors as [$text, $key]) {
            $seen[$key] = ($seen[$key] ?? 0) + 1;
            if ($seen[$key] === 1) {
                $known = isset($opt['strict']) ? [] : array_filter(KNOWN, static fn($re) => preg_match($re, $text) === 1, ARRAY_FILTER_USE_KEY);
                $show($known ? 'PEND' : 'FAIL', $text . ($known ? "\n     known issue: " . reset($known) : ''), $name, $case, $o, $msgs);
                $known ? $pending++ : $failed++;
            }
        }
    }
    $now = null;
    foreach ($seen as $key => $count) {
        if ($count > 1) {
            echo "     ($key: also in ", $count - 1, " more cases)\n";
        }
    }
    printf("%s %s: %d cases, %d messages, %.1f s\n", $seen ? '    ' : 'OK  ', $name, $cases, $n, microtime(true) - $t0);
    $messages += $n;
}
set_time_limit(0);
printf("\n%s (%d messages in %.1f s)\n", $failed ? "$failed fuzz failure(s)" : 'All fuzz tests passed' . ($pending ? ", $pending known issue(s) pending" : ''),
    $messages, microtime(true) - $started);
exit($failed ? 1 : 0);
