<?php
/**
 * Scan and Tag — runs in background, looks up metadata from MusicBrainz.
 *
 * Usage:
 *   php fix_genres.php           — scan all songs
 *   php fix_genres.php --auto    — scan only untagged songs (for cron)
 *   php fix_genres.php --dry-run — preview only, no changes
 *
 * Logic:
 *   1. Songs with no title → derive title from filename (strip track numbers)
 *   2. If AcoustID API key is configured → fingerprint each song via fpcalc
 *      and look up title, artist, album, genre from AcoustID + MusicBrainz
 *   3. AI-type artists → extract genre from title parentheses
 *   4. Other artists → query MusicBrainz API (rate limited 1 req/sec)
 *      a. Look up artist → get genre from tags
 *      b. Look up recording by title+artist → get album name
 *   5. If no genre found → keep current genre
 *   6. Write updated tags back to the audio file via ffmpeg
 *
 * Progress is written to /tmp/iradio_genre_scan.json so the admin UI
 * can poll for status.
 */

declare(strict_types=1);

require __DIR__ . '/../core/Database.php';
require __DIR__ . '/../core/ArtistNormalizer.php';

$dryRun   = in_array('--dry-run', $argv ?? []);
$autoMode = in_array('--auto', $argv ?? []);

$db = Database::get();

// ── Lock file — prevent concurrent runs ─────────────────
$lockFile = '/tmp/iradio_genre_scan.lock';
if (file_exists($lockFile)) {
    $pid = (int) @file_get_contents($lockFile);
    if ($pid > 0 && file_exists("/proc/{$pid}")) {
        exit(0); // Another scan is running
    }
    @unlink($lockFile); // Stale lock
}
file_put_contents($lockFile, (string) getmypid());
@chmod($lockFile, 0666);

// ── Auto mode: check setting ────────────────────────────
if ($autoMode) {
    $stmt = $db->prepare("SELECT value FROM settings WHERE key = 'auto_tag_enabled'");
    $stmt->execute();
    $row = $stmt->fetch();
    if (!$row || $row['value'] !== 'true') {
        @unlink($lockFile);
        exit(0); // Auto-tagging disabled
    }
}

/** Log helper — outputs to stdout (captured by cron >> log file) */
function logMsg(string $msg, bool $autoMode): void
{
    if ($autoMode) {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    }
}

logMsg('Auto-tag started', $autoMode);

// ── Progress tracking ───────────────────────────────────
$progress = [
    'status'    => 'running',
    'total'     => 0,
    'processed' => 0,
    'updated'   => 0,
    'skipped'   => 0,
    'started_at' => date('c'),
];

function writeProgress(array &$progress): void
{
    $file = '/tmp/iradio_genre_scan.json';
    @file_put_contents($file, json_encode($progress), LOCK_EX);
    @chmod($file, 0666);
}

// ── Load AcoustID API key from settings ──────────────────
$acoustidKey = '';
$stmt = $db->prepare("SELECT value FROM settings WHERE key = 'acoustid_api_key'");
$stmt->execute();
$row = $stmt->fetch();
if ($row && !empty(trim($row['value']))) {
    $acoustidKey = trim($row['value']);
}

// ── HTTP context for MusicBrainz ────────────────────────
$ua  = 'iRadio/1.0 (scan-and-tag)';
$ctx = stream_context_create(['http' => [
    'header'  => "User-Agent: {$ua}\r\nAccept: application/json\r\n",
    'timeout' => 10,
]]);

// ── Genre map (shared) ──────────────────────────────────
$genreMap = [
    'country' => 'Country', 'country pop' => 'Country', 'country rock' => 'Country',
    'contemporary country' => 'Country', 'outlaw country' => 'Country',
    'alt-country' => 'Country', 'americana' => 'Country', 'bluegrass' => 'Country',
    'rock' => 'Rock', 'soft rock' => 'Rock', 'hard rock' => 'Rock',
    'classic rock' => 'Rock', 'progressive rock' => 'Rock', 'pop rock' => 'Pop Rock',
    'alternative rock' => 'Rock', 'indie rock' => 'Rock',
    'pop' => 'Pop', 'adult contemporary' => 'Pop', 'singer-songwriter' => 'Pop',
    'jazz' => 'Jazz', 'swing' => 'Jazz', 'soul' => 'Soul', 'r&b' => 'R&B',
    'funk' => 'Funk', 'blues' => 'Blues', 'folk' => 'Folk',
    'electronic' => 'Electronic', 'dance' => 'Electronic', 'disco' => 'Disco',
    'hip hop' => 'Hip Hop', 'rap' => 'Hip Hop',
    'classical' => 'Classical', 'reggae' => 'Reggae', 'latin' => 'Latin',
    'metal' => 'Metal', 'punk' => 'Punk', 'gospel' => 'Gospel',
    'christian' => 'Christian', 'new age' => 'New Age', 'world' => 'World',
    'easy listening' => 'Easy Listening',
];

