-- 015_app_version.sql — Seed app version settings
INSERT INTO settings (key, value, type, description) VALUES
  ('app_version', '1.0.0', 'string', 'Current published app version'),
  ('app_changelog', '', 'string', 'Changelog for current version')
ON CONFLICT (key) DO NOTHING;
