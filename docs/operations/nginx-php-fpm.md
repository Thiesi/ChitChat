# Nginx and PHP-FPM deployment

This configuration is the reference single-server deployment for ChitChat. CI starts a real Nginx process in front of PHP-FPM, registers an account through the proxy, opens the authenticated SSE stream, sends a room message through the same session, and requires the event to appear before the stream closes. That rehearsal detects response buffering, an undersized FPM pool, a retained PHP session lock, and common FastCGI path errors.

Use HTTPS in production. The example listens on plain HTTP only to show the application and SSE-specific directives.

## PHP-FPM pool

Use a dedicated pool and operating-system account. Capacity must include one worker for every open SSE request plus workers for ordinary API, upload, download, and page requests.

```ini
[chitchat]
listen = /run/php/chitchat.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660

user = chitchat
group = chitchat

pm = dynamic
pm.max_children = 32
pm.start_servers = 8
pm.min_spare_servers = 4
pm.max_spare_servers = 12
pm.max_requests = 500

clear_env = yes
catch_workers_output = yes
security.limit_extensions = .php
```

Provide ChitChat's environment through a protected service environment file, systemd unit, container environment, or explicit `env[...]` pool entries. Do not place database credentials in the Nginx configuration or below `public/`.

The SSE endpoint closes after approximately 25 seconds and reconnects. A deployment with 20 concurrently connected browser tabs therefore needs room for those 20 temporary SSE workers **in addition to** normal requests. Tune from measured concurrency rather than copying the example value blindly.

## Nginx server

```nginx
server {
    listen 443 ssl http2;
    server_name chat.example.org;

    root /srv/chitchat/public;
    index index.php;

    client_max_body_size 12m; # slightly above ATTACHMENT_MAX_BYTES

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /api/v1/events/stream.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/chitchat.sock;

        fastcgi_buffering off;
        fastcgi_request_buffering off;
        fastcgi_cache off;
        fastcgi_read_timeout 60s;
        gzip off;
    }

    location ~ \.php$ {
        try_files $uri =404;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/chitchat.sock;
        fastcgi_read_timeout 60s;
    }

    location ~ /\. {
        deny all;
    }
}
```

The exact SSE location must take precedence over the general PHP location. ChitChat also sends `X-Accel-Buffering: no`, but the explicit Nginx directives are retained because proxy behavior must not depend solely on an upstream response header.

Do not add `proxy_buffering` directives to a FastCGI location; use `fastcgi_buffering off`. Do not enable gzip on the event stream. The upstream timeout must exceed the approximately 25-second stream lifetime.

## Verification

After deployment:

1. verify `/health.php` and `/ready.php` through Nginx;
2. sign in with two independent browser sessions;
3. confirm both show `Live`;
4. send room messages in both directions and confirm delivery without waiting for a reconnect;
5. leave one tab open longer than 25 seconds and confirm it reconnects;
6. upload and download a small allowed attachment;
7. inspect Nginx and PHP-FPM logs for premature upstream termination or exhausted workers.

A command-line buffering probe can open an authenticated stream with `curl -N`, send a unique room message from the same session, and require that text to appear within a few seconds. `tests/stabilization/rehearse-nginx-sse.sh` performs this automatically in CI.

## Security boundary

Only `/srv/chitchat/public` is the document root. The `.env` file, Composer metadata, `src/`, migrations, logs, PostgreSQL dumps, and attachment storage must remain outside it. Terminate TLS before issuing secure session cookies, set `SESSION_COOKIE_SECURE=1`, and preserve ChitChat's response security headers rather than replacing them with weaker values.
