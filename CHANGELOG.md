# Changelog

All notable changes to the reconstructed ChitChat application are documented here.

The project uses semantic versioning. Release-candidate versions are pre-releases and may still require schema or configuration changes before a stable release.

## [Unreleased]

### Added

- Added Web Push notification delivery: browser push subscriptions, per-category notification preferences (mute for `mentioned`), per-account quiet hours, and a new `bin/dispatch-web-push` periodic operator-scheduled command that sweeps undelivered notifications rather than sending push as a request-time side effect. See [ADR 0006](docs/architecture/0006-web-push.md).
- Added `minishlink/web-push` as this project's first production Composer dependency, for VAPID JWT signing and RFC 8291 payload encryption. Disabled unless `WEB_PUSH_VAPID_PUBLIC_KEY`, `WEB_PUSH_VAPID_PRIVATE_KEY`, and `WEB_PUSH_VAPID_SUBJECT` are all configured, mirroring how WebAuthn stays inert without `WEBAUTHN_RP_ID`/`WEBAUTHN_ORIGIN`.
- Added a "Push notifications" section to the privacy-notifications page: enable/disable push for the current device, mute `@mentions`, per-account quiet hours, and a device list with per-device revocation. Silently hidden if the browser lacks push support or the installation hasn't configured VAPID keys.

### Security and privacy

- Push payloads carry only the same sender/room/title text already considered safe for the in-app notification timeline, never a raw message body.
- `revision_review`, `moderator_message_deleted`, `admin_password_reset`, and `system_policy_changed` pushes are non-mutable — a participant cannot silence those categories short of removing all push subscriptions, matching how they're already non-optional in-app. Only `mentioned` has a per-account mute.
- Push is best-effort and never a delivery guarantee or a source of truth; the existing durable in-app notification timeline remains authoritative.
- Push subscriptions and notification preferences are cleared at the same account-tombstone point durable privacy notifications already are.

## [1.3.0] - 2026-08-15

Fourth stable release of the clean ChitChat reconstruction. This release promotes the evaluated `v1.3.0-rc.1`/`v1.3.0-rc.2` feature set — participant search, a moderation queue, and replies/mentions — and adds message reactions on top before stabilizing. Reactions did not go through its own release-candidate evaluation window; it was validated the same way every merge to `main` already is, through full CI (PHPUnit integration tests plus the complete Chromium/Firefox/WebKit browser matrix) on each of its two pull requests.

### Added

- Added authorization-aware PostgreSQL full-text search over current, undeleted room and direct-message bodies, with combined, room-only and direct-only scopes, bounded pagination, privacy-safe POST transport and exact-message deep links.
- Added participant reporting for one specific visible, undeleted room message or incoming direct message, plus an authorization-scoped moderation queue with immutable evidence snapshots, aggregation, assignment and explicit resolution states.
- Added submitted moderation reports to the reporting participant's personal-data export without exposing other reporters, queue assignments or private moderator notes.
- Added durable reply references on room and direct messages, resolved through the same authorization-scoped read path as ordinary history, with a distinct placeholder when the referenced message is unavailable, deleted or expired.
- Added `@username` mentions, plus room-scoped `@room`/`@here` broadcast mentions, resolved and authorized once at send time. Unauthorized or unresolvable tokens render as plain text without notifying anyone.
- Added durable `mentioned` participant notifications, with a human-readable timeline entry and a deep link to the exact message, and a dedicated `RATE_LIMIT_ROOM_BROADCAST_MENTION` policy independent of ordinary room-send throttling.
- Added the account's own sent and received mentions, and submitted moderation reports, to personal-data export, excluding other participants' message bodies.
- Added reply and `@mention` composer support to the room and direct-message browser clients: a reply banner with cancel, a quoted preview of the replied-to message with click-to-scroll, `@mention` autocomplete, and highlighting limited to mentions the server actually resolved and authorized.
- Added reply-target and caption-mention support to room and direct-message attachment uploads, matching ordinary text messages.
- Added message reactions: a small controlled emoji vocabulary (👍 ❤️ 😂 😮 😢 🎉), idempotent add/remove enforced by a database `UNIQUE (message_id, user_id, emoji)` constraint, and a new `message_reaction_changed` realtime event carrying the message's full current reaction state. See [ADR 0005](docs/architecture/0005-reactions.md).
- Added `reactions` to every message-shaped API response (room and direct-message history, send, mutation metadata, attachment uploads), each entry listing the reacting participants by id and username, matching how message authorship is already visible.
- Added a reaction bar to the room and direct-message clients: pills for emoji already in use (click toggles your own reaction) plus an "Add reaction" control for the full vocabulary, updated live via `message_reaction_changed`.

