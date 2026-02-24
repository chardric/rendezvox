<?php

declare(strict_types=1);

class LogoUploadHandler
{
    private const LOGO_DIR = '/var/lib/iradio/logos';

    public function handle(): void
    {
        Auth::requireRole('super_admin');

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
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
        if (!in_array($ext, $allowed, true)) {
            Response::error('Unsupported image format. Allowed: ' . implode(', ', $allowed), 400);
            return;
        }

        // Validate MIME type
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);
        $allowedMimes = [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml',
        ];
        if (!in_array($mime, $allowedMimes, true)) {
            Response::error('Invalid image file', 400);
            return;
        }

        // For raster images, validate with getimagesize
        if ($mime !== 'image/svg+xml') {
            if (!getimagesize($file['tmp_name'])) {
                Response::error('Invalid image file', 400);
                return;
            }
        } else {
            // Basic SVG sanitization — reject files with script or event handlers
            $svg = file_get_contents($file['tmp_name']);
            if ($svg === false) {
                Response::error('Failed to read uploaded file', 400);
                return;
            }
            if (preg_match('/<script|on\w+\s*=/i', $svg)) {
                Response::error('SVG contains disallowed content', 400);
                return;
            }
        }

        // Disk space guard
        $err = DiskSpace::requireSpace((int) $file['size']);
        if ($err !== null) {
            Response::error($err, 507);
            return;
        }

        if (!is_dir(self::LOGO_DIR)) {
            mkdir(self::LOGO_DIR, 0775, true);
        }

        // Delete old logo if extension changed
        $db   = Database::get();
        $stmt = $db->prepare("SELECT value FROM settings WHERE key = 'station_logo_path'");
        $stmt->execute();
        $oldPath = $stmt->fetchColumn();

        if ($oldPath) {
            $oldFile = self::LOGO_DIR . '/' . $oldPath;
            if (file_exists($oldFile)) {
                $oldExt = pathinfo($oldPath, PATHINFO_EXTENSION);
                if ($oldExt !== $ext) {
                    unlink($oldFile);
                }
            }
        }

        $filename = 'station_logo.' . $ext;
        $destPath = self::LOGO_DIR . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            Response::error('Failed to save logo', 500);
            return;
        }

        // Upsert setting
        $db->prepare("
            INSERT INTO settings (key, value, type, description)
            VALUES ('station_logo_path', :val, 'string', 'Station logo filename')
            ON CONFLICT (key) DO UPDATE SET value = :val, updated_at = NOW()
        ")->execute(['val' => $filename]);

        Response::json(['logo_path' => $filename]);
    }

    public function delete(): void
    {
        Auth::requireRole('super_admin');

        $db   = Database::get();
        $stmt = $db->prepare("SELECT value FROM settings WHERE key = 'station_logo_path'");
        $stmt->execute();
        $logoPath = $stmt->fetchColumn();

        if ($logoPath) {
            $fullPath = self::LOGO_DIR . '/' . basename($logoPath);
            if (file_exists($fullPath)) {
                unlink($fullPath);
            }

            $db->prepare("UPDATE settings SET value = '', updated_at = NOW() WHERE key = 'station_logo_path'")
               ->execute();
        }

        Response::json(['deleted' => true]);
    }
}
