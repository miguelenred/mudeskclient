<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

/*
 * RDP gateway endpoints.
 *  POST ?a=stream  JSON {c, sid, w, h, tz, password?, user?}  -> long binary response: [u8 type][u32 length][payload]...
 *  POST ?a=send&sid=...  binary message for the running session (input, resize, clipboard...), header X-MuDesk-Token
 */

use MuDesk\{Access, Audit, Auth, Config, Connections, Files, Http, Sessions, Settings, Users};
use MuDesk\Rdp\{Bulk, Client, Cliprdr, Drdynvc, ProtocolException, Rail, Rdpdr, Rdpsnd, Trace, Transport};

require __DIR__ . '/../src/bootstrap.php';   // PHP's messages to the log only: a warning printed into the binary stream broke it

Http::guard();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Http::fail('method', 405);
}
$a = (is_string($_GET['a'] ?? null) ? $_GET['a'] : '');

if ($a === 'send') { // hot path for input: no PHP session, authorised by the per-session token (a header: URLs are logged)
    $sid  = (is_string($_GET['sid'] ?? null) ? $_GET['sid'] : '');
    $info = Sessions::info($sid);
    $body = (string)file_get_contents('php://input');
    // the session's file keeps the SHA-256 of its token, never the token (a copy of the data folder gives no keyboard);
    // a session started before that change kept the token itself (32 characters)
    $have = (string)($_SERVER['HTTP_X_MUDESK_TOKEN'] ?? '');
    $want = (string)($info['token'] ?? '');
    if ($info === null || $have === '' || !hash_equals($want, strlen($want) === 64 ? hash('sha256', $have) : $have)
        || strlen($body) < 5 || strlen($body) > 4 * 1048576 || ord($body[4]) === 9) {
        http_response_code(404);
        exit;
    }
    http_response_code(Sessions::send($sid, $body) ? 204 : 410);
    exit;
}

if (!isset($_COOKIE['MUDESKSID'])) {             // signed out: no session file is made for the request
    Http::fail('auth', 401);
}
Auth::start();
Auth::checkCsrf();
$user = Auth::require();

// clipboard files of a session: kept until it ends and counted in the user's shared folder quota (print jobs are not)
$clipBytes = static fn(string $sid): int => (int)array_sum(array_map(static fn($f) => (int)@filesize($f),
    preg_grep('~/(\d{1,4}|image\.dib)$~', glob(Cliprdr::tmpDir($sid) . '/*') ?: [])));

if ($a === 'clipfile') { // chunked upload of clipboard files/images: ?sid=&n=<index|image.dib>&o=<offset>
    Auth::release();
    $sid  = (is_string($_GET['sid'] ?? null) ? $_GET['sid'] : '');
    $info = Sessions::info($sid);
    $name = (is_string($_GET['n'] ?? null) ? $_GET['n'] : '');
    $off  = (int)($_GET['o'] ?? 0);
    if ($info === null || $info['uid'] !== $user['id'] || !preg_match('/^(\\d{1,4}|image\\.dib)$/', $name)) {
        Http::fail('session', 404);
    }
    $dir = Cliprdr::tmpDir($sid);
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    $path = $dir . '/' . $name;
    if ($off === 0) {
        if (is_file($path)) {                 // copied again: the old file gives its quota back
            Files::charge($user['id'], -(int)filesize($path));
            @unlink($path);
        }
    } elseif (!is_file($path) || filesize($path) !== $off) {
        Http::fail('offset');
    }
    $left = Files::quotaLeft($user['id']);
    $in   = fopen('php://input', 'rb');
    $out  = fopen($path, 'ab');
    $n    = (int)stream_copy_to_stream($in, $out, $left < PHP_INT_MAX ? $left + 1 : null);
    fclose($out);
    if ($n > $left || !Files::charge($user['id'], $n)) {   // charged once written: a session may have taken the room meanwhile
        Files::charge($user['id'], -$off);
        @unlink($path);
        Http::fail('quota');
    }
    if (Sessions::info($sid) === null) {      // the session ended meanwhile: what its end did not see goes now
        Files::charge($user['id'], -$clipBytes($sid));
        Files::remove($dir);
        Http::fail('session', 404);
    }
    Http::json(['ok' => true]);
}

