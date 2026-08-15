# ADR 0006: Notification preferences and Web Push

- Status: Proposed (product decisions resolved; implementation not started)
- Date: 2026-08-15

## Context

The roadmap lists notification preferences and optional Web Push as the next candidate after reactions: "Per-category preferences, quiet hours and privacy-preserving Web Push are candidates after replies/mentions establish a broader user-notification model. Push payloads should omit message bodies by default."

That broader model already exists as `account_notifications` (`0017_privacy_notifications.sql`): a durable, per-user, append-only table populated two ways —

1. a PostgreSQL trigger (`create_privacy_notifications_from_audit()`) that inserts `revision_review`, `moderator_message_deleted`, `admin_password_reset`, and `system_policy_changed` rows atomically alongside the audit-log row that caused them; and
2. direct PHP inserts from `ChitChat\Mentions\MentionNotifier` for the `mentioned` kind, alongside room/DM mention resolution.

`PrivacyNotificationService::present()` already renders each kind into a privacy-safe title/message/link — sender username and room name where relevant, never a raw message body — for the in-app timeline. This ADR reuses that existing text rather than inventing a second payload-rendering path.

Two things make this feature different from replies/mentions and reactions: it introduces the project's **first production Composer dependency** (this repository has none today — `composer.json`'s `require` block is PHP plus extensions only), and it needs a delivery mechanism that runs **outside** an HTTP request, since four of the five notification kinds are created by a database trigger with no PHP call site to hook synchronously.

## Decision

### Subscriptions: one row per browser/device, not per user

```sql
CREATE TABLE push_subscriptions (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    endpoint TEXT NOT NULL,
    p256dh_key TEXT NOT NULL,
    auth_key TEXT NOT NULL,
    user_agent VARCHAR(256) NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    last_used_at TIMESTAMPTZ NULL,
    UNIQUE (endpoint)
);
```

A user may have several active subscriptions (phone, laptop, a second browser); `endpoint` is unique because it's the push service's own per-registration URL, and the same subscription is never meaningfully re-created. `ON DELETE CASCADE` matches every other per-user table in this schema. A subscription is created client-side by `PushManager.subscribe()` using the deployment's VAPID public key, then POSTed to a new authenticated endpoint; it's removed explicitly (unsubscribe button) or implicitly when the push service reports it's gone (below).

### Preferences: per-category, defaulting to on, quiet hours as a stored local time window

```sql
CREATE TABLE notification_preferences (
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    category VARCHAR(32) NOT NULL CHECK (category IN ('mentioned')),
    push_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    PRIMARY KEY (user_id, category)
);

ALTER TABLE users
    ADD COLUMN push_quiet_hours_start SMALLINT NULL CHECK (push_quiet_hours_start BETWEEN 0 AND 23),
    ADD COLUMN push_quiet_hours_end SMALLINT NULL CHECK (push_quiet_hours_end BETWEEN 0 AND 23),
    ADD COLUMN push_quiet_hours_timezone VARCHAR(64) NULL;
```

An absent row in `notification_preferences` means "on" (the default), so enabling push for a new category later doesn't silently opt existing users out — only an explicit mute row suppresses it. Quiet hours are three nullable columns on `users` (all three or none; enforced in the service, not a `CHECK`, to match how `WEBAUTHN_RP_ID`/`WEBAUTHN_ORIGIN` pairing is already validated in application code rather than SQL) rather than a separate table, since there's exactly one quiet-hours window per account, not a list. An overnight window (e.g. 22–7) is expressed the same way a plain range is; the comparison wraps. Quiet hours apply uniformly to every push, mutable or not — suppressing an overnight push is about timing, not consent, so it isn't in tension with the four kinds below being otherwise non-optional.

**All five notification kinds are push-eligible (confirmed below), but `category` only accepts `'mentioned'` — the one kind a user can mute. The four trigger-inserted security/audit kinds (`revision_review`, `moderator_message_deleted`, `admin_password_reset`, `system_policy_changed`) are always dispatched when push is configured; no preference row for them can exist, by construction of the `CHECK` constraint, not just by convention.**

### Dispatch: a periodic CLI sweep over undelivered notifications, not a request-time side effect

```sql
ALTER TABLE account_notifications
    ADD COLUMN push_dispatched_at TIMESTAMPTZ NULL;
```

