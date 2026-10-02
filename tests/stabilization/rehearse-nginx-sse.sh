#!/usr/bin/env bash
set -euo pipefail

: "${DB_HOST:?DB_HOST is required}"
: "${DB_PORT:?DB_PORT is required}"
: "${DB_NAME:?DB_NAME is required}"
: "${DB_USER:?DB_USER is required}"
: "${DB_PASSWORD:?DB_PASSWORD is required}"
: "${ATTACHMENT_STORAGE_PATH:?ATTACHMENT_STORAGE_PATH is required}"

root="$(pwd)"
work_root="${RUNNER_TEMP:-/tmp}/chitchat-nginx-rehearsal"
fpm_log="$work_root/php-fpm.log"
nginx_log="$work_root/nginx-error.log"
nginx_access="$work_root/nginx-access.log"
stream_headers="$work_root/stream.headers"
stream_body="$work_root/stream.body"
cookie="$work_root/admin.cookies"
base_url="${CHITCHAT_BASE_URL:-http://127.0.0.1:8080}"
fpm_pid=""
nginx_pid=""
stream_pid=""

cleanup() {
  if [[ -n "$stream_pid" ]]; then
    kill "$stream_pid" 2>/dev/null || true
    wait "$stream_pid" 2>/dev/null || true
  fi
  if [[ -n "$nginx_pid" ]]; then
    kill "$nginx_pid" 2>/dev/null || true
    wait "$nginx_pid" 2>/dev/null || true
  fi
  if [[ -n "$fpm_pid" ]]; then
    sudo kill "$fpm_pid" 2>/dev/null || true
    wait "$fpm_pid" 2>/dev/null || true
  fi
}
trap cleanup EXIT

rm -rf "$work_root" "$ATTACHMENT_STORAGE_PATH"
mkdir -p "$work_root" "$ATTACHMENT_STORAGE_PATH"
chmod 700 "$work_root" "$ATTACHMENT_STORAGE_PATH"
touch "$cookie" "$stream_body" "$stream_headers"

# shellcheck source=tests/stabilization/nginx-php-fpm.sh
source "$root/tests/stabilization/nginx-php-fpm.sh"
start_nginx_php_fpm "$work_root" 8

show_logs() {
  echo '--- PHP-FPM log ---' >&2
  cat "$fpm_log" >&2 2>/dev/null || true
  cat "$work_root/php-fpm-stdout.log" >&2 2>/dev/null || true
  echo '--- Nginx error log ---' >&2
  cat "$nginx_log" >&2 2>/dev/null || true
  echo '--- Nginx access log ---' >&2
  cat "$nginx_access" >&2 2>/dev/null || true
}
trap 'status=$?; if (( status != 0 )); then show_logs; fi; cleanup; exit $status' EXIT

curl --fail --silent --show-error \
  --retry 30 --retry-delay 1 --retry-all-errors \
  "$base_url/ready.php" >/dev/null

session="$(curl --fail-with-body --silent --show-error \
  --cookie "$cookie" --cookie-jar "$cookie" \
  "$base_url/api/v1/session.php")"
csrf="$(jq -er '.csrf_token' <<< "$session")"

curl --fail-with-body --silent --show-error \
  --cookie "$cookie" --cookie-jar "$cookie" \
  -H 'Content-Type: application/json' \
  -H "X-CSRF-Token: $csrf" \
  --data '{"username":"ProxyAdmin","password":"Correct Horse Battery Staple 2026!","birth_date":"1990-01-01"}' \
  "$base_url/api/v1/register.php" \
  | jq -e '.user.roles | index("super_admin") != null' >/dev/null

session="$(curl --fail-with-body --silent --show-error \
  --cookie "$cookie" --cookie-jar "$cookie" \
  "$base_url/api/v1/session.php")"
csrf="$(jq -er '.csrf_token' <<< "$session")"

room_response="$(curl --fail-with-body --silent --show-error \
  --cookie "$cookie" --cookie-jar "$cookie" \
  -H 'Content-Type: application/json' \
  -H "X-CSRF-Token: $csrf" \
  --data '{"key":"proxy-rehearsal","name":"Proxy Rehearsal","info_line":"Nginx SSE validation","visibility":"public","minimum_age":0,"inactivity_timeout_seconds":0}' \
  "$base_url/api/v1/rooms/create.php")"
room_id="$(jq -er '.room.id' <<< "$room_response")"

curl --silent --show-error --no-buffer --max-time 20 \
  --dump-header "$stream_headers" \
  --cookie "$cookie" \
  "$base_url/api/v1/events/stream.php" > "$stream_body" &
stream_pid="$!"

for _ in $(seq 1 30); do
  if grep -qi '^content-type: text/event-stream' "$stream_headers"; then
    break
  fi
  sleep 0.2
done
grep -qi '^content-type: text/event-stream' "$stream_headers"
grep -qi '^cache-control:.*no-store' "$stream_headers"

unique_message="SSE-through-Nginx-$(date +%s%N)"
curl --fail-with-body --silent --show-error \
  --cookie "$cookie" --cookie-jar "$cookie" \
  -H 'Content-Type: application/json' \
  -H "X-CSRF-Token: $csrf" \
  --data "$(jq -nc --argjson room_id "$room_id" --arg body "$unique_message" '{room_id:$room_id,body:$body}')" \
  "$base_url/api/v1/rooms/send.php" \
  | jq -e --arg body "$unique_message" '.message.body == $body' >/dev/null

seen=0
for _ in $(seq 1 40); do
  if grep -Fq "$unique_message" "$stream_body"; then
    seen=1
    break
  fi
  sleep 0.25
done

if (( seen == 0 )); then
  echo 'The SSE event did not pass through Nginx before the buffering deadline.' >&2
  exit 1
fi

grep -q '^event: room_message' "$stream_body"

kill "$stream_pid" 2>/dev/null || true
wait "$stream_pid" 2>/dev/null || true
stream_pid=""

printf 'Nginx/PHP-FPM delivered an authenticated SSE room event without buffering.\n'
