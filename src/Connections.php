<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk;

/**
 * Saved RDP connections of each user (data/connections/<uid>.json). Passwords are encrypted with the user's data key.
 * Connections of the administrator (data/managed_connections.json, ids starting with "m") are given to some users or to all
 * (`users`: uids or "*"); those users connect with them but cannot change or delete them. What each user keeps of one of
 * them (the password, the trusted certificate, the options of the connect dialog) is in the user's file under its id.
 */
final class Connections
{
    private const MANAGED  = 'managed_connections';
    private const OWN_KEYS = ['pass', 'cert', 'audio', 'mic', 'clipboard', 'drive', 'printer', 'ask', 'gfx', 'paste', 'bpp'];   // a user's, of a managed one
    private const TARGET   = ['host', 'port', 'user', 'domain', 'security'];   // changed: a saved password must not go to the new target

    /** Keyboard layouts offered in the UI (KLID). */
    public const KEYBOARDS = [0x040A, 0x0409, 0x0809, 0x041D, 0x040C, 0x0407, 0x0410, 0x0816, 0x0416, 0x0414,
                              0x0406, 0x040B, 0x0413, 0x0419, 0x080C, 0x0807, 0x100C, 0x0C0C, 0x080A];
    private const PERF     = ['wallpaper', 'themes', 'smoothing', 'animations', 'drag'];

    /**
     * Whether two connections go to the same target, field by field and strictly: compared loosely, numeric texts were
     * equal ("0123" and "123" as user names), so a saved password and the pinned certificate went on to the other account.
     */
    private static function sameTarget(array $a, array $b): bool
    {
        foreach (self::TARGET as $k) {
            if (($a[$k] ?? null) !== ($b[$k] ?? null)) {
                return false;
            }
        }
        return true;
    }

    /** Whether an id is that of a connection of the administrator. */
    public static function isManaged(string $id): bool
    {
        return str_starts_with($id, 'm');
    }

    /** Defaults for fields added in later versions. */
    private static function defaults(array $c): array
    {
        return ['gfx' => self::gfxMode($c['gfx'] ?? 'auto')] + $c + ['audio' => true, 'mic' => false, 'printer' => true, 'ask' => true, 'cert' => '', 'paste' => 'auto', 'pass' => null];
    }

    /** The user's connections: their own and those of the administrator given to them (with what the user keeps of them). */
    public static function all(string $uid): array
    {
        $own = Store::read('connections/' . $uid);
        $out = [];
        foreach ($own as $id => $c) {
            if (!self::isManaged((string)$id)) {
                $out[$id] = self::defaults($c);
            }
        }
        foreach (self::managed() as $id => $m) {
            if (self::assigned($m, $uid)) {
                $out[$id] = ['managed' => true] + self::defaults(array_intersect_key($own[$id] ?? [], array_flip(self::OWN_KEYS))
                    + array_diff_key($m, ['users' => 0]) + ['pass' => null, 'cert' => '']);
            }
        }
        return $out;
    }

    /** Connections of the administrator: id => connection with `users`. */
    public static function managed(): array
    {
        return Store::read(self::MANAGED);
    }

    private static function assigned(array $m, string $uid): bool
    {
        return in_array('*', $m['users'] ?? [], true) || in_array($uid, $m['users'] ?? [], true);
    }

    /** A user's own connections only (for the administrator). */
    public static function own(string $uid): array
    {
        return array_filter(self::all($uid), static fn(array $c): bool => empty($c['managed']));
    }

    /**
     * Graphics mode: 'auto' (graphics pipeline with H.264 for Windows 8.1 or later, bitmaps for the rest), 'on' or 'off'.
     * Earlier versions stored a boolean "H.264 graphics": true becomes 'on', false (the old default) 'auto'.
     */
    public static function gfxMode(mixed $v): string
    {
        return $v === true || $v === 'on' ? 'on' : ($v === 'off' ? 'off' : 'auto');
    }

    /**
     * Phone keyboard through the clipboard (Cliprdr::typeText): 'auto' (on for phones and tablets, decided by the browser),
     * 'on' or 'off'.
     */
    public static function pasteMode(mixed $v): string
    {
        return in_array($v, ['on', 'off'], true) ? $v : 'auto';
    }

    public static function get(string $uid, string $id): ?array
    {
        return self::all($uid)[$id] ?? null;
    }

