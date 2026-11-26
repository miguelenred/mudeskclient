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
        'record_days'           => 0,      // session recordings are kept this long (Recording); 0: until deleted by hand
        'record_users'          => false,  // users who are not administrators may record their own sessions (start and stop it)
        'share_control_idle_minutes' => 15, // a guest given control loses it after this long without using it (0: never)
        'users_create_conns'    => false,  // users who are not administrators may create connections (and import .rdp files)
        'kerberos_rc4'          => false,  // Kerberos may use RC4 (a Windows Server 2003 domain): off, see Kerberos::types()
        'timezone'              => '',     // of the users' sign-in hours (an IANA name); empty: PHP's own
        // signing in with a Windows account (WindowsLogin): the RDP computer of the domain that checks it ('' = off)
        'ad_host'               => '',
        'ad_port'               => 3389,
        'ad_domain'             => '',     // the domain's NetBIOS name (EMPRESA), or '.' for the computer's own accounts
        'ad_create'             => false,  // a Windows account without a MuDeskClient user gets one when it signs in
        'ad_cert'               => '',     // SHA-256 of that computer's certificate, pinned when set (WindowsLogin): never from a request
        // signing in with OpenID Connect (Oidc): the provider's issuer ('' = off), this application's client id and secret
        'oidc_issuer'           => '',
        'oidc_client_id'        => '',
        'oidc_secret'           => null,   // encrypted with the server key; never sent to the page
        'oidc_label'            => '',     // the sign-in button says "Sign in with" this ('' = the provider's host)
        'oidc_claim'            => 'email',   // the claim that is the MuDeskClient user name: email or preferred_username
        'oidc_domains'          => [],     // e-mail domains allowed ([] = any)
        'oidc_create'           => false,  // an account of the provider without a MuDeskClient user gets one when it signs in
    ];

    /** The time zone the sign-in hours are read in: the setting, or PHP's own (date.timezone) when it is empty. */
    /** What the administration shows: everything but the OpenID Connect secret, of which only whether there is one. */
    public static function view(): array
    {
        $s = self::all();
        $s['oidc_secret_set'] = $s['oidc_secret'] !== null;
        unset($s['oidc_secret']);
        return $s;
    }

    public static function timeZone(): string
    {
        $tz = (string)self::get('timezone');
        return $tz !== '' ? $tz : date_default_timezone_get();
    }

    private static ?array $cache = null;

    public static function all(): array
    {
        return self::$cache ??= array_replace(self::DEFAULTS, array_intersect_key(Store::read('settings'), self::DEFAULTS));
    }

    public static function get(string $key): mixed
    {
        return self::all()[$key];
    }

    /** Read again next time (a backup restored put its own in place). */
    public static function forget(): void
    {
        self::$cache = null;
    }

    /** The settings the last save() changed (their names), which restore() puts back. */
    private static array $saved = [];

    /**
     * Puts back, as they were in $old (as stored: the secret is not encrypted again), the settings the last save() changed,
     * e.g. a save undone; only those, as save() writes them (another administrator's change made meanwhile stays).
     */
    public static function restore(array $old): void
    {
        self::write(array_intersect_key($old, self::$saved));
    }

    /** A value only the gateway sets (the certificate pinned for Windows accounts). */
    public static function pin(string $key, string $value): void
    {
        self::write([$key => $value]);
    }

    /**
     * The settings given written onto those stored now, under their lock: the whole copy read at the start of the request
     * written back lost a change another administrator saved meanwhile (or the certificate pinned after a save).
     */
    private static function write(array $changes): void
    {
        self::$cache = Store::update('settings', static function (array &$d) use ($changes): array {
            return $d = array_replace(self::DEFAULTS, array_intersect_key($d, self::DEFAULTS), $changes);
        });
    }

    /** The settings that are a yes or a no. */
    private const YES_NO = ['ad_create', 'oidc_create', 'totp_required', 'trace_all', 'save_passwords', 'users_create_conns', 'kerberos_rc4', 'record_users'];

    /**
     * Validates and stores the given values; returns the new settings, or 'invalid_input' (nothing stored) for a JSON
     * list or object where text or a number goes. Only the values that change are written (see write()).
     */
    public static function save(array $in): array|string
    {
        $s    = self::all();
        $was  = $s;
        $ints = ['login_max_attempts' => [1, 100], 'login_lock_minutes' => [1, 1440], 'login_global_max' => [5, 100000], 'session_idle_minutes' => [5, 1440],
                 'rdp_idle_minutes' => [0, 1440], 'max_sessions_per_user' => [1, 50], 'max_sessions_total' => [0, 10000], 'audit_retention_days' => [0, 36500], 'reconnect_attempts' => [0, 50],
                 'files_quota_mb' => [0, 100000], 'record_days' => [0, 3650], 'share_control_idle_minutes' => [0, 1440]];
        foreach (['clients', 'targets'] as $k) {
            $a = $in[$k] ?? [];
            if (!is_array($a) || is_array($a['mode'] ?? null) || array_filter((array)($a['list'] ?? []), 'is_array') !== []) {
                return 'invalid_input';
            }
        }
        if (array_filter(array_intersect_key($in, $ints), 'is_array') !== [] || is_array($in['timezone'] ?? null)) {
            return 'invalid_input';
        }
        foreach (['ad_host', 'ad_domain', 'oidc_issuer', 'oidc_client_id', 'oidc_secret', 'oidc_label', 'oidc_claim'] as $k) {
            if (is_array($in[$k] ?? null)) {
                return 'invalid_input';
            }
        }
        foreach (self::YES_NO as $k) {                          // true or false (Http::yes): "false" or a list were taken as yes
            if (isset($in[$k]) && Http::yes($in[$k]) === null) {
                return 'invalid_input';
            }
        }
        // signing in with a company account: each value on its own, as the others (only those sent change)
        if (isset($in['ad_host'])) {
            $h = strtolower(trim((string)$in['ad_host'], " []"));
            if ($h !== '' && @inet_pton($h) === false && !preg_match('~^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$~', $h)) {
                return 'ad_host';
            }
            $s['ad_host'] = $h;
        }
        if (isset($in['oidc_issuer'])) {
            $iss = trim((string)$in['oidc_issuer']);           // as the provider writes it: some end in "/" (it is compared exactly)
            if ($iss !== '' && !Oidc::validIssuer($iss)) {
                return 'oidc_issuer';
            }
            if ($iss !== $s['oidc_issuer']) {
                $s['oidc_secret'] = null;                       // another provider (or none): the secret was the old one's
            }
            $s['oidc_issuer'] = $iss;
        }
        if (isset($in['ad_port'])) {
            $s['ad_port'] = max(1, min(65535, Http::int($in['ad_port'])));
        }
        foreach (['ad_domain' => 64, 'oidc_client_id' => 256, 'oidc_label' => 40] as $k => $max) {
            if (isset($in[$k])) {
                $s[$k] = Text::cut(trim((string)$in[$k]), $max);
            }
        }
        if (isset($in['oidc_claim'])) {
            $s['oidc_claim'] = $in['oidc_claim'] === 'preferred_username' ? 'preferred_username' : 'email';
        }
        if (isset($in['oidc_domains'])) {
            $s['oidc_domains'] = array_values(array_unique(array_filter(array_map(static fn($d) => strtolower(trim((string)$d, " @\t")),
                is_array($in['oidc_domains']) ? array_filter($in['oidc_domains'], 'is_scalar') : []), static fn($d) => (bool)preg_match('~^[a-z0-9.-]+$~', $d))));
        }
        if ($s['ad_host'] !== '' && $s['ad_domain'] === '') {
            return 'ad_domain';                                 // which accounts: the domain's, or "." for that computer's own
        }
        if (($in['oidc_secret'] ?? '') !== '' && $s['oidc_issuer'] !== '') {   // a new secret; an empty one keeps the stored one
            $s['oidc_secret'] = Crypto::encrypt(Crypto::appKey(), (string)$in['oidc_secret'], 'oidc');
        }
        if (isset($in['timezone'])) {
            $tz = trim((string)$in['timezone']);
            if ($tz !== '' && !in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
                return 'invalid_timezone';
            }
            $s['timezone'] = $tz;
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
        foreach (self::YES_NO as $k) {
            if (isset($in[$k])) {
                $s[$k] = (bool)Http::yes($in[$k]);
            }
        }
        self::$saved = array_filter($s, static fn(mixed $v, string $k): bool => $v !== $was[$k], ARRAY_FILTER_USE_BOTH);
        self::write(self::$saved);
        return self::all();
    }
}
