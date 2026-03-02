<?php
/**
 * Re-fetch Cover Art — runs in background, re-downloads cover art using improved algorithm.
 *
 * Usage:
 *   php refetch_covers.php              — re-fetch for all songs (replaces existing covers)
 *   php refetch_covers.php --missing    — only songs without cover art
 *
 * Priority: Single release > EP > Album > first available release
 * Fallback: Cover Art Archive → TheAudioDB track/album → TheAudioDB artist photo → App logo
 *
 * Progress is written to /tmp/rendezvox_cover_rescan.json so the admin UI
 * can poll for status.
 */

declare(strict_types=1);

require __DIR__ . '/../core/Database.php';
require __DIR__ . '/../core/MetadataLookup.php';

$missingOnly = in_array('--missing', $argv ?? []);

$db = Database::get();

// ── Lock file — prevent concurrent runs ─────────────────
$lockFile = '/tmp/rendezvox_cover_rescan.lock';
if (file_exists($lockFile)) {
    $pid = (int) @file_get_contents($lockFile);
    if ($pid > 0 && file_exists("/proc/{$pid}")) {
        exit(0);
    }
    @unlink($lockFile);
}
file_put_contents($lockFile, (string) getmypid());
@chmod($lockFile, 0666);

// ── Progress tracking ───────────────────────────────────
$progress = [
    'status'     => 'running',
    'total'      => 0,
    'processed'  => 0,
    'updated'    => 0,
    'skipped'    => 0,
    'started_at' => date('c'),
];

function writeProgress(array &$progress): void
{
    $file = '/tmp/rendezvox_cover_rescan.json';
    @file_put_contents($file, json_encode($progress), LOCK_EX);
    @chmod($file, 0666);
}

// ── Initialize MetadataLookup ────────────────────────────
$lookup = new MetadataLookup();

$stmt = $db->prepare("SELECT key, value FROM settings WHERE key IN ('acoustid_api_key', 'theaudiodb_api_key')");
$stmt->execute();
while ($row = $stmt->fetch()) {
    if ($row['key'] === 'acoustid_api_key' && !empty(trim($row['value']))) {
        $lookup->setAcoustIdKey(trim($row['value']));
    }
    if ($row['key'] === 'theaudiodb_api_key' && !empty(trim($row['value']))) {
        $lookup->setTheAudioDbKey(trim($row['value']));
    }
}

$musicDir = '/var/lib/rendezvox/music';

// ── Get songs ────────────────────────────────────────────
$where = $missingOnly ? 'WHERE s.has_cover_art = FALSE' : '';
$stmt = $db->query("
    SELECT s.id, s.title, s.file_path, s.has_cover_art,
           a.name AS artist_name
    FROM songs s
    JOIN artists a ON a.id = s.artist_id
    {$where}
    ORDER BY a.name, s.id
");
$allSongs = $stmt->fetchAll();

$progress['total'] = count($allSongs);
writeProgress($progress);

if (count($allSongs) === 0) {
    $progress['status']      = 'done';
    $progress['finished_at'] = date('c');
    writeProgress($progress);
    @unlink($lockFile);
    exit(0);
}

$stopFile = $lockFile . '.stop';

foreach ($allSongs as $song) {
    // Check for stop signal
    if (file_exists($stopFile)) {
        @unlink($stopFile);
        $progress['status']      = 'stopped';
        $progress['finished_at'] = date('c');
        writeProgress($progress);
        @unlink($lockFile);
        exit(0);
    }

    $songId     = (int) $song['id'];
    $artistName = $song['artist_name'];
    $title      = $song['title'];
    $filePath   = $musicDir . '/' . $song['file_path'];

    if (!file_exists($filePath)) {
        $progress['skipped']++;
        $progress['processed']++;
        writeProgress($progress);
        continue;
    }

    // Look up release ID via MusicBrainz (uses improved Single > EP > Album priority)
    $releaseId = null;
    if (!empty(trim($artistName)) && !empty(trim($title))) {
        $mbResult = $lookup->lookupByArtistTitle($artistName, $title);
        $releaseId = $mbResult['release_id'] ?? null;
    }

    // Fetch cover art via full fallback chain
    $imageData = $lookup->lookupCoverArt($artistName, $title, $releaseId);

    if ($imageData) {
        if (MetadataLookup::embedCoverArt($filePath, $imageData)) {
            $db->prepare("UPDATE songs SET has_cover_art = TRUE WHERE id = :id")
               ->execute(['id' => $songId]);

            // Update file_hash since file content changed
            $newHash = hash_file('sha256', $filePath);
            $db->prepare("UPDATE songs SET file_hash = :hash WHERE id = :id")
               ->execute(['hash' => $newHash, 'id' => $songId]);

            // Clear cached cover so CoverArtHandler serves the new one
            $cachePath = '/tmp/rendezvox_covers/' . $songId . '.jpg';
            @unlink($cachePath);

            $progress['updated']++;
        } else {
            $progress['skipped']++;
        }
    } else {
        $progress['skipped']++;
    }

    $progress['processed']++;
    writeProgress($progress);
}

$progress['status']      = 'done';
$progress['finished_at'] = date('c');
writeProgress($progress);
@unlink($lockFile);
@unlink($stopFile);
