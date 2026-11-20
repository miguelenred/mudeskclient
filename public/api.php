<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

use MuDesk\{Access, Audit, Auth, Connections, Crypto, Http, Sessions, Settings, Text, Totp, Users, WebAuthn};
use MuDesk\Rdp\Trace;

require __DIR__ . '/../src/bootstrap.php';

Http::guard();
// the page asking how long its session has left is not activity: counted as such, it would keep an idle session open
$peek = Http::input();                           // (decoded once, at most Http::BODY_MAX)
if (!isset($_COOKIE['MUDESKSID']) && (!is_array($peek) || ($peek['action'] ?? null) !== 'state')) {
    Http::fail('csrf', 403);                     // (only 'state' opens a session: the token comes with it)
}
Auth::start(!is_array($peek) || ($peek['action'] ?? null) !== 'session.left');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Http::fail('method', 405);
}
$in     = Http::input();
$action = is_string($in['action'] ?? null) ? $in['action'] : '';
Http::$action        = 'api:' . substr($action, 0, 40);
Http::$auditFailures = !in_array($action, ['login', 'passkey.login', 'setup'], true);   // these log their own detailed outcome
if ($action !== 'state') {
    Auth::checkCsrf();
}
// fields of the request: text or a number ('' when missing); a JSON list or object there is refused (400 invalid_input)
$raw = static fn(string $k): string => is_array($v = $in[$k] ?? '') ? Http::fail('invalid_input') : (string)$v;
$str = static fn(string $k): string => trim($raw($k));
$int = static fn(string $k): int => is_array($v = $in[$k] ?? 0) ? Http::fail('invalid_input') : Http::int($v);   // (a float past the int range warned on PHP 8.5)
$obj = static fn(string $k): array => is_array($v = $in[$k] ?? []) ? $v : Http::fail('invalid_input');
// public key of the signed audit exports, made the first time; OpenSSL unable to make it: logged, and a clear error
$signKey = static function (): string {
    try {
        return Audit::publicKey();
    } catch (RuntimeException $e) {
        error_log('MuDeskClient: ' . $e->getMessage());
        Http::fail('sign_key', 500);
    }
};

function state(): never
{
    Auth::reload();
    if (Users::all() === []) {
        Auth::setupCode();
    }
    $u = Auth::user();
    Http::json([
        'setup'   => Users::all() === [],
        'csrf'    => Auth::csrf(),
        'version' => MuDesk\VERSION,
        'build'   => Http::build(__DIR__ . '/assets'),   // the page compares it with its own: an older page says so
        'second'  => ($p = $_SESSION['pending'] ?? null) === null || !($pu = Users::get($p['uid'])) ? null
                     : ['totp' => !empty($pu['totp']['enabled']), 'passkey' => !empty($pu['passkeys'])],
        'user'    => $u === null ? null : Users::view($u) + ['totp_required' => Settings::get('totp_required'),
                     'reconnect' => Settings::get('reconnect_attempts'), 'save_passwords' => Settings::get('save_passwords')]
                     + ($u['role'] === 'admin' ? ['targets_all' => Settings::get('targets')['mode'] === 'all'] : []),   // warned in the panel
        'previous' => $u === null ? null : Auth::takePrevious(),   // right after signing in: last sign-in and failures since
        'session'  => $u === null ? null : ['idle' => Settings::get('session_idle_minutes') * 60, 'max' => Auth::maxLeft()],   // when it expires
        'closed'   => Auth::closedReason(),               // closed by this request: why (idle, max_duration...)
    ]);
}

/**
 * Adding a passkey or an authenticator app asks for the current password again: whoever borrows an open session must
 * not add a sign-in of his own. Not while a password change is pending. The next steps check reauthed().
 */
function reauth(string $password): array
{
    $u = Auth::require(false, true);
    if ($u['must_change']) {
        Http::fail('pending', 403);
    }
    if (!Auth::confirmPassword($u, $password)) {
        Http::fail('invalid_password');
    }
    $_SESSION['reauth'] = time();
    return $u;
}

function reauthed(): array
{
    $u = Auth::require(false, true);
    if (($_SESSION['reauth'] ?? 0) < time() - 300) {
        Http::fail('invalid_password');
    }
    return $u;
}

