<?php

declare(strict_types=1);
/** @var ChitChat\Config $config */
$config = require dirname(__DIR__) . '/bootstrap/app.php';
if (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) === '/favicon.ico') {
    \ChitChat\InstallationIcon\IconResponse::respond($config, true);
}
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
  <link rel="stylesheet" href="/assets/css/tokens.css">
  <link rel="stylesheet" href="/assets/css/app.css">
<?= \ChitChat\View\HeadAssets::html(\ChitChat\InstallationIcon\IconService::resolve($config)) ?>
  <link rel="stylesheet" href="/assets/css/components.css">
  <link rel="stylesheet" href="/assets/css/transcript.css">
  <link rel="stylesheet" href="/assets/css/accessibility.css">
  <link rel="stylesheet" href="/assets/css/totp.css">
  <link rel="stylesheet" href="/assets/css/person-reports.css">
  <link rel="stylesheet" href="/assets/css/recovery.css">
  <link rel="stylesheet" href="/assets/css/message-mutations.css">
  <link rel="stylesheet" href="/assets/css/privacy-notifications.css">
  <link rel="stylesheet" href="/assets/css/guest.css">
  <link rel="stylesheet" href="/assets/css/rules.css">
  <link rel="stylesheet" href="/assets/css/shell.css">
  <link rel="stylesheet" href="/assets/css/panes.css">
  <link rel="stylesheet" href="/assets/css/room-list.css">
  <link rel="stylesheet" href="/assets/css/auth.css">
