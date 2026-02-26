<?php
/**
 * Library Sync — runs in background, checks all active songs for missing files.
 *
 * Songs whose files no longer exist on disk are deactivated (is_active = false).
 * This is safe and reversible — no data is deleted.
 *
 * Progress is written to /tmp/rendezvox_library_sync.json so the admin UI
 * can poll for status.
 */

declare(strict_types=1);

require __DIR__ . '/../core/Database.php';

$autoMode = in_array('--auto', $argv ?? []);

$db = Database::get();

// ── Lock file — prevent concurrent runs ─────────────────
$lockFile = '/tmp/rendezvox_library_sync.lock';
if (file_exists($lockFile)) {
    $pid = (int) @file_get_contents($lockFile);
    if ($pid > 0 && file_exists("/proc/{$pid}")) {
        exit(0); // Another sync is running
    }
    @unlink($lockFile); // Stale lock
}
file_put_contents($lockFile, (string) getmypid());
@chmod($lockFile, 0666);

// ── Auto mode: check setting ────────────────────────────
if ($autoMode) {
    $stmt = $db->prepare("SELECT value FROM settings WHERE key = 'auto_library_sync_enabled'");
    $stmt->execute();
    $row = $stmt->fetch();
    if (!$row || $row['value'] !== 'true') {
        @unlink($lockFile);
        exit(0); // Auto-sync disabled
    }
}

/** Log helper — outputs to stdout (captured by cron >> log file) */
function logMsg(string $msg, bool $autoMode): void
{
    if ($autoMode) {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    }
}

logMsg('Auto-sync started', $autoMode);

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
    $file = '/tmp/rendezvox_library_sync.json';
    @file_put_contents($file, json_encode($progress), LOCK_EX);
    @chmod($file, 0666);
}

// ── Main ─────────────────────────────────────────────────

$musicDir = '/var/lib/rendezvox/music';

$stmt = $db->query("SELECT id, file_path FROM songs WHERE is_active = true");
$allSongs = $stmt->fetchAll();

$progress['total'] = count($allSongs);
writeProgress($progress);

if (count($allSongs) === 0) {
    logMsg('No active songs — exiting', $autoMode);
    $progress['status']      = 'done';
    $progress['finished_at'] = date('c');
    writeProgress($progress);
    if ($autoMode) {
        @file_put_contents('/tmp/rendezvox_auto_sync_last.json', json_encode([
            'ran_at'      => date('c'),
            'total'       => 0,
            'missing'     => 0,
            'deactivated' => 0,
            'message'     => 'No active songs found',
        ]), LOCK_EX);
        @chmod('/tmp/rendezvox_auto_sync_last.json', 0666);
    }
    @unlink($lockFile);
    exit(0);
}

$stopFile   = '/tmp/rendezvox_library_sync.lock.stop';
$batchSize  = 100;  // Progress write + stop check frequency
$missingIds = [];    // Collect for batch DB update

foreach ($allSongs as $i => $song) {
    // Check for stop signal every batch
    if ($i % $batchSize === 0 && file_exists($stopFile)) {
        // Flush pending deactivations
        if (!empty($missingIds)) {
            $placeholders = implode(',', array_fill(0, count($missingIds), '?'));
            $db->prepare("UPDATE songs SET is_active = false WHERE id IN ($placeholders)")
               ->execute($missingIds);
            $missingIds = [];
        }
        @unlink($stopFile);
        $progress['status']      = 'stopped';
        $progress['finished_at'] = date('c');
        writeProgress($progress);
        logMsg('Sync stopped — ' . $progress['processed'] . '/' . $progress['total'] . ' processed, ' . $progress['deactivated'] . ' deactivated', $autoMode);
        if ($autoMode) {
            @file_put_contents('/tmp/rendezvox_auto_sync_last.json', json_encode([
                'ran_at'      => date('c'),
                'total'       => $progress['total'],
                'missing'     => $progress['missing'],
                'deactivated' => $progress['deactivated'],
                'message'     => 'Stopped — ' . $progress['deactivated'] . ' deactivated so far',
            ]), LOCK_EX);
            @chmod('/tmp/rendezvox_auto_sync_last.json', 0666);
        }
        @unlink($lockFile);
        exit(0);
    }

    $filePath     = $song['file_path'];
    $absolutePath = $musicDir . '/' . $filePath;

    if (!file_exists($absolutePath)) {
        $progress['missing']++;
        $progress['deactivated']++;
        $missingIds[] = (int) $song['id'];

        // Flush batch every 50 missing files
        if (count($missingIds) >= 50) {
            $placeholders = implode(',', array_fill(0, count($missingIds), '?'));
            $db->prepare("UPDATE songs SET is_active = false WHERE id IN ($placeholders)")
               ->execute($missingIds);
            $missingIds = [];
        }
    }

    $progress['processed']++;

    // Write progress every batch instead of every file
    if ($i % $batchSize === 0 || $i === count($allSongs) - 1) {
        writeProgress($progress);
    }
}

// Flush remaining deactivations
if (!empty($missingIds)) {
    $placeholders = implode(',', array_fill(0, count($missingIds), '?'));
    $db->prepare("UPDATE songs SET is_active = false WHERE id IN ($placeholders)")
       ->execute($missingIds);
}

// ── Auto-purge: delete inactive songs whose files are missing ──
$purgeStmt = $db->query("SELECT id, file_path FROM songs WHERE is_active = false");
$purgeIds  = [];
foreach ($purgeStmt->fetchAll() as $row) {
    $abs = $musicDir . '/' . $row['file_path'];
    if (!file_exists($abs)) {
        $purgeIds[] = (int) $row['id'];
    }
}
$progress['purged'] = 0;
if (!empty($purgeIds)) {
    // ON DELETE CASCADE handles playlist_songs, play_history, song_requests, request_queue
    $placeholders = implode(',', array_fill(0, count($purgeIds), '?'));
    $db->prepare("DELETE FROM songs WHERE id IN ($placeholders)")->execute($purgeIds);
    $progress['purged'] = count($purgeIds);
    logMsg('Purged ' . count($purgeIds) . ' inactive songs with missing files', $autoMode);
}

$progress['status']      = 'done';
$progress['finished_at'] = date('c');
writeProgress($progress);

logMsg('Sync complete — ' . $progress['missing'] . ' missing, ' . $progress['deactivated'] . ' deactivated, ' . $progress['purged'] . ' purged out of ' . $progress['total'], $autoMode);

// Write auto-sync summary for the Settings UI
if ($autoMode) {
    @file_put_contents('/tmp/rendezvox_auto_sync_last.json', json_encode([
        'ran_at'      => date('c'),
        'total'       => $progress['total'],
        'missing'     => $progress['missing'],
        'deactivated' => $progress['deactivated'],
        'purged'      => $progress['purged'],
        'message'     => $progress['missing'] . ' missing, ' . $progress['deactivated'] . ' deactivated, ' . $progress['purged'] . ' purged',
    ]), LOCK_EX);
    @chmod('/tmp/rendezvox_auto_sync_last.json', 0666);
}

@unlink($lockFile);
@unlink($stopFile);
