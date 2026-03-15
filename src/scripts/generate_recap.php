<?php
/**
 * Show Recap Generator — daily cron job to create AI-generated show summaries.
 *
 * Usage:
 *   php generate_recap.php
 *
 * Queries play_history for the day's songs, sends to Gemini for a
 * natural-language summary, stores in show_recaps table.
 */

declare(strict_types=1);

require __DIR__ . '/../core/Database.php';
require __DIR__ . '/../core/MetadataLookup.php';

$db = Database::get();

// Check if recap generation is enabled
$stmt = $db->prepare("SELECT value FROM settings WHERE key = 'recap_enabled'");
$stmt->execute();
$row = $stmt->fetch();
if (!$row || $row['value'] !== 'true') {
    exit(0);
}

// Get Gemini API key
$keyStmt = $db->prepare("SELECT value FROM settings WHERE key = 'gemini_api_key'");
$keyStmt->execute();
$keyRow = $keyStmt->fetch();
$apiKey = $keyRow ? $keyRow['value'] : '';
if (!$apiKey) {
    echo '[' . date('Y-m-d H:i:s') . '] No Gemini API key configured — skipping recap' . "\n";
    exit(0);
}

// Get station timezone
$tzStmt = $db->prepare("SELECT value FROM settings WHERE key = 'station_timezone'");
$tzStmt->execute();
$tzRow = $tzStmt->fetch();
$tz = $tzRow ? $tzRow['value'] : 'UTC';

// Get station name
$nameStmt = $db->prepare("SELECT value FROM settings WHERE key = 'station_name'");
$nameStmt->execute();
$nameRow = $nameStmt->fetch();
$stationName = $nameRow ? $nameRow['value'] : 'RendezVox';

// Yesterday's date in station timezone
$yesterday = (new \DateTime('yesterday', new \DateTimeZone($tz)))->format('Y-m-d');

// Check if recap already exists for this date
$existsStmt = $db->prepare("SELECT id FROM show_recaps WHERE recap_date = :date AND recap_type = 'daily'");
$existsStmt->execute(['date' => $yesterday]);
if ($existsStmt->fetch()) {
    echo '[' . date('Y-m-d H:i:s') . '] Recap already exists for ' . $yesterday . "\n";
    exit(0);
}

echo '[' . date('Y-m-d H:i:s') . '] Generating recap for ' . $yesterday . '...' . "\n";

// Gather play statistics for yesterday
$statsStmt = $db->prepare("
    SELECT
        COUNT(DISTINCT ph.song_id) AS unique_songs,
        COUNT(*) AS total_plays,
        COUNT(DISTINCT s.artist_id) AS unique_artists,
        COALESCE(MAX(ph.listener_count), 0) AS peak_listeners,
        COALESCE(ROUND(AVG(ph.listener_count)), 0) AS avg_listeners
    FROM play_history ph
    JOIN songs s ON s.id = ph.song_id
    WHERE ph.started_at::date = :date
      AND ph.source != 'station_id'
");
$statsStmt->execute(['date' => $yesterday]);
$stats = $statsStmt->fetch();

// Top genres played
$genresStmt = $db->prepare("
    SELECT c.name, COUNT(*) AS cnt
    FROM play_history ph
    JOIN songs s ON s.id = ph.song_id
    JOIN categories c ON c.id = s.category_id
    WHERE ph.started_at::date = :date
      AND ph.source != 'station_id'
    GROUP BY c.name
    ORDER BY cnt DESC
    LIMIT 5
");
$genresStmt->execute(['date' => $yesterday]);
$genres = $genresStmt->fetchAll(\PDO::FETCH_ASSOC);

// Most played songs
$topSongsStmt = $db->prepare("
    SELECT s.title, a.name AS artist, COUNT(*) AS plays
    FROM play_history ph
    JOIN songs s ON s.id = ph.song_id
    JOIN artists a ON a.id = s.artist_id
    WHERE ph.started_at::date = :date
      AND ph.source != 'station_id'
    GROUP BY s.title, a.name
    ORDER BY plays DESC
    LIMIT 5
");
$topSongsStmt->execute(['date' => $yesterday]);
$topSongs = $topSongsStmt->fetchAll(\PDO::FETCH_ASSOC);

// Most requested
$requestsStmt = $db->prepare("
    SELECT s.title, a.name AS artist, COUNT(*) AS reqs
    FROM song_requests sr
    JOIN songs s ON s.id = sr.song_id
    JOIN artists a ON a.id = s.artist_id
    WHERE sr.created_at::date = :date
      AND sr.status IN ('played', 'approved')
    GROUP BY s.title, a.name
    ORDER BY reqs DESC
    LIMIT 3
");
$requestsStmt->execute(['date' => $yesterday]);
$requests = $requestsStmt->fetchAll(\PDO::FETCH_ASSOC);

// Build prompt for Gemini
$genreList = implode(', ', array_map(fn($g) => $g['name'] . ' (' . $g['cnt'] . ')', $genres));
$songList = implode("\n", array_map(fn($s) => '- ' . $s['title'] . ' by ' . $s['artist'] . ' (' . $s['plays'] . ' plays)', $topSongs));
$requestList = empty($requests)
    ? 'No requests today.'
    : implode("\n", array_map(fn($r) => '- ' . $r['title'] . ' by ' . $r['artist'], $requests));

$dayName = (new \DateTime($yesterday, new \DateTimeZone($tz)))->format('l, F j');

$prompt = <<<PROMPT
Write a concise, engaging daily recap for "{$stationName}" radio station for {$dayName}.

Stats:
- {$stats['total_plays']} songs played ({$stats['unique_songs']} unique)
- {$stats['unique_artists']} different artists
- Peak listeners: {$stats['peak_listeners']}, Average: {$stats['avg_listeners']}
- Top genres: {$genreList}

Most played songs:
{$songList}

Listener requests:
{$requestList}

Write in a warm, radio-host tone. 3-5 sentences max. Include one highlight about the most played song or a genre trend. End with a teaser for tomorrow. Do not use hashtags or emojis. Return ONLY the recap text, nothing else.
PROMPT;

// Call Gemini API
$url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . urlencode($apiKey);
$payload = json_encode([
    'contents' => [['parts' => [['text' => $prompt]]]],
    'generationConfig' => [
        'temperature'    => 0.7,
        'maxOutputTokens' => 512,
        'thinkingConfig' => ['thinkingBudget' => 0],
    ],
]);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
]);
$resp = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || !$resp) {
    echo '[' . date('Y-m-d H:i:s') . '] Gemini API error (HTTP ' . $httpCode . ')' . "\n";
    exit(1);
}

$json = json_decode($resp, true);
$recapText = $json['candidates'][0]['content']['parts'][0]['text'] ?? null;

if (!$recapText) {
    echo '[' . date('Y-m-d H:i:s') . '] Empty response from Gemini' . "\n";
    exit(1);
}

$recapText = trim($recapText);
$title = $stationName . ' — ' . $dayName;

// Store recap
$insertStmt = $db->prepare("
    INSERT INTO show_recaps (recap_date, recap_type, title, body, generated_by)
    VALUES (:date, 'daily', :title, :body, 'gemini')
");
$insertStmt->execute([
    'date'  => $yesterday,
    'title' => $title,
    'body'  => $recapText,
]);

echo '[' . date('Y-m-d H:i:s') . '] Recap generated for ' . $yesterday . ': ' . mb_substr($recapText, 0, 100) . '...' . "\n";
