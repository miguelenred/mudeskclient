<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk;

/** Per-user shared folder (data/files/<uid>), visible in the remote session as a redirected drive. */
final class Files
{
    /**
     * The quota counter last used, kept open until the request ends (a session's drive updates it all along): opening the
     * file right after it was written is slow where an antivirus scans it (10 ms against 0.2 ms).
     * @var array<string, resource>
     */
    private static array $counters = [];

    public static function root(string $uid): string
    {
        $dir = Config::dir('files/' . $uid);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {   // made by another request at once: no warning
            throw new \RuntimeException('Cannot create the shared folder');
        }
        return $dir;
    }

    /**
     * Normalises a relative path ("a/b", "\\a\\b"); returns null if it tries to escape, has a name longer than file systems
     * take (255 bytes) or names a file the web server or PHP would read as configuration (.htaccess, .htpasswd, web.config,
     * .user.ini; Windows drops trailing dots and spaces, so "web.config." is the same file), or a Windows device (NUL, CON,
     * AUX, PRN, COM1-9, LPT1-9, CONIN$, CONOUT$, CLOCK$, also with an extension: older Windows map "nul.txt" to it too).
     */
    public static function clean(string $rel): ?string
    {
        if (!preg_match('//u', $rel)) {
            return null;                       // not UTF-8: the checks below (which need it) would be skipped
        }
        $parts = [];
        foreach (preg_split('~[/\\\\]+~', $rel) as $p) {
            if ($p === '' || $p === '.') {
                continue;
            }
            if ($p === '..' || strlen($p) > 255 || preg_match('~[\x00-\x1f:*?"<>|]~', $p)
                || preg_match('~^(?:\.ht|(?:\.user\.ini|web\.config)$)~i', rtrim($p, '. '))
                || preg_match('~^(?:con|prn|aux|nul|com[0-9\x{b9}\x{b2}\x{b3}]|lpt[0-9\x{b9}\x{b2}\x{b3}]|conin\$|conout\$|clock\$) *(?:\.|$)~iu', rtrim($p, '. '))) {   // ("nul .txt" is the device too)
                return null;
            }
            $parts[] = $p;
        }
        return implode('/', $parts);
    }

    /**
     * Where an upload writes a file that replaces another (files.php): beside it, under a name of its own, put in its place
     * by a rename at the last chunk.
     */
    public static function partOf(string $path): string
    {
        return dirname($path) . '/.mudesk-upload-' . substr(hash('sha256', basename($path)), 0, 16);
    }

    /**
     * The part of an upload started for the user's file $rel, noted (tmp/upload-<uid>.parts, a path of the folder a line) so
     * that one left half way (the browser closed, the network gone) is found by the housekeeping without walking the user's
     * folder (Maintenance::run). Under the user's upload lock.
     */
    public static function notePart(string $uid, string $rel): void
    {
        $f    = Config::dir("tmp/upload-$uid.parts");
        $list = is_file($f) ? explode("\n", (string)@file_get_contents($f)) : [];
        if (!in_array($rel, $list, true)) {
            file_put_contents($f, "$rel\n", FILE_APPEND);
        }
    }

    public static function path(string $uid, string $rel): ?string
    {
        $rel  = self::clean($rel);
        $root = self::root($uid);
        $path = $rel === null ? null : $root . ($rel === '' ? '' : '/' . $rel);
        return $path !== null && self::confined($root, $path) ? $path : null;
    }

    /**
     * Whether $path, inside $root by its name, stays inside it once links are followed (symbolic links, Windows junctions).
     * Nothing of MuDeskClient makes one, but one put into a user's folder by something else (a restored backup, a program
     * on the server) must not lead out of it. The deepest part of $path that exists is resolved; a link that leads nowhere
     * is refused too (writing through it would create its target). At most 64 folders that are not there are walked up
     * (more is refused): each step goes over the whole path, and a server's path of thousands of them took seconds.
     */
    public static function confined(string $root, string $path): bool
    {
        $base = realpath($root);
        if ($base === false) {
            return false;
        }
        // (something there that does not resolve is a link that leads nowhere: lstat() sees a Windows junction to a target
        // that is gone, which is_link() does not; before, such a junction was walked past, and a write created its target)
        for ($p = $path, $up = 0; ($real = realpath($p)) === false; $p = dirname($p)) {
            if (is_link($p) || @lstat($p) !== false || $p === dirname($p) || strlen($p) < strlen($root) || ++$up > 64) {
                return false;
            }
        }
        return $real === $base || str_starts_with($real, rtrim($base, '/\\') . DIRECTORY_SEPARATOR);
    }

