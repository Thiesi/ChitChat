ALTER TABLE account_notifications DROP CONSTRAINT account_notifications_kind_check;
ALTER TABLE account_notifications ADD CONSTRAINT account_notifications_kind_check CHECK (kind IN (
    'revision_review', 'moderator_message_deleted', 'admin_password_reset',
    'system_policy_changed', 'mentioned', 'pinged', 'room_deleted', 'room_restored',
    'avatar_removed', 'muted', 'guest_welcome', 'privacy_policy_updated', 'direct_message_received'
));
ALTER TABLE notification_preferences DROP CONSTRAINT notification_preferences_category_check;
ALTER TABLE notification_preferences ADD CONSTRAINT notification_preferences_category_check
    CHECK (category IN ('mentioned', 'pinged', 'direct_message_received'));
COMMENT ON TABLE notification_preferences IS
    'Per-account push choices: absent mentioned/pinged means enabled; absent direct_message_received means disabled.';

CREATE UNIQUE INDEX account_notifications_direct_received
    ON account_notifications (user_id, (context_json->>'message_id'))
    WHERE kind = 'direct_message_received';

-- Selection is an ID snapshot, not another cascading link back to the timeline.
-- The authoritative FK-constrained source mapping is checked before each attempt.
-- Avoid a notification-delete -> job-update lock cycle with completion's job -> notification order.
ALTER TABLE push_dispatch_jobs
    ADD COLUMN selected_notification_id bigint CHECK (selected_notification_id > 0),
    ADD COLUMN selection_frozen boolean NOT NULL DEFAULT FALSE;
UPDATE push_dispatch_jobs j SET
    selected_notification_id = (SELECT MIN(notification_id) FROM push_dispatch_sources s WHERE s.job_id = j.id),
    selection_frozen = EXISTS (SELECT 1 FROM push_deliveries d WHERE d.job_id = j.id AND d.state = 'sent');
CREATE INDEX account_notifications_direct_event
    ON account_notifications (user_id, (context_json->>'message_id'))
    WHERE kind IN ('mentioned', 'direct_message_received') AND context_json->>'message_kind' = 'direct';