<?php

declare(strict_types=1);

class DuplicateScanHandler
{
    private const BASE_DIR = '/var/lib/iradio/music';

    private function resolveFilePath(string $filePath): string
    {
        if ($filePath !== '' && $filePath[0] !== '/') {
            return self::BASE_DIR . '/' . $filePath;
        }
        return $filePath;
    }

    public function handle(): void
    {
        $db = Database::get();
        $groups = [];

        // ── Tier 1: Exact duplicates (same file_hash) ──
        $stmt = $db->query("
            SELECT s.*, a.name AS artist_name
            FROM songs s
            JOIN artists a ON a.id = s.artist_id
            WHERE s.file_hash IN (
                SELECT file_hash FROM songs
                WHERE file_hash IS NOT NULL AND file_hash != ''
                GROUP BY file_hash HAVING COUNT(*) > 1
            )
            ORDER BY s.file_hash, s.play_count DESC
        ");

        $exactRows = $stmt->fetchAll();
        $byHash = [];
        foreach ($exactRows as $row) {
            $byHash[$row['file_hash']][] = $row;
        }

        $exactSongIds = [];
        foreach ($byHash as $hash => $rows) {
            $songs = [];
            $bestId = null;
            $bestPlays = -1;
            $bestSize = -1;

            foreach ($rows as $row) {
                $absPath = $this->resolveFilePath($row['file_path'] ?? '');
                $fileSize = 0;
                $fileExists = ($absPath !== '' && file_exists($absPath));
                if ($fileExists) {
                    $fileSize = (int) filesize($absPath);
                }

                $song = $this->formatSong($row, $fileSize, $fileExists);
                $songs[] = $song;
                $exactSongIds[(int) $row['id']] = true;

                // Never recommend keeping a zero-byte or missing file
                if ($fileSize === 0) {
                    continue;
                }

                $plays = (int) $row['play_count'];
                if ($plays > $bestPlays || ($plays === $bestPlays && $fileSize > $bestSize)) {
                    $bestPlays = $plays;
                    $bestSize = $fileSize;
                    $bestId = (int) $row['id'];
                }
            }

            $groups[] = [
                'type' => 'exact',
                'songs' => $songs,
                'recommended_keep_id' => $bestId,
            ];
        }

        // ── Tier 2: Likely duplicates (same title+artist, similar duration) ──
        $stmt = $db->query("
            SELECT s1.id AS id1, s2.id AS id2
            FROM songs s1
            JOIN songs s2
              ON s1.id < s2.id
              AND s1.artist_id = s2.artist_id
              AND LOWER(s1.title) = LOWER(s2.title)
              AND ABS(s1.duration_ms - s2.duration_ms) <= 5000
            WHERE (s1.file_hash IS NULL OR s2.file_hash IS NULL OR s1.file_hash != s2.file_hash)
        ");

        $pairs = $stmt->fetchAll();
        if (!empty($pairs)) {
            // Collect all unique song IDs from likely pairs, excluding those already in exact groups
            $likelyIds = [];
            $adjacency = [];
            foreach ($pairs as $pair) {
                $id1 = (int) $pair['id1'];
                $id2 = (int) $pair['id2'];
                if (isset($exactSongIds[$id1]) && isset($exactSongIds[$id2])) {
                    continue;
                }
                $likelyIds[$id1] = true;
                $likelyIds[$id2] = true;
                $adjacency[$id1][] = $id2;
                $adjacency[$id2][] = $id1;
            }

            if (!empty($likelyIds)) {
                // Group connected pairs via union-find
                $likelyGroups = $this->groupConnected(array_keys($likelyIds), $adjacency);

                // Fetch song details for all likely IDs
                $placeholders = implode(',', array_keys($likelyIds));
                $stmt = $db->query("
                    SELECT s.*, a.name AS artist_name
                    FROM songs s
                    JOIN artists a ON a.id = s.artist_id
                    WHERE s.id IN ({$placeholders})
                ");
                $songMap = [];
                while ($row = $stmt->fetch()) {
                    $songMap[(int) $row['id']] = $row;
                }

                foreach ($likelyGroups as $idList) {
                    $songs = [];
                    $bestId = null;
                    $bestPlays = -1;
                    $bestSize = -1;

                    foreach ($idList as $id) {
                        if (!isset($songMap[$id])) continue;
                        $row = $songMap[$id];
                        $absPath = $this->resolveFilePath($row['file_path'] ?? '');
                        $fileSize = 0;
                        $fileExists = ($absPath !== '' && file_exists($absPath));
                        if ($fileExists) {
                            $fileSize = (int) filesize($absPath);
                        }

                        $song = $this->formatSong($row, $fileSize, $fileExists);
                        $songs[] = $song;

                        // Never recommend keeping a zero-byte or missing file
                        if ($fileSize === 0) {
                            continue;
                        }

                        $plays = (int) $row['play_count'];
                        if ($plays > $bestPlays || ($plays === $bestPlays && $fileSize > $bestSize)) {
                            $bestPlays = $plays;
                            $bestSize = $fileSize;
                            $bestId = (int) $row['id'];
                        }
                    }

                    if (count($songs) >= 2) {
                        $groups[] = [
                            'type' => 'likely',
                            'songs' => $songs,
                            'recommended_keep_id' => $bestId,
                        ];
                    }
                }
            }
        }

        $totalDuplicates = 0;
        foreach ($groups as $g) {
            $totalDuplicates += count($g['songs']) - 1;
        }

        Response::json([
            'groups' => $groups,
            'total_groups' => count($groups),
            'total_duplicates' => $totalDuplicates,
        ]);
    }

    private function formatSong(array $row, int $fileSize, bool $fileExists): array
    {
        return [
            'id'           => (int) $row['id'],
            'title'        => $row['title'],
            'artist_id'    => (int) $row['artist_id'],
            'artist_name'  => $row['artist_name'],
            'file_path'    => $row['file_path'],
            'file_hash'    => $row['file_hash'],
            'file_size'    => $fileSize,
            'file_missing' => !$fileExists,
            'duration_ms'  => (int) $row['duration_ms'],
            'play_count'   => (int) $row['play_count'],
            'is_active'    => (bool) $row['is_active'],
            'created_at'   => $row['created_at'],
        ];
    }

    private function groupConnected(array $ids, array $adjacency): array
    {
        $visited = [];
        $groups = [];

        foreach ($ids as $id) {
            if (isset($visited[$id])) continue;

            $group = [];
            $stack = [$id];
            while (!empty($stack)) {
                $current = array_pop($stack);
                if (isset($visited[$current])) continue;
                $visited[$current] = true;
                $group[] = $current;
                foreach ($adjacency[$current] ?? [] as $neighbor) {
                    if (!isset($visited[$neighbor])) {
                        $stack[] = $neighbor;
                    }
                }
            }

            $groups[] = $group;
        }

        return $groups;
    }
}
