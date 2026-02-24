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
                rs.next_playlist_id,
                rs.next_song_id,
                rs.next_source,
                s.id         AS song_id,
                s.title,
                s.duration_ms,
                s.has_cover_art,
                a.name       AS artist_name,
                c.name       AS category_name,
                p.name       AS playlist_name,
                ph.source
            FROM rotation_state rs
            LEFT JOIN songs      s  ON s.id = rs.current_song_id
            LEFT JOIN artists    a  ON a.id = s.artist_id
            LEFT JOIN categories c  ON c.id = s.category_id
            LEFT JOIN playlists  p  ON p.id = rs.current_playlist_id
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
                'song_id'       => (int) $npRow['song_id'],
                'title'         => $npRow['title'],
                'artist'        => $npRow['artist_name'],
                'category'      => $npRow['category_name'],
                'playlist'      => $npRow['playlist_name'],
                'duration_ms'   => (int) $npRow['duration_ms'],
                'has_cover_art' => (bool) $npRow['has_cover_art'],
                'source'        => $npSource,
                'started_at'    => $npRow['started_at'],
                'is_playing'    => (bool) $npRow['is_playing'],
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
        $nextPlaylistId = $npRow ? ($npRow['next_playlist_id'] ?? null) : null;

        if ($nextSongId && (int) $nextSongId !== (int) $currentSongId) {
            $ntStmt = $db->prepare('
                SELECT s.title, a.name AS artist_name, c.name AS category_name,
                       p.name AS playlist_name
                FROM songs      s
                JOIN artists    a ON a.id = s.artist_id
                JOIN categories c ON c.id = s.category_id
                LEFT JOIN playlists p ON p.id = :playlist_id
                WHERE s.id = :id
            ');
            $ntStmt->execute(['id' => $nextSongId, 'playlist_id' => $nextPlaylistId]);
            $ntRow = $ntStmt->fetch();
            if ($ntRow) {
                // Use rotation_state.next_source for the Up Next badge —
                // NOT play_history, which reflects the song's last historical play
                $nextTrack = [
                    'title'    => $ntRow['title'],
                    'artist'   => $ntRow['artist_name'],
                    'category' => $ntRow['category_name'],
                    'playlist' => $ntRow['playlist_name'],
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

        // ── CPU load ──
        $cpuLoad = sys_getloadavg() ?: [0, 0, 0];
        $cpuCores = 1;
        if (is_readable('/proc/cpuinfo')) {
            $cpuCores = max(1, (int) substr_count(file_get_contents('/proc/cpuinfo'), 'processor'));
        }

        // ── Memory usage (from /proc/meminfo) ──
        $memTotalMb  = 0;
        $memUsedMb   = 0;
        $memPercent  = 0.0;
        if (is_readable('/proc/meminfo')) {
            $meminfo = file_get_contents('/proc/meminfo');
            $total     = 0;
            $available = 0;
            if (preg_match('/MemTotal:\s+(\d+)\s+kB/', $meminfo, $m)) {
                $total = (int) $m[1];
            }
            if (preg_match('/MemAvailable:\s+(\d+)\s+kB/', $meminfo, $m)) {
                $available = (int) $m[1];
            }
            $memTotalMb = round($total / 1024, 1);
            $memUsedMb  = round(($total - $available) / 1024, 1);
            $memPercent = $total > 0 ? round(($total - $available) / $total * 100, 1) : 0.0;
        }

        // ── Service health ──
        $services = $this->checkServices();

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
            'cpu_load'           => array_map(function ($v) { return round($v, 2); }, $cpuLoad),
            'cpu_cores'          => $cpuCores,
            'memory_used_mb'     => $memUsedMb,
            'memory_total_mb'    => $memTotalMb,
            'memory_percent'     => $memPercent,
            'services'           => $services,
        ]);
    }

    // ── Service health checks ──────────────────────────────────

    private function checkServices(): array
    {
        $services = [];

        // Nginx — this response is served through nginx; if we're here, it's up
        $services['nginx'] = 'running';

        // PHP-FPM — we're executing inside it; if we're here, it's up
        $services['php'] = 'running';

        // Icecast — TCP connect (both on iradio_net bridge)
        $icecastHost = getenv('IRADIO_ICECAST_HOST') ?: 'icecast';
        $icecastPort = (int) (getenv('IRADIO_ICECAST_PORT') ?: 8000);

        $icecastUp = false;
        $fp = @fsockopen($icecastHost, $icecastPort, $errno, $errstr, 2);
        if ($fp) {
            $icecastUp = true;
            fclose($fp);
        }
        $services['icecast'] = $icecastUp ? 'running' : 'stopped';

        // Liquidsoap — check if Icecast has an active source on any mount
        // (liquidsoap is the only source client, so an active source = liquidsoap running)
        $services['liquidsoap'] = 'stopped';
        if ($icecastUp) {
            $ctx = stream_context_create(['http' => ['timeout' => 2]]);
            $json = @file_get_contents(
                "http://{$icecastHost}:{$icecastPort}/status-json.xsl",
                false,
                $ctx
            );
            if ($json !== false) {
                $data = json_decode($json, true);
                if (isset($data['icestats']['source'])) {
                    $services['liquidsoap'] = 'running';
                }
            }
        }

        return $services;
    }
}
