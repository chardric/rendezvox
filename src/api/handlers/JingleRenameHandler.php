<?php

declare(strict_types=1);

class JingleRenameHandler
{
    public function handle(): void
    {
        $oldFilename = $_GET['filename'] ?? '';

        // Validate old filename
        if (
            $oldFilename === '' ||
            $oldFilename !== basename($oldFilename) ||
            str_contains($oldFilename, '..') ||
            str_contains($oldFilename, "\0")
        ) {
            Response::error('Invalid filename', 400);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $newFilename = trim($input['filename'] ?? '');

        if ($newFilename === '') {
            Response::error('New filename is required', 400);
            return;
        }

        // Validate new filename characters
        if (
            $newFilename !== basename($newFilename) ||
            str_contains($newFilename, '..') ||
            str_contains($newFilename, "\0") ||
            str_contains($newFilename, '/') ||
            str_contains($newFilename, '\\')
        ) {
            Response::error('Invalid new filename', 400);
            return;
        }

        // Validate extension on new filename
        $allowed = ['mp3', 'ogg', 'wav', 'flac', 'aac', 'm4a'];
        $newExt = strtolower(pathinfo($newFilename, PATHINFO_EXTENSION));
        if (!in_array($newExt, $allowed, true)) {
            Response::error('Unsupported audio format. Allowed: ' . implode(', ', $allowed), 400);
            return;
        }

        $dir = '/var/lib/rendezvox/jingles';
        $oldPath = $dir . '/' . $oldFilename;

        // Verify old file exists and is within jingles dir
        $oldReal = realpath($oldPath);
        if ($oldReal === false || !str_starts_with($oldReal, $dir . '/')) {
            Response::error('Jingle not found', 404);
            return;
        }

        // Sanitize new filename (same rules as upload handler)
        $newBase = pathinfo($newFilename, PATHINFO_FILENAME);
        $sanitized = preg_replace('/[^a-zA-Z0-9._-]/', '_', $newBase);
        if ($sanitized === '' || $sanitized === '.') {
            Response::error('Invalid new filename', 400);
            return;
        }
        $newFilename = $sanitized . '.' . $newExt;
        $newPath = $dir . '/' . $newFilename;

        // No-op if name unchanged
        if ($oldReal === realpath($newPath) || $oldFilename === $newFilename) {
            Response::json(['filename' => $newFilename, 'message' => 'Filename unchanged']);
            return;
        }

        // Prevent overwriting existing files
        if (file_exists($newPath)) {
            Response::error('A jingle with that name already exists', 409);
            return;
        }

        if (!rename($oldReal, $newPath)) {
            Response::error('Failed to rename jingle', 500);
            return;
        }

        Response::json(['filename' => $newFilename, 'message' => 'Jingle renamed']);
    }
}
