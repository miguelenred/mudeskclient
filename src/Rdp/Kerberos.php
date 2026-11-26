<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk\Rdp;

use MuDesk\{Access, Config, Settings};

/**
 * Kerberos 5 client (RFC 4120, MS-KILE), in plain PHP: the user's ticket from the domain's KDC (AS exchange, with
 * pre-authentication by an encrypted timestamp), a service ticket (TGS exchange), and the AP-REQ that signs in to the
 * service, in a GSS-API token (RFC 4121) inside SPNEGO (RFC 4178), as Windows' Negotiate sends it; the AP-REP checked, and
 * then GSS Wrap and Unwrap with the context's key (CredSSP). AES (types 17 and 18: what Active Directory gives every
 * account since Windows Server 2008) and RC4 (23, RFC 4757: a Windows Server 2003 domain's only keys, an account whose
 * password is older than its domain's AES, a service that has no AES key; its GSS tokens are RFC 4757's) only if the
 * administrator allows it (types()); the KDC over TCP port 88. A user@domain whose suffix is not its domain's (an alternate UPN suffix) is found as Windows does: asked
 * of the KDC of the domain or of the computer, which says which domain of the forest has it (KDC_ERR_WRONG_REALM).
 *
 * Throws ProtocolException: 'kerberos' (it could not be used: no KDC, an unknown user or service, a type not supported...;
 * the caller signs in with NTLM instead) or 'auth_failed' / 'account_locked' / 'account_disabled' / 'password_expired' /
 * 'account_expired' (the KDC's own answer about the user: never tried again with NTLM, which would count another attempt).
 */
final class Kerberos
{
    /** @var float seconds for the whole sign-in to the KDC (the tests shorten it) */
    public static float $wait = 8.0;
    private const OID_KRB5    = "\x2A\x86\x48\x86\xF7\x12\x01\x02\x02";            // 1.2.840.113554.1.2.2
    private const OID_MS_KRB5 = "\x2A\x86\x48\x82\xF7\x12\x01\x02\x02";            // 1.2.840.48018.1.2.2
    private const OID_NTLM    = "\x2B\x06\x01\x04\x01\x82\x37\x02\x02\x0A";        // 1.3.6.1.4.1.311.2.2.10
    private const OID_SPNEGO  = "\x2B\x06\x01\x05\x05\x02";                        // 1.3.6.1.5.5.2

    /** KRB-ERROR codes the KDC gives about the user (RFC 4120 7.5.9): said as such, never retried with NTLM. */
    private const USER_ERRORS = [24 => 'auth_failed', 18 => 'account_disabled', 23 => 'password_expired', 12 => 'account_restricted'];

    public string $realm;
    /** @var int clock offset to the KDC, in seconds (taken from a KRB_AP_ERR_SKEW error) */
    private int $skew = 0;
    private ?array $tgt = null;          // [ticket DER, session key, etype]
    private ?array $service = null;      // [ticket DER, session key, etype]
    private ?string $subkey = null;      // the initiator's subkey (in the authenticator)
    private int $etype = KerberosCrypto::AES256;
    private int $seq = 0;                // the initiator's sequence number
    /** @var string|null the context's key once the AP-REP came: the acceptor's subkey, else the initiator's */
    private ?string $key = null;
    private bool $acceptorSubkey = false;
    private int $peerSeq = 0;            // the service's next sequence number (its AP-REP's, then one more each message)
    private bool $sequenced = false;     // replay and sequence detection asked: the service's messages checked in order
    private string $mechTypes = '';      // the mechTypes list sent, for the mechListMIC
    private ?string $cname = null;       // the client's name as the KDC gave it back (its canonical form)
    private float $deadline = 0.0;       // when the sign-in to the KDC gives up
    private bool $kdcFixed = false;      // a KDC answered: only it asked from then on
    private array $apTime = ['', 0];     // the AP-REQ's authenticator's ctime and cusec (its AP-REP gives them back)

    /**
     * $user (without realm), $realm (the domain's DNS name, upper case), the service principal ('HTTP/rdg.empresa.local',
     * 'TERMSRV/rdsh1.empresa.local'). $kdc: [host, port] list to try; empty: found in the configuration or the DNS.
     */
    public function __construct(private string $user, string $realm, #[\SensitiveParameter] private string $password, private string $spn,
                                private array $kdc = [])
    {
        $this->realm = strtoupper($realm);
        if ($this->kdc === []) {
            $this->kdc = self::kdcs($this->realm);
        }
        if ($this->kdc === []) {
            throw new ProtocolException("Kerberos: no KDC for {$this->realm}", 'kerberos');
        }
    }

    /**
     * The realms to sign in to as $user of $domain, for a service on $host, in order: the suffix of a user@domain, the domain
     * when it is a DNS name, the host's DNS suffix (a NetBIOS domain names no realm); the first one with a KDC is asked (an
     * alternate UPN suffix has none: its domain's or the computer's then). None when $host is an IP address or localhost
     * (services are known to Kerberos by name).
     */
    public static function realmsFor(string $user, string $domain, string $host): array
    {
        $local  = trim($domain) === '.';
        $host   = strtolower(rtrim($host, '.'));
        $domain = strtolower(rtrim(trim($domain), '.'));
        $labels = explode('.', $host);
        // none for an address, localhost, or a local account (the domain ".", or the computer's own name: a domain account
        // of the same name would be asked, and a wrong password counted against it)
        if ($host === '' || $local || @inet_pton(trim($host, '[]')) !== false || $host === 'localhost'
            || ($domain !== '' && !str_contains($domain, '.') && $domain === $labels[0])) {
            return [];
        }
        $suffix = implode('.', array_slice($labels, 1));
        $realms = [];
        if (str_contains($user, '@')) {
            $realms[] = substr($user, strrpos($user, '@') + 1);
        }
        if (str_contains($domain, '.')) {
            $realms[] = $domain;
        } elseif ($suffix !== '' && ($domain !== '' ? in_array($domain, array_slice($labels, 1), true) : str_contains($user, '@'))) {
            // a NetBIOS domain: the host's DNS suffix when it names that domain (EMPRESA, pc.empresa.local), never without a
            // domain (a local account); a user@domain's account looked for there by that name only (an alternate UPN suffix)
            $realms[] = $suffix;
        }
        return array_values(array_unique(array_filter(array_map(static fn($r) => strtoupper(rtrim($r, '.')), $realms), static fn($r) => $r !== '')));
    }

