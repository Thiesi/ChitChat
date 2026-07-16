# ChitChat development installation

This document applies to the v1 reconstruction. The application is not yet feature-complete or suitable for production use.

## Requirements

- PHP 8.2 or newer
- Composer 2
- PostgreSQL 15 or newer
- PHP extensions: `pdo`, `pdo_pgsql`, `json`, `mbstring`

## Setup

1. Create a PostgreSQL database and account.
2. Copy the example environment file:

   ```sh
   cp .env.example .env
   ```

3. Adjust the database values in `.env`.
4. For plain HTTP local development, keep `SESSION_COOKIE_SECURE=0`. Production HTTPS deployments must use `SESSION_COOKIE_SECURE=1`.
5. Install dependencies:

   ```sh
   composer install
   ```

6. Apply the migrations:

   ```sh
   composer migrate
   ```

7. Start PHP's built-in server with `public/` as the document root:

   ```sh
   php -S 127.0.0.1:8080 -t public
   ```

8. Open `http://127.0.0.1:8080/`.

## Authentication bootstrap

API clients begin by requesting:

```text
GET /api/v1/session.php
```

The response contains a `csrf_token`. Send that value in the `X-CSRF-Token` header for every state-changing request, including registration and login.

The first successfully registered account is promoted to `super_admin` inside the same database transaction that creates it. Concurrent first registrations are serialized by locking the single system-settings row.

## Endpoints

- `/health.php` reports whether the PHP process is alive.
- `/ready.php` verifies that the application can connect to PostgreSQL.
- Authentication and account-control endpoints are documented in `docs/api/authentication.md`.

## Production web-root rule

The web server document root must be the repository's `public/` directory. Do not expose `src/`, `bootstrap/`, `migrations/`, `.env`, `var/`, or Composer metadata.

Use HTTPS, set `SESSION_COOKIE_SECURE=1`, and leave `SESSION_COOKIE_SAMESITE=Lax` unless deployment requirements justify a stricter setting. Do not use `SameSite=None` without secure cookies.

## Tests and checks

```sh
composer check
```

The integration suite expects a migrated PostgreSQL database described by the current environment variables. It clears authentication-related tables between tests and must never be pointed at a database containing valuable data.
