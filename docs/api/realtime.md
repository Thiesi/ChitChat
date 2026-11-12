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

The stream may also send a bare `id: <n>` line with no `event` or `data` (#281). It moves the cursor past events this account can't see; per the SSE specification it updates `Last-Event-ID` without dispatching an event, and a native client should store it as its resume position like any other `id:`.

The stream sends heartbeat comments at least every ten seconds and closes after approximately 25 seconds. Browser `EventSource` reconnects automatically. The server requests a one-second retry interval.

The stream releases the PHP session lock before polling, so the same browser session can continue making API requests while SSE is open.

### Settled resume: `resume=settled` (#286)

Event IDs are assigned when an event is written, but it only becomes visible when its transaction commits. Two events written at about the same time can therefore commit out of order: event 101 can be delivered before event 100 exists. In the default mode, the stream's position moves past 101, and a client that later receives 100's commit never gets it from the stream. It sees the message only after a history reload.

A client that sets `?resume=settled` never misses such an event. Support is advertised as `capabilities.realtime_settled_resume: true` in `GET /api/v1/session.php`.

```text
GET /api/v1/events/stream.php?resume=settled
```

In this mode:

- **The SSE `id:` is a resume position, not the event's identity.** Every visible event with an ID at or below it has already been sent. It may be lower than the event's own ID, which stays in `data.id`. It never decreases within a connection. Each `id:` is safe to store as soon as that event has been received, including the last event before a dropped connection.
- **Resume with the stored `id:`** (as `Last-Event-ID` or `after_id`) and keep `resume=settled`. Browser `EventSource` does both automatically, because it reconnects to the same URL with the last `id:`.
- **A reconnect may resend events,** those sent above the resume position. **Clients must drop an event whose `data.id` they have already processed.** Keep the IDs seen above your stored position, and forget any once your stored position passes them. As a backstop, apply message events idempotently by message ID: the same `message.id` never adds a second message or a second unread count. A resend covers only events sent since the position last settled: normally the last second or less, and never more than one normal batch per poll. While one writing transaction stays open (a stuck database session), the position waits for it, so the window covers events sent since that transaction began. Nothing is skipped past it. Operators bound this with `idle_in_transaction_session_timeout` (see `INSTALL.md`).
- **Bare `id:` lines are optional for the client.** The stream also sends a bare `id: <n>` (no `event`, no `data`) when the position settles without a new event. Storing it saves the server rescanning on the next reconnect. A client whose SSE reader doesn't expose bare IDs (for example OkHttp's) loses nothing: the `id:` of the next dispatched event carries the position too, and correctness never depends on the bare line.

Without `resume=settled`, the stream behaves exactly as before: `id:` is the event's own ID, a reconnect never resends, and a late-committing lower ID can be missed. The room list's matching unread fields are `unread_settled_message_id` and `unread_counted_ids` (see [`rooms.md`](rooms.md)).

### Replayed events

Retained events (see `realtime_event_retention_hours`) are replayed to a client that resumes from an older cursor. Two rules keep a replay from handing out message content the account couldn't read now (#276):

- **Room events start at the membership.** A room's events are delivered only if they were published after the account's current membership began. Joining (or rejoining) a room never replays its earlier events. Global moderators, who see every room, are not bound this way. The bound compares transaction start times: a message whose sending began just before the join but committed after it is not streamed to the new member, who sees it in history on the next load.
- **Messages are served as they are now.** A `room_message` or `direct_message` event is re-rendered from the message's current state for the viewer when the message, or the message it quotes, was edited or deleted after the event was published. An edited message carries its current text; a deleted one has `"body": null, "deleted": true`; a message that no longer exists is redacted the same way. The later edit or deletion event is still delivered, in order. Events whose message hasn't changed are served exactly as stored.