### Security and privacy

- Search enforces room discoverability, membership, invitation, minimum-age and DM-participant authorization inside the query and never joins retained revision bodies; search terms are excluded from URLs, ChitChat audits, rate-limit identifiers, aggregate counters and Prometheus labels.
- Room-scoped moderators see only cases from rooms they currently moderate; global moderation roles may review DM cases but receive only submitted exact-message snapshots rather than surrounding conversation history or attachment bytes. Report bodies, participant details and moderator resolution notes are excluded from moderation audit metadata.
- Reply targets must be in the same room or the same direct-message conversation as the reply; a reply cannot point across rooms or DM threads. This applies equally to attachment uploads.
- Mention authorization is re-checked against current room access and minimum-age eligibility for every candidate, individual or broadcast, and never discloses another participant's message body in the mentioned account's own personal-data export.
- Reacting requires exactly the same authorization as reading the message; there is no new authorization concept. Reacting to an already-deleted message is rejected with `409 message_already_deleted`. Reacting to an existing direct message stays available after a block, matching how reply previews already behave.

### Compatibility

- `v1.3.0` supports an in-place upgrade from stable `v1.2.0`, or promotion of an existing `v1.3.0-rc.1`/`v1.3.0-rc.2` deployment, after PostgreSQL and attachment storage are backed up together.
- The upgrade applies forward-only migrations `0018_message_search.sql`, `0019_moderation_reports.sql`, `0020_replies_mentions.sql` and `0021_reactions.sql` without introducing an external search, queue, cache or moderation service.
- Once these migrations are applied, older ChitChat source must not be pointed at the upgraded database. Rollback requires restoring a matching pre-upgrade database and attachment backup.
- An installation already migrated through `0020_replies_mentions.sql` (i.e. `v1.3.0-rc.2`) needs only `0021_reactions.sql` and a redeployed source tree; no data conversion is required.

### Upgrade notes

Fresh installations should follow `INSTALL.md` and `docs/releases/v1.3.0.md`.

Existing `v1.2.0` installations should:

1. stop or drain application writes;
2. back up PostgreSQL and attachment storage together and verify the backup;
3. deploy `v1.3.0` while preserving `.env` and attachment storage;
4. compare the existing `.env` with `.env.example`;
5. run `composer install --no-dev --classmap-authoritative`;
6. run `composer migrate` once;
7. run `composer maintenance:dry-run` and review the result;
8. verify `/health.php`, `/ready.php`, login, rooms, direct messages, attachments, SSE through the production reverse proxy, system status, participant search, moderation reporting, replies/mentions, and reactions.

Existing `v1.3.0-rc.1`/`v1.3.0-rc.2` installations should create and verify a backup, deploy stable source, change an explicitly pinned `APP_VERSION` to `1.3.0`, run the same Composer, migration, and maintenance commands, and verify representative behavior. `composer migrate` applies only the migrations not already present on that installation.

### Known limitations

