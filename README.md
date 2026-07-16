# ChitChat

ChitChat is being reconstructed as a small, self-hosted browser chat application.

## Repository status

The former `v0.10.25` source snapshot is incomplete and is not considered runnable or production-ready. It is preserved on the `legacy/v0.10.25` branch for reference.

The clean v1 foundation is now on `main`. Feature work is developed on reviewable branches and merged through CI-backed pull requests.

## v1 architecture

- PHP 8.2 or newer
- PostgreSQL
- PDO
- Vanilla browser JavaScript
- PHP sessions and CSRF protection
- Server-Sent Events for realtime delivery
- A single application server as the initial deployment target
- Only `public/` exposed by the web server

The architectural decisions are recorded in [`docs/architecture/`](docs/architecture/).

## Current milestone

The application currently provides:

- environment-based configuration;
- PostgreSQL migrations;
- health and readiness endpoints;
- user registration and case-insensitive login;
- atomic first-user Super-Administrator promotion;
- secure session cookies and CSRF protection;
- password changes and administrator password resets;
- database-backed login throttling;
- kicks, temporary or indefinite bans, and unbans;
- session-version invalidation for active sessions;
- audit records for sensitive account actions;
- PHPUnit, PHPStan, and PostgreSQL-backed CI.

Room chat and realtime message delivery remain the next major milestones.

## Development

See [INSTALL.md](INSTALL.md). The authentication API is documented in [docs/api/authentication.md](docs/api/authentication.md).
