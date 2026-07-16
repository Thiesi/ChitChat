# Presence and inactivity API

Presence is an expiring, tab-scoped lease. It is separate from persistent room membership: inactivity or an unclean browser disconnect removes a user from the active-room list but does not remove their membership or room role.

All endpoints require an authenticated session. The heartbeat endpoint also requires the current `X-CSRF-Token` header.

## Connection identifiers

Each browser tab generates one UUID and reuses it for the lifetime of that page. A user may therefore have several active connections in one room. Presence lists aggregate those connections into one user entry and report the connection count.

## `POST /api/v1/presence/heartbeat.php`

```json
{
  "connection_id": "11111111-1111-4111-8111-111111111111",
  "room_id": 42,
  "interacted": false
}
```

`room_id` may be `null` to leave active room presence. A non-null room requires persistent membership or a global room-moderation role. Minimum-age rules still apply.

`interacted` should be true for deliberate activity such as selecting the room or sending a message. Routine lease renewals use false.

Example response:

```json
{
  "presence": {
    "room_id": 42,
    "idle_seconds": 75,
    "warning_seconds": 45,
    "expired": false,
    "lease_seconds": 45
  }
}
```

When a room has an inactivity timeout, `warning_seconds` becomes non-null near expiry. Once the timeout has elapsed, a heartbeat with `interacted: false` returns `expired: true` and `room_id: null`. A later heartbeat with `interacted: true` may enter the room again.

## `GET /api/v1/rooms/presence.php?room_id=42`

Returns active users for a room. The caller must be a room member or hold a global room-moderation role.

```json
{
  "users": [
    {
      "id": 17,
      "username": "Example",
      "idle_seconds": 12,
      "connections": 2
    }
  ]
}
```

Expired leases and users beyond the room inactivity timeout are omitted.

## Realtime invalidation

Room members receive a `presence_changed` SSE event whenever a connection enters, leaves, expires, or changes rooms. The event payload contains `room_id` and `user_id`. Clients should reload the presence list rather than infer final online state from the event, because one user may have multiple connections.

## Configuration

- `PRESENCE_LEASE_SECONDS` defaults to 45 and may be 15-300.
- `INACTIVITY_WARNING_SECONDS` defaults to 60 and may be 10-3600.

The browser renews its lease every 20 seconds. Stale leases are removed opportunistically by the SSE polling loop and by presence requests, so no cron job is required for the initial single-server deployment.
