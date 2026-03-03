<?php

declare(strict_types=1);

class TtsPreviewHandler
{
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

        header('Content-Type: audio/mpeg');
        header('Content-Length: ' . strlen($mp3));
        header('Cache-Control: no-store');
        echo $mp3;
        exit;
    }
}