- The supported deployment target remains one application server; horizontal scaling and Redis-backed event delivery are not implemented.
- PostgreSQL is the only supported database.
- Direct messages are not end-to-end encrypted.
- No reply/mention/reaction support in administrative DM inspection or revision-review surfaces.
- Maintenance, backup scheduling, retention, alerting, and release-specific manual assistive-technology testing remain operator responsibilities.
- The committed automated accessibility suite is not a substitute for a complete manual WCAG audit.
- No supported in-place upgrade exists from the incomplete legacy `v0.10.25` snapshot.

## [1.3.0-rc.2] - 2026-08-14

Second release candidate for ChitChat v1.3.0, superseding `v1.3.0-rc.1`. This pre-release adds durable replies and `@mention`s (including room-scoped `@room`/`@here` broadcasts) on top of `v1.3.0-rc.1`'s participant search and moderation queue. It is intended for controlled evaluation and upgrade rehearsal before the stable release.

### Added

- Added durable reply references on room and direct messages, resolved through the same authorization-scoped read path as ordinary history, with a distinct placeholder when the referenced message is unavailable, deleted or expired.
- Added `@username` mentions, plus room-scoped `@room`/`@here` broadcast mentions, resolved and authorized once at send time. Unauthorized or unresolvable tokens render as plain text without notifying anyone.
- Added durable `mentioned` participant notifications, with a human-readable timeline entry and a deep link to the exact message, and a dedicated `RATE_LIMIT_ROOM_BROADCAST_MENTION` policy independent of ordinary room-send throttling.
- Added the account's own sent and received mentions to personal-data export, excluding other participants' message bodies.
- Added reply and `@mention` composer support to the room and direct-message browser clients: a reply banner with cancel, a quoted preview of the replied-to message with click-to-scroll, and highlighting limited to mentions the server actually resolved and authorized.
- Added `@mention` autocomplete while composing: room suggestions come from current room membership (plus `@room`/`@here`), direct-message suggestions from the conversation's only possible recipient. A suggestion is a typing convenience only — the server independently re-authorizes every mention at send time regardless of what was suggested or typed.
- Added reply-target and caption-mention support to room and direct-message attachment uploads, matching ordinary text messages.

### Security and privacy

- Reply targets must be in the same room or the same direct-message conversation as the reply; a reply cannot point across rooms or DM threads. This applies equally to attachment uploads.
- Mention authorization is re-checked against current room access and minimum-age eligibility for every candidate, individual or broadcast; a message body's mention count is otherwise unbounded and relies on existing message-length and send rate limits rather than a separate cap.
- Mentions of another participant never disclose that participant's message body in the mentioned account's own personal-data export.
- The mention-search endpoint behind autocomplete is scoped to a room's current membership and re-uses the same history-read authorization boundary as ordinary room access; it is a suggestion source, not an independent authorization surface.

### Compatibility

- `v1.3.0-rc.2` supports an in-place upgrade from stable `v1.2.0`, or from an existing `v1.3.0-rc.1` deployment, after PostgreSQL and attachment storage are backed up together.
- Adds forward-only migration `0020_replies_mentions.sql` on top of `0018_message_search.sql` and `0019_moderation_reports.sql`, without introducing an external notification, queue or search service.
- Once these migrations are applied, older ChitChat source must not be pointed at the upgraded database. Rollback requires restoring a matching pre-upgrade database and attachment backup.
- This is a release candidate: compatible fixes may land before `v1.3.0`, but operators must not assume database or configuration compatibility with later pre-releases without reading their notes.

### Upgrade notes

Fresh installations should follow `INSTALL.md` and the release-candidate evaluation guidance in `docs/releases/v1.3.0-rc.2.md`.

Existing `v1.2.0` installations should:

1. stop or drain application writes;
2. back up PostgreSQL and attachment storage together and verify the backup;
3. deploy `v1.3.0-rc.2` while preserving `.env` and attachment storage;
4. compare the existing `.env` with `.env.example`;
5. run `composer install --no-dev --classmap-authoritative`;
6. run `composer migrate` once;
7. run `composer maintenance:dry-run` and review the result;
8. verify `/health.php`, `/ready.php`, login, rooms, direct messages, attachments, SSE through the production reverse proxy, system status, participant search, moderation reporting, and replies/mentions.

