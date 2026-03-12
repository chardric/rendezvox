<?php

declare(strict_types=1);

class DuplicateResolveHandler
{
    public function handle(): void
    {
        $db = Database::get();

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            Response::error('Invalid JSON body', 400);
            return;
        }

        $keepIds   = $input['keep_ids']   ?? [];
        $deleteIds = $input['delete_ids'] ?? [];

        if (!is_array($keepIds) || !is_array($deleteIds)) {
            Response::error('keep_ids and delete_ids must be arrays', 400);
            return;
        }

        if (empty($deleteIds)) {
            Response::error('delete_ids must not be empty', 400);
            return;
        }

        if (empty($keepIds)) {
            Response::error('keep_ids must not be empty — at least one copy of each song must be kept', 400);
            return;
        }

        $keepSet   = array_flip(array_map('intval', $keepIds));
        $deleteSet = array_map('intval', $deleteIds);

        foreach ($deleteSet as $did) {
            if (isset($keepSet[$did])) {
                Response::error('Song ID ' . $did . ' appears in both keep_ids and delete_ids', 400);
                return;
            }
        }

        // Verify all IDs exist
        $allIds = array_merge(array_keys($keepSet), $deleteSet);
        $placeholders = implode(',', array_fill(0, count($allIds), '?'));
        $stmt = $db->prepare("SELECT id FROM songs WHERE id IN ({$placeholders})");
        foreach ($allIds as $i => $id) {
            $stmt->bindValue($i + 1, $id, PDO::PARAM_INT);
        }
        $stmt->execute();

        $foundIds = [];
        while ($row = $stmt->fetch()) {
            $foundIds[(int) $row['id']] = true;
        }

        foreach ($deleteSet as $did) {
            if (!isset($foundIds[$did])) {
                Response::error('Song ID ' . $did . ' not found', 404);
                return;
            }
        }

        foreach (array_keys($keepSet) as $kid) {
            if (!isset($foundIds[$kid])) {
                Response::error('Keep song ID ' . $kid . ' not found', 404);
                return;
            }
        }

        // Find which delete candidates are in playlists
        $delPlaceholders = implode(',', array_fill(0, count($deleteSet), '?'));
        $plStmt = $db->prepare("
            SELECT DISTINCT ps.song_id
            FROM playlist_songs ps
            WHERE ps.song_id IN ({$delPlaceholders})
        ");
        foreach ($deleteSet as $i => $id) {
            $plStmt->bindValue($i + 1, $id, PDO::PARAM_INT);
        }
        $plStmt->execute();

        $inPlaylist = [];
        while ($plRow = $plStmt->fetch()) {
            $inPlaylist[(int) $plRow['song_id']] = true;
        }

        // Build a map: delete_id → keep_id (canonical) for playlist reassignment
        // Use the first keep_id as the canonical for all deletes in this batch
        $canonicalId = (int) array_keys($keepSet)[0];

        $resolved = 0;
        $errors   = [];

        $markDupStmt  = $db->prepare('UPDATE songs SET duplicate_of = ?, is_active = false WHERE id = ?');
        $reassignStmt = $db->prepare('
            UPDATE playlist_songs SET song_id = ?
            WHERE song_id = ? AND NOT EXISTS (
                SELECT 1 FROM playlist_songs ps2
                WHERE ps2.playlist_id = playlist_songs.playlist_id AND ps2.song_id = ?
            )
        ');
        $removeOrphanStmt = $db->prepare('
            DELETE FROM playlist_songs WHERE song_id = ? AND EXISTS (
                SELECT 1 FROM playlist_songs ps2
                WHERE ps2.playlist_id = playlist_songs.playlist_id AND ps2.song_id = ?
                  AND ps2.id != playlist_songs.id
            )
        ');

        foreach ($deleteSet as $did) {
            try {
                // Reassign playlist entries to canonical (if in any playlist)
                if (isset($inPlaylist[$did])) {
                    $reassignStmt->execute([$canonicalId, $did, $canonicalId]);
                    $removeOrphanStmt->execute([$did, $canonicalId]);
                }

                // Mark as duplicate — song row and file stay intact
                $markDupStmt->execute([$canonicalId, $did]);
                $resolved++;
            } catch (\PDOException $e) {
                error_log('DuplicateResolve error for song ' . $did . ': ' . $e->getMessage());
                $errors[] = 'Error resolving song ' . $did;
            }
        }

        Response::json([
            'resolved' => $resolved,
            'errors'   => $errors,
        ]);
    }
}
