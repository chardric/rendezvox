<?php

declare(strict_types=1);

class SongToggleHandler
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
            UPDATE songs SET is_active = NOT is_active WHERE id = :id
            RETURNING is_active
        ');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if (!$row) {
            Response::error('Song not found', 404);
            return;
        }

        Response::json([
            'id'        => $id,
            'is_active' => (bool) $row['is_active'],
            'message'   => $row['is_active'] ? 'Song activated' : 'Song deactivated',
        ]);
    }
}
