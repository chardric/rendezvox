<?php

declare(strict_types=1);

class CategoryListHandler
{
    public function handle(): void
    {
        $db = Database::get();

        $stmt = $db->query('
            SELECT id, name, type, rotation_weight, is_active
            FROM categories
            ORDER BY name ASC
        ');

        $categories = [];
        while ($row = $stmt->fetch()) {
            $categories[] = [
                'id'              => (int) $row['id'],
                'name'            => $row['name'],
                'type'            => $row['type'],
                'rotation_weight' => (float) $row['rotation_weight'],
                'is_active'       => (bool) $row['is_active'],
            ];
        }

        Response::json(['categories' => $categories]);
    }
}
