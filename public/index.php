<?php
// MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
declare(strict_types=1);

use MuDesk\{Http, I18n};

require __DIR__ . '/../src/bootstrap.php';

Http::guard();
header('Cache-Control: no-store');
$build = Http::build(__DIR__ . '/assets');               // changes with any script or style: an update is loaded at once
$v     = MuDesk\VERSION . '-' . $build;
?><!doctype html>
<html lang="<?= I18n::accept((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) ?>" data-build="<?= $build ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#2563eb">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="MuDesk">
<title>MuDeskClient</title>
<link rel="manifest" href="manifest.webmanifest">
<link rel="icon" href="assets/icon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
<link rel="stylesheet" href="assets/app.css?v=<?= $v ?>">
</head>
<body>
<div id="app"><div class="center muted">…</div></div>
<script type="module" src="assets/app.js?v=<?= $v ?>"></script>
</body>
</html>