switch ($action) {
    case 'state':
        state();

    case 'session.left':           // seconds until the session closes on its own; not activity (see Auth::start)
        Auth::require(false, true);
        Http::json(['left' => Auth::left()]);

    case 'setup':
        if (Users::all() !== []) {
            Http::fail('forbidden', 403);
        }
        if (!Auth::setupAllowed($str('setup_code'))) {
            Http::fail('setup_code', 403);
        }
        if (!Users::validName($str('username')) || !Users::validPassword($raw('password'), $str('username'))) {
            Http::fail('weak_password');
        }
        Users::create($str('username'), $raw('password'), 'admin', false);
        Auth::setupDone();
        Audit::log('setup', ['admin' => $str('username')], $str('username'), true);
        Auth::login($str('username'), $raw('password'));
        state();

    case 'login':
        $r = Auth::login($str('username'), $raw('password'), $str('code'));
        if ($r !== 'ok' && $r !== 'second') {
            Http::fail($r, 401);       // 'invalid', or 'code_used': the TOTP code of a step already used, wait for the next one
        }
        state();

    // ---------- passkeys ----------
    case 'passkey.begin':          // sign-in: second step (pending password) or passwordless
        $p = ($_SESSION['pending']['exp'] ?? 0) > time() ? Users::get($_SESSION['pending']['uid']) : null;
        Http::json(['options' => WebAuthn::getOptions($p['passkeys'] ?? [], $p ? 'login2:' . $p['id'] : 'login')]);

    case 'passkey.login':
        $keys = ['id', 'clientData', 'authData', 'signature', 'userHandle', 'prf'];
        $r    = Auth::passkeyLogin(array_combine($keys, array_map($raw, $keys)));
        if ($r !== 'ok') {
            Http::fail($r, 401);
        }
        state();

    case 'passkey.create.begin':
        $u = reauth($raw('password'));
        Http::json(['options' => WebAuthn::createOptions($u)]);

    case 'passkey.create':
        $u  = reauthed();
        $pk = WebAuthn::register($u, WebAuthn::unb64u($str('clientData')), WebAuthn::unb64u($str('attestation')));
        if ($pk === null) {
            Http::fail('passkey_invalid');
        }
        $pk['name'] = trim(Text::cut($str('name'), 40)) ?: 'Passkey';
        Users::update($u['id'], static function (array &$x) use ($pk): void {
            $x['passkeys'] = [...($x['passkeys'] ?? []), $pk];
        });
        Audit::log('passkey_add', ['name' => $pk['name']], null, true);
        Http::json(['id' => $pk['id'], 'options' => WebAuthn::getOptions([$pk], 'prf:' . $u['id'])]);

    case 'passkey.prf':            // right after creating it: the passkey's PRF output wraps the data key
        $u   = reauthed();
        $i   = array_search($str('id'), array_column($u['passkeys'] ?? [], 'id'), true);
        $prf = WebAuthn::unb64u($str('prf'));
        $dek = Auth::dek();
        if ($i === false || strlen($prf) !== 32 || $dek === null
            || WebAuthn::verify($u['passkeys'][$i], 'prf:' . $u['id'], WebAuthn::unb64u($str('clientData')),
                                WebAuthn::unb64u($str('authData')), WebAuthn::unb64u($str('signature'))) === null) {
            Http::fail('passkey_invalid');
        }
        $wrapped = Crypto::encrypt(WebAuthn::prfKey($prf, $u['passkeys'][$i]['id']), $dek, 'passkey:' . $u['id']);
        $pkId = $u['passkeys'][$i]['id'];         // found again by its id under the lock (by its place: a broken entry)
        Users::update($u['id'], static function (array &$x) use ($pkId, $wrapped): void {
            foreach ($x['passkeys'] ?? [] as $j => $p) {
                if (($p['id'] ?? null) === $pkId) {
                    $x['passkeys'][$j]['dek'] = $wrapped;
                }
            }
        });
        Audit::log('passkey_passwordless', ['name' => $u['passkeys'][$i]['name']], null, true);
        state();

    case 'passkey.delete':
        $u    = Auth::require(false, true);
        $name = array_column($u['passkeys'] ?? [], 'name', 'id')[$str('id')] ?? Http::fail('not_found', 404);
        if (!Auth::confirmPassword($u, $raw('password'))) {
            Http::fail('invalid_password');
        }
        Users::update($u['id'], static function (array &$x) use ($str): void {
            $x['passkeys'] = array_values(array_filter($x['passkeys'] ?? [], static fn($p) => $p['id'] !== $str('id')));
        });
        Audit::log('passkey_delete', ['name' => $name], null, true);
        state();

    case 'logout':
        Auth::logout();
        state();

    // ---------- own profile ----------
    case 'profile.password':
        $u   = Auth::require(false, true);
        $old = $raw('old');
        $new = $raw('new');
        if (!Auth::confirmPassword($u, $old)) {
            Http::fail('invalid_password');
        }
        if (!Users::validPassword($new, $u['username']) || $new === $old) {
            Http::fail('weak_password');
        }
        $dek = Crypto::unwrapKey($old, $u['dek'], $u['id']);
        Users::setPassword($u['id'], $new, $dek, false);
        Auth::keepSession();
        if ($dek !== null) {
            Auth::storeKey($dek);
        }
        Audit::log('password_change', ['other_sessions_closed' => true, 'saved_rdp_passwords_kept' => $dek !== null], null, true);
        state();

    case 'profile.lang':
        $u = Auth::require(false, true);
        if ($str('lang') !== '' && !in_array($str('lang'), MuDesk\I18n::LANGS, true)) {   // '' = the browser's language
            Http::fail('invalid_input');
        }
        Users::update($u['id'], static fn(array &$x) => $x['lang'] = $str('lang'));
        Audit::log('profile_update', ['changes' => ['lang' => [$u['lang'], $str('lang')]]], null, true);
        state();

    case 'profile.activity':       // the user's own remote sessions and known browsers
        $u = Auth::require(false, true);
        Http::json(['sessions' => array_map(static fn($s) => array_intersect_key($s, array_flip(['sid', 'conn', 'host', 'ip', 'started'])),
            Sessions::active($u['id'])), 'browsers' => Auth::browsers($u)]);

    case 'profile.session.kill':
        $u    = Auth::require(false, true);
        $info = Sessions::info($str('sid'));
        if (($info['uid'] ?? null) !== $u['id']) {
            Http::fail('not_found', 404);
        }
        Sessions::kill($str('sid'));
        Audit::log('session_kill', ['sid' => $str('sid'), 'conn' => $info['conn'] ?? '', 'by' => 'owner'], null, true);
        Http::json(['ok' => true]);

    case 'profile.browser.forget': // one known browser (id) or every one but this (others)
        $u    = Auth::require(false, true);
        $gone = Auth::forget($u, empty($in['others']) ? $str('id') : null);
        if ($gone === [] && empty($in['others'])) {
            Http::fail('not_found', 404);
        }
        Audit::log('device_forget', ['browsers' => array_map(static fn($d) => trim(($d['ua'] ?? '') . ' ' . ($d['ip'] ?? '')), array_values($gone)),
            'others' => !empty($in['others'])], null, true);
        Http::json(['browsers' => Auth::browsers(Users::get($u['id']) ?? $u)]);

    case 'totp.begin':
        $u                      = reauth($raw('password'));
        $_SESSION['totp_setup'] = Totp::secret();
        Http::json(['secret' => $_SESSION['totp_setup'],
                    'uri'    => 'otpauth://totp/MuDeskClient:' . rawurlencode($u['username']) . '?secret=' . $_SESSION['totp_setup'] . '&issuer=MuDeskClient']);

    case 'totp.enable':
        $u      = reauthed();
        $secret = $_SESSION['totp_setup'] ?? '';
        $step   = $secret === '' ? null : Totp::verify($secret, $str('code'));
        if ($step === null) {
            Http::fail('totp_invalid');
        }
        unset($_SESSION['totp_setup']);
        Users::update($u['id'], static fn(array &$x) => $x['totp'] = [
            'enabled' => true, 'last' => $step, 'secret' => Crypto::encrypt(Crypto::appKey(), $secret, 'totp:' . $u['id'])]);
        Audit::log('totp_enable', [], null, true);
        state();

    case 'totp.disable':
        $u = Auth::require(false, true);
        if (Settings::get('totp_required')) {
            Http::fail('forbidden', 403);
        }
        if (!Auth::confirmPassword($u, $raw('password'))) {
            Http::fail('invalid_password');
        }
        Users::update($u['id'], static fn(array &$x) => $x['totp'] = null);
        Audit::log('totp_disable', [], null, true);
        state();

    // ---------- connections ----------
    case 'conns.list':
        $u = Auth::require();
        $c = array_map([Connections::class, 'view'], array_values(Connections::all($u['id'])));
        usort($c, static fn($a, $b) => strnatcasecmp($a['group'] . "\0" . $a['name'], $b['group'] . "\0" . $b['name']));
        Http::json(['conns' => $c, 'keyboards' => Connections::KEYBOARDS]);

    case 'conns.save':
        $u    = Auth::require();
        $conn = $obj('conn');
        $old  = empty($conn['id']) || is_array($conn['id']) ? [] : (Connections::all($u['id'])[(string)$conn['id']] ?? []);
        $r    = Connections::save($u['id'], $conn, Auth::dek());
        if (is_string($r)) {
            Http::fail($r);
        }
        $skip = ['id' => 0, 'created' => 0, 'updated' => 0];
        Audit::log($old === [] ? 'conn_create' : 'conn_update', ['conn' => $r['name'], 'host' => $r['host']] + ($old === []
            ? ['values' => array_diff_key($r, $skip + ['pass' => 0, 'cert' => 0]) + ['password' => empty($r['pass']) ? 'not_saved' : 'saved']]
            : ['changes' => Audit::changes(array_diff_key($old, $skip), array_diff_key($r, $skip), ['pass'])]), null, true);
        Http::json(['conn' => Connections::view($r)]);

    case 'conns.delete':
        $u   = Auth::require();
        if (Connections::isManaged($str('id'))) {
            Http::fail('managed', 403);           // the administrator's: only they delete it
        }
        $old = Connections::all($u['id'])[$str('id')] ?? null;
        if (!Connections::delete($u['id'], $str('id'))) {
            Http::fail('not_found', 404);
        }
        Audit::log('conn_delete', ['conn' => $old['name'] ?? '', 'host' => $old['host'] ?? ''], null, true);
        Http::json(['ok' => true]);

    case 'conns.options':
        $u = Auth::require();
        if (!Connections::setOptions($u['id'], $str('id'), $obj('opts'))) {
            Http::fail('not_found', 404);
        }
        $c = Connections::get($u['id'], $str('id')) ?? [];       // the stored (validated) values of the options sent
        Audit::log('conn_options', ['conn' => $c['name'] ?? '', 'options' => array_intersect_key($c,
            array_flip(['audio', 'mic', 'clipboard', 'drive', 'printer', 'ask', 'gfx', 'paste', 'bpp']), $obj('opts'))], null, true);
        Http::json(['ok' => true]);

    case 'conns.password':         // saves the password typed in the connect dialog (the user ticked "save", not recommended)
        $u   = Auth::require();
        $old = Connections::all($u['id'])[$str('id')] ?? Http::fail('not_found', 404);
        $r   = Connections::save($u['id'], ['id' => $old['id'], 'password' => $raw('password')] + $old, Auth::dek());
        if (is_string($r)) {
            Http::fail($r);
        }
        Audit::log('conn_update', ['conn' => $r['name'], 'host' => $r['host'], 'changes' => ['pass' => 'changed'], 'from' => 'connect_dialog'], null, true);
        Http::json(['ok' => true]);

    case 'conns.forget_cert':
        $u = Auth::require();
        $c = Connections::get($u['id'], $str('id')) ?? Http::fail('not_found', 404);
        Connections::setCert($u['id'], $c['id'], '');
        Audit::log('cert_forget', ['conn' => $c['name']], null, true);
        Http::json(['ok' => true]);

    // ---------- administration ----------
    case 'admin.users':
        Auth::require(true);
        $list = array_map([Users::class, 'view'], array_values(Users::all()));
        usort($list, static fn($a, $b) => strnatcasecmp($a['username'], $b['username']));
        Http::json(['users' => $list]);

    case 'admin.user.save':
        $me = Auth::require(true);
        $id = $str('id');
        if ($id === '') {
            if (!Users::validName($str('username')) || Users::byName($str('username')) !== null) {
                Http::fail('invalid_username');
            }
            if (!Users::validPassword($raw('password'), $str('username'))) {
                Http::fail('weak_password');
            }
            $u  = Users::create($str('username'), $raw('password'), $str('role'), true) ?? Http::fail('invalid_username');   // taken meanwhile
            $id = $u['id'];
            Audit::log('user_create', ['target' => $u['username'], 'role' => $u['role']], null, true);
        }
        $target = Users::get($id) ?? Http::fail('not_found', 404);
        $role   = $str('role') === 'admin' ? 'admin' : 'user';
        $on     = (bool)($in['enabled'] ?? true);
        $max    = max(0, min(50, $int('max_sessions')));
        $u = Users::updateKeepingAdmin($id, static function (array &$x) use ($role, $on, $max): void {
            $x['role']         = $role;
            $x['enabled']      = $on;
            $x['max_sessions'] = $max;
        }) ?? Http::fail('not_found', 404);
        if ($u === 'last_admin') {   // no enabled administrator would be left (counted under the lock of the users)
            Http::fail('last_admin');
        }
        $changes = Audit::changes($target, array_intersect_key($u, array_flip(['role', 'enabled', 'max_sessions'])));
        if ($changes !== []) {
            Audit::log('user_update', ['target' => $u['username'], 'changes' => $changes], null, true);
        }
        if (!$on) {
            Sessions::killUser($id);   // its remote desktops end too, not only the web sessions
        }
        Http::json(['user' => Users::view($u)]);

    case 'admin.user.delete':
        $me     = Auth::require(true);
        $target = Users::get($str('id')) ?? Http::fail('not_found', 404);
        if ($target['id'] === $me['id'] || !Users::delete($target['id'])) {
            Http::fail('last_admin');
        }
        Connections::forgetUser($target['id']);
        Sessions::killUser($target['id']);
        Audit::log('user_delete', ['target' => $target['username'], 'role' => $target['role']], null, true);
        Http::json(['ok' => true]);

    case 'admin.user.password':
        Auth::require(true);
        $target = Users::get($str('id')) ?? Http::fail('not_found', 404);
        if (!Users::validPassword($raw('password'), $target['username'])) {
            Http::fail('weak_password');
        }
        Users::setPassword($target['id'], $raw('password'), null, true);
        Sessions::killUser($target['id']);
        Audit::log('password_reset', ['target' => $target['username'], 'saved_rdp_passwords_removed' => true], null, true);
        Http::json(['ok' => true]);

    case 'admin.user.totp_reset':
        Auth::require(true);
        $target = Users::get($str('id')) ?? Http::fail('not_found', 404);
        Users::update($target['id'], static function (array &$x): void {
            $x['totp']     = null;
            $x['passkeys'] = [];
        });
        Audit::log('second_factor_reset', ['target' => $target['username'], 'totp' => !empty($target['totp']['enabled']),
            'passkeys' => count($target['passkeys'] ?? [])], null, true);
        Http::json(['ok' => true]);

    // ---------- administration: connections of the administrator (given to users) and those of each user ----------
    case 'admin.conns':
        Auth::require(true);
        Http::json([
            'managed'   => array_values(array_map([Connections::class, 'view'], Connections::managed())),
            'users'     => array_map(static fn(array $u): array => ['id' => $u['id'], 'username' => $u['username'],
                'conns' => array_values(array_map([Connections::class, 'view'], Connections::own($u['id'])))], array_values(Users::all())),
            'keyboards' => Connections::KEYBOARDS,
        ]);

    case 'admin.conn.save':                // body: conn, users (uids or ["*"]: every user, also those created later)
        Auth::require(true);
        $conn = $obj('conn');
        $old  = is_string($conn['id'] ?? null) ? Connections::managed()[$conn['id']] ?? null : null;
        $r    = Connections::saveManaged($conn, $in['users'] ?? null);
        if (is_string($r)) {
            Http::fail($r);
        }
        $names = array_column(Users::all(), 'username', 'id');   // read once: a read of every user per id took 38 s with 300 users
        $who   = static fn(array $ids): array => in_array('*', $ids, true) ? ['*'] : array_map(static fn($id) => $names[$id] ?? $id, $ids);
        $skip = ['id' => 0, 'created' => 0, 'updated' => 0];
        Audit::log($old === null ? 'admin_conn_create' : 'admin_conn_update', ['conn' => $r['name'], 'host' => $r['host'], 'users' => $who($r['users'])]
            + ($old === null ? ['values' => array_diff_key($r, $skip + ['users' => 0])]
                : ['changes' => Audit::changes(array_diff_key($old, $skip) + ['users' => $who($old['users'] ?? [])], array_diff_key($r, $skip) + ['users' => $who($r['users'])])]),
            null, true);
        Http::json(['conn' => Connections::view($r)]);

    case 'admin.conn.delete':
        Auth::require(true);
        $old = Connections::isManaged($str('id')) ? Connections::deleteManaged($str('id')) : null;
        if ($old === null) {
            Http::fail('not_found', 404);
        }
        Audit::log('admin_conn_delete', ['conn' => $old['name'], 'host' => $old['host']], null, true);
        Http::json(['ok' => true]);

    case 'admin.user.conn.save':           // a user's own connection changed by the administrator; forget: its saved password
        Auth::require(true);
        $target = Users::get($str('uid')) ?? Http::fail('not_found', 404);
        $conn   = $obj('conn');
        $old    = is_string($conn['id'] ?? null) ? Connections::own($target['id'])[$conn['id']] ?? null : null;
        $r      = Connections::adminUpdate($target['id'], $conn, !empty($in['forget']));
        if (is_string($r)) {
            Http::fail($r, $r === 'not_found' ? 404 : 400);
        }
        $skip = ['id' => 0, 'created' => 0, 'updated' => 0];
        Audit::log('admin_user_conn_update', ['target' => $target['username'], 'conn' => $r['name'], 'host' => $r['host'],
            'changes' => Audit::changes(array_diff_key($old ?? [], $skip), array_diff_key($r, $skip), ['pass'])], null, true);
        Http::json(['conn' => Connections::view($r)]);

    case 'admin.user.conn.delete':
        Auth::require(true);
        $target = Users::get($str('uid')) ?? Http::fail('not_found', 404);
        $old    = Connections::own($target['id'])[$str('id')] ?? null;
        if ($old === null || !Connections::delete($target['id'], $str('id'))) {
            Http::fail('not_found', 404);
        }
        Audit::log('admin_user_conn_delete', ['target' => $target['username'], 'conn' => $old['name'], 'host' => $old['host']], null, true);
        Http::json(['ok' => true]);

    case 'admin.settings':
        $me = Auth::require(true);
        if (isset($in['settings'])) {
            $set = $obj('settings');
            // everybody's second factor required, the administrator's first: without it his own next request is refused
            if (!empty($set['totp_required']) && !Users::hasSecondFactor($me)) {
                Http::fail('totp_required_self');
            }
            $old = Settings::all();
            $new = Settings::save($set);
            if (is_string($new)) {
                Http::fail($new);
            }
            if (!Access::clientAllowed(Http::clientIp())) {   // the list would shut everybody out, the administrator too
                Settings::save($old);
                Http::fail('client_lockout');
            }
            Audit::log('settings_update', ['changes' => Audit::changes($old, $new)], null, true);
            if ($old['save_passwords'] && !$new['save_passwords']) {   // no longer allowed: the saved RDP passwords are erased
                Audit::log('rdp_passwords_erased', ['connections' => Connections::forgetAllPasswords()], null, true);
            }
        }
        Http::json(['settings' => Settings::all()]);

    case 'admin.audit':            // search; export = the same search with up to 20,000 rows, sent as they are found
        Auth::require(true);
        $f = array_intersect_key($obj('filter'), array_flip(['from', 'to', 'user', 'event', 'ip', 'q', 'ok']));
        if (array_filter($f, 'is_array') !== []) {
            Http::fail('invalid_input');
        }
        $f      = array_map('strval', $f);
        $export = in_array($str('export'), ['csv', 'json'], true) ? $str('export') : '';
        $offset = max(0, $int('offset'));
        $filter = static fn(array $res): array => array_filter($f, 'strlen') + ['from' => $res['from'], 'to' => $res['to']];
        if ($export) {             // the file itself, sent as the rows are found; the JSON one is followed by its signature in base64
            if ($export === 'json') {
                $signKey();            // made before anything is sent: an error can still be answered
            }
            header('Content-Type: ' . ($export === 'json' ? 'application/octet-stream' : 'application/json; charset=utf-8'));
            header('Cache-Control: no-store');
            $res = Audit::export($f, static function (string $s): void {   // pieces of 64 KB
                echo $s;
            }, $export === 'json');
            Audit::log('audit_export', ['filter' => $filter($res), 'rows' => $res['rows'], 'format' => $export] + array_filter(['sha256' => $res['sha256']]), null, true);
            exit($res['sig'] === null ? '' : base64_encode($res['sig']));
        }
        $res = Audit::search($f, $offset, 100);
        if ($offset === 0) {
            Audit::log('audit_search', ['filter' => $filter($res), 'rows' => $res['total']], null, true);
        }
        Http::json($res + ['days' => Audit::days(), 'retention' => Settings::get('audit_retention_days')]);

    case 'admin.audit.key':        // public key of the signed exports
        Auth::require(true);
        Http::json(['key' => $signKey()]);

    case 'admin.audit.verify':
        Auth::require(true);
        @set_time_limit(600);      // about 5 s per million lines
        $v = Audit::verify();
        Audit::log('audit_verify', ['lines' => $v['lines'], 'problems' => $v['problem_count']], null, $v['ok']);
        Http::json($v);

    case 'admin.traces':           // diagnostic traces of RDP sessions
        Auth::require(true);
        Trace::cleanup();
        $list = [];
        foreach (glob(Trace::dir() . '/*.log') ?: [] as $f) {
            $h      = fopen($f, 'rb');
            $head   = json_decode((string)strstr((string)fgets($h), '{'), true) ?: [];   // first line: "0.000 start {...}"
            fclose($h);
            $sid    = basename($f, '.log');
            $list[] = ['sid' => $sid, 'size' => filesize($f), 'date' => $head['date'] ?? date('c', filemtime($f)), 'user' => $head['user'] ?? '',
                       'conn' => $head['conn'] ?? '', 'host' => $head['host'] ?? '', 'running' => Sessions::info($sid) !== null];
        }
        usort($list, static fn($a, $b) => strcmp($b['date'], $a['date']));
        Http::json(['traces' => $list, 'keep_days' => Trace::KEEP_DAYS]);

    case 'admin.trace':            // ?sid= : content; with delete=1 removes it
        Auth::require(true);
        $file = Sessions::validSid($str('sid')) ? Trace::dir() . '/' . $str('sid') . '.log' : '';
        if ($file === '' || !is_file($file)) {
            Http::fail('not_found', 404);
        }
        $delete = !empty($in['delete']);
        Audit::log($delete ? 'trace_delete' : 'trace_download', ['sid' => $str('sid'), 'size' => filesize($file)], null, true);
        if ($delete) {
            unlink($file);
            Http::json(['ok' => true]);
        }
        Http::json(['name' => 'mudesk-trace-' . $str('sid') . '.log', 'text' => (string)file_get_contents($file)]);

    case 'admin.sessions':
        Auth::require(true);
        Http::json(['sessions' => Sessions::active()]);

    case 'admin.sessions.kill':
        Auth::require(true);
        $info = Sessions::info($str('sid')) ?? Http::fail('not_found', 404);
        Sessions::kill($str('sid'));
        Audit::log('session_kill', ['sid' => $str('sid'), 'target' => $info['user'] ?? '', 'conn' => $info['conn'] ?? ''], null, true);
        Http::json(['ok' => true]);

    default:
        Http::fail('unknown_action', 404);
}
