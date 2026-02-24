<?php

declare(strict_types=1);

/**
 * Weighted random rotation engine for radio-grade playlist scheduling.
 *
 * Shuffles once per cycle using weighted Fisher-Yates, then enforces
 * artist/category separation constraints. The shuffled order is persisted
 * to playlist_songs.position so playback is resume-safe across restarts.
 */
class RotationEngine
{
    /**
     * Weighted Fisher-Yates shuffle.
     *
     * Produces a permutation where songs with higher effective weight
     * (song.rotation_weight * category.rotation_weight) tend to appear
     * earlier, but every song appears exactly once per cycle.
     *
     * @param array $songs Each element must have 'song_id', 'artist_id',
     *                     'category_id', and 'effective_weight' keys.
     * @return array Shuffled songs in new order.
     */
    public static function weightedShuffle(array $songs): array
    {
        $len = count($songs);
        if ($len <= 1) {
            return $songs;
        }

        for ($i = 0; $i < $len - 1; $i++) {
            // Build cumulative weight array for candidates [i..len-1]
            $cumulative = [];
            $total = 0.0;
            for ($j = $i; $j < $len; $j++) {
                $total += max(0.01, (float) $songs[$j]['effective_weight']);
                $cumulative[$j] = $total;
            }

            // Pick a random candidate proportional to weight
            $rand = mt_rand() / mt_getrandmax() * $total;
            $pick = $i;
            for ($j = $i; $j < $len; $j++) {
                if ($cumulative[$j] >= $rand) {
                    $pick = $j;
                    break;
                }
            }

            // Swap picked candidate into position i
            if ($pick !== $i) {
                [$songs[$i], $songs[$pick]] = [$songs[$pick], $songs[$i]];
            }
        }

        return $songs;
    }

    /**
     * Reorder to enforce minimum gap between songs by the same artist.
     *
     * Scans the order and when a collision is found, swaps the offending
     * song with the nearest non-colliding song further in the list.
     *
     * @param array $songs  Ordered array with 'artist_id' key.
     * @param int   $minGap Minimum positions between same artist (default 6).
     * @return array Reordered songs.
     */
    public static function enforceArtistSeparation(array $songs, int $minGap = 6): array
    {
        $len = count($songs);
        if ($len <= 1) {
            return $songs;
        }

        // Cap gap to avoid impossible constraints in small playlists
        $minGap = min($minGap, intdiv($len, 2));

        for ($i = 1; $i < $len; $i++) {
            if (!self::hasArtistCollision($songs, $i, $minGap)) {
                continue;
            }

            // Find nearest j > i that can be swapped without creating new collision
            $bestJ = null;
            for ($j = $i + 1; $j < $len; $j++) {
                if (!self::hasArtistCollision($songs, $j, $minGap, $i)) {
                    // Check that placing songs[j] at position i wouldn't collide
                    if (!self::wouldCollideAtPosition($songs, $j, $i, $minGap, 'artist_id')) {
                        $bestJ = $j;
                        break;
                    }
                }
            }

            if ($bestJ !== null) {
                [$songs[$i], $songs[$bestJ]] = [$songs[$bestJ], $songs[$i]];
            }
            // If no swap candidate found, leave as-is (best effort)
        }

        return $songs;
    }

    /**
     * Reorder to enforce minimum gap between songs of the same category.
     *
     * @param array $songs  Ordered array with 'category_id' key.
     * @param int   $minGap Minimum positions between same category (default 1 = no back-to-back).
     * @return array Reordered songs.
     */
    public static function enforceCategorySeparation(array $songs, int $minGap = 1): array
    {
        $len = count($songs);
        if ($len <= 1) {
            return $songs;
        }

        $minGap = min($minGap, intdiv($len, 2));

        for ($i = 1; $i < $len; $i++) {
            if (!self::hasCategoryCollision($songs, $i, $minGap)) {
                continue;
            }

            $bestJ = null;
            for ($j = $i + 1; $j < $len; $j++) {
                if (!self::wouldCollideAtPosition($songs, $j, $i, $minGap, 'category_id')) {
                    $bestJ = $j;
                    break;
                }
            }

            if ($bestJ !== null) {
                [$songs[$i], $songs[$bestJ]] = [$songs[$bestJ], $songs[$i]];
            }
        }

        return $songs;
    }

