-- ============================================================
-- Migration 022: Smart Features
-- Mood analysis, weather-reactive, retention scoring,
-- crossfade intelligence, voting, recaps, segments
-- ============================================================

BEGIN;

-- (A) Song mood/energy columns
ALTER TABLE songs
    ADD COLUMN IF NOT EXISTS bpm           SMALLINT,
    ADD COLUMN IF NOT EXISTS energy        NUMERIC(4,3),
    ADD COLUMN IF NOT EXISTS valence       NUMERIC(4,3),
    ADD COLUMN IF NOT EXISTS ending_type   VARCHAR(10) DEFAULT NULL
        CHECK (ending_type IN ('fade', 'hard', 'silence')),
    ADD COLUMN IF NOT EXISTS ending_energy NUMERIC(4,3),
    ADD COLUMN IF NOT EXISTS intro_energy  NUMERIC(4,3),
    ADD COLUMN IF NOT EXISTS mood_analyzed_at TIMESTAMPTZ,
    ADD COLUMN IF NOT EXISTS retention_score NUMERIC(5,3) DEFAULT NULL;

CREATE INDEX IF NOT EXISTS idx_songs_mood ON songs (energy, valence) WHERE is_active = TRUE;
CREATE INDEX IF NOT EXISTS idx_songs_bpm  ON songs (bpm) WHERE bpm IS NOT NULL AND is_active = TRUE;

-- (B) Listener retention: end count for delta computation
ALTER TABLE play_history
    ADD COLUMN IF NOT EXISTS listener_count_end INT;

-- (C) Request voting
CREATE TABLE IF NOT EXISTS request_polls (
    id              SERIAL PRIMARY KEY,
    status          VARCHAR(10) NOT NULL DEFAULT 'active'
        CHECK (status IN ('active', 'closed', 'played')),
    candidate_ids   INT[] NOT NULL,
    winner_song_id  INT REFERENCES songs(id),
    expires_at      TIMESTAMPTZ NOT NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_request_polls_active ON request_polls (status) WHERE status = 'active';

CREATE TABLE IF NOT EXISTS request_votes (
    id          BIGSERIAL PRIMARY KEY,
    poll_id     INT NOT NULL REFERENCES request_polls(id) ON DELETE CASCADE,
    song_id     INT NOT NULL REFERENCES songs(id) ON DELETE CASCADE,
    voter_ip    INET NOT NULL,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (poll_id, voter_ip)
);

CREATE INDEX IF NOT EXISTS idx_request_votes_poll ON request_votes (poll_id);

-- (D) Show recaps
CREATE TABLE IF NOT EXISTS show_recaps (
    id          SERIAL PRIMARY KEY,
    recap_date  DATE NOT NULL,
    recap_type  VARCHAR(10) NOT NULL DEFAULT 'daily'
        CHECK (recap_type IN ('daily', 'weekly')),
    title       VARCHAR(255) NOT NULL,
    body        TEXT NOT NULL,
    generated_by VARCHAR(20) NOT NULL DEFAULT 'gemini',
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (recap_date, recap_type)
);

-- (E) Segments/podcasts
CREATE TABLE IF NOT EXISTS segments (
    id              SERIAL PRIMARY KEY,
    name            VARCHAR(255) NOT NULL,
    file_path       VARCHAR(512) NOT NULL,
    duration_ms     INT NOT NULL CHECK (duration_ms > 0),
    segment_type    VARCHAR(20) NOT NULL DEFAULT 'announcement'
        CHECK (segment_type IN ('announcement', 'news', 'devotional', 'podcast', 'promo', 'custom')),
    days_of_week    SMALLINT[],
    play_time       TIME NOT NULL,
    priority        SMALLINT NOT NULL DEFAULT 10,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    last_played_at  TIMESTAMPTZ,
    created_by      INT REFERENCES users(id) ON DELETE SET NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_segments_active ON segments (is_active, play_time) WHERE is_active = TRUE;

-- Auto-update updated_at on segments
CREATE TRIGGER trg_segments_updated_at
    BEFORE UPDATE ON segments
    FOR EACH ROW EXECUTE FUNCTION fn_set_updated_at();

-- (F) New settings
INSERT INTO settings (key, value, type, description) VALUES
    ('mood_programming_enabled',    'false', 'boolean', 'Enable mood-based song selection by time of day'),
    ('weather_reactive_enabled',    'false', 'boolean', 'Bias song selection based on current weather'),
    ('retention_scoring_enabled',   'false', 'boolean', 'Auto-demote songs that lose listeners'),
    ('retention_demote_threshold',  '-0.15', 'string',  'Retention score below which to reduce rotation weight'),
    ('smart_jingle_enabled',        'false', 'boolean', 'Insert station IDs at natural energy breaks instead of fixed interval'),
    ('voting_enabled',              'false', 'boolean', 'Enable collaborative request voting'),
    ('voting_duration_minutes',     '15',    'integer', 'Duration of each voting poll in minutes'),
    ('voting_candidate_count',      '4',     'integer', 'Number of song candidates per poll'),
    ('recap_enabled',               'false', 'boolean', 'Generate daily show recaps via AI'),
    ('crossfade_intelligence',      'false', 'boolean', 'Adjust crossfade duration based on track endings'),
    ('segment_scheduling_enabled',  'false', 'boolean', 'Enable podcast/segment scheduling'),
    ('auto_mood_analyze_enabled',   'false', 'boolean', 'Automatically analyze mood for new songs every 15 minutes')
ON CONFLICT (key) DO NOTHING;

-- (G) Extend play_history.source CHECK to include segment/tts
ALTER TABLE play_history DROP CONSTRAINT IF EXISTS play_history_source_check;
ALTER TABLE play_history ADD CONSTRAINT play_history_source_check
    CHECK (source IN ('rotation','request','manual','emergency','station_id','segment','tts'));

COMMIT;
