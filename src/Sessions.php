<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk;

/**
 * Registry of running RDP sessions (data/run/<sid>.json) and the channel used by short
 * requests (input, clipboard, resize) to reach the long-running stream request:
 * a Unix socket when available, otherwise a queue file polled by the stream loop.
 */
final class Sessions
{
    private const STALE = 60;

    /** @var resource|null */
    private $server = null;
    private ?string $queue = null;
    private string $pending = '';
    private array $held = [];
    private int $seq = 0;
    private ?float $gapSince = null;
    /** @var resource[] */
    private array $conns = [];
    /** @var array<int, string> what each connection has sent so far (one message each) */
    private array $parts = [];
    /** TCP mode (Windows): what each connection must start with, as only this computer's processes can reach the port */
    private ?string $secret = null;

    private function __construct(public readonly string $sid)
    {
    }

    public static function validSid(string $sid): bool
    {
        return (bool)preg_match('/^[a-f0-9]{32}$/', $sid);
    }

    private static function file(string $sid, string $ext): string
    {
        return Config::dir('run/' . $sid . '.' . $ext);
    }

    private static function sockPath(string $sid): string
    {
        $p = self::file($sid, 'sock');
        return strlen($p) < 100 ? $p : sys_get_temp_dir() . '/mudesk-' . $sid . '.sock';   // (16 characters: two sessions could share it)
    }

    public static function info(string $sid): ?array
    {
        $f = self::file($sid, 'json');
        return self::validSid($sid) && is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    }

    /** Running sessions (stale entries are cleaned up), without their input token: only the session's browser has it. */
    public static function active(?string $uid = null): array
    {
        return array_map(static function (array $s): array {
            unset($s['token'], $s['wid']);
            return $s;
        }, self::running($uid));
    }

    /** The running sessions as registered (their token and web session too); stale ones removed. */
    private static function running(?string $uid = null): array
    {
        $out = [];
        foreach (glob(Config::dir('run/*.json')) ?: [] as $f) {
            // a session that ends meanwhile takes its file with it (no warning); one being written is not a dead one:
            // only a file not touched for STALE seconds is cleaned up (one read empty while written was removed with
            // the session's queue, and its input got 404)
            $s     = json_decode((string)@file_get_contents($f), true);
            $mtime = @filemtime($f);
            if ($mtime !== false && $mtime < time() - self::STALE) {
                self::cleanup(basename($f, '.json'));
                continue;
            }
            if (!is_array($s)) {
                continue;
            }
            if ($uid === null || $s['uid'] === $uid) {
                $out[] = $s;
            }
        }
        return $out;
    }

    /**
     * Ends every running session of a user (disabled, deleted or password reset by the administrator), or, with $keepWid,
     * those opened from the user's other web sessions (the user changed the password from that one).
     */
    public static function killUser(string $uid, string $keepWid = ''): void
    {
        foreach (self::running($uid) as $s) {
            if ($keepWid === '' || ($s['wid'] ?? '') !== $keepWid) {
                self::kill($s['sid'], $keepWid === '' ? '' : 'signed_out');   // (by an administrator: said so)
            }
        }
    }

    /** Ends the remote sessions opened from a web session (signed out, expired or closed): none outlives it. */
    public static function killWeb(string $wid): void
    {
        if ($wid === '') {
            return;
        }
        foreach (self::running() as $s) {
            if (($s['wid'] ?? '') === $wid) {
                self::kill($s['sid'], 'signed_out');
            }
        }
    }

