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
  <meta name="description" content="<?= $appName ?> direct messages">
  <title>Messages · <?= $appName ?></title>
  <link rel="stylesheet" href="/assets/css/app.css">
  <link rel="stylesheet" href="/assets/css/components.css">
  <link rel="stylesheet" href="/assets/css/messages.css">
  <link rel="stylesheet" href="/assets/css/messages-blocking.css">
  <link rel="stylesheet" href="/assets/css/messages-attachments.css">
  <link rel="stylesheet" href="/assets/css/message-mutations.css">
</head>
<body>
  <div id="messages-loading" class="app-loading" role="status">Loading messages…</div>

  <main id="messages-shell" class="messages-shell hidden">
    <header class="messages-header">
      <div>
        <p class="messages-eyebrow"><?= $appName ?></p>
        <h1>Direct messages</h1>
        <p id="messages-identity" class="messages-muted"></p>
      </div>
      <a class="secondary-button" href="/">Back to chat</a>
    </header>

    <section id="dm-privacy-notice" class="privacy-notice" aria-labelledby="dm-privacy-heading">
      <h2 id="dm-privacy-heading">Privacy notice</h2>
      <p id="dm-privacy-text"></p>
      <p>Edits and deletions preserve historical bodies until direct-message retention removes the message. Administrative revision review is separately configurable, requires a stated reason, and is audited when enabled.</p>
    </section>

    <p id="messages-error" class="error-text" role="alert"></p>

    <section class="messages-layout">
      <aside class="conversation-sidebar">
        <form id="dm-user-search-form" class="form-stack compact-search">
          <label>
            Start a conversation
            <input id="dm-user-search" type="search" minlength="2" maxlength="32" placeholder="Username prefix" required>
          </label>
          <button class="secondary-button" type="submit">Search</button>
        </form>
        <div id="dm-user-results" class="dm-user-results"></div>

        <h2>Conversations</h2>
        <nav id="dm-conversation-list" class="conversation-list" aria-label="Direct-message conversations"></nav>
      </aside>

      <section class="conversation-main" aria-labelledby="dm-peer-name">
        <header class="conversation-header">
          <div>
            <h2 id="dm-peer-name">Choose a conversation</h2>
            <p id="dm-peer-status" class="messages-muted">Search for a user or choose an existing conversation.</p>
          </div>
          <button id="dm-block-toggle" class="secondary-button dm-block-toggle hidden" type="button">Block user</button>
        </header>

        <div id="dm-empty-state" class="dm-empty-state">No conversation selected.</div>
        <section id="dm-message-list" class="dm-message-list" aria-live="polite" aria-label="Direct messages">
          <button id="dm-load-older" class="secondary-button hidden" type="button">Load older messages</button>
        </section>

        <form id="dm-composer" class="dm-composer hidden">
          <div class="attachment-picker dm-attachment-picker">
            <label class="secondary-button attachment-button" for="dm-attachment-input">Attach file</label>
            <input
              id="dm-attachment-input"
              class="visually-hidden"
              name="file"
              type="file"
              accept="image/jpeg,image/png,image/gif,image/webp,application/pdf,text/plain,text/csv,application/json,application/zip"
            >
            <span id="dm-attachment-name" class="attachment-name" aria-live="polite"></span>
            <button id="dm-attachment-clear" class="secondary-button hidden" type="button">Remove</button>
          </div>
          <label class="visually-hidden" for="dm-message-input">Direct message or attachment caption</label>
          <textarea id="dm-message-input" maxlength="4000" rows="3" placeholder="Write a direct message…"></textarea>
          <button id="dm-send" class="primary-button" type="submit">Send</button>
        </form>
      </section>
    </section>
  </main>

  <div id="toast-region" class="toast-region" aria-live="assertive"></div>
  <script type="module" src="/assets/js/messages.js"></script>
  <script type="module" src="/assets/js/dm-attachments.js"></script>
  <script type="module" src="/assets/js/dm-message-mutations.js"></script>
</body>
</html>
