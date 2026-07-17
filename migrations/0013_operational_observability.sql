BEGIN;

CREATE TABLE maintenance_runs (
    id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    dry_run boolean NOT NULL,
    status text NOT NULL CHECK (status IN ('running', 'success', 'failure')),
    started_at timestamptz NOT NULL DEFAULT NOW(),
    finished_at timestamptz NULL,
    duration_ms bigint NULL CHECK (duration_ms IS NULL OR duration_ms >= 0),
    result_json jsonb NULL,
    error_message text NULL
);

CREATE INDEX maintenance_runs_started_at_idx
    ON maintenance_runs (started_at DESC, id DESC);

CREATE INDEX maintenance_runs_success_idx
    ON maintenance_runs (finished_at DESC, id DESC)
    WHERE status = 'success' AND dry_run = FALSE;

CREATE TABLE sse_connections (
    connection_id uuid PRIMARY KEY,
    user_id bigint NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    opened_at timestamptz NOT NULL DEFAULT NOW(),
    last_seen_at timestamptz NOT NULL DEFAULT NOW(),
    lease_expires_at timestamptz NOT NULL
);

CREATE INDEX sse_connections_active_idx
    ON sse_connections (lease_expires_at, user_id);

COMMIT;
