<?php

declare(strict_types=1);

/**
 * POST /admin/schedules/bulk
 *
 * Accepts { clear_existing: bool, schedules: [...] }
 * Deletes all existing schedules (if flagged) and bulk-inserts new ones
 * in a single transaction. Bypasses per-request rate limits.
 */
class ScheduleBulkHandler
{
    public function handle(): void
    {
        $db   = Database::get();
        $user = Auth::requireAuth();
        $body = json_decode(file_get_contents('php://input'), true);

        $clearExisting = !empty($body['clear_existing']);
        $items         = $body['schedules'] ?? [];

        if (!is_array($items)) {
            Response::error('schedules array is required', 400);
            return;
        }

        if (empty($items) && !$clearExisting) {
            Response::error('schedules array is empty', 400);
            return;
        }

        if (count($items) > 200) {
            Response::error('Maximum 200 schedules per bulk request', 400);
            return;
        }

        // Validate all items up front before touching the DB
        $validated = [];
        foreach ($items as $i => $item) {
            $playlistId = (int) ($item['playlist_id'] ?? 0);
            $startTime  = $item['start_time'] ?? '';
            $endTime    = $item['end_time'] ?? '';
            $daysOfWeek = $item['days_of_week'] ?? null;
            $priority   = (int) ($item['priority'] ?? 0);

            if ($playlistId <= 0 || $startTime === '' || $endTime === '') {
                Response::error("Item $i: playlist_id, start_time, and end_time are required", 400);
                return;
            }

            // Validate days_of_week
            $pgDays = null;
            if ($daysOfWeek !== null && $daysOfWeek !== '') {
                if (!is_array($daysOfWeek) || empty($daysOfWeek)) {
                    Response::error("Item $i: days_of_week must be an array of 0-6 or null", 400);
                    return;
                }
                $days = array_unique(array_map('intval', $daysOfWeek));
                foreach ($days as $d) {
                    if ($d < 0 || $d > 6) {
                        Response::error("Item $i: each day must be 0-6", 400);
                        return;
                    }
                }
                sort($days);
                $pgDays = '{' . implode(',', $days) . '}';
            }

            $validated[] = [
                'playlist_id' => $playlistId,
                'start_time'  => $startTime,
                'end_time'    => $endTime,
                'days_of_week'=> $pgDays,
                'priority'    => $priority,
            ];
        }

        // Look up playlist names for auto-naming
        $plNames = [];
        $playlistIds = array_unique(array_column($validated, 'playlist_id'));
        if (!empty($playlistIds)) {
            $placeholders = implode(',', array_fill(0, count($playlistIds), '?'));
            $nameStmt = $db->prepare("SELECT id, name FROM playlists WHERE id IN ($placeholders)");
            $nameStmt->execute(array_values($playlistIds));
            while ($row = $nameStmt->fetch()) {
                $plNames[(int) $row['id']] = $row['name'];
            }

            // Check all playlist IDs exist
            foreach ($playlistIds as $pid) {
                if (!isset($plNames[$pid])) {
                    Response::error("Playlist $pid not found", 404);
                    return;
                }
            }
        }

        // All validated — execute in a transaction
        $db->beginTransaction();
        try {
            if ($clearExisting) {
                $clearSpecial = !empty($body['clear_special']);
                if ($clearSpecial) {
                    $db->exec('DELETE FROM schedules WHERE priority = 99');
                } else {
                    $db->exec('DELETE FROM schedules WHERE priority < 99');
                }
            }

            $stmt = $db->prepare('
                INSERT INTO schedules (name, playlist_id, days_of_week, start_time, end_time, priority, is_active, created_by)
                VALUES (:name, :playlist_id, :days_of_week, :start_time, :end_time, :priority, true, :user_id)
            ');

            $created = 0;
            foreach ($validated as $v) {
                $stmt->execute([
                    'name'         => $plNames[$v['playlist_id']],
                    'playlist_id'  => $v['playlist_id'],
                    'days_of_week' => $v['days_of_week'],
                    'start_time'   => $v['start_time'],
                    'end_time'     => $v['end_time'],
                    'priority'     => $v['priority'],
                    'user_id'      => $user['sub'],
                ]);
                $created++;
            }

            $db->commit();
            Response::json(['created' => $created, 'message' => "$created schedules created"], 201);
        } catch (\Throwable $e) {
            $db->rollBack();
            Response::error('Bulk create failed: ' . $e->getMessage(), 500);
        }
    }
}
