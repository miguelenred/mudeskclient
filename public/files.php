<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

use MuDesk\{Audit, Auth, Config, Files, Http, ZipStream};

require __DIR__ . '/../src/bootstrap.php';

Http::guard();
if (!isset($_COOKIE['MUDESKSID'])) {             // signed out: no session file is made for the request
    Http::fail('auth', 401);
}
Auth::start();
$u = Auth::require();
$q = static fn(string $k): string => is_string($_GET[$k] ?? null) ? $_GET[$k] : '';   // a list (a[]=x) is not text: no warning
$a = $q('a');
$p = $q('p');

// images shown as such in the file manager (its thumbnails): only bitmap types, never as a page (no SVG, no HTML)
const VIEW = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp',
              'bmp' => 'image/bmp', 'avif' => 'image/avif'];

if ($a === 'download') {
    Auth::release();
    $file = Files::path($u['id'], $p);
    if ($file === null || !is_file($file)) {
        Http::fail('not_found', 404);
    }
    $type = !empty($_GET['view']) ? (VIEW[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? null) : null;
    if (!empty($_GET['view']) && $type === null) {
        Http::fail('not_found', 404);
    }
    @set_time_limit(0);                                   // wall time on Windows: a big file takes longer
    header('Cross-Origin-Resource-Policy: same-origin');
    if ($type !== null) {                                 // a thumbnail: not a download nor an audit line each time the folder is shown
        // revalidated each time (Last-Modified, 304): kept for minutes, the next user of the browser could see it after sign-out
        $mtime = gmdate('D, d M Y H:i:s', (int)filemtime($file)) . ' GMT';
        header('Cache-Control: private, no-cache');
        header('Last-Modified: ' . $mtime);
        if (($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '') === $mtime) {
            http_response_code(304);
            exit;
        }
        header('Content-Type: ' . $type);
        header('Content-Length: ' . filesize($file));
        header("Content-Disposition: inline; filename*=UTF-8''" . rawurlencode(basename($file)));
        header("Content-Security-Policy: default-src 'none'; sandbox");
        readfile($file);
        exit;
    }
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($file));
    header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode(basename($file)));
    header('Cache-Control: no-store');
    Audit::log('file_download', ['path' => $p, 'size' => filesize($file)], null, true);   // before sending: a download cut short counts too
    readfile($file);
    exit;
}

// ?p=folder&n[]=name...: those items of the folder (folders with all they hold) in one ZIP, made as it is sent. The page asks
// first (check=1, the names in the body: hundreds of names do not fit in an address, IIS takes 2 KB) and gets an id kept in
// the session for the download (z=id)
if ($a === 'zip') {
    if (!empty($_GET['check'])) {
        Auth::checkCsrf();
        $names = json_decode(Http::body(), true);
    } elseif (isset($_SESSION['zips'][$q('z')])) {
        [$p, $names] = $_SESSION['zips'][$q('z')];
        unset($_SESSION['zips'][$q('z')]);                  // used once: kept, 8 lists of 10,000 names weighed on every request
    } else {
        $names = $_GET['n'] ?? [];
    }
    $names   = is_array($names) && array_is_list($names) && count($names) <= 10000 ? $names : [];
    $entries = Files::zipEntries($u['id'], $p, $names);
    if ($entries === null) {
        Auth::release();
        Http::fail('not_found', 404);
    }
    $length = $entries === false ? null : ZipStream::length($entries);
    if ($length === null) {
        Auth::release();
        Http::fail('too_big');
    }
    if (!empty($_GET['check'])) {                          // asked first by the page: an error is shown there, not saved as a file
        $id = bin2hex(random_bytes(8));
        $_SESSION['zips'] = array_slice(($_SESSION['zips'] ?? []) + [$id => [$p, $names]], -8, null, true);
        Auth::release();
        Http::json(['size' => $length, 'id' => $id]);
    }
    Auth::release();
    $name   = count($names) === 1 ? (string)Files::clean((string)reset($names)) : (basename(Files::clean($p) ?: '') ?: 'MuDesk');
    @set_time_limit(0);
    header('Content-Type: application/zip');
    header('Content-Length: ' . $length);
    header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($name . '.zip'));
    header('Cache-Control: no-store');
    Audit::log('file_download', ['path' => $p, 'names' => array_slice(array_values($names), 0, 50), 'zip' => true,
        'size' => $length], null, true);
    $out = fopen('php://output', 'wb');
    ZipStream::write($entries, $out);
    fclose($out);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Http::fail('method', 405);
}
Auth::checkCsrf();
Auth::release();
$path = Files::path($u['id'], $p) ?? Http::fail('invalid_path');

