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
  <title>You · <?= $appName ?></title>
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
  <link rel="stylesheet" href="/assets/css/totp.css">
  <link rel="stylesheet" href="/assets/css/shell.css">
  <link rel="stylesheet" href="/assets/css/panes.css">
  <link rel="stylesheet" href="/assets/css/you.css">
</head>
<body>
  <div id="account-loading" class="app-loading" role="status">Loading account…</div>

  <?= \ChitChat\View\AppShell::open('you', 'account-shell', config: $config) ?>
  <!-- You (S5a, #171): the sections in the list pane, the chosen one in the
       main pane; on phones the list comes first and a section pushes over it
       (panes.js). Sign out is the list's last row: two steps from anywhere. -->
  <div id="you-frame" class="you-frame" data-panes data-list="you-list" data-toggle="you-list-toggle" data-backdrop="you-backdrop" data-title="you-section-title" data-list-name="You" data-deep-link-hash>
    <section id="you-list" class="list-pane you-list hidden" aria-labelledby="you-list-title">
      <header class="list-pane-header">
        <div class="list-pane-title">
          <?= \ChitChat\View\AppShell::brand($config, 'list-pane-brand') ?>
          <h1 id="you-list-title" class="list-title">You</h1>
        </div>
      </header>
      <div class="you-person">
        <span id="you-avatar" class="you-avatar" aria-hidden="true"></span>
        <div class="you-person-text">
          <p id="account-identity" class="you-name"></p>
          <!-- Its line is kept from the start, so the rows below never move when the date arrives. -->
          <p id="you-member-since" class="list-pane-status you-member-since"></p>
        </div>
      </div>
      <section id="you-guest-card" class="account-card you-guest-card" aria-labelledby="you-guest-heading" data-guest-visible data-guest-only hidden>
        <h3 id="you-guest-heading">Want to stay?</h3>
        <p>With a free account you can message people, mention them, get alerts and keep your settings. Nothing from this visit is kept after you leave.</p>
        <div class="account-action-row"><a class="primary-button" href="/?register=1">Create an account</a></div>
      </section>
      <nav class="you-nav" aria-label="Settings">
        <ul class="you-sections">
          <!-- Staff only; shell.js reveals it with the same destination as the rail's Stewarding item.
               On phones, where the bottom bar has no Stewarding, this is the way in. -->
          <li id="you-steward-card" class="you-steward hidden"><a id="you-steward-link" class="you-section-link you-steward-link" href="/admin.php">Looking after this server</a></li>
          <li data-member-only><a class="you-section-link" href="#profile" data-section="profile">Profile</a></li>
          <li data-member-only><a class="you-section-link" href="#alerts" data-section="alerts">Alerts</a></li>
          <li><a class="you-section-link" href="#appearance" data-section="appearance">Appearance</a></li>
          <li data-member-only><a class="you-section-link" href="#security" data-section="security">Sign-in and security</a></li>
          <li data-member-only><a class="you-section-link" href="#privacy" data-section="privacy">Privacy and data</a></li>
          <li data-member-only><a class="you-section-link" href="#account" data-section="account">Account</a></li>
        </ul>
      </nav>
      <div id="you-session" class="you-session">
        <button id="logout-button" class="secondary-button" type="button"><span data-member-only>Sign out</span><span data-guest-only hidden>Leave</span></button>
      </div>
    </section>
    <div id="you-backdrop" class="drawer-backdrop" aria-hidden="true"></div>

    <main id="account-shell" tabindex="-1" class="account-shell you-main hidden" aria-labelledby="you-section-title">
      <header class="you-main-header">
        <!-- Phones: back to the list of sections. -->
        <button id="you-list-toggle" class="header-button drawer-toggle" type="button" aria-label="You" aria-controls="you-list">
          <svg class="toggle-list" aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
          <svg class="toggle-back" aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
        </button>
        <h2 id="you-section-title" class="you-section-title" tabindex="-1">You</h2>
      </header>

      <div id="you-section-profile" class="you-section" data-section="profile" hidden>
        <section class="account-card you-profile-card" data-member-only aria-labelledby="you-profile-heading">
          <h3 id="you-profile-heading">Name</h3>
          <dl class="you-facts">
            <div><dt>Username</dt><dd id="you-username"></dd></div>
            <div><dt>Member since</dt><dd id="you-member-since-full">—</dd></div>
          </dl>
          <!-- #164: a moderator required a new username (session.user.username_change). -->
          <p id="you-username-change" class="you-notice" hidden><span>A moderator asked you to choose a new username.</span> <a class="secondary-button" href="/choose-username.php">Choose a new username</a></p>
        </section>

        <section class="account-card" data-member-only aria-labelledby="avatar-heading">
          <div>
            <h3 id="avatar-heading">Profile picture</h3>
          </div>
          <div class="avatar-settings">
            <span id="avatar-preview" class="mini-avatar avatar-preview" aria-hidden="true"></span>
            <div class="avatar-settings-text">
              <p>Shown instead of your initials, next to your messages and on your profile card. JPEG, PNG or WebP, up to 5 MB.</p>
              <details class="you-more">
                <summary>Learn more<span class="visually-hidden"> about your picture</span></summary>
                <p>You choose the crop. The server keeps a fresh 256 × 256 copy without any photo metadata, such as location.</p>
              </details>
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
      </div>

      <div id="you-section-alerts" class="you-section" data-section="alerts" hidden>
        <section id="alerts-card" class="account-card" aria-label="Alerts" data-member-only>
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
      </div>

      <div id="you-section-appearance" class="you-section" data-section="appearance" data-guest-visible hidden>
        <section class="account-card" aria-labelledby="appearance-heading" data-guest-visible>
          <div>
            <h3 id="appearance-heading">Theme and colours</h3>
          </div>
          <p>Every scheme has a light and a dark variant. Saved on this device.</p>
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
              <label class="scheme-option" title="Midnight, ChitChat web’s own"><input type="radio" name="account-scheme" value="midnight" data-scheme-radio><span class="scheme-swatch" data-swatch="midnight" aria-hidden="true"></span><span class="scheme-name">Midnight<span class="scheme-note">, ChitChat web’s own</span></span></label>
              <label class="scheme-option" title="High contrast"><input type="radio" name="account-scheme" value="contrast" data-scheme-radio><span class="scheme-swatch" data-swatch="contrast" aria-hidden="true"></span><span class="scheme-name">High contrast</span></label>
            </div>
          </fieldset>
        </section>

        <section class="account-card" aria-labelledby="date-time-heading">
          <div>
            <h3 id="date-time-heading">How dates and times look</h3>
          </div>
          <p>Automatic follows your browser’s language.</p>
          <details class="you-more">
            <summary>Learn more<span class="visually-hidden"> about date and time formats</span></summary>
            <p>A region you choose applies wherever you sign in. Times always use this device’s time zone.</p>
          </details>
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
            <h3 id="typing-heading">Typing indicator</h3>
          </div>
          <p>“Alex is typing…”, above the message box. It never shows what you write.</p>
          <label>
            <input id="share-typing" type="checkbox" role="switch" checked>
            Show when I’m typing, and when others are
          </label>
          <p id="share-typing-status" class="account-muted" role="status" aria-live="polite"></p>
        </section>

        <!-- Screen readers (#166): what the page announces as messages arrive. Device-only; announcer.js reads it. -->
        <section class="account-card" aria-labelledby="accessibility-heading" data-guest-visible>
          <div>
            <h3 id="accessibility-heading">Accessibility</h3>
          </div>
          <fieldset id="announce-choice" class="announce-fieldset" role="radiogroup">
            <legend>Announce new messages</legend>
            <label class="announce-option"><input type="radio" name="announce" value="all" checked> All</label>
            <label class="announce-option"><input type="radio" name="announce" value="to-me" aria-describedby="announce-to-me-help"> Only messages to me</label>
            <label class="announce-option"><input type="radio" name="announce" value="off"> Off</label>
            <p id="announce-to-me-help" class="account-muted announce-help">Mentions, pings and direct messages. Saved on this device.</p>
          </fieldset>
        </section>
      </div>

      <div id="you-section-security" class="you-section" data-section="security" hidden>
        <section id="password-card" class="account-card" aria-labelledby="password-heading">
          <div>
            <h3 id="password-heading">Password and recovery</h3>
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
            <h4 id="recovery-row-title">Recovery key</h4>
            <p id="recovery-row-status"></p>
            <div class="account-action-row">
              <button id="recovery-create" class="secondary-button hidden" type="button">Create recovery key</button>
            </div>
          </div>
        </section>

        <section id="sign-in-methods" class="account-card hidden" aria-labelledby="sign-in-methods-heading">
          <div>
            <h3 id="sign-in-methods-heading">Sign-in methods</h3>
          </div>
          <p>Sign in with Google or Twitch instead of your password.</p>
          <details class="you-more">
            <summary>Learn more<span class="visually-hidden"> about sign-in methods</span></summary>
            <p>ChitChat only receives an account number from the provider: no email address, name or picture.</p>
          </details>
          <ul id="sign-in-method-list" class="sign-in-method-list"></ul>
          <p id="sign-in-methods-status" class="account-muted" role="status" aria-live="polite"></p>
        </section>

        <!-- mfa-account.js puts Passkeys and recovery codes here (with the authenticator app). -->
        <div id="mfa-card-slot"></div>

        <!-- Members only (#205), shown when the server lists sign-ins (capabilities.signed_in_sessions). -->
        <section id="sessions-card" class="account-card hidden" aria-labelledby="sessions-heading">
          <div>
            <h3 id="sessions-heading" tabindex="-1">Where you’re signed in</h3>
          </div>
          <p>Browsers and apps signed in to your account.</p>
          <details class="you-more">
            <summary>Learn more<span class="visually-hidden"> about sign-ins</span></summary>
            <p>A sign-in stays listed until it’s signed out, even while that device is off. Up to 50 sign-ins are kept; when there are more, the one used least recently ends.</p>
          </details>
          <ul id="sessions-list" class="sessions-list"></ul>
          <p id="sessions-status" class="account-muted" role="status" aria-live="polite"></p>
          <div class="account-action-row"><button id="sessions-revoke-others" class="secondary-button" type="button" hidden>Sign out everywhere else</button></div>
          <p id="sessions-error" class="error-text" role="alert"></p>
        </section>
      </div>

      <div id="you-section-privacy" class="you-section" data-section="privacy" hidden>
        <section id="rules-card" class="account-card hidden" aria-labelledby="rules-heading">
          <h3 id="rules-heading">Rules and privacy</h3>
          <p id="rules-status"></p>
          <p id="rules-pending" class="hidden"><button class="primary-button" type="button" data-rules-review>Review and agree</button></p>
          <p class="account-links">
            <a id="rules-card-rules" class="hidden" href="#">Read the community rules</a><span id="rules-card-separator" class="hidden" aria-hidden="true"> · </span><a id="rules-card-privacy" class="hidden" href="#">Privacy policy</a>
          </p>
        </section>

        <section class="account-card" aria-labelledby="personal-data-heading">
          <div>
            <h3 id="personal-data-heading">Download your personal data</h3>
          </div>

          <p>A JSON file of your account and the data kept about you. You confirm it’s you first.</p>

          <details class="you-more">
            <summary>What the export contains</summary>
            <p>Your profile, roles, ban history, rooms and memberships, messages you wrote, direct messages you can see, attachment details (not the files), blocks you made, sign-in history, and actions recorded as yours.</p>
            <p>Not included: password hashes, session secrets, attachment files, internal storage keys, who blocked you, or earlier versions of other people’s messages. Each export is recorded in the audit log.</p>
          </details>

          <div class="account-action-row">
            <button id="personal-data-export" class="primary-button" type="button">Download JSON export</button>
            <p id="personal-data-status" class="account-muted" role="status" aria-live="polite"></p>
          </div>
        </section>
      </div>

      <div id="you-section-account" class="you-section" data-section="account" hidden>
        <section class="account-card" aria-labelledby="account-closure-heading">
          <div>
            <h3 id="account-closure-heading">Close your account</h3>
          </div>
          <p>You’re signed out everywhere at once. For 14 days you can restore the account with your username and password.</p>
          <details class="you-more">
            <summary>Learn more<span class="visually-hidden"> about closing your account</span></summary>
            <p>After 14 days, your username, password and birth date are removed for good. Until then, nobody else can take your username.</p>
            <p>What you shared stays for as long as this server’s retention rules say: room and direct-message history, edits, attachments, rooms you own and audit records. It shows under a closed-account name, so conversations aren’t rewritten.</p>
          </details>
          <label>
            <input id="account-closure-confirm" type="checkbox">
            I understand that I’m signed out at once, and must restore the account within 14 days to keep it.
          </label>
          <div class="account-action-row">
            <button id="account-closure-request" class="danger-button" type="button" disabled>Request account closure</button>
            <p id="account-closure-status" class="account-muted" role="status" aria-live="polite"></p>
          </div>
        </section>
      </div>

      <p id="account-error" class="error-text" role="alert"></p>
      <?= \ChitChat\View\PoweredBy::html($config) ?>
    </main>
  </div>
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
  <script type="module" src="/assets/js/panes.js"></script>
  <script type="module" src="/assets/js/sessions.js"></script>
  <script type="module" src="/assets/js/shell.js"></script>
</body>
</html>
