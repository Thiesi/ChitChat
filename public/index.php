<?php

declare(strict_types=1);

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__) . '/bootstrap/app.php';
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($config->applicationName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
  <style>
    body { max-width: 52rem; margin: 4rem auto; padding: 0 1.25rem; font: 1rem/1.6 system-ui, sans-serif; }
    code { background: #eee; padding: .1rem .3rem; }
  </style>
</head>
<body>
  <h1><?= htmlspecialchars($config->applicationName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
  <p>The v1 reconstruction foundation is running.</p>
  <p>Version: <code><?= htmlspecialchars($config->applicationVersion, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></code></p>
  <p>Authentication and chat functionality will be added in subsequent milestones.</p>
</body>
</html>
