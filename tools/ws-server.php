<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);
/*
 * The optional WebSocket service (Linux; the Docker image starts it, on a server it runs as a service):
 *   php tools/ws-server.php
 * It listens on 127.0.0.1:8091 (config.php: websocket_listen), behind the web server, which passes <public>/ws to it with
 * the Upgrade headers, the site's Host, X-Forwarded-For and X-Forwarded-Proto (docs/HOSTING.md, WebSocket). Each connection
 * is one session, run by rdp.php itself in a process of its own (a copy of this one, or tools/ws-session.php: see FORK), so
 * a session is the same with the WebSocket or without it:
 *  - the browser's first message (text): {"csrf": ..., "body": what rdp.php?a=stream takes}; the session's output, its
 *    frames, goes back as binary messages as it comes (or, refused before its first frame, its answer as one text message);
 *  - then a text message {"token": ...}, the session's input token from its first state frame, and binary messages, each
 *    what rdp.php?a=send takes, taken the same way (Sessions::tokenOk, Sessions::browserOk), in order;
 *  - the browser leaving closes the session's standard input: it ends as over HTTP when the browser goes (its end audited,
 *    its files removed); one that does not end within 15 s is stopped. So does the service stopping (SIGTERM).
 * Guests, files, recordings and everything else stay HTTP. A connection whose Origin is not the site's is refused. Nothing a
 * connection sends or leaves unread is kept past a few MB, and nothing waits on one session.
 */
use MuDesk\{Config, Sessions, WebSocket};

if (PHP_SAPI !== 'cli') {
    exit;
}
ini_set('display_errors', 'stderr');
require_once __DIR__ . '/../src/bootstrap.php';

const HANDSHAKE_MAX = 8192;          // bytes of the opening request
const HANDSHAKE_TIME = 10;           // seconds for it
const FIRST_TIME = 30;               // seconds for the session's request after it
const FIRST_MAX = 65536;             // bytes of a message before the session is open (its request is small)
const OUT_HIGH = 8 << 20;            // bytes waiting for the browser past which neither the session nor the browser is read
const IN_HIGH = 8 << 20;             // bytes of the browser's waiting for the session past which the browser is not read
const CLOSE_TIME = 5;                // seconds a closing connection has to take its last bytes
const STOP_AFTER = 15;               // seconds a session has to end once its browser left
const MAX_CONNS = 240;               // connections at once: each holds up to 4 descriptors, and select() sees only 1024
const PER_IP = 60;                   // of them from one client address (an office behind one address: the rest go over HTTP)

if (Config::get('websocket') === false) {          // (turned off: ended as done, so that what starts it does not again)
    fwrite(STDERR, "MuDeskClient WebSocket: off (config.php: websocket => false)\n");
    exit(0);
}
// Each session a copy of this process (pcntl_fork) where PHP has it: the code, compiled here once at the start, is shared
// with every session (some 5 to 8 MB a session less than a PHP started for each, as Apache's processes share it through
// OPcache); else tools/ws-session.php, a PHP started for it. Code changed since (an update without a restart): a PHP
// started for each session, which reads it anew, until the service is restarted.
const FORK = PHP_OS_FAMILY === 'Linux';
$forking = FORK && function_exists('pcntl_fork') && function_exists('pcntl_waitpid') && function_exists('posix_kill') && is_dir('/proc/self/fd');
$codeAt  = codePrint();                    // (before it says it listens: a change after that is seen)
if ($forking) {
    foreach (array_merge(glob(dirname(__DIR__) . '/src/*.php') ?: [], glob(dirname(__DIR__) . '/src/Rdp/*.php') ?: []) as $f) {
        $class = 'MuDesk\\' . (basename(dirname($f)) === 'Rdp' ? 'Rdp\\' : '') . basename($f, '.php');
        preg_match('/^[A-Z]\w*$/', basename($f, '.php')) && (class_exists($class) || interface_exists($class));   // (compiled now, shared)
    }
}

$listen = (string)(Config::get('websocket_listen') ?: '127.0.0.1:8091');
$srv    = @stream_socket_server("tcp://$listen", $en, $es);
if ($srv === false) {
    fwrite(STDERR, "MuDeskClient WebSocket: cannot listen on $listen ($es)\n");
    exit(1);
}
stream_set_blocking($srv, false);
fwrite(STDERR, "MuDeskClient WebSocket: listening on $listen\n");

