<?php

declare(strict_types=1);

/**
 * iRadio — Media Library Auto-Organizer
 *
 * Long-running CLI process that watches /var/lib/iradio/music/upload/ for new
 * audio files, validates metadata, detects duplicates, organizes files into
 * Genre/Artist/Title.ext structure, and imports them into the database.
 *
 * Files with missing required metadata are quarantined to _quarantine/.
 * Duplicate files (by SHA-256) are moved to _duplicates/.
 *
 * Usage:
 *   php media-organizer.php [--dry-run] [--once]
 *
 * Run inside the PHP container:
 *   docker exec -d iradio-php php /var/www/html/src/cli/media-organizer.php
 */

// -- Bootstrap (outside web context) --
require __DIR__ . '/../core/Database.php';
require __DIR__ . '/../core/MetadataExtractor.php';
require __DIR__ . '/../core/ArtistNormalizer.php';

// -- Configuration --
$musicDir       = '/var/lib/iradio/music';
$uploadDir      = $musicDir . '/upload';
$lockFile       = '/tmp/media-organizer.lock';
$stopFile       = '/tmp/media-organizer.lock.stop';
$progressFile   = '/tmp/iradio_media_organizer.json';
$extensions     = ['mp3', 'flac', 'm4a', 'ogg', 'wav'];
$quarantineDir  = '_quarantine';
$duplicatesDir  = '_duplicates';
$stabilityDelay = 3;        // seconds — file mtime must be this old

$dryRun = in_array('--dry-run', $argv ?? []);
$once   = in_array('--once', $argv ?? []);

// -- Lock file (PID-based with command verification) --
if (file_exists($lockFile)) {
    $lockPid = (int) file_get_contents($lockFile);
    if ($lockPid > 0 && file_exists("/proc/{$lockPid}")) {
        // Verify the PID is actually a media-organizer process, not a recycled PID
        $cmdline = @file_get_contents("/proc/{$lockPid}/cmdline");
        if ($cmdline !== false && str_contains($cmdline, 'media-organizer')) {
            log_msg("Another instance is running (PID {$lockPid}), exiting.");
            exit(0);
        }
    }
    @unlink($lockFile);
}
file_put_contents($lockFile, (string) getmypid());
register_shutdown_function(function () use ($lockFile, $progressFile) {
    @unlink($lockFile);
    @unlink($progressFile);
});

// -- Stop-file signal handler --
function shouldStop(string $stopFile): bool
{
    if (file_exists($stopFile)) {
        @unlink($stopFile);
        return true;
    }
    return false;
}

// -- Validate directories --
if (!is_dir($musicDir)) {
    log_msg("Music directory not found: {$musicDir}");
    exit(1);
}
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0775, true)) {
        log_msg("Cannot create upload directory: {$uploadDir}");
        exit(1);
    }
}

// -- Connect to DB --
$db = Database::get();

// -- Read poll interval from settings --
function getPollInterval(PDO $db): int
{
    $stmt = $db->prepare('SELECT value FROM settings WHERE key = :key');
    $stmt->execute(['key' => 'organizer_poll_secs']);
    $val = $stmt->fetchColumn();
    $secs = $val !== false ? (int) $val : 3;
    return max(1, $secs);
}

function isOrganizerEnabled(PDO $db): bool
{
    $stmt = $db->prepare('SELECT value FROM settings WHERE key = :key');
    $stmt->execute(['key' => 'organizer_enabled']);
    $val = $stmt->fetchColumn();
    return $val === 'true';
}

// -- Load known hashes for dedup --
function loadKnownHashes(PDO $db): array
{
    $hashes = [];
    $stmt = $db->query('SELECT file_hash FROM songs WHERE file_hash IS NOT NULL');
    while ($row = $stmt->fetch()) {
        $hashes[$row['file_hash']] = true;
    }
    $stmt = $db->query('SELECT file_hash FROM organizer_hashes');
    while ($row = $stmt->fetch()) {
        $hashes[$row['file_hash']] = true;
    }
    return $hashes;
}

// -- Path sanitization --
function sanitizeSegment(string $segment, int $maxLen = 100): string
{
    $segment = preg_replace('/[\/\\\\:*?"<>|\x00-\x1F]/', '', $segment);
    $segment = trim($segment, ". \t\n\r");
    if ($segment === '') {
        $segment = 'Unknown';
    }
    if (mb_strlen($segment) > $maxLen) {
        $segment = mb_substr($segment, 0, $maxLen);
        $segment = rtrim($segment, ". \t");
    }
    return $segment;
}

