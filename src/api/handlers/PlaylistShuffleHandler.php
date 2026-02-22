<?php

declare(strict_types=1);

class PlaylistShuffleHandler
{
    public function handle(): void
    {
        $db = Database::get();
        $id = (int) ($_GET['id'] ?? 0);

        if ($id <= 0) {
            Response::error('Invalid playlist ID', 400);
            return;
        }

        // Verify playlist exists
        $stmt = $db->prepare('SELECT id FROM playlists WHERE id = :id');
        $stmt->execute(['id' => $id]);
        if (!$stmt->fetch()) {
            Response::error('Playlist not found', 404);
            return;
        }

        // Shuffle unplayed songs first; if all are played, reset cycle and reshuffle all
        $count = RotationEngine::shuffleRemaining($db, $id);

        if ($count === 0) {
            // All songs played — reset cycle and do a full reshuffle
            $db->prepare('
                UPDATE playlist_songs SET played_in_cycle = false WHERE playlist_id = :id
            ')->execute(['id' => $id]);
            RotationEngine::generateCycleOrder($db, $id);

            $totalStmt = $db->prepare('
                SELECT COUNT(*) FROM playlist_songs ps
                JOIN songs s ON s.id = ps.song_id
                WHERE ps.playlist_id = :id AND s.is_active = true
            ');
            $totalStmt->execute(['id' => $id]);
            $count = (int) $totalStmt->fetchColumn();

            Response::json(['message' => 'Cycle reset — ' . $count . ' songs reshuffled']);
            return;
        }

        Response::json(['message' => $count . ' unplayed songs reshuffled']);
    }
}
