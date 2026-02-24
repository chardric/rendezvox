<?php

declare(strict_types=1);

class JingleListHandler
{
    public function handle(): void
    {
        $dir = '/var/lib/iradio/jingles';
        $allowed = ['mp3', 'ogg', 'wav', 'flac', 'aac', 'm4a'];
        $jingles = [];

        if (is_dir($dir)) {
            $files = scandir($dir);
            foreach ($files as $file) {
                if ($file === '.' || $file === '..') continue;

                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed)) continue;

                $path = $dir . '/' . $file;
                $jingles[] = [
                    'filename'    => $file,
                    'size'        => filesize($path),
                    'duration_ms' => self::getDurationMs($path),
                    'created_at'  => date('c', filectime($path)),
                ];
            }
        }

        // Sort by filename
        usort($jingles, fn($a, $b) => strcasecmp($a['filename'], $b['filename']));

        Response::json(['jingles' => $jingles]);
    }

    private static function getDurationMs(string $path): ?int
    {
        $cmd = 'ffprobe -v quiet -show_entries format=duration -of csv=p=0 ' . escapeshellarg($path) . ' 2>/dev/null';
        $output = trim((string) shell_exec($cmd));
        if ($output === '' || !is_numeric($output)) {
            return null;
        }
        return (int) round((float) $output * 1000);
    }
}
