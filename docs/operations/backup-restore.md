# Backup and restore

A complete ChitChat backup contains PostgreSQL **and** attachment storage. The supported commands create, verify, and restore both as one manifest-bound backup set.

Backups are highly sensitive. A database dump contains password hashes, room and direct-message history, audit metadata, IP addresses, and administrative settings. Store backup sets outside the web root with restrictive permissions and encryption at rest and in transit.

## Prerequisites

The commands are intended for the supported Linux/PostgreSQL deployment and require these client tools in `PATH`:

- `pg_dump`, `pg_restore`, `createdb`, and `dropdb` from a PostgreSQL client version compatible with the server;
- GNU `tar`.

They load the same `.env` file as ChitChat. The database password is passed to PostgreSQL child processes through `PGPASSWORD`, not command-line arguments. A protected `.pgpass` or service-specific environment remains preferable where local policy supports it.

## Create a backup

```sh
cd /srv/chitchat
php bin/chitchat-backup --destination /srv/backups/chitchat
```

The command creates a timestamped directory such as:

```text
/srv/backups/chitchat/20260717T184500Z-a1b2c3d4/
├── manifest.json
├── database.dump
└── attachments.tar
```

It:

1. records the application version, PostgreSQL server metadata, and ordered `schema_migrations` state;
2. inventories attachment files, directories, and bytes while rejecting symbolic links, special files, and control characters in names;
3. creates a custom-format PostgreSQL dump without owner or privilege restoration;
4. creates a PAX attachment archive rooted at the storage directory basename;
5. rejects an attachment tree that changes while the archive is being created;
6. records SHA-256 checksums and byte sizes in `manifest.json`;
7. verifies checksums, dump readability, archive paths, and archive entry types;
8. atomically publishes the completed directory only after verification succeeds.

Incomplete working directories are removed on failure. Backup directories and manifests are created with restrictive permissions under a `0077` umask.

### Offline and live consistency

PostgreSQL and the filesystem cannot be captured in one transaction. The strongest procedure is:

1. stop or drain all application writes;
2. ensure no deployment or migration is running;
3. run the backup with `--application-stopped`;
4. resume the application after the command succeeds or fails.

```sh
php bin/chitchat-backup \
  --destination /srv/backups/chitchat \
  --application-stopped
```

That flag records `offline` consistency in the manifest; it does not stop services itself. Without the flag, the manifest honestly records a `live` backup. The command detects a changing attachment inventory and concurrent migration state, but a live upload can still fall between the database and filesystem capture. A live set is therefore useful but weaker than a drained set.

For automation, `--json` emits the final backup path and summary:

```sh
php bin/chitchat-backup --destination /srv/backups/chitchat --json
```

## Manifest

`manifest.json` is versioned as `chitchat-backup` format `1`. It binds the two payloads through:

- backup ID, creation time, and completion time;
- ChitChat application name and version;
- live/offline consistency declaration;
- source database name, server version, encoding, approximate size, dump format, and ordered migration list;
- attachment archive root and inventory counts;
- SHA-256 checksum and exact byte size for each payload;
- `pg_dump`, `pg_restore`, and `tar` version strings;
- restore strategy and the application version recommended for initial validation.

The manifest contains no database password. Checksums detect accidental corruption; they are not a cryptographic signature against an attacker who can replace both the payload and manifest. Protect the entire set with filesystem controls and, where required, signed or authenticated external storage.

## Verify a backup

```sh
php bin/chitchat-verify-backup \
  --backup /srv/backups/chitchat/20260717T184500Z-a1b2c3d4
```

Verification checks:

- manifest JSON shape and supported format version;
- exact payload sizes and SHA-256 checksums;
- `pg_restore --list` readability;
- that every archive path stays below the declared attachment root;
- that the archive contains only regular files and directories, never links or special files.

The create command performs this verification automatically. Run the standalone command after copying, decrypting, retrieving, or aging a set. `--json` is available for monitoring and automation.

