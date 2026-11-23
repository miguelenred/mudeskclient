<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk;

/**
 * Recording of an RDP session (data/recordings/<sid>.rec, its details in <sid>.json): the frames the browser was sent of
 * the picture (Recording::TYPES), each with the milliseconds since the start; never the keyboard, the clipboard, sound,
 * the microphone or printed documents. Encrypted with a key of its own derived from the server key: AES-256-GCM in pieces
 * of up to 1 MB (or one a second), each bound to its recording and its place, so pieces can be neither changed, swapped
 * nor reordered. Kept the days of the settings (record_days), at most record_max_mb each (config.php).
 *
 * File: "MDREC1\n", then pieces [u32 length][12-byte nonce][16-byte tag][ciphertext]. Plain piece: frames
 * [u32 ms][u8 type][u32 length][payload], the frame types of the stream (see ARCHITECTURE).
 */
final class Recording
{
    public const DAYS  = 30;                  // kept by default
    /** Frame types kept: the picture and its size, the pointer and where the owner's mouse is, the state, a connection made again */
    public const TYPES = [1 => true, 2 => true, 3 => true, 5 => true, 9 => true, 10 => true, 11 => true, 14 => true, 15 => true];
    private const MAGIC = "MDREC1\n";
    private const PIECE = 1 << 20;            // plain bytes kept before they are encrypted and written
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

    private function __construct(public readonly string $sid, private array $meta, private int $max)
    {
        $this->t0   = $this->last = microtime(true);
        $this->size = strlen(self::MAGIC);
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

    /** A recording of session $sid starts ($meta: who, which connection), or null when its file cannot be made. */
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
        $r     = new self($sid, $meta + ['sid' => $sid, 'started' => date('c')], self::max());
        $r->fh = $fh;
        $r->meta();
        return $r;
    }

    /** A frame the browser was sent: kept if it is of the picture (and while the recording has room). */
    public function frame(int $type, string $payload): void
    {
        if (!isset(self::TYPES[$type]) || $this->full || $this->failed) {
            return;
        }
        $this->buf .= pack('VCV', (int)((microtime(true) - $this->t0) * 1000), $type, strlen($payload));
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

    /** The session ended: what waits is written and the details completed (end, duration, size). */
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

    /** Recordings older than the days of the settings go (called when a session starts and when one is listed). */
    public static function cleanup(): void
    {
        $days = max(1, (int)Settings::get('record_days'));
        clearstatcache();                           // (the dates PHP kept for this process: a recording just ended looked old, or the reverse)
        foreach (glob(self::dir() . '/*.rec') ?: [] as $f) {
            if ((int)@filemtime($f) < time() - $days * 86400) {
                @unlink($f);
                @unlink(substr($f, 0, -4) . '.json');
            }
        }
    }
}
