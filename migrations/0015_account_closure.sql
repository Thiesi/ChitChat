ALTER TABLE users
    ADD COLUMN account_state VARCHAR(24) NOT NULL DEFAULT 'active'
        CHECK (account_state IN ('active', 'closure_pending', 'closed')),
    ADD COLUMN closure_requested_at TIMESTAMPTZ NULL,
    ADD COLUMN closure_finalizes_at TIMESTAMPTZ NULL,
    ADD COLUMN closed_at TIMESTAMPTZ NULL,
    ADD CONSTRAINT users_account_closure_state_check CHECK (
        (account_state = 'active' AND closure_requested_at IS NULL AND closure_finalizes_at IS NULL AND closed_at IS NULL)
        OR (account_state = 'closure_pending' AND closure_requested_at IS NOT NULL AND closure_finalizes_at IS NOT NULL AND closed_at IS NULL)
        OR (account_state = 'closed' AND closure_requested_at IS NOT NULL AND closure_finalizes_at IS NOT NULL AND closed_at IS NOT NULL)
    );

CREATE INDEX users_account_state ON users (account_state, closure_finalizes_at, id);

CREATE TABLE account_closures (
    id BIGSERIAL PRIMARY KEY,
    user_id BIGINT NOT NULL REFERENCES users(id),
    requested_at TIMESTAMPTZ NOT NULL,
    finalizes_at TIMESTAMPTZ NOT NULL,
    restored_at TIMESTAMPTZ NULL,
    finalized_at TIMESTAMPTZ NULL,
    role_snapshot JSONB NOT NULL DEFAULT '[]'::jsonb,
    CHECK (jsonb_typeof(role_snapshot) = 'array'),
    CHECK (finalizes_at > requested_at),
    CHECK (NOT (restored_at IS NOT NULL AND finalized_at IS NOT NULL))
);

CREATE UNIQUE INDEX account_closures_one_pending_per_user
    ON account_closures (user_id)
    WHERE restored_at IS NULL AND finalized_at IS NULL;

CREATE INDEX account_closures_due
    ON account_closures (finalizes_at, id)
    WHERE restored_at IS NULL AND finalized_at IS NULL;

COMMENT ON TABLE account_closures IS
    'Account-closure lifecycle and role restoration metadata; does not duplicate usernames, passwords, IP addresses, or message content.';
