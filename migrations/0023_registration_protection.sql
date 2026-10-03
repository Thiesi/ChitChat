-- Registration bot-resistance settings editable by Super-Administrators.
-- NULL means "use the server default" from the environment (or the built-in default).
ALTER TABLE system_settings
    ADD COLUMN registration_rate_limit_max_attempts INTEGER NULL
        CHECK (registration_rate_limit_max_attempts BETWEEN 1 AND 100),
    ADD COLUMN registration_rate_limit_window_seconds INTEGER NULL
        CHECK (registration_rate_limit_window_seconds BETWEEN 60 AND 86400),
    ADD COLUMN registration_min_fill_seconds INTEGER NULL
        CHECK (registration_min_fill_seconds BETWEEN 0 AND 60),
    ADD COLUMN registration_proof_of_work_bits INTEGER NULL
        CHECK (registration_proof_of_work_bits BETWEEN 0 AND 22);
