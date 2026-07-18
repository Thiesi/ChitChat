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
  <meta name="color-scheme" content="dark light">
  <meta name="description" content="<?= $appName ?> privacy and security notifications">
  <title>Privacy notifications · <?= $appName ?></title>
  <link rel="stylesheet" href="/assets/css/app.css">
  <link rel="stylesheet" href="/assets/css/components.css">
  <link rel="stylesheet" href="/assets/css/accessibility.css">
  <link rel="stylesheet" href="/assets/css/account.css">
  <link rel="stylesheet" href="/assets/css/privacy-notifications.css">
</head>
<body>
  <div id="privacy-notifications-loading" class="app-loading" role="status">Loading privacy notifications…</div>

  <main id="privacy-notifications-shell" class="account-shell hidden">
    <header class="account-header">
      <div>
        <p class="account-eyebrow"><?= $appName ?></p>
        <h1>Privacy notifications</h1>
        <p id="privacy-notifications-identity" class="account-muted"></p>
      </div>
      <a class="secondary-button" href="/">Back to chat</a>
    </header>

    <section class="account-card" aria-labelledby="privacy-notifications-heading">
      <div class="privacy-notifications-heading-row">
        <div>
          <p class="account-eyebrow">Security and transparency</p>
          <h2 id="privacy-notifications-heading">Events affecting your account or content</h2>
        </div>
        <button id="privacy-notifications-mark-all" class="secondary-button" type="button" disabled>Mark all as read</button>
      </div>

      <p>
        These durable notices disclose selected administrative and moderation actions without copying message bodies,
        administrator reasons, IP addresses, credentials, or recovery material into the notification record.
      </p>

      <p id="privacy-notifications-status" class="account-muted" role="status" aria-live="polite"></p>
      <ol id="privacy-notifications-list" class="privacy-notification-list" aria-label="Privacy notifications"></ol>
      <p id="privacy-notifications-empty" class="account-muted hidden">No privacy notifications have been recorded for this account.</p>

      <div class="account-action-row">
        <button id="privacy-notifications-more" class="secondary-button hidden" type="button">Load older notifications</button>
      </div>
    </section>

    <p id="privacy-notifications-error" class="error-text" role="alert"></p>
  </main>

  <script type="module" src="/assets/js/privacy-notifications.js"></script>
</body>
</html>
