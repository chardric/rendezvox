<?php

declare(strict_types=1);

/**
 * Retention Scorer — computes per-song listener retention scores
 * from play_history listener count deltas.
 *
 * retention_score = average((listener_count_end - listener_count) / listener_count)
 *
 * Positive = listeners tuned in during the song.
 * Negative = listeners left during the song.
 * Null     = insufficient data.
 *
 * Songs consistently losing listeners get auto-demoted (rotation_weight reduced).
 */
class RetentionScorer
{
    /**
     * Compute retention scores for all songs with sufficient data.
     *
     * @param PDO $db
     * @param int $lookbackDays Number of days to look back for play data
     * @param int $minPlays     Minimum plays required to compute a score
     * @return array{updated: int, demoted: int}
     */
    public static function computeScores(PDO $db, int $lookbackDays = 30, int $minPlays = 5): array
    {
        // Calculate average retention per song
        $stmt = $db->prepare('
            WITH song_retention AS (
                SELECT
                    ph.song_id,
                    AVG(
                        CASE WHEN ph.listener_count > 0
                            THEN (ph.listener_count_end - ph.listener_count)::NUMERIC / ph.listener_count
                            ELSE 0
                        END
                    ) AS avg_retention,
                    COUNT(*) AS play_count
                FROM play_history ph
                WHERE ph.started_at > NOW() - make_interval(days => :days)
                  AND ph.listener_count IS NOT NULL
                  AND ph.listener_count_end IS NOT NULL
                  AND ph.listener_count > 0
                GROUP BY ph.song_id
                HAVING COUNT(*) >= :min_plays
            )
            UPDATE songs s
            SET retention_score = sr.avg_retention
            FROM song_retention sr
            WHERE s.id = sr.song_id
            RETURNING s.id
        ');
        $stmt->execute(['days' => $lookbackDays, 'min_plays' => $minPlays]);
        $updated = $stmt->rowCount();

        return ['updated' => $updated, 'demoted' => 0];
    }

    /**
     * Auto-demote songs below the retention threshold.
     *
     * Reduces rotation_weight by 25% for songs with negative retention.
     * Never goes below 0.25 (songs can still play, just less often).
     *
     * @return int Number of songs demoted
     */
    public static function autoDemote(PDO $db, float $threshold = -0.15): int
    {
        $stmt = $db->prepare('
            UPDATE songs
            SET rotation_weight = GREATEST(0.25, rotation_weight * 0.75)
            WHERE retention_score IS NOT NULL
              AND retention_score < :threshold
              AND retention_score != 0
              AND rotation_weight > 0.25
              AND is_active = true
            RETURNING id
        ');
        $stmt->execute(['threshold' => $threshold]);
        return $stmt->rowCount();
    }

    /**
     * Get retention heatmap data for the admin dashboard.
     *
     * Returns songs sorted by retention score with play counts.
     *
     * @return array List of songs with retention data
     */
    public static function getHeatmapData(PDO $db, int $limit = 100, string $sort = 'worst'): array
    {
        $orderDir = $sort === 'worst' ? 'ASC' : 'DESC';

        $stmt = $db->prepare("
            SELECT
                s.id,
                s.title,
                a.name AS artist,
                s.retention_score,
                s.rotation_weight,
                s.play_count,
                s.energy,
                s.valence,
                s.bpm
            FROM songs s
            JOIN artists a ON a.id = s.artist_id
            WHERE s.retention_score IS NOT NULL
              AND s.is_active = true
            ORDER BY s.retention_score {$orderDir}
            LIMIT :lim
        ");
        $stmt->bindValue('lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
