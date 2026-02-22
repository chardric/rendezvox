<?php

declare(strict_types=1);

class ScheduleUpdateHandler
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

        $stmt = $db->prepare('SELECT id FROM schedules WHERE id = :id');
        $stmt->execute(['id' => $id]);
        if (!$stmt->fetch()) {
            Response::error('Schedule not found', 404);
            return;
        }

        $allowed = ['name', 'playlist_id', 'days_of_week', 'start_date', 'end_date', 'start_time', 'end_time', 'priority', 'is_active'];
        $sets   = [];
        $params = ['id' => $id];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $body)) {
                if ($field === 'days_of_week') {
                    $val = $body[$field];
                    if ($val === null || $val === '' || (is_array($val) && empty($val))) {
                        $sets[] = "days_of_week = NULL";
                    } else {
                        if (!is_array($val)) {
                            Response::error('days_of_week must be an array or null', 400);
                            return;
                        }
                        $days = array_unique(array_map('intval', $val));
                        sort($days);
                        foreach ($days as $d) {
                            if ($d < 0 || $d > 6) {
                                Response::error('Each day must be 0-6', 400);
                                return;
                            }
                        }
                        $sets[]                 = "days_of_week = :days_of_week";
                        $params['days_of_week'] = '{' . implode(',', $days) . '}';
                    }
                } elseif ($field === 'start_date' || $field === 'end_date') {
                    $val = $body[$field];
                    if ($val === null || $val === '') {
                        $sets[] = "{$field} = NULL";
                    } else {
                        $sets[]         = "{$field} = :{$field}";
                        $params[$field] = $val;
                    }
                } elseif ($field === 'is_active') {
                    $sets[]         = "{$field} = :{$field}";
                    $params[$field] = $body[$field] ? 'true' : 'false';
                } else {
                    $sets[]         = "{$field} = :{$field}";
                    $params[$field] = $body[$field];
                }
            }
        }

        if (empty($sets)) {
            Response::error('No valid fields to update', 400);
            return;
        }

        // ── Overlap check (merge existing values with updates) ──
        $existing = $db->prepare('SELECT start_time, end_time, start_date, end_date, days_of_week, is_active FROM schedules WHERE id = :id');
        $existing->execute(['id' => $id]);
        $cur = $existing->fetch();

        $newStart  = $body['start_time'] ?? $cur['start_time'];
        $newEnd    = $body['end_time'] ?? $cur['end_time'];
        $newActive = array_key_exists('is_active', $body) ? (bool) $body['is_active'] : (bool) $cur['is_active'];

        // Merge dates
        $newStartDate = array_key_exists('start_date', $body)
            ? ($body['start_date'] !== '' ? $body['start_date'] : null)
            : $cur['start_date'];
        $newEndDate = array_key_exists('end_date', $body)
            ? ($body['end_date'] !== '' ? $body['end_date'] : null)
            : $cur['end_date'];

        // Validate date range
        if ($newStartDate !== null && $newEndDate !== null && $newStartDate > $newEndDate) {
            Response::error('start_date must be before or equal to end_date', 400);
            return;
        }

        // Parse days
        $newDays = null;
        if (array_key_exists('days_of_week', $body)) {
            $val = $body['days_of_week'];
            $newDays = ($val === null || $val === '' || (is_array($val) && empty($val))) ? null : array_map('intval', $val);
        } else {
            if ($cur['days_of_week'] !== null) {
                $trimmed = trim($cur['days_of_week'], '{}');
                $newDays = $trimmed !== '' ? array_map('intval', explode(',', $trimmed)) : [];
            }
        }

        if ($newActive) {
            $overlap = $this->findOverlap($db, $newStart, $newEnd, $newDays, $newStartDate, $newEndDate, $id);
            if ($overlap) {
                Response::error('Schedule overlaps with "' . $overlap . '"', 409);
                return;
            }
        }

        $sql = 'UPDATE schedules SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $db->prepare($sql)->execute($params);

        Response::json(['message' => 'Schedule updated']);
    }

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

            if (!$this->dateRangesOverlap($startDate, $endDate, $row['start_date'], $row['end_date'])) {
                continue;
            }

            $existDays = [0,1,2,3,4,5,6];
            if ($row['days_of_week'] !== null) {
                $trimmed = trim($row['days_of_week'], '{}');
                $existDays = $trimmed !== '' ? array_map('intval', explode(',', $trimmed)) : [];
            }

            if (empty(array_intersect($inputDays, $existDays))) {
                continue;
            }

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
        if ($s1 === null && $e1 === null) return true;
        if ($s2 === null && $e2 === null) return true;

        $start1 = $s1 ?? '0000-01-01';
        $end1   = $e1 ?? '9999-12-31';
        $start2 = $s2 ?? '0000-01-01';
        $end2   = $e2 ?? '9999-12-31';

        return $start1 <= $end2 && $start2 <= $end1;
    }
}
