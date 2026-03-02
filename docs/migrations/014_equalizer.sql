-- 014_equalizer.sql — Add audio equalizer settings

INSERT INTO settings (key, value, type, description) VALUES
    ('eq_preset', 'flat', 'string', 'Active equalizer preset (flat, bass_boost, treble_boost, vocal, rock, pop, jazz, classical, loudness, custom)'),
    ('eq_bands', '{"32":0,"64":0,"125":0,"250":0,"500":0,"1000":0,"2000":0,"4000":0,"8000":0,"16000":0}', 'json', 'Equalizer band gains in dB (-12 to +12)')
ON CONFLICT (key) DO NOTHING;
