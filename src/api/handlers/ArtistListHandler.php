<?php

declare(strict_types=1);

class ArtistListHandler
{
    public function handle(): void
    {
        $db = Database::get();

        $search = $_GET['search'] ?? '';

        if ($search !== '') {
            $stmt = $db->prepare('
                SELECT id, name, is_active, created_at
                FROM artists
                WHERE name ILIKE :search
                ORDER BY name ASC
                LIMIT 50
            ');
            $stmt->execute(['search' => '%' . $search . '%']);
        } else {
            $stmt = $db->query('
                SELECT id, name, is_active, created_at
                FROM artists
                ORDER BY name ASC
            ');
        }

        $artists = [];
        while ($row = $stmt->fetch()) {
            $artists[] = [
                'id'        => (int) $row['id'],
                'name'      => $row['name'],
                'is_active' => (bool) $row['is_active'],
            ];
        }

        Response::json(['artists' => $artists]);
    }
}
