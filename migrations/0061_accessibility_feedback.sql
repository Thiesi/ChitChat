-- Where "Report an accessibility problem" leads (#219), an Operational setting.
--
-- enabled = FALSE: no link anywhere. enabled = TRUE with url NULL (the default): the project's
-- GitHub issue form, so an upgrade gives every installation a working link. Otherwise url is the
-- operator's own https: page or mailto: address. Switching the link off keeps a saved url.
-- The CHECK is the database's floor: lowercase https:// or mailto:, printable ASCII only (so no
-- spaces or control characters), at most 2048 characters. PHP validates further (a host for
-- https, one valid address for mailto).
ALTER TABLE system_settings
    ADD COLUMN accessibility_feedback_enabled boolean NOT NULL DEFAULT TRUE,
    ADD COLUMN accessibility_feedback_url text NULL,
    ADD CONSTRAINT system_settings_accessibility_feedback_url_check
        CHECK (accessibility_feedback_url IS NULL OR (
            char_length(accessibility_feedback_url) <= 2048
            AND accessibility_feedback_url ~ '^(https://|mailto:)[!-~]+$'
        ));
