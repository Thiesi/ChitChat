# Web Push subscription and preferences API

ChitChat can deliver a best-effort browser push notification for the same events already recorded in the durable in-app notification timeline (`account_notifications`). See [ADR 0006](../architecture/0006-web-push.md) for the design and [`docs/operations/web-push.md`](../operations/web-push.md) for enabling the feature and scheduling the dispatch sweep that actually sends push. This document covers only the participant-facing subscription and preference API; it has no effect if the installation has not configured a VAPID keypair.

## Privacy and authorization model

Every endpoint below requires an authenticated session; all except the `GET` read requires the current `X-CSRF-Token`. A push payload never carries a raw message body — only the same sender-username/room-name/title text already considered safe for the in-app timeline. Push subscriptions and notification preferences belong to the signed-in account: a request can only create, read, or remove its own rows.

Mutations use the named `push_subscription_management` policy (20 attempts/hour per account). Subscribe has a cheap early `push_subscription_refresh` policy (600/hour); unchanged same-session registrations do not spend the mutation allowance or repeat DNS. The authenticated GET is uncharged. See [rate limiting](../operations/rate-limiting.md).

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

- `endpoint`: required, an unambiguous ASCII HTTPS URI of at most 2048 bytes; encode non-ASCII path/query characters; credentials, fragments, controls, alternate IP spellings and IPv6 zones are rejected;
- `p256dh`: required, base64url encoding of a 65-byte uncompressed, on-curve P-256 point;
- `auth`: required, base64url encoding of exactly 16 bytes; canonical padded or unpadded inputs are accepted.
- `replaces_id`: optional owned device ID; atomically replaces an obsolete endpoint without temporarily exceeding the device cap.
- `device_label`: optional human label, at most 64 characters, without controls.

`endpoint` is globally unique. Re-registering the same endpoint, keys and login-session binding refreshes its metadata idempotently. Changing keys or the login session rotates the delivery binding and generation. An endpoint owned by another account returns `409 push_subscription_conflict` and never transfers ownership — the same browser registration cannot belong to two accounts at once. The request's `User-Agent` header is stored (truncated to 256 characters) for display in the device list; it is omitted if blank.

Response:

```json
{"subscribed": true, "id": 4, "binding": "opaque-ascii-delivery-binding"}
```

The account cap is 20 devices, enforced atomically with account/session checks.
A full account returns `409 push_subscription_limit`; `replaces_id` must be
owned or returns `404 not_found`. Retrying a completed replacement with its now-missing old ID and the exact same new endpoint/keys/login binding returns the existing new ID/binding, without rotating it again. Retryable DNS failures return
`503 push_provider_unavailable`; missing or forbidden destinations return
`400 validation_error`. The server resolves both A and AAAA, rejects the whole
answer set if any address is forbidden, and pins checked addresses again on
every send. No push HTTP is performed by subscribe.

