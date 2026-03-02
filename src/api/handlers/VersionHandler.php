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

        $urlVersion = urlencode($version);

        Response::json([
            'version'   => $version,
            'changelog' => $changelog,
            'downloads' => [
                'android'       => "/installers/mobile/release/RendezVox-{$version}.apk",
                'deb_amd64'     => "/installers/desktop/linux/rendezvox_{$version}_amd64.deb",
                'deb_arm64'     => "/installers/desktop/linux/rendezvox_{$version}_arm64.deb",
                'appimage_x64'  => "/installers/desktop/linux/RendezVox-{$version}.AppImage",
                'appimage_arm64'=> "/installers/desktop/linux/RendezVox-{$version}-arm64.AppImage",
                'windows'       => "/installers/desktop/windows/RendezVox%20Setup%20{$urlVersion}.exe",
            ],
        ]);
    }
}
