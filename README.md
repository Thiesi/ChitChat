# ChitChat

ChitChat is a small, self-hosted browser chat application. The clean reconstruction has reached **v1.0.0**, its first stable release.

## Repository status

`v1.0.0` is the supported baseline for fresh single-server deployments. Operators should review the documented privacy defaults, resource requirements, maintenance schedule, backup procedure, and known limitations before exposing an installation to users.

The former `v0.10.25` source snapshot is incomplete and is not considered runnable or a supported upgrade predecessor. It is preserved on the `legacy/v0.10.25` branch for reference.

See [CHANGELOG.md](CHANGELOG.md) and the [v1.0.0 release notes](docs/releases/v1.0.0.md).

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
- short-lived, session-version-bound current-password step-up authentication for sensitive administrative actions;
- database-backed login, step-up, and request throttling shared by all PHP workers;
- kicks, temporary or indefinite bans, and unbans;
- session-version invalidation for active sessions and privileged elevation;
- a user-facing, step-up-protected JSON export of retained account data with explicit privacy boundaries and audited generation;
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
- separately configurable, disabled-by-default administrative review of exact room or DM revision chains, with recent step-up, a required reason, and a successful-access audit that never duplicates historical bodies;
- Super-Administrator management of registration and retention policy, protected by recent step-up for changes;
- dry-run-capable cleanup for retained content, deleted and orphaned room/DM attachments, events, presence, SSE leases, login attempts, and throttle rows;
- durable success/failure records for maintenance invocations and ready-to-adapt `systemd` service/timer units;
- a responsive browser client for registration, login, rooms, history, live messages, commands, presence, attachments, direct messages, account export, and logout;
- a permission-aware browser administration console for users, roles, bans, room settings, membership, invitations, audit visibility, eligible DM inspection, exact-ID revision review, operational settings, and system status;
- backup, restore, maintenance, observability, deployment, release, and browser-testing documentation;
- audit records for sensitive account, authentication, room, message, attachment, inspection, revision-review, settings, export, and maintenance actions;
- PHP lint, PHPStan level 8, JavaScript syntax checks, PostgreSQL-backed integration tests and maintenance validation;
- independent two-session Chromium and Firefox browser journeys;
- published-release archive installation, backup/restore and forward-upgrade rehearsal;
- real Nginx/PHP-FPM validation of authenticated, unbuffered SSE delivery.

Multi-factor authentication, configurable per-limit throttles, WebKit browser coverage, horizontal scaling, participant-facing review notifications, account closure, and richer compliance/reporting workflows remain future milestones.

## Installation and operation

See [INSTALL.md](INSTALL.md). API contracts are documented in [`docs/api/`](docs/api/), operating procedures in [`docs/operations/`](docs/operations/), and release procedures in [`docs/releases/`](docs/releases/).
