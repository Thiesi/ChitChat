-- Profile pictures. The server re-encodes every upload to a 256x256 WebP
-- (stripping metadata such as GPS) and stores it in attachment storage under
-- a random key, so backups and the orphan sweep treat it like an attachment.
ALTER TABLE users
    ADD COLUMN avatar_key CHAR(64) NULL UNIQUE CHECK (avatar_key IS NULL OR avatar_key ~ '^[0-9a-f]{64}$'),
    ADD COLUMN avatar_updated_at TIMESTAMPTZ NULL;

COMMENT ON COLUMN users.avatar_key IS
    'Storage key of the account''s re-encoded 256x256 WebP avatar in attachment storage, or NULL for initials.';

-- A moderator removing someone's avatar tells them so.
ALTER TABLE account_notifications
    DROP CONSTRAINT IF EXISTS account_notifications_kind_check;

ALTER TABLE account_notifications
    ADD CONSTRAINT account_notifications_kind_check CHECK (kind IN (
        'revision_review',
        'moderator_message_deleted',
        'admin_password_reset',
        'system_policy_changed',
        'mentioned',
        'pinged',
        'room_deleted',
        'room_restored',
        'avatar_removed'
    ));
