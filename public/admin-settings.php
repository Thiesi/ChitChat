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
  <meta name="description" content="<?= $appName ?> operational settings">
  <title>Operational settings · <?= $appName ?></title>
  <link rel="stylesheet" href="/assets/css/tokens.css">
  <link rel="stylesheet" href="/assets/css/app.css">
<?= \ChitChat\View\HeadAssets::html(\ChitChat\InstallationIcon\IconService::resolve($config)) ?>
  <link rel="stylesheet" href="/assets/css/components.css">
  <link rel="stylesheet" href="/assets/css/admin.css">
  <link rel="stylesheet" href="/assets/css/rules.css">
  <link rel="stylesheet" href="/assets/css/admin-icon.css">
</head>
<body>
  <div id="settings-loading" class="app-loading" role="status">Loading operational settings…</div>

  <main id="settings-shell" class="admin-shell settings-page hidden">
    <header class="admin-header">
      <div>
        <p class="admin-eyebrow"><?= $appName ?></p>
        <h1>Operational settings</h1>
        <p id="settings-identity" class="admin-muted"></p>
      </div>
      <a class="secondary-button admin-link-button" href="/admin.php">Back to administration</a>
    </header>

    <p class="admin-card warning-text">
      Retention policies control destructive maintenance. A retention value of <strong>0</strong> means permanent retention.
      Access-policy changes and destructive-retention changes require recent privileged authentication and are audited.
    </p>
    <p id="settings-error" class="error-text" role="alert"></p>

    <form id="lockdown-form" class="settings-grid">
      <section class="admin-card form-stack settings-wide lockdown-card" aria-labelledby="lockdown-heading">
        <h2 id="lockdown-heading">Maintenance lockdown</h2>
        <p id="lockdown-state" class="admin-muted" role="status"></p>
        <p class="admin-muted">
          While on, nobody can sign in, register, or restore an account, except Super-Administrators, who can always sign in to switch it off again.
          The message appears on the sign-in page and as a banner for everyone still signed in.
        </p>
        <div class="lockdown-fields">
          <label>Lockdown
            <select id="lockdown-enabled">
              <option value="0">Off</option>
              <option value="1">On</option>
            </select>
          </label>
          <label>Message <span class="optional-label">up to 500 characters; empty uses a default</span>
            <textarea id="lockdown-message" maxlength="500" rows="3"></textarea>
          </label>
        </div>
        <label id="lockdown-sign-out-label" class="check-row">
          <input id="lockdown-sign-out" type="checkbox">
          Also sign out everyone except Super-Administrators now
        </label>
        <button id="save-lockdown" class="secondary-button" type="submit">Save lockdown</button>
      </section>
    </form>

    <form id="settings-form" class="settings-grid">
      <section class="admin-card form-stack">
        <h2>Access</h2>
        <label>
          <span>Public registration</span>
          <select id="registration-enabled">
            <option value="1">Enabled</option>
            <option value="0">Disabled</option>
          </select>
        </label>
        <label>
          <span>Guest access</span>
          <select id="guest-access-enabled">
            <option value="0">Disabled</option>
            <option value="1">Enabled</option>
          </select>
        </label>
        <p class="admin-muted">
          Lets visitors look around without an account, in rooms that allow guests. Each visit is a numbered guest that ends after two idle hours or a day at most. Switching it off ends every visit.
        </p>
        <label>
          <span>Require MFA for administrative roles</span>
          <select id="admin-mfa-required">
            <option value="0">Disabled</option>
            <option value="1">Enabled</option>
          </select>
        </label>
        <p class="admin-muted">
          Applies to Super-Administrator, Administrator, Chat Administrator, and Global Moderator roles.
          Every currently assigned account must already have a passkey and an unused recovery code before this can be enabled.
        </p>
      </section>

      <section class="admin-card form-stack">
        <h2>Content retention</h2>
        <label>Room messages in days <span class="optional-label">0 keeps permanently</span>
          <input id="room-retention" type="number" min="0" max="3650" required>
        </label>
        <label>Direct messages in days <span class="optional-label">0 keeps permanently</span>
          <input id="dm-retention" type="number" min="0" max="3650" required>
        </label>
        <label>Audit entries in days <span class="optional-label">0 keeps permanently</span>
          <input id="audit-retention" type="number" min="0" max="3650" required>
        </label>
        <label>Deleted rooms restorable for days <span class="optional-label">then removed permanently; 0 keeps them until restored</span>
          <input id="deleted-room-grace" type="number" min="0" max="3650" required>
        </label>
      </section>

      <section class="admin-card form-stack">
        <h2>Attachment cleanup</h2>
        <label>Deleted attachment files in days <span class="optional-label">0 keeps permanently</span>
          <input id="deleted-attachment-retention" type="number" min="0" max="3650" required>
        </label>
        <label>Orphan grace period in hours
          <input id="orphan-grace" type="number" min="1" max="720" required>
        </label>
      </section>

      <section class="admin-card form-stack">
        <h2>Operational ledgers</h2>
        <label>Realtime event retention in hours
          <input id="event-retention" type="number" min="1" max="8760" required>
        </label>
        <label>Login attempt retention in days
          <input id="login-retention" type="number" min="1" max="3650" required>
        </label>
      </section>

      <section class="admin-card settings-apply" aria-label="Apply policy">
        <p id="settings-updated" class="admin-muted"></p>
        <button id="save-settings" class="danger-button" type="submit">Save operational settings</button>
      </section>
    </form>

    <div class="settings-pair">
    <form id="application-name-form">
      <section class="admin-card form-stack">
        <h2>Application name</h2>
        <p class="admin-muted">
          The name this installation shows people: page titles and headings, passkey prompts, push notifications,
          and exports. Leave it empty to use the server default.
        </p>
        <label>Application name <span id="app-name-default" class="optional-label"></span>
          <input id="app-name" type="text" maxlength="64" autocomplete="off">
        </label>
        <button id="save-application-name" class="secondary-button" type="submit">Save application name</button>
      </section>
    </form>

    <!-- #211: the installation's own icon. Previews come from the server; nothing is saved until "Use this icon". -->
    <section id="icon-settings" class="admin-card icon-settings" aria-labelledby="icon-heading">
      <h2 id="icon-heading">Installation icon</h2>
      <p class="admin-muted">
        Shown in browser tabs, on home screens and when people install this as an app. Use the ChitChat icon, or upload
        your own.
      </p>
      <p id="icon-unavailable" class="admin-muted hidden">Installation icon settings aren’t available on this server yet.</p>
      <div id="icon-load-error" class="icon-load-error hidden" role="alert">
        <p class="error-text">The icon settings couldn’t be loaded. Check your connection and try again.</p>
        <button id="icon-retry" class="secondary-button" type="button">Try again</button>
      </div>

      <div id="icon-body" class="icon-body hidden">
        <div class="icon-previews" id="icon-previews" data-mode="current">
          <p id="icon-preview-label" class="icon-preview-label">Current icon</p>
          <figure class="icon-figure">
            <div class="icon-mask icon-mask-circle" data-icon-mask>
              <img data-icon-variant="maskable" alt="" width="512" height="512">
              <span class="icon-safe-ring" aria-hidden="true"></span>
            </div>
            <figcaption>Circle</figcaption>
          </figure>
          <figure class="icon-figure">
            <div class="icon-mask icon-mask-rounded" data-icon-mask>
              <img data-icon-variant="maskable" alt="" width="512" height="512">
              <span class="icon-safe-ring" aria-hidden="true"></span>
            </div>
            <figcaption>Rounded square</figcaption>
          </figure>
          <figure class="icon-figure">
            <div class="icon-home">
              <img data-icon-variant="apple_touch" alt="" width="180" height="180">
              <span class="icon-home-name" data-icon-name></span>
            </div>
            <figcaption>Home screen</figcaption>
          </figure>
          <figure class="icon-figure icon-figure-tabs">
            <div class="icon-tab icon-tab-light"><img data-icon-variant="favicon" alt="" width="16" height="16"><span data-icon-name></span></div>
            <div class="icon-tab icon-tab-dark"><img data-icon-variant="favicon" alt="" width="16" height="16"><span data-icon-name></span></div>
            <figcaption>Browser tabs, actual size</figcaption>
          </figure>
        </div>
        <label class="check-row icon-ring-toggle">
          <input id="icon-safe-ring" type="checkbox">
          <span>Show the safe zone (the part every device keeps)</span>
        </label>
        <p id="icon-meta" class="admin-muted"></p>

        <form id="icon-form" class="icon-form">
          <label id="icon-file-label" for="icon-file">
            <span>Upload an image</span>
            <input id="icon-file" type="file" accept="image/png,image/webp" aria-describedby="icon-file-help">
          </label>
          <p id="icon-file-help" class="admin-muted"></p>

          <fieldset class="icon-background" aria-describedby="icon-background-help">
            <legend>Background</legend>
            <p id="icon-background-help" class="admin-muted">Fills the space around your icon on home screens and in round app icons.</p>
            <div class="icon-swatches">
              <label class="icon-swatch"><input type="radio" name="icon-background" value="#14111C"><span class="icon-swatch-chip" data-swatch="night" aria-hidden="true"></span>Night</label>
              <label class="icon-swatch"><input type="radio" name="icon-background" value="#FBF7F1"><span class="icon-swatch-chip" data-swatch="paper" aria-hidden="true"></span>Paper</label>
              <label class="icon-swatch"><input type="radio" name="icon-background" value="custom">Custom</label>
              <span class="icon-custom">
                <input id="icon-color" type="color" aria-label="Custom background colour" value="#14111C">
                <input id="icon-hex" type="text" inputmode="text" maxlength="7" spellcheck="false" autocomplete="off" aria-label="Custom background colour, as #RRGGBB" placeholder="#RRGGBB">
              </span>
            </div>
          </fieldset>

          <div class="icon-actions">
            <button id="icon-use" class="primary-button hidden" type="submit">Use this icon</button>
            <button id="icon-discard" class="secondary-button hidden" type="button">Choose another</button>
            <button id="icon-save-background" class="secondary-button hidden" type="button">Save background</button>
            <button id="icon-reset" class="secondary-button hidden" type="button">Use the ChitChat icon</button>
          </div>
          <p id="icon-status" class="admin-muted" role="status" aria-live="polite"></p>
          <p id="icon-error" class="error-text" role="alert"></p>
        </form>
      </div>
    </section>

    <section class="admin-card settings-wide policies-card" aria-labelledby="policies-heading">
      <h2 id="policies-heading">Rules and privacy</h2>
      <p class="admin-muted">
        Publish this installation's community rules and privacy policy. Write them here in Markdown, or link to a page you
        host elsewhere. Members agree to the rules when they register; the privacy policy is linked, never ticked.
      </p>
      <p id="policies-unavailable" class="admin-muted hidden">Rules and privacy settings aren't available on this server yet.</p>