    /**
     * Registers a running session unless a limit is reached (0 = none): its user's ('too_many_sessions') or that of all the
     * users together ('busy': each session holds a PHP worker). Counting and registering share one lock, so that requests
     * at the same time can not both take the last place.
     */
    public static function register(string $sid, array $info, int $perUser = 0, int $total = 0): self|string
    {
        $lock = fopen(Config::dir('run/register.lock'), 'c');
        flock($lock, LOCK_EX);
        try {
            $all = self::active();
            if ($perUser > 0 && count(array_filter($all, static fn($s) => $s['uid'] === $info['uid'])) >= $perUser) {
                return 'too_many_sessions';
            }
            if ($total > 0 && count($all) >= $total) {
                return 'busy';
            }
            $tmp = self::file($sid, 'json') . '.' . bin2hex(random_bytes(4)) . '.tmp';   // whole or not there for a reader
            file_put_contents($tmp, json_encode($info + ['sid' => $sid, 'started' => date('c')]));
            rename($tmp, self::file($sid, 'json'));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $s    = new self($sid);
        $path = self::sockPath($sid);
        @unlink($path);
        $old = umask(0077);
        $ok  = Config::get('ipc') !== 'file' && function_exists('stream_socket_server');
        // Windows has no Unix sockets for PHP: a TCP port of this computer only (127.0.0.1), whose messages must start with
        // a secret of the session kept in its mode file. The queue file it used instead was read every 20 ms: each key,
        // mouse move and frame acknowledgement waited up to 20 ms for it
        $tcp = $ok && PHP_OS_FAMILY === 'Windows';
        $srv = !$ok ? false : @stream_socket_server($tcp ? 'tcp://127.0.0.1:0' : 'unix://' . $path, $en, $es);
        umask($old);
        if ($srv !== false) {
            stream_set_blocking($srv, false);
            $s->server = $srv;
            if ($tcp) {
                $s->secret = bin2hex(random_bytes(16));
                $port      = (int)substr((string)strrchr((string)stream_socket_get_name($srv, false), ':'), 1);
                file_put_contents(self::file($sid, 'mode'), "tcp:$port:" . $s->secret);
            } else {
                file_put_contents(self::file($sid, 'mode'), 'sock');
            }
        } else {
            $s->queue = self::file($sid, 'q');
            file_put_contents($s->queue, '');
            file_put_contents(self::file($sid, 'mode'), 'file');
        }
        return $s;
    }

    public function heartbeat(): void
    {
        @touch(self::file($this->sid, 'json'));
    }

    /** Streams to watch with stream_select (empty in queue-file mode). */
    public function streams(): array
    {
        return $this->server === null ? [] : [$this->server, ...$this->conns];
    }

    public function polling(): bool
    {
        return $this->queue !== null;
    }

    /** Returns complete messages received since the last call. */
    public function read(): array
    {
        if ($this->server !== null) {
            while (($c = @stream_socket_accept($this->server, 0)) !== false) {
                stream_set_blocking($c, false);
                $this->conns[] = $c;
            }
            // each connection brings one whole message (send()): kept apart until it has all arrived. Added to one buffer
            // as it came, a large one (a paste) arriving in pieces had the browser's other requests in between, and the
            // session's input stopped for good
            foreach ($this->conns as $i => $c) {
                while (($d = fread($c, 65536)) !== false && $d !== '') {
                    $this->parts[$i] = ($this->parts[$i] ?? '') . $d;
                }
                if (feof($c)) {
                    fclose($c);
                    $b = $this->parts[$i] ?? '';
                    if ($this->secret !== null) {     // TCP: only with the session's secret first (any local process can connect)
                        $b = strlen($b) > 32 && hash_equals($this->secret, substr($b, 0, 32)) ? substr($b, 32) : '';
                    }
                    if (strlen($b) >= 4 && strlen($b) === 4 + unpack('V', $b)[1]) {   // a sender that died halfway: nothing
                        $this->pending .= $b;
                    }
                    unset($this->conns[$i], $this->parts[$i]);
                }
            }
        } else {
            clearstatcache(true, $this->queue);
            $size = (int)@filesize($this->queue);
            if ($size > 0) {
                $h = fopen($this->queue, 'r+');
                flock($h, LOCK_EX);
                $this->pending .= (string)stream_get_contents($h);
                ftruncate($h, 0);
                flock($h, LOCK_UN);
                fclose($h);
            }
        }
        while (strlen($this->pending) >= 4) {
            $n = unpack('V', $this->pending)[1];
            if (strlen($this->pending) < 4 + $n) {
                break;
            }
            $msg           = substr($this->pending, 4, $n);
            $this->pending = substr($this->pending, 4 + $n);
            $seq = strlen($msg) >= 4 ? unpack('V', $msg)[1] : -1;
            if ($seq === 0xFFFFFFFF) {           // out of band (administrator kill)
                return [substr($msg, 4)];
            }
            if ($seq >= $this->seq) {
                $this->held[$seq] = substr($msg, 4);
            }
        }
        // the browser sends several requests in parallel: deliver them in sequence order
        if ($this->held && !isset($this->held[$this->seq])) {
            $this->gapSince ??= microtime(true);
            if (microtime(true) - $this->gapSince > 0.5) { // a request was lost: skip the gap
                $this->seq = min(array_keys($this->held));
            }
        }
        $out = [];
        while (isset($this->held[$this->seq])) {
            $out[] = $this->held[$this->seq];
            unset($this->held[$this->seq++]);
            $this->gapSince = null;
        }
        return $out;
    }

    /** Delivers a message to a running session. */
    public static function send(string $sid, string $msg): bool
    {
        if (!self::validSid($sid)) {            // its callers check it, but a path from elsewhere must never be written
            return false;
        }
        $frame = pack('V', strlen($msg)) . $msg;
        $mode  = (string)@file_get_contents(self::file($sid, 'mode'));
        if (preg_match('/^tcp:(\d{1,5}):([0-9a-f]{32})$/', $mode, $t)) {
            $c = @stream_socket_client('tcp://127.0.0.1:' . $t[1], $en, $es, 2);
            if ($c === false) {
                return false;
            }
            $ok = fwrite($c, $t[2] . $frame) === 32 + strlen($frame);
            fclose($c);
            return $ok;
        }
        if ($mode === 'sock') {
            $c = @stream_socket_client('unix://' . self::sockPath($sid), $en, $es, 2);
            if ($c === false) {
                return false;
            }
            $ok = fwrite($c, $frame) === strlen($frame);
            fclose($c);
            return $ok;
        }
        if ($mode === 'file') {                 // at most 16 MB waiting (while connecting nothing is read)
            clearstatcache(true, self::file($sid, 'q'));
            return (int)@filesize(self::file($sid, 'q')) + strlen($frame) <= 16777216
                && file_put_contents(self::file($sid, 'q'), $frame, FILE_APPEND | LOCK_EX) !== false;
        }
        return false;
    }

    /** Asks a session to end; $why ('signed_out') tells the page why, else an administrator ended it. */
    public static function kill(string $sid, string $why = ''): void
    {
        if (self::validSid($sid)) {
            self::send($sid, pack('V', 0xFFFFFFFF) . "\x09" . $why);
        }
    }

    /** Removes the files of a session, its uploads and print jobs too (a process killed by the hosting leaves them). */
    public static function cleanup(string $sid): void
    {
        if (!self::validSid($sid)) {            // nothing removed out of the folder of the sessions
            return;
        }
        foreach (['json', 'mode', 'q'] as $ext) {
            @unlink(self::file($sid, $ext));
        }
        @unlink(self::sockPath($sid));
        Files::remove(Config::dir('tmp/' . $sid));
    }

    public function close(): void
    {
        foreach ($this->conns as $c) {
            @fclose($c);
        }
        if ($this->server !== null) {
            @fclose($this->server);
        }
        self::cleanup($this->sid);
    }
}
