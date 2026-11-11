<?php

declare(strict_types=1);

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__) . '/bootstrap/app.php';
$appName = htmlspecialchars(\ChitChat\Admin\ApplicationNameService::resolve($config), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="application-name" content="<?= $appName ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light dark">
  <script src="/assets/js/theme.js"></script>
  <meta name="description" content="<?= $appName ?> notifications">
  <title>Notifications · <?= $appName ?></title>
  <link rel="stylesheet" href="/assets/css/tokens.css">
  <link rel="stylesheet" href="/assets/css/app.css">
<?= \ChitChat\View\HeadAssets::html(\ChitChat\InstallationIcon\IconService::resolve($config)) ?>
  <link rel="stylesheet" href="/assets/css/components.css">
  <link rel="stylesheet" href="/assets/css/accessibility.css">
  <link rel="stylesheet" href="/assets/css/account.css">
  <link rel="stylesheet" href="/assets/css/privacy-notifications.css">
  <link rel="stylesheet" href="/assets/css/shell.css">
</head>
<body>
  <div id="privacy-notifications-loading" class="app-loading" role="status">Loading activity…</div>

  <?= \ChitChat\View\AppShell::open('activity', 'privacy-notifications-shell', 'Skip to content', true, config: $config) ?>
  <main id="privacy-notifications-shell" tabindex="-1" class="account-shell hidden">
    <header class="account-header">
      <div>
        <p class="account-eyebrow"><?= $appName ?></p>
        <h1>Activity</h1>
        <p id="privacy-notifications-identity" class="account-muted"></p>
      </div>
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
      <ol id="privacy-notifications-list" class="privacy-notification-list" aria-label="Notifications"></ol>
      <p id="privacy-notifications-empty" class="account-muted hidden">No notifications have been recorded for this account.</p>

      <div class="account-action-row">
        <button id="privacy-notifications-more" class="secondary-button hidden" type="button">Load older notifications</button>
      </div>
    </section>

    <p id="privacy-notifications-error" class="error-text" role="alert"></p>
    <?= \ChitChat\View\PoweredBy::html($config) ?>
  </main>
  <?= \ChitChat\View\AppShell::close() ?>

  <script type="module" src="/assets/js/privacy-notifications.js"></script>
  <script type="module" src="/assets/js/shell.js"></script>
</body>
</html>
