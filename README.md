# ChitChat

ChitChat is a small, self-hosted browser chat application. The clean reconstruction has reached **v1.3.0**, the fourth stable release, adding participant search, a moderation queue, replies/mentions, and message reactions on top of the stable `v1.2.0` baseline.

## Repository status

`v1.3.0` is now the supported stable baseline, superseding `v1.2.0` and the `v1.3.0-rc.1`/`v1.3.0-rc.2` release candidates. It applies forward-only migrations `0018_message_search.sql`, `0019_moderation_reports.sql`, `0020_replies_mentions.sql` and `0021_reactions.sql` in place from `v1.2.0`. Operators deploying it must back up PostgreSQL and attachment storage together and must not point older source at the migrated database. Installations already running a `v1.3.0` release candidate need only redeploy stable source and, for `rc.2`, apply `0021_reactions.sql`; no data conversion is required.

The former `v0.10.25` source snapshot is incomplete and is not considered runnable or a supported upgrade predecessor. It is preserved on the `legacy/v0.10.25` branch for reference.

See [CHANGELOG.md](CHANGELOG.md), the [v1.3.0 stable release notes](docs/releases/v1.3.0.md), the [v1.2.0 stable release notes](docs/releases/v1.2.0.md), and the [project roadmap](docs/roadmap.md).

## v1 architecture

- PHP 8.2 or newer
- PostgreSQL
- PDO
- Vanilla browser JavaScript
- PHP sessions and CSRF protection
- Server-Sent Events for realtime delivery
- Database-backed expiring presence and SSE-connection leases
- A single application server as the initial deployment target
- Only `public/` exposed by the web server
- Opaque attachment storage outside the public web root

The architectural decisions are recorded in [`docs/architecture/`](docs/architecture/).

## Current capabilities

The application currently provides:

