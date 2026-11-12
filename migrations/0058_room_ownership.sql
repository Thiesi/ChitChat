-- Room ownership transfer (#273).
--
-- At most one owner per room, enforced by the database. Nothing ever created a
-- second owner, but check first and stop with the rooms named rather than fail
-- half-way: the migrator runs this file in one transaction, so nothing changes.
-- Read-only pre-check for operators (must return no rows):
--   SELECT room_id, COUNT(*) AS owners, string_agg(user_id::text, ', ' ORDER BY user_id) AS owner_user_ids
--   FROM room_members WHERE role = 'owner' GROUP BY room_id HAVING COUNT(*) > 1;
DO $$
DECLARE duplicates text;
BEGIN
    SELECT string_agg(format('room %s (%s owners: user ids %s)', room_id, owners, ids), '; ' ORDER BY room_id)
    INTO duplicates
    FROM (
        SELECT room_id, COUNT(*) AS owners, string_agg(user_id::text, ', ' ORDER BY user_id) AS ids
        FROM room_members
        WHERE role = 'owner'
        GROUP BY room_id
        HAVING COUNT(*) > 1
    ) d;
    IF duplicates IS NOT NULL THEN
        RAISE EXCEPTION 'Migration 0058 stopped: these rooms have more than one owner: %. Keep one owner row per room (set the others to moderator), then run the migration again. Nothing was changed.', duplicates;
    END IF;
END $$;

CREATE UNIQUE INDEX room_members_one_owner ON room_members (room_id) WHERE role = 'owner';

-- In-app notices for the new and the previous owner.
ALTER TABLE account_notifications DROP CONSTRAINT IF EXISTS account_notifications_kind_check;
ALTER TABLE account_notifications ADD CONSTRAINT account_notifications_kind_check CHECK (kind IN (
    'revision_review', 'moderator_message_deleted', 'admin_password_reset',
    'system_policy_changed', 'mentioned', 'pinged', 'room_deleted', 'room_restored',
    'avatar_removed', 'muted', 'guest_welcome', 'privacy_policy_updated', 'direct_message_received',
    'username_change_required', 'room_ownership_received', 'room_ownership_removed'
));
