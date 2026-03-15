-- ============================================================
-- Migration 023: Segment file rotation
-- Multiple audio files per segment with round-robin playback
-- ============================================================

BEGIN;

CREATE TABLE segment_files (
    id             SERIAL PRIMARY KEY,
    segment_id     INT NOT NULL REFERENCES segments(id) ON DELETE CASCADE,
    file_path      VARCHAR(512) NOT NULL,
    duration_ms    INT NOT NULL CHECK (duration_ms > 0),
    position       SMALLINT NOT NULL DEFAULT 0,
    last_played_at TIMESTAMPTZ,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_segment_files_segment ON segment_files (segment_id, position);

-- Track which file index is next for round-robin
ALTER TABLE segments ADD COLUMN IF NOT EXISTS next_file_index SMALLINT NOT NULL DEFAULT 0;

COMMIT;
