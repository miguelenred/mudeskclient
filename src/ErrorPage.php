<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk;

/**
 * Ends a web request that cannot go on: a page of ours when a browser opens the address (in its language, with what to
 * do), {"error": key} for the interface's own requests. Never PHP's messages, server paths or traces: those go to the
 * server's error log. Written without PHP 8 syntax, so it also explains an unsupported PHP version.
 */
final class ErrorPage
{
    /** key => language => [title, what happened, what to do]; the interface has the same keys as e_<key> in i18n.js. */
    private const TEXT = [
        'data_dir'  => [
            'es' => ['No se puede guardar en la carpeta de datos',
                'MuDeskClient guarda los usuarios, las conexiones y la auditoría en su carpeta data, y el servidor web no tiene permiso para escribir en ella.',
                'Para el administrador: da permiso de modificación sobre la carpeta data (y todo su contenido) a la cuenta con la que se ejecutan el servidor web y PHP, y vuelve a cargar la página. El detalle está en el registro de errores del servidor.'],
            'en' => ['Cannot save to the data folder',
                'MuDeskClient keeps users, connections and the audit log in its data folder, and the web server is not allowed to write there.',
                'For the administrator: give the account that runs the web server and PHP permission to modify the data folder (and everything in it), then reload the page. The details are in the server error log.'],
        ],
        'tmp_dir'   => [
            'es' => ['PHP no puede escribir en su carpeta temporal',
                'PHP guarda en su carpeta temporal lo que se envía al servidor mientras llega (un archivo que se sube, por ejemplo), y no tiene permiso para escribir en ella: lo enviado se ha perdido.',
                'Para el administrador: da permiso de escritura sobre la carpeta temporal de PHP (upload_tmp_dir en php.ini o, si no está puesta, la temporal del sistema) a la cuenta con la que se ejecutan el servidor web y PHP. El detalle está en el registro de errores del servidor.'],
            'en' => ['PHP cannot write to its temporary folder',
                'PHP keeps what is sent to the server in its temporary folder while it arrives (a file being uploaded, for example), and it is not allowed to write there: what was sent has been lost.',
                'For the administrator: give the account that runs the web server and PHP permission to write to the temporary folder of PHP (upload_tmp_dir in php.ini or, when it is not set, the system one). The details are in the server error log.'],
        ],
        'php'       => [
            'es' => ['Versión de PHP no admitida', 'MuDeskClient necesita PHP 8.1 a 8.5 con la extensión OpenSSL activada.',
                'Para el administrador: instala una de esas versiones o activa la extensión en php.ini (extension=openssl) y reinicia el servidor web.'],
            'en' => ['PHP version not supported', 'MuDeskClient needs PHP 8.1 to 8.5 with the OpenSSL extension enabled.',
                'For the administrator: install one of those versions or enable the extension in php.ini (extension=openssl), then restart the web server.'],
        ],
        'https'     => [
            'es' => ['Hace falta una conexión segura', 'Por seguridad, MuDeskClient solo se abre con https://.',
                'Abre esta misma dirección con https:// en lugar de http://.'],
            'en' => ['A secure connection is required', 'For security, MuDeskClient only opens over https://.',
                'Open this same address with https:// instead of http://.'],
        ],
        'client_ip' => [
            'es' => ['Acceso no permitido', 'MuDeskClient no admite conexiones desde la dirección IP de este equipo.',
                'Si necesitas entrar desde aquí, pide al administrador que la añada en Administración → Ajustes → IPs que pueden acceder a la web.'],
            'en' => ['Access not allowed', 'MuDeskClient does not accept connections from the IP address of this device.',
                'If you need to sign in from here, ask the administrator to add it in Administration → Settings → IPs allowed to use the web app.'],
        ],
        'internal'  => [
            'es' => ['Algo ha fallado', 'El servidor no ha podido completar la petición.',
                'Vuelve a intentarlo dentro de un momento. Si se repite, el administrador encontrará el detalle en el registro de errores del servidor.'],
            'en' => ['Something went wrong', 'The server could not complete the request.',
                'Try again in a moment. If it happens again, the administrator will find the details in the server error log.'],
        ],
    ];

    private const RETRY = ['es' => 'Volver a intentarlo', 'en' => 'Try again'];
    private const CODE  = ['es' => 'Código de error', 'en' => 'Error code'];

    public static function send(int $status, string $error): never
    {
        self::output($status, $error);
        exit(PHP_SAPI === 'cli' ? 1 : 0);
    }

    /**
     * The page (or JSON) of an error, without ending the script: what a shutdown function sends after a fatal error, where
     * exit() is not called (PHP 8.1's built-in server stopped on it after running out of memory).
     */
    public static function output(int $status, string $error): void
    {
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "MuDeskClient: $error\n");
            return;
        }
        $html = strpos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/html') !== false;   // else the interface, the stream, a download
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: ' . ($html ? 'text/html' : 'application/json') . '; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: no-referrer');
            header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
        }
        echo $html ? self::html($status, $error, I18n::accept((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''))) : '{"error":"' . $error . '"}';
    }

    /** The page: the look of the sign-in screen (app.css), light or dark as the system. */
    public static function html(int $status, string $error, string $lang): string
    {
        $e = static function (string $s): string {
            return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        };
        $error = isset(self::TEXT[$error]) ? $error : 'internal';
        $t     = self::TEXT[$error][$lang];
        $retry = in_array($error, ['data_dir', 'php', 'internal'], true)
            ? '<p class="row end"><a class="btn primary" href="">' . self::RETRY[$lang] . '</a></p>' : '';
        return '<!doctype html><html lang="' . $lang . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light dark">'
            . '<meta name="robots" content="noindex"><title>' . $e($t[0]) . ' - MuDeskClient</title>'
            . '<link rel="icon" href="assets/icon.svg" type="image/svg+xml"><link rel="stylesheet" href="assets/app.css?v=' . VERSION . '">'
            . '</head><body><div class="center"><div class="auth">'
            . '<div class="brand"><img src="assets/icon.svg" alt="">MuDeskClient</div>'
            . '<h1>' . $e($t[0]) . '</h1><p>' . $e($t[1]) . '</p><p class="muted">' . $e($t[2]) . '</p>' . $retry
            . '<p class="muted small">' . self::CODE[$lang] . ': ' . $status . ' · ' . $e($error) . '</p>'
            . '</div></div></body></html>';
    }
}
