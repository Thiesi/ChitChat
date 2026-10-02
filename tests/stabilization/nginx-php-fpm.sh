# Sourced, not executed. Serves ChitChat through Nginx and PHP-FPM, as in
# production, for the reverse-proxy rehearsal and the browser test suite.
#
# start_nginx_php_fpm <work_root> <php_fpm_children>
#   Writes configuration and logs under <work_root>, starts both servers in
#   the background on 127.0.0.1:8080, and sets fpm_pid and nginx_pid. The
#   environment is passed through to PHP-FPM (clear_env = no).
#
# Unlike `php -S`, whose workers each accept every pending connection and then
# serve them one after another, PHP-FPM hands each request to the next free
# child, so a long-lived SSE stream never delays another request.

start_nginx_php_fpm() {
  local work_root="$1"
  local children="$2"
  local root
  root="$(pwd)"

  mkdir -p "$work_root" "$work_root/nginx" "$work_root/nginx/client-body" \
    "$work_root/nginx/fastcgi-temp"
  chmod 700 "$work_root"

  local fpm_bin
  fpm_bin="$(
    command -v php-fpm8.4 \
      || command -v php-fpm8.3 \
      || command -v php-fpm \
      || find /usr/sbin -maxdepth 1 -type f -name 'php-fpm*' -print | sort -V | tail -n 1
  )"
  if [[ -z "$fpm_bin" || ! -x "$fpm_bin" ]]; then
    echo 'PHP-FPM binary not found.' >&2
    return 1
  fi

  local pool_user pool_group
  pool_user="$(id -un)"
  pool_group="$(id -gn)"
  cat > "$work_root/php-fpm.conf" <<EOF
[global]
pid = $work_root/php-fpm.pid
error_log = $work_root/php-fpm.log
daemonize = no

[chitchat]
listen = 127.0.0.1:9070
listen.allowed_clients = 127.0.0.1
user = $pool_user
group = $pool_group
pm = static
pm.max_children = $children
pm.max_requests = 100
clear_env = no
catch_workers_output = yes
security.limit_extensions = .php
php_admin_flag[log_errors] = on
php_admin_value[error_log] = $work_root/php-fpm.log
EOF

  cat > "$work_root/nginx.conf" <<EOF
worker_processes 1;
pid $work_root/nginx.pid;
error_log $work_root/nginx-error.log info;

events {
    worker_connections 1024;
}

http {
    include /etc/nginx/mime.types;
    default_type application/octet-stream;
    log_format timed '\$remote_addr [\$time_local] "\$request" \$status \$body_bytes_sent \$request_time';
    access_log $work_root/nginx-access.log timed;
    client_body_temp_path $work_root/nginx/client-body;
    fastcgi_temp_path $work_root/nginx/fastcgi-temp;
    sendfile on;

    server {
        listen 127.0.0.1:8080;
        server_name localhost;
        root $root/public;
        index index.php;
        client_max_body_size 12m;

        location / {
            try_files \$uri \$uri/ /index.php?\$query_string;
        }

        location = /api/v1/events/stream.php {
            include /etc/nginx/fastcgi_params;
            fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
            fastcgi_pass 127.0.0.1:9070;
            fastcgi_buffering off;
            fastcgi_request_buffering off;
            fastcgi_cache off;
            fastcgi_read_timeout 60s;
            gzip off;
        }

        location ~ \.php$ {
            try_files \$uri =404;
            include /etc/nginx/fastcgi_params;
            fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
            fastcgi_pass 127.0.0.1:9070;
            fastcgi_read_timeout 60s;
        }
    }
}
EOF

  sudo -E "$fpm_bin" -F -y "$work_root/php-fpm.conf" > "$work_root/php-fpm-stdout.log" 2>&1 &
  fpm_pid="$!"
  nginx -c "$work_root/nginx.conf" -p "$work_root/nginx/" -g 'daemon off;' > "$work_root/nginx-stdout.log" 2>&1 &
  nginx_pid="$!"
}
