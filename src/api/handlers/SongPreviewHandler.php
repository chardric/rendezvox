<?php

declare(strict_types=1);

class SongPreviewHandler
{
    private const MUSIC_DIR = '/var/lib/rendezvox/music';

    private const MIME_TYPES = [
        'mp3'  => 'audio/mpeg',
        'ogg'  => 'audio/ogg',
        'wav'  => 'audio/wav',
        'flac' => 'audio/flac',
        'aac'  => 'audio/aac',
        'm4a'  => 'audio/mp4',
        'wma'  => 'audio/x-ms-wma',
        'opus' => 'audio/opus',
        'aiff' => 'audio/aiff',
    ];

    public function handle(): void
    {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            Response::error('Invalid song ID', 400);
            return;
        }

        $db   = Database::get();
        $stmt = $db->prepare('SELECT file_path FROM songs WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $filePath = $stmt->fetchColumn();

        if ($filePath === false) {
            Response::error('Song not found', 404);
            return;
        }

        $absPath  = self::MUSIC_DIR . '/' . $filePath;
        $realPath = realpath($absPath);

        if ($realPath === false || !str_starts_with($realPath, self::MUSIC_DIR . '/')) {
            Response::error('File not found', 404);
            return;
        }

        $ext         = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));
        $contentType = self::MIME_TYPES[$ext] ?? 'application/octet-stream';
        $fileSize    = (int) filesize($realPath);

        // Support Range requests for seeking
        $start = 0;
        $end   = $fileSize - 1;

        if (isset($_SERVER['HTTP_RANGE'])) {
            if (preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
                $start = (int) $m[1];
                if ($m[2] !== '') {
                    $end = (int) $m[2];
                }
                if ($start > $end || $start >= $fileSize) {
                    http_response_code(416);
                    header('Content-Range: bytes */' . $fileSize);
                    exit;
                }
                http_response_code(206);
                header('Content-Range: bytes ' . $start . '-' . $end . '/' . $fileSize);
            }
        }

        $length = $end - $start + 1;

        header('Content-Type: ' . $contentType);
        header('Content-Length: ' . $length);
        header('Accept-Ranges: bytes');
        header('Cache-Control: max-age=3600');

        $fp = fopen($realPath, 'rb');
        if ($fp === false) {
            Response::error('Cannot read file', 500);
            return;
        }
        if ($start > 0) {
            fseek($fp, $start);
        }
        $remaining = $length;
        while ($remaining > 0 && !feof($fp)) {
            $chunk = fread($fp, min(8192, $remaining));
            if ($chunk === false) break;
            echo $chunk;
            $remaining -= strlen($chunk);
        }
        fclose($fp);
        exit;
    }
}
