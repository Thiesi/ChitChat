# Maintenance and retention

ChitChat keeps room messages, direct messages, and audit entries permanently by default. Retention changes are made by a Super-Administrator at `/admin-settings.php` and are audited.

A configured policy does not delete anything by itself. Cleanup occurs only when the maintenance command runs. The same command also finalizes account-closure requests whose fixed cooling-off deadline has passed.

## Dry run

Always inspect a dry run after changing retention:

```sh
composer maintenance:dry-run
```

The command prints JSON counts for each affected table, tracked or orphaned attachment files, and due account closures. A dry run acquires the same PostgreSQL advisory lock as destructive cleanup, but does not change retained application content, attachment files, or account state.

Dry runs are recorded in `maintenance_runs`, but they do not satisfy the successful destructive-maintenance freshness check shown on `/admin-status.php`.

## Destructive cleanup

```sh
composer maintenance
```

The command:

1. records a running maintenance invocation;
2. acquires a PostgreSQL advisory lock so only one cleanup may run;
3. removes content older than the configured nonzero retention periods;
4. removes expired room-presence and SSE-connection leases;
5. removes expired or old realtime events, login attempts, and stale rate-limit rows;
6. commits database content cleanup;
7. removes attachment files whose database records were deleted;
8. removes opaque attachment files that are not referenced in PostgreSQL and are older than the configured orphan grace period;
9. finalizes due account closures by tombstoning profile credentials and releasing original usernames;
10. writes the relevant maintenance and account-lifecycle audits;
11. records success or failure, duration, and the JSON result in `maintenance_runs`.

The result field `account_closures_finalized` is a count. In dry-run mode it reports closures currently due; in destructive mode it reports closures finalized during that invocation.

Database deletion occurs before physical file removal. A file-removal failure therefore leaves an unreferenced opaque file rather than a downloadable record without a file. The next maintenance run detects the file as an orphan and retries it.

The command exits with status `3` when one or more files could not be removed. Database cleanup and account finalization may still have succeeded; inspect the JSON report, `/admin-status.php`, and filesystem permissions before rerunning.

Operational maintenance-run rows are kept for 400 days. This small operational ledger is separate from the configurable audit retention policy.

## Account-closure finalization

An account becomes ineligible for restoration as soon as its 14-day deadline passes, even if maintenance has not run yet. The next destructive maintenance invocation:

- replaces the username with a generic `Closed account #<id>` label and a random internal canonical login name;
- replaces the password hash with an unusable random credential;
- clears birth date and last-login metadata;
- removes global roles, invitations, live leases, block preferences, and login-attempt rows tied to the old canonical username;
- retains shared messages, revisions, attachment evidence, room attribution, bans, and audits under their existing retention rules;
- releases the original username for registration by another account.

See [`../api/account.md`](../api/account.md) for the complete request, restoration, and retained-data contract.

## Suggested schedule

For a single-server installation, run cleanup daily as the same operating-system account that owns the attachment directory.

Ready-to-adapt systemd examples are provided in:

```text
deploy/systemd/chitchat-maintenance.service
deploy/systemd/chitchat-maintenance.timer
```

Copy them to `/etc/systemd/system/`, adapt the user, group, installation path, PHP path, and attachment `ReadWritePaths`, then enable the timer:

```sh
sudo systemctl daemon-reload
sudo systemctl enable --now chitchat-maintenance.timer
systemctl list-timers chitchat-maintenance.timer
```

The timer runs daily at approximately 03:15 with a randomized delay and uses `Persistent=true`, so a missed run is performed after the host returns.

A cron equivalent remains valid:

```cron
17 3 * * * cd /srv/chitchat && /usr/bin/php bin/maintenance-cleanup >> var/log/maintenance.log 2>&1
```

Do not run cleanup from multiple hosts. A database advisory lock prevents concurrent cleanup, but attachment storage must also be local to or consistently mounted on the host that performs cleanup.

`MAINTENANCE_MAX_AGE_HOURS` controls when the Administrator status page and Prometheus metrics consider destructive maintenance overdue. It defaults to 26 hours, allowing a daily job modest scheduling delay.

## Retention settings

- `room_message_retention_days`: `0` keeps room messages permanently.
- `direct_message_retention_days`: `0` keeps direct messages permanently.
- `audit_retention_days`: `0` keeps audit evidence permanently.
- `deleted_attachment_retention_days`: controls physical retention after moderator or author deletion; `0` keeps files permanently.
- `orphan_attachment_grace_hours`: protects files created shortly before a failed database transaction from immediate deletion.
- `realtime_event_retention_hours`: bounds the delivery ledger. Persistent message history is stored separately.
- `login_attempt_retention_days`: bounds authentication-throttle evidence.

Lowering a retention period can make a large amount of data eligible on the next run. Take and verify a backup first.

## Request limits

Named PostgreSQL-backed request policies are documented in [`rate-limiting.md`](rate-limiting.md). Counters are shared by all PHP workers, and stale identifier/window rows are removed by maintenance after two days.
