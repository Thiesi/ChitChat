# Operational observability

ChitChat exposes one human-facing status page and one optional machine-facing metrics endpoint. Both use the same server-side snapshot so their measurements cannot drift.

## Administrator system status

Administrators and Super-Administrators can open `/admin-status.php` from the administration header. The page reports:

- application version and environment;
- PostgreSQL database size and status-query latency;
- active leased SSE connections and distinct SSE users;
- active room-presence leases and distinct present users;
- retained realtime-event rows;
- active and soft-deleted attachment metadata, tracked bytes, and filesystem capacity;
- failed login attempts recorded in the previous 24 hours;
- current request-rate-limit rows;
- effective named rate-limit maxima and windows;
- aggregate allowed/rejected rate-limit decisions and last-decision timestamps by fixed policy name;
- the latest maintenance invocation and the latest successful destructive cleanup;
- whether destructive maintenance is overdue according to the maintenance max age;
- the Web Push delivery queue and whether a due push is overdue (see [Push queue health](#push-queue-health));
- the latest recorded backup run, the latest successful one, and whether backups are overdue (see [Backup freshness](#backup-freshness)).

The page never lists usernames, message bodies, attachment names, IP addresses, rate-limit identifier hashes, or bearer secrets. The underlying API is `GET /api/v1/admin/system-status.php` and requires Administrator access.

See [`rate-limiting.md`](rate-limiting.md) for policy configuration, bounds, coverage, and privacy behavior.

## SSE connection leases

Every authenticated SSE request creates a database lease in `sse_connections`. The stream refreshes that lease periodically and deletes it on a clean close. If a worker, client, or network path disappears without cleanup, the lease expires naturally and maintenance removes the stale row.

`SSE_CONNECTION_LEASE_SECONDS` defaults to `40` and accepts `20` through `300`. It should remain comfortably longer than the ten-second stream heartbeat interval.

The lease is an operational estimate, not a billing or forensic record. A reconnect creates a new connection ID, and expired rows are intentionally deleted.

## Maintenance status

Every invocation through `composer maintenance`, `composer maintenance:dry-run`, or `bin/maintenance-cleanup` creates a `maintenance_runs` row before cleanup begins. The row records:

- dry-run or destructive mode;
- running, success, or failure status;
- start and finish timestamps;
- duration in milliseconds;
- the successful JSON result or a bounded failure message.

The operational ledger is separate from `audit_log`. Successful destructive cleanup still writes `maintenance.cleanup` to the audit log. Maintenance removes operational run rows older than 400 days.

The maintenance max age is set in Operational settings › Health checks › **Maintenance maximum age, in hours** (`maintenance_max_age_hours`, 1–720). Until a value is saved there, or after **Use the server default** clears it, `MAINTENANCE_MAX_AGE_HOURS` (default `26`) is the fallback default (#312). `maintenance.maximum_age_hours` in the status snapshot is the effective value, and `maintenance.maximum_age_source` says which one applies: `setting` or `environment`. The status page and metrics mark maintenance overdue when no successful destructive run exists within that window. Dry runs do not satisfy the destructive-maintenance freshness check.

## Push queue health

`push` in the status snapshot describes the Web Push delivery queue (#310):

- `configured`: whether VAPID keys are set. Push that isn't configured is a neutral state (`status: "not_configured"`), never an error.
- `pending_deliveries`, `due_deliveries`: deliveries not yet sent, and those of them that are due now.
- `failed_deliveries`: deliveries that gave up, within the retained delivery history.
- `oldest_pending_seconds`: the age of the oldest pending delivery, counted from when it was queued. This includes deliveries deliberately waiting for a retry, so it can be large without anything being wrong.
- `oldest_due_seconds`: how long the oldest delivery that is already due has waited, or `null` when nothing is due.
- `last_sent_at`: when a push was last sent. This is for information only.
- `overdue` (`status: "overdue"`): a due delivery has waited longer than `overdue_after_seconds` (300 seconds). The cron sweep runs every minute and the `--listen` worker sweeps every 15 seconds, so a due push waiting five minutes means neither is working.

**A stopped dispatcher only shows once something is due.** With nothing to send, nothing is lost, so the page stays `ok`. The first push that then waits past the threshold turns it `overdue`. There is no separate heartbeat.

## Backup freshness

`bin/chitchat-backup` records each run in `backup_runs` (#310). It records the start and end time, the outcome, live or offline consistency, the set's size, the backup set's directory name (never its path), and on failure a short category: `backup_failed` or `unexpected`, never the message. The newest 100 runs are kept. Nothing in the table identifies a person.

**Recording never affects the backup.** The run is recorded after the dump, on its own short connection and transaction. If that fails (a read-only or broken database, a backup role without `INSERT` on `backup_runs`, a timeout), the command prints one line, `Warning: backup run not recorded (…)`, to stderr. Its exit code and output, including `--json`, stay exactly as they would have been. A backup of a database that can't be written therefore still works; its run is just missing.

`backups` in the status snapshot reports:

- `tracking_enabled` and `maximum_age_hours`: from `backup_max_age_hours`, set in Operational settings › Health checks › **Backup maximum age, in hours** (0–720, default 26). `0` turns tracking off.
- `latest_run` and `latest_success`: the most recent recorded run and the most recent successful one.
- `latest_success_age_seconds`.
- `overdue`: tracking is on and no recorded successful run exists within the maximum age.
- `status`, in this order:
  1. `not_tracked`;
  2. `unknown`: no run recorded, either never run or never recordable. It is also overdue, so a database that can't record runs never looks fine;
  3. `failed`: the latest recorded run failed;
  4. `overdue`;
  5. `ok`.

A run is recorded after its own dump, so it isn't inside that backup. After a restore, the table shows the restored database's earlier history. Only runs through `bin/chitchat-backup` are recorded; backups made another way (for example `pg_dump` alone) leave the status `unknown`.

## Prometheus endpoint

`/metrics.php` is disabled unless `METRICS_BEARER_TOKEN` is configured. The token must contain at least 24 characters.

When disabled, the endpoint returns HTTP 404. When enabled, requests require:

```text
Authorization: Bearer <configured token>
```

Incorrect or missing credentials return HTTP 401. Successful responses use the Prometheus text exposition format and include gauges for database latency and size, attachment metadata and capacity, SSE and presence leases, retained events, failed logins, rate-limit rows, maintenance freshness, the push queue and backup freshness. Monotonic rate-limit counters are exported as:

```text
chitchat_rate_limit_decisions_total{policy="room_send",outcome="allowed"} 123
chitchat_rate_limit_decisions_total{policy="room_send",outcome="rejected"} 4
```

The policy label is selected from a fixed application-defined list rather than user input.

Example Prometheus configuration:

```yaml
scrape_configs:
  - job_name: chitchat
    scheme: https
    metrics_path: /metrics.php
    static_configs:
      - targets: [chat.example.org]
    authorization:
      type: Bearer
      credentials: replace-with-the-configured-secret
```

Use a randomly generated secret, keep it out of the repository, and restrict `/metrics.php` at the reverse proxy or network layer as well. The application-level token is a second boundary, not a substitute for HTTPS and network policy.

The endpoint deliberately exports aggregate operational values only. It does not expose usernames, room names, message content, attachment names, IP addresses, database credentials, filesystem paths, or inspection policy secrets.

## Alert suggestions

Useful initial alerts include:

- `chitchat_maintenance_overdue == 1`;
- `chitchat_push_overdue == 1` (a due push has waited over 300 seconds);
- `chitchat_backup_overdue == 1`, or `time() - chitchat_backup_last_success_timestamp_seconds` above your own threshold;
- `chitchat_backup_last_run_success == 0` (the latest recorded backup failed);
- attachment filesystem free space below the operator's safety threshold;
- sustained growth in `chitchat_failed_logins_24h`;
- an unexpected increase in `chitchat_rate_limit_decisions_total{outcome="rejected"}` for login, step-up, inspection, or revision review;
- unexpected disappearance of all SSE connections during known active periods;
- PostgreSQL status-query latency materially above the installation's normal baseline.

Push and backup metrics: `chitchat_push_configured`, `chitchat_push_deliveries{state="pending|due|failed"}`, `chitchat_push_oldest_due_seconds`, `chitchat_push_oldest_pending_seconds`, `chitchat_push_overdue`, `chitchat_push_last_sent_timestamp_seconds`, `chitchat_backup_tracking_enabled`, `chitchat_backup_overdue`, `chitchat_backup_last_run_success`, `chitchat_backup_last_run_timestamp_seconds` and `chitchat_backup_last_success_timestamp_seconds`. Timestamps are `0` when there is none, as for maintenance.

Thresholds depend on deployment size and usage. Establish a baseline before treating connection or row counts as incidents.
