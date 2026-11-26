<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

namespace MuDesk;

ini_set('zend.exception_ignore_args', '1');   // no arguments (a password) in the stack traces of the error log

const VERSION = '1.3.0';

spl_autoload_register(static function (string $class): void {
    if (strncmp($class, 'MuDesk\\', 7) === 0) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 7)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

// PHP keeps a request body over 16 KB in its temporary folder (upload_tmp_dir, else the system's) while it arrives; when it
// cannot write there it drops the body, with a warning, before this script starts: an uploaded file was then saved empty
// and answered as done. Checked first, before any other warning can take the place of that one.
if (PHP_SAPI !== 'cli' && strpos((string)(error_get_last()['message'] ?? ''), "POST data can't be buffered") !== false) {
    error_log('MuDeskClient: PHP cannot write to its temporary folder (' . (ini_get('upload_tmp_dir') ?: sys_get_temp_dir())
        . ') and dropped the body of this request: give the account that runs PHP write access to it');
    ErrorPage::send(500, 'tmp_dir');
}

// web requests: PHP's own messages (paths, stack) go to the server's error log, never to the browser, even with
// display_errors on (XAMPP, development setups); the visitor gets a page of ours (ErrorPage). The command line keeps
// PHP's own report and exit status.
if (PHP_SAPI !== 'cli') {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    set_exception_handler(static function (\Throwable $e): void {
        error_log('MuDeskClient: ' . $e);
        ErrorPage::send(500, 'internal');
    });
    register_shutdown_function(static function (): void {   // a fatal error (memory, time): our page, not a blank one
        $e = error_get_last();
        if ($e !== null && ($e['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR)) && !headers_sent()) {
            if (str_starts_with((string)$e['message'], 'Allowed memory size')) {   // the page needs a little memory: none is left
                @ini_set('memory_limit', (string)(memory_get_usage() + (32 << 20)));
            }
            ErrorPage::output(500, 'internal');                 // (no exit() inside a shutdown function)
        }
    });
}

if (PHP_VERSION_ID < 80100 || !extension_loaded('openssl')) {
    ErrorPage::send(500, 'php');
}

Config::load(dirname(__DIR__) . '/config.php');
