-- Migration 012: Music directory restructure
-- tagged/ → tagged/files/, _untagged/ → untagged/files/, imports/ → tagged/folders/
--
-- Run on both localhost and RPi:
--   docker exec -i rendezvox-postgres psql -U rendezvox -d rendezvox < docs/migrations/012_music_dir_restructure.sql

BEGIN;

-- tagged/ → tagged/files/ (skip already-migrated paths)
UPDATE songs SET file_path = 'tagged/files/' || SUBSTRING(file_path FROM 8)
WHERE file_path LIKE 'tagged/%'
  AND file_path NOT LIKE 'tagged/files/%'
  AND file_path NOT LIKE 'tagged/folders/%';

-- _untagged/ → untagged/files/
UPDATE songs SET file_path = 'untagged/files/' || SUBSTRING(file_path FROM 11)
WHERE file_path LIKE '_untagged/%';

-- imports/ → tagged/folders/
UPDATE songs SET file_path = 'tagged/folders/' || SUBSTRING(file_path FROM 9)
WHERE file_path LIKE 'imports/%';

COMMIT;
