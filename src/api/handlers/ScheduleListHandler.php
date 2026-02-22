<?php

declare(strict_types=1);

class ScheduleListHandler
{
    public function handle(): void
    {
        $db = Database::get();

        $stmt = $db->query('
            SELECT
                s.id, s.name, s.playlist_id, s.days_of_week,
                s.start_date, s.end_date,
                s.start_time, s.end_time, s.priority, s.is_active,
                s.created_at,
                p.name AS playlist_name,
                p.color AS playlist_color,
                p.is_active AS playlist_active
            FROM schedules s
            JOIN playlists p ON p.id = s.playlist_id
            ORDER BY s.priority DESC, s.start_time ASC
        ');

        $schedules = [];
        while ($row = $stmt->fetch()) {
            $schedules[] = [
                'id'            => (int) $row['id'],
                'name'          => $row['name'],
                'playlist_id'   => (int) $row['playlist_id'],
                'playlist_name' => $row['playlist_name'],
                'days_of_week'  => self::parsePgArray($row['days_of_week']),
                'start_date'    => $row['start_date'],
                'end_date'      => $row['end_date'],
                'start_time'    => $row['start_time'],
                'end_time'      => $row['end_time'],
                'priority'      => (int) $row['priority'],
                'is_active'     => (bool) $row['is_active'],
                'playlist_color'=> $row['playlist_color'],
                'playlist_active'=> (bool) $row['playlist_active'],
                'created_at'    => $row['created_at'],
            ];
        }

        Response::json(['schedules' => $schedules]);
    }

    /**
     * Parse a PostgreSQL SMALLINT[] string like "{0,2,4}" into a PHP int array.
     * Returns null if the input is null (= every day).
     */
    private static function parsePgArray(?string $pgArray): ?array
    {
        if ($pgArray === null) {
            return null;
        }
        $trimmed = trim($pgArray, '{}');
        if ($trimmed === '') {
            return [];
        }
        return array_map('intval', explode(',', $trimmed));
    }
}
