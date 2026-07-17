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
  <meta name="description" content="<?= $appName ?> administrative message revision review">
  <title>Revision review · <?= $appName ?></title>
  <link rel="stylesheet" href="/assets/css/app.css">
  <link rel="stylesheet" href="/assets/css/components.css">
  <link rel="stylesheet" href="/assets/css/admin.css">
  <link rel="stylesheet" href="/assets/css/message-revision-review.css">
</head>
<body>
  <div id="revision-review-loading" class="app-loading" role="status">Loading revision-review controls…</div>

  <main id="revision-review-shell" class="revision-review-shell hidden">
    <header class="admin-header">
      <div>
        <p class="admin-eyebrow"><?= $appName ?></p>
        <h1>Message revision review</h1>
        <p id="revision-review-identity" class="admin-muted"></p>
      </div>
      <a class="secondary-button admin-link-button" href="/admin.php">Back to administration</a>
    </header>

    <section class="revision-review-warning" aria-labelledby="revision-review-warning-heading">
      <h2 id="revision-review-warning-heading">Restricted historical-content access</h2>
      <p>This workflow is separate from direct-message inspection. Each successful request records your identity, IP address, exact message kind and ID, stated reason, and returned revision IDs. Historical bodies remain in the revision ledger and are not copied into audit metadata.</p>
      <p>ChitChat does not notify message participants when a review occurs. Operators are responsible for disclosing this capability in their privacy and moderation policy.</p>
    </section>

    <p id="revision-review-error" class="error-text" role="alert"></p>

    <form id="revision-review-form" class="revision-review-form">
      <label>
        Message kind
        <select id="revision-review-kind" required>
          <option value="room">Room message</option>
          <option value="direct">Direct message</option>
        </select>
      </label>
      <label>
        Message ID
        <input id="revision-review-message-id" type="number" min="1" step="1" inputmode="numeric" required>
      </label>
      <label class="full-width">
        Review reason
        <textarea id="revision-review-reason" minlength="10" maxlength="500" rows="4" placeholder="State the incident, complaint, legal, safety, or moderation reason for reviewing historical content." required></textarea>
      </label>
      <button id="revision-review-submit" class="danger-button full-width" type="submit">Review revisions and write audit record</button>
    </form>

    <section id="revision-review-results" class="revision-review-results hidden" aria-labelledby="revision-review-results-heading">
      <header class="revision-review-results-header">
        <div>
          <h2 id="revision-review-results-heading">Retained revision history</h2>
          <p id="revision-review-summary" class="admin-muted"></p>
        </div>
      </header>
      <dl id="revision-review-context" class="revision-review-context"></dl>
      <div id="revision-review-list" class="revision-review-list" aria-live="polite"></div>
    </section>
  </main>

  <div id="toast-region" class="toast-region" aria-live="assertive"></div>
  <script type="module" src="/assets/js/admin-message-revisions.js"></script>
</body>
</html>
