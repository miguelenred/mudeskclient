<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk\Rdp;

use MuDesk\{Config, Text};

/** Network Level Authentication (MS-CSSP) over the TLS connection, using NTLM. */
final class CredSsp
{
    /**
     * CredSSP 2 to 4 (Windows 7 and Server 2008 R2 without the updates of May 2018) bind the TLS key itself, without a nonce:
     * someone in the middle taken for the server could have the client seal what it chose with the session's key, and use it
     * against the real server (CVE-2018-0886, an encryption oracle). Refused before the client's NTLM answer or its binding
     * goes, as Windows' own client does since then; 'credssp_unpatched' => true in config.php allows them (Windows'
     * "Vulnerable"). Before, they were taken whenever the server said so.
     */
    private static function refuseUnpatched(int $version): void
    {
        if ($version < 5 && Config::get('credssp_unpatched') !== true) {
            throw new ProtocolException("NLA: CredSSP version $version, without the CVE-2018-0886 update", 'credssp_unpatched');
        }
    }

    private const VERSION = 6;
    private const STATUS  = [
        0xC000006D => 'auth_failed', 0xC0000064 => 'auth_failed', 0xC000006A => 'auth_failed',
        0xC0000071 => 'password_expired', 0xC0000224 => 'password_expired', 0xC0000234 => 'account_locked',
        0xC0000072 => 'account_disabled', 0xC000006E => 'account_restricted', 0xC000015B => 'logon_not_granted',
        0xC0000193 => 'account_expired',
    ];

    private static function request(int $version, ?string $token = null, ?string $auth = null, ?string $pubKey = null, ?string $nonce = null): string
    {
        return Der::seq(
            Der::ctx(0, Der::int($version)),
            $token === null ? '' : Der::ctx(1, Der::seq(Der::seq(Der::ctx(0, Der::octet($token))))),
            $auth === null ? '' : Der::ctx(2, Der::octet($auth)),
            $pubKey === null ? '' : Der::ctx(3, Der::octet($pubKey)),
            $nonce === null ? '' : Der::ctx(5, Der::octet($nonce)),
        );
    }

    /** @return array<int,string> fields of a TSRequest; throws on errorCode */
    private static function response(string $der): array
    {
        $f = Der::fields(Der::value($der));
        if (isset($f[4])) {
            $code = Der::toInt($f[4]) & 0xffffffff;
            throw new ProtocolException(sprintf('NLA error 0x%08X', $code), self::STATUS[$code] ?? 'auth_failed');
        }
        return $f;
    }

    private static function token(array $f): string
    {
        if (!isset($f[1])) {
            throw new ProtocolException('NLA: missing token', 'auth_failed');
        }
        // negoTokens: SEQUENCE OF SEQUENCE { [0] OCTET STRING }
        $item = Der::value(Der::value($f[1]));
        return Der::value(Der::fields($item)[0] ?? throw new ProtocolException('NLA: missing token', 'auth_failed'));
    }

    /**
     * Returns the server's Windows version from the NTLM challenge ([major, minor, build]) when it sends it. Without
     * $delegate the credentials are not sent: the server's answer to the NTLM authentication (its key, bound) already says
     * the user name and password are right, which is all a sign-in check needs (WindowsLogin).
     */
    public static function authenticate(Transport $t, string $pubKey, string $user, string $domain, #[\SensitiveParameter] string $password, string $host,
                                        bool $delegate = true, bool $kerberos = true): ?array
    {
        self::$kerberos = false;
        // Kerberos first (as Windows' Negotiate), when this server can get a ticket for TERMSRV/<host>; else NTLM. The
        // server refusing the ticket throws 'kerberos': the caller connects again and signs in with NTLM ($kerberos false)
        if ($kerberos && ($krb = Kerberos::forService($user, $domain, $password, 'TERMSRV', $host)) !== null) {
            self::$kerberos = true;
            self::withKerberos($t, $krb, $pubKey, $user, $domain, $password, $delegate);
            return null;
        }
        $ntlm = new Ntlm($user, $domain, $password, 'TERMSRV/' . $host);
        $t->send(self::request(self::VERSION, $ntlm->negotiate()));

        $f       = self::response($t->recvDer());
        $version = min(self::VERSION, Der::toInt($f[0] ?? throw new ProtocolException('NLA: missing version', 'protocol')));
        self::refuseUnpatched($version);
        $auth    = $ntlm->authenticate(self::token($f));
        $nonce   = $version >= 5 ? random_bytes(32) : null;
        $binding = $nonce === null ? $pubKey : hash('sha256', "CredSSP Client-To-Server Binding Hash\0" . $nonce . $pubKey, true);
        $t->send(self::request($version, $auth, null, $ntlm->seal($binding), $nonce));

        $f      = self::response($t->recvDer());
        $server = isset($f[3]) ? $ntlm->unseal(Der::value($f[3])) : null;
        $expect = $nonce === null
            ? chr((ord($pubKey[0]) + 1) & 0xff) . substr($pubKey, 1)
            : hash('sha256', "CredSSP Server-To-Client Binding Hash\0" . $nonce . $pubKey, true);
        if ($server === null || !hash_equals($expect, $server)) {
            throw new ProtocolException('NLA: server key binding mismatch', 'mitm');
        }
        if (!$delegate) {
            return $ntlm->serverVersion;
        }

        $creds = Der::seq(Der::ctx(0, Der::octet(Text::toUtf16($ntlm->domain))), Der::ctx(1, Der::octet(Text::toUtf16($user))),
                          Der::ctx(2, Der::octet(Text::toUtf16($password))));
        $t->send(self::request($version, null, $ntlm->seal(Der::seq(Der::ctx(0, Der::int(1)), Der::ctx(1, Der::octet($creds))))));
        return $ntlm->serverVersion;
    }

