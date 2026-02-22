<?php

declare(strict_types=1);

class SongDetailHandler
{
    public function handle(): void
    {
        $db = Database::get();
        $id = (int) ($_GET['id'] ?? 0);

        if ($id <= 0) {
            Response::error('Invalid song ID', 400);
            return;
        }

        $stmt = $db->prepare('
            SELECT
                s.*, a.name AS artist_name, c.name AS category_name
            FROM songs s
            JOIN artists    a ON a.id = s.artist_id
            JOIN categories c ON c.id = s.category_id
            WHERE s.id = :id
        ');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if (!$row) {
            Response::error('Song not found', 404);
            return;
        }

        Response::json([
            'song' => [
                'id'              => (int) $row['id'],
                'title'           => $row['title'],
                'artist_id'       => (int) $row['artist_id'],
                'artist_name'     => $row['artist_name'],
                'category_id'     => (int) $row['category_id'],
                'category_name'   => $row['category_name'],
                'year'            => $row['year'] ? (int) $row['year'] : null,
                'file_path'       => $row['file_path'],
                'file_hash'       => $row['file_hash'],
                'duration_ms'     => (int) $row['duration_ms'],
                'rotation_weight' => (float) $row['rotation_weight'],
                'play_count'      => (int) $row['play_count'],
                'last_played_at'  => $row['last_played_at'],
                'is_active'       => (bool) $row['is_active'],
                'is_requestable'  => (bool) $row['is_requestable'],
                'created_at'      => $row['created_at'],
                'updated_at'      => $row['updated_at'],
            ],
        ]);
    }
}
