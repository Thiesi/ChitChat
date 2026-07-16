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

The architectural decisions are recorded in [`docs/architecture/`](docs/architecture/).

## Current capabilities

The application currently provides:

- environment-based configuration and PostgreSQL migrations;
- health and readiness endpoints;
- user registration and case-insensitive login;
- optional validated birth dates for age-restricted rooms;
- atomic first-user Super-Administrator promotion;
- secure session cookies and CSRF protection;
- password changes and administrator password resets;
- database-backed login throttling;
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
- a responsive browser client for registration, login, room creation and joining, history, live messages, commands, presence, and logout;
- audit records for sensitive account and room actions;
- PHPUnit, PHPStan, JavaScript syntax checks, and PostgreSQL-backed CI.

Attachments, direct messages, and administration screens remain future milestones.

## Development

See [INSTALL.md](INSTALL.md). API contracts are documented in [`docs/api/`](docs/api/).
