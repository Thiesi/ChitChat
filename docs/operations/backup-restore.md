# Backup and restore

A complete ChitChat backup contains both PostgreSQL and the attachment storage directory. Neither part alone is sufficient when room attachments are enabled.

## Backup

Choose a directory that is not below the web root and protect it as sensitive data: the database dump contains password hashes, room history, direct messages, audit metadata, IP addresses, and administrative settings.

```sh
set -eu
stamp=$(date -u +%Y%m%dT%H%M%SZ)
backup=/srv/backups/chitchat/$stamp
mkdir -p "$backup"
chmod 700 "$backup"

pg_dump \
  --format=custom \
  --no-owner \
  --no-privileges \
  --file="$backup/database.dump" \
  "$DB_NAME"

tar -C "$(dirname "$ATTACHMENT_STORAGE_PATH")" \
  -cpf "$backup/attachments.tar" \
  "$(basename "$ATTACHMENT_STORAGE_PATH")"

sha256sum "$backup/database.dump" "$backup/attachments.tar" > "$backup/SHA256SUMS"
```

Use the same connection options or environment that the application uses. Supplying database passwords through a protected `.pgpass` file is preferable to placing them in shell history.

### Consistency window

The database and filesystem cannot be captured in one PostgreSQL transaction. For the strongest single-server backup:

1. stop or drain the PHP application so no uploads or message writes occur;
2. run `composer maintenance:dry-run` to confirm no other cleanup is active;
3. dump PostgreSQL;
4. archive attachment storage;
5. start the application again.

A live backup is still recoverable, but an upload occurring between the two captures may appear as a missing file or an orphan after restoration. The maintenance command can remove restored orphans after its grace period, but it cannot reconstruct a missing attachment.

## Verification

At minimum:

```sh
cd "$backup"
sha256sum -c SHA256SUMS
pg_restore --list database.dump >/dev/null
tar -tf attachments.tar >/dev/null
```

Periodically restore into an isolated test database and storage directory. A backup that has never been restored is not considered verified.

## Restore

Stop the application before restoring.

```sh
set -eu
backup=/srv/backups/chitchat/20260717T000000Z

sha256sum -c "$backup/SHA256SUMS"

dropdb --if-exists chitchat_restore
createdb chitchat_restore
pg_restore \
  --clean \
  --if-exists \
  --no-owner \
  --no-privileges \
  --dbname=chitchat_restore \
  "$backup/database.dump"

rm -rf /srv/chitchat-data/uploads.restore
mkdir -p /srv/chitchat-data/uploads.restore
tar -C /srv/chitchat-data/uploads.restore --strip-components=1 -xpf "$backup/attachments.tar"
```

The example restores to new database and storage names deliberately. Point a test `.env` at those locations, run `/ready.php`, sign in, inspect room attachments and direct-message history, and run:

```sh
composer maintenance:dry-run
```

Only after validation should production connection and storage paths be switched. Preserve the previous database and attachment directory until the restored installation has been accepted.

## Version compatibility

Restore with the same ChitChat commit or release that created the backup whenever possible. After the old version starts successfully, deploy the newer code and run `composer migrate` exactly once. Migrations are forward-only; do not restore a newer schema into older application code.

## Security

- encrypt backups at rest and in transit;
- restrict access to the application service account and designated operators;
- apply a retention policy appropriate for direct-message and audit sensitivity;
- do not place dumps, archives, checksums, or `.pgpass` below `public/`;
- securely destroy expired backup media according to local policy.
