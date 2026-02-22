<?php
/**
 * Audio Normalization — runs in background, analyzes loudness via ffmpeg.
 *
 * Usage:
 *   php normalize_audio.php          — normalize all unanalyzed songs
 *   php normalize_audio.php --auto   — auto mode (for cron, checks setting)
 *
 * Uses EBU R128 (LUFS) loudness analysis (first-pass only) to calculate
 * per-track gain for consistent volume. Non-destructive — no audio files
 * are modified. Gain is applied in real-time via Liquidsoap's amplify().
 *
 * Progress is written to /tmp/iradio_normalize.json so the admin UI
 * can poll for status.
 */

declare(strict_types=1);

require __DIR__ . '/../core/Database.php';

$autoMode = in_array('--auto', $argv ?? []);

$db = Database::get();

// ── Lock file — prevent concurrent runs ─────────────────
$lockFile = '/tmp/iradio_normalize.lock';
if (file_exists($lockFile)) {
    $pid = (int) @file_get_contents($lockFile);
    if ($pid > 0 && file_exists("/proc/{$pid}")) {
        exit(0); // Another normalization is running
    }
    @unlink($lockFile); // Stale lock
}
file_put_contents($lockFile, (string) getmypid());
@chmod($lockFile, 0666);

// ── Auto mode: check setting ────────────────────────────
if ($autoMode) {
    $stmt = $db->prepare("SELECT value FROM settings WHERE key = 'auto_normalize_enabled'");
    $stmt->execute();
    $row = $stmt->fetch();
    if (!$row || $row['value'] !== 'true') {
        @unlink($lockFile);
        exit(0); // Auto-normalize disabled
    }
}

/** Log helper — outputs to stdout (captured by cron >> log file) */
function logMsg(string $msg, bool $autoMode): void
{
    if ($autoMode) {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    }
}

logMsg('Auto-normalize started', $autoMode);

// ── Progress tracking ───────────────────────────────────
$progress = [
    'status'     => 'running',
    'total'      => 0,
    'processed'  => 0,
    'normalized' => 0,
    'skipped'    => 0,
    'failed'     => 0,
    'started_at' => date('c'),
];

function writeProgress(array &$progress): void
{
    $file = '/tmp/iradio_normalize.json';
    @file_put_contents($file, json_encode($progress), LOCK_EX);
    @chmod($file, 0666);
}

// ── Read target LUFS from settings ──────────────────────
$targetLufs = -14.0;
$stmt = $db->prepare("SELECT value FROM settings WHERE key = 'normalize_target_lufs'");
$stmt->execute();
$row = $stmt->fetch();
if ($row && is_numeric(trim($row['value']))) {
    $targetLufs = (float) trim($row['value']);
}