    /**
     * Returns the stored connection or an error key ('invalid_input': a JSON list or object where text or a number goes).
     * A connection of the administrator keeps only the user's password here ('managed' for anything else).
     */
    public static function save(string $uid, array $in, ?string $dek): array|string
    {
        foreach (['id', 'name', 'group', 'host', 'port', 'user', 'domain', 'password', 'kbd', 'bpp'] as $k) {
            if (is_array($in[$k] ?? null)) {
                return 'invalid_input';
            }
        }
        $id       = (string)($in['id'] ?? '');
        $password = isset($in['password']) ? (string)$in['password'] : null;
        if ($password !== null && $dek === null) {
            return 'key';
        }
        if ($password !== null && $password !== '' && !Settings::get('save_passwords')) {
            return 'save_passwords_off';          // the administrator does not allow saving them (forgetting one is still allowed)
        }
        if (self::isManaged($id)) {
            if (!isset(self::all($uid)[$id])) {
                return 'not_found';
            }
            if ($password === null) {
                return 'managed';
            }
            Store::update('connections/' . $uid, static function (array &$d) use ($id, $password, $dek): void {
                $d[$id] = ['pass' => $password === '' ? null : Crypto::encrypt($dek, $password, 'conn:' . $id)] + ($d[$id] ?? []);
            });
            return self::all($uid)[$id];
        }
        $c = self::fields($in);
        if (is_string($c)) {
            return $c;
        }
        return Store::update('connections/' . $uid, static function (array &$d) use ($c, $id, $password, $dek): array|string {
            if ($id !== '' && !isset($d[$id])) {
                return 'not_found';
            }
            $id      = $id !== '' ? $id : Store::newId();
            $old     = $d[$id] ?? ['created' => date('c')];
            $c      += ['id' => $id, 'created' => $old['created'], 'updated' => date('c')];
            $c['pass'] = $old['pass'] ?? null;
            $c['cert'] = $old['cert'] ?? '';
            if ($password !== null) {
                $c['pass'] = $password === '' ? null : Crypto::encrypt($dek, $password, 'conn:' . $id);
            }
            $d[$id] = $c;
            return $c;
        });
    }

    /**
     * The RemoteApp program as RDP asks for it: a path or file name (with \ or /, or a program extension) as typed; any other
     * name is one the server published, which RDP writes with two bars in front ("||Excel"): the user types only the name.
     */
    public static function program(string $p): string
    {
        $p = trim($p);
        return $p === '' || str_starts_with($p, '||') || preg_match('~[\\\\/]|\.(exe|com|bat|cmd|msc|lnk|ps1|vbs)$~i', $p) ? $p : '||' . $p;
    }

    /** The settings of a connection, checked; or an error key ('host', 'port', 'invalid_input'). */
    private static function fields(array $in): array|string
    {
        foreach (['name', 'group', 'host', 'port', 'user', 'domain', 'kbd', 'bpp', 'security', 'resize', 'paste', 'gfx', 'app', 'app_args'] as $k) {
            if (is_array($in[$k] ?? null)) {
                return 'invalid_input';
            }
        }
        $host = strtolower(trim((string)($in['host'] ?? ''), " []"));
        $c    = [
            'name'      => trim(Text::cut((string)($in['name'] ?? ''), 80)),
            'group'     => trim(Text::cut((string)($in['group'] ?? ''), 60)),
            'host'      => $host,
            'port'      => Http::int($in['port'] ?? 3389),   // (a float past the int range warned on PHP 8.5)
            'user'      => Text::cut((string)($in['user'] ?? ''), 128),
            'domain'    => Text::cut((string)($in['domain'] ?? ''), 128),
            'kbd'       => in_array(Http::int($in['kbd'] ?? 0), self::KEYBOARDS, true) ? Http::int($in['kbd']) : 0x040A,
            'bpp'       => in_array(Http::int($in['bpp'] ?? 0), [15, 16, 24, 32], true) ? Http::int($in['bpp']) : 16,
            'security'  => in_array($in['security'] ?? '', ['auto', 'nla', 'tls'], true) ? $in['security'] : 'auto',
            'resize'    => ($in['resize'] ?? '') === 'fit' ? 'fit' : 'dynamic',
            'clipboard' => (bool)($in['clipboard'] ?? true),
            'paste'     => self::pasteMode($in['paste'] ?? 'auto'),
            'drive'     => (bool)($in['drive'] ?? true),
            'audio'     => (bool)($in['audio'] ?? true),
            'mic'       => (bool)($in['mic'] ?? false),           // the browser's microphone: off unless asked for
            'printer'   => (bool)($in['printer'] ?? true),
            'gfx'       => self::gfxMode($in['gfx'] ?? 'auto'),
            'ask'       => (bool)($in['ask'] ?? true),
            'perf'      => array_combine(self::PERF, array_map(static fn($k) => (bool)($in['perf'][$k] ?? false), self::PERF)),
            // RemoteApp: a program of the remote computer shown on its own (its windows, not the desktop): a path or a published
            // alias ("||Name"), with its arguments; '' = the whole desktop
            'app'       => self::program(trim(preg_replace('~[\x00-\x1f]~', '', Text::cut((string)($in['app'] ?? ''), 260)))),
            'app_args'  => trim(preg_replace('~[\x00-\x1f]~', '', Text::cut((string)($in['app_args'] ?? ''), 1000))),
        ];
        if ($c['name'] === '') {
            $c['name'] = $host;
        }
        if ($host === '' || strlen($host) > 253 || (@inet_pton($host) === false && !preg_match('~^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$~', $host))) {
            return 'host';
        }
        if ($c['port'] < 1 || $c['port'] > 65535) {
            return 'port';
        }
        return $c;
    }

