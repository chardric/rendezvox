-- Migration: Add profanity filter settings
-- Apply to existing databases that were created before this feature.

INSERT INTO settings (key, value, type, description) VALUES
    ('profanity_filter_enabled', 'true',  'boolean', 'Filter profanity in request names and messages'),
    ('profanity_custom_words',   '',      'string',  'Additional blocked words (comma-separated)')
ON CONFLICT (key) DO NOTHING;
