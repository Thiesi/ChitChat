-- Public-client browser handoff. Only proof staging belongs here; never provider tokens or PHP SIDs.
CREATE TABLE native_auth_transactions (
    id text PRIMARY KEY CHECK (id ~ '^[A-Za-z0-9_-]{43}$'),
    native_binding_hash text NOT NULL CHECK (native_binding_hash ~ '^[0-9a-f]{64}$'),
    browser_binding_hash text CHECK (browser_binding_hash ~ '^[0-9a-f]{64}$'),
    entry_hash text CHECK (entry_hash ~ '^[0-9a-f]{64}$'),
    bootstrap_hash text CHECK (bootstrap_hash ~ '^[0-9a-f]{64}$'),
    state_hash text NOT NULL CHECK (state_hash ~ '^[0-9a-f]{64}$'),
    return_state text CHECK (return_state ~ '^[A-Za-z0-9_-]{32,128}$'),
    code_challenge text NOT NULL CHECK (code_challenge ~ '^[A-Za-z0-9_-]{43}$'),
    code_hash text CHECK (code_hash ~ '^[0-9a-f]{64}$'),
    code_expires_at timestamptz,
    client_id text NOT NULL CHECK (octet_length(client_id) <= 255),
    redirect_uri text NOT NULL CHECK (octet_length(redirect_uri) <= 300),
    config_hash text NOT NULL CHECK (config_hash ~ '^[0-9a-f]{64}$'),
    provider text NOT NULL CHECK (provider IN ('google','twitch')),
    purpose text NOT NULL CHECK (purpose IN ('login','link','step_up','restore','picture')),
    user_id bigint REFERENCES users(id) ON DELETE SET NULL,
    user_version integer,
    subject_hash text CHECK (subject_hash ~ '^[0-9a-f]{64}$'),
    proof jsonb CHECK (jsonb_typeof(proof) = 'object' AND octet_length(proof::text) <= 2048),
    picture bytea CHECK (octet_length(picture) <= 5242880),
    phase text NOT NULL DEFAULT 'entry'
        CHECK (phase IN ('entry','verifying','consent','account_mismatch','identity_mismatch',
                         'staged','consumed','cancelled','superseded','expired')),
    outcome text CHECK (outcome IN ('completed','pending_mfa','pending_sign_up','unknown')),
    active boolean NOT NULL DEFAULT TRUE,
    mismatch_acknowledged boolean NOT NULL DEFAULT FALSE,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    expires_at timestamptz NOT NULL,
    finished_at timestamptz,
    CHECK (expires_at > created_at AND expires_at <= created_at + interval '10 minutes'),
    CHECK ((phase = 'staged') = (code_hash IS NOT NULL)),
    CHECK ((code_hash IS NULL) = (code_expires_at IS NULL)),
    CHECK (code_expires_at IS NULL OR (code_expires_at <= expires_at AND code_expires_at <= created_at + interval '10 minutes')),
    CHECK (picture IS NULL OR (purpose = 'picture' AND active)),
    CHECK (bootstrap_hash IS NULL OR (active AND browser_binding_hash IS NULL))
);
CREATE UNIQUE INDEX native_auth_one_active_binding ON native_auth_transactions(native_binding_hash) WHERE active;
CREATE UNIQUE INDEX native_auth_one_pending_picture ON native_auth_transactions(user_id)
    WHERE active AND picture IS NOT NULL;
CREATE INDEX native_auth_expiry ON native_auth_transactions(expires_at);
CREATE INDEX native_auth_finished ON native_auth_transactions(finished_at) WHERE finished_at IS NOT NULL;
COMMENT ON TABLE native_auth_transactions IS
    'Bounded native public-client transactions; hashed grants, temporary verified proof, private picture bytes and ID/outcome-only tombstones.';
