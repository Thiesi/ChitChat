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
  "created_by": 1,
  "member_role": "member",
  "invited": false
}
```

## Room management

### `POST /api/v1/rooms/create.php`

Requires Super-Administrator, Administrator, or Chat Admin.

```json
{
  "key": "general",
  "name": "General",
  "info_line": "General discussion",
  "visibility": "public",
  "minimum_age": 0
}
```

The creator becomes the immutable room owner. Room keys are lowercase, unique, and contain 3-48 letters, numbers, underscores, or hyphens.

### `POST /api/v1/rooms/update.php`

Requires a global room administrator or the room owner. The request supplies the complete editable room state:

```json
{
  "room_id": 42,
  "name": "General discussion",
  "info_line": "Be kind.",
  "visibility": "unlisted",
  "minimum_age": 16
}
```

### `POST /api/v1/rooms/delete.php`

```json
{
  "room_id": 42
}
```

Rooms are soft-deleted and stop appearing in normal queries.

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

### `GET /api/v1/rooms/messages.php?room_id=42&before_id=1000&limit=50`

Returns messages in chronological order. `before_id` is optional. `limit` defaults to 50 and may be 1-100.

Public and unlisted history may be read by authenticated outsiders. Private-room history requires membership or a global moderation role; an invitation alone is insufficient.

Deleted messages retain their metadata but return `body: null` and `deleted: true`.

### `POST /api/v1/rooms/send.php`

Requires room membership.

```json
{
  "room_id": 42,
  "body": "Hello"
}
```

Messages may contain up to 4000 characters. `/me waves` creates an `emote` message with body `waves`.

`/ping Alice please look` does not create a persistent room message. It creates a targeted realtime event for Alice, who must currently be a member of the same room. The optional ping text may contain up to 500 characters. The sender cannot ping themselves.

Successful ordinary sends return a `message` object. Successful `/ping` commands return a `ping` event object. Other slash commands are rejected.

### `POST /api/v1/rooms/delete-message.php`

Requires a room owner, room moderator, or global room moderation role.

```json
{
  "message_id": 1234
}
```

Deletion is soft, is recorded in the audit log, and emits a `message_deleted` realtime event to current room subscribers.
