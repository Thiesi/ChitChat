# ChitChat installation and operation

This document applies to stable `v1.1.0` of the clean reconstruction. The supported initial deployment model is one application server backed by PostgreSQL; review the privacy defaults, forward-only migrations, known limitations, backup procedure, and worker-capacity requirements before serving users.

## Requirements

- PHP 8.2 or newer
- Composer 2
- PostgreSQL 15 or newer
- PHP extensions: `pdo`, `pdo_pgsql`, `json`, `mbstring`, `fileinfo`
- Node.js 24 or newer only for CI-equivalent JavaScript and browser tests

The deployed browser client has no Node.js runtime dependency and uses no npm packages.

## Setup

1. Create a PostgreSQL database and account.
2. Copy the example environment file:

   ```sh
   cp .env.example .env
   ```

3. Adjust the database values in `.env`.
4. For plain HTTP local development, keep `SESSION_COOKIE_SECURE=0`. Production HTTPS deployments must use `SESSION_COOKIE_SECURE=1`.
5. Install dependencies:

   ```sh
   composer install
   ```

   For a production-like release installation:

   ```sh
   composer install --no-dev --classmap-authoritative
   ```

6. Apply the migrations:

   ```sh
   composer migrate
   ```

7. Ensure the attachment storage directory is writable by the PHP process. By default ChitChat uses `var/uploads`, which is outside the served `public/` tree:

   ```sh
   mkdir -p var/uploads
   chmod 700 var/uploads
   ```

8. Start PHP's built-in server with `public/` as the document root for local evaluation:

   ```sh
   PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:8080 -t public
   ```

9. Open `http://127.0.0.1:8080/`.

The first account created through the browser becomes Super-Administrator. That account can create the first room and use **Administration** for users, rooms, audit visibility, direct-message inspection policy, optional revision review, operational settings, and system status.

For a production-like single-server deployment, use Nginx and PHP-FPM as described in `docs/operations/nginx-php-fpm.md`; do not use PHP's development server.

## Upgrade from v1.0.0

`v1.1.0` applies forward-only migrations `0010` through `0013`. Do not run older ChitChat source against the database after migration.

1. Stop or drain application writes.
2. Back up PostgreSQL and the complete attachment-storage directory together.
3. Preserve `.env` and the configured attachment-storage path.
4. Deploy `v1.1.0` source.
5. Run:

   ```sh
   composer install --no-dev --classmap-authoritative
   composer migrate
   composer maintenance:dry-run
   ```

6. Review the new optional revision-review and Prometheus settings before enabling either feature.
7. Verify `/health.php`, `/ready.php`, login, retained room and direct-message history, attachment downloads, SSE through the production reverse proxy, account export, and the Administrator system-status page.

Rollback requires restoring a matching pre-upgrade PostgreSQL and attachment backup. There is no down-migration path.

## Authentication bootstrap

API clients begin by requesting:

```text
GET /api/v1/session.php
```

The response contains a `csrf_token`. Send that value in the `X-CSRF-Token` header for every state-changing request, including registration and login.

It also contains whether public registration is enabled, the direct-message privacy policy, the independently configured message-revision review policy, and current privileged step-up state under `security.privileged_step_up`. Browser clients must disclose that DMs are not end-to-end encrypted, state the active retention period, and state whether administrative inspection is enabled and for which role. The bundled DM interface also discloses that edits and deletions preserve historical bodies until message retention removes them.

The first successfully registered account is promoted to `super_admin` inside the same database transaction that creates it. Concurrent first registrations are serialized by locking the single system-settings row.

### Privileged step-up

Sensitive administrative actions require recent current-password verification in addition to session authentication, CSRF, role authorization, and action-specific audits.

```text
POST /api/v1/step-up.php
X-CSRF-Token: <session token>
Content-Type: application/json

{"password":"current account password"}
```

`PRIVILEGED_STEP_UP_MAX_AGE_SECONDS` defaults to `600` and accepts 60-3600 seconds. Elevation is bound to the current account and session version. It expires or is cleared after login rotation, logout, password changes, kicks, bans, administrator resets of the current account, and any other session invalidation.