    /**
     * Whether $p is a link: a symbolic link, or a Windows junction, which is_link() does not tell (and is_dir() neither,
     * depending on the PHP version): an entry whose real path is not its parent's followed by its name. Walks of a folder
     * never go into one: deleting a folder must not delete what a link inside it leads to, nor a ZIP or the quota take it.
     */
    public static function isLink(string $p): bool
    {
        if (is_link($p)) {
            return true;
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            return false;
        }
        [$real, $parent] = [realpath($p), realpath(dirname($p))];
        return $real === false || $parent === false || strcasecmp($real, rtrim($parent, '\\') . '\\' . basename($p)) !== 0;
    }

    public static function list(string $uid, string $rel): ?array
    {
        $dir = self::path($uid, $rel);
        if ($dir === null || !is_dir($dir)) {
            return null;
        }
        $out = [];
        foreach (scandir($dir) ?: [] as $n) {
            if ($n === '.' || $n === '..') {
                continue;
            }
            $p     = $dir . '/' . $n;
            if (($mtime = @filemtime($p)) === false) {      // gone meanwhile (a program's temporary file): no warning
                continue;
            }
            $out[] = ['name' => $n, 'dir' => is_dir($p), 'size' => is_file($p) ? (int)@filesize($p) : 0, 'mtime' => $mtime];
        }
        usort($out, static fn($a, $b) => ($b['dir'] <=> $a['dir']) ?: strnatcasecmp($a['name'], $b['name']));
        return $out;
    }

    /**
     * The folders in each of the folders $rels (paths relative to the user's folder) for the tree of the file manager, which
     * reads only the open ones (walking every folder took seconds with many files): [[folder, names in natural order, more
     * than $max], ...]; folders that are not there are left out. Links are not followed.
     * @return list<array{string, list<string>, bool}>
     */
    public static function subdirs(string $uid, array $rels, int $max): array
    {
        $out = [];
        foreach ($rels as $rel) {
            $rel  = is_string($rel) ? self::clean($rel) : null;
            $path = $rel === null ? null : self::path($uid, $rel);
            if ($path === null || !is_dir($path)) {
                continue;
            }
            $names = array_values(array_filter(@scandir($path) ?: [], static fn(string $n): bool =>
                $n !== '.' && $n !== '..' && is_dir("$path/$n") && !self::isLink("$path/$n")));
            usort($names, 'strnatcasecmp');
            $out[] = [$rel, array_slice($names, 0, $max), count($names) > $max];
        }
        return $out;
    }

    /**
     * Moves or renames $from to $to (paths relative to the user's folder; the quota does not change): null when done, else
     * the error key. The destination must not exist (a change of case only is a rename on Windows) and its folder must; a
     * folder never goes into itself.
     */
    public static function move(string $uid, string $from, string $to): ?string
    {
        [$from, $to] = [self::clean($from), self::clean($to)];
        if ($from === null || $to === null || $from === '' || $to === '') {
            return 'invalid_path';
        }
        $root = self::root($uid);
        [$src, $dst] = ["$root/$from", "$root/$to"];
        if (!self::confined($root, $src) || !self::confined($root, $dst)) {   // through a link out of the folder
            return 'invalid_path';
        }
        if (!file_exists($src)) {
            return 'not_found';
        }
        if ($from === $to) {
            return null;
        }
        $parent = realpath(dirname($dst));
        $real   = realpath($src);
        if ($parent === false || !is_dir($parent)) {
            return 'not_found';
        }
        if (is_dir($src) && ($parent === $real || str_starts_with($parent . DIRECTORY_SEPARATOR, $real . DIRECTORY_SEPARATOR))) {
            return 'into_itself';
        }
        if (file_exists($dst) && realpath($dst) !== $real) {   // the same file: only the case changes
            return 'exists';
        }
        return @rename($src, $dst) ? null : 'move_failed';     // e.g. open in a remote session (Windows)
    }

    /** Creates the folders $dirs (relative to $base, with their parents); those that exist are left as they are. Null or the error key. */
    public static function mkdirs(string $uid, string $base, array $dirs): ?string
    {
        $root = self::path($uid, $base);
        if ($root === null) {
            return 'invalid_path';
        }
        if (!is_dir($root)) {
            return 'not_found';
        }
        foreach ($dirs as $d) {
            $rel = is_string($d) ? self::clean($d) : null;
            if ($rel === null || $rel === '') {
                return 'invalid_name';
            }
            $p = "$root/$rel";
            if (!self::confined(self::root($uid), $p)) {
                return 'invalid_name';
            }
            if (!is_dir($p) && !@mkdir($p, 0777, true) && !is_dir($p)) {
                return file_exists($p) ? 'exists' : 'invalid_name';
            }
        }
        return null;
    }