<?php
$policyForms = [
    'rules' => [
        'title' => 'Community rules',
        'help' => 'Members agree to these when they register, and again when you mark a change as one they must agree to. Guests agree when they start a visit.',
        'path' => '/rules.php',
        'small' => 'Small fix (typo, wording): don’t ask anyone again',
        'material' => 'Members must agree again',
        'first' => 'Publishing asks every member to agree once.',
        'none' => 'Members will no longer be asked to agree to any rules.',
    ],
    'privacy' => [
        'title' => 'Privacy policy',
        'help' => 'Linked on the sign-in page, at registration and in every page footer.',
        'path' => '/privacy.php',
        'small' => 'Small fix (typo, wording): don’t tell anyone',
        'material' => 'Tell members about the change (a notification, nothing to agree to)',
        'first' => 'Publishing tells every member that a privacy policy is now available.',
        'none' => 'The privacy policy will no longer be linked anywhere.',
    ],
];
foreach ($policyForms as $kind => $form):
?>
      <form class="policy-form form-stack" data-policy="<?= $kind ?>" novalidate>
        <fieldset class="policy-source">
          <legend class="policy-title"><?= $form['title'] ?></legend>
          <p class="admin-muted"><?= $form['help'] ?></p>
          <div class="policy-source-options">
            <label><input type="radio" name="<?= $kind ?>-source" value="none"> None</label>
            <label><input type="radio" name="<?= $kind ?>-source" value="hosted"> Write it here</label>
            <label><input type="radio" name="<?= $kind ?>-source" value="external"> Link to another site</label>
          </div>
        </fieldset>
        <div class="policy-hosted hidden">
          <label for="<?= $kind ?>-markdown">Text <span class="optional-label">Markdown: headings, lists, links, bold and italic</span></label>
          <textarea id="<?= $kind ?>-markdown" class="policy-markdown" rows="14" spellcheck="true" aria-describedby="<?= $kind ?>-size"></textarea>
          <p id="<?= $kind ?>-size" class="policy-size admin-muted"></p>
          <div class="policy-preview-row">
            <button class="secondary-button" type="button" data-policy-preview aria-expanded="false" aria-controls="<?= $kind ?>-preview">Preview</button>
          </div>
          <div id="<?= $kind ?>-preview" class="policy-preview policy-content hidden" aria-live="polite" tabindex="0" aria-label="Preview of the <?= strtolower($form['title']) ?>"></div>
        </div>
        <div class="policy-external hidden">
          <label for="<?= $kind ?>-url">Address of the page</label>
          <input id="<?= $kind ?>-url" class="policy-url" type="url" inputmode="url" placeholder="https://example.org/<?= $kind ?>" autocomplete="off">
        </div>
        <p class="policy-meta admin-muted" data-path="<?= $form['path'] ?>"></p>
        <fieldset class="policy-change hidden">
          <legend>When you save a change</legend>
          <label><input type="radio" name="<?= $kind ?>-material" value="0" checked> <?= $form['small'] ?></label>
          <label><input type="radio" name="<?= $kind ?>-material" value="1"> <?= $form['material'] ?></label>
          <label class="policy-summary hidden">What changed <span class="optional-label">optional, shown to members</span>
            <input class="policy-summary-input" type="text" maxlength="200" autocomplete="off">
          </label>
        </fieldset>
        <p class="policy-first admin-muted hidden"><?= $form['first'] ?></p>
        <p class="policy-none-warning hidden" role="status"><?= $form['none'] ?></p>
        <p class="error-text" role="alert"></p>
        <button class="secondary-button" type="submit">Save <?= strtolower($form['title']) ?></button>
      </form>
