-- ============================================================
-- Migration: Simplify user roles to super_admin / dj
-- ============================================================

BEGIN;

-- 1. Migrate existing rows
UPDATE users SET role = 'super_admin' WHERE role IN ('super_admin', 'admin');
UPDATE users SET role = 'dj'          WHERE role NOT IN ('super_admin');

-- 2. Drop old constraint, add new one
ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check;
ALTER TABLE users ADD CONSTRAINT users_role_check
    CHECK (role IN ('super_admin', 'dj'));

-- 3. Change default
ALTER TABLE users ALTER COLUMN role SET DEFAULT 'dj';

COMMIT;
