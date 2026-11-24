<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk;

use MuDesk\Rdp\Der;

/**
 * Signing in with an OpenID Connect provider (Microsoft Entra ID, Google, Keycloak, Authentik...): the authorization code
 * flow with PKCE, for a confidential client (client_secret_post). The provider's ID token is checked here: its RS256 or
 * ES256 signature with the provider's published keys, the issuer, the audience, the times and the nonce of this browser.
 * The user is the claim chosen in the settings (e-mail or preferred_username), matched with a MuDeskClient user, or one made
 * for it when allowed. Plain PHP: discovery and keys over HTTPS with certificate checks (kept a while in the data folder).
 */
final class Oidc
{
    private const CACHE = 3600;          // seconds the provider's configuration and keys are kept
    private const FLOW  = 600;           // seconds a browser has to come back from the provider
    private const SKEW  = 120;           // seconds of clock difference allowed on the token's times
    /**
     * The browser's flow (state, nonce, PKCE verifier) goes in a cookie of its own, encrypted with the server key: it comes
     * back from the provider in a navigation from another site, which brings SameSite=Lax cookies, not the Strict session.
     */
    public const COOKIE = 'MUDESKOIDC';

    /** Tests replace the HTTP client: fn(string $url, ?array $post): array{0:int,1:string}. */
    public static ?\Closure $http = null;

    public static function enabled(): bool
    {
        return Settings::get('oidc_issuer') !== '' && Settings::get('oidc_client_id') !== '' && Settings::get('oidc_secret') !== null;
    }

    /** What the sign-in button says after "Sign in with": the label, or the provider's host. */
    public static function label(): string
    {
        $l = (string)Settings::get('oidc_label');
        return $l !== '' ? $l : (string)parse_url((string)Settings::get('oidc_issuer'), PHP_URL_HOST);
    }

    /** An https URL, or http to this computer (a provider for tests or on the same machine). */
    public static function validIssuer(string $url): bool
    {
        $p = parse_url($url);
        if (!is_array($p) || !isset($p['host']) || isset($p['query']) || isset($p['fragment']) || isset($p['user'])) {
            return false;
        }
        return ($p['scheme'] ?? '') === 'https' || (($p['scheme'] ?? '') === 'http' && in_array($p['host'], ['127.0.0.1', 'localhost', '[::1]'], true));
    }

