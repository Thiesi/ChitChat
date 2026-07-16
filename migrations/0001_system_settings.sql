CREATE TABLE system_settings (
    id SMALLINT PRIMARY KEY CHECK (id = 1),
    system_name VARCHAR(120) NOT NULL DEFAULT 'ChitChat',
    registration_enabled BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

INSERT INTO system_settings (id) VALUES (1);
