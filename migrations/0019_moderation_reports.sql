CREATE TABLE moderation_cases (
    id BIGSERIAL PRIMARY KEY,
    message_kind VARCHAR(16) NOT NULL CHECK (message_kind IN ('room', 'direct')),
    message_id BIGINT NOT NULL,
    room_id BIGINT NULL REFERENCES rooms(id) ON DELETE SET NULL,
    subject_user_id BIGINT NOT NULL REFERENCES users(id),
    status VARCHAR(16) NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'in_review', 'resolved', 'dismissed')),
    assigned_user_id BIGINT NULL REFERENCES users(id),
    resolved_by_user_id BIGINT NULL REFERENCES users(id),
    closed_audit_id BIGINT NULL REFERENCES audit_log(id) ON DELETE CASCADE,
    resolution_code VARCHAR(32) NULL CHECK (resolution_code IS NULL OR resolution_code IN (
        'no_violation',
        'content_removed',
        'user_warned',
        'account_restricted',
        'other'
    )),
    resolution_note TEXT NULL CHECK (resolution_note IS NULL OR char_length(resolution_note) <= 1000),
    first_reported_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    last_reported_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    resolved_at TIMESTAMPTZ NULL,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (message_kind, message_id),
    CHECK ((message_kind = 'room' AND room_id IS NOT NULL) OR (message_kind = 'direct' AND room_id IS NULL)),
    CHECK ((status IN ('resolved', 'dismissed')) = (resolved_at IS NOT NULL)),
    CHECK ((status IN ('resolved', 'dismissed')) = (resolved_by_user_id IS NOT NULL)),
    CHECK (status IN ('resolved', 'dismissed') OR closed_audit_id IS NULL),
    CHECK ((status IN ('resolved', 'dismissed')) = (resolution_code IS NOT NULL))
);

CREATE TABLE moderation_reports (
    id BIGSERIAL PRIMARY KEY,
    case_id BIGINT NOT NULL REFERENCES moderation_cases(id) ON DELETE CASCADE,
    reporter_user_id BIGINT NOT NULL REFERENCES users(id),
    category VARCHAR(32) NOT NULL CHECK (category IN (
        'spam',
        'harassment',
        'hate',
        'threats',
        'sexual_content',
        'privacy',
        'impersonation',
        'other'
    )),
    details TEXT NULL CHECK (details IS NULL OR char_length(details) <= 1000),
    evidence_body TEXT NULL CHECK (evidence_body IS NULL OR char_length(evidence_body) <= 4000),
    evidence_json JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (case_id, reporter_user_id),
    CHECK (jsonb_typeof(evidence_json) = 'object')
);

CREATE INDEX moderation_cases_queue
    ON moderation_cases (status, last_reported_at DESC, id DESC);

CREATE INDEX moderation_cases_room_queue
    ON moderation_cases (room_id, status, last_reported_at DESC, id DESC)
    WHERE message_kind = 'room';

CREATE INDEX moderation_cases_subject
    ON moderation_cases (subject_user_id, id DESC);

CREATE INDEX moderation_reports_case_timeline
    ON moderation_reports (case_id, id);

CREATE OR REPLACE FUNCTION moderation_case_reset_closed_audit()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
    IF NEW.status NOT IN ('resolved', 'dismissed') THEN
        NEW.closed_audit_id := NULL;
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER moderation_case_reset_closed_audit_trigger
BEFORE UPDATE OF status ON moderation_cases
FOR EACH ROW
EXECUTE FUNCTION moderation_case_reset_closed_audit();

CREATE OR REPLACE FUNCTION moderation_case_link_closed_audit()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
BEGIN
    IF NEW.action = 'moderation.case_closed'
       AND NEW.subject_type = 'moderation_case'
       AND NEW.subject_id ~ '^[0-9]+$' THEN
        UPDATE moderation_cases
        SET closed_audit_id = NEW.id
        WHERE id = NEW.subject_id::BIGINT
          AND status IN ('resolved', 'dismissed');
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER moderation_case_link_closed_audit_trigger
AFTER INSERT ON audit_log
FOR EACH ROW
EXECUTE FUNCTION moderation_case_link_closed_audit();

CREATE OR REPLACE FUNCTION moderation_case_require_closed_audit()
RETURNS TRIGGER
LANGUAGE plpgsql
AS $$
DECLARE
    current_status VARCHAR(16);
    current_audit_id BIGINT;
BEGIN
    SELECT status, closed_audit_id
    INTO current_status, current_audit_id
    FROM moderation_cases
    WHERE id = NEW.id;

    IF FOUND
       AND current_status IN ('resolved', 'dismissed')
       AND current_audit_id IS NULL THEN
        RAISE EXCEPTION 'closed moderation case % must reference its closure audit entry', NEW.id;
    END IF;
    RETURN NULL;
END;
$$;

CREATE CONSTRAINT TRIGGER moderation_case_require_closed_audit_trigger
AFTER INSERT OR UPDATE ON moderation_cases
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW
EXECUTE FUNCTION moderation_case_require_closed_audit();

COMMENT ON TABLE moderation_cases IS
    'Authorization-scoped moderation queue cases aggregated by canonical room or direct-message ID. Closed cases follow audit retention through closed_audit_id.';
COMMENT ON TABLE moderation_reports IS
    'Participant-submitted reports with an immutable exact-message evidence snapshot and bounded free-text details.';
