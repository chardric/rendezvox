<?php

declare(strict_types=1);

class NowPlayingHandler
{
    public function handle(): void
    {
        $db = Database::get();

        $stmt = $db->query('
            SELECT
                rs.is_playing,
                rs.playback_offset_ms,
                rs.started_at,
                rs.is_emergency,
                rs.next_song_id,
                s.id         AS song_id,
                s.title      AS song_title,
                s.year,
                s.duration_ms,
                s.file_path,
                s.has_cover_art,
                a.name       AS artist_name,
                c.name       AS category_name
            FROM rotation_state rs
            LEFT JOIN songs    s ON s.id = rs.current_song_id
            LEFT JOIN artists  a ON a.id = s.artist_id
            LEFT JOIN categories c ON c.id = s.category_id
            WHERE rs.id = 1
        ');

        $row = $stmt->fetch();

        if (!$row || !$row['song_id']) {
            Response::json([
                'is_playing'  => false,
                'song'        => null,
                'next_track'  => null,
                'message'     => 'No track currently loaded',
            ]);
            return;
        }

        // ── Up next (from rotation_state.next_song_id, skip if same as current) ──
        $nextTrack = null;
        if ($row['next_song_id'] && (int) $row['next_song_id'] !== (int) $row['song_id']) {
            $ntStmt = $db->prepare('
                SELECT s.title, a.name AS artist_name
                FROM songs   s
                JOIN artists a ON a.id = s.artist_id
                WHERE s.id = :id
            ');
            $ntStmt->execute(['id' => $row['next_song_id']]);
            $ntRow = $ntStmt->fetch();
            if ($ntRow) {
                $nextTrack = [
                    'title'  => $ntRow['title'],
                    'artist' => $ntRow['artist_name'],
                ];
            }
        }

        // ── Request info (listener name + dedication) ──
        $requestInfo = null;
        $phStmt = $db->prepare('
            SELECT ph.source, sr.listener_name, sr.message
            FROM play_history ph
            LEFT JOIN song_requests sr
                ON sr.song_id = ph.song_id
               AND sr.status  = \'played\'
               AND sr.played_at IS NOT NULL
            WHERE ph.song_id = :song_id
              AND ph.ended_at IS NULL
            ORDER BY ph.started_at DESC
            LIMIT 1
        ');
        $phStmt->execute(['song_id' => $row['song_id']]);
        $phRow = $phStmt->fetch();
        if ($phRow && $phRow['source'] === 'request') {
            $requestInfo = [
                'listener_name' => $phRow['listener_name'] ?: null,
                'message'       => $phRow['message'] ?: null,
            ];
        }

        Response::json([
            'is_playing'         => (bool) $row['is_playing'],
            'is_emergency'       => (bool) $row['is_emergency'],
            'song' => [
                'id'            => (int) $row['song_id'],
                'title'         => $row['song_title'],
                'artist'        => $row['artist_name'],
                'year'          => $row['year'] ? (int) $row['year'] : null,
                'category'      => $row['category_name'],
                'duration_ms'   => (int) $row['duration_ms'],
                'has_cover_art' => (bool) $row['has_cover_art'],
            ],
            'next_track'         => $nextTrack,
            'request'            => $requestInfo,
            'started_at'         => $row['started_at'],
            'playback_offset_ms' => (int) $row['playback_offset_ms'],
        ]);
    }
}
