<?php

declare(strict_types=1);

/**
 * POST /api/admin/playlists/:id/songs/folder
 *
 * Add all active songs from a media-library folder to a manual playlist.
 * Automatically scans and imports untracked audio files before adding.
 * Accepts: { "folder_path": "/Country", "recursive": false }
 * Skips duplicates silently.
 */
class PlaylistSongFolderAddHandler
{
    private const AUDIO_EXTS = ['mp3', 'flac', 'ogg', 'wav', 'aac', 'm4a', 'wma', 'opus', 'aiff'];

    public function handle(): void
    {
        set_time_limit(300);

        $db   = Database::get();
        $id   = (int) ($_GET['id'] ?? 0);
        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        $folderPath = trim((string) ($body['folder_path'] ?? ''));
        $recursive  = (bool) ($body['recursive']  ?? false);

        if ($id <= 0 || $folderPath === '') {
            Response::error('Playlist ID and folder_path are required', 400);
            return;
        }

        $absPath = MediaBrowseHandler::safePath($folderPath);
        if ($absPath === null || !is_dir($absPath)) {
            Response::error('Folder not found', 404);
            return;
        }

        $stmt = $db->prepare('SELECT id FROM playlists WHERE id = :id');
        $stmt->execute(['id' => $id]);
        if (!$stmt->fetch()) {
            Response::error('Playlist not found', 404);
            return;
        }

        $basePrefix = rtrim(MediaBrowseHandler::BASE_DIR, '/') . '/';
        $relPath = str_starts_with($absPath, $basePrefix)
            ? substr($absPath, strlen($basePrefix))
            : ltrim($absPath, '/');
        $prefix = $relPath . '/';

        // Auto-scan: find audio files on disk not yet in DB
        $existingPaths = [];
        $pathStmt = $db->query('SELECT file_path FROM songs');
        while ($row = $pathStmt->fetch()) {
            $existingPaths[$row['file_path']] = true;
        }

        $newFiles = [];
        $this->findAudioFiles($absPath, $relPath, $recursive, $existingPaths, $newFiles);

        $scanned = 0;
        if (!empty($newFiles)) {
            $categoryCache = [];
            $catStmt = $db->query('SELECT id, LOWER(name) AS lname FROM categories');
            while ($row = $catStmt->fetch()) {
                $categoryCache[$row['lname']] = (int) $row['id'];
            }
            $defaultCatId = (int) (getenv('IRADIO_DEFAULT_CATEGORY_ID') ?: 1);

            $workers = MetadataExtractor::safeWorkerCount();
            MetadataExtractor::prefetch(array_column($newFiles, 'abs'), $workers);

            foreach ($newFiles as $file) {
                $meta = MetadataExtractor::extract($file['abs']);
                if ($meta['duration_ms'] <= 0) continue;

                $title  = $meta['title'] ?: pathinfo(basename($file['abs']), PATHINFO_FILENAME);
                $artist = $meta['artist'] ?: 'Unknown Artist';
                $artistId = $this->findOrCreateArtist($db, $artist);

                $catId = $defaultCatId;
                $rawGenre = trim($meta['genre'] ?? '');
                if ($rawGenre !== '') {
                    $mapped = MetadataLookup::mapGenre($rawGenre);
                    if ($mapped) {
                        $catId = $this->findOrCreateCategory($db, $mapped, $categoryCache);
                    }
                }

                try {
                    $insertStmt = $db->prepare('
                        INSERT INTO songs (title, artist_id, category_id, file_path, duration_ms, year)
                        VALUES (:title, :artist_id, :category_id, :file_path, :duration_ms, :year)
                    ');
                    $insertStmt->execute([
                        'title'       => $title,
                        'artist_id'   => $artistId,
                        'category_id' => $catId,
                        'file_path'   => $file['rel'],
                        'duration_ms' => $meta['duration_ms'],
                        'year'        => $meta['year'] ?: null,
                    ]);
                    $existingPaths[$file['rel']] = true;
                    $scanned++;
                } catch (\PDOException $e) {
                    continue;
                }
            }
        }

        // Now query songs from DB (including newly scanned)
        if ($recursive) {
            $stmt = $db->prepare('
                SELECT id FROM songs
                WHERE  file_path LIKE :prefix
                  AND  is_active = true
                ORDER BY file_path
            ');
            $stmt->execute(['prefix' => $prefix . '%']);
        } else {
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
                'scanned' => $scanned,
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
            $skippedCount = 0;

            foreach ($songIds as $sid) {
                $sid = (int) $sid;
                if (isset($existing[$sid])) {
                    $skippedCount++;
                    continue;
                }
                $pos++;
                $insert->execute(['pid' => $id, 'sid' => $sid, 'pos' => $pos]);
                $existing[$sid] = true;
                $added++;
            }

            $db->commit();

            // Shuffle the entire playlist with artist/category/title separation
            if ($added > 0) {
                RotationEngine::generateCycleOrder($db, $id);
            }
        } catch (\Exception $e) {
            $db->rollBack();
            throw $e;
        }

        $message = $added . ' song(s) added, ' . $skippedCount . ' skipped (already in playlist)';
        if ($scanned > 0) {
            $message .= ', ' . $scanned . ' newly scanned';
        }

        Response::json([
            'message' => $message,
            'added'   => $added,
            'skipped' => $skippedCount,
            'scanned' => $scanned,
        ], 201);
    }

    private function findAudioFiles(
        string $absDir,
        string $relDir,
        bool $recursive,
        array &$existingPaths,
        array &$newFiles
    ): void {
        $entries = @scandir($absDir);
        if (!$entries) return;

        foreach ($entries as $item) {
            if ($item === '.' || $item === '..' || str_starts_with($item, '.')) continue;
            $fullPath = $absDir . '/' . $item;

            if (is_dir($fullPath) && $recursive) {
                $this->findAudioFiles($fullPath, $relDir . '/' . $item, true, $existingPaths, $newFiles);
            } elseif (is_file($fullPath)) {
                $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
                if (!in_array($ext, self::AUDIO_EXTS)) continue;
                if (str_contains($item, '.tmp.')) continue;

                $relPath = $relDir . '/' . $item;
                if (isset($existingPaths[$relPath])) continue;

                $newFiles[] = ['abs' => $fullPath, 'rel' => $relPath];
            }
        }
    }

    private function findOrCreateArtist(\PDO $db, string $name): int
    {
        $name = ArtistNormalizer::extractPrimary($name, $db);
        $normalized = mb_strtolower(trim($name));

        $stmt = $db->prepare('SELECT id FROM artists WHERE normalized_name = :norm');
        $stmt->execute(['norm' => $normalized]);
        $row = $stmt->fetch();
        if ($row) return (int) $row['id'];

        $stmt = $db->prepare('INSERT INTO artists (name, normalized_name) VALUES (:name, :norm) RETURNING id');
        $stmt->execute(['name' => trim($name), 'norm' => $normalized]);
        return (int) $stmt->fetchColumn();
    }

    private function findOrCreateCategory(\PDO $db, string $name, array &$cache): int
    {
        $key = strtolower(trim($name));
        if (isset($cache[$key])) return $cache[$key];

        $stmt = $db->prepare('SELECT id FROM categories WHERE LOWER(name) = LOWER(:name)');
        $stmt->execute(['name' => trim($name)]);
        $row = $stmt->fetch();
        if ($row) {
            $cache[$key] = (int) $row['id'];
            return (int) $row['id'];
        }

        $stmt = $db->prepare("INSERT INTO categories (name, type) VALUES (:name, 'music') RETURNING id");
        $stmt->execute(['name' => trim($name)]);
        $id = (int) $stmt->fetchColumn();
        $cache[$key] = $id;
        return $id;
    }
}