Existing `v1.3.0-rc.1` installations should redeploy source and run `composer migrate` to apply `0020_replies_mentions.sql`; no data conversion is required.

### Known limitations

- The supported deployment target remains one application server; horizontal scaling and Redis-backed event delivery are not implemented.
- PostgreSQL is the only supported database.
- Direct messages are not end-to-end encrypted.
- Maintenance, backup scheduling, retention, alerting, and release-specific manual assistive-technology testing remain operator responsibilities.
- No `@mention` autocomplete for room-broadcast wording beyond the literal `@room`/`@here` keywords, and no reply/mention support in administrative inspection or revision-review surfaces.
- This is a pre-release intended for controlled evaluation rather than an unconditional production recommendation.
- No supported in-place upgrade exists from the incomplete legacy `v0.10.25` snapshot.

## [1.3.0-rc.1] - 2026-08-14

First release candidate for ChitChat v1.3.0. This pre-release adds authorization-aware participant message search and a participant reporting and moderation queue on top of the stable `v1.2.0` baseline. It is intended for controlled evaluation and upgrade rehearsal before the stable release.

### Added

- Added authorization-aware PostgreSQL full-text search over current, undeleted room and direct-message bodies, with combined, room-only and direct-only scopes, bounded pagination, privacy-safe POST transport and exact-message deep links.
- Added participant reporting for one specific visible, undeleted room message or incoming direct message, plus an authorization-scoped moderation queue with immutable evidence snapshots, aggregation, assignment and explicit resolution states.
- Added submitted moderation reports to the reporting participant's personal-data export without exposing other reporters, queue assignments or private moderator notes.

### Security and privacy

- Search enforces room discoverability, membership, invitation, minimum-age and DM-participant authorization inside the query and never joins retained revision bodies.
- Search terms are excluded from URLs, ChitChat audits, rate-limit identifiers, aggregate counters and Prometheus labels.
- Room-scoped moderators see only cases from rooms they currently moderate; global moderation roles may review DM cases but receive only submitted exact-message snapshots rather than surrounding conversation history or attachment bytes.
- Open moderation evidence survives canonical message retention; closed evidence is transactionally linked to the exact closure audit and expires with that audit under configured retention.
- Report bodies, participant details and moderator resolution notes are excluded from moderation audit metadata.

### Compatibility

- `v1.3.0-rc.1` supports an in-place upgrade from stable `v1.2.0` after PostgreSQL and attachment storage are backed up together.
- The upgrade applies forward-only migrations `0018_message_search.sql` and `0019_moderation_reports.sql` without introducing an external search, queue, cache or moderation service.
- Once these migrations are applied, older ChitChat source must not be pointed at the upgraded database. Rollback requires restoring a matching pre-upgrade database and attachment backup.
- This is a release candidate: compatible fixes may land before `v1.3.0`, but operators must not assume database or configuration compatibility with later pre-releases without reading their notes.

### Upgrade notes

Fresh installations should follow `INSTALL.md` and the release-candidate evaluation guidance in `docs/releases/v1.3.0-rc.1.md`.

Existing `v1.2.0` installations should:

1. stop or drain application writes;
2. back up PostgreSQL and attachment storage together and verify the backup;
3. deploy `v1.3.0-rc.1` while preserving `.env` and attachment storage;
4. compare the existing `.env` with `.env.example`;
5. run `composer install --no-dev --classmap-authoritative`;
6. run `composer migrate` once;
7. run `composer maintenance:dry-run` and review the result;
8. verify `/health.php`, `/ready.php`, login, rooms, direct messages, attachments, SSE through the production reverse proxy, system status, participant search, and moderation reporting.

### Known limitations