if ($a === 'print') { // a document printed on the MuDeskPDF virtual printer
    Auth::release();
    $sid  = (is_string($_GET['sid'] ?? null) ? $_GET['sid'] : '');
    $info = Sessions::info($sid);
    $file = Cliprdr::tmpDir($sid) . '/print-' . (int)($_GET['job'] ?? 0);
    if ($info === null || $info['uid'] !== $user['id'] || !is_file($file)) {
        Http::fail('not_found', 404);
    }
    $pdf = (string)file_get_contents($file, false, null, 0, 4) === '%PDF';
    header('Content-Type: ' . ($pdf ? 'application/pdf' : 'application/octet-stream'));
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: no-store');
    readfile($file);
    Audit::log('print_download', ['sid' => $sid, 'conn' => $info['conn'] ?? '', 'job' => (int)($_GET['job'] ?? 0), 'size' => filesize($file), 'pdf' => $pdf], null, true);
    exit;
}

if ($a !== 'stream') {
    Http::fail('unknown_action', 404);
}

$in   = Http::input();
foreach (['sid', 'c', 'password', 'user', 'w', 'h', 'tz', 'trust', 'lang'] as $k) {
    if (is_array($in[$k] ?? null)) {          // a text or a number: a list was the password "Array", with a PHP warning
        Http::fail('invalid_input');
    }
}
$dek  = Auth::dek();
Auth::release();
$sid  = (string)($in['sid'] ?? '');
$conn = Connections::get($user['id'], (string)($in['c'] ?? ''));

// admission before any output (a full gateway answers 503 at once, before opening a socket): the limits are checked and the
// session registered under one lock
$limit = $user['max_sessions'] ?: Settings::get('max_sessions_per_user');
$total = Settings::get('max_sessions_total');
$token = bin2hex(random_bytes(16));
$sess  = !Sessions::validSid($sid) || Sessions::info($sid) !== null || $conn === null ? 'invalid_request'
       : Sessions::register($sid, ['uid' => $user['id'], 'user' => $user['username'], 'conn' => $conn['name'], 'host' => $conn['host'],
                                   'ip' => Http::clientIp(), 'token' => hash('sha256', $token), 'wid' => Auth::wid()], $limit, $total);
if ($sess === 'busy') {
    Audit::log('rdp_denied', ['conn' => $conn['name'], 'reason' => 'busy', 'limit' => $total], null, false);
    Http::$auditFailures = false;             // logged just above
    Http::fail('busy', 503);
}

// ---------------------------------------------------------------- streaming response
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}
while (ob_get_level()) {
    ob_end_clean();
}
set_time_limit(0);
ignore_user_abort(true);
header('Content-Type: application/octet-stream');
header('Cache-Control: no-store, no-transform');
header('X-Accel-Buffering: no');

$out   = '';
$emit  = static function (int $type, string $payload) use (&$out): void {
    $out .= pack('CV', $type, strlen($payload)) . $payload;
};
$flush = static function () use (&$out): bool {
    if ($out !== '') {
        echo $out;
        $out = '';
    }
    flush();
    return connection_aborted() === 0;
};
$status = static function (array $s) use ($emit, $flush): void {
    $emit(Client::F_STATUS, json_encode($s));
    $flush();
};
$emit(0, str_repeat("\0", 2048)); // padding: makes some proxies start forwarding
$flush();

