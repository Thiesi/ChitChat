# Operational settings API

Both endpoints require an authenticated Super-Administrator. Ordinary Administrators are deliberately excluded because these settings can disable account creation or permanently delete retained content.

## Read settings

```text
GET /api/v1/admin/settings/get.php
```

Response:

```json
{
  "settings": {
    "registration_enabled": true,
    "room_message_retention_days": 0,
    "direct_message_retention_days": 0,
    "audit_retention_days": 0,
    "deleted_attachment_retention_days": 30,
    "orphan_attachment_grace_hours": 24,
    "realtime_event_retention_hours": 168,
    "login_attempt_retention_days": 30,
    "updated_at": "2026-07-17 00:00:00+00"
  }
}
```

A retention value of `0` means permanent retention. The grace and operational-ledger values must be positive.

## Update settings

```text
POST /api/v1/admin/settings/update.php
Content-Type: application/json
X-CSRF-Token: <session token>
```

The request must include every field returned above except `updated_at`:

```json
{
  "registration_enabled": false,
  "room_message_retention_days": 90,
  "direct_message_retention_days": 180,
  "audit_retention_days": 365,
  "deleted_attachment_retention_days": 30,
  "orphan_attachment_grace_hours": 24,
  "realtime_event_retention_hours": 168,
  "login_attempt_retention_days": 30
}
```

The response returns the complete updated settings object. The old and new snapshots are written to the audit log in the same transaction.

Changing settings does not immediately delete data. The operator must run `php bin/maintenance-cleanup`; see `docs/operations/maintenance.md`.

## Public policy disclosure

```text
GET /api/v1/session.php
```

The session response includes:

- `registration_enabled`, used by the browser to hide closed registration;
- the effective direct-message retention description and number of days;
- the direct-message administrative-inspection policy.

The server still enforces registration and retention policy regardless of what a client displays.
