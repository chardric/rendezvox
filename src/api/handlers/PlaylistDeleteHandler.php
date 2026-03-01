<?php

declare(strict_types=1);

class PlaylistDeleteHandler
{
    public function handle(): void
    {
        Auth::requireRole('super_admin', 'dj');

        $db = Database::get();
        $id = (int) ($_GET['id'] ?? 0);

        if ($id <= 0) {
            Response::error('Invalid playlist ID', 400);
            return;
        }

        // Block deletion of emergency playlists — they are the safety net
        $stmt = $db->prepare('SELECT type FROM playlists WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            Response::error('Playlist not found', 404);
            return;
        }
        if ($row['type'] === 'emergency') {
            Response::error('Cannot delete the emergency playlist — it prevents dead air during schedule gaps', 403);
            return;
        }

        // Block deletion only if the station is actively playing from this playlist
        $stmt = $db->prepare('SELECT current_playlist_id, is_playing FROM rotation_state WHERE id = 1');
        $stmt->execute();
        $state = $stmt->fetch();
        if ($state && (int) $state['current_playlist_id'] === $id && $state['is_playing']) {
            Response::error('Cannot delete playlist that is currently playing', 409);
            return;
        }

        $stmt = $db->prepare('DELETE FROM playlists WHERE id = :id');
        $stmt->execute(['id' => $id]);

        if ($stmt->rowCount() === 0) {
            Response::error('Playlist not found', 404);
            return;
        }

        Response::json(['message' => 'Playlist deleted']);
    }
}
