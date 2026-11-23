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
    private float $lookedAt = 0.0;              // (the last time new input connections were looked for)
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
    /** Shared session: the guests watching it (see watch()): [stream, share id, what waits to be written] */
    private array $viewers = [];
    /** Guests that just came: they are sent the session from the reconnection their arrival brings (admitViewers) */
    private array $joining = [];
    /** @var array<int, float> when each connection was accepted */
    private array $since = [];
    /** Messages for the stream loop that are not the browser's input: a guest joined (0x21 + share id) */
    private array $events = [];

    public const MAX_VIEWERS = 5;              // guests at once on one session
    public const MAX_SHARES  = 10;             // links of one session at once
    private const VIEWER_BUFFER = 8 << 20;     // what may wait for a slow guest before it is let go
    private const WATCH = "\0WATCH";          // how a guest's connection starts (then the share id, 16 hex)
    /** Messages that are not part of the browser's ordered input (their sequence number) */
    public const OUT_OF_BAND = 0xFFFFFFFF;
    public const GUEST_INPUT = 0xFFFFFFFE;     // a guest with control: in arrival order, apart from the owner's numbering
    /** What a guest with control may send: keyboard and mouse, typed text, text of an on-screen keyboard, touch */
    public const GUEST_TYPES = [1, 7, 8, 16];
    /** What a guest is sent of the session (frame types): the picture, its size, the pointer, the state; not the owner's
     *  clipboard, sound, microphone or printed documents. */
    public const GUEST_FRAMES = [0 => true, 1 => true, 2 => true, 3 => true, 5 => true, 6 => true, 9 => true, 10 => true, 11 => true, 14 => true, 15 => true];

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
            $s['shared'] = count((array)($s['shares'] ?? []));   // how many links, never their hashes
            unset($s['token'], $s['wid'], $s['shares']);
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
            if (!self::put($sid, $info + ['sid' => $sid, 'started' => date('c')])) {
                return 'busy';
            }
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

    /** The session is still registered (touched as alive); false once its file is gone (removed as stale: not made again). */
    public function heartbeat(): bool
    {
        $f = self::file($this->sid, 'json');
        clearstatcache(true, $f);
        return is_file($f) && @touch($f);
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

    /**
     * Returns complete messages received since the last call. $ready: the streams stream_select() found readable (null: any
     * may be); new connections are looked for when the listening socket is one of them (trying in every turn cost the stream
     * loop 8-11 µs on Windows), or 20 ms after the last look: with TLS data already decrypted, stream_select gives that
     * stream alone without asking the system, and the listening socket was left out (input waited while the server drew).
     */
    public function read(?array $ready = null): array
    {
        if ($this->server !== null) {
            $look = $ready === null || in_array($this->server, $ready, true) || microtime(true) - $this->lookedAt > 0.02;
            if ($look) {
                $this->lookedAt = microtime(true);
            }
            while ($look && ($c = @stream_socket_accept($this->server, 0)) !== false) {
                if (count($this->conns) >= 128) {           // (a local process opening connections it never ends)
                    fclose($c);
                    continue;
                }
                stream_set_blocking($c, false);
                $this->conns[]                     = $c;
                $this->since[array_key_last($this->conns)] = microtime(true);
            }
            // each connection brings one whole message (send()): kept apart until it has all arrived. Added to one buffer
            // as it came, a large one (a paste) arriving in pieces had the browser's other requests in between, and the
            // session's input stopped for good
            foreach ($this->conns as $i => $c) {
                while (($d = fread($c, 65536)) !== false && $d !== '') {
                    $this->parts[$i] = ($this->parts[$i] ?? '') . $d;
                }
                if (strlen($this->parts[$i] ?? '') > 4 * 1048576 + 64 || isset($this->since[$i]) && microtime(true) - $this->since[$i] > 10
                    && !str_contains(substr($this->parts[$i] ?? '', 0, 40), self::WATCH)) {
                    fclose($c);                                  // more than a message can be, or never finished: dropped
                    unset($this->conns[$i], $this->parts[$i], $this->since[$i]);
                    continue;
                }
                $b = $this->parts[$i] ?? '';
                $p = $this->secret === null ? 0 : 32;
                if (strlen($b) >= $p + 22 && substr($b, $p, 6) === self::WATCH                  // a guest: kept open, shown the session
                    && ($this->secret === null || hash_equals($this->secret, substr($b, 0, 32)))) {
                    $id = substr($b, $p + 6, 16);
                    unset($this->conns[$i], $this->parts[$i], $this->since[$i]);
                    if (count($this->viewers) + count($this->joining) >= self::MAX_VIEWERS || !preg_match('/^[0-9a-f]{16}$/', $id)) {
                        fclose($c);
                        continue;
                    }
                    $this->joining[] = [$c, $id, ''];
                    $this->events[]  = "\x21" . $id;
                    continue;
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
                    unset($this->conns[$i], $this->parts[$i], $this->since[$i]);
                }
            }
            foreach ($this->viewers as $i => [$c]) {   // a guest that left (they send nothing): let go
                if (@fread($c, 1) === '' && feof($c)) {
                    @fclose($c);
                    unset($this->viewers[$i]);
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
            if ($seq === self::OUT_OF_BAND || $seq === self::GUEST_INPUT) {   // an administrator's kill, a share revoked; a guest's input
                $this->events[] = ($seq === self::GUEST_INPUT ? "\x22" : '') . substr($msg, 4);
                continue;
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
        $out          = $this->events;              // first, in the order they came
        $this->events = [];
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

    /** What the owner's browser was sent, to every guest too: a guest that cannot keep up (8 MB waiting) is let go. */
    public function toViewers(string $data): void
    {
        foreach ($this->viewers as $i => [$c, $id, $wait]) {
            $wait .= $data;
            $n     = @fwrite($c, $wait);
            if ($n === false || feof($c) || strlen($wait) - (int)$n > self::VIEWER_BUFFER) {
                @fclose($c);
                unset($this->viewers[$i]);
                continue;
            }
            $this->viewers[$i][2] = (string)substr($wait, (int)$n);
        }
    }

    /** Lets go of the guests of share $id (revoked), or of all of them; returns how many remain. */
    public function dropViewers(?string $id = null): int
    {
        foreach (['viewers', 'joining'] as $list) {
            foreach ($this->{$list} as $i => [$c, $vid]) {
                if ($id === null || $vid === $id) {
                    @fclose($c);
                    unset($this->{$list}[$i]);
                }
            }
        }
        return count($this->viewers);
    }

    /** The guests that came are sent the session from now on (the stream starts anew here); returns how many watch. */
    public function admitViewers(): int
    {
        array_push($this->viewers, ...$this->joining);
        $this->joining = [];
        return count($this->viewers);
    }

    /** Guests that came and wait for the reconnection that shows them the session. */
    public function joiningCount(): int
    {
        return count($this->joining);
    }

    public function viewerCount(): int
    {
        return count($this->viewers);
    }

    // ---- sharing: a link for a guest, view only or with control, while the session runs

    /**
     * A new share of session $sid ('view' or 'control'): [id, secret]. Its file keeps the secret's SHA-256, never the
     * secret, which goes only in the link the owner gives (sid.secret). Null when the session uses the queue file (no
     * sockets: a guest cannot be sent the session).
     */
    public static function share(string $sid, string $mode): ?array
    {
        $info = self::info($sid);
        if ($info === null || (string)@file_get_contents(self::file($sid, 'mode')) === 'file') {
            return null;
        }
        $id     = bin2hex(random_bytes(8));
        $secret = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        $saved = self::rewrite($sid, static function (array &$i) use ($id, $secret, $mode): void {
            $i['shares'][$id] = ['hash' => hash('sha256', $secret), 'mode' => $mode === 'control' ? 'control' : 'view', 'created' => date('c')];
        });
        return $saved ? ['id' => $id, 'secret' => $secret] : null;   // (a link never saved is never given)
    }

    /** Share $id revoked: its guests are let go at once. */
    public static function unshare(string $sid, string $id): bool
    {
        $had   = false;
        $saved = self::rewrite($sid, static function (array &$i) use ($id, &$had): void {
            $had = isset($i['shares'][$id]);
            unset($i['shares'][$id]);
        });
        $had = $had && $saved;                          // (not saved: the link would still open the session: said as not done)
        if ($had) {
            self::send($sid, pack('V', self::OUT_OF_BAND) . "\x20" . $id);
        }
        return $had;
    }

    /** The share of a guest's link (sid.secret): [sid, share id, mode, the session's info], or null. */
    public static function findShare(string $link): ?array
    {
        [$sid, $secret] = explode('.', $link, 2) + [1 => ''];
        $info = self::validSid($sid) && $secret !== '' ? self::info($sid) : null;
        foreach ((array)($info['shares'] ?? []) as $id => $s) {
            if (hash_equals((string)$s['hash'], hash('sha256', $secret))) {
                return [$sid, (string)$id, $s['mode'], $info];
            }
        }
        return null;
    }

    /** A guest's connection to the running session (its share id said first): the session's stream comes on it. */
    public static function watch(string $sid, string $id)
    {
        $mode = (string)@file_get_contents(self::file($sid, 'mode'));
        $c    = false;
        if (preg_match('/^tcp:(\d{1,5}):([0-9a-f]{32})$/', $mode, $t)) {
            $c = @stream_socket_client('tcp://127.0.0.1:' . $t[1], $en, $es, 2);
            $c !== false && fwrite($c, $t[2] . self::WATCH . $id);
        } elseif ($mode === 'sock') {
            $c = @stream_socket_client('unix://' . self::sockPath($sid), $en, $es, 2);
            $c !== false && fwrite($c, self::WATCH . $id);
        }
        return $c === false ? null : $c;
    }

    /** Changes the file of a running session under the registry's lock: false when it has ended or could not be written. */
    private static function rewrite(string $sid, callable $fn): bool
    {
        $lock = fopen(Config::dir('run/register.lock'), 'c');
        flock($lock, LOCK_EX);
        try {
            $info = self::info($sid);
            if ($info === null || !is_file(self::file($sid, 'mode'))) {   // (ended meanwhile: its file is not made again)
                return false;
            }
            $fn($info);
            return self::put($sid, $info);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Writes a session's file whole (a reader never sees half of it). On Windows a file another request is reading cannot be
     * replaced ("Access denied"): tried again for up to half a second; false if it never could (the temporary file removed).
     */
    private static function put(string $sid, array $info): bool
    {
        $file = self::file($sid, 'json');
        $tmp  = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($tmp, json_encode($info)) === false) {
            @unlink($tmp);
            return false;
        }
        for ($i = 0; !@rename($tmp, $file); $i++) {
            if ($i >= 50) {
                @unlink($tmp);
                return false;
            }
            usleep(10000);
        }
        return true;
    }

    public function close(): void
    {
        $this->dropViewers();
        foreach ($this->conns as $c) {
            @fclose($c);
        }
        if ($this->server !== null) {
            @fclose($this->server);
        }
        self::cleanup($this->sid);
    }
}
