<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);
/*
 * Command-line probe: connects to an RDP server and prints what the browser would receive.
 * php tests/rdp_probe.php host port user password [domain] [seconds] [security] [bpp]
 * GFX=1 (graphics pipeline), GFX=avc (with H.264) or GFX=auto (as the web client: pipeline with H.264 from Windows 8.1 on); COMPRESS=1|2|3 (bulk compression MPPC, NCRUSH or XCRUSH); DUMP=file saves the frames;
 * TRACE=1 writes a diagnostic trace to the data folder (trace/<id>.log), as the diagnostic mode of the web client;
 * PIPE=host:port connects to a local driver (tests/bench.mjs): frames go to it as to the browser, and it sends back the browser
 * messages (u32 length + type 1 input, 2 resize, 5 quit, 10 frame acknowledgement); without PIPE the probe resizes at half time
 */
require __DIR__ . '/../src/bootstrap.php';

use MuDesk\Rdp\{Client, Drdynvc, ProtocolException};

[$host, $port, $user, $pass] = [$argv[1] ?? '127.0.0.1', (int)($argv[2] ?? 3389), $argv[3] ?? '', $argv[4] ?? ''];
$domain  = $argv[5] ?? '';
$seconds = (float)($argv[6] ?? 5);
$stats   = [];
$t0      = microtime(true);
$dump    = getenv('DUMP') ? fopen(getenv('DUMP'), 'wb') : null;
$pipe    = getenv('PIPE') ? stream_socket_client('tcp://' . getenv('PIPE'), $en, $es, 5) : null;
$cpu     = static function (): float {
    $r = getrusage();
    return $r['ru_utime.tv_sec'] + $r['ru_stime.tv_sec'] + ($r['ru_utime.tv_usec'] + $r['ru_stime.tv_usec']) / 1e6;
};
$sink    = static function (int $type, string $p) use (&$stats, $t0, $dump, $pipe): void {
    if ($dump) {
        fwrite($dump, pack('CV', $type, strlen($p)) . $p);
    }
    if ($pipe) {
        fwrite($pipe, pack('CV', $type, strlen($p)) . $p);
    }
    $key          = $type === Client::F_UPDATE ? 'update:' . ord($p[0]) : 'frame:' . $type;
    $stats[$key]  = ($stats[$key] ?? 0) + 1;
    $stats['bytes'] = ($stats['bytes'] ?? 0) + strlen($p);
    if ($type !== Client::F_UPDATE && $type !== Client::F_GFX && !($type === Client::F_AUDIO && $p[0] === 'W')) {
        printf("[%.2fs] frame %d %s\n", microtime(true) - $t0, $type, $type === Client::F_RESIZE ? implode('x', unpack('v2', $p)) . ' bpp ' . ord($p[4]) : $p);
    }
};
$c = new Client([
    'host' => $host, 'ip' => gethostbyname($host), 'port' => $port, 'user' => $user, 'domain' => $domain, 'password' => $pass,
    'width' => (int)(getenv('W') ?: 1024), 'height' => (int)(getenv('H') ?: 768), 'bpp' => (int)($argv[8] ?? 16), 'kbd' => 0x040A, 'perf' => 0x1 | 0x8, 'tzBias' => -60,
    'security' => $argv[7] ?? 'auto', 'clientName' => 'probe', 'audio' => (bool)getenv('AUDIO'), 'orders' => !getenv('NOORDERS'), 'gfx' => getenv('GFX') === 'auto' ? 'auto' : (bool)getenv('GFX'), 'compression' => (int)getenv('COMPRESS'),
    'monitors' => getenv('MONS') ? json_decode(getenv('MONS'), true) : [],
    'channels' => [['cliprdr', 0xC0200000], ['rdpdr', 0x80000000], ['rdpsnd', 0xC0000000], ['drdynvc', 0xC0800000]],
], $sink);
if (getenv('TRACE')) {
    $c->trace = new MuDesk\Rdp\Trace(bin2hex(random_bytes(16)), ['probe' => true, 'host' => $host, 'port' => $port, 'php' => PHP_VERSION]);
}
$dvc = new Drdynvc($c, getenv('GFX') === 'avc' || getenv('GFX') === 'auto');
$c->addChannel($dvc);
if (getenv('AUDIO')) {
    $c->addChannel(new MuDesk\Rdp\Rdpsnd($c));
}
if (getenv('DRIVE_UID')) {
    $c->addChannel(new MuDesk\Rdp\Rdpdr($c, getenv('DRIVE_UID'), true, (bool)getenv('PRINTER'), sys_get_temp_dir() . '/mudesk-print'));
}
try {
    $c->connect(static fn(string $fp) => print("cert sha256 $fp\n"));
    printf("[%.2fs] connected %dx%d\n", microtime(true) - $t0, $c->width, $c->height);
    $resized = false;
    $in      = '';
    while (microtime(true) - $t0 < $seconds) {
        $r = $pipe ? [$c->t->sock, $pipe] : [$c->t->sock];
        $w = $e = null;
        @stream_select($r, $w, $e, 0, 200000);
        if (!$c->poll()) {
            echo "server closed\n";
            break;
        }
        if ($pipe && in_array($pipe, $r, true)) {     // messages from the driver, as rdp.php gets them from the browser
            $d = fread($pipe, 65536);
            if ($d === '' || $d === false) {
                break;
            }
            for ($in .= $d; strlen($in) >= 4 && strlen($in) >= 4 + ($n = unpack('V', $in)[1]); $in = substr($in, 4 + $n)) {
                $m = substr($in, 4, $n);
                match (ord($m[0])) {
                    1       => $c->input(substr($m, 2), ord($m[1])),
                    2       => $dvc->resize(...array_values(unpack('vw/vh', $m, 1))),
                    6       => $c->refresh(),
                    10      => $dvc->gfxAck(substr($m, 1, 8)),
                    5       => $seconds = 0,
                    12      => fwrite($pipe, pack('CV', 255, strlen($j = json_encode(['in' => $c->t->bytesIn, 'out' => $stats['bytes'] ?? 0,
                                   'cpu' => $cpu(), 'mem' => memory_get_peak_usage(true)]))) . $j),   // statistics for the driver

                    default => null,
                };
            }
        }
        if (!$pipe && !$resized && microtime(true) - $t0 > $seconds / 2) {
            $resized = true;
            echo 'resize request: ' . ($dvc->resize(1280, 720) ? 'sent' : 'no channel') . "\n";
            $c->input(pack('Cvvv', 1 << 5, 0x0800, 100, 100), 1); // mouse move
        }
    }
    $c->disconnect();
} catch (ProtocolException $e) {
    echo "ERROR [{$e->key}] {$e->getMessage()}\n";
    $c->trace?->log('error', ['key' => $e->key, 'message' => $e->getMessage()]);
}
if ($c->trace) {
    $c->trace->close(['bytesIn' => isset($c->t) ? $c->t->bytesIn : 0]);
    echo 'trace: ' . MuDesk\Rdp\Trace::dir() . '/' . $c->trace->sid . ".log\n";
}
ksort($stats);
print_r($stats);
printf("from server %d bytes\n", isset($c->t) ? $c->t->bytesIn : 0);
$ru = getrusage();
printf("cpu user %.2fs sys %.2fs, peak memory %.1f MB, wall %.1fs\n", $ru['ru_utime.tv_sec'] + $ru['ru_utime.tv_usec'] / 1e6,
    $ru['ru_stime.tv_sec'] + $ru['ru_stime.tv_usec'] / 1e6, memory_get_peak_usage(true) / 1048576, microtime(true) - $t0);
