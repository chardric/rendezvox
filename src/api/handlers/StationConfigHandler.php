<?php

declare(strict_types=1);

/**
 * GET /api/config
 *
 * Public endpoint returning station settings needed by
 * Liquidsoap and other internal services at startup.
 */
class StationConfigHandler
{
    public function handle(): void
    {
        $db = Database::get();

        $stmt = $db->query("
            SELECT key, value FROM settings
            WHERE key IN ('crossfade_ms', 'station_name', 'station_timezone', 'station_logo_path', 'accent_color', 'smart_jingle_enabled', 'weather_latitude', 'weather_longitude')
        ");

        $config = [];
        while ($row = $stmt->fetch()) {
            $config[$row['key']] = $row['value'];
        }

        // Auto-detect timezone from the server's system clock
        $serverTz = date_default_timezone_get() ?: 'UTC';

        Response::json([
            'crossfade_ms'     => (int) ($config['crossfade_ms'] ?? 3000),
            'station_name'     => $config['station_name'] ?? 'RendezVox',
            'station_timezone' => $serverTz,
            'has_logo'         => !empty($config['station_logo_path'] ?? ''),
            'accent_color'     => !empty($config['accent_color'] ?? '') ? $config['accent_color'] : '#ff7800',
            'smart_jingle_enabled' => ($config['smart_jingle_enabled'] ?? 'false') === 'true',
            'weather_latitude'     => $config['weather_latitude'] ?? '',
            'weather_longitude'    => $config['weather_longitude'] ?? '',
        ]);
    }
}
