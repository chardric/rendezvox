<?php

declare(strict_types=1);

class PlaylistReorderHandler
{
    public function handle(): void
    {
        $db   = Database::get();
        $id   = (int) ($_GET['id'] ?? 0);
        $body = json_decode(file_get_contents('php://input'), true);

        $songIds = $body['song_ids'] ?? [];

        if ($id <= 0 || empty($songIds) || !is_array($songIds)) {
            Response::error('playlist id and song_ids array are required', 400);
            return;
        }

        $db->beginTransaction();
        try {
            // Temporarily remove unique constraint conflicts by setting negative positions
            $stmt = $db->prepare('
                UPDATE playlist_songs SET position = -position WHERE playlist_id = :id
            ');
            $stmt->execute(['id' => $id]);

            // Reassign positions
            $update = $db->prepare('
                UPDATE playlist_songs
                SET position = :pos
                WHERE playlist_id = :pid AND song_id = :sid
            ');

            foreach ($songIds as $pos => $songId) {
                $update->execute([
                    'pos' => $pos + 1,
                    'pid' => $id,
                    'sid' => (int) $songId,
                ]);
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log('Playlist reorder failed: ' . $e->getMessage());
            Response::error('Reorder failed', 500);
            return;
        }

        Response::json(['message' => 'Playlist reordered']);
    }
}
