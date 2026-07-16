# ChitChat development installation

This document applies to the v1 reconstruction branch. The application is not yet feature-complete or suitable for production use.

## Requirements

- PHP 8.2 or newer
- Composer 2
- PostgreSQL 15 or newer
- PHP extensions: `pdo`, `pdo_pgsql`, `json`

## Setup

1. Create a PostgreSQL database and account.
2. Copy the example environment file:

   ```sh
   cp .env.example .env
   ```

3. Adjust the database values in `.env`.
4. Install dependencies:

   ```sh
   composer install
   ```

5. Apply the migrations:

   ```sh
   composer migrate
   ```

6. For local development, start PHP's built-in server with `public/` as the document root:

   ```sh
   php -S 127.0.0.1:8080 -t public
   ```

7. Open `http://127.0.0.1:8080/`.

## Endpoints

- `/health.php` reports whether the PHP process is alive.
- `/ready.php` verifies that the application can connect to PostgreSQL.

## Production web-root rule

The web server document root must be the repository's `public/` directory. Do not expose `src/`, `bootstrap/`, `migrations/`, `.env`, `var/`, or Composer metadata.

## Tests and checks

```sh
composer check
```