    /**
     * What a ZIP of the items $names of the folder $base holds: [name in the ZIP ("a/", "a/b.txt"), path of the file or
     * null for a folder, size], folders with all they hold. Null when a name is not there or not valid; false past $max
     * entries or 64 folders deep (no ZIP of that: see ZipStream::length).
     * @return list<array{string, ?string, int}>|null|false
     */
    public static function zipEntries(string $uid, string $base, array $names, int $max = 65535): array|null|false
    {
        $root = self::path($uid, $base);
        if ($root === null || !is_dir($root) || !$names) {
            return null;
        }
        $out  = [];
        $over = false;
        $walk = static function (string $path, string $name, int $depth = 0) use (&$walk, &$out, &$over, $max): void {
            if ($over || self::isLink($path)) {
                return;
            }
            if (count($out) >= $max || $depth > 64) {
                $over = true;
                return;
            }
            if (!is_dir($path)) {
                $out[] = [self::zipName($name) ?: '_', $path, (int)@filesize($path)];   // (gone meanwhile: 0, not a warning)
                return;
            }
            $out[] = [(self::zipName($name) ?: '_') . '/', null, 0];
            $names = array_values(array_diff(@scandir($path) ?: [], ['.', '..']));
            usort($names, 'strnatcasecmp');
            foreach ($names as $n) {
                $walk("$path/$n", "$name/$n", $depth + 1);
            }
        };
        foreach ($names as $n) {
            $n = is_string($n) ? self::clean($n) : null;
            if ($n === null || $n === '' || str_contains($n, '/') || !file_exists("$root/$n")) {
                return null;
            }
            $walk("$root/$n", $n);
        }
        return $over ? false : $out;
    }

    /**
     * A path made safe as a name inside a ZIP, whatever unzips it and on whatever system: each name without control
     * characters, without what Windows refuses (< > : " | ? *, a trailing dot or space, a device such as CON or COM1) and
     * without the look-alikes of / \ : . (full-width and others) that unzippers using Windows' older text functions turn into
     * the real ones, so that an extraction went outside its folder (reported against other RDP clients' clipboards); each
     * of them made "_", "." and ".." left out, "/" between names. Used for the names a remote computer gives (clipboard
     * files) and the shared folder's (which that computer writes too).
     */
    public static function zipName(string $path): string
    {
        $parts = [];
        foreach (preg_split('~[\\\\/]+~', $path, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $p) {
            $p = rtrim(preg_replace('~[\x00-\x1F\x7F<>:"|?*\x{FF0E}\x{FF0F}\x{FF3C}\x{FF1A}\x{2215}\x{2044}\x{FE68}\x{A5}\x{20A9}]~u', '_', $p) ?? '_', '. ');
            if ($p !== '') {
                $parts[] = preg_match('~^(CON|PRN|AUX|NUL|COM[0-9\x{B9}\x{B2}\x{B3}]|LPT[0-9\x{B9}\x{B2}\x{B3}]|CONIN\$|CONOUT\$|CLOCK\$)(\..*)?$~iu', $p) ? "_$p" : $p;
            }
        }
        return implode('/', $parts);
    }

    /** Bytes of a file, or of all the files in a folder. */
    public static function size(string $path): int
    {
        if (self::isLink($path)) {             // what a link leads to is not the user's
            return 0;
        }
        if (!is_dir($path)) {
            return is_file($path) ? (int)@filesize($path) : 0;
        }
        $total = 0;
        $dirs  = new \RecursiveCallbackFilterIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            static fn(\SplFileInfo $f, string $k, \RecursiveDirectoryIterator $i): bool => !$i->hasChildren() || !self::isLink($f->getPathname()));
        // (a folder removed during the walk is skipped: it threw, and the request ended in an error 500)
        foreach (new \RecursiveIteratorIterator($dirs, \RecursiveIteratorIterator::LEAVES_ONLY, \RecursiveIteratorIterator::CATCH_GET_CHILD) as $f) {
            $total += $f->isFile() && !$f->isLink() ? $f->getSize() : 0;
        }
        return $total;
    }

    /**
     * Bytes in the user's folder. $recount (the file manager lists it) also sets the quota counter to them when no session of
     * the user is running (sessions hold quota the folder does not show): files changed by other means count again.
     */
    public static function usage(string $uid, bool $recount = false): int
    {
        // the folder is walked outside the counter's lock: walked under it (seconds with many files), a session's writes
        // waited for it
        $limit  = self::limit($uid);
        $before = $recount && $limit !== 0 ? self::counter($uid, static fn(int &$used): int => $used) : 0;
        $size   = self::size(self::root($uid));
        if ($recount && $limit !== 0 && !Sessions::active($uid)) {
            // what was charged while the folder was walked (an upload of another request) is kept: set to the walk alone, it was lost
            self::counter($uid, static function (int &$used) use ($size, $before): void {
                $used = max(0, $size + $used - $before);
            });
        }
        return $size;
    }