    /**
     * Reorder to enforce minimum gap between songs with the same base title.
     *
     * Strips rendition suffixes (parenthesized, bracketed, dash-separated,
     * and bare keywords like "Remix", "Acoustic", "Live") so that
     * "25 Minutes (Soul Version)", "25 Minutes - Remix", and
     * "25 Minutes Acoustic" all share the base title "25 Minutes"
     * and are kept apart.
     *
     * @param array $songs  Ordered array with 'title' key.
     * @param int   $minGap Minimum positions between same base title (default 2).
     * @return array Reordered songs.
     */
    public static function enforceTitleSeparation(array $songs, int $minGap = 2): array
    {
        $len = count($songs);
        if ($len <= 1) {
            return $songs;
        }

        $minGap = min($minGap, intdiv($len, 2));

        // Pre-compute base titles
        $baseTitles = [];
        foreach ($songs as $idx => $s) {
            $baseTitles[$idx] = self::baseTitle($s['title'] ?? '');
        }

        for ($i = 1; $i < $len; $i++) {
            if (!self::hasTitleCollision($baseTitles, $i, $minGap)) {
                continue;
            }

            $bestJ = null;
            for ($j = $i + 1; $j < $len; $j++) {
                if (!self::wouldTitleCollideAtPosition($baseTitles, $j, $i, $minGap)) {
                    $bestJ = $j;
                    break;
                }
            }

            if ($bestJ !== null) {
                [$songs[$i], $songs[$bestJ]] = [$songs[$bestJ], $songs[$i]];
                [$baseTitles[$i], $baseTitles[$bestJ]] = [$baseTitles[$bestJ], $baseTitles[$i]];
            }
        }

        return $songs;
    }

    /**
     * Orchestrator: shuffle ALL songs + enforce constraints + write positions to DB.
     *
     * Called at the start of each new cycle (after all songs have been played
     * and played_in_cycle is reset). Uses negative-position trick to avoid
     * UNIQUE constraint violations.
     */
    public static function generateCycleOrder(PDO $db, int $playlistId): void
    {
        self::shuffleAndWrite($db, $playlistId, false);
    }

    /**
     * Shuffle only the remaining unplayed songs in the cycle.
     *
     * Already-played songs keep their positions and played_in_cycle = true.
     * Unplayed songs are reshuffled into positions after the last played song.
     *
     * @return int Number of unplayed songs that were reshuffled.
     */
    public static function shuffleRemaining(PDO $db, int $playlistId): int
    {
        return self::shuffleAndWrite($db, $playlistId, true);
    }

