<?php

declare(strict_types=1);

/**
 * GET /api/manifest.json — Dynamic PWA manifest with station branding.
 */
class ManifestHandler
{
    public function handle(): void
    {
        $db = Database::get();

        $stmt = $db->query("SELECT key, value FROM settings WHERE key IN ('station_name', 'accent_color')");
        $settings = [];
        while ($row = $stmt->fetch()) {
            $settings[$row['key']] = $row['value'];
        }
        $name  = $settings['station_name'] ?? 'RendezVox';
        $accent = $settings['accent_color'] ?? '#ff7800';
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) {
            $accent = '#ff7800';
        }

        header('Content-Type: application/manifest+json');
        echo json_encode([
            'name'             => $name . ' — Online Radio',
            'short_name'       => $name,
            'description'      => 'Listen to ' . $name . ' live, request songs, and see what\'s playing now.',
            'start_url'        => '/',
            'display'          => 'standalone',
            'orientation'      => 'portrait',
            'theme_color'      => $accent,
            'background_color' => '#0a0a0c',
            'categories'       => ['music', 'entertainment'],
            'icons'            => [
                ['src' => '/assets/icon-48x48.png',   'sizes' => '48x48',   'type' => 'image/png'],
                ['src' => '/assets/icon-72x72.png',   'sizes' => '72x72',   'type' => 'image/png'],
                ['src' => '/assets/icon-96x96.png',   'sizes' => '96x96',   'type' => 'image/png'],
                ['src' => '/assets/icon-128x128.png', 'sizes' => '128x128', 'type' => 'image/png'],
                ['src' => '/assets/icon-144x144.png', 'sizes' => '144x144', 'type' => 'image/png'],
                ['src' => '/assets/icon-192x192.png', 'sizes' => '192x192', 'type' => 'image/png'],
                ['src' => '/assets/icon-384x384.png', 'sizes' => '384x384', 'type' => 'image/png'],
                ['src' => '/assets/icon-512x512.png', 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => '/assets/icon.svg',         'sizes' => 'any',     'type' => 'image/svg+xml'],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }
}