// ── Category cache ──────────────────────────────────────
$categoryCache = [];
$stmt = $db->query("SELECT id, name FROM categories");
while ($row = $stmt->fetch()) {
    $categoryCache[strtolower($row['name'])] = (int) $row['id'];
}

function findOrCreateCategory(PDO $db, string $name, array &$cache): int
{
    $key = strtolower(trim($name));
    if (isset($cache[$key])) return $cache[$key];

    $stmt = $db->prepare("SELECT id FROM categories WHERE LOWER(name) = LOWER(:name)");
    $stmt->execute(['name' => trim($name)]);
    $row = $stmt->fetch();
    if ($row) {
        $cache[$key] = (int) $row['id'];
        return (int) $row['id'];
    }

    $stmt = $db->prepare("INSERT INTO categories (name, type) VALUES (:name, 'music') RETURNING id");
    $stmt->execute(['name' => trim($name)]);
    $id = (int) $stmt->fetchColumn();
    $cache[$key] = $id;
    return $id;
}

// ── Artist cache ────────────────────────────────────────
$artistCache = [];
$stmt = $db->query("SELECT id, name FROM artists");
while ($row = $stmt->fetch()) {
    $artistCache[strtolower($row['name'])] = (int) $row['id'];
}

function findOrCreateArtist(PDO $db, string $name, array &$cache): int
{
    $name = ArtistNormalizer::extractPrimary($name, $db);
    $key = strtolower(trim($name));
    if (isset($cache[$key])) return $cache[$key];

    $stmt = $db->prepare("SELECT id FROM artists WHERE normalized_name = :norm");
    $stmt->execute(['norm' => $key]);
    $row = $stmt->fetch();
    if ($row) {
        $cache[$key] = (int) $row['id'];
        return (int) $row['id'];
    }

    $stmt = $db->prepare("INSERT INTO artists (name, normalized_name) VALUES (:name, :norm) RETURNING id");
    $stmt->execute(['name' => trim($name), 'norm' => $key]);
    $id = (int) $stmt->fetchColumn();
    $cache[$key] = $id;
    return $id;
}

// ── Map genre string to display name ────────────────────
function mapGenre(string $raw, array &$genreMap): ?string
{
    $lower = strtolower($raw);
    if (isset($genreMap[$lower])) return $genreMap[$lower];
    foreach ($genreMap as $key => $display) {
        if (str_contains($lower, $key)) return $display;
    }
    return null;
}

// ── AcoustID: fingerprint + lookup ──────────────────────
function lookupAcoustID(string $filePath, string $apiKey, $ctx, array &$genreMap): ?array
{
    // Run fpcalc
    $cmd = 'fpcalc -json ' . escapeshellarg($filePath) . ' 2>/dev/null';
    $output = shell_exec($cmd);
    if (!$output) return null;

    $fp = json_decode($output, true);
    if (!$fp || empty($fp['fingerprint']) || empty($fp['duration'])) return null;

    // Query AcoustID API
    $params = http_build_query([
        'client'      => $apiKey,
        'fingerprint' => $fp['fingerprint'],
        'duration'    => (int) $fp['duration'],
        'meta'        => 'recordings releasegroups',
    ]);
    $url = "https://api.acoustid.org/v2/lookup?" . $params;

    $resp = @file_get_contents($url, false, $ctx);
    if (!$resp) return null;

    $data = json_decode($resp, true);
    $results = $data['results'] ?? [];
    if (empty($results)) return null;

    // Take the best scoring result
    $best = $results[0];
    $score = (float) ($best['score'] ?? 0);
    if ($score < 0.7) return null;

    $recordings = $best['recordings'] ?? [];
    if (empty($recordings)) return null;

    $rec = $recordings[0];
    $result = [];

    // Title
    if (!empty($rec['title'])) {
        $result['title'] = trim($rec['title']);
    }

    // Artist
    $artists = $rec['artists'] ?? [];
    if (!empty($artists)) {
        $result['artist'] = trim($artists[0]['name'] ?? '');
    }

    // Album + Genre from release groups
    $releaseGroups = $rec['releasegroups'] ?? [];
    foreach ($releaseGroups as $rg) {
        // Prefer "Album" type
        $type = $rg['type'] ?? '';
        if (!empty($rg['title']) && empty($result['album'])) {
            if (strtolower($type) === 'album') {
                $result['album'] = trim($rg['title']);
            }
        }
    }
    // Fall back to first release group title
    if (empty($result['album']) && !empty($releaseGroups[0]['title'])) {
        $result['album'] = trim($releaseGroups[0]['title']);
    }

    return !empty($result) ? $result : null;
}