    /**
     * The KDCs of a realm: the configuration's (config.php 'kerberos_kdc' => ['REALM' => 'host[:port]' or a list]), else the
     * DNS's SRV records (_kerberos._tcp.<realm>, then Active Directory's _kerberos._tcp.dc._msdcs.<realm>), unless config.php
     * says 'kerberos_dns' => false. None: NTLM (the realm's own name is not tried: a public domain's points to its website).
     * The DNS's are taken only on Kerberos' port 88 and at addresses that are not this computer, link-local or a cloud's
     * metadata (the realm can come from what a user typed: user@domain), and are reached at the address checked.
     */
    public static function kdcs(string $realm): array
    {
        $conf = (array)(Config::get('kerberos_kdc') ?? []);
        $hosts = [];
        foreach ((array)($conf[$realm] ?? $conf[strtolower($realm)] ?? []) as $h) {
            $h = trim((string)$h);
            if (substr_count($h, ':') > 1 && $h[0] !== '[') {
                $hosts[] = [$h, 88];                                    // (an IPv6 address without brackets: no port)
            } elseif (preg_match('~^\[?([^\[\]]+?)\]?(?::(\d+))?$~', $h, $m)) {
                $hosts[] = [$m[1], (int)($m[2] ?? 88) ?: 88];
            }
        }
        if ($hosts !== [] || Config::get('kerberos_dns') === false || !preg_match('~^[A-Z0-9-]+(\.[A-Z0-9-]+)+$~i', $realm)) {
            return $hosts;
        }
        foreach (['_kerberos._tcp.', '_kerberos._tcp.dc._msdcs.'] as $prefix) {
            $srv = @dns_get_record($prefix . strtolower($realm), DNS_SRV) ?: [];
            usort($srv, static fn($a, $b) => [$a['pri'] ?? 0, -($a['weight'] ?? 0)] <=> [$b['pri'] ?? 0, -($b['weight'] ?? 0)]);
            foreach (array_slice($srv, 0, 4) as $r) {
                if (is_string($r['target'] ?? null) && $r['target'] !== '' && $r['target'] !== '.' && (int)($r['port'] ?? 0) === 88) {
                    foreach (array_slice(Access::addresses(rtrim($r['target'], '.')), 0, 1) as $ip) {
                        if (!Access::unsafe($ip)) {
                            $hosts[] = [$ip, 88];
                        }
                    }
                }
            }
            if ($hosts !== []) {
                break;
            }
        }
        return $hosts;
    }

    // ------------------------------------------------------------------ ASN.1 (DER) of Kerberos

    private static function str(string $s): string
    {
        return Der::tlv(0x1B, $s);                     // KerberosString: GeneralString (IA5 in practice)
    }

    private static function time(int $t): string
    {
        return Der::tlv(0x18, gmdate('YmdHis', $t) . 'Z');
    }

    private static function principal(int $type, string ...$parts): string
    {
        return Der::seq(Der::ctx(0, Der::int($type)), Der::ctx(1, Der::seq(...array_map(self::str(...), $parts))));
    }

    private static function bits(int $flags): string
    {
        return Der::tlv(0x03, "\0" . pack('N', $flags));
    }

    private static function encrypted(int $etype, string $cipher, ?int $kvno = null): string
    {
        return Der::seq(Der::ctx(0, Der::int($etype)), $kvno !== null ? Der::ctx(1, Der::int($kvno)) : '', Der::ctx(2, Der::octet($cipher)));
    }

    private static function app(int $n, string $content): string
    {
        return Der::tlv(0x60 | $n, $content);
    }

    /** The context fields of an element that is [APPLICATION n] SEQUENCE {...} or SEQUENCE {...}. */
    private static function fieldsOf(string $element, ?int $app = null): array
    {
        [$tag, $content] = Der::read(new Reader($element));
        if ($app !== null) {
            if ($tag !== (0x60 | $app)) {
                throw new ProtocolException(sprintf('Kerberos: message 0x%x where [APPLICATION %d] was expected', $tag, $app), 'kerberos');
            }
            [, $content] = Der::read(new Reader($content));
        }
        return Der::fields($content);
    }

    /** The content of a primitive inside a context field (an INTEGER, a string...). */
    private static function inner(array $f, int $n): string
    {
        if (!isset($f[$n])) {
            throw new ProtocolException("Kerberos: field $n missing", 'kerberos');
        }
        return Der::value($f[$n]);
    }

    private static function int(array $f, int $n): int
    {
        return Der::toInt($f[$n] ?? throw new ProtocolException("Kerberos: field $n missing", 'kerberos'));
    }

    /** [etype, cipher] of an EncryptedData. */
    private static function encData(string $seqElement): array
    {
        $f = self::fieldsOf($seqElement);
        return [self::int($f, 0), self::inner($f, 2)];
    }

    /** [keytype, key] of an EncryptionKey: a session key or a subkey, of a type in use (types()). */
    private static function encKey(string $seqElement): array
    {
        $f    = self::fieldsOf($seqElement);
        $type = self::int($f, 0);
        if (!in_array($type, self::types(), true)) {      // (RC4 while it is off: not asked for)
            throw new ProtocolException("Kerberos: key of type $type", 'kerberos');
        }
        return [$type, self::inner($f, 1)];
    }

    // ------------------------------------------------------------------ the KDC

    /**
     * One request to the KDC (TCP: a 4-byte length first) and its answer: the first KDC of the realm that answers, then only
     * that one (a password checked once, not again by the next KDC after a slow answer). The whole sign-in to the KDC has
     * $wait seconds (a slow or silent KDC: NTLM instead).
     */
    private function kdcCall(string $req): string
    {
        $last = null;
        foreach ($this->kdcFixed ? array_slice($this->kdc, 0, 1) : $this->kdc as $i => [$host, $port]) {
            $left = $this->deadline - microtime(true);
            if ($left <= 0) {
                break;
            }
            $s = @stream_socket_client('tcp://' . (str_contains($host, ':') ? "[$host]" : $host) . ":$port", $en, $es, min(3.0, $left));
            if ($s === false) {
                $last = "$host:$port $es";
                continue;
            }
            @fwrite($s, pack('N', strlen($req)) . $req);         // (a KDC that resets the connection: no answer, not a notice)
            $head = $this->readFor($s, 4);
            $n    = strlen($head) === 4 ? unpack('N', $head)[1] : 0;
            $body = $n > 0 && $n < 1 << 20 ? $this->readFor($s, $n) : '';
            fclose($s);
            if (strlen($body) === $n && $n > 0) {
                if (!$this->kdcFixed) {
                    $this->kdc = [$this->kdc[$i], ...array_values(array_diff_key($this->kdc, [$i => 0]))];
                    $this->kdcFixed = true;
                }
                return $body;
            }
            $last = "$host:$port no answer";
        }
        throw new ProtocolException('Kerberos: KDC unreachable (' . ($last ?? 'no time left') . ')', 'kerberos');
    }

