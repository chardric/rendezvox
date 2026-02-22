<?php

declare(strict_types=1);

class JingleUploadHandler
{
    public function handle(): void
    {
        if (empty($_FILES['file'])) {
            Response::error('No file uploaded', 400);
            return;
        }

        $file = $_FILES['file'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            Response::error('File upload failed (code: ' . $file['error'] . ')', 400);
            return;
        }

        // Validate file extension
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['mp3', 'ogg', 'wav', 'flac', 'aac', 'm4a'];
        if (!in_array($ext, $allowed, true)) {
            Response::error('Unsupported audio format. Allowed: ' . implode(', ', $allowed), 400);
            return;
        }

        // Validate MIME type
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        $allowedMimes = [
            'audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/x-wav',
            'audio/flac', 'audio/x-flac', 'audio/aac', 'audio/mp4',
            'audio/x-m4a', 'application/octet-stream',
        ];
        if (!in_array($mime, $allowedMimes, true)) {
            Response::error('Invalid audio file', 400);
            return;
        }

        $dir = '/var/lib/iradio/jingles';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        // Sanitize filename
        $basename = pathinfo($file['name'], PATHINFO_FILENAME);
        $sanitized = preg_replace('/[^a-zA-Z0-9._-]/', '_', $basename);
        $filename = $sanitized . '.' . $ext;

        // Avoid overwriting existing files
        $destPath = $dir . '/' . $filename;
        if (file_exists($destPath)) {
            $filename = $sanitized . '_' . time() . '.' . $ext;
            $destPath = $dir . '/' . $filename;
        }

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            Response::error('Failed to save uploaded file', 500);
            return;
        }

        Response::json([
            'filename' => $filename,
            'message'  => 'Jingle uploaded successfully',
        ], 201);
    }
}