function sanitizeFilename(string $name, int $maxLen = 255): string
{
    $name = preg_replace('/[\/\\\\:*?"<>|\x00-\x1F]/', '', $name);
    $name = trim($name, ". \t\n\r");
    if ($name === '') {
        $name = 'Unknown';
    }
    if (mb_strlen($name) > $maxLen) {
        $name = mb_substr($name, 0, $maxLen);
        $name = rtrim($name, ". \t");
    }
    return $name;
}

// -- Extract track number from filename --
function extractTrackNumber(string $filename): ?string
{
    if (preg_match('/^(\d{1,3})[\s.\-]+/', $filename, $m)) {
        return str_pad($m[1], 2, '0', STR_PAD_LEFT);
    }
    return null;
}

// -- Resolve filename conflicts --
function resolveConflict(string $destPath): string
{
    if (!file_exists($destPath)) {
        return $destPath;
    }

    $dir  = dirname($destPath);
    $ext  = pathinfo($destPath, PATHINFO_EXTENSION);
    $base = pathinfo($destPath, PATHINFO_FILENAME);

    for ($i = 2; $i <= 999; $i++) {
        $candidate = "{$dir}/{$base} ({$i}).{$ext}";
        if (!file_exists($candidate)) {
            return $candidate;
        }
    }

    return "{$dir}/{$base} (" . bin2hex(random_bytes(4)) . ").{$ext}";
}

// -- Set ownership on new directories so www-data (PHP-FPM) can manage files --
function chownRecursive(string $dir): void
{
    $musicDir = '/var/lib/iradio/music';
    $path = $dir;
    // Walk up and chown each new directory up to (but not including) music root
    while ($path !== $musicDir && $path !== dirname($musicDir)) {
        @chmod($path, 0775);
        @chown($path, 'www-data');
        @chgrp($path, 'www-data');
        $path = dirname($path);
    }
}

// -- Move file with directory creation --
function moveFile(string $src, string $dest, bool $dryRun): bool
{
    if ($dryRun) {
        log_msg("  DRY-RUN: would move -> {$dest}");
        return true;
    }

    $destDir = dirname($dest);
    if (!is_dir($destDir)) {
        if (!mkdir($destDir, 0775, true)) {
            log_msg("  ERROR: Cannot create directory {$destDir}");
            return false;
        }
        // Ensure www-data can manage files in new directories
        chownRecursive($destDir);
    }

    if (!rename($src, $dest)) {
        log_msg("  ERROR: rename() failed: {$src} -> {$dest}");
        return false;
    }

    // Ensure www-data can delete the file (purge handler runs as www-data)
    @chmod($dest, 0664);
    @chown($dest, 'www-data');
    @chgrp($dest, 'www-data');

    return true;
}

// -- Write quarantine info sidecar --
function writeInfoFile(string $filePath, string $reason, bool $dryRun): void
{
    if ($dryRun) return;

    $infoPath = $filePath . '.info.txt';
    $content  = "Quarantined: " . date('Y-m-d H:i:s') . "\n"
              . "Reason: {$reason}\n";
    file_put_contents($infoPath, $content);
}

// -- Write progress JSON --
function writeProgress(string $file, array $stats): void
{
    $stats['updated_at'] = date('c');
    file_put_contents($file, json_encode($stats, JSON_PRETTY_PRINT));
}

// -- Check file stability (not still being written) --
function isFileStable(string $path, int $stabilityDelay): bool
{
    $mtime = filemtime($path);
    if ($mtime === false) return false;

    $age = time() - $mtime;

    if ($age < $stabilityDelay) {
        return false;
    }

    // For files modified recently (within 30s), do a size-recheck
    if ($age < 30) {
        $size1 = filesize($path);
        usleep(500000); // 0.5s
        clearstatcache(true, $path);
        $size2 = filesize($path);
        return $size1 === $size2 && $size1 > 0;
    }

    return filesize($path) > 0;
}

// -- Scan upload directory for audio files --
function scanUploadDir(string $uploadDir, array $extensions): array
{
    $files = [];

    if (!is_dir($uploadDir)) {
        return $files;
    }

    $dirIterator = new RecursiveDirectoryIterator(
        $uploadDir,
        RecursiveDirectoryIterator::SKIP_DOTS
    );

    $filterIterator = new RecursiveCallbackFilterIterator(
        $dirIterator,
        function ($current) {
            $name = $current->getFilename();
            // Skip hidden files/dirs
            if (str_starts_with($name, '.')) return false;
            return true;
        }
    );

    $iterator = new RecursiveIteratorIterator($filterIterator);

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile()) continue;

        $ext = strtolower($fileInfo->getExtension());
        if (!in_array($ext, $extensions)) continue;

        // Skip temp files
        if (str_contains($fileInfo->getFilename(), '.tmp.')) continue;

        $files[] = $fileInfo->getRealPath();
    }

    return $files;
}

