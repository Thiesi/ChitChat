#!/usr/bin/env bash
set -euo pipefail

: "${GH_TOKEN:?GH_TOKEN is required to download the published release archive}"
: "${GITHUB_REPOSITORY:?GITHUB_REPOSITORY is required}"
: "${DB_HOST:?DB_HOST is required}"
: "${DB_PORT:?DB_PORT is required}"
: "${DB_NAME:?DB_NAME is required}"
: "${DB_USER:?DB_USER is required}"
: "${DB_PASSWORD:?DB_PASSWORD is required}"

release_tag="${CHITCHAT_RELEASE_TAG:-v1.0.0-rc.1}"
current_root="$(pwd)"
current_version="$(
  cd "$current_root"
  env -u APP_VERSION php -r 'require "vendor/autoload.php"; echo \ChitChat\Config::fromEnvironment()->applicationVersion;'
)"
work_root="${RUNNER_TEMP:-/tmp}/chitchat-release-rehearsal"
release_root="$work_root/release"
release_storage="$work_root/release-uploads"
restore_parent="$work_root/restored-storage"
restore_storage="$restore_parent/$(basename "$release_storage")"
backup_root="$work_root/backup"
archive="$work_root/release.tar.gz"
fixture="$work_root/rehearsal-attachment.txt"
admin_cookie="$work_root/admin.cookies"
member_cookie="$work_root/member.cookies"
release_log="$work_root/release-server.log"
upgraded_log="$work_root/upgraded-server.log"
restore_db="${DB_NAME}_restore"
release_pid=""
upgraded_pid=""

cleanup() {
  if [[ -n "$release_pid" ]]; then
    kill "$release_pid" 2>/dev/null || true
    wait "$release_pid" 2>/dev/null || true
  fi
  if [[ -n "$upgraded_pid" ]]; then
    kill "$upgraded_pid" 2>/dev/null || true
    wait "$upgraded_pid" 2>/dev/null || true
  fi
}
trap cleanup EXIT

rm -rf "$work_root"
mkdir -p "$release_root" "$release_storage" "$backup_root" "$restore_parent"
chmod 700 "$release_storage" "$restore_parent"
touch "$admin_cookie" "$member_cookie"
printf 'attachment preserved across archive install, backup, restore and upgrade\n' > "$fixture"

export APP_ENV=test
export APP_DEBUG=1
export SESSION_COOKIE_SECURE=0
export DB_SSLMODE="${DB_SSLMODE:-disable}"
export ATTACHMENT_STORAGE_PATH="$release_storage"
export PGPASSWORD="$DB_PASSWORD"
export PGHOST="$DB_HOST"
export PGPORT="$DB_PORT"
export PGUSER="$DB_USER"

gh api \
  -H 'Accept: application/vnd.github+json' \
  "/repos/${GITHUB_REPOSITORY}/tarball/${release_tag}" > "$archive"
tar -xzf "$archive" --strip-components=1 -C "$release_root"

(
  cd "$release_root"
  composer install --no-interaction --no-dev --classmap-authoritative
  composer migrate
)

start_php_server() {
  local root="$1"
  local port="$2"
  local log="$3"

  PHP_CLI_SERVER_WORKERS=8 php -S "127.0.0.1:${port}" -t "$root/public" > "$log" 2>&1 &
  echo "$!"
}

wait_ready() {
  local base_url="$1"
  curl --fail --silent --show-error \
    --retry 30 --retry-delay 1 --retry-all-errors \
    "$base_url/ready.php" >/dev/null
}

session_json() {
  local base_url="$1"
  local cookie="$2"
  curl --fail-with-body --silent --show-error \
    --cookie "$cookie" --cookie-jar "$cookie" \
    "$base_url/api/v1/session.php"
}

csrf_token() {
  session_json "$1" "$2" | jq -er '.csrf_token'
}

