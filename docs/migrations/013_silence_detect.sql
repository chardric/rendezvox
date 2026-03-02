-- 013_silence_detect.sql — Add cue-in/cue-out silence detection columns

ALTER TABLE songs ADD COLUMN cue_in  NUMERIC(8,3) DEFAULT NULL;
ALTER TABLE songs ADD COLUMN cue_out NUMERIC(8,3) DEFAULT NULL;

INSERT INTO settings (key, value, type, description)
VALUES ('auto_silence_detect_enabled', 'true', 'boolean', 'Automatically detect silence cue points for new songs')
ON CONFLICT (key) DO NOTHING;