    /**
     * The administrator creates or changes one of their connections, for users (uids, or "*": all, also those created later).
     * When its target changes (host, port, user, domain, security), what users saved for it goes: their passwords were
     * typed for the old one, and its certificate was the old server's.
     */
    public static function saveManaged(array $in, mixed $users): array|string
    {
        $c = self::fields($in);
        if (is_string($c)) {
            return $c;
        }
        if (!is_array($users) || !array_is_list($users)) {
            return 'invalid_input';
        }
        $known = array_column(Users::all(), 'id');
        $users = in_array('*', $users, true) ? ['*'] : array_values(array_unique(array_filter($users, static fn($u) => is_string($u) && in_array($u, $known, true))));
        $id    = (string)($in['id'] ?? '');
        if (is_array($in['id'] ?? null) || ($id !== '' && !self::isManaged($id))) {
            return 'invalid_input';
        }
        $old = null;
        $r   = Store::update(self::MANAGED, static function (array &$d) use ($c, &$id, $users, &$old): array|string {
            if ($id !== '' && !isset($d[$id])) {
                return 'not_found';
            }
            $id     = $id !== '' ? $id : 'm' . bin2hex(random_bytes(8));
            $old    = $d[$id] ?? null;
            $d[$id] = $c + ['id' => $id, 'users' => $users, 'created' => $old['created'] ?? date('c'), 'updated' => date('c')];
            return $d[$id];
        });
        if (is_array($r) && $old !== null && !self::sameTarget($old, $r)) {
            self::forgetEverywhere($id, false);
        }
        // users taken out of it: what each kept of it goes (their password and certificate stayed, and came back if they
        // were given it again)
        if (is_array($r) && $old !== null && $users !== ['*']) {
            $before = in_array('*', $old['users'] ?? [], true) ? $known : ($old['users'] ?? []);
            foreach (array_diff($before, $users) as $uid) {
                if (is_string($uid) && isset(Store::read('connections/' . $uid)[$id])) {
                    Store::update('connections/' . $uid, static function (array &$d) use ($id): void {
                        unset($d[$id]);
                    });
                }
            }
        }
        return $r;
    }

    /** The administrator deletes one of their connections: what each user kept of it goes too. */
    public static function deleteManaged(string $id): ?array
    {
        $old = Store::update(self::MANAGED, static function (array &$d) use ($id): ?array {
            $o = $d[$id] ?? null;
            unset($d[$id]);
            return $o;
        });
        if ($old !== null) {
            self::forgetEverywhere($id, true);
        }
        return $old;
    }

    /** What users keep of a managed connection: its saved password and certificate, or everything ($all). */
    private static function forgetEverywhere(string $id, bool $all): void
    {
        foreach (Users::all() as $u) {
            if (!isset(Store::read('connections/' . $u['id'])[$id])) {   // only the files that hold it are written again
                continue;
            }
            Store::update('connections/' . $u['id'], static function (array &$d) use ($id, $all): void {
                if ($all) {
                    unset($d[$id]);
                } elseif (isset($d[$id])) {
                    $d[$id] = ['pass' => null, 'cert' => ''] + $d[$id];
                }
            });
        }
    }

    /** A user gone: taken out of the connections of the administrator given to them. */
    public static function forgetUser(string $uid): void
    {
        Store::update(self::MANAGED, static function (array &$d) use ($uid): void {
            foreach ($d as &$m) {
                $m['users'] = array_values(array_filter($m['users'] ?? [], static fn($u) => $u !== $uid));
            }
        });
    }