A new `bin/dispatch-web-push` command (mirroring `bin/maintenance-cleanup`'s shape: a thin CLI wrapper around a service class, safe to run repeatedly, no in-process daemon) selects `account_notifications` rows where `push_dispatched_at IS NULL`, covering all five kinds, and for each: checks quiet hours (skip if inside the window, for every kind), additionally checks `notification_preferences` only when `kind = 'mentioned'` (skip if explicitly muted — the other four kinds have no preference to check by construction), then sends to every active subscription for that `user_id` using the already-rendered `present()` title/message, and marks `push_dispatched_at = NOW()` — regardless of per-subscription success or failure. Push is a best-effort nudge, not a delivery guarantee, matching how ADR 0004 already treats realtime SSE delivery for mentions ("without making realtime delivery a correctness requirement"): a skipped or failed push never blocks or retries, because the durable in-app notification is the actual source of truth.

This is a new, deliberately short-interval systemd timer (operators are expected to run it roughly every 30–60 seconds; existing maintenance/backup timers run far less often and are the wrong cadence for a feature whose value is "notice this while it's still relevant"). It requires no queue and no Redis: PostgreSQL's `push_dispatched_at IS NULL` filter over an already-durable table is the queue, the same pattern this project already uses for SSE reconnection cursors and rate-limit windows.

### Payload privacy: reuse the existing sanitized text, never the raw body

The push payload is `{ "title": ..., "body": ..., "link": ... }` built from the same `title`/`message`/`link` `PrivacyNotificationService::present()` already computes for the in-app timeline — sender username and room name, never message content. This satisfies "push payloads should omit message bodies by default" without a second rendering path to keep in sync, and matches the roadmap's framing that push should carry no more than what's already considered safe for a durable, exportable, non-ephemeral in-app record.

### Subscription pruning: remove on 404/410, never on any other error

A push service returns `404`/`410` when a subscription is permanently gone (browser data cleared, extension revoked, endpoint rotated past its grace period). Only those two statuses delete the `push_subscriptions` row during dispatch; any other failure (timeout, 5xx, malformed key) is logged and left for the next sweep, since transient failures shouldn't silently unsubscribe a user.

### Deployment configuration: disabled unless a VAPID keypair is configured, mirroring WebAuthn

New `Config` fields `webPushEnabled` (derived, not directly settable), `webPushVapidPublicKey`, `webPushVapidPrivateKey`, `webPushVapidSubject` (a `mailto:` or `https:` contact URL the push service may use to reach the operator), all sourced from environment variables. Like `WEBAUTHN_RP_ID`/`WEBAUTHN_ORIGIN`, the feature is silently inert — no subscribe button rendered, dispatch command exits immediately — unless both keys are present; there is no separate boolean flag to keep in sync with key presence.

### Dependency: `minishlink/web-push`

VAPID JWT signing (ECDSA P-256) and RFC 8291 `aes128gcm` payload encryption (ECDH key agreement, HKDF, AES-128-GCM) are real, security-sensitive cryptographic code. PHP's OpenSSL extension exposes the underlying primitives but has no built-in HKDF and no built-in Web Push envelope construction, so implementing this from scratch means hand-rolling a cryptographic protocol with no existing test coverage anywhere in this project. `minishlink/web-push` (MIT, widely used, actively maintained) is added as this project's first production Composer dependency instead — a deliberate, confirmed exception to the zero-dependency baseline, not a precedent for casually adding others. Hand-rolled encryption to preserve that streak would be the kind of cleverness this project's own conventions (explicit, auditable, no unnecessary custom crypto) argue against.

## Consequences

- Two new small tables (`push_subscriptions`, `notification_preferences`) plus three nullable columns on `users` and one on `account_notifications`; no change to the existing notification-creation paths (trigger or `MentionNotifier`).
- A new, short-interval, operator-scheduled CLI command is required for push to be timely; an installation that never configures the systemd timer simply never dispatches (rows accumulate `push_dispatched_at IS NULL` harmlessly, visible in system status as a growing backlog if desired later).
- This introduces the project's first production runtime Composer dependency (`minishlink/web-push`) — a confirmed, deliberate exception, not a routine addition, given `composer.json` has none today.
- All five notification kinds push once configured; only `mentioned` can be muted per account. A user cannot silence a security/audit push (`revision_review`, `moderator_message_deleted`, `admin_password_reset`, `system_policy_changed`) short of disabling push entirely (removing all subscriptions) — an intentional tradeoff, matching how those same four kinds are already non-optional in the in-app timeline today.
- Push remains best-effort and non-authoritative; the in-app `account_notifications` timeline stays the durable record and the only thing anything else (export, audit) depends on.

## Decisions

The three questions this ADR originally left open have been resolved:

1. **Take the `minishlink/web-push` dependency**, confirmed over hand-rolled crypto. This is the project's first production Composer dependency.
2. **All five notification kinds are push-eligible.** `revision_review`, `moderator_message_deleted`, `admin_password_reset`, and `system_policy_changed` push immediately alongside `mentioned`, but are non-mutable — no per-account opt-out for those four, only quiet-hours suppression applies to them the same as everything else. Only `mentioned` has a `notification_preferences` mute toggle.
3. **New subscriptions default to push-on** for every category until explicitly muted; the OS-level permission prompt is treated as the meaningful consent step.

No open product decisions remain; the next step is implementation, starting with migration `0022_web_push.sql`.
