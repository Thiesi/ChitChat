# Account and personal-data API

All account endpoints require an authenticated PHP session. State-changing requests require the current `X-CSRF-Token` header from `GET /api/v1/session.php`.

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