// ── MusicBrainz: get genre for a recording MBID ─────────
function lookupRecordingGenre(string $mbid, $ctx, array &$genreMap): ?string
{
    $url = "https://musicbrainz.org/ws/2/recording/{$mbid}?inc=genres+tags&fmt=json";
    $resp = @file_get_contents($url, false, $ctx);
    if (!$resp) return null;

    $data = json_decode($resp, true);
    $allEntries = array_merge($data['genres'] ?? [], $data['tags'] ?? []);
    usort($allEntries, fn($a, $b) => ($b['count'] ?? 0) - ($a['count'] ?? 0));

    foreach ($allEntries as $entry) {
        $mapped = mapGenre($entry['name'], $genreMap);
        if ($mapped) return $mapped;
    }
    return null;
}

// ── MusicBrainz: artist genre lookup ────────────────────
function lookupMusicBrainzGenre(string $artistName, $ctx, array &$genreMap): ?string
{
    $query = urlencode($artistName);
    $url = "https://musicbrainz.org/ws/2/artist/?query=artist:" . $query . "&limit=1&fmt=json";

    $resp = @file_get_contents($url, false, $ctx);
    if (!$resp) return null;

    $data = json_decode($resp, true);
    $artists = $data['artists'] ?? [];
    if (empty($artists)) return null;

    $mbid  = $artists[0]['id'] ?? null;
    $score = (int) ($artists[0]['score'] ?? 0);

    if (!$mbid || $score < 80) return null;

    sleep(1); // rate limit

    $url2 = "https://musicbrainz.org/ws/2/artist/{$mbid}?inc=genres+tags&fmt=json";
    $resp2 = @file_get_contents($url2, false, $ctx);
    if (!$resp2) return null;

    $data2 = json_decode($resp2, true);

    $allEntries = array_merge($data2['genres'] ?? [], $data2['tags'] ?? []);
    usort($allEntries, fn($a, $b) => ($b['count'] ?? 0) - ($a['count'] ?? 0));

    foreach ($allEntries as $entry) {
        $mapped = mapGenre($entry['name'], $genreMap);
        if ($mapped) return $mapped;
    }

    return null;
}

// ── MusicBrainz: recording lookup for album + year ───────
function lookupMusicBrainzAlbum(string $title, string $artistName, $ctx): ?array
{
    $query = urlencode('recording:"' . $title . '" AND artist:"' . $artistName . '"');
    $url = "https://musicbrainz.org/ws/2/recording/?query=" . $query . "&limit=1&fmt=json";

    $resp = @file_get_contents($url, false, $ctx);
    if (!$resp) return null;

    $data = json_decode($resp, true);
    $recordings = $data['recordings'] ?? [];
    if (empty($recordings)) return null;

    $score = (int) ($recordings[0]['score'] ?? 0);
    if ($score < 80) return null;

    $releases = $recordings[0]['releases'] ?? [];
    if (empty($releases)) return null;

    // Prefer the first "Album" type release; fall back to first release
    $albumTitle = null;
    $year       = null;
    foreach ($releases as $rel) {
        $group = $rel['release-group']['primary-type'] ?? '';
        $relDate = $rel['date'] ?? '';
        if (strtolower($group) === 'album') {
            $albumTitle = trim($rel['title']);
            if ($relDate && preg_match('/\b(19|20)\d{2}\b/', $relDate, $m)) {
                $year = (int) $m[0];
            }
            break;
        }
    }

    if ($albumTitle === null) {
        $albumTitle = trim($releases[0]['title']);
        $relDate = $releases[0]['date'] ?? '';
        if ($relDate && preg_match('/\b(19|20)\d{2}\b/', $relDate, $m)) {
            $year = (int) $m[0];
        }
    }

    return ['album' => $albumTitle, 'year' => $year];
}