The `binding` is a non-authenticating, case-sensitive opaque ASCII string of
1–64 bytes. Store it for this local registration and compare it exactly on
incoming pushes. The server-only session revocation token is never returned.
The integer `id` lets an authenticated owner revoke a device after its endpoint
changes. A missing binding on an older server means this lifecycle feature is
unknown; older clients may ignore additive response fields.

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
    "pinged_push_enabled": true,
    "quiet_hours": {"start": 22, "end": 7, "timezone": "Europe/Berlin"}
  },
  "devices": [
    {
      "id": 4,
      "user_agent": "Mozilla/5.0 (...) Firefox/128.0",
      "device_label": "My laptop",
      "current_session": true,
      "created_at": "2026-07-18T09:12:00+00:00",
      "last_used_at": "2026-08-01T21:03:00+00:00"
    }
  ]
}
```

`quiet_hours` is `null` when no window is configured. `devices` is ordered newest-first and includes only current account-session versions. `current_session` identifies this login, not every browser owned by the account. The list never includes the endpoint, key material, or any other value that could re-target a push.

## `POST /api/v1/push/update-preferences.php`

Updates the mute preferences for `mentioned` and `pinged` and the account's quiet-hours window in one call. These are the only mutable preferences: `revision_review`, `moderator_message_deleted`, `admin_password_reset`, and `system_policy_changed` remain non-optional short of removing every subscription, matching how those four kinds are already non-optional in the in-app timeline.

Request body:

```json
{
  "mentioned_push_enabled": false,
  "pinged_push_enabled": true,
  "quiet_hours_start": 22,
  "quiet_hours_end": 7,
  "quiet_hours_timezone": "Europe/Berlin"
}
```

Fields:

- `mentioned_push_enabled`: required boolean;
- `pinged_push_enabled`: optional boolean; when omitted, the ping preference is left unchanged;
- `quiet_hours_start`, `quiet_hours_end`: optional integers, 0-23, an hour of the day in the account's configured time zone;
- `quiet_hours_timezone`: optional, a valid IANA time zone identifier.

The three quiet-hours fields must be sent together or all omitted/`null` — setting only one or two returns `400 validation_error`. An overnight window (for example `start: 22, end: 7`) is valid and wraps across midnight. Sending all three as `null` clears the window entirely. Quiet hours suppress every push category uniformly, mutable or not; they affect only push timing, not consent.

Response is the updated preferences object, in the same shape as the `preferences` key above:

```json
{
  "preferences": {
    "mentioned_push_enabled": false,
    "pinged_push_enabled": true,
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

## Dispatch lifetime and retries

The existing `title`, `body`, `link` and `app_name` fields keep their meaning.
The additive fields are `kind` (the exact timeline kind),
`notification_id` (integer timeline row ID), `event_id` (opaque printable ASCII,
1-64 bytes, stable across retries), and `binding` (present on every bound
registration's push). Use `event_id` as a notification tag/deduplication key;
do not parse it. A future mention/DM overlap shares the event identity.
Clients must ignore unknown fields and tolerate their absence on older servers.

Plaintext JSON is strictly below 3000 bytes; display text is clipped on UTF-8
boundaries when necessary, never routing links or identifiers. Push text
contains no message/caption/filename. Device generation changes prevent delivery
of historical queued events to newly bound credentials.

Push is derived from unread durable notifications. Read rows are skipped at
claim time and rechecked before retries. Mention/ping alerts have a 30-minute
absolute lifetime; other pushed kinds have six hours. The server sends an
explicit remaining-seconds TTL and high urgency for mentions/pings, normal for
other kinds. Quiet hours and disabled preferences suppress an event permanently;
there is no deferred morning push.

The shared worker records each snapshotted device independently, retries
transient failures at most five times within that lifetime, and never retries a
device known to have succeeded merely because another failed. A device
registered after the event does not receive it. Only HTTP 404/410 prunes the
exact expired registration; authentication/configuration failures do not revoke
valid devices. Standard and non-default HTTPS push-provider ports use their
complete origin in VAPID authentication.

A worker crash after provider acceptance can cause an ambiguous retry and a
duplicate alert. Reading or revoking after acceptance cannot recall an already
queued provider message. Push remains best effort; the timeline is the source of
truth. See [operations](../operations/web-push.md) for scheduling, retry policy,
redacted counters and queue retention.

The optional `bin/dispatch-web-push --listen` mode starts dispatch on committed
notification inserts, with a 15-second safety sweep; ordinary timer sweeps remain
supported. An idle listener normally reacts within a few hundred milliseconds,
while timer mode adds up to its cadence before provider delivery. Wakes contain
no notification data, and no push HTTP runs inside the producing request.
Enabling the listener is an explicit operator decision, not an API side effect.

## Logout and client lifecycle

`POST /api/v1/logout.php` additionally removes this login session's push
registrations and the account's unbound legacy registrations; other bound
devices survive. Global session-version invalidation also suppresses old
registrations. Revocation cannot recall provider-accepted messages.

Clients must clear their local subscription, keys/binding and visible
notifications before trying logout HTTP, including offline logout. Re-register
an existing browser subscription with its login binding on every authenticated
page load when permission is already granted, without prompting automatically.
The upgraded web worker drops unbound/old-binding pushes, deduplicates with
`event_id`, and verifies the current account before following same-origin
notification links. Removing push does not revoke account login credentials.

There is no endpoint-confirmation protocol or expiring registration lease in
this release. See [operations](../operations/web-push.md) for DNS, private-provider
configuration, migration/cutover and proof boundaries.
### Passive expiry and reauthentication

An anonymous bootstrap after passive session expiry clears the local delivery
binding and visible alerts, and starts a new epoch, while preserving the raw
browser subscription and last owner/device ID. Permission is not silently lost.
Sign-in/reset/OIDC completion clears the binding first; the same user then
re-registers automatically, including across a new account session version.
A different user causes the old subscription to be unsubscribed. Explicit
logout, account closure, turning push off, and revoking the current local device
unsubscribe and clear; turning push off also closes this device's visible alerts.

The server gates subscription rows by account `session_version`, not by passive
PHP cookie/garbage-collection expiry. Without the deferred session-lease registry,
it cannot observe that expiry. Pushes may still be sent during it; once the
client observes an anonymous bootstrap, its cleared binding rejects them without
display. Before the client observes expiry, it may still have the old binding.
No passive-expiry delivery cutoff is promised by this feature.

Late sign-in or bootstrap responses cannot resurrect a later logout. Identity
requests invalidate older bootstrap side effects, and durable epochs fence
registration responses. Initial in-flight session reads share the existing
promise; additional page caching and tab-reuse navigation are separate work.