    /** Whether the last authenticate() signed in with Kerberos (it gives no Windows version, as NTLM's challenge does). */
    public static bool $kerberos = false;

    /**
     * CredSSP with Kerberos (MS-CSSP 3.1.5): the SPNEGO token with the AP-REP, the server's AP-REP (and mechListMIC) checked,
     * then the public key binding and the credentials sealed with GSS Wrap (the context's key), as with NTLM.
     */
    private static function withKerberos(Transport $t, Kerberos $krb, string $pubKey, string $user, string $domain, #[\SensitiveParameter] string $password,
                                         bool $delegate): void
    {
        try {
            $t->send(self::request(self::VERSION, $krb->initToken('', Kerberos::SEQUENCED)));   // (its answer checked in order)
            $f       = self::response($t->recvDer());
            $version = min(self::VERSION, Der::toInt($f[0] ?? throw new ProtocolException('NLA: missing version', 'protocol')));
            $mic     = $krb->accept(self::token($f));
            if (!$krb->established()) {
                throw new ProtocolException('NLA: no AP-REP from the server', 'kerberos');
            }
        } catch (ProtocolException $e) {
            // the server refused the ticket (an SPN that is another computer's, a DNS alias, its clock...) after the KDC said
            // the password is right: not the user's failure, NTLM instead (an account's own state, e.g. locked, stays)
            throw in_array($e->key, ['auth_failed', 'closed', 'kerberos', 'kerberos_integrity', 'protocol'], true)
                ? new ProtocolException('NLA: Kerberos refused by the server (' . $e->getMessage() . ')', 'kerberos') : $e;
        }
        self::refuseUnpatched($version);
        $nonce   = $version >= 5 ? random_bytes(32) : null;
        $binding = $nonce === null ? $pubKey : hash('sha256', "CredSSP Client-To-Server Binding Hash\0" . $nonce . $pubKey, true);
        $t->send(self::request($version, $mic, null, $krb->wrap($binding), $nonce));

        $f      = self::response($t->recvDer());
        $server = isset($f[3]) ? $krb->unwrap(Der::value($f[3])) : null;
        $expect = $nonce === null
            ? chr((ord($pubKey[0]) + 1) & 0xff) . substr($pubKey, 1)
            : hash('sha256', "CredSSP Server-To-Client Binding Hash\0" . $nonce . $pubKey, true);
        if ($server === null || !hash_equals($expect, $server)) {
            throw new ProtocolException('NLA: server key binding mismatch', 'mitm');
        }
        if (!$delegate) {
            return;
        }
        if (str_contains($user, '@') && $domain === '') {   // (user@domain: the name as given, no domain)
            [$name, $dom] = [$user, ''];
        } else {
            [$name, $dom] = [$user, $domain];
        }
        $creds = Der::seq(Der::ctx(0, Der::octet(Text::toUtf16($dom))), Der::ctx(1, Der::octet(Text::toUtf16($name))),
                          Der::ctx(2, Der::octet(Text::toUtf16($password))));
        $t->send(self::request($version, null, $krb->wrap(Der::seq(Der::ctx(0, Der::int(1)), Der::ctx(1, Der::octet($creds))))));
    }
}
