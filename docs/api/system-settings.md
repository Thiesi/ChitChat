# Operational settings API

Both endpoints require an authenticated Super-Administrator. Ordinary Administrators are deliberately excluded because these settings can disable account creation or permanently delete retained content.

Reading settings requires ordinary Super-Administrator authentication. Updating them additionally requires active privileged step-up authentication; see `docs/api/authentication.md`.

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

Requires active privileged step-up. Without recent verification the endpoint returns HTTP 403 with `step_up_required`; no setting or audit record is changed. The bundled browser asks for the current password and retries the update once after successful verification.

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

The response returns the complete updated settings object. The old and new snapshots are written to the audit log in the same transaction. The earlier step-up success has its own authentication audit record; it does not replace the settings-change audit.

Changing settings does not immediately delete data. The operator must run `php bin/maintenance-cleanup`; see `docs/operations/maintenance.md`.

## Public policy disclosure

```text
GET /api/v1/session.php
```

The session response includes:

- `registration_enabled`, used by the browser to hide closed registration;
- the effective direct-message retention description and number of days;
- the direct-message administrative-inspection policy;
- current privileged step-up status and configured maximum age under `security.privileged_step_up`.

The server still enforces registration, retention, role authorization, and step-up freshness regardless of what a client displays.
