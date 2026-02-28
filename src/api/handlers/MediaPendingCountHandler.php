<?php

declare(strict_types=1);

class MediaPendingCountHandler
{
    private const UNTAGGED_DIR = '/var/lib/rendezvox/music/untagged';
    private const EXTENSIONS = ['mp3', 'flac', 'ogg', 'wav', 'aac', 'm4a'];

    public function handle(): void
    {
        $count = 0;

        // Count audio files in both untagged/files/ and untagged/folders/
        foreach (['files', 'folders'] as $sub) {
            $dir = self::UNTAGGED_DIR . '/' . $sub;
            if (!is_dir($dir)) continue;

            $iter = new RecursiveIteratorIterator(
                new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                    function ($current) {
                        return !str_starts_with($current->getFilename(), '.');
                    }
                )
            );

            foreach ($iter as $fileInfo) {
                if (!$fileInfo->isFile()) continue;
                $ext = strtolower($fileInfo->getExtension());
                if (in_array($ext, self::EXTENSIONS, true)) {
                    $count++;
                }
            }
        }

        Response::json(['pending_count' => $count]);
    }
}
