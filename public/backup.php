<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

use MuDesk\{Audit, Auth, Backup, Config, Http};

require __DIR__ . '/../src/bootstrap.php';

// A backup of the installation, for administrators (see Backup):
//   GET  ?a=download&id=   the backup asked for first with admin.backup.prepare (its key kept in the web session, used once),
//                          encrypted as it is sent
//   POST ?a=upload&id=&o=  a backup to restore, in pieces of the raw body (o: where it goes); restored by admin.backup.restore
Http::guard();
if (!isset($_COOKIE['MUDESKSID'])) {             // signed out: no session file is made for the request
    Http::fail('auth', 401);
}
Auth::start();
$u  = Auth::require(true);
$q  = static fn(string $k): string => is_string($_GET[$k] ?? null) ? $_GET[$k] : '';
$id = $q('id');
if (!preg_match('/^[0-9a-f]{16}$/D', $id)) {
    Http::fail('not_found', 404);
}

if ($q('a') === 'download') {
    $job = $_SESSION['backups'][$id] ?? null;
    unset($_SESSION['backups'][$id]);
    Auth::release();
    if (!is_array($job)) {
        Http::fail('not_found', 404);
    }
    @set_time_limit(0);
    $entries = Backup::entries((array)$job['with']);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="mudesk-' . date('Ymd-His') . '.mudeskbackup"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'wb');
    try {
        $n = Backup::write($out, (string)base64_decode((string)$job['key']), (string)base64_decode((string)$job['salt']),
            ['version' => MuDesk\VERSION, 'created' => date('c'), 'by' => $u['username'], 'with' => (array)$job['with']], $entries);
    } catch (RuntimeException $e) {                // (a file that could not be read: the backup ends unfinished, refused if restored)
        error_log('MuDeskClient: a backup was cut short: ' . $e->getMessage());
        Audit::log('backup_download', ['with' => (array)$job['with'], 'error' => $e->getMessage()], null, false);
        exit;
    }
    fclose($out);
    Audit::log('backup_download', ['with' => (array)$job['with'], 'files' => count($entries), 'bytes' => $n], null, true);
    exit;
}
if ($q('a') === 'upload' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    Auth::checkCsrf();
    Auth::release();
    $file = Config::dir("tmp/restore-$id.mudeskbackup");
    $at   = (int)($_GET['o'] ?? 0);
    clearstatcache(true, $file);
    if ($at < 0 || ($at > 0 && (!is_file($file) || filesize($file) !== $at))) {
        Http::fail('offset');
    }
    $in  = fopen('php://input', 'rb');
    $out = @fopen($file, $at === 0 ? 'wb' : 'ab');
    if ($out === false) {
        Http::fail('internal', 500);
    }
    $n = stream_copy_to_stream($in, $out);
    fclose($out);
    Http::json(['ok' => true, 'size' => $at + (int)$n]);
}
Http::fail('unknown_action', 404);