// ── Extract genre from title parentheses (AI covers) ────
function extractGenreFromTitle(string $title): ?string
{
    if (preg_match('/\(([^)]+)\)\s*$/', $title, $m)) {
        $hint = strtolower(trim($m[1]));

        if (str_contains($hint, 'jazz') || str_contains($hint, 'swing'))   return 'Jazz';
        if (str_contains($hint, 'soul') || str_contains($hint, 'motown'))  return 'Soul';
        if (str_contains($hint, 'funk'))                                    return 'Funk';
        if (str_contains($hint, 'blues'))                                   return 'Blues';
        if (str_contains($hint, 'rock'))                                    return 'Rock';
        if (str_contains($hint, 'country'))                                 return 'Country';
        if (str_contains($hint, 'reggae'))                                  return 'Reggae';
        if (str_contains($hint, 'pop'))                                     return 'Pop';
        if (str_contains($hint, 'classical'))                               return 'Classical';
        if (str_contains($hint, 'electronic') || str_contains($hint, 'edm')) return 'Electronic';
    }
    return null;
}

// ── Rename file to "Artist - Title.ext" ──────────────────
function renameAfterTag(PDO $db, int $songId, string $filePath, string $artist, string $title): ?string
{
    $dir = dirname($filePath);
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

    // Sanitize artist and title for filename
    $safeArtist = preg_replace('/[\/\\\\:*?"<>|]/', '', trim($artist));
    $safeTitle  = preg_replace('/[\/\\\\:*?"<>|]/', '', trim($title));
    if (empty($safeArtist) || empty($safeTitle)) return null;

    $newName = $safeArtist . ' - ' . $safeTitle . '.' . $ext;
    $newPath = $dir . '/' . $newName;

    // Skip if already named correctly
    if ($newPath === $filePath) return null;

    // Avoid overwriting an existing file
    if (file_exists($newPath)) return null;

    if (!rename($filePath, $newPath)) return null;

    // Update DB file_path
    $db->prepare("UPDATE songs SET file_path = :path WHERE id = :id")
       ->execute(['path' => $newPath, 'id' => $songId]);

    return $newPath;
}

// ── Write tags back to audio file via ffmpeg ────────────
function writeTagsToFile(string $filePath, array $tags): bool
{
    if (!file_exists($filePath)) return false;

    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $supported = ['mp3', 'flac', 'ogg', 'wav', 'm4a', 'aac', 'opus', 'wma'];
    if (!in_array($ext, $supported)) return false;

    $meta = [];
    foreach ($tags as $key => $value) {
        if ($value !== '' && $value !== null) {
            $meta[] = '-metadata';
            $meta[] = $key . '=' . $value;
        }
    }
    if (empty($meta)) return false;

    $tmpFile = '/tmp/iradio_tag_' . md5($filePath) . '.' . $ext;

    $cmd = 'ffmpeg -y -i ' . escapeshellarg($filePath);
    // map all streams (audio, cover art, etc.)
    $cmd .= ' -map 0 -codec copy';
    foreach ($meta as $part) {
        $cmd .= ' ' . escapeshellarg($part);
    }
    $cmd .= ' ' . escapeshellarg($tmpFile) . ' 2>/dev/null';

    exec($cmd, $output, $exitCode);

    if ($exitCode === 0 && file_exists($tmpFile) && filesize($tmpFile) > 0) {
        rename($tmpFile, $filePath);
        return true;
    }

    // Clean up failed temp file
    @unlink($tmpFile);
    return false;
}

// ── Clean title from filename ───────────────────────────
function titleFromFilename(string $filePath): string
{
    $name = pathinfo($filePath, PATHINFO_FILENAME);
    // Strip leading track numbers: "01 - ", "220 - ", "02_", "1. ", etc.
    $name = preg_replace('/^\d+[\s._-]+/', '', $name);
    // Strip leading timestamps: "1771589531_"
    $name = preg_replace('/^\d{10,}_/', '', $name);
    // Replace underscores with spaces
    $name = str_replace('_', ' ', $name);
    // Collapse whitespace
    $name = trim(preg_replace('/\s+/', ' ', $name));
    return $name;
}

