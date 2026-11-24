<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk;

/**
 * Recording of an RDP session (data/recordings/<id>.rec, its details in <id>.json): the frames the browser was sent of
 * the picture and the sound (Recording::TYPES), and what the user's microphone sent (MIC), each with the milliseconds since
 * the start; never the keyboard, the clipboard or printed documents. Encrypted with a key of its own derived from the server key: AES-256-GCM
 * in pieces of up to 1 MB (or one a second), each bound to its recording and its place, so pieces can be neither changed,
 * swapped nor reordered. Kept until deleted by hand, or the days of the settings (record_days, when not 0), at most
 * record_max_mb each (config.php).
 * A connection recorded by the administrator's choice is recorded from the start, its id the session's, from the frames
 * of the stream. One started in the session (an administrator, or its user when allowed: see rdp.php) is made in the
 * user's browser, of what its page shows and plays ("kind": "video"): the gateway keeps the video file the page sends in
 * pieces (VIDEO frames), as it comes; its id is its own, its session in "sid". (A stream's frames cannot start halfway: they
 * build on what came before, which only a new connection sends again.)
 *
 * File: "MDREC1\n", then pieces [u32 length][12-byte nonce][16-byte tag][ciphertext]. Plain piece: frames
 * [u32 ms][u8 type][u32 length][payload], the frame types of the stream (see ARCHITECTURE), or VIDEO.
 */
final class Recording
{
    public const DAYS  = 30;                  // kept by default
    /**
     * Frame types kept: the picture and its size, the pointer and where the owner's mouse is, the state, the sound, the
     * microphone's format (13: what the browser is told when a program there starts recording) and what it sent (MIC), a
     * connection made again
     */
    public const TYPES = [1 => true, 2 => true, 3 => true, 5 => true, 7 => true, 9 => true, 10 => true, 11 => true, 13 => true, 14 => true,
        15 => true, self::MIC => true];
    /** A recording's own frame type (no frame of the stream has it): the user's microphone as the browser sent it, 16-bit PCM in the format of the last frame 13 */
    public const MIC = 32;
    /** The only frame type of a recording made in the browser: the next bytes of its video file (WebM, or MP4 from Safari) */
    public const VIDEO = 33;
    private const MAGIC = "MDREC1\n";
    private const PIECE = 1 << 20;            // plain bytes kept before they are encrypted and written
    /** Bytes a second a recording made in the browser may grow by at most (its video's highest bit rate is 6 Mbit/s and sound) */
    public const PAGE_RATE = 1 << 20;
    private const EVERY = 1.0;                // seconds: a piece at least this often (little is lost if the process dies)

    /** @var resource|null */
    private $fh;
    private string $buf = '';
    private int $piece = 0;
    private int $size;
    private float $t0;
    private float $last;
    private bool $full = false;
    /** A piece could not be written (disk full...): the session ends, as without a recording */
    public bool $failed = false;
    /** Made in the browser (its video file), not of the stream's frames */
    public readonly bool $video;

    private function __construct(public readonly string $sid, private array $meta, private int $max)
    {
        $this->t0    = $this->last = microtime(true);
        $this->size  = strlen(self::MAGIC);
        $this->video = ($meta['kind'] ?? '') === 'video';
    }

    public static function dir(): string
    {
        return Config::dir('recordings');
    }

    private static function path(string $sid, string $ext): string
    {
        return self::dir() . '/' . $sid . '.' . $ext;
    }

    private static function key(): string
    {
        return hash_hmac('sha256', 'MuDeskClient session recordings', Crypto::appKey(), true);
    }

    /** Largest recording, in bytes (config.php 'record_max_mb', 2048 by default). */
    public static function max(): int
    {
        return max(1, (int)(Config::get('record_max_mb') ?? 2048)) << 20;
    }

    /** A new recording's id (one started in a session: the session's own is taken by one from its start). */
    public static function newId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * A recording starts, $sid its id ($meta: who, which connection, its session's "sid" when it is not $sid, who started
     * it), or null when its file cannot be made.
     */
    public static function start(string $sid, array $meta): ?self
    {
        if (!Sessions::validSid($sid)) {
            return null;
        }
        $dir = self::dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $fh = @fopen(self::path($sid, 'rec'), 'xb');
        if ($fh === false) {
            return null;
        }
        @chmod(self::path($sid, 'rec'), 0600);
        fwrite($fh, self::MAGIC);
        $r     = new self($sid, ['id' => $sid] + $meta + ['sid' => $sid, 'started' => date('c')], self::max());
        $r->fh = $fh;
        $r->meta();
        return $r;
    }

