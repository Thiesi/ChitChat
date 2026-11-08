# Image dimensions and preview renditions

Both room and direct-message attachment metadata add `width`, `height` and
`preview`. The additions appear wherever that attachment metadata already ships:
room uploads/history/realtime, the bounded room/direct metadata endpoints, and
personal-data exports. Direct-message history and realtime envelopes are
unchanged; clients enrich their visible message IDs through the existing
metadata endpoint.

```json
{
  "id": 12,
  "name": "photo.jpg",
  "mime_type": "image/jpeg",
  "size_bytes": 1250000,
  "sha256": "original SHA-256",
  "previewable": true,
  "width": 2048,
  "height": 1024,
  "preview": {
    "url": "/api/v1/attachments/download.php?id=12&variant=preview",
    "width": 1024,
    "height": 512,
    "mime_type": "image/webp",
    "size_bytes": 42000,
    "sha256": "rendition SHA-256"
  }
}
```

The dimensions describe the original's oriented display geometry, including
JPEG EXIF orientation swaps. They come from the image header without decoding.
They remain available when GD is unavailable or a source exceeds the rendering
budget. Both are `null` for non-images, legacy rows not yet backfilled, or headers
whose dimensions/orientation cannot be read reliably. Clients should reserve
space using the ratio and their own display bounds, not allocate an image buffer
of that size.

`preview` is either the complete descriptor above or `null`. The old
`previewable` flag still describes the MIME allowlist; it does not promise a
rendition exists. Missing new fields on older servers mean unknown.

Client check: presence of the `width` and `height` keys advertises dimensions
(values may be null); non-null `preview` advertises the rendition. No new native
authentication capability flag is involved.

## Rendering and compatibility

New uploads keep their original bytes, MIME, name, digest, size and upload
allowlist. Before the database transaction, optional GD rendering creates one
WebP at quality 80, with a maximum 1024-pixel long edge, preserved aspect ratio
and no upscaling. JPEG orientation is applied to the preview; GIF uses a static
first frame. Re-encoding strips source metadata.

The decoder preflight is at most 6000 pixels per axis and 16 million pixels
total. It also checks a conservative available-memory budget before decoding.
Resizing precedes rotation so no second full-size source is allocated. Encoded
preview bytes are capped at 4 MiB.

Over-budget, corrupt, unsupported-codec or unavailable-GD inputs remain accepted
when the original upload policy accepts them. They return `preview:null`; trusted
header dimensions remain populated. Rendering/storage failure of the optional
preview does not reject a valid original. Downloads never trigger generation.

Original and preview use separate private opaque storage keys. Neither key is
part of public metadata or exports. Upload metadata/message/event/audit writes
remain one transaction; failed database work removes both new files. Renditions
follow the original's deletion, room/DM retention and deleted-room purge. Orphan
cleanup protects both referenced keys. Backups must continue to include the
database and complete attachment storage directory together.

## Download variants

Use the returned `preview.url` directly. It is a same-origin relative path
computed for that attachment; clients do not need to construct routes. Examples:

- `GET /api/v1/attachments/download.php?id=12&variant=preview`
- `GET /api/v1/direct-messages/attachments/download.php?id=14&variant=preview`

Omitting `variant` or passing `original` preserves the original download,
including `inline=1` and `Cache-Control: private, no-store`. Unknown/non-string
variants return `400 validation_error`.

The preview returns `image/webp` with inline disposition, `nosniff`, the existing
sandbox CSP/same-origin resource policy, `Cache-Control: private, no-cache` and a
quoted SHA-256 `ETag` for its bytes. Send `If-None-Match` to revalidate: a matching
validator returns 304 with no body. Weak validators, lists and `*` follow GET
revalidation semantics. Original downloads do not use preview validators.

Authorization, room history/minimum-age rules, DM participant scope, deletion,
and original/rendition storage integrity checks happen before any 304. An old
validator never makes a forbidden, deleted or unavailable file readable.
Clients must honor `no-cache` and revalidate before reuse, scoped to the correct
installation/account.

A missing rendition returns `404 attachment_preview_unavailable`. It never
silently downloads the larger original. Deleted attachments return the existing
410 errors. Missing/integrity-invalid original or rendition storage also returns
410. Error bodies remain JSON with `no-store`.

## Existing installations

Migration 0044 adds nullable metadata only. It does not decode legacy images or
automatically generate files. Existing downloads keep working. An optional,
operator-run [bounded backfill](../operations/image-preview-backfill.md) adds
header dimensions and available renditions to retained images. Running it on a
deployed installation is a host change: the maintainer decides whether and when
to run it.
