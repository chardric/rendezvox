<?php

declare(strict_types=1);

/**
 * File Manager: browse a directory within the music folder.
 * Hides structural dirs (tagged/untagged wrappers) — shows only audio folders.
 */
class FileManagerBrowseHandler
{
    private const AUDIO_EXTS = ['mp3', 'flac', 'ogg', 'wav', 'aac', 'm4a', 'wma', 'opus', 'aiff'];

    /** Directories hidden entirely (not scanned at all). */
    private const HIDDEN_DIRS = ['untagged'];

    /** Structural wrapper dirs: scanned into but not listed as entries. */
    private const SKIP_DIRS = ['tagged/folders', 'tagged/files', 'tagged'];

    /**
     * Resolve a virtual path to an absolute disk path.
     * Folders shown at root level may actually live inside a SKIP_DIR on disk.
     */
    public static function resolveVirtualPath(string $rawPath): ?string
    {
        // Direct path first
        $abs = MediaBrowseHandler::safePath($rawPath);
        if ($abs !== null && (is_dir($abs) || is_file($abs))) {
            return $abs;
        }

        // Try each SKIP_DIR prefix for non-root paths
        if ($rawPath !== '/') {
            foreach (self::SKIP_DIRS as $skip) {
                $mapped = '/' . $skip . $rawPath;
                $abs = MediaBrowseHandler::safePath($mapped);
                if ($abs !== null && (is_dir($abs) || is_file($abs))) {
                    return $abs;
                }
            }
        }

        return $rawPath === '/' ? (MediaBrowseHandler::safePath('/')) : null;
    }

    public function handle(): void
    {
        $rawPath = '/' . ltrim(trim($_GET['path'] ?? '/'), '/');
        $absPath = self::resolveVirtualPath($rawPath);

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

        $folders = [];
        $files   = [];

        // For root and wrapper dirs, collect children from unwrapped subdirs
        $scanDirs = [['abs' => $absPath, 'rel' => $rawPath]];

        if ($rawPath === '/') {
            $scanDirs = $this->unwrapRoot($absPath);
        }

        foreach ($scanDirs as $sd) {
            $this->scanDir($sd['abs'], $sd['rel'], $folders, $files);
        }

        usort($folders, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        usort($files,   fn($a, $b) => strcasecmp($a['name'], $b['name']));

        // Build breadcrumbs
        $breadcrumbs = [['name' => 'Music', 'path' => '/']];
        if ($rawPath !== '/') {
            $parts = explode('/', trim($rawPath, '/'));
            $acc   = '';
            foreach ($parts as $part) {
                $acc          .= '/' . $part;
                $breadcrumbs[] = ['name' => $part, 'path' => $acc];
            }
        }

        Response::json([
            'path'        => $rawPath,
            'breadcrumbs' => $breadcrumbs,
            'folders'     => $folders,
            'files'       => $files,
        ]);
    }

    /**
     * For root path, unwrap structural dirs so their children appear at root level.
     * Returns list of [{abs, rel}] directories to scan.
     */
    private function unwrapRoot(string $absRoot): array
    {
        $result = [];
        // Collect from each SKIP_DIR's children directly
        foreach (self::SKIP_DIRS as $skip) {
            $skipAbs = $absRoot . '/' . $skip;
            if (is_dir($skipAbs)) {
                $result[] = ['abs' => $skipAbs, 'rel' => '/'];
            }
        }
        // Also scan root itself for non-hidden, non-skip items
        $result[] = ['abs' => $absRoot, 'rel' => '/', 'filter' => true];
        return $result;
    }

    private function scanDir(string $absDir, string $relPrefix, array &$folders, array &$files): void
    {
        $filterRoot = ($relPrefix === '/');

        foreach (scandir($absDir) ?: [] as $item) {
            if ($item === '.' || $item === '..' || str_starts_with($item, '.')) continue;

            // At root level, skip structural/hidden dirs
            if ($filterRoot) {
                $rel = ltrim(str_replace(MediaBrowseHandler::BASE_DIR, '', $absDir), '/');
                $relItem = ($rel !== '' ? $rel . '/' : '') . $item;
                if (in_array($item, self::HIDDEN_DIRS) || in_array($relItem, self::SKIP_DIRS)) continue;
            }

            $fullPath     = $absDir . '/' . $item;
            $relativePath = rtrim($relPrefix, '/') . '/' . $item;

            if (is_dir($fullPath)) {
                $childCount = 0;
                foreach (scandir($fullPath) ?: [] as $child) {
                    if ($child === '.' || $child === '..') continue;
                    $childCount++;
                }
                $folders[] = [
                    'name'        => $item,
                    'path'        => $relativePath,
                    'child_count' => $childCount,
                ];
            } elseif (is_file($fullPath)) {
                $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
                $files[] = [
                    'name' => $item,
                    'path' => $relativePath,
                    'size' => (int) filesize($fullPath),
                    'type' => in_array($ext, self::AUDIO_EXTS) ? 'audio' : 'other',
                ];
            }
        }
    }
}
