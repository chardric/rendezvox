-- 005_trash.sql — Add trash mechanism to songs table
-- Usage: docker exec rendezvox-postgres psql -U rendezvox -d rendezvox -f /migrations/005_trash.sql

BEGIN;

ALTER TABLE songs ADD COLUMN IF NOT EXISTS trashed_at TIMESTAMPTZ DEFAULT NULL;

-- Exclude trashed from active rotation index
DROP INDEX IF EXISTS idx_songs_active_rot;
CREATE INDEX idx_songs_active_rot ON songs (is_active, rotation_weight)
  WHERE is_active = TRUE AND trashed_at IS NULL;

-- Exclude trashed from requestable index
DROP INDEX IF EXISTS idx_songs_requestable;
CREATE INDEX idx_songs_requestable ON songs (is_requestable)
  WHERE is_requestable = TRUE AND is_active = TRUE AND trashed_at IS NULL;

-- Fast lookup for trash view
CREATE INDEX IF NOT EXISTS idx_songs_trashed ON songs (trashed_at) WHERE trashed_at IS NOT NULL;

COMMIT;
