<?php

declare(strict_types=1);

class PlaylistCreateHandler
{
    public function handle(): void
    {
        $db   = Database::get();
        $body = json_decode(file_get_contents('php://input'), true);

        $name  = trim($body['name'] ?? '');
        $type  = $body['type'] ?? 'manual';
        $desc  = trim($body['description'] ?? '');
        $color = isset($body['color']) && preg_match('/^#[0-9a-fA-F]{6}$/', $body['color']) ? $body['color'] : null;

        if ($name === '') {
            Response::error('name is required', 400);
            return;
        }

        $validTypes = ['manual', 'auto', 'emergency'];
        if (!in_array($type, $validTypes)) {
            Response::error('type must be one of: ' . implode(', ', $validTypes), 400);
            return;
        }

        // Only one emergency playlist allowed
        if ($type === 'emergency') {
            $check = $db->query("SELECT id FROM playlists WHERE type = 'emergency' LIMIT 1");
            if ($check->fetch()) {
                Response::error('An emergency playlist already exists', 409);
                return;
            }
        }

        $rules = null;
        if ($type === 'auto' && isset($body['rules']) && is_array($body['rules'])) {
            $r     = $body['rules'];
            $rules = json_encode([
                'categories' => array_map('intval', $r['categories'] ?? []),
                'artists'    => array_map('intval', $r['artists'] ?? []),
                'years'      => array_map('intval', $r['years'] ?? []),
                'min_weight' => (float) ($r['min_weight'] ?? 0.0),
            ]);
        }

        $user = Auth::requireAuth();

        $stmt = $db->prepare('
            INSERT INTO playlists (name, description, type, rules, color, created_by)
            VALUES (:name, :desc, :type, :rules, :color, :user_id)
            RETURNING id
        ');
        $stmt->execute([
            'name'    => $name,
            'desc'    => $desc ?: null,
            'type'    => $type,
            'rules'   => $rules,
            'color'   => $color,
            'user_id' => $user['sub'],
        ]);

        $id = (int) $stmt->fetchColumn();

        Response::json(['id' => $id, 'message' => 'Playlist created'], 201);
    }
}
