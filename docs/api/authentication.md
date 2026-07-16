# Authentication API

All responses are JSON. Error responses use this shape:

```json
{
  "error": {
    "code": "machine_readable_code",
    "message": "Human-readable explanation."
  }
}
```

PHP session cookies are HTTP-only. In production they must also be secure.

## CSRF bootstrap

### `GET /api/v1/session.php`

Returns the current authenticated user or `null`, plus a CSRF token:

```json
{
  "csrf_token": "64-character hexadecimal token",
  "user": null
}
```

For an authenticated session, `user` contains `id`, `username`, and `roles`.

Send the token in the `X-CSRF-Token` header for every POST endpoint below.

## User authentication

### `POST /api/v1/register.php`

```json
{
  "username": "Alice",
  "password": "correct horse battery staple",
  "birth_date": "1990-05-12"
}
```

`birth_date` is optional. When supplied, it uses `YYYY-MM-DD`, cannot be in the future, and is used to enforce room minimum ages.

Usernames are 3-32 ASCII characters. They begin with a letter or number and may additionally contain `.`, `_`, and `-`. Uniqueness is case-insensitive.

Passwords must contain at least 12 characters, must not contain the username, and may contain up to 4096 characters.

The first account is atomically assigned the `super_admin` role. A successful registration also authenticates the new account and rotates the session identifier and CSRF token.

### `POST /api/v1/login.php`

```json
{
  "username": "Alice",
  "password": "correct horse battery staple"
}
```

Failed attempts are recorded by canonical username and remote IP. After the configured threshold, login returns HTTP 429 until the rolling lock window expires.

Active bans are checked before a session is created.

### `POST /api/v1/logout.php`

No body is required. The server clears session state, expires the cookie, and destroys the session.

### `POST /api/v1/password.php`

Requires authentication.

```json
{
  "current_password": "correct horse battery staple",
  "new_password": "a different secure password"
}
```

Changing a password increments the account's session version, invalidating every existing session. The current request receives a newly rotated session using the new version.

## Administrative account controls

These endpoints require the `super_admin` or `admin` role. Administrators cannot act on their own account. Only a Super-Administrator may act on another Super-Administrator.

### `POST /api/v1/admin/kick.php`

```json
{
  "target_user_id": 42,
  "reason": "Optional reason"
}
```

Increments the target's session version without creating a persistent ban.

### `POST /api/v1/admin/ban.php`

```json
{
  "target_user_id": 42,
  "reason": "Policy violation",
  "expires_at": "2026-08-01T12:00:00+02:00"
}
```

`expires_at` may be `null` for an indefinite ban. Creating a ban invalidates all existing sessions.

### `POST /api/v1/admin/unban.php`

```json
{
  "target_user_id": 42
}
```

Revokes all currently active bans for the target account.

### `POST /api/v1/admin/reset-password.php`

```json
{
  "target_user_id": 42,
  "new_password": "replacement secure password"
}
```

Applies the normal password policy and invalidates all existing sessions for the target.

## Session invalidation

Each authenticated session stores the user's current `session_version`. Every authenticated request reloads the user, verifies that version, and checks for an active ban. Kicks, password changes, administrator resets, and bans increment the stored database version, so stale sessions stop authenticating on their next request.

## Auditing

Registration, first-user promotion, password changes, administrator resets, kicks, bans, and unbans create records in `audit_log`. The log stores the actor, action, subject, metadata, remote IP, and timestamp.
