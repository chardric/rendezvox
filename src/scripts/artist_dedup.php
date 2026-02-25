<?php
/**
 * Artist Deduplication — runs in background, merges collaboration variants.
 *
 * For each artist whose name contains collaboration separators (feat., &, with, etc.),
 * extract the primary artist and either merge into an existing canonical artist
 * or rename the record.
 *
 * Progress is written to /tmp/rendezvox_artist_dedup.json so the admin UI
 * can poll for status.
 */

declare(strict_types=1);

require __DIR__ . '/../core/Database.php';
require __DIR__ . '/../core/ArtistNormalizer.php';

$autoMode = in_array('--auto', $argv ?? []);

$db = Database::get();

// ── Lock file — prevent concurrent runs ─────────────────
$lockFile = '/tmp/rendezvox_artist_dedup.lock';
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
    $stmt = $db->prepare("SELECT value FROM settings WHERE key = 'auto_artist_dedup_enabled'");
    $stmt->execute();
    $row = $stmt->fetch();
    if (!$row || $row['value'] !== 'true') {
        @unlink($lockFile);
        exit(0); // Auto-dedup disabled
    }
}

/** Log helper — outputs to stdout (captured by cron >> log file) */
function logMsg(string $msg, bool $autoMode): void
{
    if ($autoMode) {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    }
}

logMsg('Auto-dedup started', $autoMode);

// ── Progress tracking ───────────────────────────────────
$progress = [
    'status'      => 'running',
    'total'       => 0,
    'processed'   => 0,
    'merged'      => 0,
    'renamed'     => 0,
    'started_at'  => date('c'),
    'finished_at' => null,
];

function writeProgress(array &$progress): void
{
    $file = '/tmp/rendezvox_artist_dedup.json';
    @file_put_contents($file, json_encode($progress), LOCK_EX);
    @chmod($file, 0666);
}

// ── Main ─────────────────────────────────────────────────

$stmt = $db->query("SELECT id, name, normalized_name FROM artists ORDER BY id");
$allArtists = $stmt->fetchAll();

$progress['total'] = count($allArtists);
writeProgress($progress);

if (count($allArtists) === 0) {
    logMsg('No artists — exiting', $autoMode);
    $progress['status']      = 'done';
    $progress['finished_at'] = date('c');
    writeProgress($progress);
    if ($autoMode) {
        @file_put_contents('/tmp/rendezvox_auto_dedup_last.json', json_encode([
            'ran_at'  => date('c'),
            'total'   => 0,
            'merged'  => 0,
            'renamed' => 0,
            'message' => 'No artists found',
        ]), LOCK_EX);
        @chmod('/tmp/rendezvox_auto_dedup_last.json', 0666);
    }
    @unlink($lockFile);
    exit(0);
}

$stopFile = '/tmp/rendezvox_artist_dedup.lock.stop';

foreach ($allArtists as $artist) {
    // Check for stop signal
    if (file_exists($stopFile)) {
        @unlink($stopFile);
        $progress['status']      = 'stopped';
        $progress['finished_at'] = date('c');
        writeProgress($progress);
        logMsg('Dedup stopped — ' . $progress['processed'] . '/' . $progress['total'] . ' processed, ' . $progress['merged'] . ' merged', $autoMode);
        if ($autoMode) {
            @file_put_contents('/tmp/rendezvox_auto_dedup_last.json', json_encode([
                'ran_at'  => date('c'),
                'total'   => $progress['total'],
                'merged'  => $progress['merged'],
                'renamed' => $progress['renamed'],
                'message' => 'Stopped — ' . $progress['merged'] . ' merged so far',
            ]), LOCK_EX);
            @chmod('/tmp/rendezvox_auto_dedup_last.json', 0666);
        }
        @unlink($lockFile);
        exit(0);
    }

    $artistId   = (int) $artist['id'];
    $artistName = $artist['name'];

    $primary = ArtistNormalizer::extractPrimary($artistName, $db);

    if (mb_strtolower($primary) !== mb_strtolower($artistName)) {
        $canonicalNorm = mb_strtolower(trim($primary));

        // Look up canonical artist
        $lookup = $db->prepare('SELECT id FROM artists WHERE normalized_name = :norm AND id != :id LIMIT 1');
        $lookup->execute(['norm' => $canonicalNorm, 'id' => $artistId]);
        $canonical = $lookup->fetch();

        if ($canonical) {
            // Canonical exists → reassign songs and delete duplicate
            $canonicalId = (int) $canonical['id'];

            $db->prepare('UPDATE songs SET artist_id = :canonical WHERE artist_id = :dup')
               ->execute(['canonical' => $canonicalId, 'dup' => $artistId]);

            $db->prepare('DELETE FROM artists WHERE id = :id')
               ->execute(['id' => $artistId]);

            $progress['merged']++;
        } else {
            // Canonical does not exist → rename this artist
            $db->prepare('UPDATE artists SET name = :name, normalized_name = :norm WHERE id = :id')
               ->execute(['name' => trim($primary), 'norm' => $canonicalNorm, 'id' => $artistId]);

            $progress['renamed']++;
        }
    }

    $progress['processed']++;
    writeProgress($progress);
}

$progress['status']      = 'done';
$progress['finished_at'] = date('c');
writeProgress($progress);

logMsg('Dedup complete — ' . $progress['merged'] . ' merged, ' . $progress['renamed'] . ' renamed out of ' . $progress['total'], $autoMode);

// Write auto-dedup summary for the Settings UI
if ($autoMode) {
    @file_put_contents('/tmp/rendezvox_auto_dedup_last.json', json_encode([
        'ran_at'  => date('c'),
        'total'   => $progress['total'],
        'merged'  => $progress['merged'],
        'renamed' => $progress['renamed'],
        'message' => $progress['merged'] . ' merged, ' . $progress['renamed'] . ' renamed',
    ]), LOCK_EX);
    @chmod('/tmp/rendezvox_auto_dedup_last.json', 0666);
}

@unlink($lockFile);
@unlink($stopFile);