- environment-based configuration and forward-only PostgreSQL migrations;
- health and readiness endpoints;
- an Administrator system-status page and an optional bearer-protected Prometheus endpoint;
- user registration and case-insensitive login;
- optional validated birth dates for age-restricted rooms;
- atomic first-user Super-Administrator promotion;
- secure session cookies, CSRF protection, a restrictive CSP, HSTS on secure deployments, and related browser security headers;
- password changes and administrator password resets;
- optional password-first WebAuthn multi-factor authentication with multiple passkeys, one-time recovery codes, exact RP/origin/challenge validation, user verification, and account-facing credential management;
- short-lived, session-version-bound privileged step-up that uses the current password for non-MFA accounts and a passkey or recovery code for MFA accounts;
- optional Super-Administrator enforcement of passkey MFA for all global administrative roles, validated transactionally and backed by a PostgreSQL role-assignment invariant;
- PostgreSQL-backed named rate-limit policies for authentication, MFA, messaging, uploads, invitations, participant search, reports, moderation actions, exports, restoration, and sensitive administrative reads, with bounded environment configuration and aggregate privacy-preserving decision counters;
- kicks, temporary or indefinite bans, and unbans;
- session-version invalidation for active sessions and privileged elevation;
- a user-facing, step-up-protected JSON export of retained account data with explicit privacy boundaries and audited generation;
- step-up-protected account closure with immediate session invalidation, a 14-day cooling-off period, MFA-preserving restoration, maintenance-driven profile tombstoning, and documented username-reuse and retained-shared-data rules;
- durable participant-facing notifications for revision review, moderator room-message deletion, administrator password reset, material installation-policy changes, and mentions, with bounded context, account-scoped read state, and an unread badge;
- optional Web Push delivery of that same notification set to subscribed browsers, with a per-category mute for mentions, per-account quiet hours, per-device subscription management, and delivery through a periodic operator-scheduled sweep rather than a request-time side effect;
- public, unlisted, and invitation-only private rooms;
- room owners, moderators, members, minimum-age enforcement, and optional inactivity policies;
- persistent room-message history with pagination;
- authorization-aware PostgreSQL full-text search over current undeleted room and direct-message bodies, with room discoverability, membership, invitation, minimum-age and DM-participant rules enforced inside the query;
- privacy-safe search transport that keeps terms out of URLs, ChitChat audits, rate-limit identifiers and aggregate metric labels, plus exact-message deep links through ordinary history APIs;
- text, `/me`, and targeted `/ping` commands;
- database-backed ordered realtime events and SSE cursor reconnection;
- room and global broadcasts;
- forced-disconnect event delivery for account-control actions;
- tab-scoped presence leases with aggregated online-user lists;
- inactivity warnings and active-room expiry without membership removal;
- audited moderator deletion plus author editing and delete-for-everyone controls backed by immutable revision ledgers;
- participant reporting of one specific visible, undeleted room message or incoming direct message through a bounded accessible form;
- an authorization-scoped moderation queue with immutable submitted snapshots, aggregation, assignment, open/in-review/resolved/dismissed states and explicit outcome recording;
- room-owner and room-moderator access limited to their current rooms, while global moderation roles may review DM reports without receiving surrounding conversation history;
- retention-aware moderation evidence that survives canonical message cleanup while active and expires with the exact closure audit under configured audit retention;
- room attachments with MIME and size allowlists, SHA-256 metadata, safe image previews, authorization-aware downloads, editable captions, and retained deletion evidence;
- durable reply references and `@username`/`@room`/`@here` mentions on room and direct messages, resolved and authorized at send time, with composer support (reply banner, quoted preview, `@mention` autocomplete) and a durable `mentioned` notification;
- message reactions from a small controlled emoji vocabulary, idempotent add/remove, authorization matching ordinary message-read access, and realtime delivery through the existing event system;
- permanent-by-default two-party direct-message history, unread counts, cursor pagination, targeted realtime events, blocking, editing, delete-for-everyone, and file attachments;
- an unavoidable direct-message privacy notice stating that messages are not end-to-end encrypted and that edits and deletions retain historical bodies until message retention removes them;
- configurable administrative DM inspection, restricted to Super-Administrators by default, protected by recent step-up, and audited on every successful page access;
- separately configurable, disabled-by-default administrative review of exact room or DM revision chains, with recent step-up, a required reason, a successful-access audit that never duplicates historical bodies, and participant-facing disclosure;
- Super-Administrator management of registration, administrative-MFA enforcement, and retention policy, protected by recent step-up for changes;
- dry-run-capable cleanup for retained content, closed moderation evidence, deleted and orphaned room/DM attachments, events, presence, SSE leases, login attempts, throttle rows, and due account closures;
- durable success/failure records for maintenance invocations and ready-to-adapt `systemd` service/timer units;
- manifest-bound backup, verification, and safe restore commands covering PostgreSQL and attachment storage together, plus ready-to-adapt scheduled-backup units;
- a responsive browser client for registration, password-first MFA login, rooms, history, message search, reporting, live messages, commands, presence, attachments, direct messages, privacy notifications, account security/export/closure/restoration, and logout;
- a permission-aware browser administration and moderation surface for users, roles, bans, room settings, membership, invitations, report cases, audit visibility, eligible DM inspection, exact-ID revision review, operational settings, and system status;
- backup, restore, maintenance, observability, deployment, release, account-lifecycle, passkey/MFA, privacy-notification, search, moderation-reporting, accessibility-review, and browser-testing documentation;
- audit records for sensitive account, authentication, MFA, room, message, attachment, inspection, revision-review, report, moderation-case, settings, export, closure, and maintenance actions;
- PHP lint, PHPStan level 8, JavaScript syntax checks, PostgreSQL-backed integration tests and maintenance validation;
- independent two-session Chromium, Firefox, and WebKit browser journeys, a Chromium virtual-WebAuthn-authenticator journey, and cross-browser structural and keyboard accessibility checks;
- pinned Chromium axe-core WCAG A/AA analysis, document-reflow, forced-colors and reduced-motion checks, plus targeted Linux screenshot regression for stable authentication and account layouts;
- published-release archive installation, first-class backup/restore and forward-upgrade rehearsal;
- real Nginx/PHP-FPM validation of authenticated, unbuffered SSE delivery.

Horizontal scaling and release-specific manual assistive-technology sign-off remain possible future work. Optional external identity (OpenID Connect) integration is postponed indefinitely — see the [roadmap](docs/roadmap.md) for why.

## Installation and operation

See [INSTALL.md](INSTALL.md). API contracts are documented in [`docs/api/`](docs/api/), operating procedures in [`docs/operations/`](docs/operations/), and release procedures in [`docs/releases/`](docs/releases/).
