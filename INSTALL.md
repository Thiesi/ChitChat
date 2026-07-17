# ChitChat installation and evaluation

This document applies to the clean v1 reconstruction. `v1.0.0-rc.1` is a release candidate intended for controlled evaluation and deployment rehearsal; compatibility changes may still occur before stable `1.0.0`.

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

The first account created through the browser becomes Super-Administrator. That account can create the first room and use **Administration** for users, rooms, audit visibility, direct-message inspection policy, and operational settings.

For a production-like single-server deployment, use Nginx and PHP-FPM as described in `docs/operations/nginx-php-fpm.md`; do not use PHP's development server.

## Authentication bootstrap

API clients begin by requesting:

```text
GET /api/v1/session.php
```

The response contains a `csrf_token`. Send that value in the `X-CSRF-Token` header for every state-changing request, including registration and login.

It also contains whether public registration is enabled and the direct-message privacy policy. Browser clients must disclose that DMs are not end-to-end encrypted, state the active retention period, and state whether administrative inspection is enabled and for which role.

The first successfully registered account is promoted to `super_admin` inside the same database transaction that creates it. Concurrent first registrations are serialized by locking the single system-settings row.

## Endpoints

- `/` serves the browser chat client.
- `/messages.php` serves the direct-message inbox and its fixed privacy notice.
- `/admin.php` serves the permission-aware administration console.
- `/admin-messages.php` serves audited direct-message inspection for eligible administrators.
- `/admin-settings.php` serves Super-Administrator operational settings.
- `/health.php` reports whether the PHP process is alive.
- `/ready.php` verifies that the application can connect to PostgreSQL and use attachment storage.
- `/api/v1/events/stream.php` provides the authenticated SSE stream.
- `/api/v1/presence/heartbeat.php` renews a browser tab's presence lease.
- `/api/v1/rooms/presence.php` lists active users in an authorized room.
- `/api/v1/attachments/` contains upload, protected download, and bounded metadata endpoints.
- `/api/v1/direct-messages/` contains user search, conversation, history, send and read-acknowledgement endpoints.
- `/api/v1/admin/direct-messages/` contains inspection user search and audited inspection endpoints.
- `/api/v1/admin/settings/` contains Super-Administrator settings read and update endpoints.
- API contracts are documented in `docs/api/`.

## Server-Sent Events

The SSE endpoint holds each connection for approximately 25 seconds and then lets the client reconnect with its last event ID. The reverse proxy timeout must therefore exceed 25 seconds.

Response buffering must be disabled for `/api/v1/events/stream.php`. The application sends `X-Accel-Buffering: no`, but the proxy configuration must also avoid buffering or caching the stream. The endpoint releases the PHP session lock before polling, allowing concurrent requests from the same login session.

Capacity planning must account for one PHP worker per currently open SSE request. The short reconnect window bounds worker lifetime but does not remove that concurrency requirement.

CI verifies this through real Nginx and PHP-FPM by opening an authenticated stream and requiring a room event to arrive before the stream closes. The tested reference configuration is documented in `docs/operations/nginx-php-fpm.md`.

## Presence leases

Each browser tab uses a distinct UUID and renews its lease every 20 seconds. `PRESENCE_LEASE_SECONDS` defaults to 45 and must remain greater than the browser renewal interval; supported values are 30-300 seconds. `INACTIVITY_WARNING_SECONDS` defaults to 60 and controls when the browser warns that a room's configured inactivity timeout is approaching.

Presence is distinct from room membership. Lease expiry, an unclean disconnect, or room inactivity removes the tab from the active-user list but does not remove the account's persistent room membership or room role.

## Administration and retention

User and audit controls are visible only to Super-Administrators and Administrators. Room controls are visible to Super-Administrators, Administrators, Chat Admins, and owners of the selected room. Only Super-Administrators may change registration or retention policy.

Room-message, direct-message, and audit retention default to `0`, meaning permanent retention. Nonzero policies become effective only when maintenance runs:

```sh
composer maintenance:dry-run
composer maintenance
```

