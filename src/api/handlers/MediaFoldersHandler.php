<?php

declare(strict_types=1);

class MediaFoldersHandler
{
    private const AUDIO_EXTS = ['mp3', 'flac', 'ogg', 'wav', 'aac', 'm4a', 'wma', 'opus', 'aiff'];

    public function handle(): void
    {
        $counts = ($_GET['counts'] ?? '') === 'true';

        $folders = [['name' => '/ (root)', 'path' => '/', 'depth' => -1]];
        $fileCounts = $counts ? [] : null;
        $this->scan(MediaBrowseHandler::BASE_DIR, '', $folders, 0, $fileCounts);

        if ($counts) {
            // DB-tracked song counts
            $db = Database::get();
            $stmt = $db->query('SELECT file_path FROM songs WHERE is_active = true');
            $paths = $stmt->fetchAll(\PDO::FETCH_COLUMN);

            $songMap = [];
            $totalSongs = 0;
            foreach ($paths as $fp) {
                $totalSongs++;
                $parts = explode('/', $fp);
                array_pop($parts);
                $acc = '';
                foreach ($parts as $part) {
                    $acc = $acc === '' ? $part : $acc . '/' . $part;
                    $songMap[$acc] = ($songMap[$acc] ?? 0) + 1;
                }
            }

            $totalFiles = $fileCounts[''] ?? 0;

            foreach ($folders as &$f) {
                $rel = ltrim($f['path'], '/');
                $f['song_count'] = $rel === '' ? $totalSongs : ($songMap[$rel] ?? 0);
                $f['file_count'] = $rel === '' ? $totalFiles : ($fileCounts[$rel] ?? 0);
            }
            unset($f);
        }

        Response::json(['folders' => $folders]);
    }

    /** Directories hidden entirely (not scanned at all). */
    private const HIDDEN_DIRS = ['untagged'];

    /** Structural wrapper dirs: scanned into but not listed as entries. */
    private const SKIP_DIRS = ['tagged', 'tagged/files', 'tagged/folders'];

    private function scan(string $absBase, string $relPath, array &$folders, int $depth, ?array &$fileCounts): void
    {
        $dir     = $absBase . ($relPath !== '' ? '/' . $relPath : '');
        $entries = @scandir($dir);
        if (!$entries) return;

        foreach ($entries as $item) {
            if ($item === '.' || $item === '..' || str_starts_with($item, '.')) continue;
            $full = $dir . '/' . $item;

            if (is_dir($full)) {
                $rel = ($relPath !== '' ? $relPath . '/' : '') . $item;

                // Hide entirely — don't scan children
                if ($relPath === '' && in_array($item, self::HIDDEN_DIRS)) continue;

                // Skip wrappers — scan children but don't list the dir itself
                if (in_array($rel, self::SKIP_DIRS)) {
                    $this->scan($absBase, $rel, $folders, $depth, $fileCounts);
                    continue;
                }

                $folders[] = ['name' => $item, 'path' => '/' . $rel, 'depth' => $depth];
                $this->scan($absBase, $rel, $folders, $depth + 1, $fileCounts);
            } elseif ($fileCounts !== null) {
                $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
                if (in_array($ext, self::AUDIO_EXTS)) {
                    // Increment current directory and all ancestors
                    $fileCounts[''] = ($fileCounts[''] ?? 0) + 1;
                    if ($relPath !== '') {
                        $parts = explode('/', $relPath);
                        $acc = '';
                        foreach ($parts as $part) {
                            $acc = $acc === '' ? $part : $acc . '/' . $part;
                            $fileCounts[$acc] = ($fileCounts[$acc] ?? 0) + 1;
                        }
                    }
                }
            }
        }
    }
}
