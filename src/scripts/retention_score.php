<?php
/**
 * Retention Score — daily cron job to compute listener retention per song.
 *
 * Usage:
 *   php retention_score.php
 *
 * Computes how many listeners tune in/out during each song, then
 * auto-demotes songs that consistently lose listeners.
 */

declare(strict_types=1);

require __DIR__ . '/../core/Database.php';
require __DIR__ . '/../core/RetentionScorer.php';

$db = Database::get();

// Check if retention scoring is enabled
$stmt = $db->prepare("SELECT value FROM settings WHERE key = 'retention_scoring_enabled'");
$stmt->execute();
$row = $stmt->fetch();
if (!$row || $row['value'] !== 'true') {
    exit(0);
}

echo '[' . date('Y-m-d H:i:s') . '] Computing retention scores...' . "\n";

$result = RetentionScorer::computeScores($db);
echo '[' . date('Y-m-d H:i:s') . '] Updated ' . $result['updated'] . ' song scores' . "\n";

// Auto-demote if enabled
$thresholdStmt = $db->prepare("SELECT value FROM settings WHERE key = 'retention_demote_threshold'");
$thresholdStmt->execute();
$thresholdRow = $thresholdStmt->fetch();
$threshold = $thresholdRow ? (float) $thresholdRow['value'] : -0.15;

$demoted = RetentionScorer::autoDemote($db, $threshold);
echo '[' . date('Y-m-d H:i:s') . '] Demoted ' . $demoted . ' songs below threshold ' . $threshold . "\n";
