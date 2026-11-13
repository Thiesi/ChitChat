# Rooms and messages API

All endpoints require an authenticated session. Every POST request also requires the current `X-CSRF-Token` header.

## Visibility

- `public`: appears in every authenticated user's room list. Any authenticated user may read history, but only members may send.
- `unlisted`: omitted from outsiders' room lists, but an authenticated user with the room ID may view it and join it.
- `private`: appears only to members, invited users, and global room moderators. Joining normally requires an invitation.

Super-Administrators, Administrators, Chat Admins, and Global Moderators may view and moderate every room. Only Super-Administrators, Administrators, and Chat Admins may create rooms. A room owner may manage their own room.

### Room visibility in errors (#326)

**A room the caller can't see answers exactly like a room that doesn't exist**, with the same status, code and message, so no route confirms that a private room exists. "Can't see" means a `private` room without membership or an invitation, for a caller without a global room-moderation role. Public and unlisted rooms are visible to every signed-in caller, so nothing changes for them; an unlisted room stays readable and joinable by ID or invitation.

- **Routes addressed by a room ID** answer `404 room_not_found` ("Room not found."). That covers details, history, mutation and attachment metadata, member search, pings, sending, uploads, join, leave, presence, management, broadcasts and room mutes.
- **Routes addressed by a message ID** (react, unreact, edit, delete, delete own, report) answer `404 message_not_found` ("Message not found."), as for a missing message.
- **`attachments/download.php`** answers `404 attachment_not_found` ("Attachment not found."), as for a missing attachment. This includes a deleted attachment: the `410 attachment_deleted` is only given to callers who can see the room.
- **Visibility is checked before anything else that depends on state:** membership, authorship, rank, minimum age, `not_a_member`, `message_already_deleted`, `attachment_deleted`. Input validation, which doesn't depend on any state, still comes first.

The `403` answers remain for callers who **can** see the room but may not do something:
- an invited non-member reading history: `403 room_forbidden`;
- posting or uploading without membership: `403 membership_required`;
- a member managing without being the owner: `403 forbidden`;
- a visible room above the caller's minimum age: `403 minimum_age_not_met`;
- a muted member: `403 muted`.

Someone who was removed from a private room, or left it, is a non-member without an invitation, so they get the 404 too. Before #326, these routes answered such callers with `403 room_forbidden`, `membership_required`, `invitation_required`, `presence_membership_required` or `forbidden`, and `leave` with `409 not_a_member`.

## Room discovery

### `GET /api/v1/rooms/list.php`

Returns rooms visible in the user's room list. Global room moderators receive all active rooms.

For rooms the account has joined, each entry also carries `unread_count` (messages from others, not deleted, after the read position; counting stops at 100, shown as "99+") and `last_read_message_id`, which starts at the room's newest message when the account joins or creates the room, so only later messages count (#268). Clients should still accept `null` for a joined room. Other rooms report `0` and `null`.

Joined rooms also carry `unread_through_message_id`: the room's newest message when the counts were taken (`null` for other rooms). A client that adds live `room_message` events to `unread_count` must skip any message with an ID at or below it, because the snapshot already counted it (#279). Otherwise a list fetched while messages arrive counts them twice.