if (is_string($sess)) {
    Audit::log('rdp_denied', $sess === 'too_many_sessions' ? ['conn' => $conn['name'], 'reason' => $sess, 'limit' => $limit]
        : ['reason' => $conn === null ? 'unknown_connection' : 'invalid_session_id'], null, false);
    $status(['end' => $sess]);
    exit;
}
// registered: every way out removes the session
$started = time();
$reason  = 'closed';
$detail  = null;
$audit   = ['sid' => $sid, 'conn' => $conn['name']];   // context of every event of this RDP session
$drive   = null;
$cleaned = false;
$cleanup = static function () use (&$cleaned, &$drive, $sess, $sid, $user, $clipBytes): void {
    if (!$cleaned) {
        $cleaned = true;
        $drive?->release();                                    // drive quota taken in advance and not used
        Files::charge($user['id'], -$clipBytes($sid));         // the clipboard files go with the session
        $sess->close();
        Files::remove(Cliprdr::tmpDir($sid));
    }
};
// a fatal error (memory, time limit) skips finally: the session files, clipboard files and print jobs stayed on disk
register_shutdown_function(static function () use (&$cleaned, $cleanup, $audit, $started): void {
    if (!$cleaned) {
        ini_set('memory_limit', (string)(memory_get_usage() + (16 << 20)));   // the memory of the failed request is still held
        $cleanup();
        Audit::log('rdp_end', $audit + ['reason' => 'fatal', 'seconds' => time() - $started], null, false);
    }
});
$ip = Access::resolveTarget($conn['host']);
if ($ip === null) {
    $cleanup();
    Audit::log('rdp_denied', ['conn' => $conn['name'], 'host' => $conn['host'], 'reason' => 'target_not_allowed'], null, false);
    $status(['end' => 'target_denied']);
    exit;
}
// options chosen in the connect dialog override the saved ones
$opts = (array)($in['opts'] ?? []);
foreach (['audio', 'mic', 'clipboard', 'drive', 'printer'] as $k) {
    if (isset($opts[$k])) {
        $conn[$k] = (bool)$opts[$k];
    }
}
if (isset($opts['gfx'])) {
    $conn['gfx'] = Connections::gfxMode($opts['gfx']); // 'auto' is decided by the client after NLA (with 32 bpp when used)
}
if (in_array(Http::int($opts['bpp'] ?? 0), [15, 16, 24, 32], true)) {
    $conn['bpp'] = Http::int($opts['bpp']);
}
// phone keyboard through the clipboard: 'auto' is decided by the browser (phones and tablets)
$paste    = isset($opts['paste']) ? (bool)$opts['paste'] : Connections::pasteMode($conn['paste'] ?? 'auto') === 'on';
$saved    = Settings::get('save_passwords') ? Connections::password($conn, $dek) : null;   // none used once the administrator forbids them
$password = $saved ?? (string)($in['password'] ?? '');
[$login, $domain] = Connections::splitUser($conn['user'] !== '' ? $conn['user'] : (string)($in['user'] ?? ''), $conn['domain']);
$p        = $conn['perf'];
$perf     = ($p['wallpaper'] ? 0 : 0x1) | ($p['drag'] ? 0 : 0x2) | ($p['animations'] ? 0 : 0x4) | ($p['themes'] ? 0 : 0x8)
          | 0x20 | ($p['smoothing'] ? 0x80 : 0) | ($p['themes'] ? 0x100 : 0);
$channels = [];   // channel data is never compressed (no CHANNEL_OPTION_COMPRESS_RDP): only the gateway would need it
if ($conn['clipboard']) {
    $channels[] = ['cliprdr', 0xC0200000];
}
if ($conn['drive'] || $conn['printer']) {
    $channels[] = ['rdpdr', 0x80000000];
}
if ($conn['drive'] || $conn['printer'] || $conn['audio'] || $conn['mic']) { // the server only enables device redirection when rdpsnd exists
    $channels[] = ['rdpsnd', 0xC0000000];
}
$channels[] = ['drdynvc', 0xC0000000];
// RemoteApp: the program's windows (rail channel); drawn from bitmaps and drawing orders (each window of the graphics pipeline
// comes on a surface of its own, not supported); on several screens too (the page's one is the primary monitor)
$app = (string)($conn['app'] ?? '');
if ($app !== '') {
    $channels[] = ['rail', 0xC0200000];
    $conn['gfx'] = 'off';
}

// several monitors: [[x, y, w, h, primary], ...] relative to the primary monitor; the desktop is their bounding box
$monitors = Drdynvc::monitors((array)($in['mons'] ?? [])) ?? [];
if ($monitors) {
    [$in['w'], $in['h']] = Drdynvc::bounds($monitors);
}