Other event types are served exactly as stored, subject only to the membership rule. A replayed message whose sender (or quoted sender) has been renamed since, for example by a moderator (#164), is re-rendered too, so it carries the current name (#281).

## Visibility

- `global_broadcast`: visible to every authenticated user.
- `room_message`, `message_deleted`, `room_broadcast`, and `presence_changed`: visible to current room members and global room moderators.
- `message_reaction_changed`: for a room message, visible to current room members and global room moderators, as one shared event; for a direct message, published as two separate targeted events, one to each participant.
- `rooms_changed`: for a public room (or one that was public before an update), visible to every authenticated user; for an unlisted or private room, visible to current room members and global room moderators; for a change to one account's membership, role, or invitation, visible only to that account.
- `ping`: published as two separate targeted events, one to the sender and one to the target, so each sees it on every open device.
- `forced_logout`: visible only to the targeted user.
- `typing`: in a room, visible to current room members and global room moderators; in a direct conversation, visible only to the other participant.
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

### `rooms_changed`

A signal that the viewer's room list may have changed. It carries an empty payload; clients respond by re-fetching `GET /api/v1/rooms/list.php`, which applies the usual room authorization, so the event itself reveals nothing about a room. It is emitted when a room is created, updated, or deleted; when an account joins or leaves a room; and when an account is invited, has its invitation revoked, has its room role changed, or is removed from a room. The ChitChat client collapses bursts into one re-fetch. Room-list signals expire from the event ledger after one hour.

Because room membership is evaluated when events are read, a member who joins an unlisted or private room can also receive that room's earlier `rooms_changed` events; the empty payload makes this harmless.

### `ping`

Created through `/ping username [message]` in the room send endpoint. The target must be a member of the same room. Each ping is stored in `room_pings` and also creates a `pinged` notification for the target, so it reaches someone who is offline. The event payload carries the stored ping:

```json
{
  "ping": {
    "id": 31,
    "room_id": 42,
    "sender": {"id": 4, "username": "Alice"},
    "target": {"id": 7, "username": "Bob"},
    "message": "Standup in five",
    "created_at": "2026-10-04T09:02:30+00:00"
  }
}
```

The events expire from the event ledger after one day; the stored ping follows room-message retention.

### `typing`

"Alex is typing…". Created through `POST /api/v1/typing.php` (CSRF) with either `{"room_id": 42}` (members only) or `{"recipient_user_id": 7}` (only while the two may message each other, so blocking in either direction stops it). The payload carries no text, only who is typing:

```json
{ "room_id": 42, "user": { "id": 17, "username": "Alex" } }
```

`room_id` is `null` in a direct conversation. The indicator works both ways: an account that turned it off (`POST /api/v1/account/typing.php` with `{"share_typing": false}`; `session.php` reports `share_typing`) publishes no signals (the response says `"signalled": false`) and is delivered none. Each signal expires after 8 seconds and is then never delivered; maintenance removes it. A second signal from the same person to the same place within 3 seconds is dropped (the response says `"signalled": false`), and the `typing` rate-limit policy bounds the rest. The browser signals at most every 4 seconds while someone types, sends nothing for `/commands` or an empty composer, shows a name for about 6 seconds after its last signal, removes it as soon as that person's message arrives, and never shows people the viewer ignores.

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

### `user_renamed`

```json
{ "user": { "id": 17, "username": "member-k7q2xm" } }
```

Sent to every connection when a moderator replaces an account's username and again when the account chooses its new one ([required username changes](username-change.md)). Clients update every rendered occurrence of that user ID; for the viewer's own ID they refresh `session.php`. Stored events are not rewritten: a client replaying older events after a reconnect receives this event after them, in order.

### `forced_logout`

Kicks, bans, and administrator password resets publish a targeted forced-logout event in the same transaction that increments the user's session version. The stream also revalidates session version and active bans during every poll, so invalidation remains enforced even if the event is missed or expires.

## Proxy and PHP requirements

Reverse proxies must not buffer the SSE response. The endpoint sends `X-Accel-Buffering: no`; Nginx and other proxies should also be configured to pass the stream through without response buffering and with an upstream timeout longer than 25 seconds.

The implementation intentionally uses short-lived reconnecting streams rather than holding a PHP worker indefinitely.

Registered-login revocation is checked **before each event batch**. Only the revoked connection receives event: forced_logout with data: {"reason":"session_revoked"}. Single-login revocation does not alter the account-wide event log; other devices continue. Account/version invalidation keeps reason session_invalidated.

An SSE stream re-checks its registered login after fetching a visible batch and before writing that batch. If the holder was revoked, the fetched events are discarded and only the top-level forced_logout event with reason session_revoked is emitted for that connection. Other invalid/retired holder proofs use session_invalidated. Other registered logins keep their own streams. This check does not create an account-wide forced_logout event in the event ledger.
