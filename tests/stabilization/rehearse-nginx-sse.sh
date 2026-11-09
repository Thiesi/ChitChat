#!/usr/bin/env bash
set -euo pipefail

: "${DB_HOST:?DB_HOST is required}"
: "${DB_PORT:?DB_PORT is required}"
: "${DB_NAME:?DB_NAME is required}"
: "${DB_USER:?DB_USER is required}"
: "${DB_PASSWORD:?DB_PASSWORD is required}"
: "${ATTACHMENT_STORAGE_PATH:?ATTACHMENT_STORAGE_PATH is required}"

# This rehearsal registers an account with curl, which cannot solve the
# browser's registration challenge.
export REGISTRATION_MIN_FILL_SECONDS=0
export REGISTRATION_PROOF_OF_WORK_BITS=0
export NATIVE_ANDROID_APPS='[{"package":"de.networkxxiii.chitchat.debug","sha256_cert_fingerprint":"AB:AB:AB:AB:AB:AB:AB:AB:AB:AB:AB:AB:AB:AB:AB:AB:AB:AB:AB:AB:AB:AB:AB:AB:AB:AB:AB:AB:AB:AB:AB:AB"}]'

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
dal_static_file=""
dal_created_directory=""

cleanup() {
  if [[ -n "$dal_static_file" && -L "$dal_static_file" \
      && "$(readlink "$dal_static_file")" == "$work_root/dal-static.json" ]]; then
    rm -f "$dal_static_file"
  fi
  if [[ -n "$dal_created_directory" ]]; then
    rmdir "$dal_created_directory" 2>/dev/null || true
  fi
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

# The legacy favicon fallback runs through ordinary try_files, never a PHP session.
curl --fail --silent --show-error --dump-header "$work_root/favicon.headers" \
  --output "$work_root/favicon.ico" "$base_url/favicon.ico"
cmp public/assets/app-icons/favicon.ico "$work_root/favicon.ico"
! grep -Eqi '^Set-Cookie:' "$work_root/favicon.headers"
grep -qi '^Content-Type: image/x-icon' "$work_root/favicon.headers"
grep -qi '^Cache-Control: no-cache' "$work_root/favicon.headers"
curl --fail --silent --show-error --head "$base_url/favicon.ico" > "$work_root/favicon-head.headers"
! grep -Eqi '^Set-Cookie:' "$work_root/favicon-head.headers"
# Unknown native callbacks never create a session, and sensitive query values
# must not appear in the configured Nginx access/error logs.
native_headers="$work_root/native.headers"
native_status="$(curl --silent --show-error --dump-header "$native_headers" --output "$work_root/native.body" \
  --write-out '%{http_code}' "$base_url/api/v1/oidc/callback.php?state=native.AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA&code=cc-native-log-secret")"
[[ "$native_status" == 400 ]]
! grep -Eqi '^Set-Cookie:' "$native_headers"
grep -qi '^Cache-Control: no-store' "$native_headers"
grep -qi '^Referrer-Policy: no-referrer' "$native_headers"
grep -Fq '/api/v1/oidc/callback.php HTTP/' "$nginx_access"
! grep -Eq 'cc-native-log-secret|native\.AAAAAAAA' "$nginx_access" "$nginx_log"
# Synthetic certificate: verifies serving/configuration only, not Android acceptance.
dal_headers="$work_root/dal.headers"
dal_body="$work_root/dal.json"
dal_status="$(curl --silent --show-error --dump-header "$dal_headers" --output "$dal_body" \
  --write-out '%{http_code}' "$base_url/.well-known/assetlinks.json")"
[[ "$dal_status" == 200 ]]
jq -e 'length == 1 and .[0].relation == ["delegate_permission/common.get_login_creds"]
  and .[0].target.namespace == "android_app"
  and .[0].target.package_name == "de.networkxxiii.chitchat.debug"
  and (.[0].target.sha256_cert_fingerprints | length) == 1' "$dal_body" >/dev/null
grep -qi '^Content-Type: application/json' "$dal_headers"
grep -qi '^Cache-Control: public, max-age=300' "$dal_headers"
! grep -Eqi '^(Set-Cookie|Location):' "$dal_headers"
[[ "$(curl --silent --show-error --head --output "$work_root/dal-head.headers" --write-out '%{http_code}' "$base_url/.well-known/assetlinks.json")" == 200 ]]
[[ "$(curl --silent --show-error -X POST --output "$work_root/dal-method.json" --write-out '%{http_code}' "$base_url/.well-known/assetlinks.json")" == 405 ]]
[[ "$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' "$base_url/.well-known/unrelated-private-file")" == 403 ]]

# Create only our own bounded symlink; never replace an operator's declaration.
dal_dir="$root/public/.well-known"
dal_path="$dal_dir/assetlinks.json"
[[ ! -e "$dal_path" && ! -L "$dal_path" ]] || { echo 'Existing static DAL fixture path; refusing to replace it.' >&2; exit 1; }
if [[ ! -d "$dal_dir" ]]; then
  mkdir "$dal_dir"
  dal_created_directory="$dal_dir"
fi
printf '[{"fixture":"operator-static"}]\n' > "$work_root/dal-static.json"
ln -s "$work_root/dal-static.json" "$dal_path"
dal_static_file="$dal_path"
curl --fail --silent --show-error "$base_url/.well-known/assetlinks.json" \
  | jq -e '.[0].fixture == "operator-static"' >/dev/null
rm -f "$dal_static_file"
dal_static_file=""
if [[ -n "$dal_created_directory" ]]; then
  rmdir "$dal_created_directory"
  dal_created_directory=""
fi

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
