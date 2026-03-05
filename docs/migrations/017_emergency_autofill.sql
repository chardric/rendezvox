-- Emergency playlist auto-fill setting
INSERT INTO settings (key, value, type, description)
VALUES ('emergency_autofill_hours', '24', 'integer',
        'Hours of audio to auto-fill emergency playlist with on each cycle reset')
ON CONFLICT (key) DO NOTHING;
