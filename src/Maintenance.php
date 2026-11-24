<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk;

/**
 * Housekeeping without a scheduled task (a shared hosting has none): a request runs it at most once an hour. It removes what
 * the normal paths leave only when a process is killed half way (the hosting's time limit, a restart, a power cut) or when
 * a hosting turns PHP's own session cleanup off: the temporary files of unfinished writes, the files of sessions no longer
 * running (their uploads, print jobs and socket, also one in the system's temporary folder), web sessions past their life,
 * the lock files of deleted users, and what the retention settings let go (traces, recordings, audit days).
 */
final class Maintenance
{
    public const EVERY = 3600;               // seconds between two runs
    public const OLD   = 3600;               // a temporary file this old is a leftover (a write takes milliseconds)

    /** Runs it if an hour went by since the last run (one request at a time; the others go on at once). */
    public static function maybe(): void
    {
        $stamp = Config::dir('run/maintenance');
        $last  = @filemtime($stamp);
        if ($last !== false && time() - $last < self::EVERY) {
            return;
        }
        @mkdir(Config::dir('run'), 0700, true);
        $l = @fopen(Config::dir('run/maintenance.lock'), 'c');
        if ($l === false || !flock($l, LOCK_EX | LOCK_NB)) {
            $l && fclose($l);
            return;
        }
        try {
            clearstatcache(true, $stamp);
            $last = @filemtime($stamp);
            if ($last === false || time() - $last >= self::EVERY) {
                @touch($stamp);
                self::run();
            }
        } finally {
            flock($l, LOCK_UN);
            fclose($l);
        }
    }

    /** One run; returns how many files of each kind it removed. */
    public static function run(?int $now = null): array
    {
        $now ??= time();
        $old  = $now - self::OLD;
        $out  = ['tmp' => 0, 'sessions' => 0, 'web_sessions' => 0, 'locks' => 0];
        // temporary files of a write cut off (Store, Sessions, Recording and the setup code write "<file>.<8 hex>.tmp", then
        // rename it; the audit key's OpenSSL settings: "cnf..." in tmp/). Never the users' own files (files/ is not looked at)
        foreach (['', 'connections', 'hosts', 'recordings', 'run', 'audit', 'trace'] as $dir) {
            foreach (glob(Config::dir($dir === '' ? '*.tmp' : "$dir/*.tmp")) ?: [] as $f) {
                if (preg_match('/\.[0-9a-f]{8}\.tmp$/', $f) && self::older($f, $old) && @unlink($f)) {
                    $out['tmp']++;
                }
            }
        }
        foreach (glob(Config::dir('tmp/cnf*')) ?: [] as $f) {
            if (is_file($f) && self::older($f, $old) && @unlink($f)) {
                $out['tmp']++;
            }
        }
        // a backup uploaded to restore and never restored, its reading cut off, or what a restore that worked could not
        // remove: after an hour. Never restore-old-*: the data a restore set aside, cut off or not put back (Backup::restore)
        foreach (glob(Config::dir('tmp/restore-*')) ?: [] as $f) {
            if (!str_starts_with(basename($f), 'restore-old-') && self::older($f, $old)) {
                is_dir($f) ? Files::remove($f) : @unlink($f);
                $out['tmp']++;
            }
        }
        // sessions: the stale ones (Sessions::active cleans them up); then the remains of one whose registration file is
        // already gone (its mode, queue and socket files, its folder of uploads and print jobs, a socket in the system's
        // temporary folder when the data folder's path was too long for one)
        Sessions::active();
        $sids = [];
        foreach (glob(Config::dir('run/*.*')) ?: [] as $f) {
            if (preg_match('/^([a-f0-9]{32})\.(mode|q|sock)$/', basename($f), $m)) {
                $sids[$m[1]] = true;
            }
        }
        foreach (glob(Config::dir('tmp/*'), GLOB_ONLYDIR) ?: [] as $d) {
            if (Sessions::validSid(basename($d))) {
                $sids[basename($d)] = true;
            }
        }
        // (only this installation's: another one's on the same server are its own business)
        $own = '/^' . preg_quote(Sessions::tmpPrefix(), '/') . '([a-f0-9]{32})\.sock$/';
        foreach (glob(sys_get_temp_dir() . '/' . Sessions::tmpPrefix() . '*.sock') ?: [] as $f) {
            if (preg_match($own, basename($f), $m)) {
                $sids[$m[1]] = true;
            }
        }
        // named as before this installation's name was in them (left by a process killed before an upgrade): whose they
        // are cannot be told, so only one nobody listens on any longer goes
        foreach (glob(sys_get_temp_dir() . '/mudesk-*.sock') ?: [] as $f) {
            if (preg_match('/^mudesk-[a-f0-9]{32}\.sock$/', basename($f)) && self::older($f, $old)) {
                $c = @stream_socket_client('unix://' . $f, $en, $es, 1);
                if ($c !== false) {
                    fclose($c);                  // (someone's session still running: kept)
                } elseif (@unlink($f)) {
                    $out['sessions']++;
                }
            }
        }
        foreach (array_keys($sids) as $sid) {
            if (is_file(Config::dir("run/$sid.json"))) {
                continue;                        // running
            }
            $files = [Config::dir("run/$sid.mode"), Config::dir("run/$sid.q"), Config::dir("run/$sid.sock"), Config::dir("tmp/$sid"),
                Sessions::tmpSock($sid)];
            $young = array_filter($files, static fn($f) => file_exists($f) && !self::older($f, $old));
            if ($young === []) {                 // (one just starting writes its mode file before its registration)
                Sessions::cleanup($sid);
                @unlink(Sessions::tmpSock($sid));
                $out['sessions']++;
            }
        }
        // web sessions past their life, also where the hosting turned PHP's session cleanup off
        $life = max((int)ini_get('session.gc_maxlifetime'), (int)Settings::get('session_idle_minutes') * 60, 1440);
        foreach (glob(Config::dir('sessions/sess_*')) ?: [] as $f) {
            if (self::older($f, $now - $life) && @unlink($f)) {
                $out['web_sessions']++;
            }
        }
        // the lock files of deleted users (their stores and their uploads), only when no request holds them
        $users = Users::all();
        $gone  = [];
        foreach (glob(Config::dir('tmp/upload-*.lock')) ?: [] as $f) {
            if (preg_match('/^upload-(.+)\.lock$/', basename($f), $m) && !isset($users[$m[1]])) {
                $gone[] = $f;
            }
        }
        foreach (['connections', 'hosts'] as $dir) {
            foreach (glob(Config::dir("$dir/*.json.lock")) ?: [] as $f) {
                $uid = basename($f, '.json.lock');
                if (!isset($users[$uid]) && !is_file(substr($f, 0, -5))) {
                    $gone[] = $f;
                }
            }
        }
        foreach ($gone as $f) {
            $h = @fopen($f, 'r');
            $free = $h !== false && flock($h, LOCK_EX | LOCK_NB);
            if ($h !== false) {
                $free && flock($h, LOCK_UN);
                fclose($h);                      // (Windows removes no file still open)
            }
            if ($free && @unlink($f)) {
                $out['locks']++;
            }
        }
        // what the retention settings let go, also on a site nobody signs in to for a while
        Rdp\Trace::cleanup();
        Recording::cleanup();
        Audit::purge();
        return $out;
    }

    private static function older(string $f, int $than): bool
    {
        $t = @filemtime($f);
        return $t !== false && $t < $than;
    }
}
