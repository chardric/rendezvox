<?php

declare(strict_types=1);

/**
 * File Manager: pre-delete check for folder → playlist associations.
 * Returns playlist names whose songs came from the given folder path.
 *
 * GET /admin/files/delete-check?path=/tagged/Rock
 * Response: { "playlists": ["Rock Hits", "Classic Rock"] }
 */
class FileManagerDeleteCheckHandler
{
    public function handle(): void
    {
        $db      = Database::get();
        $rawPath = trim($_GET['path'] ?? '');

        if ($rawPath === '') {
            Response::json(['playlists' => []]);
            return;
        }

        $abs = FileManagerBrowseHandler::resolveVirtualPath($rawPath);
        if ($abs === null || !is_dir($abs)) {
            Response::json(['playlists' => []]);
            return;
        }

        // Compute all three path variants
        $base  = rtrim(realpath(MediaBrowseHandler::BASE_DIR) ?: MediaBrowseHandler::BASE_DIR, '/');
        $rel   = substr($abs, strlen($base) + 1);   // tagged/Rock
        $slash = '/' . $rel;                         // /tagged/Rock

        // Match playlists imported from this folder or any subfolder within it.
        // Description format: "Imported from <path>"
        $stmt = $db->prepare(
            "SELECT name FROM playlists
              WHERE description = :d_abs
                 OR description = :d_rel
                 OR description = :d_slash
                 OR description LIKE :d_abs_sub
                 OR description LIKE :d_rel_sub
                 OR description LIKE :d_slash_sub
              ORDER BY name"
        );
        $stmt->execute([
            'd_abs'       => 'Imported from ' . $abs,
            'd_rel'       => 'Imported from ' . $rel,
            'd_slash'     => 'Imported from ' . $slash,
            'd_abs_sub'   => 'Imported from ' . $abs   . '/%',
            'd_rel_sub'   => 'Imported from ' . $rel   . '/%',
            'd_slash_sub' => 'Imported from ' . $slash . '/%',
        ]);

        Response::json(['playlists' => $stmt->fetchAll(\PDO::FETCH_COLUMN)]);
    }
}
