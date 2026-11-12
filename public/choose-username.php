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
  <meta name="robots" content="noindex">
  <script src="/assets/js/theme.js"></script>
  <title>Choose a new username · <?= $appName ?></title>
  <link rel="stylesheet" href="/assets/css/tokens.css">
  <link rel="stylesheet" href="/assets/css/app.css">
<?= \ChitChat\View\HeadAssets::html(\ChitChat\InstallationIcon\IconService::resolve($config)) ?>
  <link rel="stylesheet" href="/assets/css/components.css">
  <link rel="stylesheet" href="/assets/css/accessibility.css">
  <link rel="stylesheet" href="/assets/css/auth.css">
</head>
<body>
  <!-- A moderator required a new username (#164): the onboarding card (S3),
       one field, and back to the conversation once it's chosen. -->
  <main class="auth-shell auth-shell-single">
    <?= \ChitChat\View\Lockup::html($config, 'choose-username-brand', horizontal: true) ?>
    <section class="auth-card" aria-labelledby="choose-username-title">
      <h2 id="choose-username-title" class="auth-greeting" tabindex="-1">Choose a new username</h2>
      <p id="choose-username-loading" role="status">Loading…</p>

      <div id="choose-username-required" class="form-stack hidden">
        <p class="auth-lead" id="choose-username-lead">A moderator replaced your username. Choose a new one to keep writing; you can still read.</p>
        <p id="choose-username-reason" class="auth-strip hidden"></p>
        <p id="choose-username-current" class="choose-username-current"></p>
        <form id="choose-username-form" class="form-stack" novalidate>
          <label for="choose-username-input">New username <span class="optional-label">3–32 letters, digits, dots, underscores or hyphens</span></label>
          <input id="choose-username-input" name="username" type="text" autocomplete="username" autocapitalize="none" spellcheck="false"
            minlength="3" maxlength="32" pattern="[A-Za-z0-9][A-Za-z0-9._\-]{2,31}" required aria-describedby="choose-username-error">
          <p id="choose-username-error" class="field-error hidden" role="alert"></p>
          <button id="choose-username-submit" class="primary-button" type="submit">Use this name</button>
        </form>
      </div>

      <div id="choose-username-done" class="form-stack hidden">
        <p id="choose-username-done-text"></p>
      </div>

      <a class="quiet-action" href="/">Back to the conversation</a>
    </section>
    <?= \ChitChat\View\PoweredBy::html($config) ?>
  </main>
  <script type="module" src="/assets/js/choose-username.js"></script>
</body>
</html>
