<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk;

/**
 * The gateway's status for the administration (sessions, the PHP processes and memory they take, the disk, the problems of
 * the last two days) and for a monitor (health.php: working or not, nothing more).
 */
final class Status
{
    /** What runs now and how the server holds it. */
    public static function now(): array
    {
        $sessions = Sessions::active();
        $guests   = array_sum(array_map(static fn(array $s): int => (int)($s['viewers'] ?? 0), $sessions));
        $mem      = array_map(static fn(array $s): int => (int)($s['mem'] ?? 0), $sessions);
        $peaks    = array_map(static fn(array $s): int => (int)($s['mem_peak'] ?? 0), $sessions);
        $recs     = glob(Recording::dir() . '/*.rec') ?: [];   // (their files: what they take of the disk)
        $since    = date('Y-m-d', strtotime('-1 day'));          // yesterday and today
        $all      = Audit::search(['from' => $since], 0, 0);
        $stamp    = @filemtime(Config::dir('run/maintenance'));
        $load     = function_exists('sys_getloadavg') ? sys_getloadavg() : false;
        return [
            'sessions'   => count($sessions),
            'limit'      => (int)Settings::get('max_sessions_total'),   // (0: no limit)
            'guests'     => $guests,
            'recording'  => count(array_filter($sessions, static fn(array $s): bool => !empty($s['record']))),
            // each session holds a PHP process while it lasts, each guest watching one more
            'processes'  => count($sessions) + $guests,
            'memory'     => ['sessions' => array_sum($mem), 'largest' => $peaks === [] ? 0 : max($peaks), 'limit' => self::bytes((string)ini_get('memory_limit'))],
            'disk'       => ['free' => self::space('disk_free_space'), 'total' => self::space('disk_total_space'),
                             'recordings' => array_sum(array_map(static fn(string $f): int => (int)@filesize($f), $recs)), 'recordings_count' => count($recs)],
            'housekeeping' => $stamp === false ? null : date('c', $stamp),
            'load'       => is_array($load) ? array_map(static fn($x) => round((float)$x, 2), $load) : null,
            'problems'   => [
                'login_fail'    => (int)($all['events']['login_fail'] ?? 0),
                'session_error' => (int)Audit::search(['from' => $since, 'event' => 'rdp_end', 'ok' => '0'], 0, 0)['total'],
                'cert_mismatch' => (int)($all['events']['cert_mismatch'] ?? 0),
                'access_denied' => (int)($all['events']['access_denied'] ?? 0),
            ],
            'server'     => ['version' => VERSION, 'php' => PHP_VERSION, 'sapi' => PHP_SAPI, 'os' => PHP_OS_FAMILY . (function_exists('php_uname') ? ' ' . php_uname('r') : ''),
                             'openssl' => OPENSSL_VERSION_TEXT, 'time_limit' => (int)ini_get('max_execution_time')],
        ];
    }

