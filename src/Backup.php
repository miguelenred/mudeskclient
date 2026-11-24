<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk;

/**
 * A backup of the installation, encrypted with a password of its own, and its restore.
 *
 * What it carries: the users, settings, connections (the administrator's and each user's), the certificates trusted and
 * fixed, the audit and the keys of the data (secret.php: the stored secrets, recordings and tokens; audit-key.php: the
 * audit's signature); the users' folders and the recordings when asked. Never what passes (sessions running, web sessions,
 * temporary files, locks, the limits of sign-in attempts, what TLS each computer took).
 *
 * The file: MAGIC, a salt, the PBKDF2 rounds; then chunks of 1 MB [u32 length][u8 last][12-byte nonce][AES-256-GCM][tag],
 * each authenticated with its number and whether it is the last (a chunk cut, moved, or one more after the last is refused).
 * Inside: records [u16 name length][name][u64 size][bytes], the first one ".manifest" (JSON: version, when, by whom, what).
 * Written and read as it goes: never the whole of it in memory.
 */
final class Backup
{
    public const MAGIC = "MUDESKBK1\n";
    public const OPTIONAL = ['files', 'recordings'];
    private const CHUNK = 1048576, ROUNDS = 400000, ROUNDS_MAX = 5000000;
    private const DIRS = ['connections', 'hosts', 'audit', 'files', 'recordings'];
    private const KEYS = ['secret.php', 'audit-key.php'];
    private const SKIP = ['ratelimit.json', 'auditlimit.json', 'tls.json'];

    /** @var (\Closure(string, string): bool)|null how an item is moved (rename); the tests make one fail */
    public static ?\Closure $move = null;

    /**
     * Whether $name (relative, with "/") is one a backup carries: never another path of the server. A user's folder whole,
     * whatever its files are called (a "yarn.lock" too: before, such names were left out, and a restore then removed them);
     * of the gateway's own, never its unfinished writes and locks.
     */
    public static function allowed(string $name): bool
    {
        $parts = explode('/', $name);
        if ($name === '' || strlen($name) > 65535 || in_array('', $parts, true) || in_array('.', $parts, true) || in_array('..', $parts, true)
            || preg_match('~[\x00-\x1f\\\\:*?"<>|]~', $name)) {
            return false;
        }
        if ($parts[0] === 'files') {
            return count($parts) > 2;                // (beside the folders, each user's quota counter: counted again when missing)
        }
        if (preg_match('~\.(lock|tmp)$~', $name)) {
            return false;
        }
        if (count($parts) === 1) {
            return in_array($name, self::KEYS, true) || (preg_match('~^[a-z_]+\.json$~D', $name) && !in_array($name, self::SKIP, true));
        }
        return in_array($parts[0], self::DIRS, true);
    }

