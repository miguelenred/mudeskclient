<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

/*
 * RDP gateway endpoints.
 *  POST ?a=stream  JSON {c, sid, w, h, tz, password?, user?}  -> long binary response: [u8 type][u32 length][payload]...
 *  POST ?a=send&sid=...  binary message for the running session (input, resize, clipboard...), header X-MuDesk-Token
 *                        (a guest given control: its keyboard, mouse and touch, header X-MuDesk-Share)
 *  POST ?a=watch  JSON {link}  -> a guest's view of a shared session, the same frames (see Sessions::GUEST_FRAMES)
 *  POST ?a=replay JSON {sid}   -> a session's recording, for an administrator or an auditor, or for its user one they started (see Recording)
 */

use MuDesk\{Access, Audit, Auth, Config, Connections, Files, Http, Recording, Sessions, Settings, Users, WakeOnLan};
use MuDesk\Rdp\{Bulk, Client, Cliprdr, Drdynvc, Gateway, ProtocolException, Rail, Rdpdr, Rdpsnd, Redirection, Trace, Transport};

require __DIR__ . '/../src/bootstrap.php';   // PHP's messages to the log only: a warning printed into the binary stream broke it

/** A long binary response: nothing buffered or compressed on the way, and the request goes on to its end. */
function stream_start(): void
{
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
}

Http::guard();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Http::fail('method', 405);
}
$a = (is_string($_GET['a'] ?? null) ? $_GET['a'] : '');

if ($a === 'send') { // hot path for input: no PHP session, authorised by the per-session token (a header: URLs are logged)
    $sid   = (is_string($_GET['sid'] ?? null) ? $_GET['sid'] : '');
    $guest = (string)($_SERVER['HTTP_X_MUDESK_SHARE'] ?? '');
    // the body read only once the request is the session's (its token, or a guest's link), and never past its largest:
    // post_max_size does not limit php://input, and a body read whole before the token was checked, from anyone, took the
    // memory up to memory_limit (a fatal error)
    // (as long as the request says: PHP 8.1 and 8.2 take the memory of the most asked for at once, 4 MB for each key)
    $read = static function (int $max): string {
        $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
        $b   = $len > $max ? '' : (string)@file_get_contents('php://input', false, null, 0, $len > 0 ? $len : $max + 1);
        return strlen($b) > $max ? '' : $b;
    };
    if ($guest !== '') {   // a guest given control: its keyboard, mouse and touch only, on a lane of its own (see Sessions::read)
        $share = Sessions::findShare($sid . '.' . $guest);
        $body  = $share !== null && $share[2] === 'control' ? $read(4096) : '';   // (a few keys or a short text)
        if (strlen($body) < 5 || !in_array(ord($body[4]), Sessions::GUEST_TYPES, true)) {
            http_response_code(404);
            exit;
        }
        http_response_code(Sessions::send($sid, pack('V', Sessions::GUEST_INPUT) . $share[1] . substr($body, 4)) ? 204 : 410);   // (whose: its share)
        exit;
    }
    $info = Sessions::info($sid);
    // the session's file keeps the SHA-256 of its token, never the token (a copy of the data folder gives no keyboard);
    // a session started before that change kept the token itself (32 characters)
    $have = (string)($_SERVER['HTTP_X_MUDESK_TOKEN'] ?? '');
    $want = (string)($info['token'] ?? '');
    $body = $info !== null && $have !== '' && hash_equals($want, strlen($want) === 64 ? hash('sha256', $have) : $have) ? $read(4 * 1048576) : '';
    if (strlen($body) < 5 || ord($body[4]) === 9 || ord($body[4]) >= 0x20   // (9 and up: not the browser's)
        // out of order (Sessions::OUT_OF_BAND): only its camera's pictures, its folder's answers and its recording's video,
        // which no key waits behind
        || (unpack('V', $body)[1] >= Sessions::GUEST_INPUT && (unpack('V', $body)[1] !== Sessions::OUT_OF_BAND || !in_array(ord($body[4]), [18, 20, 21, 22], true)))) {
        http_response_code(404);
        exit;
    }
    http_response_code(Sessions::send($sid, $body) ? 204 : 410);
    exit;
}

if ($a === 'watch') { // a guest's view of a shared session: no account, the secret of the link (in the body: URLs are logged)
    $in    = Http::input();
    $share = is_string($in['link'] ?? null) ? Sessions::findShare($in['link']) : null;
    $c     = $share === null ? null : Sessions::watch($share[0], $share[1]);
    if ($c === null) {
        Http::fail('share_ended', 404);
    }
    [$sid, $id, $mode, $info] = $share;
    $log = ['sid' => $sid, 'conn' => $info['conn'] ?? '', 'owner' => $info['user'] ?? '', 'share' => $id, 'mode' => $mode];
    Audit::log('share_join', $log, null, true);
    stream_start();
    $st = json_encode(['watch' => $mode, 'owner' => $info['user'] ?? '', 'conn' => $info['conn'] ?? '', 'sid' => $sid, 'recording' => !empty($info['record'])]);
    echo pack('CV', 0, 2048), str_repeat("\0", 2048), pack('CV', Client::F_STATUS, strlen($st)), $st;   // (padding, as the owner's)
    flush();
    // the session's frames as they come, from its next connection (its arrival brings one); a keep-alive every 10 s
    // comes with them, so a guest that left is noticed. It ends with the session, or when the link is revoked.
    $started = time();
    stream_set_blocking($c, false);
    while (connection_aborted() === 0) {
        $r = [$c];
        $w = $e = null;
        if (@stream_select($r, $w, $e, 30) === false) {
            break;
        }
        $d = (string)fread($c, 1 << 17);
        if ($d === '' && feof($c)) {
            break;
        }
        echo $d;
        flush();
    }
    fclose($c);
    Audit::log('share_leave', $log + ['seconds' => time() - $started], null, true);
    exit;
}

if (!isset($_COOKIE['MUDESKSID'])) {             // signed out: no session file is made for the request
    Http::fail('auth', 401);
}
Auth::start();
Auth::checkCsrf();
$user = Auth::require();

// clipboard files and print jobs of a session: kept until it ends and counted in the user's shared folder quota
$clipBytes = static fn(string $sid): int => Sessions::quotaBytes($sid);

