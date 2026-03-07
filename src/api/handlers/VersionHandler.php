<?php

declare(strict_types=1);

/**
 * GET /api/version
 *
 * Public endpoint returning the current app version, changelog,
 * and download URLs for all platforms.
 */
class VersionHandler
{
    public function handle(): void
    {
        $db = Database::get();

        $stmt = $db->query("
            SELECT key, value FROM settings
            WHERE key IN ('app_version', 'app_changelog')
        ");

        $cfg = [];
        while ($row = $stmt->fetch()) {
            $cfg[$row['key']] = $row['value'];
        }

        $version   = $cfg['app_version']   ?? '1.0.0';
        $changelog = $cfg['app_changelog'] ?? '';

        $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?: '/var/www/html/public', '/');
        $base    = dirname($docRoot);  // /var/www/html

        Response::json([
            'version'   => $version,
            'changelog' => $changelog,
            'downloads' => [
                'android'        => self::findInstaller($base, '/installers/mobile/release',  'RendezVox-*.apk'),
                'deb_amd64'      => self::findInstaller($base, '/installers/desktop/linux',   'rendezvox_*_amd64.deb'),
                'deb_arm64'      => self::findInstaller($base, '/installers/desktop/linux',   'rendezvox_*_arm64.deb'),
                'appimage_x64'   => self::findInstaller($base, '/installers/desktop/linux',   'RendezVox-*.AppImage', '*-arm64*'),
                'appimage_arm64' => self::findInstaller($base, '/installers/desktop/linux',   'RendezVox-*-arm64.AppImage'),
                'windows'        => self::findInstaller($base, '/installers/desktop/windows', 'RendezVox*Setup*.exe'),
            ],
        ]);
    }

    /**
     * Find the latest installer file matching a glob pattern.
     * Returns the web-accessible path or null if not found.
     */
    private static function findInstaller(string $base, string $dir, string $pattern, ?string $exclude = null): ?string
    {
        $matches = glob("{$base}{$dir}/{$pattern}");
        if (!$matches) {
            return null;
        }
        if ($exclude) {
            $matches = array_filter($matches, fn($f) => !fnmatch($exclude, basename($f)));
            $matches = array_values($matches);
        }
        if (!$matches) {
            return null;
        }
        // Latest by modification time
        usort($matches, fn($a, $b) => filemtime($b) <=> filemtime($a));
        $path = str_replace($base, '', $matches[0]);
        // URL-encode path segments (spaces in filenames)
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }
}
