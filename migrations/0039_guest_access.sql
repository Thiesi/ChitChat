-- Guest access: visitors can look around without an account. A guest is a
-- real users row, so messages, presence and moderation work unchanged. It
-- is named "Guest NNNN" (a space, which real usernames can never contain),
-- has no password, birth date or roles, and lasts one browser session: it
-- ends after two idle hours, after 24 hours at most, when the guest leaves,
-- or when a moderator ends it. An ended guest becomes a closed account that
-- keeps its name, so its messages stay readable.
ALTER TABLE users
    ADD COLUMN account_kind VARCHAR(16) NOT NULL DEFAULT 'member'
        CHECK (account_kind IN ('member', 'guest')),
    ADD COLUMN guest_expires_at TIMESTAMPTZ NULL,
    ADD COLUMN guest_last_seen_at TIMESTAMPTZ NULL,
    ADD COLUMN guest_ip_address VARCHAR(64) NULL,
    ADD CONSTRAINT users_guest_fields_check CHECK (
        (account_kind = 'member' AND guest_expires_at IS NULL AND guest_last_seen_at IS NULL AND guest_ip_address IS NULL)
        OR (account_kind = 'guest' AND guest_expires_at IS NOT NULL AND guest_last_seen_at IS NOT NULL)
    );

CREATE INDEX users_active_guests ON users (guest_expires_at, guest_last_seen_at)
    WHERE account_kind = 'guest' AND account_state = 'active';
CREATE INDEX users_active_guests_by_ip ON users (guest_ip_address)
    WHERE account_kind = 'guest' AND account_state = 'active';

COMMENT ON COLUMN users.guest_ip_address IS
    'Only for active guests, to cap and block guest sessions per connection; cleared when the guest ends.';

-- Guest numbers are never reused, so "Guest 0012" today is never mistaken
-- for yesterday's.
CREATE SEQUENCE guest_number_seq START 1;

-- A Super-Administrator switches guest access on; off ends every guest.
ALTER TABLE system_settings
    ADD COLUMN guest_access_enabled BOOLEAN NOT NULL DEFAULT FALSE;

-- Per room: guests may not enter, may read, or may read and write. Only
-- public rooms without a minimum age can open to guests (guests have no
-- birth date).
ALTER TABLE rooms
    ADD COLUMN guest_access VARCHAR(8) NOT NULL DEFAULT 'none'
        CHECK (guest_access IN ('none', 'read', 'write')),
    ADD CONSTRAINT rooms_guest_access_scope_check CHECK (
        guest_access = 'none' OR (visibility = 'public' AND minimum_age = 0)
    );

-- "Block guests from this connection": no new guest sessions from one IP
-- address for a while. Moderators never see the address itself.
CREATE TABLE guest_blocks (
    id BIGSERIAL PRIMARY KEY,
    ip_address VARCHAR(64) NOT NULL,
    created_by BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
    reason VARCHAR(500) NOT NULL DEFAULT '',
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    expires_at TIMESTAMPTZ NOT NULL,
    CHECK (expires_at > created_at)
);

CREATE INDEX guest_blocks_current ON guest_blocks (ip_address, expires_at);

-- A guest is greeted with a pointer to registration.
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
        'avatar_removed',
        'muted',
        'guest_welcome'
    ));
