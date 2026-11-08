-- Person cases keep an immutable scope independently of the live room FK.
ALTER TABLE moderation_cases
    ADD COLUMN subject_kind VARCHAR(16) NOT NULL DEFAULT 'message'
        CHECK (subject_kind IN ('message', 'user')),
    ADD COLUMN scope_key VARCHAR(64) NULL,
    ALTER COLUMN message_kind DROP NOT NULL,
    ALTER COLUMN message_id DROP NOT NULL,
    DROP CONSTRAINT moderation_cases_room_reference_check,
    ADD CONSTRAINT moderation_cases_subject_reference_check CHECK (
        (subject_kind = 'message' AND message_kind IS NOT NULL AND message_id IS NOT NULL
            AND scope_key IS NULL AND (message_kind = 'room' OR room_id IS NULL))
        OR (subject_kind = 'user' AND message_kind IS NULL AND message_id IS NULL AND scope_key IS NOT NULL
            AND ((scope_key = 'global' AND room_id IS NULL)
                OR (scope_key ~ '^room:[1-9][0-9]*$' AND (room_id IS NULL OR scope_key = 'room:' || room_id::text))))
    ),
    DROP CONSTRAINT moderation_cases_resolution_code_check,
    ADD CONSTRAINT moderation_cases_resolution_code_check CHECK (resolution_code IS NULL OR resolution_code IN (
        'no_violation', 'content_removed', 'user_warned', 'account_restricted', 'other', 'profile_picture_removed'
    ));

CREATE UNIQUE INDEX moderation_cases_person_scope
    ON moderation_cases (subject_user_id, scope_key) WHERE subject_kind = 'user';
CREATE INDEX moderation_cases_person_room_queue
    ON moderation_cases (room_id, status, id DESC) WHERE subject_kind = 'user';
CREATE INDEX room_messages_reportable_person
    ON room_messages (room_id, sender_id) WHERE sender_id IS NOT NULL;

ALTER TABLE moderation_reports
    DROP CONSTRAINT moderation_reports_category_check,
    ADD CONSTRAINT moderation_reports_category_check CHECK (category IN (
        'spam', 'harassment', 'hate', 'threats', 'sexual_content', 'privacy', 'impersonation', 'other',
        'offensive_username', 'offensive_profile_picture'
    ));

CREATE TABLE moderation_user_snapshots (
    case_id BIGINT PRIMARY KEY REFERENCES moderation_cases(id) ON DELETE CASCADE,
    username VARCHAR(32) NOT NULL,
    avatar_key VARCHAR(64) NULL,
    avatar_updated_at TIMESTAMPTZ NULL,
    avatar_image BYTEA NULL CHECK (avatar_image IS NULL OR octet_length(avatar_image) BETWEEN 1 AND 131072),
    room_id BIGINT NULL,
    room_name VARCHAR(120) NULL,
    CHECK ((avatar_key IS NULL) = (avatar_image IS NULL)),
    CHECK ((room_id IS NULL) = (room_name IS NULL))
);
COMMENT ON TABLE moderation_user_snapshots IS
    'First-report name and sanitized 256px WebP only; case-authorized, max 128 KiB. Case/audit retention cascades delete the bytes. Original room context survives room purge.';
