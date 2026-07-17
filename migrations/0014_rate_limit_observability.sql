CREATE TABLE rate_limit_counters (
    scope VARCHAR(64) PRIMARY KEY CHECK (scope ~ '^[a-z0-9_.-]{1,64}$'),
    allowed_count BIGINT NOT NULL DEFAULT 0 CHECK (allowed_count >= 0),
    rejected_count BIGINT NOT NULL DEFAULT 0 CHECK (rejected_count >= 0),
    last_allowed_at TIMESTAMPTZ NULL,
    last_rejected_at TIMESTAMPTZ NULL,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

COMMENT ON TABLE rate_limit_counters IS
    'Aggregate per-policy decisions only; never stores account, IP, room, message, or request identifiers.';
