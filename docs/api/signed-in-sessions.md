# Signed-in sessions

Client check: GET /api/v1/session.php advertises **capabilities.signed_in_sessions: true**.
Missing or false means unsupported. The API is additive to v1, using the existing
PHP cookie. Public session IDs identify logins; they are not credentials.

## List

GET /api/v1/account/sessions/list.php requires a member or guest login.
All responses use Cache-Control: no-store.

~~~json
{"sessions":[{"id":"<opaque 43-character id>","current":true,"created_at":"2026-11-09T12:00:00+00:00","last_active_at":"2026-11-09T12:05:00+00:00","client_hint":"Firefox on Windows","device_label":null}],"maximum_sessions":50}
~~~

Current comes first; others sort by last activity then ID descending. Only active
logins for this account/version appear. These are server-issued logins, **not
online devices or proof a client still holds a cookie**. Authenticated background
HTTP counts as activity. Last activity is written at most every five minutes and
displayed to minute precision; push workers do not touch it. No new member idle
timeout is introduced. Existing guest idle and maximum lifetimes still apply.

The hint classifies a bounded User-Agent into a conservative client/OS label.
The registry stores no raw User-Agent, IP or location. A validated device label
from an existing bound push registration may appear. Labels are untrusted display
text. No PHP SID, binding token/hash, endpoint or encryption key appears here.

## Revoke one

POST /api/v1/account/sessions/revoke.php with {"id":"<id>"} requires CSRF.

- 200 {"revoked":true}; an own already-revoked record is idempotent while retained.
- 404 session_not_found for unknown/unowned records, including after pruning.
- 409 current_session: use logout to end this login.
- 400 validation_error for a malformed ID.

## Revoke other logins

POST /api/v1/account/sessions/revoke-others.php with {} requires CSRF and current
privileged step-up for members, including provider-only accounts. Guests can
revoke their own other records without step-up. Returns 200 {"revoked_count":N}.

Repeating a lost-response request is safe: the current public ID, private binding,
PHP SID and account version stay unchanged. Logins successfully created after
this transaction are allowed. Both mutations share a default 60 attempts/hour/
account limit, with standard 429 rate_limited, Retry-After and
error.retry_after_seconds. Failed mutations still count. Listing has no mutation quota.

## Enforcement and lifecycle

A revoked holder's next authenticated HTTP request receives **401 session_revoked**
("You were signed out from another device. Sign in again."), then local PHP
authentication is cleared. Only a matching ID **and private holder proof** gets
this reason. Missing/unregistered rows fail closed; reads never recreate them.
Account/version invalidation retains its previous behavior. Fetch a fresh
anonymous bootstrap before signing in again.

Revocation atomically removes the login's push subscriptions and records a
secret-free audit. Subsequent dispatch attempts recheck the registered login; an
in-flight provider request cannot be recalled. SSE checks before each event
batch, closes only the revoked connection with forced_logout reason
session_revoked, and publishes no user-wide logout.

Successful full password/MFA/provider/sign-up/restoration/recovery/guest
authentication issues a record. Pending proof is not a login. Same-account SID
rotation and native step-up/picture/link retain it; a genuine new login retires
the replaced browser login. Authenticated native handoffs record/recheck the
originating login under the account lock before effects.

At most 50 active logins/account are retained. A new proven login retires the
least recently used when necessary. Password/version changes, account closure,
guest ending and MFA disable invalidate the corresponding logins and push.
Maintenance prunes retired metadata after seven days. Export includes safe
public metadata only, excluding private bindings.

Migration 0051 deliberately signs out previous unregistered sessions once.
See the [operator notes and announcement](../operations/session-registry-upgrade.md).
