-- 015_tts.sql — Add TTS (Text-to-Speech) settings

INSERT INTO settings (key, value, type, description) VALUES
    ('tts_voice',                      'male',     'string',  'TTS voice (male or female)'),
    ('tts_speed',                      '160',      'integer', 'TTS speech rate in words per minute (80-300)'),
    ('tts_pitch',                      '50',       'integer', 'TTS voice pitch (0-99)'),
    ('tts_song_announce_enabled',      'false',    'boolean', 'Announce song title/artist before playback'),
    ('tts_song_announce_template',     'Now playing {title} by {artist}', 'string', 'Song announcement template'),
    ('tts_time_enabled',               'false',    'boolean', 'Enable periodic time announcements'),
    ('tts_time_interval_minutes',      '60',       'integer', 'Time announcement interval in minutes'),
    ('tts_time_template',              'The time is {time}', 'string', 'Time announcement template'),
    ('tts_weather_enabled',            'false',    'boolean', 'Enable periodic weather announcements'),
    ('tts_weather_interval_minutes',   '60',       'integer', 'Weather announcement interval in minutes'),
    ('tts_weather_template',           '{temperature} degrees, {description}', 'string', 'Weather announcement template')
ON CONFLICT (key) DO NOTHING;

ALTER TABLE rotation_state
    ADD COLUMN IF NOT EXISTS last_time_tts_at    TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS last_weather_tts_at TIMESTAMPTZ;
