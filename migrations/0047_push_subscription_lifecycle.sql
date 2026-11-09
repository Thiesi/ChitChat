ALTER TABLE push_subscriptions
    ADD COLUMN session_version integer,
    ADD COLUMN session_binding_hash text,
    ADD COLUMN delivery_binding text,
    ADD COLUMN generation bigint NOT NULL DEFAULT 1 CHECK (generation > 0),
    ADD COLUMN generation_started_at timestamptz;
UPDATE push_subscriptions s SET session_version = u.session_version, generation_started_at = s.created_at
FROM users u WHERE u.id = s.user_id;
ALTER TABLE push_subscriptions ALTER COLUMN session_version SET NOT NULL,
    ALTER COLUMN generation_started_at SET NOT NULL,
    ALTER COLUMN generation_started_at SET DEFAULT clock_timestamp(),
    ADD CONSTRAINT push_session_binding_shape CHECK (session_binding_hash IS NULL OR session_binding_hash ~ '^[0-9a-f]{64}$'),
    ADD CONSTRAINT push_delivery_binding_shape CHECK (delivery_binding IS NULL OR delivery_binding ~ '^[0-9a-f]{64}$'),
    ADD CONSTRAINT push_binding_pair CHECK ((session_binding_hash IS NULL) = (delivery_binding IS NULL));
CREATE INDEX push_subscriptions_session ON push_subscriptions (user_id, session_binding_hash);
ALTER TABLE push_dispatch_jobs ADD COLUMN event_id uuid NOT NULL DEFAULT gen_random_uuid();
CREATE UNIQUE INDEX push_dispatch_event_id ON push_dispatch_jobs (event_id);
ALTER TABLE push_subscriptions ADD COLUMN device_label text CHECK (char_length(device_label) <= 64);