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
  <meta name="description" content="<?= $appName ?> direct messages">
  <title>Messages · <?= $appName ?></title>
  <link rel="stylesheet" href="/assets/css/tokens.css">
  <link rel="stylesheet" href="/assets/css/app.css">
<?= \ChitChat\View\HeadAssets::html(\ChitChat\InstallationIcon\IconService::resolve($config)) ?>
  <link rel="stylesheet" href="/assets/css/components.css">
  <link rel="stylesheet" href="/assets/css/transcript.css">
  <link rel="stylesheet" href="/assets/css/accessibility.css">
  <link rel="stylesheet" href="/assets/css/person-reports.css">
  <link rel="stylesheet" href="/assets/css/messages.css">
  <link rel="stylesheet" href="/assets/css/messages-blocking.css">
  <link rel="stylesheet" href="/assets/css/messages-attachments.css">
  <link rel="stylesheet" href="/assets/css/message-mutations.css">
  <link rel="stylesheet" href="/assets/css/rules.css">
  <link rel="stylesheet" href="/assets/css/shell.css">
  <link rel="stylesheet" href="/assets/css/panes.css">
</head>
<body>
  <!-- One polite announcer per page, fed by events (announcer.js); the transcript itself never speaks. -->
  <div id="announcer" class="visually-hidden" role="status" aria-live="polite" aria-atomic="true"></div>
  <div id="messages-loading" class="app-loading" role="status">Loading messages…</div>

  <?= \ChitChat\View\AppShell::open('direct', 'dm-conversation', 'Skip to conversation', config: $config) ?>
  <!-- The Direct list pane and the conversation (S2c): side by side on
       desktop, the list over the conversation on tablets, a stack on phones
       (panes.js). -->
  <div id="messages-shell" class="messages-shell dm-shell hidden" data-panes data-list="dm-list-pane" data-toggle="dm-list-toggle" data-close="dm-list-close" data-backdrop="dm-backdrop" data-title="dm-peer-name" data-list-name="Conversations" data-deep-link="with,user_id">
    <p class="rules-banner hidden" data-rules-gate role="status"><span><strong>The community rules have changed.</strong> Agree to keep writing.</span> <button class="secondary-button" type="button" data-rules-review>Review</button></p>
    <section id="dm-list-pane" class="list-pane dm-list-pane" aria-labelledby="dm-list-title">
      <header class="list-pane-header">
        <div class="list-pane-title">
          <?= \ChitChat\View\AppShell::brand($config, 'list-pane-brand') ?>
          <h1 id="dm-list-title" class="list-title">Direct</h1>
          <p id="messages-identity" class="list-pane-status"></p>
        </div>
        <div class="list-pane-actions">
          <a class="header-button list-search" href="/search.php?scope=direct" aria-label="Search messages" title="Search messages"><svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg></a>
          <button id="dm-list-close" class="header-button close-button drawer-close" type="button" aria-label="Close conversations">×</button>
        </div>
      </header>
      <form id="dm-user-search-form" class="form-stack compact-search dm-start">
        <label for="dm-user-search">Start a conversation</label>
        <div class="dm-start-row">
          <input id="dm-user-search" type="search" minlength="2" maxlength="32" placeholder="Username prefix" required>
          <button class="secondary-button" type="submit">Search</button>
        </div>
      </form>
      <div id="dm-user-results" class="dm-user-results"></div>
      <h2 id="dm-conversations-heading" class="list-section-heading">Conversations</h2>
      <nav id="dm-conversation-list" class="conversation-list" aria-labelledby="dm-conversations-heading"></nav>
      <?= \ChitChat\View\PoweredBy::html($config) ?>
    </section>
    <div id="dm-backdrop" class="drawer-backdrop" aria-hidden="true"></div>
    <main id="dm-conversation" class="conversation-main" aria-labelledby="dm-peer-name" tabindex="-1">
      <header class="conversation-header">
        <!-- Tablets: opens the conversations over this one. Phones: back to the list. -->
        <button id="dm-list-toggle" class="header-button drawer-toggle" type="button" aria-label="Conversations" aria-expanded="false" aria-controls="dm-list-pane">
          <svg class="toggle-list" aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
          <svg class="toggle-back" aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
        </button>
        <span id="dm-peer-pebble" class="pebble" aria-hidden="true" hidden></span>
        <div class="dm-heading">
          <h2 id="dm-peer-name" class="conversation-title" tabindex="-1">Choose a conversation</h2>
          <p id="dm-peer-status" class="messages-muted">Search for a user or choose an existing conversation.</p>
        </div>
        <div class="conversation-actions">
          <button id="dm-report-person" class="secondary-button hidden" type="button">Report</button>
          <button id="dm-block-toggle" class="secondary-button dm-block-toggle hidden" type="button">Block user</button>
        </div>
      </header>
      <!-- One line, with the detail a click away (#163 voice). -->
      <details id="dm-privacy-notice" class="privacy-line">
        <summary><span id="dm-privacy-heading">Direct messages aren’t end-to-end encrypted.</span> <span class="privacy-more">Learn more</span></summary>
        <p id="dm-privacy-text"></p>
        <p>Edits and deletions preserve historical bodies until direct-message retention removes the message. Administrative revision review is separately configurable, requires a stated reason, and is audited when enabled.</p>
      </details>
      <p id="messages-error" class="error-text" role="alert"></p>
        <div id="dm-empty-state" class="dm-empty-state">No conversation selected.</div>
        <button id="dm-load-older" class="secondary-button hidden" type="button">Load older messages</button>
        <ol id="dm-message-list" class="dm-message-list" aria-label="Direct messages">
        </ol>

        <div class="muted-notice rules-notice hidden" data-rules-gate role="status">
          <div><strong>Agree to the updated community rules to write.</strong><span>You can still read along.</span></div>
          <button class="primary-button" type="button" data-rules-review>Review rules</button>
        </div>
        <form id="dm-composer" class="dm-composer hidden">
          <p id="dm-typing-indicator" class="typing-indicator"></p>
          <div id="dm-reply-banner" class="reply-banner hidden">
            <span id="dm-reply-banner-text"></span>
            <button id="dm-reply-banner-cancel" class="reply-banner-cancel" type="button" aria-label="Cancel reply">Cancel</button>
          </div>
          <div class="composer-field">
            <label class="visually-hidden" for="dm-message-input">Direct message or attachment caption</label>
            <textarea id="dm-message-input" maxlength="4000" rows="3" placeholder="Write a direct message…"></textarea>
            <button id="dm-emoji-button" class="emoji-button" type="button" aria-label="Insert emoji" title="Insert emoji">
              <svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>
            </button>
            <label class="attachment-button" for="dm-attachment-input" title="Attach file">
              <svg class="attachment-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
              <span class="visually-hidden">Attach file</span>
            </label>
            <input
              id="dm-attachment-input"
              class="visually-hidden"
              name="file"
              type="file"
              accept="image/jpeg,image/png,image/gif,image/webp,application/pdf,text/plain,text/csv,application/json,application/zip"
            >
          </div>
          <button id="dm-send" class="primary-button send-round" type="submit"><svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h13M13 6l6 6-6 6"/></svg><span class="visually-hidden">Send</span></button>
          <div class="attachment-selection">
            <span id="dm-attachment-name" class="attachment-name" aria-live="polite"></span>
            <button id="dm-attachment-clear" class="secondary-button hidden" type="button">Remove</button>
          </div>
        </form>
    </main>
  </div>
  <?= \ChitChat\View\AppShell::close() ?>

  <?= \ChitChat\View\ReportDialog::html() ?>

  <div id="toast-region" class="toast-region" aria-live="assertive"></div>
  <script type="module" src="/assets/js/messages.js"></script>
  <script type="module" src="/assets/js/search-result-navigation.js"></script>
  <script type="module" src="/assets/js/dm-attachments.js"></script>
  <script type="module" src="/assets/js/dm-message-mutations.js"></script>
  <script type="module" src="/assets/js/panes.js"></script>
  <script type="module" src="/assets/js/shell.js"></script>
</body>
</html>
