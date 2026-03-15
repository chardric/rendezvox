-- ============================================================
-- Migration 026: Interval-based segment scheduling
-- Adds interval_minutes column for recurring segments
-- (e.g. "play OPM every 60 minutes").
-- NULL = fixed-time mode (existing behavior).
-- ============================================================

BEGIN;

ALTER TABLE segments ADD COLUMN IF NOT EXISTS interval_minutes INT;

COMMIT;
