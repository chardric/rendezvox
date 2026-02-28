-- 011: Add duplicate_of column for non-destructive duplicate tracking
-- A song marked as a duplicate points to its canonical copy.
-- The file stays on disk; the song is simply suppressed from rotation/playlists.

ALTER TABLE songs ADD COLUMN duplicate_of INT REFERENCES songs(id) ON DELETE SET NULL;

CREATE INDEX idx_songs_duplicate_of ON songs (duplicate_of) WHERE duplicate_of IS NOT NULL;
