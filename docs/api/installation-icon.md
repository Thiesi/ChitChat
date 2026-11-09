# Installation icon

Super-Administrators manage this beside the installation name. Reads require the same role; mutations and previews also require CSRF and current privileged step-up.

- GET /api/v1/admin/settings/application-icon/get.php
- POST /api/v1/admin/settings/application-icon/update.php (multipart)
- POST /api/v1/admin/settings/application-icon/reset.php (JSON {})

GET, Save and Reset return `{application_icon: description}`. Description:

```json
{
  "state": "custom",
  "revision": "64 lowercase hexadecimal characters",
  "background": "#14111C",
  "source_width": 1024,
  "source_height": 1024,
  "maskable_scale": 0.560546875,
  "updated_at": "2026-11-09T12:00:00+00:00",
  "updated_by": {"id": 1, "username": "Root"},
  "renditions": {
    "favicon": {"url": "/installation-icon.php?revision=HASH&variant=favicon", "width": 32, "height": 32, "mime_type": "image/png", "size_bytes": 1234, "sha256": "HASH"}
  },
  "upload_policy": {
    "enabled": true,
    "max_bytes": 2097152,
    "minimum_side": 512,
    "maximum_side": 4000,
    "allowed_mime_types": ["image/png", "image/webp"]
  }
}
```

Rendition keys are `favicon` (32 PNG), `apple_touch` (180 PNG), `icon_192`, `icon_512`, `maskable` (512 PNG) and `ico` (32 ICO). Every entry has the same fields. Default state has revision, original source dimensions, maskable_scale and update metadata null, background #14111C, and URLs for the packaged Lantern renditions. Clients use the returned URLs even for the default preview.

Update accepts an `icon` file and optional `background` (#RGB or #RRGGBB, normalized uppercase; default #14111C). Without a file it changes the existing custom icon's background, preserving original dimensions and transparent rendition bytes. A concurrent replacement/reset returns 409 application_icon_changed; refetch before retrying. Invalid files, dimensions and colours return 400 validation_error, preserving the current icon.

PNG/WebP must be square, 512–4000 pixels, at most 4 MiB and within PHP's effective limits. max_bytes is min(4 MiB, positive upload_max_filesize, positive post_max_size minus 64 KiB), floored at zero; nonpositive PHP limits mean unlimited. Zero means uploads unavailable. Oversize files or Content-Length above post_max_size return 413 attachment_too_large with error.max_bytes. An empty file set alone is not proof of a discarded body. Rendering unavailable returns 503 application_icon_unavailable; an image beyond available rendering memory is a validation error.

Save/Reset count attempts with application_icon_update (default 20/hour/actor). Standard 429 Retry-After and error.retry_after_seconds apply. Identical saves/resets do not create another icon audit event.

## Preview

Set multipart `dry_run=1` on update.php. Same file, background, access, CSRF, step-up and validation rules as Save, with a separate application_icon_preview budget (default 60/hour/actor). Response:

```json
{
  "preview": {
    "favicon": "data:image/png;base64,...",
    "apple_touch": "data:image/png;base64,...",
    "maskable": "data:image/png;base64,..."
  },
  "maskable_scale": 0.560546875,
  "background": "#14111C"
}
```

Preview is no-store, returns at most 2 MiB of data URLs, and writes no icon, generation, pointer or audit. Only rate-limit accounting changes. No staged source is kept: Save uploads and validates again. The background is flattened into both Apple and maskable images. Maskable art fits inside the central 40%-radius safe circle; pixels below approximately 16/255 opacity are ignored for fitting and art is never enlarged.

## Public discovery and caching

session.php application.icon is null for Lantern, otherwise `{url,width:512,height:512,mime_type:"image/png",sha256}`, visible anonymously, to members and guests. A missing key means older/unknown support. No capability/schema bump.

Versioned same-origin rendition URLs support GET/HEAD, exact-byte strong ETag, nosniff and one-year immutable public caching. Lookup/integrity checks precede 304. Unknown/pruned revisions return 404; an old URL never serves new bytes. Only current and at most one previous generation are retained (at most 8 MiB of live encoded payload). Already cached older bytes may remain in browsers until expiry.

GET/HEAD /favicon.ico dispatches before any session or cookie, serving the current ICO or packaged Lantern ICO with no-cache and ETag. It works through the committed Nginx try_files and Apache FallbackResource examples without writable docroot, an Alias or an additional host rule. Pages and the manifest preserve the existing Lantern icon entries when no custom override exists. Custom metadata is injected into the pure native confirmation renderer as installation_icon {favicon_url,mark_url}|null.

## Operations

GD with PNG/WebP support is required to upload/preview; memory_limit >= 256M is recommended for a 4000px source. Stored bytes are re-encoded, metadata stripped, and originals/filenames discarded. Published generations are immutable; publication and audit commit atomically. Run migrations before using this feature. Ordinary database backup/restore includes all rendition bytes and the current reference. No additional filesystem backup, writable path or web-server configuration is needed.
