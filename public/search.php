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
  <meta name="description" content="Search <?= $appName ?> messages">
  <title>Search messages · <?= $appName ?></title>
  <link rel="stylesheet" href="/assets/css/tokens.css">
  <link rel="stylesheet" href="/assets/css/app.css">
<?= \ChitChat\View\HeadAssets::html(\ChitChat\InstallationIcon\IconService::resolve($config)) ?>
  <link rel="stylesheet" href="/assets/css/components.css">
  <link rel="stylesheet" href="/assets/css/accessibility.css">
  <link rel="stylesheet" href="/assets/css/message-search.css">
  <link rel="stylesheet" href="/assets/css/shell.css">
</head>
<body>
  <div id="message-search-loading" class="app-loading" role="status">Loading message search…</div>

  <?= \ChitChat\View\AppShell::open('rooms', 'message-search-shell', config: $config) ?>
  <main id="message-search-shell" tabindex="-1" class="message-search-shell hidden">
    <header class="message-search-header">
      <div>
        <p class="message-search-eyebrow"><?= $appName ?></p>
        <h1>Search messages</h1>
        <p id="message-search-identity" class="message-search-muted"></p>
      </div>
      <nav class="message-search-actions" aria-label="Message navigation">
        <a class="secondary-button" href="/messages.php">Direct messages</a>
      </nav>
    </header>

    <!-- One line plus Learn more (S7 copy pass), like the direct-message privacy line. -->
    <details class="message-search-notice">
      <summary><span id="message-search-notice-heading">Search covers only messages you can see.</span> <span class="message-search-more">Learn more</span></summary>
      <p>Search includes current message bodies in rooms whose history you may discover and read, plus direct conversations in which you participate.</p>
      <p>Deleted messages and retained revision bodies are deliberately excluded. Search terms are sent in a protected request body rather than the address bar, are rate-limited, and are not written to the <?= $appName ?> audit log or aggregate metrics.</p>
    </details>

    <form id="message-search-form" class="message-search-form">
      <label class="message-search-query">
        Words or phrase
        <input id="message-search-query" name="query" type="search" minlength="2" maxlength="200" autocomplete="off" required>
      </label>
      <!-- Scope chips (S4d): today's scope values; changing one re-runs the search. -->
      <fieldset id="message-search-scope" class="search-scope">
        <legend>Search in</legend>
        <label class="search-scope-chip"><input type="radio" name="scope" value="all" checked> All</label>
        <label class="search-scope-chip"><input type="radio" name="scope" value="rooms"> Rooms</label>
        <label class="search-scope-chip"><input type="radio" name="scope" value="direct"> Direct</label>
      </fieldset>
      <!-- S4 (S7h): on narrow screens one select stands in for the chips (kept in step). -->
      <label class="search-scope-select-field">
        Search in
        <select id="message-search-scope-select">
          <option value="all">All</option>
          <option value="rooms">Rooms</option>
          <option value="direct">Direct</option>
        </select>
      </label>
      <!-- One room only (#318): stands in for the scope chips until it's cleared. -->
      <div id="message-search-room" class="search-room-filter" role="group" aria-labelledby="message-search-room-label" hidden>
        <span id="message-search-room-label" class="search-room-label">Search in</span>
        <span class="search-room-chip"><span id="message-search-room-name"></span><button id="message-search-room-clear" class="search-room-clear" type="button" aria-label="Search everywhere instead"><svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><line x1="6" y1="6" x2="18" y2="18"/><line x1="18" y1="6" x2="6" y2="18"/></svg></button></span>
      </div>
      <button id="message-search-submit" class="primary-button" type="submit">Search</button>
    </form>

    <p id="message-search-error" class="error-text" role="alert"></p>
    <p id="message-search-status" class="message-search-status" role="status" aria-live="polite">Enter at least two characters to search.</p>

    <section aria-labelledby="message-search-results-heading">
      <h2 id="message-search-results-heading" class="visually-hidden">Search results</h2>
      <ol id="message-search-results" class="message-search-results"></ol>
      <button id="message-search-more" class="secondary-button message-search-more hidden" type="button">Load more results</button>
    </section>
    <?= \ChitChat\View\PoweredBy::html($config) ?>
  </main>
  <?= \ChitChat\View\AppShell::close() ?>

  <script type="module" src="/assets/js/message-search.js"></script>
  <script type="module" src="/assets/js/shell.js"></script>
</body>
</html>
