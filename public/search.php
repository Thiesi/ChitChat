<?php

declare(strict_types=1);

/** @var ChitChat\Config $config */
$config = require dirname(__DIR__) . '/bootstrap/app.php';
$appName = htmlspecialchars($config->applicationName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="dark light">
  <meta name="description" content="Search <?= $appName ?> messages">
  <title>Search messages · <?= $appName ?></title>
  <link rel="stylesheet" href="/assets/css/app.css">
  <link rel="stylesheet" href="/assets/css/components.css">
  <link rel="stylesheet" href="/assets/css/accessibility.css">
  <link rel="stylesheet" href="/assets/css/message-search.css">
</head>
<body>
  <div id="message-search-loading" class="app-loading" role="status">Loading message search…</div>

  <main id="message-search-shell" class="message-search-shell hidden">
    <header class="message-search-header">
      <div>
        <p class="message-search-eyebrow"><?= $appName ?></p>
        <h1>Search messages</h1>
        <p id="message-search-identity" class="message-search-muted"></p>
      </div>
      <nav class="message-search-actions" aria-label="Message navigation">
        <a class="secondary-button" href="/messages.php">Direct messages</a>
        <a class="secondary-button" href="/">Back to chat</a>
      </nav>
    </header>

    <section class="message-search-notice" aria-labelledby="message-search-notice-heading">
      <h2 id="message-search-notice-heading">Your visible history only</h2>
      <p>Search includes current message bodies in rooms whose history you may discover and read, plus direct conversations in which you participate.</p>
      <p>Deleted messages and retained revision bodies are deliberately excluded. Search terms are sent in a protected request body rather than the address bar, are rate-limited, and are not written to the ChitChat audit log or aggregate metrics.</p>
    </section>

    <form id="message-search-form" class="message-search-form">
      <label class="message-search-query">
        Words or phrase
        <input id="message-search-query" name="query" type="search" minlength="2" maxlength="200" autocomplete="off" required>
      </label>
      <label>
        Search in
        <select id="message-search-scope" name="scope">
          <option value="all">Rooms and direct messages</option>
          <option value="rooms">Rooms only</option>
          <option value="direct">Direct messages only</option>
        </select>
      </label>
      <button id="message-search-submit" class="primary-button" type="submit">Search</button>
    </form>

    <p id="message-search-error" class="error-text" role="alert"></p>
    <p id="message-search-status" class="message-search-status" role="status" aria-live="polite">Enter at least two characters to search.</p>

    <section aria-labelledby="message-search-results-heading">
      <h2 id="message-search-results-heading" class="visually-hidden">Search results</h2>
      <ol id="message-search-results" class="message-search-results"></ol>
      <button id="message-search-more" class="secondary-button message-search-more hidden" type="button">Load more results</button>
    </section>
  </main>

  <script type="module" src="/assets/js/message-search.js"></script>
</body>
</html>
