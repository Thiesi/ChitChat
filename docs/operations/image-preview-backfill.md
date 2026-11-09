# Optional image-preview backfill

Migration 0044 leaves legacy attachment dimensions/renditions null. This is
compatible with existing clients and downloads. Backfill is optional and never
runs from a migration, a GET/download, or scheduled automatic maintenance.

The maintainer chooses when to run this host change. It reads original images,
uses bounded image-decoding CPU/memory when GD is available, and writes rendition
files and metadata. Use an appropriate maintenance slot and the normal backup
procedure. The same database and attachment-storage configuration as the
installation must be available to the CLI. GD with WebP and the source codecs
is required to generate renditions; trusted header dimensions can still be
filled without GD. Operators wanting previews for large photos should set
PHP-FPM's `memory_limit` to at least `256M` and size the CLI limit accordingly.
The usual `128M` limit may skip a preview while still updating dimensions; see
the [rendering budget](../api/image-previews.md#rendering-and-compatibility).

Start with a dry-run, then apply the same range:

```sh
php bin/backfill-attachment-images --kind=room --limit=25 --after-id=0 --dry-run
php bin/backfill-attachment-images --kind=room --limit=25 --after-id=0
```

Use the **applied run's** returned `next_id` as `--after-id` for the next batch.
Do not advance past a range merely because its dry-run inspected it. Repeat
explicit batches until `candidates` is zero. Run the independent direct-message
range with `--kind=direct --after-id=0`.

The default kind is `room` and default limit is 25. Limits are 1-100; after-id is
a non-negative attachment ID within that table. There is no unbounded/all-files
mode. Every invocation finishes after its bounded selected range.

The JSON result includes `kind`, `dry_run`, `candidates`, `updated`,
`previews_generated`, `unavailable` and `next_id`. Dry-run makes no image, file,
metadata or audit changes. A live batch audits aggregate results without names,
file bytes or private storage keys. Each attachment is committed separately.
On failure, already committed earlier attachments remain valid and the failed
item's newly generated rendition is removed.

Only retained raster-image attachments are eligible; deleted messages/files
and deleted rooms are skipped. Ready renditions are not overwritten.
Unavailable previews (including unsupported/corrupt/over-budget sources or
missing originals) keep their original data and advance the reported cursor.
After installing codecs or restoring missing storage, an operator can deliberately
repeat a bounded range to retry unavailable items.

Backfill uses the existing maintenance-cleanup advisory lock. It refuses to run
while cleanup/backfill owns that lock; cleanup also refuses during a backfill.
This prevents adding a rendition between retention key collection and physical
file removal. Downloads continue to use existing authorization and never wait
for image generation.

Both files remain tracked by cleanup and are included in ordinary attachment
backups. `deleted_attachments` still counts attachment rows; `tracked_files`
and physical file-removal counts include both originals and renditions.

See [image preview API](../api/image-previews.md) for display dimensions, limits,
nullable fallback and private ETag revalidation.