<?php endforeach; ?>
      <form id="legal-notice-form" class="policy-form form-stack" novalidate>
        <label for="legal-notice-url" class="policy-title">Legal notice <span class="optional-label">optional link, for example an imprint</span></label>
        <input id="legal-notice-url" type="url" inputmode="url" placeholder="https://example.org/imprint" autocomplete="off">
        <p class="error-text" role="alert"></p>
        <button class="secondary-button" type="submit">Save legal notice link</button>
      </form>
    </section>

    <form id="registration-protection-form">
      <section class="admin-card form-stack settings-wide">
        <h2>Registration protection</h2>
        <p class="admin-muted">
          Limits automated sign-ups without puzzles for people: a per-IP attempt limit, a minimum time to fill in the form,
          and a small proof-of-work task the browser solves in the background. Leave a field empty to use the server default.
        </p>
        <label>Registration attempts per IP <span id="rp-max-attempts-default" class="optional-label"></span>
          <input id="rp-max-attempts" type="number" min="1" max="100">
        </label>
        <label>Attempt window in seconds <span id="rp-window-default" class="optional-label"></span>
          <input id="rp-window" type="number" min="60" max="86400">
        </label>
        <label>Minimum form fill time in seconds <span id="rp-min-fill-default" class="optional-label"></span>
          <input id="rp-min-fill" type="number" min="0" max="60">
        </label>
        <label>Proof-of-work difficulty in bits <span id="rp-pow-bits-default" class="optional-label"></span>
          <input id="rp-pow-bits" type="number" min="0" max="22">
        </label>
        <p class="admin-muted">
          0 disables the fill-time check or the proof of work. Each additional bit doubles the browser's work;
          16 bits takes about a second on a typical device.
        </p>
        <p id="rp-effective" class="admin-muted"></p>
        <button id="save-registration-protection" class="secondary-button" type="submit">Save registration protection</button>
      </section>
    </form>
    </div>
    <?= \ChitChat\View\PoweredBy::html($config) ?>
  </main>

  <div id="toast-region" class="toast-region" aria-live="assertive"></div>
  <script type="module" src="/assets/js/admin-settings.js"></script>
  <script type="module" src="/assets/js/admin-policies.js"></script>
  <script type="module" src="/assets/js/admin-icon.js"></script>
</body>
</html>