// ── Get unanalyzed songs ────────────────────────────────
$stmt = $db->query("
    SELECT s.id, s.file_path
    FROM songs s
    WHERE s.is_active = true
      AND s.loudness_lufs IS NULL
    ORDER BY s.id
");
$allSongs = $stmt->fetchAll();

$progress['total'] = count($allSongs);
writeProgress($progress);

logMsg('Found ' . count($allSongs) . ' songs to normalize', $autoMode);
if (count($allSongs) === 0) {
    logMsg('No unanalyzed songs — exiting', $autoMode);
    $progress['status']      = 'done';
    $progress['finished_at'] = date('c');
    writeProgress($progress);
    if ($autoMode) {
        @file_put_contents('/tmp/iradio_auto_norm_last.json', json_encode([
            'ran_at'     => date('c'),
            'total'      => 0,
            'normalized' => 0,
            'skipped'    => 0,
            'failed'     => 0,
            'message'    => 'No unanalyzed songs found',
        ]), LOCK_EX);
        @chmod('/tmp/iradio_auto_norm_last.json', 0666);
    }
    @unlink($lockFile);
    exit(0);
}

$stopFile = '/tmp/iradio_normalize.lock.stop';

foreach ($allSongs as $song) {
    // Check for stop signal
    if (file_exists($stopFile)) {
        @unlink($stopFile);
        $progress['status']      = 'stopped';
        $progress['finished_at'] = date('c');
        writeProgress($progress);
        logMsg('Normalize stopped — ' . $progress['processed'] . '/' . $progress['total'] . ' processed, ' . $progress['normalized'] . ' normalized', $autoMode);
        if ($autoMode) {
            @file_put_contents('/tmp/iradio_auto_norm_last.json', json_encode([
                'ran_at'     => date('c'),
                'total'      => $progress['total'],
                'normalized' => $progress['normalized'],
                'skipped'    => $progress['skipped'],
                'failed'     => $progress['failed'],
                'message'    => 'Stopped — ' . $progress['normalized'] . ' normalized so far',
            ]), LOCK_EX);
            @chmod('/tmp/iradio_auto_norm_last.json', 0666);
        }
        @unlink($lockFile);
        exit(0);
    }

    $songId   = (int) $song['id'];
    $filePath = $song['file_path'];

    // Skip if file doesn't exist
    if (!file_exists($filePath)) {
        $progress['skipped']++;
        $progress['processed']++;
        writeProgress($progress);
        continue;
    }

    // Run ffmpeg loudnorm analysis (first pass only)
    $cmd = 'ffmpeg -i ' . escapeshellarg($filePath)
         . ' -af loudnorm=I=-14:TP=-1:LRA=11:print_format=json -f null - 2>&1';

    $output = [];
    exec($cmd, $output, $exitCode);

    // Parse JSON from ffmpeg output — it's embedded in stderr
    $fullOutput = implode("\n", $output);

    // Extract JSON block from the output
    $jsonStart = strrpos($fullOutput, '{');
    $jsonEnd   = strrpos($fullOutput, '}');

    if ($jsonStart === false || $jsonEnd === false || $jsonEnd <= $jsonStart) {
        $progress['failed']++;
        $progress['processed']++;
        writeProgress($progress);
        continue;
    }

    $jsonStr = substr($fullOutput, $jsonStart, $jsonEnd - $jsonStart + 1);
    $data    = json_decode($jsonStr, true);

    if (!$data || !isset($data['input_i'])) {
        $progress['failed']++;
        $progress['processed']++;
        writeProgress($progress);
        continue;
    }

    $inputI = (float) $data['input_i'];
    $gainDb = $targetLufs - $inputI;

    // Update the song row
    $stmt = $db->prepare("
        UPDATE songs
        SET loudness_lufs = :lufs, loudness_gain_db = :gain
        WHERE id = :id
    ");
    $stmt->execute([
        'lufs' => round($inputI, 2),
        'gain' => round($gainDb, 2),
        'id'   => $songId,
    ]);

    $progress['normalized']++;
    $progress['processed']++;
    writeProgress($progress);
}

$progress['status']      = 'done';
$progress['finished_at'] = date('c');
writeProgress($progress);
@unlink($lockFile);
@unlink($stopFile);

logMsg('Normalize complete — ' . $progress['normalized'] . ' normalized, ' . $progress['skipped'] . ' skipped, ' . $progress['failed'] . ' failed out of ' . $progress['total'], $autoMode);

// Write auto-normalize summary for the Settings UI
if ($autoMode) {
    @file_put_contents('/tmp/iradio_auto_norm_last.json', json_encode([
        'ran_at'     => date('c'),
        'total'      => $progress['total'],
        'normalized' => $progress['normalized'],
        'skipped'    => $progress['skipped'],
        'failed'     => $progress['failed'],
        'message'    => $progress['normalized'] . ' normalized, ' . $progress['skipped'] . ' skipped, ' . $progress['failed'] . ' failed',
    ]), LOCK_EX);
    @chmod('/tmp/iradio_auto_norm_last.json', 0666);
}
