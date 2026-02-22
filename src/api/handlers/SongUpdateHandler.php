<?php

declare(strict_types=1);

class SongUpdateHandler
{
    public function handle(): void
    {
        $db   = Database::get();
        $id   = (int) ($_GET['id'] ?? 0);
        $body = json_decode(file_get_contents('php://input'), true);

        if ($id <= 0) {
            Response::error('Invalid song ID', 400);
            return;
        }
        if (!$body) {
            Response::error('Invalid JSON body', 400);
            return;
        }

        // Verify song exists
        $stmt = $db->prepare('SELECT id FROM songs WHERE id = :id');
        $stmt->execute(['id' => $id]);
        if (!$stmt->fetch()) {
            Response::error('Song not found', 404);
            return;
        }

        // Build dynamic SET clause
        $allowed = ['title', 'artist_id', 'category_id', 'year', 'rotation_weight',
                     'is_active', 'is_requestable'];
        $sets   = [];
        $params = ['id' => $id];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $body)) {
                $sets[]         = "{$field} = :{$field}";
                $params[$field] = $body[$field];
            }
        }

        if (empty($sets)) {
            Response::error('No valid fields to update', 400);
            return;
        }

        $sql = 'UPDATE songs SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        Response::json(['message' => 'Song updated']);
    }
}
