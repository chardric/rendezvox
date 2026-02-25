-- ============================================================
-- RendezVox — Online FM Radio Automation System
-- PostgreSQL Schema
-- ============================================================

BEGIN;

-- ---------- extensions ----------
CREATE EXTENSION IF NOT EXISTS "pgcrypto";   -- gen_random_uuid(), crypt()
CREATE EXTENSION IF NOT EXISTS "pg_trgm";    -- trigram fuzzy search on songs

-- ============================================================
-- 1. USERS (admin authentication)
-- ============================================================
CREATE TABLE users (
    id              SERIAL PRIMARY KEY,
    username        VARCHAR(64)  NOT NULL UNIQUE,
    email           VARCHAR(255) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,
    role            VARCHAR(20)  NOT NULL DEFAULT 'editor'
                        CHECK (role IN ('super_admin','admin','editor','viewer')),
    display_name    VARCHAR(255) DEFAULT NULL,
    avatar_path     VARCHAR(255) DEFAULT NULL,
    is_active       BOOLEAN      NOT NULL DEFAULT TRUE,
    last_login_at   TIMESTAMPTZ,
    last_login_ip   INET,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_users_role      ON users (role);
CREATE INDEX idx_users_is_active ON users (is_active);

-- ============================================================
-- 2. ARTISTS
-- ============================================================
CREATE TABLE artists (
    id              SERIAL PRIMARY KEY,
    name            VARCHAR(255) NOT NULL,
    normalized_name VARCHAR(255) NOT NULL,
    is_active       BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX idx_artists_normalized ON artists (normalized_name);
CREATE INDEX idx_artists_name_trgm         ON artists USING gin (name gin_trgm_ops);

-- ============================================================
-- 3. CATEGORIES
-- ============================================================
CREATE TABLE categories (
    id              SERIAL PRIMARY KEY,
    name            VARCHAR(100) NOT NULL UNIQUE,
    type            VARCHAR(20)  NOT NULL DEFAULT 'music'
                        CHECK (type IN ('music','jingle','sweeper','liner','emergency')),
    rotation_weight NUMERIC(5,2) NOT NULL DEFAULT 1.00
                        CHECK (rotation_weight >= 0),
    is_active       BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_categories_type ON categories (type);

-- ============================================================
-- 4. SONGS
-- ============================================================
CREATE TABLE songs (
    id              SERIAL PRIMARY KEY,
    title           VARCHAR(255) NOT NULL,
    artist_id       INT          NOT NULL REFERENCES artists(id) ON DELETE RESTRICT,
    category_id     INT          NOT NULL REFERENCES categories(id) ON DELETE RESTRICT,
    file_path       VARCHAR(512) NOT NULL UNIQUE,
    file_hash       CHAR(64),                       -- SHA-256 for dedup
    duration_ms     INT          NOT NULL CHECK (duration_ms > 0),
    rotation_weight NUMERIC(5,2) NOT NULL DEFAULT 1.00
                        CHECK (rotation_weight >= 0),
    year            SMALLINT,
    play_count      INT          NOT NULL DEFAULT 0,
    last_played_at  TIMESTAMPTZ,
    is_active       BOOLEAN      NOT NULL DEFAULT TRUE,
    is_requestable  BOOLEAN      NOT NULL DEFAULT TRUE,
    tagged_at       TIMESTAMPTZ,                        -- set by fix_genres.php after processing
    has_cover_art   BOOLEAN      NOT NULL DEFAULT FALSE, -- embedded cover art present
    loudness_lufs    NUMERIC(6,2),                      -- EBU R128 integrated loudness
    loudness_gain_db NUMERIC(6,2),                      -- gain to apply for target LUFS
    trashed_at      TIMESTAMPTZ,                        -- soft-delete timestamp
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_songs_artist       ON songs (artist_id);
CREATE INDEX idx_songs_category     ON songs (category_id);
CREATE INDEX idx_songs_active_rot   ON songs (is_active, rotation_weight)
    WHERE is_active = TRUE AND trashed_at IS NULL;
CREATE INDEX idx_songs_last_played  ON songs (last_played_at NULLS FIRST);
CREATE INDEX idx_songs_title_trgm   ON songs USING gin (title gin_trgm_ops);
CREATE INDEX idx_songs_requestable  ON songs (is_requestable)
    WHERE is_requestable = TRUE AND is_active = TRUE AND trashed_at IS NULL;
CREATE INDEX idx_songs_trashed      ON songs (trashed_at) WHERE trashed_at IS NOT NULL;

-- ============================================================
-- 5. PLAYLISTS
-- ============================================================
CREATE TABLE playlists (
    id              SERIAL PRIMARY KEY,
    name            VARCHAR(255) NOT NULL UNIQUE,
    description     TEXT,
    type            VARCHAR(20)  NOT NULL DEFAULT 'manual'
                        CHECK (type IN ('manual','auto','emergency')),
    rules           JSONB        DEFAULT NULL,          -- auto type: {"categories":[1,2],"min_weight":0.0}
    color           VARCHAR(7),                         -- hex color for calendar display, e.g. '#6c63ff'
    is_active       BOOLEAN      NOT NULL DEFAULT TRUE,
    cycle_count     INT          NOT NULL DEFAULT 0,   -- completed full cycles
    created_by      INT          REFERENCES users(id) ON DELETE SET NULL,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_playlists_type   ON playlists (type);
CREATE INDEX idx_playlists_active ON playlists (is_active) WHERE is_active = TRUE;

-- ============================================================
-- 6. PLAYLIST_SONGS  (no repeat per cycle)
-- ============================================================
CREATE TABLE playlist_songs (
    id              SERIAL PRIMARY KEY,
    playlist_id     INT NOT NULL REFERENCES playlists(id) ON DELETE CASCADE,
    song_id         INT NOT NULL REFERENCES songs(id)     ON DELETE CASCADE,
    position        INT NOT NULL,
    played_in_cycle BOOLEAN NOT NULL DEFAULT FALSE,       -- reset when cycle completes
    last_cycle_played INT NOT NULL DEFAULT 0,             -- cycle number last played
    added_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    UNIQUE (playlist_id, song_id),
    UNIQUE (playlist_id, position)
);

CREATE INDEX idx_playlist_songs_unplayed ON playlist_songs (playlist_id, played_in_cycle)
    WHERE played_in_cycle = FALSE;

-- ============================================================
-- 7. SCHEDULES
-- ============================================================
CREATE TABLE schedules (
    id              SERIAL PRIMARY KEY,
    name            VARCHAR(255) NOT NULL,
    playlist_id     INT          NOT NULL REFERENCES playlists(id) ON DELETE CASCADE,
    days_of_week    SMALLINT[],  -- array of 0=Mon … 6=Sun, NULL=every day
    start_date      DATE,        -- NULL = no start constraint
    end_date        DATE,        -- NULL = no end constraint
    start_time      TIME         NOT NULL,
    end_time        TIME         NOT NULL,
    priority        SMALLINT     NOT NULL DEFAULT 0,     -- higher wins on overlap
    is_active       BOOLEAN      NOT NULL DEFAULT TRUE,
    created_by      INT          REFERENCES users(id) ON DELETE SET NULL,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),

    CHECK (start_time <> end_time)
);

CREATE INDEX idx_schedules_lookup ON schedules (is_active, start_time, end_time);
CREATE INDEX idx_schedules_playlist ON schedules (playlist_id);

-- ============================================================
-- 8. PLAY_HISTORY
-- ============================================================
CREATE TABLE play_history (
    id              BIGSERIAL PRIMARY KEY,
    song_id         INT          NOT NULL REFERENCES songs(id) ON DELETE CASCADE,
    playlist_id     INT          REFERENCES playlists(id) ON DELETE SET NULL,
    schedule_id     INT          REFERENCES schedules(id) ON DELETE SET NULL,
    source          VARCHAR(20)  NOT NULL DEFAULT 'rotation'
                        CHECK (source IN ('rotation','request','manual','emergency','jingle')),
    started_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    ended_at        TIMESTAMPTZ,
    listener_count  INT,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_play_history_song      ON play_history (song_id);
CREATE INDEX idx_play_history_started    ON play_history (started_at DESC);
CREATE INDEX idx_play_history_source     ON play_history (source, started_at DESC);
-- artist-repeat-block: quickly find recent plays by artist
CREATE INDEX idx_play_history_artist_time ON play_history (started_at DESC)
    INCLUDE (song_id);

-- ============================================================
-- 9. ROTATION_STATE  (resume-safe playback state)
-- ============================================================
CREATE TABLE rotation_state (
    id                   SERIAL PRIMARY KEY,
    current_playlist_id  INT          REFERENCES playlists(id) ON DELETE SET NULL,
    next_playlist_id     INT          REFERENCES playlists(id) ON DELETE SET NULL,
    current_song_id      INT          REFERENCES songs(id) ON DELETE SET NULL,
    next_song_id         INT          REFERENCES songs(id) ON DELETE SET NULL,
    next_schedule_id     INT          REFERENCES schedules(id) ON DELETE SET NULL,
    next_source          VARCHAR(20)  NOT NULL DEFAULT 'rotation',
    current_position     INT          NOT NULL DEFAULT 0,
    current_cycle        INT          NOT NULL DEFAULT 0,
    playback_offset_ms   INT          NOT NULL DEFAULT 0,    -- resume position within song
    is_playing           BOOLEAN      NOT NULL DEFAULT FALSE,
    is_emergency         BOOLEAN      NOT NULL DEFAULT FALSE, -- emergency mode flag
    songs_since_jingle   INT          NOT NULL DEFAULT 0,     -- jingle interval counter
    last_artist_ids      INT[]        NOT NULL DEFAULT '{}',  -- recent artist ids for repeat-block
    started_at           TIMESTAMPTZ,
    updated_at           TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

-- Seed single state row
INSERT INTO rotation_state (id) VALUES (1);

-- ============================================================
-- 10. SONG_REQUESTS  (3-per-listener, expiration)
-- ============================================================
CREATE TABLE song_requests (
    id              BIGSERIAL PRIMARY KEY,
    song_id         INT          NOT NULL REFERENCES songs(id) ON DELETE CASCADE,
    listener_ip     INET         NOT NULL,
    listener_name   VARCHAR(100),
    message         VARCHAR(500),
    status          VARCHAR(20)  NOT NULL DEFAULT 'pending'
                        CHECK (status IN ('pending','approved','played','rejected','expired')),
    expires_at      TIMESTAMPTZ  NOT NULL DEFAULT (NOW() + INTERVAL '2 hours'),
    played_at       TIMESTAMPTZ,
    reviewed_by     INT          REFERENCES users(id) ON DELETE SET NULL,
    rejected_reason VARCHAR(50),                        -- NULL=admin, 'rotation_rule'=auto
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_requests_status     ON song_requests (status, created_at)
    WHERE status IN ('pending','approved');
CREATE INDEX idx_requests_listener   ON song_requests (listener_ip, created_at DESC);
CREATE INDEX idx_requests_expires    ON song_requests (expires_at)
    WHERE status = 'pending';
-- enforce 3-request limit per listener window (app-level, index supports the check)
CREATE INDEX idx_requests_limit      ON song_requests (listener_ip, status)
    WHERE status IN ('pending','approved');

-- ============================================================
-- 11. REQUEST_QUEUE  (ordered playback queue)
-- ============================================================
CREATE TABLE request_queue (
    id              SERIAL PRIMARY KEY,
    request_id      INT NOT NULL UNIQUE REFERENCES song_requests(id) ON DELETE CASCADE,
    song_id         INT NOT NULL REFERENCES songs(id) ON DELETE CASCADE,
    position        INT NOT NULL,
    queued_at       TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX idx_request_queue_pos ON request_queue (position);

-- ============================================================
-- 12. LISTENER_STATS
-- ============================================================
CREATE TABLE listener_stats (
    id              BIGSERIAL PRIMARY KEY,
    listener_count  INT         NOT NULL DEFAULT 0,
    peak_listeners  INT         NOT NULL DEFAULT 0,
    recorded_at     TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_listener_stats_time ON listener_stats (recorded_at DESC);
-- efficient range queries for dashboards
CREATE INDEX idx_listener_stats_daily ON listener_stats
    USING btree (((recorded_at AT TIME ZONE 'UTC')::date));

-- ============================================================
-- 13. STATION_LOGS
-- ============================================================
CREATE TABLE station_logs (
    id              BIGSERIAL PRIMARY KEY,
    level           VARCHAR(10)  NOT NULL DEFAULT 'info'
                        CHECK (level IN ('debug','info','warn','error','fatal')),
    component       VARCHAR(50)  NOT NULL,
    message         TEXT         NOT NULL,
    context         JSONB,
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_station_logs_level   ON station_logs (level, created_at DESC);
CREATE INDEX idx_station_logs_comp    ON station_logs (component, created_at DESC);
CREATE INDEX idx_station_logs_time    ON station_logs (created_at DESC);

-- ============================================================
-- 14. SETTINGS  (jingle interval, emergency mode, etc.)
-- ============================================================
CREATE TABLE settings (
    key             VARCHAR(100) PRIMARY KEY,
    value           TEXT         NOT NULL,
    type            VARCHAR(20)  NOT NULL DEFAULT 'string'
                        CHECK (type IN ('string','integer','boolean','json')),
    description     TEXT,
    updated_by      INT          REFERENCES users(id) ON DELETE SET NULL,
    updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

-- Seed defaults
INSERT INTO settings (key, value, type, description) VALUES
    ('station_name',          'RendezVox',  'string',  'Station display name'),
    ('station_logo_path',     '',        'string',  'Station logo filename'),
    ('station_timezone',      'UTC',     'string',  'Station timezone for schedules and clock'),
    ('jingle_interval',       '4',       'integer', 'Play a jingle every N songs'),
    ('artist_repeat_block',   '6',       'integer', 'Min songs gap before same artist replays'),
    ('song_repeat_block',     '30',      'integer', 'Min songs gap before same song replays'),
    ('request_limit',         '3',       'integer', 'Max pending+approved requests per listener'),
    ('request_window_minutes','120',     'integer', 'Rolling window (minutes) for request limit'),
    ('request_expiry_minutes','120',     'integer', 'Minutes until a pending request expires'),
    ('emergency_mode',        'false',   'boolean', 'When true, play emergency playlist only'),
    ('emergency_auto_activated','false', 'boolean', 'True when emergency was auto-activated by schedule gap'),
    ('emergency_playlist_id', '',        'string',  'Playlist ID used in emergency mode'),
    ('crossfade_ms',          '3000',    'integer', 'Default crossfade duration in milliseconds'),
    ('stream_url',            '',        'string',  'Public Icecast/HLS stream URL'),
    ('autodj_enabled',        'true',    'boolean', 'Enable automatic DJ rotation'),
    ('request_auto_approve',       'false', 'boolean', 'Auto-approve requests (skip moderation)'),
    ('request_rate_limit_seconds', '60',    'integer', 'Minimum seconds between requests per IP'),
    ('acoustid_api_key',           '',      'string',  'AcoustID API key for audio fingerprinting'),
    ('theaudiodb_api_key',         '2',     'string',  'TheAudioDB API key (default "2" is free tier)'),
    ('auto_tag_enabled',           'false', 'boolean', 'Automatically tag new songs in background'),
    ('weather_latitude',           '18.2644', 'string', 'Weather latitude coordinate'),
    ('weather_longitude',          '121.9910', 'string', 'Weather longitude coordinate'),
    ('weather_province',           '',         'string', 'Weather location — province'),
    ('weather_city',               '',         'string', 'Weather location — city/municipality'),
    ('weather_barangay',           '',         'string', 'Weather location — barangay'),
    ('normalize_target_lufs',      '-14.0',   'string', 'Target loudness level in LUFS for normalization'),
    ('auto_normalize_enabled',     'false',  'boolean', 'Automatically normalize new songs in background'),
    ('smtp_host',                  '',       'string',  'SMTP server hostname'),
    ('smtp_port',                  '587',    'integer', 'SMTP server port'),
    ('smtp_username',              '',       'string',  'SMTP authentication username'),
    ('smtp_password',              '',       'string',  'SMTP authentication password'),
    ('smtp_encryption',            'tls',    'string',  'SMTP encryption (tls, ssl, none)'),
    ('smtp_from_address',          '',       'string',  'Sender email address'),
    ('smtp_from_name',             'RendezVox', 'string',  'Sender display name'),
    ('profanity_filter_enabled',   'true',   'boolean', 'Filter profanity in request names and messages'),
    ('profanity_custom_words',     '',       'string',  'Additional blocked words (comma-separated)'),
    ('organizer_enabled',          'true',   'boolean', 'Enable real-time media organizer'),
    ('organizer_poll_secs',        '10',     'integer', 'Seconds between organizer scan cycles');

-- ============================================================
-- 15. PASSWORD_RESET_TOKENS
-- ============================================================
CREATE TABLE password_reset_tokens (
    id          SERIAL PRIMARY KEY,
    user_id     INT          NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    token_hash  VARCHAR(64)  NOT NULL UNIQUE,
    expires_at  TIMESTAMPTZ  NOT NULL,
    used_at     TIMESTAMPTZ,
    created_ip  INET,
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_reset_tokens_active ON password_reset_tokens (token_hash) WHERE used_at IS NULL;
CREATE INDEX idx_reset_tokens_user   ON password_reset_tokens (user_id, created_at DESC);

-- ============================================================
-- 16. BANNED_IPS
-- ============================================================
CREATE TABLE banned_ips (
    id              SERIAL PRIMARY KEY,
    ip_address      INET         NOT NULL,
    subnet_mask     SMALLINT     DEFAULT 32 CHECK (subnet_mask BETWEEN 0 AND 128),
    reason          VARCHAR(500),
    banned_by       INT          REFERENCES users(id) ON DELETE SET NULL,
    expires_at      TIMESTAMPTZ,                       -- NULL = permanent
    created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE UNIQUE INDEX idx_banned_ips_addr ON banned_ips (ip_address, subnet_mask);
CREATE INDEX idx_banned_ips_expires     ON banned_ips (expires_at)
    WHERE expires_at IS NOT NULL;

-- ============================================================
-- 17. ORGANIZER_QUEUE — Tracks discovered files through processing
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
-- 18. ORGANIZER_HASHES — SHA-256 index for files not yet in songs
-- ============================================================
CREATE TABLE organizer_hashes (
    file_hash       CHAR(64)      NOT NULL UNIQUE,
    absolute_path   VARCHAR(1024),
    created_at      TIMESTAMPTZ   NOT NULL DEFAULT NOW()
);

-- ============================================================
-- TRIGGER: auto-update updated_at columns
-- ============================================================
CREATE OR REPLACE FUNCTION fn_set_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DO $$
DECLARE
    t TEXT;
BEGIN
    FOREACH t IN ARRAY ARRAY[
        'users','artists','categories','songs',
        'playlists','schedules','rotation_state','settings'
    ] LOOP
        EXECUTE format(
            'CREATE TRIGGER trg_%s_updated_at
             BEFORE UPDATE ON %I
             FOR EACH ROW EXECUTE FUNCTION fn_set_updated_at()',
            t, t
        );
    END LOOP;
END;
$$;

-- ============================================================
-- FUNCTION: enforce 3-request limit per listener
-- ============================================================
CREATE OR REPLACE FUNCTION fn_enforce_request_limit()
RETURNS TRIGGER AS $$
DECLARE
    v_limit    INT;
    v_window   INT;
    v_count    INT;
BEGIN
    SELECT value::INT INTO v_limit  FROM settings WHERE key = 'request_limit';
    SELECT value::INT INTO v_window FROM settings WHERE key = 'request_window_minutes';

    SELECT COUNT(*) INTO v_count
    FROM song_requests
    WHERE listener_ip = NEW.listener_ip
      AND status IN ('pending','approved')
      AND created_at > NOW() - (v_window || ' minutes')::INTERVAL;

    IF v_count >= v_limit THEN
        RAISE EXCEPTION 'Request limit (%) reached for %', v_limit, NEW.listener_ip;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_request_limit
    BEFORE INSERT ON song_requests
    FOR EACH ROW EXECUTE FUNCTION fn_enforce_request_limit();

-- ============================================================
-- FUNCTION: expire stale requests (call via pg_cron or app)
-- ============================================================
CREATE OR REPLACE FUNCTION fn_expire_requests()
RETURNS INT AS $$
DECLARE
    affected INT;
BEGIN
    UPDATE song_requests
    SET    status = 'expired'
    WHERE  status = 'pending'
      AND  expires_at < NOW();

    GET DIAGNOSTICS affected = ROW_COUNT;
    RETURN affected;
END;
$$ LANGUAGE plpgsql;

COMMIT;
