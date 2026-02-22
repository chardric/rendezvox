<?php

declare(strict_types=1);

class MediaRenameHandler
{
    public function handle(): void
    {
        $db   = Database::get();
        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        $oldPath = trim($body['path']     ?? '');
        $newName = trim($body['new_name'] ?? '');

        if ($oldPath === '' || $newName === '') {
            Response::error('path and new_name are required', 400);
            return;
        }

        // new_name must be a simple filename, no path separators
        $newName = basename($newName);
        if ($newName === '' || $newName === '.' || $newName === '..') {
            Response::error('Invalid new_name', 400);
            return;
        }

        $absOld = MediaBrowseHandler::safePath($oldPath);
        if ($absOld === null || !file_exists($absOld)) {
            Response::error('File or folder not found', 404);
            return;
        }

        $absNew = dirname($absOld) . '/' . $newName;
        $base   = realpath(MediaBrowseHandler::BASE_DIR) ?: MediaBrowseHandler::BASE_DIR;
        if (!str_starts_with($absNew, $base)) {
            Response::error('Invalid new_name', 400);
            return;
        }

        if (file_exists($absNew)) {
            Response::error('A file or folder with that name already exists', 409);
            return;
        }

        if (!rename($absOld, $absNew)) {
            Response::error('Rename failed', 500);
            return;
        }

        // Update DB paths after rename
        if (is_file($absNew)) {
            $db->prepare('UPDATE songs SET file_path = :new WHERE file_path = :old')
               ->execute(['new' => $absNew, 'old' => $absOld]);
        } elseif (is_dir($absNew)) {
            // Bulk-update all songs inside the renamed folder
            $stmt = $db->prepare("
                UPDATE songs
                SET file_path = :base_new || SUBSTRING(file_path FROM LENGTH(:base_old) + 1)
                WHERE file_path LIKE :prefix
            ");
            $stmt->execute([
                'base_new' => $absNew,
                'base_old' => $absOld,
                'prefix'   => $absOld . '/%',
            ]);
        }

        Response::json(['message' => 'Renamed successfully']);
    }
}
