# Direct-message API

Direct messages are permanent server-side PostgreSQL records. They are not end-to-end encrypted. `GET /api/v1/session.php` exposes the current retention and administrative-inspection policy, and clients must disclose it to users.

All endpoints require authentication. State-changing user requests and every administrative inspection request also require the current `X-CSRF-Token`.

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

## `GET /api/v1/direct-messages/users.php`

Query parameters:

- `search`: 2-32 supported username characters;
- `limit`: optional, 1-50, default 20.

Returns prefix matches excluding the current account. This endpoint is for starting a conversation and is not an unrestricted export.

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

Only conversations involving the authenticated account can be read. Results are returned oldest-to-newest within the requested page.

## `POST /api/v1/direct-messages/send.php`

```json
{
  "recipient_user_id": 7,
  "body": "Hello"
}
```

The body must contain 1-4,000 characters after trimming. Self-messaging is rejected. The insert and both targeted `direct_message` events are one transaction. The response is HTTP 201.

## `POST /api/v1/direct-messages/read.php`

```json
{"user_id": 7}
```

Marks all unread messages from that user to the authenticated recipient as read and returns the number changed.

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

## Privacy disclosure in `GET /api/v1/session.php`

```json
{
  "privacy": {
    "direct_messages": {
      "end_to_end_encrypted": false,
      "admin_inspection_enabled": true,
      "admin_inspection_role": "super_admin",
      "retention": "permanent"
    }
  }
}
```

## Administrative inspection

Inspection is disabled when `DM_ADMIN_INSPECTION_ENABLED=0`. Otherwise the permitted role is configured as `super_admin` or `admin`; the latter includes both Administrators and Super-Administrators.

### `GET /api/v1/admin/direct-messages/users.php`

Uses the same bounded username-prefix search but may include the inspecting account. It requires inspection authorization.

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
- configured inspection role.

The history query and audit insert are one transaction. Content is returned only after the audit record succeeds. Every page request creates a separate `admin.direct_messages_inspected` entry containing:

- actor and IP through the normal audit columns;
- both user IDs and usernames;
- reason;
- cursor and limit;
- returned count;
- oldest and newest returned message IDs.

Message bodies are never duplicated into audit metadata. Unauthorized, disabled or invalid requests return no content and do not create a successful-inspection record.
