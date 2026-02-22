<?php

declare(strict_types=1);

class PlaylistDetailHandler
{
    public function handle(): void
    {
        $db = Database::get();
        $id = (int) ($_GET['id'] ?? 0);

        if ($id <= 0) {
            Response::error('Invalid playlist ID', 400);
            return;
        }

        $stmt = $db->prepare('SELECT * FROM playlists WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $playlist = $stmt->fetch();

        if (!$playlist) {
            Response::error('Playlist not found', 404);
            return;
        }

        $rules = $playlist['rules'] ? json_decode($playlist['rules'], true) : null;

        // Fetch rotation state to identify currently playing / up-next songs
        $rsStmt = $db->query('
            SELECT current_song_id, next_song_id, current_playlist_id, next_playlist_id
            FROM rotation_state WHERE id = 1
        ');
        $rotationState = $rsStmt->fetch();

        if ($playlist['type'] === 'auto') {
            $songs = $this->fetchAutoSongs($db, $id, $rules);
        } else {
            // Get songs in order
            $stmt = $db->prepare('
                SELECT
                    ps.id AS ps_id, ps.position, ps.played_in_cycle,
                    s.id AS song_id, s.title, s.year, s.duration_ms, s.is_active,
                    a.name AS artist_name, c.name AS category_name
                FROM playlist_songs ps
                JOIN songs      s ON s.id = ps.song_id
                JOIN artists    a ON a.id = s.artist_id
                JOIN categories c ON c.id = s.category_id
                WHERE ps.playlist_id = :id
                ORDER BY ps.position ASC
            ');
            $stmt->execute(['id' => $id]);

            $songs = [];
            while ($row = $stmt->fetch()) {
                $songs[] = [
                    'ps_id'          => (int) $row['ps_id'],
                    'position'       => (int) $row['position'],
                    'played_in_cycle'=> (bool) $row['played_in_cycle'],
                    'song_id'        => (int) $row['song_id'],
                    'title'          => $row['title'],
                    'artist'         => $row['artist_name'],
                    'category'       => $row['category_name'],
                    'year'           => $row['year'] ? (int) $row['year'] : null,
                    'duration_ms'    => (int) $row['duration_ms'],
                    'is_active'      => (bool) $row['is_active'],
                ];
            }
        }

        // Show Playing badge if this playlist owns the currently playing song,
        // and Up Next badge if this playlist owns the next queued song.
        // These are tracked separately so playlist transitions don't lose badges.
        $currentSongId = null;
        $nextSongId    = null;
        if ($rotationState) {
            if ((int) ($rotationState['current_playlist_id'] ?? 0) === (int) $playlist['id']) {
                $currentSongId = $rotationState['current_song_id'] ? (int) $rotationState['current_song_id'] : null;
            }
            if ((int) ($rotationState['next_playlist_id'] ?? 0) === (int) $playlist['id']) {
                $nextSongId = $rotationState['next_song_id'] ? (int) $rotationState['next_song_id'] : null;
            }
            // Don't show Up Next if it's the same as the current song
            if ($nextSongId !== null && $currentSongId !== null && $nextSongId === $currentSongId) {
                $nextSongId = null;
            }
        }

        Response::json([
            'playlist' => [
                'id'          => (int) $playlist['id'],
                'name'        => $playlist['name'],
                'description' => $playlist['description'],
                'type'        => $playlist['type'],
                'rules'       => $rules,
                'color'       => $playlist['color'],
                'is_active'   => (bool) $playlist['is_active'],
                'cycle_count' => (int) $playlist['cycle_count'],
                'created_at'  => $playlist['created_at'],
            ],
            'songs'           => $songs,
            'current_song_id' => $currentSongId,
            'next_song_id'    => $nextSongId,
        ]);
    }

    /**
     * Determine which songs have been played in the current cycle for an auto playlist.
     *
     * Walks play_history oldest-first. When all N songs have been seen,
     * the cycle resets. The remaining seen set = current cycle progress.
     */
    private function determineAutoCycle(PDO $db, int $playlistId, array $songIds, int $total): array
    {
        $phParams = ['pl_id' => $playlistId];
        $phPlaceholders = [];
        foreach ($songIds as $i => $sid) {
            $key = 'ph_' . $i;
            $phPlaceholders[] = ':' . $key;
            $phParams[$key] = (int) $sid;
        }
        $inClause = implode(',', $phPlaceholders);
        $limit = $total * 2;

        $stmt = $db->prepare("
            SELECT song_id FROM (
                SELECT song_id, started_at
                FROM play_history
                WHERE playlist_id = :pl_id
                  AND song_id IN ({$inClause})
                ORDER BY started_at DESC
                LIMIT {$limit}
            ) recent
            ORDER BY started_at ASC
        ");
        $stmt->execute($phParams);

        $seen = [];
        while ($row = $stmt->fetch()) {
            $sid = (int) $row['song_id'];
            $seen[$sid] = true;
            if (count($seen) >= $total) {
                $seen = [];
            }
        }

        return $seen;
    }

    private function fetchAutoSongs(PDO $db, int $playlistId, ?array $rules): array
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
            SELECT
                s.id         AS song_id,
                s.title,
                s.year,
                s.duration_ms,
                s.is_active,
                a.name       AS artist_name,
                c.name       AS category_name
            FROM songs s
            JOIN artists    a ON a.id = s.artist_id
            JOIN categories c ON c.id = s.category_id
            WHERE s.is_active = true
              AND s.rotation_weight >= :min_weight
              {$categoryClause}
              {$artistClause}
              {$yearClause}
              {$excludeClause}
            ORDER BY COALESCE(s.last_played_at, '1970-01-01'::timestamptz) ASC
        ");
        $stmt->execute($params);
        $allSongs = $stmt->fetchAll();
        $totalCount = count($allSongs);

        if ($totalCount === 0) {
            return [];
        }

        // Determine cycle progress from play_history
        $songIds = array_column($allSongs, 'song_id');
        $playedInCycle = $this->determineAutoCycle($db, $playlistId, $songIds, $totalCount);

        // Split into played and unplayed, then sort: played first, unplayed after
        $played = [];
        $unplayed = [];
        foreach ($allSongs as $row) {
            $sid = (int) $row['song_id'];
            $entry = [
                'ps_id'          => null,
                'position'       => 0,
                'played_in_cycle'=> isset($playedInCycle[$sid]),
                'song_id'        => $sid,
                'title'          => $row['title'],
                'artist'         => $row['artist_name'],
                'category'       => $row['category_name'],
                'year'           => $row['year'] ? (int) $row['year'] : null,
                'duration_ms'    => (int) $row['duration_ms'],
                'is_active'      => (bool) $row['is_active'],
            ];
            if (isset($playedInCycle[$sid])) {
                $played[] = $entry;
            } else {
                $unplayed[] = $entry;
            }
        }

        $songs = array_merge($played, $unplayed);
        $pos = 1;
        foreach ($songs as &$s) {
            $s['position'] = $pos++;
        }
        unset($s);

        return $songs;
    }
}