</head>
<body>
  <!-- One polite announcer per page, fed by events (announcer.js); the transcript itself never speaks. -->
  <div id="announcer" class="visually-hidden" role="status" aria-live="polite" aria-atomic="true"></div>
  <div id="app-loading" class="app-loading" role="status">Loading <?= $appName ?>…</div>

  <main id="auth-shell" class="auth-shell hidden">
    <div class="auth-layout">
      <!-- S3 (#169): the installation's lockup is the headline (#227, web only).
           The art is drawn from Lantern shapes only; the seasonal decoration
           (sign_in_decoration, #242) is placed around it by sign-in-season.js. -->
      <header class="auth-intro">
        <div class="auth-season" data-season hidden aria-hidden="true"></div>
        <?= \ChitChat\View\Lockup::html($config, 'auth-title') ?>
        <svg class="auth-art" viewBox="0 0 400 210" aria-hidden="true" focusable="false">
          <rect class="art-lantern art-h3" x="34" y="58" width="56" height="56" rx="17"/>
          <rect class="art-lantern art-h5" x="318" y="22" width="42" height="42" rx="13"/>
          <rect class="art-lantern art-h1" x="270" y="146" width="46" height="46" rx="14"/>
          <path class="art-bubble" d="M140 30h104a30 30 0 0 1 30 30v26a30 30 0 0 1-30 30H140a30 30 0 0 1-30-30V60a30 30 0 0 1 30-30Z"/>
          <circle class="art-chit" cx="292" cy="132" r="9"/>
          <path class="art-lines" d="M142 64h82M142 84h52"/>
          <circle class="art-pebble art-h6" cx="92" cy="168" r="20"/>
          <circle class="art-pebble art-h4" cx="146" cy="186" r="11"/>
          <circle class="art-pebble art-h2" cx="368" cy="110" r="13"/>
        </svg>
        <svg class="auth-band-art" viewBox="0 0 64 40" aria-hidden="true" focusable="false">
          <circle class="art-pebble art-h6" cx="14" cy="24" r="10"/>
          <circle class="art-pebble art-h4" cx="34" cy="32" r="6"/>
          <circle class="art-chit" cx="54" cy="10" r="5"/>
        </svg>
      </header>
      <section class="auth-card">
        <h2 id="auth-greeting" class="auth-greeting">Welcome back</h2>
        <p id="auth-notice" class="auth-strip hidden" role="status"></p>
        <p id="auth-lockdown" class="lockdown-notice auth-strip hidden" role="status"><strong>Maintenance.</strong> <span data-lockdown-text></span></p>
        <div class="auth-tabs" role="tablist" aria-label="Account access">
          <button id="login-tab" type="button" role="tab" aria-selected="true" aria-controls="login-form" tabindex="0">Sign in</button>
          <button id="register-tab" type="button" role="tab" aria-selected="false" aria-controls="register-form" tabindex="-1">Register</button>
        </div>

        <form id="login-form" class="form-stack" role="tabpanel" aria-labelledby="login-tab" autocomplete="on">
          <label>
            Username
            <input id="login-username" name="username" type="text" autocomplete="username" minlength="3" maxlength="32" required>
          </label>
          <div class="password-field">
            <label for="login-password">Password</label>
            <div class="password-field-row">
              <input id="login-password" name="password" type="password" autocomplete="current-password" minlength="12" maxlength="4096" required>
              <button class="password-toggle" type="button" data-reveal="login-password" aria-controls="login-password" aria-pressed="false" aria-label="Show password">
                  <svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
            </div>
          </div>
          <p class="forgot-password"><a id="forgot-password-link" href="/?reset=1">Forgot password?</a></p>
          <button class="primary-button" type="submit">Sign in</button>
          <div id="sign-in-providers" class="sign-in-providers hidden">
            <p class="sign-in-divider"><span>or</span></p>
          </div>
          <a class="quiet-action" href="/restore-account.php">Restore a closing account</a>
        </form>

        <form id="register-form" class="form-stack hidden" role="tabpanel" aria-labelledby="register-tab" autocomplete="on" hidden>
          <div id="register-provider-note" class="provider-sign-up-note hidden">
            <p id="register-provider-text"></p>
            <button id="register-provider-cancel" class="link-button" type="button">Use a password instead</button>
          </div>
          <label>
            Username
            <input id="register-username" name="username" type="text" autocomplete="username" minlength="3" maxlength="32" pattern="[A-Za-z0-9][A-Za-z0-9._\-]{2,31}" required>
          </label>
          <div id="register-password-field" class="password-field">
            <label for="register-password">Password <span class="optional-label">at least 12 characters</span></label>
            <div class="password-field-row">
              <input id="register-password" name="password" type="password" autocomplete="new-password" minlength="12" maxlength="4096" required>
              <button class="password-toggle" type="button" data-reveal="register-password" aria-controls="register-password" aria-pressed="false" aria-label="Show password">
                  <svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
            </div>
          </div>
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
          <div id="register-agreement" class="register-agreement hidden">
            <div id="register-rules-field" class="hidden">
              <label class="checkbox-label">
                <input id="register-rules-accept" name="accept_rules" type="checkbox" aria-describedby="register-rules-error">
                <span>I agree to the community rules of <?= $appName ?>.</span>
              </label>
              <p id="register-rules-error" class="field-error hidden" role="alert"></p>
            </div>
            <p class="register-agreement-links">
              <a id="register-rules-link" class="hidden" href="#" target="_blank" rel="noopener">Read the community rules<span class="visually-hidden"> (opens in a new tab)</span></a><span id="register-links-separator" class="hidden" aria-hidden="true"> · </span><a id="register-privacy-link" class="hidden" href="#" target="_blank" rel="noopener">How your data is handled<span class="visually-hidden"> (opens in a new tab)</span></a>
            </p>
            <p id="register-privacy-only" class="register-agreement-links hidden">See how your data is handled in the <a id="register-privacy-only-link" href="#" target="_blank" rel="noopener">privacy policy<span class="visually-hidden"> (opens in a new tab)</span></a>.</p>
          </div>
          <button class="primary-button" type="submit">Create account</button>
        </form>

        <div class="guest-entry hidden" data-guest-entry>
          <p class="guest-entry-lead">Just curious?</p>
          <button class="secondary-button" type="button" data-guest-start>Look around as a guest</button>
          <p>No account needed. Read open rooms and chat where guests are welcome.</p>
          <p class="guest-rules-note hidden" data-guest-rules>By looking around, you agree to the <a href="#" target="_blank" rel="noopener">community rules<span class="visually-hidden"> (opens in a new tab)</span></a>.</p>
        </div>

        <section id="password-reset" class="password-reset form-stack hidden" aria-labelledby="password-reset-title">
          <button id="password-reset-back" class="link-button password-reset-back" type="button">← Back to sign in</button>
          <h2 id="password-reset-title">Set a new password</h2>
          <p>Use the recovery key you saved when you created your account.</p>
          <form id="password-reset-form" class="form-stack" autocomplete="on" novalidate>
            <label>
              Username
              <input id="reset-username" name="username" type="text" autocomplete="username" minlength="3" maxlength="32" required>
            </label>
            <label>
              Recovery key
              <input id="reset-key" name="recovery_key" type="text" class="recovery-key-input" autocomplete="off" spellcheck="false" autocapitalize="characters" inputmode="text" maxlength="64" placeholder="XXXX-XXXX-XXXX-XXXX-XXXX-XXXX" required>
            </label>
            <div class="password-field">
              <label for="reset-password">New password <span class="optional-label">at least 12 characters</span></label>
              <div class="password-field-row">
                <input id="reset-password" name="new_password" type="password" autocomplete="new-password" minlength="12" maxlength="4096" required>
                <button id="reset-password-toggle" class="password-toggle" type="button" aria-pressed="false" aria-label="Show password">
                  <svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
                </button>
              </div>
            </div>
            <button id="reset-submit" class="primary-button" type="submit">Set new password</button>
          </form>
          <p id="password-reset-status" class="error-text" role="alert"></p>
          <p class="password-reset-help">No recovery key? Ask an administrator of <?= $appName ?> to reset it for you.</p>
        </section>
        <div id="recovery-key-slot" class="hidden"></div>

        <p id="auth-error" class="error-text" role="alert"></p>
      </section>
    </div>
    <?= \ChitChat\View\PoweredBy::html($config) ?>
  </main>

  <?= \ChitChat\View\AppShell::open('rooms', 'conversation', 'Skip to conversation', true, config: $config) ?>
  <div id="chat-shell" class="chat-shell hidden" data-panes data-list="sidebar" data-toggle="drawer-toggle" data-close="drawer-close" data-backdrop="drawer-backdrop" data-title="room-title" data-list-name="Rooms" data-deep-link="room_id">
    <p id="chat-lockdown" class="lockdown-notice lockdown-banner hidden" role="status"><strong>Maintenance lockdown.</strong> <span data-lockdown-text></span> New sign-ins are paused; you can keep chatting.</p>
    <p class="rules-banner hidden" data-rules-gate role="status"><span><strong>The community rules have changed.</strong> Agree to keep writing.</span> <button class="secondary-button" type="button" data-rules-review>Review</button></p>
    <p id="recovery-nudge" class="recovery-nudge hidden" role="status"><span><strong>Forgot your password one day?</strong> Create a recovery key so you can reset it yourself.</span> <a class="secondary-button" href="/account.php?recovery=create">Create key</a> <button id="recovery-nudge-dismiss" class="icon-button close-button" type="button" aria-label="Dismiss">×</button></p>
    <p id="new-look-note" class="new-look-note hidden" role="status"><span><strong><?= $appName ?> has a new look.</strong> Prefer the previous one? Choose Lounge in <a href="/account.php#appearance-heading">Appearance</a>.</span> <button id="new-look-dismiss" class="icon-button close-button" type="button" aria-label="Dismiss">×</button></p>
    <div id="guest-banner" class="guest-banner hidden" role="status">
      <span><strong>You are visiting as <span data-guest-name></span>.</strong><span class="guest-banner-more"> Look around and join in where guests can write. Nothing is kept after you leave.</span></span>
      <a href="/?register=1">Create an account</a>
      <button id="guest-banner-dismiss" class="icon-button close-button" type="button" aria-label="Dismiss">×</button>
    </div>
    <!-- The Rooms list pane (S2c): beside the conversation on desktop, over it
         on tablets (the toggle in the conversation header), and the first
         screen of the phone stack. Direct messages live on the Direct tab. -->
    <section id="sidebar" class="sidebar list-pane" aria-labelledby="rooms-heading">
      <header class="list-pane-header">
        <div class="list-pane-title">
          <?= \ChitChat\View\AppShell::brand($config, 'list-pane-brand') ?>
          <h1 id="rooms-heading" class="list-title">Rooms</h1>
          <span id="connection-status" class="connection-status" data-state="connecting">Connecting…</span>
        </div>
        <div class="list-pane-actions">
          <a class="header-button list-search member-only" href="/search.php" aria-label="Search messages" title="Search messages"><svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg></a>
          <button id="new-room-button" class="header-button section-add hidden" type="button" aria-label="Create room" title="Create room"><svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg></button>
          <button id="drawer-close" class="header-button close-button drawer-close" type="button" aria-label="Close rooms">×</button>
        </div>
      </header>
      <nav id="room-list" class="room-list" aria-labelledby="rooms-heading"></nav>
      <div class="guest-dm-card guest-only">
        <span><strong>Direct messages are for members.</strong> With a free account you can message people, mention them and get notified.</span>
        <a class="secondary-button" href="/?register=1">Create an account</a>
      </div>
    </section>
    <div id="drawer-backdrop" class="drawer-backdrop" aria-hidden="true"></div>

    <main id="conversation" class="chat-main" aria-labelledby="room-title" tabindex="-1">
      <header class="room-header">
        <!-- Tablets: opens the Rooms list over the conversation. Phones: back to the list. -->
        <button id="drawer-toggle" class="header-button drawer-toggle" type="button" aria-label="Rooms" aria-expanded="false" aria-controls="sidebar">
          <svg class="toggle-list" aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
          <svg class="toggle-back" aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
        </button>
        <span id="current-user" hidden></span>
        <div class="room-heading">
          <h2 id="room-title" class="conversation-title" tabindex="-1">Choose a room</h2>
          <p class="room-meta-line"><span id="room-here" class="room-here" hidden></span><span id="room-info">Select a room from the list.</span></p>
        </div>
        <button id="join-button" class="join-button hidden" type="button">Join room</button>
        <div class="header-actions">
          <button id="members-toggle" class="header-button" type="button" aria-pressed="false" aria-controls="presence-panel" aria-label="Members">
            <svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
            <span id="members-count" aria-hidden="true"></span>
          </button>
          <span class="header-divider member-only" aria-hidden="true"></span>
          <a class="header-button header-search member-only" href="/search.php" aria-label="Search messages" title="Search messages"><svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg></a>
        </div>
      </header>

      <div id="empty-state" class="empty-state">Choose a room to begin.</div>
      <button id="load-older-button" class="secondary-button hidden" type="button">Load older messages</button>
      <ol id="message-list" class="message-list" aria-label="Messages">
      </ol>

      <div id="composer-wrap" class="composer-wrap hidden">
        <button id="jump-latest" class="jump-latest hidden" type="button">Jump to latest</button>
        <p id="typing-indicator" class="typing-indicator"></p>
        <div id="muted-notice" class="muted-notice hidden" role="status"></div>
        <div class="muted-notice rules-notice hidden" data-rules-gate role="status">
          <div><strong>Agree to the updated community rules to write.</strong><span>You can still read along.</span></div>
          <button class="primary-button" type="button" data-rules-review>Review rules</button>
        </div>
        <div class="muted-notice username-notice hidden" data-username-gate role="status">
          <div><strong>Choose a new username to keep writing.</strong><span>A moderator replaced your username. You can still read along.</span><span class="username-reason hidden" data-username-reason></span></div>
          <a class="primary-button" href="/choose-username.php">Choose a name</a>
        </div>
        <div id="guest-readonly-notice" class="muted-notice guest-readonly hidden" role="status">
          <div><strong>Guests can read this room.</strong><span>Create an account to join the conversation.</span></div>
          <a class="primary-button" href="/?register=1">Create an account</a>
        </div>
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
            <label class="attachment-button member-only" for="attachment-input" title="Attach file">
              <svg class="attachment-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
              <span class="visually-hidden">Attach file</span>
            </label>
            <input
              id="attachment-input"
              class="visually-hidden member-only"
              name="file"
              type="file"
              accept="image/jpeg,image/png,image/gif,image/webp,application/pdf,text/plain,text/csv,application/json,application/zip"
            >
          </div>
          <button id="send-button" class="primary-button send-round" type="submit"><svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h13M13 6l6 6-6 6"/></svg><span class="visually-hidden">Send</span></button>
          <div class="attachment-selection">
            <span id="attachment-name" class="attachment-name" aria-live="polite"></span>
            <button id="attachment-clear" class="secondary-button hidden" type="button">Remove</button>
          </div>
        </form>
        <p class="composer-help member-only">Enter sends · Shift+Enter adds a line · Attachments may include an optional caption · Type <code>/</code> for commands, <code>/help</code> for formatting</p>
        <p class="composer-help guest-only">Enter sends · Shift+Enter adds a line · As a guest, <code>@names</code> and <code>/ping</code> don’t notify anyone</p>
      </div>
    </main>

    <aside id="presence-panel" class="members-panel hidden" aria-labelledby="members-heading">
      <header>
        <h2 id="members-heading">Members</h2>
        <button id="members-close" class="icon-button close-button" type="button" aria-label="Close members">×</button>
      </header>
      <h3 id="presence-heading">Online here</h3>
      <ul id="presence-list" class="member-list" aria-labelledby="presence-heading"></ul>
      <h3 id="guest-presence-heading" class="guest-heading hidden">Guests</h3>
      <ul id="guest-presence-list" class="member-list hidden" aria-labelledby="guest-presence-heading"></ul>
    </aside>
  </div>
  <?= \ChitChat\View\AppShell::close() ?>

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
        Guests <span class="optional-label">public rooms without a minimum age only</span>
        <select id="room-guest-access" name="guest_access">
          <option value="none">Not allowed</option>
          <option value="read">Can read</option>
          <option value="write">Can read and write</option>
        </select>
      </label>
      <label>
        Inactivity timeout in seconds <span class="optional-label">0 disables; minimum 120</span>
        <input id="room-inactivity-timeout" name="inactivity_timeout_seconds" type="number" min="0" max="86400" step="60" value="0" required>
      </label>
      <p id="room-dialog-error" class="error-text" role="alert"></p>
      <button class="primary-button" type="submit">Create room</button>
    </form>
  </dialog>

  <?= \ChitChat\View\ReportDialog::html() ?>

  <div id="toast-region" class="toast-region" aria-live="assertive"></div>

  <script type="module" src="/assets/js/mfa-login.js"></script>
  <script type="module" src="/assets/js/password-reset.js"></script>
  <script type="module" src="/assets/js/app.js"></script>
  <script type="module" src="/assets/js/search-result-navigation.js"></script>
  <script type="module" src="/assets/js/panes.js"></script>
  <script type="module" src="/assets/js/auth-tabs.js"></script>
  <script type="module" src="/assets/js/password-reveal.js"></script>
  <script type="module" src="/assets/js/admin-link.js"></script>
  <script type="module" src="/assets/js/navigation.js"></script>
  <script type="module" src="/assets/js/room-message-mutations.js"></script>
  <script type="module" src="/assets/js/shell.js"></script>
</body>
</html>
