<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);
/*
 * MuDeskClient - diagnóstico del hosting.
 * 1) Cambia TOKEN (16 caracteres o más).  2) Súbelo a public/ y ábrelo en el navegador.  3) BORRA el fichero al terminar.
 * Sin sintaxis de PHP 8: en un PHP antiguo también se abre y avisa de la versión.
 */
const TOKEN = 'CAMBIAR';
const MEMORIA = 128 << 20;      // memory_limit mínimo: exportar la auditoría (hasta 20 000 filas) y buscar en muchos días
const CADUCA = 86400;           // segundos desde que se subió o se cambió: después no hace nada (por si se olvida borrarlo)
const TOKEN_MIN = 16;           // caracteres del token como mínimo: lo elige quien copia el fichero y no hay límite de intentos

// caducado (subido o cambiado hace más de un día), con el token sin cambiar o con uno corto (antes, cualquiera: sin límite
// de intentos, uno corto se adivinaba): ninguna prueba ni ningún formulario
$subido = max((int)@filemtime(__FILE__), (int)@filectime(__FILE__));   // (un FTP que conserva la fecha: la de creación)
$malo   = TOKEN === 'CAMBIAR' || strlen(TOKEN) < TOKEN_MIN;
if (time() - $subido > CADUCA || $malo) {
    http_response_code($malo ? 403 : 410);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    exit($malo ? 'Cambia TOKEN en este fichero por uno de ' . TOKEN_MIN . " caracteres o más para usarlo, y bórralo al terminar.\n"
        : "Este diagnóstico se subió hace más de un día y ya no funciona: bórralo del servidor (súbelo de nuevo si lo necesitas).\n");
}

if (isset($_GET['a'])) {
    if ($malo || !hash_equals(TOKEN, (string)($_SERVER['HTTP_X_TOKEN'] ?? ''))) {
        http_response_code(403);
        exit('Token no válido o sin cambiar');
    }
    switch ($_GET['a']) {
        case 'env':       env();       break;
        case 'rdp':       rdp();       break;
        case 'stream':    stream();    break;
        case 'ping':      echo 'ok';   break;
        case 'datos':     datos();     break;
        case 'datos_fin': datosFin();  break;
        case 'espera':    espera();    break;
        default:          http_response_code(400);
    }
    exit;
}

function json(array $d): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/** Valor de php.ini en bytes ('128M', '1G', '-1'...). */
function bytes(string $v): int
{
    $n = (int)$v;
    $u = strtolower(substr(trim($v), -1));
    return $u === 'g' ? $n << 30 : ($u === 'm' ? $n << 20 : ($u === 'k' ? $n << 10 : $n));
}

/** Ruta con barras normales, terminada en barra (y en minúsculas en Windows), para comparar carpetas. */
function carpeta(string $p): string
{
    $p = rtrim(str_replace('\\', '/', $p), '/') . '/';
    return DIRECTORY_SEPARATOR === '\\' ? strtolower($p) : $p;
}

/** Avisos del entorno: versión de PHP, display_errors, memory_limit. */
function avisos(): array
{
    $a = [];
    if (PHP_VERSION_ID < 80100 || PHP_VERSION_ID >= 80600) {
        $a[] = 'PHP ' . PHP_VERSION . ': MuDeskClient necesita PHP 8.1, 8.2, 8.3, 8.4 u 8.5 (probado con todas ellas). Cambia la versión en el panel del hosting.';
    }
    if (!in_array(strtolower(trim((string)ini_get('display_errors'))), ['', '0', 'off', 'false', 'no', 'none'], true)) {
        $a[] = 'display_errors está activo: un error de PHP mostraría a cualquiera rutas y datos internos del servidor. Desactívalo (display_errors = Off) en el panel del hosting, php.ini o .user.ini.';
    }
    $mem = bytes((string)ini_get('memory_limit'));
    if ($mem !== -1 && $mem < MEMORIA) {
        $a[] = 'memory_limit = ' . ini_get('memory_limit') . ': la exportación de la auditoría (hasta 20 000 filas) y las búsquedas en muchos días pueden quedarse sin memoria. Ponlo al menos en 128M.';
    }
    return $a;
}

