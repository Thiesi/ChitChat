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
  <meta name="description" content="Restore a closing <?= $appName ?> account">
  <title>Restore account · <?= $appName ?></title>
  <link rel="stylesheet" href="/assets/css/tokens.css">
  <link rel="stylesheet" href="/assets/css/app.css">
<?= \ChitChat\View\HeadAssets::html(\ChitChat\InstallationIcon\IconService::resolve($config)) ?>
  <link rel="stylesheet" href="/assets/css/components.css">
  <link rel="stylesheet" href="/assets/css/accessibility.css">
  <link rel="stylesheet" href="/assets/css/totp.css">
  <link rel="stylesheet" href="/assets/css/auth.css">
</head>
<body>
  <main class="auth-shell auth-shell-single">
    <?= \ChitChat\View\Lockup::html($config, 'restore-brand', horizontal: true) ?>
    <section class="auth-card" aria-labelledby="restore-title">
      <h2 id="restore-title" class="auth-greeting">Restore your account</h2>
      <p class="auth-lead">
        A closure request can be reversed during its 14-day cooling-off period. Normal sign-in remains disabled until restoration succeeds.
      </p>
      <form id="restore-account-form" class="form-stack" autocomplete="on">
        <label>
          Username
          <input id="restore-username" name="username" type="text" autocomplete="username" minlength="3" maxlength="32" required>
        </label>
        <div class="password-field">
          <label for="restore-password">Current password</label>
          <div class="password-field-row">
            <input id="restore-password" name="password" type="password" autocomplete="current-password" minlength="12" maxlength="4096" required>
            <button class="password-toggle" type="button" data-reveal="restore-password" aria-controls="restore-password" aria-pressed="false" aria-label="Show password">
              <svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
        </div>
        <button class="primary-button" type="submit">Restore account</button>
      </form>
      <div id="restore-providers" class="sign-in-providers hidden">
        <p class="sign-in-divider"><span>or</span></p>
      </div>
      <p id="restore-account-status" role="status" aria-live="polite"></p>
      <p id="restore-account-error" class="error-text" role="alert"></p>
      <a class="quiet-action" href="/">Back to sign in</a>
    </section>
    <?= \ChitChat\View\PoweredBy::html($config) ?>
  </main>
  <script type="module" src="/assets/js/restore-account.js"></script>
  <script type="module" src="/assets/js/password-reveal.js"></script>
</body>
</html>
