# Web Push subscription and preferences API

ChitChat can deliver a best-effort browser push notification for the same events already recorded in the durable in-app notification timeline (`account_notifications`). See [ADR 0006](../architecture/0006-web-push.md) for the design and [`docs/operations/web-push.md`](../operations/web-push.md) for enabling the feature and scheduling the dispatch sweep that actually sends push. This document covers only the participant-facing subscription and preference API; it has no effect if the installation has not configured a VAPID keypair.

## Privacy and authorization model

Every endpoint below requires an authenticated session; all except the `GET` read requires the current `X-CSRF-Token`. A push payload never carries a raw message body — only the same sender-username/room-name/title text already considered safe for the in-app timeline. Push subscriptions and notification preferences belong to the signed-in account: a request can only create, read, or remove its own rows.

All five endpoints share the named `push_subscription_management` rate-limit policy (see [`docs/operations/rate-limiting.md`](../operations/rate-limiting.md)).

## `POST /api/v1/push/subscribe.php`

Registers (or re-registers) one browser subscription for the signed-in account, created client-side by `PushManager.subscribe()` using the deployment's VAPID public key.

Request body:

```json
{
  "endpoint": "https://push-service.example/abc123",
  "p256dh": "<base64url P-256 public key>",
  "auth": "<base64url auth secret>"
}
```

Fields:

- `endpoint`: required, 1-2048 characters, must be an `https://` URL;
- `p256dh`: required, non-empty;
- `auth`: required, non-empty.

`endpoint` is globally unique. Subscribing again with an endpoint already on file updates its keys, user agent, and `last_used_at` in place and reassigns it to the requesting account, rather than creating a duplicate row — the same browser registration cannot belong to two accounts at once. The request's `User-Agent` header is stored (truncated to 256 characters) for display in the device list; it is omitted if blank.

Response:

```json
{"subscribed": true}
```

## `POST /api/v1/push/unsubscribe.php`

Removes one subscription belonging to the signed-in account by endpoint. Typically called from the same browser that is unsubscribing.

Request body:

```json
{"endpoint": "https://push-service.example/abc123"}
```

An endpoint that does not exist, or belongs to another account, is silently a no-op rather than an error.

Response:

```json
{"subscribed": false}
```

## `GET /api/v1/push/preferences.php`

Returns the signed-in account's notification preferences and current device list together, for rendering the Web Push section of `/notifications.php`. Authentication only; no CSRF token required for this read.

Example response:

```json
{
  "preferences": {
    "mentioned_push_enabled": true,
    "quiet_hours": {"start": 22, "end": 7, "timezone": "Europe/Berlin"}
  },
  "devices": [
    {
      "id": 4,
      "user_agent": "Mozilla/5.0 (...) Firefox/128.0",
      "created_at": "2026-07-18T09:12:00+00:00",
      "last_used_at": "2026-08-01T21:03:00+00:00"
    }
  ]
}
```

`quiet_hours` is `null` when no window is configured. `devices` is ordered newest-first and never includes the endpoint, key material, or any other value that could re-target a push.

## `POST /api/v1/push/update-preferences.php`

Updates the mute preference for `mentioned` and the account's quiet-hours window in one call. This is the only mutable preference: `revision_review`, `moderator_message_deleted`, `admin_password_reset`, and `system_policy_changed` remain non-optional short of removing every subscription, matching how those four kinds are already non-optional in the in-app timeline.

Request body:

```json
{
  "mentioned_push_enabled": false,
  "quiet_hours_start": 22,
  "quiet_hours_end": 7,
  "quiet_hours_timezone": "Europe/Berlin"
}
```

Fields:

- `mentioned_push_enabled`: required boolean;
- `quiet_hours_start`, `quiet_hours_end`: optional integers, 0-23, an hour of the day in the account's configured time zone;
- `quiet_hours_timezone`: optional, a valid IANA time zone identifier.

The three quiet-hours fields must be sent together or all omitted/`null` — setting only one or two returns `400 validation_error`. An overnight window (for example `start: 22, end: 7`) is valid and wraps across midnight. Sending all three as `null` clears the window entirely. Quiet hours suppress every push category uniformly, mutable or not; they affect only push timing, not consent.

Response is the updated preferences object, in the same shape as the `preferences` key above:

```json
{
  "preferences": {
    "mentioned_push_enabled": false,
    "quiet_hours": {"start": 22, "end": 7, "timezone": "Europe/Berlin"}
  }
}
```

## `POST /api/v1/push/revoke-device.php`

Removes one subscription by ID rather than endpoint, for the device-list "Remove" control — the signed-in browser revoking a different, no-longer-trusted device rather than itself.

Request body:

```json
{"id": 4}
```

`id` must belong to the signed-in account; an ID owned by another account or that does not exist is silently a no-op. Response is the account's remaining device list, in the same shape as the `devices` key above:

```json
{"devices": []}
```