function env(): void
{
    $need = ['stream_socket_client', 'stream_socket_enable_crypto', 'stream_socket_server', 'stream_select',
             'set_time_limit', 'ignore_user_abort', 'flock', 'random_bytes', 'hash_hmac', 'openssl_encrypt', 'password_hash'];
    $fn = [];
    foreach ($need as $f) {
        $fn[$f] = function_exists($f);
    }
    $ssl  = extension_loaded('openssl');
    $sock = sys_get_temp_dir() . '/mdc_' . bin2hex(random_bytes(4)) . '.sock';
    $srv  = @stream_socket_server('unix://' . $sock);
    $unix = $srv !== false;
    if ($unix) {
        fclose($srv);
        @unlink($sock);
    }
    json([
        'avisos'              => avisos(),
        'php'                 => PHP_VERSION,
        'php_8_1_o_superior'  => PHP_VERSION_ID >= 80100,
        'sapi'                => PHP_SAPI,
        'servidor'            => $_SERVER['SERVER_SOFTWARE'] ?? '',
        'so'                  => PHP_OS_FAMILY,
        'openssl'             => $ssl ? OPENSSL_VERSION_TEXT : false,
        'aes_256_gcm'         => $ssl && in_array('aes-256-gcm', openssl_get_cipher_methods(), true),
        'funciones'           => $fn,
        'max_execution_time'  => ini_get('max_execution_time'),
        'set_time_limit_ok'   => $fn['set_time_limit'] && set_time_limit(0),
        'output_buffering'    => ini_get('output_buffering'),
        'zlib_compression'    => ini_get('zlib.output_compression'),
        'memory_limit'        => ini_get('memory_limit'),
        'display_errors'      => ini_get('display_errors'),
        'post_max_size'       => ini_get('post_max_size'),
        'upload_max_filesize' => ini_get('upload_max_filesize'),
        'tmp_escribible'      => is_writable(sys_get_temp_dir()),
        'dir_escribible'      => is_writable(__DIR__),
        'unix_socket'         => $unix,
    ]);
}

/** Carpeta de datos de la aplicación: la de config.php (data_dir) o data/ junto a public/; null si aún no existe. */
function dataDir(): ?string
{
    $root = dirname(__DIR__);
    $cfg  = is_file("$root/config.php") ? (array)(include "$root/config.php") : [];
    $dir  = (string)($cfg['data_dir'] ?? "$root/data");
    return is_dir($dir) ? (realpath($dir) ?: $dir) : null;
}

/** Deja en la carpeta de datos un fichero de prueba con un contenido al azar: el navegador intenta descargarlo. */
function datos(): void
{
    $dir = dataDir();
    if ($dir === null) {
        json(['error' => 'No se encuentra la carpeta de datos (data_dir de config.php, o data/ junto a public/): sube antes la aplicación y ábrela una vez']);
        return;
    }
    foreach (glob($dir . '/mudesk-check-*.txt') ?: [] as $f) {      // restos de una prueba que no terminó
        @unlink($f);
    }
    $name  = 'mudesk-check-' . bin2hex(random_bytes(8)) . '.txt';
    $token = bin2hex(random_bytes(16));
    if (@file_put_contents("$dir/$name", $token) === false) {
        json(['carpeta' => $dir, 'error' => 'PHP no puede escribir en la carpeta de datos']);
        return;
    }
    $doc    = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? '')) ?: '';
    $inside = $doc !== '' && strpos(carpeta($dir), carpeta($doc)) === 0;
    json(['carpeta' => $dir, 'dentro_de_la_raiz_web' => $inside, 'fichero' => $name, 'contenido' => $token,
          'url' => $inside ? substr(carpeta($dir), strlen(carpeta($doc)) - 1) . $name : null]);
}

