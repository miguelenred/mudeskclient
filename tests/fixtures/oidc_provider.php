<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);
// An OpenID Connect provider for the tests (a router for PHP's built-in server): its configuration, its key (RS256) and its
// token endpoint, which checks the client's secret and its PKCE verifier. The authorization step is the test's: the "code"
// carries what a real provider would have kept from it (base64url JSON: nonce, challenge, the claims to give).
//   IDP_KEY=key.pem IDP_ISS=http://127.0.0.1:port IDP_CLIENT=id IDP_SECRET=secret php -S 127.0.0.1:port oidc_provider.php
$iss  = (string)getenv('IDP_ISS');
$pem  = (string)file_get_contents((string)getenv('IDP_KEY'));
$key  = openssl_pkey_get_private($pem);
$b64  = static fn(string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
$json = static function (mixed $v, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($v);
    exit;
};
$path = (string)parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/.well-known/openid-configuration') {
    $json(['issuer' => $iss, 'authorization_endpoint' => "$iss/authorize", 'token_endpoint' => "$iss/token", 'jwks_uri' => "$iss/jwks"]);
}
if ($path === '/jwks') {
    $d = openssl_pkey_get_details($key)['rsa'];
    $json(['keys' => [['kty' => 'RSA', 'use' => 'sig', 'kid' => 'k1', 'alg' => 'RS256', 'n' => $b64($d['n']), 'e' => $b64($d['e'])]]]);
}
if ($path === '/token' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $code = json_decode((string)base64_decode(strtr((string)($_POST['code'] ?? ''), '-_', '+/')), true);
    if (!is_array($code) || ($_POST['client_id'] ?? '') !== getenv('IDP_CLIENT') || ($_POST['client_secret'] ?? '') !== getenv('IDP_SECRET')
        || $b64(hash('sha256', (string)($_POST['code_verifier'] ?? ''), true)) !== ($code['challenge'] ?? null)
        || ($_POST['grant_type'] ?? '') !== 'authorization_code') {
        $json(['error' => 'invalid_grant'], 400);
    }
    $claims = ($code['claims'] ?? []) + ['iss' => $iss, 'aud' => getenv('IDP_CLIENT'), 'exp' => time() + 300, 'iat' => time(), 'nonce' => $code['nonce'], 'sub' => 'subject-1'];
    $head   = $b64(json_encode(['alg' => 'RS256', 'kid' => 'k1', 'typ' => 'JWT']));
    $body   = $b64(json_encode($claims));
    openssl_sign("$head.$body", $sig, $key, OPENSSL_ALGO_SHA256);
    $json(['access_token' => 'x', 'token_type' => 'Bearer', 'id_token' => "$head.$body." . $b64($sig)]);
}
$json(['error' => 'not_found'], 404);
