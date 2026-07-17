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
  <meta name="description" content="<?= $appName ?> administration console">
  <title>Administration · <?= $appName ?></title>
  <link rel="stylesheet" href="/assets/css/app.css">
  <link rel="stylesheet" href="/assets/css/components.css">
  <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body>
  <div id="admin-loading" class="app-loading" role="status">Loading administration…</div>

  <main id="admin-shell" class="admin-shell hidden">
    <header class="admin-header">
      <div>
        <p class="admin-eyebrow"><?= $appName ?></p>
        <h1>Administration</h1>
        <p id="admin-identity" class="admin-muted"></p>
      </div>
      <div class="action-row">
        <a id="dm-inspection-link" class="secondary-button admin-link-button hidden" href="/admin-messages.php">DM inspection</a>
        <a class="secondary-button admin-link-button" href="/">Back to chat</a>
      </div>
    </header>

    <nav id="admin-tabs" class="admin-tabs" aria-label="Administration areas">
      <button id="users-tab" type="button" data-panel="users-panel">Users</button>
      <button id="rooms-tab" type="button" data-panel="rooms-panel">Rooms</button>
      <button id="audit-tab" type="button" data-panel="audit-panel">Audit</button>
    </nav>

    <p id="admin-error" class="error-text" role="alert"></p>

    <section id="users-panel" class="admin-panel hidden" aria-labelledby="users-tab">
      <div class="panel-heading">
        <div>
          <h2>User administration</h2>
          <p>Roles and account-control actions invalidate active sessions immediately.</p>
        </div>
        <form id="user-search-form" class="inline-form">
          <label>
            <span class="visually-hidden">Search usernames</span>
            <input id="user-search" type="search" maxlength="32" placeholder="Username prefix">
          </label>
          <button class="secondary-button" type="submit">Search</button>
        </form>
      </div>
      <div id="user-list" class="admin-card-list"></div>
      <button id="users-more" class="secondary-button hidden" type="button">Load more users</button>
    </section>

    <section id="rooms-panel" class="admin-panel hidden" aria-labelledby="rooms-tab">
      <div class="panel-heading">
        <div>
          <h2>Room administration</h2>
          <p>Edit settings, manage members, and control pending invitations.</p>
        </div>
        <label class="room-picker-label">
          Room
          <select id="room-picker"></select>
        </label>
      </div>

      <div id="room-admin-empty" class="admin-empty hidden">No manageable rooms are available.</div>
      <div id="room-admin-content" class="room-admin-grid hidden">
        <form id="room-settings-form" class="admin-card form-stack">
          <h3>Settings</h3>
          <label>Name <input id="admin-room-name" maxlength="120" required></label>
          <label>Description <input id="admin-room-info" maxlength="255"></label>
          <label>Visibility
            <select id="admin-room-visibility">
              <option value="public">Public</option>
              <option value="unlisted">Unlisted</option>
              <option value="private">Private, invitation only</option>
            </select>
          </label>
          <label>Minimum age <input id="admin-room-age" type="number" min="0" max="120" required></label>
          <label>Inactivity timeout in seconds <input id="admin-room-timeout" type="number" min="0" max="86400" required></label>
          <button class="primary-button" type="submit">Save room settings</button>
        </form>

        <section class="admin-card">
          <h3>Members</h3>
          <div id="room-member-list" class="admin-card-list compact"></div>
        </section>

        <section class="admin-card">
          <h3>Invite a user</h3>
          <form id="invitation-search-form" class="inline-form">
            <label>
              <span class="visually-hidden">Search invite candidates</span>
              <input id="invitation-search" minlength="2" maxlength="32" placeholder="Username prefix" required>
            </label>
            <button class="secondary-button" type="submit">Search</button>
          </form>
          <div id="invitation-search-results" class="admin-card-list compact"></div>
          <h3>Pending invitations</h3>
          <div id="room-invitation-list" class="admin-card-list compact"></div>
        </section>
      </div>
    </section>

    <section id="audit-panel" class="admin-panel hidden" aria-labelledby="audit-tab">
      <div class="panel-heading">
        <div>
          <h2>Audit log</h2>
          <p>Newest entries appear first. Metadata is shown exactly as recorded by the server.</p>
        </div>
      </div>
      <div id="audit-list" class="audit-list"></div>
      <button id="audit-more" class="secondary-button hidden" type="button">Load older entries</button>
    </section>
  </main>

  <dialog id="user-dialog" class="room-dialog admin-user-dialog">
    <form id="user-admin-form" class="form-stack" method="dialog">
      <header class="dialog-header">
        <div>
          <h2 id="user-dialog-title">Manage user</h2>
          <p id="user-dialog-status" class="admin-muted"></p>
        </div>
        <button id="user-dialog-close" class="icon-button" type="button" aria-label="Close">×</button>
      </header>

      <fieldset id="global-role-fieldset" class="role-fieldset">
        <legend>Global roles</legend>
        <label><input type="checkbox" name="role" value="super_admin"> Super-Administrator</label>
        <label><input type="checkbox" name="role" value="admin"> Administrator</label>
        <label><input type="checkbox" name="role" value="chat_admin"> Chat Admin</label>
        <label><input type="checkbox" name="role" value="global_moderator"> Global Moderator</label>
      </fieldset>
      <button id="save-global-roles" class="primary-button" type="button">Save roles</button>

      <hr>
      <label>Reason <input id="moderation-reason" maxlength="500" placeholder="Optional for kick; recommended for bans"></label>
      <label>Ban expiry <span class="optional-label">optional ISO date/time</span>
        <input id="ban-expiry" type="datetime-local">
      </label>
      <div class="action-row">
        <button id="kick-user" class="secondary-button" type="button">Kick</button>
        <button id="ban-user" class="danger-button" type="button">Ban</button>
        <button id="unban-user" class="secondary-button" type="button">Unban</button>
      </div>

      <label>New password <input id="admin-new-password" type="password" minlength="12" maxlength="4096" autocomplete="new-password"></label>
      <button id="reset-user-password" class="danger-button" type="button">Reset password</button>
    </form>
  </dialog>

  <div id="toast-region" class="toast-region" aria-live="assertive"></div>
  <script type="module" src="/assets/js/admin.js"></script>
  <script type="module" src="/assets/js/admin-dm-link.js"></script>
</body>
</html>
