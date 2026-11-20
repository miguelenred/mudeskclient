<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);
// Login security against a running copy with an EMPTY data folder and 'trusted_proxies' => ['127.0.0.1']:
//   php tests/auth_e2e.php http://127.0.0.1:8200 /path/to/that/data
// Checks: one-time setup code, password rules, constant answer time, per-IP / per-user / global locks with the
// known-browser exemption, session bound to the browser, other sessions closed on password change, and the audit
// of all of it (reasons, old/new values, no passwords, search, export, tamper detection); malformed requests and
// errors answered with 4xx codes, the page language, second factor required for everybody, a TOTP code used twice.

[, $base, $data] = $argv + [null, null, null];
if (!$base || !$data) {
    exit("usage: php tests/auth_e2e.php baseUrl dataDir\n");
}

$fail = 0;
function check(string $name, bool $ok, string $info = ''): void
{
    global $fail;
    $fail += $ok ? 0 : 1;
    echo ($ok ? 'OK   ' : 'FAIL ') . $name . ($info !== '' ? " ($info)" : '') . "\n";
}

/** A browser: its own cookies, user agent and IP (X-Forwarded-For, trusted by the test configuration). */
final class Browser
{
    public array $cookies = [];
    public string $csrf = '';
    public string $lang = 'es';

    public function __construct(public string $ip, public string $agent = 'TestBrowser/1')
    {
    }

    /** @return array{0:int,1:array,2:float} status, JSON body, seconds */
    public function api(string $action, array $data = []): array
    {
        global $base;
        $cookie = implode('; ', array_map(static fn($k, $v) => "$k=$v", array_keys($this->cookies), $this->cookies));
        $ctx = stream_context_create(['http' => [
            'method' => 'POST', 'ignore_errors' => true,
            'header' => "Content-Type: application/json\r\nX-CSRF: {$this->csrf}\r\nX-Forwarded-For: {$this->ip}\r\nX-Forwarded-Proto: https\r\n"
                      . "User-Agent: {$this->agent}\r\nAccept-Language: {$this->lang}\r\n" . ($cookie !== '' ? "Cookie: $cookie\r\n" : ''),
            'content' => json_encode(['action' => $action] + $data),
        ]]);
        $t    = microtime(true);
        $fp   = fopen("$base/api.php", 'r', false, $ctx);
        $body = (string)stream_get_contents($fp);
        $head = stream_get_meta_data($fp)['wrapper_data'];     // the response headers (not $http_response_header: deprecated in PHP 8.5)
        fclose($fp);
        $t    = microtime(true) - $t;
        $code = 0;
        foreach ($head as $h) {
            if (preg_match('#^HTTP/\S+ (\d+)#', $h, $m)) {
                $code = (int)$m[1];
            } elseif (preg_match('#^Set-Cookie: ([^=]+)=([^;]*)#i', $h, $m)) {
                if ($m[2] === '' || $m[2] === 'deleted') {
                    unset($this->cookies[$m[1]]);
                } else {
                    $this->cookies[$m[1]] = $m[2];
                }
            }
        }
        $j = (array)json_decode($body, true);
        $this->csrf = $j['csrf'] ?? $this->csrf;
        return [$code, $j, $t];
    }

    /**
     * POST of a body sent as is (to $path, e.g. "files.php?a=upload&p=x&o=0"), chunked when asked (no Content-Length):
     * what api() can not send. @return array{0:int,1:array,2:string} status, JSON body, body as it came
     */
    public function raw(string $path, string $body, bool $chunked = false): array
    {
        global $base;
        $u = parse_url($base);
        $s = fsockopen($u['host'], $u['port'] ?? 80);
        fwrite($s, 'POST ' . ($u['path'] ?? '') . "/$path HTTP/1.1\r\nHost: {$u['host']}\r\nConnection: close\r\nX-CSRF: {$this->csrf}\r\n"
            . "X-Forwarded-For: {$this->ip}\r\nX-Forwarded-Proto: https\r\nUser-Agent: {$this->agent}\r\nContent-Type: application/octet-stream\r\nCookie: "
            . implode('; ', array_map(static fn($k, $v) => "$k=$v", array_keys($this->cookies), $this->cookies)) . "\r\n"
            . ($chunked ? "Transfer-Encoding: chunked\r\n\r\n" . implode('', array_map(static fn($c) => dechex(strlen($c)) . "\r\n$c\r\n", str_split($body, 65536))) . "0\r\n\r\n"
                        : 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body));
        [$head, $content] = explode("\r\n\r\n", (string)stream_get_contents($s), 2) + ['', ''];
        fclose($s);
        if (preg_match('/^Transfer-Encoding: chunked/mi', $head)) {
            for ($out = ''; preg_match('/^([0-9a-f]+)\r\n/i', $content, $m) && ($n = hexdec($m[1])) > 0; $content = substr($content, strlen($m[0]) + $n + 2)) {
                $out .= substr($content, strlen($m[0]), $n);
            }
            $content = $out;
        }
        return [(int)substr($head, 9, 3), (array)json_decode($content, true), $content];
    }
}

