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
  <link rel="stylesheet" href="/assets/css/tokens.css">
  <link rel="stylesheet" href="/assets/css/app.css">
<?= \ChitChat\View\HeadAssets::html(\ChitChat\InstallationIcon\IconService::resolve($config)) ?>
  <link rel="stylesheet" href="/assets/css/components.css">
  <link rel="stylesheet" href="/assets/css/accessibility.css">
  <link rel="stylesheet" href="/assets/css/account.css">
  <link rel="stylesheet" href="/assets/css/rules.css">
  <link rel="stylesheet" href="/assets/css/recovery.css">
  <link rel="stylesheet" href="/assets/css/step-up.css" data-step-up-styles="true">
  <link rel="stylesheet" href="/assets/css/privacy-notifications.css">
  <link rel="stylesheet" href="/assets/css/shell.css">
</head>
<body>
  <div id="account-loading" class="app-loading" role="status">Loading account…</div>

  <?= \ChitChat\View\AppShell::open('you', 'account-shell') ?>
  <main id="account-shell" tabindex="-1" class="account-shell hidden">
    <header class="account-header">
      <div>
        <p class="account-eyebrow"><?= $appName ?></p>
        <h1>Your account</h1>
        <p class="you-identity"><span id="you-avatar" class="you-avatar" aria-hidden="true"></span><span id="account-identity" class="account-muted"></span></p>
      </div>
    </header>

    <!-- Guests only (you.js): a guest's You page is this card, Appearance and Leave. -->
    <section id="you-guest-card" class="account-card" aria-labelledby="you-guest-heading" data-guest-visible data-guest-only hidden>
      <p class="account-eyebrow">Guest visit</p>
      <h2 id="you-guest-heading">Want to stay?</h2>
      <p>With a free account you can message people, mention them, get alerts and keep your settings. Nothing from this visit is kept after you leave.</p>
      <div class="account-action-row"><a class="primary-button" href="/?register=1">Create an account</a></div>
    </section>

    <!-- Staff only; shell.js reveals it with the same destination as the rail's Stewarding item.
         On phones, where the bottom bar has no Stewarding, this is the way in. -->
    <section id="you-steward-card" class="account-card you-steward hidden" aria-labelledby="you-steward-heading">
      <p class="account-eyebrow">Stewarding</p>
      <h2 id="you-steward-heading">Looking after this server</h2>
      <p class="account-muted">Reports, people and settings for this installation.</p>
      <div class="account-action-row"><a id="you-steward-link" class="secondary-button" href="/admin.php">Open Stewarding</a></div>
    </section>

    <section class="account-card" data-member-only aria-labelledby="avatar-heading">
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

    <section id="alerts-card" class="account-card" aria-labelledby="push-heading" data-member-only>
      <p class="account-eyebrow">Notifications</p>
      <h2 id="push-heading">Alerts</h2>
      <p id="push-status" class="account-muted" role="status" aria-live="polite"></p>

      <!-- Account-wide: these apply to every device with alerts on, including the ChitChat app,
           so they're shown whether or not this browser is subscribed (#150). -->
      <div id="push-settings" class="hidden">
        <p>These choices apply to every device where alerts are on, including the ChitChat app.</p>
        <p id="push-no-device" class="push-note hidden">No device has alerts turned on yet. Your choices are saved for when you do.</p>
        <form id="push-preferences-form" class="form-stack">
          <h3>What should alert you?</h3>
          <label class="push-choice">
            <input id="push-dm-enabled" type="checkbox" role="switch" aria-describedby="push-dm-help">
            <span><span class="push-choice-label">New direct messages</span>
            <span id="push-dm-help" class="account-muted">Someone writes to you directly. The alert never shows what they wrote.</span></span>
          </label>
          <label class="push-choice">
            <input id="push-mentioned-enabled" type="checkbox" role="switch" aria-describedby="push-mentioned-help">
            <span><span class="push-choice-label">Mentions</span>
            <span id="push-mentioned-help" class="account-muted">@you, @room or @here.</span></span>
          </label>
          <label class="push-choice">
            <input id="push-pinged-enabled" type="checkbox" role="switch" aria-describedby="push-pinged-help">
            <span><span class="push-choice-label">Pings</span>
            <span id="push-pinged-help" class="account-muted">Someone uses /ping with your name.</span></span>
          </label>
          <p class="account-muted push-overlap">If one message is both a direct message and a mention, you get one alert, not two.</p>

          <h3>Quiet hours</h3>
          <p class="account-muted">No alerts during these hours. Your notifications here still arrive.</p>
          <label>
            <span>From</span>
            <select id="push-quiet-start">
              <option value="">Off</option>
              <?php for ($hour = 0; $hour < 24; $hour++): ?>
                <option value="<?= $hour ?>"><?= sprintf('%02d:00', $hour) ?></option>
              <?php endfor; ?>
            </select>
          </label>
          <label>
            <span>To</span>
            <select id="push-quiet-end">
              <option value="">Off</option>
              <?php for ($hour = 0; $hour < 24; $hour++): ?>
                <option value="<?= $hour ?>"><?= sprintf('%02d:00', $hour) ?></option>
              <?php endfor; ?>
            </select>
          </label>
          <label>
            <span>Time zone</span>
            <input id="push-quiet-timezone" type="text" placeholder="e.g. Europe/Berlin" list="push-timezone-options" autocomplete="off">
            <datalist id="push-timezone-options">
              <?php foreach (DateTimeZone::listIdentifiers() as $identifier): ?>
                <option value="<?= htmlspecialchars($identifier, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></option>
              <?php endforeach; ?>
            </datalist>
          </label>

          <div class="account-action-row">
            <button id="push-preferences-save" class="secondary-button" type="submit">Save</button>
            <p id="push-preferences-status" class="account-muted" role="status" aria-live="polite"></p>
          </div>
        </form>
      </div>

      <!-- This device only. -->
      <div id="push-browser" class="hidden">
        <h3>This browser</h3>
        <p id="push-unsupported" class="account-muted hidden">This browser can’t show alerts. Your choices above still apply to your other devices.</p>
        <p id="push-browser-status" class="account-muted"></p>
        <div class="account-action-row">
          <button id="push-subscribe-toggle" class="secondary-button" type="button" disabled>Turn on alerts here</button>
        </div>
        <p class="account-muted">Alerts can arrive late or not at all. Your notifications here are always complete.</p>
        <!-- #212: the device, not ChitChat, decides where alerts appear and how long they stay. -->
        <details id="push-help" class="account-details push-help">
          <summary>How long alerts stay on screen</summary>
          <p>Your device shows alerts, so it decides where they appear and how long they stay. ChitChat can’t choose that. You can change it in your system’s notification settings, for example:</p>
          <ul>
            <li><strong>Windows:</strong> Settings<span aria-hidden="true"> ›</span><span class="visually-hidden">,</span> Accessibility<span aria-hidden="true"> ›</span><span class="visually-hidden">,</span> Visual effects<span aria-hidden="true"> ›</span><span class="visually-hidden">,</span> “Dismiss notifications after this amount of time”.</li>
            <li><strong>macOS:</strong> System Settings<span aria-hidden="true"> ›</span><span class="visually-hidden">,</span> Notifications<span aria-hidden="true"> ›</span><span class="visually-hidden">,</span> your browser. Choose “Persistent” (called “Alerts” in older versions) to keep alerts until you close them.</li>
            <li><strong>Android:</strong> alerts stay in the notification shade until you clear them.</li>
          </ul>
        </details>
      </div>

      <div id="push-devices" class="hidden">
        <h3>Devices with alerts on</h3>
        <ul id="push-device-list" class="push-device-list" aria-label="Devices with alerts on"></ul>
        <p id="push-device-empty" class="account-muted hidden">None yet.</p>
      </div>


      <fieldset class="push-sounds" data-member-only>
        <legend><h3>Sounds on this device</h3></legend>
        <p class="account-muted">Played while ChitChat is open in this browser.</p>
        <label><input type="checkbox" data-sound="ping" checked> Pings</label>
        <label><input type="checkbox" data-sound="mention" checked> Mentions</label>
        <label><input type="checkbox" data-sound="dm" checked> Direct messages</label>
      </fieldset>

      <p id="push-error" class="error-text" role="alert"></p>
    </section>

    <section class="account-card" aria-labelledby="appearance-heading" data-guest-visible>
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
          <label class="scheme-option" title="Lantern"><input type="radio" name="account-scheme" value="lantern" data-scheme-radio><span class="scheme-swatch" data-swatch="lantern" aria-hidden="true"></span><span class="scheme-name">Lantern</span></label>
          <label class="scheme-option" title="Lounge"><input type="radio" name="account-scheme" value="lounge" data-scheme-radio><span class="scheme-swatch" data-swatch="lounge" aria-hidden="true"></span><span class="scheme-name">Lounge</span></label>
          <label class="scheme-option" title="Dusk"><input type="radio" name="account-scheme" value="dusk" data-scheme-radio><span class="scheme-swatch" data-swatch="dusk" aria-hidden="true"></span><span class="scheme-name">Dusk</span></label>
          <label class="scheme-option" title="Ember"><input type="radio" name="account-scheme" value="ember" data-scheme-radio><span class="scheme-swatch" data-swatch="ember" aria-hidden="true"></span><span class="scheme-name">Ember</span></label>
          <label class="scheme-option" title="Rosé"><input type="radio" name="account-scheme" value="rose" data-scheme-radio><span class="scheme-swatch" data-swatch="rose" aria-hidden="true"></span><span class="scheme-name">Rosé</span></label>
          <label class="scheme-option" title="Midnight"><input type="radio" name="account-scheme" value="midnight" data-scheme-radio><span class="scheme-swatch" data-swatch="midnight" aria-hidden="true"></span><span class="scheme-name">Midnight</span></label>
          <label class="scheme-option" title="High contrast"><input type="radio" name="account-scheme" value="contrast" data-scheme-radio><span class="scheme-swatch" data-swatch="contrast" aria-hidden="true"></span><span class="scheme-name">High contrast</span></label>
        </div>
      </fieldset>
    </section>

    <section id="rules-card" class="account-card hidden" aria-labelledby="rules-heading">
      <p class="account-eyebrow">Community</p>
      <h2 id="rules-heading">Rules and privacy</h2>
      <p id="rules-status"></p>
      <p id="rules-pending" class="hidden"><button class="primary-button" type="button" data-rules-review>Review and agree</button></p>
      <p class="account-links">
        <a id="rules-card-rules" class="hidden" href="#">Read the community rules</a><span id="rules-card-separator" class="hidden" aria-hidden="true"> · </span><a id="rules-card-privacy" class="hidden" href="#">Privacy policy</a>
      </p>
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
        <h2 id="password-heading">Password and recovery</h2>
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
      <div id="recovery-row" class="recovery-row hidden">
        <h3 id="recovery-row-title">Recovery key</h3>
        <p id="recovery-row-status"></p>
        <div class="account-action-row">
          <button id="recovery-create" class="secondary-button hidden" type="button">Create recovery key</button>
        </div>
      </div>
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

    <!-- Members only (#205), shown when the server lists sign-ins (capabilities.signed_in_sessions). -->
    <section id="sessions-card" class="account-card hidden" aria-labelledby="sessions-heading">
      <div>
        <p class="account-eyebrow">Sign-in</p>
        <h2 id="sessions-heading" tabindex="-1">Where you’re signed in</h2>
      </div>
      <p>Browsers and apps where your account is signed in. A sign-in stays listed until it’s signed out, even while that device is off. Up to 50 sign-ins are kept; when there are more, the one used least recently ends.</p>
      <ul id="sessions-list" class="sessions-list"></ul>
      <p id="sessions-status" class="account-muted" role="status" aria-live="polite"></p>
      <div class="account-action-row"><button id="sessions-revoke-others" class="secondary-button" type="button" hidden>Sign out everywhere else</button></div>
      <p id="sessions-error" class="error-text" role="alert"></p>
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
    <!-- Sign out is at most two steps from any page: You, then this (S2b, #168). -->
    <section id="you-session" class="account-card you-session" aria-labelledby="you-session-heading" data-guest-visible>
      <h2 id="you-session-heading" class="visually-hidden">Session</h2>
      <button id="logout-button" class="secondary-button" type="button"><span data-member-only>Sign out</span><span data-guest-only hidden>Leave</span></button>
    </section>
    <?= \ChitChat\View\PoweredBy::html($config) ?>
  </main>
  <?= \ChitChat\View\AppShell::close() ?>

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
  <script type="module" src="/assets/js/account-recovery.js"></script>
  <script type="module" src="/assets/js/typing-settings.js"></script>
  <script type="module" src="/assets/js/account-rules.js"></script>
  <script type="module" src="/assets/js/push-notifications.js"></script>
  <script type="module" src="/assets/js/you.js"></script>
  <script type="module" src="/assets/js/sessions.js"></script>
  <script type="module" src="/assets/js/shell.js"></script>
</body>
</html>
