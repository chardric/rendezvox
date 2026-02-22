<?php

declare(strict_types=1);

class MediaMoveHandler
{
    public function handle(): void
    {
        $db   = Database::get();
        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        $srcPath    = trim($body['path']        ?? '');
        $destFolder = trim($body['destination'] ?? '');

        if ($srcPath === '' || $destFolder === '') {
            Response::error('path and destination are required', 400);
            return;
        }

        $absSrc  = MediaBrowseHandler::safePath($srcPath);
        $absDest = MediaBrowseHandler::safePath($destFolder);

        if ($absSrc === null || !file_exists($absSrc)) {
            Response::error('Source not found', 404);
            return;
        }
        if ($absDest === null || !is_dir($absDest)) {
            Response::error('Destination folder not found', 404);
            return;
        }

        $name   = basename($absSrc);
        $target = $absDest . '/' . $name;

        if (file_exists($target)) {
            Response::error('A file or folder with that name already exists at the destination', 409);
            return;
        }

        if (!rename($absSrc, $target)) {
            Response::error('Move failed', 500);
            return;
        }

        // Update DB paths
        if (is_file($target)) {
            $db->prepare('UPDATE songs SET file_path = :new WHERE file_path = :old')
               ->execute(['new' => $target, 'old' => $absSrc]);
        } elseif (is_dir($target)) {
            $stmt = $db->prepare("
                UPDATE songs
                SET file_path = :base_new || SUBSTRING(file_path FROM LENGTH(:base_old) + 1)
                WHERE file_path LIKE :prefix
            ");
            $stmt->execute([
                'base_new' => $target,
                'base_old' => $absSrc,
                'prefix'   => $absSrc . '/%',
            ]);
        }

        Response::json(['message' => 'Moved to ' . $destFolder . '/' . $name]);
    }
}
