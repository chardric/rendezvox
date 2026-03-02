<?php
/**
 * Silence Detection — runs in background, detects leading/trailing silence via ffmpeg.
 *
 * Usage:
 *   php silence_detect.php          — analyze all unanalyzed songs
 *   php silence_detect.php --auto   — auto mode (for cron, checks setting)
 *
 * Uses ffmpeg's silencedetect filter to find leading and trailing silence.
 * Results are stored as cue_in/cue_out in the songs table and passed to
 * Liquidsoap via liq_cue_in/liq_cue_out metadata for precise trimming.
 *
 * Non-destructive — no audio files are modified. Cue points are applied
 * in real-time via Liquidsoap's cue_cut() operator.
 *
 * Progress is written to /tmp/rendezvox_silence_detect.json so the admin UI
 * can poll for status.
 */

declare(strict_types=1);

require __DIR__ . '/../core/Database.php';

$autoMode = in_array('--auto', $argv ?? []);

$db = Database::get();

// ── Lock file — prevent concurrent runs ─────────────────
$lockFile = '/tmp/rendezvox_silence_detect.lock';
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
    $stmt = $db->prepare("SELECT value FROM settings WHERE key = 'auto_silence_detect_enabled'");
    $stmt->execute();
    $row = $stmt->fetch();
    if (!$row || $row['value'] !== 'true') {
        @unlink($lockFile);
        exit(0); // Auto-silence-detect disabled
    }
}

/** Log helper — outputs to stdout (captured by cron >> log file) */
function logMsg(string $msg, bool $autoMode): void
{
    if ($autoMode) {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    }
}

logMsg('Auto-silence-detect started', $autoMode);

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
    $file = '/tmp/rendezvox_silence_detect.json';
    @file_put_contents($file, json_encode($progress), LOCK_EX);
    @chmod($file, 0666);
}

// ── Get unanalyzed songs ────────────────────────────────
$stmt = $db->query("
    SELECT s.id, s.file_path, s.duration_ms
    FROM songs s
    WHERE s.is_active = true
      AND s.cue_in IS NULL
      AND s.cue_out IS NULL
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
    if ($autoMode) {
        @file_put_contents('/tmp/rendezvox_auto_silence_last.json', json_encode([
            'ran_at'   => date('c'),
            'total'    => 0,
            'analyzed' => 0,
            'skipped'  => 0,
            'failed'   => 0,
            'message'  => 'No unanalyzed songs found',
        ]), LOCK_EX);
        @chmod('/tmp/rendezvox_auto_silence_last.json', 0666);
    }
    @unlink($lockFile);
    exit(0);
}

$musicDir = '/var/lib/rendezvox/music';
$stopFile = '/tmp/rendezvox_silence_detect.lock.stop';

// ── Parallel workers — safe parallelism based on CPU cores & load ──
$cpuCores   = (int) @shell_exec('nproc') ?: 2;
$maxWorkers = max(1, (int) floor($cpuCores / 2));
$load       = sys_getloadavg();
$load5m     = $load ? $load[1] : 0;
if ($load5m > $cpuCores * 0.6) {
    $maxWorkers = 1;
}
logMsg("CPU: {$cpuCores} cores, 5m load: " . round($load5m, 2) . " — using $maxWorkers parallel workers", $autoMode);

/**
 * Parse ffmpeg silencedetect output and compute cue_in/cue_out.
 *
 * @param string $output   Full stderr from ffmpeg silencedetect
 * @param float  $duration Track duration in seconds
 * @return array{float, float}|null  [cue_in, cue_out] or null on parse failure
 */
function parseSilenceDetect(string $output, float $duration): ?array
{
    if ($duration <= 0) {
        return null;
    }

    // Extract all silence_start/silence_end pairs from ffmpeg output
    $silenceBlocks = [];
    $currentStart  = null;

    // Match silence_start lines
    if (preg_match_all('/silence_start:\s*([\d.]+)/', $output, $starts)) {
        foreach ($starts[1] as $s) {
            $silenceBlocks[] = ['start' => (float) $s, 'end' => null];
        }
    }

    // Match silence_end lines and pair with starts
    if (preg_match_all('/silence_end:\s*([\d.]+)/', $output, $ends)) {
        foreach ($ends[1] as $i => $e) {
            if (isset($silenceBlocks[$i])) {
                $silenceBlocks[$i]['end'] = (float) $e;
            }
        }
    }

    $cueIn  = 0.0;
    $cueOut = $duration;

    if (empty($silenceBlocks)) {
        // No silence found — full track plays
        return [$cueIn, $cueOut];
    }

    // Leading silence: first block starting at or near 0
    $first = $silenceBlocks[0];
    if ($first['start'] <= 0.1 && $first['end'] !== null) {
        $cueIn = $first['end'];
    }

    // Trailing silence: last block ending at or near duration
    $last = end($silenceBlocks);
    if ($last['end'] === null) {
        // silence_start with no silence_end = silence runs to EOF
        $cueOut = $last['start'];
    } elseif (abs($last['end'] - $duration) <= 0.5) {
        $cueOut = $last['start'];
    }

    // Sanity checks
    if ($cueIn >= $cueOut) {
        // Invalid — reset to full track
        return [0.0, $duration];
    }
    if ($cueIn < 0) {
        $cueIn = 0.0;
    }
    if ($cueOut > $duration) {
        $cueOut = $duration;
    }

    return [$cueIn, $cueOut];
}

