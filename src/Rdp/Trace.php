<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk\Rdp;

use MuDesk\Config;

/**
 * Diagnostic trace of one RDP session (data/trace/<sid>.log): connection sequence, capabilities, channels, message
 * types with counts and sizes every 10 s, errors of the gateway and of the browser. Never screen, keys, clipboard
 * or file contents, passwords or keys: only headers, types, sizes and protocol fields.
 */
final class Trace
{
    public const KEEP_DAYS = 7;
    private const MAX      = 8 * 1048576;     // per session
    private const EVERY    = 10;              // seconds between statistics

    /** @var resource|null */
    private $fh;
    private float $t0;
    private float $next;
    private int $size = 0;
    private array $stats = [];
    private array $seen = [];

    public function __construct(public readonly string $sid, array $header)
    {
        $dir = Config::dir('trace');
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $this->fh   = @fopen("$dir/$sid.log", 'xb') ?: null;
        @chmod("$dir/$sid.log", 0600);
        $this->t0   = microtime(true);
        $this->next = $this->t0 + self::EVERY;
        $this->log('start', $header + ['date' => date('c')]);
    }

    public static function dir(): string
    {
        return Config::dir('trace');
    }

    /** Whether a new trace fits: at most 256 MB of traces in all (any user can ask for one, 8 MB each). */
    public static function room(): bool
    {
        return array_sum(array_map(static fn($f) => (int)@filesize($f), glob(self::dir() . '/*.log') ?: [])) < 268435456 - self::MAX;
    }

    /** Traces older than KEEP_DAYS are removed (called when a session starts and from the admin list). */
    public static function cleanup(): void
    {
        foreach (glob(self::dir() . '/*.log') ?: [] as $f) {
            $t = @filemtime($f);                 // (removed meanwhile by another request's cleanup: no warning)
            if ($t !== false && $t < time() - self::KEEP_DAYS * 86400) {
                @unlink($f);
            }
        }
    }

    public function log(string $what, array $d = []): void
    {
        if ($this->fh === null) {
            return;
        }
        $line = sprintf('%9.3f %s', microtime(true) - $this->t0, $what)
              . ($d === [] ? '' : ' ' . json_encode($d, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)) . "\n";
        $this->size += strlen($line);
        if ($this->size > self::MAX) {
            fwrite($this->fh, "trace size limit reached\n");
            fclose($this->fh);
            $this->fh = null;
            return;
        }
        fwrite($this->fh, $line);
    }

    /** Logs the first occurrences of something only (unknown message types, repeated errors). */
    public function once(string $key, string $what, array $d = [], int $times = 3): void
    {
        $n = $this->seen[$key] = ($this->seen[$key] ?? 0) + 1;
        if ($n <= $times) {
            $this->log($what, $d + ($n === $times ? ['note' => 'further occurrences only counted'] : []));
        }
    }

    /** Counts a message ("fastpath:bitmap", "channel:cliprdr"…) and its size; statistics are written every 10 s. */
    public function count(string $key, int $bytes): void
    {
        if (!isset($this->stats[$key]) && count($this->stats) >= 500) {
            $key = 'other';                          // (the server chooses channel ids: 300,000 of them took 100 MB)
        }
        $s                  = &$this->stats[$key];
        $s                  = [($s[0] ?? 0) + 1, ($s[1] ?? 0) + $bytes];
        if (microtime(true) >= $this->next) {
            $this->flush();
        }
    }

    private function flush(): void
    {
        if ($this->stats !== []) {
            ksort($this->stats);
            $this->log('stats', array_map(static fn($s) => "$s[0] msgs / $s[1] B", $this->stats));
            $this->stats = [];
        }
        $this->next = microtime(true) + self::EVERY;
    }

    public function close(array $d): void
    {
        $this->flush();
        $this->log('end', $d);
        if ($this->fh !== null) {
            fclose($this->fh);
            $this->fh = null;
        }
    }

    public static function hex(string $s, int $max = 96): string
    {
        return bin2hex(substr($s, 0, $max)) . (strlen($s) > $max ? '…(' . strlen($s) . ' B)' : '');
    }
}