    /**
     * The administrator changes a user's own connection. The user's saved password cannot be read or set (it is encrypted
     * with the user's key): it can be forgotten ($forget), and it goes by itself when the target changes, so that it never
     * reaches another server; the trusted certificate too.
     */
    public static function adminUpdate(string $uid, array $in, bool $forget): array|string
    {
        $c = self::fields($in);
        if (is_string($c)) {
            return $c;
        }
        $id = is_array($in['id'] ?? null) ? '' : (string)($in['id'] ?? '');
        return Store::update('connections/' . $uid, static function (array &$d) use ($c, $id, $forget): array|string {
            if ($id === '' || self::isManaged($id) || !isset($d[$id])) {
                return 'not_found';
            }
            $old    = $d[$id];
            $moved  = !self::sameTarget($old, $c);
            $d[$id] = $c + ['id' => $id, 'created' => $old['created'] ?? date('c'), 'updated' => date('c'),
                            'pass' => $forget || $moved ? null : $old['pass'] ?? null, 'cert' => $moved ? '' : $old['cert'] ?? ''];
            return $d[$id];
        });
    }

    public static function delete(string $uid, string $id): bool
    {
        if (self::isManaged($id)) {
            return false;                         // the administrator's: not the user's to delete
        }
        return Store::update('connections/' . $uid, static function (array &$d) use ($id): bool {
            $found = isset($d[$id]);
            unset($d[$id]);
            return $found;
        });
    }

    /**
     * RDP user name as typed: "DOMAIN\user" or "HOST\user" (domain or computer, ".\user" = local account of the server)
     * or "user@domain" (UPN, sent whole with an empty domain). Otherwise the connection's domain applies.
     * @return array{0:string,1:string} [user, domain]
     */
    public static function splitUser(string $login, string $domain): array
    {
        if (str_contains($login, '\\')) {
            [$domain, $login] = explode('\\', $login, 2);
        } elseif (str_contains($login, '@')) {
            $domain = '';
        }
        return [$login, $domain];
    }

    /** Saves the options chosen in the connect dialog as the connection defaults. */
    public static function setOptions(string $uid, string $id, array $o): bool
    {
        if (self::isManaged($id) && !isset(self::all($uid)[$id])) {
            return false;
        }
        return Store::update('connections/' . $uid, static function (array &$d) use ($id, $o): bool {
            if (!isset($d[$id]) && !self::isManaged($id)) {
                return false;
            }
            $d[$id] ??= [];                       // a managed one: the user's own options for it
            foreach (['audio', 'mic', 'clipboard', 'drive', 'printer', 'ask'] as $k) {
                if (isset($o[$k])) {
                    $d[$id][$k] = (bool)$o[$k];
                }
            }
            if (isset($o['gfx'])) {
                $d[$id]['gfx'] = self::gfxMode($o['gfx']);
            }
            if (isset($o['paste'])) {
                $d[$id]['paste'] = self::pasteMode($o['paste']);
            }
            if (in_array(Http::int($o['bpp'] ?? 0), [15, 16, 24, 32], true)) {
                $d[$id]['bpp'] = Http::int($o['bpp']);
            }
            return true;
        });
    }

    /** Stores the trusted server certificate fingerprint (trust on first use). */
    public static function setCert(string $uid, string $id, string $fingerprint): void
    {
        Store::update('connections/' . $uid, static function (array &$d) use ($id, $fingerprint): void {
            if (isset($d[$id]) || self::isManaged($id)) {
                $d[$id]['cert'] = $fingerprint;
            }
        });
    }

    /** Erases the saved passwords of a user's connections; returns how many there were. */
    public static function forgetPasswords(string $uid): int
    {
        return Store::update('connections/' . $uid, static function (array &$d): int {
            $n = 0;
            foreach ($d as &$c) {
                $n        += ($c['pass'] ?? null) !== null ? 1 : 0;
                $c['pass'] = null;
            }
            return $n;
        });
    }

    /** The administrator turned saving passwords off: the saved ones of every user are erased; returns how many. */
    public static function forgetAllPasswords(): int
    {
        return array_sum(array_map(static fn(array $u): int => self::forgetPasswords($u['id']), Users::all()));
    }

    public static function password(array $c, ?string $dek): ?string
    {
        return ($c['pass'] ?? null) === null || $dek === null ? null : Crypto::decrypt($dek, $c['pass'], 'conn:' . $c['id']);
    }

    public static function view(array $c): array
    {
        $c['has_pass'] = ($c['pass'] ?? null) !== null;
        unset($c['pass']);
        return $c;
    }
}
