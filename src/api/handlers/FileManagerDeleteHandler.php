<?php

declare(strict_types=1);

/**
 * File Manager: permanently delete file(s) or folder(s) and remove all DB references.
 *
 * Single:  DELETE /admin/files/delete?path=...
 * Bulk:    POST  /admin/files/delete-bulk  {paths:[...]}
 *
 * All child records (playlist_songs, play_history, song_requests, request_queue)
 * are removed automatically via ON DELETE CASCADE.
 *
 * Root-level system folders (tagged, untagged) are protected and cannot be deleted.
 */
class FileManagerDeleteHandler
{
    /** Root-level folder names that may never be deleted. */
    private const SYSTEM_DIRS = ['tagged', 'untagged'];

    /** Single-item delete (DELETE method, query param). */
    public function handle(): void
    {
        $rawPath = trim($_GET['path'] ?? '');
        if ($rawPath === '') {
            Response::error('path is required', 400);
            return;
        }

        $result = $this->deleteOne(Database::get(), $rawPath);
        if ($result !== null) {
            Response::error($result, $result === 'Not found' ? 404 : 403);
            return;
        }
        Response::json(['message' => 'Deleted']);
    }

    /** Bulk delete (POST method, JSON body). */
    public function handleBulk(): void
    {
        $body  = json_decode(file_get_contents('php://input'), true) ?? [];
        $paths = $body['paths'] ?? [];

        if (!is_array($paths) || count($paths) === 0) {
            Response::error('paths array is required', 400);
            return;
        }

        $db   = Database::get();
        $ok   = 0;
        $fail = 0;

        foreach ($paths as $p) {
            $p = trim((string) $p);
            if ($p === '') { $fail++; continue; }
            $err = $this->deleteOne($db, $p);
            if ($err !== null) { $fail++; } else { $ok++; }
        }

        Response::json([
            'message' => $ok . ' deleted' . ($fail > 0 ? ', ' . $fail . ' failed' : ''),
            'ok'      => $ok,
            'failed'  => $fail,
        ]);
    }

    /**
     * Delete a single path. Returns null on success, error string on failure.
     */
    private function deleteOne(\PDO $db, string $rawPath): ?string
    {
        $abs = FileManagerBrowseHandler::resolveVirtualPath($rawPath);
        if ($abs === null || !file_exists($abs)) {
            return 'Not found';
        }

        $base  = rtrim(realpath(MediaBrowseHandler::BASE_DIR) ?: MediaBrowseHandler::BASE_DIR, '/');
        $rel   = substr($abs, strlen($base) + 1);
        $slash = '/' . $rel;

        if (is_dir($abs) && self::isProtected($rel)) {
            return '"' . $rel . '" is a system folder and cannot be deleted.';
        }

        if (is_dir($abs)) {
            self::deleteDir($abs);
            $db->prepare(
                'DELETE FROM songs
                  WHERE file_path LIKE :abs_prefix
                     OR file_path LIKE :rel_prefix
                     OR file_path LIKE :slash_prefix'
            )->execute([
                'abs_prefix'   => $abs   . '/%',
                'rel_prefix'   => $rel   . '/%',
                'slash_prefix' => $slash . '/%',
            ]);
        } else {
            unlink($abs);
            $db->prepare(
                'DELETE FROM songs
                  WHERE file_path = :abs
                     OR file_path = :rel
                     OR file_path = :slash'
            )->execute([
                'abs'   => $abs,
                'rel'   => $rel,
                'slash' => $slash,
            ]);
        }

        return null;
    }

    /** Recursively delete all empty folders under a given path. */
    public function deleteEmpty(): void
    {
        $rawPath = trim($_GET['path'] ?? '/');
        $abs = FileManagerBrowseHandler::resolveVirtualPath($rawPath);
        if ($abs === null || !is_dir($abs)) {
            Response::error('Invalid path', 400);
            return;
        }

        $base    = rtrim(realpath(MediaBrowseHandler::BASE_DIR) ?: MediaBrowseHandler::BASE_DIR, '/');
        $deleted = 0;
        self::removeEmptyDirs($abs, $base, $deleted);

        Response::json([
            'message' => $deleted > 0 ? $deleted . ' empty folder(s) deleted' : 'No empty folders found',
            'deleted' => $deleted,
        ]);
    }

    /**
     * Recursively remove empty directories (bottom-up).
     * A directory is "empty" if it contains no files and all subdirs are also empty.
     * System folders are never removed.
     */
    private static function removeEmptyDirs(string $dir, string $base, int &$deleted): bool
    {
        $entries = scandir($dir);
        if ($entries === false) {
            return false;
        }

        $hasContent = false;
        foreach ($entries as $item) {
            if ($item === '.' || $item === '..') continue;
            $full = $dir . '/' . $item;
            if (is_dir($full)) {
                // Recurse — if subdir couldn't be removed, this dir has content
                if (!self::removeEmptyDirs($full, $base, $deleted)) {
                    $hasContent = true;
                }
            } else {
                $hasContent = true;
            }
        }

        if ($hasContent) {
            return false;
        }

        // Check if this folder is protected
        $rel = substr($dir, strlen($base) + 1);
        if ($rel === false || $rel === '' || self::isProtected($rel)) {
            return false;
        }

        @rmdir($dir);
        if (!is_dir($dir)) {
            $deleted++;
            return true;
        }
        return false;
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

    private static function deleteDir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') continue;
            $full = $dir . '/' . $item;
            if (is_dir($full)) {
                self::deleteDir($full);
            } else {
                unlink($full);
            }
        }
        rmdir($dir);
    }
}