    /**
     * Core shuffle logic shared by generateCycleOrder and shuffleRemaining.
     *
     * @param bool $remainingOnly If true, only shuffle unplayed songs.
     * @return int Number of songs shuffled.
     */
    private static function shuffleAndWrite(PDO $db, int $playlistId, bool $remainingOnly): int
    {
        // Fetch unplayed active songs in this playlist with their weights
        $playedFilter = $remainingOnly ? 'AND ps.played_in_cycle = false' : '';

        $stmt = $db->prepare("
            SELECT
                ps.song_id,
                s.artist_id,
                s.category_id,
                s.title,
                (s.rotation_weight * c.rotation_weight) AS effective_weight
            FROM playlist_songs ps
            JOIN songs      s ON s.id = ps.song_id
            JOIN categories c ON c.id = s.category_id
            WHERE ps.playlist_id = :playlist_id
              AND s.is_active = true
              {$playedFilter}
        ");
        $stmt->execute(['playlist_id' => $playlistId]);
        $songs = $stmt->fetchAll();

        if (empty($songs)) {
            return 0;
        }

        // Read artist separation setting
        $artistGap = self::getSettingInt($db, 'artist_repeat_block', 6);

        // 1. Weighted shuffle
        $songs = self::weightedShuffle($songs);

        // 2. Enforce artist separation
        $songs = self::enforceArtistSeparation($songs, $artistGap);

        // 3. Enforce category separation (no back-to-back)
        $songs = self::enforceCategorySeparation($songs, 1);

        // 4. Enforce title separation (prevent "Song (Version A)" next to "Song (Version B)")
        $songs = self::enforceTitleSeparation($songs, 2);

        // 5. Determine starting position
        $startPos = 1;
        if ($remainingOnly) {
            // Place reshuffled songs after the highest position of played songs
            $maxStmt = $db->prepare('
                SELECT COALESCE(MAX(position), 0) FROM playlist_songs
                WHERE playlist_id = :id AND played_in_cycle = true
            ');
            $maxStmt->execute(['id' => $playlistId]);
            $startPos = (int) $maxStmt->fetchColumn() + 1;
        }

        // 6. Write shuffled positions to DB
        // Step 1: Negate positions of affected songs to avoid UNIQUE violations
        if ($remainingOnly) {
            $db->prepare("
                UPDATE playlist_songs SET position = -(position + 1000000)
                WHERE playlist_id = :id AND played_in_cycle = false
            ")->execute(['id' => $playlistId]);
        } else {
            $db->prepare('
                UPDATE playlist_songs SET position = -(position + 1000000)
                WHERE playlist_id = :id
            ')->execute(['id' => $playlistId]);
        }

        // Step 2: Write new positions
        $update = $db->prepare('
            UPDATE playlist_songs
            SET position = :pos
            WHERE playlist_id = :playlist_id AND song_id = :song_id
        ');

        foreach ($songs as $idx => $song) {
            $update->execute([
                'pos'         => $startPos + $idx,
                'playlist_id' => $playlistId,
                'song_id'     => $song['song_id'],
            ]);
        }

        return count($songs);
    }

    /**
     * Check if a specific artist is blocked by the repeat rule.
     *
     * Looks at the last N plays in play_history and returns true if the
     * given artist appears in that window.
     *
     * @param PDO $db
     * @param int $artistId  The artist to check.
     * @param int $blockSize Number of recent plays to check (from settings).
     * @return bool True if the artist is blocked (played too recently).
     */
    public static function isArtistBlocked(PDO $db, int $artistId, int $blockSize): bool
    {
        if ($blockSize <= 0) {
            return false;
        }

        $stmt = $db->prepare('
            SELECT COUNT(*) AS cnt
            FROM (
                SELECT s.artist_id
                FROM play_history ph
                JOIN songs s ON s.id = ph.song_id
                ORDER BY ph.started_at DESC
                LIMIT :block_size
            ) recent
            WHERE recent.artist_id = :artist_id
        ');
        $stmt->bindValue('block_size', $blockSize, PDO::PARAM_INT);
        $stmt->bindValue('artist_id', $artistId, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Check if a song's base title was played too recently.
     *
     * Looks at the last N plays in play_history and returns true if any
     * song sharing the same base title (ignoring parenthetical variants)
     * appears in that window.
     *
     * @param PDO    $db
     * @param string $title     The title of the candidate song.
     * @param int    $songId    The candidate song's ID (excluded from match).
     * @param int    $blockSize Number of recent plays to check (default 2).
     * @return bool True if a same-base-title song was played recently.
     */
    public static function isTitleBlocked(PDO $db, string $title, int $songId, int $blockSize = 2): bool
    {
        if ($blockSize <= 0) {
            return false;
        }

        $baseTitle = self::baseTitle($title);
        if ($baseTitle === '') {
            return false;
        }

        $stmt = $db->prepare('
            SELECT s.title
            FROM (
                SELECT song_id
                FROM play_history
                ORDER BY started_at DESC
                LIMIT :block_size
            ) recent
            JOIN songs s ON s.id = recent.song_id
            WHERE recent.song_id != :song_id
        ');
        $stmt->bindValue('block_size', $blockSize, PDO::PARAM_INT);
        $stmt->bindValue('song_id', $songId, PDO::PARAM_INT);
        $stmt->execute();

        while ($row = $stmt->fetch()) {
            if (self::baseTitle($row['title']) === $baseTitle) {
                return true;
            }
        }

        return false;
    }

    // ── Private helpers ──────────────────────────────────────────

    private static function hasArtistCollision(array $songs, int $i, int $minGap, ?int $skipPos = null): bool
    {
        $artistId = (int) $songs[$i]['artist_id'];
        $start = max(0, $i - $minGap);
        for ($k = $start; $k < $i; $k++) {
            if ($skipPos !== null && $k === $skipPos) {
                continue;
            }
            if ((int) $songs[$k]['artist_id'] === $artistId) {
                return true;
            }
        }
        return false;
    }

    private static function hasCategoryCollision(array $songs, int $i, int $minGap): bool
    {
        $categoryId = (int) $songs[$i]['category_id'];
        $start = max(0, $i - $minGap);
        for ($k = $start; $k < $i; $k++) {
            if ((int) $songs[$k]['category_id'] === $categoryId) {
                return true;
            }
        }
        return false;
    }

    /**
     * Would placing songs[$fromIdx] at position $toIdx cause a collision?
     */
    private static function wouldCollideAtPosition(
        array $songs,
        int $fromIdx,
        int $toIdx,
        int $minGap,
        string $field
    ): bool {
        $value = (int) $songs[$fromIdx][$field];
        $start = max(0, $toIdx - $minGap);
        $end   = min(count($songs) - 1, $toIdx + $minGap);

        for ($k = $start; $k <= $end; $k++) {
            if ($k === $toIdx || $k === $fromIdx) {
                continue;
            }
            if ((int) $songs[$k][$field] === $value) {
                return true;
            }
        }
        return false;
    }

    private static function getSettingInt(PDO $db, string $key, int $default): int
    {
        $stmt = $db->prepare('SELECT value FROM settings WHERE key = :key');
        $stmt->execute(['key' => $key]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (int) $val : $default;
    }

    /**
     * Extract base title by stripping rendition/variant suffixes.
     *
     * Handles multiple formats:
     *   "25 Minutes (Soul Version)"         → "25 minutes"
     *   "Hello [Remix]"                     → "hello"
     *   "Song (Feat. X) (Remix)"            → "song"
     *   "Bohemian Rhapsody - Acoustic"      → "bohemian rhapsody"
     *   "Bohemian Rhapsody Remix"           → "bohemian rhapsody"
     *   "My Song - Live Version"            → "my song"
     *   "Track feat. Artist"                → "track"
     *   "Track ft. Artist"                  → "track"
     */
    private static function baseTitle(string $title): string
    {
        // 1. Remove anything in parentheses or brackets (repeat for nested)
        $base = preg_replace('/\s*[\(\[][^\)\]]*[\)\]]\s*/', ' ', $title);

        // 2. Remove dash-separated suffixes containing rendition keywords
        $base = preg_replace(
            '/\s+[-–—]\s+(?:(?:.*\b(?:remix|acoustic|live|radio|club|extended|instrumental|'
            . 'unplugged|remaster(?:ed)?|version|ver\.|mix|edit|dub|demo|karaoke|stripped|'
            . 'deluxe|bonus|original|alternate|alt\.|cover|reprise|interlude|orchestral|'
            . 'symphony|piano|guitar|vocal).*))$/i',
            '',
            $base
        );

        // 3. Remove trailing bare rendition keywords (no dash needed)
        $base = preg_replace(
            '/\s+(?:remix|acoustic|live|radio\s+edit|club\s+mix|extended\s+(?:mix|version)|'
            . 'instrumental|unplugged|remaster(?:ed)?|karaoke|stripped|orchestral)$/i',
            '',
            $base
        );

        // 4. Remove trailing "feat./ft." and everything after
        $base = preg_replace('/\s+(?:feat\.?|ft\.?)\s+.+$/i', '', $base);

        return mb_strtolower(trim($base));
    }

    private static function hasTitleCollision(array $baseTitles, int $i, int $minGap): bool
    {
        $title = $baseTitles[$i];
        if ($title === '') {
            return false;
        }
        $start = max(0, $i - $minGap);
        for ($k = $start; $k < $i; $k++) {
            if ($baseTitles[$k] === $title) {
                return true;
            }
        }
        return false;
    }

    private static function wouldTitleCollideAtPosition(array $baseTitles, int $fromIdx, int $toIdx, int $minGap): bool
    {
        $title = $baseTitles[$fromIdx];
        if ($title === '') {
            return false;
        }
        $start = max(0, $toIdx - $minGap);
        $end   = min(count($baseTitles) - 1, $toIdx + $minGap);
        for ($k = $start; $k <= $end; $k++) {
            if ($k === $toIdx || $k === $fromIdx) {
                continue;
            }
            if ($baseTitles[$k] === $title) {
                return true;
            }
        }
        return false;
    }
}
