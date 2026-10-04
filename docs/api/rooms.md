# Rooms and messages API

All endpoints require an authenticated session. Every POST request also requires the current `X-CSRF-Token` header.

## Visibility

- `public`: appears in every authenticated user's room list. Any authenticated user may read history, but only members may send.
- `unlisted`: omitted from outsiders' room lists, but an authenticated user with the room ID may view it and join it.
- `private`: appears only to members, invited users, and global room moderators. Joining normally requires an invitation.

Super-Administrators, Administrators, Chat Admins, and Global Moderators may view and moderate every room. Only Super-Administrators, Administrators, and Chat Admins may create rooms. A room owner may manage their own room.

## Room discovery

### `GET /api/v1/rooms/list.php`

Returns rooms visible in the user's room list. Global room moderators receive all active rooms.

For rooms the account has joined, each entry also carries `unread_count` (messages from others, not deleted, after the read position; counting stops at 100, shown as "99+") and `last_read_message_id` (`null` until the account first reads there). Other rooms report `0` and `null`.

### `POST /api/v1/rooms/read.php`

Requires authentication, CSRF and membership of the room.

```json
{ "room_id": 42, "message_id": 1235 }
```

Moves the account's read position in the room forward to `message_id`. It never moves back and never past the room's newest message; the response returns the resulting `last_read_message_id`. The browser calls it once the newest message has been on screen in a visible tab. Read positions are part of the personal-data export and are deleted when an account closure is finalized.

### `GET /api/v1/rooms/detail.php?room_id=42`

Returns one room if the caller may see its metadata.

Room objects contain:

```json
{
  "id": 42,
  "key": "general",
  "name": "General",
  "info_line": "General discussion",
  "visibility": "public",
  "minimum_age": 0,
  "inactivity_timeout_seconds": 0,
  "created_by": 1,
  "member_role": "member",
  "invited": false
}
```

`inactivity_timeout_seconds` is 0 when disabled. Otherwise it is 120-86400 seconds and applies only to active presence, not persistent membership.

## Room management

### `POST /api/v1/rooms/create.php`

Requires Super-Administrator, Administrator, or Chat Admin.

```json
{
  "key": "general",
  "name": "General",
  "info_line": "General discussion",
  "visibility": "public",
  "minimum_age": 0,
  "inactivity_timeout_seconds": 0
}
```

The inactivity field is optional and defaults to 0. The creator becomes the immutable room owner. Room keys are lowercase, unique, and contain 3-48 letters, numbers, underscores, or hyphens.

### `POST /api/v1/rooms/update.php`

Requires a global room administrator or the room owner. The request supplies the complete editable room state:

```json
{
  "room_id": 42,
  "name": "General discussion",
  "info_line": "Be kind.",
  "visibility": "unlisted",
  "minimum_age": 16,
  "inactivity_timeout_seconds": 900
}
```

The inactivity field is optional for backward-compatible API clients. When omitted, the room's existing inactivity policy is preserved.

### `POST /api/v1/rooms/delete.php`

```json
{
  "room_id": 42
}
```

Deletion has two stages, so a mistake can be undone and moderation evidence is kept:

