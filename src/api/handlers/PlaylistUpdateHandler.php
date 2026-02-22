<?php

declare(strict_types=1);

class PlaylistUpdateHandler
{
    public function handle(): void
    {
        $db   = Database::get();
        $id   = (int) ($_GET['id'] ?? 0);
        $body = json_decode(file_get_contents('php://input'), true);

        if ($id <= 0 || !$body) {
            Response::error('Invalid request', 400);
            return;
        }

        $stmt = $db->prepare('SELECT id, type FROM playlists WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $playlist = $stmt->fetch();
        if (!$playlist) {
            Response::error('Playlist not found', 404);
            return;
        }

        // Prevent changing type to/from emergency
        if (array_key_exists('type', $body)) {
            if ($body['type'] === 'emergency' && $playlist['type'] !== 'emergency') {
                Response::error('Cannot change playlist type to emergency — use the dedicated emergency playlist', 400);
                return;
            }
            if ($playlist['type'] === 'emergency' && $body['type'] !== 'emergency') {
                Response::error('Cannot change the emergency playlist type — delete and recreate instead', 400);
                return;
            }
        }

        $allowed = ['name', 'description', 'is_active', 'color'];
        $sets   = [];
        $params = ['id' => $id];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $body)) {
                $val = $body[$field];
                if ($field === 'is_active') {
                    $val = $val ? 'true' : 'false';
                }
                $sets[]         = "{$field} = :{$field}";
                $params[$field] = $val;
            }
        }

        // rules is JSONB — must be handled separately (needs encoding / explicit NULL)
        if (array_key_exists('rules', $body)) {
            $rulesVal = $body['rules'];
            if ($rulesVal === null) {
                $sets[] = 'rules = NULL';
            } else {
                $sets[]          = 'rules = :rules';
                $params['rules'] = json_encode([
                    'categories' => array_map('intval', $rulesVal['categories'] ?? []),
                    'artists'    => array_map('intval', $rulesVal['artists'] ?? []),
                    'years'      => array_map('intval', $rulesVal['years'] ?? []),
                    'min_weight' => (float) ($rulesVal['min_weight'] ?? 0.0),
                ]);
            }
        }

        if (empty($sets)) {
            Response::error('No valid fields to update', 400);
            return;
        }

        $sql = 'UPDATE playlists SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $db->prepare($sql)->execute($params);

        Response::json(['message' => 'Playlist updated']);
    }
}
