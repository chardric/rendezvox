<?php

declare(strict_types=1);

class MediaDeleteHandler
{
    public function handle(): void
    {
        $db      = Database::get();
        $rawPath = trim($_GET['path'] ?? '');

        if ($rawPath === '') {
            Response::error('path is required', 400);
            return;
        }

        $abs = MediaBrowseHandler::safePath($rawPath);
        if ($abs === null || !file_exists($abs)) {
            Response::error('Not found', 404);
            return;
        }

        if (is_dir($abs)) {
            $this->deleteDir($abs);
            // Remove song records whose files lived inside this folder
            $db->prepare('DELETE FROM songs WHERE file_path LIKE :prefix')
               ->execute(['prefix' => $abs . '/%']);
        } else {
            unlink($abs);
            // Remove the song record for this file
            $db->prepare('DELETE FROM songs WHERE file_path = :path')
               ->execute(['path' => $abs]);
        }

        Response::json(['message' => 'Deleted']);
    }

    private function deleteDir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') continue;
            $full = $dir . '/' . $item;
            if (is_dir($full)) {
                $this->deleteDir($full);
            } else {
                unlink($full);
            }
        }
        rmdir($dir);
    }
}
