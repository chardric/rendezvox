<?php

declare(strict_types=1);

class MediaCopyHandler
{
    public function handle(): void
    {
        $body       = json_decode(file_get_contents('php://input'), true) ?? [];
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

        // If target exists, append _copy suffix to avoid collision
        if (file_exists($target)) {
            $base = pathinfo($name, PATHINFO_FILENAME);
            $ext  = pathinfo($name, PATHINFO_EXTENSION);
            $i    = 1;
            do {
                $newName = $ext !== '' ? "{$base}_copy{$i}.{$ext}" : "{$base}_copy{$i}";
                $target  = $absDest . '/' . $newName;
                $i++;
            } while (file_exists($target) && $i <= 99);
        }

        if (is_file($absSrc)) {
            if (!copy($absSrc, $target)) {
                Response::error('Copy failed', 500);
                return;
            }
        } elseif (is_dir($absSrc)) {
            if (!$this->copyDir($absSrc, $target)) {
                Response::error('Copy failed', 500);
                return;
            }
        } else {
            Response::error('Source is not a file or directory', 400);
            return;
        }

        Response::json(['message' => 'Copied successfully', 'name' => basename($target)]);
    }

    private function copyDir(string $src, string $dest): bool
    {
        if (!mkdir($dest, 0775, true)) {
            return false;
        }
        foreach (scandir($src) ?: [] as $item) {
            if ($item === '.' || $item === '..') continue;
            $srcFull  = $src  . '/' . $item;
            $destFull = $dest . '/' . $item;
            if (is_dir($srcFull)) {
                if (!$this->copyDir($srcFull, $destFull)) return false;
            } else {
                if (!copy($srcFull, $destFull)) return false;
            }
        }
        return true;
    }
}
