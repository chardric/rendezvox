-- ============================================================
-- Migration 025: Extended segment types + library-based segments
-- Adds OPM, song_pick, music_block types that pull from the
-- song library instead of uploaded files.
-- ============================================================

BEGIN;

ALTER TABLE segments ADD COLUMN IF NOT EXISTS library_config JSONB;

ALTER TABLE segments DROP CONSTRAINT IF EXISTS segments_segment_type_check;
ALTER TABLE segments ADD CONSTRAINT segments_segment_type_check CHECK (
  segment_type::text = ANY(ARRAY[
    'announcement','news','devotional','prayer','podcast','promo',
    'psa','commercial','weather','editorial','interview',
    'opm','song_pick','music_block','custom'
  ]::text[])
);

COMMIT;
