<?php

declare(strict_types=1);

/**
 * File Manager: move file(s) or folder(s) and update all DB references.
 * Accepts single item {path, destination} or bulk {paths:[], destination}.
 * Reuses FileManagerRenameHandler::syncPaths for the DB update logic.
 */
class FileManagerMoveHandler
{
    private const SYSTEM_DIRS = ['tagged', 'untagged'];

    public function handle(): void
    {
        $db   = Database::get();
        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        $destFolder = trim($body['destination'] ?? '');
        if ($destFolder === '') {
            Response::error('destination is required', 400);
            return;
        }

        // Support bulk: {paths:[...], destination} or single: {path, destination}
        $paths = $body['paths'] ?? null;
        if (!is_array($paths)) {
            $single = trim($body['path'] ?? '');
            if ($single === '') {
                Response::error('path or paths is required', 400);
                return;
            }
            $paths = [$single];
        }

        $absDest = FileManagerBrowseHandler::resolveVirtualPath($destFolder);
        if ($absDest === null || !is_dir($absDest)) {
            Response::error('Destination folder not found', 404);
            return;
        }

        $base = rtrim(realpath(MediaBrowseHandler::BASE_DIR) ?: MediaBrowseHandler::BASE_DIR, '/');
        $ok   = 0;
        $fail = 0;
        $errors = [];

        foreach ($paths as $srcPath) {
            $srcPath = trim((string) $srcPath);
            if ($srcPath === '') { $fail++; continue; }

            $absSrc = FileManagerBrowseHandler::resolveVirtualPath($srcPath);
            if ($absSrc === null || !file_exists($absSrc)) {
                $fail++;
                $errors[] = basename($srcPath) . ': not found';
                continue;
            }

            // Block moving system folders
            $relSrc = substr($absSrc, strlen($base) + 1);
            if (is_dir($absSrc) && self::isProtected($relSrc)) {
                $fail++;
                $errors[] = basename($srcPath) . ': system folder';
                continue;
            }

            $name   = basename($absSrc);
            $absNew = $absDest . '/' . $name;

            if (file_exists($absNew)) {
                $fail++;
                $errors[] = $name . ': already exists';
                continue;
            }

            $isFile = is_file($absSrc);

            if (!rename($absSrc, $absNew)) {
                $fail++;
                $errors[] = $name . ': move failed';
                continue;
            }

            FileManagerRenameHandler::syncPaths($db, $absSrc, $absNew, $isFile);
            $ok++;
        }

        Response::json([
            'message' => $ok . ' moved' . ($fail > 0 ? ', ' . $fail . ' failed' : ''),
            'ok'      => $ok,
            'failed'  => $fail,
            'errors'  => array_slice($errors, 0, 10),
        ]);
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
}
