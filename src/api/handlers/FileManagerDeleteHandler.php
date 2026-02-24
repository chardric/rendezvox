<?php

declare(strict_types=1);

/**
 * File Manager: permanently delete a file or folder and remove all DB references.
 * Handles all three path storage formats (absolute, relative, /relative).
 * All child records (playlist_songs, play_history, song_requests, request_queue)
 * are removed automatically via ON DELETE CASCADE.
 *
 * Root-level system folders (tagged, _untagged, imports, upload) are protected
 * and cannot be deleted.
 */
class FileManagerDeleteHandler
{
    /** Root-level folder names that may never be deleted. */
    private const SYSTEM_DIRS = ['tagged', '_untagged', 'imports', 'upload'];

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

        $base  = rtrim(realpath(MediaBrowseHandler::BASE_DIR) ?: MediaBrowseHandler::BASE_DIR, '/');
        $rel   = substr($abs, strlen($base) + 1);  // tagged/Rock/song.mp3
        $slash = '/' . $rel;                        // /tagged/Rock/song.mp3

        // Block deletion of root-level system folders
        if (is_dir($abs) && !str_contains($rel, '/') && in_array($rel, self::SYSTEM_DIRS, true)) {
            Response::error('"' . $rel . '" is a system folder and cannot be deleted.', 403);
            return;
        }

        if (is_dir($abs)) {
            $this->deleteDir($abs);

            // Remove all song records whose file_path points inside this folder
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

            // Remove song records for this specific file
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
