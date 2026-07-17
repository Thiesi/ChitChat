# Account and personal-data API

Authenticated account endpoints require a PHP session. State-changing requests require the current `X-CSRF-Token` header from `GET /api/v1/session.php`. The restoration endpoint is intentionally available without authentication but still requires the anonymous session's CSRF token, valid account credentials, a pending unexpired closure, and its own database-backed throttle.

## Personal data export

### `POST /api/v1/account/export.php`

Creates a complete JSON response for the retained personal data currently available to the signed-in account. The endpoint requires active privileged step-up authentication and is limited to five requests per account per hour through the shared database-backed request limiter.

The response has this envelope:

```json
{
  "filename": "chitchat-personal-data-Example-20260717-123456.json",
  "export": {
    "format": {
      "name": "chitchat-personal-data-export",
      "version": 1
    },
    "generated_at": "2026-07-17T12:34:56+00:00",
    "application": {},
    "scope": {},
    "account": {},
    "rooms": {},
    "direct_messages": {},
    "security_history": {},
    "activity": []
  }
}
```

The bundled account page serializes `export` as formatted UTF-8 JSON and downloads it using `filename`.

### Included data

The export includes:

- account profile timestamps, optional birth date, role grants, and ban history;
- rooms created by the account, current room memberships, and pending invitations;
- retained room messages authored by the account, including their retained revision history;
- retained direct messages the account can already read, including attachment metadata;
- retained revision history only for direct messages authored by the exporting account;
- direct-message blocks created by the account;
- login-attempt history associated with the account's canonical username;
- audit entries where the account is the actor, including the source IP already recorded for that activity.

### Deliberate exclusions

The export does not include password hashes, session state, CSRF tokens, privileged-step-up state, attachment file bytes, opaque attachment storage keys, hidden revision history for messages authored by somebody else, the identities of users who blocked the account, or audit entries and source IPs belonging only to another actor.

The direct-message block export therefore preserves the existing public relationship contract: users can retrieve blocks they created, but the export does not add a `blocked_by_other` disclosure.

### Consistency and audit behavior

The service reads the export in a repeatable-read PostgreSQL transaction. A successful generation creates `account.personal_data_exported` after the exported activity snapshot has been assembled, so the export audit is not recursively included in the same file. Audit metadata records only the export format and aggregate item counts; it does not copy message bodies, filenames, IP addresses, or other exported content.

The synchronous JSON response is intended for the supported single-server baseline. Large retained histories can require substantial PHP memory; operators should test representative accounts before exposing the feature on installations with very large permanent histories.

## Account closure

### `POST /api/v1/account/close.php`

Requires authentication, CSRF protection, and recent password step-up. The final active Super-Administrator cannot request closure.

A successful request atomically:

- changes the account from `active` to `closure_pending`;
- records a 14-day cooling-off deadline;
- increments the session version and removes all global roles;
- removes current presence and SSE leases;
- emits a forced-logout event for other tabs and sessions;
- records `account.closure_requested` without duplicating the username or content;
- destroys the requesting PHP session.

Normal login is denied while closure is pending. The original username and password are retained only so explicit restoration remains possible during cooling-off.

## Account restoration

### `POST /api/v1/account/restore.php`

Accepts:

```json
{
  "username": "Example",
  "password": "current password"
}
```

The endpoint requires the anonymous session's CSRF token and is limited by the `account_restore` policy, which defaults to five attempts per username/IP combination per hour. It succeeds only while the matching closure remains pending and the 14-day deadline has not passed.

Restoration increments the session version again, restores the saved global-role snapshot, records `account.closure_restored`, creates a fresh authenticated session, and returns the ordinary session user envelope. It does not silently occur during normal login.

## Finalization and retained shared data

Ordinary maintenance finalizes pending closures whose deadline has passed. Finalization:

- replaces the username with `Closed account #<id>` and assigns a non-user-controlled unique canonical name;
- replaces the password hash with an unusable random credential;
- clears birth date and last-login metadata;
- removes global roles, pending room invitations, live presence/SSE leases, direct-message block preferences, and login attempts tied to the old canonical username;
- records `account.closure_finalized` using IDs only;
- releases the original username for reuse.

Shared room and direct-message history, message revisions, attachment evidence, room membership and ownership attribution, bans, and audit records remain subject to their existing retention policies. This preserves conversation integrity and security evidence rather than rewriting other participants' retained history. Once the deadline has passed, restoration is refused even if maintenance has not yet run.
