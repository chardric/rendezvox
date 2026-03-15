<?php

declare(strict_types=1);

class NextTrackHandler
{
    public function handle(): void
    {
        $db = Database::get();
        Database::ensureRotationState();

        // ── 0. Emergency mode override ───────────────────────
        $isEmergency = false;
        $playlistId  = null;
        $scheduleId  = null;

        $emergencyOn   = $this->getSetting($db, 'emergency_mode', 'false') === 'true';
        $emergencyAuto = $this->getSetting($db, 'emergency_auto_activated', 'false') === 'true';

        if ($emergencyOn) {
            if ($emergencyAuto) {
                // Auto-activated (schedule gap) — check if a schedule exists now
                $schedule = $this->resolveSchedule($db);
                if ($schedule) {
                    // Schedule found — auto-disable emergency, use the schedule
                    $this->disableAutoEmergency($db);
                    $scheduleId = (int) $schedule['schedule_id'];
                    $playlistId = (int) $schedule['playlist_id'];
                } else {
                    // Still no schedule — stay in emergency
                    $playlistId  = $this->findEmergencyPlaylist($db);
                    $isEmergency = $playlistId !== null;
                }
            } else {
                // Manually activated — always use emergency playlist
                $playlistId  = $this->findEmergencyPlaylist($db);
                $isEmergency = $playlistId !== null;
            }
        }

        // ── 1. Resolve playlist from schedule ──────────────
        if (!$isEmergency && !$playlistId) {
            $schedule   = $this->resolveSchedule($db);
            $scheduleId = $schedule['schedule_id'] ?? null;

            if ($schedule) {
                $playlistId = (int) $schedule['playlist_id'];
            } else {
                // No active schedule — auto-activate emergency fallback
                $epId = $this->findEmergencyPlaylist($db);
                if ($epId !== null) {
                    $playlistId  = $epId;
                    $isEmergency = true;
                    $this->enableAutoEmergency($db);
                } else {
                    // No emergency playlist configured — go idle
                    $db->prepare('
                        UPDATE rotation_state
                        SET is_playing = false, current_song_id = NULL, started_at = NULL
                        WHERE id = 1
                    ')->execute();

                    Response::json([
                        'song'    => null,
                        'message' => 'No active schedule and no emergency playlist configured',
                    ]);
                    return;
                }
            }
        }

        $state = $db->query('SELECT * FROM rotation_state WHERE id = 1')->fetch();

        // ── 1b. Detect playlist transition (schedule boundary) ──
        // When the resolved playlist differs from the currently-playing
        // playlist, set crossfade to 0 so the current song finishes
        // completely before the new playlist starts.
        $isTransition = false;
        $currentPid   = $state['current_playlist_id'] ?? null;
        if (!$isEmergency && $currentPid && (int) $currentPid !== $playlistId) {
            $isTransition = true;
        }

        if (!$playlistId) {
            Response::json([
                'song'    => null,
                'message' => 'No active playlist — emergency mode has no playlist configured',
            ]);
            return;
        }

        // ── 2. Fetch playlist type + rules ──────────────────
        $stmt = $db->prepare('SELECT type, rules FROM playlists WHERE id = :id');
        $stmt->execute(['id' => $playlistId]);
        $playlistRow  = $stmt->fetch();
        $playlistType = $playlistRow ? $playlistRow['type'] : 'manual';
        $playlistRules = ($playlistRow && $playlistRow['rules'])
            ? json_decode($playlistRow['rules'], true)
            : null;

        // ── 2b. Detect playlist switch ─────────────────────
        // No cycle reset — continue playing unplayed songs where we left off.
        // The cycle only resets naturally when all songs have been played.

        $artistBlockSize = $this->getSettingInt($db, 'artist_repeat_block', 6);
        $cycle = (int) ($state['current_cycle'] ?? 0);

        // ── 2.5. Request injection (Playlist → Request alternation) ──
        //      Disabled during emergency mode.
        $lastSource = $this->getLastPlaySource($db);

        if (!$isEmergency && $lastSource === 'rotation') {
            $requestSong = $this->tryPlayRequest($db, (int) $playlistId, $artistBlockSize);

            if ($requestSong) {
                $this->commitRequestPlay(
                    $db, $requestSong, (int) $playlistId, $scheduleId, $state, $cycle
                );

                $ttsPath = $this->generateTtsPreRoll($db, $state, $requestSong);

                Response::json([
                    'song' => [
                        'id'          => (int) $requestSong['song_id'],
                        'title'       => $requestSong['title'],
                        'artist'      => $requestSong['artist_name'],
                        'artist_id'   => (int) $requestSong['artist_id'],
                        'year'        => isset($requestSong['year']) && $requestSong['year'] ? (int) $requestSong['year'] : null,
                        'category'    => $requestSong['category_name'],
                        'duration_ms' => (int) $requestSong['duration_ms'],
                        'file_path'   => $requestSong['file_path'],
                        'gain_db'     => $requestSong['loudness_gain_db'] !== null ? (float) $requestSong['loudness_gain_db'] : 0.0,
                        'cue_in'      => $requestSong['cue_in'] !== null ? (float) $requestSong['cue_in'] : null,
                        'cue_out'     => $requestSong['cue_out'] !== null ? (float) $requestSong['cue_out'] : null,
                    ],
                    'source'      => 'request',
                    'request_id'  => (int) $requestSong['request_id'],
                    'tts_path'    => $ttsPath,
                    'position'    => (int) ($state['current_position'] ?? 0),
                    'cycle'       => $cycle,
                    'cycle_reset' => false,
                    'playlist_id' => (int) $playlistId,
                    'schedule_id' => $scheduleId ? (int) $scheduleId : null,
                ]);
                return;
            }
            // No eligible request — fall through to rotation
        }

        // ── 3. Fetch next song ──────────────────────────────
        $song       = null;
        $cycleReset = false;

        if ($playlistType === 'auto') {
            // Auto: least-recently-played from songs matching the rules.
            // No cycle tracking — just pick by last_played_at.
            $song = $this->fetchNextAutoSong($db, $playlistRules, $artistBlockSize);

            if (!$song) {
                Response::json([
                    'song'    => null,
                    'message' => 'Auto playlist — no songs match the current rules',
                ]);
                return;
            }
        } else {
            // Manual / Emergency: weighted cycle-based rotation
            $song = $this->fetchNextSongWithBlock($db, (int) $playlistId, $artistBlockSize);

            // ── 4. Cycle complete → reshuffle and re-fetch ─────
            if (!$song) {
                $this->resetCycleForPlaylist($db, (int) $playlistId);
                $db->prepare('
                    UPDATE playlists SET cycle_count = cycle_count + 1 WHERE id = :id
                ')->execute(['id' => $playlistId]);

                if ($isEmergency) {
                    RotationEngine::autoFillEmergencyPlaylist($db, (int) $playlistId);
                }

                $song = $this->fetchNextSongWithBlock($db, (int) $playlistId, $artistBlockSize);
                $cycleReset = true;

                if (!$song) {
                    Response::json([
                        'song'    => null,
                        'message' => 'Playlist is empty — no active songs found',
                    ]);
                    return;
                }
            }

            if ($cycleReset) {
                $cycle += 1;
            }
        }

        // ── 5. Commit rotation pick (single transaction) ──
        // play_history INSERT and play_count are deferred to
        // TrackStartedHandler when the track actually starts playing.
        $db->beginTransaction();
        try {
            // Update last_played_at (prevents auto-rotation re-pick)
            $db->prepare('
                UPDATE songs SET last_played_at = NOW() WHERE id = :id
            ')->execute(['id' => $song['song_id']]);

            // Mark played in cycle (manual/emergency only — auto has no cycle)
            if ($playlistType !== 'auto') {
                $db->prepare('
                    UPDATE playlist_songs
                    SET played_in_cycle    = true,
                        last_cycle_played  = :cycle
                    WHERE playlist_id = :playlist_id AND song_id = :song_id
                ')->execute([
                    'cycle'       => $cycle,
                    'playlist_id' => $playlistId,
                    'song_id'     => $song['song_id'],
                ]);
            }

            // Update artist block list
            $artistIds = $this->parseIntArray($state['last_artist_ids'] ?? '{}');
            array_unshift($artistIds, (int) $song['artist_id']);
            $artistIds = array_slice($artistIds, 0, 10);
            $pgArray   = '{' . implode(',', $artistIds) . '}';

            // Update rotation state — store playlist/schedule/source so
            // TrackStartedHandler can create the play_history row later.
            // Write to next_playlist_id (not current_playlist_id) so the
            // currently-playing song keeps its playlist badge until it ends.
            $source = $isEmergency ? 'emergency' : 'rotation';
            $db->prepare('
                UPDATE rotation_state
                SET next_playlist_id   = :playlist_id,
                    current_position    = :position,
                    current_cycle       = :cycle,
                    is_emergency        = :is_emergency,
                    songs_since_station_id  = songs_since_station_id + 1,
                    last_artist_ids     = :artist_ids,
                    next_song_id        = :next_song_id,
                    next_schedule_id    = :next_schedule_id,
                    next_source         = :next_source
                WHERE id = 1
            ')->execute([
                'playlist_id'      => $playlistId,
                'position'         => $song['position'] ?? 0,
                'cycle'            => $cycle,
                'is_emergency'     => $isEmergency ? 'true' : 'false',
                'artist_ids'       => $pgArray,
                'next_song_id'     => $song['song_id'],
                'next_schedule_id' => $scheduleId,
                'next_source'      => $source,
            ]);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log('NextTrack commit failed: ' . $e->getMessage());
            Response::error('Failed to commit play', 500);
        }

        // ── 5b. Segment injection ────────────────────────
        $segment = $this->checkSegmentDue($db);

        if ($segment) {
            $isLibrary = isset($segment['_song_id']);
            Response::json([
                'song' => [
                    'id'          => $isLibrary ? (int) $segment['_song_id'] : 0,
                    'title'       => $isLibrary ? $segment['_song_title'] : $segment['name'],
                    'artist'      => $isLibrary ? $segment['_song_artist'] : $segment['segment_type'],
                    'artist_id'   => 0,
                    'year'        => null,
                    'category'    => $isLibrary ? $segment['segment_type'] : 'segment',
                    'duration_ms' => (int) $segment['duration_ms'],
                    'file_path'   => $segment['file_path'],
                    'gain_db'     => $isLibrary ? (float) $segment['_gain_db'] : 0.0,
                    'cue_in'      => $isLibrary ? $segment['_cue_in'] : null,
                    'cue_out'     => $isLibrary ? $segment['_cue_out'] : null,
                ],
                'source'       => $isLibrary ? 'segment_library' : 'segment',
                'is_emergency' => false,
                'request_id'   => null,
                'tts_path'     => null,
                'position'     => 0,
                'cycle'        => $cycle,
                'cycle_reset'  => false,
                'playlist_id'  => (int) $playlistId,
                'schedule_id'  => $scheduleId ? (int) $scheduleId : null,
                'crossfade_ms' => 500,
            ]);
            return;
        }

        // ── 5c. Smart jingle injection ─────────────────────
        $stationId = $this->checkSmartJingleDue($db, $state);
        if ($stationId) {
            Response::json([
                'song' => [
                    'id'          => 0,
                    'title'       => 'Station ID',
                    'artist'      => '',
                    'artist_id'   => 0,
                    'year'        => null,
                    'category'    => 'station_id',
                    'duration_ms' => 0,
                    'file_path'   => $stationId['file_path'],
                    'gain_db'     => 0.0,
                    'cue_in'      => null,
                    'cue_out'     => null,
                ],
                'source'       => 'station_id',
                'is_emergency' => false,
                'request_id'   => null,
                'tts_path'     => $stationId['tts_path'],
                'position'     => (int) ($song['position'] ?? 0),
                'cycle'        => $cycle,
                'cycle_reset'  => false,
                'playlist_id'  => (int) $playlistId,
                'schedule_id'  => $scheduleId ? (int) $scheduleId : null,
                'crossfade_ms' => 500,
            ]);
            return;
        }

        // ── 6. TTS pre-roll ───────────────────────────────
        $ttsPath = $this->generateTtsPreRoll($db, $state, $song);

        // ── 6b. Crossfade intelligence ───────────────────
        $crossfadeMs = null;
        if ($this->getSetting($db, 'crossfade_intelligence', 'false') === 'true') {
            require_once __DIR__ . '/../../core/CrossfadeAnalyzer.php';
            $defaultCf = $this->getSettingInt($db, 'crossfade_ms', 3000);
            $transition = CrossfadeAnalyzer::getTransitionParams(
                $song,  // outgoing mood data (ending_type, ending_energy)
                null,   // incoming not known yet
                $defaultCf
            );
            $crossfadeMs = $transition['crossfade_ms'];
        }

        // ── 7. Return song data ───────────────────────────
        $response = [
            'song' => [
                'id'          => (int) $song['song_id'],
                'title'       => $song['title'],
                'artist'      => $song['artist_name'],
                'artist_id'   => (int) $song['artist_id'],
                'year'        => isset($song['year']) && $song['year'] ? (int) $song['year'] : null,
                'category'    => $song['category_name'],
                'duration_ms' => (int) $song['duration_ms'],
                'file_path'   => $song['file_path'],
                'gain_db'     => $song['loudness_gain_db'] !== null ? (float) $song['loudness_gain_db'] : 0.0,
                'cue_in'      => $song['cue_in'] !== null ? (float) $song['cue_in'] : null,
                'cue_out'     => $song['cue_out'] !== null ? (float) $song['cue_out'] : null,
            ],
            'source'       => $isEmergency ? 'emergency' : 'rotation',
            'is_emergency' => $isEmergency,
            'request_id'   => null,
            'tts_path'     => $ttsPath,
            'position'    => (int) ($song['position'] ?? 0),
            'cycle'       => $cycle,
            'cycle_reset' => $cycleReset,
            'playlist_id' => (int) $playlistId,
            'schedule_id' => $scheduleId ? (int) $scheduleId : null,
        ];

        if ($isTransition) {
            // No crossfade during playlist transitions — let current song finish
            $response['crossfade_ms'] = 0;
        } elseif ($crossfadeMs !== null) {
            $response['crossfade_ms'] = $crossfadeMs;
        }

        Response::json($response);
    }

    // ── Schedule resolution ─────────────────────────────────

    private function resolveSchedule(PDO $db): ?array
    {
        $tz = $this->getSetting($db, 'station_timezone', 'UTC');

        $stmt = $db->prepare("
            SELECT s.id AS schedule_id, s.playlist_id
            FROM schedules s
            JOIN playlists p ON p.id = s.playlist_id
            WHERE s.is_active = true
              AND p.is_active = true
              AND EXISTS (SELECT 1 FROM playlist_songs ps WHERE ps.playlist_id = p.id)
              AND (EXTRACT(DOW FROM NOW() AT TIME ZONE :tz)::int = ANY(s.days_of_week)
                   OR s.days_of_week IS NULL)
              AND (s.start_date IS NULL OR s.start_date <= (NOW() AT TIME ZONE :tz4)::date)
              AND (s.end_date   IS NULL OR s.end_date   >= (NOW() AT TIME ZONE :tz5)::date)
              AND s.start_time <= (NOW() AT TIME ZONE :tz2)::time
              AND s.end_time   >  (NOW() AT TIME ZONE :tz3)::time
            ORDER BY s.priority DESC
            LIMIT 1
        ");
        $stmt->execute(['tz' => $tz, 'tz2' => $tz, 'tz3' => $tz, 'tz4' => $tz, 'tz5' => $tz]);
        return $stmt->fetch() ?: null;
    }

    // ── Rotation helpers ────────────────────────────────────

    private function resetCycleForPlaylist(PDO $db, int $playlistId): void
    {
        $db->prepare('
            UPDATE playlist_songs SET played_in_cycle = false WHERE playlist_id = :id
        ')->execute(['id' => $playlistId]);

        RotationEngine::generateCycleOrder($db, $playlistId);
    }

    private function fetchNextSongWithBlock(PDO $db, int $playlistId, int $artistBlockSize): ?array
    {
        $stmt = $db->prepare('
            SELECT
                ps.position,
                s.id         AS song_id,
                s.title,
                s.year,
                s.file_path,
                s.duration_ms,
                s.artist_id,
                s.loudness_gain_db,
                s.cue_in,
                s.cue_out,
                s.energy,
                s.valence,
                s.ending_type,
                s.ending_energy,
                s.intro_energy,
                a.name       AS artist_name,
                c.name       AS category_name
            FROM playlist_songs ps
            JOIN songs      s ON s.id = ps.song_id
            JOIN artists    a ON a.id = s.artist_id
            JOIN categories c ON c.id = s.category_id
            WHERE ps.playlist_id    = :playlist_id
              AND ps.played_in_cycle = false
              AND s.is_active        = true
              AND s.duplicate_of IS NULL
            ORDER BY ps.position ASC
        ');
        $stmt->execute(['playlist_id' => $playlistId]);
        $candidates = $stmt->fetchAll();

        if (empty($candidates)) {
            return null;
        }

        // Collect eligible candidates
        $eligible = [];
        foreach ($candidates as $candidate) {
            if (RotationEngine::isArtistBlocked($db, (int) $candidate['artist_id'], $artistBlockSize)) {
                continue;
            }
            if (RotationEngine::isTitleBlocked($db, $candidate['title'], (int) $candidate['song_id'])) {
                continue;
            }
            if (RotationEngine::isSongRecentlyPlayed($db, (int) $candidate['song_id'])) {
                continue;
            }
            $eligible[] = $candidate;
        }

        if (empty($eligible)) {
            return $candidates[0];
        }

        // If mood programming is enabled and we have mood data, bias selection
        return $this->pickWithMoodBias($db, $eligible) ?? $eligible[0];
    }

    /**
     * Fetch the next song for an auto playlist.
     *
     * Selects from songs matching the playlist rules (categories + min_weight),
     * ordered by least recently played. No cycle tracking involved.
     */
    private function fetchNextAutoSong(PDO $db, ?array $rules, int $artistBlockSize): ?array
    {
        $categories = $rules['categories'] ?? [];
        $minWeight  = (float) ($rules['min_weight'] ?? 0.0);
        $params     = ['min_weight' => $minWeight];

        if (!empty($categories)) {
            $placeholders = [];
            foreach ($categories as $i => $catId) {
                $key            = 'cat_' . $i;
                $placeholders[] = ':' . $key;
                $params[$key]   = (int) $catId;
            }
            $categoryClause = 'AND s.category_id IN (' . implode(',', $placeholders) . ')';
        } else {
            $categoryClause = "AND c.type = 'music'";
        }

        $artists = $rules['artists'] ?? [];
        if (!empty($artists)) {
            $artPlaceholders = [];
            foreach ($artists as $i => $artId) {
                $key               = 'art_' . $i;
                $artPlaceholders[] = ':' . $key;
                $params[$key]      = (int) $artId;
            }
            $artistClause = 'AND s.artist_id IN (' . implode(',', $artPlaceholders) . ')';
        } else {
            $artistClause = '';
        }

        $years = $rules['years'] ?? [];
        if (!empty($years)) {
            $yearPlaceholders = [];
            foreach ($years as $i => $yr) {
                $key                = 'yr_' . $i;
                $yearPlaceholders[] = ':' . $key;
                $params[$key]       = (int) $yr;
            }
            $yearClause = 'AND s.year IN (' . implode(',', $yearPlaceholders) . ')';
        } else {
            $yearClause = '';
        }

        $excluded = $rules['excluded_songs'] ?? [];
        if (!empty($excluded)) {
            $exPlaceholders = [];
            foreach ($excluded as $i => $exId) {
                $key              = 'ex_' . $i;
                $exPlaceholders[] = ':' . $key;
                $params[$key]     = (int) $exId;
            }
            $excludeClause = 'AND s.id NOT IN (' . implode(',', $exPlaceholders) . ')';
        } else {
            $excludeClause = '';
        }

        $stmt = $db->prepare("
            SELECT
                0            AS position,
                s.id         AS song_id,
                s.title,
                s.year,
                s.file_path,
                s.duration_ms,
                s.artist_id,
                s.loudness_gain_db,
                s.cue_in,
                s.cue_out,
                s.energy,
                s.valence,
                s.ending_type,
                s.ending_energy,
                s.intro_energy,
                a.name       AS artist_name,
                c.name       AS category_name
            FROM songs s
            JOIN artists    a ON a.id = s.artist_id
            JOIN categories c ON c.id = s.category_id
            WHERE s.is_active = true
              AND s.rotation_weight >= :min_weight
              AND s.duplicate_of IS NULL
              {$categoryClause}
              {$artistClause}
              {$yearClause}
              {$excludeClause}
            ORDER BY COALESCE(s.last_played_at, '1970-01-01'::timestamptz) ASC, random()
            LIMIT 50
        ");
        $stmt->execute($params);
        $candidates = $stmt->fetchAll();

        if (empty($candidates)) {
            return null;
        }

        // Collect eligible candidates (not blocked by artist or title),
        // then pick randomly from the top few to add variety between cycles.
        // Without this, last_played_at ordering creates the same deterministic
        // sequence every cycle.
        $eligible = [];
        foreach ($candidates as $candidate) {
            if (RotationEngine::isArtistBlocked($db, (int) $candidate['artist_id'], $artistBlockSize)) {
                continue;
            }
            if (RotationEngine::isTitleBlocked($db, $candidate['title'], (int) $candidate['song_id'])) {
                continue;
            }
            if (RotationEngine::isSongRecentlyPlayed($db, (int) $candidate['song_id'])) {
                continue;
            }
            $eligible[] = $candidate;
        }

        if (empty($eligible)) {
            // All candidates blocked — play the least-recently-played one anyway
            return $candidates[0];
        }

        // If mood programming is enabled, use mood bias for selection
        $moodPick = $this->pickWithMoodBias($db, $eligible);
        if ($moodPick) {
            return $moodPick;
        }

        // Pick randomly from the top N eligible to shuffle between cycles
        $poolSize = min(count($eligible), max(3, (int) ceil(count($candidates) / 3)));
        $pool = array_slice($eligible, 0, $poolSize);
        return $pool[array_rand($pool)];
    }

    // ── Request injection ───────────────────────────────────

    /**
     * Get the source of the most recent play_history entry.
     */
    private function getLastPlaySource(PDO $db): ?string
    {
        $stmt = $db->query('
            SELECT source FROM play_history ORDER BY started_at DESC LIMIT 1
        ');
        $row = $stmt->fetch();
        return $row ? $row['source'] : null;
    }

    /**
     * Try to find an eligible request from the queue.
     *
     * Walks request_queue in position order. For each candidate:
     * 1. Song must still be active
     * 2. Song must not have been played recently (no repeat within cycle)
     * 3. Artist must not be blocked
     *
     * Failed candidates are rejected with reason 'rotation_rule'.
     * Returns song data + request metadata if found, null otherwise.
     */
    private function tryPlayRequest(PDO $db, int $playlistId, int $artistBlockSize): ?array
    {
        $stmt = $db->query('
            SELECT
                rq.id         AS queue_id,
                rq.request_id,
                rq.song_id,
                s.title,
                s.file_path,
                s.duration_ms,
                s.artist_id,
                s.loudness_gain_db,
                s.cue_in,
                s.cue_out,
                s.is_active,
                a.name        AS artist_name,
                c.name        AS category_name
            FROM request_queue rq
            JOIN songs      s  ON s.id = rq.song_id
            JOIN artists    a  ON a.id = s.artist_id
            JOIN categories c  ON c.id = s.category_id
            JOIN song_requests sr ON sr.id = rq.request_id
            WHERE sr.status = \'approved\'
            ORDER BY rq.position ASC
        ');
        $candidates = $stmt->fetchAll();

        if (empty($candidates)) {
            return null;
        }

        // Load current/next song IDs to prevent consecutive same-song plays
        $stateStmt = $db->query('SELECT current_song_id, next_song_id FROM rotation_state WHERE id = 1');
        $rotState = $stateStmt->fetch();
        $currentSongId = $rotState ? (int) ($rotState['current_song_id'] ?? 0) : 0;
        $nextSongId    = $rotState ? (int) ($rotState['next_song_id'] ?? 0) : 0;

        foreach ($candidates as $candidate) {
            // Reject if the song has been deactivated
            if (!$candidate['is_active']) {
                $this->rejectRequest($db, (int) $candidate['request_id'], (int) $candidate['queue_id'], 'song_inactive');
                continue;
            }

            // Skip (don't reject) if same song is currently playing or queued next —
            // leave it in the queue for a later rotation cycle to pick up
            $candidateSongId = (int) $candidate['song_id'];
            if ($candidateSongId === $currentSongId || $candidateSongId === $nextSongId) {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    /**
     * Commit a request song play in a single transaction.
     *
     * Does NOT advance rotation position — rotation resumes where it left off.
     */
    private function commitRequestPlay(
        PDO $db,
        array $song,
        int $playlistId,
        ?int $scheduleId,
        array $state,
        int $cycle
    ): void {
        $db->beginTransaction();
        try {
            // Update last_played_at (prevents re-pick)
            $db->prepare('
                UPDATE songs SET last_played_at = NOW() WHERE id = :id
            ')->execute(['id' => $song['song_id']]);

            // Mark request as played
            $db->prepare('
                UPDATE song_requests SET status = :status, played_at = NOW()
                WHERE id = :id
            ')->execute(['status' => 'played', 'id' => $song['request_id']]);

            // Remove from queue
            $db->prepare('DELETE FROM request_queue WHERE id = :id')
                ->execute(['id' => $song['queue_id']]);

            // Update artist block list
            $artistIds = $this->parseIntArray($state['last_artist_ids'] ?? '{}');
            array_unshift($artistIds, (int) $song['artist_id']);
            $artistIds = array_slice($artistIds, 0, 10);
            $pgArray   = '{' . implode(',', $artistIds) . '}';

            // Update rotation state — store source for TrackStartedHandler
            $db->prepare('
                UPDATE rotation_state
                SET songs_since_station_id  = songs_since_station_id + 1,
                    last_artist_ids     = :artist_ids,
                    next_playlist_id    = :next_playlist_id,
                    next_song_id        = :next_song_id,
                    next_schedule_id    = :next_schedule_id,
                    next_source         = :next_source
                WHERE id = 1
            ')->execute([
                'artist_ids'       => $pgArray,
                'next_playlist_id' => $playlistId,
                'next_song_id'     => $song['song_id'],
                'next_schedule_id' => $scheduleId,
                'next_source'      => 'request',
            ]);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log('NextTrack request commit failed: ' . $e->getMessage());
            Response::error('Failed to commit request play', 500);
        }
    }

    /**
     * Reject a request due to rotation rule and remove from queue.
     */
    private function rejectRequest(PDO $db, int $requestId, int $queueId, string $reason): void
    {
        $db->prepare('
            UPDATE song_requests SET status = :status, rejected_reason = :reason
            WHERE id = :id
        ')->execute(['status' => 'rejected', 'reason' => $reason, 'id' => $requestId]);

        $db->prepare('DELETE FROM request_queue WHERE id = :id')
            ->execute(['id' => $queueId]);
    }

    // ── Utility helpers ─────────────────────────────────────

    private function parseIntArray(string $pgArray): array
    {
        $trimmed = trim($pgArray, '{}');
        if ($trimmed === '') {
            return [];
        }
        return array_map('intval', explode(',', $trimmed));
    }

    /**
     * Lightweight check: is a segment due right now?
     * Returns JSON {"due": true/false} without side effects.
     * Called by Liquidsoap every 15s to decide whether to skip current track.
     */
    public function segmentDue(): void
    {
        $db = Database::get();
        if ($this->getSetting($db, 'segment_scheduling_enabled', 'false') !== 'true') {
            Response::json(['due' => false]);
            return;
        }

        $tz = $this->getSetting($db, 'station_timezone', 'UTC');

        // Check fixed-time segments (narrower 1-min window for immediacy)
        $stmt = $db->prepare("
            SELECT 1 FROM segments
            WHERE is_active = true
              AND interval_minutes IS NULL
              AND play_time BETWEEN (NOW() AT TIME ZONE :tz)::time - INTERVAL '1 minute'
                                AND (NOW() AT TIME ZONE :tz2)::time + INTERVAL '1 minute'
              AND (days_of_week IS NULL
                   OR EXTRACT(DOW FROM NOW() AT TIME ZONE :tz3)::int = ANY(days_of_week))
              AND (last_played_at IS NULL
                   OR (last_played_at AT TIME ZONE :tz4)::date < (NOW() AT TIME ZONE :tz5)::date)
            LIMIT 1
        ");
        $stmt->execute(['tz' => $tz, 'tz2' => $tz, 'tz3' => $tz, 'tz4' => $tz, 'tz5' => $tz]);

        if ($stmt->fetch()) {
            Response::json(['due' => true]);
            return;
        }

        // Check interval-based segments
        $stmt = $db->prepare("
            SELECT 1 FROM segments
            WHERE is_active = true
              AND interval_minutes IS NOT NULL
              AND (NOW() AT TIME ZONE :tz)::time >= play_time
              AND (days_of_week IS NULL
                   OR EXTRACT(DOW FROM NOW() AT TIME ZONE :tz2)::int = ANY(days_of_week))
              AND (last_played_at IS NULL
                   OR (NOW() >= last_played_at + (interval_minutes || ' minutes')::interval
                       AND (last_played_at AT TIME ZONE :tz3)::date <= (NOW() AT TIME ZONE :tz4)::date))
            LIMIT 1
        ");
        $stmt->execute(['tz' => $tz, 'tz2' => $tz, 'tz3' => $tz, 'tz4' => $tz]);

        Response::json(['due' => (bool) $stmt->fetch()]);
    }

    private function getSettingInt(PDO $db, string $key, int $default): int
    {
        $stmt = $db->prepare('SELECT value FROM settings WHERE key = :key');
        $stmt->execute(['key' => $key]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (int) $val : $default;
    }

    private function getSetting(PDO $db, string $key, string $default): string
    {
        $stmt = $db->prepare('SELECT value FROM settings WHERE key = :key');
        $stmt->execute(['key' => $key]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (string) $val : $default;
    }

    /**
     * Find the active emergency playlist ID, or null if none configured.
     */
    private function findEmergencyPlaylist(PDO $db): ?int
    {
        $stmt = $db->query("
            SELECT id FROM playlists
            WHERE type = 'emergency' AND is_active = true
            ORDER BY id ASC LIMIT 1
        ");
        $epId = $stmt->fetchColumn();
        return $epId !== false ? (int) $epId : null;
    }

    /**
     * Auto-activate emergency mode (schedule-gap fallback).
     * Sets emergency_mode ON and marks it as auto-activated so it can be
     * auto-disabled when a schedule is later added.
     */
    private function enableAutoEmergency(PDO $db): void
    {
        $update = $db->prepare("
            INSERT INTO settings (key, value, type, description)
            VALUES (:key, :val, 'boolean', :desc)
            ON CONFLICT (key) DO UPDATE SET value = :val2
        ");

        $update->execute([
            'key'  => 'emergency_mode',
            'val'  => 'true',
            'val2' => 'true',
            'desc' => 'When true, play emergency playlist only',
        ]);
        $update->execute([
            'key'  => 'emergency_auto_activated',
            'val'  => 'true',
            'val2' => 'true',
            'desc' => 'True when emergency was auto-activated by schedule gap',
        ]);

        $db->prepare('UPDATE rotation_state SET is_emergency = true WHERE id = 1')->execute();

        // Log the auto-activation
        $db->prepare("
            INSERT INTO station_logs (level, component, message)
            VALUES ('info', 'emergency', 'Emergency mode auto-activated (no active schedule)')
        ")->execute();
    }

    /**
     * Auto-disable emergency mode when a schedule is found.
     * Only called when emergency_auto_activated = 'true'.
     */
    private function disableAutoEmergency(PDO $db): void
    {
        $update = $db->prepare("
            UPDATE settings SET value = :val WHERE key = :key
        ");
        $update->execute(['val' => 'false', 'key' => 'emergency_mode']);
        $update->execute(['val' => 'false', 'key' => 'emergency_auto_activated']);

        $db->prepare('UPDATE rotation_state SET is_emergency = false WHERE id = 1')->execute();

        // Log the auto-deactivation
        $db->prepare("
            INSERT INTO station_logs (level, component, message)
            VALUES ('info', 'emergency', 'Emergency mode auto-deactivated (schedule found)')
        ")->execute();
    }

    // ── TTS pre-roll generation ──────────────────────────────

    /**
     * Generate a TTS pre-roll audio file for the upcoming track.
     * (Time announcements are handled by Liquidsoap's station ID overlay.)
     * Returns the absolute path to the TTS MP3, or null if disabled/failed.
     */
    private function generateTtsPreRoll(PDO $db, array $state, array $song): ?string
    {
        $ttsSettings = $this->getTtsSettings($db);
        if ($ttsSettings === null) {
            return null;
        }

        require_once __DIR__ . '/../../core/TtsEngine.php';

        return $this->trySongAnnounceTts($ttsSettings, $song);
    }

    /**
     * Load TTS settings. Returns null if no TTS feature is enabled.
     */
    private function getTtsSettings(PDO $db): ?array
    {
        $stmt = $db->query("
            SELECT key, value FROM settings
            WHERE key LIKE 'tts_%' OR key = 'station_timezone'
        ");
        $settings = [];
        while ($row = $stmt->fetch()) {
            $settings[$row['key']] = $row['value'];
        }

        $anyEnabled = ($settings['tts_song_announce_enabled'] ?? 'false') === 'true';

        return $anyEnabled ? $settings : null;
    }

    /**
     * Generate a song announcement TTS if enabled.
     */
    private function trySongAnnounceTts(array $s, array $song): ?string
    {
        if (($s['tts_song_announce_enabled'] ?? 'false') !== 'true') {
            return null;
        }

        $template = $s['tts_song_announce_template'] ?? 'Now playing {title} by {artist}';
        $text     = str_replace(
            ['{title}', '{artist}'],
            [$song['title'] ?? '', $song['artist_name'] ?? ''],
            $template
        );

        return TtsEngine::generate(
            $text,
            $s['tts_voice'] ?? 'male',
            (int) ($s['tts_speed'] ?? 160)
        );
    }

    // ── Mood-biased selection ────────────────────────────

    /**
     * Pick from eligible candidates biased toward the current mood target.
     *
     * When mood_programming_enabled is true and songs have mood data,
     * scores each candidate by distance to the daypart target (optionally
     * with weather bias) and picks from the top 3 closest.
     *
     * @return array|null Selected song, or null if mood programming disabled
     */
    private function pickWithMoodBias(PDO $db, array $eligible): ?array
    {
        if ($this->getSetting($db, 'mood_programming_enabled', 'false') !== 'true') {
            return null;
        }

        // Filter to songs with mood data
        $withMood = array_filter($eligible, fn($s) => $s['energy'] !== null && $s['valence'] !== null);
        if (count($withMood) < 2) {
            return null; // Not enough mood data to be useful
        }

        require_once __DIR__ . '/../../core/MoodEngine.php';

        $tz     = $this->getSetting($db, 'station_timezone', 'UTC');
        $target = MoodEngine::getTargetMood($tz);

        // Apply weather bias if enabled
        if ($this->getSetting($db, 'weather_reactive_enabled', 'false') === 'true') {
            $weather = $this->fetchCurrentWeather($db);
            if ($weather) {
                $target = MoodEngine::applyWeatherBias($target, $weather);
            }
        }

        // Score each candidate
        $scored = [];
        foreach ($withMood as $song) {
            $dist = MoodEngine::moodDistance(
                (float) $song['energy'],
                (float) $song['valence'],
                $target['energy_target'],
                $target['valence_target']
            );
            $scored[] = ['song' => $song, 'distance' => $dist];
        }

        // Sort by distance (closest to target first)
        usort($scored, fn($a, $b) => $a['distance'] <=> $b['distance']);

        // Pick from top 3 to maintain variety
        $poolSize = min(count($scored), 3);
        $pool = array_slice($scored, 0, $poolSize);
        return $pool[array_rand($pool)]['song'];
    }

    /**
     * Fetch current weather data from cache or API.
     */
    private function fetchCurrentWeather(PDO $db): ?array
    {
        // Read cached weather from /tmp
        $cacheFile = '/tmp/rendezvox_weather.json';
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 1800) {
            $data = json_decode((string) @file_get_contents($cacheFile), true);
            if ($data) return $data;
        }

        // Fetch from OpenMeteo
        $lat = $this->getSetting($db, 'weather_latitude', '18.2644');
        $lon = $this->getSetting($db, 'weather_longitude', '121.9910');

        $url = 'https://api.open-meteo.com/v1/forecast?latitude=' . urlencode($lat)
             . '&longitude=' . urlencode($lon)
             . '&current_weather=true';

        $ctx = stream_context_create(['http' => ['timeout' => 5]]);
        $resp = @file_get_contents($url, false, $ctx);
        if (!$resp) return null;

        $json = json_decode($resp, true);
        $weather = $json['current_weather'] ?? null;
        if ($weather) {
            @file_put_contents($cacheFile, json_encode($weather), LOCK_EX);
        }

        return $weather;
    }

    // ── Segment injection ────────────────────────────────

    /**
     * Check if a scheduled segment is due for playback.
     *
     * Returns segment data if one is due (within a 2-minute window
     * and not yet played today), null otherwise.
     */
    /**
     * Smart jingle: inject station ID at natural energy breaks.
     * Triggers when: smart_jingle_enabled + enough songs since last ID +
     * previous song had a low-energy ending (fade/silence/low ending_energy).
     */
    private function checkSmartJingleDue(PDO $db, array $state): ?array
    {
        if ($this->getSetting($db, 'smart_jingle_enabled', 'false') !== 'true') {
            return null;
        }

        $songsSince = (int) ($state['songs_since_station_id'] ?? 0);
        if ($songsSince < 5) {
            return null; // Too soon — minimum 5 songs between jingles
        }

        // Check the previous song's ending mood
        $prevSongId = $state['current_song_id'] ?? null;
        if (!$prevSongId) {
            return null;
        }

        $stmt = $db->prepare('SELECT ending_type, ending_energy FROM songs WHERE id = :id');
        $stmt->execute(['id' => $prevSongId]);
        $prev = $stmt->fetch();

        if (!$prev) {
            return null;
        }

        // Natural break: fade/silence ending, or low ending energy
        $endType   = $prev['ending_type'] ?? null;
        $endEnergy = $prev['ending_energy'] !== null ? (float) $prev['ending_energy'] : null;

        $isNaturalBreak = false;
        if ($endType === 'fade' || $endType === 'silence') {
            $isNaturalBreak = true;
        } elseif ($endEnergy !== null && $endEnergy <= 0.3) {
            $isNaturalBreak = true;
        }

        // After 12+ songs, inject regardless (don't go too long without ID)
        if ($songsSince >= 12) {
            $isNaturalBreak = true;
        }

        if (!$isNaturalBreak) {
            return null;
        }

        // Pick a station ID file (sequential round-robin)
        $stationIdDir = '/var/lib/rendezvox/stationids';
        $stateFile    = '/tmp/stationid_last_index';

        $files = glob($stationIdDir . '/*.{mp3,ogg,wav,flac,aac,m4a}', GLOB_BRACE);
        if (!$files) {
            return null;
        }
        sort($files);

        $lastIdx = @file_get_contents($stateFile);
        $lastIdx = $lastIdx !== false ? (int) $lastIdx : -1;
        $nextIdx = ($lastIdx + 1) % count($files);
        @file_put_contents($stateFile, (string) $nextIdx);

        // Reset the counter
        $db->prepare('UPDATE rotation_state SET songs_since_station_id = 0 WHERE id = 1')
           ->execute();

        // Optional time announcement TTS
        $ttsPath = null;
        $tz = $this->getSetting($db, 'station_timezone', 'UTC');
        $ttsEnabled = $this->getSetting($db, 'tts_time_announcement', 'false');
        if ($ttsEnabled === 'true') {
            // Reuse the TTS time announcement endpoint logic
            $ttsFile = '/var/lib/rendezvox/tts/time_' . date('H_i') . '.mp3';
            if (file_exists($ttsFile)) {
                $ttsPath = $ttsFile;
            }
        }

        return [
            'file_path' => $files[$nextIdx],
            'tts_path'  => $ttsPath,
        ];
    }

    private const LIBRARY_SEGMENT_TYPES = ['opm', 'song_pick', 'music_block'];
    private const MUSIC_DIR = '/var/lib/rendezvox/music';

    private function checkSegmentDue(PDO $db): ?array
    {
        if ($this->getSetting($db, 'segment_scheduling_enabled', 'false') !== 'true') {
            return null;
        }

        $tz = $this->getSetting($db, 'station_timezone', 'UTC');

        // Fixed-time segments: play once per day at play_time
        $stmt = $db->prepare("
            SELECT id, name, file_path, duration_ms, segment_type, next_file_index, library_config, interval_minutes
            FROM segments
            WHERE is_active = true
              AND interval_minutes IS NULL
              AND play_time BETWEEN (NOW() AT TIME ZONE :tz)::time - INTERVAL '1 minute'
                                AND (NOW() AT TIME ZONE :tz2)::time + INTERVAL '1 minute'
              AND (days_of_week IS NULL
                   OR EXTRACT(DOW FROM NOW() AT TIME ZONE :tz3)::int = ANY(days_of_week))
              AND (last_played_at IS NULL
                   OR (last_played_at AT TIME ZONE :tz4)::date < (NOW() AT TIME ZONE :tz5)::date)
            ORDER BY priority DESC
            LIMIT 1
        ");
        $stmt->execute(['tz' => $tz, 'tz2' => $tz, 'tz3' => $tz, 'tz4' => $tz, 'tz5' => $tz]);
        $segment = $stmt->fetch();

        // Interval-based segments: play every N minutes, starting from play_time
        // Only plays if today is an allowed day AND current time >= play_time
        if (!$segment) {
            $stmt = $db->prepare("
                SELECT id, name, file_path, duration_ms, segment_type, next_file_index, library_config, interval_minutes
                FROM segments
                WHERE is_active = true
                  AND interval_minutes IS NOT NULL
                  AND (NOW() AT TIME ZONE :tz)::time >= play_time
                  AND (days_of_week IS NULL
                       OR EXTRACT(DOW FROM NOW() AT TIME ZONE :tz2)::int = ANY(days_of_week))
                  AND (last_played_at IS NULL
                       OR (NOW() >= last_played_at + (interval_minutes || ' minutes')::interval
                           AND (last_played_at AT TIME ZONE :tz3)::date <= (NOW() AT TIME ZONE :tz4)::date))
                ORDER BY priority DESC
                LIMIT 1
            ");
            $stmt->execute(['tz' => $tz, 'tz2' => $tz, 'tz3' => $tz, 'tz4' => $tz]);
            $segment = $stmt->fetch();
        }

        if (!$segment) {
            return null;
        }

        // Library-based segment types — pick a song from the library
        if (in_array($segment['segment_type'], self::LIBRARY_SEGMENT_TYPES, true)) {
            return $this->resolveLibrarySegment($db, $segment);
        }

        // File-based: check rotation files first
        $filesStmt = $db->prepare("
            SELECT id, file_path, duration_ms FROM segment_files
            WHERE segment_id = :sid ORDER BY position
        ");
        $filesStmt->execute(['sid' => $segment['id']]);
        $rotationFiles = $filesStmt->fetchAll(\PDO::FETCH_ASSOC);

        if (!empty($rotationFiles)) {
            $idx = (int) $segment['next_file_index'];
            $count = count($rotationFiles);
            if ($idx >= $count) $idx = 0;

            $pick = $rotationFiles[$idx];

            $tried = 0;
            while (!file_exists($pick['file_path']) && $tried < $count) {
                $idx = ($idx + 1) % $count;
                $pick = $rotationFiles[$idx];
                $tried++;
            }
            if (!file_exists($pick['file_path'])) {
                return null;
            }

            $nextIdx = ($idx + 1) % $count;
            $db->prepare("UPDATE segments SET last_played_at = NOW(), next_file_index = :nfi WHERE id = :id")
               ->execute(['nfi' => $nextIdx, 'id' => $segment['id']]);
            $db->prepare("UPDATE segment_files SET last_played_at = NOW() WHERE id = :fid")
               ->execute(['fid' => $pick['id']]);

            $segment['file_path']   = $pick['file_path'];
            $segment['duration_ms'] = $pick['duration_ms'];
        } else {
            if (empty($segment['file_path']) || !file_exists($segment['file_path'])) {
                return null;
            }
            $db->prepare("UPDATE segments SET last_played_at = NOW() WHERE id = :id")
               ->execute(['id' => $segment['id']]);
        }

        return $segment;
    }

    /**
     * Resolve a library-based segment to a playable song.
     * opm: random song from OPM category
     * song_pick: round-robin through specific song IDs
     * music_block: random song from a specific category
     */
    private function resolveLibrarySegment(PDO $db, array $segment): ?array
    {
        $config = $segment['library_config'] ? json_decode($segment['library_config'], true) : [];
        $song = null;


        switch ($segment['segment_type']) {
            case 'opm':
                // Pick random active song from OPM category
                $catId = $config['category_id'] ?? null;
                if (!$catId) {
                    // Find category named 'OPM'
                    $catStmt = $db->query("SELECT id FROM categories WHERE LOWER(name) = 'opm' LIMIT 1");
                    $catId = $catStmt->fetchColumn();
                }
                if (!$catId) return null;

                $songStmt = $db->prepare("
                    SELECT s.id, s.title, s.file_path, s.duration_ms, s.loudness_gain_db, s.cue_in, s.cue_out,
                           a.name AS artist
                    FROM songs s
                    LEFT JOIN artists a ON a.id = s.artist_id
                    WHERE s.category_id = :cat AND s.is_active = true AND s.trashed_at IS NULL
                      AND s.file_path IS NOT NULL
                    ORDER BY RANDOM()
                    LIMIT 5
                ");
                $songStmt->execute(['cat' => $catId]);
                foreach ($songStmt->fetchAll(\PDO::FETCH_ASSOC) as $candidate) {
                    if (file_exists(self::MUSIC_DIR . '/' . $candidate['file_path'])) {
                        $song = $candidate;
                        break;
                    }
                }
                break;

            case 'song_pick':
                // Round-robin through configured song IDs
                $songIds = $config['song_ids'] ?? [];
                if (empty($songIds)) return null;

                $idx = (int) $segment['next_file_index'];
                $count = count($songIds);
                if ($idx >= $count) $idx = 0;

                // Try to find a valid song, cycling through if needed
                $tried = 0;
                while ($tried < $count) {
                    $pickId = (int) $songIds[$idx];
                    $songStmt = $db->prepare("
                        SELECT s.id, s.title, s.file_path, s.duration_ms, s.loudness_gain_db, s.cue_in, s.cue_out,
                               a.name AS artist
                        FROM songs s
                        LEFT JOIN artists a ON a.id = s.artist_id
                        WHERE s.id = :id AND s.is_active = true AND s.trashed_at IS NULL
                    ");
                    $songStmt->execute(['id' => $pickId]);
                    $song = $songStmt->fetch(\PDO::FETCH_ASSOC);
                    if ($song && file_exists(self::MUSIC_DIR . '/' . $song['file_path'])) break;
                    $song = null;
                    $idx = ($idx + 1) % $count;
                    $tried++;
                }

                // Always advance index so rotation doesn't get stuck on a missing song
                $nextIdx = ($idx + 1) % $count;
                $db->prepare("UPDATE segments SET next_file_index = :nfi WHERE id = :id")
                   ->execute(['nfi' => $nextIdx, 'id' => $segment['id']]);
                break;

            case 'music_block':
                // Random song from a specific category
                $catId = $config['category_id'] ?? null;
                if (!$catId) return null;

                $songStmt = $db->prepare("
                    SELECT s.id, s.title, s.file_path, s.duration_ms, s.loudness_gain_db, s.cue_in, s.cue_out,
                           a.name AS artist
                    FROM songs s
                    LEFT JOIN artists a ON a.id = s.artist_id
                    WHERE s.category_id = :cat AND s.is_active = true AND s.trashed_at IS NULL
                      AND s.file_path IS NOT NULL
                    ORDER BY RANDOM()
                    LIMIT 5
                ");
                $songStmt->execute(['cat' => $catId]);
                foreach ($songStmt->fetchAll(\PDO::FETCH_ASSOC) as $candidate) {
                    if (file_exists(self::MUSIC_DIR . '/' . $candidate['file_path'])) {
                        $song = $candidate;
                        break;
                    }
                }
                break;
        }

        if (!$song) {
            return null;
        }

        $db->prepare("UPDATE segments SET last_played_at = NOW() WHERE id = :id")
           ->execute(['id' => $segment['id']]);

        // Return in segment format
        $segment['file_path']   = $song['file_path'];
        $segment['duration_ms'] = (int) $song['duration_ms'];
        $segment['_song_id']    = (int) $song['id'];
        $segment['_song_title'] = $song['title'];
        $segment['_song_artist'] = $song['artist'] ?? '';
        $segment['_gain_db']    = (float) ($song['loudness_gain_db'] ?? 0);
        $segment['_cue_in']     = $song['cue_in'] ? (float) $song['cue_in'] : null;
        $segment['_cue_out']    = $song['cue_out'] ? (float) $song['cue_out'] : null;

        return $segment;
    }
}
