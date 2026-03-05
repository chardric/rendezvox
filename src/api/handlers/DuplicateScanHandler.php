<?php

declare(strict_types=1);

class DuplicateScanHandler
{
    private const BASE_DIR = '/var/lib/rendezvox/music';

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

        // Pre-load playlist membership for all songs (song_id → playlist names)
        $plStmt = $db->query("
            SELECT ps.song_id, p.name AS playlist_name
            FROM playlist_songs ps
            JOIN playlists p ON p.id = ps.playlist_id
            ORDER BY ps.song_id
        ");
        $playlistMap = [];
        while ($plRow = $plStmt->fetch()) {
            $playlistMap[(int) $plRow['song_id']][] = $plRow['playlist_name'];
        }

        // ── Tier 1: Exact duplicates (same file_hash) ──
        $stmt = $db->query("
            SELECT s.*, a.name AS artist_name
            FROM songs s
            JOIN artists a ON a.id = s.artist_id
            WHERE s.file_hash IN (
                SELECT file_hash FROM songs
                WHERE file_hash IS NOT NULL AND file_hash != ''
                AND duplicate_of IS NULL
                GROUP BY file_hash HAVING COUNT(*) > 1
            )
            AND s.duplicate_of IS NULL
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
            $bestInPlaylist = false;
            $bestPlays = -1;
            $bestSize = -1;

            foreach ($rows as $row) {
                $absPath = $this->resolveFilePath($row['file_path'] ?? '');
                $fileSize = 0;
                $fileExists = ($absPath !== '' && file_exists($absPath));
                if ($fileExists) {
                    $fileSize = (int) filesize($absPath);
                }

                $song = $this->formatSong($row, $fileSize, $fileExists, $playlistMap);
                $songs[] = $song;
                $exactSongIds[(int) $row['id']] = true;

                // Never recommend keeping a zero-byte or missing file
                if ($fileSize === 0) {
                    continue;
                }

                $songId = (int) $row['id'];
                $inPlaylist = !empty($playlistMap[$songId]);
                $plays = (int) $row['play_count'];

                // Prefer songs in playlists, then by play count, then file size
                if ($inPlaylist && !$bestInPlaylist
                    || ($inPlaylist === $bestInPlaylist && $plays > $bestPlays)
                    || ($inPlaylist === $bestInPlaylist && $plays === $bestPlays && $fileSize > $bestSize)) {
                    $bestInPlaylist = $inPlaylist;
                    $bestPlays = $plays;
                    $bestSize = $fileSize;
                    $bestId = $songId;
                }
            }

            $groups[] = [
                'type' => 'exact',
                'songs' => $songs,
                'recommended_keep_id' => $bestId,
            ];
        }

        // ── Tier 2: Likely duplicates (same title+artist, similar duration) ──
        // Duration within 30s, OR one file is very short (<10s, likely corrupt/truncated)
        $stmt = $db->query("
            SELECT s1.id AS id1, s2.id AS id2
            FROM songs s1
            JOIN songs s2
              ON s1.id < s2.id
              AND s1.artist_id = s2.artist_id
              AND LOWER(s1.title) = LOWER(s2.title)
              AND (
                  ABS(s1.duration_ms - s2.duration_ms) <= 30000
                  OR LEAST(s1.duration_ms, s2.duration_ms) <= 10000
              )
            WHERE (s1.file_hash IS NULL OR s2.file_hash IS NULL OR s1.file_hash != s2.file_hash)
              AND s1.duplicate_of IS NULL AND s2.duplicate_of IS NULL
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
                    $bestInPlaylist = false;
                    $bestQuality = -1;
                    $bestPlays = -1;

                    foreach ($idList as $id) {
                        if (!isset($songMap[$id])) continue;
                        $row = $songMap[$id];
                        $absPath = $this->resolveFilePath($row['file_path'] ?? '');
                        $fileSize = 0;
                        $fileExists = ($absPath !== '' && file_exists($absPath));
                        if ($fileExists) {
                            $fileSize = (int) filesize($absPath);
                        }

                        $song = $this->formatSong($row, $fileSize, $fileExists, $playlistMap);
                        $songs[] = $song;

                        // Never recommend keeping a zero-byte or missing file
                        if ($fileSize === 0) {
                            continue;
                        }

                        $inPlaylist = !empty($playlistMap[$id]);
                        $quality = $this->qualityScore($absPath, $fileSize);
                        $plays = (int) $row['play_count'];

                        // Prefer songs in playlists, then quality, then plays
                        if ($inPlaylist && !$bestInPlaylist
                            || ($inPlaylist === $bestInPlaylist && $quality > $bestQuality)
                            || ($inPlaylist === $bestInPlaylist && $quality === $bestQuality && $plays > $bestPlays)) {
                            $bestInPlaylist = $inPlaylist;
                            $bestQuality = $quality;
                            $bestPlays = $plays;
                            $bestId = $id;
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

        // ── Tier 3: Fuzzy duplicates (same artist, similar title after stripping suffixes) ──
        // Catches "(radio edit)", "(remix)", "(live)", "(acoustic)", "(remastered)", etc.
        $allGroupedIds = $exactSongIds + ($likelyIds ?? []);

        $stmt = $db->query("
            SELECT s.id, s.title, s.artist_id, s.duration_ms, s.file_hash,
                   s.*, a.name AS artist_name
            FROM songs s
            JOIN artists a ON a.id = s.artist_id
            WHERE s.duplicate_of IS NULL AND s.trashed_at IS NULL
            ORDER BY s.artist_id, s.title
        ");
        $allSongs = $stmt->fetchAll();

        // Group by artist_id → normalized title
        $fuzzyBuckets = [];
        foreach ($allSongs as $row) {
            $id = (int) $row['id'];
            if (isset($allGroupedIds[$id])) continue;
            $key = $row['artist_id'] . '::' . $this->normalizeTitle($row['title']);
            $fuzzyBuckets[$key][] = $row;
        }

        foreach ($fuzzyBuckets as $bucket) {
            if (count($bucket) < 2) continue;

            // Duration check: at least one pair must be within 30s or one is very short
            $hasDurationMatch = false;
            for ($i = 0; $i < count($bucket) && !$hasDurationMatch; $i++) {
                for ($j = $i + 1; $j < count($bucket); $j++) {
                    $diff = abs($bucket[$i]['duration_ms'] - $bucket[$j]['duration_ms']);
                    $shortest = min($bucket[$i]['duration_ms'], $bucket[$j]['duration_ms']);
                    if ($diff <= 30000 || $shortest <= 10000) {
                        $hasDurationMatch = true;
                        break;
                    }
                }
            }
            if (!$hasDurationMatch) continue;

            $songs = [];
            $bestId = null;
            $bestInPlaylist = false;
            $bestQuality = -1;
            $bestPlays = -1;

            foreach ($bucket as $row) {
                $absPath = $this->resolveFilePath($row['file_path'] ?? '');
                $fileSize = 0;
                $fileExists = ($absPath !== '' && file_exists($absPath));
                if ($fileExists) {
                    $fileSize = (int) filesize($absPath);
                }

                $songs[] = $this->formatSong($row, $fileSize, $fileExists, $playlistMap);

                if ($fileSize === 0) continue;

                $songId = (int) $row['id'];
                $inPlaylist = !empty($playlistMap[$songId]);
                $quality = $this->qualityScore($absPath, $fileSize);
                $plays = (int) $row['play_count'];

                if ($inPlaylist && !$bestInPlaylist
                    || ($inPlaylist === $bestInPlaylist && $quality > $bestQuality)
                    || ($inPlaylist === $bestInPlaylist && $quality === $bestQuality && $plays > $bestPlays)) {
                    $bestInPlaylist = $inPlaylist;
                    $bestQuality = $quality;
                    $bestPlays = $plays;
                    $bestId = $songId;
                }
            }

            if (count($songs) >= 2) {
                $groups[] = [
                    'type' => 'fuzzy',
                    'songs' => $songs,
                    'recommended_keep_id' => $bestId,
                ];
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

    /**
     * Strip common variant suffixes so "Song (radio edit)" matches "Song".
     */
    private function normalizeTitle(string $title): string
    {
        $t = strtolower(trim($title));
        // Remove parenthesized/bracketed suffixes like (radio edit), [remastered], etc.
        $t = preg_replace('/\s*[\(\[][^)\]]*\b(radio\s*edit|remix|remixed|remaster(ed)?|live|acoustic|instrumental|extended|short|clean|explicit|bonus|demo|alternate|alt|version|ver|mix|feat\.?|ft\.?|featuring)\b[^)\]]*[\)\]]/i', '', $t);
        // Collapse whitespace and trim
        $t = trim(preg_replace('/\s+/', ' ', $t));
        return $t;
    }

    private function qualityScore(string $absPath, int $fileSize): int
    {
        $ext = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
        $lossless = in_array($ext, ['flac', 'wav', 'aiff', 'alac']);
        return ($lossless ? 100000 : 0) + $fileSize;
    }

    private function formatSong(array $row, int $fileSize, bool $fileExists, array $playlistMap): array
    {
        $songId = (int) $row['id'];
        $playlists = $playlistMap[$songId] ?? [];
        return [
            'id'           => $songId,
            'title'        => $row['title'],
            'artist_id'    => (int) $row['artist_id'],
            'artist_name'  => $row['artist_name'],
            'file_path'    => $row['file_path'],
            'file_hash'    => $row['file_hash'],
            'file_size'    => $fileSize,
            'file_missing' => !$fileExists,
            'format'       => strtoupper(pathinfo($row['file_path'] ?? '', PATHINFO_EXTENSION)),
            'duration_ms'  => (int) $row['duration_ms'],
            'play_count'   => (int) $row['play_count'],
            'is_active'    => (bool) $row['is_active'],
            'created_at'   => $row['created_at'],
            'duplicate_of' => $row['duplicate_of'] ? (int) $row['duplicate_of'] : null,
            'in_playlist'  => !empty($playlists),
            'playlists'    => $playlists,
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
