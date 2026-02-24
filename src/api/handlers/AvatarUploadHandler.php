<?php

declare(strict_types=1);

class AvatarUploadHandler
{
    public function handle(): void
    {
        $auth = Auth::requireAuth();
        $userId = (int) $auth['sub'];

        if (empty($_FILES['file'])) {
            Response::error('No file uploaded', 400);
            return;
        }

        $file = $_FILES['file'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            Response::error('File upload failed (code: ' . $file['error'] . ')', 400);
            return;
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (!in_array($ext, $allowed, true)) {
            Response::error('Unsupported image format. Allowed: ' . implode(', ', $allowed), 400);
            return;
        }

        // Validate MIME type matches an actual image
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($mime, $allowedMimes, true)) {
            Response::error('Invalid image file', 400);
            return;
        }

        // Validate it's a real image (prevents disguised files)
        if (!getimagesize($file['tmp_name'])) {
            Response::error('Invalid image file', 400);
            return;
        }

        // Disk space guard
        $err = DiskSpace::requireSpace((int) $file['size']);
        if ($err !== null) {
            Response::error($err, 507);
            return;
        }

        $dir = '/var/lib/iradio/avatars';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        // Delete old avatar if extension changed
        $db = Database::get();
        $stmt = $db->prepare('SELECT avatar_path FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $oldPath = $stmt->fetchColumn();

        if ($oldPath) {
            $oldFile = $dir . '/' . $oldPath;
            if (file_exists($oldFile)) {
                $oldExt = pathinfo($oldPath, PATHINFO_EXTENSION);
                if ($oldExt !== $ext) {
                    unlink($oldFile);
                }
            }
        }

        $avatarFilename = $userId . '.' . $ext;
        $destPath = $dir . '/' . $avatarFilename;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            Response::error('Failed to save avatar', 500);
            return;
        }

        $stmt = $db->prepare('UPDATE users SET avatar_path = :path WHERE id = :id');
        $stmt->execute(['path' => $avatarFilename, 'id' => $userId]);

        Response::json(['avatar_path' => $avatarFilename]);
    }
}
