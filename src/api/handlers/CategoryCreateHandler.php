<?php

declare(strict_types=1);

class CategoryCreateHandler
{
    public function handle(): void
    {
        $db   = Database::get();
        $body = json_decode(file_get_contents('php://input'), true);

        $name   = trim($body['name'] ?? '');
        $type   = $body['type'] ?? 'music';
        $weight = (float) ($body['rotation_weight'] ?? 1.0);

        if ($name === '') {
            Response::error('name is required', 400);
            return;
        }

        $validTypes = ['music', 'station_id', 'sweeper', 'liner', 'emergency'];
        if (!in_array($type, $validTypes)) {
            Response::error('type must be one of: ' . implode(', ', $validTypes), 400);
            return;
        }

        // Check duplicate
        $stmt = $db->prepare('SELECT id FROM categories WHERE name = :name');
        $stmt->execute(['name' => $name]);
        if ($existing = $stmt->fetch()) {
            Response::json([
                'id'      => (int) $existing['id'],
                'message' => 'Category already exists',
            ]);
            return;
        }

        $stmt = $db->prepare('
            INSERT INTO categories (name, type, rotation_weight)
            VALUES (:name, :type, :weight)
            RETURNING id
        ');
        $stmt->execute(['name' => $name, 'type' => $type, 'weight' => $weight]);
        $id = (int) $stmt->fetchColumn();

        Response::json(['id' => $id, 'message' => 'Category created'], 201);
    }
}
