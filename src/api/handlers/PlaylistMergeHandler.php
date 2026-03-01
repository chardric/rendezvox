<?php

declare(strict_types=1);

/**
 * POST /api/admin/playlists/merge
 *
 * Merge 2+ playlists into a new one. Songs from all source playlists
 * are combined; duplicates are skipped.
 *
 * Accepts: { "source_ids": [1, 2, 3], "name": "Merged Playlist" }
 */
class PlaylistMergeHandler
{
    public function handle(): void
    {
        $user = Auth::requireRole('super_admin', 'admin');

        $db   = Database::get();
        $body = json_decode(file_get_contents('php://input'), true);

        $sourceIds = $body['source_ids'] ?? [];
        $name      = trim($body['name'] ?? '');

        // Validate inputs
        if (!is_array($sourceIds) || count($sourceIds) < 2) {
            Response::error('At least 2 source playlists are required', 400);
            return;
        }
        if ($name === '') {
            Response::error('name is required', 400);
            return;
        }
        if (mb_strlen($name) > 200) {
            Response::error('name is too long (max 200 chars)', 400);
            return;
        }

        // Sanitise source IDs
        $sourceIds = array_values(array_unique(array_filter(
            array_map('intval', $sourceIds),
            fn($x) => $x > 0
        )));

        if (count($sourceIds) < 2) {
            Response::error('At least 2 valid source playlist IDs are required', 400);
            return;
        }

        // Check name uniqueness
        $stmt = $db->prepare('SELECT id FROM playlists WHERE LOWER(name) = LOWER(:name) LIMIT 1');
        $stmt->execute(['name' => $name]);
        if ($stmt->fetch()) {
            Response::error('A playlist with that name already exists', 409);
            return;
        }

        // Verify all source playlists exist
        $placeholders = [];
        $params       = [];
        foreach ($sourceIds as $i => $sid) {
            $key              = 'src_' . $i;
            $placeholders[]   = ':' . $key;
            $params[$key]     = $sid;
        }
        $inClause = implode(',', $placeholders);
        $stmt = $db->prepare("SELECT id, type, rules FROM playlists WHERE id IN ({$inClause})");
        $stmt->execute($params);
        $sources = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (count($sources) !== count($sourceIds)) {
            Response::error('One or more source playlists not found', 404);
            return;
        }

        // Index sources by ID for lookup
        $sourceMap = [];
        foreach ($sources as $s) {
            $sourceMap[(int) $s['id']] = $s;
        }

        // Pick a random color
        $colors = [
            '#ff7800', '#f87171', '#60a5fa', '#fbbf24', '#a78bfa',
            '#f472b6', '#2dd4bf', '#fb923c', '#818cf8', '#34d399',
            '#38bdf8', '#e879f9', '#facc15', '#4ade80', '#fb7185',
            '#22d3ee', '#c084fc', '#fdba74', '#a3e635', '#67e8f9',
        ];
        $color = $colors[array_rand($colors)];

        $db->beginTransaction();
        try {
            // Create new playlist
            $stmt = $db->prepare('
                INSERT INTO playlists (name, type, color, created_by)
                VALUES (:name, \'manual\', :color, :user_id)
                RETURNING id
            ');
            $stmt->execute([
                'name'    => $name,
                'color'   => $color,
                'user_id' => $user['sub'],
            ]);
            $newId = (int) $stmt->fetchColumn();

            // Collect all song IDs from source playlists (ordered by source ID)
            $allSongIds = [];
            foreach ($sourceIds as $srcId) {
                $src = $sourceMap[$srcId];
                if ($src['type'] === 'auto') {
                    // Resolve auto playlist songs via rules
                    $songIds = $this->resolveAutoSongs($db, $src);
                } else {
                    // Manual/emergency: get from playlist_songs
                    $stmt = $db->prepare('
                        SELECT song_id FROM playlist_songs
                        WHERE playlist_id = :pid
                        ORDER BY position ASC
                    ');
                    $stmt->execute(['pid' => $srcId]);
                    $songIds = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
                }
                foreach ($songIds as $sid) {
                    $allSongIds[] = $sid;
                }
            }

            // Insert songs, skipping duplicates
            $seen    = [];
            $pos     = 0;
            $added   = 0;
            $skipped = 0;
            $insert  = $db->prepare('
                INSERT INTO playlist_songs (playlist_id, song_id, position)
                VALUES (:pid, :sid, :pos)
            ');

            foreach ($allSongIds as $sid) {
                if (isset($seen[$sid])) {
                    $skipped++;
                    continue;
                }
                $seen[$sid] = true;
                $pos++;
                $insert->execute(['pid' => $newId, 'sid' => $sid, 'pos' => $pos]);
                $added++;
            }

            $db->commit();

            // Generate cycle order for the new playlist
            if ($added > 0) {
                RotationEngine::generateCycleOrder($db, $newId);
            }

            Response::json([
                'id'      => $newId,
                'message' => 'Merged ' . count($sourceIds) . ' playlists into "' . $name . '" (' . $added . ' songs, ' . $skipped . ' duplicates skipped)',
                'added'   => $added,
                'skipped' => $skipped,
            ], 201);
        } catch (\Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Resolve song IDs for an auto playlist based on its rules.
     */
    private function resolveAutoSongs(PDO $db, array $source): array
    {
        $rules      = $source['rules'] ? json_decode($source['rules'], true) : [];
        $categories = $rules['categories'] ?? [];
        $artists    = $rules['artists'] ?? [];
        $years      = $rules['years'] ?? [];
        $minWeight  = (float) ($rules['min_weight'] ?? 0.0);
        $excluded   = $rules['excluded_songs'] ?? [];

        $params = ['min_weight' => $minWeight];

        if (!empty($categories)) {
            $ph = [];
            foreach ($categories as $i => $catId) {
                $key  = 'cat_' . $i;
                $ph[] = ':' . $key;
                $params[$key] = (int) $catId;
            }
            $categoryClause = 'AND s.category_id IN (' . implode(',', $ph) . ')';
        } else {
            $categoryClause = "AND c.type = 'music'";
        }

        if (!empty($artists)) {
            $ph = [];
            foreach ($artists as $i => $artId) {
                $key  = 'art_' . $i;
                $ph[] = ':' . $key;
                $params[$key] = (int) $artId;
            }
            $artistClause = 'AND s.artist_id IN (' . implode(',', $ph) . ')';
        } else {
            $artistClause = '';
        }

        if (!empty($years)) {
            $ph = [];
            foreach ($years as $i => $yr) {
                $key  = 'yr_' . $i;
                $ph[] = ':' . $key;
                $params[$key] = (int) $yr;
            }
            $yearClause = 'AND s.year IN (' . implode(',', $ph) . ')';
        } else {
            $yearClause = '';
        }

        if (!empty($excluded)) {
            $ph = [];
            foreach ($excluded as $i => $exId) {
                $key  = 'ex_' . $i;
                $ph[] = ':' . $key;
                $params[$key] = (int) $exId;
            }
            $excludeClause = 'AND s.id NOT IN (' . implode(',', $ph) . ')';
        } else {
            $excludeClause = '';
        }

        $stmt = $db->prepare("
            SELECT s.id
            FROM songs s
            JOIN categories c ON c.id = s.category_id
            WHERE s.is_active = true
              AND s.rotation_weight >= :min_weight
              {$categoryClause}
              {$artistClause}
              {$yearClause}
              {$excludeClause}
        ");
        $stmt->execute($params);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }
}
