<?php

declare(strict_types=1);

/**
 * POST /api/admin/skip-track
 *
 * Tells Liquidsoap to skip the current track immediately.
 * Connects to the Liquidsoap telnet server and sends iRadio.skip.
 */
class SkipTrackHandler
{
    private const LIQ_HOST    = 'iradio-liquidsoap';
    private const LIQ_PORT    = 1234;
    private const LIQ_TIMEOUT = 3;

    public function handle(): void
    {
        Auth::requireAuth();

        $db = Database::get();
        Database::ensureRotationState();

        // Get the currently playing song before skipping
        $state = $db->query('
            SELECT current_song_id, current_playlist_id
            FROM rotation_state WHERE id = 1
        ')->fetch();

        // Prevent skipping a requested song that is currently playing
        $songId = $state['current_song_id'] ?? null;
        if ($songId) {
            $srcStmt = $db->prepare('
                SELECT source FROM play_history
                WHERE song_id = :song_id AND ended_at IS NULL
                ORDER BY started_at DESC LIMIT 1
            ');
            $srcStmt->execute(['song_id' => $songId]);
            $currentSource = $srcStmt->fetchColumn();

            if ($currentSource === 'request') {
                Response::error('Cannot skip a requested song', 403);
                return;
            }
        }

        $sock = @fsockopen(self::LIQ_HOST, self::LIQ_PORT, $errno, $errstr, self::LIQ_TIMEOUT);
        if (!$sock) {
            Response::error('Stream engine unavailable: ' . $errstr, 503);
            return;
        }

        stream_set_timeout($sock, self::LIQ_TIMEOUT);

        // Flush any initial banner/prompt
        usleep(100000);
        while (($info = stream_get_meta_data($sock)) && !$info['eof']) {
            $peek = @fread($sock, 1024);
            if ($peek === false || $peek === '') break;
        }

        fwrite($sock, "stream.skip\r\n");
        usleep(200000);
        $response = fread($sock, 1024);

        fwrite($sock, "quit\r\n");
        fclose($sock);

        // Check for error response
        if ($response !== false && stripos($response, 'error') !== false) {
            Response::error('Skip command failed: ' . trim($response), 500);
            return;
        }

        // Undo rotation effects so the skipped song gets another chance
        $songId     = $state['current_song_id'] ?? null;
        $playlistId = $state['current_playlist_id'] ?? null;

        if ($songId && $playlistId) {
            // Determine playlist type
            $plStmt = $db->prepare('SELECT type FROM playlists WHERE id = :id');
            $plStmt->execute(['id' => $playlistId]);
            $plType = $plStmt->fetchColumn() ?: 'manual';

            if ($plType === 'auto') {
                // Auto playlist: revert last_played_at and play_count
                // Find the previous play time (before the current one)
                $prevStmt = $db->prepare('
                    SELECT started_at FROM play_history
                    WHERE song_id = :song_id
                    ORDER BY started_at DESC
                    LIMIT 1 OFFSET 1
                ');
                $prevStmt->execute(['song_id' => $songId]);
                $prevPlayed = $prevStmt->fetchColumn() ?: null;

                $db->prepare('
                    UPDATE songs
                    SET last_played_at = :prev,
                        play_count     = GREATEST(play_count - 1, 0)
                    WHERE id = :id
                ')->execute(['prev' => $prevPlayed, 'id' => $songId]);
            } else {
                // Manual / Emergency: reset played_in_cycle + revert play_count
                $db->prepare('
                    UPDATE playlist_songs
                    SET played_in_cycle = false
                    WHERE playlist_id = :playlist_id
                      AND song_id = :song_id
                ')->execute([
                    'playlist_id' => $playlistId,
                    'song_id'     => $songId,
                ]);

                $db->prepare('
                    UPDATE songs
                    SET play_count = GREATEST(play_count - 1, 0)
                    WHERE id = :id
                ')->execute(['id' => $songId]);
            }
        }

        Response::json(['message' => 'Track skipped']);
    }
}