// ── Main ─────────────────────────────────────────────────

// Get songs — only process untagged songs (both auto and manual)
$stmt = $db->query("
    SELECT s.id, s.title, s.year, s.file_path, s.artist_id,
           a.name AS artist_name, c.name AS current_genre
    FROM songs s
    JOIN artists a ON a.id = s.artist_id
    JOIN categories c ON c.id = s.category_id
    WHERE s.tagged_at IS NULL
    ORDER BY a.name, s.id
");
$allSongs = $stmt->fetchAll();

$progress['total'] = count($allSongs);
writeProgress($progress);

logMsg('Found ' . count($allSongs) . ' songs to process', $autoMode);
if (count($allSongs) === 0) {
    logMsg('No untagged songs — exiting', $autoMode);
    $progress['status']      = 'done';
    $progress['finished_at'] = date('c');
    writeProgress($progress);
    if ($autoMode) {
        @file_put_contents('/tmp/iradio_auto_tag_last.json', json_encode([
            'ran_at'    => date('c'),
            'total'     => 0,
            'updated'   => 0,
            'skipped'   => 0,
            'message'   => 'No untagged songs found',
        ]), LOCK_EX);
        @chmod('/tmp/iradio_auto_tag_last.json', 0666);
    }
    @unlink($lockFile);
    exit(0);
}

$artistGenreCache = [];
$stopFile         = '/tmp/iradio_genre_scan.lock.stop';

foreach ($allSongs as $song) {
    // Check for stop signal
    if (file_exists($stopFile)) {
        @unlink($stopFile);
        $progress['status']      = 'stopped';
        $progress['finished_at'] = date('c');
        writeProgress($progress);
        logMsg('Scan stopped — ' . $progress['processed'] . '/' . $progress['total'] . ' processed, ' . $progress['updated'] . ' updated', $autoMode);
        if ($autoMode) {
            @file_put_contents('/tmp/iradio_auto_tag_last.json', json_encode([
                'ran_at'    => date('c'),
                'total'     => $progress['total'],
                'updated'   => $progress['updated'],
                'skipped'   => $progress['skipped'],
                'message'   => 'Stopped — ' . $progress['updated'] . ' updated so far',
            ]), LOCK_EX);
            @chmod('/tmp/iradio_auto_tag_last.json', 0666);
        }
        @unlink('/tmp/iradio_genre_scan.lock');
        exit(0);
    }

    $artistName = $song['artist_name'];
    $title      = $song['title'];
    $genre      = $song['current_genre'];
    $artistId   = (int) $song['artist_id'];
    $songId     = (int) $song['id'];
    $tagsChanged = false;

    // Step 1: Fix empty/missing title from filename
    if (empty(trim($title))) {
        $title = titleFromFilename($song['file_path']);
        if ($title !== '' && !$dryRun) {
            $db->prepare("UPDATE songs SET title = :title WHERE id = :id")
               ->execute(['title' => $title, 'id' => $songId]);
            $progress['updated']++;
            $tagsChanged = true;
        }
    }

    // Step 2: AcoustID fingerprint lookup (if API key configured)
    if ($acoustidKey !== '' && file_exists($song['file_path'])) {
        $acoustResult = lookupAcoustID($song['file_path'], $acoustidKey, $ctx, $genreMap);
        sleep(1); // AcoustID rate limit

        if ($acoustResult) {
            $updates = [];
            $params  = ['id' => $songId];

            // Update title if current is empty
            if (!empty($acoustResult['title']) && empty(trim($title))) {
                $updates[] = 'title = :title';
                $params['title'] = $acoustResult['title'];
                $title = $acoustResult['title'];
            }

            // Update artist if current is "Unknown Artist"
            if (!empty($acoustResult['artist'])) {
                if (strtolower($artistName) === 'unknown artist' || strtolower($artistName) === 'unknown') {
                    $newArtistId = findOrCreateArtist($db, $acoustResult['artist'], $artistCache);
                    $updates[] = 'artist_id = :artist_id';
                    $params['artist_id'] = $newArtistId;
                    $artistName = $acoustResult['artist'];
                    $artistId = $newArtistId;
                }
            }

            if (!empty($updates) && !$dryRun) {
                $sql = "UPDATE songs SET " . implode(', ', $updates) . " WHERE id = :id";
                $db->prepare($sql)->execute($params);
                $progress['updated']++;
                $tagsChanged = true;
            }
        }
    }

    // Step 3: Determine genre
    $isAiArtist = (stripos($artistName, 'a.i.') !== false
                || stripos($artistName, 'ai ') !== false
                || stripos($artistName, 'ai cover') !== false);

    if ($isAiArtist) {
        $extracted = extractGenreFromTitle($title);
        if ($extracted && strtolower($extracted) !== strtolower($genre)) {
            $genre = $extracted;
            $catId = findOrCreateCategory($db, $genre, $categoryCache);
            if (!$dryRun) {
                $db->prepare("UPDATE songs SET category_id = :cat WHERE id = :id")
                   ->execute(['cat' => $catId, 'id' => $songId]);
            }
            $progress['updated']++;
            $tagsChanged = true;
        }
    } else {
        // Genre lookup (cached per artist)
        if (isset($artistGenreCache[$artistName])) {
            $lookedUpGenre = $artistGenreCache[$artistName];
        } else {
            $lookedUpGenre = lookupMusicBrainzGenre($artistName, $ctx, $genreMap);
            sleep(1); // rate limit
            $artistGenreCache[$artistName] = $lookedUpGenre;
        }

        if ($lookedUpGenre) {
            if (strtolower($lookedUpGenre) !== strtolower($genre)) {
                $genre = $lookedUpGenre;
                $catId = findOrCreateCategory($db, $genre, $categoryCache);
                if (!$dryRun) {
                    $db->prepare("UPDATE songs SET category_id = :cat WHERE id = :id")
                       ->execute(['cat' => $catId, 'id' => $songId]);
                }
                $progress['updated']++;
                $tagsChanged = true;
            }
        } else {
            $progress['skipped']++;
        }

        // Step 4: Year lookup if still missing
        $currentYear = $song['year'] ? (int) $song['year'] : null;
        if ($currentYear === null && !empty(trim($title))) {
            $cacheKey = strtolower($artistName . '|' . $title);
            sleep(1); // rate limit
            $mbResult = lookupMusicBrainzAlbum($title, $artistName, $ctx);

            if ($mbResult && !empty($mbResult['year']) && !$dryRun) {
                $db->prepare("UPDATE songs SET year = :year WHERE id = :id")
                   ->execute(['year' => $mbResult['year'], 'id' => $songId]);
                $progress['updated']++;
                $tagsChanged = true;
            }
        }
    }

    // Step 5: Write tags back to audio file
    $currentPath = $song['file_path'];
    if ($tagsChanged && !$dryRun && file_exists($currentPath)) {
        $writeTags = [
            'title'  => $title,
            'artist' => $artistName,
            'genre'  => $genre,
        ];
        if (!empty($mbResult['year'])) {
            $writeTags['date'] = (string) $mbResult['year'];
        }
        writeTagsToFile($currentPath, $writeTags);
    }

    // Step 6: Rename file to "Artist - Title.ext"
    if (!$dryRun && !empty(trim($title)) && file_exists($currentPath)) {
        $renamed = renameAfterTag($db, $songId, $currentPath, $artistName, $title);
        if ($renamed) $currentPath = $renamed;
    }

    // Mark song as processed
    if (!$dryRun) {
        $db->prepare("UPDATE songs SET tagged_at = NOW() WHERE id = :id")
           ->execute(['id' => $songId]);
    }

    $progress['processed']++;
    writeProgress($progress);
}

$progress['status']      = 'done';
$progress['finished_at'] = date('c');
writeProgress($progress);
@unlink('/tmp/iradio_genre_scan.lock');
@unlink($stopFile);

logMsg('Scan complete — ' . $progress['updated'] . ' updated, ' . $progress['skipped'] . ' skipped out of ' . $progress['total'], $autoMode);

// Write auto-tag summary for the Settings UI
if ($autoMode) {
    @file_put_contents('/tmp/iradio_auto_tag_last.json', json_encode([
        'ran_at'    => date('c'),
        'total'     => $progress['total'],
        'updated'   => $progress['updated'],
        'skipped'   => $progress['skipped'],
        'message'   => $progress['updated'] . ' updated, ' . $progress['skipped'] . ' skipped',
    ]), LOCK_EX);
    @chmod('/tmp/iradio_auto_tag_last.json', 0666);
}
