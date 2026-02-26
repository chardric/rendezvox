-- 009_login_lockout.sql
-- Add brute-force protection columns to users table

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS failed_login_count SMALLINT NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS locked_until TIMESTAMPTZ DEFAULT NULL;
