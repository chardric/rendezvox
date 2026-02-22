<?php

declare(strict_types=1);

class PlaylistSongRemoveHandler
{
    private const LIQ_HOST    = 'iradio-liquidsoap';
    private const LIQ_PORT    = 1234;
    private const LIQ_TIMEOUT = 3;

    public function handle(): void
    {
        $db     = Database::get();
        $id     = (int) ($_GET['id'] ?? 0);
        $songId = (int) ($_GET['song_id'] ?? 0);

        if ($id <= 0 || $songId <= 0) {
            Response::error('playlist id and song_id are required', 400);
            return;
        }

        // Check playlist type
        $stmt = $db->prepare('SELECT type, rules FROM playlists WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $playlist = $stmt->fetch();

        if (!$playlist) {
            Response::error('Playlist not found', 404);
            return;
        }

        if ($playlist['type'] === 'auto') {
            // Auto playlist: add song to excluded_songs in rules
            $rules = $playlist['rules'] ? json_decode($playlist['rules'], true) : [];
            $excluded = $rules['excluded_songs'] ?? [];

            if (!in_array($songId, $excluded)) {
                $excluded[] = $songId;
            }

            $rules['excluded_songs'] = $excluded;

            $db->prepare('UPDATE playlists SET rules = :rules WHERE id = :id')
                ->execute(['rules' => json_encode($rules), 'id' => $id]);
        } else {
            // Manual/emergency: delete from playlist_songs
            $stmt = $db->prepare('
                DELETE FROM playlist_songs WHERE playlist_id = :pid AND song_id = :sid
            ');
            $stmt->execute(['pid' => $id, 'sid' => $songId]);

            if ($stmt->rowCount() === 0) {
                Response::error('Song not found in playlist', 404);
                return;
            }
        }

        // If the removed song is currently playing or queued as next
        // for this playlist, clear it and skip to the next track.
        $this->advanceIfActive($db, $id, $songId);

        Response::json(['message' => 'Song removed from playlist']);
    }

    /**
     * If the removed song is current or next for this playlist,
     * clear it from rotation_state and skip the stream forward.
     */
    private function advanceIfActive(PDO $db, int $playlistId, int $songId): void
    {
        $rs = $db->query('
            SELECT current_song_id, next_song_id,
                   current_playlist_id, next_playlist_id
            FROM rotation_state WHERE id = 1
        ')->fetch();

        if (!$rs) return;

        $isCurrent = (int) ($rs['current_song_id'] ?? 0) === $songId
                  && (int) ($rs['current_playlist_id'] ?? 0) === $playlistId;

        $isNext = (int) ($rs['next_song_id'] ?? 0) === $songId
               && (int) ($rs['next_playlist_id'] ?? 0) === $playlistId;

        if (!$isCurrent && !$isNext) return;

        // Clear the removed song from rotation state
        $updates = [];
        $params  = [];
        if ($isCurrent) {
            $updates[] = 'current_song_id = NULL';
        }
        if ($isNext) {
            $updates[] = 'next_song_id = NULL';
            $updates[] = "next_source = 'rotation'";
        }

        if (!empty($updates)) {
            $db->exec('UPDATE rotation_state SET ' . implode(', ', $updates) . ' WHERE id = 1');
        }

        // Skip the stream so Liquidsoap requests the next track
        // (which will no longer pick the removed song)
        $this->skipStream();
    }

    private function skipStream(): void
    {
        $sock = @fsockopen(self::LIQ_HOST, self::LIQ_PORT, $errno, $errstr, self::LIQ_TIMEOUT);
        if (!$sock) return; // best effort — stream will self-correct on next track

        stream_set_timeout($sock, self::LIQ_TIMEOUT);
        usleep(100000);
        while (($info = stream_get_meta_data($sock)) && !$info['eof']) {
            $peek = @fread($sock, 1024);
            if ($peek === false || $peek === '') break;
        }

        fwrite($sock, "stream.skip\r\n");
        usleep(200000);
        fread($sock, 1024);
        fwrite($sock, "quit\r\n");
        fclose($sock);
    }
}
