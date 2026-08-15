# Administrative message revision review

Message revision review is a separate, opt-in administrative capability for examining retained bodies from message edits and deletions. It does not inherit authorization from direct-message inspection, room moderation, Chat Admin, Global Moderator, or room-owner permissions.

Every successful review also requires active privileged step-up authentication. Step-up does not replace the independently configured review role, CSRF protection, exact-message restriction, required reason, or successful-access audit.

## Policy

```text
MESSAGE_REVISION_REVIEW_ENABLED=0
MESSAGE_REVISION_REVIEW_ROLE=super_admin
```

`MESSAGE_REVISION_REVIEW_ENABLED` defaults to disabled. `MESSAGE_REVISION_REVIEW_ROLE` accepts:

- `super_admin`: only Super-Administrators;
- `admin`: Administrators and Super-Administrators.

The policy is exposed by `GET /api/v1/session.php` under `privacy.message_revisions` so the browser can hide unavailable controls. The API remains authoritative.

This policy is independent of `DM_ADMIN_INSPECTION_ENABLED` and `DM_ADMIN_INSPECTION_ROLE`. Enabling one capability does not enable the other.

## Review endpoint

```text
POST /api/v1/admin/message-revisions/review.php
X-CSRF-Token: <session token>
Content-Type: application/json
```

Requires active privileged step-up. Missing or expired elevation returns HTTP 403 with `step_up_required` before revision content is read or a successful-review audit is written. The bundled browser prompts for the current password through `POST /api/v1/step-up.php` and retries the review once.

Request:

```json
{
  "kind": "room",
  "message_id": 123,
  "reason": "Investigating a reported moderation incident"
}
```

`kind` must be `room` or `direct`. `message_id` must be a positive integer. `reason` is required on every request and must contain 10-500 Unicode characters after trimming.

The endpoint accepts only an exact message kind and ID. It does not provide user, room, conversation, date, or body search. A retained canonical message with no revision rows returns `revision_history_not_found`; the endpoint cannot be used as a second general-purpose message-history inspector.

Successful response:

```json
{
  "kind": "direct",
  "message": {
    "id": 456,
    "sender": {"id": 8, "username": "Alice"},
    "recipient": {"id": 9, "username": "Bob"},
    "created_at": "2026-07-17T10:00:00+00:00",
    "edited_at": "2026-07-17T10:02:00+00:00",
    "last_editor": {"id": 8, "username": "Alice"},
    "deleted_at": "2026-07-17T10:04:00+00:00",
    "deleted_by": {"id": 8, "username": "Alice"}
  },
  "revisions": [
    {
      "id": 31,
      "action": "edit",
      "actor": {"id": 8, "username": "Alice"},
      "body_before": "Original body",
      "body_after": "Edited body",
      "created_at": "2026-07-17T10:02:00+00:00"
    },
    {
      "id": 32,
      "action": "delete",
      "actor": {"id": 8, "username": "Alice"},
      "body_before": "Edited body",
      "body_after": null,
      "created_at": "2026-07-17T10:04:00+00:00"
    }
  ]
}
```

Room-message responses also include room metadata and the message type. Canonical message bodies are deliberately absent from the `message` object; historical content is returned only from actual revision rows.

## Audit contract

Successful password reauthentication writes `auth.privileged_step_up_succeeded`. Every successful review then writes its separate `admin.message_revisions_reviewed` record before content is returned. The authentication audit does not replace the per-review audit.

The review audit subject is `message_revision_history` with an ID such as `room:123` or `direct:456`.

Audit metadata includes:

- message kind and ID;
- reviewer-supplied reason;
- revision count, IDs, and actions;
- room or DM participant identifiers and usernames;
- canonical creation, edit, and deletion timestamps;
- the normal audit actor and request IP fields.

Historical message bodies and passwords are never copied into audit JSON. Validation failures, disabled-policy denials, role denials, missing step-up, unknown messages, and messages without revisions do not return content or write a successful-review record.

## Retention and disclosure

Revision rows use `ON DELETE CASCADE` to their canonical message. Soft deletion keeps revision history. Configured room-message or direct-message retention eventually hard-deletes the canonical message and its revisions together.

A successful review does notify the affected participant: the same `admin.message_revisions_reviewed` audit row that satisfies the audit contract above also drives a durable `revision_review` privacy notification (and, if the installation has Web Push configured, a best-effort push) to the retained room-message author, or to both retained direct-message participants — see [`privacy-notifications.md`](privacy-notifications.md). The notification discloses that review occurred; it never includes the reviewer's reason, the historical bodies, or any other reviewer-supplied detail. Independently of that per-review notice, the direct-message privacy notice discloses up front that edits and deletions preserve historical bodies and that separately configured audited review may exist, and the administrative page states the review policy to the reviewer. Operators remain responsible for reflecting the capability in their privacy, moderation, employment, and legal policies.
