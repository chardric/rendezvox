<?php

declare(strict_types=1);

/**
 * File Manager: rename a file or folder and update all DB references.
 *
 * Songs may be stored in any of three path formats:
 *   1. Absolute:       /var/lib/rendezvox/music/tagged/Rock/song.mp3
 *   2. Relative:       tagged/Rock/song.mp3
 *   3. Slash-relative: /tagged/Rock/song.mp3
 *
 * All three formats are updated in a single operation.
 */
class FileManagerRenameHandler
{
    private const SYSTEM_DIRS = ['tagged', 'untagged'];

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

        // Disallow path separators in the new name
        $newName = basename($newName);
        if ($newName === '' || $newName === '.' || $newName === '..') {
            Response::error('Invalid new_name', 400);
            return;
        }

        $absOld = FileManagerBrowseHandler::resolveVirtualPath($oldPath);
        if ($absOld === null || !file_exists($absOld)) {
            Response::error('File or folder not found', 404);
            return;
        }

        // Block rename of system folders
        $base    = rtrim(realpath(MediaBrowseHandler::BASE_DIR) ?: MediaBrowseHandler::BASE_DIR, '/');
        $relOld  = substr($absOld, strlen($base) + 1);
        if (is_dir($absOld) && self::isProtected($relOld)) {
            Response::error('"' . $relOld . '" is a system folder and cannot be renamed.', 403);
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

        $isFile = is_file($absOld);

        if (!rename($absOld, $absNew)) {
            Response::error('Rename failed', 500);
            return;
        }

        self::syncPaths($db, $absOld, $absNew, $isFile);

        Response::json(['message' => 'Renamed successfully']);
    }

    private static function isProtected(string $rel): bool
    {
        if (!str_contains($rel, '/') && in_array($rel, self::SYSTEM_DIRS, true)) {
            return true;
        }
        $parts = explode('/', $rel, 3);
        if (count($parts) === 2 && in_array($parts[0], self::SYSTEM_DIRS, true)
            && in_array($parts[1], ['files', 'folders'], true)) {
            return true;
        }
        return false;
    }

    /**
     * Update all DB song paths after a filesystem rename or move.
     * Handles all three path storage formats (absolute, relative, /relative).
     * Made public+static so FileManagerMoveHandler can reuse it.
     */
    public static function syncPaths(\PDO $db, string $absOld, string $absNew, bool $isFile): void
    {
        $base     = rtrim(realpath(MediaBrowseHandler::BASE_DIR) ?: MediaBrowseHandler::BASE_DIR, '/');
        $relOld   = substr($absOld, strlen($base) + 1);  // tagged/Rock/song.mp3
        $relNew   = substr($absNew, strlen($base) + 1);
        $slashOld = '/' . $relOld;                        // /tagged/Rock/song.mp3
        $slashNew = '/' . $relNew;

        if ($isFile) {
            // Exact-match update — normalise all variants to absolute path
            $db->prepare(
                'UPDATE songs SET file_path = :new
                  WHERE file_path = :abs
                     OR file_path = :rel
                     OR file_path = :slash'
            )->execute([
                'new'   => $absNew,
                'abs'   => $absOld,
                'rel'   => $relOld,
                'slash' => $slashOld,
            ]);
        } else {
            // Folder: bulk-update using prefix matching for each path format

            // 1. Absolute-stored paths
            $db->prepare(
                'UPDATE songs
                    SET file_path = :new_base || SUBSTRING(file_path FROM LENGTH(:old_base) + 1)
                  WHERE file_path LIKE :prefix'
            )->execute([
                'new_base' => $absNew,
                'old_base' => $absOld,
                'prefix'   => $absOld . '/%',
            ]);

            // 2. Relative paths (no leading slash)
            $db->prepare(
                'UPDATE songs
                    SET file_path = :new_base || SUBSTRING(file_path FROM LENGTH(:old_base) + 1)
                  WHERE file_path LIKE :prefix'
            )->execute([
                'new_base' => $relNew,
                'old_base' => $relOld,
                'prefix'   => $relOld . '/%',
            ]);

            // 3. Slash-relative paths (/tagged/…)
            $db->prepare(
                'UPDATE songs
                    SET file_path = :new_base || SUBSTRING(file_path FROM LENGTH(:old_base) + 1)
                  WHERE file_path LIKE :prefix'
            )->execute([
                'new_base' => $slashNew,
                'old_base' => $slashOld,
                'prefix'   => $slashOld . '/%',
            ]);
        }
    }
}
