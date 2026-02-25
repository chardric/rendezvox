<?php

declare(strict_types=1);

/**
 * Icecast Stats Collector
 *
 * Polls Icecast admin stats XML, extracts listener count for the /live mount,
 * computes the hourly peak, and inserts a row into listener_stats.
 *
 * Intended to run via cron every minute.
 */

require __DIR__ . '/../core/Database.php';

// ── Configuration from environment ───────────────────────────

$icecastHost = getenv('RENDEZVOX_ICECAST_HOST') ?: 'icecast';
$icecastPort = getenv('RENDEZVOX_ICECAST_PORT') ?: '8000';
$adminPass   = getenv('ICECAST_ADMIN_PASSWORD') ?: 'changeme_admin';
$mount       = '/live';

$statsUrl = "http://{$icecastHost}:{$icecastPort}/admin/stats.xml";

// ── Fetch Icecast stats XML ──────────────────────────────────

$context = stream_context_create([
    'http' => [
        'method'  => 'GET',
        'header'  => 'Authorization: Basic ' . base64_encode("admin:{$adminPass}"),
        'timeout' => 5,
    ],
]);

$xml = @file_get_contents($statsUrl, false, $context);

if ($xml === false) {
    fwrite(STDERR, date('c') . " ERROR: Failed to fetch {$statsUrl}\n");
    exit(1);
}

// ── Parse XML ────────────────────────────────────────────────

libxml_use_internal_errors(true);
$doc = simplexml_load_string($xml);

if ($doc === false) {
    fwrite(STDERR, date('c') . " ERROR: Failed to parse Icecast stats XML\n");
    exit(1);
}

// Find the /live mount source
$currentListeners = null;

foreach ($doc->source as $source) {
    if ((string) $source['mount'] === $mount) {
        $currentListeners = (int) $source->listeners;
        break;
    }
}

if ($currentListeners === null) {
    fwrite(STDERR, date('c') . " ERROR: Mount {$mount} not found in Icecast stats\n");
    exit(1);
}

// ── Compute hourly peak ──────────────────────────────────────

$db = Database::get();

$stmt = $db->query("
    SELECT COALESCE(MAX(listener_count), 0) AS hourly_max
    FROM listener_stats
    WHERE recorded_at >= date_trunc('hour', NOW())
");
$hourlyMax = (int) $stmt->fetchColumn();

$peak = max($hourlyMax, $currentListeners);

// ── Insert row ───────────────────────────────────────────────

$db->prepare('
    INSERT INTO listener_stats (listener_count, peak_listeners)
    VALUES (:count, :peak)
')->execute([
    'count' => $currentListeners,
    'peak'  => $peak,
]);

echo date('c') . " OK: listeners={$currentListeners} peak={$peak}\n";
exit(0);