// -- Register hash in organizer_hashes --
function registerHash(PDO $db, string $hash, string $path): void
{
    $stmt = $db->prepare('
        INSERT INTO organizer_hashes (file_hash, absolute_path)
        VALUES (:hash, :path)
        ON CONFLICT (file_hash) DO UPDATE SET absolute_path = :path2
    ');
    $stmt->execute(['hash' => $hash, 'path' => $path, 'path2' => $path]);
}

// -- Find or create artist --
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

    $stmt = $db->prepare('INSERT INTO artists (name, normalized_name) VALUES (:name, :norm) RETURNING id');
    $stmt->execute(['name' => trim($name), 'norm' => $normalized]);
    return (int) $stmt->fetchColumn();
}

// -- Find or create category --
function findOrCreateCategory(PDO $db, string $name): int
{
    $stmt = $db->prepare('SELECT id FROM categories WHERE LOWER(name) = LOWER(:name)');
    $stmt->execute(['name' => trim($name)]);
    $row = $stmt->fetch();
    if ($row) {
        return (int) $row['id'];
    }

    $stmt = $db->prepare("INSERT INTO categories (name, type) VALUES (:name, 'music') RETURNING id");
    $stmt->execute(['name' => trim($name)]);
    return (int) $stmt->fetchColumn();
}

