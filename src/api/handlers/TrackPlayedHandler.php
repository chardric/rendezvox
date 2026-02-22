<?php

declare(strict_types=1);

/**
 * POST /api/track-played
 *
 * Lightweight "track finished" callback. All heavy state mutations
 * (play_history insert, play_count, rotation_state, playlist_songs)
 * are now handled by NextTrackHandler.
 *
 * This endpoint only stamps ended_at on the most recent play_history row.
 */
class TrackPlayedHandler
{
    public function handle(): void
    {
        $input = json_decode(file_get_contents('php://input'), true);

        if (!$input || empty($input['song_id'])) {
            Response::error('Missing required field: song_id', 422);
        }

        $songId = (int) $input['song_id'];
        $db     = Database::get();

        // Set ended_at on the most recent play_history entry for this song
        $stmt = $db->prepare('
            UPDATE play_history
            SET ended_at = NOW()
            WHERE id = (
                SELECT id FROM play_history
                WHERE song_id = :song_id
                ORDER BY started_at DESC
                LIMIT 1
            )
            RETURNING id, started_at, ended_at
        ');
        $stmt->execute(['song_id' => $songId]);
        $row = $stmt->fetch();

        if (!$row) {
            Response::error('No play_history entry found for this song', 404);
        }

        Response::json([
            'status'     => 'ended',
            'song_id'    => $songId,
            'history_id' => (int) $row['id'],
            'started_at' => $row['started_at'],
            'ended_at'   => $row['ended_at'],
        ]);
    }
}
