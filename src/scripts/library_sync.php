<?php
/**
 * Library Sync — runs in background, checks all active songs for missing files.
 *
 * Songs whose files no longer exist on disk are deactivated (is_active = false).
 * This is safe and reversible — no data is deleted.
 *
 * Progress is written to /tmp/iradio_library_sync.json so the admin UI
 * can poll for status.
 */

declare(strict_types=1);

require __DIR__ . '/../core/Database.php';

$db = Database::get();

// ── Lock file — prevent concurrent runs ─────────────────
$lockFile = '/tmp/iradio_library_sync.lock';
if (file_exists($lockFile)) {
    $pid = (int) @file_get_contents($lockFile);
    if ($pid > 0 && file_exists("/proc/{$pid}")) {
        exit(0); // Another sync is running
    }
    @unlink($lockFile); // Stale lock
}
file_put_contents($lockFile, (string) getmypid());
@chmod($lockFile, 0666);

// ── Progress tracking ───────────────────────────────────
$progress = [
    'status'      => 'running',
    'total'       => 0,
    'processed'   => 0,
    'missing'     => 0,
    'deactivated' => 0,
    'started_at'  => date('c'),
    'finished_at' => null,
];

function writeProgress(array &$progress): void
{
    $file = '/tmp/iradio_library_sync.json';
    @file_put_contents($file, json_encode($progress), LOCK_EX);
    @chmod($file, 0666);
}

// ── Main ─────────────────────────────────────────────────

$musicDir = '/var/lib/iradio/music';

$stmt = $db->query("SELECT id, file_path FROM songs WHERE is_active = true");
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

$stopFile = '/tmp/iradio_library_sync.lock.stop';

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

    $filePath = $song['file_path'];
    $absolutePath = $musicDir . '/' . $filePath;

    if (!file_exists($absolutePath)) {
        $progress['missing']++;

        // Deactivate the song
        $db->prepare("UPDATE songs SET is_active = false WHERE id = :id")
           ->execute(['id' => (int) $song['id']]);

        $progress['deactivated']++;
    }

    $progress['processed']++;
    writeProgress($progress);
}

$progress['status']      = 'done';
$progress['finished_at'] = date('c');
writeProgress($progress);
@unlink($lockFile);
@unlink($stopFile);