function datosFin(): void
{
    $name = (string)($_GET['f'] ?? '');
    $dir  = dataDir();
    if ($dir !== null && preg_match('/^mudesk-check-[0-9a-f]{16}\.txt$/', $name)) {
        @unlink("$dir/$name");
    }
    echo 'ok';
}

/** Petición que espera: el navegador lanza varias a la vez y cuenta cuántas atiende el servidor al mismo tiempo. */
function espera(): void
{
    $ms = max(100, min(5000, (int)($_GET['ms'] ?? 1000)));
    $t  = microtime(true);
    usleep($ms * 1000);
    json(['inicio' => $t, 'fin' => microtime(true), 'pid' => function_exists('getmypid') ? getmypid() : 0, 'protocolo' => $_SERVER['SERVER_PROTOCOL'] ?? '']);
}

function readn($s, int $n): string
{
    $b = '';
    while (strlen($b) < $n && !feof($s)) {
        $c = fread($s, $n - strlen($b));
        if ($c === false || $c === '') {
            break;
        }
        $b .= $c;
    }
    return $b;
}

/** Direcciones IPv4 e IPv6 de un nombre ([] si no tiene o no es un nombre). */
function direcciones(string $name): array
{
    if (!preg_match('~^[a-z0-9.-]+$~', $name)) {
        return [];
    }
    $ips = gethostbynamel($name) ?: [];
    if ($ips === []) {
        foreach (@dns_get_record($name, DNS_AAAA) ?: [] as $rec) {
            $ips[] = $rec['ipv6'];
        }
    }
    return $ips;
}

/**
 * Si es una dirección a la que la aplicación no se conecta con todos los equipos permitidos (Access::unsafe): este mismo
 * servidor (loopback, 0.0.0.0/8, ::), enlace local (169.254.0.0/16 con los metadatos de la nube, fe80::/10) y los metadatos
 * de otras nubes (100.100.100.200, fd00:ec2::254), también como IPv4 dentro de IPv6.
 */
function inseguro(string $ip): bool
{
    $b = (string)@inet_pton($ip);
    $p = substr($b, 0, 12);
    if (strlen($b) === 16 && ($p === "\0\0\0\0\0\0\0\0\0\0\xff\xff" || $p === "\0\x64\xff\x9b\0\0\0\0\0\0\0\0" || $p === str_repeat("\0", 12))) {
        $b = substr($b, 12);
    }
    if ($b === inet_pton('fd00:ec2::254')) {
        return true;
    }
    if (strlen($b) === 4) {
        return $b[0] === "\0" || $b[0] === "\x7f" || substr($b, 0, 2) === "\xa9\xfe" || $b === "\x64\x64\x64\xc8";
    }
    return strlen($b) !== 16 || ltrim($b, "\0") === '' || ltrim($b, "\0") === "\1" || ($b[0] === "\xfe" && (ord($b[1]) & 0xc0) === 0x80);
}