    /**
     * The use of the last $days days (today included), from the audit: each day's sessions started, hours used and most
     * sessions at once; the computers used most (hours, sessions). A session's start gives its computer and its end how long
     * it lasted; one still running counts up to now; an attempt that never started, nothing.
     */
    public static function usage(int $days = 30): array
    {
        $days  = max(1, min(90, $days));
        $first = strtotime(date('Y-m-d', strtotime('-' . ($days - 1) . ' days')) . ' 00:00:00');
        $now   = time();
        [$hostOf, $spans, $started] = [[], [], []];
        Audit::search(['from' => date('Y-m-d', $first), 'event' => 'rdp_'], 0, PHP_INT_MAX,
            static function (array $r) use (&$hostOf, &$spans, &$started): void {
                $at = strtotime((string)($r['t'] ?? '')) ?: 0;
                if (($r['event'] ?? '') === 'rdp_start') {
                    $hostOf[(string)($r['sid'] ?? '')] = strtolower((string)($r['host'] ?? ''));
                    $started[] = $at;
                } elseif (($r['event'] ?? '') === 'rdp_end' && isset($r['seconds'])) {
                    $spans[] = [$at - max(0, (int)$r['seconds']), $at, (string)($r['sid'] ?? ''), (string)($r['conn'] ?? '')];
                }
            });
        // only sessions that started: an attempt that failed before (a password refused, a certificate) has an end but no start
        // (one begun before the days shown has its start before them)
        $spans = array_values(array_filter($spans, static fn(array $x): bool => isset($hostOf[$x[2]]) || $x[0] < $first));
        foreach (Sessions::active() as $s) {                      // (running: up to now)
            $from    = strtotime((string)($s['started'] ?? '')) ?: $now;
            $spans[] = [$from, $now, (string)($s['sid'] ?? ''), (string)($s['conn'] ?? '')];
            $hostOf[(string)($s['sid'] ?? '')] ??= strtolower((string)($s['host'] ?? ''));
        }
        $out = [];
        for ($d = 0; $d < $days; $d++) {
            $a     = strtotime(date('Y-m-d', $first + $d * 86400 + 43200) . ' 00:00:00');   // (a day of 23 or 25 hours too)
            $b     = strtotime(date('Y-m-d', $a + 129600) . ' 00:00:00');
            $edges = [];
            $secs  = 0;
            foreach ($spans as [$s, $e]) {
                [$s, $e] = [max($s, $a), min($e, $b)];
                if ($e > $s) {
                    $secs   += $e - $s;
                    $edges[] = [$s, 1];
                    $edges[] = [$e, -1];
                }
            }
            usort($edges, static fn(array $x, array $y): int => $x[0] <=> $y[0] ?: $x[1] <=> $y[1]);   // (an end before a start at once)
            [$at, $peak] = [0, 0];
            foreach ($edges as [, $step]) {
                $peak = max($peak, $at += $step);
            }
            $out[] = ['day' => date('Y-m-d', $a), 'sessions' => count(array_filter($started, static fn(int $t): bool => $t >= $a && $t < $b)),
                'hours' => round($secs / 3600, 2), 'peak' => $peak];
        }
        $hosts = [];
        foreach ($spans as [$s, $e, $sid, $conn]) {
            [$s, $e] = [max($s, $first), min($e, $now)];
            $key = ($hostOf[$sid] ?? '') !== '' ? $hostOf[$sid] : $conn;   // (begun before the days shown: its connection's name)
            if ($e <= $s || $key === '') {
                continue;
            }
            $hosts[$key] ??= ['host' => $key, 'hours' => 0.0, 'sessions' => 0];
            $hosts[$key]['hours']    += ($e - $s) / 3600;
            $hosts[$key]['sessions'] += 1;
        }
        usort($hosts, static fn(array $x, array $y): int => $y['hours'] <=> $x['hours'] ?: $y['sessions'] <=> $x['sessions']);
        $top = array_map(static fn(array $h): array => ['hours' => round($h['hours'], 2)] + $h, array_slice($hosts, 0, 10));
        return ['days' => $out, 'hosts' => $top];
    }

    /**
     * For a monitor: null while the gateway works (its data folder written to, its users and settings read), else what does
     * not ('data', 'store'). Nothing of the installation is told.
     */
    public static function problem(): ?string
    {
        clearstatcache();
        if (!is_dir(Config::dir()) || !is_writable(Config::dir()) || !is_writable(Config::dir('run'))) {
            return 'data';
        }
        if (Exposure::state()['state'] === 'exposed') {
            return 'data_exposed';
        }
        foreach (['users', 'settings'] as $store) {
            $f = Config::dir("$store.json");
            if (is_file($f) && !is_array(json_decode((string)@file_get_contents($f), true))) {
                return 'store';
            }
        }
        return null;
    }

    /** A php.ini size ("128M", "1G", "-1") in bytes; 0 for no limit. */
    public static function bytes(string $v): int
    {
        $v = trim($v);
        if ($v === '' || $v === '-1') {
            return 0;
        }
        $n = (int)$v;
        return match (strtolower(substr($v, -1))) {
            'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n,
        };
    }

    private static function space(string $fn): ?int
    {
        $v = function_exists($fn) ? @$fn(Config::dir()) : false;   // (a hosting may disable it)
        return $v === false ? null : (int)$v;
    }
}
