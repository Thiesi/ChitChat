# NetBSD with Apache and PHP-FPM

This guide covers running ChitChat on **NetBSD with pkgsrc**, using rc.d, cron, and **Apache 2.4 with PHP-FPM**, instead of the Linux, systemd and Nginx setup that the rest of the operator documentation assumes. It's based on a production setup running several ChitChat instances on NetBSD 11.0 (amd64) with pkgsrc binary packages, PHP 8.3, PostgreSQL 16 and Apache 2.4. Ready-to-adapt files are in [`deploy/netbsd/`](../../deploy/netbsd/).

Steps that aren't specific to the platform (`.env`, `composer install`, `composer migrate`, release upgrades) are the same as in [`INSTALL.md`](../../INSTALL.md). Continuous integration tests only Linux with Nginx; the Apache and NetBSD material here is operator-validated.

Placeholders: `APPUSER` is the instance's user and database name, and `/home/APPUSER/{app,uploads,sessions,backups,log,run}` are its directories. Each instance gets its own user, database, PHP-FPM pool, socket, crontab and push listener.

## Packages

From pkgsrc (`pkgin install …`):

- `php83`, `php83-fpm`, plus `php83-pdo`, `php83-pdo_pgsql`, `php83-curl`, `php83-gd` (with WebP), `php83-mbstring`, `php83-intl`, `php83-iconv`, `php83-zip`, `php83-zlib`, `php83-sockets` and `php83-exif`;
- `php83-pcntl`, only for the push listener (see [pcntl](#pcntl-only-for-the-listener));
- `postgresql16-server` and `postgresql16-client` (`pg_dump`/`pg_restore` live in `/usr/pkg/bin`, so `PATH` must include it);
- `apache24`, with `mod_proxy_fcgi`, `mod_headers`, `mod_setenvif` and `mod_rewrite`.

Composer can be a per-instance `composer.phar`. Binary packages don't install rc.d scripts; copy `php_fpm83` from `/usr/pkg/share/examples/rc.d/` to `/etc/rc.d/`.

## Accounts

Give each instance a system user with `/sbin/nologin` as its shell, and run every ChitChat command as that user. As root, `su -m USER -c …` runs `$SHELL` rather than the target user's shell, so set it explicitly, and give the command a clean environment so tools like Composer don't write to root's home:

```sh
SHELL=/bin/sh su -m APPUSER -c 'env -i HOME=/home/APPUSER PATH=/usr/bin:/bin:/usr/pkg/bin OPENSSL_CONF=/usr/share/examples/openssl/openssl.cnf sh -c "cd ~/app && php bin/migrate"'
```

**Editing `.env` as root:** `sed -i` replaces the file and resets its owner to root. Write into the existing file instead (`cat new > .env`), keeping `APPUSER:APPUSER` and mode `0600`.

## OpenSSL configuration

NetBSD 11's base OpenSSL ships no `/etc/openssl/openssl.cnf`, only `/usr/share/examples/openssl/openssl.cnf`. Without `OPENSSL_CONF`, `openssl_pkey_new()` fails, and with it VAPID key generation and Web Push. Set `OPENSSL_CONF=/usr/share/examples/openssl/openssl.cnf` in all four places:

- the PHP-FPM master (`php_fpm83_env` in `rc.conf`);
- each pool (`env[OPENSSL_CONF]`);
- each crontab;
- the push listener (the rc.d script sets it).

## PHP-FPM

Run one shared `php_fpm83` master with one pool per instance: [`php-fpm-pool.conf.example`](../../deploy/netbsd/php-fpm-pool.conf.example). The pool runs as `APPUSER`, listens on a Unix socket owned by `APPUSER` with group `www` (Apache), clears the environment, and sets the upload limits a little above `ATTACHMENT_MAX_BYTES`. Disable the package's default `www` pool.

Because the master is shared with other sites, "stopping ChitChat" means taking its pool out of the configuration and reloading, not stopping the master. That's why the backup example below runs live.

## Apache

[`apache-vhost.conf.example`](../../deploy/netbsd/apache-vhost.conf.example) holds the ChitChat-specific parts of the vhost. The important points:

- **`DocumentRoot` is `public/`.** `.env`, `src/`, `vendor/` and everything else live outside it, so no deny rules are needed. `FallbackResource /index.php` answers unknown paths, including `/.env`, with the chat page and status `200`. That's harmless; add explicit `404` rules if you prefer them.
- **Live updates (SSE) must not be buffered.** `mod_proxy_fcgi` buffers responses unless the worker has `flushpackets=on`, and the worker `timeout` must exceed the stream's roughly 25-second lifetime. Disable compression for `/api/v1/events/stream.php` (`no-gzip`). This setup has been verified to deliver stream chunks as they're sent. For a stream opened with `resume=settled`, two optional PostgreSQL role settings help: `idle_in_transaction_session_timeout` for roles other than the web sessions, and `pg_read_all_stats` when other database roles write events. See [`INSTALL.md`, Server-Sent Events](../../INSTALL.md#server-sent-events).
- **Request body limit:** set `LimitRequestBody` a little above `ATTACHMENT_MAX_BYTES` (12 MiB for the 10 MiB default).
- **Security headers:** ChitChat sends its own CSP, frame and referrer headers. A server-wide `Header always set X-Frame-Options …` replaces ChitChat's value, so restore `DENY` for the vhost, as in the example. Server-wide HSTS and `nosniff` are fine.
- **Android credential association** (from the release after `v3.5.0`, #145): `/.well-known/assetlinks.json` must reach `public/assetlinks.php`, unless you serve your own static file at that path. Without a rule, `FallbackResource` answers with the chat page, and Android rejects the app's passkeys. The example uses an exact `mod_rewrite` rule that lets an existing file win. Write it in **vhost context**, outside `<Directory>` and `<Location>`: inside `<Directory>` the pattern loses its leading slash and `DOCUMENT_ROOT` behaves differently. If your server denies dotfiles (for example `<LocationMatch "/\.">` or `<FilesMatch "^\.">`), place this rule before that deny or exempt the path. After deploying, check that it returns JSON (`[]` when `NATIVE_ANDROID_APPS` is empty).
- **Query strings in access logs:** ChitChat's native sign-in hand-off (#146) carries one-time values in query strings. Before enabling native sign-in, **replace** the access log your vhost already writes with a format that records the path without the query (`%U` instead of `%r`), as in the example. Adding a second `CustomLog` leaves the query strings in the first. If a shared vhost frame writes the log, suppress its line for this site (for example `SetEnvIf Request_URI "^" own_access_log` with the frame's `CustomLog … env=!own_access_log`) and define the site's own log. Logging the referrer is fine, because ChitChat sends `Referrer-Policy: no-referrer`.
- **One origin:** passkeys (`WEBAUTHN_RP_ID`, `APP_ORIGIN`) need exactly one origin, so make any `www.` alias a plain redirect to the canonical name.

### Installation icons

Use the **Installation icon** setting beside the installation name. ChitChat stores normalized renditions in the database; ordinary backups include them. GD with PNG and WebP is needed for uploads and presets, and FreeType for the preset that shows the installation name's initial (pkgsrc's `php83-gd` is normally built with FreeType; confirm with `php -r 'var_export(gd_info());'` as the instance user). Without FreeType that one preset is unavailable and the setting explains why; it isn't a fault. `memory_limit >= 256M` is recommended for large inputs. No Alias, writable docroot or new host rule is needed. /favicon.ico uses the existing FallbackResource dispatch before any session starts. See [the API and operations contract](../api/installation-icon.md).

Legacy note: older releases required per-URL Alias overrides for Lantern assets. Remove those mappings when adopting this setting, including any /favicon.ico Alias, or the web server will continue serving the legacy files. Browsers and installed apps can retain previously cached icons.

## Scheduled jobs (cron)

NetBSD's cron can't run more often than once a minute. Install a per-instance crontab: [`crontab.example`](../../deploy/netbsd/crontab.example).

- **Web Push sweep, every minute:** the fallback to the listener, which does the fast delivery.
- **Daily maintenance**, offset per instance.
- **Daily live backup**, offset per instance. The backup tool never prunes old sets, so manage retention yourself.

## Web Push listener (rc.d)

The `--listen` worker delivers push within about a second of a notification being committed, and sweeps by itself at least every 15 seconds while idle; see [`web-push.md`](web-push.md#optional-immediate-listener). NetBSD's rc.d has no restart-on-failure, and NetBSD has no `daemon(8)`, so a small supervisor provides it:

- [`APPUSER_push.rc`](../../deploy/netbsd/APPUSER_push.rc): install it as `/etc/rc.d/APPUSER_push` (owned `root:wheel`, mode `0555`), with one copy per instance that differs only in `PROVIDE` and `inst=`, and enable it with `APPUSER_push=YES` ([`rc.conf.example`](../../deploy/netbsd/rc.conf.example)). It starts the supervisor as the instance user with a clean environment, treats the user-writable pidfiles as untrusted, and offers `start`, `stop` and `status`.
- [`chitchat-push-supervise`](../../deploy/netbsd/chitchat-push-supervise): install it as `/usr/local/libexec/chitchat-push-supervise` (owned `root:wheel`, mode `0555`); one copy serves all instances.

What the supervisor does:

| Requirement | Behaviour |
| --- | --- |
| User, directory | the instance user, the app directory, `umask 077` |
| Restart | after any unexpected exit, with a 5 s delay |
| Not restarted | exit `2` (bad arguments); after 5 starts within 60 s it gives up and logs why |
| Stop | passes SIGTERM on; the listener finishes its in-flight attempt (usually about 1 s). The rc.d script allows 25 s, then sends SIGKILL, which is safe: leases expire, and the cron sweep picks up |
| Resources | virtual memory capped at 256 MB, `nice 10` |
| Database outages | handled inside the listener (reconnect with 1–30 s back-off), with no restart |

Restart the listener after every code deploy (after `composer migrate`) and after any `.env` or VAPID change: `service APPUSER_push restart`.

System status shows push as **overdue** when a due delivery has waited over 5 minutes, which means both the listener and the cron sweep have stopped. Nothing shows while nothing is due ([Push queue health](observability.md#push-queue-health)).

Rotate the listener's log with **copy-then-truncate**, for example `/etc/copytruncate.conf`. Rename-based rotation such as newsyslog's default leaves the long-running process writing to the renamed file.

### pcntl only for the listener

The listener needs `pcntl` for graceful SIGTERM handling. Installing `php83-pcntl` adds `php.d/pcntl.ini`, which enables it for **every** PHP SAPI, PHP-FPM included, at the next reload. Comment that line out; the supervisor loads the extension only for the listener with `php -d extension=pcntl.so`.

## Authenticator-app (TOTP) keys

Authenticator-app codes need an encryption key for the stored secrets; without one, enrollment simply isn't offered. The recommended source is a server key ring in the instance's `.env`:

1. As the instance user, in a private terminal: `php bin/totp-key generate`. It prints the `TOTP_ENCRYPTION_KEYS` and `TOTP_ACTIVE_KEY_ID` lines and touches nothing else. The output is a secret: keep it out of shell history, tickets and logs.
2. Add both lines to `.env` following [Editing `.env` as root](#accounts): write into the existing file so it stays `APPUSER:APPUSER` and mode `0600`.
3. Reload the shared FPM master (`service php_fpm83 reload`). PHP-FPM workers keep environment values they have already read, so a reload is what makes every worker use the new key.

No cron entry, rc.d script or Apache change is needed. [`totp.md`](totp.md) covers the warned database-key alternative, rotation and escrow; back the key ring up with the instance's secrets, because codes can't be verified without it.

Settings such as the sign-in decoration and the username reservation period are operated in the admin interface and need no host steps.

## Backups

[`backup-restore.md`](backup-restore.md) applies, with the commands run as the instance user. System status shows each instance's latest recorded backup and whether it is overdue ([Backup freshness](observability.md#backup-freshness)); the crontab line needs no change, and a run that couldn't be recorded leaves a warning in `backup.log` (the existing `2>&1`). Set `backup_max_age_hours` in Operational settings to suit each instance's schedule. On NetBSD, `chitchat-backup` calls `tar`, which resolves to the base system's **bsdtar** (libarchive), not GNU tar. Creating and verifying backups work with it, and the manifest records its version. **Restoring with bsdtar hasn't been rehearsed yet**, so run a restore drill to a new database name and directory before relying on it. `chitchat-verify-backup` takes `--backup DIR`.

## Upgrading

1. Take and verify a live backup (`php bin/chitchat-backup`, then `php bin/chitchat-verify-backup --backup DIR`).
2. Deploy the release source and follow its release notes for `.env` changes.
3. As the instance user: `composer install --no-dev --classmap-authoritative` and `composer migrate`.
4. Reload the shared FPM master (`service php_fpm83 reload`) and restart the instance's listener (`service APPUSER_push restart`).
5. Check `/health.php`, `/ready.php`, and the `application.version` that `/api/v1/session.php` reports, and run `service APPUSER_push status`.
