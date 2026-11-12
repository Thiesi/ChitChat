-- A moderator can replace an offensive username with a neutral placeholder (#164).
-- The account keeps reading and signing in, but must choose a new name before writing.
ALTER TABLE users
    ADD COLUMN username_change_required_at TIMESTAMPTZ NULL,
    ADD COLUMN username_change_reason VARCHAR(500) NULL,
    ADD COLUMN username_change_previous_canonical VARCHAR(32) NULL,
    ADD CONSTRAINT users_username_change_complete CHECK (
        (username_change_required_at IS NULL AND username_change_reason IS NULL
            AND username_change_previous_canonical IS NULL)
        OR (username_change_required_at IS NOT NULL AND username_change_reason IS NOT NULL
            AND username_change_previous_canonical IS NOT NULL)
    );
-- Partial, so it is not a key for row-lock purposes; the old name still signs in while pending.
CREATE UNIQUE INDEX users_username_change_previous
    ON users (username_change_previous_canonical)
    WHERE username_change_previous_canonical IS NOT NULL;
COMMENT ON COLUMN users.username_change_previous_canonical IS
    'Removed name, accepted for sign-in only until a new name is chosen; reserved in username_reservations meanwhile.';

CREATE TABLE username_reservations (
    username_canonical VARCHAR(32) PRIMARY KEY,
    user_id BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
    -- NULL while the account still has to choose its new name: held whatever the setting.
    reserved_until TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX username_reservations_expiry ON username_reservations (reserved_until)
    WHERE reserved_until IS NOT NULL;
COMMENT ON TABLE username_reservations IS
    'Names removed by moderators; unclaimable while the change is pending and for username_reservation_days after. No reason is stored here (see audit_log). Maintenance removes expired rows.';

ALTER TABLE system_settings
    ADD COLUMN username_reservation_days INTEGER NOT NULL DEFAULT 365
        CHECK (username_reservation_days BETWEEN 0 AND 3650);

ALTER TABLE moderation_cases
    DROP CONSTRAINT moderation_cases_resolution_code_check,
    ADD CONSTRAINT moderation_cases_resolution_code_check CHECK (resolution_code IS NULL OR resolution_code IN (
        'no_violation', 'content_removed', 'user_warned', 'account_restricted', 'other', 'profile_picture_removed',
        'username_change_required'
    ));

ALTER TABLE account_notifications DROP CONSTRAINT account_notifications_kind_check;
ALTER TABLE account_notifications ADD CONSTRAINT account_notifications_kind_check CHECK (kind IN (
    'revision_review', 'moderator_message_deleted', 'admin_password_reset',
    'system_policy_changed', 'mentioned', 'pinged', 'room_deleted', 'room_restored',
    'avatar_removed', 'muted', 'guest_welcome', 'privacy_policy_updated', 'direct_message_received',
    'username_change_required'
));
-- Renames rewrite the stored sender_username snapshot of mentions and pings while the account row is locked.
-- jsonb rather than a bigint cast, so no historic context can make the index build fail.
CREATE INDEX account_notifications_sender
    ON account_notifications ((context_json->'sender_user_id'))
    WHERE context_json ? 'sender_username';

-- Every connection learns the new name of a renamed account at once.
ALTER TABLE realtime_events
    DROP CONSTRAINT IF EXISTS realtime_events_event_type_check;
ALTER TABLE realtime_events
    ADD CONSTRAINT realtime_events_event_type_check CHECK (event_type IN (
        'room_message',
        'message_deleted',
        'ping',
        'room_broadcast',
        'global_broadcast',
        'forced_logout',
        'presence_changed',
        'direct_message',
        'message_reaction_changed',
        'rooms_changed',
        'typing',
        'user_renamed'
    ));