- The supported deployment target remains one application server; horizontal scaling and Redis-backed event delivery are not implemented.
- PostgreSQL is the only supported database.
- Direct messages are not end-to-end encrypted.
- Maintenance, backup scheduling, retention, alerting, and release-specific manual assistive-technology testing remain operator responsibilities.
- This is a pre-release intended for controlled evaluation rather than an unconditional production recommendation.
- No supported in-place upgrade exists from the incomplete legacy `v0.10.25` snapshot.

## [1.2.0] - 2026-07-18

Third stable release of the clean ChitChat reconstruction. This release promotes the `v1.2.0-rc.1` feature set after controlled evaluation completed without a reported defect. It adds deployment-configurable throttling, supported backup and restore tooling, account closure and restoration, passkey multi-factor authentication, participant-facing privacy notifications, and deeper accessibility and visual-regression coverage.

### Stabilized since 1.2.0-rc.1

- No application defect was reported during the release-candidate evaluation period.
- No database migration, runtime behavior, API contract, privacy policy, retention rule, production dependency, or deployment configuration requirement changed after the release candidate.
- Stabilized the WebKit structural-accessibility test by waiting for the asynchronous MFA account summary before enumerating visible account controls; this is a test-only timing fix.
- Updated version declarations, tests, repository status, changelog, and release metadata for stable `v1.2.0`.

### Added

- Added bounded named rate-limit policies and aggregate privacy-preserving counters for authentication, account, messaging, upload, invitation, search, inspection, revision-review, restoration, and MFA paths.
- Added supported manifest-bound PostgreSQL-plus-attachment backup, verification, safe staged restore, and dedicated backup-rehearsal automation.
- Added step-up-protected account closure, a 14-day cooling-off and restoration flow, maintenance-driven profile tombstoning, and final-Super-Administrator protection.
- Added optional password-first WebAuthn MFA with multiple passkeys, ten one-time recovery codes, MFA-aware login/step-up/restoration, and enforceable administrative-MFA policy.
- Added durable participant-facing privacy notifications for revision review, moderator deletion, administrative password reset, and material installation-policy changes.
- Added pinned axe-core, reflow, forced-colors, reduced-motion, screenshot-regression, and explicit human assistive-technology review procedures.

### Security and privacy

- Rate-limit configuration remains deployment-only and aggregate counters contain only fixed policy names and coarse totals.
- Backup manifests contain no database password; publication requires self-verification, and restore rejects unsafe archives and accidental production replacement.
- Closure destroys private credentials and profile identifiers at finalization while preserving shared retained conversation history and immutable evidence according to policy.
- MFA-enabled accounts cannot establish an authenticated session or privileged step-up from a password alone; recovery-code plaintext is revealed only on creation and each code is consumed atomically once.
- Selected participant notifications commit atomically with their append-only audit source and exclude restricted bodies, reasons, usernames, IP addresses, credentials, passkey data, and recovery material.
- Automated accessibility results remain separate from release-specific manual NVDA and VoiceOver sign-off.

### Compatibility

- `v1.2.0` supports an in-place upgrade from stable `v1.1.0` after PostgreSQL and attachment storage are backed up together.
- The upgrade applies forward-only migrations `0014_rate_limit_observability.sql`, `0015_account_closure.sql`, `0016_mfa_passkeys.sql`, and `0017_privacy_notifications.sql`.
- An installation already migrated for `v1.2.0-rc.1` requires no additional database migration, data conversion, runtime dependency, or configuration redesign.
- Once migrations `0014`–`0017` are applied, older ChitChat source must not be pointed at the upgraded database. Rollback requires restoring a matching pre-upgrade database and attachment backup.
- Passkeys remain disabled unless both `WEBAUTHN_RP_ID` and `WEBAUTHN_ORIGIN` are configured. Production WebAuthn origins require HTTPS; the PHP OpenSSL extension is required when passkeys are enabled.
- Existing password-only installations retain password login and password step-up behavior while WebAuthn remains unconfigured.
- No external identity service, queue, Redis deployment, or other new production runtime service is required. Axe-core and screenshot comparison are development-only npm dependencies.

