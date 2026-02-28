<?php

declare(strict_types=1);

/**
 * RendezVox — Rename Tagged Files & Isolate Untagged
 *
 * Scans tagged/folders/ and processes audio files:
 *
 *   1. Has artist + title tags → rename to "Artist - Title.ext", stays in place
 *   2. Has title only (no artist) → rename to "Title.ext", stays in place
 *   3. No usable tags at all → move to /music/Untagged/ for manual tagging
 *
 * Updates songs.file_path in DB after every rename/move.
 *
 * Usage:
 *   php rename_tagged.php [--dry-run]
 */

require __DIR__ . '/../core/Database.php';
require __DIR__ . '/../core/MetadataExtractor.php';

$MUSIC_DIR    = '/var/lib/rendezvox/music';
$SCAN_DIR     = $MUSIC_DIR . '/tagged/folders';
$UNTAGGED_DIR = $MUSIC_DIR . '/Untagged';
$AUDIO_EXTS   = ['mp3', 'flac', 'ogg', 'wav', 'aac', 'm4a', 'wma', 'opus', 'aiff'];
$dryRun       = in_array('--dry-run', $argv ?? []);

function log_msg(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
}

function sanitizeSegment(string $segment, int $maxLen = 100): string
{
    $segment = preg_replace('/[\/\\\\:*?"<>|\x00-\x1F]/', '', $segment);
    $segment = trim($segment, ". \t\n\r");
    if ($segment === '') $segment = 'Unknown';
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
    if ($name === '') $name = 'Unknown';
    if (mb_strlen($name) > $maxLen) {
        $name = mb_substr($name, 0, $maxLen);
        $name = rtrim($name, ". \t");
    }
    return $name;
}

function resolveConflict(string $path): string
{
    if (!file_exists($path)) return $path;
    $dir  = dirname($path);
    $ext  = pathinfo($path, PATHINFO_EXTENSION);
    $base = pathinfo($path, PATHINFO_FILENAME);
    $i = 2;
    do {
        $path = $dir . '/' . $base . " ({$i})." . $ext;
        $i++;
    } while (file_exists($path));
    return $path;
}

function updateDb(\PDO $db, string $musicDir, string $oldAbs, string $newAbs): int
{
    $oldRel   = ltrim(str_replace($musicDir . '/', '', $oldAbs), '/');
    $newRel   = ltrim(str_replace($musicDir . '/', '', $newAbs), '/');
    $oldSlash = '/' . $oldRel;

    $stmt = $db->prepare(
        'UPDATE songs SET file_path = :new_rel
          WHERE file_path = :old_abs
             OR file_path = :old_rel
             OR file_path = :old_slash'
    );
    $stmt->execute([
        'new_rel'   => $newRel,
        'old_abs'   => $oldAbs,
        'old_rel'   => $oldRel,
        'old_slash' => $oldSlash,
    ]);
    return $stmt->rowCount();
}

if (!is_dir($SCAN_DIR)) {
    log_msg("ERROR: {$SCAN_DIR} does not exist.");
    exit(1);
}

$db = Database::get();

// Ensure Untagged directory exists
if (!is_dir($UNTAGGED_DIR) && !$dryRun) {
    mkdir($UNTAGGED_DIR, 0775, true);
    @chown($UNTAGGED_DIR, 'www-data');
    @chgrp($UNTAGGED_DIR, 'www-data');
}

// Collect all audio files
$files = [];
$rii = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($SCAN_DIR, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);

foreach ($rii as $file) {
    if (!$file->isFile()) continue;
    $ext = strtolower($file->getExtension());
    if (!in_array($ext, $AUDIO_EXTS)) continue;
    $files[] = $file->getPathname();
}

$total     = count($files);
$renamed   = 0;
$moved     = 0;
$failed    = 0;
$already   = 0;

log_msg("Found {$total} audio files in tagged/folders/");
if ($dryRun) log_msg("DRY-RUN mode — no changes will be made");

foreach ($files as $absPath) {
    $dir  = dirname($absPath);
    $ext  = strtolower(pathinfo($absPath, PATHINFO_EXTENSION));
    $base = pathinfo($absPath, PATHINFO_FILENAME);
    $currentFilename = basename($absPath);

    // Extract metadata from embedded file tags (uses ffprobe)
    $meta = MetadataExtractor::extract($absPath);
    $artist = trim($meta['artist'] ?? '');
    $title  = trim($meta['title'] ?? '');

    // ── No usable tags → move to Untagged/ ──
    if ($artist === '' && $title === '') {
        $destPath = resolveConflict($UNTAGGED_DIR . '/' . $currentFilename);
        $destRel  = ltrim(str_replace($MUSIC_DIR . '/', '', $destPath), '/');

        if ($dryRun) {
            log_msg("  UNTAGGED: {$currentFilename} -> {$destRel}");
            $moved++;
            continue;
        }

        if (rename($absPath, $destPath)) {
            @chmod($destPath, 0664);
            $dbRows = updateDb($db, $MUSIC_DIR, $absPath, $destPath);
            log_msg("  UNTAGGED: {$currentFilename} -> Untagged/" . ($dbRows > 0 ? ' (DB updated)' : ' (no DB match)'));
            $moved++;
        } else {
            log_msg("  ERROR: Failed to move {$currentFilename} to Untagged/");
            $failed++;
        }
        continue;
    }

    // ── Build new filename from tags ──
    if ($artist !== '' && $title !== '') {
        $artist = sanitizeSegment($artist);
        $title  = sanitizeFilename($title);
        $newFilename = "{$artist} - {$title}.{$ext}";

        if (mb_strlen($newFilename) > 255) {
            $title = mb_substr($title, 0, max(1, 255 - mb_strlen($artist) - mb_strlen($ext) - 4));
            $newFilename = "{$artist} - {$title}.{$ext}";
        }
    } else {
        // Title only
        $title = sanitizeFilename($title);
        $newFilename = "{$title}.{$ext}";

        if (mb_strlen($newFilename) > 255) {
            $title = mb_substr($title, 0, max(1, 255 - mb_strlen($ext) - 1));
            $newFilename = "{$title}.{$ext}";
        }
    }

    // Already correctly named?
    if ($currentFilename === $newFilename) {
        $already++;
        continue;
    }

    $newPath = resolveConflict($dir . '/' . $newFilename);

    if ($dryRun) {
        $oldRel = ltrim(str_replace($MUSIC_DIR . '/', '', $absPath), '/');
        $newRel = ltrim(str_replace($MUSIC_DIR . '/', '', $newPath), '/');
        log_msg("  RENAME: {$oldRel}");
        log_msg("      ->  {$newRel}");
        $renamed++;
        continue;
    }

    if (!rename($absPath, $newPath)) {
        log_msg("  ERROR: Failed to rename {$currentFilename}");
        $failed++;
        continue;
    }

    $dbRows = updateDb($db, $MUSIC_DIR, $absPath, $newPath);
    log_msg("  RENAMED: {$currentFilename} -> " . basename($newPath) . ($dbRows > 0 ? ' (DB updated)' : ' (no DB match)'));
    $renamed++;
}

log_msg("Done. Renamed: {$renamed}, Moved to Untagged: {$moved}, Already correct: {$already}, Failed: {$failed}");