    /** The files a backup of now carries ($with: of OPTIONAL, those wanted): [name in it, path]. */
    public static function entries(array $with): array
    {
        $root = Config::dir();
        $out  = [];
        foreach (scandir($root) ?: [] as $f) {
            if (is_file("$root/$f") && self::allowed($f)) {
                $out[] = [$f, "$root/$f"];
            }
        }
        foreach (self::DIRS as $d) {
            if ((in_array($d, self::OPTIONAL, true) && !in_array($d, $with, true)) || !is_dir("$root/$d")) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/$d", \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                $name = $d . '/' . str_replace('\\', '/', substr($file->getPathname(), strlen("$root/$d") + 1));
                if ($file->isFile() && !$file->isLink() && self::allowed($name)) {
                    $out[] = [$name, $file->getPathname()];
                }
            }
        }
        return $out;
    }

    /** The key of $password and $salt (its rounds: the file's). */
    public static function key(string $password, string $salt, int $rounds = self::ROUNDS): string
    {
        return hash_pbkdf2('sha256', $password, $salt, $rounds, 32, true);
    }

    /**
     * Writes a backup of $entries to $out with $key (and its $salt), $manifest first. Returns the bytes written. A file that
     * goes away meanwhile is left out (as in a backup made a moment later); one that cannot be read, or grows shorter while it
     * is read, throws: the backup ends there without its last chunk, refused whole when restored. Before, such a file was
     * written as zeros, and the backup taken for good (a secret.php so restored stopped the whole installation).
     */
    public static function write($out, string $key, string $salt, array $manifest, array $entries): int
    {
        $total = (int)fwrite($out, self::MAGIC . $salt . pack('N', self::ROUNDS));
        [$buf, $index] = ['', 0];
        $emit = static function (string $plain, bool $last) use ($out, $key, &$index, &$total): void {
            $nonce = random_bytes(12);
            $ct    = (string)openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, self::MAGIC . pack('NC', $index, $last ? 1 : 0), 16);
            $total += (int)fwrite($out, pack('NC', strlen($ct), $last ? 1 : 0) . $nonce . $ct . $tag);
            $index++;
        };
        $push = static function (string $data) use (&$buf, $emit): void {
            $buf .= $data;
            while (strlen($buf) >= self::CHUNK) {
                $emit(substr($buf, 0, self::CHUNK), false);
                $buf = (string)substr($buf, self::CHUNK);
            }
        };
        $json = (string)json_encode($manifest + ['files' => count($entries)], Http::JSON);
        $push(pack('n', 9) . '.manifest' . pack('J', strlen($json)) . $json);
        foreach ($entries as [$name, $path]) {
            $h = is_file($path) ? @fopen($path, 'rb') : false;
            if ($h === false) {
                if (!file_exists($path)) {
                    continue;
                }
                throw new \RuntimeException("$name cannot be read");
            }
            // its size from what is read: a file of up to a chunk whole first (one being written meanwhile, as it is then)
            $first = (string)fread($h, self::CHUNK);
            $size  = feof($h) ? strlen($first) : max(strlen($first), (int)(fstat($h)['size'] ?? 0));
            $push(pack('n', strlen($name)) . $name . pack('J', $size) . $first);
            for ($left = $size - strlen($first); $left > 0;) {
                $d = (string)fread($h, min(self::CHUNK, $left));
                if ($d === '') {
                    fclose($h);
                    throw new \RuntimeException("$name grew shorter while it was read");
                }
                $push($d);
                $left -= strlen($d);
            }
            fclose($h);
        }
        $emit($buf, true);
        return $total;
    }

    /**
     * Reads backup $file with $password into folder $dir, every chunk checked: its manifest, or why not ('backup_password': the
     * first chunk does not open, a wrong password; 'backup_damaged': cut, changed or more after its end; 'backup_format': not a
     * backup; 'backup_version': of a newer version; 'backup_restore': a file it could not write, the disk full, say). Its files
     * only for the server's account, as the gateway writes its own (before, the process's mask: a restored secret.php readable
     * by other accounts of a shared server). While it reads, $file and $dir say they are in use (housekeeping removes them
     * after an hour unused: a big backup can take longer).
     */
    public static function extract(string $file, string $password, string $dir): array|string
    {
        $h = @fopen($file, 'rb');
        if ($h === false || fread($h, strlen(self::MAGIC)) !== self::MAGIC) {
            $h && fclose($h);
            return 'backup_format';
        }
        $salt   = (string)fread($h, 16);
        $rounds = strlen($r = (string)fread($h, 4)) === 4 ? unpack('N', $r)[1] : 0;
        if (strlen($salt) !== 16 || $rounds < 1000 || $rounds > self::ROUNDS_MAX) {
            fclose($h);
            return 'backup_format';
        }
        $key = self::key($password, $salt, $rounds);
        [$index, $done, $pending, $rec, $out, $manifest] = [0, false, '', null, null, null];
        $fail = static function (string $why) use (&$out, $h): string {
            $out && fclose($out);
            fclose($h);
            return $why;
        };
        $seen = time();
        while (!$done) {
            if (time() - $seen >= 60) {
                @touch($file);
                @touch($dir);
                $seen = time();
            }
            $head = (string)fread($h, 5);
            if (strlen($head) < 5) {
                return $fail('backup_damaged');      // (cut before its last chunk, the first too)
            }
            ['n' => $n, 'l' => $last] = unpack('Nn/Cl', $head);
            $body = $n <= self::CHUNK + 16 ? self::readFull($h, 12 + $n + 16) : '';
            if (strlen($body) !== 12 + $n + 16 || $last > 1) {
                return $fail('backup_damaged');
            }
            $plain = openssl_decrypt(substr($body, 12, $n), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($body, 0, 12), substr($body, 12 + $n),
                self::MAGIC . pack('NC', $index, $last));
            if ($plain === false) {
                return $fail($index === 0 ? 'backup_password' : 'backup_damaged');
            }
            $index++;
            $done     = $last === 1;
            $pending .= $plain;
            // the records in it, as they come (one may go on in the next chunk)
            while (true) {
                if ($rec === null) {
                    if (strlen($pending) < 2 || strlen($pending) < 2 + unpack('n', $pending)[1] + 8) {
                        break;
                    }
                    $nl   = unpack('n', $pending)[1];
                    $name = substr($pending, 2, $nl);
                    $size = unpack('J', $pending, 2 + $nl)[1];
                    $pending = (string)substr($pending, 2 + $nl + 8);
                    if ($size < 0 || ($manifest === null) !== ($name === '.manifest') || ($name === '.manifest' && $size > 65536)
                        || ($name !== '.manifest' && !self::allowed($name))) {
                        return $fail('backup_damaged');
                    }
                    if ($name !== '.manifest') {
                        @mkdir(dirname("$dir/$name"), 0700, true);
                        $out = @fopen("$dir/$name", 'xb');
                        if ($out === false) {
                            return $fail('backup_restore');   // (not to be written here: twice the same name, or two that differ
                        }                                     // only in case where the file system does not tell them apart)
                        @chmod("$dir/$name", 0600);
                    }
                    $rec = [$name, $size, ''];
                }
                $take = (int)min(strlen($pending), $rec[1]);
                if ($rec[0] === '.manifest') {
                    $rec[2] .= substr($pending, 0, $take);
                } elseif ($take > 0 && @fwrite($out, substr($pending, 0, $take)) !== $take) {
                    return $fail('backup_restore');      // (the disk full: before, taken as read, and put in place cut short)
                }
                $pending = (string)substr($pending, $take);
                $rec[1] -= $take;
                if ($rec[1] > 0) {
                    break;
                }
                if ($rec[0] === '.manifest') {
                    $manifest = json_decode($rec[2], true);
                    if (!is_array($manifest)) {
                        return $fail('backup_damaged');
                    }
                    $v = (string)($manifest['version'] ?? '');
                    if (preg_match('~^\d+\.\d+~', VERSION, $mine) && preg_match('~^\d+\.\d+~', $v, $theirs) && version_compare($theirs[0], $mine[0], '>')) {
                        return $fail('backup_version');
                    }
                } else {
                    $closed = fclose($out);
                    $out    = null;
                    if (!$closed) {
                        return $fail('backup_restore');
                    }
                }
                $rec = null;
            }
        }
        $extra = fread($h, 1);
        fclose($h);
        if ($rec !== null || $pending !== '' || $extra !== '' || $manifest === null) {
            return 'backup_damaged';              // (a record cut short, or bytes after the last chunk)
        }
        return $manifest;
    }

    /**
     * Puts what folder $dir holds (an extract()) in place of the data folder's, each item whole (renamed). The data that lasts
     * is the backup's: also a store it did not have then goes (certificates fixed since, say); the users' folders and the
     * recordings only when it carries them ($with: its manifest's), else as they are. On any failure what was moved goes back;
     * what could not go back is never removed: kept in the data folder (restore-kept-<when>), said in the server's log. Every
     * session ends first (the remote ones, and the web ones after: their users are the backup's now). True, 'backup_restore'
     * (all as it was), or 'backup_kept' (not all of it could go back).
     */
    public static function restore(string $dir, array $with = []): bool|string
    {
        $live = Config::dir();
        $old  = Config::dir('tmp/restore-old-' . bin2hex(random_bytes(6)));
        $flag = Config::dir('run/restoring');
        @file_put_contents($flag, (string)time());              // (meanwhile Config::load makes no folder of these again)
        $running = static fn(): array => array_filter(Sessions::active(), static fn(array $s): bool => Sessions::validSid((string)($s['sid'] ?? '')));
        foreach ($running() as $s) {                            // (their processes stop writing before their files move)
            Sessions::kill((string)$s['sid'], 'killed');
        }
        for ($end = microtime(true) + 5; $running() !== [] && microtime(true) < $end;) {
            usleep(100000);
        }
        @mkdir($old, 0700, true);
        $mv    = self::$move ?? static fn(string $from, string $to): bool => @rename($from, $to);
        $items = [];
        foreach ([...(scandir($dir) ?: []), ...(scandir($live) ?: [])] as $item) {
            if ((self::allowed($item) || in_array($item, self::DIRS, true)) && (!in_array($item, self::OPTIONAL, true) || in_array($item, $with, true))) {
                $items[$item] = true;
            }
        }
        [$moved, $ok] = [[], true];
        foreach (array_keys($items) as $item) {
            $had = file_exists("$live/$item");
            $has = file_exists("$dir/$item");
            if ($had && !$mv("$live/$item", "$old/$item")) {
                $ok = false;
                break;
            }
            if ($has && !$mv("$dir/$item", "$live/$item")) {
                $moved[] = [$item, $had, false];                 // (its own aside: back below)
                $ok = false;
                break;
            }
            $moved[] = [$item, $had, $has];
        }
        $lost = [];
        if (!$ok) {                                              // back as it was
            foreach (array_reverse($moved) as [$item, $had, $has]) {
                $out = !$has || $mv("$live/$item", "$dir/$item");
                if ($had && $out && is_dir("$live/$item") && count(scandir("$live/$item") ?: []) === 2) {
                    @rmdir("$live/$item");                       // (a folder made again meanwhile, empty: out of the way)
                }
                if (!$out || ($had && !$mv("$old/$item", "$live/$item"))) {
                    $lost[] = $item;
                }
            }
        }
        if ($lost === []) {
            Files::remove($old);
            // (what could not be removed now, a file still open: by the housekeeping, which never removes restore-old-*)
            is_dir($old) && @rename($old, Config::dir('tmp/restore-done-' . bin2hex(random_bytes(6))));
        } else {                                                 // never removed: the data that was there (where the
            $kept = Config::dir('restore-kept-' . date('Ymd-His'));   // rename fails, as restore-old-*: kept too)
            $kept = @rename($old, $kept) ? $kept : $old;
            error_log('MuDeskClient: a restore that failed could not put back ' . implode(', ', $lost) . "; what was there is kept in $kept");
        }
        if ($ok) {
            foreach (glob(Config::dir('sessions/sess_*')) ?: [] as $f) {   // signed out: their users are the backup's
                @unlink($f);
            }
            Crypto::forgetAppKey();                              // (the backup's key from now on: the audit's next line too)
            Settings::forget();
        }
        @unlink($flag);
        return $ok ?: ($lost === [] ? 'backup_restore' : 'backup_kept');
    }

    /** Whether a restore is putting the backup in place now (other requests wait: 503). */
    public static function restoring(): bool
    {
        $t = (int)@file_get_contents(Config::dir('run/restoring'));
        return $t > 0 && time() - $t < 300;
    }

    private static function readFull($h, int $n): string
    {
        $s = '';
        while (strlen($s) < $n && !feof($h)) {
            $d = fread($h, $n - strlen($s));
            if ($d === false || $d === '') {
                break;
            }
            $s .= $d;
        }
        return $s;
    }
}