The browser displays a current-password dialog after a protected endpoint returns `step_up_required`, verifies the password, and retries the original JSON POST exactly once. Incorrect passwords are audited and limited together with successful attempts to ten attempts per account and source IP in fifteen minutes.

This is password reauthentication, not MFA. It does not grant roles or bypass any content, target, policy, reason, or audit restriction.

## Endpoints

- `/` serves the browser chat client.
- `/messages.php` serves the direct-message inbox and its fixed privacy notice.
- `/account.php` serves the signed-in account and personal-data export page.
- `/admin.php` serves the permission-aware administration console.
- `/admin-messages.php` serves audited direct-message inspection for eligible administrators.
- `/admin-message-revisions.php` serves exact-ID, reason-required revision review when separately enabled.
- `/admin-settings.php` serves Super-Administrator operational settings.
- `/admin-status.php` serves aggregate system status for Administrators and Super-Administrators.
- `/health.php` reports whether the PHP process is alive.
- `/ready.php` verifies that the application can connect to PostgreSQL and use attachment storage.
- `/metrics.php` serves optional bearer-protected Prometheus metrics when explicitly enabled.
- `/api/v1/step-up.php` verifies the current password and creates short-lived privileged elevation.
- `/api/v1/account/export.php` creates a step-up-protected retained-data JSON export for the signed-in account.
- `/api/v1/events/stream.php` provides the authenticated SSE stream.
- `/api/v1/presence/heartbeat.php` renews a browser tab's presence lease.
- `/api/v1/rooms/presence.php` lists active users in an authorized room.
- `/api/v1/attachments/` contains upload, protected download, and bounded metadata endpoints.
- `/api/v1/direct-messages/` contains user search, conversation, history, blocking, mutation, attachment, send, and read-acknowledgement endpoints.
- `/api/v1/admin/direct-messages/` contains inspection user search and audited inspection endpoints.
- `/api/v1/admin/message-revisions/` contains the audited exact-message revision-review endpoint.
- `/api/v1/admin/settings/` contains Super-Administrator settings read and update endpoints.
- `/api/v1/admin/system-status.php` contains the Administrator status snapshot used by the browser page.
- API contracts are documented in `docs/api/`.

## Server-Sent Events

The SSE endpoint holds each connection for approximately 25 seconds and then lets the client reconnect with its last event ID. The reverse proxy timeout must therefore exceed 25 seconds.

Response buffering must be disabled for `/api/v1/events/stream.php`. The application sends `X-Accel-Buffering: no`, but the proxy configuration must also avoid buffering or caching the stream. The endpoint releases the PHP session lock before polling, allowing concurrent requests from the same login session.

Capacity planning must account for one PHP worker per currently open SSE request. The short reconnect window bounds worker lifetime but does not remove that concurrency requirement.

Each stream also maintains a leased row in `sse_connections`. `SSE_CONNECTION_LEASE_SECONDS` defaults to 40 and accepts 20-300 seconds. The stream refreshes the lease every ten seconds, deletes it on a clean close, and lets it expire after an unclean client, worker, or network failure. Maintenance removes expired rows.

CI verifies SSE delivery through real Nginx and PHP-FPM by opening an authenticated stream and requiring a room event to arrive before the stream closes. The tested reference configuration is documented in `docs/operations/nginx-php-fpm.md`.

## Presence leases

Each browser tab uses a distinct UUID and renews its lease every 20 seconds. `PRESENCE_LEASE_SECONDS` defaults to 45 and must remain greater than the browser renewal interval; supported values are 30-300 seconds. `INACTIVITY_WARNING_SECONDS` defaults to 60 and controls when the browser warns that a room's configured inactivity timeout is approaching.

Presence is distinct from room membership. Lease expiry, an unclean disconnect, or room inactivity removes the tab from the active-user list but does not remove the account's persistent room membership or room role.

## Administration, retention, and maintenance

User, audit, and system-status controls are visible only to Super-Administrators and Administrators. Room controls are visible to Super-Administrators, Administrators, Chat Admins, and owners of the selected room. Only Super-Administrators may change registration or retention policy.

The following operations additionally require active privileged step-up:

- global role replacement;
- administrator password reset;
- direct-message inspection POSTs;
- message-revision review POSTs;
- registration and retention-policy updates;
- personal-data export generation.

