<?php

declare(strict_types=1);

class DashboardStatsHandler
{
    public function handle(): void
    {
        $db = Database::get();

        // ── Current listeners (latest from listener_stats) ──
        $stmt = $db->query('
            SELECT listener_count, peak_listeners, recorded_at
            FROM listener_stats
            ORDER BY recorded_at DESC
            LIMIT 1
        ');
        $listenerRow = $stmt->fetch();
        $listenersCurrent = $listenerRow ? (int) $listenerRow['listener_count'] : 0;

        // Peak today
        $stmt = $db->query("
            SELECT COALESCE(MAX(peak_listeners), 0) AS peak
            FROM listener_stats
            WHERE recorded_at >= date_trunc('day', NOW())
        ");
        $peakToday = (int) $stmt->fetchColumn();

        // ── Request counts ──
        $stmt = $db->query("
            SELECT
                COUNT(*) FILTER (WHERE status = 'pending')  AS pending,
                COUNT(*) FILTER (WHERE status = 'approved') AS approved
            FROM song_requests
        ");
        $reqRow = $stmt->fetch();
        $pendingRequests  = (int) $reqRow['pending'];
        $approvedRequests = (int) $reqRow['approved'];

        // ── Now playing + up next ──
        $stmt = $db->query('
            SELECT
                rs.is_playing,
                rs.is_emergency,
                rs.started_at,
                rs.current_playlist_id,
                rs.next_song_id,
                rs.next_source,
                s.id         AS song_id,
                s.title,
                s.duration_ms,
                a.name       AS artist_name,
                c.name       AS category_name,
                ph.source
            FROM rotation_state rs
            LEFT JOIN songs      s  ON s.id = rs.current_song_id
            LEFT JOIN artists    a  ON a.id = s.artist_id
            LEFT JOIN categories c  ON c.id = s.category_id
            LEFT JOIN LATERAL (
                SELECT source FROM play_history
                WHERE song_id = rs.current_song_id
                ORDER BY started_at DESC LIMIT 1
            ) ph ON true
            WHERE rs.id = 1
        ');
        $npRow = $stmt->fetch();

        $nowPlaying = null;
        if ($npRow && $npRow['song_id']) {
            // Use play_history source, but override with 'emergency' when
            // emergency mode is active (covers the transition window before
            // TrackStartedHandler has written the play_history row)
            $npSource = $npRow['source'] ?? 'rotation';
            if ($npRow['is_emergency']) {
                $npSource = 'emergency';
            }

            $nowPlaying = [
                'song_id'    => (int) $npRow['song_id'],
                'title'      => $npRow['title'],
                'artist'     => $npRow['artist_name'],
                'category'   => $npRow['category_name'],
                'duration_ms'=> (int) $npRow['duration_ms'],
                'source'     => $npSource,
                'started_at' => $npRow['started_at'],
                'is_playing' => (bool) $npRow['is_playing'],
            ];
        }

        // ── Emergency mode ──
        $stmt = $db->query("SELECT value FROM settings WHERE key = 'emergency_mode'");
        $emergencyMode = ($stmt->fetchColumn() === 'true');

        // ── Stream status ──
        $stmt = $db->query("SELECT value FROM settings WHERE key = 'stream_enabled'");
        $streamVal = $stmt->fetchColumn();
        $streamActive = ($streamVal === false || $streamVal !== 'false'); // default true

        // ── Up next (from rotation_state.next_song_id) ──
        $nextTrack = null;
        $nextSongId  = $npRow ? ($npRow['next_song_id'] ?? null) : null;
        $currentSongId = $npRow ? ($npRow['song_id'] ?? null) : null;

        // Skip Up Next if it's the same as the currently playing song
        // (happens briefly after TrackStartedHandler promotes next → current)
        if ($nextSongId && (int) $nextSongId !== (int) $currentSongId) {
            $ntStmt = $db->prepare('
                SELECT s.title, a.name AS artist_name, c.name AS category_name
                FROM songs      s
                JOIN artists    a ON a.id = s.artist_id
                JOIN categories c ON c.id = s.category_id
                WHERE s.id = :id
            ');
            $ntStmt->execute(['id' => $nextSongId]);
            $ntRow = $ntStmt->fetch();
            if ($ntRow) {
                // Use rotation_state.next_source for the Up Next badge —
                // NOT play_history, which reflects the song's last historical play
                $nextTrack = [
                    'title'    => $ntRow['title'],
                    'artist'   => $ntRow['artist_name'],
                    'category' => $ntRow['category_name'],
                    'source'   => $npRow['next_source'] ?? 'rotation',
                ];
            }
        }

        // ── Recently played (last 10 completed tracks) ──
        // Filter out orphan prefetch rows (created by NextTrackHandler but
        // never actually played) by requiring a minimum play duration.
        $stmt = $db->query('
            SELECT ph.id, ph.source, ph.started_at, ph.ended_at,
                   s.title, a.name AS artist_name, c.name AS category_name
            FROM play_history ph
            JOIN songs      s ON s.id = ph.song_id
            JOIN artists    a ON a.id = s.artist_id
            JOIN categories c ON c.id = s.category_id
            WHERE ph.ended_at IS NOT NULL
              AND (ph.ended_at - ph.started_at) > INTERVAL \'30 seconds\'
            ORDER BY ph.ended_at DESC
            LIMIT 10
        ');
        $recentPlays = [];
        while ($row = $stmt->fetch()) {
            $recentPlays[] = [
                'id'        => (int) $row['id'],
                'title'     => $row['title'],
                'artist'    => $row['artist_name'],
                'category'  => $row['category_name'],
                'source'    => $row['source'],
                'started_at'=> $row['started_at'],
                'ended_at'  => $row['ended_at'],
            ];
        }

        // ── Song/playlist/schedule counts ──
        $stmt = $db->query("SELECT COUNT(*) FROM songs WHERE is_active = true");
        $activeSongs = (int) $stmt->fetchColumn();

        $stmt = $db->query("SELECT COUNT(*) FROM playlists WHERE is_active = true");
        $activePlaylists = (int) $stmt->fetchColumn();

        $stmt = $db->query("SELECT COUNT(*) FROM schedules WHERE is_active = true");
        $activeSchedules = (int) $stmt->fetchColumn();

        Response::json([
            'listeners_current'  => $listenersCurrent,
            'listeners_peak_today' => $peakToday,
            'pending_requests'   => $pendingRequests,
            'approved_requests'  => $approvedRequests,
            'now_playing'        => $nowPlaying,
            'next_track'         => $nextTrack,
            'emergency_mode'     => $emergencyMode,
            'stream_active'      => $streamActive,
            'recent_plays'       => $recentPlays,
            'active_songs'       => $activeSongs,
            'active_playlists'   => $activePlaylists,
            'active_schedules'   => $activeSchedules,
        ]);
    }
}
