-- Migration: Add weather location picker settings
-- Run this on existing databases to add province/city/barangay settings.

INSERT INTO settings (key, value, type, description) VALUES
  ('weather_province',  '', 'string', 'Weather location — province'),
  ('weather_city',      '', 'string', 'Weather location — city/municipality'),
  ('weather_barangay',  '', 'string', 'Weather location — barangay')
ON CONFLICT (key) DO NOTHING;
