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
  <meta name="description" content="<?= $appName ?> system status">
  <title>System status · <?= $appName ?></title>
  <link rel="stylesheet" href="/assets/css/app.css">
  <link rel="stylesheet" href="/assets/css/components.css">
  <link rel="stylesheet" href="/assets/css/admin.css">
  <link rel="stylesheet" href="/assets/css/admin-status.css">
</head>
<body>
  <div id="status-loading" class="app-loading" role="status">Loading system status…</div>

  <main id="status-shell" class="admin-shell hidden">
    <header class="admin-header">
      <div>
        <p class="admin-eyebrow"><?= $appName ?></p>
        <h1>System status</h1>
        <p id="status-identity" class="admin-muted"></p>
      </div>
      <div class="action-row">
        <button id="status-refresh" class="secondary-button" type="button">Refresh</button>
        <a class="secondary-button admin-link-button" href="/admin.php">Back to administration</a>
      </div>
    </header>

    <p id="status-error" class="error-text" role="alert"></p>
    <p id="status-generated" class="admin-muted"></p>

    <div class="status-grid">
      <section id="maintenance-card" class="admin-card status-card">
        <div class="status-card-heading">
          <h2>Maintenance</h2>
          <span id="maintenance-state" class="status-badge">Unknown</span>
        </div>
        <dl>
          <div><dt>Latest invocation</dt><dd id="maintenance-latest">Never</dd></div>
          <div><dt>Latest successful cleanup</dt><dd id="maintenance-success">Never</dd></div>
          <div><dt>Maximum age</dt><dd id="maintenance-max-age">—</dd></div>
          <div><dt>Latest result</dt><dd id="maintenance-result">—</dd></div>
        </dl>
      </section>

      <section class="admin-card status-card">
        <h2>PostgreSQL</h2>
        <dl>
          <div><dt>Database</dt><dd id="database-name">—</dd></div>
          <div><dt>Size</dt><dd id="database-size">—</dd></div>
          <div><dt>Status query latency</dt><dd id="database-latency">—</dd></div>
        </dl>
      </section>

      <section class="admin-card status-card">
        <h2>Realtime</h2>
        <dl>
          <div><dt>SSE connections</dt><dd id="sse-connections">0</dd></div>
          <div><dt>SSE users</dt><dd id="sse-users">0</dd></div>
          <div><dt>Presence leases</dt><dd id="presence-leases">0</dd></div>
          <div><dt>Presence users</dt><dd id="presence-users">0</dd></div>
          <div><dt>Retained events</dt><dd id="retained-events">0</dd></div>
        </dl>
      </section>

      <section class="admin-card status-card">
        <h2>Attachments</h2>
        <dl>
          <div><dt>Active files</dt><dd id="attachment-active">0</dd></div>
          <div><dt>Deleted, retained files</dt><dd id="attachment-deleted">0</dd></div>
          <div><dt>Tracked bytes</dt><dd id="attachment-bytes">0 B</dd></div>
          <div><dt>Filesystem free</dt><dd id="attachment-free">—</dd></div>
          <div><dt>Filesystem used</dt><dd id="attachment-used">—</dd></div>
        </dl>
      </section>

      <section class="admin-card status-card">
        <h2>Security ledgers</h2>
        <dl>
          <div><dt>Failed logins, 24 hours</dt><dd id="failed-logins">0</dd></div>
          <div><dt>Current rate-limit rows</dt><dd id="rate-limit-rows">0</dd></div>
          <div><dt>Prometheus endpoint</dt><dd id="metrics-enabled">Disabled</dd></div>
        </dl>
      </section>

      <section class="admin-card status-card">
        <h2>Application</h2>
        <dl>
          <div><dt>Name</dt><dd id="application-name">—</dd></div>
          <div><dt>Version</dt><dd id="application-version">—</dd></div>
          <div><dt>Environment</dt><dd id="application-environment">—</dd></div>
        </dl>
      </section>

      <section class="admin-card status-card status-wide">
        <h2>Rate-limit policies</h2>
        <p class="admin-muted">Effective deployment policy and aggregate decisions. No account or IP identifiers are retained here.</p>
        <div class="status-table-wrap">
          <table class="status-table" aria-label="Effective rate-limit policies">
            <thead>
              <tr>
                <th scope="col">Policy</th>
                <th scope="col">Limit</th>
                <th scope="col">Allowed</th>
                <th scope="col">Rejected</th>
                <th scope="col">Last rejection</th>
              </tr>
            </thead>
            <tbody id="rate-limit-policies"></tbody>
          </table>
        </div>
      </section>
    </div>
  </main>

  <script type="module" src="/assets/js/admin-status.js"></script>
</body>
</html>