    /** $n bytes from the KDC, while the sign-in's time lasts (fewer: it ran out). */
    private function readFor($s, int $n): string
    {
        $buf = '';
        stream_set_blocking($s, false);
        while (strlen($buf) < $n && ($left = $this->deadline - microtime(true)) > 0) {
            $r = [$s];
            $w = $e = null;
            if (@stream_select($r, $w, $e, (int)$left, (int)(($left - (int)$left) * 1e6)) < 1) {
                break;
            }
            $d = fread($s, $n - strlen($buf));
            if ($d === false || ($d === '' && feof($s))) {
                break;
            }
            $buf .= $d;
        }
        return $buf;
    }

    /** A KRB-ERROR: [code, e-data, stime, crealm]; null when $msg is not one. */
    private static function krbError(string $msg): ?array
    {
        if ($msg === '' || ord($msg[0]) !== 0x7E) {
            return null;
        }
        $f = self::fieldsOf($msg, 30);
        $stime = isset($f[4]) ? strtotime(substr(Der::value($f[4]), 0, 14) . ' UTC') : false;
        return [self::int($f, 6), isset($f[12]) ? Der::value($f[12]) : '', $stime === false ? null : $stime, isset($f[7]) ? Der::value($f[7]) : null];
    }

    /**
     * A KDC's error said as what it means: about the user (its password, its account: the NTSTATUS Windows adds in the
     * KERB-EXT-ERROR of its e-data says which, MS-KILE 2.2.1), else 'kerberos' (NTLM tried instead).
     */
    private function fail(int $code, string $where, string $edata = ''): never
    {
        $key = self::USER_ERRORS[$code] ?? 'kerberos';
        if ($key !== 'kerberos' && ($status = self::extStatus($edata)) !== null) {
            $key = [0xC0000234 => 'account_locked', 0xC0000072 => 'account_disabled', 0xC0000193 => 'account_expired',
                    0xC0000071 => 'password_expired', 0xC0000224 => 'password_expired', 0xC000006E => 'account_restricted',
                    0xC000006F => 'account_restricted', 0xC0000070 => 'account_restricted'][$status] ?? $key;
        }
        throw new ProtocolException("Kerberos: KDC error $code ($where)", $key);
    }

    /**
     * The NTSTATUS of a KERB-ERROR-DATA with a KERB-EXT-ERROR (data-type 3: status, reserved, flags; little-endian), or of
     * a METHOD-DATA with it as PA-DATA of type 3 (as older Windows sends it: the same two fields, one level deeper).
     */
    private static function extStatus(string $edata): ?int
    {
        try {
            $c = Der::value($edata);
            $items = [$c];
            if ($c !== '' && ord($c[0]) === 0x30) {    // METHOD-DATA: SEQUENCE OF PA-DATA
                $items = [];
                for ($r = new Reader($c); $r->left() > 0;) {
                    $items[] = Der::read($r)[1];
                }
            }
            foreach ($items as $item) {
                $f = Der::fields($item);
                if (Der::toInt($f[1] ?? '') === 3 && strlen($v = Der::value($f[2] ?? '')) >= 4) {
                    return unpack('V', $v)[1];
                }
            }
        } catch (\Throwable) {
        }
        return null;
    }

    private function now(): int
    {
        return time() + $this->skew;
    }

    /**
     * The encryption types used: AES, and RC4 only if the administrator allows it (Settings, kerberos_rc4: a Windows Server
     * 2003 domain, an account whose password is older than its domain's AES keys; without it they sign in with NTLM). Off by
     * default:
     * anyone between this server and the KDC could answer that the account has only RC4, and the timestamp encrypted with
     * that key (the password's NT hash, without salt) can be tried against guessed passwords thousands of times faster than
     * AES's (salted, 4096 rounds).
     */
    private static function types(): array
    {
        return Settings::get('kerberos_rc4') === true
            ? [KerberosCrypto::AES256, KerberosCrypto::AES128, KerberosCrypto::RC4]
            : [KerberosCrypto::AES256, KerberosCrypto::AES128];
    }

    /**
     * The KDC-REQ-BODY of a request: options, client or service, realm, till (a day), nonce, the encryption types (AES first,
     * RC4 last when it is used: a KDC that has AES gives it).
     */
    private function body(int $options, ?string $cname, string $sname, int $nonce): string
    {
        return Der::seq(
            Der::ctx(0, self::bits($options)),
            $cname !== null ? Der::ctx(1, $cname) : '',
            Der::ctx(2, self::str($this->realm)),
            Der::ctx(3, $sname),
            Der::ctx(5, self::time($this->now() + 86400)),
            Der::ctx(7, Der::int($nonce)),
            Der::ctx(8, Der::seq(...array_map(static fn(int $t): string => Der::int($t), self::types()))),
        );
    }

    /**
     * The user's ticket (AS exchange): asked without pre-authentication first, to learn the salt and the type of the user's
     * key (PA-ETYPE-INFO2), then with the timestamp encrypted with it; a clock too far from the KDC's is corrected once. A KDC
     * that says the account is in another domain of the forest (KDC_ERR_WRONG_REALM, RFC 6806 7): that domain's KDCs asked.
     */
    private function asExchange(): void
    {
        for ($hop = 0; ($realm = $this->asByName()) !== null; $hop++) {
            if ($hop >= 2) {
                throw new ProtocolException('Kerberos: too many realm referrals', 'kerberos');
            }
            $this->realm = $realm;
            $this->kdc = self::kdcs($realm) ?: throw new ProtocolException("Kerberos: no KDC for $realm", 'kerberos');
            $this->kdcFixed = false;
        }
    }

