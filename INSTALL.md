# ChitChat installation and operation

This document describes installing and operating the current release; each release's notes in [`docs/releases/`](docs/releases/) list what changed and any upgrade actions. The supported deployment model is one application server backed by PostgreSQL; review the privacy defaults, forward-only migrations, known limitations, backup procedure, authentication configuration, and worker-capacity requirements before serving users.

## Requirements

- PHP 8.2 or newer
- Composer 2
- PostgreSQL 15 or newer
- PHP extensions: `pdo`, `pdo_pgsql`, `json`, `mbstring`, `fileinfo`, `openssl`, and `curl` (needed only if Web Push is enabled)
- Recommended: `gd` with PNG, WebP and FreeType support (Debian/Ubuntu: `php8.x-gd`; check with `php -r 'var_export(gd_info());'`). GD is used for profile pictures, image preview renditions, the installation icon (presets and uploads) and the icon preset that shows the installation name's initial, which needs FreeType. Without GD, ChitChat works normally: it shows initials instead of pictures, offers no previews, keeps the default icon, and the relevant settings explain why. ChitChat re-encodes every uploaded image with GD, so it never serves an uploaded file as-is. A `memory_limit` of at least 256M is recommended for large icon uploads.
- Node.js 24 or newer only for CI-equivalent JavaScript and browser tests

The deployed browser client has no Node.js runtime dependency and uses no npm packages. Composer installs the Web Push dependencies (`minishlink/web-push`, its HTTP client and PSR-7 implementation) and `league/commonmark` for bounded, sanitized policy Markdown rendering; see [ADR 0006](docs/architecture/0006-web-push.md) and the [policy API](docs/api/policies.md).

## Setup

1. Create a PostgreSQL database and account.
2. Copy the example environment file:

   ```sh
   cp .env.example .env
   ```

3. Adjust the database values in `.env`.
   Leave `APP_VERSION` unset: the version comes from the deployed release.
   Set it only for a custom build. This keeps `/health.php` and
   `/api/v1/session.php` application.version aligned with the installed source.
4. For plain HTTP local development, keep `SESSION_COOKIE_SECURE=0`. Production HTTPS deployments must use `SESSION_COOKIE_SECURE=1`.
   Set `APP_ORIGIN=https://chat.example.org` to the canonical public scheme, host and optional port (no path).
   This is especially important behind a TLS-terminating proxy: policy discovery and privacy-notification links
   use this origin, and clients such as CCM open the absolute URL directly. Without it, a proxy that does not
   pass `HTTPS=on` to PHP can produce HTTP links. The fallbacks are `OIDC_REDIRECT_ORIGIN`, `WEBAUTHN_ORIGIN`,
   then the validated request Host and direct HTTPS state; forwarded headers are not trusted. HTTP is allowed
   for `APP_ORIGIN` only on localhost/loopback in development or test environments.
5. To enable passkeys, configure a durable relying-party ID and exact browser origin. Production must use HTTPS:

   ```text
   WEBAUTHN_RP_ID=chat.example.org
   WEBAUTHN_ORIGIN=https://chat.example.org
   ```

   Leave both variables unset to keep passkeys disabled. Changing either value after enrollment invalidates the relationship between existing credentials and the installation. See `docs/operations/passkeys.md` before enabling the feature.
6. To enable Web Push, generate a VAPID keypair and set all three values in `.env`:

   ```sh
   npx web-push generate-vapid-keys
   ```

   ```text
   WEB_PUSH_VAPID_PUBLIC_KEY=<generated public key>
   WEB_PUSH_VAPID_PRIVATE_KEY=<generated private key>
   WEB_PUSH_VAPID_SUBJECT=mailto:admin@example.org
   ```

   Leave all three unset to keep Web Push disabled. See `docs/operations/web-push.md` for the dispatch sweep, scheduling, and notification-category details before enabling the feature.
7. To offer authenticator-app (TOTP) codes as a second factor, give ChitChat an encryption key for the stored secrets. The recommended source is a server key ring: run this in a private terminal and copy its two lines into `.env`:

   ```sh
   php bin/totp-key generate
   ```

   ```text
   TOTP_ENCRYPTION_KEYS=<generated key ring>
   TOTP_ACTIVE_KEY_ID=<generated key ID>
   ```

   The output is a secret: don't paste it into tickets, logs or shell history. The alternative is a key generated and stored in the database by a Super-Administrator in Operational settings, which the interface warns about; a server key ring always wins. Without either source, authenticator enrollment simply isn't offered. When you add or change these values on a running installation, reload PHP-FPM so every worker picks them up. Read `docs/operations/totp.md` before choosing, and for rotation and escrow.