$pass  = 'Rio-Verde-' . bin2hex(random_bytes(4)) . '!';
$admin = new Browser('10.1.0.1');
[, $s] = $admin->api('state');
check('fresh install asks for setup', ($s['setup'] ?? false) === true);
$file = "$data/setup-code.php";
check('setup code file created, behind exit', is_file($file) && str_starts_with((string)file_get_contents($file), '<?php exit; ?>'));
$code = trim(substr((string)file_get_contents($file), 15));

[$c] = $admin->api('setup', ['username' => 'admin', 'password' => $pass]);
check('setup refused without the code', $c === 403);
[$c] = $admin->api('setup', ['setup_code' => 'AAAAA-BBBBB-CCCCC-DDDDD', 'username' => 'admin', 'password' => $pass]);
check('setup refused with a wrong code', $c === 403);
[$c, $j] = $admin->api('setup', ['setup_code' => strtolower($code), 'username' => 'admin', 'password' => 'password1234']);
check('common password refused', $c !== 200 && ($j['error'] ?? '') === 'weak_password');
[$c, $j] = $admin->api('setup', ['setup_code' => $code, 'username' => 'admin', 'password' => 'xadminx-2026']);
check('password containing the user name refused', ($j['error'] ?? '') === 'weak_password');
[$c, $j] = $admin->api('setup', ['setup_code' => $code, 'username' => 'admin', 'password' => $pass]);
check('setup with the code', $c === 200 && ($j['user']['username'] ?? '') === 'admin');
clearstatcache();
check('setup code deleted after use', !is_file($file));
[$c] = (new Browser('10.1.0.2'))->api('setup', ['setup_code' => $code, 'username' => 'evil', 'password' => $pass]);
check('second setup refused', $c === 403);

// test settings: small global limit
[$c] = $admin->api('admin.settings', ['settings' => ['login_max_attempts' => 5, 'login_lock_minutes' => 15, 'login_global_max' => 12]]);
check('settings saved', $c === 200);

// constant answer time and one answer for every failure
$times = [];
foreach ([['nobody', $pass], ['admin', 'wrong-password-123']] as $i => [$u, $p]) {
    $b = new Browser('10.2.0.' . ($i + 1));
    $b->api('state');
    [$c, $j, $t] = $b->api('login', ['username' => $u, 'password' => $p]);
    $times[] = $t;
    check("failure answer for $u", $c === 401 && ($j['error'] ?? '') === 'invalid');
}
$ok = new Browser('10.2.0.9');
$ok->api('state');
[$c, , $t] = $ok->api('login', ['username' => 'admin', 'password' => $pass]);
$times[] = $t;
check('login ok', $c === 200);
check('every login answer takes the same time', max($times) - min($times) < 0.15 && min($times) >= 0.95, implode(' / ', array_map(static fn($x) => sprintf('%.2fs', $x), $times)));

// session bound to the browser
$thief = new Browser('10.3.0.1', 'OtherBrowser/9');
$thief->cookies = $ok->cookies;
$thief->csrf    = $ok->csrf;
[$c] = $thief->api('conns.list');
check('stolen session cookie refused from another browser', $c === 401 || $c === 403, "HTTP $c");
[$c] = $ok->api('conns.list');
check('...and the session itself was closed', $c === 401 || $c === 403, "HTTP $c");

