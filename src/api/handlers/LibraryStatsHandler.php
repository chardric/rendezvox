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

        // Artists with clear collaboration tags (feat., ft., featuring)
        // Only counts "always-split" separators that the dedup script will
        // definitely act on.  Excludes &, and, comma, x — those use smart
        // splitting and often belong to legitimate artist names.
        $stmt = $db->query("
            SELECT COUNT(*) FROM artists
            WHERE is_active = true
              AND (name ~* '\\y(feat\\.?|ft\\.?|featuring)\\y')
        ");
        $dupArtists = (int) $stmt->fetchColumn();

        // Active songs with missing files on disk
        $missingFiles = 0;
        $stmt = $db->query("
            SELECT file_path FROM songs
            WHERE is_active = true AND trashed_at IS NULL AND file_path IS NOT NULL
        ");
        while ($row = $stmt->fetch()) {
            $path = $row['file_path'];
            // Resolve relative paths against the music directory
            if ($path !== '' && $path[0] !== '/') {
                $path = self::MUSIC_DIR . '/' . $path;
            }
            if (!file_exists($path)) {
                $missingFiles++;
            }
        }

        // Disk free space for low-disk-space notice
        $diskSpace = DiskSpace::check();

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
            'disk_free_bytes'    => $diskSpace['free_bytes'],
            'disk_reserved_bytes'=> $diskSpace['reserved_bytes'],
            'disk_usable_bytes'  => $diskSpace['usable_bytes'],
            'disk_total_bytes'   => $diskSpace['total_bytes'],
            'unnormalized'       => $unnormalized,
            'untagged'           => $untagged,
            'dup_artists'        => $dupArtists,
            'missing_files'      => $missingFiles,
            'active_playlists'   => $playlistCount,
            'active_schedules'   => $scheduleCount,
        ]);
    }
}
