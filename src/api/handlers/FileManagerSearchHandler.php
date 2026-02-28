<?php

declare(strict_types=1);

/**
 * File Manager: recursive file search within a directory.
 * Supports wildcards (*, ?), bare audio extensions (flac, mp3), and .ext shorthand.
 */
class FileManagerSearchHandler
{
    private const AUDIO_EXTS = ['mp3', 'flac', 'ogg', 'wav', 'aac', 'm4a', 'wma', 'opus', 'aiff'];
    private const SKIP_DIRS  = ['tagged/folders', 'tagged/files', 'tagged'];
    private const MAX_RESULTS = 5000;

    public function handle(): void
    {
        $rawPath = '/' . ltrim(trim($_GET['path'] ?? '/'), '/');
        $query   = trim($_GET['q'] ?? '');

        if ($query === '') {
            Response::error('Query required', 400);
            return;
        }

        $absPath = FileManagerBrowseHandler::resolveVirtualPath($rawPath);
        if ($absPath === null || !is_dir($absPath)) {
            Response::error('Invalid path', 400);
            return;
        }

        $regex   = self::buildPattern(strtolower($query));
        $baseDir = rtrim(MediaBrowseHandler::BASE_DIR, '/');
        $results = [];

        $rii = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absPath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($rii as $file) {
            if (!$file->isFile()) continue;
            $name = $file->getFilename();
            if (!preg_match($regex, strtolower($name))) continue;

            $fullPath = $file->getPathname();

            // Build virtual path: strip base dir, then strip SKIP_DIR prefixes
            $relPath  = ltrim(str_replace($baseDir, '', $fullPath), '/');
            $virtPath = self::virtualPath($relPath);

            // Relative folder from search root for display
            $folder = dirname('/' . $virtPath);
            if ($rawPath !== '/') {
                $folder = substr($folder, strlen($rawPath)) ?: '/';
                $folder = '/' . ltrim($folder, '/');
            }

            $results[] = [
                'type'   => 'file',
                'name'   => $name,
                'path'   => '/' . $virtPath,
                'size'   => (int) $file->getSize(),
                'folder' => $folder === '/' ? '' : ltrim($folder, '/'),
            ];

            if (count($results) >= self::MAX_RESULTS) break;
        }

        usort($results, function (array $a, array $b): int {
            return strcasecmp($a['name'], $b['name']);
        });

        Response::json(['files' => $results, 'total' => count($results)]);
    }

    /** Strip SKIP_DIR prefixes from a real relative path to produce a virtual path. */
    private static function virtualPath(string $relPath): string
    {
        foreach (self::SKIP_DIRS as $skip) {
            $prefix = $skip . '/';
            if (str_starts_with($relPath, $prefix)) {
                return substr($relPath, strlen($prefix));
            }
        }
        return $relPath;
    }

    /** Build a PCRE regex from the user query. */
    private static function buildPattern(string $q): string
    {
        // Bare audio extension: "flac" → *.flac
        if (in_array($q, self::AUDIO_EXTS, true)) {
            return '/\.' . preg_quote($q, '/') . '$/';
        }

        // ".ext" shorthand
        if (str_starts_with($q, '.') && !str_contains($q, '*') && !str_contains($q, '?') && !str_contains($q, ' ')) {
            return '/' . preg_quote($q, '/') . '$/';
        }

        // Wildcard pattern
        if (str_contains($q, '*') || str_contains($q, '?')) {
            $escaped = preg_quote($q, '/');
            $escaped = str_replace(['\\*', '\\?'], ['.*', '.'], $escaped);
            return '/^' . $escaped . '$/';
        }

        // Plain substring
        return '/' . preg_quote($q, '/') . '/';
    }
}