// per-IP lock (each lock scenario starts with no failures counted: a sign-in no longer clears its address's failures,
// so the ones above would count for the global limit)
@unlink("$data/ratelimit.json");
$ipb = new Browser('10.4.0.1');
$ipb->api('state');
for ($i = 0; $i < 5; $i++) {
    $ipb->api('login', ['username' => 'someone' . $i, 'password' => 'bad-password-xx']);
}
[$c] = $ipb->api('login', ['username' => 'admin', 'password' => $pass]);
check('locked IP refused even with the right password', $c === 401);
// a sign-in no longer clears its address's failures (with an account of their own, someone could clear them between guesses)
@unlink("$data/ratelimit.json");
$ipc = new Browser('10.4.0.2');
$ipc->api('state');
for ($i = 0; $i < 4; $i++) {
    $ipc->api('login', ['username' => 'someone' . $i, 'password' => 'bad-password-xx']);
}
[$c1] = $ipc->api('login', ['username' => 'admin', 'password' => $pass]);
$ipc->api('logout');
$ipc->cookies = [];                                                    // (not a known browser: those have a count of their own)
$ipc->api('state');
$ipc->api('login', ['username' => 'someone9', 'password' => 'bad-password-xx']);
[$c2] = $ipc->api('login', ['username' => 'admin', 'password' => $pass]);
check('the failures of an address are kept after a sign-in from it', $c1 === 200 && $c2 === 401, "$c1 $c2");

// per-user lock: an attacker spreading over IPs cannot lock the owner out of a browser he already used
@unlink("$data/ratelimit.json");
$known = new Browser('10.5.0.1');
$known->api('state');
[$c] = $known->api('login', ['username' => 'admin', 'password' => $pass]);
check('owner logs in (browser becomes known)', $c === 200);
$known->api('logout');
for ($i = 0; $i < 5; $i++) {
    $a = new Browser('10.6.0.' . ($i + 1));
    $a->api('state');
    $a->api('login', ['username' => 'admin', 'password' => 'bad-password-xx']);
}
$new = new Browser('10.7.0.1');
$new->api('state');
[$c] = $new->api('login', ['username' => 'admin', 'password' => $pass]);
check('locked user refused from an unknown browser', $c === 401);
$known->ip = '10.7.0.2';
[$c, $j] = $known->api('login', ['username' => 'admin', 'password' => $pass]);
check('locked user still enters from a known browser', $c === 200, json_encode($j['error'] ?? '') . ' ' . implode(',', array_keys($known->cookies)));

// global limit: many IPs, many names
@unlink("$data/ratelimit.json");
for ($i = 0; $i < 12; $i++) {
    $a = new Browser('10.8.0.' . ($i + 1));
    $a->api('state');
    $a->api('login', ['username' => 'user' . $i, 'password' => 'bad-password-xx']);
}
$second = new Browser('10.9.0.1');
$second->api('state');
[$c] = $known->api('admin.user.save', ['username' => 'ana', 'password' => $pass, 'role' => 'user', 'enabled' => true]);
check('admin creates a user', $c === 200);
[$c] = $second->api('login', ['username' => 'ana', 'password' => $pass]);
check('under a distributed attack an unknown browser is refused', $c === 401);

// other sessions closed on password change (Store::read is cached per request, so two separate requests)
$s1 = $known;
$s2 = new Browser('10.5.0.1');
$s2->cookies = array_intersect_key($known->cookies, ['MUDESKD' => 1]);   // same browser, second session
$s2->api('state');
[$c] = $s2->api('login', ['username' => 'admin', 'password' => $pass]);
check('second session of the owner', $c === 200);
$new = 'Monte-Azul-' . bin2hex(random_bytes(4)) . '?';
[$c] = $s1->api('profile.password', ['old' => $pass, 'new' => $new]);
check('password changed', $c === 200);
[$c] = $s1->api('conns.list');
check('the session that changed it stays open', $c === 200);
[$c] = $s2->api('conns.list');
check('the other session is closed', $c === 401);


// audit: every step above recorded with its outcome and reason, searchable, tamper-evident
$find = static function (array $filter) use ($s1): array {
    [, $j] = $s1->api('admin.audit', ['filter' => $filter]);
    return $j['rows'] ?? [];
};
$reasons = array_count_values(array_map(static fn($r) => $r['reason'] ?? '', $find(['event' => 'login_fail'])));
foreach (['unknown_user', 'bad_password', 'locked_ip', 'locked_user', 'locked_global'] as $r) {
    check("audit: login failure '$r' recorded", isset($reasons[$r]), json_encode($reasons));
}
$all = $find(['ok' => '0']);
check('audit: failed filter only returns failures', $all !== [] && array_filter($all, static fn($r) => $r['ok'] !== false) === []);
$closed = array_column($find(['event' => 'session_closed']), 'reason');
check('audit: stolen session and password change close sessions with reason', !array_diff(['other_browser', 'password_changed'], $closed), implode(',', $closed));
$set = $find(['event' => 'settings_update'])[0] ?? [];
check('audit: settings change shows old and new value', ($set['changes']['login_global_max'] ?? null) === [50, 12], json_encode($set['changes'] ?? null));
check('audit: setup, user creation, password change and logout recorded',
    !array_diff(['setup', 'setup_fail', 'user_create', 'password_change', 'logout'], array_column($find([]), 'event')));
