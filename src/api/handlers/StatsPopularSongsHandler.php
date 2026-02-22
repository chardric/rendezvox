<?php

declare(strict_types=1);

class StatsPopularSongsHandler
{
    public function handle(): void
    {
        $db    = Database::get();
        $limit = min(50, max(1, (int) ($_GET['limit'] ?? 20)));

        $stmt = $db->prepare('
            SELECT
                s.id, s.title, a.name AS artist, c.name AS category,
                COUNT(ph.id) AS play_count,
                MAX(ph.started_at) AS last_played
            FROM play_history ph
            JOIN songs      s ON s.id = ph.song_id
            JOIN artists    a ON a.id = s.artist_id
            JOIN categories c ON c.id = s.category_id
            GROUP BY s.id, s.title, a.name, c.name
            ORDER BY play_count DESC
            LIMIT :limit
        ');
        $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        $songs = [];
        while ($row = $stmt->fetch()) {
            $songs[] = [
                'id'          => (int) $row['id'],
                'title'       => $row['title'],
                'artist'      => $row['artist'],
                'category'    => $row['category'],
                'play_count'  => (int) $row['play_count'],
                'last_played' => $row['last_played'],
            ];
        }

        Response::json(['songs' => $songs]);
    }
}
