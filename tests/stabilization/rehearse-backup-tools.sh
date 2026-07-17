#!/usr/bin/env bash
set -euo pipefail

: "${DB_HOST:?DB_HOST is required}"
: "${DB_PORT:?DB_PORT is required}"
: "${DB_NAME:?DB_NAME is required}"
: "${DB_USER:?DB_USER is required}"
: "${DB_PASSWORD:?DB_PASSWORD is required}"
: "${ATTACHMENT_STORAGE_PATH:?ATTACHMENT_STORAGE_PATH is required}"

work_root="${RUNNER_TEMP:-/tmp}/chitchat-backup-rehearsal"
backup_parent="$work_root/backups"
restore_db="${DB_NAME}_restore"
restore_storage="$work_root/restored/uploads"
corrupt_backup="$work_root/corrupt"
fixture="$work_root/fixture.txt"

export PGPASSWORD="$DB_PASSWORD"
export PGHOST="$DB_HOST"
export PGPORT="$DB_PORT"
export PGUSER="$DB_USER"

cleanup() {
  dropdb --if-exists --maintenance-db=postgres "$restore_db" >/dev/null 2>&1 || true
}
trap cleanup EXIT
cleanup

rm -rf "$work_root" "$ATTACHMENT_STORAGE_PATH"
mkdir -p "$backup_parent" "$ATTACHMENT_STORAGE_PATH/nested directory"
chmod 700 "$backup_parent" "$ATTACHMENT_STORAGE_PATH"
printf 'first-class ChitChat backup fixture\n' > "$fixture"
cp "$fixture" "$ATTACHMENT_STORAGE_PATH/nested directory/preserved attachment.txt"
printf 'second payload\n' > "$ATTACHMENT_STORAGE_PATH/second.bin"

backup_json="$(php bin/chitchat-backup \
  --destination "$backup_parent" \
  --application-stopped \
  --json)"
printf '%s\n' "$backup_json" > "$work_root/backup.json"
backup_path="$(jq -er '.backup_path' <<< "$backup_json")"
[[ -d "$backup_path" ]]
[[ -z "$(find "$backup_parent" -maxdepth 1 -type d -name '.*.partial' -print -quit)" ]]

verify_json="$(php bin/chitchat-verify-backup --backup "$backup_path" --json)"
printf '%s\n' "$verify_json" > "$work_root/verify.json"
jq -e '.status == "ok" and .verified == true and .migration_count > 0' <<< "$verify_json" >/dev/null

manifest="$backup_path/manifest.json"
jq -e '
  .format == "chitchat-backup"
  and .format_version == 1
  and .consistency.mode == "offline"
  and .consistency.application_writes_stopped == true
  and .attachments.file_count == 2
  and .attachments.directory_count == 1
  and (.files["database.dump"].sha256 | length == 64)
' "$manifest" >/dev/null

if php bin/chitchat-restore \
  --backup "$backup_path" \
  --database "$DB_NAME" \
  --attachments "$restore_storage" >/dev/null 2> "$work_root/current-target-error.txt"; then
  echo 'Restore unexpectedly accepted the configured database target.' >&2
  exit 1
fi
grep -q -- '--allow-current-target' "$work_root/current-target-error.txt"

restore_json="$(php bin/chitchat-restore \
  --backup "$backup_path" \
  --database "$restore_db" \
  --attachments "$restore_storage" \
  --json)"
printf '%s\n' "$restore_json" > "$work_root/restore.json"
jq -e --arg database "$restore_db" --arg storage "$restore_storage" '
  .status == "ok"
  and .database == $database
  and .attachments == $storage
  and .previous_attachments == null
  and .migration_count > 0
' <<< "$restore_json" >/dev/null

cmp "$fixture" "$restore_storage/nested directory/preserved attachment.txt"
printf 'second payload\n' | cmp - "$restore_storage/second.bin"

psql --set ON_ERROR_STOP=1 --dbname "$DB_NAME" --tuples-only --no-align \
  -c 'SELECT version FROM schema_migrations ORDER BY version' > "$work_root/source-migrations.txt"
psql --set ON_ERROR_STOP=1 --dbname "$restore_db" --tuples-only --no-align \
  -c 'SELECT version FROM schema_migrations ORDER BY version' > "$work_root/restored-migrations.txt"
cmp "$work_root/source-migrations.txt" "$work_root/restored-migrations.txt"

DB_NAME="$restore_db" ATTACHMENT_STORAGE_PATH="$restore_storage" \
  php bin/maintenance-cleanup --dry-run > "$work_root/maintenance.json"

cp -a "$backup_path" "$corrupt_backup"
printf 'corruption' >> "$corrupt_backup/database.dump"
if php bin/chitchat-verify-backup --backup "$corrupt_backup" >/dev/null 2> "$work_root/corruption-error.txt"; then
  echo 'Corrupted backup unexpectedly passed verification.' >&2
  exit 1
fi
grep -q 'size differs from manifest\|checksum differs from manifest' "$work_root/corruption-error.txt"

printf 'First-class backup, verification and restore rehearsal passed.\n'
