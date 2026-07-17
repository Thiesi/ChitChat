# Direct-message attachments

Direct-message attachments use the same opaque storage root, MIME allowlist, maximum size, SHA-256 metadata, and safe inline-image policy as room attachments. Their authorization is separate from rooms: only the message sender and recipient may request metadata or file bytes.

Attachments are not end-to-end encrypted. The file and its message caption follow the configured direct-message retention policy.

## Upload

`POST /api/v1/direct-messages/attachments/upload.php` requires authentication, the current `X-CSRF-Token`, and `multipart/form-data` fields:

- `recipient_user_id`: positive integer;
- `caption`: optional UTF-8 text, at most 4,000 characters after trimming;
- `file`: one uploaded file.

If the caption is empty, the normalized original filename becomes the direct-message body. The upload is limited to 10 successful attempts per authenticated user per hour, in addition to the web server and PHP request-size limits.

The service writes the file under a random 64-character storage key outside `public/`, verifies its actual size and SHA-256 digest, and then serializes on the same unordered user-pair advisory lock used by text sends and block operations. If either participant has blocked the other, the upload returns HTTP 403 with `direct_message_unavailable` and the stored file is removed.

The direct-message row, attachment metadata, upload audit entry, and perspective-correct sender and recipient realtime events commit in one database transaction. Any failure removes the file again.

## Visible-message metadata

`GET /api/v1/direct-messages/attachments/metadata.php?message_ids=1,2,3`

- accepts 1-100 positive direct-message IDs;
- returns metadata only for messages where the authenticated account is the sender or recipient;
- silently omits inaccessible and non-attachment messages.

```json
{
  "attachments": [
    {
      "id": 14,
      "message_id": 83,
      "name": "report.pdf",
      "mime_type": "application/pdf",
      "size_bytes": 48231,
      "sha256": "…",
      "previewable": false
    }
  ]
}
```

The normal direct-message history and SSE payload remain backward-compatible. Browsers enrich currently visible message IDs through this bounded endpoint.

## Download

`GET /api/v1/direct-messages/attachments/download.php?id=14`

The server rechecks that the authenticated account is the direct-message sender or recipient on every request. Unauthorized callers receive the same HTTP 404 response as an unknown attachment.

`inline=1` is honored only for the existing raster-image preview allowlist. Other MIME types always use attachment disposition. Responses use `no-store`, `nosniff`, same-origin resource policy, and a sandboxed attachment CSP.

Blocking affects only future sends. It does not revoke either participant's access to attachments already retained in their shared history.

## Storage and retention

Metadata is stored in `direct_message_attachments`, one file per direct message. Storage keys share the same physical attachment namespace as room files.

Maintenance treats keys from both tables as tracked files. When direct-message retention deletes a message, its metadata cascades and the corresponding file is removed in the same maintenance run. Orphan detection excludes live room and direct-message attachment keys.

Back up PostgreSQL and the attachment storage directory together.

## Administrative inspection

The audited administrative inspection endpoint continues to return retained message rows and captions. It does not grant participant download authorization for attachment binaries. This avoids turning general inspection access into an unaudited file-download bypass. A future administrative binary-inspection workflow must require its own explicit reason and audit event.