$plain = json_encode($find([]));
check('audit: passwords never stored', !str_contains($plain, $pass) && !str_contains($plain, $new) && !str_contains($plain, 'bad-password-xx'));
check('audit: search by IP', ($find(['ip' => '10.4.0.1'])[0]['ip'] ?? '') === '10.4.0.1');
[$c, $j] = $s1->api('admin.audit', ['filter' => [], 'export' => 'csv']);
check('audit: export returns every filtered row', $c === 200 && count($j['rows']) === $j['total'] && $j['total'] > 50, "{$j['total']} rows");
[, $v] = $s1->api('admin.audit.verify');
check('audit: chain intact', ($v['ok'] ?? false) === true && $v['lines'] > 50, json_encode($v['problems'] ?? null));
$day   = "$data/audit/" . date('Y-m-d') . '.jsonl';
$lines = file($day);
$lines[10] = str_replace('"10.', '"11.', $lines[10]);             // someone edits a line
file_put_contents($day, implode('', $lines));
[, $v] = $s1->api('admin.audit.verify');
check('audit: edited line reported', ($v['ok'] ?? true) === false && in_array([date('Y-m-d'), 11, 'changed'], $v['problems'], true), json_encode($v['problems'] ?? null));
[$c] = (new Browser('10.10.0.1'))->api('admin.audit');
check('audit: only for administrators', $c === 401 || $c === 403, "HTTP $c");

// the same browser with another Accept-Language (Web Workers may send a different one) keeps its session
$s1->lang = 'es-ES,es;q=0.9,en;q=0.8';
[$c] = $s1->api('conns.list');
check('session kept when only Accept-Language changes', $c === 200, "HTTP $c");
$s1->lang = 'es';

// reconnection attempts: administrator setting (10 by default, clamped to 0-50) given to the browser with the user
[, $j] = $s1->api('state');
check('reconnection attempts default to 10', ($j['user']['reconnect'] ?? null) === 10);
$s1->api('admin.settings', ['settings' => ['reconnect_attempts' => 99]]);
[, $j] = $s1->api('state');
check('reconnection attempts clamped and returned', ($j['user']['reconnect'] ?? null) === 50);
// password typed in the connect dialog saved on request ("not recommended"); language '' = the browser's
[, $j] = $s1->api('conns.save', ['conn' => ['name' => 'Srv', 'host' => '10.0.0.9', 'port' => 3389]]);
$cid = $j['conn']['id'] ?? '';
[$c] = $s1->api('conns.password', ['id' => $cid, 'password' => 'Remote-Pass-1']);
[, $j] = $s1->api('conns.list');
check('connect dialog can save the password', $c === 200 && ($j['conns'][0]['has_pass'] ?? false) === true);
// the administrator can forbid saving RDP passwords (allowed by default): turned off, those saved are erased (audited),
// none can be saved again from the connection form or the connect dialog, and the interface is told; forgetting one works
[, $j] = $s1->api('state');
$byDefault = $j['user']['save_passwords'] ?? null;
$s1->api('admin.settings', ['settings' => ['save_passwords' => false]]);
[, $list] = $s1->api('conns.list');
[$c1, $e1] = $s1->api('conns.password', ['id' => $cid, 'password' => 'Remote-Pass-2']);
[$c2, $e2] = $s1->api('conns.save', ['conn' => ['id' => $cid, 'name' => 'Srv', 'host' => '10.0.0.9', 'port' => 3389, 'password' => 'Remote-Pass-3']]);
[$c3] = $s1->api('conns.save', ['conn' => ['id' => $cid, 'name' => 'Srv', 'host' => '10.0.0.9', 'port' => 3389, 'password' => '']]);
[, $j] = $s1->api('state');
$erased = $find(['event' => 'rdp_passwords_erased'])[0]['connections'] ?? null;
$got = [$byDefault, $list['conns'][0]['has_pass'] ?? null, $c1, $e1['error'] ?? '', $c2, $e2['error'] ?? '', $c3, $j['user']['save_passwords'] ?? null, $erased];
check('saving RDP passwords turned off: saved ones erased and audited, none saved again, the interface told',
    $got === [true, false, 400, 'save_passwords_off', 400, 'save_passwords_off', 200, false, 1], json_encode($got));
