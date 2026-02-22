-- 008: Add emergency_auto_activated setting
-- Tracks whether emergency mode was auto-activated by a schedule gap
-- (as opposed to manually toggled by an admin).

INSERT INTO settings (key, value, type, description) VALUES
    ('emergency_auto_activated', 'false', 'boolean', 'True when emergency was auto-activated by schedule gap')
ON CONFLICT (key) DO NOTHING;
