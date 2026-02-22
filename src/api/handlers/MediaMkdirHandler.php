<?php

declare(strict_types=1);

class MediaMkdirHandler
{
    public function handle(): void
    {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $path = trim($body['path'] ?? '');

        if ($path === '') {
            Response::error('path is required', 400);
            return;
        }

        $abs = MediaBrowseHandler::safePath($path);
        if ($abs === null) {
            Response::error('Invalid path', 400);
            return;
        }

        if (is_dir($abs)) {
            Response::error('Folder already exists', 409);
            return;
        }

        if (!mkdir($abs, 0775, true)) {
            Response::error('Failed to create folder', 500);
            return;
        }

        Response::json(['message' => 'Folder created', 'path' => $path]);
    }
}