    /** One realm's AS exchange; returns the realm the KDC refers the account to, or null when it gave the ticket. */
    private function asByName(): ?string
    {
        // a user@domain as an enterprise name (NT-ENTERPRISE, RFC 6806), the KDC finding the account by its UPN; a KDC that
        // does not know it so (an unknown client) asked again with the name before the @, only in the realm the @ names
        // (another realm's account of that name is not this user's)
        if (str_contains($this->user, '@')) {
            try {
                return $this->asRequest(self::principal(10, $this->user));
            } catch (ProtocolException $e) {
                if ($e->getMessage() !== 'Kerberos: KDC error 6 (AS)' || strcasecmp(rtrim(substr($this->user, strrpos($this->user, '@') + 1), '.'), $this->realm) !== 0) {
                    throw $e;
                }
                $this->user = substr($this->user, 0, strrpos($this->user, '@'));
            }
        }
        return $this->asRequest(self::principal(1, $this->user));
    }

    private function asRequest(string $cname): ?string
    {
        $sname = self::principal(2, 'krbtgt', $this->realm);
        $pac   = Der::seq(Der::ctx(1, Der::int(128)), Der::ctx(2, Der::octet(Der::seq(Der::ctx(0, Der::tlv(0x01, "\xFF"))))));   // PA-PAC-REQUEST
        $req   = fn(string $padata, int $nonce): string => self::app(10, Der::seq(Der::ctx(1, Der::int(5)), Der::ctx(2, Der::int(10)),
            Der::ctx(3, Der::seq($padata, $pac)), Der::ctx(4, $this->body(0x40810010, $cname, $sname, $nonce))));
        $salt0   = $this->realm . $this->user;                          // (the default salt)
        $nonce   = random_int(1, 0x7FFFFFFF);
        $rep     = $this->kdcCall($req('', $nonce));
        $info    = $userKey = $keyInfo = null;
        for ($try = 0; ($err = self::krbError($rep)) !== null; $try++) {
            [$code, $edata, $stime, $crealm] = $err;
            if ($try >= 3) {
                $this->fail($code, 'AS', $edata);
            } elseif ($code === 68 && $crealm !== null && $crealm !== '' && strcasecmp($crealm, $this->realm) !== 0) {
                return strtoupper($crealm);                              // KDC_ERR_WRONG_REALM: the account's realm
            } elseif ($code === 37 && $stime !== null) {                // KRB_AP_ERR_SKEW: the KDC's time taken
                // (within a day: the error is not authenticated, and a KDC so far off is broken; before, any time taken)
                if (abs($stime - time()) > 86400) {
                    throw new ProtocolException('Kerberos: the KDC\'s clock is more than a day away', 'kerberos');
                }
                $this->skew = $stime - time();
            } elseif ($code === 25) {                                    // KDC_ERR_PREAUTH_REQUIRED: its salt and type
                $info = self::etypeInfo($edata, $salt0) ?? throw new ProtocolException('Kerberos: no key of a type in use for the user', 'kerberos');
            } else {
                $this->fail($code, 'AS', $edata);
            }
            $nonce = random_int(1, 0x7FFFFFFF);
            if ($info === null) {
                $rep = $this->kdcCall($req('', $nonce));
                continue;
            }
            [$etype, $salt, $iter] = $info;
            // the key of the type and salt the last PREAUTH_REQUIRED named (before, the first one's stayed when a later one named
            // others), made again only when they change (a clock corrected asks with the same one)
            if ($keyInfo !== $info) {
                [$userKey, $keyInfo] = [KerberosCrypto::userKey($etype, $this->password, $salt, $iter), $info];
            }
            $ts  = Der::seq(Der::ctx(0, self::time($this->now())), Der::ctx(1, Der::int(random_int(0, 999999))));
            $enc = Der::seq(Der::ctx(1, Der::int(2)), Der::ctx(2, Der::octet(self::encrypted($etype, KerberosCrypto::encryptFor($etype, $userKey, 1, $ts)))));
            $rep = $this->kdcCall($req($enc, $nonce));
        }
        $f = self::fieldsOf($rep, 11);
        [$etype, $cipher] = self::encData($f[6] ?? throw new ProtocolException('Kerberos: AS-REP without its part', 'kerberos'));
        // only in a type in use: RC4 only when allowed (before, a reply in RC4 was opened with the password's NT hash whatever
        // the setting, a KDC or someone in between choosing it without pre-authentication)
        if (!in_array($etype, self::types(), true)) {
            throw new ProtocolException('Kerberos: the AS-REP in an encryption type not in use', 'kerberos');
        }
        if ($userKey === null || $info[0] !== $etype) {  // no pre-authentication asked (the salt in the reply, if any), or the reply in another key
            [, $salt, $iter] = self::etypeInfo($f[2] ?? '', $salt0, $etype) ?? [$etype, $info[1] ?? $salt0, $info[2] ?? 4096];
            $userKey = KerberosCrypto::userKey($etype, $this->password, $salt, $iter);
        }
        try {
            $plain = KerberosCrypto::decryptFor($etype, $userKey, 3, $cipher);
        } catch (ProtocolException) {                                   // (not the user's key: a wrong password)
            throw new ProtocolException('Kerberos: the AS-REP does not open with the password', 'auth_failed');
        }
        $this->tgt = [$f[5] ?? '', ...self::repPart($plain, $nonce)];
        if (isset($f[3], $f[4])) {                                      // its name and realm as the KDC has them, from then on
            $this->realm = strtoupper(Der::value($f[3]));
            $this->cname = $f[4];
        }
        return null;
    }

