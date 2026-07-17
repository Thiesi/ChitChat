# Direct-message API

Direct messages are permanent server-side PostgreSQL records by default. They are not end-to-end encrypted. `GET /api/v1/session.php` exposes the current retention, administrative-inspection, independently configured revision-review, and privileged step-up policies, and clients must disclose the relevant privacy behavior to users.

All endpoints require authentication. State-changing user requests, every administrative inspection request, and every revision-review request also require the current `X-CSRF-Token`.

## Message representation

```json
{
  "id": 18,
  "sender": {"id": 4, "username": "Alice"},
  "recipient": {"id": 7, "username": "Bob"},
  "body": "Hello",
  "read_at": null,
  "created_at": "2026-07-17T00:00:00+00:00",
  "outgoing": true
}
```

`outgoing` is calculated from the requesting or event-target account's perspective. Sender and recipient SSE events therefore carry separate perspective-correct payloads.

Edits and deletions preserve historical bodies in append-only revision rows until direct-message retention hard-deletes the canonical message. Participant history never exposes those revision rows. Deleted canonical DMs use the fixed compatibility body `Message deleted.`.

## `GET /api/v1/direct-messages/users.php`

Query parameters:

- `search`: 2-32 supported username characters;
- `limit`: optional, 1-50, default 20.

Returns prefix matches excluding the current account. This endpoint is for starting a conversation and is not an unrestricted export. Blocking does not remove an account from search, so a user can reopen the conversation header and reverse their own block.

## `GET /api/v1/direct-messages/conversations.php`

Optional `limit` is 1-100 and defaults to 100.

```json
{
  "conversations": [
    {
      "user": {"id": 7, "username": "Bob"},
      "last_message": {
        "id": 18,
        "sender_id": 4,
        "body": "Hello",
        "created_at": "2026-07-17T00:00:00+00:00",
        "outgoing": true
      },
      "unread_count": 0
    }
  ]
}
```

## `GET /api/v1/direct-messages/history.php`

Query parameters:

- `user_id`: the other participant;
- `before_id`: optional exclusive cursor;
- `limit`: optional, 1-100, default 50.

Only conversations involving the authenticated account can be read. Results are returned oldest-to-newest within the requested page. Blocking does not delete, hide or rewrite existing history.

## Blocking relationship

Blocking is directional state, but a block in either direction makes new messaging unavailable in both directions. Bilateral blocks are independent: removing your own block does not remove the other participant's block.

The public relationship object deliberately exposes only:

```json
{
  "blocked_by_me": true,
  "messaging_available": false
}
```

It never returns a separate `blocked_by_other` field. A user who has not set their own block sees only generic message unavailability.

### `GET /api/v1/direct-messages/block-status.php`

Required query parameter: `user_id`.

Returns the relationship object for the authenticated user and the other participant.

### `POST /api/v1/direct-messages/block.php`

```json
{"user_id": 7}
```

Creates the authenticated user's block idempotently and returns the resulting relationship object.

### `POST /api/v1/direct-messages/unblock.php`

```json
{"user_id": 7}
```

Removes only the authenticated user's block idempotently and returns the resulting relationship object.

Block, unblock and send operations serialize on the same PostgreSQL advisory lock for the unordered user pair. A send therefore cannot race past a block operation that has already completed.

## `POST /api/v1/direct-messages/send.php`

```json
{
  "recipient_user_id": 7,
  "body": "Hello"
}
```

The body must contain 1-4,000 characters after trimming. Self-messaging is rejected. If either participant has blocked the other, the endpoint returns HTTP 403 with `direct_message_unavailable`; the error does not identify who set the block. Otherwise the insert and both targeted `direct_message` events are one transaction. The successful response is HTTP 201.

## `POST /api/v1/direct-messages/read.php`

```json
{"user_id": 7}
```

Marks all unread messages from that user to the authenticated recipient as read and returns the number changed. Read acknowledgement and history remain available while blocked.

## Realtime delivery

The existing authenticated SSE stream emits `direct_message` events targeted separately to sender and recipient accounts. This lets another sender tab update immediately while preventing unrelated accounts from observing the event.

```json
{
  "payload": {
    "message": {
      "id": 18,
      "outgoing": false
    }
  }
}
```

No direct-message event is created for a blocked send.

## Privacy and security disclosure in `GET /api/v1/session.php`

```json
{
  "security": {
    "privileged_step_up": {
      "active": false,
      "method": null,
      "verified_at": null,
      "expires_at": null,
      "max_age_seconds": 600
    }
  },
  "privacy": {
    "direct_messages": {
      "end_to_end_encrypted": false,
      "admin_inspection_enabled": true,
      "admin_inspection_role": "super_admin",
      "retention": "permanently"
    },
    "message_revisions": {
      "admin_review_enabled": false,
      "admin_review_role": "super_admin",
      "reason_required": true,
      "audit_each_review": true,
      "participant_notification": false
    }
  }
}
```

The bundled inbox separately states that edited and deleted bodies remain in the revision ledger until direct-message retention removes the canonical message.

## Administrative inspection

Inspection is disabled when `DM_ADMIN_INSPECTION_ENABLED=0`. Otherwise the permitted role is configured as `super_admin` or `admin`; the latter includes both Administrators and Super-Administrators.

Every inspection POST additionally requires active privileged step-up authentication. Step-up is current-password reauthentication and does not replace the configured role, required reason, CSRF validation, or per-page inspection audit.

User blocking controls future participant sends. It does not alter the configured retention policy or administrative inspection of retained canonical history.

Administrative inspection does **not** grant revision-review access. The inspection service reads the canonical DM table only. Historical bodies from prior edits or deletions require the separate policy and endpoint documented in [`message-revision-review.md`](message-revision-review.md).

### `GET /api/v1/admin/direct-messages/users.php`

Uses the same bounded username-prefix search but may include the inspecting account. It requires inspection authorization but does not itself return message content and does not require step-up.

### `POST /api/v1/admin/direct-messages/inspect.php`

```json
{
  "user_a_id": 4,
  "user_b_id": 7,
  "reason": "Investigating a reported safety incident",
  "before_id": null,
  "limit": 50
}
```

Requirements:

- two distinct existing users;
- a 3-500 character reason;
- optional positive exclusive cursor;
- limit 1-100;
- CSRF token;
- configured inspection role;
- active privileged step-up authentication.

Without recent step-up, the endpoint returns HTTP 403 with `step_up_required` before querying or returning conversation content. The bundled browser asks for the current password, establishes elevation through `POST /api/v1/step-up.php`, and retries the inspection once.

The history query and inspection audit insert are one transaction. Content is returned only after the audit record succeeds. Every page request creates a separate `admin.direct_messages_inspected` entry containing:

- actor and IP through the normal audit columns;
- both user IDs and usernames;
- reason;
- cursor and limit;
- returned count;
- oldest and newest returned message IDs.

A successful password verification has its own `auth.privileged_step_up_succeeded` record and does not replace the per-page inspection audit.

Message bodies and passwords are never duplicated into audit metadata. Unauthorized, disabled, stale-step-up, or invalid requests return no content and do not create a successful-inspection record.
