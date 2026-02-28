<?php

declare(strict_types=1);

/**
 * Three-tier song matching: Exact → ILIKE → pg_trgm.
 * Used by SongSearchHandler (public search) and SubmitRequestHandler (request resolution).
 */
class SongResolver
{
    private const MAX_RESULTS    = 10;
    private const TRGM_THRESHOLD = 0.3;

    private \PDO $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    /**
     * @return array{songs: list<array{id: int, title: string, artist: string}>, resolved: bool}
     */
    public function resolve(string $title, string $artist = ''): array
    {
        if ($title !== '') {
            return $this->resolveByTitle($title, $artist);
        }

        if ($artist !== '') {
            return $this->resolveByArtist($artist);
        }

        return ['songs' => [], 'resolved' => false];
    }

    private function resolveByTitle(string $title, string $artist): array
    {
        // Priority A: Exact match
        $exact = $this->searchExact($title, $artist);
        if (count($exact) === 1) {
            return ['songs' => $exact, 'resolved' => true];
        }
        if (count($exact) > 1) {
            return ['songs' => $exact, 'resolved' => false];
        }

        // Priority B: ILIKE partial
        $partial = $this->searchPartial($title, $artist);
        if (!empty($partial)) {
            return ['songs' => $partial, 'resolved' => count($partial) === 1];
        }

        // Priority C: pg_trgm similarity
        $trgm = $this->searchTrigram($title, $artist);
        return ['songs' => $trgm, 'resolved' => count($trgm) === 1];
    }

    private function resolveByArtist(string $artist): array
    {
        // Tier A: Exact artist name
        $exact = $this->searchArtistExact($artist);
        if (!empty($exact)) {
            return ['songs' => $exact, 'resolved' => false];
        }

        // Tier B: ILIKE partial artist
        $partial = $this->searchArtistPartial($artist);
        if (!empty($partial)) {
            return ['songs' => $partial, 'resolved' => false];
        }

        // Tier C: Trigram on artist name
        $trgm = $this->searchArtistTrigram($artist);
        return ['songs' => $trgm, 'resolved' => false];
    }

    private function searchExact(string $title, string $artist): array
    {
        $sql = '
            SELECT s.id, s.title, a.name AS artist
            FROM songs s
            JOIN artists a ON a.id = s.artist_id
            WHERE s.is_active = true
              AND s.is_requestable = true
              AND s.duplicate_of IS NULL
              AND LOWER(s.title) = LOWER(:title)
        ';
        $params = ['title' => $title];

        if ($artist !== '') {
            $sql .= ' AND LOWER(a.name) = LOWER(:artist)';
            $params['artist'] = $artist;
        }

        $sql .= ' LIMIT ' . self::MAX_RESULTS;
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $this->format($stmt->fetchAll());
    }

    private function searchPartial(string $title, string $artist): array
    {
        $sql = '
            SELECT s.id, s.title, a.name AS artist
            FROM songs s
            JOIN artists a ON a.id = s.artist_id
            WHERE s.is_active = true
              AND s.is_requestable = true
              AND s.duplicate_of IS NULL
              AND s.title ILIKE :title_like
        ';
        $params = ['title_like' => '%' . $title . '%'];

        if ($artist !== '') {
            $sql .= ' AND a.name ILIKE :artist_like';
            $params['artist_like'] = '%' . $artist . '%';
        }

        $sql .= ' ORDER BY s.title LIMIT ' . self::MAX_RESULTS;
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $this->format($stmt->fetchAll());
    }

    private function searchTrigram(string $title, string $artist): array
    {
        $sql = '
            SELECT
                s.id,
                s.title,
                a.name AS artist,
                GREATEST(
                    similarity(s.title, :t1),
                    word_similarity(:t2, s.title)
                ) AS score
            FROM songs s
            JOIN artists a ON a.id = s.artist_id
            WHERE s.is_active = true
              AND s.is_requestable = true
              AND s.duplicate_of IS NULL
              AND (
                  similarity(s.title, :t3) > :threshold
                  OR word_similarity(:t4, s.title) > :threshold2
              )
        ';
        $params = [
            't1' => $title, 't2' => $title,
            't3' => $title, 't4' => $title,
            'threshold'  => self::TRGM_THRESHOLD,
            'threshold2' => self::TRGM_THRESHOLD,
        ];

        if ($artist !== '') {
            $sql .= ' AND (similarity(a.name, :a1) > :threshold3 OR word_similarity(:a2, a.name) > :threshold4)';
            $params['a1'] = $artist;
            $params['a2'] = $artist;
            $params['threshold3'] = self::TRGM_THRESHOLD;
            $params['threshold4'] = self::TRGM_THRESHOLD;
        }

        $sql .= ' ORDER BY score DESC LIMIT ' . self::MAX_RESULTS;
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $this->format($stmt->fetchAll());
    }

    private function searchArtistExact(string $artist): array
    {
        $sql = '
            SELECT s.id, s.title, a.name AS artist
            FROM songs s
            JOIN artists a ON a.id = s.artist_id
            WHERE s.is_active = true
              AND s.is_requestable = true
              AND s.duplicate_of IS NULL
              AND LOWER(a.name) = LOWER(:artist)
            ORDER BY s.title
            LIMIT ' . self::MAX_RESULTS;
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['artist' => $artist]);
        return $this->format($stmt->fetchAll());
    }

    private function searchArtistPartial(string $artist): array
    {
        $sql = '
            SELECT s.id, s.title, a.name AS artist
            FROM songs s
            JOIN artists a ON a.id = s.artist_id
            WHERE s.is_active = true
              AND s.is_requestable = true
              AND s.duplicate_of IS NULL
              AND a.name ILIKE :artist_like
            ORDER BY s.title
            LIMIT ' . self::MAX_RESULTS;
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['artist_like' => '%' . $artist . '%']);
        return $this->format($stmt->fetchAll());
    }

    private function searchArtistTrigram(string $artist): array
    {
        $sql = '
            SELECT
                s.id,
                s.title,
                a.name AS artist,
                GREATEST(
                    similarity(a.name, :a1),
                    word_similarity(:a2, a.name)
                ) AS score
            FROM songs s
            JOIN artists a ON a.id = s.artist_id
            WHERE s.is_active = true
              AND s.is_requestable = true
              AND s.duplicate_of IS NULL
              AND (
                  similarity(a.name, :a3) > :threshold
                  OR word_similarity(:a4, a.name) > :threshold2
              )
            ORDER BY score DESC, s.title
            LIMIT ' . self::MAX_RESULTS;
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'a1' => $artist, 'a2' => $artist,
            'a3' => $artist, 'a4' => $artist,
            'threshold'  => self::TRGM_THRESHOLD,
            'threshold2' => self::TRGM_THRESHOLD,
        ]);
        return $this->format($stmt->fetchAll());
    }

    private function format(array $rows): array
    {
        return array_map(function ($r) {
            return [
                'id'     => (int) $r['id'],
                'title'  => $r['title'],
                'artist' => $r['artist'],
            ];
        }, $rows);
    }
}