8. Install dependencies:

   ```sh
   composer install
   ```

   For a production-like release installation:

   ```sh
   composer install --no-dev --classmap-authoritative
   ```

9. Apply the migrations:

   ```sh
   composer migrate
   ```

10. Ensure the attachment storage directory is writable by the PHP process. By default ChitChat uses `var/uploads`, which is outside the served `public/` tree:

   ```sh
   mkdir -p var/uploads
   chmod 700 var/uploads
   ```

11. Start PHP's built-in server with `public/` as the document root for local evaluation:

    ```sh
    PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:8080 -t public
    ```

12. Open `http://127.0.0.1:8080/`.

For local passkey evaluation use `WEBAUTHN_RP_ID=localhost`, `WEBAUTHN_ORIGIN=http://localhost:8080`, and open that exact origin rather than `127.0.0.1`.

The first account created through the browser becomes Super-Administrator. That account can create the first room and use **Administration** for users, rooms, audit visibility, direct-message inspection policy, optional revision review, administrative-MFA enforcement, operational settings, and system status.

For a production-like single-server deployment, use Nginx and PHP-FPM as described in `docs/operations/nginx-php-fpm.md`; do not use PHP's development server. That Nginx instance must terminate the client's TCP connection directly — see [Do not add another proxy or CDN in front of this Nginx](docs/operations/nginx-php-fpm.md#do-not-add-another-proxy-or-cdn-in-front-of-this-nginx) before placing any CDN, load balancer, or additional reverse proxy in front of it. On NetBSD with rc.d, cron, and Apache 2.4 plus PHP-FPM, follow [`docs/operations/netbsd-apache.md`](docs/operations/netbsd-apache.md) instead; the same rule applies to that Apache instance.

## Upgrading

Migrations are forward-only: there is no down-migration path, and older ChitChat source must not run against a database after newer migrations have been applied. Read the release notes in [`docs/releases/`](docs/releases/) for every release you skip; they list required `.env` changes and one-time actions (for example the single sign-out when registered sessions were introduced, described in `docs/operations/session-registry-upgrade.md`).

1. Stop or drain application writes.
2. Back up PostgreSQL and the complete attachment-storage directory together (`php bin/chitchat-backup`, then `php bin/chitchat-verify-backup`; see `docs/operations/backup-restore.md`).
3. Preserve `.env` and the configured attachment-storage path.
4. Deploy the intended ChitChat release.
   If your `.env` sets `APP_VERSION`, remove it (recommended) or update it to the
   deployed version; an old pin would keep health and client discovery reporting
   the previous release.
5. Run:

   ```sh
   composer install --no-dev --classmap-authoritative
   composer migrate
   composer maintenance:dry-run
   ```

6. Restart long-running processes such as the Web Push listener, and reload PHP-FPM.
7. Review new optional features and settings named in the release notes before enabling them.
8. Verify `/health.php`, `/ready.php`, the `application.version` reported by `/api/v1/session.php`, sign-in (with MFA when enabled), retained room and direct-message history, attachment downloads, SSE through the production reverse proxy, and the Administrator system-status page.

Rollback requires restoring a matching pre-upgrade PostgreSQL and attachment backup.

## Authentication bootstrap

API clients begin by requesting:

```text
GET /api/v1/session.php
```

The response contains a `csrf_token`. Send that value in the `X-CSRF-Token` header for every state-changing request, including registration and login.

It also contains whether public registration is enabled, the direct-message privacy policy, the independently configured message-revision review policy, and current privileged step-up state under `security.privileged_step_up`. Browser clients must disclose that DMs are not end-to-end encrypted, state the active retention period, and state whether administrative inspection is enabled and for which role. The bundled DM interface also discloses that edits and deletions preserve historical bodies until message retention removes them.

The first successfully registered account is promoted to `super_admin` inside the same database transaction that creates it. Concurrent first registrations are serialized by locking the single system-settings row.

### Password-first MFA

When passkeys are configured, users may enroll one or more credentials under **Account → Passkeys and recovery codes**. The first successful enrollment enables MFA and returns ten one-time recovery codes exactly once. ChitChat stores only their hashes.

A correct password for an MFA-enabled account does not create an authenticated session or record a successful login. It creates a short-lived pending context bound to the account, session version, source IP, CSRF token and expiry. The client then completes a registered passkey assertion or consumes one recovery code through the endpoints documented in `docs/api/mfa.md`.

Account restoration follows the same rule: username and password validate eligibility, but the account remains closure-pending until the second factor succeeds. A cancelled or expired MFA attempt does not cancel closure.

### Native Android passkeys

Set **NATIVE_ANDROID_APPS** to verified package/SHA-256 app-signing certificate
pairs (default **[]**) alongside the existing WebAuthn RP and web origin.
Deploy the exact **/.well-known/assetlinks.json** mapping from the
[Nginx example](docs/operations/nginx-php-fpm.md), including the RP domain if it
differs from the web host. Existing static declarations take precedence.
See [Android trust](docs/operations/passkeys.md#android-credential-manager-trust)
for certificate rotation, Play signing, debug trust and native acceptance.
This keeps password-first MFA and the existing endpoints. No host configuration
is installed by updating the application; the operator deploys and verifies it.

### Privileged step-up

Sensitive actions require recent privileged authentication in addition to session authentication, CSRF, role authorization, and action-specific audits.

Accounts without MFA verify the current password through:

```text
POST /api/v1/step-up.php
X-CSRF-Token: <session token>
Content-Type: application/json

{"password":"current account password"}
```

MFA-enabled accounts must instead use a passkey assertion or one-time recovery code. Direct password step-up is refused for those accounts. The browser automatically chooses the strongest configured method and retries the original protected JSON request exactly once.

`PRIVILEGED_STEP_UP_MAX_AGE_SECONDS` defaults to `600` and accepts 60-3600 seconds. Elevation is bound to the current account, session version and verification method. It expires or is cleared after login rotation, logout, password changes, kicks, bans, administrator resets of the current account, and any other session invalidation.

Incorrect password, passkey and recovery-code attempts are independently audited and bounded by named rate-limit policies. Successful verification creates a separate audit record from the later protected action and never bypasses content, target, policy, reason or authorization checks.

### Sign in with Google or Twitch

Accounts can connect Google or Twitch on the Account page and then sign in with it. New people can also sign up with a provider: they still choose a ChitChat username, and registration being open, the bot protection and maintenance lockdown apply as for a password sign-up. Such accounts have no password until they set one on the Account page; they confirm sensitive actions by signing in with the provider again in a small window (or with a passkey, if they use MFA), and restore a closing account through the provider. ChitChat uses OpenID Connect with the authorization-code flow, PKCE, a state bound to the browser session, and a nonce bound into the ID token. It requests **only the `openid` scope** and stores the provider plus its stable account identifier (`sub`), **never an email address**, name, or picture. The identifier is not anonymous, which matters for a privacy notice: Google's is the same in every application the person signs in to, and Twitch's is the person's public Twitch user ID, which resolves to their Twitch username. ID tokens are verified locally (RS256 signature against the provider's published keys, issuer, audience, expiry, and nonce); the keys are cached for an hour.

Each provider is disabled until configured. Register an application with the provider, use `<OIDC_REDIRECT_ORIGIN>/api/v1/oidc/callback.php` as its redirect URI, and set:

```text
OIDC_REDIRECT_ORIGIN=https://chat.example.org   # defaults to WEBAUTHN_ORIGIN
GOOGLE_OIDC_CLIENT_ID=…
GOOGLE_OIDC_CLIENT_SECRET=…
TWITCH_OIDC_CLIENT_ID=…
TWITCH_OIDC_CLIENT_SECRET=…
```

- Google: create an OAuth client of type "Web application" in the Google Cloud console; the consent screen needs no scopes beyond `openid`.
- Twitch: register an application in the Twitch developer console with the redirect URI above.

Provider sign-in follows the same rules as password sign-in: bans, maintenance lockdown, and multi-factor authentication apply (an account with passkeys still completes its second factor), it is rate-limited per IP address (`oidc_sign_in`), and it is audited. Connecting or disconnecting a provider requires privileged step-up, and an account without a password cannot disconnect its last provider.

Confirming a sensitive action through a provider is as strong as the provider's own sign-in. ChitChat asks for a fresh sign-in: Google reports when it happened (`auth_time`) and ChitChat insists it is from this attempt; Twitch reports no such time, so ChitChat relies on Twitch's confirmation screen (`force_verify`). Members who want more should add a passkey, which then becomes the required method. The server must be able to reach the provider over HTTPS for the token exchange and key download.

## Endpoints

Browser pages:

- `/` serves the chat client (rooms) and the sign-in, registration and guest entry screens.
- `/messages.php` serves direct messages, `/notifications.php` the Activity feed, `/search.php` message search, and `/account.php` the You page (account, security, notifications and push, appearance, export and closure).
- `/moderation.php` serves the moderation queue for eligible moderators.
- `/admin.php`, `/admin-settings.php`, `/admin-status.php`, `/admin-messages.php` and `/admin-message-revisions.php` serve administration, operational settings, system status, direct-message inspection and revision review for the roles that may use them.
- `/restore-account.php`, `/choose-username.php`, `/step-up-complete.php` and `/native-auth.php` serve account restoration, choosing a new username after a moderator requires one, finishing a privileged re-confirmation through Google or Twitch, and the native-app sign-in hand-off.
- `/rules.php` and `/privacy.php` serve the installation's community rules and privacy policy when configured.
- `/installation-icon.php` (and `/favicon.ico` through the front controller) serve the installation icon, and `/manifest.php` the web app manifest; `/.well-known/assetlinks.json` serves Android credential association when native apps are configured.

Operational endpoints:

- `/health.php` reports whether the PHP process is alive.
- `/ready.php` verifies that the application can connect to PostgreSQL and use attachment storage.
- `/metrics.php` serves optional bearer-protected Prometheus metrics when explicitly enabled.

The JSON API lives under `/api/v1/`. Its contracts, including authentication, CSRF and step-up requirements, are documented per feature in [`docs/api/README.md`](docs/api/README.md).

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

User, audit, and system-status controls are visible only to Super-Administrators and Administrators. Room controls are visible to Super-Administrators, Administrators, Chat Admins, and owners of the selected room. Only Super-Administrators may change registration, administrative-MFA, or retention policy.

Sensitive actions additionally require active privileged step-up (a recent re-confirmation of the password, passkey or authenticator code), among them: every Super-Administrator setting (operational settings, application name, installation icon, sign-in decoration, lockdown, registration protection, authenticator key settings and policies), global role changes, administrator password resets, direct-message inspection and revision review, room deletion and restoration, and, for one's own account, password changes, MFA and recovery-key management, connecting or disconnecting a sign-in provider, signing out other sessions, personal-data export and account closure. The API documentation in [`docs/api/`](docs/api/) describes the individual routes.

Coarse role and feature-policy checks occur before step-up, so an unauthorized account is denied without receiving an authentication prompt. Successful verification and the later protected action create separate audit records.

Administrative-MFA enforcement covers `super_admin`, `admin`, `chat_admin`, and `global_moderator`. It cannot be enabled until every current holder has a second factor (a passkey or an authenticator app) and an unused recovery code. Once enabled, later protected-role grants require a second factor and are enforced both by the application transaction and PostgreSQL.

Room-message, direct-message, and audit retention default to `0`, meaning permanent retention. Nonzero policies become effective only when maintenance runs:

```sh
composer maintenance:dry-run
composer maintenance
```

The command also removes expired presence and SSE leases, old realtime events and throttle ledgers, signed-in session records retired more than seven days ago, expired username reservations, deleted attachment files, and opaque orphan files older than their grace period. `docs/operations/maintenance.md` lists every step and result field. Every invocation is recorded with mode, status, duration, and result or failure information. A destructive run with attachment-file failures is recorded as a warning and does not count as a fresh clean success.

`MAINTENANCE_MAX_AGE_HOURS` defaults to 26 and controls the overdue state on `/admin-status.php` and in Prometheus metrics. Schedule maintenance as the same operating-system account that owns attachment storage. Ready-to-adapt files are provided for systemd (`deploy/systemd/`) and for cron with rc.d on NetBSD (`deploy/netbsd/crontab.example`, described in `docs/operations/netbsd-apache.md`). See `docs/operations/maintenance.md`.

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