function rdp(): void
{
    $host = trim((string)($_GET['h'] ?? ''));
    $port = (int)($_GET['p'] ?? 3389);
    $r    = ['host' => $host, 'puerto' => $port];
    if ($host === '' || $port < 1 || $port > 65535) {
        json($r + ['error' => 'Host o puerto no válido']);
        return;
    }
    // la dirección, comprobada como la comprueba la aplicación con todos los equipos permitidos (lo predeterminado), y la
    // misma la que se usa (un DNS que cambiara entre comprobar y conectar no lleva a otra). Antes cualquier destino: quien
    // tuviera el token podía sondear desde el hosting este mismo servidor y los metadatos de la nube. Cada prueba queda
    // en el registro de errores de PHP, con quién la pidió
    $name = strtolower(trim($host, ' []'));
    $ips  = @inet_pton($name) !== false ? [$name] : direcciones($name);
    $deny = array_values(array_filter($ips, 'inseguro'));
    error_log("MuDeskClient check.php: prueba de RDP saliente a $host:$port pedida desde " . ($_SERVER['REMOTE_ADDR'] ?? '?')
        . ($ips === [] ? ' (nombre no encontrado)' : ($deny !== [] ? ' (rechazada: ' . implode(', ', $deny) . ')' : '')));
    if ($ips === []) {
        json($r + ['error' => 'No se encuentra la dirección de ese nombre (DNS)']);
        return;
    }
    if ($deny !== []) {
        json($r + ['error' => 'Dirección no permitida: ' . implode(', ', $deny) . ' (este mismo servidor, enlace local o metadatos de la nube; la aplicación tampoco se conecta a ella)']);
        return;
    }
    $ssl = ['verify_peer' => false, 'verify_peer_name' => false, 'capture_peer_cert' => true];
    $ctx = stream_context_create(['ssl' => $ssl + ($ips[0] === $name ? [] : ['peer_name' => $name])]);
    $t   = microtime(true);
    $s   = @stream_socket_client('tcp://' . (strpos($ips[0], ':') !== false ? "[$ips[0]]" : $ips[0]) . ":$port", $en, $es, 5, STREAM_CLIENT_CONNECT, $ctx);
    if (!$s) {
        json($r + ['error' => "TCP: $es ($en)", 'pista' => 'Comprueba que el equipo está encendido, la dirección y el puerto (el que use ese equipo: 3389 si no se cambió), '
            . 'y que ningún cortafuegos lo bloquea: el de Windows en el equipo remoto, el del router o la red de la empresa, '
            . 'y el del hosting, que a veces no deja salir a otros puertos (pregunta al proveedor).']);
        return;
    }
    $r['tcp_ms'] = round((microtime(true) - $t) * 1000, 1);
    stream_set_timeout($s, 5);

    // X.224 Connection Request + RDP_NEG_REQ (TLS | NLA)
    fwrite($s, "\x03\x00\x00\x13\x0e\xe0\x00\x00\x00\x00\x00\x01\x00\x08\x00\x03\x00\x00\x00");
    $resp = readn($s, 19);
    if (strlen($resp) < 11 || $resp[0] !== "\x03" || $resp[5] !== "\xd0") {
        fclose($s);
        json($r + ['error' => 'La respuesta no es RDP']);
        return;
    }
    if (strlen($resp) < 19) {
        fclose($s);
        json($r + ['error' => 'Sin negociación: solo seguridad RDP clásica']);
        return;
    }
    $type = ord($resp[11]);
    $val  = unpack('V', $resp, 15)[1];
    if ($type === 3) {
        $fail = [1 => 'TLS obligatorio', 2 => 'TLS no permitido', 3 => 'sin certificado', 5 => 'NLA obligatorio', 6 => 'TLS con autenticación de usuario'];
        fclose($s);
        json($r + ['error' => 'Negociación rechazada: ' . ($fail[$val] ?? $val)]);
        return;
    }
    $r['protocolo'] = [0 => 'RDP clásico', 1 => 'TLS', 2 => 'NLA (CredSSP)', 8 => 'NLA extendido'][$val] ?? $val;

    if ($val !== 0) {
        $m = STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
           | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0);
        $t = microtime(true);
        if (@stream_socket_enable_crypto($s, true, $m)) {
            $r['tls_ms'] = round((microtime(true) - $t) * 1000, 1);
            $c           = stream_get_meta_data($s)['crypto'] ?? [];
            $r['tls']    = trim(($c['protocol'] ?? '') . ' ' . ($c['cipher_name'] ?? ''));
            $cert        = stream_context_get_params($s)['options']['ssl']['peer_certificate'] ?? null;
            if ($cert) {
                $x                     = openssl_x509_parse($cert);
                $r['certificado_cn']   = $x['subject']['CN'] ?? '';
                $r['certificado_hasta'] = date('Y-m-d', $x['validTo_time_t']);
            }
        } else {
            $r['error'] = 'Fallo TLS: ' . (error_get_last()['message'] ?? '');
        }
    }
    fclose($s);
    json($r);
}

