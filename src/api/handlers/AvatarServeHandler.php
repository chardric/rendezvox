<?php

declare(strict_types=1);

class AvatarServeHandler
{
    public function handle(): void
    {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            Response::error('Invalid user ID', 400);
            return;
        }

        $db = Database::get();
        $stmt = $db->prepare('SELECT avatar_path FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $avatarPath = $stmt->fetchColumn();

        if (!$avatarPath) {
            Response::error('No avatar found', 404);
            return;
        }

        // Block path traversal attacks
        if ($avatarPath !== basename($avatarPath) || str_contains($avatarPath, '..') || str_contains($avatarPath, "\0")) {
            Response::error('Invalid avatar path', 400);
            return;
        }

        $filePath = '/var/lib/rendezvox/avatars/' . $avatarPath;

        // Verify resolved path stays within avatars directory
        $realPath = realpath($filePath);
        if ($realPath === false || !str_starts_with($realPath, '/var/lib/rendezvox/avatars/')) {
            Response::error('Avatar file not found', 404);
            return;
        }
        $filePath = $realPath;

        $ext = strtolower(pathinfo($avatarPath, PATHINFO_EXTENSION));
        $mimeTypes = [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
        ];

        $contentType = $mimeTypes[$ext] ?? 'application/octet-stream';

        header('Content-Type: ' . $contentType);
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: max-age=3600');
        readfile($filePath);
        exit;
    }
}
