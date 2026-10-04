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
  <script type="module" src="/assets/js/theme-toggle.js"></script>
  <meta name="description" content="<?= $appName ?> self-hosted browser chat">
  <title><?= $appName ?></title>
  <link rel="stylesheet" href="/assets/css/app.css">
  <link rel="stylesheet" href="/assets/css/components.css">
  <link rel="stylesheet" href="/assets/css/accessibility.css">
  <link rel="stylesheet" href="/assets/css/message-mutations.css">
  <link rel="stylesheet" href="/assets/css/privacy-notifications.css">
</head>
<body>
  <div id="app-loading" class="app-loading" role="status">Loading <?= $appName ?>…</div>

  <main id="auth-shell" class="auth-shell hidden">
    <div class="auth-layout">
      <div class="auth-intro">
        <p class="welcome-eyebrow">Make yourself at home</p>
        <p class="welcome-title">Good company.<br>Great conversations.</p>
        <p class="welcome-copy">A little hello can go a long way. Settle in, find your people, and let the conversation flow.</p>
        <svg class="welcome-art" viewBox="0 0 400 220" fill="none" aria-hidden="true" focusable="false">
          <circle cx="196" cy="108" r="96" class="art-orbit"/>
          <circle cx="196" cy="108" r="70" class="art-orbit"/>
          <path d="M46 56a24 24 0 0 1 24-24h156a24 24 0 0 1 24 24v66a24 24 0 0 1-24 24H99l-36 25v-27a24 24 0 0 1-17-23Z" class="art-bubble-back"/>
          <path d="M166 107a24 24 0 0 1 24-24h130a24 24 0 0 1 24 24v57a24 24 0 0 1-24 24h-9v24l-34-24h-87a24 24 0 0 1-24-24Z" class="art-bubble-front"/>
          <path d="M81 72h117M81 92h76" class="art-lines"/>
          <g class="art-dots"><circle cx="222" cy="136" r="6"/><circle cx="254" cy="136" r="6"/><circle cx="286" cy="136" r="6"/></g>
          <path d="M336 34v20m-10-10h20M75 190v14m-7-7h14" class="art-spark"/>
        </svg>
      </div>
      <section class="auth-card" aria-labelledby="auth-title">
        <h1 id="auth-title" class="brand"><?= $appName ?></h1>
        <p class="tagline">Your people. Your place to talk.</p>

        <p id="auth-lockdown" class="lockdown-notice hidden" role="status"><strong>Maintenance.</strong> <span data-lockdown-text></span></p>
        <div class="auth-tabs" role="tablist" aria-label="Account access">
          <button id="login-tab" type="button" role="tab" aria-selected="true" aria-controls="login-form" tabindex="0">Sign in</button>
          <button id="register-tab" type="button" role="tab" aria-selected="false" aria-controls="register-form" tabindex="-1">Register</button>
        </div>

        <form id="login-form" class="form-stack" role="tabpanel" aria-labelledby="login-tab" autocomplete="on">
          <label>
            Username
            <input id="login-username" name="username" type="text" autocomplete="username" minlength="3" maxlength="32" required>
          </label>
          <label>
            Password
            <input id="login-password" name="password" type="password" autocomplete="current-password" minlength="12" maxlength="4096" required>
          </label>
          <button class="primary-button" type="submit">Sign in</button>
          <div id="sign-in-providers" class="sign-in-providers hidden">
            <p class="sign-in-divider"><span>or</span></p>
          </div>
          <a class="secondary-button" href="/restore-account.php">Restore a closing account</a>
        </form>

        <form id="register-form" class="form-stack hidden" role="tabpanel" aria-labelledby="register-tab" autocomplete="on" hidden>
          <label>
            Username
            <input id="register-username" name="username" type="text" autocomplete="username" minlength="3" maxlength="32" pattern="[A-Za-z0-9][A-Za-z0-9._\-]{2,31}" required>
          </label>
          <label>
            Password
            <input id="register-password" name="password" type="password" autocomplete="new-password" minlength="12" maxlength="4096" required>
          </label>
          <label>
            Birth date <span class="optional-label">optional; required for age-restricted rooms</span>
            <input id="register-birth-date" name="birth_date" type="date" autocomplete="bday">
          </label>
          <div class="registration-trap" aria-hidden="true">
            <label>
              Website
              <input id="register-website" name="website" type="text" tabindex="-1" autocomplete="off">
            </label>
          </div>
          <button class="primary-button" type="submit">Create account</button>
        </form>

        <p id="auth-error" class="error-text" role="alert"></p>
      </section>
    </div>
    <?= \ChitChat\View\PoweredBy::html() ?>
  </main>

  <main id="chat-shell" class="chat-shell hidden">
    <p id="chat-lockdown" class="lockdown-notice lockdown-banner hidden" role="status"><strong>Maintenance lockdown.</strong> <span data-lockdown-text></span> New sign-ins are paused; you can keep chatting.</p>
    <aside id="sidebar" class="sidebar" aria-label="Rooms and conversations">
      <header class="sidebar-header">
        <div class="brand-row">
          <h1><?= $appName ?></h1>
          <span id="connection-status" class="connection-status" data-state="disconnected" role="status" aria-live="polite" aria-atomic="true">Offline</span>
        </div>
        <button id="drawer-close" class="icon-button close-button drawer-close" type="button" aria-label="Close rooms and conversations">×</button>
      </header>

      <a class="drawer-search" href="/search.php"><svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>Search messages</a>

      <section class="nav-section" aria-labelledby="rooms-heading">
        <div class="rooms-heading-row">
          <h2 id="rooms-heading" class="rooms-heading">Rooms</h2>
          <button id="new-room-button" class="icon-button section-add hidden" type="button" aria-label="Create room" title="Create room">+</button>
        </div>
        <nav id="room-list" class="room-list" aria-labelledby="rooms-heading"></nav>
      </section>

      <section class="nav-section" aria-labelledby="dm-heading">
        <div class="rooms-heading-row">
          <h2 id="dm-heading" class="rooms-heading">Direct messages</h2>
          <a class="icon-button section-add" href="/messages.php" aria-label="New conversation" title="New conversation">+</a>
        </div>
        <ul id="dm-list" class="dm-list"></ul>
        <a class="all-conversations" href="/messages.php">All conversations →</a>
      </section>
    </aside>
    <div id="drawer-backdrop" class="drawer-backdrop" aria-hidden="true"></div>

    <section class="chat-main" aria-labelledby="room-title">
      <header class="room-header">
        <button id="drawer-toggle" class="header-button drawer-toggle" type="button" aria-label="Rooms and conversations" aria-expanded="false" aria-controls="sidebar">
          <svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
        </button>
        <div class="room-heading">
          <h2 id="room-title">Choose a room</h2>
          <p id="room-info">Select a room from the sidebar.</p>
        </div>
        <button id="join-button" class="join-button hidden" type="button">Join room</button>
        <div class="header-actions">
          <button id="members-toggle" class="header-button" type="button" aria-pressed="false" aria-controls="presence-panel" aria-label="Members">
            <svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            <span id="members-count" aria-hidden="true"></span>
          </button>
          <span class="header-divider" aria-hidden="true"></span>
          <a class="header-button header-search" href="/search.php" aria-label="Search messages" title="Search messages"><svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg></a>
          <div class="popover-anchor">
            <button id="notifications-button" class="header-button" type="button" aria-expanded="false" aria-controls="notifications-popover" aria-label="Notifications, none unread">
              <svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
              <span id="notification-badge" class="header-badge hidden" aria-hidden="true">0</span>
            </button>
            <section id="notifications-popover" class="popover hidden" aria-labelledby="notifications-popover-title">
              <div class="popover-title">
                <h2 id="notifications-popover-title">Notifications</h2>
                <button id="notifications-mark-all" class="text-button hidden" type="button">Mark all read</button>
              </div>
              <ul id="notifications-preview" class="notification-preview"></ul>
              <hr>
              <a class="popover-footer" href="/notifications.php">All notifications and push settings →</a>
            </section>
          </div>
          <div class="popover-anchor">
            <button id="user-menu-button" class="user-button" type="button" aria-expanded="false" aria-controls="user-menu" aria-label="Account menu"><span id="user-initials" aria-hidden="true"></span></button>
            <section id="user-menu" class="popover hidden" aria-label="Account menu">
              <div class="popover-identity">
                <span id="user-menu-avatar" class="mini-avatar" aria-hidden="true"></span>
                <div><span class="visually-hidden">Signed in as </span><strong id="current-user"></strong><span aria-hidden="true">Signed in</span></div>
              </div>
              <hr>
              <a class="menu-item" href="/account.php">
                <svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>Account
              </a>
              <a id="admin-link" class="menu-item hidden" href="/admin.php">
                <svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>Administration
              </a>
              <hr>
              <fieldset class="menu-group">
                <legend class="menu-group-label">Appearance</legend>
                <div class="segmented">
                  <label><input type="radio" name="theme-mode" value="system" data-theme-radio><span>System</span></label>
                  <label><input type="radio" name="theme-mode" value="light" data-theme-radio><span>Light</span></label>
                  <label><input type="radio" name="theme-mode" value="dark" data-theme-radio><span>Dark</span></label>
                </div>
                <div class="scheme-picker" role="radiogroup" aria-label="Colour scheme">
                  <label class="scheme-option" title="Lounge"><input type="radio" name="menu-scheme" value="lounge" data-scheme-radio><span class="scheme-swatch" data-swatch="lounge" aria-hidden="true"></span><span class="visually-hidden">Lounge</span></label>
                  <label class="scheme-option" title="Dusk"><input type="radio" name="menu-scheme" value="dusk" data-scheme-radio><span class="scheme-swatch" data-swatch="dusk" aria-hidden="true"></span><span class="visually-hidden">Dusk</span></label>
                  <label class="scheme-option" title="Ember"><input type="radio" name="menu-scheme" value="ember" data-scheme-radio><span class="scheme-swatch" data-swatch="ember" aria-hidden="true"></span><span class="visually-hidden">Ember</span></label>
                  <label class="scheme-option" title="Rosé"><input type="radio" name="menu-scheme" value="rose" data-scheme-radio><span class="scheme-swatch" data-swatch="rose" aria-hidden="true"></span><span class="visually-hidden">Rosé</span></label>
                  <label class="scheme-option" title="Midnight"><input type="radio" name="menu-scheme" value="midnight" data-scheme-radio><span class="scheme-swatch" data-swatch="midnight" aria-hidden="true"></span><span class="visually-hidden">Midnight</span></label>
                  <label class="scheme-option" title="High contrast"><input type="radio" name="menu-scheme" value="contrast" data-scheme-radio><span class="scheme-swatch" data-swatch="contrast" aria-hidden="true"></span><span class="visually-hidden">High contrast</span></label>
                </div>
              </fieldset>
              <fieldset class="menu-group">
                <legend class="menu-group-label">Sounds on this device</legend>
                <label class="menu-switch"><input type="checkbox" data-sound="ping" checked> Pings</label>
                <label class="menu-switch"><input type="checkbox" data-sound="mention" checked> Mentions</label>
                <label class="menu-switch"><input type="checkbox" data-sound="dm" checked> Direct messages</label>
              </fieldset>
              <hr>
              <button id="logout-button" class="menu-item danger-item" type="button">
                <svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>Sign out
              </button>
              <?= \ChitChat\View\PoweredBy::html() ?>
            </section>
          </div>
        </div>
      </header>

      <div id="empty-state" class="empty-state">Choose a room to begin.</div>
      <section id="message-list" class="message-list" aria-live="polite" aria-label="Messages">
        <button id="load-older-button" class="secondary-button hidden" type="button">Load older messages</button>
      </section>

      <div id="composer-wrap" class="composer-wrap hidden">
        <div id="reply-banner" class="reply-banner hidden">
          <span id="reply-banner-text"></span>
          <button id="reply-banner-cancel" class="reply-banner-cancel" type="button" aria-label="Cancel reply">Cancel</button>
        </div>
        <form id="composer-form" class="composer">
          <div class="composer-field">
            <label class="visually-hidden" for="composer-input">Message or attachment caption</label>
            <textarea id="composer-input" name="message" maxlength="4000" rows="2" placeholder="Write a message…"></textarea>
            <button id="emoji-button" class="emoji-button" type="button" aria-label="Insert emoji" title="Insert emoji">
              <svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>
            </button>
            <label class="attachment-button" for="attachment-input" title="Attach file">
              <svg class="attachment-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
              <span class="visually-hidden">Attach file</span>
            </label>
            <input
              id="attachment-input"
              class="visually-hidden"
              name="file"
              type="file"
              accept="image/jpeg,image/png,image/gif,image/webp,application/pdf,text/plain,text/csv,application/json,application/zip"
            >
          </div>
          <button id="send-button" class="primary-button" type="submit">Send</button>
          <div class="attachment-selection">
            <span id="attachment-name" class="attachment-name" aria-live="polite"></span>
            <button id="attachment-clear" class="secondary-button hidden" type="button">Remove</button>
          </div>
        </form>
        <p class="composer-help">Enter sends · Shift+Enter adds a line · Attachments may include an optional caption · Commands: <code>/me</code>, <code>/ping username</code></p>
      </div>
    </section>

    <aside id="presence-panel" class="members-panel hidden" aria-labelledby="members-heading">
      <header>
        <h2 id="members-heading">Members</h2>
        <button id="members-close" class="icon-button close-button" type="button" aria-label="Close members">×</button>
      </header>
      <h3 id="presence-heading">Online here</h3>
      <ul id="presence-list" class="member-list" aria-labelledby="presence-heading"></ul>
    </aside>
  </main>

  <dialog id="room-dialog" class="room-dialog" aria-labelledby="room-dialog-title">
    <form id="room-create-form" class="form-stack" method="dialog">
      <header class="dialog-header">
        <h2 id="room-dialog-title">Create room</h2>
        <button id="room-dialog-cancel" class="icon-button" type="button" aria-label="Close">×</button>
      </header>
      <label>
        Room key
        <input id="room-key" name="key" type="text" minlength="3" maxlength="48" pattern="[a-z0-9][a-z0-9_\-]{2,47}" placeholder="general" required>
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

  <dialog id="message-report-dialog" class="room-dialog message-report-dialog" aria-labelledby="message-report-title">
    <form id="message-report-form" class="form-stack">
      <header class="dialog-header">
        <div>
          <h2 id="message-report-title">Report message</h2>
          <p>Moderators receive an immutable snapshot of this message only. A report does not grant access to unrelated private history.</p>
        </div>
      </header>
      <label>Reason
        <select id="message-report-category" required>
          <option value="spam">Spam</option>
          <option value="harassment">Harassment</option>
          <option value="hate">Hate speech</option>
          <option value="threats">Threats or violence</option>
          <option value="sexual_content">Sexual content</option>
          <option value="privacy">Privacy violation</option>
          <option value="impersonation">Impersonation</option>
          <option value="other">Other</option>
        </select>
      </label>
      <label>Additional details <span class="optional-label">optional</span>
        <textarea id="message-report-details" maxlength="1000" rows="5"></textarea>
      </label>
      <p id="message-report-error" class="error-text" role="alert"></p>
      <div class="action-row">
        <button id="message-report-cancel" class="secondary-button" type="button">Cancel</button>
        <button id="message-report-submit" class="danger-button" type="submit">Submit report</button>
      </div>
    </form>
  </dialog>

  <div id="toast-region" class="toast-region" aria-live="assertive"></div>

  <script type="module" src="/assets/js/mfa-login.js"></script>
  <script type="module" src="/assets/js/app.js"></script>
  <script type="module" src="/assets/js/search-result-navigation.js"></script>
  <script type="module" src="/assets/js/auth-tabs.js"></script>
  <script type="module" src="/assets/js/admin-link.js"></script>
  <script type="module" src="/assets/js/navigation.js"></script>
  <script type="module" src="/assets/js/room-message-mutations.js"></script>
</body>
</html>
