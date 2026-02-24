<?php

declare(strict_types=1);

class JingleStreamHandler
{
    public function handle(): void
    {
        $filename = $_GET['filename'] ?? '';

        // Validate filename — no path traversal
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
        $filePath = $dir . '/' . $filename;

        // Verify resolved path stays within jingles directory
        $realPath = realpath($filePath);
        if ($realPath === false || !str_starts_with($realPath, $dir . '/')) {
            Response::error('Jingle not found', 404);
            return;
        }
        $filePath = $realPath;

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mimeTypes = [
            'mp3'  => 'audio/mpeg',
            'ogg'  => 'audio/ogg',
            'wav'  => 'audio/wav',
            'flac' => 'audio/flac',
            'aac'  => 'audio/aac',
            'm4a'  => 'audio/mp4',
        ];

        $contentType = $mimeTypes[$ext] ?? 'application/octet-stream';

        header('Content-Type: ' . $contentType);
        header('Content-Length: ' . filesize($filePath));
        header('Accept-Ranges: bytes');
        header('Cache-Control: max-age=3600');
        readfile($filePath);
        exit;
    }
}