### Upgrade notes

Fresh installations should follow `INSTALL.md` and `docs/releases/v1.2.0.md`.

Existing `v1.1.0` installations should:

1. stop or drain application writes;
2. back up PostgreSQL and attachment storage together and verify the backup;
3. deploy `v1.2.0` while preserving `.env` and attachment storage;
4. compare the existing `.env` with `.env.example`, keeping WebAuthn disabled unless a durable HTTPS RP ID and origin have been chosen;
5. run `composer install --no-dev --classmap-authoritative`;
6. run `composer migrate` once;
7. run `composer maintenance:dry-run` and review the result;
8. verify `/health.php`, `/ready.php`, login, rooms, direct messages, attachments, SSE through the production reverse proxy, system status, account closure/restoration, backup verification, privacy notifications, and any enabled passkey flow.

Existing `v1.2.0-rc.1` installations should create and verify a backup, deploy stable source, change an explicitly pinned `APP_VERSION` to `1.2.0`, run the same Composer, migration, and maintenance commands, and verify representative behavior. `composer migrate` should find no new stable-release migration.

### Known limitations

- The supported deployment target remains one application server; horizontal scaling and Redis-backed event delivery are not implemented.
- PostgreSQL is the only supported database.
- Direct messages are not end-to-end encrypted.
- Maintenance, backup scheduling, retention, alerting, and release-specific manual assistive-technology testing remain operator responsibilities.
- The committed automated accessibility suite is not a substitute for a complete manual WCAG audit.
- No supported in-place upgrade exists from the incomplete legacy `v0.10.25` snapshot.

## [1.2.0-rc.1] - 2026-07-18

First release candidate for ChitChat v1.2.0. This pre-release adds deployment-configurable throttling, supported backup and restore tooling, account closure and restoration, passkey multi-factor authentication, participant-facing privacy notifications, and deeper accessibility and visual-regression coverage. It is intended for controlled evaluation and upgrade rehearsal before the stable release.

### Added

- Added bounded named rate-limit policies for authentication, account, messaging, upload, invitation, search, inspection, revision-review, restoration, and MFA paths, configured through deployment environment variables.
- Added aggregate privacy-preserving allowed/rejected rate-limit counters to Administrator system status and Prometheus without account, IP, room, message, search-term, or request-body identifiers.
- Added supported `chitchat-backup`, `chitchat-verify-backup`, and `chitchat-restore` commands with a versioned manifest binding PostgreSQL and attachment storage by exact size and SHA-256 checksum.
- Added safe staged restore into new targets by default, explicit destructive-replacement flags, attachment archive traversal/link/special-file rejection, and a dedicated backup-rehearsal CI gate.
- Added step-up-protected account closure with immediate session invalidation, global-role revocation, a fixed 14-day cooling-off period, independently throttled credential-based restoration, and maintenance-driven irreversible tombstoning.
- Added optional password-first WebAuthn multi-factor authentication with multiple labelled ES256 or RS256 passkeys, ten one-time recovery codes, account credential management, and passkey or recovery-code completion for login, privileged step-up, and restoration.
- Added optional Super-Administrator enforcement of passkey MFA for all global administrative roles, with transactional activation checks and a PostgreSQL role-assignment invariant.
- Added durable participant-facing privacy notifications for administrative revision review, moderator room-message deletion, administrative password reset, and material installation-policy changes.
- Added a paginated signed-in notification center, account-scoped individual and bulk read state, and a capped unread badge.
- Added pinned axe-core WCAG A/AA analysis for core signed-out and signed-in surfaces, Chromium reflow checks at 640 and 320 CSS pixels, forced-colors and reduced-motion checks, and targeted pinned-Chromium/Linux screenshot regression for stable authentication and account layouts.
- Added explicit manual NVDA, VoiceOver, keyboard-only, browser-zoom, Windows contrast-theme, and reduced-motion review procedures with versioned result recording.