if ($a === 'clipfile') { // chunked upload of clipboard files/images: ?sid=&n=<index|image.dib>&o=<offset>
    Auth::release();
    $sid  = (is_string($_GET['sid'] ?? null) ? $_GET['sid'] : '');
    $info = Sessions::info($sid);
    $name = (is_string($_GET['n'] ?? null) ? $_GET['n'] : '');
    $off  = (int)($_GET['o'] ?? 0);
    if ($info === null || $info['uid'] !== $user['id'] || !preg_match('/^(\\d{1,4}|image\\.dib)$/D', $name)) {
        Http::fail('session', 404);
    }
    $dir = Cliprdr::tmpDir($sid);
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    $path = $dir . '/' . $name;
    // one at a time with the user's uploads: copied at once, each wrote up to the room left (then given back), and two
    // with the same file wrote into it together
    $lock = fopen(Config::dir('tmp/upload-' . $user['id'] . '.lock'), 'c');
    flock($lock, LOCK_EX);
    if ($off === 0) {
        if (is_file($path)) {                 // copied again: the old file gives its quota back
            Files::charge($user['id'], -(int)filesize($path));
            @unlink($path);
        }
    } elseif (!is_file($path) || filesize($path) !== $off) {
        Http::fail('offset');
    }
    $left = Files::quotaLeft($user['id']);
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $left) {   // too big: refused before a byte is written
        Files::charge($user['id'], -$off);
        @unlink($path);
        Http::fail('quota');
    }
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
    // made by the remote computer: never shown as a page of this site, even opened directly (the page fetches it)
    header("Content-Security-Policy: default-src 'none'; sandbox");
    header('Content-Disposition: attachment');
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: no-store');
    readfile($file);
    Audit::log('print_download', ['sid' => $sid, 'conn' => $info['conn'] ?? '', 'job' => (int)($_GET['job'] ?? 0), 'size' => filesize($file), 'pdf' => $pdf], null, true);
    exit;
}

