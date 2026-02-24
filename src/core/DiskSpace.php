<?php

declare(strict_types=1);

class DiskSpace
{
    private const MUSIC_DIR = '/var/lib/iradio/music';
    private const DEFAULT_RESERVED_GB = 2;

    /**
     * Check available disk space on the music volume.
     *
     * @return array{free_bytes: int, reserved_bytes: int, usable_bytes: int, total_bytes: int}
     */
    public static function check(): array
    {
        $free  = (int) disk_free_space(self::MUSIC_DIR);
        $total = (int) disk_total_space(self::MUSIC_DIR);

        $reservedGb = self::DEFAULT_RESERVED_GB;
        try {
            $db   = Database::get();
            $stmt = $db->prepare("SELECT value FROM settings WHERE key = :key");
            $stmt->execute(['key' => 'disk_reserved_gb']);
            $val = $stmt->fetchColumn();
            if ($val !== false && is_numeric($val)) {
                $reservedGb = max(0, (float) $val);
            }
        } catch (\Throwable $e) {
            // Fall back to default if DB unavailable
        }

        $reserved = (int) ($reservedGb * 1073741824); // GB -> bytes
        $usable   = max(0, $free - $reserved);

        return [
            'free_bytes'     => $free,
            'reserved_bytes' => $reserved,
            'usable_bytes'   => $usable,
            'total_bytes'    => $total,
        ];
    }

    /**
     * Verify that $neededBytes can fit in usable disk space.
     * Returns null if OK, or an error message string if insufficient.
     */
    public static function requireSpace(int $neededBytes): ?string
    {
        $info = self::check();
        if ($neededBytes > $info['usable_bytes']) {
            $freeHuman   = self::formatBytes($info['free_bytes']);
            $reserveHuman = self::formatBytes($info['reserved_bytes']);
            $usableHuman = self::formatBytes($info['usable_bytes']);
            $neededHuman = self::formatBytes($neededBytes);
            return "Insufficient disk space. Need {$neededHuman}, only {$usableHuman} available ({$freeHuman} free, {$reserveHuman} reserved)";
        }
        return null;
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
        if ($bytes < 1073741824) return round($bytes / 1048576, 1) . ' MB';
        return round($bytes / 1073741824, 1) . ' GB';
    }
}
