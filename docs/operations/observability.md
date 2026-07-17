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
- the latest maintenance invocation and the latest successful destructive cleanup;
- whether destructive maintenance is overdue according to `MAINTENANCE_MAX_AGE_HOURS`.

The page never lists usernames, message bodies, attachment names, IP addresses, or bearer secrets. The underlying API is `GET /api/v1/admin/system-status.php` and requires Administrator access.

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

`MAINTENANCE_MAX_AGE_HOURS` defaults to `26`. The status page and metrics mark maintenance overdue when no successful destructive run exists within that window. Dry runs do not satisfy the destructive-maintenance freshness check.

## Prometheus endpoint

`/metrics.php` is disabled unless `METRICS_BEARER_TOKEN` is configured. The token must contain at least 24 characters.

When disabled, the endpoint returns HTTP 404. When enabled, requests require:

```text
Authorization: Bearer <configured token>
```

Incorrect or missing credentials return HTTP 401. Successful responses use the Prometheus text exposition format and include gauges for database latency and size, attachment metadata and capacity, SSE and presence leases, retained events, failed logins, rate-limit rows, and maintenance freshness.

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
- attachment filesystem free space below the operator's safety threshold;
- sustained growth in `chitchat_failed_logins_24h`;
- unexpected disappearance of all SSE connections during known active periods;
- PostgreSQL status-query latency materially above the installation's normal baseline.

Thresholds depend on deployment size and usage. Establish a baseline before treating connection or row counts as incidents.
