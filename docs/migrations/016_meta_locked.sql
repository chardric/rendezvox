-- 016_meta_locked.sql
-- Protect manually edited song metadata from being overwritten by auto-tag/re-scan.
ALTER TABLE songs ADD COLUMN IF NOT EXISTS meta_locked BOOLEAN NOT NULL DEFAULT FALSE;
