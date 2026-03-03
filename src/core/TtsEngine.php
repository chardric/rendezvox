<?php

declare(strict_types=1);

class TtsEngine
{
    private const TTS_DIR      = '/var/lib/rendezvox/tts';
    private const VOICES_DIR   = '/usr/local/share/piper-voices';
    private const SAMPLE_RATE  = 44100;
    private const PIPER_RATE   = 22050;
    private const BITRATE      = '128k';

    private const VOICE_MODELS = [
        'male'   => 'en_US-lessac-medium.onnx',
        'female' => 'en_US-amy-medium.onnx',
    ];

    /**
     * Generate a TTS audio file with silence padding for crossfade compatibility.
     * Returns absolute path to the cached MP3, or null on failure.
     */
    public static function generate(
        string $text,
        string $voice = 'male',
        int    $speed = 160,
        float  $padStart = 1.5,
        float  $padEnd = 1.5
    ): ?string {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        $speed = max(80, min(300, $speed));
        $model = self::resolveModel($voice);

        $hash   = md5($text . '|' . $voice . '|' . $speed . '|' . $padStart . '|' . $padEnd);
        $subdir = self::TTS_DIR . '/announce';
        $path   = $subdir . '/' . $hash . '.mp3';

        if (file_exists($path)) {
            touch($path);
            return $path;
        }

        if (!is_dir($subdir)) {
            mkdir($subdir, 0775, true);
        }

        $lengthScale = sprintf('%.2f', 160.0 / $speed);
        $padStartMs  = (int) ($padStart * 1000);
        $padEndStr   = sprintf('%.1f', $padEnd);

        $cmd = sprintf(
            'echo %s | piper --model %s --length-scale %s --output-raw 2>/dev/null | ' .
            'ffmpeg -y -f s16le -ar %d -ac 1 -i - ' .
            '-af "adelay=%d|%d,apad=pad_dur=%s" ' .
            '-t 30 -ac 2 -ar %d -b:a %s %s 2>/dev/null',
            escapeshellarg($text),
            escapeshellarg($model),
            $lengthScale,
            self::PIPER_RATE,
            $padStartMs,
            $padStartMs,
            escapeshellarg($padEndStr),
            self::SAMPLE_RATE,
            self::BITRATE,
            escapeshellarg($path)
        );

        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0 || !file_exists($path)) {
            @unlink($path);
            return null;
        }

        return $path;
    }

    /**
     * Generate TTS for browser preview (no silence padding).
     * Returns raw MP3 bytes, or null on failure.
     */
    public static function preview(
        string $text,
        string $voice = 'male',
        int    $speed = 160
    ): ?string {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        $speed = max(80, min(300, $speed));
        $model = self::resolveModel($voice);
        $lengthScale = sprintf('%.2f', 160.0 / $speed);

        $cmd = sprintf(
            'echo %s | piper --model %s --length-scale %s --output-raw 2>/dev/null | ' .
            'ffmpeg -y -f s16le -ar %d -ac 1 -i - ' .
            '-ac 2 -ar %d -b:a %s -f mp3 pipe:1 2>/dev/null',
            escapeshellarg($text),
            escapeshellarg($model),
            $lengthScale,
            self::PIPER_RATE,
            self::SAMPLE_RATE,
            self::BITRATE
        );

        $mp3 = shell_exec($cmd);

        return ($mp3 !== null && strlen($mp3) > 0) ? $mp3 : null;
    }

    private static function resolveModel(string $voice): string
    {
        $file = self::VOICE_MODELS[$voice] ?? self::VOICE_MODELS['male'];
        return self::VOICES_DIR . '/' . $file;
    }
}
