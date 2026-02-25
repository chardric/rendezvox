<?php

declare(strict_types=1);

/**
 * POST /api/track-started
 *
 * Called by Liquidsoap when a track actually begins playing
 * (via source.on_metadata). Updates the display state in
 * rotation_state so the dashboard/now-playing shows the
 * correct currently-playing track.
 *
 * Body: { "song_id": int }
 */
class TrackStartedHandler
{
    public function handle(): void
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $songId = (int) ($input['song_id'] ?? 0);

        if ($songId <= 0) {
            Response::json(['error' => 'Missing or invalid song_id'], 400);
            return;
        }

        $db = Database::get();
        Database::ensureRotationState();

        // Close out ALL stale open play_history rows (any song except the new
        // one starting now). This covers:
        //   1. The previous track (safety net if track-played was missed)
        //   2. Orphaned pre-fetch rows from rapid skipping / repeated
        //      NextTrackHandler calls that were never actually played
        $prevStmt = $db->prepare('
            UPDATE play_history
            SET ended_at = NOW()
            WHERE ended_at IS NULL
              AND song_id != :new_song_id
        ');
        $prevStmt->execute(['new_song_id' => $songId]);

        // Read rotation_state to get playlist/schedule context
        // stored by NextTrackHandler when the song was queued
        $rsStmt = $db->query('
            SELECT next_playlist_id, next_schedule_id, next_source
            FROM rotation_state WHERE id = 1
        ');
        $rs = $rsStmt->fetch();
        $playlistId = $rs ? ($rs['next_playlist_id'] ?: null) : null;
        $scheduleId = $rs ? ($rs['next_schedule_id'] ?: null) : null;

        // Source and request_id travel with each track through Liquidsoap
        // metadata, so they stay correct even when multiple tracks are
        // pre-fetched (which overwrites the single rotation_state.next_source).
        // Fall back to rotation_state for backwards compatibility.
        $source    = !empty($input['source']) ? $input['source'] : ($rs ? ($rs['next_source'] ?: 'rotation') : 'rotation');
        $requestId = (int) ($input['request_id'] ?? 0);

        // Validate source value
        $validSources = ['rotation', 'request', 'manual', 'emergency', 'jingle'];
        if (!in_array($source, $validSources, true)) {
            $source = 'rotation';
        }

        // NOW insert play_history — the song is actually starting
        $db->prepare('
            INSERT INTO play_history (song_id, playlist_id, schedule_id, source, started_at)
            VALUES (:song_id, :playlist_id, :schedule_id, :source, NOW())
        ')->execute([
            'song_id'     => $songId,
            'playlist_id' => $playlistId,
            'schedule_id' => $scheduleId,
            'source'      => $source,
        ]);

        // If this is a request, update played_at to the actual play time
        if ($requestId > 0) {
            $db->prepare('
                UPDATE song_requests SET played_at = NOW()
                WHERE id = :id AND status = :status
            ')->execute(['id' => $requestId, 'status' => 'played']);
        }

        // Increment play_count now that the song is actually playing
        $db->prepare('
            UPDATE songs SET play_count = play_count + 1 WHERE id = :id
        ')->execute(['id' => $songId]);

        // Promote to current. Only clear next_song_id if it still points
        // to this song — Liquidsoap may have already requested the next-next
        // track (via get_next_track) before the crossfade delay completed,
        // so next_song_id could already hold a different song.
        $db->prepare('
            UPDATE rotation_state
            SET current_song_id     = :song_id,
                current_playlist_id = next_playlist_id,
                next_song_id        = CASE WHEN next_song_id = :song_id2
                                           THEN NULL
                                           ELSE next_song_id END,
                next_source         = CASE WHEN next_song_id = :song_id3
                                           THEN \'rotation\'
                                           ELSE next_source END,
                started_at          = NOW(),
                is_playing          = true
            WHERE id = 1
        ')->execute([
            'song_id'  => $songId,
            'song_id2' => $songId,
            'song_id3' => $songId,
        ]);

        // Write snapshot for SSE clients (cheap file check, no DB polling)
        $this->writeSnapshot($db, $songId);

        Response::json(['ok' => true]);
    }

    private function writeSnapshot(PDO $db, int $songId): void
    {
        $stmt = $db->prepare('
            SELECT s.id, s.title, s.duration_ms, s.has_cover_art,
                   a.name AS artist, c.name AS category,
                   rs.started_at, rs.is_emergency, rs.next_song_id,
                   rs.next_playlist_id, rs.next_source,
                   p.name AS playlist_name,
                   ph.source
            FROM rotation_state rs
            JOIN songs      s ON s.id = rs.current_song_id
            JOIN artists    a ON a.id = s.artist_id
            JOIN categories c ON c.id = s.category_id
            LEFT JOIN playlists p ON p.id = rs.current_playlist_id
            LEFT JOIN LATERAL (
                SELECT source FROM play_history
                WHERE song_id = rs.current_song_id
                ORDER BY started_at DESC LIMIT 1
            ) ph ON true
            WHERE rs.id = 1
        ');
        $stmt->execute();
        $row = $stmt->fetch();
        if (!$row) return;

        // Read next track from rotation_state.next_song_id
        $next = null;
        if ($row['next_song_id']) {
            $nextPlaylistId = $row['next_playlist_id'] ?? null;
            $nextStmt = $db->prepare('
                SELECT s.title, a.name AS artist, p.name AS playlist
                FROM songs   s
                JOIN artists a ON a.id = s.artist_id
                LEFT JOIN playlists p ON p.id = :playlist_id
                WHERE s.id = :id
            ');
            $nextStmt->execute(['id' => $row['next_song_id'], 'playlist_id' => $nextPlaylistId]);
            $next = $nextStmt->fetch();
        }

        // Determine source: use play_history but override for emergency mode
        $requestInfo = null;
        $source = $row['source'] ?? 'rotation';
        if ($row['is_emergency']) {
            $source = 'emergency';
        }
        if ($source === 'request') {
            $reqStmt = $db->prepare('
                SELECT listener_name, message
                FROM song_requests
                WHERE song_id = :song_id
                  AND status  = \'played\'
                  AND played_at IS NOT NULL
                ORDER BY played_at DESC
                LIMIT 1
            ');
            $reqStmt->execute(['song_id' => $songId]);
            $reqRow = $reqStmt->fetch();
            if ($reqRow) {
                $requestInfo = [
                    'listener_name' => $reqRow['listener_name'] ?: null,
                    'message'       => $reqRow['message'] ?: null,
                ];
            }
        }

        $snapshot = [
            'ts'   => microtime(true),
            'song' => [
                'id'            => (int) $row['id'],
                'title'         => $row['title'],
                'artist'        => $row['artist'],
                'category'      => $row['category'],
                'playlist'      => $row['playlist_name'],
                'duration_ms'   => (int) $row['duration_ms'],
                'has_cover_art' => (bool) $row['has_cover_art'],
                'source'        => $source,
                'started_at'    => $row['started_at'],
            ],
            'is_emergency' => (bool) $row['is_emergency'],
            'next_track'   => $next ? ['title' => $next['title'], 'artist' => $next['artist'], 'playlist' => $next['playlist'] ?? null] : null,
            'request'      => $requestInfo,
        ];

        @file_put_contents('/tmp/rendezvox_now.json', json_encode($snapshot), LOCK_EX);
    }
}
