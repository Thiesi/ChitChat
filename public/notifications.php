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
<?= \ChitChat\View\HeadAssets::html() ?>
  <link rel="stylesheet" href="/assets/css/components.css">
  <link rel="stylesheet" href="/assets/css/accessibility.css">
  <link rel="stylesheet" href="/assets/css/account.css">
  <link rel="stylesheet" href="/assets/css/privacy-notifications.css">
</head>
<body>
  <div id="privacy-notifications-loading" class="app-loading" role="status">Loading notifications…</div>

  <main id="privacy-notifications-shell" class="account-shell hidden">
    <header class="account-header">
      <div>
        <p class="account-eyebrow"><?= $appName ?></p>
        <h1>Notifications</h1>
        <p id="privacy-notifications-identity" class="account-muted"></p>
      </div>
      <a class="secondary-button" href="/">Back to chat</a>
    </header>

    <section class="account-card" aria-labelledby="push-heading">
      <p class="account-eyebrow">Notifications</p>
      <h2 id="push-heading">Alerts</h2>
      <p id="push-status" class="account-muted" role="status" aria-live="polite"></p>

      <!-- Account-wide: these apply to every device with alerts on, including the ChitChat app,
           so they're shown whether or not this browser is subscribed (#150). -->
      <div id="push-settings" class="hidden">
        <p>These choices apply to every device where alerts are on, including the ChitChat app.</p>
        <p id="push-no-device" class="push-note hidden">No device has alerts turned on yet. Your choices are saved for when you do.</p>
        <form id="push-preferences-form" class="form-stack">
          <h3>What should alert you?</h3>
          <label class="push-choice">
            <input id="push-dm-enabled" type="checkbox" role="switch" aria-describedby="push-dm-help">
            <span><span class="push-choice-label">New direct messages</span>
            <span id="push-dm-help" class="account-muted">Someone writes to you directly. The alert never shows what they wrote.</span></span>
          </label>
          <label class="push-choice">
            <input id="push-mentioned-enabled" type="checkbox" role="switch" aria-describedby="push-mentioned-help">
            <span><span class="push-choice-label">Mentions</span>
            <span id="push-mentioned-help" class="account-muted">@you, @room or @here.</span></span>
          </label>
          <label class="push-choice">
            <input id="push-pinged-enabled" type="checkbox" role="switch" aria-describedby="push-pinged-help">
            <span><span class="push-choice-label">Pings</span>
            <span id="push-pinged-help" class="account-muted">Someone uses /ping with your name.</span></span>
          </label>
          <p class="account-muted push-overlap">If one message is both a direct message and a mention, you get one alert, not two.</p>

          <h3>Quiet hours</h3>
          <p class="account-muted">No alerts during these hours. Your notifications here still arrive.</p>
          <label>
            <span>From</span>
            <select id="push-quiet-start">
              <option value="">Off</option>
              <?php for ($hour = 0; $hour < 24; $hour++): ?>
                <option value="<?= $hour ?>"><?= sprintf('%02d:00', $hour) ?></option>
              <?php endfor; ?>
            </select>
          </label>
          <label>
            <span>To</span>
            <select id="push-quiet-end">
              <option value="">Off</option>
              <?php for ($hour = 0; $hour < 24; $hour++): ?>
                <option value="<?= $hour ?>"><?= sprintf('%02d:00', $hour) ?></option>
              <?php endfor; ?>
            </select>
          </label>
          <label>
            <span>Time zone</span>
            <input id="push-quiet-timezone" type="text" placeholder="e.g. Europe/Berlin" list="push-timezone-options" autocomplete="off">
            <datalist id="push-timezone-options">
              <?php foreach (DateTimeZone::listIdentifiers() as $identifier): ?>
                <option value="<?= htmlspecialchars($identifier, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></option>
              <?php endforeach; ?>
            </datalist>
          </label>

          <div class="account-action-row">
            <button id="push-preferences-save" class="secondary-button" type="submit">Save</button>
            <p id="push-preferences-status" class="account-muted" role="status" aria-live="polite"></p>
          </div>
        </form>
      </div>

      <!-- This device only. -->
      <div id="push-browser" class="hidden">
        <h3>This browser</h3>
        <p id="push-unsupported" class="account-muted hidden">This browser can’t show alerts. Your choices above still apply to your other devices.</p>
        <p id="push-browser-status" class="account-muted"></p>
        <div class="account-action-row">
          <button id="push-subscribe-toggle" class="secondary-button" type="button" disabled>Turn on alerts here</button>
        </div>
        <p class="account-muted">Alerts can arrive late or not at all. Your notifications here are always complete.</p>
      </div>

      <div id="push-devices" class="hidden">
        <h3>Devices with alerts on</h3>
        <ul id="push-device-list" class="push-device-list" aria-label="Devices with alerts on"></ul>
        <p id="push-device-empty" class="account-muted hidden">None yet.</p>
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
      <ol id="privacy-notifications-list" class="privacy-notification-list" aria-label="Notifications"></ol>
      <p id="privacy-notifications-empty" class="account-muted hidden">No notifications have been recorded for this account.</p>

      <div class="account-action-row">
        <button id="privacy-notifications-more" class="secondary-button hidden" type="button">Load older notifications</button>
      </div>
    </section>

    <p id="privacy-notifications-error" class="error-text" role="alert"></p>
    <?= \ChitChat\View\PoweredBy::html($config) ?>
  </main>

  <script type="module" src="/assets/js/push-notifications.js"></script>
  <script type="module" src="/assets/js/privacy-notifications.js"></script>
</body>
</html>
