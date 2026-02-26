<?php

declare(strict_types=1);

class StationIdDeleteHandler
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

        $dir = '/var/lib/rendezvox/stationids';
        $path = $dir . '/' . $filename;

        $realPath = realpath($path);
        if ($realPath === false || !str_starts_with($realPath, $dir . '/')) {
            Response::error('Station ID not found', 404);
            return;
        }

        if (!unlink($realPath)) {
            Response::error('Failed to delete station ID', 500);
            return;
        }

        Response::json(['message' => 'Station ID deleted']);
    }
}
