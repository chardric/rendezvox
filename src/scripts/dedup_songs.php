<?php
/**
 * Song Deduplication — runs in background, finds and resolves exact duplicate songs.
 *
 * Only resolves EXACT duplicates (same file_hash). Likely duplicates (same title+artist)
 * are skipped — they require human judgement and are handled via the Media Duplicates tab.
 *
 * For each group, keeps the copy with the highest play_count (ties broken by largest file).
 * Marks the rest as duplicates (non-destructive — no files or rows deleted).
 *
 * Progress is written to /tmp/rendezvox_dedup_songs.json so the admin UI can poll.
 */

declare(strict_types=1);

require __DIR__ . '/../core/Database.php';

$autoMode = in_array('--auto', $argv ?? []);

$db = Database::get();

// ── Lock file — prevent concurrent runs ─────────────────
$lockFile = '/tmp/rendezvox_dedup_songs.lock';
if (file_exists($lockFile)) {
    $pid = (int) @file_get_contents($lockFile);
    if ($pid > 0 && file_exists("/proc/{$pid}")) {
        exit(0); // Another dedup is running
    }
    @unlink($lockFile); // Stale lock
}
file_put_contents($lockFile, (string) getmypid());
@chmod($lockFile, 0666);

// ── Auto mode: check setting ────────────────────────────
if ($autoMode) {
    $stmt = $db->prepare("SELECT value FROM settings WHERE key = 'auto_dedup_songs_enabled'");
    $stmt->execute();
    $row = $stmt->fetch();
    if (!$row || $row['value'] !== 'true') {
        @unlink($lockFile);
        exit(0); // Auto-dedup disabled
    }
}

/** Log helper */
function logMsg(string $msg, bool $autoMode): void
{
    if ($autoMode) {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    }
}

logMsg('Song dedup started', $autoMode);

// ── Progress tracking ───────────────────────────────────
$progress = [
    'status'      => 'running',
    'phase'       => 'scanning',
    'total_groups' => 0,
    'processed'   => 0,
    'marked'      => 0,
    'started_at'  => date('c'),
    'finished_at' => null,
];

function writeProgress(array &$progress): void
{
    $file = '/tmp/rendezvox_dedup_songs.json';
    @file_put_contents($file, json_encode($progress), LOCK_EX);
    @chmod($file, 0666);
}

writeProgress($progress);

// ── Constants ─────────────────────────────────────────────
$musicDir = '/var/lib/rendezvox/music';
$stopFile = '/tmp/rendezvox_dedup_songs.lock.stop';

// ── Scan: find exact duplicates (same file_hash) ──────────
$stmt = $db->query("
    SELECT s.id, s.file_path, s.file_hash, s.play_count, s.duration_ms,
           a.name AS artist_name, s.title
    FROM songs s
    JOIN artists a ON a.id = s.artist_id
    WHERE s.file_hash IS NOT NULL AND s.file_hash != ''
      AND s.trashed_at IS NULL
      AND s.duplicate_of IS NULL
      AND s.file_hash IN (
          SELECT file_hash FROM songs
          WHERE file_hash IS NOT NULL AND file_hash != '' AND trashed_at IS NULL AND duplicate_of IS NULL
          GROUP BY file_hash HAVING COUNT(*) > 1
      )
    ORDER BY s.file_hash, s.play_count DESC
");

$allRows = $stmt->fetchAll();

// Group by hash
$byHash = [];
foreach ($allRows as $row) {
    $byHash[$row['file_hash']][] = $row;
}

$groups = [];
foreach ($byHash as $hash => $rows) {
    // Determine the best to keep: highest play count, then largest file
    $bestId    = null;
    $bestPlays = -1;
    $bestSize  = -1;

    foreach ($rows as $row) {
        $absPath  = $musicDir . '/' . $row['file_path'];
        $fileSize = file_exists($absPath) ? (int) filesize($absPath) : 0;
        $plays    = (int) $row['play_count'];

        if ($fileSize === 0) continue; // Never keep a missing file

        if ($plays > $bestPlays || ($plays === $bestPlays && $fileSize > $bestSize)) {
            $bestPlays = $plays;
            $bestSize  = $fileSize;
            $bestId    = (int) $row['id'];
        }
    }

    if ($bestId === null) {
        // All files missing — keep the first one anyway
        $bestId = (int) $rows[0]['id'];
    }

    $deleteIds = [];
    foreach ($rows as $row) {
        if ((int) $row['id'] !== $bestId) {
            $deleteIds[] = (int) $row['id'];
        }
    }

    if (!empty($deleteIds)) {
        $groups[] = [
            'keep_id'    => $bestId,
            'delete_ids' => $deleteIds,
            'hash'       => $hash,
        ];
    }
}

$progress['total_groups'] = count($groups);
$progress['phase']        = 'resolving';
writeProgress($progress);

if (count($groups) === 0) {
    logMsg('No exact duplicates found — exiting', $autoMode);
    $progress['status']      = 'done';
    $progress['finished_at'] = date('c');
    writeProgress($progress);
    if ($autoMode) {
        @file_put_contents('/tmp/rendezvox_auto_dedup_songs_last.json', json_encode([
            'ran_at'  => date('c'),
            'groups'  => 0,
            'marked'  => 0,
            'message' => 'No exact duplicates found',
        ]), LOCK_EX);
        @chmod('/tmp/rendezvox_auto_dedup_songs_last.json', 0666);
    }
    @unlink($lockFile);
    exit(0);
}

// ── Resolve: mark duplicates (non-destructive) ─────────────
$markStmt = $db->prepare('UPDATE songs SET duplicate_of = ? WHERE id = ?');

$totalMarked = 0;

foreach ($groups as $gi => $group) {
    // Check for stop signal
    if (file_exists($stopFile)) {
        @unlink($stopFile);
        $progress['status']      = 'stopped';
        $progress['finished_at'] = date('c');
        writeProgress($progress);
        logMsg('Dedup stopped — ' . $totalMarked . ' marked so far', $autoMode);
        if ($autoMode) {
            @file_put_contents('/tmp/rendezvox_auto_dedup_songs_last.json', json_encode([
                'ran_at'  => date('c'),
                'groups'  => $gi,
                'marked'  => $totalMarked,
                'message' => 'Stopped — ' . $totalMarked . ' marked so far',
            ]), LOCK_EX);
            @chmod('/tmp/rendezvox_auto_dedup_songs_last.json', 0666);
        }
        @unlink($lockFile);
        exit(0);
    }

    foreach ($group['delete_ids'] as $did) {
        try {
            $markStmt->execute([$group['keep_id'], $did]);
            $totalMarked++;
        } catch (\PDOException $e) {
            error_log('DedupSongs error marking song ' . $did . ': ' . $e->getMessage());
        }
    }

    $progress['processed'] = $gi + 1;
    $progress['marked']    = $totalMarked;
    writeProgress($progress);
}

$progress['status']      = 'done';
$progress['finished_at'] = date('c');
writeProgress($progress);

logMsg("Dedup complete — {$totalMarked} duplicates marked from " . count($groups) . " groups", $autoMode);

// Write auto-dedup summary
if ($autoMode) {
    @file_put_contents('/tmp/rendezvox_auto_dedup_songs_last.json', json_encode([
        'ran_at'  => date('c'),
        'groups'  => count($groups),
        'marked'  => $totalMarked,
        'message' => $totalMarked . ' duplicates marked',
    ]), LOCK_EX);
    @chmod('/tmp/rendezvox_auto_dedup_songs_last.json', 0666);
}

@unlink($lockFile);
@unlink($stopFile);
