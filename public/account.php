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
  <meta name="description" content="<?= $appName ?> account and personal data">
  <title>Account · <?= $appName ?></title>
  <link rel="stylesheet" href="/assets/css/app.css">
  <link rel="stylesheet" href="/assets/css/components.css">
  <link rel="stylesheet" href="/assets/css/accessibility.css">
  <link rel="stylesheet" href="/assets/css/account.css">
</head>
<body>
  <div id="account-loading" class="app-loading" role="status">Loading account…</div>

  <main id="account-shell" class="account-shell hidden">
    <header class="account-header">
      <div>
        <p class="account-eyebrow"><?= $appName ?></p>
        <h1>Your account</h1>
        <p id="account-identity" class="account-muted"></p>
      </div>
      <a class="secondary-button" href="/">Back to chat</a>
    </header>

    <section class="account-card" aria-labelledby="personal-data-heading">
      <div>
        <p class="account-eyebrow">Privacy and portability</p>
        <h2 id="personal-data-heading">Download your personal data</h2>
      </div>

      <p>
        Create a machine-readable JSON snapshot of the account and retained data currently associated with you.
        Preparing the export requires recent privileged authentication and is recorded in the audit log.
      </p>

      <details class="account-details">
        <summary>What the export contains</summary>
        <p>
          It includes your profile, role grants, ban history, rooms and memberships, messages you authored,
          direct messages visible to you, attachment metadata, blocks you created, login history, and actions
          recorded with you as the actor.
        </p>
        <p>
          It does not contain password hashes, session secrets, attachment file bytes or internal storage keys.
          It also does not reveal who blocked you or hidden revision history for messages authored by somebody else.
        </p>
      </details>

      <div class="account-action-row">
        <button id="personal-data-export" class="primary-button" type="button">Download JSON export</button>
        <p id="personal-data-status" class="account-muted" role="status" aria-live="polite"></p>
      </div>
    </section>

    <section class="account-card" aria-labelledby="account-closure-heading">
      <div>
        <p class="account-eyebrow">Account lifecycle</p>
        <h2 id="account-closure-heading">Close your account</h2>
      </div>
      <p>
        Closure disables sign-in and invalidates every active session immediately. A 14-day cooling-off period then
        allows explicit restoration with your current username and password. After that deadline, maintenance
        permanently tombstones your username, password and birth date.
      </p>
      <details class="account-details">
        <summary>What remains after closure</summary>
        <p>
          Shared room and direct-message history, message revisions, attachment evidence, room ownership and audit
          records remain subject to the installation's retention policy. They are attributed to a generic closed-account
          identity so shared conversations and security evidence are not silently rewritten.
        </p>
        <p>
          Your original username remains reserved during cooling-off and becomes reusable only after finalization.
        </p>
      </details>
      <label>
        <input id="account-closure-confirm" type="checkbox">
        I understand that I will be signed out immediately and must restore the account before the deadline.
      </label>
      <div class="account-action-row">
        <button id="account-closure-request" class="danger-button" type="button" disabled>Request account closure</button>
        <p id="account-closure-status" class="account-muted" role="status" aria-live="polite"></p>
      </div>
    </section>

    <p id="account-error" class="error-text" role="alert"></p>
  </main>

  <script type="module" src="/assets/js/account.js"></script>
  <script type="module" src="/assets/js/mfa-account.js"></script>
</body>
</html>