A set that has never completed an isolated restore drill is not fully proven. Checksum and archive validation cannot test credentials, database creation permissions, available capacity, service orchestration, or application behavior.

## Restore safely

The safe default is restoration into **new** database and storage names while production remains untouched:

```sh
php bin/chitchat-restore \
  --backup /srv/backups/chitchat/20260717T184500Z-a1b2c3d4 \
  --database chitchat_restore \
  --attachments /srv/chitchat-data/uploads.restore
```

The command always verifies the set first. It then:

1. extracts attachments into a private sibling staging directory;
2. verifies the restored attachment inventory against the manifest;
3. creates the target database and restores with `--exit-on-error`, no owners, and no privileges;
4. reconnects to the restored database and compares its ordered migration state with the manifest;
5. renames the verified attachment tree into place only after the database restore succeeds.

It does **not** run migrations, edit `.env`, start ChitChat, or remove an old installation.

Point an isolated ChitChat configuration at the restored names and initially use the application version recorded in the manifest whenever possible. Verify `/ready.php`, sign in, inspect room and direct-message history, download representative attachments, and run:

```sh
composer maintenance:dry-run
```

When upgrading after the restore, deploy newer code and run `composer migrate` exactly once. Migrations are forward-only; do not point older code at a newer restored schema.

### Existing targets

Existing targets require explicit destructive flags:

```sh
php bin/chitchat-restore \
  --backup /srv/backups/chitchat/20260717T184500Z-a1b2c3d4 \
  --database chitchat_restore \
  --attachments /srv/chitchat-data/uploads.restore \
  --drop-existing-database \
  --replace-attachments
```

`--replace-attachments` renames the old directory to a unique `.pre-restore-*` path instead of deleting it. A partially created target database is removed if restoration fails.

Restoring to the database or attachment path currently configured in `.env` is refused unless `--allow-current-target` is also supplied. In-place database replacement is destructive and has no automatic old-database preservation. Prefer new names and a controlled configuration switch.

## Scheduled backups

Ready-to-adapt units are provided at:

```text
deploy/systemd/chitchat-backup.service
deploy/systemd/chitchat-backup.timer
```

Create the destination first, adapt the unit paths and operating-system account, then install and enable it:

```sh
sudo install -d -m 0700 -o www-data -g www-data /srv/backups/chitchat
sudo cp deploy/systemd/chitchat-backup.service /etc/systemd/system/
sudo cp deploy/systemd/chitchat-backup.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now chitchat-backup.timer
systemctl list-timers chitchat-backup.timer
```

The example performs a live daily backup. For offline consistency, add site-specific stop/drain and guaranteed resume hooks, and add `--application-stopped` to `ExecStart`. Test failure handling before relying on the unit.

The tool deliberately does not delete old sets. Apply a retention policy only after newer sets have been verified and copied to the required failure domain. Alert on timer or service failures and on the age of the newest verified off-host copy.

## Recovery drill

A useful periodic drill records:

- time to retrieve and verify the set;
- time to create and restore the database;
- time to stage and validate attachments;
- application readiness and representative data checks;
- migration and deployment steps required to reach the desired version;
- actual recovery time and any manual decisions.

The dedicated backup-rehearsal CI invokes these same backup, verification, and restore commands, compares restored database migration state and exact attachment bytes, runs maintenance against the restored targets, and proves that corruption is rejected. Production drills remain necessary because CI cannot reproduce site-specific storage, encryption, credentials, capacity, networking, or recovery objectives.

## Security

- encrypt backup sets at rest and in transit;
- restrict access to the application service account and designated operators;
- never place dumps, archives, manifests, credentials, or restore staging below `public/`;
- preserve a previous database and attachment directory until the restored installation is accepted;
- test restoration with isolated credentials and network exposure;
- securely destroy expired backup media according to local policy.
