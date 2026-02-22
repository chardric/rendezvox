-- ============================================================
-- iRadio — Migration 003: Media Organizer
--
-- Adds queue and hash tables for the real-time media organizer,
-- plus settings to enable/configure it.
-- ============================================================

BEGIN;

-- ============================================================
-- ORGANIZER_QUEUE — Tracks discovered files through processing
-- ============================================================
CREATE TABLE organizer_queue (
    id              SERIAL PRIMARY KEY,
    absolute_path   VARCHAR(1024) NOT NULL UNIQUE,
    file_size       BIGINT,
    status          VARCHAR(20)   NOT NULL DEFAULT 'pending'
                        CHECK (status IN ('pending','processing','done','failed','skipped')),
    result_action   VARCHAR(20)
                        CHECK (result_action IN ('organized','quarantined','duplicate','skipped')),
    result_path     VARCHAR(1024),
    error_message   TEXT,
    processed_at    TIMESTAMPTZ,
    created_at      TIMESTAMPTZ   NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_organizer_queue_status ON organizer_queue (status)
    WHERE status IN ('pending','processing');
CREATE INDEX idx_organizer_queue_path   ON organizer_queue (absolute_path);

-- ============================================================
-- ORGANIZER_HASHES — SHA-256 index for files not yet in songs
-- ============================================================
CREATE TABLE organizer_hashes (
    file_hash       CHAR(64)      NOT NULL UNIQUE,
    absolute_path   VARCHAR(1024),
    created_at      TIMESTAMPTZ   NOT NULL DEFAULT NOW()
);

-- ============================================================
-- SETTINGS — Organizer configuration
-- ============================================================
INSERT INTO settings (key, value, type, description) VALUES
    ('organizer_enabled',   'true', 'boolean', 'Enable real-time media organizer'),
    ('organizer_poll_secs', '10',    'integer', 'Seconds between organizer scan cycles');

COMMIT;