$s1->api('admin.settings', ['settings' => ['save_passwords' => true]]);
[$c] = $s1->api('conns.password', ['id' => $cid, 'password' => 'Remote-Pass-1']);
check('saving RDP passwords turned on again: allowed', $c === 200, "HTTP $c");
[$c, $j] = $s1->api('profile.lang', ['lang' => '']);
check('profile language "automatic"', $c === 200 && ($j['user']['lang'] ?? 'x') === '');
[$c] = $s1->api('profile.lang', ['lang' => 'fr']);
[, $j] = $s1->api('state');
check('unknown language refused', [$c, $j['user']['lang'] ?? 'x'] === [400, ''], "HTTP $c");
$s1->api('profile.lang', ['lang' => ' en ']);
[, $j] = $s1->api('state');
$padded = $j['user']['lang'] ?? 'x';
[$c] = $s1->api('profile.lang');
[, $j] = $s1->api('state');
check('profile language stored trimmed, and "" when none is sent', [$padded, $c, $j['user']['lang'] ?? 'x'] === ['en', 200, ''], json_encode([$padded, $c]));

// adding a sign-in method asks for the current password (a borrowed session must not add its own)
[$c, $j] = $s1->api('totp.begin');
check('authenticator app asks for the current password', $c === 400 && ($j['error'] ?? '') === 'invalid_password', "HTTP $c");
[$c, $j] = $s1->api('passkey.create', ['clientData' => '', 'attestation' => '']);
check('passkey creation needs the password step first', $c === 400 && ($j['error'] ?? '') === 'invalid_password', "HTTP $c");
[$c, $j] = $s1->api('passkey.create.begin', ['password' => 'wrong-password-123']);
check('passkey refused with a wrong password', $c === 400 && ($j['error'] ?? '') === 'invalid_password', "HTTP $c");
[$c, $j] = $s1->api('passkey.create.begin', ['password' => $new]);
check('passkey goes on with the current password', $c === 200 && isset($j['options']['challenge']), "HTTP $c");
// confirming the password: five wrong ones close the session (a borrowed session could try passwords without end), and
// only that one
@unlink("$data/ratelimit.json");                                        // (the global lock of the scenario above)
$s3 = new Browser('10.5.0.3');
$s3->api('state');
[$c] = $s3->api('login', ['username' => 'admin', 'password' => $new]);
$tries = [];
for ($i = 0; $i < 5; $i++) {
    [$tc, $tj] = $s3->api('passkey.create.begin', ['password' => 'wrong-password-' . $i]);
    $tries[] = "$tc " . ($tj['error'] ?? '') . ' ' . ($tj['reason'] ?? '');
}
[$after] = $s3->api('conns.list');
[$still] = $s1->api('conns.list');
check('five wrong passwords to confirm close that session only', $c === 200 && $tries === [...array_fill(0, 4, '400 invalid_password '), '401 auth reauth']
    && $after !== 200 && $still === 200, json_encode([$c, $tries, $after, $still]));

// connection options: a value JSON can not hold (1e999) and keys that are not options; the audit keeps the stored values
[, $j] = $s1->api('conns.list');
[$c] = $s1->raw('api.php', '{"action":"conns.options","id":"' . ($j['conns'][0]['id'] ?? '') . '","opts":{"audio":1e999,"pass":"x"}}');
$opts = $find(['event' => 'conn_options'])[0]['options'] ?? null;
check('connection options audited with the stored values only', [$c, $opts] === [200, ['audio' => true]], "HTTP $c " . json_encode($opts));

// client allow-list that would shut the administrator out
[$c, $j] = $s1->api('admin.settings', ['settings' => ['clients' => ['mode' => 'list', 'list' => ['192.0.2.0/24']]]]);
[$c2] = $s1->api('conns.list');
check("allow-list without the administrator's IP refused", [$c, $j['error'] ?? '', $c2] === [400, 'client_lockout', 200], "HTTP $c / $c2");
if ($c2 !== 200) {                                        // without the check every request below would be refused
    $st = json_decode((string)file_get_contents("$data/settings.json"), true);
    file_put_contents("$data/settings.json", json_encode(['clients' => ['mode' => 'all', 'list' => []]] + $st));
}

