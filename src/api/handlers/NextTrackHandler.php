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
                    ],
                    'source'      => 'request',
                    'request_id'  => (int) $requestSong['request_id'],
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
                    songs_since_jingle  = songs_since_jingle + 1,
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

        // ── 6. Return song data ───────────────────────────
        Response::json([
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
            ],
            'source'       => $isEmergency ? 'emergency' : 'rotation',
            'is_emergency' => $isEmergency,
            'request_id'   => null,
            'position'    => (int) ($song['position'] ?? 0),
            'cycle'       => $cycle,
            'cycle_reset' => $cycleReset,
            'playlist_id' => (int) $playlistId,
            'schedule_id' => $scheduleId ? (int) $scheduleId : null,
        ]);
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
              AND ((EXTRACT(ISODOW FROM NOW() AT TIME ZONE :tz)::int - 1) = ANY(s.days_of_week)
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
                a.name       AS artist_name,
                c.name       AS category_name
            FROM playlist_songs ps
            JOIN songs      s ON s.id = ps.song_id
            JOIN artists    a ON a.id = s.artist_id
            JOIN categories c ON c.id = s.category_id
            WHERE ps.playlist_id    = :playlist_id
              AND ps.played_in_cycle = false
              AND s.is_active        = true
            ORDER BY ps.position ASC
        ');
        $stmt->execute(['playlist_id' => $playlistId]);
        $candidates = $stmt->fetchAll();

        if (empty($candidates)) {
            return null;
        }

        foreach ($candidates as $candidate) {
            if (RotationEngine::isArtistBlocked($db, (int) $candidate['artist_id'], $artistBlockSize)) {
                continue;
            }
            if (RotationEngine::isTitleBlocked($db, $candidate['title'], (int) $candidate['song_id'])) {
                continue;
            }
            return $candidate;
        }

        return $candidates[0];
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
                a.name       AS artist_name,
                c.name       AS category_name
            FROM songs s
            JOIN artists    a ON a.id = s.artist_id
            JOIN categories c ON c.id = s.category_id
            WHERE s.is_active = true
              AND s.rotation_weight >= :min_weight
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
            $eligible[] = $candidate;
        }

        if (empty($eligible)) {
            // All candidates blocked — play the least-recently-played one anyway
            return $candidates[0];
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

        foreach ($candidates as $candidate) {
            // Only reject if the song has been deactivated.
            // Rotation rules (recently-played, artist-block) do NOT apply
            // to requests — the listener explicitly asked for this song.
            if (!$candidate['is_active']) {
                $this->rejectRequest($db, (int) $candidate['request_id'], (int) $candidate['queue_id'], 'song_inactive');
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
                SET songs_since_jingle  = songs_since_jingle + 1,
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
}
