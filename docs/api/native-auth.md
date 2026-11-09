# Native browser authentication handoff

API v1 is additive. Check `session.php` → `capabilities.native_auth_handoff === true`
before offering this flow. A missing or false flag means unavailable. The flag
requires an explicitly configured HTTPS `APP_ORIGIN`, a matching
`OIDC_REDIRECT_ORIGIN`, an enabled Google/Twitch provider, trusted
`NATIVE_ANDROID_APPS`, and the integrated browser confirmation page. It does not
attest the running APK or prove Android Digital Asset Links delivery.

The Android app uses its own cookie jar and CSRF token. Open the returned browser
URL in a system browser/Custom Tab; do not copy browser cookies into the app and
do not embed a WebView. Provider credentials stay on the server.

## Start

`POST /api/v1/native-auth/start.php`, app cookie and `X-CSRF-Token`:

```json
{
  "client_id": "de.networkxxiii.chitchat",
  "purpose": "login",
  "provider": "google",
  "code_challenge_method": "S256",
  "code_challenge": "E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM",
  "state": "<app-generated random base64url string>"
}
```

`client_id` is an exact configured package. Release is
`de.networkxxiii.chitchat`; debug is `de.networkxxiii.chitchat.debug` and needs
separate explicit installation trust. The derived return is
`<client_id>:/native-auth/callback`. Optional `redirect_uri` must exactly equal
that value, without any extra host, query, fragment or path. This is a public
client identifier, not APK authentication; there is no app secret. A package must
also be a valid URI scheme: an Android package containing an underscore can
still be trusted for passkeys, but cannot use this derived handoff return.

`state` is required, 32–128 base64url characters. Retain it and the local selected
server/account intent. PKCE is S256 only: a canonical unpadded base64url SHA-256
challenge and a 43–128-character RFC 7636 verifier. The example challenge is an
RFC test vector, not a production secret.

Purposes: `login` and `restore` require an anonymous app session. `link`,
`picture` and `step_up` require the initiating authenticated non-guest account.
Linking also requires current privileged step-up. Provider step-up cannot
replace an enabled/required passkey factor.

200:

```json
{"transaction_id":"<opaque>","browser_url":"https://chat.example.org/native-auth.php?transaction_id=...&entry=...","expires_at":"<ISO timestamp>"}
```

The browser URL always uses the explicit HTTPS `APP_ORIGIN`, never request Host
or forwarded headers. Transactions expire after ten minutes. A new start in the
same private app-session binding supersedes its previous active transaction and
replaces older primary-auth continuations. Start is limited to 10 attempts per
15 minutes, separately by IP and private app binding.

## Browser verification, consent and return

Opening the GET link is not consent. Continue, Approve, Cancel and Restart are
browser-CSRF-protected POSTs followed by 303 navigation. An existing browser
account never selects the target or silently replaces its session. Account and
provider-identity mismatches require an explicit action.

With a withheld Strict PHP cookie, an entry or provider callback uses a separate
single-use `__Host-` Secure, HttpOnly, SameSite=Strict, Path=/ bootstrap cookie.
Entry and callback bootstraps cannot substitute for each other. The initial
entry/callback response renders its interstitial directly with HTTP 200; a
redirect there would keep the cross-site initiator and withhold both Strict
cookies again. The user-initiated form POST is the same-site continuation.
Continue first
validates its cookie/CSRF, then recovers the original same-site session. A
callback with its session cookie verifies directly. Unknown/expired native
callback state creates no session or cookie. Parked callbacks keep only bounded
code/error fields for at most five minutes and never past transaction expiry;
only the original browser binding can resume them. No provider tokens are
parked. The first callback wins.

Verification stages proof only. It does not sign the browser in, link an
identity, restore an account, elevate a session or publish an imported picture.
Approval creates one return code, valid for at most 90 seconds and never beyond
the transaction deadline. Repeated approval never replaces it or extends it.
The browser renders one user-initiated link labelled **Return to the ChitChat
app**, carrying only `code`, `transaction_id` and `state`. There is no automatic
return and no displayed code. Failure to open the app has a code-free fallback.

The app must compare returned state exactly with its local expected value and
use its locally selected server; callback data must never choose a new server.

## Exchange

`POST /api/v1/native-auth/exchange.php`, initiating app cookie/CSRF:

```json
{"transaction_id":"<opaque>","code":"<one-use>","code_verifier":"<original verifier>","state":"<original state>"}
```

All four fields are required. Exchange compares state, code, PKCE, private
session binding, current trust/provider/configuration, account/version, purpose,
permissions and deadlines. Wrong state, verifier or session does not consume
another person's usable grant. Limit: 30 attempts per 15 minutes, separately by
IP and private binding.

A successful response adds an explicit `outcome` and `purpose` to the existing
user/CSRF or continuation fields:

| Result | HTTP | Fields |
|---|---|---|
| Existing account sign-in | 200 | `outcome:"completed"`, `user`, `csrf_token` |
| Required second factor | 202 | `outcome:"pending_mfa"`, `mfa_required:true`, `methods`, `user:null`, `csrf_token` |
| New provider identity | 202 | `outcome:"pending_sign_up"`, `pending_sign_up:{id,label}`, `user:null`, `csrf_token` |
| Link | 200 | `outcome:"completed"`, `identities`, same account and new CSRF |
| Provider step-up | 200 | `outcome:"completed"`, `security.privileged_step_up`, same account and new CSRF |
| Picture | 200 | `outcome:"completed"`, `picture_ready:true`, `picture_url`, same account and new CSRF |
| Restoration without MFA | 200 | `outcome:"completed"`, `restored:true`, `user`, new CSRF |

