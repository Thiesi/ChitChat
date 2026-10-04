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
    "mfa_required_for_admin_roles": false,
    "room_message_retention_days": 0,
    "direct_message_retention_days": 0,
    "audit_retention_days": 0,
    "deleted_attachment_retention_days": 30,
    "orphan_attachment_grace_hours": 24,
    "realtime_event_retention_hours": 168,
    "login_attempt_retention_days": 30,
    "deleted_room_grace_days": 30,
    "updated_at": "2026-07-17 00:00:00+00"
  }
}
```

A retention value of `0` means permanent retention. The grace and operational-ledger values must be positive. `deleted_room_grace_days` is how long a deleted room stays restorable before maintenance removes it permanently; `0` keeps deleted rooms until they are restored.

## Update settings

```text
POST /api/v1/admin/settings/update.php
Content-Type: application/json
X-CSRF-Token: <session token>
```

Requires active privileged step-up. Without recent verification the endpoint returns HTTP 403 with `step_up_required`; no setting or audit record is changed. The bundled browser asks for the current password and retries the update once after successful verification.

The request must include every field returned above except `updated_at`; `deleted_room_grace_days` is optional and left unchanged when omitted:

```json
{
  "registration_enabled": false,
  "mfa_required_for_admin_roles": false,
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

Setting `mfa_required_for_admin_roles` from `false` to `true` additionally requires every currently active `super_admin`, `admin`, `chat_admin`, and `global_moderator` account to already have passkey MFA enrolled and at least one unused recovery code. If any qualifying account is missing either, the request is rejected with `409 administrators_missing_mfa` and the count of accounts still missing enrollment, before any setting changes or audit record is written. Turning the policy back off has no such precondition.

Changing settings does not immediately delete data. The operator must run `php bin/maintenance-cleanup`; see `docs/operations/maintenance.md`.

## Application name

```text
GET  /api/v1/admin/settings/application-name/get.php
POST /api/v1/admin/settings/application-name/update.php
```

Both require a Super-Administrator; the update also requires active privileged step-up. The name is an optional override of the `APP_NAME` server default:

```json
{ "application_name": "Harbor Chat" }
```

`null` removes the override. Names are trimmed and must contain 1–64 characters with no control characters. Both endpoints return `{"application_name": {"override": …, "default": …, "effective": …}}`. Changes are audited as `system.application_name_updated` without notifying every account.

The effective name appears in page titles and headings (and the `<meta name="application-name">` tag that scripts read), passkey prompts, push notifications, personal-data exports, backup manifests, and the system-status page. `/health.php` and the metrics authentication realm report `APP_NAME`, because they must not depend on the database. Machine identifiers such as the `chitchat-backup` and `chitchat-personal-data-export` format names never change.

## Registration protection

```text
GET  /api/v1/admin/settings/registration-protection/get.php
POST /api/v1/admin/settings/registration-protection/update.php
```

Both require a Super-Administrator; the update also requires active privileged step-up. Each setting is an optional override of a server default from the environment:

| Setting | Server default | Range |
|---|---|---|
| `rate_limit_max_attempts` | `RATE_LIMIT_REGISTRATION_MAX_ATTEMPTS` | 1–100 |
| `rate_limit_window_seconds` | `RATE_LIMIT_REGISTRATION_WINDOW_SECONDS` | 60–86400 |
| `min_fill_seconds` | `REGISTRATION_MIN_FILL_SECONDS` | 0–60 (0 disables) |
| `proof_of_work_bits` | `REGISTRATION_PROOF_OF_WORK_BITS` | 0–22 (0 disables) |

The update body sets all four; `null` (or an omitted field) removes the override so the server default applies:

```json
{
  "rate_limit_max_attempts": 10,
  "rate_limit_window_seconds": null,
  "min_fill_seconds": 5,
  "proof_of_work_bits": null
}
```

Both endpoints return `{"registration_protection": {"overrides": {…}, "defaults": {…}, "effective": {…}}}`. Changes are audited as `system.registration_protection_updated` with the old and new overrides. Unlike `system.settings_updated`, they do not notify every account, because they tune abuse resistance rather than an installation policy that affects people's data. See `docs/api/authentication.md` for how registration applies them.

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
