-- ============================================================
-- Migration 024: Make segment file_path/duration_ms optional
-- Segments now use rotation files (segment_files table) as primary
-- audio source. The legacy file_path on segments is no longer required.
-- ============================================================

BEGIN;

ALTER TABLE segments ALTER COLUMN file_path DROP NOT NULL;
ALTER TABLE segments ALTER COLUMN file_path SET DEFAULT NULL;
ALTER TABLE segments ALTER COLUMN duration_ms DROP NOT NULL;
ALTER TABLE segments ALTER COLUMN duration_ms SET DEFAULT NULL;
ALTER TABLE segments DROP CONSTRAINT IF EXISTS segments_duration_ms_check;

COMMIT;
