<?php

declare(strict_types=1);

class DuplicateResolveHandler
{
    private const BASE_DIR = '/var/lib/iradio/music';

    private function resolveFilePath(string $filePath): string
    {
        if ($filePath !== '' && $filePath[0] !== '/') {
            return self::BASE_DIR . '/' . $filePath;
        }
        return $filePath;
    }

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

        // Ensure no overlap between keep and delete
        $keepSet   = array_flip(array_map('intval', $keepIds));
        $deleteSet = array_map('intval', $deleteIds);

        foreach ($deleteSet as $did) {
            if (isset($keepSet[$did])) {
                Response::error('Song ID ' . $did . ' appears in both keep_ids and delete_ids', 400);
                return;
            }
        }

        // Verify all keep_ids and delete_ids exist
        $allIds = array_merge(array_keys($keepSet), $deleteSet);
        $placeholders = implode(',', array_fill(0, count($allIds), '?'));
        $stmt = $db->prepare("SELECT id, file_path FROM songs WHERE id IN ({$placeholders})");
        foreach ($allIds as $i => $id) {
            $stmt->bindValue($i + 1, $id, PDO::PARAM_INT);
        }
        $stmt->execute();

        $songMap = [];
        while ($row = $stmt->fetch()) {
            $songMap[(int) $row['id']] = $row['file_path'];
        }

        foreach ($deleteSet as $did) {
            if (!isset($songMap[$did])) {
                Response::error('Song ID ' . $did . ' not found', 404);
                return;
            }
        }

        foreach (array_keys($keepSet) as $kid) {
            if (!isset($songMap[$kid])) {
                Response::error('Keep song ID ' . $kid . ' not found', 404);
                return;
            }
        }

        // Delete songs and their files
        $deleted = 0;
        $freedBytes = 0;
        $errors = [];

        $deleteStmt = $db->prepare('DELETE FROM songs WHERE id = ?');
        $hashStmt   = $db->prepare('SELECT file_hash FROM songs WHERE id = ?');
        $delHash    = $db->prepare('DELETE FROM organizer_hashes WHERE file_hash = ?');

        foreach ($deleteSet as $did) {
            $filePath = $songMap[$did];

            try {
                // Get hash before deleting
                $hashStmt->execute([$did]);
                $hash = $hashStmt->fetchColumn();

                $deleteStmt->execute([$did]);
                $deleted++;

                // Remove hash from organizer_hashes
                if ($hash) {
                    $delHash->execute([$hash]);
                }

                // Remove file from disk
                $absPath = $this->resolveFilePath($filePath);
                if ($absPath !== '' && file_exists($absPath)) {
                    $size = (int) filesize($absPath);
                    if (unlink($absPath)) {
                        $freedBytes += $size;
                        // Clean up empty parent directories
                        $dir = dirname($absPath);
                        while ($dir !== self::BASE_DIR && $dir !== dirname(self::BASE_DIR)) {
                            if (is_dir($dir) && count(scandir($dir)) === 2) {
                                @rmdir($dir);
                                $dir = dirname($dir);
                            } else {
                                break;
                            }
                        }
                    } else {
                        $errors[] = 'Failed to delete file: ' . $absPath;
                    }
                }
            } catch (\PDOException $e) {
                error_log('DuplicateResolve DB error deleting song ' . $did . ': ' . $e->getMessage());
                $errors[] = 'DB error deleting song ' . $did;
            }
        }

        // Clean up orphaned artists
        if ($deleted > 0) {
            $db->exec('DELETE FROM artists WHERE id NOT IN (SELECT DISTINCT artist_id FROM songs)');
        }

        Response::json([
            'deleted'     => $deleted,
            'freed_bytes' => $freedBytes,
            'errors'      => $errors,
        ]);
    }
}
