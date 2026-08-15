CREATE TABLE push_subscriptions (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    endpoint TEXT NOT NULL,
    p256dh_key TEXT NOT NULL,
    auth_key TEXT NOT NULL,
    user_agent VARCHAR(256) NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    last_used_at TIMESTAMPTZ NULL,
    UNIQUE (endpoint)
);

CREATE INDEX push_subscriptions_user
    ON push_subscriptions (user_id);

CREATE TABLE notification_preferences (
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    category VARCHAR(32) NOT NULL CHECK (category IN ('mentioned')),
    push_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    PRIMARY KEY (user_id, category)
);

ALTER TABLE users
    ADD COLUMN push_quiet_hours_start SMALLINT NULL CHECK (push_quiet_hours_start BETWEEN 0 AND 23),
    ADD COLUMN push_quiet_hours_end SMALLINT NULL CHECK (push_quiet_hours_end BETWEEN 0 AND 23),
    ADD COLUMN push_quiet_hours_timezone VARCHAR(64) NULL;

ALTER TABLE account_notifications
    ADD COLUMN push_dispatched_at TIMESTAMPTZ NULL;

CREATE INDEX account_notifications_push_pending
    ON account_notifications (id)
    WHERE push_dispatched_at IS NULL;

CREATE OR REPLACE FUNCTION clear_privacy_notifications_on_account_tombstone()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
    IF OLD.account_state <> 'closed' AND NEW.account_state = 'closed' THEN
        DELETE FROM account_notifications WHERE user_id = OLD.id;
        DELETE FROM push_subscriptions WHERE user_id = OLD.id;
        DELETE FROM notification_preferences WHERE user_id = OLD.id;
    END IF;
    RETURN NEW;
END;
$$;

COMMENT ON TABLE push_subscriptions IS
    'Browser Push API subscriptions (one row per browser/device). Cascades with its account. Endpoint uniqueness reflects the push service''s own per-registration URL.';
COMMENT ON TABLE notification_preferences IS
    'Per-account, per-category push mute state. An absent row means the category is enabled — only an explicit mute row suppresses it, so enabling push for a future category never silently opts existing accounts out.';
COMMENT ON COLUMN users.push_quiet_hours_start IS
    'Local hour (0-23) push delivery is suppressed from. NULL unless push_quiet_hours_end and push_quiet_hours_timezone are also set; enforced together in application code, matching how WEBAUTHN_RP_ID/WEBAUTHN_ORIGIN pairing is validated.';
COMMENT ON COLUMN account_notifications.push_dispatched_at IS
    'Set once a push dispatch sweep has attempted delivery for this notification, regardless of per-subscription success. Push is best-effort, not a delivery guarantee; this column is never retried once set.';