// shared folder: the quota also holds for a chunked body (no Content-Length); every upload recorded from its first
// chunk; no web server configuration file; no upload onto a folder
$s1->api('admin.settings', ['settings' => ['files_quota_mb' => 1]]);
$uid = $s1->api('state')[1]['user']['id'] ?? '';
[$c, $j] = $s1->raw('files.php?a=upload&p=big.bin&o=0&last=1', str_repeat('x', 2 * 1048576), true);
clearstatcache();
$size = (int)@filesize("$data/files/$uid/big.bin");
check('upload over the quota refused with a chunked body', [$j['error'] ?? '', $size <= 1048576] === ['quota', true], "HTTP $c, $size bytes kept");
[$c] = $s1->raw('files.php?a=upload&p=part.bin&o=0', 'abc');
check('an upload is recorded from its first chunk', $c === 200 && ($find(['event' => 'file_upload_start'])[0]['path'] ?? '') === 'part.bin', "HTTP $c");
$s1->raw('files.php?a=mkdir&p=&n=sub', '');
[$c, $j] = $s1->raw('files.php?a=upload&p=sub/.htaccess&o=0&last=1', 'Require all granted');
check('.htaccess refused in the shared folder', [$c, $j['error'] ?? ''] === [400, 'invalid_path'], "HTTP $c");
[$c, $j] = $s1->raw('files.php?a=upload&p=sub&o=0&last=1', 'abc');
check('upload onto a folder refused', [$c, $j['error'] ?? ''] === [400, 'invalid_path'], "HTTP $c");
$s1->api('admin.settings', ['settings' => ['files_quota_mb' => 500]]);

// diagnostic traces: listed, downloaded and deleted by the administrator only, each action audited
@mkdir("$data/trace", 0700);
file_put_contents("$data/trace/00112233445566778899aabbccddeeff.log", '    0.000 start {"user":"ana","conn":"Srv","host":"10.0.0.9","date":"2026-01-01T00:00:00+00:00"}' . "\n");
[, $j] = $s1->api('admin.traces');
check('traces: listed with their session details', ($j['traces'][0]['conn'] ?? '') === 'Srv' && ($j['traces'][0]['user'] ?? '') === 'ana');
[, $j] = $s1->api('admin.trace', ['sid' => '00112233445566778899aabbccddeeff']);
check('traces: download', str_contains($j['text'] ?? '', '"host":"10.0.0.9"'));
[$c] = $s1->api('admin.trace', ['sid' => '../users']);
check('traces: invalid id refused', $c === 404);
[$c] = $s1->api('admin.trace', ['sid' => '00112233445566778899aabbccddeeff', 'delete' => 1]);
check('traces: deleted', $c === 200 && !is_file("$data/trace/00112233445566778899aabbccddeeff.log"));
check('traces: download and deletion audited', count($find(['event' => 'trace_'])) === 2);

// errors answered with a 4xx code; a JSON list or object where text or a number goes: 400 invalid_input, never a PHP
// warning (it would also break the JSON answer)
$got = [];
foreach ([['login', ['username' => ['admin'], 'password' => 'x']], ['conns.save', ['conn' => ['host' => ['pc']]]], ['conns.save', ['conn' => 'pc']],
          ['admin.settings', ['settings' => ['login_max_attempts' => [5]]]], ['admin.audit', ['filter' => ['user' => ['x']]]],
          ['admin.user.save', ['id' => $uid, 'max_sessions' => [1]]], ['profile.password', ['old' => [$new], 'new' => 'x']]] as [$a, $d]) {
    [$c, $j] = $s1->api($a, $d);
    $got[] = "$a $c " . ($j['error'] ?? '?');
}
check('a list where text or a number goes: 400 invalid_input', $got === array_map(static fn($s) => explode(' ', $s)[0] . ' 400 invalid_input', $got), implode(', ', $got));
[$c, $j] = $s1->api('conns.save', ['conn' => ['name' => 'x', 'host' => '']]);
check('a connection without a computer refused', [$c, $j['error'] ?? ''] === [400, 'host'], "HTTP $c");
$got = [];
foreach ([['conns.forget_cert', ['id' => 'nope']], ['passkey.delete', ['id' => 'nope', 'password' => $new]], ['admin.sessions.kill', ['sid' => str_repeat('0', 32)]]] as [$a, $d]) {
    [$c, $j] = $s1->api($a, $d);
    $got[] = [$c, $j['error'] ?? ''];
}
check('what does not exist: 404 not_found', $got === array_fill(0, 3, [404, 'not_found']), json_encode($got));