### Security and privacy

- Rate-limit configuration remains deployment-only so a compromised browser administration session cannot weaken anti-abuse controls; aggregate counters use only fixed policy names and coarse totals.
- Backup manifests contain no database password, backup sets publish only after self-verification, and restore refuses unsafe archives, public-root overlap, and configured production targets without explicit acknowledgement.
- Closure preserves shared room and direct-message history, immutable revisions, attachment evidence, membership and ownership attribution, bans, and audits according to retention policy while destroying private credentials and profile identifiers at finalization.
- A correct password for an MFA-enabled account creates only a short-lived pending context and does not establish an authenticated session or successful login until a passkey or unused recovery code succeeds.
- Recovery-code plaintext is returned only when a set is created or replaced; PostgreSQL stores SHA-256 hashes of 96-bit random values and consumes codes atomically once.
- Selected privacy notifications are derived from append-only audit records inside the same PostgreSQL transaction and contain only a fixed event kind, recipient, nullable audit reference, bounded structural context, and read timestamps.
- Notification context excludes message and revision bodies, administrator or moderation reasons, usernames, IP addresses, credentials, session state, passkey data, and recovery material.
- Automated accessibility results remain separate from release-specific manual assistive-technology sign-off; green axe, emulation, and screenshot checks do not claim completed NVDA or VoiceOver validation.

### Compatibility

- `v1.2.0-rc.1` supports an in-place upgrade from stable `v1.1.0` after PostgreSQL and attachment storage are backed up together.
- The upgrade applies forward-only migrations `0014_rate_limit_observability.sql`, `0015_account_closure.sql`, `0016_mfa_passkeys.sql`, and `0017_privacy_notifications.sql`.
- Once these migrations are applied, older ChitChat source must not be pointed at the upgraded database. Rollback requires restoring a matching pre-upgrade database and attachment backup.
- Passkeys remain disabled unless both `WEBAUTHN_RP_ID` and `WEBAUTHN_ORIGIN` are configured. Production WebAuthn origins require HTTPS; the PHP OpenSSL extension is required when passkeys are enabled.
- Existing password-only installations retain password login and password step-up behavior while WebAuthn remains unconfigured.
- No external identity service, queue, Redis deployment, or other new production runtime service is required. Axe-core and screenshot comparison are development-only npm dependencies.
- This is a release candidate: compatible fixes may land before `v1.2.0`, but operators must not assume database or configuration compatibility with later pre-releases without reading their notes.

### Upgrade notes

Fresh installations should follow `INSTALL.md` and the release-candidate evaluation guidance in `docs/releases/v1.2.0-rc.1.md`.

Existing `v1.1.0` installations should:

1. stop or drain application writes;
2. back up PostgreSQL and attachment storage together and verify the backup;
3. deploy `v1.2.0-rc.1` while preserving `.env` and attachment storage;
4. compare the existing `.env` with `.env.example`, keeping WebAuthn disabled unless a durable HTTPS RP ID and origin have been chosen;
5. run `composer install --no-dev --classmap-authoritative`;
6. run `composer migrate` once;
7. run `composer maintenance:dry-run` and review the result;
8. verify `/health.php`, `/ready.php`, login, rooms, direct messages, attachments, SSE through the production reverse proxy, system status, account closure/restoration, backup verification, privacy notifications, and any enabled passkey flow.

### Known limitations

- The supported deployment target remains one application server; horizontal scaling and Redis-backed event delivery are not implemented.
- PostgreSQL is the only supported database.
- Direct messages are not end-to-end encrypted.
- Maintenance, backup scheduling, retention, alerting, and release-specific manual assistive-technology testing remain operator responsibilities.
- The committed automated accessibility suite is not a substitute for a complete manual WCAG audit.
- This is a pre-release intended for controlled evaluation rather than an unconditional production recommendation.
- No supported in-place upgrade exists from the incomplete legacy `v0.10.25` snapshot.

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
