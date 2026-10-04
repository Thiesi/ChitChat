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
  <meta name="description" content="<?= $appName ?> account and personal data">
  <title>Account · <?= $appName ?></title>
  <link rel="stylesheet" href="/assets/css/app.css">
  <link rel="stylesheet" href="/assets/css/components.css">
  <link rel="stylesheet" href="/assets/css/accessibility.css">
  <link rel="stylesheet" href="/assets/css/account.css">
  <link rel="stylesheet" href="/assets/css/step-up.css" data-step-up-styles="true">
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

    <section class="account-card" aria-labelledby="avatar-heading">
      <div>
        <p class="account-eyebrow">Profile</p>
        <h2 id="avatar-heading">Profile picture</h2>
      </div>
      <div class="avatar-settings">
        <span id="avatar-preview" class="mini-avatar avatar-preview" aria-hidden="true"></span>
        <div class="avatar-settings-text">
          <p>Shown next to your messages, in member lists, and on your profile card instead of your initials. JPEG, PNG, or WebP up to 5 MB; you can choose the crop. The server stores a fresh 256 × 256 copy without any photo metadata, such as location.</p>
          <div class="account-action-row">
            <label class="secondary-button file-button" for="avatar-file">Upload a picture</label>
            <input id="avatar-file" class="visually-hidden" type="file" accept="image/jpeg,image/png,image/webp">
            <button id="avatar-remove" class="secondary-button hidden" type="button">Remove picture</button>
            <span id="avatar-providers" class="avatar-providers"></span>
          </div>
          <p id="avatar-status" class="account-muted" role="status" aria-live="polite"></p>
        </div>
      </div>
    </section>

    <section class="account-card" aria-labelledby="appearance-heading">
      <div>
        <p class="account-eyebrow">Appearance</p>
        <h2 id="appearance-heading">Theme and colours</h2>
      </div>
      <p>Follow your device's light or dark setting, or choose one, and pick a colour scheme. Every scheme has a light and a dark variant. These choices are saved on this device.</p>
      <label class="theme-select">
        Theme
        <select data-theme-select>
          <option value="system">System</option>
          <option value="light">Light</option>
          <option value="dark">Dark</option>
        </select>
      </label>
      <fieldset class="scheme-fieldset">
        <legend>Colour scheme</legend>
        <div class="scheme-picker" role="radiogroup" aria-label="Colour scheme">
          <label class="scheme-option" title="Lounge"><input type="radio" name="account-scheme" value="lounge" data-scheme-radio><span class="scheme-swatch" data-swatch="lounge" aria-hidden="true"></span><span class="scheme-name">Lounge</span></label>
          <label class="scheme-option" title="Dusk"><input type="radio" name="account-scheme" value="dusk" data-scheme-radio><span class="scheme-swatch" data-swatch="dusk" aria-hidden="true"></span><span class="scheme-name">Dusk</span></label>
          <label class="scheme-option" title="Ember"><input type="radio" name="account-scheme" value="ember" data-scheme-radio><span class="scheme-swatch" data-swatch="ember" aria-hidden="true"></span><span class="scheme-name">Ember</span></label>
          <label class="scheme-option" title="Rosé"><input type="radio" name="account-scheme" value="rose" data-scheme-radio><span class="scheme-swatch" data-swatch="rose" aria-hidden="true"></span><span class="scheme-name">Rosé</span></label>
          <label class="scheme-option" title="Midnight"><input type="radio" name="account-scheme" value="midnight" data-scheme-radio><span class="scheme-swatch" data-swatch="midnight" aria-hidden="true"></span><span class="scheme-name">Midnight</span></label>
          <label class="scheme-option" title="High contrast"><input type="radio" name="account-scheme" value="contrast" data-scheme-radio><span class="scheme-swatch" data-swatch="contrast" aria-hidden="true"></span><span class="scheme-name">High contrast</span></label>
        </div>
      </fieldset>
    </section>

    <section class="account-card" aria-labelledby="date-time-heading">
      <div>
        <p class="account-eyebrow">Date and time</p>
        <h2 id="date-time-heading">How dates and times look</h2>
      </div>
      <p>Automatic follows your browser's language settings. Choosing a region applies its date format wherever you sign in. Times always use this device's time zone.</p>
      <form id="date-time-form" class="form-stack">
        <label>
          Format region
          <select id="date-locale" name="date_locale"><option value="">Automatic</option></select>
        </label>
        <label>
          Clock
          <select id="hour-cycle" name="hour_cycle">
            <option value="">Automatic</option>
            <option value="h23">24-hour</option>
            <option value="h12">12-hour</option>
          </select>
        </label>
        <dl class="date-preview" aria-live="polite">
          <dt>In chat</dt><dd id="date-preview-short"></dd>
          <dt>Elsewhere</dt><dd id="date-preview-medium"></dd>
        </dl>
        <div class="account-action-row">
          <button class="secondary-button" type="submit">Save date and time format</button>
          <p id="date-time-status" class="account-muted" role="status"></p>
        </div>
      </form>
    </section>

    <section class="account-card" aria-labelledby="typing-heading">
      <div>
        <p class="account-eyebrow">Chat</p>
        <h2 id="typing-heading">Typing indicator</h2>
      </div>
      <p>“Alex is typing…” above the message box, in rooms and direct messages. It never includes what you type. It works both ways: turned off, others don’t see when you type, and you don’t see when they do.</p>
      <label>
        <input id="share-typing" type="checkbox" checked>
        Show when I’m typing, and when others are
      </label>
      <p id="share-typing-status" class="account-muted" role="status" aria-live="polite"></p>
    </section>

    <section id="password-card" class="account-card" aria-labelledby="password-heading">
      <div>
        <p class="account-eyebrow">Sign-in</p>
        <h2 id="password-heading">Password</h2>
      </div>
      <p id="password-intro">Changing your password signs you out everywhere else.</p>
      <form id="password-form" class="form-stack" autocomplete="on">
        <label id="password-current-field">
          Current password
          <input id="password-current" type="password" autocomplete="current-password" maxlength="4096" required>
        </label>
        <label>
          New password <span class="optional-label">at least 12 characters</span>
          <input id="password-new" type="password" autocomplete="new-password" minlength="12" maxlength="4096" required>
        </label>
        <div class="action-row">
          <button id="password-submit" class="secondary-button" type="submit">Change password</button>
        </div>
      </form>
      <p id="password-status" class="account-muted" role="status" aria-live="polite"></p>
    </section>

    <section id="sign-in-methods" class="account-card hidden" aria-labelledby="sign-in-methods-heading">
      <div>
        <p class="account-eyebrow">Sign-in</p>
        <h2 id="sign-in-methods-heading">Sign-in methods</h2>
      </div>
      <p>Connect Google or Twitch to sign in with it instead of your password. ChitChat only receives an account number from the provider: no email address, name, or picture.</p>
      <ul id="sign-in-method-list" class="sign-in-method-list"></ul>
      <p id="sign-in-methods-status" class="account-muted" role="status" aria-live="polite"></p>
    </section>

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
    <?= \ChitChat\View\PoweredBy::html() ?>
  </main>

  <dialog id="avatar-crop-dialog" class="room-dialog avatar-crop-dialog" aria-labelledby="avatar-crop-title">
    <form method="dialog" class="form-stack">
      <header class="dialog-header">
        <h2 id="avatar-crop-title">Crop your picture</h2>
      </header>
      <div class="avatar-crop-stage">
        <canvas id="avatar-crop-canvas" width="512" height="512" tabindex="0" aria-label="Picture crop area. Drag, or use the arrow keys, to move the picture; plus and minus zoom."></canvas>
      </div>
      <label>
        Zoom
        <input id="avatar-crop-zoom" type="range" min="1" max="4" step="0.01" value="1">
      </label>
      <p class="account-muted">Drag the picture or use the arrow keys to position it. The circle shows what others see.</p>
      <div class="account-action-row">
        <button id="avatar-crop-save" class="primary-button" type="button">Save picture</button>
        <button id="avatar-crop-cancel" class="secondary-button" type="button">Cancel</button>
      </div>
    </form>
  </dialog>


  <script type="module" src="/assets/js/account.js"></script>
  <script type="module" src="/assets/js/mfa-account.js"></script>
  <script type="module" src="/assets/js/date-time-settings.js"></script>
  <script type="module" src="/assets/js/avatar-settings.js"></script>
  <script type="module" src="/assets/js/sign-in-methods.js"></script>
  <script type="module" src="/assets/js/password-settings.js"></script>
  <script type="module" src="/assets/js/typing-settings.js"></script>
</body>
</html>
