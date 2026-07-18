# Multi-factor authentication API

All endpoints return the standard ChitChat JSON success or error envelope and require the session CSRF header on `POST` requests.

WebAuthn binary values are encoded as unpadded base64url strings. The browser must pass the option objects to the WebAuthn API after converting challenge, user ID, and credential IDs to byte arrays, then serialize response buffers back to base64url.

## Password-first login

`POST /api/v1/login.php`

A successful password for an account without MFA returns the authenticated user as before.

An MFA-enabled account returns HTTP 202:

```json
{
  "mfa_required": true,
  "methods": ["passkey", "recovery_code"]
}
```

The session is not authenticated. It contains only a short-lived pending-MFA context.

### Passkey completion

1. `POST /api/v1/mfa/login-options.php`
2. call `navigator.credentials.get()` with the returned `public_key` options;
3. `POST /api/v1/mfa/login-finish.php` with `{ "credential": ... }`.

### Recovery-code completion

`POST /api/v1/mfa/login-recovery.php`

```json
{
  "recovery_code": "ABCD-EF01-2345-6789-ABCD-EF01"
}
```

The successful response includes the remaining unused-code count.

## Privileged step-up

Password-only accounts continue to use `POST /api/v1/step-up.php`.

MFA-enabled accounts use:

- `POST /api/v1/mfa/step-up-options.php` followed by `POST /api/v1/mfa/step-up-finish.php`; or
- `POST /api/v1/mfa/step-up-recovery.php`.

Direct password step-up for an MFA-enabled account returns `passkey_step_up_required`.

## Account MFA status

`GET /api/v1/account/mfa/status.php`

Returns whether the installation is configured for WebAuthn, whether MFA is enabled, whether administrative policy requires it, the unused recovery-code count, and display-safe passkey metadata. Public-key bytes, credential IDs, signature counters, and recovery hashes are never returned.

## Passkey enrollment

Both enrollment endpoints require authenticated recent privileged step-up.

1. `POST /api/v1/account/mfa/register-options.php`
2. call `navigator.credentials.create()`;
3. `POST /api/v1/account/mfa/register-finish.php`:

```json
{
  "label": "Laptop passkey",
  "credential": {}
}
```

The first successful enrollment returns `recovery_codes`. That array is the only time that recovery-code set is available in plaintext.

## Credential management

All operations require recent privileged step-up.

- `POST /api/v1/account/mfa/rename.php`
  - `credential_id`: positive internal record ID
  - `label`: 1–80 characters
- `POST /api/v1/account/mfa/remove.php`
  - `credential_id`: positive internal record ID
  - the final passkey cannot be removed this way
- `POST /api/v1/account/mfa/recovery-regenerate.php`
  - replaces all previous unused recovery codes and returns the new plaintext set once
- `POST /api/v1/account/mfa/disable.php`
  - removes credentials and recovery hashes, clears the WebAuthn user handle, rotates the session version, and is refused when administrative policy requires MFA for the account

## Restoration

`POST /api/v1/account/restore.php` first validates the username, password, closure state and restoration deadline. For an account without MFA, the endpoint completes restoration and authenticates the new session.

For an MFA-enabled account it returns HTTP 202 without changing the account state:

```json
{
  "restored": false,
  "restoration_pending": true,
  "mfa_required": true,
  "methods": ["passkey", "recovery_code"]
}
```

The client then uses the ordinary pending-MFA passkey or recovery-code endpoints. Successful completion performs the restoration transaction, rechecks the deadline and current administrative-MFA policy, records the completed login, and returns `restored: true`. A failed, cancelled or expired second-factor attempt leaves the account closure-pending.