switch ($a) {
    case 'list':      // ?count=1 (opened, refreshed): the folder counted again, so the quota counter takes in what changed by other means
        $items = Files::list($u['id'], $p) ?? Http::fail('not_found', 404);
        Http::json(['items' => $items, 'quota' => Files::quota()] + array_filter(['usage' => Files::listedUsage($u['id'], !empty($_GET['count']))],
            static fn($v) => $v !== null));

    case 'tree':      // body: JSON list of the folders open in the tree; their folders: [[folder, [names], more]...]
        $open = json_decode(Http::body(), true);
        if (!is_array($open) || !array_is_list($open) || count($open) > 200) {
            Http::fail('invalid_input');
        }
        Http::json(['dirs' => Files::subdirs($u['id'], $open, 2000)]);

    case 'move':      // ?p=from&to=destination: moved or renamed (the destination must not exist)
        $to  = $q('to');
        $err = Files::move($u['id'], $p, $to);
        if ($err !== null) {
            Http::fail($err, $err === 'not_found' ? 404 : 400);
        }
        Audit::log('file_move', ['path' => $p, 'to' => Files::clean($to)], null, true);
        Http::json(['ok' => true]);

    case 'mkdirs':    // body: JSON list of folders under ?p= (an uploaded folder), parents included; existing ones kept
        $dirs = json_decode(Http::body(), true);
        if (!is_array($dirs) || !array_is_list($dirs) || count($dirs) > 1000) {
            Http::fail('invalid_input');
        }
        $err = Files::mkdirs($u['id'], $p, $dirs);
        if ($err !== null) {
            Http::fail($err, $err === 'not_found' ? 404 : 400);
        }
        Audit::log('file_mkdir', ['path' => $p, 'dirs' => count($dirs)], null, true);
        Http::json(['ok' => true]);

    case 'mkdir':
        $name = Files::clean($q('n'));
        if (!is_dir($path)) {
            Http::fail('not_found', 404);
        }
        if ($name === null || $name === '' || str_contains($name, '/') || !@mkdir($path . '/' . $name)) {
            Http::fail('invalid_name');
        }
        Audit::log('file_mkdir', ['path' => rtrim($p, '/') . '/' . $name], null, true);
        Http::json(['ok' => true]);

    case 'delete':    // a folder goes with everything in it (the page asks first)
        $lock = fopen(Config::dir('tmp/upload-' . $u['id'] . '.lock'), 'c');   // after a chunk still being written (a cancelled upload)
        flock($lock, LOCK_EX);
        if ($path === Files::root($u['id'])) {
            Http::fail('invalid_path');
        }
        if (!file_exists($path)) {
            Http::fail('not_found', 404);
        }
        $dir = is_dir($path);
        Files::delete($u['id'], $path);
        Audit::log('file_delete', ['path' => $p, 'dir' => $dir], null, true);
        Http::json(['ok' => true]);

    case 'upload': // raw body, chunked: ?p=dir/name&o=offset
        $offset = (int)($_GET['o'] ?? 0);
        $last   = !empty($_GET['last']);
        if ($path === Files::root($u['id']) || is_dir($path)) {
            Http::fail('invalid_path');
        }
        if (!is_dir(dirname($path))) {
            Http::fail('not_found', 404);
        }
        $lock = fopen(Config::dir('tmp/upload-' . $u['id'] . '.lock'), 'c');   // one upload of the user at a time
        flock($lock, LOCK_EX);
        $in   = fopen('php://input', 'rb');
        $size = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);          // none with a chunked body: the copy below stops anyway
        // a file replaced gives its room back first: a new version that fits was refused near the quota
        $left = Files::quotaLeft($u['id']) + ($offset === 0 && is_file($path) ? (int)filesize($path) : 0);
        if ($size > $left) {
            Http::fail('quota');
        }
        if ($offset === 0) {
            if (is_file($path)) {                                 // replaced: its quota goes back
                Files::delete($u['id'], $path);
            }
            if (!$last) {                                         // recorded from the first chunk, whatever the client sends later
                Audit::log('file_upload_start', ['path' => $p, 'size' => $size], null, true);
            }
        } elseif (!is_file($path) || filesize($path) !== $offset) {
            Http::fail('offset');
        }
        $out = @fopen($path, 'ab');
        if ($out === false) {                                     // a name the file system refuses
            Http::fail('invalid_path');
        }
        $n = stream_copy_to_stream($in, $out, $left === PHP_INT_MAX ? null : $left + 1);
        fclose($out);
        clearstatcache(true, $path);
        // charged once written (a session may have taken the room meanwhile); over the quota what this request wrote is dropped
        if ($n === false || $n > $left || !Files::charge($u['id'], $n)) {
            if ($offset === 0) {
                @unlink($path);
            } else {
                $h = fopen($path, 'r+');
                ftruncate($h, $offset);
                fclose($h);
            }
            Http::fail('quota');
        }
        if ($last) {
            Audit::log('file_upload', ['path' => $p, 'size' => filesize($path)], null, true);
        }
        Http::json(['ok' => true, 'size' => filesize($path)]);

    default:
        Http::fail('unknown_action', 404);
}
