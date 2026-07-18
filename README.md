# ChitChat

ChitChat is a small, self-hosted browser chat application. The clean reconstruction has reached **v1.1.0**, its second stable release.

## Repository status

`v1.1.0` is the supported baseline for fresh single-server deployments and in-place upgrades from stable `v1.0.0`. Operators should review the documented privacy defaults, forward-only migrations, resource requirements, maintenance schedule, backup procedure, and known limitations before exposing an installation to users.

The former `v0.10.25` source snapshot is incomplete and is not considered runnable or a supported upgrade predecessor. It is preserved on the `legacy/v0.10.25` branch for reference.

See [CHANGELOG.md](CHANGELOG.md), the [v1.1.0 release notes](docs/releases/v1.1.0.md), and the [project roadmap](docs/roadmap.md).

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
- PostgreSQL-backed named rate-limit policies for authentication, MFA, messaging, uploads, invitations, searches, exports, restoration, and sensitive administrative reads, with bounded environment configuration and aggregate privacy-preserving decision counters;
- kicks, temporary or indefinite bans, and unbans;
- session-version invalidation for active sessions and privileged elevation;
- a user-facing, step-up-protected JSON export of retained account data with explicit privacy boundaries and audited generation;
- step-up-protected account closure with immediate session invalidation, a 14-day cooling-off period, MFA-preserving restoration, maintenance-driven profile tombstoning, and documented username-reuse and retained-shared-data rules;
- durable participant-facing notifications for revision review, moderator room-message deletion, administrator password reset, and material installation-policy changes, with bounded context, account-scoped read state, and an unread badge;
- public, unlisted, and invitation-only private rooms;
- room owners, moderators, members, minimum-age enforcement, and optional inactivity policies;
- persistent room-message history with pagination;
- text, `/me`, and targeted `/ping` commands;
- database-backed ordered realtime events and SSE cursor reconnection;
- room and global broadcasts;
- forced-disconnect event delivery for account-control actions;
- tab-scoped presence leases with aggregated online-user lists;
- inactivity warnings and active-room expiry without membership removal;
- audited moderator deletion plus author editing and delete-for-everyone controls backed by immutable revision ledgers;
- room attachments with MIME and size allowlists, SHA-256 metadata, safe image previews, authorization-aware downloads, editable captions, and retained deletion evidence;
- permanent-by-default two-party direct-message history, unread counts, cursor pagination, targeted realtime events, blocking, editing, delete-for-everyone, and file attachments;
- an unavoidable direct-message privacy notice stating that messages are not end-to-end encrypted and that edits and deletions retain historical bodies until message retention removes them;
- configurable administrative DM inspection, restricted to Super-Administrators by default, protected by recent step-up, and audited on every successful page access;
- separately configurable, disabled-by-default administrative review of exact room or DM revision chains, with recent step-up, a required reason, a successful-access audit that never duplicates historical bodies, and participant-facing disclosure;
- Super-Administrator management of registration, administrative-MFA enforcement, and retention policy, protected by recent step-up for changes;
- dry-run-capable cleanup for retained content, deleted and orphaned room/DM attachments, events, presence, SSE leases, login attempts, throttle rows, and due account closures;
- durable success/failure records for maintenance invocations and ready-to-adapt `systemd` service/timer units;
- manifest-bound backup, verification, and safe restore commands covering PostgreSQL and attachment storage together, plus ready-to-adapt scheduled-backup units;
- a responsive browser client for registration, password-first MFA login, rooms, history, live messages, commands, presence, attachments, direct messages, privacy notifications, account security/export/closure/restoration, and logout;
- a permission-aware browser administration console for users, roles, bans, room settings, membership, invitations, audit visibility, eligible DM inspection, exact-ID revision review, operational settings, and system status;
- backup, restore, maintenance, observability, deployment, release, account-lifecycle, passkey/MFA, privacy-notification, accessibility-review, and browser-testing documentation;
- audit records for sensitive account, authentication, MFA, room, message, attachment, inspection, revision-review, settings, export, closure, and maintenance actions;
- PHP lint, PHPStan level 8, JavaScript syntax checks, PostgreSQL-backed integration tests and maintenance validation;
- independent two-session Chromium, Firefox, and WebKit browser journeys, a Chromium virtual-WebAuthn-authenticator journey, and cross-browser structural and keyboard accessibility checks;
- pinned Chromium axe-core WCAG A/AA analysis, document-reflow, forced-colors and reduced-motion checks, plus targeted Linux screenshot regression for stable authentication and account layouts;
- published-release archive installation, first-class backup/restore and forward-upgrade rehearsal;
- real Nginx/PHP-FPM validation of authenticated, unbuffered SSE delivery.

Horizontal scaling, release-specific manual assistive-technology sign-off, and richer compliance/reporting workflows remain deliberate ongoing or future work.

## Installation and operation

See [INSTALL.md](INSTALL.md). API contracts are documented in [`docs/api/`](docs/api/), operating procedures in [`docs/operations/`](docs/operations/), and release procedures in [`docs/releases/`](docs/releases/).
