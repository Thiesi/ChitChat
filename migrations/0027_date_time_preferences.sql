-- Per-account date and time display. NULL means Automatic: the browser's
-- own locale and clock. The time zone always follows the device.
ALTER TABLE users
    ADD COLUMN date_locale VARCHAR(16) NULL,
    ADD COLUMN hour_cycle VARCHAR(3) NULL CHECK (hour_cycle IN ('h12', 'h23'));

COMMENT ON COLUMN users.date_locale IS
    'BCP 47 format region for dates and times (an allow-listed value such as de-DE), or NULL to follow the browser.';
COMMENT ON COLUMN users.hour_cycle IS
    'h23 for a 24-hour clock, h12 for a 12-hour clock, or NULL to follow the format region.';