1. **Delete** (this endpoint): the room disappears for everyone at once, including live room lists ([`rooms_changed`](realtime.md#rooms_changed)), and every other member gets a `room_deleted` notification. Nothing else is removed yet.
2. **Purge**: maintenance permanently removes the room, with its messages, attachment files, memberships, invitations, and pings, once `deleted_room_grace_days` (an Operational setting, default 30; `0` keeps deleted rooms indefinitely) has passed since deletion. Each purge is audited as `room.purged`. Moderation cases about the room's messages keep their report evidence and only lose the room reference.

Super-Administrators, Administrators, Chat Admins, and the room's owner may delete; room moderators may not. Deleting requires active privileged step-up and is audited as `room.delete`.

### `POST /api/v1/rooms/restore.php`

```json
{
  "room_id": 42
}
```

Restores a deleted room that has not been purged yet. The same people who may delete may restore. It requires active privileged step-up, is audited as `room.restore`, notifies the other members (`room_restored`), and returns `{"room": {…}}`. A purged or unknown room returns `404 room_not_found`.

### `GET /api/v1/rooms/deleted.php`

Lists deleted rooms the account could restore, newest deletion first: every deleted room for Super-Administrators, Administrators, and Chat Admins, and their own rooms for owners. Each entry has `id`, `key`, `name`, `visibility`, `deleted_at`, and `purge_after` (when maintenance may remove it, or `null` while the grace period is `0`).

## Membership

### `POST /api/v1/rooms/join.php`

```json
{
  "room_id": 42
}
```

Private rooms require an invitation unless the user has a global moderation role. If the room has a minimum age, the account must have a birth date and meet the requirement. Joining consumes any invitation.

### `POST /api/v1/rooms/leave.php`

```json
{
  "room_id": 42
}
```

Room owners cannot leave in v1 because ownership transfer is not yet implemented.

### `POST /api/v1/rooms/invite.php`

Requires a global room administrator or the room owner.

```json
{
  "room_id": 42,
  "target_user_id": 17
}
```

### `POST /api/v1/rooms/role.php`

Requires a global room administrator or the room owner.

```json
{
  "room_id": 42,
  "target_user_id": 17,
  "role": "moderator"
}
```

The endpoint accepts `member` and `moderator`. Ownership cannot be transferred through this endpoint.

## Messages

### Message representation

`body` is always the plain text as written. The browser client displays a small formatting subset from it (`*bold*` or `**bold**`, `_italic_`, `` `code` ``, fenced code blocks, `> ` quotes, and http/https links opening in a new tab with `rel="noopener noreferrer nofollow"`), building elements in the page rather than HTML, so a message can never inject markup. Markers count only when they hug a word, so `2 * 3 * 4` and `snake_case_names` stay as typed. The same applies to direct messages.

Room history, `POST send.php`, and the `room_message` realtime event all return messages in this shape:

```json
{
  "id": 91,
  "room_id": 4,
  "sender_id": 8,
  "username": "Example",
  "type": "text",
  "body": "Hello @Bob",
  "attachment": null,
  "deleted": false,
  "created_at": "2026-07-17T00:00:00+00:00",
  "reply_to": null,
  "mentions": [
    {"user_id": 7, "username": "Bob", "broadcast": false}
  ],
  "reactions": []
}
```

`reply_to` is `null` for an ordinary message, or `{"kind": "room", "message_id": 80, "available": true, "message": {...}}` for a reply; `available` is `false` and `message` is `null` when the referenced message is deleted, retention-expired, or otherwise no longer accessible to the viewer. `mentions` lists every `@username`, `@room`, and `@here` token the server actually resolved and authorized at send time — an unauthorized or unresolvable token is left as plain text and never appears here. `reactions` is documented in full in [`message-mutations.md`](message-mutations.md) and the reactions ADR; it groups reactor identities by emoji and includes `reacted_by_me` for the requesting account.

### `GET /api/v1/rooms/messages.php?room_id=42&before_id=1000&limit=50`

Returns messages in chronological order. `before_id` is optional. `limit` defaults to 50 and may be 1-100.

Public and unlisted history may be read by authenticated outsiders. Private-room history requires membership or a global moderation role; an invitation alone is insufficient.

Deleted messages retain their metadata but return `body: null` and `deleted: true`.

### `POST /api/v1/rooms/send.php`

Requires room membership.

```json
{
  "room_id": 42,
  "body": "Hello",
  "reply_to_message_id": null
}
```

Messages may contain up to 4000 characters. `/me waves` creates an `emote` message with body `waves`. `/ping username [message]` pings another current room member: the response is `201` with `{"ping": {…}}` (the same object as the [`ping` event](realtime.md#ping)) instead of a message, the ping is stored and shown only to its sender and target, and the target also gets a `pinged` notification. Other slash commands are rejected. `reply_to_message_id` is an optional positive integer identifying another message in the same room; omit it or send `null` for an ordinary message. `@username` mentions, plus room-scoped `@room`/`@here` broadcasts, are parsed from `body` automatically — there is no separate mentions field on the request.

### `POST /api/v1/rooms/delete-message.php`

Requires a room owner, room moderator, or global room moderation role.

```json
{
  "message_id": 1234
}
```

Deletion is soft and is recorded in the audit log.
