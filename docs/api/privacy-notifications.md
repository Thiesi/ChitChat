# Participant-facing privacy notifications

ChitChat records durable in-app notifications for selected administrative, moderation, security, and installation-policy events that materially affect an account or its retained content.

## Notification events

The initial notification vocabulary is deliberately fixed and bounded:

- `revision_review` — retained revision history was reviewed for a room message authored by the account or a direct-message conversation in which the account participated;
- `moderator_message_deleted` — a moderator deleted a room message authored by the account;
- `admin_password_reset` — an administrator reset the account password and invalidated existing sessions;
- `system_policy_changed` — a Super-Administrator changed one or more installation-wide registration, MFA, retention, attachment-cleanup, realtime-retention, or login-history policies.

An account whose profile picture a moderator removed gets an `avatar_removed` notification (it names no moderator). An account whose username a moderator replaced gets a `username_change_required` notification with the moderator's reason, linking to `/choose-username.php` ([required username changes](username-change.md)); it names no moderator. Stored `mentioned` and `pinged` notifications keep their sender's name current: a required change rewrites their `sender_username`. Room members also get `room_deleted` and `room_restored` notifications when a room they belong to is deleted or restored (the account that did it is not notified); they store only the room ID and name.

Two participation kinds share the same table: `mentioned` (see [replies and mentions](../architecture/0004-replies-and-mentions.md)) and `pinged`, created for the target of every `/ping`. A `pinged` notification stores only the ping ID, room ID and name, and sender; the ping text stays in `room_pings` and is shown only inside the room. Its link opens the room at the ping (`/?room_id=…&ping_id=…`).

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

`/notifications.php` (titled **Notifications**, since it also lists @mentions) provides the signed-in notification center with pagination, individual read controls, and a mark-all-read action. In the chat top bar, the bell shows a capped unread badge, refreshed once per minute while the signed-in shell is active, and opens a preview of the five newest notifications with a mark-all-read action; opening an unread linked notification marks it read. The notification center is usable without realtime delivery; durable database state is always authoritative.

## Opt-in direct-message participation notices

The additive `direct_message_received` kind is created for opted-in recipients
of new text/attachment DMs, transactionally with the message. The absent
`direct_message_push_enabled` preference is false. Opting in never backfills,
and editing/reactions do not create another notice.

Context stores only `message_kind: "direct"`, `message_id` and `sender_user_id`.
The server renders the current name as "Rowan sent you a direct message." and
links to `/messages.php?with=<id>`; an unavailable/closed/deleted sender uses
"Someone" and `/messages.php`. Message bodies, captions and filenames never enter
this notification or its push. Current identities are fetched in one batch for
the timeline.

Both DM and mention semantic records can coexist and contribute to unread
counts, but the shared dispatcher sends one selected event per device. See the
[overlap contract](web-push.md#opt-in-incoming-direct-message-alerts).
Reading a conversation marks exactly its covered DM notice rows read, atomically
with the underlying messages. Mention notification read state stays independent.
Older clients must tolerate this additional kind and ignore unknown fields.