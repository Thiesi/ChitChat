ALTER TABLE system_settings
    ADD COLUMN room_message_retention_days INTEGER NOT NULL DEFAULT 0
        CHECK (room_message_retention_days BETWEEN 0 AND 3650),
    ADD COLUMN direct_message_retention_days INTEGER NOT NULL DEFAULT 0
        CHECK (direct_message_retention_days BETWEEN 0 AND 3650),
    ADD COLUMN audit_retention_days INTEGER NOT NULL DEFAULT 0
        CHECK (audit_retention_days BETWEEN 0 AND 3650),
    ADD COLUMN deleted_attachment_retention_days INTEGER NOT NULL DEFAULT 30
        CHECK (deleted_attachment_retention_days BETWEEN 0 AND 3650),
    ADD COLUMN orphan_attachment_grace_hours INTEGER NOT NULL DEFAULT 24
        CHECK (orphan_attachment_grace_hours BETWEEN 1 AND 720),
    ADD COLUMN realtime_event_retention_hours INTEGER NOT NULL DEFAULT 168
        CHECK (realtime_event_retention_hours BETWEEN 1 AND 8760),
    ADD COLUMN login_attempt_retention_days INTEGER NOT NULL DEFAULT 30
        CHECK (login_attempt_retention_days BETWEEN 1 AND 3650);

CREATE TABLE request_rate_limits (
    scope VARCHAR(64) NOT NULL CHECK (scope ~ '^[a-z0-9_.-]{1,64}$'),
    identifier_hash CHAR(64) NOT NULL CHECK (identifier_hash ~ '^[0-9a-f]{64}$'),
    window_started_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    attempt_count INTEGER NOT NULL DEFAULT 1 CHECK (attempt_count >= 1),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (scope, identifier_hash)
);

CREATE INDEX request_rate_limits_updated
    ON request_rate_limits (updated_at);