Picture readiness means a private one-use crop source at the existing
`account/avatar/imported.php` endpoint, not an installed avatar. Provider image
URLs are allowlisted and downloaded outside database locks, at most 5 MiB.

Database purpose effects and one-use consumption commit atomically. PHP session
installation happens afterward; PostgreSQL and PHP file sessions are not one
transaction. A lost response, Set-Cookie or session write can leave a committed
purpose without a usable authenticated cookie. **Fetch a fresh `session.php`
and verify the expected account before announcing sign-in or elevation.** Never
retry a used code to recreate authentication. Reconcile the recorded outcome or
start a new flow.

## MFA and sign-up continuations

Use existing `mfa/login-options.php`, `mfa/login-finish.php` (`credential`) and
`mfa/login-recovery.php` (`recovery_code`) with the app cookie/new CSRF. Native
pending MFA binds to the app session plus transaction, not an IP address; an
IP change alone is allowed. Ordinary web pending MFA retains its existing IP
pin. Both native factor completion and pending provider sign-up recheck live
trust, expiry, identity and account/version before final mutation. Factor
consumption and restoration/login effects commit together.

New identities use existing `oidc/register.php`, with the current registration
challenge and `accepted_rules_version`. The order remains challenge, current
rules acceptance, then mutation. No account exists at exchange. Cancellation
through `oidc/cancel-sign-up.php` also cancels its native continuation. A revoked
or expired continuation disappears from session bootstrap. Native sign-up and
MFA cannot silently fall through the ordinary web completion paths.

## Status and cancellation

`GET /api/v1/native-auth/status.php?transaction_id=...` uses the app cookie and
returns 200 `{transaction_id,phase,outcome,error_code}`. It is read-only and
never counts as replay, exchanges a code or returns credentials. Limit: 120/min
per IP and private binding. Browser status is separately browser-binding
authorized through clean `GET /native-auth.php`.

Phases include `entry`, `verifying`, `consent`, `account_mismatch`,
`identity_mismatch`, `staged`, `used`, `cancelled`, `superseded`, `expired`.
`outcome` is null or `completed`, `pending_mfa`, `pending_sign_up`, `unknown`.
A consumed exchange remains `used`; an active continuation later records its
completion. Browser completed login/step-up copy stays uncertain because the
DB record cannot prove cookie delivery. It may describe only narrower
committed link/picture/restore facts. Status never claims app delivery.

`POST /api/v1/native-auth/cancel.php` with app cookie/CSRF and
`{transaction_id}` returns 200 `{status:"cancelled"}` if cancellation wins
before consumption. Repeated cancellation is safe. Once used it returns
`already_used`; cancellation must not claim nothing changed after a winning
exchange. New starts supersede pending continuations; explicit account change,
logout or reset invalidates their private session binding.

## Errors and operations

Errors retain API v1 `{error:{code,message}}`:

| Code | HTTP | Meaning |
|---|---|---|
| `expired`, `code_expired` | 410 | Transaction or return-code deadline passed |
| `superseded`, `cancelled`, `already_used` | 409 | Replaced, cancelled or consumed |
| `state_mismatch`, `verifier_mismatch`, `code_mismatch` | 403 | Returned proof does not match |
| `native_session_changed` | 401/409 | Wrong or changed app session/intent |
| `native_config_changed`, `account_changed`, `identity_changed` | 409 | Live trust, account or provider identity changed |
| `result_not_ready`, `consent_required` | 409 | Verification/approval is incomplete |
| `redirect_mismatch`, `validation_error` | 400 | Invalid input |
| `native_client_unavailable` | 403 | Package is not trusted |
| `native_auth_unavailable` | 503 | Required installation configuration is missing |
| `browser_session_changed`, `bootstrap_mismatch`, `bootstrap_kind_mismatch`, `csrf_failed`, `entry_mismatch` | 403 | Browser proof/session mismatch |
| `native_callback_unavailable`, `native_callback_invalid` | 400 | Unknown or malformed provider callback |
| `rate_limited` | 429 | `Retry-After` integer seconds plus `error.retry_after_seconds` |

Normal existing ban, lockdown, registration, rules, factor and step-up errors
still apply. Clients must ignore unknown additive fields and handle unknown
errors without applying any result.

Responses/pages are no-store/no-referrer, with no third-party assets. Native
confirmation alone permits exact configured provider authorization origins in
CSP `form-action`; ordinary pages remain `'self'`. Endpoint overrides use only
the existing development/test provider setting, never a production override.
`bin/maintenance-cleanup` expires/scrubs proof, parked callback fields and private
pictures in batches of 1000, then removes terminal records after one hour.
No codes, state, verifier, claims, picture bytes, provider tokens or cookies
belong in application/audit/request logs. See the query-redacting
[Nginx example](../operations/nginx-php-fpm.md#native-authentication-log-privacy).
Deploying that example remains the operator's decision. Synthetic HTTP,
signature and browser tests do not establish real Android/Custom Tabs acceptance.