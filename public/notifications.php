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

    <section class="account-card" aria-labelledby="push-heading">
      <p class="account-eyebrow">Real-time delivery</p>
      <h2 id="push-heading">Push notifications</h2>
      <p>
        Get a browser notification for select account events even when no ChitChat tab is open. A push
        payload never carries a message body — only the same sender and room text already shown below —
        and is a best-effort nudge, not a delivery guarantee; this timeline remains the complete record.
      </p>
      <p id="push-unsupported" class="account-muted hidden">This browser does not support push notifications.</p>
      <p id="push-status" class="account-muted" role="status" aria-live="polite"></p>
      <div class="account-action-row">
        <button id="push-subscribe-toggle" class="secondary-button" type="button" disabled>Enable push notifications</button>
      </div>

      <div id="push-settings" class="hidden">
        <form id="push-preferences-form" class="form-stack">
          <label>
            <input id="push-mentioned-enabled" type="checkbox">
            Notify me when I'm mentioned (@username, @room, or @here)
          </label>

          <h3>Quiet hours</h3>
          <p class="account-muted">Push is suppressed during this local time window; the in-app timeline is unaffected.</p>
          <label>
            <span>Start hour</span>
            <select id="push-quiet-start">
              <option value="">Off</option>
              <?php for ($hour = 0; $hour < 24; $hour++): ?>
                <option value="<?= $hour ?>"><?= sprintf('%02d:00', $hour) ?></option>
              <?php endfor; ?>
            </select>
          </label>
          <label>
            <span>End hour</span>
            <select id="push-quiet-end">
              <option value="">Off</option>
              <?php for ($hour = 0; $hour < 24; $hour++): ?>
                <option value="<?= $hour ?>"><?= sprintf('%02d:00', $hour) ?></option>
              <?php endfor; ?>
            </select>
          </label>
          <label>
            <span>Timezone</span>
            <input id="push-quiet-timezone" type="text" placeholder="e.g. Europe/Berlin" list="push-timezone-options" autocomplete="off">
            <datalist id="push-timezone-options">
              <?php foreach (DateTimeZone::listIdentifiers() as $identifier): ?>
                <option value="<?= htmlspecialchars($identifier, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></option>
              <?php endforeach; ?>
            </datalist>
          </label>

          <div class="account-action-row">
            <button id="push-preferences-save" class="secondary-button" type="submit">Save notification preferences</button>
            <p id="push-preferences-status" class="account-muted" role="status" aria-live="polite"></p>
          </div>
        </form>

        <h3>Devices receiving push</h3>
        <ul id="push-device-list" class="push-device-list" aria-label="Devices subscribed to push notifications"></ul>
        <p id="push-device-empty" class="account-muted hidden">No devices are currently subscribed.</p>
      </div>

      <p id="push-error" class="error-text" role="alert"></p>
    </section>

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

  <script type="module" src="/assets/js/push-notifications.js"></script>
  <script type="module" src="/assets/js/privacy-notifications.js"></script>
</body>
</html>
