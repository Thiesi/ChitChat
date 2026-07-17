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

Returns the current authenticated user or `null`, plus a CSRF token and current privileged step-up state:

```json
{
  "csrf_token": "64-character hexadecimal token",
  "user": {
    "id": 1,
    "username": "Alice",
    "roles": ["super_admin"]
  },
  "security": {
    "privileged_step_up": {
      "active": false,
      "method": null,
      "verified_at": null,
      "expires_at": null,
      "max_age_seconds": 600
    }
  }
}
```

For an anonymous session, `user` is `null` and step-up is inactive.

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

Active bans are checked before a session is created. Login rotates the session identifier and clears any previous privileged step-up state.

### `POST /api/v1/logout.php`

No body is required. The server clears authentication, privileged step-up state, and CSRF state, expires the cookie, and destroys the session.

### `POST /api/v1/password.php`

Requires authentication.

```json
{
  "current_password": "correct horse battery staple",
  "new_password": "a different secure password"
}
```

Changing a password increments the account's session version, invalidating every existing session. The current request receives a newly rotated session using the new version. Any existing privileged step-up is cleared.

## Privileged step-up authentication

Sensitive administrative actions require recent reauthentication with the current account password in addition to ordinary session authentication, CSRF, role authorization, and any action-specific reason or audit requirements.

### `POST /api/v1/step-up.php`

Requires authentication and CSRF.

```json
{
  "password": "current account password"
}
```

Successful response:

```json
{
  "privileged_step_up": {
    "active": true,
    "method": "password",
    "verified_at": "2026-07-17T11:20:00+00:00",
    "expires_at": "2026-07-17T11:30:00+00:00",
    "max_age_seconds": 600
  }
}
```

The default lifetime is ten minutes and is configured with `PRIVILEGED_STEP_UP_MAX_AGE_SECONDS`, which accepts 60-3600 seconds.

Step-up state is stored only in the current PHP session and is bound to the current user ID and session version. It is cleared or becomes invalid after:

- expiry;
- login or registration session rotation;
- logout;
- password change;
- administrator password reset of the current account;
- kick, ban, or any other session-version change;
- any authentication failure that causes the session to be cleared.

Ten verification attempts are allowed per account and source IP in a fifteen-minute database-backed window. The eleventh attempt returns HTTP 429. Incorrect passwords return HTTP 403 with `step_up_invalid_credentials`; missing or expired elevation at a protected endpoint returns HTTP 403 with `step_up_required`.

Successful and failed password verifications create `auth.privileged_step_up_succeeded` or `auth.privileged_step_up_failed` audit entries. Passwords are never written to audit metadata.

The bundled browser client displays an accessible current-password dialog after `step_up_required`, establishes elevation, and retries the original JSON POST exactly once. An active elevation is reused for later protected actions in the same browser session until it expires.

This mechanism is password reauthentication, **not multi-factor authentication**. It reduces exposure from an unattended or opportunistically reused authenticated browser session, but it does not protect against compromise of both the session and account password.

Protected actions currently include:

- administrative direct-message inspection;
- administrative message-revision review;
- global role replacement;
- administrator password reset;
- registration and retention-policy updates.

Ordinary room moderation, kicks, bans, unbans, invitation management, and read-only system status do not require step-up in this milestone.

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

Requires active privileged step-up.

```json
{
  "target_user_id": 42,
  "new_password": "replacement secure password"
}
```

Applies the normal password policy and invalidates all existing sessions for the target.

## Session invalidation

Each authenticated session stores the user's current `session_version`. Every authenticated request reloads the user, verifies that version, and checks for an active ban. Kicks, password changes, administrator resets, and bans increment the stored database version, so stale sessions stop authenticating on their next request. Privileged step-up is bound to the same version and therefore cannot outlive that invalidation.

## Auditing

Registration, first-user promotion, password changes, administrator resets, kicks, bans, unbans, and both successful and failed privileged step-up attempts create records in `audit_log`. The log stores the actor, action, subject, metadata, remote IP, and timestamp, but never receives password values.
