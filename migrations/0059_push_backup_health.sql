-- Push queue health and backup freshness on admin status (#310).
--
-- backup_runs: what bin/chitchat-backup recorded about each run, best-effort and after the
-- dump, so a backup never depends on this table (or the database) being writable. Nothing
-- here identifies a person: the set's directory name only, never its path, and a short error
-- category, never a message. The recorder keeps the newest 100 rows.
CREATE TABLE backup_runs (
    id BIGSERIAL PRIMARY KEY,
    started_at TIMESTAMPTZ NOT NULL,
    finished_at TIMESTAMPTZ NOT NULL,
    outcome VARCHAR(16) NOT NULL CHECK (outcome IN ('success', 'failure')),
    consistency VARCHAR(16) NOT NULL CHECK (consistency IN ('live', 'offline')),
    size_bytes BIGINT NULL CHECK (size_bytes IS NULL OR size_bytes >= 0),
    set_name VARCHAR(128) NULL,
    error_kind VARCHAR(32) NULL,
    application_version VARCHAR(64) NOT NULL,
    CHECK (finished_at >= started_at),
    CHECK ((outcome = 'success') = (error_kind IS NULL))
);
CREATE INDEX backup_runs_success ON backup_runs (finished_at DESC) WHERE outcome = 'success';

COMMENT ON TABLE backup_runs IS
    'Runs of bin/chitchat-backup, recorded best-effort after the dump (#310). No personal data; newest 100 kept.';

-- How old the newest successful backup may be before System status calls it overdue; 0 = don't track.
ALTER TABLE system_settings
    ADD COLUMN backup_max_age_hours INTEGER NOT NULL DEFAULT 26
        CHECK (backup_max_age_hours BETWEEN 0 AND 720);

-- Keeps "last push sent" cheap on a large delivery ledger.
CREATE INDEX push_deliveries_last_sent ON push_deliveries (completed_at DESC) WHERE state = 'sent';
