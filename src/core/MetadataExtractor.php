<?php

declare(strict_types=1);

/**
 * Extracts audio metadata via ffprobe with filename-based fallback.
 */
class MetadataExtractor
{
    /**
     * @return array{title: string, artist: string, genre: string, year: int, duration_ms: int}
     */
    public static function extract(string $filePath): array
    {
        // Run ffprobe
        $cmd = sprintf(
            'ffprobe -v quiet -print_format json -show_format %s 2>/dev/null',
            escapeshellarg($filePath)
        );
        $output = shell_exec($cmd);
        $data   = $output ? json_decode($output, true) : null;

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
