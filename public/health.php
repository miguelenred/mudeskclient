<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
// For a monitor: {"status":"ok"} (200) while the gateway works, {"status":"error","problem":"data"|"store"} (503) when its data
// folder cannot be written or its users or settings cannot be read. Nothing else of the installation is told; the allowed
// clients apply (list the monitor's address when they are a list).
declare(strict_types=1);

use MuDesk\{Http, Status};

require __DIR__ . '/../src/bootstrap.php';

Http::guard();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$problem = Status::problem();
http_response_code($problem === null ? 200 : 503);
echo json_encode($problem === null ? ['status' => 'ok'] : ['status' => 'error', 'problem' => $problem]);