// the page's language before the interface loads: the browser's first language among Spanish and English, else English
$lang = static function (string $accept) use ($base): string {
    $html = (string)file_get_contents("$base/index.php", false, stream_context_create(['http' => ['header' => "Accept-Language: $accept\r\nX-Forwarded-Proto: https\r\n"]]));
    return preg_match('/<html lang="([a-z]+)"/', $html, $m) ? $m[1] : '?';
};
$got = array_map($lang, ['en-US,en;q=0.9', 'es-ES,es;q=0.9', 'fr-FR,fr;q=0.9,es;q=0.8', 'sv-SE']);
check('page language from the browser', $got === ['en', 'es', 'es', 'en'], implode(',', $got));

// second factor for everybody: refused while the administrator has none (he would be asked for it himself); a user
// without it signs in, is asked for it and sets it up. The TOTP code that has just turned it on is answered apart.
require_once __DIR__ . '/../src/Totp.php';
$s1->api('admin.settings', ['settings' => ['login_global_max' => 50]]);   // the distributed attack above is over
[$c, $j] = $s1->api('admin.settings', ['settings' => ['totp_required' => true]]);
[, $st]  = $s1->api('admin.settings');
check('2FA for everybody refused while the administrator has none', [$c, $j['error'] ?? '', $st['settings']['totp_required'] ?? null] === [400, 'totp_required_self', false], "HTTP $c");
[, $j]  = $s1->api('totp.begin', ['password' => $new]);
$secret = $j['secret'] ?? '';
$step   = intdiv(time(), 30);
[$c]    = $s1->api('totp.enable', ['code' => MuDesk\Totp::code($secret, $step)]);
$b = new Browser('10.11.0.1');
$b->api('state');
$b->api('login', ['username' => 'admin', 'password' => $new]);
[$c1, $j1] = $b->api('login', ['username' => 'admin', 'code' => MuDesk\Totp::code($secret, $step)]);
[$c2]      = $b->api('login', ['username' => 'admin', 'code' => MuDesk\Totp::code($secret, $step + 1)]);
check('TOTP: the code that turned it on, at once at sign-in, answered apart; the next one works', [$c, $c1, $j1['error'] ?? '', $c2] === [200, 401, 'code_used', 200],
    "$c $c1 " . ($j1['error'] ?? '') . " $c2");
[$c]  = $s1->api('admin.settings', ['settings' => ['totp_required' => true]]);
$ana  = new Browser('10.11.0.2');
$ana->api('state');
[$c1, $j1] = $ana->api('login', ['username' => 'ana', 'password' => $pass]);
$pass2 = 'Lago-Claro-' . bin2hex(random_bytes(4)) . '#';
$ana->api('profile.password', ['old' => $pass, 'new' => $pass2]);    // created by the administrator: her own password first
[$c2, $j2] = $ana->api('conns.list');
[, $j] = $ana->api('totp.begin', ['password' => $pass2]);
[$c3]  = $ana->api('totp.enable', ['code' => MuDesk\Totp::code($j['secret'] ?? '', intdiv(time(), 30))]);
[$c4]  = $ana->api('conns.list');
check('2FA for everybody: a user without it signs in, is asked for it, sets it up and goes on',
    [$c, $c1, $j1['user']['totp_required'] ?? null, $c2, $j2['error'] ?? '', $c3, $c4] === [200, 200, true, 403, 'pending', 200, 200], "$c $c1 $c2 $c3 $c4");

// own activity: the user's remote sessions only (one of another user can not be ended)
$own   = bin2hex(random_bytes(16));
$other = bin2hex(random_bytes(16));
foreach ([$own => $uid, $other => 'someone-else'] as $sid => $owner) {
    file_put_contents("$data/run/$sid.json", json_encode(['uid' => $owner, 'user' => 'x', 'conn' => 'Srv', 'host' => '10.0.0.9', 'ip' => '10.5.0.1',
        'token' => 'secret-token', 'sid' => $sid, 'started' => date('c')]));
}
[, $j] = $s1->api('profile.activity');
[$c1] = $s1->api('profile.session.kill', ['sid' => $other]);
[$c2] = $s1->api('profile.session.kill', ['sid' => $own]);
@unlink("$data/run/$own.json");
@unlink("$data/run/$other.json");
check('own activity: only the own remote sessions, without their token; one of another user is not ended',
    [array_column($j['sessions'] ?? [], 'sid'), isset($j['sessions'][0]['token']), $c1, $c2, $find(['event' => 'session_kill'])[0]['by'] ?? ''] === [[$own], false, 404, 200, 'owner'],
    "$c1 $c2 " . json_encode($j['sessions'] ?? null));

