<?php

declare(strict_types=1);

/**
 * File Manager: return full folder tree for the sidebar.
 * Shows all directories, no filtering, no recursive file counting (fast).
 */
class FileManagerTreeHandler
{
    public function handle(): void
    {
        $folders = [['name' => 'Music', 'path' => '/', 'depth' => -1]];
        $this->scan(MediaBrowseHandler::BASE_DIR, '', $folders, 0);
        Response::json(['folders' => $folders]);
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

            $relPath   = ($rel !== '' ? $rel . '/' : '') . $item;
            $folders[] = ['name' => $item, 'path' => '/' . $relPath, 'depth' => $depth];
            $this->scan($base, $relPath, $folders, $depth + 1);
        }
    }
}
