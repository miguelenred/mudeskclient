<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

use MuDesk\{Access, Audit, Auth, Connections, Crypto, Http, Maintenance, Oidc, Recording, Sessions, Settings, Text, Totp, Users, WebAuthn, WindowsLogin};
use MuDesk\Rdp\Trace;

require __DIR__ . '/../src/bootstrap.php';

Http::guard();
Maintenance::maybe();                    // (at most once an hour: leftovers of killed processes, expired files)
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
        'version' => $u === null ? null : MuDesk\VERSION,   // (only to a signed-in user: not which version runs to anyone)
        'build'   => Http::build(__DIR__ . '/assets'),   // the page compares it with its own: an older page says so
        'second'  => ($p = $_SESSION['pending'] ?? null) === null || !($pu = Users::get($p['uid'])) ? null
                     : ['totp' => !empty($pu['totp']['enabled']), 'passkey' => !empty($pu['passkeys'])],
        // (signed in with OpenID Connect, the provider's own second step counts: nothing to add here)
        'user'    => $u === null ? null : Users::view($u) + ['totp_required' => Settings::get('totp_required') && ($_SESSION['via'] ?? '') !== 'oidc',
                     'reconnect' => Settings::get('reconnect_attempts'), 'save_passwords' => Settings::get('save_passwords') && Auth::dek() !== null,
                     'no_key' => Settings::get('save_passwords') && Auth::dek() === null,   // (a session opened with OpenID Connect)
                     'create_conns' => Auth::createsConns($u), 'record_own' => Auth::recordsOwn($u)]
                     + ($u['role'] === 'admin' ? ['targets_all' => Settings::get('targets')['mode'] === 'all',   // warned in the panel
                        'check_php' => is_file(__DIR__ . '/check.php')] : []),   // the hosting diagnostics left in the web root
        'previous' => $u === null ? null : Auth::takePrevious(),   // right after signing in: last sign-in and failures since
        'session'  => $u === null ? null : ['idle' => Settings::get('session_idle_minutes') * 60, 'max' => Auth::maxLeft()],   // when it expires
        'closed'   => Auth::closedReason(),               // closed by this request: why (idle, max_duration...)
        'oidc'     => $u === null && Oidc::enabled() ? Oidc::label() : null,   // the sign-in screen offers it
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
        if (isset($u['source'])) {             // a Windows or OpenID Connect account: its password is changed there
            Http::fail('external_password');
        }
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

    case 'session.record':         // the user's running session recorded from now on (on), or no longer: administrators, and
        $u    = Auth::require();       // users when the settings allow it (record_users)
        $sid  = $str('sid');
        $info = Sessions::info($sid);
        if (($info['uid'] ?? null) !== $u['id']) {
            Http::fail('not_found', 404);
        }
        $admin = $u['role'] === 'admin';
        if (!Auth::recordsOwn($u)) {
            Http::fail('forbidden', 403);
        }
        if (!empty($info['app'])) {    // (RemoteApp: its connection is not made again in the session, see rdp.php)
            Http::fail('record_unavailable');
        }
        if (empty($in['on']) && !$admin && in_array($info['record_by'] ?? null, ['policy', 'admin'], true)) {
            Http::fail('record_locked', 403);   // (the connection's recording, or an administrator's: only an administrator stops it)
        }
        Sessions::record($sid, !empty($in['on']), $u['username'], $admin) || Http::fail('not_found', 404);
        Http::json(['ok' => true]);

    case 'session.share':          // a link to the user's running session for a guest without an account: view, or control
    case 'session.shares':         // the session's links
    case 'session.unshare':        // a link revoked (id): its guests stop seeing the session at once
        $u    = Auth::require();
        $sid  = $str('sid');
        $info = Sessions::info($sid);
        if (($info['uid'] ?? null) !== $u['id']) {
            Http::fail('not_found', 404);
        }
        $list = static fn(array $i): array => array_values(array_map(static fn($id, $x) => ['id' => (string)$id, 'mode' => $x['mode'], 'created' => $x['created'],
            'expires' => empty($x['expires']) ? null : date('c', (int)$x['expires'])],
            array_keys((array)($i['shares'] ?? [])), (array)($i['shares'] ?? [])));
        if ($action === 'session.unshare') {
            if (!Sessions::unshare($sid, $str('id'))) {
                Http::fail(isset((Sessions::info($sid)['shares'] ?? [])[$str('id')]) ? 'share_failed' : 'not_found', 404);
            }
            Audit::log('share_revoke', ['sid' => $sid, 'conn' => $info['conn'] ?? '', 'share' => $str('id')], null, true);
        } elseif ($action === 'session.share') {
            if (!empty($info['app']) || count((array)($info['shares'] ?? [])) >= Sessions::MAX_SHARES) {
                Http::fail(empty($info['app']) ? 'too_many_shares' : 'share_unavailable');
            }
            $mode    = $str('mode') === 'control' ? 'control' : 'view';
            $minutes = in_array($int('minutes'), [15, 60, 240], true) ? $int('minutes') : 0;   // (0: as long as the session)
            $new     = Sessions::share($sid, $mode, $minutes) ?? Http::fail((string)@file_get_contents(MuDesk\Config::dir('run/' . $sid . '.mode')) === 'file' ? 'share_unavailable' : 'share_failed');
            Audit::log('share_create', ['sid' => $sid, 'conn' => $info['conn'] ?? '', 'share' => $new['id'], 'mode' => $mode] + ($minutes ? ['minutes' => $minutes] : []), null, true);
            Http::json(['id' => $new['id'], 'mode' => $mode, 'link' => $sid . '.' . $new['secret'], 'shares' => $list(Sessions::info($sid) ?? [])]);
        }
        Http::json(['shares' => $list(Sessions::info($sid) ?? [])]);

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
        Http::json(['conns' => $c, 'keyboards' => Connections::KEYBOARDS,       // (and this web session's occasional ones,
            'occasional' => array_map([Connections::class, 'view'], array_values(Connections::occasionals($u['id'])))]);   // for their tabs)

    case 'conns.occasional':        // a connection to connect once, not saved (kept in this web session): for whoever may make them
        $u = Auth::require();
        if (!Auth::createsConns($u)) {
            Http::fail('conns_locked', 403);
        }
        $r = Connections::occasional($obj('conn'), $u['id']);
        if (is_string($r)) {
            Http::fail($r);
        }
        Http::json(['conn' => Connections::view($r)]);

    case 'conns.save':
        $u    = Auth::require();
        $conn = $obj('conn');
        $old  = empty($conn['id']) || is_array($conn['id']) ? [] : (Connections::all($u['id'])[(string)$conn['id']] ?? []);
        $r    = Connections::save($u['id'], $conn, Auth::dek(), Auth::createsConns($u), ($u['role'] ?? '') === 'admin');   // (recording: administrators only)
        if (is_string($r)) {
            Http::fail($r, $r === 'conns_locked' ? 403 : 400);
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
            array_flip(['audio', 'mic', 'clipboard', 'drive', 'printer', 'ask', 'gfx', 'paste', 'bpp', 'full', 'multi']), $obj('opts'))], null, true);
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
        // what is not sent stays as the user has it (a new one: as created). Before, a field left out was taken as its
        // default: changing one thing through the API gave the user any hour, the default sessions, enabled them again or
        // took their role away
        $sent   = array_intersect_key($in, array_flip(['role', 'enabled', 'max_sessions', 'hours']));
        $role   = in_array($str('role'), ['admin', 'auditor'], true) ? $str('role') : 'user';
        $on     = (bool)($in['enabled'] ?? true);
        $max    = max(0, min(50, $int('max_sessions')));
        $hours  = Users::hours($in['hours'] ?? null);
        if ($hours === false) {
            Http::fail('invalid_hours');
        }
        $set = array_intersect_key(['role' => $role, 'enabled' => $on, 'max_sessions' => $max, 'hours' => $hours], $sent);
        $u   = Users::updateKeepingAdmin($id, static function (array &$x) use ($set): void {
            $x = array_replace($x, $set);
        }) ?? Http::fail('not_found', 404);
        if ($u === 'last_admin') {   // no enabled administrator would be left (counted under the lock of the users)
            Http::fail('last_admin');
        }
        $changes = Audit::changes($target + ['hours' => null], array_intersect_key($u, array_flip(['role', 'enabled', 'max_sessions', 'hours'])));
        if ($changes !== []) {
            Audit::log('user_update', ['target' => $u['username'], 'changes' => $changes], null, true);
        }
        if (empty($u['enabled'])) {
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
        if (isset($target['source'])) {
            Http::fail('external_password');
        }
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

    case 'admin.conns.import':             // body: csv, the text of a CSV file (Connections::importCsv)
        Auth::require(true);
        $r = Connections::importCsv($raw('csv'));
        if (is_string($r)) {
            Http::fail($r);
        }
        Audit::log('admin_conns_import', ['created' => count($r['created']), 'rows_refused' => count($r['errors']),
            'conns' => array_slice($r['created'], 0, 50)], null, true);
        Http::json($r);

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
                Settings::restore($old);                       // (as stored: saved again, the secret was encrypted twice)
                Http::fail('client_lockout');
            }
            Audit::log('settings_update', ['changes' => Audit::changes($old, $new, ['oidc_secret'])], null, true);
            // the computer that checks Windows accounts: its certificate pinned when it is set or changed, or when asked again
            if ($new['ad_host'] === '' && $new['ad_cert'] !== '') {
                Settings::pin('ad_cert', '');
            } elseif ($new['ad_host'] !== '' && ($new['ad_host'] !== $old['ad_host'] || $new['ad_port'] !== $old['ad_port'] || !empty($set['ad_repin']) || $new['ad_cert'] === '')) {
                $fp = WindowsLogin::fingerprint() ?? '';
                Settings::pin('ad_cert', $fp);
                Audit::log('ad_cert_pinned', ['host' => $new['ad_host'], 'fingerprint' => $fp !== '' ? $fp : null], null, $fp !== '');
            }
            if ($old['save_passwords'] && !$new['save_passwords']) {   // no longer allowed: the saved RDP passwords are erased
                Audit::log('rdp_passwords_erased', ['connections' => Connections::forgetAllPasswords()], null, true);
            }
        }
        Http::json(['settings' => Settings::view(), 'server_timezone' => date_default_timezone_get(), 'oidc_redirect' => Oidc::redirectUri()]);

    case 'admin.audit':            // search; export = the same search with up to 20,000 rows, sent as they are found
        Auth::requireAuditor();
        $f = array_intersect_key($obj('filter'), array_flip(['from', 'to', 'user', 'event', 'ip', 'q', 'ok']));
        if (array_filter($f, 'is_array') !== []) {
            Http::fail('invalid_input');
        }
        $f      = array_map('strval', $f);
        // 'json': the signed file; 'rows': the same rows unsigned, which the page turns into CSV (formulas neutralized there)
        $export = in_array($str('export'), ['rows', 'json'], true) ? $str('export') : '';
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
            Audit::log('audit_export', ['filter' => $filter($res), 'rows' => $res['rows'], 'format' => $export === 'rows' ? 'csv' : 'json'] + array_filter(['sha256' => $res['sha256']]), null, true);
            exit($res['sig'] === null ? '' : base64_encode($res['sig']));
        }
        $res = Audit::search($f, $offset, 100);
        if ($offset === 0) {
            Audit::log('audit_search', ['filter' => $filter($res), 'rows' => $res['total']], null, true);
        }
        Http::json($res + ['days' => Audit::days(), 'retention' => Settings::get('audit_retention_days')]);

    case 'admin.audit.key':        // public key of the signed exports
        Auth::requireAuditor();
        Http::json(['key' => $signKey()]);

    case 'admin.audit.verify':
        Auth::requireAuditor();
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

    case 'admin.recording':                // a session's recording: who, which connection, when, how long (played with rdp.php?a=replay):
    case 'recording.info':                 // any for administrators and auditors, a user's own (started in their session) for them
        $u = Auth::require();
        Recording::cleanup();
        $info = Recording::info($str('sid'));
        if ($info === null || (!in_array($u['role'], ['admin', 'auditor'], true) && !Recording::ownedBy($info, $u))) {
            Http::fail('not_found', 404);
        }
        Http::json(['recording' => $info]);

    case 'admin.recordings':               // every recording, the newest first, for administrators and auditors: watched, made into a video
        Auth::requireAuditor();
        Recording::cleanup();
        Http::json(['recordings' => array_map(static fn(array $r): array => array_intersect_key($r, array_flip(['id', 'user', 'conn', 'host', 'started',
            'ended', 'seconds', 'bytes', 'sound', 'truncated', 'width', 'height', 'started_as', 'by', 'kind', 'mime', 'running'])), Recording::all()), 'keep_days' => Settings::get('record_days')]);

    case 'recordings.mine':                // the recordings the user started in their own sessions: watched, made into a video, deleted
        $u = Auth::require();
        Recording::cleanup();
        Http::json(['recordings' => array_map(static fn(array $r): array => array_intersect_key($r, array_flip(['id', 'conn', 'host', 'started', 'ended',
            'seconds', 'bytes', 'sound', 'truncated', 'width', 'height', 'kind', 'mime', 'running'])), Recording::mine($u))]);

    case 'recordings.delete':              // one of them deleted (not while it is being recorded); audited
        $u    = Auth::require();
        $info = Recording::info($str('id'));
        if ($info === null || !Recording::ownedBy($info, $u)) {
            Http::fail('not_found', 404);
        }
        if (!Recording::delete($str('id'))) {
            Http::fail('recording_running');
        }
        Audit::log('recording_delete', ['rec' => $str('id'), 'sid' => $info['sid'] ?? '', 'conn' => $info['conn'] ?? ''], null, true);
        Http::json(['ok' => true]);

    case 'admin.recordings.delete':        // an administrator deletes any recording (not while it is being recorded); audited
        Auth::require(true);
        $info = Recording::info($str('id'));
        if ($info === null) {
            Http::fail('not_found', 404);
        }
        if (!Recording::delete($str('id'))) {
            Http::fail('recording_running');
        }
        Audit::log('recording_delete', ['rec' => $str('id'), 'sid' => $info['sid'] ?? '', 'conn' => $info['conn'] ?? '', 'owner' => $info['user'] ?? '',
            'as' => 'admin'], null, true);
        Http::json(['ok' => true]);

    case 'data.exposed':                   // a page read data/web-check.txt: its code, if the file's now, stops the gateway
        Http::json(['stopped' => MuDesk\Exposure::proof($raw('proof'))]);

    case 'admin.backup.prepare':           // a backup asked for: its key (from its password) kept for its download, once
        Auth::require(true);
        if (preg_match_all('/./su', $raw('password')) < 10) {   // (characters, not bytes; no mbstring)
            Http::fail('backup_weak');
        }
        $salt = random_bytes(16);
        $id   = bin2hex(random_bytes(8));
        $with = array_values(array_intersect(MuDesk\Backup::OPTIONAL, array_filter((array)($in['with'] ?? []), 'is_string')));
        foreach (MuDesk\Backup::entries($with) as [$name, $path]) {   // (a file the server cannot read: said now, not a backup that stops)
            if (!is_readable($path)) {
                error_log("MuDeskClient: a backup cannot be made: the web server cannot read $path");
                Http::fail('backup_unreadable');
            }
        }
        $_SESSION['backups'] = array_slice(($_SESSION['backups'] ?? []) + [$id => ['key' => base64_encode(MuDesk\Backup::key($raw('password'), $salt)),
            'salt' => base64_encode($salt), 'with' => $with]], -2, null, true);
        Http::json(['id' => $id]);

    case 'admin.backup.restore':           // the backup uploaded (backup.php) put in place: checked whole first; every session ends
        $a    = Auth::require(true);
        $id   = $str('id');
        $file = MuDesk\Config::dir("tmp/restore-$id.mudeskbackup");
        if (!preg_match('/^[0-9a-f]{16}$/D', $id) || !is_file($file)) {
            Http::fail('not_found', 404);
        }
        @set_time_limit(0);
        $dir = MuDesk\Config::dir("tmp/restore-$id");
        MuDesk\Files::remove($dir);
        @mkdir($dir, 0700, true);
        $m = MuDesk\Backup::extract($file, $raw('password'), $dir);
        if (is_string($m)) {
            MuDesk\Files::remove($dir);
            $m === 'backup_password' || @unlink($file);     // (another password may still open it)
            Audit::log('backup_restore', ['error' => $m], null, false);
            Http::fail($m);
        }
        Auth::release();                                  // (its web session ends too: its file is let go first)
        $done = MuDesk\Backup::restore($dir, array_filter((array)($m['with'] ?? []), 'is_string'));
        MuDesk\Files::remove($dir);
        @unlink($file);
        Audit::log('backup_restore', ['created' => $m['created'] ?? null, 'by' => $m['by'] ?? null, 'version' => $m['version'] ?? null,
            'with' => $m['with'] ?? [], 'restored_by' => $a['username']] + ($done === true ? [] : ['error' => $done]), null, $done === true);
        $done === true || Http::fail($done);
        Http::json(['ok' => true]);

    case 'admin.status':                   // the gateway now: sessions, processes, memory, disk, recent problems (auditors too)
        Auth::requireAuditor();
        Http::json(['status' => MuDesk\Status::now()]);

    case 'admin.usage':                    // the use of the last 30 days, from the audit (auditors too)
        Auth::requireAuditor();
        Http::json(MuDesk\Status::usage(30));

    case 'admin.certs':                    // the certificates fixed for every connection to their computers
        Auth::require(true);
        Http::json(['certs' => Connections::fixedCerts()]);

    case 'admin.cert.fix':                 // one fixed ({host, port, fingerprint}; '' forgets it), for every connection to it
        $a  = Auth::require(true);
        $fp = Connections::fixCert($str('host'), $int('port'), $str('fingerprint'), $a['username']);
        if ($fp === 'cert_fixed') {
            Http::fail('cert_fixed');
        }
        Audit::log($fp === '' ? 'cert_unfixed' : 'cert_fixed', ['host' => strtolower($str('host')), 'port' => $int('port')] + ($fp === '' ? [] : ['fingerprint' => $fp]), null, true);
        Http::json(['certs' => Connections::fixedCerts()]);

    case 'admin.sessions':                 // seen by auditors too; ended only by an administrator
        Auth::requireAuditor();
        Http::json(['sessions' => Sessions::active()]);

    case 'admin.sessions.record':  // any running session recorded from now on (on), or no longer (also one recorded by its connection)
        $a    = Auth::require(true);
        $info = Sessions::info($str('sid')) ?? Http::fail('not_found', 404);
        if (!empty($in['on']) && !empty($info['app'])) {
            Http::fail('record_unavailable');
        }
        Sessions::record($str('sid'), !empty($in['on']), $a['username'], true) || Http::fail('not_found', 404);
        Http::json(['ok' => true]);

    case 'admin.sessions.kill':
        Auth::require(true);
        $info = Sessions::info($str('sid')) ?? Http::fail('not_found', 404);
        Sessions::kill($str('sid'));
        Audit::log('session_kill', ['sid' => $str('sid'), 'target' => $info['user'] ?? '', 'conn' => $info['conn'] ?? ''], null, true);
        Http::json(['ok' => true]);

    default:
        Http::fail('unknown_action', 404);
}
