<?php

declare(strict_types=1);

class PlaylistSongAddHandler
{
    public function handle(): void
    {
        $db   = Database::get();
        $id   = (int) ($_GET['id'] ?? 0);
        $body = json_decode(file_get_contents('php://input'), true);

        $songId = (int) ($body['song_id'] ?? 0);

        if ($id <= 0 || $songId <= 0) {
            Response::error('playlist id and song_id are required', 400);
            return;
        }

        // Check duplicate
        $stmt = $db->prepare('
            SELECT id FROM playlist_songs WHERE playlist_id = :pid AND song_id = :sid
        ');
        $stmt->execute(['pid' => $id, 'sid' => $songId]);
        if ($stmt->fetch()) {
            Response::error('Song is already in this playlist', 409);
            return;
        }

        // Get next position
        $stmt = $db->prepare('
            SELECT COALESCE(MAX(position), 0) + 1 AS next_pos
            FROM playlist_songs WHERE playlist_id = :id
        ');
        $stmt->execute(['id' => $id]);
        $nextPos = (int) $stmt->fetchColumn();

        $stmt = $db->prepare('
            INSERT INTO playlist_songs (playlist_id, song_id, position)
            VALUES (:pid, :sid, :pos)
        ');
        $stmt->execute(['pid' => $id, 'sid' => $songId, 'pos' => $nextPos]);

        Response::json(['message' => 'Song added to playlist', 'position' => $nextPos], 201);
    }
}