The command also removes expired presence, old realtime events and throttle ledgers, deleted attachment files, and opaque orphan files older than their grace period. Schedule it as the same operating-system account that owns attachment storage. See `docs/operations/maintenance.md`.

## Attachments

`ATTACHMENT_STORAGE_PATH` defaults to the repository's absolute `var/uploads` directory. A custom value must be an absolute path outside `public/`. Files are stored with random extensionless keys in two-level shard directories and are never addressed directly by the web server.

`ATTACHMENT_MAX_BYTES` defaults to 10 MiB and accepts values from 1 KiB through 100 MiB. PHP and the reverse proxy must permit at least the same request size. Set `upload_max_filesize` and `post_max_size` at or above the ChitChat limit, with `post_max_size` slightly larger for multipart overhead.

The allowlist is JPEG, PNG, GIF, WebP, PDF, plain text, CSV, JSON, and ZIP. SVG, HTML, scripts, executables, and unknown binary types are rejected. Only raster image formats may be served inline.

Moderator deletion immediately revokes downloads. Physical files remain until the configured deleted-attachment retention expires. Failed upload transactions may leave opaque files; maintenance removes them after the orphan grace period.

## Direct messages and privacy

Direct messages are ordinary server-side PostgreSQL records. They are **not end-to-end encrypted**. Retention is permanent by default and may be changed by a Super-Administrator; the inbox reads the active policy from the server.

`DM_ADMIN_INSPECTION_ENABLED` defaults to `1`. Set it to `0` to disable administrative content access entirely. `DM_ADMIN_INSPECTION_ROLE` defaults to `super_admin`; `admin` permits both Administrators and Super-Administrators. Chat Admins, Global Moderators and room owners never receive DM inspection through these settings.

Every successful inspection page is audited before content is returned. Audit metadata includes the actor, IP, reason, selected users, pagination details and returned ID range, but not copied message bodies.

## Browser security and rate limits

All PHP responses use a restrictive Content Security Policy, clickjacking protection, `nosniff`, no-referrer behavior, a restrictive Permissions Policy, same-origin opener/resource policies, and `Cache-Control: no-store`. When `SESSION_COOKIE_SECURE=1`, ChitChat also emits one-year HSTS.

Database-backed fixed-window limits are shared by all PHP workers: five registrations per source IP per hour, thirty room sends per user per minute, ten attachment uploads per user per hour, and thirty direct messages per user per minute. Login has its existing configurable credential throttle.

## Backup, restore and upgrade rehearsal

Back up PostgreSQL and attachment storage together. The database contains password hashes, chat and DM history, audit data, IP addresses and policy settings. See `docs/operations/backup-restore.md` for the operator procedure.

CI also installs the published `v1.0.0-rc.1` archive into an empty directory, seeds it through the HTTP API, creates and verifies a database plus attachment backup, restores both under new names, runs current migrations, and checks that users, messages, DMs and attachment bytes remain intact. See `docs/operations/release-rehearsal.md`.

## Production web-root rule

The web server document root must be the repository's `public/` directory. Do not expose `src/`, `bootstrap/`, `migrations/`, `.env`, `var/`, Composer metadata, database dumps, or backup archives.

Use HTTPS, set `SESSION_COOKIE_SECURE=1`, and leave `SESSION_COOKIE_SAMESITE=Lax` unless deployment requirements justify a stricter setting. Do not use `SameSite=None` without secure cookies.

## Tests and checks

```sh
composer check
find public/assets/js tests/e2e -type f -name '*.js' -print0 | xargs -0 -n1 node --check
find tests/stabilization -type f -name '*.sh' -print0 | xargs -0 -n1 bash -n
npm run test:e2e -- --project=chromium
npm run test:e2e -- --project=firefox
```

The integration and browser suites require disposable migrated PostgreSQL databases. They create and clear application data and must never be pointed at a database containing valuable information. Attachment, maintenance, release-rehearsal and reverse-proxy tests use isolated temporary storage directories.
