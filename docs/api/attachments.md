# Attachment API

Attachments are room messages backed by opaque files outside the public web root. Every endpoint requires an authenticated session. Uploads also require the current CSRF token.

## Message representation

Room history and `room_message` realtime events include an `attachment` field on every message. It is `null` for ordinary or deleted messages.

```json
{
  "id": 91,
  "room_id": 4,
  "sender_id": 8,
  "username": "Example",
  "type": "attachment",
  "body": "Optional caption",
  "attachment": {
    "id": 12,
    "name": "diagram.png",
    "mime_type": "image/png",
    "size_bytes": 45123,
    "sha256": "64 lowercase hexadecimal characters",
    "previewable": true
  },
  "deleted": false,
  "created_at": "2026-07-17T00:00:00+00:00",
  "reply_to": null,
  "mentions": [],
  "reactions": []
}
```

The filename is display metadata only and never determines the storage path. Clients must use the attachment ID with the download endpoint. `reply_to`, `mentions`, and `reactions` follow the same shape as ordinary text messages; see [`rooms.md`](rooms.md#message-representation).

## `POST /api/v1/attachments/upload.php`

Send `multipart/form-data` with:

- `room_id`: positive integer form field;
- `caption`: optional UTF-8 string of at most 4,000 characters; parsed for `@username`/`@room`/`@here` mentions the same way an ordinary message body is;
- `reply_to_message_id`: optional positive integer form field identifying another message in the same room;
- `file`: one uploaded file.

The request must include `X-CSRF-Token`. The caller must be a persistent member of the room and satisfy its minimum-age policy.

Example response, HTTP 201:

```json
{
  "message": {
    "id": 91,
    "room_id": 4,
    "type": "attachment",
    "body": "Optional caption",
    "attachment": {
      "id": 12,
      "name": "diagram.png",
      "mime_type": "image/png",
      "size_bytes": 45123,
      "sha256": "...",
      "previewable": true
    },
    "deleted": false,
    "reply_to": null,
    "mentions": [],
    "reactions": []
  }
}
```

The message, attachment metadata, audit record and `room_message` event are committed together. If database work fails after the file is moved, ChitChat removes that file before returning the error.

Important errors include:

- `attachment_missing`;
- `attachment_too_large`;
- `attachment_empty`;
- `attachment_type_unknown`;
- `attachment_type_not_allowed`;
- `membership_required`;
- room age-policy errors.

## `GET /api/v1/attachments/download.php?id=12`

The server reloads the linked room and applies current history and minimum-age authorization. Private-room invitations alone are not sufficient; the caller must have joined, unless a global moderation role grants access.

The normal response uses `Content-Disposition: attachment`. Add `inline=1` to request an inline response. Inline disposition is honored only for JPEG, PNG, GIF and WebP; all other MIME types remain forced downloads.

Responses include:

- the stored MIME type;
- exact `Content-Length`;
- `X-Content-Type-Options: nosniff`;
- a sandboxed content-security policy;
- same-origin resource isolation;
- private, no-store caching.

Deleted messages and attachments return HTTP 410. Missing or inaccessible records do not expose a filesystem path.

## `GET /api/v1/attachments/metadata.php`

Query parameters:

- `room_id`: positive integer;
- `message_ids`: comma-separated list of 1-100 positive message IDs.

The endpoint applies room-history and age authorization, excludes deleted messages, and returns only attachments linked to the requested room and message IDs.

```json
{
  "attachments": [
    {
      "message_id": 91,
      "id": 12,
      "name": "diagram.png",
      "mime_type": "image/png",
      "size_bytes": 45123,
      "sha256": "...",
      "previewable": true
    }
  ]
}
```

This bounded endpoint lets the browser enhance visible message nodes without exposing unrestricted attachment searches.

## Deletion and retention

Authorized message deletion marks the linked attachment deleted in the same transaction and immediately blocks metadata and downloads. The physical opaque file is retained until the configured `deleted_attachment_retention_days` policy elapses, at which point `composer maintenance` removes the metadata and binary together; a failed upload's orphaned file is removed after the separate orphan grace period. See [`docs/operations/maintenance.md`](../operations/maintenance.md).
