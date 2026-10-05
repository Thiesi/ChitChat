# Guest access

A Super-Administrator can let visitors look around without an account (`guest_access_enabled` in [System settings](system-settings.md)). A guest is a real account of its own kind, named `Guest NNNN`. The number comes from a sequence and is never reused. The name contains a space, which no username can, so a guest's name never collides with a member's, and it can't be addressed with `@` or `/ping`. Clients may rely on this: a sender name matching `Guest <digits>` is a guest.

A guest session lasts one browser session. It ends:

- after two hours without a request;
- after 24 hours at most;
- when the guest leaves (`POST /api/v1/logout.php`);
- when a moderator ends it;
- when guest access is switched off.

An ended guest becomes a closed account that keeps its name, so its messages stay readable. During a maintenance lockdown no new guest session starts.

## What guests may do

Guests see only rooms whose `guest_access` is `read` or `write` (see [Rooms](rooms.md)). They may join such rooms, read history, and see presence. Where a room allows `write`, they may also post, edit and delete their own messages, react, and show that they are typing. They may also:

- open profile cards;
- ignore people;
- report room messages;
- read their notifications.

Everything else answers HTTP 403 with `guest_not_allowed`. That includes direct messages, pings, attachment uploads, search, room management, and every account and administration endpoint. Endpoints opt in to guests explicitly, so a new endpoint is closed to them by default.

Guests are never mentioned: `@room` skips them, and their own `@names` notify nobody. They cannot receive direct messages, be invited, or hold a role. On top of `room_send`, a guest's messages count against `guest_room_send` (6 per minute).

`GET /api/v1/session.php` reports `guest_access: true` while the sign-in page may offer a guest session. For a guest, `user.guest` is `true` and `user.guest_expires_at` gives the latest end.

## Start a guest session

### `GET /api/v1/guest/challenge.php`

Issues the proof-of-work puzzle used by registration protection, with the same difficulty but no minimum fill time:

```json
{ "challenge": { "nonce": "4f1c…", "bits": 16 } }
```

`challenge` is `null` when proof of work is off. Returns 403 `guest_access_disabled` while guest access is off or paused.

### `POST /api/v1/guest/start.php`

```json
{ "challenge_nonce": "4f1c…", "challenge_solution": "48213" }
```

Starts the session and answers 201 with a new `csrf_token` and the guest `user`. The guest gets a welcome notification (`guest_welcome`) that links to registration. Rate-limited by `guest_start` per IP address. Errors:

| Code | Status | Meaning |
|---|---|---|
| `guest_access_disabled` | 403 | Guest access is off. |
| `guest_access_paused` | 403 | A maintenance lockdown is on. |
| `guest_access_blocked` | 403 | A moderator blocked guests from this connection for a while. |
| `guest_limit_reached` | 429 | Three guests from this connection are already active. |
| `already_signed_in` | 409 | The browser is already signed in. |

## Moderation

Profile cards (`GET /api/v1/users/profile.php`) carry `profile.guest`. For a guest, `moderation.guest` is `{ "can_end": true, "can_block_connection": true }` when the viewer is a global moderator or above. Kick and ban are not offered for guests; muting still is.

### `POST /api/v1/moderation/guest-end.php`

```json
{ "user_id": 123 }
```

Ends the guest's session at once. The guest is signed out (a `forced_logout` event) and leaves every room. Requires a global moderator, Chat Administrator, Administrator or Super-Administrator. Audited as `guest.session_ended`.

### `POST /api/v1/moderation/guest-block.php`

```json
{ "user_id": 123, "duration_seconds": 3600, "reason": "Spam" }
```

Blocks new guest sessions from the guest's connection (IP address) for one hour, one day or one week (`3600`, `86400` or `604800`). Every guest from that connection ends now. The moderator never sees the address. Members using the same connection are not affected and can still sign in or register. Audited as `guest.connection_blocked`.