    /**
     * Bytes used, for the file manager's list: walked and counted again only when it asks ($recount: opened or refreshed);
     * otherwise the quota counter (no walk: every list walked the whole folder, 7 to 17 s with 20,000 files), or null with
     * no quota (no counter: the page keeps what it had).
     */
    public static function listedUsage(string $uid, bool $recount): ?int
    {
        if ($recount) {
            return self::usage($uid, true);
        }
        return self::limit($uid) === 0 ? null : self::counter($uid, static fn(int &$used): int => $used);
    }

    /** Bytes left of the user's quota (PHP_INT_MAX without a quota). */
    public static function quotaLeft(string $uid): int
    {
        $limit = self::limit($uid);
        return $limit === 0 ? PHP_INT_MAX : self::counter($uid, static fn(int &$used): int => max(0, $limit - $used));
    }

    /** Charges $bytes to the user's quota if that many are left (false: nothing charged); negative bytes give them back. */
    public static function charge(string $uid, int $bytes): bool
    {
        $limit = self::limit($uid);
        return $limit === 0 || $bytes === 0 || self::counter($uid, static function (int &$used) use ($bytes, $limit): bool {
            if ($bytes > 0 && $used + $bytes > $limit) {
                return false;
            }
            $used = max(0, $used + $bytes);
            return true;
        });
    }

    /** Removes a file or folder of the user's and gives its quota back. */
    public static function delete(string $uid, string $path): void
    {
        if (self::limit($uid) === 0) {
            self::remove($path);
            return;
        }
        self::counter($uid, static function (int &$used) use ($path): void {
            $size = self::size($path);
            self::remove($path);
            $used = max(0, $used - $size + self::size($path));   // what could not be removed still counts
        });
    }

    /** Bytes of the quota of each user's folder, 0 without one. */
    public static function quota(): int
    {
        return Settings::get('files_quota_mb') * 1048576;
    }

    /** Bytes of the quota, 0 without one (then nothing is counted: the counter is counted again once there is a quota). */
    private static function limit(string $uid): int
    {
        $limit = self::quota();
        if ($limit === 0 && is_file(self::counterFile($uid))) {
            // emptied, not deleted: a session holding it open kept writing to a deleted file (Linux), or every new open of it
            // failed while it was "delete pending" (Windows); an empty one is counted again from the folder when needed
            @file_put_contents(self::counterFile($uid), '');
        }
        return $limit;
    }

    private static function counterFile(string $uid): string
    {
        return Config::dir('files/' . $uid . '.quota');
    }

    /**
     * Runs $fn(int &$used) under the lock of the user's quota counter: one for every way of writing to the folder (the drive
     * of each session, the file manager, clipboard files), so that together they stay within the quota. It holds the bytes
     * used, and those sessions took in advance (see Rdpdr). Counted from the folder when missing.
     */
    private static function counter(string $uid, callable $fn): mixed
    {
        $file = self::counterFile($uid);
        if (!isset(self::$counters[$file])) {
            self::closeCounters('');                 // one kept: a request works for one user (tests go through many)
            self::$counters[$file] = fopen($file, 'c+');
        }
        $h = self::$counters[$file];
        if ($h === false || !flock($h, LOCK_EX)) {
            unset(self::$counters[$file]);
            throw new \RuntimeException('Cannot lock the quota counter');
        }
        try {
            rewind($h);
            $old  = (string)stream_get_contents($h);
            $used = preg_match('/^\d+$/', $old) ? (int)$old : self::size(self::root($uid));
            $out  = $fn($used);
            if ((string)$used !== $old) {
                rewind($h);
                ftruncate($h, 0);
                fwrite($h, (string)$used);
            }
            return $out;
        } finally {
            flock($h, LOCK_UN);
        }
    }

    /** Closes the counters kept open under $path (Windows does not remove a folder with an open file). */
    private static function closeCounters(string $path): void
    {
        foreach (self::$counters as $file => $h) {
            if (str_starts_with($file, $path)) {
                fclose($h);
                unset(self::$counters[$file]);
            }
        }
    }

    public static function remove(string $path): void
    {
        self::closeCounters($path);
        if (self::isLink($path)) {             // the link goes, never what it leads to (a junction is removed as a folder)
            @unlink($path) || @rmdir($path);
            return;
        }
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $n) {
                if ($n !== '.' && $n !== '..') {
                    self::remove($path . '/' . $n);
                }
            }
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }

    public static function removeAll(string $uid): void
    {
        self::remove(Config::dir('files/' . $uid));
        self::remove(self::counterFile($uid));
    }
}
