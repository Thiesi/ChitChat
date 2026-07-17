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
  <meta name="description" content="<?= $appName ?> operational settings">
  <title>Operational settings · <?= $appName ?></title>
  <link rel="stylesheet" href="/assets/css/app.css">
  <link rel="stylesheet" href="/assets/css/components.css">
  <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body>
  <div id="settings-loading" class="app-loading" role="status">Loading operational settings…</div>

  <main id="settings-shell" class="admin-shell hidden">
    <header class="admin-header">
      <div>
        <p class="admin-eyebrow"><?= $appName ?></p>
        <h1>Operational settings</h1>
        <p id="settings-identity" class="admin-muted"></p>
      </div>
      <a class="secondary-button admin-link-button" href="/admin.php">Back to administration</a>
    </header>

    <p class="admin-card warning-text">
      These policies control destructive maintenance. A retention value of <strong>0</strong> means permanent retention.
      Changes are audited, but cleanup occurs only when <code>php bin/maintenance-cleanup</code> runs.
    </p>
    <p id="settings-error" class="error-text" role="alert"></p>

    <form id="settings-form" class="room-admin-grid">
      <section class="admin-card form-stack">
        <h2>Access</h2>
        <label>
          <span>Public registration</span>
          <select id="registration-enabled">
            <option value="1">Enabled</option>
            <option value="0">Disabled</option>
          </select>
        </label>
      </section>

      <section class="admin-card form-stack">
        <h2>Content retention</h2>
        <label>Room messages in days <span class="optional-label">0 keeps permanently</span>
          <input id="room-retention" type="number" min="0" max="3650" required>
        </label>
        <label>Direct messages in days <span class="optional-label">0 keeps permanently</span>
          <input id="dm-retention" type="number" min="0" max="3650" required>
        </label>
        <label>Audit entries in days <span class="optional-label">0 keeps permanently</span>
          <input id="audit-retention" type="number" min="0" max="3650" required>
        </label>
      </section>

      <section class="admin-card form-stack">
        <h2>Attachment cleanup</h2>
        <label>Deleted attachment files in days <span class="optional-label">0 keeps permanently</span>
          <input id="deleted-attachment-retention" type="number" min="0" max="3650" required>
        </label>
        <label>Orphan grace period in hours
          <input id="orphan-grace" type="number" min="1" max="720" required>
        </label>
      </section>

      <section class="admin-card form-stack">
        <h2>Operational ledgers</h2>
        <label>Realtime event retention in hours
          <input id="event-retention" type="number" min="1" max="8760" required>
        </label>
        <label>Login attempt retention in days
          <input id="login-retention" type="number" min="1" max="3650" required>
        </label>
      </section>

      <section class="admin-card form-stack">
        <h2>Apply policy</h2>
        <p id="settings-updated" class="admin-muted"></p>
        <button id="save-settings" class="danger-button" type="submit">Save operational settings</button>
      </section>
    </form>
  </main>

  <div id="toast-region" class="toast-region" aria-live="assertive"></div>
  <script type="module" src="/assets/js/admin-settings.js"></script>
</body>
</html>