    /**
     * [etype, salt, iterations] from a METHOD-DATA's PA-ETYPE-INFO2 (19): the entry of type $want, else the first AES one,
     * else the RC4 one (its key has no salt), of the types in use (types(): with RC4 off, a KDC, or someone in between, that
     * says the account has only RC4 gets no timestamp encrypted with it).
     */
    private static function etypeInfo(string $methodData, string $defaultSalt, ?int $want = null): ?array
    {
        if ($methodData === '') {
            return null;
        }
        $found = [];
        $r = new Reader(Der::value($methodData));
        while ($r->left() > 0) {
            [, $pa] = Der::read($r);
            $p = Der::fields($pa);
            if (!isset($p[2]) || Der::toInt($p[1] ?? Der::int(0)) !== 19) {
                continue;
            }
            $e = new Reader(Der::value(Der::value($p[2])));
            while ($e->left() > 0) {
                [, $entry] = Der::read($e);
                $x = Der::fields($entry);
                if (!isset($x[0])) {
                    continue;
                }
                $etype = Der::toInt($x[0]);
                $iter  = isset($x[2]) && strlen($s = Der::value($x[2])) === 4 ? unpack('N', $s)[1] : 4096;
                // (the count comes from a message anyone can send: fewer than RFC 3962's 4096 would make the timestamp easy
                // to break, many more would keep this server busy; Windows never sends one)
                if (in_array($etype, self::types(), true) && !isset($found[$etype])
                    && $iter >= 4096 && $iter <= 100000) {
                    $found[$etype] = [$etype, isset($x[1]) ? Der::value($x[1]) : $defaultSalt, $iter];
                }
            }
        }
        if ($want !== null) {
            return $found[$want] ?? null;
        }
        foreach ($found as $etype => $info) {
            if ($etype !== KerberosCrypto::RC4) {
                return $info;                    // (the first AES one: the KDC lists them in the order it prefers)
            }
        }
        return $found[KerberosCrypto::RC4] ?? null;
    }

    /** [session key, its etype] of an EncASRepPart / EncTGSRepPart (Windows sends 26 for both), its nonce checked. */
    private static function repPart(string $plain, int $nonce): array
    {
        $tag = ord($plain[0] ?? "\0");
        if ($tag !== 0x79 && $tag !== 0x7A) {
            throw new ProtocolException('Kerberos: unexpected reply part', 'kerberos');
        }
        $f = self::fieldsOf($plain, $tag & 0x1F);
        if (self::int($f, 2) !== $nonce) {
            throw new ProtocolException('Kerberos: nonce mismatch', 'kerberos');
        }
        [$keytype, $key] = self::encKey($f[0] ?? throw new ProtocolException('Kerberos: reply part without its key', 'kerberos'));
        if (strlen($key) !== KerberosCrypto::keyLength($keytype)) {
            throw new ProtocolException('Kerberos: session key of the wrong length', 'kerberos');
        }
        return [$key, $keytype];
    }

    /** An authenticator, encrypted for key usage $usage with $key. */
    private function authenticator(string $key, int $etype, int $usage, ?string $cksum, ?string $subkey, ?int $seq, string $authData = ''): string
    {
        $now = $this->now();
        $this->apTime = [gmdate('YmdHis', $now) . 'Z', random_int(0, 999999)];
        $a = self::app(2, Der::seq(
            Der::ctx(0, Der::int(5)),
            Der::ctx(1, self::str($this->realm)),
            Der::ctx(2, $this->cname ?? self::principal(1, $this->user)),
            $cksum !== null ? Der::ctx(3, $cksum) : '',
            Der::ctx(4, Der::int($this->apTime[1])),
            Der::ctx(5, Der::tlv(0x18, $this->apTime[0])),
            $subkey !== null ? Der::ctx(6, Der::seq(Der::ctx(0, Der::int($etype)), Der::ctx(1, Der::octet($subkey)))) : '',
            $seq !== null ? Der::ctx(7, Der::int($seq)) : '',
            $authData !== '' ? Der::ctx(8, $authData) : '',
        ));
        return self::encrypted($etype, KerberosCrypto::encryptFor($etype, $key, $usage, $a));
    }

    /** The service ticket (TGS exchange): asked with the user's ticket and an authenticator over the request's body. */
    private function tgsExchange(): void
    {
        [$ticket, $key, $etype] = $this->tgt;
        $parts = explode('/', $this->spn, 2);
        $nonce = random_int(1, 0x7FFFFFFF);
        $body  = $this->body(0x40810000, null, self::principal(2, ...$parts), $nonce);
        $cksum = Der::seq(Der::ctx(0, Der::int(KerberosCrypto::checksumType($etype))), Der::ctx(1, Der::octet(KerberosCrypto::checksumFor($etype, $key, 6, $body))));
        $apReq = self::app(14, Der::seq(Der::ctx(0, Der::int(5)), Der::ctx(1, Der::int(14)), Der::ctx(2, self::bits(0)), Der::ctx(3, $ticket),
            Der::ctx(4, $this->authenticator($key, $etype, 7, $cksum, null, null))));
        $req   = self::app(12, Der::seq(Der::ctx(1, Der::int(5)), Der::ctx(2, Der::int(12)),
            Der::ctx(3, Der::seq(Der::seq(Der::ctx(1, Der::int(1)), Der::ctx(2, Der::octet($apReq))))), Der::ctx(4, $body)));
        $rep   = $this->kdcCall($req);
        if (($err = self::krbError($rep)) !== null) {     // (the password is right: an unknown service... NTLM says the rest)
            throw new ProtocolException("Kerberos: KDC error {$err[0]} (TGS)", 'kerberos');
        }
        $f = self::fieldsOf($rep, 13);
        [, $cipher] = self::encData($f[6] ?? throw new ProtocolException('Kerberos: TGS-REP without its part', 'kerberos'));
        $this->service = [$f[5] ?? '', ...self::repPart(KerberosCrypto::decryptFor($etype, $key, 8, $cipher), $nonce)];
        // a referral (a ticket for another realm's KDC: the service is in another domain of the forest) is no service's
        // ticket: the service would refuse it, NTLM instead
        $t = self::fieldsOf($this->service[0], 1);
        $s = Der::fields(Der::value($t[2] ?? throw new ProtocolException('Kerberos: ticket without its service', 'kerberos')));
        [, $first] = Der::read(new Reader(Der::value($s[1] ?? throw new ProtocolException('Kerberos: ticket without its service', 'kerberos'))));
        if (strcasecmp($first, 'krbtgt') === 0) {
            throw new ProtocolException('Kerberos: the service is in another realm', 'kerberos');
        }
    }

    /** The tickets: the user's, then the service's (a second call does nothing). */
    public function tickets(): void
    {
        if ($this->service === null) {
            $this->deadline = microtime(true) + self::$wait;
            $this->asExchange();
            $this->tgsExchange();
        }
    }