post_json() {
  local base_url="$1"
  local cookie="$2"
  local csrf="$3"
  local path="$4"
  local body="$5"

  curl --fail-with-body --silent --show-error \
    --cookie "$cookie" --cookie-jar "$cookie" \
    -H 'Content-Type: application/json' \
    -H "X-CSRF-Token: $csrf" \
    --data "$body" \
    "$base_url$path"
}

release_base='http://127.0.0.1:8081'
release_pid="$(start_php_server "$release_root" 8081 "$release_log")"
wait_ready "$release_base"

curl --fail --silent "$release_base/health.php" \
  | jq -e --arg expected "${release_tag#v}" '.status == "ok" and .version == $expected' >/dev/null

admin_csrf="$(csrf_token "$release_base" "$admin_cookie")"
admin_response="$(post_json "$release_base" "$admin_cookie" "$admin_csrf" '/api/v1/register.php' \
  '{"username":"ArchiveAdmin","password":"Correct Horse Battery Staple 2026!","birth_date":"1990-01-01"}')"
admin_id="$(jq -er '.user.id' <<< "$admin_response")"
admin_csrf="$(csrf_token "$release_base" "$admin_cookie")"

room_response="$(post_json "$release_base" "$admin_cookie" "$admin_csrf" '/api/v1/rooms/create.php' \
  '{"key":"release-rehearsal","name":"Release Rehearsal","info_line":"Archive and restore validation","visibility":"public","minimum_ae":0,"inactivity_timeout_seconds":0}')
room_id="$(jq -er '.room.id' <<< "$room_response")"

post_json "$release_base" "$admin_cooie" "$admin_csrf" '/api/v1/rooms/send.php' \
  "$(jq -nc --argjson room_id "$room_id" --arg body 'Message created by the published archive' '{room_id:$room_id,body:$body}')" \
  | jq -e '.message.type == "text"' >/dev/null

upload_response="$(curl --fail-with-body --silent --show-error \
  --cookie "$admin_cookie" --cookie-jar "$admin_cookie" \
  -H "X-CSRF-Token: $admin_csrf" \
  -F "room_id=$room_id" \
  -F 'caption=Attachment created by the published archive' \
  -F "file=@$fixture;type=text/plain;filename=rehearsal-attachment.txt" \
  "$release_base/api/v1/attachments/upload.php")"
attachment_id="$(jq -er '.message.attachment.id' <<< "$upload_response")"

member_csrf="$(csrf_token "$release_base" "$member_cookie")"
member_response="$(post_json "$release_base" "$member_cookie" "$member_csrf" '/api/v1/register.php' \
  '{"username":"ArchiveMember","password":"Another Correct Horse Battery Staple 2026!","birth_date":"1991-02-02"}')"
member_id="$(jq -er '.user.id' <<< "$member_response")"
member_csrf="$(csrf_token "$release_base" "$member_cookie")"

post_json "$release_base" "$member_cookie" "$member_csrf" '/api/v1/rooms/join.php' \
  "$(jq -nc --argjson room_id "$room_id" '{room_id:$room_id}')" \
  | jq -e '.room.member_role == "member"' >/dev/null

post_json "$release_base" "$member_cookie" "$member_csrf" '/api/v1/rooms/send.php' \
  "$(jq -nc --argjson room_id "$room_id" --arg body 'Member message preserved through restore' '{room_id:$room_id,body:$body}')" \
  | jq -e '.message.type == "text"' >/dev/null

post_json "$release_base" "$admin_cooie" "$admin_csrf" '/api/v1/direct-messages/send.php' \
  "$(jq -nc --argjson recipient_user_id "$member_id" --arg body 'Direct message preserved through restore' '{recipient_user_id:$recipient_user_id,body:$body}')" \
  | jq -e '.message.body == "Direct message preserved through restore"' >/dev/null

curl --fail-with-body --silent --show-error \
  --cookie "$admin_cookie" \
  "$release_base/api/v1/attachments/download.php?id=$attachment_id" \
  > "$work_root/download-before-backup.txt"
