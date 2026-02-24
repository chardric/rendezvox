<?php

declare(strict_types=1);

/**
 * File Manager: move a file or folder and update all DB references.
 * Reuses FileManagerRenameHandler::syncPaths for the DB update logic.
 */
class FileManagerMoveHandler
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
        $absNew = $absDest . '/' . $name;

        if (file_exists($absNew)) {
            Response::error('A file or folder with that name already exists at the destination', 409);
            return;
        }

        $isFile = is_file($absSrc);

        if (!rename($absSrc, $absNew)) {
            Response::error('Move failed', 500);
            return;
        }

        FileManagerRenameHandler::syncPaths($db, $absSrc, $absNew, $isFile);

        Response::json(['message' => 'Moved successfully']);
    }
}