// -- Import song into database --
function importSong(PDO $db, string $destPath, string $musicDir, array $meta, string $hash, bool $dryRun, array $sidecar = []): ?int
{
    if ($dryRun) {
        log_msg("  DRY-RUN: would import to DB");
        return null;
    }

    $relativePath = ltrim(str_replace($musicDir, '', $destPath), '/');
    $title   = $meta['title'];
    $artist  = $meta['artist'] ?: 'Unknown Artist';
    $genre   = $meta['genre'];
    $year    = $meta['year'] ?: null;

    // Use sidecar IDs directly if provided, otherwise look up by name
    $sidecarCategoryId = (int) ($sidecar['category_id'] ?? 0);
    $sidecarArtistId   = (int) ($sidecar['artist_id'] ?? 0);

    $artistId   = $sidecarArtistId > 0   ? $sidecarArtistId   : findOrCreateArtist($db, $artist);
    $categoryId = $sidecarCategoryId > 0 ? $sidecarCategoryId : findOrCreateCategory($db, $genre);

    $stmt = $db->prepare('
        INSERT INTO songs (title, artist_id, category_id, file_path, file_hash,
                           duration_ms, year)
        VALUES (:title, :artist_id, :category_id, :file_path, :file_hash,
                :duration_ms, :year)
        RETURNING id
    ');
    $stmt->execute([
        'title'       => $title,
        'artist_id'   => $artistId,
        'category_id' => $categoryId,
        'file_path'   => $relativePath,
        'file_hash'   => $hash,
        'duration_ms' => $meta['duration_ms'],
        'year'        => $year,
    ]);

    return (int) $stmt->fetchColumn();
}

// -- Process a single file from the upload directory --
function processFile(
    PDO    $db,
    string $absolutePath,
    string $musicDir,
    string $quarantineDir,
    string $duplicatesDir,
    array  &$knownHashes,
    bool   $dryRun
): array {
    $filename = basename($absolutePath);
    $ext      = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));

    log_msg("Processing: {$filename}");

    // -- Step A: SHA-256 duplicate check --
    $hash = hash_file('sha256', $absolutePath);
    if ($hash === false) {
        return ['status' => 'failed', 'action' => null, 'error' => 'Cannot compute hash'];
    }

    // Check songs table
    $stmt = $db->prepare('SELECT file_path FROM songs WHERE file_hash = :hash LIMIT 1');
    $stmt->execute(['hash' => $hash]);
    $existingSong = $stmt->fetchColumn();

    if ($existingSong !== false) {
        log_msg("  DUPLICATE (in DB as {$existingSong}) — deleting");
        if (!$dryRun) {
            @unlink($absolutePath);
            // Also remove sidecar if present
            $sidecarPath = dirname($absolutePath) . '/.' . basename($absolutePath) . '.meta';
            @unlink($sidecarPath);
        }
        return ['status' => 'done', 'action' => 'duplicate', 'path' => null, 'error' => null];
    }

    // Check organizer_hashes
    if (isset($knownHashes[$hash])) {
        log_msg("  DUPLICATE (hash already seen) — deleting");
        if (!$dryRun) {
            @unlink($absolutePath);
            $sidecarPath = dirname($absolutePath) . '/.' . basename($absolutePath) . '.meta';
            @unlink($sidecarPath);
        }
        return ['status' => 'done', 'action' => 'duplicate', 'path' => null, 'error' => null];
    }

    // -- Step B: Extract metadata + read sidecar overrides --
    $meta = MetadataExtractor::extract($absolutePath);

    // Read .meta sidecar (written by web upload)
    $sidecarPath = dirname($absolutePath) . '/.' . basename($absolutePath) . '.meta';
    $sidecar = [];
    if (file_exists($sidecarPath)) {
        $sidecarJson = @file_get_contents($sidecarPath);
        if ($sidecarJson !== false) {
            $sidecar = json_decode($sidecarJson, true) ?: [];
        }
        @unlink($sidecarPath);
        log_msg("  Sidecar: " . json_encode($sidecar));
    }

    // Apply sidecar overrides to metadata
    if (!empty($sidecar['title']) && trim($meta['title']) === '') {
        $meta['title'] = $sidecar['title'];
    }

    // Resolve sidecar category_id to genre name for folder path
    $sidecarCategoryId = (int) ($sidecar['category_id'] ?? 0);
    $sidecarArtistId   = (int) ($sidecar['artist_id'] ?? 0);

    if ($sidecarCategoryId > 0 && trim($meta['genre']) === '') {
        $catStmt = $db->prepare('SELECT name FROM categories WHERE id = :id');
        $catStmt->execute(['id' => $sidecarCategoryId]);
        $catName = $catStmt->fetchColumn();
        if ($catName !== false) {
            $meta['genre'] = $catName;
        }
    }

    if ($sidecarArtistId > 0 && trim($meta['artist']) === '') {
        $artStmt = $db->prepare('SELECT name FROM artists WHERE id = :id');
        $artStmt->execute(['id' => $sidecarArtistId]);
        $artName = $artStmt->fetchColumn();
        if ($artName !== false) {
            $meta['artist'] = $artName;
        }
    }

    $missing = [];

    if ($meta['duration_ms'] <= 0) {
        $missing[] = 'duration (invalid audio)';
    }

    if (trim($meta['genre']) === '' && $sidecarCategoryId <= 0) {
        $missing[] = 'genre';
    }

    if (trim($meta['artist']) === '' && $sidecarArtistId <= 0) {
        $missing[] = 'artist';
    }

    if (trim($meta['title']) === '') {
        $missing[] = 'title';
    }

    if (!empty($missing)) {
        $reason   = 'Missing: ' . implode(', ', $missing);
        $destPath = "{$musicDir}/{$quarantineDir}/{$filename}";
        $destPath = resolveConflict($destPath);

        log_msg("  QUARANTINE: {$reason}");
        if (moveFile($absolutePath, $destPath, $dryRun)) {
            writeInfoFile($destPath, $reason, $dryRun);
            return ['status' => 'done', 'action' => 'quarantined', 'path' => $destPath, 'error' => null];
        }
        return ['status' => 'failed', 'action' => 'quarantined', 'error' => 'Move failed'];
    }

    // -- Step C: Build destination path (Genre/Artist/Title.ext) --
    $genre  = sanitizeSegment(trim($meta['genre']));
    $artist = sanitizeSegment(trim($meta['artist']));
    $title  = sanitizeFilename(trim($meta['title']));

    $destFilename = "{$title}.{$ext}";

    // Cap total filename length at 255
    if (mb_strlen($destFilename) > 255) {
        $title = mb_substr($title, 0, max(1, 255 - mb_strlen($ext) - 1));
        $destFilename = "{$title}.{$ext}";
    }

    $destPath = "{$musicDir}/{$genre}/{$artist}/{$destFilename}";
    $destPath = resolveConflict($destPath);

    // -- Step D: Move, register hash, and import to DB --
    $destRelative = ltrim(str_replace($musicDir, '', $destPath), '/');
    log_msg("  ORGANIZE -> {$destRelative}");

    if (moveFile($absolutePath, $destPath, $dryRun)) {
        if (!$dryRun) {
            registerHash($db, $hash, $destPath);
        }
        $knownHashes[$hash] = true;

        // Import into songs table
        $songId = importSong($db, $destPath, $musicDir, $meta, $hash, $dryRun, $sidecar);
        if ($songId !== null) {
            log_msg("  IMPORTED -> song #{$songId}");
        }

        return ['status' => 'done', 'action' => 'organized', 'path' => $destPath, 'error' => null];
    }
    return ['status' => 'failed', 'action' => 'organized', 'error' => 'Move failed'];
}

