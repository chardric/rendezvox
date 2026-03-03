<?php

declare(strict_types=1);

class DuplicateResolveHandler
{
    public function handle(): void
    {
        $db = Database::get();

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            Response::error('Invalid JSON body', 400);
            return;
        }

        $keepIds   = $input['keep_ids']   ?? [];
        $deleteIds = $input['delete_ids'] ?? [];

        if (!is_array($keepIds) || !is_array($deleteIds)) {
            Response::error('keep_ids and delete_ids must be arrays', 400);
            return;
        }

        if (empty($deleteIds)) {
            Response::error('delete_ids must not be empty', 400);
            return;
        }

        if (empty($keepIds)) {
            Response::error('keep_ids must not be empty — at least one copy of each song must be kept', 400);
            return;
        }

        // Ensure no overlap between keep and delete
        $keepSet   = array_flip(array_map('intval', $keepIds));
        $deleteSet = array_map('intval', $deleteIds);

        foreach ($deleteSet as $did) {
            if (isset($keepSet[$did])) {
                Response::error('Song ID ' . $did . ' appears in both keep_ids and delete_ids', 400);
                return;
            }
        }

        // Verify all keep_ids and delete_ids exist
        $allIds = array_merge(array_keys($keepSet), $deleteSet);
        $placeholders = implode(',', array_fill(0, count($allIds), '?'));
        $stmt = $db->prepare("SELECT id FROM songs WHERE id IN ({$placeholders})");
        foreach ($allIds as $i => $id) {
            $stmt->bindValue($i + 1, $id, PDO::PARAM_INT);
        }
        $stmt->execute();

        $foundIds = [];
        while ($row = $stmt->fetch()) {
            $foundIds[(int) $row['id']] = true;
        }

        foreach ($deleteSet as $did) {
            if (!isset($foundIds[$did])) {
                Response::error('Song ID ' . $did . ' not found', 404);
                return;
            }
        }

        foreach (array_keys($keepSet) as $kid) {
            if (!isset($foundIds[$kid])) {
                Response::error('Keep song ID ' . $kid . ' not found', 404);
                return;
            }
        }

        // Delete duplicate songs from DB (files are kept on disk)
        // FK constraints use CASCADE/SET NULL so this is safe
        $deleted = 0;
        $errors = [];
        $delStmt = $db->prepare('DELETE FROM songs WHERE id = ?');

        foreach ($deleteSet as $did) {
            try {
                $delStmt->execute([$did]);
                $deleted++;
            } catch (\PDOException $e) {
                error_log('DuplicateResolve DB error deleting song ' . $did . ': ' . $e->getMessage());
                $errors[] = 'DB error deleting song ' . $did;
            }
        }

        Response::json([
            'deleted' => $deleted,
            'errors'  => $errors,
        ]);
    }
}
