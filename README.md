# ChitChat

ChitChat is being reconstructed as a small, self-hosted browser chat application.

## Repository status

The former `v0.10.25` source snapshot is incomplete and is not considered runnable or production-ready. It is preserved on the `legacy/v0.10.25` branch for reference.

Development of the new application takes place on `agent/reconstruction-v1` until the v1 foundation is ready to merge.

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

This branch currently provides the application skeleton:

- environment-based configuration;
- PDO connection handling;
- PostgreSQL migrations;
- health and readiness endpoints;
- PHPUnit and PHPStan configuration;
- CI against PostgreSQL.

Authentication and chat functionality are intentionally not present yet.

## Development

See [INSTALL.md](INSTALL.md).
