# Release installation and recovery rehearsal

ChitChat's stabilization CI exercises the published release artifact rather than assuming that a green repository checkout guarantees a usable release archive.

## Published-archive rehearsal

The `release-rehearsal` CI job:

1. downloads the private GitHub source archive for stable `v1.0.0` through the GitHub API;
2. extracts it into an empty directory;
3. runs `composer install --no-dev --classmap-authoritative`;
4. applies every migration from that release to an empty PostgreSQL 16 database;
5. starts the archive using multiple real PHP workers;
6. verifies `/health.php`, `/ready.php`, and the expected release version;
7. creates two accounts, a room, ordinary messages, an attachment, and a direct message through the public HTTP API.

This catches missing release files, production-only Composer dependency errors, broken version metadata, migration omissions, and installation instructions that work only from a developer checkout.

## Backup and restore rehearsal

After seeding the published release, the job stops its application server and follows the documented backup model:

- `pg_dump --format=custom --no-owner --no-privileges`;
- a tar archive of the entire attachment-storage directory;
- SHA-256 checksums for both artifacts;
- `pg_restore --list` and `tar -tf` structural verification.

It then restores into a new database and a new storage path. The original database and files remain untouched during restore, matching the recommended operator procedure.

## Upgrade rehearsal

The current branch is pointed at the restored `v1.0.0` database and attachment directory. It runs `composer migrate` unconditionally, so it always applies every migration newer than the archived tag — currently `0010` through `0022` — rather than a number pinned in the rehearsal script itself; that range grows automatically as new migrations are added. It then starts the application and verifies through the public API that:

- both users remain usable;
- the room and all three room-message records remain present;
- the attachment still downloads with byte-for-byte identical content;
- the direct-message history remains readable;
- maintenance dry-run succeeds against the restored installation;
- the running application reports the current branch's release version.

This is an upgrade rehearsal from the previous supported stable release, not a claim that arbitrary development snapshots are supported predecessors. Because the migrations are forward-only, rollback requires restoring the matching pre-upgrade PostgreSQL and attachment backup.

## Local execution

The script is designed for CI but can run locally with a disposable PostgreSQL server and a GitHub token that can read the private repository:

```sh
export GH_TOKEN=...
export GITHUB_REPOSITORY=Thiesi/ChitChat
export CHITCHAT_RELEASE_TAG=v1.0.0
export DB_HOST=127.0.0.1
export DB_PORT=5432
export DB_NAME=chitchat_rehearsal
export DB_USER=chitchat
export DB_PASSWORD=...
export DB_SSLMODE=disable

composer install
bash tests/stabilization/rehearse-release.sh
```

The database user must be permitted to create and drop the separate restore database. Never point this script at a database or attachment directory containing valuable data.

## Diagnostics

On failure, CI retains the temporary release tree, server logs, backup files, restored-count report, downloaded attachment copies, and other rehearsal state for seven days. Treat those artifacts as sensitive because they contain test password hashes, messages, direct messages, and session material.