Coarse role and feature-policy checks occur before step-up, so an unauthorized account is denied without receiving a password prompt. Successful verification and the later protected action create separate audit records.

Room-message, direct-message, and audit retention default to `0`, meaning permanent retention. Nonzero policies become effective only when maintenance runs:

```sh
composer maintenance:dry-run
composer maintenance
```

The command also removes expired presence and SSE leases, old realtime events and throttle ledgers, deleted attachment files, and opaque orphan files older than their grace period. Every invocation is recorded with mode, status, duration, and result or failure information. A destructive run with attachment-file failures is recorded as a warning and does not count as a fresh clean success.

`MAINTENANCE_MAX_AGE_HOURS` defaults to 26 and controls the overdue state on `/admin-status.php` and in Prometheus metrics. Schedule maintenance as the same operating-system account that owns attachment storage. Ready-to-adapt `systemd` service and timer files are provided under `deploy/systemd/`. See `docs/operations/maintenance.md`.

Message revisions reference their canonical room or direct message with `ON DELETE CASCADE`. Soft deletion retains revisions; hard deletion by configured message retention removes the canonical message and revision chain together.

## Operational status and metrics

The Administrator status page reports aggregate application, PostgreSQL, attachment-storage, realtime, security-ledger, and maintenance information. It does not display usernames, message bodies, attachment names, IP addresses, credentials, or bearer secrets.

Prometheus output is disabled while `METRICS_BEARER_TOKEN` is empty. To enable it, configure a random token containing at least 24 characters and send it as:

```text
Authorization: Bearer <token>
```

Protect `/metrics.php` with HTTPS and reverse-proxy or network restrictions in addition to the application bearer token. See `docs/operations/observability.md` for the metric list, scrape configuration, privacy boundary, and alert suggestions.

## Attachments

`ATTACHMENT_STORAGE_PATH` defaults to the repository's absolute `var/uploads` directory. A custom value must be an absolute path outside `public/`. Files are stored with random extensionless keys in two-level shard directories and are never addressed directly by the web server.

`ATTACHMENT_MAX_BYTES` defaults to 10 MiB and accepts values from 1 KiB through 100 MiB. PHP and the reverse proxy must permit at least the same request size. Set `upload_max_filesize` and `post_max_size` at or above the ChitChat limit, with `post_max_size` slightly larger for multipart overhead.

The allowlist is JPEG, PNG, GIF, WebP, PDF, plain text, CSV, JSON, and ZIP. SVG, HTML, scripts, executables, and unknown binary types are rejected. Only raster image formats may be served inline.

Moderator or author deletion immediately revokes downloads. Physical files remain until the configured deleted-attachment retention expires. Failed upload transactions may leave opaque files; maintenance removes them after the orphan grace period.

## Direct messages, revisions, and privacy

Direct messages are ordinary server-side PostgreSQL records. They are **not end-to-end encrypted**. Retention is permanent by default and may be changed by a Super-Administrator; the inbox reads the active policy from the server.

`DM_ADMIN_INSPECTION_ENABLED` defaults to `1`. Set it to `0` to disable administrative canonical-content access. `DM_ADMIN_INSPECTION_ROLE` defaults to `super_admin`; `admin` permits both Administrators and Super-Administrators. Chat Admins, Global Moderators and room owners never receive DM inspection through these settings.

Historical message revision review is independent from canonical DM inspection. `MESSAGE_REVISION_REVIEW_ENABLED` defaults to `0`; enabling it still requires `super_admin` unless `MESSAGE_REVISION_REVIEW_ROLE=admin` is explicitly configured. Every successful review requires recent step-up and a fresh reason, is limited to an exact message ID with retained revisions, and is audited without copying historical bodies into audit metadata. ChitChat does not automatically notify participants when review occurs.

A direct-message block in either direction prevents new text and attachment sends while preserving retained history. The public relationship response reveals whether the current user created a block and only a generic messaging-availability flag; it does not identify whether the other participant blocked the account.

Personal-data exports are machine-readable snapshots of retained data associated with the signed-in account. They require recent step-up, are rate-limited and audited, and deliberately exclude credentials, session state, attachment bytes and storage keys, incoming block identities, hidden revisions authored by other users, and audit entries belonging only to another actor.
