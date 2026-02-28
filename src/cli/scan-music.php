<?php

declare(strict_types=1);

/**
 * RendezVox — Music Directory Scanner
 *
 * Scans /var/lib/rendezvox/music for audio files and imports them into the database.
 * Uses MetadataExtractor for ffprobe tag extraction + filename fallback.
 *
 * Usage:
 *   php scan-music.php [--dry-run]
 *
 * Lock file prevents overlapping cron runs.
 */

// -- Bootstrap (outside web context) --
require __DIR__ . '/../core/Database.php';
require __DIR__ . '/../core/MetadataExtractor.php';
require __DIR__ . '/../core/MetadataLookup.php';
require __DIR__ . '/../core/ArtistNormalizer.php';

// -- Configuration --
$musicDir   = '/var/lib/rendezvox/music';
$lockFile   = '/tmp/scan-music.lock';
$extensions = ['mp3', 'flac', 'ogg', 'wav', 'aac', 'm4a'];
$dryRun     = in_array('--dry-run', $argv ?? []);
$defaultCat = (int) (getenv('RENDEZVOX_DEFAULT_CATEGORY_ID') ?: 1);

// -- Lock file --
if (file_exists($lockFile)) {
    $lockPid = (int) file_get_contents($lockFile);
    if ($lockPid > 0 && file_exists("/proc/{$lockPid}")) {
        log_msg("Another scan is running (PID {$lockPid}), exiting.");
        exit(0);
    }
    // Stale lock
    unlink($lockFile);
}
file_put_contents($lockFile, (string) getmypid());
register_shutdown_function(function () use ($lockFile) {
    @unlink($lockFile);
});

// -- Validate music directory --
if (!is_dir($musicDir)) {
    log_msg("Music directory not found: {$musicDir}");
    exit(1);
}

// -- Connect to DB --
$db = Database::get();

// -- Collect existing file paths and hashes --
$existingPaths  = [];
$existingHashes = [];

$stmt = $db->query('SELECT id, file_path, file_hash FROM songs');
while ($row = $stmt->fetch()) {
    $existingPaths[$row['file_path']] = true;
    if ($row['file_hash'] && !isset($existingHashes[$row['file_hash']])) {
        $existingHashes[$row['file_hash']] = (int) $row['id'];
    }
}

// -- Scan files --
$imported = 0;
$skipped  = 0;
$errors   = 0;

$dirIterator = new RecursiveDirectoryIterator($musicDir, RecursiveDirectoryIterator::SKIP_DOTS);

$filterIterator = new RecursiveCallbackFilterIterator(
    $dirIterator,
    function ($current) {
        if ($current->isDir()) {
            $name = $current->getFilename();
            // Skip hidden directories, organizer-managed directories, and upload staging
            if (str_starts_with($name, '.')) return false;
            return !in_array($name, ['tagged', 'untagged']);
        }
        return true;
    }
);

$iterator = new RecursiveIteratorIterator($filterIterator);

foreach ($iterator as $fileInfo) {
    if (!$fileInfo->isFile()) continue;

    $ext = strtolower($fileInfo->getExtension());
    if (!in_array($ext, $extensions)) continue;

    // Skip temp files left by tag writer (e.g. "song.mp3.tmp.mp3")
    if (str_contains($fileInfo->getFilename(), '.tmp.')) continue;

    $absolutePath = $fileInfo->getRealPath();
    $relativePath = ltrim(str_replace($musicDir, '', $absolutePath), '/');

    // Skip if path already in DB
    if (isset($existingPaths[$relativePath])) {
        $skipped++;
        continue;
    }

    // Compute hash and detect duplicates
    $hash = hash_file('sha256', $absolutePath);
    $canonicalId = $existingHashes[$hash] ?? null;

    // Extract metadata
    $meta = MetadataExtractor::extract($absolutePath);

    if ($meta['duration_ms'] <= 0) {
        log_msg("ERROR (no duration): {$relativePath}");
        $errors++;
        continue;
    }

    $title  = $meta['title'] ?: pathinfo($fileInfo->getFilename(), PATHINFO_FILENAME);
    $artist = $meta['artist'];

    if ($dryRun) {
        log_msg("DRY-RUN: {$relativePath} → \"{$title}\" by \"{$artist}\" ({$meta['duration_ms']}ms)");
        $imported++;
        continue;
    }

    // Find or create artist
    $artistId = findOrCreateArtist($db, $artist ?: 'Unknown Artist');

    // Detect embedded cover art
    $hasCoverArt = MetadataLookup::hasCoverArt($absolutePath);

    // Insert song
    try {
        $stmt2 = $db->prepare('
            INSERT INTO songs (title, artist_id, category_id, file_path, file_hash,
                               duration_ms, year, has_cover_art, duplicate_of)
            VALUES (:title, :artist_id, :category_id, :file_path, :file_hash,
                    :duration_ms, :year, :has_cover_art, :duplicate_of)
            RETURNING id
        ');
        $stmt2->execute([
            'title'         => $title,
            'artist_id'     => $artistId,
            'category_id'   => $defaultCat,
            'file_path'     => $relativePath,
            'file_hash'     => $hash,
            'duration_ms'   => $meta['duration_ms'],
            'year'          => $meta['year'] ?: null,
            'has_cover_art' => $hasCoverArt ? 'true' : 'false',
            'duplicate_of'  => $canonicalId,
        ]);

        $newId = (int) $stmt2->fetchColumn();
        $existingPaths[$relativePath] = true;
        if (!isset($existingHashes[$hash])) {
            $existingHashes[$hash] = $newId;
        }
        $imported++;
        $dupLabel = $canonicalId !== null ? " (dup of #{$canonicalId})" : '';
        log_msg("IMPORTED{$dupLabel}: {$relativePath} → \"{$title}\" by \"{$artist}\"");
    } catch (\PDOException $e) {
        log_msg("ERROR: {$relativePath} — " . $e->getMessage());
        $errors++;
    }
}

$prefix = $dryRun ? '[DRY-RUN] ' : '';
log_msg("{$prefix}Scan complete: {$imported} imported, {$skipped} skipped, {$errors} errors.");

// -- Helpers --

function findOrCreateArtist(PDO $db, string $name): int
{
    $name = ArtistNormalizer::extractPrimary($name, $db);
    $normalized = mb_strtolower(trim($name));

    $stmt = $db->prepare('SELECT id FROM artists WHERE normalized_name = :norm');
    $stmt->execute(['norm' => $normalized]);
    $row = $stmt->fetch();

    if ($row) {
        return (int) $row['id'];
    }

    $stmt = $db->prepare('
        INSERT INTO artists (name, normalized_name)
        VALUES (:name, :norm)
        RETURNING id
    ');
    $stmt->execute(['name' => trim($name), 'norm' => $normalized]);
    return (int) $stmt->fetchColumn();
}

function log_msg(string $msg): void
{
    $ts = date('Y-m-d H:i:s');
    echo "[{$ts}] {$msg}\n";
}
