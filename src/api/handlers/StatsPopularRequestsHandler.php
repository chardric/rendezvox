<?php

declare(strict_types=1);

class StatsPopularRequestsHandler
{
    public function handle(): void
    {
        $db    = Database::get();
        $limit = min(50, max(1, (int) ($_GET['limit'] ?? 20)));

        $stmt = $db->prepare('
            SELECT
                s.id, s.title, a.name AS artist,
                COUNT(sr.id) AS request_count,
                COUNT(*) FILTER (WHERE sr.status = \'played\') AS played_count
            FROM song_requests sr
            JOIN songs   s ON s.id = sr.song_id
            JOIN artists a ON a.id = s.artist_id
            GROUP BY s.id, s.title, a.name
            ORDER BY request_count DESC
            LIMIT :limit
        ');
        $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        $songs = [];
        while ($row = $stmt->fetch()) {
            $songs[] = [
                'id'            => (int) $row['id'],
                'title'         => $row['title'],
                'artist'        => $row['artist'],
                'request_count' => (int) $row['request_count'],
                'played_count'  => (int) $row['played_count'],
            ];
        }

        Response::json(['songs' => $songs]);
    }
}
