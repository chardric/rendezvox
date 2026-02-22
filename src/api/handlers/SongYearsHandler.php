<?php

declare(strict_types=1);

class SongYearsHandler
{
    public function handle(): void
    {
        $db = Database::get();

        $stmt = $db->query('
            SELECT DISTINCT year
            FROM songs
            WHERE year IS NOT NULL AND year > 0
            ORDER BY year ASC
        ');

        $years = [];
        while ($row = $stmt->fetch()) {
            $years[] = (int) $row['year'];
        }

        Response::json(['years' => $years]);
    }
}