    /** Where the provider sends the browser back: oidc.php next to the page (to be registered with the provider). */
    public static function redirectUri(): string
    {
        if (($pub = Config::publicUrl()) !== null) {        // fixed in config.php: never taken from the request
            return $pub['origin'] . $pub['path'] . '/oidc.php';
        }
        $dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
        return (Http::isHttps() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir . '/oidc.php';
    }

    /** The provider's address to send the browser to, with this browser's state, nonce and PKCE verifier kept. */
    public static function start(): string
    {
        $conf = self::configuration() ?? throw new \RuntimeException('oidc_provider');
        $flow = ['state' => self::random(), 'nonce' => self::random(), 'verifier' => self::random(), 'exp' => time() + self::FLOW];
        self::cookie(self::b64(Crypto::encrypt(Crypto::appKey(), json_encode($flow), 'oidc-flow')), time() + self::FLOW);
        return $conf['authorization_endpoint'] . (str_contains($conf['authorization_endpoint'], '?') ? '&' : '?') . http_build_query([
            'response_type' => 'code', 'client_id' => Settings::get('oidc_client_id'), 'redirect_uri' => self::redirectUri(),
            'scope' => 'openid email profile', 'state' => $flow['state'], 'nonce' => $flow['nonce'],
            'code_challenge' => self::b64(hash('sha256', $flow['verifier'], true)), 'code_challenge_method' => 'S256',
        ]);
    }

    /**
     * The browser back from the provider ($q: its query): the MuDeskClient user to sign in, or why not (an error key:
     * oidc_state, oidc_denied, oidc_token, oidc_claim, oidc_domain, oidc_unknown_user, oidc_provider).
     */
    public static function finish(array $q): array|string
    {
        $raw  = self::unb64((string)($_COOKIE[self::COOKIE] ?? ''));
        $flow = $raw === false ? null : json_decode((string)Crypto::decrypt(Crypto::appKey(), $raw, 'oidc-flow'), true);
        self::cookie('', 1);                                // used once, whatever happens
        if (!is_array($flow) || $flow['exp'] < time() || !is_string($q['state'] ?? null) || !hash_equals($flow['state'], $q['state'])) {
            return 'oidc_state';
        }
        if (isset($q['error']) || !is_string($q['code'] ?? null) || $q['code'] === '') {
            return 'oidc_denied';                           // refused or cancelled at the provider
        }
        $conf = self::configuration();
        if ($conf === null) {
            return 'oidc_provider';
        }
        $secret = Crypto::decrypt(Crypto::appKey(), (string)Settings::get('oidc_secret'), 'oidc') ?? '';
        [$status, $body] = self::http($conf['token_endpoint'], [
            'grant_type' => 'authorization_code', 'code' => $q['code'], 'redirect_uri' => self::redirectUri(),
            'client_id' => Settings::get('oidc_client_id'), 'client_secret' => $secret, 'code_verifier' => $flow['verifier'],
        ]);
        $token = json_decode($body, true);
        if ($status !== 200 || !is_array($token) || !is_string($token['id_token'] ?? null)) {
            return 'oidc_token';
        }
        $claims = self::verify($token['id_token'], $flow['nonce']);
        if (is_string($claims)) {
            return $claims;
        }
        $claim = Settings::get('oidc_claim');
        $name  = $claims[$claim] ?? null;
        // an e-mail counts only when the provider says it is verified (Microsoft Entra ID does not: use preferred_username there)
        if (!is_string($name) || $name === '' || ($claim === 'email' && ($claims['email_verified'] ?? null) !== true) || !is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
            return 'oidc_claim';
        }
        $domains = Settings::get('oidc_domains');
        if ($domains !== [] && !in_array(strtolower(substr((string)strrchr($name, '@'), 1)), $domains, true)) {
            return 'oidc_domain';
        }
        return ['name' => $name, 'sub' => $claims['sub'], 'iss' => (string)$claims['iss']];
    }

    /** The claims of a valid ID token, or 'oidc_token' (bad signature, issuer, audience, time or nonce). */
    public static function verify(string $jwt, string $nonce): array|string
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return 'oidc_token';
        }
        $head   = json_decode((string)self::unb64($parts[0]), true);
        $claims = json_decode((string)self::unb64($parts[1]), true);
        $sig    = (string)self::unb64($parts[2]);
        if (!is_array($head) || !is_array($claims) || !in_array($head['alg'] ?? '', ['RS256', 'RS384', 'RS512', 'ES256', 'ES384'], true)) {
            return 'oidc_token';
        }
        $algo = ['256' => OPENSSL_ALGO_SHA256, '384' => OPENSSL_ALGO_SHA384, '512' => OPENSSL_ALGO_SHA512][substr($head['alg'], 2)];
        if ($head['alg'][0] === 'E') {                     // JOSE gives r and s side by side; OpenSSL wants them in DER
            $half = intdiv(strlen($sig), 2);
            $sig  = Der::seq(self::uint(substr($sig, 0, $half)), self::uint(substr($sig, $half)));
        }
        // the key of its kid; without one, any of the provider's keys of that type (a second RSA key was never tried)
        $signed = false;
        foreach (self::keys(is_string($head['kid'] ?? null) ? $head['kid'] : '', $head['alg']) as $key) {
            if (openssl_verify($parts[0] . '.' . $parts[1], $sig, $key, $algo) === 1) {
                $signed = true;
                break;
            }
        }
        if (!$signed) {
            return 'oidc_token';
        }
        $aud = (array)($claims['aud'] ?? []);
        if ((count($aud) > 1 || isset($claims['azp'])) && ($claims['azp'] ?? null) !== Settings::get('oidc_client_id')) {
            return 'oidc_token';                           // issued to this application (it was only checked for several audiences)
        }
        // the times are numbers, and "iat" is there, as OpenID Connect asks (a missing or text "iat" passed as now, a text
        // "nbf" as 0)
        $now = time();
        $ok  = ($claims['iss'] ?? null) === Settings::get('oidc_issuer') && in_array(Settings::get('oidc_client_id'), $aud, true)
            && is_int($claims['exp'] ?? null) && $claims['exp'] > $now - self::SKEW && is_int($claims['iat'] ?? null) && $claims['iat'] <= $now + self::SKEW
            && (!isset($claims['nbf']) || (is_int($claims['nbf']) && $claims['nbf'] <= $now + self::SKEW))
            && is_string($claims['nonce'] ?? null) && hash_equals($nonce, $claims['nonce']);
        return $ok ? $claims : 'oidc_token';
    }

    /** The provider's configuration (its issuer must be the one set), kept for an hour; null when it cannot be had. */
    private static function configuration(): ?array
    {
        $iss   = (string)Settings::get('oidc_issuer');
        $cache = Store::read('oidc');
        if (($cache['issuer'] ?? '') === $iss && ($cache['at'] ?? 0) > time() - self::CACHE && isset($cache['conf'])) {
            return $cache['conf'];
        }
        if (($cache['failed'] ?? 0) > time() - 30 && ($cache['issuer'] ?? '') === $iss) {
            return null;                                    // it failed a moment ago: not asked again for each request
        }
        [$status, $body] = self::http(rtrim($iss, '/') . '/.well-known/openid-configuration');
        $conf = json_decode($body, true);
        if ($status !== 200 || !is_array($conf) || ($conf['issuer'] ?? null) !== $iss) {
            Store::update('oidc', static function (array &$d) use ($iss): void {
                $d = ['issuer' => $iss, 'failed' => time()];
            });
            return null;
        }
        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $k) {
            if (!is_string($conf[$k] ?? null) || !self::validIssuer($conf[$k])) {
                return null;
            }
        }
        $conf = array_intersect_key($conf, array_flip(['issuer', 'authorization_endpoint', 'token_endpoint', 'jwks_uri']));
        Store::update('oidc', static function (array &$d) use ($iss, $conf): void {
            $d = ['issuer' => $iss, 'at' => time(), 'conf' => $conf, 'keys' => []];
        });
        return $conf;
    }

    /** The public keys of $kid for $alg from the provider's keys (fetched again once when the kid is new: keys rotate). */
    private static function keys(string $kid, string $alg): array
    {
        $conf = self::configuration();
        if ($conf === null) {
            return [];
        }
        $keys = Store::read('oidc')['keys'] ?? [];
        foreach ([false, true] as $fetch) {
            if ($fetch) {
                [$status, $body] = self::http($conf['jwks_uri']);
                $keys = json_decode($body, true)['keys'] ?? null;
                if ($status !== 200 || !is_array($keys)) {
                    return [];
                }
                Store::update('oidc', static function (array &$d) use ($keys): void {
                    $d['keys'] = $keys;
                });
            }
            $found = [];
            foreach ($keys as $k) {
                if (is_array($k) && ($kid === '' || ($k['kid'] ?? null) === $kid) && ($k['use'] ?? 'sig') === 'sig') {
                    $pem = self::pem($k, $alg);
                    if ($pem !== null && ($key = openssl_pkey_get_public($pem)) !== false) {
                        $found[] = $key;
                    }
                }
            }
            if ($found !== []) {
                return $found;
            }
        }
        return [];
    }

    /** A JWK (RSA, or EC on P-256 / P-384) as a PEM public key for $alg; null when it does not fit. */
    private static function pem(array $k, string $alg): ?string
    {
        $tlv = static fn(int $tag, string $v): string => Der::tlv($tag, $v);
        if (($k['kty'] ?? '') === 'RSA' && $alg[0] === 'R' && is_string($k['n'] ?? null) && is_string($k['e'] ?? null)) {
            $rsa  = Der::seq(self::uint((string)self::unb64($k['n'])), self::uint((string)self::unb64($k['e'])));
            $spki = Der::seq(Der::seq($tlv(0x06, "\x2A\x86\x48\x86\xF7\x0D\x01\x01\x01"), "\x05\x00"), $tlv(0x03, "\0" . $rsa));
        } elseif (($k['kty'] ?? '') === 'EC' && $alg[0] === 'E' && is_string($k['x'] ?? null) && is_string($k['y'] ?? null)
            && in_array($k['crv'] ?? '', ['P-256', 'P-384'], true)) {
            $curve = $k['crv'] === 'P-256' ? "\x2A\x86\x48\xCE\x3D\x03\x01\x07" : "\x2B\x81\x04\x00\x22";
            $spki  = Der::seq(Der::seq($tlv(0x06, "\x2A\x86\x48\xCE\x3D\x02\x01"), $tlv(0x06, $curve)),
                $tlv(0x03, "\0\x04" . self::unb64($k['x']) . self::unb64($k['y'])));
        } else {
            return null;
        }
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /** GET, or POST of a form; [status, body]. Certificates are checked (an https provider must have a valid one). */
    private static function http(string $url, ?array $post = null): array
    {
        if (self::$http !== null) {
            return (self::$http)($url, $post);
        }
        $ctx = stream_context_create([
            'http' => ['method' => $post === null ? 'GET' : 'POST', 'timeout' => 10, 'ignore_errors' => true, 'follow_location' => 0,
                       'header' => "Accept: application/json\r\n" . ($post === null ? '' : "Content-Type: application/x-www-form-urlencoded\r\n"),
                       'content' => $post === null ? '' : http_build_query($post)],
            'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $fp = @fopen($url, 'r', false, $ctx);
        if ($fp === false) {
            return [0, ''];
        }
        $body = (string)stream_get_contents($fp, 1 << 20);
        $code = 0;
        foreach (stream_get_meta_data($fp)['wrapper_data'] ?? [] as $h) {
            if (is_string($h) && preg_match('#^HTTP/\S+ (\d+)#', $h, $m)) {
                $code = (int)$m[1];
            }
        }
        fclose($fp);
        return [$code, $body];
    }

    private static function cookie(string $value, int $expires): void
    {
        $_COOKIE[self::COOKIE] = $value;
        if (!headers_sent()) {
            setcookie(self::COOKIE, $value, ['expires' => $expires, 'path' => '/', 'secure' => Http::isHttps(), 'httponly' => true, 'samesite' => 'Lax']);
        }
    }

    private static function uint(string $bytes): string
    {
        $bytes = ltrim($bytes, "\0");
        return Der::tlv(0x02, $bytes === '' || ord($bytes[0]) >= 0x80 ? "\0" . $bytes : $bytes);
    }

    private static function random(): string
    {
        return self::b64(random_bytes(32));
    }

    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private static function unb64(string $s): string|false
    {
        return base64_decode(strtr($s, '-_', '+/'), true);
    }
}
