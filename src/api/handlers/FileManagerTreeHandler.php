<?php

declare(strict_types=1);

/**
 * File Manager: return full folder tree for the sidebar.
 * Hides structural dirs (tagged/untagged wrappers) — shows only audio folders.
 */
class FileManagerTreeHandler
{
    /** Directories hidden entirely (not scanned at all). */
    private const HIDDEN_DIRS = ['untagged'];

    /** Structural wrapper dirs: scanned into but not listed as entries (longest first for prefix stripping). */
    private const SKIP_DIRS = ['tagged/folders', 'tagged/files', 'tagged'];

    public function handle(): void
    {
        $folders = [['name' => 'Music', 'path' => '/', 'depth' => -1]];
        $this->scan(MediaBrowseHandler::BASE_DIR, '', $folders, 0);
        Response::json(['folders' => $folders]);
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

    private function scan(string $base, string $rel, array &$folders, int $depth): void
    {
        $dir     = $base . ($rel !== '' ? '/' . $rel : '');
        $entries = @scandir($dir);
        if (!$entries) return;

        foreach ($entries as $item) {
            if ($item === '.' || $item === '..' || str_starts_with($item, '.')) continue;
            $full = $dir . '/' . $item;
            if (!is_dir($full)) continue;

            $relPath = ($rel !== '' ? $rel . '/' : '') . $item;

            // Hide entirely — don't scan children
            if ($rel === '' && in_array($item, self::HIDDEN_DIRS)) continue;

            // Skip wrappers — scan children but don't list the dir itself
            if (in_array($relPath, self::SKIP_DIRS)) {
                $this->scan($base, $relPath, $folders, $depth);
                continue;
            }

            $vPath = self::virtualPath($relPath);
            $folders[] = ['name' => $item, 'path' => '/' . $vPath, 'depth' => $depth];
            $this->scan($base, $relPath, $folders, $depth + 1);
        }
    }
}
