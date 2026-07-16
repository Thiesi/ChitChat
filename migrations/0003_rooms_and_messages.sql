ALTER TABLE users
    ADD COLUMN birth_date DATE NULL,
    ADD CONSTRAINT users_birth_date_not_future CHECK (birth_date IS NULL OR birth_date <= CURRENT_DATE);

CREATE TABLE rooms (
    id BIGSERIAL PRIMARY KEY,
    room_key VARCHAR(48) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL,
    info_line VARCHAR(255) NOT NULL DEFAULT '',
    visibility VARCHAR(16) NOT NULL CHECK (visibility IN ('public', 'unlisted', 'private')),
    minimum_age SMALLINT NOT NULL DEFAULT 0 CHECK (minimum_age BETWEEN 0 AND 120),
    created_by BIGINT NOT NULL REFERENCES users(id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    deleted_at TIMESTAMPTZ NULL
);

CREATE INDEX rooms_visibility_active ON rooms (visibility, id) WHERE deleted_at IS NULL;

CREATE TABLE room_members (
    room_id BIGINT NOT NULL REFERENCES rooms(id) ON DELETE CASCADE,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role VARCHAR(16) NOT NULL CHECK (role IN ('member', 'moderator', 'owner')),
    joined_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (room_id, user_id)
);

CREATE INDEX room_members_user ON room_members (user_id, room_id);

CREATE TABLE room_invitations (
    room_id BIGINT NOT NULL REFERENCES rooms(id) ON DELETE CASCADE,
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    invited_by BIGINT NOT NULL REFERENCES users(id),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (room_id, user_id)
);

CREATE INDEX room_invitations_user ON room_invitations (user_id, room_id);

CREATE TABLE room_messages (
    id BIGSERIAL PRIMARY KEY,
    room_id BIGINT NOT NULL REFERENCES rooms(id) ON DELETE CASCADE,
    sender_id BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
    message_type VARCHAR(16) NOT NULL CHECK (message_type IN ('text', 'emote', 'system')),
    body TEXT NOT NULL CHECK (char_length(body) BETWEEN 1 AND 4000),
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    deleted_at TIMESTAMPTZ NULL,
    deleted_by BIGINT NULL REFERENCES users(id)
);

CREATE INDEX room_messages_history ON room_messages (room_id, id DESC);
