<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

/*
 * Signing in with OpenID Connect (see Oidc):
 *  GET ?start=1  the browser is sent to the provider
 *  GET ?code=&state=  back from it: the session is opened and the browser goes to the page (or to the page with ?oidc_error=)
 */

use MuDesk\{Audit, Auth, Http, Oidc, Settings, Users};

// (no session is opened, and failures are logged only within the anonymous limit, until the provider has vouched for someone)

require __DIR__ . '/../src/bootstrap.php';

Http::guard();
header('Cache-Control: no-store');
$back = static function (string $error = ''): never {
    header('Location: ./' . ($error === '' ? '' : '?oidc_error=' . rawurlencode($error)), true, 302);
    exit;
};
if (!Oidc::enabled() || $_SERVER['REQUEST_METHOD'] !== 'GET') {
    $back('oidc_off');
}
if (isset($_GET['start'])) {
    try {
        $url = Oidc::start();
    } catch (\RuntimeException) {
        Audit::anonymous() && Audit::log('login_fail', ['method' => 'oidc', 'reason' => 'oidc_provider'], null, false);
        $back('oidc_provider');
    }
    header('Location: ' . $url, true, 302);
    exit;
}
$who = Oidc::finish($_GET);
if (is_string($who)) {
    Audit::anonymous() && Audit::log('login_fail', ['method' => 'oidc', 'reason' => $who], null, false);
    $back($who);
}
Auth::start();
$fail = static function (string $reason, string $name) use ($back): never {
    Audit::log('login_fail', ['method' => 'oidc', 'reason' => $reason], $name, false);
    $back($reason === 'not_linked' ? 'oidc_not_linked' : 'oidc_unknown_user');
};
$link = ['iss' => $who['iss'], 'sub' => $who['sub']];
if ($who['link'] !== null) {
    // a user linking their own account, from their profile (the flow was started by api.php's oidc.link, signed in with their
    // password and second step): the provider's account bound to it, if it is no one else's
    $u     = Users::get($who['link']);
    $other = Users::byOidc($link);
    if ($u === null || ($u['source'] ?? null) !== null || $u['role'] === 'admin' || isset($u['oidc']) || ($other !== null && $other['id'] !== $u['id'])) {
        Audit::log('oidc_link', ['target' => $u['username'] ?? '', 'reason' => $other !== null ? 'other_account' : 'not_linkable'], $u['username'] ?? null, false);
        header('Location: ./?oidc_link=failed#/profile', true, 302);   // (said in the profile, where it was asked)
        exit;
    }
    Users::update($u['id'], static function (array &$x) use ($link): void {
        $x['oidc'] ??= $link;
    });
    Audit::log('oidc_link', ['target' => $u['username']], $u['username'], true);
    header('Location: ./?oidc_link=ok#/profile', true, 302);
    exit;
}
// the account linked to the provider's one first, whatever its name now; else by the name the provider gives
$u = Users::byOidc($link) ?? Users::byName($who['name']);
if ($u === null) {
    if (!Settings::get('oidc_create')) {
        $fail('no_user', $who['name']);
    }
    $u = Users::createExternal($who['name'], 'oidc', null, ['oidc' => $link]);
    if ($u === null) {
        $fail(Users::validName($who['name']) ? 'no_user' : 'invalid_name', $who['name']);   // (made meanwhile: signed in next time)
    }
    Audit::log('user_create', ['target' => $who['name'], 'role' => 'user', 'source' => 'oidc'], $who['name'], true);
} elseif ($u['role'] === 'admin' && ($u['source'] ?? '') !== 'oidc') {
    // an administrator keeps his own sign-in and second step, also one linked as a user and made administrator later (before,
    // refused only when first linked: such an account went on signing in through the provider, without its password or
    // second step). Only one the provider made has no other sign-in. (An auditor links and signs in as a user does.)
    $fail('not_linkable', $who['name']);
} elseif (isset($u['oidc'])) {
    // bound to the provider's account it was first used with: a name or e-mail given later to another account is not it
    if (!is_array($u['oidc']) || !hash_equals((string)($u['oidc']['iss'] ?? ''), $link['iss']) || !hash_equals((string)($u['oidc']['sub'] ?? ''), $link['sub'])) {
        $fail('other_account', $who['name']);
    }
} elseif (isset($u['source'])) {
    // a Windows account is the domain's
    $fail('not_linkable', $who['name']);
} else {
    // a local account of the same name, not linked: never taken by its name alone (before, linked and signed in at once,
    // without its password or second step: whoever the provider let use that name was that user). Its user links it from
    // their profile, signed in
    $fail('not_linked', $who['name']);
}
$r = Auth::loginExternal($u, 'oidc');
$back($r === 'ok' ? '' : ($r === 'outside_hours' ? 'outside_hours' : 'oidc_unknown_user'));
