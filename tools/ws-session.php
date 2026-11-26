<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);
/*
 * One session of the WebSocket service, in a PHP of its own (tools/ws-server.php starts it where PHP cannot copy the
 * service itself, see FORK there; never the web server): the request it is given on its first line ({server: the
 * request's details, body: what rdp.php?a=stream takes}) run by rdp.php as over HTTP, so a session is the same either way.
 * Its output, the frames, goes back to the service; its standard input closing says the browser left. PHP's messages go to
 * the standard error (the service's log), never into the frames.
 */
if (PHP_SAPI !== 'cli') {
    exit;
}
ini_set('display_errors', 'stderr');
$ctx = json_decode((string)fgets(STDIN, 1 << 20), true);
if (!is_array($ctx) || !is_array($ctx['server'] ?? null) || !is_string($ctx['body'] ?? null)) {
    fwrite(STDERR, "MuDeskClient WebSocket: a session without its request\n");
    exit(1);
}
require_once __DIR__ . '/../src/bootstrap.php';
MuDesk\WebSocket::asRequest($ctx['server'], $ctx['body'], STDIN);
unset($ctx);
require __DIR__ . '/../public/rdp.php';
