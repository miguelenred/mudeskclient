<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk;

/** Allow-lists for client IPs and RDP targets (IPs, CIDR ranges, host names and *.domain wildcards). */
final class Access
{
    public static function validRule(string $rule, bool $allowNames): bool
    {
        if (self::parseCidr($rule) !== null) {
            return true;
        }
        return $allowNames && (bool)preg_match('~^(\*\.)?([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$~', $rule);
    }

    /** @return array{0:string,1:int}|null packed network and prefix length */
    private static function parseCidr(string $rule): ?array
    {
        [$ip, $len] = array_pad(explode('/', $rule, 2), 2, null);
        $bin        = @inet_pton((string)$ip);
        if ($bin === false) {
            return null;
        }
        $max = strlen($bin) * 8;
        if ($len === null) {
            return [$bin, $max];
        }
        if (!preg_match('/^\d{1,3}$/', $len) || (int)$len > $max) {
            return null;
        }
        return [$bin, (int)$len];
    }

    public static function ipMatches(string $ip, string $rule): bool
    {
        $bin  = @inet_pton($ip);
        $cidr = self::parseCidr($rule);
        if ($bin === false || $cidr === null || strlen($bin) !== strlen($cidr[0])) {
            return false;
        }
        [$net, $len] = $cidr;
        $bytes       = intdiv($len, 8);
        if (substr($bin, 0, $bytes) !== substr($net, 0, $bytes)) {
            return false;
        }
        $bits = $len % 8;
        if ($bits === 0) {
            return true;
        }
        $mask = (0xff << (8 - $bits)) & 0xff;
        return (ord($bin[$bytes]) & $mask) === (ord($net[$bytes]) & $mask);
    }

    private static function ipAllowed(string $ip, array $list): bool
    {
        foreach ($list as $rule) {
            if (self::ipMatches($ip, $rule)) {
                return true;
            }
        }
        return false;
    }

    public static function clientAllowed(string $ip): bool
    {
        $c = Settings::get('clients');
        return $c['mode'] === 'all' || self::ipAllowed($ip, $c['list']);
    }

    /**
     * Resolves an RDP target and checks it against the allow-list.
     * Returns the address to connect to (so DNS cannot change between check and use) or null.
     */
    public static function resolveTarget(string $host): ?string
    {
        $host = strtolower(trim($host, " []"));
        $ips  = @inet_pton($host) !== false ? [$host] : self::resolve($host);
        if ($ips === []) {
            return null;
        }
        $t = Settings::get('targets');
        if ($t['mode'] === 'all') {
            return array_filter($ips, [self::class, 'unsafe']) === [] ? $ips[0] : null;
        }
        // a name rule (the name itself, or *.domain) trusts its DNS: what it resolves to is allowed, but this computer,
        // link-local and cloud metadata addresses only when listed themselves too (before, a name listed itself reached them
        // whatever its DNS said: an answer changed to 169.254.169.254 or 127.0.0.1 took the gateway there)
        $unlisted = static fn(string $ip): bool => self::unsafe($ip) && !self::ipAllowed($ip, $t['list']);
        foreach ($t['list'] as $rule) {
            if (self::parseCidr($rule) === null && ($host === $rule || (str_starts_with($rule, '*.') && str_ends_with($host, substr($rule, 1))))) {
                return array_filter($ips, $unlisted) === [] ? $ips[0] : null;
            }
        }
        foreach ($ips as $ip) {
            if (!self::ipAllowed($ip, $t['list'])) {
                return null;
            }
        }
        return $ips[0];
    }

    /**
     * Refused while all targets are allowed (list them to reach them): loopback (the gateway itself), link-local (cloud
     * metadata at 169.254.169.254 too), unspecified addresses and the other cloud metadata addresses (100.100.100.200,
     * fd00:ec2::254), also as IPv4 inside IPv6 (::ffff:a.b.c.d, ::a.b.c.d, NAT64 64:ff9b::/96).
     */
    public static function unsafe(string $ip): bool
    {
        $b = (string)@inet_pton($ip);
        if (strlen($b) === 16 && (str_starts_with($b, "\0\0\0\0\0\0\0\0\0\0\xff\xff") || str_starts_with($b, "\0\x64\xff\x9b\0\0\0\0\0\0\0\0")
            || str_starts_with($b, str_repeat("\0", 12)))) {
            $b = substr($b, 12);                                      // the IPv4 address inside (:: and ::1 are 0.0.0.0/8: refused too)
        }
        if ($b === inet_pton('fd00:ec2::254')) {
            return true;
        }
        if (strlen($b) === 4) {                                       // 0.0.0.0/8, 127.0.0.0/8, 169.254.0.0/16, 100.100.100.200
            return $b[0] === "\0" || $b[0] === "\x7f" || str_starts_with($b, "\xa9\xfe") || $b === "\x64\x64\x64\xc8";
        }
        return ltrim($b, "\0") === '' || ltrim($b, "\0") === "\1" || ($b[0] === "\xfe" && (ord($b[1]) & 0xc0) === 0x80);   // ::, ::1, fe80::/10
    }

    /** The addresses of $host (itself when it is one), IPv4 and IPv6, with no allow-list applied (a computer the administrator set). */
    public static function addresses(string $host): array
    {
        $host = strtolower(trim($host, " []"));
        return @inet_pton($host) !== false ? [$host] : self::resolve($host);
    }

    private static function resolve(string $host): array
    {
        if (!preg_match('~^[a-z0-9.-]+$~', $host)) {
            return [];
        }
        $ips = gethostbynamel($host) ?: [];
        if ($ips === []) {
            foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $r) {
                $ips[] = $r['ipv6'];
            }
        }
        return $ips;
    }
}