A message with a lower ID can still commit after the snapshot, though, and that rule then skips it (#286). Clients that see `capabilities.realtime_settled_resume` should use these two fields instead, which every joined room also carries (`null` and `[]` for other rooms):

- `unread_settled_message_id`: every message with an ID at or below it had committed when the counts were taken.
- `unread_counted_ids`: the IDs above it that `unread_count` includes, in ID order. There are at most 100 (the cap), and usually none.

**Count a live message unless its ID is at or below `last_read_message_id` or `unread_settled_message_id`, or is in `unread_counted_ids`.** That counts each message once, whatever order the messages commit in. Use it together with the stream's `resume=settled` mode and its de-duplication by event ID (see [`realtime.md`](realtime.md)).

`unread_settled_message_id` is `0` when any message insert was in progress as the counts were taken, which is common on a busy installation. The list then holds every counted ID, so at most `unread_count` IDs per room.

**At 100 the count is saturated.** `unread_count` is 100, the list holds 100 counted IDs, and which unread messages were counted isn't defined. Treat the count as "100 or more" (shown as "99+") and don't add live messages to it until the next list fetch or read.

`unread_through_message_id` is unchanged, for clients that rely on it. `unread_mention_count` has the same late-commit gap and isn't covered yet; a mention committing just after the snapshot is counted only on the next fetch.

Joined rooms also carry `unread_mention_count` (#266): room mentions of the account, explicit or through `@room`/`@here`, that are still unseen. A mention counts while all of these hold:
- its `mentioned` notification is unread;
- its message is after the read position;
- the message isn't deleted;
- the account doesn't ignore its sender.

Counting stops at 100. Either reading the room past the mention (`POST /api/v1/rooms/read.php`) or reading the notification clears it. Reading the room does **not** mark the notification read, so Activity and push are unaffected. Other rooms report `0`. Like `unread_count`, the value is computed when the list is fetched; no realtime event carries it.

Joined-room list entries additionally contain:

```json
{"active_count": 4, "last_activity_at": "2026-11-08T10:00:00+00:00"}
```

- `active_count` counts distinct accounts and guests with an unexpired room
  presence lease and an unexpired interaction window, exactly as the presence
  list. Multiple tabs/devices count once. The viewer is excluded; `0` means
  nobody else is active. A zero room inactivity timeout disables only the
  interaction-window check, never lease expiry.
- `last_activity_at` is the newest creation timestamp among current messages
  visible in room history, formatted as ISO-8601 UTC. Deleted-for-everyone and
  retention-removed messages do not count. Private pings never count. Messages
  from ignored accounts do count: ignore is a display filter. No message text
  or sender identity is disclosed. An empty visible history gives `null`.
- Both values are `null` outside joined rooms, including rooms a global
  moderator can inspect without joining. Joined accounts that can no longer
  read history (for example after a minimum-age change) also receive `null`;
  global moderators keep their existing age bypass. Missing fields on older
  servers, or `null`, mean unknown/unavailable, never zero.

These are snapshots, not a new live-presence feed. Clients can refresh the list
on entry, foreground, and existing invalidations. No new SSE event is added;
room ordering and unread/read-position behavior stay unchanged. All room activity
is fetched in one batch query, in addition to the existing list/read/mute queries.

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
  "invited": false,
  "guest_access": "none"
}
```

`inactivity_timeout_seconds` is 0 when disabled. Otherwise it is 120-86400 seconds and applies only to active presence, not persistent membership.

`guest_access` is `none`, `read` (guests may read) or `write` (guests may also post and react). Only public rooms without a minimum age can let guests in. A guest sees only rooms whose `guest_access` is not `none`; see [Guest access](guest-access.md).

### `GET /api/v1/rooms/mentionable-users.php?room_id=42&search=ann_&limit=8`

Returns `{"users":[{"id":7,"username":"ann_member"}]}` for current active member
accounts whose usernames start with the literal, case-insensitive prefix.
An underscore in `ann_` matches an underscore, so it does not match `annxmember`.
Only the trailing prefix wildcard is added by the server; callers cannot supply
SQL patterns. Search is optional (empty lists starting suggestions), at most 32
characters, and permits letters, digits, underscore, dot and hyphen. `limit`
defaults to 8 and must be 1–25. Results exclude the viewer and guests, are ordered
by lowercased username then ID, and require the existing room-history permission.
The server independently authorizes mentions when sending a message.

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
  "inactivity_timeout_seconds": 0,
  "guest_access": "read"
}
```

The inactivity field is optional and defaults to 0. `guest_access` is optional and defaults to `none`; a value other than `none` for a room that is not public or has a minimum age is refused with `guest_access_scope`. The creator becomes the immutable room owner. Room keys are lowercase, unique, and contain 3-48 letters, numbers, underscores, or hyphens.

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

The inactivity field is optional for backward-compatible API clients. When omitted, the room's existing inactivity policy is preserved. `guest_access` is optional too: when omitted it is kept, unless the room stops being public or gains a minimum age, which closes it to guests. Closing a room to guests removes the guests in it at once.

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

The response is `{"room": …}` with the room's details plus the read fields the room list carries: `unread_count`, `last_read_message_id` (the room's newest message at the join, #268), `unread_mention_count`, `unread_through_message_id`, `unread_settled_message_id` and `unread_counted_ids`. A client can replace its list entry with it without fetching the list again.

### `POST /api/v1/rooms/leave.php`

```json
{
  "room_id": 42
}
```

A room owner cannot leave (`409 owner_cannot_leave`); they [transfer ownership](#post-apiv1roomstransfer-ownershipphp) first.

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

The endpoint accepts `member` and `moderator`. It never changes the owner's role (`409 owner_role_immutable`); use the transfer below.

### `POST /api/v1/rooms/transfer-ownership.php`

Hands a room to another member (#273). It requires active privileged step-up (`403 step_up_required` otherwise) and is allowed for:
- Super-Administrators, Administrators and Chat Admins;
- the room's current owner.

Global Moderators and room moderators can't transfer.

```json
{
  "room_id": 42,
  "target_user_id": 17
}
```

**The new owner** must be a member of the room with an active, registered account that isn't banned. An administrator may also name **themselves** for a room they aren't in, such as an invite-only room whose owner is gone. Their membership is created as part of the transfer, any invitation they had is consumed, and the room's minimum age still applies to them (`403 birth_date_required` / `minimum_age_not_met`).

**The previous owner** becomes a moderator, or a member if their account is closing or closed. They can then leave like anyone. Replacing an owner whose account is active needs a global role at least as high as theirs (an Administrator's room can't be taken by a Chat Admin); an owner whose account is closing or closed can be replaced by any role that may transfer.

**What's recorded and who's told:**
- The transfer is audited as `room.ownership_transferred` with `from_user_id`, `to_user_id` and `joined`.
- The new owner gets a `room_ownership_received` notification (in-app only, never pushed).
- The previous owner gets `room_ownership_removed`, unless they made the change themselves or their account isn't active.
- Both get a room-list update.

Returns `{"room": {…}}` as the actor now sees it.

| Status | Code | When |
| --- | --- | --- |
| 403 | `forbidden` | Not allowed to manage this room, including an owner whose room was handed on meanwhile, an existing room the account can't see (as for `role.php` and `update.php`), or replacing an active owner whose global role is higher than the actor's |
| 403 | `step_up_required` | No active privileged step-up |
| 404 | `room_not_found` | Unknown or deleted room |
| 404 | `membership_not_found` | The target isn't a member, and this isn't an administrator's own takeover |
| 409 | `already_owner` | The target already owns the room |
| 409 | `transfer_target_not_eligible` | A guest, closing, closed or banned account |
| 409 | `ownership_changed` | The owner or the membership changed while the transfer was in progress; reload and try again |

A room has at most one owner. The database enforces this (migration 0058), and the owner row is never changed by `role.php`, `leave.php` or a member removal, even when they race a transfer.

**Offering the transfer (#302):** clients don't need to repeat these rules. The same rule, in the same order, gives viewer-specific flags:
- `moderation.room.can_transfer_ownership` on the profile card (`users/profile.php` with `room_id`; see [admin.md](admin.md#what-the-card-offers-get-apiv1usersprofilephpuser_id7room_id42));
- `can_transfer_ownership` on each member row of `admin/rooms/snapshot.php`, and `can_take_ownership` on its `room`, for an administrator making themselves the owner, also of a room they aren't in. The minimum age is included.

A flag means **allowed now, after step-up**. Step-up is still asked when the transfer is made, and a flag can go stale, so handle the errors above as usual.

**Bans:** flags take a ban into account only for viewers who can already see bans (Super-Administrators and Administrators, in the user directory). For everyone else, including the room's owner and Chat Admins, the flag ignores bans, so it reveals nothing the profile doesn't. A guest, or an account that is closing or closed, is already visible: the profile answers `404 user_not_found` for those. A banned target is then refused with `409 transfer_target_not_eligible` when the transfer is attempted.

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

Requires a room owner, room moderator, or global room moderation role, and a higher moderation rank than the message's author (member < room moderator < room owner < Chat Admin or Global Moderator < Administrator < Super-Administrator; Super-Administrators act on everyone but themselves). Otherwise `403 forbidden`.

```json
{
  "message_id": 1234,
  "reason": "Spam"
}
```

`reason` is optional (at most 500 characters) and is kept in the audit log only. Deletion is soft and is recorded in the audit log; the author gets a `moderator_message_deleted` notification. The `rooms/message-mutations.php` metadata reports `can_moderate` for each message the viewer may delete this way.


### Sentence punctuation after a mention

Mention resolution tries the complete username first, then removes one trailing
`.`, `-` or `_` at a time while a 3–32 character username remains. The longest
**mentionable name in the current context** wins. For example, `@ada.` mentions
`ada` when that is the eligible name, but mentions the eligible `ada.` account
when both exist. An unreadable account cannot shadow a readable recipient.
A DM considers only its other participant. Registration rules are unchanged.

`@room.` and `@here...` retain room broadcast behavior. Email-like text such as
`x@ada.de` is not a mention. Overlong names are not truncated into another
person's valid name; a 32-character name may still be followed by punctuation.

The server's existing `mentions[]` metadata remains authoritative for rendering.
The browser applies the same longest-match rule against those resolved names,
and keeps any discarded sentence punctuation outside the highlighted name.
This applies when a new message is sent; existing stored metadata is preserved.
There are no new request or response fields.
