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
        <a class="secondary-button admin-link-button" href="/moderation.php">Moderation queue</a>
        <a id="system-settings-link" class="secondary-button admin-link-button hidden" href="/admin-settings.php">Operational settings</a>
        <a id="dm-inspection-link" class="secondary-button admin-link-button hidden" href="/admin-messages.php">DM inspection</a>
        <a id="revision-review-link" class="secondary-button admin-link-button hidden" href="/admin-message-revisions.php">Revision review</a>
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
        <div class="inline-form room-heading-actions">
          <label class="room-picker-label">
            Room
            <select id="room-picker"></select>
          </label>
          <button id="room-create-open" class="primary-button hidden" type="button">Create room</button>
        </div>
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
          <label>Guests <span class="optional-label">public rooms without a minimum age only</span>
            <select id="admin-room-guest-access">
              <option value="none">Not allowed</option>
              <option value="read">Can read</option>
              <option value="write">Can read and write</option>
            </select>
          </label>
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

        <section class="admin-card danger-card" aria-labelledby="room-delete-heading">
          <h3 id="room-delete-heading">Delete this room</h3>
          <p class="admin-card-meta">Members lose access immediately and are notified. The room stays restorable under Deleted rooms until maintenance removes it permanently after the grace period set in Operational settings. Reports to moderators keep their evidence.</p>
          <button id="room-delete" class="danger-button" type="button">Delete room</button>
        </section>
      </div>

      <section id="deleted-rooms" class="admin-card deleted-rooms hidden" aria-labelledby="deleted-rooms-heading">
        <h3 id="deleted-rooms-heading">Deleted rooms</h3>
        <div id="deleted-room-list" class="admin-card-list compact"></div>
      </section>
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
    <?= \ChitChat\View\PoweredBy::html() ?>
  </main>

  <dialog id="room-create-dialog" class="room-dialog" aria-labelledby="room-create-title">
    <form id="room-create-form" class="form-stack">
      <header class="dialog-header">
        <h2 id="room-create-title">Create room</h2>
        <button id="room-create-cancel" class="icon-button" type="button" aria-label="Close">×</button>
      </header>
      <label>
        Room key <span class="optional-label">lowercase letters, numbers, - and _; can't be changed later</span>
        <input id="new-room-key" type="text" minlength="3" maxlength="48" pattern="[a-z0-9][a-z0-9_\-]{2,47}" placeholder="general" required>
      </label>
      <label>
        Name
        <input id="new-room-name" type="text" maxlength="120" placeholder="General" required>
      </label>
      <label>
        Description
        <input id="new-room-info" type="text" maxlength="255" placeholder="General discussion">
      </label>
      <label>
        Visibility
        <select id="new-room-visibility">
          <option value="public">Public</option>
          <option value="unlisted">Unlisted</option>
          <option value="private">Private, invitation only</option>
        </select>
      </label>
      <label>
        Minimum age
        <input id="new-room-age" type="number" min="0" max="120" value="0" required>
      </label>
      <label>
        Guests <span class="optional-label">public rooms without a minimum age only</span>
        <select id="new-room-guest-access">
          <option value="none">Not allowed</option>
          <option value="read">Can read</option>
          <option value="write">Can read and write</option>
        </select>
      </label>
      <label>
        Inactivity timeout in seconds <span class="optional-label">0 disables; minimum 120</span>
        <input id="new-room-timeout" type="number" min="0" max="86400" step="60" value="0" required>
      </label>
      <p id="room-create-error" class="error-text" role="alert"></p>
      <button class="primary-button" type="submit">Create room</button>
    </form>
  </dialog>

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
