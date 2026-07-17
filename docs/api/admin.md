# Administration API and browser console

The browser administration console is served at `/admin.php`. It is an ordinary client of the versioned JSON API and does not bypass server-side authorization.

## Privilege boundaries

- Super-Administrators and Administrators may list users, inspect the audit log, kick, ban, unban, reset passwords, and manage global roles.
- Only a Super-Administrator may grant or revoke `super_admin` or manage another Super-Administrator.
- Administrators cannot change their own global roles. This prevents accidental self-lockout and avoids privilege changes from a session whose authority is being modified.
- Global-role changes increment the target account's session version and publish a targeted `forced_logout` event.
- Super-Administrators, Administrators, Chat Admins, and room owners may manage room settings, members, roles, and invitations.
- Global Moderators may moderate messages and broadcasts through the existing room APIs but do not receive room ownership controls.

All state-changing endpoints require the current `X-CSRF-Token` header.

Global-role replacement and administrator password reset additionally require active privileged step-up authentication. Current-password verification does not grant any role or bypass target-account restrictions; it only satisfies the recent-authentication requirement for a session already authorized to perform the action.

## Users

### `GET /api/v1/admin/users.php`

Requires Super-Administrator or Administrator.

Optional query parameters:

- `search`: case-insensitive username prefix, up to 32 supported username characters;
- `after_id`: ascending user-ID cursor;
- `limit`: 1-100, default 50.

The response includes roles, creation and last-login timestamps, and the current active ban when one exists.

### `POST /api/v1/admin/roles.php`

Requires active privileged step-up.

```json
{
  "target_user_id": 17,
  "roles": ["admin", "chat_admin"]
}
```

The supplied role array replaces the complete global-role set. Supported values are:

- `super_admin`
- `admin`
- `chat_admin`
- `global_moderator`

The operation is transactional, audited, and invalidates active sessions for the target account. Missing or expired step-up returns `step_up_required` before any role is changed or role-change audit is written.

Existing account-control endpoints are used by the console:

- `POST /api/v1/admin/kick.php`
- `POST /api/v1/admin/ban.php`
- `POST /api/v1/admin/unban.php`
- `POST /api/v1/admin/reset-password.php`

`reset-password.php` requires active privileged step-up; kick, ban, and unban do not in this milestone. A successful password verification and the later password reset create separate audit records.

## Audit log

### `GET /api/v1/admin/audit.php`

Requires Super-Administrator or Administrator.

Optional query parameters:

- `before_id`: descending audit-ID cursor;
- `limit`: 1-100, default 50.

The response includes actor identity when still available, action, subject, recorded metadata, source IP address, and timestamp. Newest entries are returned first. Successful and failed privileged password verification appear as `auth.privileged_step_up_succeeded` and `auth.privileged_step_up_failed`; password values are never included.

## Room administration

Room administration requires a global room-management role (`super_admin`, `admin`, or `chat_admin`) or ownership of the selected room.

### `GET /api/v1/admin/rooms/snapshot.php?room_id=42`

Returns:

- the editable room object;
- persistent members with room role, join timestamp, and active connection count;
- pending invitations and inviter identity.

### `GET /api/v1/admin/rooms/search-users.php`

Required parameters:

- `room_id`;
- `search`, containing 2-32 supported username characters.

The result excludes current members and users who already have a pending invitation. This endpoint is room-scoped to avoid providing an unrestricted account directory to room owners.

### `POST /api/v1/admin/rooms/remove-member.php`

```json
{
  "room_id": 42,
  "target_user_id": 17
}
```

The immutable room owner cannot be removed. Removal also clears active presence leases for that room, emits a `presence_changed` invalidation, and writes an audit entry. It does not delete or ban the account.

### `POST /api/v1/admin/rooms/revoke-invitation.php`

```json
{
  "room_id": 42,
  "target_user_id": 17
}
```

Revokes one pending invitation and records the action in the audit log.

The console uses the existing room endpoints for settings, invitations, and member-role changes:

- `POST /api/v1/rooms/update.php`
- `POST /api/v1/rooms/invite.php`
- `POST /api/v1/rooms/role.php`

Room administration does not require privileged step-up in this milestone.

## Browser safety

The administration console constructs all account, room, and audit views with DOM nodes and `textContent`. Usernames, reasons, room information, and audit metadata are never inserted as HTML.

When a protected JSON POST returns `step_up_required`, the shared browser API layer opens an accessible current-password dialog, verifies through `POST /api/v1/step-up.php`, and retries the original action exactly once. Cancellation or failed verification leaves the original action unapplied.
