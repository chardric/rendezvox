<?php

declare(strict_types=1);

class SongTrashHandler
{
    public function trash(): void
    {
        $body = json_decode(file_get_contents('php://input'), true);
        $ids  = $this->validateIds($body);

        if ($ids === null) {
            return;
        }

        $db = Database::get();
        $placeholders = implode(',', array_map(fn($i) => ':id' . $i, range(0, count($ids) - 1)));

        $sql = "UPDATE songs SET trashed_at = NOW(), is_active = false WHERE id IN ({$placeholders}) AND trashed_at IS NULL";
        $stmt = $db->prepare($sql);

        foreach ($ids as $i => $id) {
            $stmt->bindValue('id' . $i, $id, \PDO::PARAM_INT);
        }

        $stmt->execute();
        $affected = $stmt->rowCount();

        Response::json(['trashed' => $affected]);
    }

    public function restore(): void
    {
        $body = json_decode(file_get_contents('php://input'), true);
        $ids  = $this->validateIds($body);

        if ($ids === null) {
            return;
        }

        $db = Database::get();
        $placeholders = implode(',', array_map(fn($i) => ':id' . $i, range(0, count($ids) - 1)));

        $sql = "UPDATE songs SET trashed_at = NULL, is_active = true WHERE id IN ({$placeholders}) AND trashed_at IS NOT NULL";
        $stmt = $db->prepare($sql);

        foreach ($ids as $i => $id) {
            $stmt->bindValue('id' . $i, $id, \PDO::PARAM_INT);
        }

        $stmt->execute();
        $affected = $stmt->rowCount();

        Response::json(['restored' => $affected]);
    }

    private function validateIds(?array $body): ?array
    {
        if (!$body || empty($body['ids']) || !is_array($body['ids'])) {
            Response::error('ids array is required', 400);
            return null;
        }

        $ids = array_values(array_unique(array_filter(
            array_map('intval', $body['ids']),
            fn($id) => $id > 0
        )));

        if (empty($ids)) {
            Response::error('No valid song IDs provided', 400);
            return null;
        }

        return $ids;
    }
}
