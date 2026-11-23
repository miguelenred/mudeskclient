<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk;

/** User accounts stored in data/users.json. */
final class Users
{
    public const MIN_PASSWORD = 12;
    public const HASH         = ['cost' => 12];   // bcrypt (password_hash): ≈0.27 s on the test machine

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, self::HASH);
    }

    public static function all(): array
    {
        return Store::read('users');
    }

    public static function get(string $id): ?array
    {
        return self::all()[$id] ?? null;
    }

    public static function byName(string $name): ?array
    {
        foreach (self::all() as $u) {
            if (strcasecmp($u['username'], $name) === 0) {
                return $u;
            }
        }
        return null;
    }

    public static function validName(string $name): bool
    {
        return (bool)preg_match('/^[\p{L}\p{N}._@-]{2,64}$/u', $name);
    }

    /**
     * At least 12 characters (72 bytes at most and no NUL: bcrypt ignores what follows), 4 different ones at least, not
     * containing the user name and not in the list of common passwords of that length (src/common-passwords.txt, lower
     * case). Characters, not bytes: three equal emojis are 12 bytes with 4 different ones. Valid UTF-8 only.
     */
    public static function validPassword(string $p, string $name = ''): bool
    {
        $lc    = strtolower($p);
        $chars = preg_split('//u', $p, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false || count($chars) < self::MIN_PASSWORD || strlen($p) > 72 || str_contains($p, "\0")
            || count(array_unique($chars)) < 4 || (strlen($name) >= 3 && str_contains($lc, strtolower($name)))) {
            return false;
        }
        static $list = null;
        $list ??= (string)file_get_contents(__DIR__ . '/common-passwords.txt');   // read once per process (435 KB)
        return !str_contains("\n" . $list, "\n" . $lc . "\n");
    }

    public static function create(string $name, #[\SensitiveParameter] string $password, string $role, bool $mustChange, array $extra = []): ?array
    {
        $id = Store::newId();
        // an external user (Windows, OpenID Connect): its data key wrapped with the Windows password, but no local password
        // signs it in; all of it in one write (made in two, a request dying between them left a local account)
        $u  = $extra + [
            'id' => $id, 'username' => $name, 'role' => in_array($role, ['admin', 'auditor'], true) ? $role : 'user',
            'pass' => self::hash(isset($extra['source']) ? bin2hex(random_bytes(24)) : $password), 'enabled' => true, 'must_change' => $mustChange,
            'lang' => '', 'max_sessions' => 0, 'created' => date('c'), 'last_login' => null,
            'dek' => Crypto::wrapKey($password, random_bytes(32), $id), 'totp' => null, 'sv' => 1, 'devices' => [],
        ];
        // the name checked again under the lock: two administrators creating the same one at once (the hashes above take
        // about 0.7 s) made two accounts, and the second one could never sign in
        $free = Store::update('users', static function (array &$d) use ($u): bool {
            foreach ($d as $x) {
                if (strcasecmp($x['username'], $u['username']) === 0) {
                    return false;
                }
            }
            $d[$u['id']] = $u;
            return true;
        });
        return $free ? $u : null;
    }

    /** Enabled administrators in $users. */
    private static function admins(array $users): int
    {
        return count(array_filter($users, static fn(array $u): bool => $u['role'] === 'admin' && $u['enabled']));
    }

    /**
     * Like update(), refused ('last_admin', nothing changed) when no enabled administrator would be left. Counted under the
     * store's lock: two administrators demoting each other at once both did it, and changing a disabled administrator
     * was refused while a single one was enabled.
     */
    public static function updateKeepingAdmin(string $id, callable $fn): array|string|null
    {
        return Store::update('users', static function (array &$d) use ($id, $fn): array|string|null {
            if (!isset($d[$id])) {
                return null;
            }
            $x = $d[$id];
            $fn($x);
            if (self::admins([$id => $x] + $d) === 0 && self::admins($d) > 0) {
                return 'last_admin';
            }
            $d[$id] = $x;
            return $x;
        });
    }

    /**
     * A user made by a sign-in from outside: 'ad' (a Windows account, checked each time against the domain: its data key
     * is wrapped with that password) or 'oidc' (an OpenID Connect account: no password of its own, so no saved RDP
     * passwords). Neither has a local password that signs in. Null when the name is taken meanwhile.
     */
    public static function createExternal(string $name, string $source, #[\SensitiveParameter] ?string $password = null, array $more = []): ?array
    {
        return self::validName($name) ? self::create($name, $password ?? bin2hex(random_bytes(24)), 'user', false, ['source' => $source] + $more) : null;
    }

    /** Applies $fn to the stored record and returns the result. */
    public static function update(string $id, callable $fn): ?array
    {
        return Store::update('users', static function (array &$d) use ($id, $fn): ?array {
            if (!isset($d[$id])) {
                return null;
            }
            $fn($d[$id]);
            return $d[$id];
        });
    }

    /** Deletes a user and what it keeps; false (nothing deleted) when it is the last enabled administrator (checked under the lock). */
    public static function delete(string $id): bool
    {
        $done = Store::update('users', static function (array &$d) use ($id): bool {
            $rest = $d;
            unset($rest[$id]);
            if (isset($d[$id]) && self::admins($rest) === 0 && self::admins($d) > 0) {
                return false;
            }
            $d = $rest;
            return true;
        });
        if ($done) {
            Store::delete('connections/' . $id);
            Store::delete('hosts/' . $id);          // (the certificates of the computers reached with occasional connections)
            Files::removeAll($id);
        }
        return $done;
    }

    /**
     * Sets a new password. Without the old data key the saved RDP passwords become unreadable and are removed, and the
     * passkeys stop signing in without a password (they wrap the old key).
     */
    public static function setPassword(string $id, string $password, ?string $dek, bool $mustChange): void
    {
        $lost = $dek === null;
        $dek ??= random_bytes(32);
        self::update($id, static function (array &$u) use ($password, $dek, $id, $mustChange, $lost): void {
            $u['pass']        = self::hash($password);
            $u['dek']         = Crypto::wrapKey($password, $dek, $id);
            $u['must_change'] = $mustChange;
            $u['sv']          = ($u['sv'] ?? 1) + 1;   // closes the user's other sessions
            $u['devices']     = [];                    // and forgets the known browsers (id => last sign-in)
            if ($lost) {
                $u['passkeys'] = array_map(static fn(array $p): array => ['dek' => null] + $p, $u['passkeys'] ?? []);
            }
        });
        if ($lost) {
            Connections::forgetPasswords($id);
        }
    }

    public static function hasSecondFactor(array $u): bool
    {
        return !empty($u['totp']['enabled']) || !empty($u['passkeys']);
    }

    /** Public view without secrets. */
    /**
     * The sign-in hours of a user as the administrator sent them: null (any time), the hours (days 1 = Monday to 7, from
     * and to as HH:MM), or false when they are not valid (no day, a day out of range, a time that is not HH:MM).
     */
    public static function hours(mixed $in): array|null|false
    {
        if ($in === null || $in === false || $in === '') {
            return null;
        }
        if (!is_array($in) || !is_array($in['days'] ?? null) || !is_string($in['from'] ?? null) || !is_string($in['to'] ?? null)) {
            return false;
        }
        $days = [];
        foreach ($in['days'] as $d) {
            if (!is_int($d) && !(is_string($d) && preg_match('/^[0-9]+$/D', $d))) {
                return false;
            }
            if ((int)$d < 1 || (int)$d > 7) {
                return false;
            }
            $days[(int)$d] = (int)$d;
        }
        ksort($days);
        $time = '/^([01]\d|2[0-3]):[0-5]\d$/D';
        if ($days === [] || !preg_match($time, $in['from']) || !preg_match($time, $in['to'])) {
            return false;
        }
        return ['days' => array_values($days), 'from' => $in['from'], 'to' => $in['to']];
    }

    /**
     * Whether $u may sign in and open sessions at $now (a Unix time), in the time zone of the settings. Administrators
     * always may (their own hours could shut everybody out). From later than to crosses midnight: 22:00–06:00 on Friday
     * goes on until Saturday 06:00; the same from and to is the whole of each day.
     */
    public static function withinHours(array $u, ?int $now = null): bool
    {
        $h = $u['hours'] ?? null;
        if (!is_array($h) || ($u['role'] ?? '') === 'admin') {
            return true;
        }
        $t    = (new \DateTimeImmutable('@' . ($now ?? time())))->setTimezone(new \DateTimeZone(Settings::timeZone()));
        $min  = (int)$t->format('G') * 60 + (int)$t->format('i');
        $day  = (int)$t->format('N');
        $from = (int)substr($h['from'], 0, 2) * 60 + (int)substr($h['from'], 3, 2);
        $to   = (int)substr($h['to'], 0, 2) * 60 + (int)substr($h['to'], 3, 2);
        $on   = static fn(int $d): bool => in_array($d, $h['days'], true);
        if ($from === $to) {
            return $on($day);
        }
        if ($from < $to) {
            return $on($day) && $min >= $from && $min < $to;
        }
        return $min >= $from ? $on($day) : ($min < $to && $on($day === 1 ? 7 : $day - 1));
    }

    public static function view(array $u): array
    {
        return [
            'id' => $u['id'], 'username' => $u['username'], 'role' => $u['role'], 'enabled' => $u['enabled'], 'hours' => $u['hours'] ?? null,
            'source' => $u['source'] ?? null,   // 'ad', 'oidc' or null (a local user)
            'must_change' => $u['must_change'], 'lang' => $u['lang'], 'max_sessions' => $u['max_sessions'],
            'created' => $u['created'], 'last_login' => $u['last_login'], 'totp' => !empty($u['totp']['enabled']),
            'passkeys' => array_map(static fn($p) => ['id' => $p['id'], 'name' => $p['name'], 'created' => $p['created'],
                                                      'used' => $p['used'], 'passwordless' => $p['dek'] !== null], $u['passkeys'] ?? []),
        ];
    }
}
