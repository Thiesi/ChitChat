-- Two-stage room deletion. Deleting a room hides it immediately and keeps
-- everything restorable; maintenance permanently removes it after a grace
-- period. 0 keeps deleted rooms until they are restored or removed by hand.
ALTER TABLE system_settings
    ADD COLUMN deleted_room_grace_days INTEGER NOT NULL DEFAULT 30
        CHECK (deleted_room_grace_days BETWEEN 0 AND 3650);

COMMENT ON COLUMN system_settings.deleted_room_grace_days IS
    'Days a deleted room stays restorable before maintenance permanently removes it with its messages, attachments, memberships and pings. 0 keeps deleted rooms indefinitely.';

-- Moderation evidence outlives a purged room: the case keeps its report
-- snapshots and loses only the room reference (ON DELETE SET NULL), which the
-- original check did not allow for room cases.
DO $$
DECLARE
    constraint_name TEXT;
BEGIN
    SELECT conname INTO constraint_name
    FROM pg_constraint
    WHERE conrelid = 'moderation_cases'::regclass
      AND contype = 'c'
      AND pg_get_constraintdef(oid) LIKE '%room_id IS NOT NULL%';
    IF constraint_name IS NOT NULL THEN
        EXECUTE format('ALTER TABLE moderation_cases DROP CONSTRAINT %I', constraint_name);
    END IF;
END;
$$;

ALTER TABLE moderation_cases
    ADD CONSTRAINT moderation_cases_room_reference_check
        CHECK (message_kind = 'room' OR room_id IS NULL);

COMMENT ON COLUMN moderation_cases.room_id IS
    'The room of a reported room message. NULL for direct messages, and for room cases whose room has since been permanently removed; the report evidence snapshots remain.';

-- Members hear when a room they belong to is deleted or restored.
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
        'room_restored'
    ));

CREATE INDEX rooms_deleted_at ON rooms (deleted_at) WHERE deleted_at IS NOT NULL;