/** Active worker slots */
$workers    = [];
$songIdx    = 0;
$totalSongs = count($allSongs);

while ($songIdx < $totalSongs || count($workers) > 0) {
    // Check for stop signal
    if (file_exists($stopFile)) {
        foreach ($workers as $w) {
            @fclose($w['pipes'][1]);
            proc_terminate($w['proc']);
            proc_close($w['proc']);
        }
        @unlink($stopFile);
        $progress['status']      = 'stopped';
        $progress['finished_at'] = date('c');
        writeProgress($progress);
        logMsg('Silence detect stopped — ' . $progress['processed'] . '/' . $progress['total'] . ' processed, ' . $progress['analyzed'] . ' analyzed', $autoMode);
        if ($autoMode) {
            @file_put_contents('/tmp/rendezvox_auto_silence_last.json', json_encode([
                'ran_at'   => date('c'),
                'total'    => $progress['total'],
                'analyzed' => $progress['analyzed'],
                'skipped'  => $progress['skipped'],
                'failed'   => $progress['failed'],
                'message'  => 'Stopped — ' . $progress['analyzed'] . ' analyzed so far',
            ]), LOCK_EX);
            @chmod('/tmp/rendezvox_auto_silence_last.json', 0666);
        }
        @unlink($lockFile);
        exit(0);
    }

    // Fill empty worker slots
    while (count($workers) < $maxWorkers && $songIdx < $totalSongs) {
        $song       = $allSongs[$songIdx++];
        $songId     = (int) $song['id'];
        $filePath   = $song['file_path'];
        $durationMs = (int) $song['duration_ms'];

        if ($filePath[0] !== '/') {
            $filePath = $musicDir . '/' . $filePath;
        }

        if (!file_exists($filePath)) {
            $progress['skipped']++;
            $progress['processed']++;
            writeProgress($progress);
            continue;
        }

        $cmd  = 'ffmpeg -i ' . escapeshellarg($filePath)
              . ' -af silencedetect=noise=-40dB:d=0.5 -f null - 2>&1';
        $desc = [1 => ['pipe', 'w']];
        $proc = proc_open($cmd, $desc, $pipes);

        if (!is_resource($proc)) {
            $progress['failed']++;
            $progress['processed']++;
            writeProgress($progress);
            continue;
        }

        stream_set_blocking($pipes[1], false);
        $workers[] = [
            'proc'       => $proc,
            'pipes'      => $pipes,
            'songId'     => $songId,
            'durationMs' => $durationMs,
            'output'     => '',
        ];
    }

    // Poll active workers for completion
    foreach ($workers as $idx => &$w) {
        $chunk = @stream_get_contents($w['pipes'][1]);
        if ($chunk !== false) {
            $w['output'] .= $chunk;
        }

        $status = proc_get_status($w['proc']);
        if (!$status['running']) {
            // Read any remaining output
            $chunk = @stream_get_contents($w['pipes'][1]);
            if ($chunk !== false) {
                $w['output'] .= $chunk;
            }
            @fclose($w['pipes'][1]);
            proc_close($w['proc']);

            $durationSec = $w['durationMs'] / 1000.0;
            $result = parseSilenceDetect($w['output'], $durationSec);

            if ($result) {
                [$cueIn, $cueOut] = $result;
                $stmt = $db->prepare("
                    UPDATE songs SET cue_in = :cue_in, cue_out = :cue_out WHERE id = :id
                ");
                $stmt->execute([
                    'cue_in'  => round($cueIn, 3),
                    'cue_out' => round($cueOut, 3),
                    'id'      => $w['songId'],
                ]);
                $progress['analyzed']++;
            } else {
                $progress['failed']++;
            }

            $progress['processed']++;
            writeProgress($progress);
            unset($workers[$idx]);
        }
    }
    unset($w);
    $workers = array_values($workers);

    // Brief sleep to avoid busy-waiting
    if (count($workers) > 0) {
        usleep(100000); // 100ms
    }
}

$progress['status']      = 'done';
$progress['finished_at'] = date('c');
writeProgress($progress);
@unlink($lockFile);
@unlink($stopFile);

logMsg('Silence detect complete — ' . $progress['analyzed'] . ' analyzed, ' . $progress['skipped'] . ' skipped, ' . $progress['failed'] . ' failed out of ' . $progress['total'], $autoMode);

// Write auto summary for the Settings UI
if ($autoMode) {
    @file_put_contents('/tmp/rendezvox_auto_silence_last.json', json_encode([
        'ran_at'   => date('c'),
        'total'    => $progress['total'],
        'analyzed' => $progress['analyzed'],
        'skipped'  => $progress['skipped'],
        'failed'   => $progress['failed'],
        'message'  => $progress['analyzed'] . ' analyzed, ' . $progress['skipped'] . ' skipped, ' . $progress['failed'] . ' failed',
    ]), LOCK_EX);
    @chmod('/tmp/rendezvox_auto_silence_last.json', 0666);
}
