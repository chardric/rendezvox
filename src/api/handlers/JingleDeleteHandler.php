<?php

declare(strict_types=1);

class JingleDeleteHandler
{
    public function handle(string $filename): void
    {
        // Prevent path traversal
        if (strpos($filename, '..') !== false || strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
            Response::error('Invalid filename', 400);
            return;
        }

        $path = '/var/lib/iradio/jingles/' . $filename;

        if (!file_exists($path)) {
            Response::error('Jingle not found', 404);
            return;
        }

        if (!unlink($path)) {
            Response::error('Failed to delete jingle', 500);
            return;
        }

        Response::json(['message' => 'Jingle deleted']);
    }
}