/** @var array<int, array> $conns by the id of their socket */
$conns = [];

// stopped (systemd, a new install): every session's standard input closed, as when its browser goes, so that each ends
// as over HTTP (audited, its files removed); then this process ends (143: stopped, not done, so it is started again)
$stopping = false;
if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    foreach ([SIGTERM, SIGINT] as $sig) {
        pcntl_signal($sig, static function () use (&$stopping): void {
            $stopping = true;
        });
    }
}

/** What the code is now (the names, sizes and dates of src/ and rdp.php): a copy of this process runs the code it compiled. */
function codePrint(): string
{
    clearstatcache();
    $h = '';
    foreach (array_merge(glob(dirname(__DIR__) . '/src/*.php') ?: [], glob(dirname(__DIR__) . '/src/Rdp/*.php') ?: [], [dirname(__DIR__) . '/public/rdp.php']) as $f) {
        $h .= $f . ':' . (int)@filesize($f) . ':' . (int)@filemtime($f) . "\n";
    }
    return md5($h);
}

/** A session's process for connection $c, given its request. */
function startSession(array &$c, string $csrf, string $body): void
{
    global $forking, $srv, $conns, $codeAt;
    static $told = false;
    $in = json_decode($body, true);
    $c['sid'] = is_array($in) && is_string($in['sid'] ?? null) ? $in['sid'] : '';
    $mem    = WebSocket::sessionMemory((string)ini_get('memory_limit'));   // (the command line's PHP has no limit by default)
    $server = $c['server'] + ['HTTP_X_CSRF' => $csrf];
    $copy   = $forking && codePrint() === $codeAt;
    if ($forking && !$copy && !$told) {
        $told = true;
        fwrite(STDERR, "MuDeskClient WebSocket: the code changed since it started: each session in a PHP of its own until it is restarted\n");
    }
    if ($copy) {
        $pin  = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);   // its "standard input" (the browser
        $pout = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);   // leaving closes it) and its output
        $pid  = $pin && $pout ? pcntl_fork() : -1;
        if ($pid === 0) {                             // the session: nothing of the service's kept, then rdp.php
            if (function_exists('pcntl_signal')) {
                pcntl_signal(SIGTERM, SIG_DFL);       // (stopped as any process: the service's way of stopping is not its own)
                pcntl_signal(SIGINT, SIG_DFL);
            }
            fclose($pin[0]);
            fclose($pout[0]);
            fclose($srv);
            foreach ($conns as $o) {
                $o['sock'] !== null && @fclose($o['sock']);
                foreach ([...($o['pipes'] ?? []), ...array_filter($o['inq'], 'is_array')] as $p) {
                    $p = is_array($p) ? $p[0] : $p;
                    is_resource($p) && @fclose($p);
                }
            }
            $conns = [];                              // (nor the other connections' buffers and cookies)
            runForked($server, $body, $mem, $pin[1], $pout[1]);
        }
        if ($pid > 0) {
            fclose($pin[1]);
            fclose($pout[1]);
            stream_set_blocking($pout[0], false);
            [$c['proc'], $c['pid'], $c['pipes'], $c['mode']] = [true, $pid, [$pin[0], $pout[0]], null];
            return;
        }
        foreach ([$pin, $pout] as $pair) {            // (no copy made: a PHP started for it, below)
            foreach ($pair ?: [] as $p) {
                fclose($p);
            }
        }
    }
    // PHP marks none of its sockets and files to be closed when a program starts (only its own ends of proc_open's pipes):
    // so that a session does not hold the others' browsers and the listening port (a browser that left not let go, a
    // service started again unable to listen), each of them is /dev/null in the session (one handle for all, put at those
    // numbers by proc_open). Before, a shell closed them, but Debian's sh cannot name a descriptor past 9: with a few
    // connections open, no session started at all
    $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR];
    $null = fopen('/dev/null', 'r');
    foreach (scandir('/proc/self/fd') ?: [] as $n) {
        if (ctype_digit($n) && (int)$n > 2 && !str_starts_with((string)@readlink("/proc/self/fd/$n"), 'pipe:')) {
            $spec[(int)$n] = $null;
        }
    }
    $proc = proc_open([PHP_BINARY, '-d', "memory_limit=$mem", __DIR__ . '/ws-session.php'], $spec, $pipes);
    fclose($null);
    if (!is_resource($proc)) {
        closeWith($c, WebSocket::frame(WebSocket::TEXT, '{"error":"internal"}') . WebSocket::frame(WebSocket::CLOSE, pack('n', 1011)));
        return;
    }
    // (bytes of the browser's headers that are not UTF-8, a cookie or a user agent: replaced, never the request lost)
    fwrite($pipes[0], json_encode(['server' => $server, 'body' => $body], JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
    stream_set_blocking($pipes[1], false);
    [$c['proc'], $c['pid'], $c['pipes'], $c['mode']] = [$proc, null, $pipes, null];
}

/**
 * The session in the copy of the service (see FORK): its output to $out (made the standard output, which rdp.php writes
 * to), its input's end said by $in closing, the request given to rdp.php as tools/ws-session.php gives it; never returns.
 *
 * @param resource $in
 * @param resource $out
 */
function runForked(array $server, string $body, string $mem, mixed $in, mixed $out): never
{
    $ino = (int)(fstat($out)['ino'] ?? 0);
    fclose(STDOUT);                                   // (fd 1 free: the next opened takes it)
    foreach (scandir('/proc/self/fd') ?: [] as $fd) {
        if (ctype_digit($fd) && (int)(@stat("/proc/self/fd/$fd")['ino'] ?? -1) === $ino && $ino > 0) {
            $one = fopen("php://fd/$fd", 'w');        // (a copy of the output's descriptor: number 1, the standard output)
            break;
        }
    }
    if (!isset($one) || (int)(@stat('/proc/self/fd/1')['ino'] ?? -1) !== $ino) {
        fwrite(STDERR, "MuDeskClient WebSocket: a session's output could not be set\n");
        exit(1);
    }
    fclose($out);
    gc_collect_cycles();
    if (ini_set('memory_limit', $mem) === false) {
        fwrite(STDERR, "MuDeskClient WebSocket: a session's memory limit could not be set to $mem\n");
        exit(1);
    }
    mt_srand();                                       // (its own sequence, not the service's)
    Config::load(dirname(__DIR__) . '/config.php');   // (read now, as a session started anew reads them)
    \MuDesk\Settings::forget();
    WebSocket::asRequest($server, $body, $in);
    require __DIR__ . '/../public/rdp.php';
    exit(0);
}

/** A session's process ended: reaped (a copy) or closed (a PHP started for it). */
function ended(array &$c): void
{
    if ($c['pid'] !== null) {
        pcntl_waitpid($c['pid'], $status);            // (its output closed: it is ending)
    } else {
        proc_close($c['proc']);
    }
    [$c['proc'], $c['pid'], $c['pipes']] = [false, null, null];
}

/** A session whose browser left 15 s ago and that did not end: stopped. */
function stop(array $c): void
{
    $c['pid'] !== null ? posix_kill($c['pid'], 15) : proc_terminate($c['proc']);
}

/** Frames that end the connection: nothing more is sent after them, and it is left once they went (or CLOSE_TIME passed). */
function closeWith(array &$c, string $frames): void
{
    if (!$c['closing']) {
        $c['out']      .= $frames;
        $c['closing']   = true;
        $c['closingAt'] = time();
    }
}

/** The browser left (or the connection broke): the session's standard input closed, as over HTTP; stopped later if it does not end. */
function leave(array &$c): void
{
    if ($c['sock'] !== null) {
        @fclose($c['sock']);
        $c['sock'] = null;
    }
    $c['out'] = '';                                   // (nobody to send it to: the session's output is read on, and dropped)
    foreach ($c['inq'] as $m) {                       // (nor input to give it)
        is_array($m) && @fclose($m[0]);
    }
    $c['inq'] = [];
    if ($c['pipes'] !== null && is_resource($c['pipes'][0])) {
        fclose($c['pipes'][0]);
    }
    $c['left'] ??= time();
}

/** The browser's input waiting for the session, in bytes. */
function waiting(array $c): int
{
    $n = 0;
    foreach ($c['inq'] as $m) {
        $n += strlen(is_array($m) ? $m[1] : $m);
    }
    return $n;
}

/**
 * The browser's input given to its session, in order, never waiting for it: each message on a connection of its own (as
 * an HTTP request gives it), the next once it is taken whole; one the session cannot take (it ended) left out, as over HTTP.
 */
function deliver(array &$c): void
{
    while ($c['inq'] !== []) {
        if (!is_array($c['inq'][0])) {
            $to = Sessions::sendLater($c['sid'], $c['inq'][0]);
            if (!$to) {                               // (delivered at once, or not to be)
                array_shift($c['inq']);
                continue;
            }
            $c['inq'][0] = $to;
        }
        [$s, $bytes] = $c['inq'][0];
        $n = @fwrite($s, $bytes);
        if ($n !== false && $n < strlen($bytes)) {
            $c['inq'][0][1] = (string)substr($bytes, $n);   // (the rest when the session takes it)
            return;
        }
        @fclose($s);
        array_shift($c['inq']);
    }
}

$stopAt = null;
for (;;) {
    $now = time();
    if ($stopping && $stopAt === null) {
        $stopAt = $now;
        fclose($srv);                                 // (no new connection)
        foreach ($conns as &$c) {
            leave($c);
        }
        unset($c);
    }
    if ($stopAt !== null && ($conns === [] || $now - $stopAt > STOP_AFTER + 2)) {
        foreach ($conns as $c) {                      // (one that did not end in time)
            $c['proc'] !== null && $c['proc'] !== false && stop($c);
        }
        exit(143);
    }
    $r = $stopAt === null && count($conns) < MAX_CONNS ? [$srv] : [];
    $w = [];
    foreach ($conns as $c) {
        if ($c['sock'] !== null) {
            if (!$c['closing'] && strlen($c['out']) < OUT_HIGH && strlen($c['buf']) < IN_HIGH && waiting($c) < IN_HIGH) {
                $r[] = $c['sock'];
            }
            if ($c['out'] !== '') {
                $w[] = $c['sock'];
            }
        }
        if ($c['inq'] !== [] && is_array($c['inq'][0])) {
            $w[] = $c['inq'][0][0];
        }
        if ($c['pipes'] !== null && is_resource($c['pipes'][1]) && strlen($c['out']) < OUT_HIGH) {
            $r[] = $c['pipes'][1];
        }
    }
    $e = null;
    if (($r === [] && $w === []) || @stream_select($r, $w, $e, 1) === false) {
        usleep(100000);                               // (nothing to wait on, or a signal came: the next round)
        $r = $w = [];
    }
    $now = time();
    if ($stopAt === null && in_array($srv, $r, true)) {
        while (count($conns) < MAX_CONNS && ($s = @stream_socket_accept($srv, 0, $peer)) !== false) {
            stream_set_blocking($s, false);
            $conns[(int)$s] = ['sock' => $s, 'peer' => (string)$peer, 'buf' => '', 'up' => false, 'frag' => null, 'since' => $now, 'out' => '',
                'closing' => false, 'closingAt' => null, 'server' => [], 'proc' => null, 'pid' => null, 'pipes' => null, 'mode' => null, 'text' => '',
                'sid' => '', 'token' => '', 'verified' => false, 'inq' => [], 'left' => null];
        }
    }
    foreach ($conns as $id => &$c) {
        // the browser's bytes: the opening request, then frames
        if ($c['sock'] !== null && in_array($c['sock'], $r, true)) {
            $d = @fread($c['sock'], 1 << 20);
            if ($d === false || ($d === '' && feof($c['sock']))) {
                leave($c);
            } else {
                $c['buf'] .= $d;
            }
        }
        if ($c['sock'] !== null && !$c['up'] && !$c['closing']) {
            $end = strpos($c['buf'], "\r\n\r\n");
            if ($end === false && (strlen($c['buf']) > HANDSHAKE_MAX || $now - $c['since'] > HANDSHAKE_TIME)) {
                leave($c);
            } elseif ($end !== false) {
                [$server, $answer] = WebSocket::handshake(substr($c['buf'], 0, $end), $c['peer']);
                // one client address takes PER_IP connections at most (before, one could take all MAX_CONNS: everybody else
                // went over HTTP); refused as busy: its page goes over HTTP
                $ip = $server !== false ? ($server['REMOTE_ADDR'] ?? '') : '';
                if ($server !== false && count(array_filter($conns, static fn(array $o): bool => $o['up'] && ($o['server']['REMOTE_ADDR'] ?? '') === $ip)) >= PER_IP) {
                    [$server, $answer] = [false, "HTTP/1.1 503 Service Unavailable\r\nConnection: close\r\nContent-Length: 0\r\n\r\n"];
                }
                $c['buf']    = (string)substr($c['buf'], $end + 4);
                $c['up']     = $server !== false;
                $c['server'] = $server ?: [];
                if ($server === false) {
                    closeWith($c, $answer);
                } else {
                    $c['out'] .= $answer;
                }
            }
        }
        if ($c['sock'] !== null && $c['up'] && !$c['closing']) {
            try {
                foreach (WebSocket::read($c['buf'], $c['frag'], $c['proc'] === null ? FIRST_MAX : WebSocket::MAX_MESSAGE) as [$op, $data]) {
                    if ($c['closing']) {
                        break;                        // (nothing after its close)
                    }
                    if ($op === WebSocket::PING) {
                        $c['out'] .= WebSocket::frame(WebSocket::PONG, $data);
                    } elseif ($op === WebSocket::CLOSE) {
                        closeWith($c, WebSocket::frame(WebSocket::CLOSE, substr($data, 0, 2)));
                    } elseif ($op === WebSocket::TEXT) {
                        $m = json_decode($data, true);
                        if ($c['proc'] === null && is_array($m) && is_string($m['csrf'] ?? null) && is_array($m['body'] ?? null)) {
                            if (!WebSocket::hasSession((string)($c['server']['HTTP_COOKIE'] ?? ''))) {
                                // no web session for its cookie: nothing started (its page goes over HTTP, which says why)
                                closeWith($c, WebSocket::frame(WebSocket::CLOSE, pack('n', 1008)));
                                break;
                            }
                            startSession($c, $m['csrf'], (string)json_encode($m['body']));
                        } elseif (is_array($m) && is_string($m['token'] ?? null)) {
                            [$c['token'], $c['verified']] = [$m['token'], false];
                        }
                    } elseif ($op === WebSocket::BINARY && $c['token'] !== ''
                        && ($c['verified'] || ($c['verified'] = Sessions::tokenOk($c['sid'], $c['token'])))) {
                        $c['inq'][] = $data;          // (the token checked once: the session's file read once)
                    }
                }
            } catch (RuntimeException) {                 // (a frame a browser does not send: the connection ends)
                closeWith($c, WebSocket::frame(WebSocket::CLOSE, pack('n', 1002)));
            }
            if ($c['proc'] === null && !$c['closing'] && $now - $c['since'] > FIRST_TIME) {
                closeWith($c, WebSocket::frame(WebSocket::CLOSE, pack('n', 1008)));
            }
        }
        deliver($c);
        // the session's output: its frames as binary messages, or a refusal before them as one text message
        if ($c['pipes'] !== null && is_resource($c['pipes'][1]) && in_array($c['pipes'][1], $r, true)) {
            $d = (string)@fread($c['pipes'][1], 1 << 20);
            if ($d !== '') {
                $c['mode'] ??= $d[0] === "\0" ? 'frames' : 'text';   // (a stream's first frame is its padding, type 0)
                if ($c['mode'] === 'frames') {
                    $c['sock'] !== null && !$c['closing'] && $c['out'] .= WebSocket::frame(WebSocket::BINARY, $d);
                } else {
                    $c['text'] .= substr($d, 0, 65536 - strlen($c['text']));
                }
            } elseif (feof($c['pipes'][1])) {           // the session ended
                fclose($c['pipes'][1]);
                if (is_resource($c['pipes'][0])) {
                    fclose($c['pipes'][0]);
                }
                ended($c);
                if ($c['sock'] !== null) {
                    closeWith($c, ($c['mode'] === 'text' ? WebSocket::frame(WebSocket::TEXT, $c['text']) : '') . WebSocket::frame(WebSocket::CLOSE, pack('n', 1000)));
                }
            }
        }
        if ($c['sock'] !== null && $c['out'] !== '' && in_array($c['sock'], $w, true)) {
            $n = @fwrite($c['sock'], $c['out']);
            if ($n === false) {
                leave($c);
            } else {
                $c['out'] = (string)substr($c['out'], $n);
            }
        }
        if ($c['sock'] !== null && $c['closing'] && ($c['out'] === '' || $now - (int)$c['closingAt'] > CLOSE_TIME)) {
            leave($c);
        }
        if ($c['sock'] === null && $c['proc'] !== null && $c['proc'] !== false && $now - (int)$c['left'] > STOP_AFTER) {
            stop($c);                                 // (its browser left 15 s ago and it did not end)
        }
        if ($c['sock'] === null && ($c['proc'] === null || $c['proc'] === false)) {
            unset($conns[$id]);
        }
    }
    unset($c);
}
