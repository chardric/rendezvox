<?php

declare(strict_types=1);

class MediaImportHandler
{
    public function handle(): void
    {
        $db   = Database::get();
        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        $relPath = trim($body['path'] ?? '');
        $weight  = (float) ($body['rotation_weight'] ?? 1.0);

        if ($relPath === '') {
            Response::error('path is required', 400);
            return;
        }

        $absPath = MediaBrowseHandler::safePath($relPath);
        if ($absPath === null || !is_file($absPath)) {
            Response::error('File not found', 404);
            return;
        }

        // Check already imported by path
        $stmt = $db->prepare('SELECT id, title FROM songs WHERE file_path = :path');
        $stmt->execute(['path' => $absPath]);
        $existing = $stmt->fetch();
        if ($existing) {
            Response::error('Already imported as song #' . $existing['id'] . ': ' . $existing['title'], 409);
            return;
        }

        // Check duplicate content via SHA-256
        $hash = hash_file('sha256', $absPath);
        $stmt = $db->prepare('SELECT id, title FROM songs WHERE file_hash = :hash');
        $stmt->execute(['hash' => $hash]);
        $dup = $stmt->fetch();
        if ($dup) {
            Response::error('Duplicate file — matches song #' . $dup['id'] . ': ' . $dup['title'], 409);
            return;
        }

        // Extract metadata via ffprobe
        $meta = MetadataExtractor::extract($absPath);
        if ($meta['duration_ms'] <= 0) {
            Response::error('Cannot determine audio duration — is ffprobe installed?', 500);
            return;
        }

        // Title: body override → tag → filename-derived
        $title    = trim($body['title'] ?? '') ?: $meta['title'];
        $artistId = (int) ($body['artist_id'] ?? 0);
        if ($artistId <= 0 && $meta['artist'] !== '') {
            $artistId = $this->findOrCreateArtist($db, $meta['artist']);
        }

        if ($title === '' || $artistId <= 0) {
            Response::error('Could not determine title or artist — embed ID3 tags or provide them manually', 400);
            return;
        }

        // Genre/category: metadata tag → default "Easy Listening"
        $genreName  = $meta['genre'] !== '' ? $meta['genre'] : 'Easy Listening';
        $categoryId = $this->findOrCreateCategory($db, $genreName);

        $stmt = $db->prepare('
            INSERT INTO songs (title, artist_id, category_id, file_path, file_hash,
                               duration_ms, rotation_weight, year)
            VALUES (:title, :artist_id, :category_id, :file_path, :file_hash,
                    :duration_ms, :weight, :year)
            RETURNING id
        ');
        $stmt->execute([
            'title'       => $title,
            'artist_id'   => $artistId,
            'category_id' => $categoryId,
            'file_path'   => $absPath,
            'file_hash'   => $hash,
            'duration_ms' => $meta['duration_ms'],
            'weight'      => $weight,
            'year'        => $meta['year'] ?: null,
        ]);

        Response::json([
            'id'      => (int) $stmt->fetchColumn(),
            'title'   => $title,
            'artist'  => $meta['artist'],
            'message' => 'Imported successfully',
        ], 201);
    }

    private function findOrCreateArtist(\PDO $db, string $name): int
    {
        $name = ArtistNormalizer::extractPrimary($name, $db);
        $normalized = mb_strtolower(trim($name));
        $stmt = $db->prepare('SELECT id FROM artists WHERE normalized_name = :norm');
        $stmt->execute(['norm' => $normalized]);
        $row = $stmt->fetch();
        if ($row) return (int) $row['id'];

        $stmt = $db->prepare('INSERT INTO artists (name, normalized_name) VALUES (:name, :norm) RETURNING id');
        $stmt->execute(['name' => trim($name), 'norm' => $normalized]);
        return (int) $stmt->fetchColumn();
    }

    private function findOrCreateCategory(\PDO $db, string $name): int
    {
        $stmt = $db->prepare('SELECT id FROM categories WHERE LOWER(name) = LOWER(:name)');
        $stmt->execute(['name' => trim($name)]);
        $row = $stmt->fetch();
        if ($row) return (int) $row['id'];

        $stmt = $db->prepare("INSERT INTO categories (name, type) VALUES (:name, 'music') RETURNING id");
        $stmt->execute(['name' => trim($name)]);
        return (int) $stmt->fetchColumn();
    }
}
