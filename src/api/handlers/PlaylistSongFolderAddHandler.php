<?php

declare(strict_types=1);

/**
 * POST /api/admin/playlists/:id/songs/folder
 *
 * Add all active songs from a media-library folder to a manual playlist.
 * Accepts: { "folder_path": "/Country", "recursive": false }
 * Skips duplicates silently.
 */
class PlaylistSongFolderAddHandler
{
    public function handle(): void
    {
        $db   = Database::get();
        $id   = (int) ($_GET['id'] ?? 0);
        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        $folderPath = trim((string) ($body['folder_path'] ?? ''));
        $recursive  = (bool) ($body['recursive']  ?? false);

        if ($id <= 0 || $folderPath === '') {
            Response::error('Playlist ID and folder_path are required', 400);
            return;
        }

        // Validate and resolve the folder path via the existing safe-path logic
        $absPath = MediaBrowseHandler::safePath($folderPath);
        if ($absPath === null || !is_dir($absPath)) {
            Response::error('Folder not found', 404);
            return;
        }

        // Ensure the playlist exists
        $stmt = $db->prepare('SELECT id FROM playlists WHERE id = :id');
        $stmt->execute(['id' => $id]);
        if (!$stmt->fetch()) {
            Response::error('Playlist not found', 404);
            return;
        }

        // Query songs that live in the target folder
        $prefix = $absPath . '/';
        if ($recursive) {
            $stmt = $db->prepare('
                SELECT id FROM songs
                WHERE  file_path LIKE :prefix
                  AND  is_active = true
                ORDER BY file_path
            ');
            $stmt->execute(['prefix' => $prefix . '%']);
        } else {
            // Direct children only — file_path must not contain another slash after the prefix
            $stmt = $db->prepare('
                SELECT id FROM songs
                WHERE  file_path LIKE :prefix
                  AND  file_path NOT LIKE :subprefix
                  AND  is_active = true
                ORDER BY file_path
            ');
            $stmt->execute([
                'prefix'    => $prefix . '%',
                'subprefix' => $prefix . '%/%',
            ]);
        }

        $songIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        if (count($songIds) === 0) {
            Response::json([
                'message' => 'No active songs found in this folder',
                'added'   => 0,
                'skipped' => 0,
            ]);
            return;
        }

        // Bulk-insert, skipping duplicates
        $db->beginTransaction();
        try {
            $maxStmt = $db->prepare('
                SELECT COALESCE(MAX(position), 0) FROM playlist_songs WHERE playlist_id = :id
            ');
            $maxStmt->execute(['id' => $id]);
            $pos = (int) $maxStmt->fetchColumn();

            $existStmt = $db->prepare('SELECT song_id FROM playlist_songs WHERE playlist_id = :id');
            $existStmt->execute(['id' => $id]);
            $existing = array_flip($existStmt->fetchAll(\PDO::FETCH_COLUMN));

            $insert = $db->prepare('
                INSERT INTO playlist_songs (playlist_id, song_id, position)
                VALUES (:pid, :sid, :pos)
            ');

            $added   = 0;
            $skipped = 0;

            foreach ($songIds as $sid) {
                $sid = (int) $sid;
                if (isset($existing[$sid])) {
                    $skipped++;
                    continue;
                }
                $pos++;
                $insert->execute(['pid' => $id, 'sid' => $sid, 'pos' => $pos]);
                $existing[$sid] = true;
                $added++;
            }

            $db->commit();
        } catch (\Exception $e) {
            $db->rollBack();
            throw $e;
        }

        Response::json([
            'message' => $added . ' song(s) added, ' . $skipped . ' skipped (already in playlist)',
            'added'   => $added,
            'skipped' => $skipped,
        ], 201);
    }
}
