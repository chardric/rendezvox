-- Migration 010: Rename "jingles" to "station IDs" throughout the database
-- Apply: psql -U rendezvox -d rendezvox -f 010_rename_jingles_station_ids.sql

BEGIN;

-- 1. Rename rotation_state column
ALTER TABLE rotation_state RENAME COLUMN songs_since_jingle TO songs_since_station_id;

-- 2. Update categories.type CHECK constraint
ALTER TABLE categories DROP CONSTRAINT IF EXISTS categories_type_check;
ALTER TABLE categories ADD CONSTRAINT categories_type_check
    CHECK (type IN ('music','station_id','sweeper','liner','emergency'));
UPDATE categories SET type = 'station_id' WHERE type = 'jingle';

-- 3. Update play_history.source CHECK constraint
ALTER TABLE play_history DROP CONSTRAINT IF EXISTS play_history_source_check;
ALTER TABLE play_history ADD CONSTRAINT play_history_source_check
    CHECK (source IN ('rotation','request','manual','emergency','station_id'));
UPDATE play_history SET source = 'station_id' WHERE source = 'jingle';

-- 4. Update settings
UPDATE settings SET key = 'station_id_interval',
                    description = 'Play a station ID every N songs'
WHERE key = 'jingle_interval';

COMMIT;
