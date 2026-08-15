# Realtime events API

ChitChat uses database-backed Server-Sent Events (SSE). Every persistent event has one monotonically increasing PostgreSQL ID. Clients retain the last processed ID and use it when reconnecting.

## Stream

### `GET /api/v1/events/stream.php`

Requires an authenticated PHP session. CSRF is not required for this read-only endpoint.

The client may resume using either the standard `Last-Event-ID` header or the fallback query parameter `after_id`:

```text
GET /api/v1/events/stream.php?after_id=1234
```

The response uses `Content-Type: text/event-stream` and sends:

```text
id: 1235
event: room_message
data: {"id":1235,"type":"room_message",...}

```

The stream sends heartbeat comments at least every ten seconds and closes after approximately 25 seconds. Browser `EventSource` reconnects automatically. The server requests a one-second retry interval.

The stream releases the PHP session lock before polling, so the same browser session can continue making API requests while SSE is open.

## Visibility

- `global_broadcast`: visible to every authenticated user.
- `room_message`, `message_deleted`, `room_broadcast`, and `presence_changed`: visible to current room members and global room moderators.
- `message_reaction_changed`: for a room message, visible to current room members and global room moderators, as one shared event; for a direct message, published as two separate targeted events, one to each participant.
- `ping`: visible only to the targeted user.
- `forced_logout`: visible only to the targeted user.
- `direct_message`: visible only to the sender and recipient, as separate perspective-correct events; see [`direct-messages.md`](direct-messages.md#realtime-delivery).

Public room history remains readable through the paginated message endpoint, but realtime room delivery begins only after joining the room.

Global room moderators are Super-Administrators, Administrators, Chat Admins, and Global Moderators. They receive every non-targeted room event. They do not receive another user's targeted ping or forced-logout event.

## Event envelope

Persistent event data has this shape:

```json
{
  "id": 1235,
  "type": "room_message",
  "room_id": 42,
  "actor_user_id": 17,
  "payload": {},
  "created_at": "2026-07-16 21:00:00+00"
}
```

`target_user_id` is used internally for filtering and is not exposed in the event envelope.

Clients should deduplicate by event ID. Duplicate transport delivery is permitted, particularly around reconnects.

## Event types

### `room_message`

Emitted in the same transaction that stores a room message.

```json
{
  "message": {
    "id": 80,
    "room_id": 42,
    "sender_id": 17,
    "username": "Alice",
    "type": "text",
    "body": "Hello",
    "attachment": null,
    "deleted": false,
    "created_at": "2026-07-16 21:00:00+00",
    "reply_to": null,
    "mentions": [],
    "reactions": []
  }
}
```

The message shape matches [`rooms.md`](rooms.md#message-representation) exactly, since the event is published from the same row that ordinary history reads.

### `message_deleted`

Emitted in the same transaction as an audited soft deletion.

### `message_reaction_changed`

Emitted whenever a reaction is added or removed, carrying the message's complete current reaction state rather than a single delta:

```json
{
  "message_kind": "room",
  "message_id": 80,
  "room_id": 42,
  "reactions": [
    {"emoji": "👍", "users": [{"id": 17, "username": "Alice"}], "reacted_by_me": true}
  ]
}
```

`message_kind` is `room` or `direct`; a direct-message payload omits `room_id`. For a room message this is one shared broadcast to every current member, so `reacted_by_me` reflects only the acting account's perspective — a recipient other than the actor must not treat it as their own reaction state. For a direct message, ChitChat publishes two separately computed targeted events (one per participant), each with `reacted_by_me` correct for that recipient, matching how other direct-message events are already perspective-correct.

### `ping`

Created through `/ping username [message]` in the room send endpoint. The target must be a member of the same room. Pings expire from the event ledger after one day.

### `room_broadcast` and `global_broadcast`

Created through `POST /api/v1/broadcast.php`:

```json
{
  "room_id": 42,
  "message": "Maintenance begins shortly."
}
```

Omit `room_id` for a global broadcast. Room broadcasts require room moderation permission. Global broadcasts require Super-Administrator, Administrator, or Chat Admin.

### `presence_changed`

Emitted when a presence connection enters a room, changes rooms, leaves through inactivity, or expires after an unclean disconnect.

```json
{
  "room_id": 42,
  "user_id": 17
}
```

The event is an invalidation signal, not a complete online/offline transition. Because one user may have several tabs, clients must reload `GET /api/v1/rooms/presence.php` to obtain authoritative aggregated state. These transient invalidations expire from the event ledger after one hour.

### `forced_logout`

Kicks, bans, and administrator password resets publish a targeted forced-logout event in the same transaction that increments the user's session version. The stream also revalidates session version and active bans during every poll, so invalidation remains enforced even if the event is missed or expires.

## Proxy and PHP requirements

Reverse proxies must not buffer the SSE response. The endpoint sends `X-Accel-Buffering: no`; Nginx and other proxies should also be configured to pass the stream through without response buffering and with an upstream timeout longer than 25 seconds.

The implementation intentionally uses short-lived reconnecting streams rather than holding a PHP worker indefinitely.
