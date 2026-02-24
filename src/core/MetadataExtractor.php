<?php

declare(strict_types=1);

/**
 * Extracts audio metadata via ffprobe with filename-based fallback.
 */
class MetadataExtractor
{
    /** @var array<string, string> Cached ffprobe output keyed by file path */
    private static array $cache = [];

    /**
     * Pre-fetch ffprobe metadata for multiple files in parallel.
     * Results are cached so subsequent extract() calls are instant.
     *
     * @param string[] $filePaths  Absolute paths to audio files
     * @param int      $maxWorkers Number of concurrent ffprobe processes
     */
    public static function prefetch(array $filePaths, int $maxWorkers = 2): void
    {
        // Filter out already-cached paths
        $filePaths = array_values(array_filter($filePaths, fn($p) => !isset(self::$cache[$p])));
        if (empty($filePaths)) return;

        $workers = [];
        $fileIdx = 0;
        $total   = count($filePaths);

        while ($fileIdx < $total || count($workers) > 0) {
            // Fill worker slots
            while (count($workers) < $maxWorkers && $fileIdx < $total) {
                $path = $filePaths[$fileIdx++];
                $cmd  = sprintf(
                    'ffprobe -v quiet -print_format json -show_format %s 2>/dev/null',
                    escapeshellarg($path)
                );
                $proc = proc_open($cmd, [1 => ['pipe', 'w']], $pipes);
                if (!is_resource($proc)) continue;
                stream_set_blocking($pipes[1], false);
                $workers[] = ['proc' => $proc, 'pipes' => $pipes, 'path' => $path, 'output' => ''];
            }

            // Poll workers for completion
            foreach ($workers as $idx => &$w) {
                $chunk = @stream_get_contents($w['pipes'][1]);
                if ($chunk !== false) $w['output'] .= $chunk;

                $status = proc_get_status($w['proc']);
                if (!$status['running']) {
                    $chunk = @stream_get_contents($w['pipes'][1]);
                    if ($chunk !== false) $w['output'] .= $chunk;
                    @fclose($w['pipes'][1]);
                    proc_close($w['proc']);

                    self::$cache[$w['path']] = $w['output'];
                    unset($workers[$idx]);
                }
            }
            unset($w);
            $workers = array_values($workers);

            if (count($workers) > 0) usleep(50000); // 50ms
        }
    }

    /**
     * Return number of safe parallel workers based on CPU cores and load.
     */
    public static function safeWorkerCount(): int
    {
        $cores = (int) @shell_exec('nproc') ?: 2;
        $max   = max(1, (int) floor($cores / 2));
        $load  = sys_getloadavg();
        if ($load && $load[1] > $cores * 0.6) {
            $max = 1;
        }
        return $max;
    }

    /**
     * @return array{title: string, artist: string, genre: string, year: int, duration_ms: int}
     */
    public static function extract(string $filePath): array
    {
        // Use cached ffprobe output if available (from prefetch)
        if (isset(self::$cache[$filePath])) {
            $output = self::$cache[$filePath];
            unset(self::$cache[$filePath]);
        } else {
            $cmd = sprintf(
                'ffprobe -v quiet -print_format json -show_format %s 2>/dev/null',
                escapeshellarg($filePath)
            );
            $output = shell_exec($cmd);
        }

        $data = $output ? json_decode($output, true) : null;

        $title    = '';
        $artist   = '';
        $genre    = '';
        $year     = 0;
        $duration = 0;

        if ($data) {
            // Duration from format
            $duration = (int) round(((float) ($data['format']['duration'] ?? 0)) * 1000);

            // Tags (case-insensitive)
            $tags = self::normalizeTags($data['format']['tags'] ?? []);
            $title  = $tags['title'] ?? '';
            $artist = $tags['artist'] ?? $tags['album_artist'] ?? '';
            $genre  = $tags['genre'] ?? '';
            $year   = self::extractYear($tags);
        }

        // Filename fallback for title/artist
        if ($title === '' || $artist === '') {
            $parsed = self::parseFilename($filePath);
            if ($title === '')  $title  = $parsed['title'];
            if ($artist === '') $artist = $parsed['artist'];
        }

        return [
            'title'       => $title,
            'artist'      => $artist,
            'genre'       => $genre,
            'year'        => $year,
            'duration_ms' => $duration,
        ];
    }

    /**
     * Extract a 4-digit year from common tag fields (date, year, release_date, original_date).
     * Returns 0 if not found or invalid.
     */
    private static function extractYear(array $tags): int
    {
        foreach (['date', 'year', 'release_date', 'original_date', 'tdrc', 'tdrl'] as $key) {
            $val = $tags[$key] ?? '';
            if ($val !== '' && preg_match('/\b(19|20)\d{2}\b/', $val, $m)) {
                return (int) $m[0];
            }
        }
        return 0;
    }

    /**
     * Parse "Artist - Title.ext" from filename, stripping leading track numbers.
     *
     * @return array{title: string, artist: string}
     */
    private static function parseFilename(string $filePath): array
    {
        $basename = pathinfo($filePath, PATHINFO_FILENAME);

        // Strip leading track number patterns: "01 - ", "01. ", "01 "
        $basename = preg_replace('/^\d{1,3}[\s.\-]+/', '', $basename);

        // Try "Artist - Title" split
        if (str_contains($basename, ' - ')) {
            $parts = explode(' - ', $basename, 2);
            return [
                'artist' => trim($parts[0]),
                'title'  => trim($parts[1]),
            ];
        }

        // No separator — use filename as title, empty artist
        return [
            'artist' => '',
            'title'  => trim($basename),
        ];
    }

    /**
     * Lowercase all tag keys for case-insensitive access.
     */
    private static function normalizeTags(array $tags): array
    {
        $out = [];
        foreach ($tags as $k => $v) {
            $out[strtolower($k)] = trim((string) $v);
        }
        return $out;
    }
}
