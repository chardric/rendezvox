<?php

declare(strict_types=1);

/**
 * GET /api/cover/{song_id}
 *
 * Extracts and serves embedded cover art from an audio file.
 * Caches extracted images on disk to avoid repeated ffmpeg calls.
 * Public endpoint (no auth) — cover art is not sensitive.
 */
class CoverArtHandler
{
    private const CACHE_DIR = '/tmp/rendezvox_covers';
    private const MAX_AGE   = 86400; // 24h browser cache

    public function handle(): void
    {
        $songId = (int) ($_GET['id'] ?? 0);
        if ($songId <= 0) {
            http_response_code(400);
            echo 'Bad request';
            return;
        }

        // Ensure cache directory exists
        if (!is_dir(self::CACHE_DIR)) {
            @mkdir(self::CACHE_DIR, 0755, true);
        }

        // Check disk cache first
        $cachePath = self::CACHE_DIR . '/' . $songId . '.jpg';
        if (file_exists($cachePath) && filesize($cachePath) > 0) {
            $this->serve($cachePath);
            return;
        }

        // Look up file path from DB
        $db = Database::get();
        $stmt = $db->prepare('
            SELECT s.file_path, s.has_cover_art
            FROM songs s
            WHERE s.id = :id
        ');
        $stmt->execute(['id' => $songId]);
        $row = $stmt->fetch();

        if (!$row) {
            http_response_code(404);
            echo 'Song not found';
            return;
        }

        // If no embedded cover art, serve app logo
        if (!$row['has_cover_art']) {
            $this->serveFallbackLogo($cachePath);
            return;
        }

        // Resolve absolute path
        $musicRoot = '/var/lib/rendezvox/music';
        $filePath  = $musicRoot . '/' . ltrim($row['file_path'], '/');

        // Path traversal prevention
        $real = realpath($filePath);
        if (!$real || strpos($real, $musicRoot) !== 0 || !file_exists($real)) {
            $this->serveFallbackLogo($cachePath);
            return;
        }

        // Extract cover art via ffmpeg
        $cmd = 'ffmpeg -y -i ' . escapeshellarg($real)
             . ' -an -vcodec mjpeg -frames:v 1 -q:v 2 '
             . escapeshellarg($cachePath) . ' 2>/dev/null';
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0 || !file_exists($cachePath) || filesize($cachePath) === 0) {
            @unlink($cachePath);
            $this->serveFallbackLogo($cachePath);
            return;
        }

        $this->serve($cachePath);
    }

    /**
     * Serve app logo as fallback, caching a copy in the cover cache dir.
     */
    private function serveFallbackLogo(string $cachePath): void
    {
        $logoPath = '/var/www/html/public/assets/icon-512x512.png';
        if (file_exists($logoPath)) {
            @copy($logoPath, $cachePath);
            $this->serve($cachePath);
        } else {
            http_response_code(404);
            echo 'No cover art';
        }
    }

    private function serve(string $path): void
    {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($path) ?: 'image/jpeg';

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: public, max-age=' . self::MAX_AGE);
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }
}
