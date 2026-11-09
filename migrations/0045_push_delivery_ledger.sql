-- Delivery attempts are durable; source notifications and their timeline remain authoritative.
CREATE TABLE push_dispatch_jobs (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    event_key VARCHAR(96) NOT NULL,
    expires_at TIMESTAMPTZ NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    claim_token CHAR(64) NULL,
    lease_until TIMESTAMPTZ NULL,
    finished_at TIMESTAMPTZ NULL,
    outcome VARCHAR(32) NULL,
    UNIQUE (user_id, event_key),
    CHECK ((claim_token IS NULL) = (lease_until IS NULL)),
    CHECK ((finished_at IS NULL) = (outcome IS NULL))
);
CREATE TABLE push_dispatch_sources (
    notification_id BIGINT PRIMARY KEY REFERENCES account_notifications(id) ON DELETE CASCADE,
    job_id BIGINT NOT NULL REFERENCES push_dispatch_jobs(id) ON DELETE CASCADE
);
CREATE INDEX push_dispatch_sources_job ON push_dispatch_sources(job_id);
CREATE TABLE push_deliveries (
    id BIGSERIAL PRIMARY KEY,
    job_id BIGINT NOT NULL REFERENCES push_dispatch_jobs(id) ON DELETE CASCADE,
    subscription_id BIGINT NULL REFERENCES push_subscriptions(id) ON DELETE SET NULL,
    subscription_fingerprint CHAR(64) NOT NULL,
    state VARCHAR(16) NOT NULL DEFAULT 'pending'
        CHECK (state IN ('pending', 'sent', 'suppressed', 'expired', 'failed')),
    attempts SMALLINT NOT NULL DEFAULT 0 CHECK (attempts BETWEEN 0 AND 5),
    next_attempt_at TIMESTAMPTZ NOT NULL,
    completed_at TIMESTAMPTZ NULL,
    outcome VARCHAR(32) NULL,
    http_status SMALLINT NULL CHECK (http_status BETWEEN 100 AND 599),
    UNIQUE (job_id, subscription_id, subscription_fingerprint),
    CHECK ((state = 'pending' AND completed_at IS NULL AND outcome IS NULL)
        OR (state <> 'pending' AND completed_at IS NOT NULL AND outcome IS NOT NULL))
);
CREATE INDEX push_dispatch_jobs_pending ON push_dispatch_jobs(id) WHERE finished_at IS NULL;
CREATE INDEX push_deliveries_due ON push_deliveries(next_attempt_at, job_id) WHERE state = 'pending';
ALTER TABLE account_notifications ADD COLUMN push_outcome VARCHAR(32) NULL;
COMMENT ON COLUMN account_notifications.push_dispatched_at IS
    'Terminal push processing time: accepted, suppressed, expired or failed on all snapshotted subscriptions. Not a delivery/read receipt.';
COMMENT ON TABLE push_deliveries IS
    'Per-device push outcomes without routing secrets or content; successful recipients are never retried. Ambiguous provider acceptance can still duplicate an alert.';
