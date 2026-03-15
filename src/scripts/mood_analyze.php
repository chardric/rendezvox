<?php
/**
 * Mood Analysis — batch analyzes songs for BPM, energy, valence, and ending type.
 *
 * Usage:
 *   php mood_analyze.php          — analyze all unanalyzed songs
 *   php mood_analyze.php --auto   — auto mode (for cron, checks setting)
 *
 * Uses ffmpeg spectral analysis via MoodAnalyzer class.
 * Results stored in songs table (bpm, energy, valence, ending_type, etc.)
 *
 * Progress is written to /tmp/rendezvox_mood_analyze.json for admin UI polling.
 */

declare(strict_types=1);

require __DIR__ . '/../core/Database.php';
require __DIR__ . '/../core/MoodAnalyzer.php';

$autoMode = in_array('--auto', $argv ?? []);

$db = Database::get();

// ── Lock file — prevent concurrent runs ─────────────────
$lockFile = '/tmp/rendezvox_mood_analyze.lock';
if (file_exists($lockFile)) {
    $pid = (int) @file_get_contents($lockFile);
    if ($pid > 0 && file_exists("/proc/{$pid}")) {
        exit(0); // Another instance is running
    }
    @unlink($lockFile); // Stale lock
}
file_put_contents($lockFile, (string) getmypid());
@chmod($lockFile, 0666);

// ── Auto mode: check setting ────────────────────────────
if ($autoMode) {
    $stmt = $db->prepare("SELECT value FROM settings WHERE key = 'auto_mood_analyze_enabled'");
    $stmt->execute();
    $row = $stmt->fetch();
    if (!$row || $row['value'] !== 'true') {
        @unlink($lockFile);
        exit(0);
    }
}

/** Log helper */
function logMsg(string $msg, bool $autoMode): void
{
    if ($autoMode) {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    }
}

logMsg('Mood analysis started', $autoMode);

// ── Progress tracking ───────────────────────────────────
$progress = [
    'status'     => 'running',
    'total'      => 0,
    'processed'  => 0,
    'analyzed'   => 0,
    'skipped'    => 0,
    'failed'     => 0,
    'started_at' => date('c'),
];

function writeProgress(array &$progress): void
{
    $file = '/tmp/rendezvox_mood_analyze.json';
    @file_put_contents($file, json_encode($progress), LOCK_EX);
    @chmod($file, 0666);
}

// ── Get unanalyzed songs ────────────────────────────────
$stmt = $db->query("
    SELECT s.id, s.file_path, s.duration_ms
    FROM songs s
    WHERE s.is_active = true
      AND s.mood_analyzed_at IS NULL
    ORDER BY s.id
");
$allSongs = $stmt->fetchAll();

$progress['total'] = count($allSongs);
writeProgress($progress);

logMsg('Found ' . count($allSongs) . ' songs to analyze', $autoMode);
if (count($allSongs) === 0) {
    logMsg('No unanalyzed songs — exiting', $autoMode);
    $progress['status']      = 'done';
    $progress['finished_at'] = date('c');
    writeProgress($progress);
    @unlink($lockFile);
    exit(0);
}

$musicDir = '/var/lib/rendezvox/music';
$stopFile = '/tmp/rendezvox_mood_analyze.lock.stop';

$updateStmt = $db->prepare("
    UPDATE songs
    SET bpm = :bpm,
        energy = :energy,
        valence = :valence,
        ending_type = :ending_type,
        ending_energy = :ending_energy,
        intro_energy = :intro_energy,
        mood_analyzed_at = NOW()
    WHERE id = :id
");

foreach ($allSongs as $song) {
    // Check for stop signal
    if (file_exists($stopFile)) {
        @unlink($stopFile);
        $progress['status']      = 'stopped';
        $progress['finished_at'] = date('c');
        writeProgress($progress);
        logMsg('Mood analysis stopped — ' . $progress['analyzed'] . '/' . $progress['total'] . ' analyzed', $autoMode);
        @unlink($lockFile);
        exit(0);
    }

    $songId   = (int) $song['id'];
    $filePath = $song['file_path'];

    if ($filePath[0] !== '/') {
        $filePath = $musicDir . '/' . $filePath;
    }

    if (!file_exists($filePath)) {
        $progress['skipped']++;
        $progress['processed']++;
        writeProgress($progress);
        continue;
    }

    try {
        $result = MoodAnalyzer::analyze($filePath);

        $updateStmt->execute([
            'bpm'           => $result['bpm'],
            'energy'        => $result['energy'],
            'valence'       => $result['valence'],
            'ending_type'   => $result['ending_type'],
            'ending_energy' => $result['ending_energy'],
            'intro_energy'  => $result['intro_energy'],
            'id'            => $songId,
        ]);

        $progress['analyzed']++;
    } catch (\Throwable $e) {
        error_log("Mood analysis failed for song {$songId}: " . $e->getMessage());
        $progress['failed']++;
    }

    $progress['processed']++;
    writeProgress($progress);
}

$progress['status']      = 'done';
$progress['finished_at'] = date('c');
writeProgress($progress);
@unlink($lockFile);
@unlink($stopFile);

// Write auto-mode last-run status
if ($autoMode) {
    $lastRun = [
        'ran_at'   => date('c'),
        'total'    => $progress['total'],
        'analyzed' => $progress['analyzed'],
        'skipped'  => $progress['skipped'],
        'failed'   => $progress['failed'],
    ];
    @file_put_contents('/tmp/rendezvox_auto_mood_last.json', json_encode($lastRun), LOCK_EX);
    @chmod('/tmp/rendezvox_auto_mood_last.json', 0666);
}

logMsg('Mood analysis complete — ' . $progress['analyzed'] . ' analyzed, ' . $progress['skipped'] . ' skipped, ' . $progress['failed'] . ' failed out of ' . $progress['total'], $autoMode);
