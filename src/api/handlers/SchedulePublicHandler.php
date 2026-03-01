<?php

declare(strict_types=1);

class SchedulePublicHandler
{
    public function handle(): void
    {
        $db = Database::get();

        $stmt = $db->query('
            SELECT s.name, s.days_of_week, s.start_time, s.end_time,
                   p.name AS playlist_name, p.color AS playlist_color
            FROM schedules s
            JOIN playlists p ON p.id = s.playlist_id
            WHERE s.is_active = true AND p.is_active = true
            ORDER BY s.start_time ASC
        ');

        $schedules = [];
        while ($row = $stmt->fetch()) {
            $schedules[] = [
                'name'           => $row['name'],
                'days_of_week'   => self::parsePgArray($row['days_of_week']),
                'start_time'     => $row['start_time'],
                'end_time'       => $row['end_time'],
                'playlist_name'  => $row['playlist_name'],
                'playlist_color' => $row['playlist_color'],
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
