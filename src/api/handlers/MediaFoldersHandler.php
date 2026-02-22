<?php

declare(strict_types=1);

class MediaFoldersHandler
{
    public function handle(): void
    {
        $folders = [['name' => '/ (root)', 'path' => '/', 'depth' => -1]];
        $this->scan(MediaBrowseHandler::BASE_DIR, '', $folders, 0);
        Response::json(['folders' => $folders]);
    }

    private function scan(string $absBase, string $relPath, array &$folders, int $depth): void
    {
        $dir     = $absBase . ($relPath !== '' ? '/' . $relPath : '');
        $entries = @scandir($dir);
        if (!$entries) return;

        foreach ($entries as $item) {
            if ($item === '.' || $item === '..' || str_starts_with($item, '.')) continue;
            $full = $dir . '/' . $item;
            if (!is_dir($full)) continue;

            $rel       = ($relPath !== '' ? $relPath . '/' : '') . $item;
            $folders[] = ['name' => $item, 'path' => '/' . $rel, 'depth' => $depth];
            $this->scan($absBase, $rel, $folders, $depth + 1);
        }
    }
}