cmp "$fixture" "$work_root/download-before-backup.txt"

kill "$release_pid"
wait "$release_pid" 2>/dev/null || true
release_pid=""

pg_dump \
  --format=custom \
  --no-owner \
  --no-privileges \
  --file="$backup_root/database.dump" \
  "$DB_NAME"
tar -C "$(dirname "$release_storage")" -cpf "$backup_root/attachments.tar" "$(basename "$release_storage")"
(
cd "$backup_root"
  sha256sum database.dump attachments.tar > SHA256SUMS
  sha256sum -c SHA256SUMS
  pg_restore --list database.dump >/dev/null
  tar -tf attachments.tar >/dev/null
)

dropdb --if-exists "$restore_db"
createdb "$restore_db"
pg_restore \
  --no-owner \
  --no-privileges \
  --dbname="$restore_db" \
  "$backup_root/database.dump"
tar -C "$restore_parent" -xpf "$backup_root/attachments.tar"
chmod 700 "$restore_storage"

export DB_NAME="$restore_db"
export ATTACHMENT_STORAGE_PATH="$restore_storage"
(
  cd "$current_root"
  composer migrate
)

upgraded_base='http://127.0.0.1:8082'
upgraded_pid="$(start_php_server "$current_root" 8082 "$upgraded_log")"
wait_ready "$upgraded_base"

curl --fail --silent "$upgraded_base/health.php" \
  | jq -e --arg expected "$current_version" '.status == "ok" and .version == $expected' >/dev/null

rm -f "$admin_cookie"
touch "$admin_cookie"
admin_csrf="$(csrf_token "$upgraded_base" "$admin_cookie")"
post_json "$upgraded_base" "$admin_cookie" "$admin_csrf" '/api/v1/login.php' \
  '{"username":"ArchiveAdmin","password":"Correct Horse Battery Staple 2026!"}' \
  | jq -e --argjson admin_id "$admin_id" '.user.id == $admin_id' >/dev/null
admin_csrf="$(csrf_token "$upgraded_base" "$admin_cookie")"

curl --fail-with-body --silent --show-error --cookie "$admin_cookie" \
  "$upgraded_base/api/v1/rooms/messages.php?room_id=$room_id&limit=100" \
  | jq -e '
      [.messages[].body] as $bodies
      | ($bodies | index("Message created by the published archive")) != null
      and ($bodies | index("Member message preserved through restore")) != null
    ' >/dev/null

curl --fail-with-body --silent --show-error --cookie "$admin_cookie" \
  "$upgraded_base/api/v1/direct-messages/history.php?user_id=$member_id&limit=100" \
  | jq -e '[.messages[].body] | index("Direct message preserved through restore") != null' >/dev/null

curl --fail-with-body --silent --show-error \
  --cookie "$admin_cookie" \
  "$upgraded_base/api/v1/attachments/download.php?id=$attachment_id" \
  > "$work_root/download-after-restore.txt"
cmp "$fixture" "$work_root/download-after-restore.txt"

psql --dbname "$restore_db" --tuples-only --no-align <<'SQL' > "$work_root/restored-counts.txt"
SELECT 'users=' || count(*) FROM users;
SELECT 'rooms=' || count(*) FROM rooms WHERE deleted_at IS NULL;
SELECT 'messages=' || count(*) FROM room_messages;
SELECT 'attachments=' || count(*) FROM attachments;
SELECT 'direct_messages=' || count(*) FROM direct_messages;
SQL

grep -qx 'users=2' "$work_root/restored-counts.txt"
grep -qx 'rooms=1' "$work_root/restored-counts.txt"
grep -qx 'messages=3' "$work_root/restored-counts.txt"
grep -qx 'attachments=1' "$work_root/restored-counts.txt"
grep -qx 'direct_messages=1' "$work_root/restored-counts.txt"

(
  cd "$current_root"
  composer maintenance:dry-run >/dev/null
)

printf 'Release archive installation, backup, restore and upgrade rehearsal passed.\n'