// after signing in, once: the previous sign-in and the failed attempts with the user name since then
[$c] = $s1->api('admin.user.save', ['username' => 'leo', 'password' => $pass, 'role' => 'user', 'enabled' => true]);
for ($i = 1; $i <= 3; $i++) {
    $a = new Browser("10.13.0.$i");
    $a->api('state');
    $a->api('login', ['username' => 'LEO', 'password' => 'bad-password-xx']);
}
$leo = new Browser('10.13.1.1');
$leo->api('state');
[, $j1] = $leo->api('login', ['username' => 'leo', 'password' => $pass]);
[, $j2] = $leo->api('state');
$leo2 = new Browser('10.13.1.2', 'Mozilla/5.0 (X11; Linux x86_64; rv:132.0) Gecko/20100101 Firefox/132.0');
$leo2->api('state');
[, $j3] = $leo2->api('login', ['username' => 'leo', 'password' => $pass]);
[$p1, $p3] = [$j1['previous'] ?? [], $j3['previous'] ?? []];
check('sign-in notice: failures since the last sign-in, once; then that sign-in',
    [$c, $p1['failures'] ?? null, $p1['failed']['ip'] ?? null, array_key_exists('login', $p1) && $p1['login'] === null,
     array_key_exists('previous', $j2) && $j2['previous'] === null, $p3['ip'] ?? null, $p3['failures'] ?? null] === [200, 3, '10.13.0.3', true, true, '10.13.1.1', 0],
    json_encode([$j1['previous'] ?? null, $j3['previous'] ?? null]));

// trusted browsers of the user: named from the User-Agent, this one marked; one that is not there, then all the others forgotten
[, $j] = $leo2->api('profile.activity');
$names = array_column($j['browsers'] ?? [], 'name');
[$c1]     = $leo2->api('profile.browser.forget', ['id' => 'nope']);
[$c2, $j] = $leo2->api('profile.browser.forget', ['others' => true]);
check('trusted browsers: listed with name and the current one, the others forgotten and audited',
    [$names, count(array_filter(array_column($j['browsers'] ?? [], 'current'))), count($j['browsers'] ?? []), $c1, $c2, count($find(['event' => 'device_forget']))]
    === [['Firefox 132 · Linux', ''], 1, 1, 404, 200, 1], json_encode($names));

// signed export: the body is the file, then its signature in base64; it checks with the published public key
[, $k]           = $s1->api('admin.audit.key');
[$c, , $body]    = $s1->raw('api.php', json_encode(['action' => 'admin.audit', 'filter' => [], 'export' => 'json']));
$cut             = strrpos($body, "\n") + 1;
[$file, $sig]    = [substr($body, 0, $cut), (string)base64_decode(substr($body, $cut), true)];
check('signed audit export: file and signature, checked with the public key', [$c, openssl_verify($file, $sig, (string)($k['key'] ?? ''), OPENSSL_ALGO_SHA256),
    count(json_decode($file, true)['rows'] ?? []) > 50, ($find(['event' => 'audit_export'])[0]['sha256'] ?? '') === hash('sha256', $file)] === [200, 1, true, true], "HTTP $c");

// installable app: manifest linked from the page and served, its icons there
$page     = (string)file_get_contents("$base/index.php", false, stream_context_create(['http' => ['header' => "X-Forwarded-Proto: https\r\n"]]));
$manifest = json_decode((string)file_get_contents("$base/manifest.webmanifest"), true);
$icons    = array_map(static fn($i) => strlen((string)@file_get_contents("$base/" . $i['src'])) > 300, $manifest['icons'] ?? []);
check('installable app: manifest and icons', [str_contains($page, '<link rel="manifest" href="manifest.webmanifest">'), $manifest['display'] ?? '', count($icons),
    !in_array(false, $icons, true), str_contains($page, 'apple-touch-icon')] === [true, 'standalone', 4, true, true]);

echo $fail === 0 ? "\nAll tests passed\n" : "\n$fail test(s) failed\n";
exit($fail === 0 ? 0 : 1);
