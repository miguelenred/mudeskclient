<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk;

/** Runtime settings editable by the administrator. */
final class Settings
{
    public const DEFAULTS = [
        'clients'               => ['mode' => 'all', 'list' => []], // who may open the web app
        'targets'               => ['mode' => 'all', 'list' => []], // which RDP hosts may be reached ('all': not loopback, link-local or unspecified)
        'login_max_attempts'    => 5,
        'login_lock_minutes'    => 15,
        'login_global_max'      => 50,   // failures from all IPs within the lock time before unknown browsers are refused
        'session_idle_minutes'  => 30,
        'rdp_idle_minutes'      => 0,
        'max_sessions_per_user' => 3,
        'max_sessions_total'    => 20,   // RDP sessions of all users at once (each one holds a PHP worker); 0 = no limit
        'totp_required'         => false,
        'audit_retention_days'  => 90,   // 0 = keep everything
        'files_quota_mb'        => 500,
        'trace_all'             => false,  // diagnostic trace of every RDP session (data/trace/, kept 7 days)
        'reconnect_attempts'    => 10,     // automatic reconnections after the connection is lost (0 = none)
        'save_passwords'        => true,   // users may save the password of their RDP connections; turned off, those saved are erased
    ];

    private static ?array $cache = null;

    public static function all(): array
    {
        return self::$cache ??= array_replace(self::DEFAULTS, array_intersect_key(Store::read('settings'), self::DEFAULTS));
    }

    public static function get(string $key): mixed
    {
        return self::all()[$key];
    }

    /**
     * Validates and stores the given values; returns the new settings, or 'invalid_input' (nothing stored) for a JSON
     * list or object where text or a number goes.
     */
    public static function save(array $in): array|string
    {
        $s    = self::all();
        $ints = ['login_max_attempts' => [1, 100], 'login_lock_minutes' => [1, 1440], 'login_global_max' => [5, 100000], 'session_idle_minutes' => [5, 1440],
                 'rdp_idle_minutes' => [0, 1440], 'max_sessions_per_user' => [1, 50], 'max_sessions_total' => [0, 10000], 'audit_retention_days' => [0, 36500], 'reconnect_attempts' => [0, 50],
                 'files_quota_mb' => [0, 100000]];
        foreach (['clients', 'targets'] as $k) {
            $a = $in[$k] ?? [];
            if (!is_array($a) || is_array($a['mode'] ?? null) || array_filter((array)($a['list'] ?? []), 'is_array') !== []) {
                return 'invalid_input';
            }
        }
        if (array_filter(array_intersect_key($in, $ints), 'is_array') !== []) {
            return 'invalid_input';
        }
        foreach (['clients', 'targets'] as $k) {
            if (isset($in[$k])) {
                $list = [];
                foreach ((array)($in[$k]['list'] ?? []) as $rule) {
                    $rule = strtolower(trim((string)$rule));
                    if ($rule !== '' && Access::validRule($rule, $k === 'targets')) {
                        $list[] = $rule;
                    }
                }
                $s[$k] = ['mode' => ($in[$k]['mode'] ?? '') === 'list' ? 'list' : 'all', 'list' => array_values(array_unique($list))];
            }
        }
        foreach ($ints as $k => [$min, $max]) {
            if (isset($in[$k])) {
                $s[$k] = max($min, min($max, Http::int($in[$k])));   // (a float past the int range warned on PHP 8.5)
            }
        }
        foreach (['totp_required', 'trace_all', 'save_passwords'] as $k) {
            if (isset($in[$k])) {
                $s[$k] = (bool)$in[$k];
            }
        }
        Store::update('settings', static function (array &$d) use ($s): void {
            $d = $s;
        });
        return self::$cache = $s;
    }
}
