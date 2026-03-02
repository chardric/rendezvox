<?php

declare(strict_types=1);

/**
 * GET  /api/admin/eq — get current EQ preset + bands
 * PUT  /api/admin/eq — save EQ preset + bands, write eq.json for mpv
 */
class EqHandler
{
    private const EQ_FILE    = '/var/log/rendezvox/eq.json';
    private const MPV_SOCKET = '/var/log/rendezvox/mpv.sock';

    private const PRESETS = [
        'flat'            => [0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
        'bass_boost'      => [6, 5, 4, 2, 0, 0, 0, 0, 0, 0],
        'treble_boost'    => [0, 0, 0, 0, 0, 2, 3, 4, 5, 6],
        'vocal'           => [-2, -1, 0, 2, 4, 4, 3, 1, 0, -1],
        'rock'            => [4, 3, 1, -1, -2, 1, 3, 4, 4, 3],
        'pop'             => [-1, 1, 3, 4, 3, 0, -1, -1, 1, 2],
        'jazz'            => [3, 2, 0, 2, -2, -2, 0, 2, 3, 4],
        'classical'       => [4, 3, 2, 1, 0, 0, 0, 1, 2, 3],
        'loudness'        => [6, 4, 0, 0, -2, 0, -1, -4, 4, 2],
        'small_speakers'  => [5, 4, 3, 1, 0, 1, 2, 3, 3, 2],
        'earphones'       => [-1, -1, 0, 1, 2, 2, 1, 0, -1, -2],
        'headphones'      => [2, 1, 0, 0, -1, 0, 1, 2, 2, 1],
    ];

    private const SPATIAL_MODES = ['off', 'stereo_wide', 'surround', 'crossfeed'];

    private const FREQS = [32, 64, 125, 250, 500, 1000, 2000, 4000, 8000, 16000];

    public function get(): void
    {
        Auth::requireAuth();
        $db = Database::get();

        $preset = $this->getSetting($db, 'eq_preset', 'flat');
        $bandsJson = $this->getSetting($db, 'eq_bands', '{}');
        $bands = json_decode($bandsJson, true) ?: [];
        $spatial = $this->getSetting($db, 'eq_spatial', 'off');

        // If bands are empty, populate from preset
        if (empty($bands) && isset(self::PRESETS[$preset])) {
            $bands = $this->presetToBands($preset);
        }

        Response::json([
            'preset'       => $preset,
            'bands'        => $bands,
            'spatial'      => $spatial,
            'presets'      => array_keys(self::PRESETS),
            'spatial_modes' => self::SPATIAL_MODES,
            'freqs'        => self::FREQS,
        ]);
    }

    public function put(): void
    {
        Auth::requireAuth();

        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $preset  = $body['preset'] ?? 'flat';
        $bands   = $body['bands'] ?? null;
        $spatial = $body['spatial'] ?? null;

        // Validate preset
        $validPresets = array_merge(array_keys(self::PRESETS), ['custom']);
        if (!in_array($preset, $validPresets, true)) {
            Response::error('Invalid preset', 400);
        }

        // Validate spatial mode
        if ($spatial !== null && !in_array($spatial, self::SPATIAL_MODES, true)) {
            $spatial = 'off';
        }

        // If a named preset is selected (not custom), use its band values
        if ($preset !== 'custom' && isset(self::PRESETS[$preset])) {
            $bands = $this->presetToBands($preset);
        }

        // Validate band values
        if (!is_array($bands)) {
            Response::error('Invalid bands', 400);
        }

        $sanitized = [];
        foreach (self::FREQS as $freq) {
            $key = (string) $freq;
            $val = isset($bands[$key]) ? (float) $bands[$key] : 0.0;
            $sanitized[$key] = max(-12.0, min(12.0, round($val, 1)));
        }

        $db = Database::get();
        $bandsJson = json_encode($sanitized);

        $stmt = $db->prepare("
            INSERT INTO settings (key, value, type, description)
            VALUES (:key, :val, :type, :desc)
            ON CONFLICT (key) DO UPDATE SET value = :val2
        ");

        $stmt->execute([
            'key' => 'eq_preset', 'val' => $preset, 'val2' => $preset,
            'type' => 'string', 'desc' => 'Active equalizer preset',
        ]);
        $stmt->execute([
            'key' => 'eq_bands', 'val' => $bandsJson, 'val2' => $bandsJson,
            'type' => 'json', 'desc' => 'Equalizer band gains in dB (-12 to +12)',
        ]);

        // Save spatial mode if provided
        $db2 = Database::get();
        $currentSpatial = $this->getSetting($db2, 'eq_spatial', 'off');
        if ($spatial !== null) {
            $stmt->execute([
                'key' => 'eq_spatial', 'val' => $spatial, 'val2' => $spatial,
                'type' => 'string', 'desc' => 'Spatial audio mode',
            ]);
            $currentSpatial = $spatial;
        }

        // Write eq.json for persistence (audio container reads on startup)
        $eqData = json_encode([
            'preset'  => $preset,
            'bands'   => $sanitized,
            'spatial' => $currentSpatial,
        ]);
        @file_put_contents(self::EQ_FILE, $eqData, LOCK_EX);
        @chmod(self::EQ_FILE, 0666);

        // Apply EQ + spatial to mpv in real-time via IPC socket
        $this->applyToMpv($sanitized, $currentSpatial);

        Response::json([
            'message' => 'EQ updated',
            'preset'  => $preset,
            'bands'   => $sanitized,
            'spatial' => $currentSpatial,
        ]);
    }

    private function applyToMpv(array $bands, string $spatial = 'off'): void
    {
        if (!file_exists(self::MPV_SOCKET)) {
            return;
        }

        // EQ filters
        $filters = [];
        foreach ($bands as $freq => $gain) {
            $f = (int) $freq;
            $g = (float) $gain;
            $filters[] = "equalizer=f={$f}:t=o:w=1:g={$g}";
        }

        // Spatial filters
        if ($spatial === 'stereo_wide') {
            $filters[] = 'stereowiden=delay=20:feedback=0.3:crossfeed=0.3:drymix=0.8';
        } elseif ($spatial === 'surround') {
            $filters[] = 'haas=level_in=1:level_out=1:side_gain=0.4:middle_source=mid:middle_phase=false';
            $filters[] = 'extrastereo=m=1.5';
        } elseif ($spatial === 'crossfeed') {
            $filters[] = 'crossfeed=strength=0.7:range=8000:level_in=1:level_out=1';
        }

        $cmd = json_encode(['command' => ['af', 'set', implode(',', $filters)]]);
        $sock = @stream_socket_client('unix://' . self::MPV_SOCKET, $errno, $errstr, 2);
        if ($sock) {
            @fwrite($sock, $cmd . "\n");
            @fclose($sock);
        }
    }

    private function presetToBands(string $preset): array
    {
        $values = self::PRESETS[$preset] ?? self::PRESETS['flat'];
        $bands = [];
        foreach (self::FREQS as $i => $freq) {
            $bands[(string) $freq] = $values[$i] ?? 0;
        }
        return $bands;
    }

    private function getSetting(PDO $db, string $key, string $default): string
    {
        $stmt = $db->prepare('SELECT value FROM settings WHERE key = :key');
        $stmt->execute(['key' => $key]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (string) $val : $default;
    }
}
