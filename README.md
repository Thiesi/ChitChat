# ChitChat

ChitChat is being reconstructed as a small, self-hosted browser chat application.

## Repository status

The former `v0.10.25` source snapshot is incomplete and is not considered runnable or production-ready. It is preserved on the `legacy/v0.10.25` branch for reference.

The clean v1 implementation is developed on reviewable branches and merged through CI-backed pull requests.

## v1 architecture

- PHP 8.2 or newer
- PostgreSQL
- PDO
- Vanilla browser JavaScript
- PHP sessions and CSRF protection
- Server-Sent Events for realtime delivery
- Database-backed expiring presence leases
- A single application server as the initial deployment target
- Only `public/` exposed by the web server
- Opaque attachment storage outside the public web root

The architectural decisions are recorded in [`docs/architecture/`](docs/architecture/).

## Current capabilities

The application currently provides:

- environment-based configuration and forward-only PostgreSQL migrations;
- health and readiness endpoints;
- user registration and case-insensitive login;
- optional validated birth dates for age-restricted rooms;
- atomic first-user Super-Administrator promotion;
- secure session cookies, CSRF protection, a restrictive CSP, HSTS on secure deployments, and related browser security headers;
- password changes and administrator password resets;
- database-backed login and request throttling shared by all PHP workers;
- kicks, temporary or indefinite bans, and unbans;
- session-version invalidation for active sessions;
- public, unlisted, and invitation-only private rooms;
- room owners, moderators, members, minimum-age enforcement, and optional inactivity policies;
- persistent room-message history with pagination;
- text, `/me`, and targeted `/ping` commands;
- database-backed ordered realtime events and SSE cursor reconnection;
- room and global broadcasts;
- forced-disconnect event delivery for account-control actions;
- tab-scoped presence leases with aggregated online-user lists;
- inactivity warnings and active-room expiry without membership removal;
- audited soft deletion of messages by authorized moderators;
- room attachments with MIME and size allowlists, SHA-256 metadata, safe image previews, and authorization-aware downloads;
- permanent-by-default two-party direct-message history, unread counts, cursor pagination and targeted realtime events;
- an unavoidable direct-message privacy notice stating that messages are not end-to-end encrypted;
- configurable administrative DM inspection, restricted to Super-Administrators by default and audited on every successful page access;
- Super-Administrator management of registration and retention policy;
- dry-run-capable cleanup for retained content, deleted and orphaned attachments, events, presence, login attempts, and throttle rows;
- a responsive browser client for registration, login, rooms, history, live messages, commands, presence, attachments, direct messages, and logout;
- a permission-aware browser administration console for users, roles, bans, room settings, membership, invitations, audit visibility, eligible DM inspection, and operational settings;
- backup, restore, maintenance, deployment, and browser-testing documentation;
- audit records for sensitive account, room, attachment, inspection, settings, and maintenance actions;
- PHPUnit, PHPStan, JavaScript syntax checks, PostgreSQL-backed integration tests, and a real Chromium end-to-end journey in CI.

Direct-message attachments, configurable per-limit throttles, wider browser coverage, and horizontal scaling remain future milestones.

## Development

See [INSTALL.md](INSTALL.md). API contracts are documented in [`docs/api/`](docs/api/), and operating procedures in [`docs/operations/`](docs/operations/).
