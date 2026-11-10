-- Selection metadata belongs to the current publication, not immutable image bytes.
ALTER TABLE system_settings
    ADD COLUMN application_icon_preset text
        CHECK (application_icon_preset IN ('lantern-paper','lounge','dusk','ember','rose','voices','spark','initial')),
    ADD COLUMN application_icon_initial text
        CHECK (char_length(application_icon_initial) = 1),
    ADD CONSTRAINT application_icon_preset_has_generation
        CHECK (application_icon_preset IS NULL OR application_icon_revision IS NOT NULL),
    ADD CONSTRAINT application_icon_initial_matches_selection
        CHECK ((application_icon_preset IS NOT DISTINCT FROM 'initial' AND application_icon_initial IS NOT NULL)
            OR (application_icon_preset IS DISTINCT FROM 'initial' AND application_icon_initial IS NULL));