    /**
     * A frame the browser was sent: kept if it is of the picture (and while the recording has room); one made in the
     * browser keeps only its video (VIDEO).
     */
    public function frame(int $type, string $payload): void
    {
        if (($this->video ? $type !== self::VIDEO : !isset(self::TYPES[$type])) || $this->full || $this->failed || $this->fh === null) {
            return;
        }
        if ($type === 2 && strlen($payload) >= 4) {         // the largest desktop, for a video of the recording
            ['w' => $w, 'h' => $h] = unpack('vw/vh', $payload);
            if ($w > (int)($this->meta['width'] ?? 0) || $h > (int)($this->meta['height'] ?? 0)) {   // (written at once: rare)
                $this->meta['width']  = max($w, (int)($this->meta['width'] ?? 0));
                $this->meta['height'] = max($h, (int)($this->meta['height'] ?? 0));
                $this->meta();
            }
        }
        if (($type === 7 || $type === self::MIC) && empty($this->meta['sound'])) {   // it has sound (a video of it gets an audio track)
            $this->meta['sound'] = true;
            $this->meta();
        }
        $this->buf .= pack('VCV', (int)((microtime(true) - $this->t0) * 1000), $type, strlen($payload));
        if (strlen($this->buf) >= self::PIECE) {           // (the header past the piece's end: else the room below was negative,
            $this->write();                                // and a large frame went whole into a piece the reader refuses)
        }
        // in slices up to the size of a piece: a large frame (a graphics message of up to 64 MB) made a piece the reader
        // refused, and the recording stopped playing there; the plain bytes are one stream, so a frame may span pieces
        for ($o = 0, $n = strlen($payload); $o < $n; $o += $room) {
            $room       = self::PIECE - strlen($this->buf);
            $this->buf .= $o === 0 && $n <= $room ? $payload : substr($payload, $o, $room);
            if (strlen($this->buf) >= self::PIECE) {
                $this->write();
            }
        }
    }

    /**
     * A recording made in the browser: what its page says of its video (its type, its size, whether it has sound), kept
     * in its details for the player and the lists.
     */
    public function describe(array $d): void
    {
        $this->meta['mime'] = is_string($d['mime'] ?? null) && str_starts_with($d['mime'], 'video/mp4') ? 'video/mp4' : 'video/webm';
        $w = is_int($d['w'] ?? null) ? $d['w'] : 0;
        $h = is_int($d['h'] ?? null) ? $d['h'] : 0;
        if ($w >= 1 && $w <= 32766 && $h >= 1 && $h <= 32766) {
            [$this->meta['width'], $this->meta['height']] = [$w, $h];
        }
        if (!empty($d['sound'])) {
            $this->meta['sound'] = true;
        }
        $this->meta();
    }

    /**
     * A recording made in the browser that grew faster than its video can (PAGE_RATE a second since it started, and 10 s
     * more): a page sending what it likes, filling the disk. Pieces held back by the network and sent at once later are
     * not: they were made in their time.
     */
    public function overRate(): bool
    {
        return $this->video && $this->size + strlen($this->buf) > (microtime(true) - $this->t0 + 10) * self::PAGE_RATE;
    }

    /** Called from the session's loop: what waits is written once a second. */
    public function tick(): void
    {
        if ($this->buf !== '' && microtime(true) - $this->last >= self::EVERY) {
            $this->write();
        }
    }

    private function write(): void
    {
        $this->last = microtime(true);
        if ($this->full) {
            $this->buf = '';                       // full: nothing more is kept (a smaller last piece was written after it)
            return;
        }
        if ($this->fh === null || $this->buf === '') {
            return;
        }
        $iv = random_bytes(12);
        $ct = openssl_encrypt($this->buf, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag, $this->sid . ':' . $this->piece, 16);
        $this->buf = '';
        if ($ct === false) {
            $this->failed = true;
            return;
        }
        $rec = pack('V', 28 + strlen($ct)) . $iv . $tag . $ct;
        if ($this->size + strlen($rec) > $this->max) {     // full: what comes later is not kept (said in its details)
            $this->full = true;
            $this->meta['truncated'] = true;
            $this->meta();
            return;
        }
        if (@fwrite($this->fh, $rec) !== strlen($rec)) {
            $this->failed = true;
            $this->meta['failed'] = true;
            $this->meta();
            return;
        }
        $this->size += strlen($rec);
        $this->piece++;
    }

    /** The session ended, or the recording was stopped: what waits is written and the details completed (end, duration, size). */
    public function close(array $end = []): void
    {
        if ($this->fh === null) {
            return;
        }
        $this->write();
        fclose($this->fh);
        $this->fh   = null;
        $this->meta = $end + ['ended' => date('c'), 'seconds' => (int)round(microtime(true) - $this->t0), 'bytes' => $this->size] + $this->meta;
        $this->meta();
    }

