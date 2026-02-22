<?php

declare(strict_types=1);

class ArtistCreateHandler
{
    public function handle(): void
    {
        $db   = Database::get();
        $body = json_decode(file_get_contents('php://input'), true);

        $name = trim($body['name'] ?? '');
        if ($name === '') {
            Response::error('name is required', 400);
            return;
        }

        $normalized = strtolower(preg_replace('/\s+/', ' ', $name));

        // Check for duplicate
        $stmt = $db->prepare('SELECT id FROM artists WHERE normalized_name = :n');
        $stmt->execute(['n' => $normalized]);
        if ($existing = $stmt->fetch()) {
            Response::json([
                'id'      => (int) $existing['id'],
                'message' => 'Artist already exists',
            ]);
            return;
        }

        $stmt = $db->prepare('
            INSERT INTO artists (name, normalized_name) VALUES (:name, :normalized)
            RETURNING id
        ');
        $stmt->execute(['name' => $name, 'normalized' => $normalized]);
        $id = (int) $stmt->fetchColumn();

        Response::json(['id' => $id, 'message' => 'Artist created'], 201);
    }
}
