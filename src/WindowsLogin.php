<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk;

use MuDesk\Rdp\CredSsp;
use MuDesk\Rdp\Der;
use MuDesk\Rdp\ProtocolException;
use MuDesk\Rdp\Transport;

/**
 * Signing in to MuDeskClient with a Windows account (Active Directory): the user name and password are checked the way
 * Remote Desktop checks them before a session, with NLA (CredSSP, NTLM) against an RDP computer of the domain chosen in the
 * settings, and nothing more: the connection is closed once the server has accepted them, without sending them on (no
 * session opens there). No PHP extension or LDAP is needed, so it works on any hosting that reaches that computer; AD groups
 * are not read.
 */
final class WindowsLogin
{
    /** Tests replace the check: fn(string $user, string $domain, string $password): string. */
    public static ?\Closure $check = null;

    public static function enabled(): bool
    {
        return Settings::get('ad_host') !== '';
    }

    /**
     * "ok", or why not: auth_failed (wrong name or password), account_locked, account_disabled, password_expired...,
     * unreachable (the computer did not answer), nla_unavailable, mitm, ad_cert (not the computer pinned in the settings).
     *
     * NTLM proves to each side that the other knows the password, so a computer that is not the domain's (a name pointed
     * elsewhere) would "accept" any password whoever typed it gave it: the certificate of the computer is pinned when the
     * administrator sets it (ad_cert, see fingerprint()) and any other one is refused before the password is used.
     */
    public static function check(string $user, #[\SensitiveParameter] string $password): string
    {
        $domain = (string)Settings::get('ad_domain');
        if (self::$check !== null) {
            return (self::$check)($user, $domain, $password);
        }
        for ($kerberos = true; ; $kerberos = false) {
            try {
                $t = self::secured();
                if (is_string($t)) {
                    return $t;
                }
                [$t, $cert, $tlsKey] = $t;
                $pin  = (string)Settings::get('ad_cert');
                if ($pin === '' || !hash_equals($pin, (string)openssl_x509_fingerprint($cert, 'sha256'))) {
                    fclose($t->sock);
                    return 'ad_cert';
                }
                Transport::tlsWorked($tlsKey, $t->tlsInfo()['protocol']);
                CredSsp::authenticate($t, Der::subjectPublicKey($cert), $user, $domain, $password, (string)Settings::get('ad_host'), false, $kerberos);
                fclose($t->sock);
                return 'ok';
            } catch (ProtocolException $e) {
                if ($e->key === 'kerberos' && $kerberos) {
                    continue;                          // the computer refused the Kerberos ticket: again, with NTLM
                }
                return $e->key === 'closed' ? 'auth_failed' : $e->key;   // Windows drops the connection on a wrong password
            }
        }
    }

    /** The SHA-256 of the certificate of the computer of the settings (pinned when the administrator sets it), or null. */
    public static function fingerprint(): ?string
    {
        try {
            $t = self::secured();
            if (is_string($t)) {
                return null;
            }
            $fp = (string)openssl_x509_fingerprint($t[1], 'sha256');
            fclose($t[0]->sock);
            return $fp !== '' ? $fp : null;
        } catch (ProtocolException) {
            return null;
        }
    }

    /**
     * The connection to the computer of the settings with its TLS handshake: [Transport, certificate, its key for
     * Transport::tlsWorked()]; or why not (as connect()). TLS 1.2 or later first, TLS 1.0 and
     * triple DES on a new connection only to a computer never seen with it (as Client: see Transport::tlsSeen()).
     */
    private static function secured(): array|string
    {
        $key  = strtolower((string)Settings::get('ad_host')) . ':' . (int)Settings::get('ad_port');
        $seen = Transport::tlsSeen($key);
        for ($legacy = $seen === 'legacy'; ; $legacy = true) {
            $t = self::connect();
            if (is_string($t)) {
                return $t;
            }
            try {
                return [$t, $t->startTls($legacy), $key];
            } catch (ProtocolException $e) {
                fclose($t->sock);
                if ($legacy || $seen !== null) {
                    throw $e;
                }
            }
        }
    }

    /** The connection to the computer of the settings, NLA agreed (X.224); or why not: unreachable, nla_unavailable. */
    private static function connect(): Transport|string
    {
        $host = (string)Settings::get('ad_host');
        $ip   = Access::addresses($host)[0] ?? null;          // (IPv6 too: gethostbyname() only gave IPv4 addresses)
        if ($ip === null) {
            return 'unreachable';
        }
        $t = new Transport($ip, (int)Settings::get('ad_port'));
        $x = "\xe0\x00\x00\x00\x00\x00" . pack('CCvV', 1, 0, 8, 3);   // X.224 Connection Request asking for NLA
        $t->send("\x03\x00" . pack('n', strlen($x) + 5) . chr(strlen($x)) . $x);
        $r = $t->recvPdu();
        if (strlen($r) < 19 || ord($r[5]) !== 0xD0 || ord($r[11]) !== 2 || unpack('V', $r, 15)[1] !== 2) {
            fclose($t->sock);
            return 'nla_unavailable';                        // no NLA there: nothing to check the password with
        }
        return $t;
    }

    /** The name of the MuDeskClient user for what was typed: "user", "DOMAIN\user" or "user@domain" give "user". */
    public static function userName(string $typed): string
    {
        $typed = trim($typed);
        if (str_contains($typed, '\\')) {
            $typed = substr($typed, strrpos($typed, '\\') + 1);
        }
        return str_contains($typed, '@') ? substr($typed, 0, strrpos($typed, '@')) : $typed;
    }
}
