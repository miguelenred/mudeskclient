<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk;

/**
 * Saved RDP connections of each user (data/connections/<uid>.json). Passwords are encrypted with the user's data key.
 * Connections of the administrator (data/managed_connections.json, ids starting with "m") are given to some users or to all
 * (`users`: uids or "*"); those users connect with them but cannot change or delete them. What each user keeps of one of
 * them (the password, the trusted certificate, the options of the connect dialog) is in the user's file under its id.
 * Occasional connections (ids starting with "o") are never saved: kept in the web session only (see occasional()).
 */
final class Connections
{
    private const MANAGED  = 'managed_connections';
    private const OWN_KEYS = ['pass', 'cert', 'certs', 'audio', 'mic', 'clipboard', 'drive', 'printer', 'ask', 'gfx', 'paste', 'bpp', 'full', 'multi'];   // a user's, of a managed one
    private const TARGET   = ['host', 'port', 'user', 'domain', 'security', 'gw'];   // changed: a saved password must not go to the new target
    /**
     * What a session may share from or with this computer. An administrator's choice is a ceiling: in a connection of theirs a
     * user can turn each off, never on (before, a user's own value went before the administrator's, and the connect dialog's
     * too: a connection made without clipboard or folder so that no data left a computer had them back at a user's click); so
     * are a user's own connections' when the user may not make connections. See max().
     */
    private const REDIRECTS = ['audio', 'mic', 'clipboard', 'drive', 'printer'];
    /** Kept as they are, besides the target, by a user who may not make connections (see Settings users_create_conns) */
    private const LOCKED   = ['vm' => 0, 'wol' => 0, 'wol_ip' => 0, 'lb' => 0];   // (lb: another collection, another computer)
    /** The longest load balance info: the X.224 request that carries it has a one-byte length */
    private const LB_MAX   = 238;

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
        $a = self::defaults($a);                  // (saved before the gateway existed: no "gw", the same as none; taken for
        $b = self::defaults($b);                  // another computer, its password and certificate went, or its save was refused)
        foreach (self::TARGET as $k) {
            if (($a[$k] ?? null) !== ($b[$k] ?? null)) {
                return false;
            }
        }
        return true;
    }

    /**
     * In the remote user of a connection of the administrator, the MuDeskClient user name of whoever connects
     * (`DOMAIN\{username}`): one connection serves every user whose Windows name is the same as their MuDeskClient name.
     */
    public const USER_VAR = '{username}';

    /** Whether an id is that of a connection of the administrator. */
    public static function isManaged(string $id): bool
    {
        return str_starts_with($id, 'm');
    }

    /** Whether an id is that of an occasional connection (never one saved: their ids are hexadecimal). */
    public static function isOccasional(string $id): bool
    {
        return str_starts_with($id, 'o');
    }

    /** Occasional connections a web session keeps at most (the oldest go) */
    private const OCCASIONAL_MAX = 20;
    /** Certificates of the computers reached with occasional connections a user keeps (the latest; their gateways' apart) */
    private const HOSTS_MAX = 256, HOSTS_GW_MAX = 64;

    /**
     * A connection to connect once, without saving it (one the user could have made: see Auth::createsConns): checked as a
     * saved one, named after its computer, without a password (asked for when connecting, the connect dialog always shown)
     * and never recorded by itself (a computer the administrator records is recorded all the same: recordedTarget). Kept
     * in this web session only, with the user's id, gone when it ends. Returns it, or an error key.
     */
    public static function occasional(array $in, string $uid): array|string
    {
        $c = self::fields(['name' => '', 'group' => '', 'record' => false] + $in);
        if (is_string($c)) {
            return $c;
        }
        $c = self::defaults(['id' => 'o' . bin2hex(random_bytes(8)), 'uid' => $uid, 'occasional' => true, 'ask' => true] + $c);
        $list            = is_array($_SESSION['occasional'] ?? null) ? $_SESSION['occasional'] : [];
        $list[$c['id']]  = $c;
        $_SESSION['occasional'] = array_slice($list, -self::OCCASIONAL_MAX, null, true);
        return $c;
    }

    /** The user's occasional connections of this web session: id => connection. */
    public static function occasionals(string $uid): array
    {
        return array_filter(is_array($_SESSION['occasional'] ?? null) ? $_SESSION['occasional'] : [],
            static fn($c, $id): bool => is_array($c) && self::isOccasional((string)$id) && ($c['uid'] ?? null) === $uid, ARRAY_FILTER_USE_BOTH);
    }

    /** "rdp:<host>:<port>": an occasional connection's computer among the user's certificates (apart from certKey's names) */
    private static function hostKey(array $conn): string
    {
        return 'rdp:' . strtolower((string)$conn['host']) . ':' . (int)$conn['port'];
    }

    /**
     * The certificates trusted for a connection's computers, read again each time: [its own, the others' (certKey => its
     * fingerprint)]. An occasional connection's are the user's for the computers reached so (data/hosts/<uid>.json).
     */
    public static function pins(string $uid, array $conn): array
    {
        if (self::isOccasional((string)$conn['id'])) {
            $h = Store::read('hosts/' . $uid);
            return [(string)($h[self::hostKey($conn)] ?? ''), $h];
        }
        $c = self::get($uid, (string)$conn['id']) ?? [];
        return [(string)($c['cert'] ?? ''), is_array($c['certs'] ?? null) ? $c['certs'] : []];
    }

    /**
     * A certificate trusted for a connection's computer ($host null) or another one of it (a broker's target, its gateway:
     * see setCertFor). An occasional connection's go to the user's certificates of the computers reached so, the next
     * occasional connection to one checked as a saved one is (the latest HOSTS_MAX; of the gateways, apart, the latest
     * HOSTS_GW_MAX: kept as long as a saved connection's would be, without letting a user's file grow for ever).
     */
    public static function trust(string $uid, array $conn, string $fingerprint, ?string $host = null, bool $gateway = false): void
    {
        $id = (string)$conn['id'];
        if (!self::isOccasional($id)) {
            $host === null ? self::setCert($uid, $id, $fingerprint) : self::setCertFor($uid, $id, $host, $fingerprint, $gateway);
            return;
        }
        $key = $host === null ? self::hostKey($conn) : self::certKey($host, $gateway);
        Store::update('hosts/' . $uid, static function (array &$d) use ($key, $fingerprint): void {
            unset($d[$key]);                        // (trusted again: last, so that those kept are the latest)
            $d[$key] = $fingerprint;
            $gws     = array_filter($d, static fn($k): bool => str_starts_with((string)$k, 'gw:'), ARRAY_FILTER_USE_KEY);
            $d       = array_slice($gws, -self::HOSTS_GW_MAX, null, true) + array_slice(array_diff_key($d, $gws), -self::HOSTS_MAX, null, true);
        });
    }

    /**
     * The certificate an administrator fixed for a computer (its SHA-256, or SHA-1 as Windows shows it), for any connection to
     * it: by its name, or by its address ($ip, what the connection's name resolved to: one fixed for "pc" holds for 10.0.0.5
     * too, and the other way round). Null: none fixed (each user confirms it the first time). Each one's addresses are those
     * kept with it (fixCert(), seenAt()), never looked up here: before, every connection to a computer without one waited for
     * the DNS of each name fixed (9.5 s on Windows for a name that no longer resolved). One fixed by an earlier version, without
     * them, is looked up once (5 s at most for all such) and kept.
     */
    public static function fixedCert(string $host, int $port, string $ip = ''): ?string
    {
        $host  = strtolower(trim($host, ' []'));
        $ip    = self::ip($ip);
        $other = [];
        foreach (Store::read('certs') as $k => $e) {
            if (!preg_match('~^rdp:(.+):(\d+)$~D', (string)$k, $m) || (int)$m[2] !== $port || !is_string($e['fp'] ?? null)) {
                continue;
            }
            if ($m[1] === $host) {
                return $e['fp'];
            }
            $other[$m[1]] = $e;
        }
        $old = [];
        foreach ($ip !== '' ? $other : [] as $name => $e) {
            if (!is_array($e['ips'] ?? null)) {
                $old[] = (string)$name;
            } elseif (self::ip((string)$name) === $ip || in_array($ip, $e['ips'], true)) {
                return $e['fp'];
            }
        }
        $found = [];
        $until = microtime(true) + 5;
        foreach ($old as $name) {
            if (microtime(true) > $until) {
                break;
            }
            $found[$name] = array_values(array_unique(array_map(self::ip(...), Access::addresses($name))));
        }
        if ($found !== []) {
            Store::update('certs', static function (array &$d) use ($found, $port): void {
                foreach ($found as $name => $ips) {
                    if (is_array($d["rdp:$name:$port"] ?? null) && !isset($d["rdp:$name:$port"]['ips'])) {
                        $d["rdp:$name:$port"]['ips'] = $ips;
                    }
                }
            });
        }
        foreach ($found as $name => $ips) {
            if (in_array($ip, $ips, true)) {
                return $other[$name]['fp'];
            }
        }
        return null;
    }

    /**
     * A connection by its name that showed the certificate fixed for it: the address it went to kept with it, so that a
     * connection by that address finds it too when the computer's address changed (the last 4).
     */
    public static function seenAt(string $host, int $port, string $ip): void
    {
        $k  = 'rdp:' . strtolower(trim($host, ' []')) . ":$port";
        $ip = self::ip($ip);
        $e  = Store::read('certs')[$k] ?? null;
        if ($ip === '' || !is_array($e) || in_array($ip, is_array($e['ips'] ?? null) ? $e['ips'] : [], true)) {
            return;
        }
        Store::update('certs', static function (array &$d) use ($k, $ip): void {
            if (is_array($d[$k] ?? null)) {
                $d[$k]['ips'] = [...array_slice(array_values(array_diff(is_array($d[$k]['ips'] ?? null) ? $d[$k]['ips'] : [], [$ip])), -3), $ip];
            }
        });
    }

    /** An address as one text (IPv6 written one way); '' stays ''. */
    private static function ip(string $a): string
    {
        $b = @inet_pton(trim($a, ' []'));
        return $b !== false ? (string)inet_ntop($b) : strtolower($a);
    }

    /** Every certificate fixed: "rdp:host:port" => [fp, by, at]. */
    public static function fixedCerts(): array
    {
        return Store::read('certs');
    }

    /**
     * Fixes (or with '' forgets) the certificate of computer $host:$port for every connection to it, as administrator $by:
     * its SHA-256 or SHA-1 in hex, any case, with or without separators. Returns the fingerprint kept, or 'cert_fixed' when it
     * is neither.
     */
    public static function fixCert(string $host, int $port, string $fp, string $by, ?array $ips = null): string
    {
        $host = strtolower(trim($host, ' []'));
        $fp   = strtolower((string)preg_replace('~[\s:-]~', '', $fp));
        if (($fp !== '' && !preg_match('~^([0-9a-f]{40}|[0-9a-f]{64})$~D', $fp)) || $host === '' || $port < 1 || $port > 65535) {
            return 'cert_fixed';
        }
        // its addresses, kept with it (see fixedCert()): $ips, those the connection went to; else looked up now
        $ips = $fp === '' ? [] : array_values(array_unique(array_map(self::ip(...), array_map('strval', $ips ?? Access::addresses($host)))));
        Store::update('certs', static function (array &$d) use ($host, $port, $fp, $by, $ips): void {
            if ($fp === '') {
                unset($d["rdp:$host:$port"]);
            } else {
                $d["rdp:$host:$port"] = ['fp' => $fp, 'by' => $by, 'at' => date('c'), 'ips' => $ips];
            }
        });
        return $fp;
    }

    /** Defaults for fields added in later versions. */
    private static function defaults(array $c): array
    {
        return ['gfx' => self::gfxMode($c['gfx'] ?? 'auto')] + $c + ['audio' => true, 'mic' => false, 'printer' => true, 'ask' => true, 'full' => true, 'multi' => true, 'cert' => '', 'paste' => 'auto', 'pass' => null,
            'wol' => '', 'wol_ip' => '', 'vm' => '', 'vm_enhanced' => false, 'touch' => false, 'record' => false, 'lb' => '', 'gw' => '', 'certs' => []];
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
        $name = null;
        foreach (self::managed() as $id => $m) {
            if (self::assigned($m, $uid)) {
                if (str_contains((string)($m['user'] ?? ''), self::USER_VAR)) {   // the user's own name (read only when used)
                    $name    ??= (string)(Users::get($uid)['username'] ?? '');
                    $m['user'] = str_replace(self::USER_VAR, $name, (string)$m['user']);
                }
                $c     = ['managed' => true] + self::defaults(array_intersect_key($own[$id] ?? [], array_flip(self::OWN_KEYS))
                    + array_diff_key($m, ['users' => 0]) + ['pass' => null, 'cert' => '']);
                $admin = self::defaults(array_diff_key($m, ['users' => 0]));
                foreach (self::REDIRECTS as $k) {                  // (the administrator's a ceiling: see REDIRECTS)
                    $c['max'][$k] = (bool)($admin[$k] ?? true);
                    $c[$k]        = !empty($c[$k]) && $c['max'][$k];
                }
                $out[$id] = $c;
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
        $out = [];                              // (only the user's file: through all(), each user read users.json for {username})
        foreach (Store::read('connections/' . $uid) as $id => $c) {
            if (!self::isManaged((string)$id)) {
                $out[$id] = self::defaults($c);
            }
        }
        return $out;
    }

    /**
     * Whether $conn's computer is recorded for the user: a connection to the same computer and port that the administrator
     * records (another connection to it, made by the user, would be a way around the recording), by its name or by its
     * addresses (the same computer reached through its IP address or another name was not recorded).
     */
    public static function recordedTarget(string $uid, array $conn): bool
    {
        $mine = null;                            // its addresses: resolved once, only when some connection is recorded
        foreach (self::all($uid) as $c) {
            if (empty($c['record']) || (int)$c['port'] !== (int)$conn['port']) {
                continue;
            }
            if (strcasecmp((string)$c['host'], (string)$conn['host']) === 0) {
                return true;
            }
            $mine ??= self::ips((string)$conn['host']);
            if ($mine !== [] && array_intersect($mine, self::ips((string)$c['host'])) !== []) {
                return true;
            }
        }
        return false;
    }

    /** A host's addresses in binary, an IPv4 one inside IPv6 (::ffff:a.b.c.d) as the IPv4 one, to compare them. */
    private static function ips(string $host): array
    {
        $out = [];
        foreach (Access::addresses($host) as $ip) {
            $b = @inet_pton($ip);
            if ($b !== false) {
                $out[] = strlen($b) === 16 && str_starts_with($b, str_repeat("\0", 10) . "\xff\xff") ? substr($b, 12) : $b;
            }
        }
        return $out;
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

    /** A connection of the user: saved, of the administrator, or occasional (of this web session). */
    public static function get(string $uid, string $id): ?array
    {
        return self::isOccasional($id) ? self::occasionals($uid)[$id] ?? null : self::all($uid)[$id] ?? null;
    }

    /**
     * A user's own connection made or changed (or their password for one of the administrator's). Returns the stored
     * connection or an error key ('invalid_input': a JSON list or object where text or a number goes). A connection of the
     * administrator keeps only the user's password here ('managed' for anything else). With $create false (the administrator
     * does not let users create connections) no new one is made and none changes its target: 'conns_locked'. With $record
     * (the one saving it is an administrator) "Record the sessions" is taken from $in when it has it; else it stays.
     */
    public static function save(string $uid, array $in, ?string $dek, bool $create = true, bool $record = false): array|string
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
        $setRecord = $record && array_key_exists('record', $in);
        return Store::update('connections/' . $uid, static function (array &$d) use ($c, $id, $password, $dek, $create, $setRecord): array|string {
            if ($id !== '' && !isset($d[$id])) {
                return 'not_found';
            }
            if (!$create && ($id === '' || !self::sameTarget($d[$id], $c) || array_diff_assoc(array_intersect_key($c, self::LOCKED), array_intersect_key(self::defaults($d[$id]), self::LOCKED)) !== [])) {
                return 'conns_locked';                     // (also another virtual machine, or other Wake-on-LAN packets)
            }
            $id      = $id !== '' ? $id : Store::newId();
            $old     = $d[$id] ?? ['created' => date('c')];
            if (!$create) {                                // (a user who may not make connections: see REDIRECTS)
                foreach (self::REDIRECTS as $k) {
                    $c[$k] = !empty($c[$k]) && (bool)(self::defaults($old)[$k] ?? true);
                }
            }
            $c      += ['id' => $id, 'created' => $old['created'], 'updated' => date('c')];
            $moved     = isset($d[$id]) && !self::sameTarget($old, $c);   // another computer: its certificate and password were the old one's
            $c['pass'] = $moved ? null : $old['pass'] ?? null;
            $c['cert'] = $moved ? '' : $old['cert'] ?? '';
            $c['certs'] = $moved ? [] : $old['certs'] ?? [];   // (its gateway's and its broker's computers': lost on every save)
            // the administrator's to set (also in their own editor): a user cannot start or stop it
            $c['record'] = $setRecord ? (bool)$c['record'] : (bool)($old['record'] ?? false);
            $c['full']   = (bool)($old['full'] ?? true);     // "Start in full screen": set in the connect dialog only
            $c['multi']  = (bool)($old['multi'] ?? true);    // "Use all my screens": the same
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
        foreach (['name', 'group', 'host', 'port', 'user', 'domain', 'kbd', 'bpp', 'security', 'resize', 'paste', 'gfx', 'app', 'app_args', 'wol', 'wol_ip', 'vm', 'lb', 'gw'] as $k) {
            if (is_array($in[$k] ?? null)) {
                return 'invalid_input';
            }
        }
        // the yes/no options true or false (Http::yes): "false", "no" or a list were taken as yes
        $perf = $in['perf'] ?? [];
        foreach (['clipboard', 'drive', 'audio', 'mic', 'touch', 'printer', 'ask', 'vm_enhanced', 'record'] as $k) {
            if (isset($in[$k]) && Http::yes($in[$k]) === null) {
                return 'invalid_input';
            }
        }
        if (!is_array($perf) || array_filter(array_intersect_key($perf, array_flip(self::PERF)), static fn($v): bool => $v !== null && Http::yes($v) === null) !== []) {
            return 'invalid_input';
        }
        $host = strtolower(trim((string)($in['host'] ?? ''), " []"));
        $c    = [
            'name'      => trim(preg_replace('~[\x00-\x1f\x7f]~', '', Text::cut((string)($in['name'] ?? ''), 80))),
            'group'     => trim(preg_replace('~[\x00-\x1f\x7f]~', '', Text::cut((string)($in['group'] ?? ''), 60))),
            'host'      => $host,
            'port'      => Http::int($in['port'] ?? 3389),   // (a float past the int range warned on PHP 8.5)
            'user'      => preg_replace('~[\x00-\x1f\x7f]~', '', Text::cut((string)($in['user'] ?? ''), 128)),
            'domain'    => preg_replace('~[\x00-\x1f\x7f]~', '', Text::cut((string)($in['domain'] ?? ''), 128)),
            'kbd'       => in_array(Http::int($in['kbd'] ?? 0), self::KEYBOARDS, true) ? Http::int($in['kbd']) : 0x040A,
            'bpp'       => in_array(Http::int($in['bpp'] ?? 0), [15, 16, 24, 32], true) ? Http::int($in['bpp']) : 16,
            // NLA only unless chosen (before, Automatic: on a first connection someone in between could answer that the computer
            // has only TLS or the old security and be given the password, if the user trusted its fingerprint)
            'security'  => in_array($in['security'] ?? '', ['auto', 'nla', 'tls'], true) ? $in['security'] : 'nla',
            'resize'    => ($in['resize'] ?? '') === 'fit' ? 'fit' : 'dynamic',
            'clipboard' => (bool)($in['clipboard'] ?? true),
            'paste'     => self::pasteMode($in['paste'] ?? 'auto'),
            'drive'     => (bool)($in['drive'] ?? true),
            'audio'     => (bool)($in['audio'] ?? true),
            'mic'       => (bool)($in['mic'] ?? false),           // the browser's microphone: off unless asked for
            'touch'     => (bool)($in['touch'] ?? false),         // multitouch to Windows (RDPEI): off unless asked for
            'printer'   => (bool)($in['printer'] ?? true),
            'gfx'       => self::gfxMode($in['gfx'] ?? 'auto'),
            'ask'       => (bool)($in['ask'] ?? true),
            'perf'      => array_combine(self::PERF, array_map(static fn($k) => (bool)($in['perf'][$k] ?? false), self::PERF)),
            // RemoteApp: a program of the remote computer shown on its own (its windows, not the desktop): a path or a published
            // alias ("||Name"), with its arguments; '' = the whole desktop
            'app'       => self::program(trim(preg_replace('~[\x00-\x1f]~', '', Text::cut((string)($in['app'] ?? ''), 260)))),
            'app_args'  => trim(preg_replace('~[\x00-\x1f]~', '', Text::cut((string)($in['app_args'] ?? ''), 1000))),
            // Wake-on-LAN: the computer's MAC address ('' = never sent) and where the packet goes ('' = the whole network)
            'wol'       => trim((string)($in['wol'] ?? '')),
            'wol_ip'    => trim((string)($in['wol_ip'] ?? '')),
            // Hyper-V: the id of a virtual machine of the host (its console), and whether as an enhanced session
            'vm'        => strtoupper(trim((string)($in['vm'] ?? ''), " {}")),
            'vm_enhanced' => (bool)($in['vm_enhanced'] ?? false),
            // its sessions recorded (Recording): set by the administrator only (a user's own save keeps what it was)
            'record'    => (bool)($in['record'] ?? false),
            // a connection broker's load balance info (the routing token sent first, as "loadbalanceinfo" of a .rdp file)
            'lb'        => trim(preg_replace('~[\x00-\x1f\x7f]~', '', Text::cut((string)($in['lb'] ?? ''), 512))),
            // a Remote Desktop Gateway (host or host:port, 443 by default) the connection goes through
            'gw'        => strtolower(trim((string)($in['gw'] ?? ''))),
        ];
        if ($c['name'] === '') {
            $c['name'] = $host;
        }
        if ($host === '' || strlen($host) > 253 || (@inet_pton($host) === false && !preg_match('~^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$~D', $host))) {
            return 'host';
        }
        if ($c['port'] < 1 || $c['port'] > 65535) {
            return 'port';
        }
        if ($c['wol'] !== '') {
            $c['wol'] = WakeOnLan::mac($c['wol']) ?? '';
            if ($c['wol'] === '') {
                return 'wol_mac';
            }
        }
        if ($c['wol_ip'] !== '' && WakeOnLan::target($c['wol_ip']) === null) {
            return 'wol_ip';
        }
        if ($c['gw'] !== '') {                    // kept in one form ("rdg.x:443", "rdg.x:00443" and "rdg.x" are one gateway)
            $g = self::gateway($c['gw']);
            if ($g === null) {
                return 'gw';
            }
            $c['gw'] = (str_contains($g[0], ':') ? "[$g[0]]" : $g[0]) . ($g[1] !== 443 ? ':' . $g[1] : '');
        }
        if (strlen($c['lb']) > self::LB_MAX) {
            return 'lb';
        }
        if ($c['vm'] !== '') {
            if (!preg_match('/^[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12}$/', $c['vm'])) {
                return 'vm_id';
            }
            if ($c['app'] !== '') {
                return 'vm_app';           // a console shows the machine's screen: no RemoteApp program
            }
            if ($c['lb'] !== '') {
                return 'vm_lb';            // it goes to the Hyper-V host (its port 2179), never through a connection broker
            }
            if ($c['port'] === 3389) {
                $c['port'] = self::VM_PORT;
            }
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
        if (is_array($in['id'] ?? null)) {
            return 'invalid_input';                // (read as text first, a list warned "Array to string conversion")
        }
        $id    = (string)($in['id'] ?? '');
        if ($id !== '' && !self::isManaged($id)) {
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

    /** Columns of a CSV import of connections of the administrator: the first row names them; any may be left out but host. */
    public const CSV_COLUMNS = ['name', 'group', 'host', 'port', 'user', 'domain', 'users', 'security', 'gfx', 'bpp', 'app', 'app_args',
                                'gw', 'lb', 'audio', 'mic', 'clipboard', 'drive', 'printer'];
    public const CSV_MAX     = 2000;     // rows in one file
    public const VM_PORT     = 2179;     // Hyper-V's console port (VMConnect), taken when the port is left at 3389

    /**
     * Connections of the administrator from a CSV text: comma, semicolon or tab separated (as a spreadsheet saves it), the
     * first row naming the columns (CSV_COLUMNS, `host` at least). `users`: MuDeskClient user names separated by spaces or
     * "|", `*` for all users (also later ones); empty, nobody yet. The options are yes/no (1/0, sí/no) and the rest as in the
     * editor; an empty cell is the editor's default. Every valid row is added in one write, as a new connection; returns
     * created (names), errors ([row, key]: refused rows, numbered as in a spreadsheet, the first row being 1), unknown
     * ([row, names]: users that do not exist) and ignored (columns not recognised), or an error key: csv_empty, csv_no_host,
     * too_many_rows (nothing added).
     */
    public static function importCsv(string $csv): array|string
    {
        $csv   = (string)preg_replace('/^\xEF\xBB\xBF/', '', $csv);    // the BOM a spreadsheet may write
        $csv   = (string)preg_replace('/\r(?!\n)/', "\n", $csv);           // old Mac line ends (Excel for Mac's "CSV (Macintosh)")
        $sep   = preg_match('/^sep=(.)\r?\n/i', $csv, $m) ? $m[1] : null;   // Excel's separator line: said, then left out
        $csv   = $sep === null ? $csv : (string)substr($csv, strlen($m[0]));
        $head  = (string)strtok(ltrim($csv, "\r\n"), "\r\n");
        $count = [',' => substr_count($head, ','), ';' => substr_count($head, ';'), "\t" => substr_count($head, "\t")];
        $delim = $sep ?? (array_search(max($count), $count, true) ?: ',');
        $h     = fopen('php://temp', 'r+');
        fwrite($h, $csv);
        rewind($h);
        $rows = [];
        $row  = 0;
        while (($r = fgetcsv($h, null, $delim, '"', '')) !== false) {
            $row++;
            if ($r !== [null]) {
                $rows[$row] = $r;
            }
        }
        fclose($h);
        if ($rows === []) {
            return 'csv_empty';
        }
        $first = array_key_first($rows);                                // (array_shift would number the rows again)
        $cols  = array_map(static fn($c) => strtolower(trim((string)$c)), $rows[$first]);
        unset($rows[$first]);
        if (!in_array('host', $cols, true)) {
            return 'csv_no_host';
        }
        if (count($rows) > self::CSV_MAX) {
            return 'too_many_rows';
        }
        $ids     = array_change_key_case(array_column(Users::all(), 'id', 'username'));
        $bool    = static fn(string $v): ?bool => in_array($v, ['1', 'yes', 'y', 'true', 'si', 'sí', 'x', 'on', 'verdadero'], true) ? true
                 : (in_array($v, ['0', 'no', 'n', 'false', 'off', 'falso'], true) ? false : null);
        $out     = ['created' => [], 'errors' => [], 'unknown' => [], 'ignored' => array_values(array_diff(array_filter($cols, 'strlen'), self::CSV_COLUMNS))];
        $new     = [];
        foreach ($rows as $n => $r) {
            $in = [];
            foreach ($cols as $i => $k) {
                if (in_array($k, self::CSV_COLUMNS, true) && isset($r[$i])) {
                    $in[$k] = trim((string)$r[$i]);
                }
            }
            if (implode('', $in) === '') {
                continue;                                               // an empty row
            }
            $c = ['perf' => ['smoothing' => true]];                     // the editor's defaults for a new connection
            foreach (['name', 'group', 'host', 'port', 'user', 'domain', 'bpp', 'app', 'app_args', 'gw', 'lb'] as $k) {   // (gw: the
                if (($in[$k] ?? '') !== '') {                                                                               // gateway, lb: the broker's info)
                    $c[$k] = $in[$k];
                }
            }
            foreach (['security', 'gfx'] as $k) {
                if (($in[$k] ?? '') !== '') {
                    $c[$k] = strtolower($in[$k]);
                }
            }
            foreach (['audio', 'mic', 'clipboard', 'drive', 'printer'] as $k) {
                if (($in[$k] ?? '') !== '') {
                    $c[$k] = $bool(str_replace('Í', 'í', strtolower($in[$k])));   // (no mbstring needed: SÍ, Sí)
                    if ($c[$k] === null) {
                        $out['errors'][] = [$n, 'csv_bool'];
                        continue 2;
                    }
                }
            }
            $f = self::fields($c);
            if (is_string($f)) {
                $out['errors'][] = [$n, $f];
                continue;
            }
            $names = preg_split('/[\s|,]+/', $in['users'] ?? '', -1, PREG_SPLIT_NO_EMPTY) ?: [];   // ("," too: "ana,luis" in a ";" file)
            $users = in_array('*', $names, true) ? ['*'] : [];
            $miss  = [];
            foreach ($users === ['*'] ? [] : $names as $u) {
                isset($ids[strtolower($u)]) ? $users[] = $ids[strtolower($u)] : $miss[] = $u;   // case as Users::byName (ASCII)
            }
            if ($miss !== []) {
                $out['unknown'][] = [$n, implode(' ', $miss)];
            }
            $new[]            = $f + ['users' => array_values(array_unique($users))];
            $out['created'][] = $f['name'];
        }
        if ($new !== []) {
            Store::update(self::MANAGED, static function (array &$d) use ($new): void {
                foreach ($new as $c) {
                    do {
                        $id = 'm' . bin2hex(random_bytes(8));
                    } while (isset($d[$id]));
                    $d[$id] = $c + ['id' => $id, 'created' => date('c'), 'updated' => date('c')];
                }
            });
        }
        return $out;
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
                    $d[$id] = ['pass' => null, 'cert' => '', 'certs' => []] + $d[$id];
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
                            'pass' => $forget || $moved ? null : $old['pass'] ?? null, 'cert' => $moved ? '' : $old['cert'] ?? '',
                            'certs' => $moved ? [] : $old['certs'] ?? [],
                            // the user's own choices of the connect dialog (the form has no such boxes): kept
                            'full' => (bool)($old['full'] ?? true), 'multi' => (bool)($old['multi'] ?? true)];
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

    /**
     * The most each redirection may be in a session of $c (see REDIRECTS): an administrator's connection, its own ceiling; a
     * user's own when the user may not make connections ($creates false), what it has. [] when the user chooses freely.
     */
    public static function max(array $c, bool $creates): array
    {
        if (is_array($c['max'] ?? null)) {
            return $c['max'];
        }
        return $creates || !empty($c['occasional']) ? [] : array_map('boolval', array_intersect_key(self::defaults($c), array_flip(self::REDIRECTS)));
    }

    /** Saves the options chosen in the connect dialog as the connection defaults (the redirections within max()). */
    public static function setOptions(string $uid, string $id, array $o, bool $creates = true): bool
    {
        $c = self::all($uid)[$id] ?? null;
        if ($c === null) {
            return false;
        }
        $max = self::max($c, $creates);
        return Store::update('connections/' . $uid, static function (array &$d) use ($id, $o, $max): bool {
            if (!isset($d[$id]) && !self::isManaged($id)) {
                return false;
            }
            $d[$id] ??= [];                       // a managed one: the user's own options for it
            foreach (['audio', 'mic', 'clipboard', 'drive', 'printer', 'ask', 'full', 'multi'] as $k) {
                if (isset($o[$k])) {
                    $d[$id][$k] = (bool)$o[$k] && ($max[$k] ?? true);
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

    /** A gateway "host" or "host:port" (IPv6 in brackets) as [host, port], 443 by default; null when it is not one. */
    public static function gateway(string $gw): ?array
    {
        if (!preg_match('~^(\[[0-9a-f:.]+\]|[a-z0-9]([a-z0-9.-]*[a-z0-9])?)(?::(\d{1,5}))?$~iD', trim($gw), $m)) {
            return null;
        }
        $port = isset($m[3]) ? (int)$m[3] : 443;
        $host = trim($m[1], '[]');
        return $port >= 1 && $port <= 65535 && strlen($host) <= 253 && (str_starts_with($m[1], '[') ? @inet_pton($host) !== false : true) ? [$host, $port] : null;
    }

    /** The key of another computer's certificate in 'certs': its name, or for the connection's gateway "gw:" and its name. */
    public static function certKey(string $host, bool $gateway = false): string
    {
        return ($gateway ? 'gw:' : '') . strtolower($host);
    }

    /**
     * The certificate of another computer of a connection: a broker's redirection target, or its gateway. A broker's farm
     * keeps the 32 latest; the gateway's is kept apart and never dropped (dropped, it would be trusted again on its next use,
     * and a computer of the farm named as the gateway would have replaced it).
     */
    public static function setCertFor(string $uid, string $id, string $host, string $fingerprint, bool $gateway = false): void
    {
        Store::update('connections/' . $uid, static function (array &$d) use ($id, $host, $fingerprint, $gateway): void {
            if (isset($d[$id]) || self::isManaged($id)) {
                $certs = is_array($d[$id]['certs'] ?? null) ? $d[$id]['certs'] : [];
                $key   = self::certKey($host, $gateway);
                unset($certs[$key]);                // (trusted again: last, so that the 32 kept are the latest)
                $certs[$key] = $fingerprint;
                $gws   = array_filter($certs, static fn($k): bool => str_starts_with((string)$k, 'gw:'), ARRAY_FILTER_USE_KEY);
                $d[$id]['certs'] = $gws + array_slice(array_diff_key($certs, $gws), -32, null, true);
            }
        });
    }

    /** Stores the trusted server certificate fingerprint (trust on first use); '' forgets it, with its gateway's and broker's. */
    public static function setCert(string $uid, string $id, string $fingerprint): void
    {
        Store::update('connections/' . $uid, static function (array &$d) use ($id, $fingerprint): void {
            if (isset($d[$id]) || self::isManaged($id)) {
                $d[$id]['cert'] = $fingerprint;
                if ($fingerprint === '') {
                    $d[$id]['certs'] = [];        // forgotten: also its gateway's and its broker's computers'
                }
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