    private function meta(): void
    {
        $tmp = self::path($this->sid, 'json') . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, json_encode($this->meta)) === false) {
            return;
        }
        @chmod($tmp, 0600);
        for ($i = 0; !@rename($tmp, self::path($this->sid, 'json')); $i++) {   // (Windows: an auditor reading it at that moment)
            if ($i >= 50) {
                @unlink($tmp);
                return;
            }
            usleep(10000);
        }
    }

    /** The details of a recording (who, connection, start, end, seconds, bytes), or null when there is none. */
    public static function info(string $sid): ?array
    {
        if (!Sessions::validSid($sid) || !is_file(self::path($sid, 'rec'))) {
            return null;
        }
        $m = json_decode((string)@file_get_contents(self::path($sid, 'json')), true);
        return is_array($m) ? $m : ['sid' => $sid];
    }

    /**
     * The recording's frames, piece by piece, to $out (plain bytes, as written: [u32 ms][u8 type][u32 length][payload]...);
     * false when there is none, or a piece was changed, swapped or cut (what came before it was given). $out returning
     * false stops it.
     */
    public static function read(string $sid, callable $out): bool
    {
        $fh = self::info($sid) === null ? false : @fopen(self::path($sid, 'rec'), 'rb');
        if ($fh === false || fread($fh, strlen(self::MAGIC)) !== self::MAGIC) {
            return false;
        }
        $key = self::key();
        for ($n = 0; ($h = fread($fh, 4)) !== false && strlen($h) === 4; $n++) {
            $len = unpack('V', $h)[1];
            $rec = $len >= 28 && $len <= self::PIECE + 1048576 ? (string)fread($fh, $len) : '';
            $pt  = strlen($rec) === $len && $len >= 28
                ? openssl_decrypt(substr($rec, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($rec, 0, 12), substr($rec, 12, 16), $sid . ':' . $n) : false;
            if ($pt === false) {
                fclose($fh);
                return false;
            }
            if ($out($pt) === false) {             // (the browser left)
                break;
            }
        }
        fclose($fh);
        return true;
    }

    /**
     * Whether user $u started recording $info in their own session (as its user, or, an administrator, as such): theirs to
     * watch, make into a video and delete. One of a connection always recorded, or one another administrator started, is
     * for administrators and auditors only.
     */
    public static function ownedBy(array $info, array $u): bool
    {
        $as = $info['started_as'] ?? '';
        return ($info['uid'] ?? null) === ($u['id'] ?? false)
            && ($as === 'owner' || ($as === 'admin' && ($info['by'] ?? null) === ($u['username'] ?? false)));
    }

    /** The recordings user $u started in their own sessions (their details, with their id), the newest first. */
    public static function mine(array $u): array
    {
        return self::all(static fn(array $m): bool => self::ownedBy($m, $u));
    }

    /**
     * Every recording (its details, with its id; one of before 1.2 says nothing of who started it: its connection's), the
     * newest first; $only picks some by their details.
     */
    public static function all(?callable $only = null): array
    {
        $out = [];
        foreach (glob(self::dir() . '/*.json') ?: [] as $f) {
            $id = basename($f, '.json');
            $m  = Sessions::validSid($id) ? json_decode((string)@file_get_contents($f), true) : null;
            if (is_array($m) && ($only === null || $only($m)) && is_file(self::path($id, 'rec'))) {
                $out[] = ['id' => $id] + $m + ['started_as' => 'policy', 'running' => self::running($m)];
            }
        }
        usort($out, static fn(array $a, array $b): int => strcmp((string)($b['started'] ?? ''), (string)($a['started'] ?? '')));
        return $out;
    }

    /**
     * Whether recording $info is still being made: not ended, and its session runs (one left behind by a process the hosting
     * killed, never closed, is not: it can be deleted).
     */
    public static function running(array $info): bool
    {
        return !isset($info['ended']) && Sessions::alive((string)($info['sid'] ?? ''));
    }

    /** Removes recording $id and its details; one still being written is not (false). */
    public static function delete(string $id): bool
    {
        $info = self::info($id);
        if ($info === null || self::running($info)) {
            return false;
        }
        @unlink(self::path($id, 'rec'));
        @unlink(self::path($id, 'json'));
        clearstatcache();
        return !is_file(self::path($id, 'rec'));
    }

    /**
     * Recordings older than the days of the settings go; with 0 (by default), none: kept until deleted by hand (called when
     * a session starts, when one is listed and by the hourly housekeeping).
     */
    public static function cleanup(): void
    {
        $days = (int)Settings::get('record_days');
        if ($days <= 0) {
            return;                                 // (kept until deleted by hand)
        }
        clearstatcache();                           // (the dates PHP kept for this process: a recording just ended looked old, or the reverse)
        foreach (glob(self::dir() . '/*.rec') ?: [] as $f) {
            if ((int)@filemtime($f) < time() - $days * 86400) {
                @unlink($f);
                @unlink(substr($f, 0, -4) . '.json');
            }
        }
    }
}
