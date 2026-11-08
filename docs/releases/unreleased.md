# Unreleased

Image attachment metadata now includes nullable oriented display dimensions and
an optional bounded WebP rendition for room/direct-message attachments. Clients
detect the new keys and use the existing download route's `variant=preview`;
original downloads remain compatible.

Operator note: migration 0044 adds nullable columns without processing existing
files. The optional [image-preview backfill](../operations/image-preview-backfill.md)
is an explicit bounded host operation with dry-run support. The maintainer
decides whether and when to run it; release/deployment does not run it
automatically. Backups continue to include PostgreSQL and the full attachment
storage directory, now including private renditions.
