# Required username changes

A Global Moderator or above can **require a new username** when a name breaks the community rules (#164). The offensive name disappears at once: the server replaces it with a neutral placeholder such as `member-k7q2xm` and flags the account. The account keeps reading and signing in, but it must choose a new name before its next write.

All of this is additive to API v1. `session.php` reports `capabilities.moderated_username_change: true` on servers that support it; missing means unsupported.

## Discovery: `session.php`

While a change is required, `user.username` already holds the placeholder and `user.username_change` is:

```json
{
  "required": true,
  "required_at": "2026-11-12T10:00:00+00:00",
  "reason": "Your username was reported and found to break the community rules.",
  "sign_in_with_previous_username": true
}
```

Otherwise `user.username_change` is `null`. `reason` is the moderator's text, shown to the person in the same way a mute reason is. The same object appears in every response that returns the session user (sign-in, registration, `account/username.php`).

## Gated writes: `403 username_change_required`

While a change is required, these return `403 username_change_required` ("Choose a new username to write. You can still read."):

- room and direct-message sends and edits;
- attachment uploads, in rooms and direct messages;
- adding reactions;
- private pings;
- uploading a profile picture (including a cropped provider picture).

This is exactly the set gated by `rules_acceptance_required` ([policies](policies.md)). Everything that gate leaves open stays open: reading, discovery and search; sign-in and sign-out; reporting, block and ignore; removing one's own messages, reactions or avatar; security, device and notification settings; export, closure and restoration; and choosing the new name.

If both apply, `rules_acceptance_required` is returned first; after accepting the rules, the next write returns `username_change_required`. Clients that receive it refresh `session.php` and offer the chooser.

## Choosing the new name: `POST /api/v1/account/username.php`

Requires a signed-in member and the current `X-CSRF-Token`.

```json
{ "username": "NewName" }
```

`200 {"user": {...}}` returns the session user: `user.username` is the new name and `user.username_change` is `null`. The session is not rotated and `session_version` does not change; other signed-in devices see the new name on their next `session.php` or `user_renamed` event.

| Status | `error.code` | When |
| --- | --- | --- |
| 400 | `validation_error` | `username` missing or not a string |
| 400 | `invalid_username` | The existing username rules: 3–32 ASCII letters, digits, `.`, `_` or `-`, starting with a letter or digit |
| 409 | `username_taken` | Taken by another account, **or reserved** (see below), or in the placeholder pattern |
| 409 | `username_change_not_required` | No change is required for this account. This is not a general self-service rename |
| 403 | `guest_not_allowed` | A guest |
| 401 | `authentication_required` | Signed out, or the session became stale |
| 429 | `rate_limited` | The `username_change` budget (default 10 per hour per account), with the usual retry metadata |

Reserved and placeholder-pattern names are deliberately reported exactly like taken ones, so moderation history is not disclosed.

## Signing in while a change is required

Password sign-in, account restoration and password recovery identify the account by its username. The person does not know the placeholder unless they were signed in when it happened, so the **previous username keeps working as a sign-in identifier** until a new name is chosen. The placeholder works too. Nobody else can claim the previous name meanwhile, because it is reserved. Once a new name is chosen, only the new name signs in. Passkeys, Google/Twitch and native hand-off do not use the username and are unaffected. Login throttling keys on the name as typed, so while a change is pending the account has two per-username budgets, one for the previous name and one for the placeholder. This is accepted: the per-IP `login_ip` limit still bounds attempts, and the pending state is temporary.

## Reservation of the removed name

The removed name (case-insensitive) is reserved **from the moderator's action until the new name is chosen**, whatever the setting. From that moment it stays reserved for `username_reservation_days` ([system settings](system-settings.md), default 365, range 0–3650; `0` releases it as soon as the new name is chosen). If the account is closed and finalized before choosing, the period starts at finalization.

During a reservation, registration, Google/Twitch sign-up completion, native sign-up and the chooser all return `409 username_taken` for that name, including for the person it was taken from. Maintenance removes expired reservations.

Placeholders are `member-` followed by six random lowercase base32 characters (`a`–`z`, `2`–`7`). They are valid usernames, unique, and unrelated to the account ID. **New** names matching `member-` plus exactly six such characters (case-insensitive) are refused with `409 username_taken`, so nobody can register, imitate or keep a placeholder. Existing accounts with such a name are not affected.

## Moderator action: `POST /api/v1/moderation/require-username-change.php`

Requires a member whose global rank is Global Moderator or Chat Admin or above **and** who outranks the target installation-wide: a strictly higher rank, except that Super-Administrators may act on their equals, and nobody acts on themselves. This is the same rule as muting everywhere ([administration](admin.md)). Also requires the current `X-CSRF-Token` and the `moderation_action` budget. No privileged step-up, as for other moderation actions.

```json
{ "user_id": 123, "reason": "Your username was reported and found to break the community rules.", "case_id": 45 }
```

`reason` is 1–500 characters after trimming and is shown to the person. `case_id` is optional (`null` or absent): an open **person** case whose subject is `user_id`. It is resolved in the same transaction with `status: "resolved"` and `resolution_code: "username_change_required"`.

`200`:

```json
{ "user": { "id": 123, "username": "member-k7q2xm" }, "case": { "...": "case detail, or null" } }
```

| Status | `error.code` | When |
| --- | --- | --- |
| 400 | `validation_error` | Bad field types; empty or too long `reason`; `case_id` is not a person case about this account; the target is a guest (guest names are generated) |
| 403 | `forbidden` | The rank rule fails |
| 404 | `user_not_found` | No such account, or it is closed |
| 404 | `moderation_case_not_found` | `case_id` does not exist |
| 409 | `username_change_already_required` | The account already has to choose a new name |
| 409 | `moderation_case_closed` | `case_id` is already resolved or dismissed |
| 429 | `rate_limited` | The `moderation_action` budget |

A refused case rolls the whole action back. The action is allowed on active accounts, on accounts pending closure, and on banned accounts, whose names may still show in older content.

The profile card reports what the viewer may do: `profile.moderation.everywhere.can_require_username_change` and `profile.moderation.everywhere.username_change_required` ([administration](admin.md)). The ordinary `moderation/resolve.php` also accepts `resolution_code: "username_change_required"` for person cases ([moderation reports](moderation-reports.md)).

Audited as `moderation.username_change_required` with `old_username`, `placeholder`, `reason` and `case_id`; the chosen name as `account.username_changed` with `from`, `to` and `required: true`. The case's first-report snapshot keeps the reported name as moderator evidence.

## Realtime and notifications

- **`user_renamed`** `{"user": {"id": 123, "username": "member-k7q2xm"}}` goes to every connection, on the moderator's action and again when the new name is chosen. Clients update every rendered occurrence of that user ID (author names, mention and reply labels, presence and typing, the direct-message inbox). If it is the viewer's own ID, they refresh `session.php`. See [realtime](realtime.md).
- The person gets a **`username_change_required`** notification: "Choose a new username", with the reason, linking to `/choose-username.php`. Push delivery follows the same rules as the moderator's `avatar_removed` notice ([privacy notifications](privacy-notifications.md)).
- Stored mention and ping notifications from this person are rewritten to the placeholder in the same transaction, and to the new name when it is chosen. So the notification list **and any push not yet delivered** never show the removed name.
- Messages, replies, mentions, pings, search results and the direct-message inbox always show the account's current name, so existing content shows the placeholder at once.

### Limitations

- Push alerts already shown on a device cannot be recalled.
- The literal text "@OldName" typed inside existing message bodies is people's content and is not rewritten. Moderators remove such messages with the existing tools. Mentions and `/ping` with the old name no longer resolve to anyone.
- A typing indicator sent just before a rename can show the old name for its few remaining seconds after `user_renamed`. Typing signals are ephemeral (8 seconds) and are never stored.
- Stored realtime events are not rewritten. A client that reconnects within `realtime_event_retention_hours` may replay older events carrying the old name; the later `user_renamed` event follows them in order and corrects it.
- The personal data export of an account with a pending change includes `account.username_change` (`required_at`, `reason`, `previous_username_canonical`).
