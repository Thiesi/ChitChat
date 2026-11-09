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
  <meta name="description" content="<?= $appName ?> moderation queue">
  <title>Moderation queue · <?= $appName ?></title>
  <link rel="stylesheet" href="/assets/css/tokens.css">
  <link rel="stylesheet" href="/assets/css/app.css">
<?= \ChitChat\View\HeadAssets::html(\ChitChat\InstallationIcon\IconService::resolve($config)) ?>
  <link rel="stylesheet" href="/assets/css/components.css">
  <link rel="stylesheet" href="/assets/css/accessibility.css">
  <link rel="stylesheet" href="/assets/css/moderation.css">
  <link rel="stylesheet" href="/assets/css/person-reports.css">
</head>
<body>
  <div id="moderation-loading" class="app-loading" role="status">Loading moderation queue…</div>

  <main id="moderation-shell" class="moderation-shell hidden">
    <header class="moderation-header">
      <div>
        <p class="moderation-eyebrow"><?= $appName ?></p>
        <h1>Moderation queue</h1>
        <p id="moderation-identity" class="moderation-muted"></p>
      </div>
      <a class="secondary-button" href="/admin.php">Back to administration</a>
    </header>

    <section class="moderation-notice" aria-labelledby="moderation-notice-title">
      <h2 id="moderation-notice-title">Participant-submitted evidence</h2>
      <p>Each report contains an immutable snapshot of one message as the reporter saw it, or of one person's name and profile picture. A direct-message report does not grant access to surrounding conversation history.</p>
      <p>Room owners and room moderators see only cases from rooms they moderate. Global moderation roles may review all room and direct-message cases.</p>
    </section>

    <p id="moderation-error" class="error-text" role="alert"></p>

    <section class="moderation-workspace">
      <aside class="moderation-queue" aria-labelledby="moderation-queue-title">
        <div class="moderation-queue-heading">
          <div>
            <h2 id="moderation-queue-title">Cases</h2>
            <p id="moderation-status" class="moderation-muted" role="status" aria-live="polite"></p>
          </div>
          <label>Status
            <select id="moderation-filter">
              <option value="open">Open</option>
              <option value="in_review">In review</option>
              <option value="resolved">Resolved</option>
              <option value="dismissed">Dismissed</option>
              <option value="all">All</option>
            </select>
          </label>
          <fieldset class="moderation-kind">
            <legend>Kind</legend>
            <label><input type="radio" name="moderation-kind" value="all" checked> All</label>
            <label><input type="radio" name="moderation-kind" value="message"> Messages</label>
            <label><input type="radio" name="moderation-kind" value="user"> People</label>
          </fieldset>
        </div>
        <div id="moderation-case-list" class="moderation-case-list"></div>
        <button id="moderation-more" class="secondary-button hidden" type="button">Load older cases</button>
      </aside>

      <section id="moderation-detail" class="moderation-detail" aria-labelledby="moderation-detail-title">
        <div id="moderation-empty" class="moderation-empty">Choose a case to inspect its submitted evidence.</div>
        <div id="moderation-case" class="hidden">
          <header class="moderation-detail-header">
            <div>
              <p id="moderation-case-context" class="moderation-eyebrow"></p>
              <h2 id="moderation-detail-title">Case</h2>
              <p id="moderation-case-meta" class="moderation-muted"></p>
            </div>
            <div class="action-row">
              <button id="moderation-claim" class="primary-button" type="button">Claim case</button>
              <button id="moderation-release" class="secondary-button hidden" type="button">Release case</button>
            </div>
          </header>

          <section id="moderation-person-evidence" class="moderation-person-evidence hidden" aria-labelledby="moderation-person-evidence-title">
            <h3 id="moderation-person-evidence-title">Evidence at first report</h3>
            <div class="person-evidence">
              <div id="person-evidence-picture" class="person-evidence-picture"></div>
              <dl class="person-evidence-facts">
                <dt>Username then</dt><dd id="person-evidence-then"></dd>
                <dt class="person-evidence-renamed hidden">Username now</dt><dd id="person-evidence-now" class="person-evidence-renamed hidden"></dd>
              </dl>
            </div>
            <p id="person-evidence-changed" class="moderation-muted hidden">The profile picture has changed since the report.</p>
          </section>

          <section aria-labelledby="moderation-reports-title">
            <h3 id="moderation-reports-title">Reports and evidence</h3>
            <div id="moderation-report-list" class="moderation-report-list"></div>
          </section>

          <section id="moderation-case-actions" class="moderation-case-actions hidden" aria-labelledby="moderation-actions-title">
            <h3 id="moderation-actions-title">Act on this report</h3>
            <p class="optional-label">Each action also closes the case with the matching outcome and the note below.</p>
            <div id="moderation-case-action-row" class="action-row"></div>
          </section>

          <form id="moderation-resolution-form" class="moderation-resolution form-stack">
            <h3>Close case</h3>
            <label>Outcome
              <select id="moderation-resolution-code">
                <option value="content_removed">Reported content removed</option>
                <option value="profile_picture_removed">Profile picture removed</option>
                <option value="user_warned">User warned</option>
                <option value="account_restricted">Account restricted</option>
                <option value="other">Other action</option>
              </select>
            </label>
            <label>Resolution note <span class="optional-label">required for “Other action”</span>
              <textarea id="moderation-resolution-note" maxlength="1000" rows="4"></textarea>
            </label>
            <div class="action-row">
              <button id="moderation-dismiss" class="secondary-button" type="button">Dismiss: no violation</button>
              <button id="moderation-resolve" class="primary-button" type="submit">Resolve case</button>
            </div>
          </form>

          <section id="moderation-closed" class="moderation-closed hidden" aria-labelledby="moderation-closed-title">
            <h3 id="moderation-closed-title">Case closed</h3>
            <p id="moderation-closed-summary"></p>
          </section>
        </div>
      </section>
    </section>
    <?= \ChitChat\View\PoweredBy::html($config) ?>
  </main>

  <div id="toast-region" class="toast-region" aria-live="assertive"></div>
  <script type="module" src="/assets/js/moderation.js"></script>
</body>
</html>
