# Account and personal-data API

Authenticated account endpoints require a PHP session. State-changing requests require the current `X-CSRF-Token` header from `GET /api/v1/session.php`. The restoration endpoint is intentionally available without authentication but still requires the anonymous session's CSRF token, valid account credentials, a pending unexpired closure, and its own database-backed throttle.

## Profile picture and profile card

A profile picture replaces an account's initials next to its messages, in member and conversation lists, in the account menu, and on its profile card. Without one, initials in a stable colour tone are shown.

Uploads are never stored as sent. The server checks the type (JPEG, PNG, or WebP only; SVG and everything else is refused) and the declared dimensions (at most 6000 × 6000 pixels, checked before decoding), then decodes the image with GD and stores a newly encoded, centre-cropped 256 × 256 WebP. That drops all metadata, such as camera details or GPS positions. Pictures live in attachment storage under a random key, so backups include them and the maintenance orphan sweep leaves them alone. Profile pictures need the PHP GD extension with WebP support; without it uploads return `503 avatars_unavailable` and initials are shown.

### `POST /api/v1/account/avatar/upload.php`

Multipart form with the image in the `avatar` field, at most 5 MB; authentication and the CSRF token are required. The browser lets the user crop first, but the server crops again regardless. Returns `{"avatar": {"has_avatar": true, "avatar_version": …}}`; audited as `account.avatar_updated`. The previous picture's file is deleted.

### `POST /api/v1/account/avatar/remove.php`

`{}` removes your own picture (audited as `account.avatar_removed`). `{"user_id": 7}` removes someone else's, which only Super-Administrators, Administrators, Chat Admins, and Global Moderators may do: it is audited as `moderation.avatar_removed`, and the account gets an `avatar_removed` notification. `404 avatar_not_found` if there is no picture.

### `GET /api/v1/avatars/show.php?user_id=7`

The picture as `image/webp`, for signed-in users only, or `404` while the account shows initials (or is not active). Responses revalidate on every use via an `ETag` (the storage key, new with each upload), so a changed picture appears at once.

### `GET /api/v1/users/profile.php?user_id=7`

The small profile card behind every name: `id`, `username`, `member_since`, `badge` (the most senior staff role, such as `Administrator`, or `null`), `has_avatar`, `avatar_version`, and `can_remove_avatar` for the viewer, plus `avatars_available` for the installation. Closed and closing accounts return `404`.

When an account is permanently closed, its picture is deleted along with the tombstone. The personal data export contains the stored picture itself (`account.avatar`, base64-encoded WebP).

## Date and time display

Each account can choose how dates and times are shown, independently of the browser's language: a **format region** (`date_locale`, one of `en-US`, `en-GB`, `en-AU`, `de-DE`, `de-AT`, `de-CH`, `fr-FR`, `es-ES`, `it-IT`, `nl-NL`, `pl-PL`, `pt-BR`, `sv-SE`, `ja-JP`) and a **clock** (`hour_cycle`: `h23` for 24-hour, `h12` for 12-hour). `null` means Automatic, which follows the browser. The time zone always follows the device. The session (`GET /api/v1/session.php`) returns the choice as `preferences: {"date_locale": …, "hour_cycle": …}` (`null` while signed out), and the client caches it per device so pages format correctly before the session loads.

### `POST /api/v1/account/display-preferences.php`

Authentication and the current CSRF token are required.

```json
{"date_locale": "de-DE", "hour_cycle": "h23"}
```

Both fields are optional and default to `null` (Automatic). Any other value returns `400 validation_error`. The response is `{"preferences": {…}}` with the stored values. The choice is part of the personal data export (`account.display_preferences`).

Colour scheme and light/dark mode are per-device choices kept in the browser, not account data.

## Sign-in methods

### `GET /api/v1/account/identities/list.php`

Requires authentication.

```json
{
  "providers": [{ "id": "google", "label": "Google" }],
  "identities": [{ "provider": "google", "label": "Google", "linked_at": "2026-10-04T12:00:00+00:00", "last_used_at": null }],
  "has_password": true
}
```

`providers` lists the providers this installation offers; `has_password` is `false` for an account created through Google or Twitch that has not set a password.

### `POST /api/v1/account/identities/unlink.php`

Requires authentication, CSRF and active privileged step-up. Body `{"provider":"google"}`. Refused with `409 last_sign_in_method` when it would leave an account without a password and without any connected provider. Audited as `account.identity_unlinked`.

## Personal data export

### `POST /api/v1/account/export.php`

Creates a complete JSON response for the retained personal data currently available to the signed-in account. The endpoint requires active privileged step-up authentication and is limited to five requests per account per hour through the shared database-backed request limiter.

The response has this envelope:

