<?php

declare(strict_types=1);

class ScheduleCreateHandler
{
    public function handle(): void
    {
        $db   = Database::get();
        $body = json_decode(file_get_contents('php://input'), true);

        $name       = trim($body['name'] ?? '');
        $playlistId = (int) ($body['playlist_id'] ?? 0);
        $daysOfWeek = $body['days_of_week'] ?? null;
        $startDate  = isset($body['start_date']) && $body['start_date'] !== '' ? $body['start_date'] : null;
        $endDate    = isset($body['end_date']) && $body['end_date'] !== '' ? $body['end_date'] : null;
        $startTime  = $body['start_time'] ?? '';
        $endTime    = $body['end_time'] ?? '';
        $priority   = (int) ($body['priority'] ?? 0);

        if ($playlistId <= 0 || $startTime === '' || $endTime === '') {
            Response::error('playlist_id, start_time, and end_time are required', 400);
            return;
        }

        // Validate date range
        if ($startDate !== null && $endDate !== null && $startDate > $endDate) {
            Response::error('start_date must be before or equal to end_date', 400);
            return;
        }

        // Auto-generate name from playlist name when not supplied
        if ($name === '') {
            $plStmt = $db->prepare('SELECT name FROM playlists WHERE id = :id');
            $plStmt->execute(['id' => $playlistId]);
            $plRow = $plStmt->fetch();
            if (!$plRow) {
                Response::error('Playlist not found', 404);
                return;
            }
            $name = $plRow['name'];
        }

        // Validate days_of_week: null = every day, otherwise array of 0-6
        $pgDays = null;
        if ($daysOfWeek !== null && $daysOfWeek !== '') {
            if (!is_array($daysOfWeek) || empty($daysOfWeek)) {
                Response::error('days_of_week must be an array of day numbers (0-6) or null for every day', 400);
                return;
            }
            foreach ($daysOfWeek as $d) {
                if (!is_int($d) && !ctype_digit((string) $d)) {
                    Response::error('Each day must be an integer 0-6', 400);
                    return;
                }
                $d = (int) $d;
                if ($d < 0 || $d > 6) {
                    Response::error('Each day must be 0-6 (Mon=0 … Sun=6)', 400);
                    return;
                }
            }
            $days = array_unique(array_map('intval', $daysOfWeek));
            sort($days);
            $pgDays = '{' . implode(',', $days) . '}';
        }

        $user = Auth::requireAuth();

        // ── Overlap check ──────────────────────────────────
        $overlap = $this->findOverlap($db, $startTime, $endTime, $daysOfWeek, $startDate, $endDate, null);
        if ($overlap) {
            Response::error('Schedule overlaps with "' . $overlap . '"', 409);
            return;
        }

        $stmt = $db->prepare('
            INSERT INTO schedules (name, playlist_id, days_of_week, start_date, end_date, start_time, end_time, priority, created_by)
            VALUES (:name, :playlist_id, :days_of_week, :start_date, :end_date, :start_time, :end_time, :priority, :user_id)
            RETURNING id
        ');
        $stmt->execute([
            'name'         => $name,
            'playlist_id'  => $playlistId,
            'days_of_week' => $pgDays,
            'start_date'   => $startDate,
            'end_date'     => $endDate,
            'start_time'   => $startTime,
            'end_time'     => $endTime,
            'priority'     => $priority,
            'user_id'      => $user['sub'],
        ]);

        $id = (int) $stmt->fetchColumn();
        Response::json(['id' => $id, 'message' => 'Schedule created'], 201);
    }

    /**
     * Check for overlapping active schedules on any shared day + date range.
     * Returns the name of the conflicting schedule, or null if no overlap.
     */
    private function findOverlap(PDO $db, string $start, string $end, ?array $days, ?string $startDate, ?string $endDate, ?int $excludeId): ?string
    {
        $rows = $db->query('
            SELECT id, name, days_of_week, start_date, end_date, start_time, end_time
            FROM schedules
            WHERE is_active = true
        ')->fetchAll();

        $inputDays = $days ?? [0,1,2,3,4,5,6];

        foreach ($rows as $row) {
            if ($excludeId !== null && (int) $row['id'] === $excludeId) {
                continue;
            }

            // Check date range overlap: if either has no constraint, they overlap
            if (!$this->dateRangesOverlap($startDate, $endDate, $row['start_date'], $row['end_date'])) {
                continue;
            }

            // Parse existing schedule days
            $existDays = [0,1,2,3,4,5,6];
            if ($row['days_of_week'] !== null) {
                $trimmed = trim($row['days_of_week'], '{}');
                $existDays = $trimmed !== '' ? array_map('intval', explode(',', $trimmed)) : [];
            }

            if (empty(array_intersect($inputDays, $existDays))) {
                continue;
            }

            // Check time overlap: strictly overlapping (touching boundaries OK)
            // Normalize to HH:MM for comparison (DB may store HH:MM:SS)
            $existStart = substr($row['start_time'], 0, 5);
            $existEnd   = substr($row['end_time'], 0, 5);
            $s = substr($start, 0, 5);
            $e = substr($end, 0, 5);
            if ($s < $existEnd && $existStart < $e && $s !== $existEnd && $e !== $existStart) {
                return $row['name'];
            }
        }

        return null;
    }

    private function dateRangesOverlap(?string $s1, ?string $e1, ?string $s2, ?string $e2): bool
    {
        // If either range is unbounded, they potentially overlap
        if ($s1 === null && $e1 === null) return true;
        if ($s2 === null && $e2 === null) return true;

        $start1 = $s1 ?? '0000-01-01';
        $end1   = $e1 ?? '9999-12-31';
        $start2 = $s2 ?? '0000-01-01';
        $end2   = $e2 ?? '9999-12-31';

        return $start1 <= $end2 && $start2 <= $end1;
    }
}
