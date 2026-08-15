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
  <meta name="description" content="<?= $appName ?> moderation queue">
  <title>Moderation queue · <?= $appName ?></title>
  <link rel="stylesheet" href="/assets/css/app.css">
  <link rel="stylesheet" href="/assets/css/components.css">
  <link rel="stylesheet" href="/assets/css/accessibility.css">
  <link rel="stylesheet" href="/assets/css/moderation.css">
</head>
<body>
  <div id="moderation-loading" class="app-loading" role="status">Loading moderation queue…</div>

  <main id="moderation-shell" class="moderation-shell hidden">
    <header class="moderation-header">
      <div>
        <p class="moderation-eyebrow"><?= $appName ?></p>
        <h1>Moderation queue</h1>
        <p id="moderation-identity" class="moderation-muted"></p>
      </div>
      <a class="secondary-button" href="/admin.php">Back to administration</a>
    </header>

    <section class="moderation-notice" aria-labelledby="moderation-notice-title">
      <h2 id="moderation-notice-title">Participant-submitted evidence</h2>
      <p>Each report contains an immutable snapshot of one message as the reporter saw it. A direct-message report does not grant access to surrounding conversation history.</p>
      <p>Room owners and room moderators see only cases from rooms they moderate. Global moderation roles may review all room and direct-message cases.</p>
    </section>

    <p id="moderation-error" class="error-text" role="alert"></p>

    <section class="moderation-workspace">
      <aside class="moderation-queue" aria-labelledby="moderation-queue-title">
        <div class="moderation-queue-heading">
          <div>
            <h2 id="moderation-queue-title">Cases</h2>
            <p id="moderation-status" class="moderation-muted" role="status" aria-live="polite"></p>
          </div>
          <label>Status
            <select id="moderation-filter">
              <option value="open">Open</option>
              <option value="in_review">In review</option>
              <option value="resolved">Resolved</option>
              <option value="dismissed">Dismissed</option>
              <option value="all">All</option>
            </select>
          </label>
        </div>
        <div id="moderation-case-list" class="moderation-case-list"></div>
        <button id="moderation-more" class="secondary-button hidden" type="button">Load older cases</button>
      </aside>

      <section id="moderation-detail" class="moderation-detail" aria-labelledby="moderation-detail-title">
        <div id="moderation-empty" class="moderation-empty">Choose a case to inspect its submitted evidence.</div>
        <div id="moderation-case" class="hidden">
          <header class="moderation-detail-header">
            <div>
              <p id="moderation-case-context" class="moderation-eyebrow"></p>
              <h2 id="moderation-detail-title">Case</h2>
              <p id="moderation-case-meta" class="moderation-muted"></p>
            </div>
            <div class="action-row">
              <button id="moderation-claim" class="primary-button" type="button">Claim case</button>
              <button id="moderation-release" class="secondary-button hidden" type="button">Release case</button>
            </div>
          </header>

          <section aria-labelledby="moderation-reports-title">
            <h3 id="moderation-reports-title">Reports and evidence</h3>
            <div id="moderation-report-list" class="moderation-report-list"></div>
          </section>

          <form id="moderation-resolution-form" class="moderation-resolution form-stack">
            <h3>Close case</h3>
            <label>Outcome
              <select id="moderation-resolution-code">
                <option value="content_removed">Reported content removed</option>
                <option value="user_warned">User warned</option>
                <option value="account_restricted">Account restricted</option>
                <option value="other">Other action</option>
              </select>
            </label>
            <label>Resolution note <span class="optional-label">required for “Other action”</span>
              <textarea id="moderation-resolution-note" maxlength="1000" rows="4"></textarea>
            </label>
            <div class="action-row">
              <button id="moderation-dismiss" class="secondary-button" type="button">Dismiss: no violation</button>
              <button id="moderation-resolve" class="primary-button" type="submit">Resolve case</button>
            </div>
          </form>

          <section id="moderation-closed" class="moderation-closed hidden" aria-labelledby="moderation-closed-title">
            <h3 id="moderation-closed-title">Case closed</h3>
            <p id="moderation-closed-summary"></p>
          </section>
        </div>
      </section>
    </section>
  </main>

  <div id="toast-region" class="toast-region" aria-live="assertive"></div>
  <script type="module" src="/assets/js/moderation.js"></script>
</body>
</html>
