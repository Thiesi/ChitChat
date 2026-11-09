# Authenticator codes (TOTP)

TOTP is an additional MFA method in API v1. Password/provider primary proof is
still required. Passkeys and one-time recovery codes keep their existing routes.
The web enrollment, proof and management UI must be integrated before enrollment
or discovery is enabled.

Client check: session bootstrap has capabilities.totp=true when enrollment is
shipped and a usable write-key source exists. A missing capability means unknown.
Private MFA status adds usable_methods and
totp {enabled, available, created_at, last_used_at}. Existing mfa.available still
means WebAuthn availability. Ignore unknown method names and future object keys.
Enabled is retained even if a key is lost; unavailable TOTP never downgrades MFA.

Session bootstrap adds `mfa_pending: {methods:[...]} | null` for the session's
valid pending MFA login/restoration, including provider redirects to
`/?mfa=continue`. Method names are `passkey`, `recovery_code` and `totp`; no
account identity or primary-proof data is returned. Absent, expired,
version-invalid or wrong-transport contexts return null. Native continuation
retains its session/transaction binding and may change networks. Reconciliation
happens before returning `csrf_token`, since clearing expired context rotates
that token. Use this field on provider return instead of guessing from
installation capabilities.

## Enrollment

All routes are below /api/v1/account/mfa/totp/. Every request is authenticated,
member-only, CSRF-protected and no-store. Begin, confirm and remove require
current step-up. Use an MFA proof when MFA is already enabled.

| POST route | JSON input | Success |
| --- | --- | --- |
| begin.php | {} | {enrollment_id, secret, otpauth_uri, expires_at, algorithm:"SHA1", digits:6, period_seconds:30} |
| confirm.php | {enrollment_id, code} | {mfa, recovery_codes} |
| cancel.php | {enrollment_id} | {cancelled:true} |
| remove.php | {} | {removed:true} |

The random 20-byte secret is returned once as unpadded Base32. The URI follows
the otpauth Key URI format. Render any QR locally; never send the secret/URI to
another service, navigate to it, log it or persist it. Restart setup after a lost
begin response. There is no endpoint that repeats a pending secret.

Enrollment lasts five minutes and is bound to the account's version and exact
registered login. A new begin supersedes pending setup. Replacement leaves the
old active factor usable until confirmation commits. Cancellation only clears
the matching login's pending enrollment.

Only the account's first primary MFA enrollment returns the existing ten recovery
codes. Adding TOTP to a passkey account, replacing it, or adding a first passkey
to a TOTP account preserves recovery codes. Display newly returned codes once.

Removing the final primary method returns 409 last_mfa_method. Use the existing
explicit disable-MFA operation when policy permits. With TOTP enabled the final
passkey can be removed; without it, existing 409 last_passkey is unchanged.

## Proof routes and replay

POST {code} with CSRF to:

- /api/v1/mfa/login-totp.php for the existing pending login/restoration, including
  a native continuation. Returns the existing user/CSRF/restored shape and
  mfa_method:"totp" (native continuation retains its outcome/purpose fields).
- /api/v1/mfa/step-up-totp.php for a signed-in member. Returns
  {security:{privileged_step_up:{active,method,verified_at,expires_at,max_age_seconds}}}.
- /api/v1/password-reset/mfa-totp.php for a pending recovery-key password reset.
  Returns the existing reset completion and replacement recovery key. No step-up
  is granted by reset.

Strip ASCII spaces, hyphens and dots from code, then require exactly six ASCII
digits, preserving leading zeros. Tabs, newlines, Unicode digits and other
characters are rejected. Input is bounded to 64 bytes before normalization.

Codes use SHA-1, six digits, 30-second Unix-time steps and current step +/-1.
Each accepted step is consumed once across all purposes, including enrollment.
A repeated or older step is invalid even after a backward clock change. An
accepted future-window code can require waiting for a later fresh code.
Consumption commits atomically with the protected DB operation. A lost success
response never permits replay.

Ordinary pending MFA keeps its existing IP/deadline/version checks. Native
continuation is bound to the native session and transaction, with no IP pin.
Password reset retains its own primary proof and pending context.

## Policy and errors

TOTP step-up has the same MFA authority as WebAuthn, including when a passkey
exists. Recovery codes remain an allowed MFA proof. Password/provider proof
alone cannot authorize an MFA-enabled sensitive action. Enabling, replacing or
removing a factor invalidates cached step-up across all logins without signing
those logins out. A later action requires fresh proof. Encryption-key rotation
does not change this private policy generation.

Admin-MFA policy and restored administrative roles accept enabled MFA with a
confirmed passkey or TOTP; recovery codes alone are insufficient. Existing
recovery-code requirements when enabling policy remain.

| Status / error.code | Meaning |
| --- | --- |
| 400 validation_error | Malformed code/payload |
| 403 invalid_totp | Wrong, expired-window, already-used or older code |
| 409 totp_enrollment_expired | Missing, superseded, expired or wrong-login setup |
| 409 last_mfa_method | Removal would leave no primary MFA method |
| 404 totp_not_found | No active authenticator to remove |
| 503 totp_unavailable | Missing/wrong key, failed authenticated decryption or unusable crypto |
| 429 rate_limited | A shared verification/management budget was exhausted |

Every code attempt shares totp_verification (10 attempts/300 seconds/account) and
totp_verification_ip (60/300 seconds/IP), across all purposes and enrollment.
Attempts survive effect rollback. Existing mfa_management (20/hour/account+IP)
and mfa_assertion (20/900 seconds/account+IP) also apply to their respective
operations. Operators can change bounded policies through the normal RATE_LIMIT_
environment settings; documented values are defaults. A 429 includes integer
Retry-After and error.retry_after_seconds. Wait before retrying.

Other primary-context, CSRF, ban, lockdown, authentication and step-up errors
retain their existing codes. A missing key blocks only the affected TOTP method;
other usable passkeys/recovery codes remain usable.

## Super-Administrator key setting

GET /api/v1/admin/settings/totp/get.php returns:

{totp:{mode:"server"|"database"|"unavailable",available,database_key_enabled,
database_key_switch_available,database_key_references,warning}}

POST update.php at the same path accepts {database_key_enabled:boolean}, with
CSRF, current step-up and Super-Administrator access. It returns the same shape.
The management default is 10 changes/hour/account+IP. Responses are no-store.
No master key, seed, encrypted material or key ID is returned.

Server keys always take precedence. The DB switch is unavailable while a server
ring is configured (409 totp_server_keys_configured). Without server keys, turning
it on generates a random DB key. Switching it off returns 409 totp_key_in_use
while any active OR pending credential references it.

Database-key mode puts both the master key and encrypted seeds in backups.
A leaked database backup therefore exposes every authenticator secret. The UI
must show the strong warning before enablement. Server keys are recommended.
See [operator key management](../operations/totp.md) for generation, rotation,
DB-to-server migration, escrow and restore drills.
