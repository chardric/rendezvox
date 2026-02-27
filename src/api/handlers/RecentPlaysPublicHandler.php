<?php

declare(strict_types=1);

class RecentPlaysPublicHandler
{
    public function handle(): void
    {
        $db = Database::get();

        // Last 5 completed tracks — filter orphaned prefetch rows (< 30s)
        $stmt = $db->query('
            SELECT s.title, a.name AS artist, ph.ended_at
            FROM play_history ph
            JOIN songs   s ON s.id = ph.song_id
            JOIN artists a ON a.id = s.artist_id
            WHERE ph.ended_at IS NOT NULL
              AND (ph.ended_at - ph.started_at) > INTERVAL \'30 seconds\'
            ORDER BY ph.ended_at DESC
            LIMIT 5
        ');

        $plays = [];
        while ($row = $stmt->fetch()) {
            $plays[] = [
                'title'    => $row['title'],
                'artist'   => $row['artist'],
                'ended_at' => $row['ended_at'],
            ];
        }

        Response::json(['plays' => $plays]);
    }
}
