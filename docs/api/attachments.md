# Attachment API

Attachments are room messages backed by opaque files outside the public web root. Every endpoint requires an authenticated session. Uploads also require the current CSRF token.

## Upload policy discovery

`GET /api/v1/session.php` includes a top-level `attachments` block for signed-in
members. It is `null` while signed out and for guests. A missing block on older
servers means unknown; clients must not assume a default size or MIME policy.

```json
{
  "attachments": {
    "max_bytes": 2097152,
    "allowed_mime_types": ["application/json", "application/pdf", "application/zip", "image/gif", "image/jpeg", "image/png", "image/webp", "text/csv", "text/plain"],
    "previewable_mime_types": ["image/gif", "image/jpeg", "image/png", "image/webp"],
    "rate_limits": {
      "room": {"maximum_attempts": 10, "window_seconds": 3600},
      "direct_message": {"maximum_attempts": 10, "window_seconds": 3600}
    }
  }
}
```

The values above are examples. `max_bytes` is the minimum of
`ATTACHMENT_MAX_BYTES` (10 MiB by default), positive PHP `upload_max_filesize`,
and positive `post_max_size` minus 64 KiB reserved for multipart framing, caption
and fields. Nonpositive PHP size limits mean unlimited. The result may be below
1 KiB; zero means the installation has no safe file allowance with this reserve.
Clients must treat uploads as unavailable when it is zero; operators should
correct their PHP limits. The same effective limit
is enforced by room and direct-message uploads and returned on every size 413. MIME lists come from the same policy
used to accept and preview files. Room and direct upload quotas are independently
configurable; each authenticated, CSRF-valid upload attempt consumes its quota
before form/file validation, including rejected uploads. The default is 10 attempts
per hour for each route, not 10 successful uploads.

Clients can reject known oversized or unsupported files before sending them, but
the server still verifies actual size, detected MIME and current authorization.
The advertised limit already accounts for the whole-body PHP bound using the
64 KiB reserve. Requests with unusually large extra fields or framing can still
exceed that reserve. Operators should configure `post_max_size` above the desired
file limit plus this reserve, and their proxy request limit at least as high as
PHP's whole-body limit.

Both upload routes check `CONTENT_LENGTH` against the parsed positive
`post_max_size` before looking for discarded form fields. An exceeded limit returns
HTTP 413 `attachment_too_large` with the effective file limit in `error.max_bytes`.
PHP per-file size failures and application size failures also include this field.
An empty file set alone is not evidence of an oversized request and retains the
existing missing-file/field errors. A reverse proxy may reject a body before PHP
with its own (possibly HTML) 413 response, outside this JSON contract.

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
    "previewable": true,
    "width": 1280,
    "height": 720,
    "preview": {"url":"/api/v1/attachments/download.php?id=12&variant=preview","width":1024,"height":576,"mime_type":"image/webp","size_bytes":24000,"sha256":"rendition SHA-256"}
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
      "previewable": true,
      "width": 1280,
      "height": 720,
      "preview": {"url":"/api/v1/attachments/download.php?id=12&variant=preview","width":1024,"height":576,"mime_type":"image/webp","size_bytes":24000,"sha256":"rendition SHA-256"}
    },
    "deleted": false,
    "reply_to": null,
    "mentions": [],
    "reactions": []
  }
}
```

The message, attachment metadata, audit record and `room_message` event are committed together. If database work fails after the file is moved, ChitChat removes the original and any generated rendition before returning the error.

Important errors include:

- `attachment_missing`;
- `attachment_too_large`;
- `attachment_empty`;
- `attachment_type_unknown`;
- `attachment_type_not_allowed`;
- `membership_required` (for a room the caller can see but hasn't joined);
- `room_not_found` (for a missing room, or a private room the caller can't see; see [Room visibility in errors](rooms.md#room-visibility-in-errors-326));
- room age-policy errors.

## `GET /api/v1/attachments/download.php?id=12`

**`HEAD`** is accepted too (#357), on the same URL with the same query parameters. It runs the same session, visibility, deletion and storage checks and answers with exactly the same status and headers as `GET` (including `Content-Length`, `Content-Disposition`, `ETag` and the preview `304`), but with no body. The file is never opened or read, so it is a cheap way to check whether a file is still available. Without a body the error code isn't readable; use `GET` when you need `attachment_deleted` versus `attachment_storage_missing`. If the file can't be opened (for example it vanished after the check), a `GET` answers `410 attachment_storage_missing` where a `HEAD` said `200`. HEAD has no rate limit and writes no audit rows, the same as `GET`. Other methods still answer `405`, with an `Allow: GET, HEAD` header. The method must be exactly `HEAD`.

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
      "previewable": true,
      "width": 1280,
      "height": 720,
      "preview": {"url":"/api/v1/attachments/download.php?id=12&variant=preview","width":1024,"height":576,"mime_type":"image/webp","size_bytes":24000,"sha256":"rendition SHA-256"}
    }
  ]
}
```

This bounded endpoint lets the browser enhance visible message nodes without exposing unrestricted attachment searches.

## Deletion and retention

Authorized message deletion marks the linked attachment deleted in the same transaction and immediately blocks metadata and downloads. The physical opaque file is retained until the configured `deleted_attachment_retention_days` policy elapses, at which point `composer maintenance` removes the metadata and binary together; a failed upload's orphaned file is removed after the separate orphan grace period. See [`docs/operations/maintenance.md`](../operations/maintenance.md).

## Image dimensions and preview renditions

Attachment metadata also carries nullable `width`, `height` and `preview`. See [image previews](image-previews.md) for the oriented geometry, generation bounds, optional legacy backfill, feature detection, and `variant=preview` download. Original downloads keep private/no-store; preview downloads use private/no-cache with an authenticated ETag revalidation before reuse. Missing renditions return an explicit error and never fall back to original bytes. Both stored files follow the original lifecycle and failed upload transactions remove both.
