<?php

declare(strict_types=1);

/**
 * POST /api/admin/playlists/:id/songs/bulk
 *
 * Bulk-add songs to a playlist. Skips duplicates silently.
 * Accepts: { "song_ids": [1, 2, 3, ...] }
 */
class PlaylistSongBulkAddHandler
{
    public function handle(): void
    {
        $db   = Database::get();
        $id   = (int) ($_GET['id'] ?? 0);
        $body = json_decode(file_get_contents('php://input'), true);

        $songIds = $body['song_ids'] ?? [];

        if ($id <= 0 || !is_array($songIds) || count($songIds) === 0) {
            Response::error('playlist id and song_ids[] are required', 400);
            return;
        }

        if (count($songIds) > 500) {
            Response::error('Maximum 500 songs per bulk add', 400);
            return;
        }

        $songIds = array_values(array_unique(array_filter(
            array_map('intval', $songIds),
            fn($x) => $x > 0
        )));

        if (count($songIds) === 0) {
            Response::error('No valid song IDs provided', 400);
            return;
        }

        $db->beginTransaction();
        try {
            // Get current max position
            $stmt = $db->prepare('
                SELECT COALESCE(MAX(position), 0) AS max_pos
                FROM playlist_songs WHERE playlist_id = :id
            ');
            $stmt->execute(['id' => $id]);
            $pos = (int) $stmt->fetchColumn();

            // Get existing song IDs in this playlist
            $stmt = $db->prepare('SELECT song_id FROM playlist_songs WHERE playlist_id = :id');
            $stmt->execute(['id' => $id]);
            $existingSet = array_flip($stmt->fetchAll(\PDO::FETCH_COLUMN));

            $added   = 0;
            $skipped = 0;

            $insert = $db->prepare('
                INSERT INTO playlist_songs (playlist_id, song_id, position)
                VALUES (:pid, :sid, :pos)
            ');

            foreach ($songIds as $sid) {
                if (isset($existingSet[$sid])) {
                    $skipped++;
                    continue;
                }
                $pos++;
                $insert->execute(['pid' => $id, 'sid' => $sid, 'pos' => $pos]);
                $added++;
            }

            $db->commit();

            // Shuffle the entire playlist with artist/category/title separation
            if ($added > 0) {
                RotationEngine::generateCycleOrder($db, $id);
            }

            Response::json([
                'message' => $added . ' song(s) added, ' . $skipped . ' skipped (already in playlist)',
                'added'   => $added,
                'skipped' => $skipped,
            ], 201);
        } catch (\Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }
}
