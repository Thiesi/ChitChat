# Changelog

All notable changes to the reconstructed ChitChat application are documented here.

The project uses semantic versioning. Release-candidate versions are pre-releases and may still require schema or configuration changes before a stable release.

## [Unreleased]

### Added

- Added optional password-first WebAuthn multi-factor authentication with multiple labelled passkeys and ten one-time recovery codes.
- Added privacy-preserving `none` attestation, ES256 and RS256 public-key verification, required user presence and verification, exact RP/origin/challenge checks, authenticator backup-state tracking, and signature-counter validation.
- Added account-facing passkey enrollment, credential rename/removal, recovery-code replacement, and MFA disablement with session-version invalidation.
- Added passkey and recovery-code completion for ordinary login, privileged step-up, and account restoration.
- Added a Super-Administrator policy requiring passkey MFA for `super_admin`, `admin`, `chat_admin`, and `global_moderator` roles.
- Added transactional policy activation checks, application-level role validation, and a PostgreSQL invariant for later protected-role grants.
- Added bounded `mfa_assertion`, `mfa_recovery`, and `mfa_management` rate-limit policies and authentication audits that exclude challenges, signatures, credential identifiers, public keys, and recovery material.
- Added protocol-level WebAuthn fixtures, MFA lifecycle integration tests, and a Chromium virtual-authenticator journey for enrollment, passkey login, recovery login, and one-time-code reuse rejection.
- Added passkey deployment, API, security-model, and recovery operating documentation.
- Added durable participant-facing privacy notifications for administrative revision review, moderator room-message deletion, administrative password reset, and material installation-policy changes.
- Added a paginated signed-in notification center, account-scoped individual and bulk read state, and a capped unread badge on the chat surface.
- Added PostgreSQL integration coverage for notification recipients, no-op policy suppression, account isolation, tombstone cleanup, and exclusion of content, reasons, IP addresses, and secrets from notification context.
- Added pinned axe-core WCAG A/AA analysis for authentication, restoration, chat, direct-message, account, and privacy-notification surfaces.
- Added Chromium reflow checks at 640- and 320-CSS-pixel widths plus forced-colors and reduced-motion preference validation.
- Added targeted pinned-Chromium/Linux screenshot regression for stable desktop authentication and desktop/narrow account layouts.
- Added explicit manual NVDA, VoiceOver, keyboard, browser-zoom, Windows contrast-theme, and reduced-motion review procedures with versioned result recording.

### Security and privacy

- A correct password for an MFA-enabled account creates only a short-lived pending context; it does not create an authenticated session, update `last_login_at`, or record a successful login until the second factor succeeds.
- MFA-enabled accounts cannot bypass their configured factor through the password-only privileged-step-up endpoint.
- Recovery-code plaintext is returned only when a set is created or replaced; PostgreSQL stores SHA-256 hashes of 96-bit random values and consumes codes atomically once.
- Account restoration remains closure-pending after password verification and mutates lifecycle state only after passkey or recovery-code completion.
- Restoration rechecks the deadline and current administrative-MFA policy; protected roles that no longer qualify are withheld and named in audit metadata rather than creating an unusable administrator.
- Irreversible account tombstoning destroys WebAuthn credentials, recovery hashes, the opaque WebAuthn user handle, and the MFA activation timestamp at the database boundary.
- Selected notifications are derived from the append-only audit log by PostgreSQL in the same transaction as the audited action, preventing an event from committing without its participant disclosure.
- Notification rows contain only the recipient, fixed event kind, nullable audit reference, bounded structural context, and read timestamps; they do not duplicate message or revision bodies, administrator or moderation reasons, usernames, IP addresses, credentials, session state, passkey data, or recovery material.
- Audit retention may remove the restricted source entry while preserving the participant-facing disclosure; permanent account tombstoning removes that account's private notification history.
- Automated accessibility results remain explicitly separate from release-specific manual assistive-technology sign-off; green axe, emulation, and screenshot checks do not claim completed NVDA or VoiceOver validation.

### Compatibility

- Passkeys remain disabled unless both `WEBAUTHN_RP_ID` and `WEBAUTHN_ORIGIN` are configured; existing password-only installations retain their prior login and step-up behavior.
- Production WebAuthn origins require HTTPS. Local development and tests may use `http://localhost:<port>`.
- The PHP OpenSSL extension is required when passkeys are enabled; no external identity service or new runtime package is introduced.
- Forward-only migration `0016_mfa_passkeys.sql` adds the MFA schema and invariants. Older ChitChat source must not be run against a database after applying it.
- Forward-only migration `0017_privacy_notifications.sql` adds durable notification state and audit-derived database triggers. Older ChitChat source must not be run against a database after applying it.
- Notification correctness does not depend on Redis, a queue, email delivery, or realtime browser delivery; PostgreSQL remains authoritative and the unread badge performs a bounded periodic refresh.
- Axe-core and visual comparison are development-only npm dependencies and introduce no production runtime package or external service.
- Screenshot baselines are intentionally limited to pinned Chromium on Linux and stable content; Chromium, Firefox, and WebKit retain their existing functional and structural accessibility journeys.

## [1.1.0] - 2026-07-17