function stream(): void
{
    $secs = max(10, min(3600, (int)($_GET['s'] ?? 600)));
    if (function_exists('set_time_limit')) {
        set_time_limit(0);
    }
    if (function_exists('ignore_user_abort')) {
        ignore_user_abort(false);
    }
    @ini_set('zlib.output_compression', '0');
    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', '1');
    }
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-cache, no-transform');
    header('X-Accel-Buffering: no');
    echo str_repeat(' ', 4096), "\n";
    flush();
    $t0 = microtime(true);
    do {
        $e = (int)round((microtime(true) - $t0) * 1000);
        echo $e, "\n";
        flush();
        if (connection_aborted()) {
            break;
        }
        usleep(2000000);
    } while ($e < $secs * 1000);
}
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>MuDeskClient · diagnóstico</title>
<style>
body{font:14px system-ui,sans-serif;max-width:860px;margin:2rem auto;padding:0 1rem;color:#222}
fieldset{border:1px solid #ccc;border-radius:6px;margin:0 0 1rem;padding:.8rem}
input{padding:.35rem;margin:.2rem .4rem .2rem 0}
button{padding:.4rem .8rem;margin:.2rem .4rem .2rem 0;cursor:pointer}
pre{background:#111;color:#cfc;padding:1rem;border-radius:6px;white-space:pre-wrap;min-height:6rem}
#avisos{color:#b00020;font-weight:600;padding-left:1.2rem}
#avisos:empty{display:none}
</style>
</head>
<body>
<h1>MuDeskClient · diagnóstico del hosting</h1>
<fieldset><legend>Token</legend><input id="tok" type="password" placeholder="TOKEN del fichero"></fieldset>
<fieldset><legend>1. Entorno PHP</legend><button data-t="env">Comprobar</button></fieldset>
<fieldset><legend>2. Conexión RDP saliente</legend>
<input id="h" placeholder="IP o dominio del equipo"><input id="p" value="3389" size="6">
<button data-t="rdp">Probar</button></fieldset>
<fieldset><legend>3. Petición larga (streaming)</legend>
<input id="s" value="600" size="6"> segundos
<button data-t="stream">Iniciar</button><button data-t="stop">Parar</button></fieldset>
<fieldset><legend>4. Latencia ida y vuelta</legend><button data-t="ping">Medir (20 peticiones)</button></fieldset>
<fieldset><legend>5. Carpeta de datos accesible desde la web</legend><button data-t="datos">Comprobar</button></fieldset>
<fieldset><legend>6. Procesos PHP a la vez</legend>
<input id="n" value="20" size="4"> peticiones de 1 s a la vez
<button data-t="procesos">Medir</button></fieldset>
<ul id="avisos"></ul>
<pre id="out"></pre>
<script>
'use strict';
const $ = id => document.getElementById(id), out = $('out');
let ctrl = null;
const req = (a, q = '', sig) => fetch(`?a=${a}${q}`, {headers: {'X-Token': $('tok').value}, cache: 'no-store', signal: sig});
const show = t => { out.textContent = t; };
const get = async (a, q) => { const r = await req(a, q); if (!r.ok) throw new Error(`HTTP ${r.status}: ${await r.text()}`); return r.json(); };
const avisos = {};
const warn = (test, list) => {                  // los avisos de cada prueba sustituyen a los de su vez anterior
  avisos[test] = list;
  $('avisos').replaceChildren(...Object.values(avisos).flat().map(t => Object.assign(document.createElement('li'), {textContent: t})));
};

const tests = {
  async env() {
    const t = await (await req('env')).text();
    try { warn('env', JSON.parse(t).avisos); } catch {}
    show(t);
  },
  async datos() {
    const d = await get('datos');
    if (d.error) { warn('datos', []); return show(`${d.carpeta ? d.carpeta + '\n' : ''}${d.error}`); }
    const urls = [...new Set(['../data/' + d.fichero, 'data/' + d.fichero, d.url].filter(Boolean).map(u => new URL(u, location.href).href))];
    const res = await Promise.all(urls.map(u => fetch(u, {cache: 'no-store', credentials: 'omit'})
      .then(async r => [u, r.status, r.ok && (await r.text()) === d.contenido], () => [u, 'sin respuesta', false])));
    await req('datos_fin', `&f=${d.fichero}`);
    const open = res.filter(r => r[2]).map(r => r[0]);
    warn('datos', open.length ? [`La carpeta de datos se puede descargar desde la web (${open.join(', ')}): cualquiera podría leer usuarios, conexiones y la auditoría. El servidor no aplica sus .htaccess / web.config: sácala de la raíz web (data_dir en config.php) o niega el acceso con las reglas de docs/es/HOSTING.md.`] : []);
    show(`Carpeta de datos: ${d.carpeta}\n${d.dentro_de_la_raiz_web ? 'Está dentro de la raíz web: solo la protegen sus .htaccess / web.config' : 'Está fuera de la raíz web'}\n\n`
      + res.map(([u, s, ok]) => `${ok ? 'DESCARGADO' : 'protegido '} ${s}  ${u}`).join('\n')
      + `\n\n${open.length ? 'MAL: el fichero de prueba se descargó' : 'OK: el fichero de prueba no se pudo descargar'}`);
  },
  async procesos() {
    const n = Math.max(2, Math.min(64, +$('n').value || 20));
    show(`Lanzando ${n} peticiones de 1 s a la vez…`);
    // las que el servidor rechaza sin ejecutarlas (508: el límite de procesos de entrada de CloudLinux; 503, 429), que no
    // esperan turno: el límite es el de las que atendió a la vez (antes, la prueba acababa en error, sin cifra)
    const all = await Promise.all(Array.from({length: n}, async () => {
      const x = await req('espera', '&ms=1000');
      if ([429, 503, 508].includes(x.status)) return {rechazada: x.status};
      if (!x.ok) throw new Error(`HTTP ${x.status}: ${await x.text()}`);
      return x.json();
    }));
    const r = all.filter(x => !x.rechazada), codes = [...new Set(all.filter(x => x.rechazada).map(x => x.rechazada))].join(', ');
    let cur = 0, max = 0;                           // las que el servidor atendió a la vez (con su reloj)
    for (const [, d] of r.flatMap(x => [[x.inicio, 1], [x.fin, -1]]).sort((a, b) => a[0] - b[0] || a[1] - b[1])) max = Math.max(max, cur += d);
    if (codes) {
      // cada sesión RDP necesita hasta 5 a la vez: la suya y hasta 4 del teclado y el ratón (docs/es/HOSTING.md, Capacidad)
      const keep = Math.floor(max / 5);
      warn('procesos', [keep < 1
        ? `El servidor rechaza peticiones PHP (HTTP ${codes}) al pasar de ${max} a la vez: no alcanza ni para una sesión RDP, que necesita hasta 5 a la vez (la suya y las del teclado y el ratón). Pide al hosting más procesos de entrada (entry processes).`
        : `El servidor rechaza peticiones PHP (HTTP ${codes}) al pasar de ${max} a la vez: pon «Sesiones RDP a la vez» (max_sessions_total, en Administración → Ajustes) como mucho en ${keep}. Cada sesión RDP necesita hasta 5 peticiones a la vez (la suya y las del teclado y el ratón), y las que el servidor rechaza esperan a reintentarse.`]);
      show(`Peticiones a la vez: ${max} de ${n}; rechazadas sin ejecutarse: ${all.length - r.length} (HTTP ${codes})\n`
        + (keep < 1 ? 'No alcanza para MuDeskClient.' : `max_sessions_total: como mucho ${keep}.`));
      return;
    }
    const h1 = r.some(x => /^HTTP\/1/.test(x.protocolo)), limited = max >= n || (h1 && max >= 6);
    const keep = max - 2;                           // cada sesión ocupa un proceso; el teclado, el ratón y la web necesitan alguno libre
    warn('procesos', limited ? [] : [keep < 1
      ? `El servidor solo atiende ${max} ${max === 1 ? 'petición' : 'peticiones'} PHP a la vez: no alcanza ni para una sesión RDP, que ocupa un proceso PHP mientras dura, porque el teclado, el ratón y la web necesitan otros libres. Pide al hosting más procesos PHP (por ejemplo pm.max_children de PHP-FPM).`
      : `El servidor atiende ${max} peticiones PHP a la vez: pon «Sesiones RDP a la vez» (max_sessions_total, en Administración → Ajustes) por debajo, como mucho en ${keep}. Cada sesión RDP ocupa un proceso PHP mientras dura, y el teclado, el ratón y la web necesitan alguno libre.`]);
    show(`Peticiones a la vez: ${max}${limited ? ' o más' : ''} de ${n} (procesos distintos: ${new Set(r.map(x => x.pid)).size}, ${r[0].protocolo})\n`
      + (limited ? (max >= n ? 'Todas a la vez: repite con más peticiones para encontrar el límite.' : 'Con HTTP/1.1 el navegador no abre más de 6 conexiones al servidor: desde aquí no se puede medir más.')
        + `\nmax_sessions_total debe quedar por debajo del número de procesos PHP que admita el servidor.` : keep < 1 ? 'No alcanza para MuDeskClient.' : `max_sessions_total: como mucho ${keep}.`));
  },
  async rdp() { show(await (await req('rdp', `&h=${encodeURIComponent($('h').value)}&p=${+$('p').value}`)).text()); },
  async ping() {
    const t = [];
    for (let i = 0; i < 20; i++) { const a = performance.now(); await (await req('ping')).text(); t.push(performance.now() - a); }
    show(`Media: ${(t.reduce((x, y) => x + y) / t.length).toFixed(1)} ms · Máx: ${Math.max(...t).toFixed(1)} ms`);
  },
  stop() { ctrl?.abort(); },
  async stream() {
    ctrl?.abort();
    ctrl = new AbortController();
    const target = +$('s').value, dec = new TextDecoder();
    let buf = '', srv0 = null, cli0 = 0, last = 0, maxLag = 0;
    show('Conectando…');
    try {
      const r = await req('stream', `&s=${target}`, ctrl.signal);
      if (!r.ok) return show(`HTTP ${r.status}: ${await r.text()}`);
      const rd = r.body.getReader();
      for (;;) {
        const {value, done} = await rd.read();
        if (done) break;
        buf += dec.decode(value, {stream: true});
        const lines = buf.split('\n'); buf = lines.pop();
        for (const l of lines) {
          if (!l.trim()) continue;
          const srv = +l, now = performance.now();
          if (srv0 === null) { srv0 = srv; cli0 = now; }
          last = srv;
          maxLag = Math.max(maxLag, (now - cli0) - (srv - srv0));
          show(`En curso: ${(srv / 1000).toFixed(0)} s de ${target} s\nRetraso máx. por búfer: ${maxLag.toFixed(0)} ms`);
        }
      }
      const ok = last >= target * 1000;
      show(`${ok ? 'OK: aguantó' : 'CORTADA a los'} ${(last / 1000).toFixed(0)} s de ${target} s\nRetraso máx. por búfer: ${maxLag.toFixed(0)} ms`);
    } catch (e) {
      show(e.name === 'AbortError' ? `Parada a los ${(last / 1000).toFixed(0)} s` : `Error: ${e.message} (a los ${(last / 1000).toFixed(0)} s)`);
    }
  }
};
document.querySelectorAll('button[data-t]').forEach(b => b.onclick = () => tests[b.dataset.t]().catch(e => show(`Error: ${e.message}`)));
</script>
</body>
</html>
