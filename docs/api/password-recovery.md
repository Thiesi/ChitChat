# Password recovery API

Password accounts have one saved recovery key. It proves the password factor only:
an account with MFA still needs a registered passkey or an unused **MFA recovery
code**. The two secrets cannot substitute for each other.

All responses use `Cache-Control: no-store`. Every POST requires the current
session cookie and `X-CSRF-Token`. Recovery is available while signed out and
does not depend on registration being enabled or on accepting new rules.
Maintenance lockdown and account bans apply as they do to sign-in; only a
Super-Administrator can complete sign-in during lockdown.

## Discovery and one-time disclosure

Private `user` objects from session, login and registration add:

```json
{"recovery_key":{"exists":true,"created_at":"2026-11-08T12:00:00+00:00"}}
```

A password account without a saved key returns
`{"exists":false,"created_at":null}`. Provider-only accounts and guests return
`recovery_key:null`. A provider account that later sets its first password can
generate a key through the authenticated endpoint below.

Password registration adds a top-level `recovery_key` string, shown once.
Successful password recovery returns a **replacement** key, also shown once.
These values cannot be read back. The server stores only a SHA-256 hash of the
120-bit random key and a separate random generation identifier. Keys have 24
Crockford Base32 characters in six groups of four. Verification ignores case,
spaces and hyphens and accepts O as 0 and I/L as 1.

Client check: an authenticated private `user.recovery_key` field advertises key
management; a missing field means unknown. While signed out, a successful GET of
`/api/v1/password-reset/challenge.php` with `challenge.nonce` advertises recovery;
older servers can return 404. Do not infer support from absent capability flags
or show a reset form that submits without a challenge.

Clients must display and let the user save each newly returned key before
discarding the response. Refreshing or losing that response cannot recover it.
A signed-in owner can generate another key after fresh privileged verification.

## `GET /api/v1/password-reset/challenge.php`

```json
{"challenge":{"nonce":"32 hexadecimal characters","bits":0},"csrf_token":"current token"}
```

The nonce is mandatory, single-use, session-bound and expires after five minutes,
including when `bits` is zero. The puzzle follows the installation's effective
registration proof-of-work setting (including the database override). Find a
decimal string `solution` such that SHA-256 of `nonce + ":" + solution` has at
least `bits` leading zero bits. At zero, `"0"` works. Always keep the cookie and
use the latest CSRF token. This is independent of registration and guest nonces.

## `POST /api/v1/password-reset.php`

```json
{
  "username":"Alice",
  "recovery_key":"XXXX-XXXX-XXXX-XXXX-XXXX-XXXX",
  "new_password":"a sufficiently strong new password",
  "challenge_nonce":"nonce from this session",
  "challenge_solution":"0"
}
```

A submission consumes its challenge even when rejected or throttled. Get a new
challenge for a deliberate retry; never automatically replay a reset.

Without MFA, completion returns HTTP 200:

```json
{"status":"complete","user":{},"csrf_token":"new token","recovery_key":"replacement key"}
```

With MFA, initiation returns HTTP 202:

```json
{
  "status":"pending_mfa",
  "mfa_required":true,
  "csrf_token":"new token",
  "methods":["passkey","recovery_code"],
  "expires_at":"2026-11-08T12:05:00+00:00"
}
```

Pending MFA leaves the password, recovery key and existing sessions unchanged.
The pending session is not signed in and cannot act as ordinary login/restore
MFA. It stores only the staged password hash, account/session version, recovery
generation, source IP and original expiry. Retrying MFA does not extend expiry.
Changing IP, expiry, key replacement, a password/session-version change, closure
or a ban invalidates the pending operation.

On completion, consuming the primary key, committing the password, consuming the
MFA proof (if required), rotating the recovery key and incrementing the session
version are one transaction. Failures roll all of these back; abuse counters
remain counted. All older authenticated sessions become invalid. The completing
session is signed in with rotated cookie/CSRF state and **no privileged step-up**.
MFA remains enabled even after its last recovery code is consumed. New rules are
not automatically accepted.

Wrong username, missing/wrong recovery key and unavailable account proof return
the same HTTP 400 `reset_failed` message. A weak new password returns
400 `validation_error`. Lockdown returns 503 `maintenance_lockdown` with the
configured sign-in message. Throttling returns 429 `rate_limited`,
`error.retry_after_seconds` and the identical integer `Retry-After` header.

## Pending reset MFA endpoints

All are POST, require CSRF and the same pending reset session:

| Endpoint under `/api/v1/password-reset/` | Body | Success |
| --- | --- | --- |
| `mfa-options.php` | `{}` | `{"public_key":{...}}` WebAuthn assertion options |
| `mfa-finish.php` | Existing WebAuthn JSON wrapper (`id`, `rawId`, `type`, `response` under `credential`) | HTTP 200 completion envelope above |
| `mfa-recovery.php` | `{"code":"unused MFA recovery code"}` | HTTP 200 completion envelope above |
| `cancel.php` | `{}` | `{"status":"cancelled","csrf_token":"new token"}` |

The reset code field is `code`; ordinary sign-in's existing `recovery_code`
field is unchanged. Passkey proof is bound to this account and reset purpose;
an assertion started for ordinary sign-in or privileged step-up cannot complete
a reset. Existing MFA errors and independent MFA rate limits apply.

Only an acknowledged cancellation means no reset committed. With no pending
reset (including after an already committed completion), cancel returns
409 `reset_not_pending`. A lost response or failed cancellation is uncertain:
check sign-in rather than promising "nothing changed" or retrying the reset.

## `POST /api/v1/account/recovery-key.php`

An authenticated non-guest password account, CSRF and fresh privileged step-up
are required. Empty JSON object `{}` generates the first key or replaces it:

```json
{"recovery_key":"XXXX-XXXX-XXXX-XXXX-XXXX-XXXX","created_at":"2026-11-08T12:00:00+00:00"}
```

The previous key and staged resets using it become invalid immediately.
Provider-only accounts return 409 `password_account_required`. A stale
authenticated session cannot replace recovery material after a password reset.

Ordinary authenticated password changes retain the saved key. An administrator
password reset invalidates it in the same password transaction and never gives
the administrator a replacement; private status becomes `exists:false`.
Permanent closure destroys stored recovery material. Recovery keys, hashes,
generation identifiers, staged password hashes and plaintext passwords are
excluded from personal-data exports and audit metadata. Audit entries record
fixed outcome/action data, not submitted secrets.

Default named limits: `password_reset` 10/900 seconds by canonical username;
`password_reset_ip` 30/900 seconds independently by source IP;
`password_reset_challenge` 60/60 seconds by source IP;
`recovery_key_management` 10/3600 seconds by account. Reset limits are independent
of ordinary sign-in. See [rate limiting](../operations/rate-limiting.md).
