<?php

declare(strict_types=1);

/**
 * File Manager: browse a directory within the music folder.
 * Unlike MediaBrowseHandler, this shows ALL folders and files with no filtering.
 */
class FileManagerBrowseHandler
{
    private const AUDIO_EXTS = ['mp3', 'flac', 'ogg', 'wav', 'aac', 'm4a', 'wma', 'opus', 'aiff'];

    public function handle(): void
    {
        $rawPath = '/' . ltrim(trim($_GET['path'] ?? '/'), '/');
        $absPath = MediaBrowseHandler::safePath($rawPath);

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

        foreach (scandir($absPath) ?: [] as $item) {
            if ($item === '.' || $item === '..' || str_starts_with($item, '.')) continue;

            $fullPath     = $absPath . '/' . $item;
            $relativePath = rtrim($rawPath, '/') . '/' . $item;

            if (is_dir($fullPath)) {
                // Count only direct children (fast — no recursive scan)
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
}
