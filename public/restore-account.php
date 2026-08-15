<?php

declare(strict_types=1);

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__) . '/bootstrap/app.php';
$appName = htmlspecialchars($config->applicationName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="dark">
  <meta name="description" content="Restore a closing <?= $appName ?> account">
  <title>Restore account · <?= $appName ?></title>
  <link rel="stylesheet" href="/assets/css/app.css">
  <link rel="stylesheet" href="/assets/css/components.css">
  <link rel="stylesheet" href="/assets/css/accessibility.css">
</head>
<body>
  <main class="auth-shell">
    <section class="auth-card" aria-labelledby="restore-title">
      <h1 id="restore-title" class="brand">Restore account</h1>
      <p class="tagline">
        A closure request can be reversed during its 14-day cooling-off period. Normal sign-in remains disabled until restoration succeeds.
      </p>
      <form id="restore-account-form" class="form-stack" autocomplete="on">
        <label>
          Username
          <input id="restore-username" name="username" type="text" autocomplete="username" minlength="3" maxlength="32" required>
        </label>
        <label>
          Current password
          <input id="restore-password" name="password" type="password" autocomplete="current-password" minlength="12" maxlength="4096" required>
        </label>
        <button class="primary-button" type="submit">Restore account</button>
      </form>
      <p id="restore-account-status" role="status" aria-live="polite"></p>
      <p id="restore-account-error" class="error-text" role="alert"></p>
      <a class="secondary-button" href="/">Back to sign in</a>
    </section>
  </main>
  <script type="module" src="/assets/js/restore-account.js"></script>
</body>
</html>
