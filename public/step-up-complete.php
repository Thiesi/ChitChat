<?php

declare(strict_types=1);

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__) . '/bootstrap/app.php';
$appName = htmlspecialchars(\ChitChat\Admin\ApplicationNameService::resolve($config), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light dark">
  <meta name="robots" content="noindex">
  <script src="/assets/js/theme.js"></script>
  <title>Confirming · <?= $appName ?></title>
  <link rel="stylesheet" href="/assets/css/app.css">
  <link rel="stylesheet" href="/assets/css/components.css">
</head>
<body>
  <main class="auth-shell">
    <section class="auth-card" aria-labelledby="step-up-complete-title">
      <h1 id="step-up-complete-title" class="brand"><?= $appName ?></h1>
      <p id="step-up-complete-status" role="status" aria-live="polite">Confirming…</p>
      <p id="step-up-complete-error" class="error-text" role="alert"></p>
      <p>You can close this window and return to <?= $appName ?>.</p>
    </section>
  </main>
  <script type="module" src="/assets/js/step-up-complete.js"></script>
</body>
</html>