Second stable release of the clean ChitChat reconstruction. This release adds direct-message controls and attachments, message editing and revision history, stronger administrative authentication and privacy boundaries, operational observability, personal-data export, WebKit validation, and accessibility hardening.

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
- Added an Administrator system-status page backed by aggregate PostgreSQL, attachment-storage, realtime, security-ledger, and maintenance measurements.
- Added a disabled-by-default Prometheus text endpoint protected by a configurable bearer token.
- Added leased SSE-connection accounting and persistent maintenance invocation records for success, warning, failure, duration, and result reporting.
- Added ready-to-adapt hardened `systemd` service/timer units and observability operating documentation.
- Added unit, PostgreSQL integration, and Chromium/Firefox coverage for status authorization, Prometheus encoding, maintenance freshness, and SSE lease lifecycle.
- Added short-lived current-password step-up authentication for DM inspection, revision review, global role replacement, administrator password resets, and operational-policy updates.
- Added session disclosure of step-up status, a shared accessible browser password dialog, and one-time automatic retry of protected JSON POST requests after successful verification.
- Added database-backed step-up attempt limiting plus separate success and failure audit records.
- Added unit, PostgreSQL integration, and Chromium/Firefox coverage for failed verification, successful elevation, session-version binding, expiry, rate limiting, and reuse during the active window.
- Added a signed-in account page with a step-up-protected JSON export of retained profile, room, direct-message, security-history, and actor-audit data.
- Added repeatable-read export snapshots, versioned export metadata, per-account export throttling, and successful-generation audits containing aggregate counts only.
- Added PostgreSQL integration and Chromium/Firefox coverage for export scope, download behavior, revision ownership, block-direction privacy, and secret/storage-key exclusion.
- Added the complete browser release journey as an independent WebKit CI gate alongside Chromium and Firefox.
- Added dependency-free browser accessibility checks for landmarks, headings, unique IDs, labelled controls, named interactive elements and dialogs, keyboard-operated authentication tabs, and visible focus indicators.
- Added explicit authentication-tab panel relationships, roving tab focus, keyboard navigation, a named room dialog, live connection-status semantics, and high-visibility focus styling on the core user surfaces.

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
- Metrics remain unavailable while no bearer token is configured and expose aggregate operational values rather than usernames, message content, attachment names, IP addresses, credentials, or filesystem paths.
- Privileged elevation is bound to the current user and session version, expires after a configurable 60-3600 second window, and is cleared by login rotation, logout, password or session-version changes, bans, and other authentication invalidation.
- Coarse role and feature-policy checks occur before step-up, so unauthorized accounts are denied without receiving a password prompt.
- Successful and failed step-up audits contain method and timing policy only; passwords are never written to audit metadata.
- Password step-up is recent reauthentication rather than multi-factor authentication and does not replace roles, CSRF, required reasons, per-action audits, or target-specific authorization.
- Personal-data exports exclude credentials, session and step-up state, attachment bytes and storage keys, other users' incoming block state, and hidden revisions for messages authored by somebody else.
- Successful export audits are created after the exported activity snapshot and contain only the format version and aggregate item counts, avoiding recursive inclusion and content duplication.

### Compatibility

- `v1.1.0` supports an in-place upgrade from stable `v1.0.0` after PostgreSQL and attachment storage are backed up together.
- The upgrade applies forward-only migrations `0010_direct_message_blocks.sql`, `0011_direct_message_attachments.sql`, `0012_message_mutations.sql`, and `0013_operational_observability.sql`.
- Once these migrations are applied, older ChitChat code must not be pointed at the upgraded database. Rollback requires restoring a matching pre-upgrade database and attachment backup.
- No new runtime package or external service is required for the supported single-server deployment.

### Upgrade notes

Fresh installations should follow `INSTALL.md`.

Existing `v1.0.0` installations should:

1. stop or drain application writes;
2. back up PostgreSQL and attachment storage together;
3. deploy `v1.1.0` while preserving `.env` and attachment storage;
4. run `composer install --no-dev --classmap-authoritative`;
5. run `composer migrate` once;
6. run `composer maintenance:dry-run` and review the result;
7. verify `/health.php`, `/ready.php`, login, room and direct-message history, attachment access, SSE through the production reverse proxy, account export, and system status.

### Known limitations

- Initial deployment target remains one application server; horizontal scaling and Redis-backed delivery are not implemented.
- PostgreSQL is the only supported database.
- Direct messages are not end-to-end encrypted.
- Revision review does not automatically notify affected participants.
- Browser accessibility checks are regression smoke tests rather than a complete manual WCAG audit or visual-regression suite.
- Maintenance scheduling and monitoring remain operator responsibilities.
- Account closure, multi-factor authentication, configurable per-limit throttles, and richer compliance/reporting workflows are not included.
- No supported in-place upgrade exists from the incomplete legacy `v0.10.25` snapshot.

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
3. run `composer install --no-dev --classmap-authoritative` for production or `composer install` for development;
4. run `composer migrate` once;
5. verify attachment-directory ownership, `/health.php`, and `/ready.php`;
6. run `composer maintenance:dry-run`;
7. test login, a room message, an attachment download, a direct message, and SSE through the production reverse proxy.
