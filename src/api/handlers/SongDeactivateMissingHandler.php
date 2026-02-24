<?php

declare(strict_types=1);

class SongDeactivateMissingHandler
{
    public function handle(): void
    {
        $body = json_decode(file_get_contents('php://input'), true);
        $ids  = $body['ids'] ?? [];

        if (!is_array($ids) || count($ids) === 0) {
            Response::error('No song IDs provided', 400);
            return;
        }

        // Sanitize IDs to integers
        $ids = array_map('intval', $ids);
        $ids = array_filter($ids, fn($id) => $id > 0);

        if (count($ids) === 0) {
            Response::error('No valid song IDs', 400);
            return;
        }

        $db = Database::get();

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("UPDATE songs SET is_active = false WHERE id IN ({$placeholders}) AND is_active = true");
        $stmt->execute(array_values($ids));

        Response::json(['count' => $stmt->rowCount()]);
    }
}
