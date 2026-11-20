<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk\Rdp;

use MuDesk\Text;

/** Network Level Authentication (MS-CSSP) over the TLS connection, using NTLM. */
final class CredSsp
{
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

    /** Returns the server's Windows version from the NTLM challenge ([major, minor, build]) when it sends it. */
    public static function authenticate(Transport $t, string $pubKey, string $user, string $domain, #[\SensitiveParameter] string $password, string $host): ?array
    {
        $ntlm = new Ntlm($user, $domain, $password, 'TERMSRV/' . $host);
        $t->send(self::request(self::VERSION, $ntlm->negotiate()));

        $f       = self::response($t->recvDer());
        $version = min(self::VERSION, Der::toInt($f[0] ?? throw new ProtocolException('NLA: missing version', 'protocol')));
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

        $creds = Der::seq(Der::ctx(0, Der::octet(Text::toUtf16($ntlm->domain))), Der::ctx(1, Der::octet(Text::toUtf16($user))),
                          Der::ctx(2, Der::octet(Text::toUtf16($password))));
        $t->send(self::request($version, null, $ntlm->seal(Der::seq(Der::ctx(0, Der::int(1)), Der::ctx(1, Der::octet($creds))))));
        return $ntlm->serverVersion;
    }
}
