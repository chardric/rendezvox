<?php

declare(strict_types=1);

class SongListHandler
{
    private const MUSIC_DIR = '/var/lib/rendezvox/music';

    public function handle(): void
    {
        $db = Database::get();

        $search     = $_GET['search'] ?? '';
        $categoryId = $_GET['category_id'] ?? '';
        $active     = $_GET['active'] ?? '';        // 'true', 'false', or '' (all)
        $trashed    = $_GET['trashed'] ?? '';       // 'true' = show trash, default = exclude trashed
        $missing    = $_GET['missing'] ?? '';        // 'true' = only songs with missing files
        $page       = max(1, (int) ($_GET['page'] ?? 1));
        $perPage    = min(10000, max(1, (int) ($_GET['per_page'] ?? 50)));
        $offset     = ($page - 1) * $perPage;

        // Missing files mode: fetch all active songs and filter by disk check
        if ($missing === 'true') {
            $this->handleMissing($db, $search, $categoryId, $page, $perPage);
            return;
        }

        $where  = [];
        $params = [];

        // Trash filter: default excludes trashed songs
        if ($trashed === 'true') {
            $where[] = 's.trashed_at IS NOT NULL';
        } else {
            $where[] = 's.trashed_at IS NULL';
        }

        if ($search !== '') {
            $where[]          = '(s.title ILIKE :search OR a.name ILIKE :search)';
            $params['search'] = '%' . $search . '%';
        }
        if ($categoryId !== '') {
            $where[]              = 's.category_id = :category_id';
            $params['category_id'] = (int) $categoryId;
        }
        $artistId = $_GET['artist_id'] ?? '';
        if ($artistId !== '') {
            $where[]            = 's.artist_id = :artist_id';
            $params['artist_id'] = (int) $artistId;
        }
        if ($active === 'true') {
            $where[] = 's.is_active = true';
        } elseif ($active === 'false') {
            $where[] = 's.is_active = false';
        }

        $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        // Count
        $countSql = "
            SELECT COUNT(*)
            FROM songs s
            JOIN artists a ON a.id = s.artist_id
            {$whereClause}
        ";
        $stmt = $db->prepare($countSql);
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        // Fetch
        $sql = "
            SELECT
                s.id, s.title, s.file_path, s.file_hash, s.duration_ms,
                s.rotation_weight, s.year, s.play_count, s.last_played_at,
                s.is_active, s.is_requestable, s.trashed_at, s.created_at,
                a.id   AS artist_id,
                a.name AS artist_name,
                c.id   AS category_id,
                c.name AS category_name
            FROM songs s
            JOIN artists    a ON a.id = s.artist_id
            JOIN categories c ON c.id = s.category_id
            {$whereClause}
            ORDER BY s.title ASC
            LIMIT :limit OFFSET :offset
        ";
        $stmt = $db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue('limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        $songs = [];
        while ($row = $stmt->fetch()) {
            $songs[] = $this->formatRow($row);
        }

        Response::json([
            'songs'    => $songs,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int) ceil($total / $perPage),
        ]);
    }

    /**
     * Handle missing=true filter: find active songs whose files don't exist on disk.
     */
    private function handleMissing(PDO $db, string $search, string $categoryId, int $page, int $perPage): void
    {
        $where  = ['s.is_active = true', 's.trashed_at IS NULL', 's.file_path IS NOT NULL'];
        $params = [];

        if ($search !== '') {
            $where[]          = '(s.title ILIKE :search OR a.name ILIKE :search)';
            $params['search'] = '%' . $search . '%';
        }
        if ($categoryId !== '') {
            $where[]              = 's.category_id = :category_id';
            $params['category_id'] = (int) $categoryId;
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        $sql = "
            SELECT
                s.id, s.title, s.file_path, s.file_hash, s.duration_ms,
                s.rotation_weight, s.year, s.play_count, s.last_played_at,
                s.is_active, s.is_requestable, s.trashed_at, s.created_at,
                a.id   AS artist_id,
                a.name AS artist_name,
                c.id   AS category_id,
                c.name AS category_name
            FROM songs s
            JOIN artists    a ON a.id = s.artist_id
            JOIN categories c ON c.id = s.category_id
            {$whereClause}
            ORDER BY s.title ASC
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        $missing = [];
        while ($row = $stmt->fetch()) {
            $path = self::MUSIC_DIR . '/' . $row['file_path'];
            if (!file_exists($path)) {
                $missing[] = $this->formatRow($row);
            }
        }

        $total  = count($missing);
        $offset = ($page - 1) * $perPage;
        $paged  = array_slice($missing, $offset, $perPage);

        Response::json([
            'songs'    => $paged,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => max(1, (int) ceil($total / $perPage)),
        ]);
    }

    private function formatRow(array $row): array
    {
        return [
            'id'              => (int) $row['id'],
            'title'           => $row['title'],
            'artist_id'       => (int) $row['artist_id'],
            'artist_name'     => $row['artist_name'],
            'category_id'     => (int) $row['category_id'],
            'category_name'   => $row['category_name'],
            'file_path'       => $row['file_path'],
            'year'            => $row['year'] ? (int) $row['year'] : null,
            'duration_ms'     => (int) $row['duration_ms'],
            'rotation_weight' => (float) $row['rotation_weight'],
            'play_count'      => (int) $row['play_count'],
            'last_played_at'  => $row['last_played_at'],
            'is_active'       => (bool) $row['is_active'],
            'is_requestable'  => (bool) $row['is_requestable'],
            'trashed_at'      => $row['trashed_at'],
            'created_at'      => $row['created_at'],
        ];
    }
}
