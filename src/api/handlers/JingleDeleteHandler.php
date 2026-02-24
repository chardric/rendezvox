<?php

declare(strict_types=1);

class JingleDeleteHandler
{
    public function handle(): void
    {
        $filename = $_GET['filename'] ?? '';

        // Prevent path traversal
        if (
            $filename === '' ||
            $filename !== basename($filename) ||
            str_contains($filename, '..') ||
            str_contains($filename, "\0") ||
            str_contains($filename, '/') ||
            str_contains($filename, '\\')
        ) {
            Response::error('Invalid filename', 400);
            return;
        }

        $dir = '/var/lib/iradio/jingles';
        $path = $dir . '/' . $filename;

        $realPath = realpath($path);
        if ($realPath === false || !str_starts_with($realPath, $dir . '/')) {
            Response::error('Jingle not found', 404);
            return;
        }

        if (!unlink($realPath)) {
            Response::error('Failed to delete jingle', 500);
            return;
        }

        Response::json(['message' => 'Jingle deleted']);
    }
}
