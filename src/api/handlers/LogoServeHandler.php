<?php

declare(strict_types=1);

class LogoServeHandler
{
    private const LOGO_DIR = '/var/lib/iradio/logos';

    public function handle(): void
    {
        $db   = Database::get();
        $stmt = $db->prepare("SELECT value FROM settings WHERE key = 'station_logo_path'");
        $stmt->execute();
        $logoPath = $stmt->fetchColumn();

        if (!$logoPath) {
            Response::error('No logo configured', 404);
            return;
        }

        // Block path traversal
        if ($logoPath !== basename($logoPath) || str_contains($logoPath, '..') || str_contains($logoPath, "\0")) {
            Response::error('Invalid logo path', 400);
            return;
        }

        $filePath = self::LOGO_DIR . '/' . $logoPath;

        // Verify resolved path stays within logos directory
        $realPath = realpath($filePath);
        if ($realPath === false || !str_starts_with($realPath, self::LOGO_DIR . '/')) {
            Response::error('Logo file not found', 404);
            return;
        }

        $ext = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));
        $mimeTypes = [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'svg'  => 'image/svg+xml',
        ];

        $contentType = $mimeTypes[$ext] ?? 'application/octet-stream';

        header('Content-Type: ' . $contentType);
        header('Content-Length: ' . filesize($realPath));
        header('Cache-Control: max-age=3600');
        readfile($realPath);
        exit;
    }
}
