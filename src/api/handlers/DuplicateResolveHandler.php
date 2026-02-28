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
        $stmt = $db->prepare("SELECT id, file_path FROM songs WHERE id IN ({$placeholders})");
        foreach ($allIds as $i => $id) {
            $stmt->bindValue($i + 1, $id, PDO::PARAM_INT);
        }
        $stmt->execute();

        $songMap = [];
        while ($row = $stmt->fetch()) {
            $songMap[(int) $row['id']] = $row['file_path'];
        }

        foreach ($deleteSet as $did) {
            if (!isset($songMap[$did])) {
                Response::error('Song ID ' . $did . ' not found', 404);
                return;
            }
        }

        foreach (array_keys($keepSet) as $kid) {
            if (!isset($songMap[$kid])) {
                Response::error('Keep song ID ' . $kid . ' not found', 404);
                return;
            }
        }

        // Mark duplicates — match each delete_id to its keep_id by file_hash
        $marked = 0;
        $errors = [];

        $hashStmt  = $db->prepare('SELECT file_hash FROM songs WHERE id = ?');
        $markStmt  = $db->prepare('UPDATE songs SET duplicate_of = ? WHERE id = ?');

        // Build hash → keep_id map from keepSet
        $hashToKeep = [];
        foreach (array_keys($keepSet) as $kid) {
            $hashStmt->execute([$kid]);
            $kHash = $hashStmt->fetchColumn();
            if ($kHash) {
                $hashToKeep[$kHash] = $kid;
            }
        }

        foreach ($deleteSet as $did) {
            try {
                $hashStmt->execute([$did]);
                $dHash = $hashStmt->fetchColumn();

                // Find the canonical keep_id: prefer same-hash match, fallback to first keep_id
                $keepId = ($dHash && isset($hashToKeep[$dHash]))
                    ? $hashToKeep[$dHash]
                    : (int) array_key_first($keepSet);

                $markStmt->execute([$keepId, $did]);
                $marked++;
            } catch (\PDOException $e) {
                error_log('DuplicateResolve DB error marking song ' . $did . ': ' . $e->getMessage());
                $errors[] = 'DB error marking song ' . $did;
            }
        }

        Response::json([
            'deleted'     => $marked,
            'marked'      => $marked,
            'freed_bytes' => 0,
            'errors'      => $errors,
        ]);
    }
}
