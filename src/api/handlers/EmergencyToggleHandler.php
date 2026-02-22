<?php

declare(strict_types=1);

/**
 * POST /api/admin/toggle-emergency
 *
 * Enables or disables emergency mode.
 * When enabled, overrides all schedules and plays only the emergency playlist.
 */
class EmergencyToggleHandler
{
    public function handle(): void
    {
        $input = json_decode(file_get_contents('php://input'), true);

        if (!isset($input['enabled']) || !is_bool($input['enabled'])) {
            Response::error('Missing or invalid field: enabled (boolean required)', 422);
        }

        $enabled = $input['enabled'];
        $db      = Database::get();
        Database::ensureRotationState();

        if ($enabled) {
            $this->activate($db);
        } else {
            $this->deactivate($db);
        }
    }

    private function activate(PDO $db): void
    {
        // Find the active emergency-type playlist
        $stmt = $db->query("
            SELECT p.id, p.name,
                   (SELECT COUNT(*) FROM playlist_songs ps
                    JOIN songs s ON s.id = ps.song_id
                    WHERE ps.playlist_id = p.id AND s.is_active = true) AS song_count
            FROM playlists p
            WHERE p.type = 'emergency' AND p.is_active = true
            ORDER BY p.id ASC
            LIMIT 1
        ");
        $playlist = $stmt->fetch();

        if (!$playlist) {
            Response::error('No active emergency playlist found — create a playlist with type "emergency" first', 404);
        }

        $playlistId = (int) $playlist['id'];

        if ((int) $playlist['song_count'] === 0) {
            Response::error('Emergency playlist "' . $playlist['name'] . '" has no active songs', 422);
        }

        $db->beginTransaction();
        try {
            $db->prepare('UPDATE settings SET value = :val WHERE key = :key')
                ->execute(['val' => 'true', 'key' => 'emergency_mode']);

            // Mark as manually activated so schedule resolution won't auto-disable it
            $db->prepare("
                INSERT INTO settings (key, value, type, description)
                VALUES ('emergency_auto_activated', 'false', 'boolean', 'True when emergency was auto-activated by schedule gap')
                ON CONFLICT (key) DO UPDATE SET value = 'false'
            ")->execute();

            $db->prepare('UPDATE rotation_state SET is_emergency = true WHERE id = 1')
                ->execute();

            $db->prepare('
                INSERT INTO station_logs (level, component, message, context)
                VALUES (:level, :component, :message, :context)
            ')->execute([
                'level'     => 'warn',
                'component' => 'emergency',
                'message'   => 'Emergency mode activated',
                'context'   => json_encode([
                    'playlist_id'   => $playlistId,
                    'playlist_name' => $playlist['name'],
                ]),
            ]);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log('Emergency activate failed: ' . $e->getMessage());
            Response::error('Failed to activate emergency mode', 500);
        }

        // Force Liquidsoap to skip current track and fetch from emergency playlist
        $this->skipLiquidsoap();

        Response::json([
            'emergency'   => true,
            'playlist_id' => $playlistId,
        ]);
    }

    private function deactivate(PDO $db): void
    {
        $db->beginTransaction();
        try {
            $db->prepare('UPDATE settings SET value = :val WHERE key = :key')
                ->execute(['val' => 'false', 'key' => 'emergency_mode']);

            $db->prepare('UPDATE rotation_state SET is_emergency = false WHERE id = 1')
                ->execute();

            $db->prepare('
                INSERT INTO station_logs (level, component, message)
                VALUES (:level, :component, :message)
            ')->execute([
                'level'     => 'info',
                'component' => 'emergency',
                'message'   => 'Emergency mode deactivated',
            ]);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log('Emergency deactivate failed: ' . $e->getMessage());
            Response::error('Failed to deactivate emergency mode', 500);
        }

        // Force Liquidsoap to skip back to normal rotation
        $this->skipLiquidsoap();

        Response::json([
            'emergency' => false,
        ]);
    }

    /**
     * Tell Liquidsoap to flush its pre-fetched queue and skip the current track,
     * so the next track comes from a fresh API call (picking up the mode change).
     */
    private function skipLiquidsoap(): void
    {
        $host = getenv('LIQUIDSOAP_HOST') ?: 'iradio-liquidsoap';
        $port = (int) (getenv('LIQUIDSOAP_PORT') ?: 1234);

        $fp = @fsockopen($host, $port, $errno, $errstr, 2);
        if ($fp) {
            fwrite($fp, "request.dynamic.flush_and_skip\n");
            fflush($fp);
            stream_set_timeout($fp, 2);
            fgets($fp); // read response
            fclose($fp);
        }
    }
}
