<?php

declare(strict_types=1);

class MediaPendingCountHandler
{
    private const UPLOAD_DIR = '/var/lib/iradio/music/upload';
    private const EXTENSIONS = ['mp3', 'flac', 'ogg', 'wav', 'aac', 'm4a'];

    public function handle(): void
    {
        $count = 0;

        if (is_dir(self::UPLOAD_DIR)) {
            $iter = new RecursiveIteratorIterator(
                new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator(self::UPLOAD_DIR, RecursiveDirectoryIterator::SKIP_DOTS),
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
