<?php

declare(strict_types=1);

class LibraryStatsHandler
{
    private const MUSIC_DIR = '/var/lib/iradio/music';

    public function handle(): void
    {
        $db = Database::get();

        // Song counts by status
        $stmt = $db->query("
            SELECT
                COUNT(*) FILTER (WHERE trashed_at IS NULL)                        AS total,
                COUNT(*) FILTER (WHERE is_active = true  AND trashed_at IS NULL)  AS active,
                COUNT(*) FILTER (WHERE is_active = false AND trashed_at IS NULL)  AS inactive,
                COUNT(*) FILTER (WHERE trashed_at IS NOT NULL)                    AS trashed,
                COALESCE(SUM(duration_ms) FILTER (WHERE trashed_at IS NULL), 0)   AS total_duration_ms,
                COALESCE(SUM(play_count) FILTER (WHERE trashed_at IS NULL), 0)    AS total_plays
            FROM songs
        ");
        $songStats = $stmt->fetch();

        // Artist count
        $stmt = $db->query("SELECT COUNT(*) FROM artists WHERE is_active = true");
        $artistCount = (int) $stmt->fetchColumn();

        // Genre/category count (music type only)
        $stmt = $db->query("SELECT COUNT(*) FROM categories WHERE type = 'music' AND is_active = true");
        $genreCount = (int) $stmt->fetchColumn();

        // Pending uploads
        $pendingCount = 0;
        $uploadDir = self::MUSIC_DIR . '/upload';
        if (is_dir($uploadDir)) {
            $extensions = ['mp3', 'flac', 'ogg', 'wav', 'aac', 'm4a'];
            $iter = new RecursiveIteratorIterator(
                new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator($uploadDir, RecursiveDirectoryIterator::SKIP_DOTS),
                    function ($current) {
                        return !str_starts_with($current->getFilename(), '.');
                    }
                )
            );
            foreach ($iter as $fileInfo) {
                if (!$fileInfo->isFile()) continue;
                $ext = strtolower($fileInfo->getExtension());
                if (in_array($ext, $extensions, true)) {
                    $pendingCount++;
                }
            }
        }

        // Disk usage (music directory, excluding upload)
        // BusyBox-compatible: find + stat instead of GNU du --exclude
        $diskBytes = 0;
        if (is_dir(self::MUSIC_DIR)) {
            $output = shell_exec(
                "find " . escapeshellarg(self::MUSIC_DIR) . " -not -path " .
                escapeshellarg(self::MUSIC_DIR . '/upload/*') .
                " -type f -exec stat -c '%s' {} + 2>/dev/null | awk '{s+=\$1} END {print s+0}'"
            );
            if ($output && preg_match('/^(\d+)/', trim($output), $m)) {
                $diskBytes = (int) $m[1];
            }
        }

        // Songs missing normalization
        $stmt = $db->query("
            SELECT COUNT(*) FROM songs
            WHERE is_active = true AND trashed_at IS NULL AND loudness_lufs IS NULL
        ");
        $unnormalized = (int) $stmt->fetchColumn();

        // Songs missing genre tag
        $stmt = $db->query("
            SELECT COUNT(*) FROM songs
            WHERE is_active = true AND trashed_at IS NULL
              AND (category_id IS NULL OR category_id = (SELECT id FROM categories WHERE name = 'Uncategorized' LIMIT 1))
        ");
        $untagged = (int) $stmt->fetchColumn();

        // Playlist and schedule counts
        $stmt = $db->query("SELECT COUNT(*) FROM playlists WHERE is_active = true");
        $playlistCount = (int) $stmt->fetchColumn();

        $stmt = $db->query("SELECT COUNT(*) FROM schedules WHERE is_active = true");
        $scheduleCount = (int) $stmt->fetchColumn();

        Response::json([
            'songs_total'        => (int) $songStats['total'],
            'songs_active'       => (int) $songStats['active'],
            'songs_inactive'     => (int) $songStats['inactive'],
            'songs_trashed'      => (int) $songStats['trashed'],
            'total_duration_ms'  => (int) $songStats['total_duration_ms'],
            'total_plays'        => (int) $songStats['total_plays'],
            'artists'            => $artistCount,
            'genres'             => $genreCount,
            'pending_imports'    => $pendingCount,
            'disk_bytes'         => $diskBytes,
            'unnormalized'       => $unnormalized,
            'untagged'           => $untagged,
            'active_playlists'   => $playlistCount,
            'active_schedules'   => $scheduleCount,
        ]);
    }
}
