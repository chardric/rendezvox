<?php

declare(strict_types=1);

class SongPurgeHandler
{
    private const MUSIC_DIR = '/var/lib/rendezvox/music';

    public function purge(): void
    {
        $body = json_decode(file_get_contents('php://input'), true);

        if (!$body || empty($body['ids']) || !is_array($body['ids'])) {
            Response::error('ids array is required', 400);
            return;
        }

        $ids = array_values(array_unique(array_filter(
            array_map('intval', $body['ids']),
            fn($id) => $id > 0
        )));

        if (empty($ids)) {
            Response::error('No valid song IDs provided', 400);
            return;
        }

        $result = $this->purgeSongs($ids);

        Response::json($result);
    }

    public function purgeAll(): void
    {
        $db = Database::get();

        $stmt = $db->query('SELECT id FROM songs WHERE trashed_at IS NOT NULL');
        $ids  = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        if (empty($ids)) {
            Response::json(['purged' => 0, 'errors' => []]);
            return;
        }

        $result = $this->purgeSongs(array_map('intval', $ids), 'trashed_at IS NOT NULL');

        Response::json($result);
    }

    public function purgeInactive(): void
    {
        $db   = Database::get();
        $body = json_decode(file_get_contents('php://input'), true);

        // If specific IDs provided, purge only those; otherwise purge all inactive
        if (!empty($body['ids']) && is_array($body['ids'])) {
            $ids = array_values(array_unique(array_filter(
                array_map('intval', $body['ids']),
                fn($id) => $id > 0
            )));
        } else {
            $stmt = $db->query('SELECT id FROM songs WHERE is_active = false AND trashed_at IS NULL');
            $ids  = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        }

        if (empty($ids)) {
            Response::json(['purged' => 0, 'errors' => []]);
            return;
        }

        $result = $this->purgeSongs($ids, 'is_active = false AND trashed_at IS NULL');

        Response::json($result);
    }

    /**
     * Purge all songs marked as duplicates — delete files from disk + DB rows.
     * Keeps canonical copies intact; only removes duplicate-of entries.
     */
    public function purgeDuplicates(): void
    {
        $db = Database::get();

        $stmt = $db->query('SELECT id FROM songs WHERE duplicate_of IS NOT NULL');
        $ids  = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));

        if (empty($ids)) {
            Response::json(['purged' => 0, 'freed_bytes' => 0, 'errors' => []]);
            return;
        }

        // Calculate space to be freed before deleting
        $freedBytes = 0;
        foreach ($ids as $id) {
            $fp = $db->prepare('SELECT file_path FROM songs WHERE id = :id');
            $fp->execute(['id' => $id]);
            $path = $fp->fetchColumn();
            if ($path) {
                $full = self::MUSIC_DIR . '/' . $path;
                if (file_exists($full)) {
                    $freedBytes += (int) filesize($full);
                }
            }
        }

        $result = $this->purgeSongs($ids, 'duplicate_of IS NOT NULL');
        $result['freed_bytes'] = $freedBytes;

        Response::json($result);
    }

    /**
     * @param string $condition SQL WHERE condition that guards which songs can be purged
     */
    private function purgeSongs(array $ids, string $condition = 'trashed_at IS NOT NULL'): array
    {
        $db     = Database::get();
        $purged = 0;
        $errors = [];

        foreach ($ids as $id) {
            $stmt = $db->prepare("SELECT file_path, file_hash FROM songs WHERE id = :id AND {$condition}");
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch();

            if ($row === false) {
                $errors[] = ['id' => $id, 'error' => 'Song not found or condition not met'];
                continue;
            }

            $filePath = $row['file_path'];
            $fileHash = $row['file_hash'];

            // Delete DB row
            $del = $db->prepare("DELETE FROM songs WHERE id = :id AND {$condition}");
            $del->execute(['id' => $id]);

            if ($del->rowCount() === 0) {
                $errors[] = ['id' => $id, 'error' => 'Failed to delete from database'];
                continue;
            }

            // Remove hash from organizer_hashes so re-uploading the same file works
            if ($fileHash) {
                $db->prepare('DELETE FROM organizer_hashes WHERE file_hash = :hash')
                   ->execute(['hash' => $fileHash]);
            }

            // Remove physical file and clean up empty parent directories
            $fullPath = self::MUSIC_DIR . '/' . $filePath;
            if (file_exists($fullPath)) {
                @unlink($fullPath);
                $dir = dirname($fullPath);
                while ($dir !== self::MUSIC_DIR && $dir !== dirname(self::MUSIC_DIR)) {
                    if (is_dir($dir) && count(scandir($dir)) === 2) {
                        @rmdir($dir);
                        $dir = dirname($dir);
                    } else {
                        break;
                    }
                }
            }

            $purged++;
        }

        // Clean up orphaned artists (no remaining songs)
        if ($purged > 0) {
            $db->exec('DELETE FROM artists WHERE id NOT IN (SELECT DISTINCT artist_id FROM songs)');
        }

        return ['purged' => $purged, 'errors' => $errors];
    }
}
