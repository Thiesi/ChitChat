# Changelog

All notable changes to the reconstructed ChitChat application are documented here.

The project uses semantic versioning. Release-candidate versions are pre-releases and may still require schema or configuration changes before a stable release.

## [Unreleased]

### Added

- Added user-controlled direct-message blocking and unblocking from the conversation header.
- Added authenticated block-status, block, and unblock API endpoints.
- Added integration and Chromium/Firefox journey coverage for blocked sends, retained history, and resumed messaging after unblock.
- Added direct-message file uploads with optional captions, opaque storage, shared MIME and size policy, SHA-256 metadata, safe image previews, and participant-only downloads.
- Added bounded attachment metadata enrichment for visible direct-message history without changing the canonical DM/SSE payload shape.
- Added PostgreSQL/filesystem and Chromium/Firefox coverage for multipart DM uploads, exact-byte downloads, outsider denial, and retention cleanup.
- Added author editing and delete-for-everyone controls for room and direct messages, including attachment captions.
- Added immutable database-triggered revision ledgers for room and direct-message edits and deletions.
- Added bounded mutation metadata endpoints, edited markers, realtime cross-session refresh, and author/moderator deletion placeholders.
- Added PostgreSQL/filesystem and Chromium/Firefox coverage for authorship enforcement, block-aware editing, deletion, revision history, and attachment revocation.
- Added separately configurable administrative review of retained room and direct-message revision chains.
- Added an exact-message-ID, reason-required review endpoint and browser surface that expose only messages with retained revisions rather than providing user, room, conversation, date, or body search.
- Added integration and Chromium/Firefox coverage for revision-chain rendering, independent role authorization, reason validation, and content-free audit metadata.

### Security and privacy

- A block in either direction prevents new messages and file uploads in both directions while preserving existing retained history.
- Public relationship state exposes whether the requesting user set a block and only a generic messaging-availability flag; it does not expose a separate `blocked_by_other` field.
- Send, upload, block, and unblock operations serialize on a PostgreSQL advisory lock for the user pair, preventing a completed block from being bypassed by a concurrent message or attachment.
- DM attachment metadata and bytes are returned only to the sender or recipient; unauthorized requests use the same not-found response as an unknown attachment.
- Administrative text-history inspection does not silently grant attachment-binary download rights.
- Direct-message retention removes associated attachment metadata and files in the same maintenance run, while orphan detection treats room and DM keys as one shared storage namespace.
- Only an undeleted message's author may edit or delete it; room moderator deletion remains a separate audited action.
- Direct-message editing is disabled while either participant has blocked the other, preventing an edit from becoming a post-block delivery channel; sender deletion remains available.
- User-deleted attachments become inaccessible immediately while their binary and immutable revision evidence remain until configured cleanup.
- Revision bodies are not exposed through participant mutation endpoints or duplicated into ordinary mutation audit metadata.
- Revision review is disabled by default, has an authorization policy independent from DM inspection and moderation roles, requires a fresh 10-500 character reason, and audits every successful access before returning historical bodies.
- Successful review audits record the actor, IP, exact message context, reason, and returned revision IDs and actions without copying historical bodies into audit JSON.
- Messages without retained revisions cannot be opened through the review workflow, limiting it to its stated historical-content purpose.
- ChitChat does not notify participants when a revision review occurs; the administrative interface and operating documentation make that limitation and the operator's disclosure responsibility explicit.

## [1.0.0] - 2026-07-17

First stable release of the clean ChitChat reconstruction.

### Stabilized since 1.0.0-rc.1

- Added the complete two-session browser journey as an independent Firefox gate alongside Chromium.
- Added installation testing from the published `v1.0.0-rc.1` source archive using production Composer dependencies.
- Added automated PostgreSQL and attachment backup verification, restore under new names, and forward migration from the release candidate.
- Added verification that restored accounts, room history, direct-message history, and exact attachment bytes remain usable through current source.
- Added a real Nginx and PHP-FPM deployment gate that authenticates, opens SSE, sends a room message concurrently, and requires the event to arrive before the stream closes.
- Added tested Nginx/PHP-FPM, browser-matrix, and release-rehearsal operating documentation.
- Corrected the reverse-proxy test harness to use Ubuntu-supported PHP-FPM meta-packages and dynamically locate the installed FPM binary.

### Compatibility

- No database migration, API, retention-policy, or application-feature change was introduced between `v1.0.0-rc.1` and `v1.0.0`.
- A `v1.0.0-rc.1` installation may be upgraded in place after backing up PostgreSQL and attachment storage together, deploying stable source, running `composer install --no-dev --classmap-authoritative`, and running `composer migrate`.
- The automated release rehearsal proves the published RC archive can be installed, backed up, restored, and advanced to stable source without losing seeded user, room, attachment, or direct-message data.

### Security and privacy defaults

