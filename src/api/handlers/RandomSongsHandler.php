<?php

declare(strict_types=1);

/**
 * GET /api/admin/songs/random?hours=24&exclude_playlist_id=5
 * GET /api/admin/songs/random?count=25&exclude_playlist_id=5
 *
 * Returns N random active songs with unique titles.
 * If `hours` is provided, calculates how many songs are needed to fill
 * that many hours of playback based on average song duration.
 * If `count` is provided, returns exactly that many (max 500).
 * Optionally excludes songs already in a specific playlist.
 * Verifies each song's file exists on disk.
 */
class RandomSongsHandler
{
    private const BASE_DIR = '/var/lib/rendezvox/music';

    public function handle(): void
    {
        $db = Database::get();

        $hours = isset($_GET['hours']) ? (float) $_GET['hours'] : null;
        $excludePlaylistId = (int) ($_GET['exclude_playlist_id'] ?? 0);

        // Get average duration of active songs (for hours-based calc and response)
        $avgStmt = $db->query('
            SELECT AVG(duration_ms) AS avg_ms
            FROM songs
            WHERE is_active = true AND duration_ms > 0
        ');
        $avgDurationMs = (float) ($avgStmt->fetchColumn() ?: 210000); // fallback: 3.5 min

        // Determine count
        if ($hours !== null && $hours > 0) {
            $targetMs = $hours * 3600 * 1000;
            $count = (int) ceil($targetMs / $avgDurationMs);
            // Cap at 2000 for hours-based (multi-day playlists)
            $count = max(1, min(2000, $count));
        } else {
            $count = max(1, min(500, (int) ($_GET['count'] ?? 25)));
        }

        // Get one random song per unique title (active only)
        $sql = '
            SELECT DISTINCT ON (s.title) s.id, s.title, s.file_path, s.duration_ms, a.name AS artist
            FROM songs s
            JOIN artists a ON a.id = s.artist_id
            WHERE s.is_active = true
              AND s.duplicate_of IS NULL
        ';
        $params = [];

        // Exclude songs already in a playlist
        if ($excludePlaylistId > 0) {
            $sql .= '
                AND s.id NOT IN (
                    SELECT song_id FROM playlist_songs WHERE playlist_id = :playlist_id
                )
            ';
            $params['playlist_id'] = $excludePlaylistId;
        }

        $sql .= ' ORDER BY s.title, random()';

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Filter to songs whose files exist on disk
        $verified = [];
        foreach ($rows as $row) {
            // file_path may already be absolute or relative to BASE_DIR
            $fp = $row['file_path'];
            $path = str_starts_with($fp, self::BASE_DIR) ? $fp : self::BASE_DIR . '/' . $fp;
            if (file_exists($path)) {
                $verified[] = $row;
            }
        }

        $totalAvailable = count($verified);

        // Shuffle and take N
        shuffle($verified);
        $selected = array_slice($verified, 0, $count);

        // Calculate total duration of selected songs
        $totalDurationMs = 0;
        $songs = [];
        foreach ($selected as $r) {
            $totalDurationMs += (int) ($r['duration_ms'] ?? 0);
            $songs[] = [
                'id'     => (int) $r['id'],
                'title'  => $r['title'],
                'artist' => $r['artist'],
            ];
        }

        Response::json([
            'songs'             => $songs,
            'total_available'   => $totalAvailable,
            'avg_duration_ms'   => round($avgDurationMs),
            'total_duration_ms' => $totalDurationMs,
        ]);
    }
}