    /**
     * A Kerberos client for $service on $host (HTTP, TERMSRV) with its tickets, when this server can get them: the first of
     * the user's realms (realmsFor()) with a KDC, and the KDC's tickets; null otherwise (NTLM then), also when config.php
     * turns Kerberos off ('kerberos' => false). The KDC's answers about the user (a wrong password...) are thrown: NTLM would
     * count another failed attempt against the account.
     */
    public static function forService(string $user, string $domain, #[\SensitiveParameter] string $password, string $service, string $host): ?self
    {
        if (Config::get('kerberos') === false || $password === '') {
            return null;
        }
        foreach (self::realmsFor($user, $domain, $host) as $realm) {
            if (($kdc = self::kdcs($realm)) === []) {
                continue;
            }
            try {
                $k = new self($user, $realm, $password, $service . '/' . strtolower(rtrim($host, '.')), $kdc);
                $k->tickets();
                return $k;
            } catch (ProtocolException $e) {
                if (!in_array($e->key, ['kerberos', 'kerberos_integrity', 'protocol'], true)) {   // (a KDC's answer that makes no sense: NTLM)
                    throw $e;
                }
                return null;
            }
        }
        return null;
    }

    // ------------------------------------------------------------------ GSS-API (RFC 4121) and SPNEGO (RFC 4178)

    /**
     * GSS flags for CredSSP (RFC 4121 4.1.1.1): mutual authentication, confidentiality and integrity (0x32, the default)
     * with replay and sequence detection (0x0C). Asked so, Windows numbers its sealed messages from its AP-REP's sequence
     * number, and they are checked (inOrder()); not asked, it numbers them 0 (seen on Windows Server 2022, AES and RC4).
     */
    public const SEQUENCED = 0x3E;

    /**
     * The first token for the service: SPNEGO's NegTokenInit offering Kerberos (Microsoft's OID first, as Windows does),
     * with the AP-REQ (mutual authentication) whose authenticator carries the GSS checksum (RFC 4121 4.1.1: the channel
     * bindings' MD5, or zeros, and the context flags $flags), a fresh subkey and the initial sequence number.
     */
    public function initToken(string $bindings = '', int $flags = 0x32): string
    {
        $this->tickets();
        [$ticket, $key, $etype] = $this->service;
        $this->etype  = $etype;
        $this->subkey = random_bytes(KerberosCrypto::keyLength($etype));
        $this->seq    = random_int(0, 0x3FFFFFFF);
        $this->key    = null;
        [$this->acceptorSubkey, $this->peerSeq] = [false, 0];   // (a new context: nothing kept from the last one's AP-REP)
        $this->sequenced = ($flags & 0x0C) !== 0;
        $cksum = Der::seq(Der::ctx(0, Der::int(0x8003)),
            Der::ctx(1, Der::octet(pack('V', 16) . ($bindings !== '' ? $bindings : str_repeat("\0", 16)) . pack('V', $flags))));
        // with channel bindings, the hint that this client sends them (MS-KILE 3.2.5.8: AD-IF-RELEVANT { AD-AUTH-DATA-AP-OPTIONS,
        // KERB_AP_OPTIONS_CBT }), which a gateway whose Extended Protection is "when supported" goes by
        $hint  = $bindings === '' ? '' : Der::seq(Der::seq(Der::ctx(0, Der::int(1)),
            Der::ctx(1, Der::octet(Der::seq(Der::seq(Der::ctx(0, Der::int(143)), Der::ctx(1, Der::octet(pack('V', 0x4000)))))))));
        $apReq = self::app(14, Der::seq(Der::ctx(0, Der::int(5)), Der::ctx(1, Der::int(14)), Der::ctx(2, self::bits(0x20000000)),
            Der::ctx(3, $ticket), Der::ctx(4, $this->authenticator($key, $etype, 11, $cksum, $this->subkey, $this->seq, $hint))));
        $gss = Der::tlv(0x60, Der::tlv(0x06, self::OID_KRB5) . "\x01\x00" . $apReq);
        $this->mechTypes = Der::seq(Der::tlv(0x06, self::OID_MS_KRB5), Der::tlv(0x06, self::OID_KRB5));
        return Der::tlv(0x60, Der::tlv(0x06, self::OID_SPNEGO) . Der::ctx(0, Der::seq(Der::ctx(0, $this->mechTypes), Der::ctx(2, Der::octet($gss)))));
    }

    /**
     * The service's answer (SPNEGO NegTokenResp, or a bare Kerberos token): refused, an error, or its AP-REP, decrypted
     * with the ticket's session key (its time ours, its subkey the context's key from then on, its sequence number the
     * service's). Its mechListMIC, when it sends one, is checked. Returns the token to send back (the client's own
     * mechListMIC, when the service sent one), or null.
     */
    public function accept(string $token): ?string
    {
        $mic   = null;
        $state = 0;
        if ($token !== '' && ord($token[0]) === 0xA1) {         // NegTokenResp
            $f = Der::fields(Der::value(Der::value($token)));
            $state = isset($f[0]) ? (strlen($v = Der::value($f[0])) === 1 ? ord($v) : 2) : 0;   // (an ENUMERATED of one byte: else refused)
            if ($state === 2) {
                throw new ProtocolException('Kerberos: refused by the service', 'kerberos');
            }
            $token = isset($f[2]) ? Der::value($f[2]) : '';
            $mic   = isset($f[3]) ? Der::value($f[3]) : null;
        }
        if ($token !== '') {
            [$tag, $body] = Der::read(new Reader($token));     // [APPLICATION 0] { OID, TOK_ID, message }
            $r = new Reader($body);
            [, $oid] = Der::read($r);
            $tokId = $r->bytes(2);
            $msg   = $r->bytes($r->left());
            if ($tag !== 0x60 || ($oid !== self::OID_KRB5 && $oid !== self::OID_MS_KRB5)) {
                throw new ProtocolException('Kerberos: not a Kerberos token', 'kerberos');
            }
            if ($tokId === "\x03\x00") {                       // a KRB-ERROR from the service
                $err = self::krbError($msg);
                throw new ProtocolException('Kerberos: service error ' . ($err[0] ?? '?'), 'kerberos');
            }
            if ($tokId !== "\x02\x00") {
                throw new ProtocolException('Kerberos: not an AP-REP', 'kerberos');
            }
            $f = self::fieldsOf($msg, 15);
            [, $cipher] = self::encData($f[2] ?? throw new ProtocolException('Kerberos: AP-REP without its part', 'kerberos'));
            try {
                $p = self::fieldsOf(KerberosCrypto::decryptFor($this->service[2], $this->service[1], 12, $cipher), 27);
            } catch (ProtocolException $e) {           // (not made with the ticket's key: not the service)
                throw $e->key === 'kerberos_integrity' ? new ProtocolException('Kerberos: AP-REP not from the service', 'mitm') : $e;
            }
            // its time is our authenticator's (RFC 4120 3.2.5: the answer to this request, not an old one)
            if (!isset($p[0], $p[1]) || Der::value($p[0]) !== $this->apTime[0] || Der::toInt($p[1]) !== $this->apTime[1]) {
                throw new ProtocolException('Kerberos: AP-REP for another request', 'mitm');
            }
            $key = $this->subkey;
            if (isset($p[2])) {
                [$keytype, $key] = self::encKey($p[2]);
                if (strlen($key) !== KerberosCrypto::keyLength($keytype)) {
                    throw new ProtocolException('Kerberos: subkey of the wrong length', 'kerberos');
                }
                [$this->etype, $this->acceptorSubkey] = [$keytype, true];
            }
            $this->key     = $key;                     // (the context's key only with the AP-REP: mutual authentication)
            $this->peerSeq = isset($p[3]) ? Der::toInt($p[3]) & 0xFFFFFFFF : 0;
        }
        if ($this->key === null && ($mic !== null || $state === 1 || $state === 3)) {
            throw new ProtocolException('Kerberos: no AP-REP from the service', 'kerberos');
        }
        // its mechListMIC, when it sends one, checked (its sequence number not: Windows signs it with 0); ours sent only when
        // it asks for more (accept-incomplete, request-mic), as Windows' own client does
        if ($mic !== null && !$this->micOk($mic)) {
            throw new ProtocolException('Kerberos: mechListMIC mismatch', 'mitm');
        }
        // (numbered as RFC 4121 says, the service's first: the next message one more; Windows' 0 is outside the sequence)
        if ($mic !== null && $this->sequenced && $this->micSeq($mic) === $this->peerSeq) {
            $this->peerSeq++;
        }
        if ($state !== 1 && $state !== 3) {
            return null;
        }
        return Der::ctx(1, Der::seq(Der::ctx(0, Der::tlv(0x0A, "\0")), Der::ctx(3, Der::octet($this->getMic($this->mechTypes)))));
    }

    /** Whether the service sent its AP-REP (mutual authentication done: wrap() and unwrap() can be used). */
    public function established(): bool
    {
        return $this->key !== null;
    }

    private function flags(bool $fromAcceptor, bool $sealed): int
    {
        return ($fromAcceptor ? 0x01 : 0) | ($sealed ? 0x02 : 0) | ($this->acceptorSubkey ? 0x04 : 0);
    }

    /** A MIC token over $data (RFC 4121 4.2.6.1): header 04 04, flags, five 0xFF, sequence number; its checksum after. */
    public function getMic(string $data): string
    {
        if ($this->etype === KerberosCrypto::RC4) {
            // RFC 4757 7.2: 01 01, HMAC (11 00), filler; the sequence number encrypted with the checksum's key; the checksum
            $hdr = "\x01\x01\x11\x00\xFF\xFF\xFF\xFF";
            $ck  = $this->rc4Sign(15, $hdr . $data);
            return self::framed($hdr . $this->rc4Seq($ck, self::seqBytes($this->seq++, false)) . $ck);
        }
        $hdr = "\x04\x04" . chr($this->flags(false, false)) . "\xFF\xFF\xFF\xFF\xFF" . pack('J', $this->seq++);
        return $hdr . KerberosCrypto::checksum($this->key, 25, $data . $hdr);
    }

    /** Whether the service's MIC token over our mechTypes is right (usage 23: the acceptor's sign; RC4's: usage 15). */
    private function micOk(string $mic): bool
    {
        if ($this->etype === KerberosCrypto::RC4) {
            $t = self::unframed($mic);
            if (strlen($t) < 24 || substr($t, 0, 8) !== "\x01\x01\x11\x00\xFF\xFF\xFF\xFF") {
                throw new ProtocolException('Kerberos: bad MIC token', 'mitm');
            }
            return hash_equals($this->rc4Sign(15, substr($t, 0, 8) . $this->mechTypes), substr($t, 16, 8));
        }
        if (strlen($mic) < 28 || substr($mic, 0, 2) !== "\x04\x04" || !(ord($mic[2]) & 0x01)) {
            throw new ProtocolException('Kerberos: bad MIC token', 'mitm');
        }
        return hash_equals(KerberosCrypto::checksum($this->key, 23, $this->mechTypes . substr($mic, 0, 16)), substr($mic, 16));
    }

    /** The sequence number of the service's MIC token (RFC 4757's: encrypted with its checksum's key). */
    private function micSeq(string $mic): int
    {
        if ($this->etype === KerberosCrypto::RC4) {
            $t = self::unframed($mic);
            return unpack('N', $this->rc4Seq(substr($t, 16, 8), substr($t, 8, 8)))[1];
        }
        return unpack('J', $mic, 8)[1];
    }

    /**
     * The service's sealed message taken in its order (RFC 4121 4.2.6, RFC 4757 7.3): with replay and sequence detection
     * asked (SEQUENCED), its number must be the service's next; one repeated, out of order or after one lost is refused.
     * Before, never compared: one message of the service read in CredSSP, but nothing said it was the one expected.
     */
    private function inOrder(int $n): void
    {
        if ($this->sequenced) {
            if ($n !== $this->peerSeq) {
                throw new ProtocolException('Kerberos: a message of the service out of its order', 'mitm');
            }
            $this->peerSeq++;
        }
    }

    // ------------------------------------------------------------------ RFC 4757's GSS tokens (an RC4 context key)

    /** A token in RFC 1964's framing: [APPLICATION 0] { the Kerberos OID, the token }. */
    private static function framed(string $token): string
    {
        return Der::tlv(0x60, Der::tlv(0x06, self::OID_KRB5) . $token);
    }

    private static function unframed(string $token): string
    {
        [$tag, $body] = Der::read(new Reader($token));
        $r = new Reader($body);
        [$oidTag, $oid] = Der::read($r);
        if ($tag !== 0x60 || $oidTag !== 0x06 || ($oid !== self::OID_KRB5 && $oid !== self::OID_MS_KRB5)) {
            throw new ProtocolException('Kerberos: not a Kerberos token', 'protocol');
        }
        return $r->bytes($r->left());
    }

    /** The token's checksum: the first 8 bytes of HMAC-MD5 with the "signaturekey" key over the MD5 of the usage and the data. */
    private function rc4Sign(int $usage, string $data): string
    {
        return substr(hash_hmac('md5', md5(pack('V', $usage) . $data, true), hash_hmac('md5', "signaturekey\0", $this->key, true), true), 0, 8);
    }

    /** The sequence number field: big-endian, then the direction (0 the initiator's, FF the acceptor's). */
    private static function seqBytes(int $seq, bool $acceptor): string
    {
        return pack('N', $seq & 0xFFFFFFFF) . str_repeat($acceptor ? "\xFF" : "\0", 4);
    }

    /** The sequence number field in RC4 with the checksum's key, either way (encrypted or read). */
    private function rc4Seq(string $ck, string $field): string
    {
        return (new Rc4(hash_hmac('md5', $ck, hash_hmac('md5', pack('V', 0), $this->key, true), true)))->crypt($field);
    }

    /** The key that seals a token's confounder and data: the context key XOR F0, then its HMAC-MD5s with 0 and the sequence number. */
    private function rc4Seal(string $seqBe): Rc4
    {
        return new Rc4(hash_hmac('md5', $seqBe, hash_hmac('md5', pack('V', 0), $this->key ^ str_repeat("\xF0", strlen($this->key)), true), true));
    }

    /**
     * GSS Wrap with confidentiality (RFC 4121 4.2.6.2): header 05 04, flags (sealed, acceptor subkey), 0xFF, EC 0, RRC, the
     * sequence number; then the plaintext and a copy of the header (its RRC 0), encrypted for usage 24 (the initiator's
     * seal), rotated right by RRC 28 (its trailer first: what Windows sends and expects in CredSSP).
     */
    public function wrap(string $plain): string
    {
        if ($this->etype === KerberosCrypto::RC4) {
            // RFC 4757 7.3: 02 01, HMAC (11 00), RC4 (10 00), filler; the sequence number, the checksum (usage 13) over the
            // header, a confounder and the data with its one byte of padding; confounder and data sealed in one RC4 stream
            $hdr  = "\x02\x01\x11\x00\x10\x00\xFF\xFF";
            $conf = random_bytes(8);
            $data = $plain . "\x01";
            $ck   = $this->rc4Sign(13, $hdr . $conf . $data);
            $n    = $this->seq++;
            return self::framed($hdr . $this->rc4Seq($ck, self::seqBytes($n, false)) . $ck . $this->rc4Seal(pack('N', $n & 0xFFFFFFFF))->crypt($conf . $data));
        }
        $seq = pack('J', $this->seq++);
        $f   = chr($this->flags(false, true));
        $c   = KerberosCrypto::encrypt($this->key, 24, $plain . "\x05\x04" . $f . "\xFF" . pack('nn', 0, 0) . $seq);
        return "\x05\x04" . $f . "\xFF" . pack('nn', 0, 28) . $seq . substr($c, -28) . substr($c, 0, -28);
    }

    /** GSS Unwrap of the service's sealed token: rotated back by its RRC, decrypted (usage 22), its header copy and EC filler off. */
    public function unwrap(string $token): string
    {
        if ($this->etype === KerberosCrypto::RC4) {
            $t = self::unframed($token);
            if (strlen($t) < 32 + 1 || substr($t, 0, 8) !== "\x02\x01\x11\x00\x10\x00\xFF\xFF") {
                throw new ProtocolException('Kerberos: bad wrap token', 'protocol');
            }
            $ck  = substr($t, 16, 8);
            $seq = $this->rc4Seq($ck, substr($t, 8, 8));
            if (substr($seq, 4) !== "\xFF\xFF\xFF\xFF") {                  // (the acceptor's direction)
                throw new ProtocolException('Kerberos: wrap token not from the service', 'mitm');
            }
            $p = $this->rc4Seal(substr($seq, 0, 4))->crypt(substr($t, 24));
            if (!hash_equals($this->rc4Sign(13, substr($t, 0, 8) . $p), $ck)) {
                throw new ProtocolException('Kerberos: wrap token not from the service', 'mitm');
            }
            $pad = ord($p[-1]);                                          // (RFC 1964 padding: n bytes of value n)
            if ($pad < 1 || $pad > 8 || strlen($p) < 8 + $pad || substr($p, -$pad) !== str_repeat(chr($pad), $pad)) {
                throw new ProtocolException('Kerberos: bad wrap token padding', 'protocol');
            }
            $this->inOrder(unpack('N', $seq)[1]);
            return substr($p, 8, strlen($p) - 8 - $pad);
        }
        if (strlen($token) < 16 + 16 + 12 || substr($token, 0, 2) !== "\x05\x04" || ($f = ord($token[2])) & 0x03 ^ 0x03 || $token[3] !== "\xFF") {
            throw new ProtocolException('Kerberos: bad wrap token', 'protocol');
        }
        ['ec' => $ec, 'rrc' => $rrc] = unpack('nec/nrrc', $token, 4);
        $data = substr($token, 16);
        $rrc %= strlen($data);
        $data = substr($data, $rrc) . substr($data, 0, $rrc);   // (rotated right by RRC: rotated back)
        try {
            $p = KerberosCrypto::decrypt($this->key, 22, $data);
        } catch (ProtocolException $e) {
            throw $e->key === 'kerberos_integrity' ? new ProtocolException('Kerberos: wrap token not from the service', 'mitm') : $e;
        }
        // (the header copy inside: the same flags, EC and sequence number; its RRC is 0)
        if (strlen($p) < 16 + $ec || substr($p, -16, 6) !== "\x05\x04" . chr($f) . "\xFF" . pack('n', $ec) || substr($p, -8) !== substr($token, 8, 8)) {
            throw new ProtocolException('Kerberos: wrap token header mismatch', 'protocol');
        }
        $this->inOrder(unpack('J', $token, 8)[1]);
        return substr($p, 0, strlen($p) - 16 - $ec);
    }
}