if ($a === 'replay') { // a recorded session, for an administrator or an auditor (or its user, one they started): its frames with their times
    $ru   = Auth::require();
    Auth::release();
    $rsid = is_string(Http::input()['sid'] ?? null) ? Http::input()['sid'] : '';
    $info = Recording::info($rsid);
    if ($info === null || (!in_array($ru['role'], ['admin', 'auditor'], true) && !Recording::ownedBy($info, $ru))) {
        Http::fail('not_found', 404);
    }
    Audit::log('recording_view', ['sid' => $rsid, 'conn' => $info['conn'] ?? '', 'owner' => $info['user'] ?? ''], null, true);
    stream_start();
    $whole = Recording::read($rsid, static function (string $p): bool {
        echo $p;
        flush();
        return connection_aborted() === 0;
    });
    if ($whole) {                               // read to its end: a padding frame "end" says so
        echo pack('VCV', 0, 0, 3), 'end';
    }
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
if ($conn !== null && !empty($conn['occasional']) && !Auth::createsConns($user)) {
    $conn = null;                             // an occasional connection: only while the user may make connections
}
$occasional = !empty($conn['occasional']) ? ['occasional' => true] : [];   // (said in its audit lines)
if ($conn !== null && empty($conn['record']) && Connections::recordedTarget($user['id'], $conn)) {
    $conn['record'] = true;                   // its computer is recorded through another connection: recorded through this one too
}
if (!Users::withinHours($user)) {             // outside the user's sign-in hours: no new session (open ones go on)
    Audit::log('rdp_denied', ['conn' => $conn['name'] ?? '', 'reason' => 'outside_hours'] + $occasional, null, false);
    Http::$auditFailures = false;
    Http::fail('outside_hours', 403);
}

// admission before any output (a full gateway answers 503 at once, before opening a socket): the limits are checked and the
// session registered under one lock
$limit = $user['max_sessions'] ?: Settings::get('max_sessions_per_user');
$total = Settings::get('max_sessions_total');
$token = bin2hex(random_bytes(16));
$sess  = !Sessions::validSid($sid) || Sessions::info($sid) !== null || $conn === null ? 'invalid_request'
       : Sessions::register($sid, ['uid' => $user['id'], 'user' => $user['username'], 'conn' => $conn['name'], 'host' => $conn['host'],
                                   'ip' => Http::clientIp(), 'token' => hash('sha256', $token), 'wid' => Auth::wid(), 'app' => ($conn['app'] ?? '') !== '', 'record' => !empty($conn['record']),
                                   'record_by' => empty($conn['record']) ? null : 'policy'], $limit, $total);
if ($sess === 'busy') {
    Audit::log('rdp_denied', ['conn' => $conn['name'], 'reason' => 'busy', 'limit' => $total] + $occasional, null, false);
    Http::$auditFailures = false;             // logged just above
    Http::fail('busy', 503);
}

// ---------------------------------------------------------------- streaming response
stream_start();

// a shared session: what guests may see is copied for them while any watches ($outV), and sent with the owner's
$out     = $outV = '';
$watched = false;
$rec     = null;                            // the session's recording, when the administrator records the connection
$recBy   = null;                            // ... who started it: 'policy' (the connection's), 'admin' or 'owner' (in the session)
$emit    = static function (int $type, string $payload) use (&$out, &$outV, &$watched, &$rec): void {
    $h    = pack('CV', $type, strlen($payload));
    $out .= $h;                               // (two appends: header . payload copied a large message once more)
    $out .= $payload;
    if ($watched && isset(Sessions::GUEST_FRAMES[$type])) {
        $outV .= $h;
        $outV .= $payload;
    }
    $rec?->frame($type, $payload);
};
$flush   = static function () use (&$out, &$outV, &$watched, $sess): bool {
    if ($out !== '') {
        echo $out;
        $out = '';
    }
    flush();
    if ($watched) {
        $sess->toViewers($outV);
        $outV = '';
    }
    return connection_aborted() === 0;
};
$status = static function (array $s) use ($emit, $flush): void {
    $emit(Client::F_STATUS, json_encode($s));
    $flush();
};
$emit(0, str_repeat("\0", 2048)); // padding: makes some proxies start forwarding
$flush();

if (is_string($sess)) {
    Audit::log('rdp_denied', ($sess === 'too_many_sessions' ? ['conn' => $conn['name'], 'reason' => $sess, 'limit' => $limit]
        : ['reason' => $conn === null ? 'unknown_connection' : 'invalid_session_id']) + $occasional, null, false);
    $status(['end' => $sess]);
    exit;
}
// registered: every way out removes the session
$started = time();
$reason  = 'closed';
$detail  = $certHost = $certSha1 = null;
$audit   = ['sid' => $sid, 'conn' => $conn['name']] + $occasional;   // context of every event of this RDP session
$drive   = null;
$localDir = null;   // [name, read only]: a folder of this computer shared from the browser (see Rdpdr::addLocal)
$cleaned = false;
$bytesIn = $bytesOut = 0;                  // of the connections before this one (made again for guests)
$recEnd  = null;                            // a recording made in the browser being stopped: how it ends, once its page sent the rest
$cleanup = static function () use (&$cleaned, &$drive, &$rec, &$recEnd, $sess, $sid, $user, $clipBytes): void {
    if (!$cleaned) {
        $cleaned = true;
        $rec?->close($recEnd ?? []);                           // what waits is written, its details completed
        $drive?->removeLocal(false);                           // this computer's folder: its files still open audited
        $drive?->release();                                    // drive quota taken in advance and not used
        Files::charge($user['id'], -$clipBytes($sid));         // the clipboard files and print jobs go with the session
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
// through a Remote Desktop Gateway: the gateway must be an allowed target (it reaches the computer, by its own rules)
$gw = $conn['gw'] !== '' ? Connections::gateway($conn['gw']) : null;
if ($conn['gw'] !== '' && $gw === null) {      // (one stored that is not a gateway: never straight to the computer instead)
    $cleanup();
    $status(['end' => 'gateway']);
    exit;
}
$ip = $gw !== null ? Access::resolveTarget($gw[0]) : Access::resolveTarget($conn['host']);
if ($ip === null) {
    $cleanup();
    Audit::log('rdp_denied', ['conn' => $conn['name'], 'host' => $gw[0] ?? $conn['host'], 'reason' => 'target_not_allowed'] + $occasional, null, false);
    $status(['end' => $gw !== null ? 'gateway_not_allowed' : 'target_denied']);
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

$trace = !empty($opts['trace']) || Settings::get('trace_all');      // diagnostic mode
if ($trace) {
    Trace::cleanup();
    $trace = Trace::room();
}
try {
$tr = !$trace ? null : new Trace($sid, ['version' => MuDesk\VERSION, 'php' => PHP_VERSION, 'os' => PHP_OS_FAMILY, 'user' => $user['username'], 'conn' => $conn['name'],
    'host' => $conn['host'], 'ip' => $ip, 'port' => $conn['port'], 'gateway' => $gw !== null ? $gw[0] . ':' . $gw[1] : null, 'routing_token' => $conn['lb'] !== '', 'security' => $conn['security'], 'bpp' => $conn['bpp'], 'kbd' => sprintf('0x%x', $conn['kbd']),
    'audio' => $conn['audio'], 'mic' => $conn['mic'], 'clipboard' => $conn['clipboard'], 'paste' => $paste, 'drive' => $conn['drive'], 'printer' => $conn['printer'], 'gfx' => $conn['gfx'],
    'h264' => !empty($in['avc']), 'orders' => (bool)Config::get('drawing_orders'), 'compression' => Bulk::level(Config::get('bulk_compression')),
    'size' => ($in['w'] ?? 0) . 'x' . ($in['h'] ?? 0), 'monitors' => $monitors, 'browser' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200),
        'client' => array_slice((array)($in['diag'] ?? []), 0, 20)]);

// the client and its channels; made again, for the desktop as it is then, when a guest joins a shared session
$size  = [Http::int($in['w'] ?? 1024), Http::int($in['h'] ?? 768), $monitors];
$client = $dvc = $rail = $clip = null;
// the certificate of another computer than the connection's (a broker's redirection target, its gateway): pinned on its first
// use, as the connection's own; one the broker vouched for ($vouched: the target's certificate in its redirection) is the one
// to find there, at once (another is a changed certificate, even on a first use). The broker's word never replaces a
// certificate pinned before: a broker taken over could send the user to an impostor it vouched for (the user decides)
//
// A computer (or gateway) never seen is not trusted by itself: the connection stops before anything of the user is sent, and
// the page shows its fingerprints (SHA-256, and SHA-1 as Windows shows it) for the user to confirm (`trust`, the next time).
// Before, it was trusted at once: a man in the middle of that first connection got the password (or the NLA exchange)
$certEnd = static function (string $key, string $fp, string $sha1, ?string $host = null): never {
    throw new ProtocolException($fp . ' ' . ($sha1 !== '' ? $sha1 : '-') . ($host !== null ? ' ' . $host : ''), $key);
};
$pinFor = static function (string $host, string $fp, ?string $vouched = null, bool $gateway = false, string $sha1 = '') use ($conn, $user, $in, $audit, $certEnd): void {
    $pinned = (string)(Connections::pins($user['id'], $conn)[1][Connections::certKey($host, $gateway)] ?? '');   // (read again: pinned
    if ($pinned === $fp && ($vouched === null || hash_equals($vouched, $fp))) {                       // meanwhile, too)
        return;
    }
    if (($in['trust'] ?? '') === $fp || ($pinned === '' && ($vouched !== null ? hash_equals($vouched, $fp) : Config::get('confirm_new_certificates') === false))) {
        Connections::trust($user['id'], $conn, $fp, $host, $gateway);
        Audit::log('cert_trusted', $audit + ['host' => $host, 'fingerprint' => $fp, 'first_use' => $pinned === '']
            + ($vouched !== null ? ['vouched_by_broker' => hash_equals($vouched, $fp)] : []), null, true);
    } elseif ($pinned === '' && $vouched === null) {
        $certEnd('cert_new', $fp, $sha1, $host);   // (the page says which computer: not the connection's)
    } else {
        Audit::log('cert_mismatch', $audit + ['host' => $host, 'expected' => $vouched ?? $pinned, 'received' => $fp], null, false);
        $certEnd('cert_changed', $fp, $sha1, $host);
    }
};
// a gateway's consent message (its terms of use): shown on the page, the session going on only if the user accepts it there
// (the page's answer comes as message 17; ten minutes at most, the browser checked meanwhile; leaving is declining). The
// session's other messages that come meanwhile are kept for the main loop ($held: a guest arriving, a link revoked, a
// resize; before, they were lost). The same terms accepted again at once in this session (the session is connected again
// when a guest joins, or a broker sends it elsewhere: the user would have had to accept them again, ten minutes at most)
$held     = [];
$accepted = [];
$consent  = static function (string $text) use ($status, $sess, $audit, &$held, &$accepted): bool {
    if (isset($accepted[hash('sha256', $text)])) {
        Audit::log('gateway_consent', $audit + ['accepted' => true, 'again' => true], null, true);
        return true;
    }
    $status(['gateway_message' => $text, 'consent' => true]);
    for ($end = microtime(true) + 600, $ok = null, $why = 'timeout'; $ok === null && microtime(true) < $end;) {
        (Transport::$waiting)();
        foreach ($sess->read() as $m) {
            $type = ord($m[0] ?? "\0");
            if ($type === 17) {
                $ok  = ($m[1] ?? "\0") === "\x01";
                $why = $ok ? 'accepted' : 'declined';
                continue;
            }
            if ($type === 5 || $type === 9) {           // closed, or ended meanwhile (kept: the main loop says why)
                [$ok, $why] = [false, $type === 5 ? 'closed' : 'ended'];
            }
            $held[] = $m;
        }
        if ($ok === null) {
            $r = $sess->streams();
            $w = $e = null;
            $r ? @stream_select($r, $w, $e, 0, 500000) : usleep(100000);
        }
    }
    $ok === true && $accepted[hash('sha256', $text)] = true;
    Audit::log('gateway_consent', $audit + ['accepted' => $ok === true] + ($ok === true ? [] : ['why' => $why]), null, $ok === true);
    return $ok === true;
};
// the gateway opened for the computer the client connects to (also one a broker sent it to)
$gwOpen = $gw === null ? null : static function (string $target, int $port) use ($gw, $ip, $login, $domain, $password, $pinFor, $consent): Gateway {
    return new Gateway($gw[0], $ip, $gw[1], $login, $domain, $password, $target, $port, Rdpdr::CLIENT_NAME,
        static fn(string $fp, string $sha1 = '') => $pinFor($gw[0], $fp, null, true, $sha1), $consent);
};
$redirect = null;                             // a connection broker's redirection being followed: [info, host, ip]
$build = static function () use (&$client, &$dvc, &$rail, &$clip, &$drive, &$size, &$redirect, &$localDir, $tr, $conn, $ip, $gw, $gwOpen, $login, $domain, $password, $perf, $channels, $app, $in, $emit, $sid, $user): void {
    [$w, $h, $mons] = $size;
    $co = [
        'host' => $conn['host'], 'ip' => $gw === null ? $ip : '', 'port' => $conn['port'], 'lb' => $conn['lb'], 'gateway' => $gwOpen, 'user' => $login, 'domain' => $domain, 'password' => $password,
        'width' => max(200, min($mons ? 32766 : 8192, $w & ~1)), 'height' => max(200, min($mons ? 32766 : 8192, $h)),
        'monitors' => $mons,
        'bpp' => $conn['bpp'], 'kbd' => $conn['kbd'], 'audio' => $conn['audio'], 'mic' => $conn['mic'], 'orders' => (bool)Config::get('drawing_orders'), 'gfx' => $conn['gfx'], 'compression' => Bulk::level(Config::get('bulk_compression')), 'perf' => $perf, 'security' => $conn['security'],
        'tzBias' => max(-840, min(840, Http::int($in['tz'] ?? 0))), 'clientName' => Rdpdr::CLIENT_NAME, 'channels' => $channels, 'app' => $app,
        'vm' => $conn['vm'] !== '' ? $conn['vm'] . ($conn['vm_enhanced'] ? ';EnhancedMode=1' : '') : '',
    ];
    $client = new Client($redirect === null ? $co : Client::redirected($co, $redirect[0], $redirect[1], $redirect[2]), $emit);
    $client->trace = $tr;
    // the camera with the microphone (one option), if the browser has one; its name in Windows in the language of the page
    $dvc = new Drdynvc($client, !empty($in['avc']), $conn['mic'], $conn['touch'],
        $conn['mic'] && !empty($in['cam']) ? (($in['lang'] ?? '') === 'en' ? 'MuDesk camera' : 'Cámara de MuDesk') : null);
    $client->addChannel($dvc);
    $rail = $app !== '' ? new Rail($client, $app, (string)($conn['app_args'] ?? ''), !empty($in['again']), !empty($in['resume'])) : null;   // it may be running there
    if ($rail) {
        $client->addChannel($rail);
        if ($mons && ($main = Rail::mainMonitor($mons))) {
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
        $localDir !== null && $drive->addLocal(...$localDir);   // (this computer's folder, shared before a connection made again)
    }
    if ($conn['audio']) {
        $client->addChannel(new Rdpsnd($client));
    }
};
$build();
} catch (Throwable $e) {
    error_log('MuDeskClient: ' . $e);
    $cleanup();
    Audit::log('rdp_end', $audit + ['reason' => 'internal'], null, false);
    $status(['end' => 'internal']);
    exit;
}

$status(['token' => $token, 'trace' => $trace]);
// recorded connection: the page is told (and says so) before anything is shown; no recording, no session
if (!empty($conn['record'])) {
    Recording::cleanup();
    $rec = Recording::start($sid, ['uid' => $user['id'], 'user' => $user['username'], 'conn' => $conn['name'], 'host' => $conn['host'], 'started_as' => 'policy']);
    if ($rec === null) {
        $cleanup();
        Audit::log('rdp_denied', ['conn' => $conn['name'], 'reason' => 'record_failed'] + $occasional, null, false);
        $status(['end' => 'record_failed']);
        exit;
    }
    $status(['recording' => true, 'record_by' => 'policy']);
    $recBy = 'policy';
}
Audit::log('rdp_connect', $audit + ['host' => $conn['host'], 'target_ip' => $ip, 'port' => $conn['port'], 'rdp_user' => ($domain !== '' ? "$domain\\" : '') . $login,
    'password' => $saved !== null ? 'saved' : 'typed', 'security' => $conn['security'], 'gateway' => $gw[0] ?? null, 'routing_token' => $conn['lb'] !== '',
    'size' => ($in['w'] ?? 0) . 'x' . ($in['h'] ?? 0), 'monitors' => max(1, count($monitors)), 'bpp' => $conn['bpp'],
    'audio' => $conn['audio'], 'mic' => $conn['mic'], 'clipboard' => $conn['clipboard'], 'paste' => $paste, 'drive' => $conn['drive'], 'printer' => $conn['printer'],
    'gfx' => $conn['gfx'], 'h264' => !empty($in['avc']), 'compression' => Bulk::level(Config::get('bulk_compression')), 'orders' => (bool)Config::get('drawing_orders'), 'trace' => $trace]
    + ($rec !== null ? ['recording' => true] : []));
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
    // Wake-on-LAN: a computer with a MAC address that does not answer is sent the packet and waited for (up to two minutes,
    // the packet again every 30 s); the browser is told, and a browser that leaves frees the worker as during a connection.
    // The packet goes only to the whole network or to an address the targets allow.
    $wake = $conn['wol'] !== '' && $gw === null ? WakeOnLan::target($conn['wol_ip']) : null;   // (behind a gateway: not this network)
    if ($wake !== null && ($wake[0] === WakeOnLan::ALL || Access::resolveTarget($wake[0]) !== null) && !Transport::reachable($ip, $conn['port'], 2.0)) {
        $sent = WakeOnLan::send($conn['wol'], ...$wake);
        Audit::log('wol_sent', $audit + ['mac' => $conn['wol'], 'to' => implode(':', $wake)], null, $sent);
        $status(['waking' => true]);
        $until = microtime(true) + WakeOnLan::WAIT;
        $again = microtime(true) + WakeOnLan::RETRY;
        while (microtime(true) < $until) {
            $t = microtime(true);
            if (Transport::reachable($ip, $conn['port'], 1.0)) {
                break;
            }
            (Transport::$waiting)();
            if (microtime(true) >= $again) {
                WakeOnLan::send($conn['wol'], ...$wake);
                $again = microtime(true) + WakeOnLan::RETRY;
            }
            usleep((int)max(0, 1e6 - (microtime(true) - $t) * 1e6));   // one try a second (a refused connection answers at once)
        }
    }
    // a connection broker's redirection: to the computer it names, or, with a routing token and no address, back to the one
    // that sent it (MS-RDPBCGR 1.3.8), with its token, session and cookie. Without a gateway, the first of the computers it
    // names that are allowed targets (by address first, as Windows' own client), each tried in turn while one cannot be
    // reached (a broker names every address of its computer, some of them out of this server's reach); through one, by name
    // first (the gateway's policies name the computers, and decide)
    $follow = static function (Redirection $r, bool $late = false) use (&$redirect, $conn, $gw, $ip, $login, $audit): void {
        $targets = Client::redirectTargets($r->info, $gw !== null);
        if ($targets === []) {
            $to = [[$redirect[1] ?? $conn['host'], $redirect[2] ?? ($gw !== null ? '' : $ip)]];
        } elseif ($gw !== null) {
            $to = array_map(static fn(string $t): array => [$t, ''], $targets);
        } else {
            $to = [];
            foreach ($targets as $t) {
                if (($a = Access::resolveTarget($t)) !== null && !in_array($a, array_column($to, 1), true)) {
                    $to[] = [$t, $a];
                }
            }
            if ($to === []) {
                Audit::log('rdp_denied', $audit + ['host' => $targets[0], 'reason' => 'target_not_allowed', 'redirected' => true], null, false);
                throw new ProtocolException($targets[0], 'target_denied');
            }
            // its addresses named by the computer's name the broker gave too (TargetFQDN): that name for Kerberos, TLS and the
            // certificate's pin, the address checked connected to (before, Kerberos found no realm for an address: NTLM,
            // which a domain that turns NTLM off refuses)
            $fqdn = (string)($r->info['fqdn'] ?? '');
            if ($fqdn !== '' && strlen($fqdn) <= 253 && preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?$/', $fqdn)) {
                foreach ($to as $i => [$t]) {
                    if (filter_var($t, FILTER_VALIDATE_IP) !== false) {
                        $to[$i][0] = $fqdn;
                    }
                }
            }
        }
        Audit::log('rdp_redirect', $audit + ['to' => $to[0][0], 'target_ip' => $to[0][1] !== '' ? $to[0][1] : null, 'session' => $r->info['session'],
            'routing_token' => $targets === [] && $r->info['lb'] !== null, 'user_changed' => ($r->info['user'] ?? '') !== '' && strcasecmp($r->info['user'], $login) !== 0]
            + ($late ? ['late' => true] : []), null, true);
        $redirect = [$r->info, $to[0][0], $to[0][1], array_slice($to, 1)];
    };
    // the bytes of a connection that ends, added once (then zeroed: the end adds what the last one has; before, a redirection
    // refused added its connection's twice)
    $tally = static function () use (&$client, &$bytesIn, &$bytesOut): void {
        if (isset($client->t)) {
            $bytesIn  += $client->t->bytesIn;
            $bytesOut += $client->t->bytesOut;
            $client->t->bytesIn = $client->t->bytesOut = 0;
        }
    };
    // connected, following a broker's redirections (at most 3 in a row); the bytes of each connection counted
    $connectTo = static function (callable $verify) use (&$client, &$redirect, $build, $follow, $audit, $tally): void {
        for ($hop = 0; ; ) {
            try {
                $client->connect($verify);
                return;
            } catch (Redirection $r) {
                $tally();
                $client->disconnect();
                if ($hop++ >= 3) {
                    throw new ProtocolException('Too many redirections', 'redirect');
                }
                $follow($r);
                $build();
            } catch (ProtocolException $e) {
                // a broker's computer that cannot be reached (or that the gateway does not reach): the next one it named
                if (($redirect[3] ?? []) === [] || !in_array($e->key, ['unreachable', 'gateway_denied'], true)) {
                    throw $e;
                }
                if (isset($client->t)) {
                    $tally();
                    $client->disconnect();
                }
                [$to, $toIp] = array_shift($redirect[3]);
                Audit::log('rdp_redirect', $audit + ['to' => $to, 'target_ip' => $toIp !== '' ? $toIp : null, 'instead_of' => $redirect[1], 'reason' => $e->key], null, true);
                $redirect = [$redirect[0], $to, $toIp, $redirect[3]];
                $build();
            }
        }
    };
    // each computer's certificate checked against the one pinned for it, read again each time (a guest's join, a broker's
    // redirection back to a computer already seen)
    $verify = static function (string $fp, string $sha1 = '') use ($conn, $user, $in, $audit, $sess, &$redirect, $pinFor, $certEnd, $ip, $gw): void {
        $sess->heartbeat();                   // mid-connection: the loop's first one comes 15 s after it
        $vouched = $redirect[0]['cert'] ?? null;   // the certificate the broker gave for the computer it sent the client to
        if ($redirect !== null && strcasecmp($redirect[1], $conn['host']) !== 0) {
            // another computer of the broker's: the certificate an administrator fixed for it only (before, the user was asked,
            // and could take another), else its own, pinned for the user
            $fixed = Connections::fixedCert((string)$redirect[1], (int)$conn['port'], $gw === null ? (string)$redirect[2] : '');
            if ($fixed === null) {
                $pinFor($redirect[1], $fp, $vouched, false, $sha1);
            } elseif (!hash_equals($fixed, strlen($fixed) === 40 ? $sha1 : $fp)) {
                Audit::log('cert_mismatch', $audit + ['host' => $redirect[1], 'expected' => $fixed, 'received' => $fp, 'fixed_by_admin' => true], null, false);
                $certEnd('cert_unexpected', $fp, $sha1, $redirect[1]);
            }
            return;
        }
        $pinned = Connections::pins($user['id'], $conn)[0];   // (an occasional connection's: the user's for its computer)
        // the certificate an administrator fixed for this computer (by its name or its address, in any connection to it): that
        // one only, whatever was pinned or the user would trust; never asked
        $fixed   = Connections::fixedCert((string)$conn['host'], (int)$conn['port'], $gw === null ? (string)$ip : '');   // (through a
        $isFixed = $fixed !== null && hash_equals($fixed, strlen($fixed) === 40 ? $sha1 : $fp);   // gateway, $ip is the gateway's)
        // an administrator who trusts it for every user (the page's box): fixed for any connection to this computer, also in
        // place of one fixed before (a certificate renewed); once (the page sends it again when it connects again)
        if (!$isFixed && ($user['role'] ?? '') === 'admin' && !empty($in['trust_all']) && ($in['trust'] ?? '') === $fp) {
            Connections::fixCert((string)$conn['host'], (int)$conn['port'], $fp, (string)$user['username']);
            Audit::log('cert_fixed', $audit + ['host' => $conn['host'], 'port' => (int)$conn['port'], 'fingerprint' => $fp], null, true);
            [$fixed, $isFixed] = [$fp, true];
        }
        if ($fixed !== null) {
            if ($isFixed) {
                $pinned !== $fp && Connections::trust($user['id'], $conn, $fp);
                return;
            }
            Audit::log('cert_mismatch', $audit + ['expected' => $fixed, 'received' => $fp, 'fixed_by_admin' => true], null, false);
            $certEnd('cert_unexpected', $fp, $sha1);
        }
        if ($pinned === $fp && ($vouched === null || hash_equals($vouched, $fp))) {
            return;
        }
        if (($in['trust'] ?? '') === $fp || ($pinned === '' && ($vouched !== null ? hash_equals($vouched, $fp) : Config::get('confirm_new_certificates') === false))) {   // (as $pinFor)
            Connections::trust($user['id'], $conn, $fp);
            Audit::log('cert_trusted', $audit + ['fingerprint' => $fp, 'first_use' => $pinned === '']
                + ($vouched !== null ? ['vouched_by_broker' => hash_equals($vouched, $fp)] : []), null, true);
        } elseif ($pinned === '' && $vouched === null) {
            $certEnd('cert_new', $fp, $sha1);
        } else {
            Audit::log('cert_mismatch', $audit + ['expected' => $vouched ?? $pinned, 'received' => $fp], null, false);
            $certEnd('cert_changed', $fp, $sha1);
        }
    };
    $connectTo($verify);
    $sess->heartbeat();                       // a connection over 45 s was taken for stale and its files removed
    Audit::log('rdp_start', $audit + ['host' => $conn['host'], 'fingerprint' => $client->certFingerprint, 'gfx_used' => $client->gfx,
        'server' => $client->serverVersion ? implode('.', $client->serverVersion) : null] + ($rec !== null ? ['recording' => true] : [])
        + ($redirect !== null ? ['redirected_to' => $redirect[1]] : []) + ($client->denied !== [] ? ['gateway_denied' => $client->denied] : [])
        + ($client->kerberos ? ['nla' => 'kerberos'] : []) + ($client->rdpSecurity !== null ? ['rdp_security' => $client->rdpSecurity] : [])
        + (($g = $client->t->gw) !== null ? ['gateway_transport' => $g->transport, 'gateway_sign_in' => $g->auth] : []), null, true);
    $status(['connected' => true, 'cert' => $client->certFingerprint]);

    // a recording started in the session (an administrator from the active sessions, or its user when allowed: see
    // api.php): made at once in the user's browser, of what its page shows and plays (no new connection: the stream's
    // frames cannot start halfway), the page told by its number ($recGen) and sending its video in numbered pieces on its
    // out-of-order lane (message 22); stopped, the page sends what it has left, then it is closed. One the connection's or
    // an administrator's is stopped only by an administrator.
    $recGen   = 0;                           // the page's recordings in this session, the current one's number
    $recNext  = 0;                           // the number of the piece of its video expected next
    $closeRec = static function (bool $failed = false) use (&$rec, &$recBy, &$recEnd): void {
        $rec?->close(($recEnd ?? []) + ($failed ? ['failed' => true] : []));
        $rec = $recBy = $recEnd = null;
    };
    $startRec = static function (array $w) use (&$rec, &$recBy, &$recGen, &$recNext, &$recEnd, $closeRec, $sid, $user, $conn, $audit, $status): void {
        $recEnd !== null && $closeRec();     // one stopped whose page has not sent the rest yet: closed as it is
        Recording::cleanup();
        $id  = Recording::newId();
        $rec = Recording::start($id, ['sid' => $sid, 'uid' => $user['id'], 'user' => $user['username'], 'conn' => $conn['name'], 'host' => $conn['host'],
            'by' => $w['by'], 'started_as' => $w['as'], 'kind' => 'video']);
        if ($rec === null) {
            Audit::log('recording_start', $audit + ['by' => $w['by'], 'as' => $w['as'], 'reason' => 'record_failed'], null, false);
            $status(['recording' => false, 'record_error' => 'record_failed']);
            return;
        }
        [$recBy, $recGen, $recNext] = [$w['as'], $recGen % 65535 + 1, 0];
        Audit::log('recording_start', $audit + ['rec' => $id, 'by' => $w['by'], 'as' => $w['as'], 'recording' => true], null, true);
        Sessions::recording($sid, $recBy);                 // (after its audit line: whoever sees it recorded finds that line)
        $status(['recording' => true, 'record_by' => $recBy, 'record_video' => $recGen]);
    };
    $stopRec = static function (string $by, bool $asAdmin, ?string $failed = null) use (&$rec, &$recBy, &$recEnd, $closeRec, $sid, $audit, $status): void {
        if ($rec === null || $recEnd !== null || ($recBy !== 'owner' && !$asAdmin && $failed === null)) {
            return;
        }
        Audit::log('recording_stop', $audit + ['rec' => $rec->sid, 'by' => $by, 'recording' => true] + ($failed !== null ? ['reason' => $failed] : []),
            null, $failed === null);
        Sessions::recording($sid, null);
        $status(['recording' => false] + ($failed !== null ? ['record_error' => $failed] : []));
        $recEnd = ['stopped_by' => $by] + ($failed !== null ? ['failed' => true] : []);
        if (!$rec->video || $failed !== null) {
            $closeRec();                     // (the stream's frames: all of it is here already)
        }
    };
    $idle     = Settings::get('rdp_idle_minutes') * 60;
    $lastIn   = $lastPing = $lastBeat = time();
    $lastMem  = 0;                           // (its memory noted at the first heartbeat, then once a minute)
    $controlAt = [];                         // the shares with control: when their guests last used it (or came)
    $visible  = true;                        // the owner's page is seen (else the server stops drawing, unless guests watch)
    $watchers = 0;
    $joinWanted = $guestUsed = false;          // a guest waiting to be shown the session; a guest's input taken
    $lateHops   = 0;                           // a broker's redirections once the session was up
    $lastJoin   = 0.0;
    while (true) {
        $r = [...$client->t->streams(), ...$sess->streams()];   // (through a gateway, its socket too)
        $e = null;
        $due = $client->sendInput();             // typed text goes out one character at a time
        $client->t->pump();                      // what the client wrote, into the gateway's tunnel
        $w = $client->t->writeStreams() ?: null; // (and the gateway's socket while frames wait for it)
        @stream_select($r, $w, $e, 0, (int)(min($sess->polling() ? 0.02 : 1.0, $due ?? 1.0) * 1e6));
        $client->t->pump();                      // what came out of the tunnel, to the client (once it ended: what came before
                                                 // the end, then the end, which poll() sees as a remote computer that closed)
        if (($g = $client->t->gw) !== null && $g->messages !== []) {   // the gateway's service messages: shown on the page
            foreach ($g->messages as $text) {
                $status(['gateway_message' => $text]);
            }
            $g->messages = [];
        }
        try {
            $polled = $client->poll();
        } catch (Redirection $late) {            // a broker's redirection once the session is up (signed in on its logon
            if (++$lateHops > 3) {               // screen, without NLA): followed, as when connecting; every page decodes anew
                throw new ProtocolException('Too many redirections', 'redirect');
            }
            $flush();
            $outV      = '';
            $tally();
            $client->disconnect();
            $drive?->removeLocal(false);         // (its files still open audited; the folder shared again on the new one)
            $drive?->release();
            $dvc->stopCapture();                 // (the browser lets go of its microphone and camera until asked again)
            $follow($late, true);
            $build();
            $emit(Client::F_RESET, '');
            $connectTo($verify);
            $sess->heartbeat();
            continue;
        }
        if (!$polled) {
            $reason = $client->errorInfo ? 'error_info' : 'disconnected';
            break;
        }
        if (!$flush()) {                         // the frames go out first: the history catches up after the input
            $reason = 'browser_closed';
            break;
        }
        $pointer = null;
        foreach ([...array_splice($held, 0), ...$sess->read($r)] as $m) {   // (those kept during a consent first)
            $type = ord($m[0] ?? "\0");
            if ($type >= 0x20) {                    // not from the owner's page (see Sessions::read)
                if ($type === 0x22 && in_array(ord($m[17] ?? "\0"), Sessions::GUEST_TYPES, true)) {
                    $controlAt[substr($m, 1, 16)] = time();   // (its control used now)
                    $m    = substr($m, 17);          // a guest given control: its keyboard, mouse and touch, as the owner's
                    $type = ord($m[0]);
                    $guestUsed = true;
                    if (($type === 7 || $type === 8) && $client->queued() > 64) {
                        continue;                    // typed text waiting already: a guest cannot queue minutes of it
                    }
                } else {
                    if ($type === 0x21) {            // a guest came: shown the session once connected again (below)
                        $id = substr($m, 1);
                        $sh = (Sessions::info($sid)['shares'] ?? [])[$id] ?? null;
                        if ($rail === null && $sh !== null) {
                            $joinWanted = true;
                            ($sh['mode'] ?? '') === 'control' && $controlAt[$id] ??= time();   // (unused from now on: its control lost
                                                                                                // after the administrator's minutes)
                        } else {                     // (revoked meanwhile; RemoteApp is not shared)
                            $sess->dropViewers($id);
                        }
                    } elseif ($type === 0x20) {      // a link revoked: its guests let go
                        $sess->dropViewers(substr($m, 1));
                        $guestUsed && $client->releaseModifiers();   // (a key it held stays pressed otherwise)
                    } elseif ($type === 0x23) {      // the recording started or stopped (api.php: who, and as what)
                        $rw = json_decode(substr($m, 1), true);
                        if (!is_array($rw)) {
                            continue;
                        }
                        $as = ($rw['as'] ?? '') === 'admin' ? 'admin' : 'owner';
                        if (empty($rw['on'])) {
                            $stopRec((string)($rw['by'] ?? ''), $as === 'admin');
                        } elseif (($rec === null || $recEnd !== null) && $rail === null) {   // (RemoteApp: its windows are no one picture)
                            $startRec(['by' => (string)($rw['by'] ?? ''), 'as' => $as]);
                        }
                    }
                    continue;
                }
            }
            if ($type === 1 && strlen($m) >= 2) {
                $lastIn = time();
                $client->input(substr($m, 2), ord($m[1]));
                ($watched || $rec) && ($pointer = Client::mouseAt(substr($m, 2)) ?? $pointer);   // shown to the guests, recorded
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
                    $size = [$wh['w'], $wh['h'], []];
                    $rail?->workArea($wh['w'], $wh['h']);    // maximized windows fill the new size
                }
                Audit::log('rdp_resize', $audit + ['size' => $wh['w'] . 'x' . $wh['h']]);
            } elseif ($type === 13 && strlen($m) <= 4096) {   // several monitors, each the size of its window: JSON [[x, y, w, h, primary], ...]
                $mons = Drdynvc::monitors((array)json_decode(substr($m, 1), true));
                if ($mons !== null && $dvc->resizeMonitors($mons)) {
                    [$bw, $bh] = Drdynvc::bounds($mons);
                    $client->resized($bw, $bh);
                    $size = [$bw, $bh, $mons];
                    if ($rail && ($main = Rail::mainMonitor($mons))) {
                        $rail->workArea(...$main);          // RemoteApp: the page's monitor, its taskbar left out
                    }
                    Audit::log('rdp_resize', $audit + ['size' => "{$bw}x{$bh}", 'monitors' => count($mons)]);
                }
            } elseif ($type === 14 && $rail && strlen($m) <= 1024) {   // RemoteApp: the desktop synced; a window brought to the front, minimized...
                $rail->command(substr($m, 1));
            } elseif ($type === 3) {
                $visible = ($m[1] ?? "\1") !== "\0";
                $client->suppressOutput($visible || $watched);
            } elseif ($type === 4 && $clip) {
                $clip->fromBrowser(substr($m, 1));
            } elseif ($type === 6) {
                $client->refresh();
            } elseif ($type === 10 && strlen($m) >= 9) {
                $dvc->gfxAck(substr($m, 1, 8));
            } elseif ($type === 16 && strlen($m) <= 62) {   // multitouch: a frame of the fingers on the screen (Rdpei)
                $lastIn = time();                            // (in use: a session used only with the fingers ended as idle)
                $dvc->touchData(substr($m, 1));
            } elseif ($type === 15) {               // microphone: 16-bit PCM in the format the browser was told (F_MIC)
                $heard = $dvc->micData(substr($m, 1));
                if ($heard !== '') {
                    $rec?->frame(Recording::MIC, $heard);   // what the remote computer heard, recorded with the session's sound
                }
            } elseif ($type === 18) {               // camera: u8 its start (F_CAM), a JPEG picture at the size it was told; nothing, it could not open it
                $dvc->camData(substr($m, 1));
            } elseif ($type === 22 && strlen($m) >= 8) {   // the page's recording: u16 its number, u32 the piece's, u8 flags, the video's next bytes
                ['g' => $g, 'n' => $n, 'f' => $f] = unpack('vg/Vn/Cf', $m, 1);
                if ($rec !== null && $rec->video && $g === $recGen && $n >= $recNext) {   // (one sent twice, or of a recording before: left out)
                    // a piece missing (lost on the way, or left out by a page that gave up): what comes after it cannot be
                    // read, so the recording ends there, whole up to it
                    $gap = $n > $recNext;
                    if (!$gap) {
                        $o = 8;
                        if ($n === 0 && $f & 1 && strlen($m) >= 10) {   // the first: u16 length + JSON, what the page records
                            $o += 2 + unpack('v', $m, 8)[1];
                            $rec->describe((array)json_decode(substr($m, 10, $o - 10), true));
                        }
                        $recNext = $n + 1;
                        strlen($m) > $o && $rec->frame(Recording::VIDEO, substr($m, $o));
                    }
                    // ended: the rest after it was stopped (2); else the page cannot record or gave up (4), a piece missing,
                    // more than a video makes (a page filling the disk), or the page stopped on its own: stopped, said why
                    $failed = $gap || $f & 4 || $rec->overRate();
                    if ($failed || $f & 2) {
                        $recEnd !== null ? $closeRec($failed) : $stopRec('', true, 'record_page');
                    }
                }
            } elseif ($type === 19 && strlen($m) >= 2 && strlen($m) <= 300) {   // this computer's folder: 1 shared (u8 read only + name), 0 no longer
                if ($m[1] === "\1" && strlen($m) >= 3) {
                    if ($drive?->addLocal(substr($m, 3), $m[2] !== "\0")) {
                        $localDir = [substr($m, 3), $m[2] !== "\0"];
                    } else {                         // (no shared folder in this session, or the gateway's policy forbids drives)
                        $status(['local_folder' => 'refused']);
                    }
                } elseif ($m[1] === "\0") {
                    $drive?->removeLocal();
                    $localDir = null;
                }
            } elseif ($type === 20) {               // ... its answer to a request (F_DRIVE): u32 the request's number, the answer
                $drive?->localReply(substr($m, 1));
            } elseif ($type === 21) {               // ... the first answer of a listing, with its other names (answered here)
                $drive?->localList(substr($m, 1));
            } elseif ($type === 11) {               // diagnostic note from the browser (decoder errors, statistics)
                $client->trace?->log('browser', (array)json_decode(substr($m, 1, 4000), true));
            } elseif ($type === 5 || $type === 9) {
                $reason = $type === 5 ? 'user' : (substr($m, 1) === 'signed_out' ? 'signed_out' : 'killed');
                break 2;
            }
        }
        if ($pointer !== null) {
            if ($watched) {
                $outV .= pack('CV', Client::F_POINTER, 4) . $pointer;
            }
            $rec?->frame(Client::F_POINTER, $pointer);
        }
        // a guest came: at most one reconnection each 10 s (a link opened and closed in a loop kept the desktop stopping)
        $joined = $joinWanted && microtime(true) >= $lastJoin + 10;
        if ($joined) {
            $joinWanted = false;
            $lastJoin   = microtime(true);
            $joined     = $sess->joiningCount() > 0;   // (a guest gone meanwhile: nothing to show)
        }
        if ($joined) {
            // a guest came: the connection is made again so that every page starts from the same picture (the graphics
            // pipeline's surfaces, caches and video cannot be joined halfway); Windows keeps the session meanwhile, as after
            // a dropped connection. The owner's page sees the desktop stop for a moment.
            $flush();                            // the old connection's last frames, to whoever saw it
            $outV    = '';                       // (nothing left over for the guests from before)
            $watched = $sess->admitViewers() > 0;
            $tally();
            $client->disconnect();
            $drive?->removeLocal(false);         // (its files still open audited; the folder shared again on the new one)
            $drive?->release();
            $dvc->stopCapture();                 // (the browser lets go of its microphone and camera until asked again)
            $build();
            $emit(Client::F_RESET, '');
            $status(['viewers' => $watchers = $sess->viewerCount()]);
            Sessions::report($sid, ['viewers' => $watchers]);   // (for the administration's status)
            $connectTo($verify);                 // (each computer's pinned certificate: the same as when it started)
            $sess->heartbeat();
        }
        if ($sess->viewerCount() !== $watchers) {   // a guest left: the owner's page says how many watch
            $watchers = $sess->viewerCount();
            $watched  = $watchers > 0;
            $watched || $outV = '';
            $status(['viewers' => $watchers]);
            Sessions::report($sid, ['viewers' => $watchers]);
            $watched || $visible || $client->suppressOutput(false);
            $guestUsed && $client->releaseModifiers();
        }
        $client->catchUp();                      // the history of what was forwarded, now that frames and input went out
        $clip?->tick();
        $rec?->tick();
        if ($rec?->failed && $recBy === 'policy') {   // the connection's recording cannot be written: no recording, no session
            $reason = 'record_failed';
            break;
        }
        if ($rec?->failed) {                     // one started in the session: it stops, the session goes on
            $stopRec('', true, 'record_failed');
        }
        $now = time();
        $rail?->tick();                          // RemoteApp: the program asked for if the browser did not report the desktop
        if ($now - $lastPing >= 10) {
            $emit(6, '');
            $lastPing = $now;
        }
        if ($now - $lastBeat >= 15) {
            if (!$sess->heartbeat()) {           // its file removed as stale (the worker was held over a minute): ended,
                $reason = 'stale';                // the page connects again (no input reached it any more)
                break;
            }
            $lastBeat = $now;
            // a link past its time: its guests let go (as revoked); a guest given control that has not used it for the
            // administrator's minutes: it watches on, told why
            foreach (Sessions::expiredShares($sid) as $id) {
                if (Sessions::unshare($sid, $id)) {
                    unset($controlAt[$id]);
                    Audit::log('share_expire', $audit + ['share' => $id], null, true);
                }
            }
            $ctlIdle = (int)Settings::get('share_control_idle_minutes') * 60;
            $ended   = $sess->endIdleControls($sid, $controlAt, $now, $ctlIdle, static function () use ($sid): string {
                $i  = Sessions::info($sid) ?? [];
                $st = (string)json_encode(['watch' => 'view', 'owner' => $i['user'] ?? '', 'conn' => $i['conn'] ?? '', 'sid' => $sid,
                    'recording' => !empty($i['record']), 'control_ended' => 'idle']);
                return pack('CV', Client::F_STATUS, strlen($st)) . $st;
            });
            foreach ($ended as $id) {
                $guestUsed && $client->releaseModifiers();   // (a key it held stays pressed otherwise)
                Audit::log('share_control_end', $audit + ['share' => $id, 'reason' => 'idle', 'minutes' => intdiv($ctlIdle, 60)], null, true);
            }
            if ($now - $lastMem >= 60) {         // its memory, for the administration's status
                Sessions::report($sid, ['mem' => memory_get_usage(), 'mem_peak' => memory_get_peak_usage()]);
                $lastMem = $now;
            }
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
} catch (Redirection) {                         // a broker's redirection once connected: not followed there
    $reason = 'redirect';
} catch (ProtocolException $e) {
    $reason = $e->key;
    $client->trace?->log('error', ['key' => $e->key, 'message' => $e->getMessage()]);
    if (in_array($e->key, ['cert_changed', 'cert_new', 'cert_unexpected'], true)) {   // its fingerprints, and which computer when
        [$detail, $certSha1, $certHost] = explode(' ', $e->getMessage(), 3) + [1 => '-', 2 => null];   // it is not the connection's own
    }
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
        'bytesIn' => $bytesIn + (isset($client->t) ? $client->t->bytesIn : 0), 'bytesOut' => $bytesOut + (isset($client->t) ? $client->t->bytesOut : 0)]);
    $cleanup();
    Audit::log('rdp_end', $audit + ['reason' => $reason, 'errorInfo' => $client->errorInfo, 'seconds' => time() - $started,
                           'in' => $bytesIn + (isset($client->t) ? $client->t->bytesIn : 0), 'out' => $bytesOut + (isset($client->t) ? $client->t->bytesOut : 0)],
               null, in_array($reason, ['user', 'browser_closed', 'disconnected', 'idle', 'closed'], true));
}
if ($reason !== 'browser_closed') {
    $emit(Client::F_STATUS, json_encode(['end' => $reason, 'detail' => $detail, 'errorInfo' => $client->errorInfo] + ($certHost !== null ? ['host' => $certHost] : [])
        + ($certSha1 !== null && $certSha1 !== '-' ? ['sha1' => $certSha1] : [])));
    $flush();
}
