<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk;

/** Web session, login with optional TOTP, brute-force protection and the user's data key. */
final class Auth
{
    private const KEY_COOKIE    = 'MUDESKK';
    private const DEVICE_COOKIE = 'MUDESKD';   // known browser of a user: exempt from the per-user and global locks until locked itself
    private const LOGIN_TIME    = 1.0;         // seconds every login answer takes, whatever happened
    private const DUMMY_HASH    = '$2y$12$xneHAF2M2DBmmlSeEKfUjOVlmFcTNgE1HU9xSaDCGQ9wOGw/ZRCG6';   // same cost: unknown users take as long
    private static ?array $user = null;
    private static int $deadline = PHP_INT_MAX;    // hrtime: within the fixed time of a sign-in answer (timed())
    /** Why this request found its session closed (idle, max_duration, other_browser, password_changed...): told to the page. */
    private static ?string $closed = null;
    /** Longest a session lasts, in seconds, whatever its activity. */
    private const MAX_SESSION = 43200;

    /**
     * Starts the PHP session and closes it when it has expired. $touch false: the request is not activity (the page asking
     * how long is left must not keep an idle session open).
     */
    public static function start(bool $touch = true): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $idle = Settings::get('session_idle_minutes') * 60;
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string)max($idle, 1440));
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
        session_save_path(Config::dir('sessions'));
        session_name('MUDESKSID');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => Http::isHttps(), 'httponly' => true, 'samesite' => 'Strict']);
        session_start();
        $now = time();
        if (isset($_SESSION['uid'])) {
            $why = match (true) {
                !hash_equals($_SESSION['bind'] ?? '', self::binding()) => 'other_browser',   // stolen session cookie?
                $now - ($_SESSION['seen'] ?? 0) > $idle                => 'idle',
                $now - ($_SESSION['since'] ?? 0) > self::MAX_SESSION   => 'max_duration',
                default                                                => null,
            };
            if ($why !== null) {
                Audit::log('session_closed', ['reason' => $why], $_SESSION['username'] ?? null, $why !== 'other_browser');
                Sessions::killWeb((string)($_SESSION['wid'] ?? ''));   // its remote sessions end too
                self::clear();
                self::$closed = $why;
            }
        }
        if ($touch || !isset($_SESSION['seen'])) {
            $_SESSION['seen'] = $now;
        }
        $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
    }

    /** Releases the session lock (long requests must call this early). */
    public static function release(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    public static function user(): ?array
    {
        if (self::$user === null && isset($_SESSION['uid'])) {
            $u = Users::get($_SESSION['uid']);
            if ($u === null || !$u['enabled'] || ($u['sv'] ?? 1) !== ($_SESSION['sv'] ?? 1)) {   // password changed elsewhere
                self::$closed = $u === null ? 'user_deleted' : (!$u['enabled'] ? 'user_disabled' : 'password_changed');
                Audit::log('session_closed', ['reason' => self::$closed], $_SESSION['username'] ?? null);
                Sessions::killWeb((string)($_SESSION['wid'] ?? ''));
                self::clear();
                return null;
            }
            self::$user = $u;
        }
        return self::$user;
    }

    /** Forgets the cached user so the next user() call reads the stored record. */
    public static function reload(): void
    {
        self::$user = null;
    }

    public static function username(): ?string
    {
        return self::$user['username'] ?? $_SESSION['username'] ?? null;
    }

    /** Requires a logged user; blocks everything but password change while a change is pending. */
    public static function require(bool $admin = false, bool $allowPending = false): array
    {
        $u = self::user() ?? Http::fail('auth', 401, self::$closed === null ? [] : ['reason' => self::$closed]);
        if (!$allowPending && ($u['must_change'] || (Settings::get('totp_required') && !Users::hasSecondFactor($u)))) {
            Http::fail('pending', 403);
        }
        if ($admin && $u['role'] !== 'admin') {
            Http::fail('forbidden', 403);
        }
        return $u;
    }

    /** Why this request found its session closed (idle, max_duration...), or null. */
    public static function closedReason(): ?string
    {
        return self::$closed;
    }

    /** Seconds until this session closes on its own: its idle time-out or its 12 hours, whichever comes first. */
    public static function left(): int
    {
        $now = time();
        return max(0, min(Settings::get('session_idle_minutes') * 60 - ($now - ($_SESSION['seen'] ?? $now)),
            self::MAX_SESSION - ($now - ($_SESSION['since'] ?? $now))));
    }

    /** Seconds the session can still last whatever its activity (its 12 hours). */
    public static function maxLeft(): int
    {
        return max(0, self::MAX_SESSION - (time() - ($_SESSION['since'] ?? time())));
    }

    public static function csrf(): string
    {
        return $_SESSION['csrf'];
    }

    public static function checkCsrf(): void
    {
        if (self::$closed !== null) {                 // closed by this request (its token went with it): the page is told why
            Http::fail('auth', 401, ['reason' => self::$closed]);
        }
        if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) {
            (isset($_SESSION['uid']) || Audit::anonymous()) && Audit::log('access_denied', ['reason' => 'csrf', 'uri' => Http::uri()], null, false);
            Http::$auditFailures = false;
            Http::fail('csrf', 403);
        }
    }

    // ---- brute-force protection ----
    // Failures are counted per IP, per user name, per known browser and globally. A locked IP is refused. A locked user
    // name, or too many failures overall (an attack from many IPs), only lets in browsers where that user has already
    // logged in (device cookies): each of them has its own count and lock, and once locked it gets the usual locks, so
    // a stolen device cookie gives no more attempts than the per-user lock.

    /** Browser fingerprint the session is bound to: the user agent (Accept-Language is not used: Web Workers may send another one). */
    private static function binding(): string
    {
        return hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    }

    /** Lock keys of an attempt: the IP, the user name and, from a known browser of $u, that browser. */
    private static function keys(string $name, ?array $u): array
    {
        $dev = self::device($u);
        return ['ip:' . Http::clientIp(), 'u:' . strtolower($name)] + ($dev === null ? [] : [2 => 'd:' . $dev]);
    }

    /** Which lock refuses this attempt: 'ip', 'user', 'global' or null. */
    private static function lockedFor(array $keys): ?string
    {
        $rl  = Store::read('ratelimit');
        $now = time();
        if (($rl[$keys[0]]['until'] ?? 0) > $now) {
            return 'ip';
        }
        if (isset($keys[2]) && ($rl[$keys[2]]['until'] ?? 0) <= $now) {   // a known browser of the user, not locked itself
            return null;
        }
        if (($rl[$keys[1]]['until'] ?? 0) > $now) {
            return 'user';
        }
        $global = array_sum(array_map(static fn($r) => $r['first'] > $now - Settings::get('login_lock_minutes') * 60 ? $r['n'] : 0,
                                      array_filter($rl, static fn($k) => str_starts_with((string)$k, 'ip:'), ARRAY_FILTER_USE_KEY)));
        return $global >= Settings::get('login_global_max') ? 'global' : null;
    }

    private static function failure(array $keys): void
    {
        $max  = Settings::get('login_max_attempts');
        $lock = Settings::get('login_lock_minutes') * 60;
        Store::update('ratelimit', static function (array &$rl) use ($keys, $max, $lock): void {
            $now = time();
            $rl  = array_filter($rl, static fn($r) => $r['until'] > $now || $r['first'] > $now - $lock);
            foreach ($keys as $k) {
                $r = $rl[$k] ?? ['n' => 0, 'first' => $now, 'until' => 0];
                if ($r['first'] < $now - $lock) {
                    $r = ['n' => 0, 'first' => $now, 'until' => 0];
                }
                if (++$r['n'] >= $max) {
                    $r['until'] = $now + $lock;
                }
                $rl[$k] = $r;
            }
        });
    }

    private static function success(array $keys): void
    {
        Store::update('ratelimit', static function (array &$rl) use ($keys): void {
            foreach ($keys as $k) {
                if (!str_starts_with($k, 'ip:')) {   // the address keeps its count: a sign-in of one's own must not clear
                    unset($rl[$k]);                   // the failures tried against other names (nor the global count)
                }
            }
        });
    }

    /**
     * The password asked again in a signed-in session (a passkey or an authenticator app added, the password changed or
     * the second factor removed): after 5 wrong ones this session closes. Before, whoever had the session open could guess
     * it without limit. (Not the sign-in locks: an attack on the user name would keep the owner from changing it.)
     */
    public static function confirmPassword(array $u, string $password): bool
    {
        if (password_verify($password, $u['pass'])) {
            return true;
        }
        Audit::log('reauth_fail', ['tries' => ($_SESSION['reauth_fails'] ?? 0) + 1], null, false);
        if (($_SESSION['reauth_fails'] = ($_SESSION['reauth_fails'] ?? 0) + 1) >= 5) {
            Sessions::killWeb(self::wid());
            self::clear();
            self::$closed = 'reauth';
            Http::fail('auth', 401, ['reason' => 'reauth']);
        }
        return false;
    }

    /** Id of this browser among the known browsers of $u (device cookie: user id, random id, MAC with the server key) or null. */
    private static function device(?array $u): ?string
    {
        $parts = explode('.', (string)($_COOKIE[self::DEVICE_COOKIE] ?? ''));
        return $u !== null && count($parts) === 3 && $parts[0] === $u['id'] && isset(self::devices($u)[$parts[1]])
            && hash_equals(hash_hmac('sha256', $parts[0] . '.' . $parts[1], Crypto::appKey()), $parts[2]) ? $parts[1] : null;
    }

    /**
     * Known browsers of $u as id => [used, added, ua, ip]: times of the last sign-in and of the first, browser and address
     * of the first (earlier versions kept a list of ids, oldest first, then id => last sign-in).
     */
    private static function devices(array $u): array
    {
        $d = $u['devices'] ?? [];
        return array_map(static fn($v) => is_array($v) ? $v : ['used' => (int)$v], array_is_list($d) ? array_flip($d) : $d);
    }

    /**
     * Known browsers of $u for their owner, the one used last first (this browser first among those used in the same second:
     * two sign-ins in one second came in any order); this browser is marked.
     */
    public static function browsers(array $u): array
    {
        $cur = self::device($u);
        $d   = self::devices($u);
        uksort($d, static fn($a, $b) => [$d[$b]['used'], (string)$b === $cur] <=> [$d[$a]['used'], (string)$a === $cur]);
        $out = [];
        foreach ($d as $id => $x) {
            $out[] = ['id' => (string)$id, 'name' => $x['ua'] ?? '', 'ip' => $x['ip'] ?? '', 'added' => isset($x['added']) ? date('c', $x['added']) : null,
                      'used' => $x['used'] > 0 ? date('c', $x['used']) : null, 'current' => (string)$id === $cur];
        }
        return $out;
    }

    /** Forgets the known browser $id of $u, or every one but this browser ($id null): their cookies stop working. */
    public static function forget(array $u, ?string $id): array
    {
        $keep = $id === null ? self::device($u) : null;
        $gone = [];
        Users::update($u['id'], static function (array &$x) use ($id, $keep, &$gone): void {
            $d    = self::devices($x);
            $gone = array_filter($d, static fn($k) => $id === null ? (string)$k !== $keep : (string)$k === $id, ARRAY_FILTER_USE_KEY);
            $x['devices'] = array_diff_key($d, $gone);
        });
        return $gone;
    }

    // ---- first run: the administrator needs the one-time code of data/setup-code.php ----

    private const SETUP_FILE = 'setup-code.php';

    /** Creates the code file if needed (only while there are no users). */
    public static function setupCode(): void
    {
        $file = Config::dir(self::SETUP_FILE);
        if (!is_file($file)) {
            $code = implode('-', str_split(strtoupper(bin2hex(random_bytes(10))), 5));
            file_put_contents($file, "<?php exit; ?>\n" . $code . "\n", LOCK_EX);
            @chmod($file, 0600);
        }
    }

    /** Checks the code; a wrong one counts as a login failure of the IP. */
    public static function setupAllowed(string $code): bool
    {
        $keys = ['ip:' . Http::clientIp(), 'u:#setup'];
        $file = Config::dir(self::SETUP_FILE);
        $want = is_file($file) ? trim(substr((string)file_get_contents($file), 15)) : '';
        if (self::lockedFor($keys) !== null || $want === '' || !hash_equals($want, strtoupper(trim($code)))) {
            self::failure($keys);
            Audit::log('setup_fail', ['reason' => $want === '' ? 'no_code_file' : 'bad_code'], null, false);
            return false;
        }
        return true;
    }

    /** The code works once: deleted when the administrator exists. */
    public static function setupDone(): void
    {
        @unlink(Config::dir(self::SETUP_FILE));
    }

    // ---- login ----

    /**
     * Returns 'ok', 'second' (TOTP code or passkey needed) or 'invalid'. Every answer takes LOGIN_TIME and wrong
     * user, wrong password, disabled account and lock look the same, so nothing can be learnt from them.
     */
    public static function login(string $name, string $password, string $code = ''): string
    {
        return self::timed(static fn() => self::attempt($name, $password, $code));
    }

    /**
     * Passkey sign-in: second step after the password, or alone (passwordless) when the passkey holds the data key
     * (PRF). $in: id, clientData, authData, signature, userHandle, prf (base64url). Returns 'ok', 'invalid' or
     * 'passkey_password' (this passkey can only be a second step).
     */
    public static function passkeyLogin(array $in): string
    {
        return self::timed(static fn() => self::passkeyAttempt($in));
    }

    private static function timed(callable $fn): string
    {
        $start          = hrtime(true);
        self::$deadline = $start + (int)(self::LOGIN_TIME * 0.8e9);   // reading the audit after a sign-in stops here
        $r              = $fn();
        self::$deadline = PHP_INT_MAX;
        if ($r === 'locked_ip') {      // answered at once: the lock of the caller's own IP says nothing about users
            return 'invalid';          // and waiting would only keep a PHP process busy
        }
        // until the fixed time is over: usleep() may wake early (in PHP's web server on Windows, 0.72 s asked for gave 0.70 s
        // and answers came after 0.78 s), and an answer sooner than the others says something
        $end = $start + (int)(self::LOGIN_TIME * 1e9);
        while (($left = $end - hrtime(true)) > 0) {
            usleep((int)ceil($left / 1e3));
        }
        return $r;
    }

    private static function passkeyAttempt(array $in): string
    {
        $pending = ($_SESSION['pending']['exp'] ?? 0) > time() ? $_SESSION['pending'] : null;
        $uid     = $pending['uid'] ?? WebAuthn::unb64u((string)($in['userHandle'] ?? ''));
        $u       = $uid === '' ? null : Users::get($uid);
        $keys    = self::keys($u['username'] ?? '#passkey', $u);
        $pk      = null;
        foreach ($u['passkeys'] ?? [] as $i => $p) {
            if (is_string($p['id'] ?? null) && ($in['id'] ?? '') !== '' && hash_equals($p['id'], (string)$in['id'])) {
                $pk = $i;
            }
        }
        if (($lock = self::lockedFor($keys)) !== null) {
            Audit::anonymous() && Audit::log('login_fail', ['method' => 'passkey', 'reason' => 'locked_' . $lock], $u['username'] ?? null, false);
            return $lock === 'ip' ? 'locked_ip' : 'invalid';
        }
        $count = $pk === null || !$u['enabled'] ? null : WebAuthn::verify($u['passkeys'][$pk], $pending ? 'login2:' . $u['id'] : 'login',
            WebAuthn::unb64u((string)($in['clientData'] ?? '')), WebAuthn::unb64u((string)($in['authData'] ?? '')),
            WebAuthn::unb64u((string)($in['signature'] ?? '')));
        if ($count === null) {
            self::failure($keys);
            Audit::log('login_fail', ['method' => 'passkey', 'reason' => $u === null ? 'unknown_user' : ($pk === null ? 'unknown_passkey'
                : (!$u['enabled'] ? 'disabled' : 'bad_signature'))], $u['username'] ?? null, false);
            return 'invalid';
        }
        if (!$pending) {                                   // passwordless: the data key comes from the passkey
            $prf = WebAuthn::unb64u((string)($in['prf'] ?? ''));
            $dek = $u['passkeys'][$pk]['dek'] === null || strlen($prf) !== 32 ? null
                 : Crypto::decrypt(WebAuthn::prfKey($prf, $u['passkeys'][$pk]['id']), $u['passkeys'][$pk]['dek'], 'passkey:' . $u['id']);
            if ($dek === null) {
                Audit::log('login_fail', ['method' => 'passkey', 'reason' => 'passkey_second_step_only'], $u['username'], false);
                return 'passkey_password';
            }
            session_regenerate_id(true);
            self::storeKey($dek);
        }
        // found again by its id under the lock: by its place, a passkey deleted meanwhile left a broken entry (no id) that
        // made every later passkey sign-in of the user fail
        $pkId = $u['passkeys'][$pk]['id'];
        Users::update($u['id'], static function (array &$x) use ($pkId, $count): void {
            foreach ($x['passkeys'] ?? [] as $j => $p) {
                if (($p['id'] ?? null) === $pkId) {
                    $x['passkeys'][$j]['count'] = $count;
                    $x['passkeys'][$j]['used']  = date('c');
                }
            }
        });
        return self::finish(Users::get($u['id']), $keys, 'passkey');
    }

    private static function attempt(string $name, string $password, string $code): string
    {
        $name    = strlen($name) > 256 ? '' : $name;   // longer than any user name: never kept as a lock key or in the audit
        // second step (TOTP code) only while the password step is valid; the lock keys follow the same user as the check
        $pending = ($_SESSION['pending']['exp'] ?? 0) > time() && $code !== '' ? $_SESSION['pending'] : null;
        $u       = $pending !== null ? Users::get($pending['uid']) : Users::byName($name);
        $keys    = self::keys($pending['name'] ?? $name, $u);
        if (($lock = self::lockedFor($keys)) !== null) {
            Audit::anonymous() && Audit::log('login_fail', ['method' => $pending !== null ? 'totp' : 'password', 'reason' => 'locked_' . $lock], $pending['name'] ?? $name, false);
            return $lock === 'ip' ? 'locked_ip' : 'invalid';
        }
        if ($pending === null) {
            $hash = $u['pass'] ?? self::DUMMY_HASH;
            if (!password_verify($password, $hash) || $u === null || !$u['enabled']) {
                self::failure($keys);
                Audit::log('login_fail', ['method' => 'password', 'reason' => $u === null ? 'unknown_user'
                    : ($u['enabled'] ? 'bad_password' : 'disabled')], $name, false);
                return 'invalid';
            }
            $dek = Crypto::unwrapKey($password, $u['dek'], $u['id']);
            if ($dek === null && (strlen($password) > 72 || str_contains($password, "\0"))) {
                // bcrypt only checks 72 bytes (up to a NUL): another password that starts the same, not the user's
                self::failure($keys);
                Audit::log('login_fail', ['method' => 'password', 'reason' => 'bad_password'], $name, false);
                return 'invalid';
            }
            if ($dek === null) {
                // should never happen (the key is wrapped with this same password: only a damaged users file); the account
                // stays usable with a new key, but what the old one opened is lost, so it is said, never done in silence
                Audit::log('saved_passwords_lost', ['reason' => 'key_unreadable'], $u['username'], false);
                error_log('MuDeskClient: the data key of user ' . $u['username'] . ' could not be opened with the right password; a new one was made and the saved RDP passwords were removed');
                Users::setPassword($u['id'], $password, null, $u['must_change']);
                $u   = Users::get($u['id']);
                $dek = Crypto::unwrapKey($password, $u['dek'], $u['id']);
            }
            if (password_needs_rehash($u['pass'], PASSWORD_BCRYPT, Users::HASH)) {
                Users::update($u['id'], static fn(array &$x) => $x['pass'] = Users::hash($password));
            }
            session_regenerate_id(true);
            self::storeKey($dek);
            if (!empty($u['totp']['enabled']) || !empty($u['passkeys'])) {
                $_SESSION['pending'] = ['uid' => $u['id'], 'name' => $u['username'], 'exp' => time() + 300];
                Audit::log('login_step', ['method' => 'password', 'next' => 'second_factor'], $u['username'], true);
                return 'second';
            }
            return self::finish($u, $keys, 'password');
        }
        if ($u === null || empty($u['totp']['enabled'])) {
            return 'invalid';
        }
        $secret = Crypto::decrypt(Crypto::appKey(), $u['totp']['secret'], 'totp:' . $u['id']);
        $step   = $secret === null ? null : Totp::verify($secret, $code, (int)($u['totp']['last'] ?? 0));
        // the step taken under the lock of the users: two sign-ins with the same code at once both passed the check above
        $taken = false;
        if ($step !== null) {
            Users::update($u['id'], static function (array &$x) use ($step, &$taken): void {
                if ($taken = $step > (int)($x['totp']['last'] ?? 0)) {
                    $x['totp']['last'] = $step;
                }
            });
        }
        if (!$taken) {
            // the right code of a step already used (e.g. the one that has just turned TOTP on, or one used a moment ago
            // with the phone's clock ahead): refused and said apart, so the user waits for the next code; not counted as
            // a failure (it is the right code: counted, a few of them locked the user's own address for 15 minutes)
            $used = $step !== null || ($secret !== null && Totp::verify($secret, $code) !== null);
            if (!$used) {
                self::failure($keys);
            }
            Audit::log('login_fail', ['method' => 'totp', 'reason' => $used ? 'code_used' : 'bad_code'], $u['username'], false);
            return $used ? 'code_used' : 'invalid';
        }
        return self::finish($u, $keys, 'totp');
    }

    /** Opens the session of $u after a complete sign-in and remembers the browser (the 20 used most recently). */
    private static function finish(array $u, array $keys, string $how): string
    {
        unset($_SESSION['pending']);
        session_regenerate_id(true);
        $_SESSION['uid']      = $u['id'];
        $_SESSION['username'] = $u['username'];
        $_SESSION['since']    = time();
        $_SESSION['csrf']     = bin2hex(random_bytes(16));
        $_SESSION['bind']     = self::binding();
        $_SESSION['sv']       = $u['sv'] ?? 1;
        $_SESSION['wid']      = bin2hex(random_bytes(16));   // this sign-in: its remote sessions end with it
        $_SESSION['previous'] = self::previous($u);
        $known = self::device($u);
        $dev   = $known ?? bin2hex(random_bytes(12));
        $ip    = Http::clientIp();
        self::$user = Users::update($u['id'], static function (array &$x) use ($dev, $ip): void {
            $x['last_login'] = date('c');
            $d       = self::devices($x);
            $d[$dev] = ['used' => time()] + ($d[$dev] ?? ['added' => time(), 'ua' => Http::browser((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 'ip' => $ip]);
            uasort($d, static fn($a, $b) => $a['used'] <=> $b['used']);
            $x['devices'] = array_slice($d, -20, null, true);
        });
        self::success($keys);
        Audit::log('login_ok', ['method' => $how], null, true);
        if ($known === null) {
            setcookie(self::DEVICE_COOKIE, $u['id'] . '.' . $dev . '.' . hash_hmac('sha256', $u['id'] . '.' . $dev, Crypto::appKey()),
                ['expires' => time() + 31536000, 'path' => '/', 'secure' => Http::isHttps(), 'httponly' => true, 'samesite' => 'Strict']);
            Audit::log('device_add', ['known_browsers' => count(self::$user['devices'] ?? [])], null, true);
        }
        return 'ok';
    }

    /**
     * Shown once after signing in: the previous sign-in (time, address) and the failed attempts with this user name since
     * then, read from the audit log back to the day of the previous sign-in (at most 90 days), within the fixed time of
     * the answer, or 50 ms if the password checks took it all: a failure writes nothing but its log line.
     */
    private static function previous(array $u): array
    {
        $p = Audit::sinceLogin($u['username'], max((int)strtotime((string)($u['last_login'] ?? $u['created'] ?? '')), time() - 90 * 86400),
            max(self::$deadline, hrtime(true) + 50000000));
        $p['login'] ??= $u['last_login'];
        return $p;
    }

    /** The notice of the sign-in that opened this session, once. */
    public static function takePrevious(): ?array
    {
        $p = $_SESSION['previous'] ?? null;
        unset($_SESSION['previous']);
        return $p;
    }

    /** Signs out: the remote sessions opened from this sign-in end too (before, they went on, input and all). */
    public static function logout(): void
    {
        if (isset($_SESSION['uid'])) {
            Audit::log('logout');
            Sessions::killWeb((string)($_SESSION['wid'] ?? ''));
        }
        self::clear();
        header('Clear-Site-Data: "cache"');      // images of the shared folder kept by the browser go too
    }

    /** This sign-in's id (its remote sessions are registered with it). */
    public static function wid(): string
    {
        return (string)($_SESSION['wid'] ?? '');
    }

    private static function clear(): void
    {
        $_SESSION    = ['csrf' => bin2hex(random_bytes(16))];
        self::$user  = null;
        session_regenerate_id(true);
        setcookie(self::KEY_COOKIE, '', ['expires' => 1, 'path' => '/', 'secure' => Http::isHttps(), 'httponly' => true, 'samesite' => 'Strict']);
    }

    // ---- data key: half in the session file, half in a cookie ----

    /** After the user's own password change: this session stays open, the others close. */
    public static function keepSession(): void
    {
        $_SESSION['sv'] = Users::get($_SESSION['uid'])['sv'] ?? 1;
        self::$user     = null;
        session_regenerate_id(true);            // a copied cookie of this session no longer works either
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
        Sessions::killUser($_SESSION['uid'], self::wid());   // remote sessions of the other sign-ins end with them
    }

    public static function storeKey(string $dek): void
    {
        $k = random_bytes(32);
        setcookie(self::KEY_COOKIE, rtrim(strtr(base64_encode($k), '+/', '-_'), '='),
            ['expires' => 0, 'path' => '/', 'secure' => Http::isHttps(), 'httponly' => true, 'samesite' => 'Strict']);
        $_COOKIE[self::KEY_COOKIE] = rtrim(strtr(base64_encode($k), '+/', '-_'), '=');
        $_SESSION['dek']           = Crypto::encrypt($k, $dek, 'session');
    }

    public static function dek(): ?string
    {
        $k = base64_decode(strtr((string)($_COOKIE[self::KEY_COOKIE] ?? ''), '-_', '+/'), true);
        return $k === false || strlen($k) !== 32 || !isset($_SESSION['dek']) ? null : Crypto::decrypt($k, $_SESSION['dek'], 'session');
    }
}
