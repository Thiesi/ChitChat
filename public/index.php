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
  <meta name="description" content="<?= $appName ?> self-hosted browser chat">
  <title><?= $appName ?></title>
  <link rel="stylesheet" href="/assets/css/app.css">
  <link rel="stylesheet" href="/assets/css/components.css">
</head>
<body>
  <div id="app-loading" class="app-loading" role="status">Loading <?= $appName ?>…</div>

  <main id="auth-shell" class="auth-shell hidden">
    <section class="auth-card" aria-labelledby="auth-title">
      <h1 id="auth-title" class="brand"><?= $appName ?></h1>
      <p class="tagline">A small, self-hosted place to talk.</p>

      <div class="auth-tabs" role="tablist" aria-label="Account access">
        <button id="login-tab" type="button" role="tab" aria-selected="true">Sign in</button>
        <button id="register-tab" type="button" role="tab" aria-selected="false">Register</button>
      </div>

      <form id="login-form" class="form-stack" autocomplete="on">
        <label>
          Username
          <input id="login-username" name="username" type="text" autocomplete="username" minlength="3" maxlength="32" required>
        </label>
        <label>
          Password
          <input id="login-password" name="password" type="password" autocomplete="current-password" minlength="12" maxlength="4096" required>
        </label>
        <button class="primary-button" type="submit">Sign in</button>
      </form>

      <form id="register-form" class="form-stack hidden" autocomplete="on">
        <label>
          Username
          <input id="register-username" name="username" type="text" autocomplete="username" minlength="3" maxlength="32" pattern="[A-Za-z0-9][A-Za-z0-9._-]{2,31}" required>
        </label>
        <label>
          Password
          <input id="register-password" name="password" type="password" autocomplete="new-password" minlength="12" maxlength="4096" required>
        </label>
        <label>
          Birth date <span class="optional-label">optional; required for age-restricted rooms</span>
          <input id="register-birth-date" name="birth_date" type="date" autocomplete="bday">
        </label>
        <button class="primary-button" type="submit">Create account</button>
      </form>

      <p id="auth-error" class="error-text" role="alert"></p>
    </section>
  </main>

  <main id="chat-shell" class="chat-shell hidden">
    <aside class="sidebar">
      <header class="sidebar-header">
        <h1><?= $appName ?></h1>
        <span id="connection-status" class="connection-status" data-state="disconnected">Offline</span>
      </header>

      <div class="rooms-heading-row">
        <h2 class="rooms-heading">Rooms</h2>
        <button id="new-room-button" class="icon-button hidden" type="button" aria-label="Create room" title="Create room">+</button>
      </div>
      <nav id="room-list" class="room-list" aria-label="Chat rooms"></nav>

      <section id="presence-panel" class="presence-panel hidden" aria-labelledby="presence-heading">
        <h2 id="presence-heading" class="rooms-heading">Online here</h2>
        <ul id="presence-list" class="presence-list"></ul>
      </section>

      <footer class="sidebar-footer">
        <div class="current-user"><span class="current-user-label">Signed in as </span><strong id="current-user"></strong></div>
        <a id="admin-link" class="secondary-button hidden" href="/admin.php">Administration</a>
        <button id="logout-button" class="secondary-button" type="button">Sign out</button>
      </footer>
    </aside>

    <section class="chat-main" aria-labelledby="room-title">
      <header class="room-header">
        <div>
          <h2 id="room-title">Choose a room</h2>
          <p id="room-info">Select a room from the sidebar.</p>
        </div>
        <button id="join-button" class="join-button hidden" type="button">Join room</button>
      </header>

      <div id="empty-state" class="empty-state">Choose a room to begin.</div>
      <section id="message-list" class="message-list" aria-live="polite" aria-label="Messages">
        <button id="load-older-button" class="secondary-button hidden" type="button">Load older messages</button>
      </section>

      <div id="composer-wrap" class="composer-wrap hidden">
        <form id="composer-form" class="composer">
          <label class="visually-hidden" for="composer-input">Message</label>
          <textarea id="composer-input" name="message" maxlength="4000" rows="2" placeholder="Write a message…" required></textarea>
          <button id="send-button" class="primary-button" type="submit">Send</button>
        </form>
        <p class="composer-help">Enter sends · Shift+Enter adds a line · Commands: <code>/me</code>, <code>/ping username</code></p>
      </div>
    </section>
  </main>

  <dialog id="room-dialog" class="room-dialog">
    <form id="room-create-form" class="form-stack" method="dialog">
      <header class="dialog-header">
        <h2>Create a room</h2>
        <button id="room-dialog-cancel" class="icon-button" type="button" aria-label="Close">×</button>
      </header>
      <label>
        Room key
        <input id="room-key" name="key" type="text" minlength="3" maxlength="48" pattern="[a-z0-9][a-z0-9_-]{2,47}" placeholder="general" required>
      </label>
      <label>
        Name
        <input id="room-name" name="name" type="text" maxlength="120" placeholder="General" required>
      </label>
      <label>
        Description
        <input id="room-info-line" name="info_line" type="text" maxlength="255" placeholder="General discussion">
      </label>
      <label>
        Visibility
        <select id="room-visibility" name="visibility">
          <option value="public">Public</option>
          <option value="unlisted">Unlisted</option>
          <option value="private">Private, invitation only</option>
        </select>
      </label>
      <label>
        Minimum age
        <input id="room-minimum-age" name="minimum_age" type="number" min="0" max="120" value="0" required>
      </label>
      <label>
        Inactivity timeout in seconds <span class="optional-label">0 disables; minimum 120</span>
        <input id="room-inactivity-timeout" name="inactivity_timeout_seconds" type="number" min="0" max="86400" step="60" value="0" required>
      </label>
      <p id="room-dialog-error" class="error-text" role="alert"></p>
      <button class="primary-button" type="submit">Create room</button>
    </form>
  </dialog>

  <div id="toast-region" class="toast-region" aria-live="assertive"></div>

  <script type="module" src="/assets/js/app.js"></script>
  <script type="module" src="/assets/js/admin-link.js"></script>
</body>
</html>
