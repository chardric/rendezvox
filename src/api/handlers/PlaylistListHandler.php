<?php

declare(strict_types=1);

class PlaylistListHandler
{
    public function handle(): void
    {
        $db = Database::get();

        $stmt = $db->query("
            SELECT
                p.id, p.name, p.description, p.type, p.is_active,
                p.cycle_count, p.created_at, p.rules, p.color,
                CASE WHEN p.type != 'auto' THEN COUNT(ps.id) ELSE NULL END AS song_count
            FROM playlists p
            LEFT JOIN playlist_songs ps ON ps.playlist_id = p.id
            GROUP BY p.id
            ORDER BY p.id ASC
        ");

        // Optional: check which playlists contain specific songs
        $checkIds = [];
        $songPlaylists = []; // playlist_id => count of checked songs it contains
        $raw = trim((string) ($_GET['check_song_ids'] ?? ''));
        if ($raw !== '') {
            $checkIds = array_values(array_unique(array_filter(
                array_map('intval', explode(',', $raw)),
                fn($id) => $id > 0
            )));
            if (count($checkIds) > 500) {
                $checkIds = array_slice($checkIds, 0, 500);
            }
            if (!empty($checkIds)) {
                $ph = implode(',', array_fill(0, count($checkIds), '?'));
                $cStmt = $db->prepare(
                    "SELECT playlist_id, COUNT(*) AS cnt FROM playlist_songs
                     WHERE song_id IN ({$ph}) GROUP BY playlist_id"
                );
                $cStmt->execute($checkIds);
                while ($cr = $cStmt->fetch()) {
                    $songPlaylists[(int) $cr['playlist_id']] = (int) $cr['cnt'];
                }
            }
        }

        $playlists = [];
        while ($row = $stmt->fetch()) {
            $rules = null;
            if ($row['rules']) {
                $rules = json_decode($row['rules'], true);
            }

            $songCount = $row['song_count'] !== null ? (int) $row['song_count'] : null;

            // For auto playlists, count matching songs dynamically
            if ($row['type'] === 'auto' && $rules !== null) {
                $songCount = $this->countAutoSongs($db, $rules);
            }

            $entry = [
                'id'          => (int) $row['id'],
                'name'        => $row['name'],
                'description' => $row['description'],
                'type'        => $row['type'],
                'rules'       => $rules,
                'is_active'   => (bool) $row['is_active'],
                'cycle_count' => (int) $row['cycle_count'],
                'song_count'  => $songCount,
                'color'       => $row['color'],
                'created_at'  => $row['created_at'],
            ];

            if (!empty($checkIds)) {
                $entry['contains_count'] = $songPlaylists[(int) $row['id']] ?? 0;
            }

            $playlists[] = $entry;
        }

        $rsStmt = $db->query("SELECT current_playlist_id FROM rotation_state WHERE id = 1");
        $currentPlaylistId = $rsStmt ? ($rsStmt->fetchColumn() ?: null) : null;

        Response::json([
            'playlists'           => $playlists,
            'current_playlist_id' => $currentPlaylistId ? (int) $currentPlaylistId : null,
        ]);
    }

    private function countAutoSongs(PDO $db, array $rules): int
    {
        $categories = $rules['categories'] ?? [];
        $minWeight  = (float) ($rules['min_weight'] ?? 0.0);
        $params     = ['min_weight' => $minWeight];

        if (!empty($categories)) {
            $placeholders = [];
            foreach ($categories as $i => $catId) {
                $key            = 'cat_' . $i;
                $placeholders[] = ':' . $key;
                $params[$key]   = (int) $catId;
            }
            $categoryClause = 'AND s.category_id IN (' . implode(',', $placeholders) . ')';
        } else {
            $categoryClause = "AND c.type = 'music'";
        }

        $artists = $rules['artists'] ?? [];
        if (!empty($artists)) {
            $artPlaceholders = [];
            foreach ($artists as $i => $artId) {
                $key               = 'art_' . $i;
                $artPlaceholders[] = ':' . $key;
                $params[$key]      = (int) $artId;
            }
            $artistClause = 'AND s.artist_id IN (' . implode(',', $artPlaceholders) . ')';
        } else {
            $artistClause = '';
        }

        $years = $rules['years'] ?? [];
        if (!empty($years)) {
            $yearPlaceholders = [];
            foreach ($years as $i => $yr) {
                $key                = 'yr_' . $i;
                $yearPlaceholders[] = ':' . $key;
                $params[$key]       = (int) $yr;
            }
            $yearClause = 'AND s.year IN (' . implode(',', $yearPlaceholders) . ')';
        } else {
            $yearClause = '';
        }

        $excluded = $rules['excluded_songs'] ?? [];
        if (!empty($excluded)) {
            $exPlaceholders = [];
            foreach ($excluded as $i => $exId) {
                $key              = 'ex_' . $i;
                $exPlaceholders[] = ':' . $key;
                $params[$key]     = (int) $exId;
            }
            $excludeClause = 'AND s.id NOT IN (' . implode(',', $exPlaceholders) . ')';
        } else {
            $excludeClause = '';
        }

        $stmt = $db->prepare("
            SELECT COUNT(*) FROM songs s
            JOIN categories c ON c.id = s.category_id
            WHERE s.is_active = true
              AND s.rotation_weight >= :min_weight
              {$categoryClause}
              {$artistClause}
              {$yearClause}
              {$excludeClause}
        ");
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }
}