- Direct messages are not end-to-end encrypted.
- Administrative DM inspection is enabled for Super-Administrators by default and every successful page access is audited.
- Room messages, direct messages, and audit entries are retained permanently until a Super-Administrator configures a nonzero retention period and maintenance runs.
- Attachment downloads always re-evaluate current room and minimum-age authorization.
- Only `public/` may be exposed by the web server; attachment storage and `.env` must remain outside it.

### Known limitations

- Initial deployment target is one application server; horizontal scaling and Redis-backed delivery are not implemented.
- PostgreSQL is the only supported database.
- Direct messages are text-only and do not support attachments, blocking, editing, or user deletion.
- Room messages cannot be edited; moderator deletion is a soft-delete action.
- Browser end-to-end coverage targets Chromium and Firefox, not WebKit, and is a release journey rather than visual-regression coverage.
- Retention cleanup requires an operator-scheduled maintenance command.
- No supported in-place upgrade exists from the incomplete legacy `v0.10.25` snapshot.

### Upgrade notes

Fresh installations should follow `INSTALL.md`.

Existing `v1.0.0-rc.1` installations should:

1. back up PostgreSQL and attachment storage together;
2. deploy the stable source while preserving `.env` and attachment storage;
3. run `composer install --no-dev --classmap-authoritative`;
4. run `composer migrate` once;
5. verify attachment-directory ownership, `/health.php`, and `/ready.php`;
6. run `composer maintenance:dry-run`;
7. test login, a room message, an attachment download, a direct message, and SSE through the production reverse proxy.

Do not point older application code at a database after its migrations have been advanced.

## [1.0.0-rc.1] - 2026-07-17

First release candidate of the clean v1 reconstruction.

### Added

- PostgreSQL-only application schema with forward-only migrations and atomic first-account Super-Administrator bootstrap.
- Session authentication, CSRF protection, login throttling, bans, kicks, password changes, administrator password resets, and active-session invalidation.
- Public, unlisted, and invitation-only private rooms with owners, moderators, members, age restrictions, and optional inactivity policy.
- Persistent room history, text messages, `/me`, targeted `/ping`, moderator deletion, room/global broadcasts, and ordered database-backed SSE delivery.
- Tab-scoped presence leases, aggregated online lists, inactivity warnings, and reconnect-safe realtime cursors.
- Opaque room-attachment storage outside the public web root, MIME and size policy, SHA-256 metadata, safe image previews, and authorization-aware downloads.
- Permanent-by-default direct-message history, unread counts, cursor pagination, targeted realtime events, fixed privacy disclosure, and configurable audited administrative inspection.
- Browser administration for users, roles, bans, passwords, rooms, membership, invitations, audit visibility, DM inspection, registration, and retention policy.
- Dry-run-capable maintenance for retained content, deleted and orphaned attachments, expired events and presence, login attempts, and request-throttle rows.
- Database-backed request limits for registration, room sends, attachments, and direct messages.
- Restrictive CSP, clickjacking protection, HSTS on secure deployments, `nosniff`, no-referrer behavior, Permissions Policy, same-origin isolation, and no-store responses.
- Health/readiness endpoints, Apache/Nginx/PHP-FPM operational guidance, backup/restore procedures, and maintenance scheduling documentation.
- PHP linting, PHPStan level 8, PostgreSQL integration tests, JavaScript syntax checks, maintenance CLI validation, and a real two-session Chromium release journey in CI.

### Fixed

- Authentication-state changes that occurred during an in-flight navigation-policy refresh now schedule a follow-up refresh, preventing eligible users from intermittently seeing the Administration link remain hidden immediately after registration or login.

### Security and privacy defaults

- Direct messages are not end-to-end encrypted.
- Administrative DM inspection is enabled for Super-Administrators by default and every successful page access is audited.
- Room messages, direct messages, and audit entries are retained permanently until a Super-Administrator configures a nonzero retention period and maintenance runs.
- Attachment downloads always re-evaluate current room and minimum-age authorization.
- Only `public/` may be exposed by the web server; attachment storage and `.env` must remain outside it.

### Known limitations

- Initial deployment target is one application server; horizontal scaling and Redis-backed delivery are not implemented.
- PostgreSQL is the only supported database.
- Direct messages are text-only and do not support attachments, blocking, editing, or user deletion.
- Room messages cannot be edited; moderator deletion is a soft-delete action.
- Browser end-to-end coverage currently targets Chromium only and is a release smoke journey rather than visual regression coverage.
- Retention cleanup requires an operator-scheduled maintenance command.
- No supported in-place upgrade exists from the incomplete legacy `v0.10.25` snapshot.

### Upgrade notes

Fresh installations should follow `INSTALL.md`.

Developers or test installations running an earlier v1 reconstruction commit should:

1. back up PostgreSQL and attachment storage together;
2. deploy this release-candidate source;
3. run `composer install --no-dev --classmap-authoritative` for production or `composer install` for development;
4. run `composer migrate` once;
5. verify attachment-directory ownership and `/ready.php`;
6. review `composer maintenance:dry-run` before scheduling cleanup;
7. test registration, a room message, an attachment download, and a direct message.

Do not point older application code at a database after its migrations have been advanced.
