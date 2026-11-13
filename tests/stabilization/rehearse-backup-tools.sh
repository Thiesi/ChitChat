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

php tests/stabilization/installation-icon-backup.php seed "$work_root/icon-expected.json"
php tests/stabilization/username-change-backup.php seed "$work_root/username-expected.json"

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

DB_NAME="$restore_db" php tests/stabilization/installation-icon-backup.php verify "$work_root/icon-expected.json"
DB_NAME="$restore_db" php tests/stabilization/username-change-backup.php verify "$work_root/username-expected.json"

cmp "$fixture" "$restore_storage/nested directory/preserved attachment.txt"
printf 'second payload\n' | cmp - "$restore_storage/second.bin"

psql --set ON_ERROR_STOP=1 --dbname "$DB_NAME" --tuples-only --no-align \
  -c 'SELECT version FROM schema_migrations ORDER BY version' > "$work_root/source-migrations.txt"
psql --set ON_ERROR_STOP=1 --dbname "$restore_db" --tuples-only --no-align \
  -c 'SELECT version FROM schema_migrations ORDER BY version' > "$work_root/restored-migrations.txt"
cmp "$work_root/source-migrations.txt" "$work_root/restored-migrations.txt"

DB_NAME="$restore_db" ATTACHMENT_STORAGE_PATH="$restore_storage" \
  php bin/maintenance-cleanup --dry-run > "$work_root/maintenance.json"

# Backup runs are recorded for System status after the dump, best-effort (#310).
runs() {
  psql --set ON_ERROR_STOP=1 --dbname "$1" --tuples-only --no-align -c "$2"
}
[[ "$(runs "$DB_NAME" "SELECT outcome || '|' || consistency || '|' || set_name || '|' || (size_bytes > 0)::text || '|' || COALESCE(error_kind, '-') FROM backup_runs ORDER BY id")" \
  == "success|offline|$(basename "$backup_path")|true|-" ]]
# The run is recorded after its own dump, so the restored copy doesn't contain it.
[[ "$(runs "$restore_db" 'SELECT COUNT(*) FROM backup_runs')" == "0" ]]

# A database that can't be written still gets a backup, with the same exit code and JSON
# keys; only the run goes unrecorded, with a warning on stderr.
readonly_json="$(PGOPTIONS='-c default_transaction_read_only=on' php bin/chitchat-backup \
  --destination "$backup_parent" \
  --json 2> "$work_root/readonly-stderr.txt")"
printf '%s\n' "$readonly_json" > "$work_root/readonly-backup.json"
[[ -d "$(jq -er '.backup_path' <<< "$readonly_json")" ]]
[[ "$(jq -c 'keys' <<< "$readonly_json")" == "$(jq -c 'keys' <<< "$backup_json")" ]]
jq -e '.status == "ok" and .verified == true and .consistency == "live"' <<< "$readonly_json" >/dev/null
grep -q 'Warning: backup run not recorded' "$work_root/readonly-stderr.txt"
[[ "$(runs "$DB_NAME" 'SELECT COUNT(*) FROM backup_runs')" == "1" ]]

# A failed backup is recorded with a short category only, never its message or a path.
if php bin/chitchat-backup --destination "$PWD/public/backup-refused" >/dev/null 2> "$work_root/refused-stderr.txt"; then
  echo 'A backup into the public web root was unexpectedly accepted.' >&2
  exit 1
fi
grep -q 'outside the public web root' "$work_root/refused-stderr.txt"
[[ "$(runs "$DB_NAME" "SELECT outcome || '|' || error_kind || '|' || COALESCE(set_name, '-') FROM backup_runs ORDER BY id DESC LIMIT 1")" \
  == "failure|backup_failed|-" ]]
rm -rf "$PWD/public/backup-refused"

cp -a "$backup_path" "$corrupt_backup"
printf 'corruption' >> "$corrupt_backup/database.dump"
if php bin/chitchat-verify-backup --backup "$corrupt_backup" >/dev/null 2> "$work_root/corruption-error.txt"; then
  echo 'Corrupted backup unexpectedly passed verification.' >&2
  exit 1
fi
grep -q 'size differs from manifest\|checksum differs from manifest' "$work_root/corruption-error.txt"

printf 'First-class backup, verification and restore rehearsal passed.\n'
