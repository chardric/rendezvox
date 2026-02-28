<?php

declare(strict_types=1);

class MediaBrowseHandler
{
    public const BASE_DIR = '/var/lib/rendezvox/music';

    public function handle(): void
    {
        $db      = Database::get();
        $rawPath = '/' . ltrim(trim($_GET['path'] ?? '/'), '/');
        $absPath = self::safePath($rawPath);

        if ($absPath === null) {
            Response::error('Invalid path', 400);
            return;
        }

        if (!is_dir($absPath)) {
            if ($rawPath === '/') {
                mkdir($absPath, 0775, true);
            } else {
                Response::error('Path not found', 404);
                return;
            }
        }

        $folders   = [];
        $absPaths  = [];    // ordered list of absolute audio file paths
        $fileInfos = [];    // abs_path → {filename, path, size}
        $audioExts = ['mp3', 'flac', 'ogg', 'wav', 'aac', 'm4a'];
        $notice    = null;

        // Internal directories hidden from the root folder list
        $hiddenDirs = ['untagged'];

        foreach (scandir($absPath) ?: [] as $item) {
            if ($item === '.' || $item === '..' || str_starts_with($item, '.')) continue;

            $fullPath     = $absPath . '/' . $item;
            $relativePath = rtrim($rawPath, '/') . '/' . $item;

            if (is_dir($fullPath)) {
                // Hide internal directories from root folder chips
                if ($rawPath === '/' && in_array($item, $hiddenDirs)) continue;

                $fileCount = 0;
                $iter = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($fullPath, RecursiveDirectoryIterator::SKIP_DOTS)
                );
                foreach ($iter as $fi) {
                    if (!$fi->isFile()) continue;
                    $sfExt = strtolower($fi->getExtension());
                    if (in_array($sfExt, $audioExts)) {
                        $fileCount++;
                    }
                }
                $folders[] = ['name' => $item, 'path' => $relativePath, 'file_count' => $fileCount];
            } else {
                // At root level, skip loose files — we show untagged/files/ instead
                if ($rawPath === '/') continue;

                $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
                if (!in_array($ext, $audioExts)) continue;

                $absPaths[]         = $fullPath;
                $fileInfos[$fullPath] = [
                    'filename' => $item,
                    'path'     => $relativePath,
                    'size'     => (int) filesize($fullPath),
                ];
            }
        }

        // At root, show files from untagged/files/ as the file listing
        if ($rawPath === '/') {
            $pendingAbs = self::BASE_DIR . '/untagged/files';
            if (is_dir($pendingAbs)) {
                foreach (scandir($pendingAbs) ?: [] as $item) {
                    if ($item === '.' || $item === '..' || str_starts_with($item, '.')) continue;
                    $fullPath = $pendingAbs . '/' . $item;
                    if (!is_file($fullPath)) continue;
                    $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
                    if (!in_array($ext, $audioExts)) continue;

                    $relativePath       = '/untagged/files/' . $item;
                    $absPaths[]         = $fullPath;
                    $fileInfos[$fullPath] = [
                        'filename' => $item,
                        'path'     => $relativePath,
                        'size'     => (int) filesize($fullPath),
                    ];
                }
            }
            if (!empty($absPaths)) {
                $notice = count($absPaths) . ' file(s) waiting to be organized. The organizer will process them shortly.';
            }
        }

