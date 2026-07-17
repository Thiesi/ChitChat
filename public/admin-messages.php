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
  <meta name="description" content="<?= $appName ?> direct-message inspection">
  <title>DM inspection · <?= $appName ?></title>
  <link rel="stylesheet" href="/assets/css/app.css">
  <link rel="stylesheet" href="/assets/css/components.css">
  <link rel="stylesheet" href="/assets/css/messages.css">
</head>
<body>
  <div id="inspection-loading" class="app-loading" role="status">Loading inspection controls…</div>

  <main id="inspection-shell" class="inspection-shell hidden">
    <header class="inspection-header">
      <div>
        <p class="messages-eyebrow"><?= $appName ?></p>
        <h1>Direct-message inspection</h1>
        <p id="inspection-identity" class="messages-muted"></p>
      </div>
      <a class="secondary-button" href="/admin.php">Back to administration</a>
    </header>

    <section class="inspection-warning" aria-labelledby="inspection-warning-heading">
      <h2 id="inspection-warning-heading">Sensitive administrative access</h2>
      <p>Every inspection, including each older-history page, records your identity, IP address, stated reason, selected users, cursor and returned message range. Message bodies are not copied into the audit log.</p>
    </section>

    <p id="inspection-error" class="error-text" role="alert"></p>

    <form id="inspection-form" class="inspection-form">
      <section>
        <label>
          User A
          <input id="inspection-user-a-search" type="search" minlength="2" maxlength="32" placeholder="Username prefix" required>
        </label>
        <button id="inspection-user-a-search-button" class="secondary-button" type="button">Search</button>
        <div id="inspection-user-a-results" class="inspection-user-results"></div>
        <div id="inspection-user-a-selected" class="selected-user">No user selected.</div>
      </section>

      <section>
        <label>
          User B
          <input id="inspection-user-b-search" type="search" minlength="2" maxlength="32" placeholder="Username prefix" required>
        </label>
        <button id="inspection-user-b-search-button" class="secondary-button" type="button">Search</button>
        <div id="inspection-user-b-results" class="inspection-user-results"></div>
        <div id="inspection-user-b-selected" class="selected-user">No user selected.</div>
      </section>

      <label class="full-width">
        Inspection reason
        <textarea id="inspection-reason" minlength="3" maxlength="500" rows="3" placeholder="State the operational or safety reason for accessing this conversation." required></textarea>
      </label>
      <button id="inspection-submit" class="danger-button full-width" type="submit">Inspect and write audit record</button>
    </form>

    <section id="inspection-results" class="inspection-results hidden">
      <header class="inspection-results-header">
        <h2 id="inspection-conversation-title">Inspected conversation</h2>
        <p id="inspection-conversation-meta" class="messages-muted"></p>
      </header>
      <section id="inspection-message-list" class="inspection-message-list" aria-live="polite">
        <button id="inspection-load-older" class="secondary-button hidden" type="button">Inspect older messages</button>
      </section>
    </section>
  </main>

  <div id="toast-region" class="toast-region" aria-live="assertive"></div>
  <script type="module" src="/assets/js/admin-messages.js"></script>
</body>
</html>