$client = new Client([
    'host' => $conn['host'], 'ip' => $ip, 'port' => $conn['port'], 'user' => $login, 'domain' => $domain, 'password' => $password,
    'width' => max(200, min($monitors ? 32766 : 8192, Http::int($in['w'] ?? 1024) & ~1)), 'height' => max(200, min($monitors ? 32766 : 8192, Http::int($in['h'] ?? 768))),
    'monitors' => $monitors,
    'bpp' => $conn['bpp'], 'kbd' => $conn['kbd'], 'audio' => $conn['audio'], 'mic' => $conn['mic'], 'orders' => (bool)Config::get('drawing_orders'), 'gfx' => $conn['gfx'], 'compression' => Bulk::level(Config::get('bulk_compression')), 'perf' => $perf, 'security' => $conn['security'],
    'tzBias' => max(-840, min(840, Http::int($in['tz'] ?? 0))), 'clientName' => Rdpdr::CLIENT_NAME, 'channels' => $channels, 'app' => $app,
], $emit);
$trace = !empty($opts['trace']) || Settings::get('trace_all');      // diagnostic mode
if ($trace) {
    Trace::cleanup();
    $trace = Trace::room();
}
if ($trace) {
    $client->trace = new Trace($sid, ['version' => MuDesk\VERSION, 'php' => PHP_VERSION, 'os' => PHP_OS_FAMILY, 'user' => $user['username'], 'conn' => $conn['name'],
        'host' => $conn['host'], 'ip' => $ip, 'port' => $conn['port'], 'security' => $conn['security'], 'bpp' => $conn['bpp'], 'kbd' => sprintf('0x%x', $conn['kbd']),
        'audio' => $conn['audio'], 'mic' => $conn['mic'], 'clipboard' => $conn['clipboard'], 'paste' => $paste, 'drive' => $conn['drive'], 'printer' => $conn['printer'], 'gfx' => $conn['gfx'],
        'h264' => !empty($in['avc']), 'orders' => (bool)Config::get('drawing_orders'), 'compression' => Bulk::level(Config::get('bulk_compression')),
        'size' => ($in['w'] ?? 0) . 'x' . ($in['h'] ?? 0), 'monitors' => $monitors, 'browser' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200),
        'client' => array_slice((array)($in['diag'] ?? []), 0, 20)]);
}
$dvc = new Drdynvc($client, !empty($in['avc']), $conn['mic']);
$client->addChannel($dvc);
$rail = $app !== '' ? new Rail($client, $app, (string)($conn['app_args'] ?? ''), !empty($in['again']), !empty($in['resume'])) : null;   // it may be running there
if ($rail) {
    $client->addChannel($rail);
    if ($monitors && ($main = Rail::mainMonitor($monitors))) {
        $rail->workArea(...$main);                  // several screens: the work area is the page's monitor (with its taskbar)
    }
}
$clip = $conn['clipboard'] ? new Cliprdr($client, $sid, $conn['name']) : null;
if ($clip) {
    $client->addChannel($clip);
    $client->paster = $clip->typeText(...);         // pastes text through the remote clipboard (see Client::pasteText)
}
if ($conn['drive'] || $conn['printer']) {
    $client->addChannel($drive = new Rdpdr($client, $user['id'], $conn['drive'], $conn['printer'], Cliprdr::tmpDir($sid), $sid, $conn['name'],
        ($in['lang'] ?? '') === 'en' ? 'Shared folder' : 'Carpeta compartida'));   // in the language of the page
}
if ($conn['audio']) {
    $client->addChannel(new Rdpsnd($client));
}

$status(['token' => $token, 'trace' => $trace]);
Audit::log('rdp_connect', $audit + ['host' => $conn['host'], 'target_ip' => $ip, 'port' => $conn['port'], 'rdp_user' => ($domain !== '' ? "$domain\\" : '') . $login,
    'password' => $saved !== null ? 'saved' : 'typed', 'security' => $conn['security'],
    'size' => ($in['w'] ?? 0) . 'x' . ($in['h'] ?? 0), 'monitors' => max(1, count($monitors)), 'bpp' => $conn['bpp'],
    'audio' => $conn['audio'], 'mic' => $conn['mic'], 'clipboard' => $conn['clipboard'], 'paste' => $paste, 'drive' => $conn['drive'], 'printer' => $conn['printer'],
    'gfx' => $conn['gfx'], 'h264' => !empty($in['avc']), 'compression' => Bulk::level(Config::get('bulk_compression')), 'orders' => (bool)Config::get('drawing_orders'), 'trace' => $trace]);