```json
{
  "filename": "chitchat-personal-data-Example-20260717-123456.json",
  "export": {
    "format": {
      "name": "chitchat-personal-data-export",
      "version": 1
    },
    "generated_at": "2026-07-17T12:34:56+00:00",
    "application": {},
    "scope": {},
    "account": {},
    "rooms": {},
    "direct_messages": {},
    "moderation": {
      "reports_submitted": []
    },
    "security_history": {},
    "activity": []
  }
}
```

The bundled account page serializes `export` as formatted UTF-8 JSON and downloads it using `filename`.

### Included data

The export includes:

- account profile timestamps, optional birth date, date and time display preferences, role grants, and ban history;
- rooms created by the account, current room memberships (with how far the account has read in each), and pending invitations;
- retained room messages authored by the account, including their retained revision history;
- retained direct messages the account can already read, including attachment metadata;
- retained revision history only for direct messages authored by the exporting account;
- retained pings the account sent or received, including their text, room, and both participants;
- direct-message blocks created by the account;
- moderation reports submitted by the account, including its category, optional participant-authored details, retained exact-message snapshot, structural evidence metadata, current case status, and public outcome code;
- login-attempt history associated with the account's canonical username;
- audit entries where the account is the actor, including the source IP already recorded for that activity.

A report snapshot remains in the export while the corresponding report is retained, even when ordinary message retention has removed the canonical message. This reflects the data ChitChat still stores on behalf of the exporting reporter.

### Deliberate exclusions

The export does not include password hashes, session state, CSRF tokens, privileged-step-up state, attachment file bytes, opaque attachment storage keys, hidden revision history for messages authored by somebody else, the identities of users who blocked the account, moderation reports submitted by somebody else, moderation queue assignments, private moderator resolution notes, or audit entries and source IPs belonging only to another actor.

The direct-message block export therefore preserves the existing public relationship contract: users can retrieve blocks they created, but the export does not add a `blocked_by_other` disclosure. The moderation export likewise does not turn personal-data export into access to the wider queue or to another reporter's complaint.

### Consistency and audit behavior

The service reads the complete export, including submitted moderation reports, in one repeatable-read PostgreSQL transaction. A successful generation creates `account.personal_data_exported` after the exported activity snapshot has been assembled, so the export audit is not recursively included in the same file. Audit metadata records only the export format and aggregate item counts, including the number of submitted reports; it does not copy report details, evidence bodies, message bodies, filenames, IP addresses, or other exported content.

The synchronous JSON response is intended for the supported single-server baseline. Large retained histories can require substantial PHP memory; operators should test representative accounts before exposing the feature on installations with very large permanent histories.

## Account closure

### `POST /api/v1/account/close.php`

Requires authentication, CSRF protection, and recent password step-up. The final active Super-Administrator cannot request closure.

A successful request atomically:

- changes the account from `active` to `closure_pending`;
- records a 14-day cooling-off deadline;
- increments the session version and removes all global roles;
- removes current presence and SSE leases;
- emits a forced-logout event for other tabs and sessions;
- records `account.closure_requested` without duplicating the username or content;
- destroys the requesting PHP session.

Normal login is denied while closure is pending. The original username and password are retained only so explicit restoration remains possible during cooling-off.

## Account restoration

### `POST /api/v1/account/restore.php`

Accepts:

```json
{
  "username": "Example",
  "password": "current password"
}
```

The endpoint requires the anonymous session's CSRF token and is limited by the `account_restore` policy, which defaults to five attempts per username/IP combination per hour. It succeeds only while the matching closure remains pending and the 14-day deadline has not passed.

Restoration increments the session version again, restores the saved global-role snapshot, records `account.closure_restored`, creates a fresh authenticated session, and returns the ordinary session user envelope. It does not silently occur during normal login.

## Finalization and retained shared data

Ordinary maintenance finalizes pending closures whose deadline has passed. Finalization:

- replaces the username with `Closed account #<id>` and assigns a non-user-controlled unique canonical name;
- replaces the password hash with an unusable random credential;
- clears birth date and last-login metadata;
- removes global roles, pending room invitations, live presence/SSE leases, direct-message block preferences, and login attempts tied to the old canonical username;
- records `account.closure_finalized` using IDs only;
- releases the original username for reuse.

Shared room and direct-message history, message revisions, attachment evidence, open moderation evidence, room membership and ownership attribution, bans, and audit records remain subject to their existing retention policies. Reporter and subject references resolve to the tombstoned `Closed account #<id>` label after finalization rather than preserving the original username in moderation-specific storage. This preserves conversation integrity and pending security evidence rather than rewriting other participants' retained history. Once the deadline has passed, restoration is refused even if maintenance has not yet run.