        // Bulk-query songs table for all audio files in this directory.
        // Songs may be stored with absolute or relative paths, so query both.
        $songsByPath = [];
        if (!empty($absPaths)) {
            $basePrefix = rtrim(self::BASE_DIR, '/') . '/';
            $relPaths = array_map(function ($abs) use ($basePrefix) {
                return str_starts_with($abs, $basePrefix)
                    ? substr($abs, strlen($basePrefix))
                    : ltrim($abs, '/');
            }, $absPaths);

            $allLookups = array_merge($absPaths, $relPaths);
            $placeholders = implode(',', array_fill(0, count($allLookups), '?'));
            $stmt = $db->prepare("
                SELECT
                    s.id, s.title, s.artist_id, s.category_id, s.file_path,
                    s.duration_ms, s.rotation_weight, s.play_count, s.year,
                    s.is_active, s.is_requestable, s.created_at,
                    a.name AS artist_name,
                    c.name AS category_name
                FROM songs s
                JOIN artists    a ON a.id = s.artist_id
                JOIN categories c ON c.id = s.category_id
                WHERE s.file_path IN ($placeholders)
            ");
            $stmt->execute($allLookups);
            while ($row = $stmt->fetch()) {
                $songsByPath[$row['file_path']] = $row;
            }

            // Re-key by absolute path so the merge loop below finds matches
            $absToRel = array_combine($absPaths, $relPaths);
            foreach ($absPaths as $abs) {
                if (isset($songsByPath[$abs])) continue;
                $rel = $absToRel[$abs];
                if (isset($songsByPath[$rel])) {
                    $songsByPath[$abs] = $songsByPath[$rel];
                }
            }
        }

        // Merge filesystem info with DB metadata
        $songs = [];
        foreach ($absPaths as $abs) {
            $fi   = $fileInfos[$abs];
            $song = $songsByPath[$abs] ?? null;

            $songs[] = [
                'filename'        => $fi['filename'],
                'file_path'       => $fi['path'],                  // relative path (for API calls)
                'size'            => $fi['size'],
                'song_id'         => $song ? (int)   $song['id']              : null,
                'title'           => $song ?          $song['title']           : null,
                'artist_id'       => $song ? (int)   $song['artist_id']       : null,
                'artist_name'     => $song ?          $song['artist_name']     : null,
                'year'            => $song ? ($song['year'] ? (int) $song['year'] : null) : null,
                'category_id'     => $song ? (int)   $song['category_id']     : null,
                'category_name'   => $song ?          $song['category_name']   : null,
                'duration_ms'     => $song ? (int)   $song['duration_ms']     : null,
                'rotation_weight' => $song ? (float) $song['rotation_weight'] : null,
                'play_count'      => $song ? (int)   $song['play_count']      : null,
                'is_active'       => $song ? (bool)  $song['is_active']       : null,
                'is_requestable'  => $song ? (bool)  $song['is_requestable']  : null,
                'created_at'      => $song ?          $song['created_at']      : null,
            ];
        }

        usort($folders, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        usort($songs,   fn($a, $b) => strcasecmp($a['filename'], $b['filename']));

        // Breadcrumbs
        $breadcrumbs = [['name' => 'Home', 'path' => '/']];
        if ($rawPath !== '/') {
            $parts = explode('/', trim($rawPath, '/'));
            $acc   = '';
            foreach ($parts as $part) {
                $acc          .= '/' . $part;
                $breadcrumbs[] = ['name' => $part, 'path' => $acc];
            }
        }

        $response = [
            'path'        => $rawPath,
            'breadcrumbs' => $breadcrumbs,
            'folders'     => $folders,
            'songs'       => $songs,
        ];
        if ($notice !== null) {
            $response['notice'] = $notice;
        }
        Response::json($response);
    }

    /**
     * Resolve a relative browser path to a safe absolute path within BASE_DIR.
     * Returns null if traversal outside BASE_DIR is detected.
     *
     * Properly handles filenames containing ".." (e.g. "H.O.L.Y..mp3") by
     * resolving ".." only as a path-segment separator, not as a substring.
     */
    public static function safePath(string $rawPath): ?string
    {
        // Reject Windows-style backslash paths outright
        if (str_contains($rawPath, '\\')) return null;

        // Normalize ".." and "." as path segments (segment-level resolution)
        $parts    = explode('/', '/' . ltrim($rawPath, '/'));
        $resolved = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') continue;
            if ($part === '..') { array_pop($resolved); continue; }
            $resolved[] = $part;
        }
        $abs = self::BASE_DIR . ($resolved ? '/' . implode('/', $resolved) : '');

        // If the path already exists, let realpath() resolve symlinks
        $real = realpath($abs);
        if ($real !== false) {
            $abs = $real;
        }

        // Final guard: must remain inside BASE_DIR
        $base = realpath(self::BASE_DIR) ?: self::BASE_DIR;
        if (!str_starts_with($abs, $base)) {
            return null;
        }

        return $abs;
    }
}
