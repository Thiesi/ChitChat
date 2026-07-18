# Participant-facing privacy notifications

ChitChat records durable in-app notifications for selected administrative, moderation, security, and installation-policy events that materially affect an account or its retained content.

## Notification events

The initial notification vocabulary is deliberately fixed and bounded:

- `revision_review` — retained revision history was reviewed for a room message authored by the account or a direct-message conversation in which the account participated;
- `moderator_message_deleted` — a moderator deleted a room message authored by the account;
- `admin_password_reset` — an administrator reset the account password and invalidated existing sessions;
- `system_policy_changed` — a Super-Administrator changed one or more installation-wide registration, MFA, retention, attachment-cleanup, realtime-retention, or login-history policies.

Author deletion, ordinary message editing, sign-in failures, routine audit activity, and unchanged settings do not create privacy notifications.

## Atomic delivery

Notifications are derived by PostgreSQL from the existing append-only `audit_log` record. The audited action and every affected notification are therefore committed in the same database transaction. A selected action cannot commit its audit while silently losing the matching notification, and a rolled-back action creates neither.

Each recipient/source-audit pair is unique. Direct-message revision review uses distinct recipient IDs so a malformed or future self-conversation cannot create duplicate notices.

## Recipient rules

- Room revision review notifies the retained room-message author.
- Direct-message revision review notifies both retained participants.
- Moderator deletion notifies the retained room-message author.
- Administrative password reset notifies the target account.
- Material system-policy changes notify every account that is active when the change commits.

Closing accounts may retain notices created specifically for their content during the cooling-off period so the notices remain visible after restoration. Permanently closed accounts receive no new notices, and tombstoning deletes their private notification history.

## Privacy boundary

The notification table stores only:

- the recipient account ID;
- a nullable source-audit reference;
- one fixed notification kind;
- bounded structural context such as message ID, room ID/name, or old/new public policy values;
- creation and read timestamps.

It does **not** duplicate message bodies, revision bodies, attachment names, administrative review reasons, moderation reasons, usernames, source IP addresses, password material, passkey data, recovery codes, session identifiers, or CSRF state. The authoritative administrator identity, reason, and IP remain in the restricted audit log according to audit-retention policy.

Deletion of an old audit entry sets the notification's source reference to `NULL`; it does not erase the participant-facing disclosure.

## API

### List notifications

`GET /api/v1/account/notifications/list.php`

Authentication is required. Optional query parameters:

- `before_id` — positive notification ID for reverse-ID pagination;
- `limit` — 1–100, default 50.

The response contains the newest matching notifications and the account's total unread count. Presentation text is generated server-side from the fixed kind and bounded context. System-policy notifications include human-readable old-to-new details only for values that changed.

### Mark notifications read

`POST /api/v1/account/notifications/read.php`

Authentication and the current CSRF token are required. The JSON body is either:

```json
{"ids":[123,122]}
```

or:

```json
{"all":true}
```

At most 100 explicit positive IDs are accepted. IDs belonging to another account are ignored rather than disclosed. Read state is monotonic: marking an already-read notification does not change its original read timestamp.

## Browser behavior

`/notifications.php` provides the signed-in notification center with pagination, individual read controls, and a mark-all-read action. The chat sidebar displays a capped unread badge and refreshes it once per minute while the signed-in shell is active. The notification center is usable without realtime delivery; durable database state is always authoritative.
