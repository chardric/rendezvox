-- Migration: Add SMTP email settings + password reset tokens
-- Run this on existing databases to enable forgot-password and SMTP email.

BEGIN;

-- ── Password reset tokens table ──
CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id          SERIAL PRIMARY KEY,
    user_id     INT          NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash  VARCHAR(64)  NOT NULL UNIQUE,
    expires_at  TIMESTAMPTZ  NOT NULL,
    used_at     TIMESTAMPTZ,
    created_ip  INET,
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_reset_tokens_active
    ON password_reset_tokens (token_hash) WHERE used_at IS NULL;

CREATE INDEX IF NOT EXISTS idx_reset_tokens_user
    ON password_reset_tokens (user_id, created_at DESC);

-- ── SMTP settings ──
INSERT INTO settings (key, value, type, description) VALUES
    ('smtp_host',         '',       'string',  'SMTP server hostname'),
    ('smtp_port',         '587',    'integer', 'SMTP server port'),
    ('smtp_username',     '',       'string',  'SMTP authentication username'),
    ('smtp_password',     '',       'string',  'SMTP authentication password'),
    ('smtp_encryption',   'tls',    'string',  'SMTP encryption (tls, ssl, none)'),
    ('smtp_from_address', '',       'string',  'Sender email address'),
    ('smtp_from_name',    'iRadio', 'string',  'Sender display name')
ON CONFLICT (key) DO NOTHING;

COMMIT;
