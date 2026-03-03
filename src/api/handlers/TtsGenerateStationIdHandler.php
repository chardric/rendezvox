<?php

declare(strict_types=1);

class TtsGenerateStationIdHandler
{
    private const STATION_ID_DIR = '/var/lib/rendezvox/stationids';

    public function handle(): void
    {
        Auth::requireAuth();

        $body  = json_decode(file_get_contents('php://input'), true) ?? [];
        $text  = trim((string) ($body['text'] ?? ''));
        $voice = (string) ($body['voice'] ?? 'male');
        $speed = (int) ($body['speed'] ?? 160);

        if ($text === '') {
            Response::error('Text is required', 400);
            return;
        }
        if (mb_strlen($text) > 500) {
            Response::error('Text too long (max 500 characters)', 400);
            return;
        }

        require_once __DIR__ . '/../../core/TtsEngine.php';

        $mp3 = TtsEngine::preview($text, $voice, $speed);
        if ($mp3 === null) {
            Response::error('TTS generation failed', 500);
            return;
        }

        // Auto-increment filename: station-1.mp3, station-2.mp3, ...
        $filename = $this->nextStationFilename();
        $destPath = self::STATION_ID_DIR . '/' . $filename;

        $err = DiskSpace::requireSpace(strlen($mp3));
        if ($err !== null) {
            Response::error($err, 507);
            return;
        }

        if (!is_dir(self::STATION_ID_DIR)) {
            mkdir(self::STATION_ID_DIR, 0775, true);
        }

        if (file_put_contents($destPath, $mp3) === false) {
            Response::error('Failed to save station ID', 500);
            return;
        }

        Response::json([
            'filename' => $filename,
            'message'  => 'Station ID generated from TTS',
        ], 201);
    }

    /**
     * Find the next available station-N.mp3 filename.
     */
    private function nextStationFilename(): string
    {
        $max = 0;
        $files = glob(self::STATION_ID_DIR . '/station-*.mp3');
        if ($files) {
            foreach ($files as $f) {
                $base = pathinfo($f, PATHINFO_FILENAME);
                if (preg_match('/^station-(\d+)$/', $base, $m)) {
                    $max = max($max, (int) $m[1]);
                }
            }
        }
        return 'station-' . ($max + 1) . '.mp3';
    }
}
