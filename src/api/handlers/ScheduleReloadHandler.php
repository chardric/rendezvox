<?php

declare(strict_types=1);

/**
 * POST /api/admin/schedules/reload
 *
 * Called after schedule modifications to ensure the stream reflects the
 * current schedule.  Resolves the active schedule and compares it with
 * what is currently playing.  If they differ, sends a skip command to
 * Liquidsoap so it requests the next track (which queries the updated
 * schedule).  If they match, does nothing.
 */
class ScheduleReloadHandler
{
    private const LIQ_HOST    = 'rendezvox-liquidsoap';
    private const LIQ_PORT    = 1234;
    private const LIQ_TIMEOUT = 3;

    public function handle(): void
    {
        Auth::requireAuth();
        $db = Database::get();

        // What playlist SHOULD be playing right now?
        $schedule   = $this->resolveSchedule($db);
        $shouldPlay = $schedule ? (int) $schedule['playlist_id'] : null;

        // What playlist IS currently playing?
        Database::ensureRotationState();
        $state      = $db->query('SELECT current_playlist_id, is_playing FROM rotation_state WHERE id = 1')->fetch();
        $isPlaying  = (bool) ($state['is_playing'] ?? false);
        $currentPl  = $state['current_playlist_id'] ? (int) $state['current_playlist_id'] : null;

        // Accept force param (used by Surprise Me / Clear)
        $body = json_decode(file_get_contents('php://input'), true);
        $force = !empty($body['force']);

        // Decide whether to skip
        $needsSkip = false;

        if ($force) {
            // Caller explicitly requested a skip (bulk schedule change)
            $needsSkip = true;
        } elseif ($shouldPlay === null && $isPlaying) {
            // No schedule active but still playing → skip to silence
            $needsSkip = true;
        } elseif ($shouldPlay !== null && $shouldPlay !== $currentPl) {
            // Different playlist should be playing → skip
            $needsSkip = true;
        } elseif ($shouldPlay !== null && !$isPlaying) {
            // Was idle, schedule now active → wake up
            $needsSkip = true;
        }

        if (!$needsSkip) {
            Response::json(['changed' => false, 'message' => 'Schedule unchanged — no action needed']);
            return;
        }

        // Send skip command to Liquidsoap
        $sock = @fsockopen(self::LIQ_HOST, self::LIQ_PORT, $errno, $errstr, self::LIQ_TIMEOUT);
        if (!$sock) {
            Response::json([
                'changed' => true,
                'message' => 'Schedule changed; stream will update on next track (engine unavailable)',
            ]);
            return;
        }

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

        Response::json([
            'changed' => true,
            'message' => 'Schedule changed — stream switching to new playlist',
        ]);
    }

    private function resolveSchedule(PDO $db): ?array
    {
        $tz = $this->getSetting($db, 'station_timezone', 'UTC');

        $stmt = $db->prepare("
            SELECT s.id AS schedule_id, s.playlist_id
            FROM schedules s
            JOIN playlists p ON p.id = s.playlist_id
            WHERE s.is_active = true
              AND p.is_active = true
              AND (EXTRACT(DOW FROM NOW() AT TIME ZONE :tz)::int = ANY(s.days_of_week)
                   OR s.days_of_week IS NULL)
              AND (s.start_date IS NULL OR s.start_date <= (NOW() AT TIME ZONE :tz4)::date)
              AND (s.end_date   IS NULL OR s.end_date   >= (NOW() AT TIME ZONE :tz5)::date)
              AND s.start_time <= (NOW() AT TIME ZONE :tz2)::time
              AND s.end_time   >  (NOW() AT TIME ZONE :tz3)::time
            ORDER BY s.priority DESC
            LIMIT 1
        ");
        $stmt->execute(['tz' => $tz, 'tz2' => $tz, 'tz3' => $tz, 'tz4' => $tz, 'tz5' => $tz]);
        return $stmt->fetch() ?: null;
    }

    private function getSetting(PDO $db, string $key, string $default): string
    {
        $stmt = $db->prepare('SELECT value FROM settings WHERE key = :key');
        $stmt->execute(['key' => $key]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (string) $val : $default;
    }
}