// while the host is slow to answer, a browser that left gives the worker back at once (PHP only sees it when writing: an
// empty keep-alive frame each second of waiting); the session also stays fresh in the registry meanwhile
Transport::$waiting = static function () use ($emit, $flush, $sess): void {
    $sess->heartbeat();
    $emit(6, '');
    if (!$flush()) {
        throw new ProtocolException('Browser closed during the connection', 'browser_closed');
    }
};
try {
    $client->connect(static function (string $fp) use ($conn, $user, $in, $audit, $sess): void {
        $sess->heartbeat();                   // mid-connection: the loop's first one comes 15 s after it
        if (empty($conn['cert']) || (($in['trust'] ?? '') === $fp)) {
            Connections::setCert($user['id'], $conn['id'], $fp);
            Audit::log('cert_trusted', $audit + ['fingerprint' => $fp, 'first_use' => empty($conn['cert'])], null, true);
        } elseif (!hash_equals($conn['cert'], $fp)) {
            Audit::log('cert_mismatch', $audit + ['expected' => $conn['cert'], 'received' => $fp], null, false);
            throw new ProtocolException($fp, 'cert_changed');
        }
    });
    $sess->heartbeat();                       // a connection over 45 s was taken for stale and its files removed
    Audit::log('rdp_start', $audit + ['host' => $conn['host'], 'fingerprint' => $client->certFingerprint, 'gfx_used' => $client->gfx,
        'server' => $client->serverVersion ? implode('.', $client->serverVersion) : null], null, true);
    $status(['connected' => true, 'cert' => $client->certFingerprint]);

    $idle     = Settings::get('rdp_idle_minutes') * 60;
    $lastIn   = $lastPing = $lastBeat = time();
    while (true) {
        $r = [$client->t->sock, ...$sess->streams()];
        $w = $e = null;
        $due = $client->sendInput();             // typed text goes out one character at a time
        @stream_select($r, $w, $e, 0, (int)(min($sess->polling() ? 0.02 : 1.0, $due ?? 1.0) * 1e6));
        if (!$client->poll()) {
            $reason = $client->errorInfo ? 'error_info' : 'disconnected';
            break;
        }
        if (!$flush()) {                         // the frames go out first: the history catches up after the input
            $reason = 'browser_closed';
            break;
        }
        foreach ($sess->read() as $m) {
            $type = ord($m[0] ?? "\0");
            if ($type === 1 && strlen($m) >= 2) {
                $lastIn = time();
                $client->input(substr($m, 2), ord($m[1]));
            } elseif ($type === 7) {                // typed text: characters as [pause ms, event count, length, events]
                $lastIn = time();
                for ($o = 1; $o + 3 <= strlen($m); $o += 3 + ord($m[$o + 2])) {
                    $client->input(substr($m, $o + 3, ord($m[$o + 2])), ord($m[$o + 1]), ord($m[$o]));
                }
            } elseif ($type === 8 && strlen($m) <= 1048577) {   // several characters at once from an on-screen keyboard (UTF-8)
                $lastIn = time();
                $client->pasteText(substr($m, 1), $paste);   // pasted with the option "phone keyboard through the clipboard", else typed
            } elseif ($type === 12 && $clip && strlen($m) <= 1048577) {   // a text of the clipboard history (UTF-8): always pasted
                $lastIn = time();
                $client->pasteText(substr($m, 1));
            } elseif ($type === 2 && strlen($m) >= 5) {
                $wh          = unpack('vw/vh', $m, 1);
                if ($dvc->resize($wh['w'], $wh['h'])) {
                    $client->resized($wh['w'], $wh['h']);
                    $rail?->workArea($wh['w'], $wh['h']);    // maximized windows fill the new size
                }
                Audit::log('rdp_resize', $audit + ['size' => $wh['w'] . 'x' . $wh['h']]);
            } elseif ($type === 13 && strlen($m) <= 4096) {   // several monitors, each the size of its window: JSON [[x, y, w, h, primary], ...]
                $mons = Drdynvc::monitors((array)json_decode(substr($m, 1), true));
                if ($mons !== null && $dvc->resizeMonitors($mons)) {
                    [$bw, $bh] = Drdynvc::bounds($mons);
                    $client->resized($bw, $bh);
                    if ($rail && ($main = Rail::mainMonitor($mons))) {
                        $rail->workArea(...$main);          // RemoteApp: the page's monitor, its taskbar left out
                    }
                    Audit::log('rdp_resize', $audit + ['size' => "{$bw}x{$bh}", 'monitors' => count($mons)]);
                }
            } elseif ($type === 14 && $rail && strlen($m) <= 1024) {   // RemoteApp: the desktop synced; a window brought to the front, minimized...
                $rail->command(substr($m, 1));
            } elseif ($type === 3) {
                $client->suppressOutput(($m[1] ?? "\1") !== "\0");
            } elseif ($type === 4 && $clip) {
                $clip->fromBrowser(substr($m, 1));
            } elseif ($type === 6) {
                $client->refresh();
            } elseif ($type === 10 && strlen($m) >= 9) {
                $dvc->gfxAck(substr($m, 1, 8));
            } elseif ($type === 15) {               // microphone: 16-bit PCM in the format the browser was told (F_MIC)
                $dvc->micData(substr($m, 1));
            } elseif ($type === 11) {               // diagnostic note from the browser (decoder errors, statistics)
                $client->trace?->log('browser', (array)json_decode(substr($m, 1, 4000), true));
            } elseif ($type === 5 || $type === 9) {
                $reason = $type === 5 ? 'user' : (substr($m, 1) === 'signed_out' ? 'signed_out' : 'killed');
                break 2;
            }
        }
        $client->catchUp();                      // the history of what was forwarded, now that frames and input went out
        $clip?->tick();
        $now = time();
        $rail?->tick();                          // RemoteApp: the program asked for if the browser did not report the desktop
        if ($now - $lastPing >= 10) {
            $emit(6, '');
            $lastPing = $now;
        }
        if ($now - $lastBeat >= 15) {
            $sess->heartbeat();
            $lastBeat = $now;
            // the account removed or disabled (also if the message that ends it was lost): the session ends. Not a new
            // password: every change ends the remote sessions that must end (Sessions::killUser), while an own change keeps
            // those of the web session that made it; comparing the session version here ended those too.
            $u = Users::get($user['id']);
            if ($u === null || !$u['enabled']) {
                $reason = 'signed_out';
                break;
            }
        }
        if ($idle > 0 && $now - $lastIn > $idle) {
            $reason = 'idle';
            break;
        }
        if (!$flush()) {
            $reason = 'browser_closed';
            break;
        }
    }
} catch (ProtocolException $e) {
    $reason = $e->key;
    $client->trace?->log('error', ['key' => $e->key, 'message' => $e->getMessage()]);
    $detail = $e->key === 'cert_changed' ? $e->getMessage() : null;
} catch (Throwable $e) {
    $reason = 'internal';
    error_log('MuDeskClient: ' . $e);
    $client->trace?->log('internal error', ['message' => $e->getMessage(), 'at' => basename($e->getFile()) . ':' . $e->getLine(),
        'stack' => array_map(static fn($f) => basename($f['file'] ?? '?') . ':' . ($f['line'] ?? 0) . ' ' . ($f['function'] ?? ''), array_slice($e->getTrace(), 0, 12))]);
} finally {
    if (isset($client->t)) {
        $client->disconnect();
    }
    $client->trace?->close(['reason' => $reason, 'errorInfo' => sprintf('0x%08x', $client->errorInfo), 'seconds' => time() - $started,
        'bytesIn' => isset($client->t) ? $client->t->bytesIn : 0, 'bytesOut' => isset($client->t) ? $client->t->bytesOut : 0]);
    $cleanup();
    Audit::log('rdp_end', $audit + ['reason' => $reason, 'errorInfo' => $client->errorInfo, 'seconds' => time() - $started,
                           'in' => isset($client->t) ? $client->t->bytesIn : 0, 'out' => isset($client->t) ? $client->t->bytesOut : 0],
               null, in_array($reason, ['user', 'browser_closed', 'disconnected', 'idle', 'closed'], true));
}
if ($reason !== 'browser_closed') {
    $emit(Client::F_STATUS, json_encode(['end' => $reason, 'detail' => $detail, 'errorInfo' => $client->errorInfo]));
    $flush();
}