// -- Clean up empty directories in upload/ --
function cleanEmptyDirs(string $uploadDir, bool $dryRun): void
{
    if (!is_dir($uploadDir)) return;

    $dirs = [];
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($uploadDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iter as $fileInfo) {
        if ($fileInfo->isDir()) {
            $dirs[] = $fileInfo->getRealPath();
        }
    }

    foreach ($dirs as $dir) {
        // Don't remove the upload dir itself
        if ($dir === realpath($uploadDir)) continue;

        $isEmpty = (count(scandir($dir)) === 2); // only . and ..
        if ($isEmpty) {
            if ($dryRun) {
                log_msg("  DRY-RUN: would remove empty dir {$dir}");
            } else {
                rmdir($dir);
            }
        }
    }
}

// -- Main --
$pollInterval = getPollInterval($db);
log_msg("Media organizer started — watching {$uploadDir} (poll every {$pollInterval}s"
    . ($dryRun ? ', DRY-RUN' : '') . ($once ? ', single-pass' : '') . ')');

// Initial load of known hashes
$knownHashes = loadKnownHashes($db);

$stats = [
    'started_at' => date('c'),
    'scanned'    => 0,
    'organized'  => 0,
    'quarantined'=> 0,
    'duplicates' => 0,
    'errors'     => 0,
    'last_scan'  => null,
];

while (true) {
    try {
        // Reconnect DB if connection was lost
        try {
            $db->query('SELECT 1');
        } catch (\Throwable $e) {
            log_msg('DB connection lost, reconnecting...');
            Database::reconnect();
            $db = Database::get();
        }

        // Check stop signal
        if (shouldStop($stopFile)) {
            log_msg('Stop signal received, shutting down.');
            break;
        }

        // Check if enabled (skip processing if disabled, but keep running)
        if (!isOrganizerEnabled($db)) {
            if ($once) {
                log_msg('Organizer is disabled in settings. Exiting (--once mode).');
                break;
            }
            sleep($pollInterval);
            continue;
        }

        // Refresh known hashes from DB before each scan
        $dbHashes = loadKnownHashes($db);
        if (count($dbHashes) !== count($knownHashes)) {
            log_msg('Hash cache refreshed (' . count($dbHashes) . ' hashes in DB).');
        }
        $knownHashes = $dbHashes;

        // -- STEP 1: SCAN upload directory --
        $audioFiles = scanUploadDir($uploadDir, $extensions);
        $stats['last_scan'] = date('c');

        // -- STEP 2: PROCESS each stable file --
        $processedThisCycle = 0;

        foreach ($audioFiles as $absPath) {
            if (!file_exists($absPath)) continue;

            if (!isFileStable($absPath, $stabilityDelay)) {
                continue; // Still being written, try next cycle
            }

            $stats['scanned']++;

            $result = processFile($db, $absPath, $musicDir, $quarantineDir, $duplicatesDir, $knownHashes, $dryRun);

            match ($result['action']) {
                'organized'   => $stats['organized']++,
                'quarantined' => $stats['quarantined']++,
                'duplicate'   => $stats['duplicates']++,
                default       => $stats['errors']++,
            };

            $processedThisCycle++;
        }

        // -- STEP 3: Clean empty directories in upload/ --
        if ($processedThisCycle > 0) {
            cleanEmptyDirs($uploadDir, $dryRun);
        }

        // Write progress
        writeProgress($progressFile, $stats);

        if ($processedThisCycle > 0) {
            log_msg("Cycle done: processed {$processedThisCycle} file(s). Totals: "
                . "{$stats['organized']} organized, {$stats['quarantined']} quarantined, "
                . "{$stats['duplicates']} duplicates, {$stats['errors']} errors.");
        }

    } catch (\Throwable $e) {
        log_msg('ERROR: ' . $e->getMessage());
        $stats['errors']++;
    }

    if ($once) {
        log_msg('Single pass complete (--once), exiting.');
        break;
    }

    // Re-read poll interval in case it changed
    try {
        $pollInterval = getPollInterval($db);
    } catch (\Throwable $e) {
        // Keep previous interval on DB error
    }

    sleep($pollInterval);
}

$prefix = $dryRun ? '[DRY-RUN] ' : '';
log_msg("{$prefix}Media organizer stopped. Final: "
    . "{$stats['organized']} organized, {$stats['quarantined']} quarantined, "
    . "{$stats['duplicates']} duplicates, {$stats['errors']} errors.");

// -- Helpers --

function log_msg(string $msg): void
{
    $ts = date('Y-m-d H:i:s');
    echo "[{$ts}] {$msg}\n";
}
