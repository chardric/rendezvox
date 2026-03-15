<?php
/**
 * Scan and Tag — runs in background, looks up metadata from MusicBrainz.
 *
 * Usage:
 *   php fix_genres.php           — scan untagged songs
 *   php fix_genres.php --auto    — scan only untagged songs (for cron)
 *   php fix_genres.php --all     — re-scan ALL songs (re-tag + re-fetch covers)
 *   php fix_genres.php --verify  — batch-verify all tagged songs via Gemini AI
 *   php fix_genres.php --dry-run — preview only, no changes
 *
 * Logic:
 *   1. Songs with no title → derive title from filename (strip track numbers)
 *   2. If AcoustID API key is configured → fingerprint each song via fpcalc
 *      and look up title, artist, album from AcoustID + MusicBrainz
 *   3. Genre detection (priority order):
 *      a. AI-type artists → extract genre from title parentheses
 *      b. MusicBrainz recording genre (track-specific, most accurate)
 *      c. Title keyword heuristics (Christian, etc.)
 *      d. MusicBrainz artist genre (artist-level)
 *      e. TheAudioDB fallback
 *      f. Embedded genre tag from audio file (ID3/Vorbis, least reliable)
 *   4. Write updated tags back to the audio file via ffmpeg
 *
 * Progress is written to /tmp/rendezvox_genre_scan.json so the admin UI
 * can poll for status.
 */

declare(strict_types=1);

require __DIR__ . '/../core/Database.php';
require __DIR__ . '/../core/MetadataExtractor.php';
require __DIR__ . '/../core/MetadataLookup.php';
require __DIR__ . '/../core/ArtistNormalizer.php';

$dryRun     = in_array('--dry-run', $argv ?? []);
$autoMode   = in_array('--auto', $argv ?? []);
$allMode    = in_array('--all', $argv ?? []);
$verifyMode = in_array('--verify', $argv ?? []);

$db = Database::get();

// ── Lock file — prevent concurrent runs ─────────────────
$lockFile = '/tmp/rendezvox_genre_scan.lock';
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
    'covers'    => 0,
    'relocated' => 0,
    'started_at' => date('c'),
];

function writeProgress(array &$progress): void
{
    $file = '/tmp/rendezvox_genre_scan.json';
    @file_put_contents($file, json_encode($progress), LOCK_EX);
    @chmod($file, 0666);
}

// ── Initialize MetadataLookup ────────────────────────────
$lookup = new MetadataLookup();

$stmt = $db->prepare("SELECT key, value FROM settings WHERE key IN ('acoustid_api_key', 'theaudiodb_api_key', 'gemini_api_key', 'ollama_url', 'ollama_model', 'ai_provider')");
$stmt->execute();
$aiProvider = 'gemini_ollama';
while ($row = $stmt->fetch()) {
    if ($row['key'] === 'acoustid_api_key' && !empty(trim($row['value']))) {
        $lookup->setAcoustIdKey(trim($row['value']));
    }
    if ($row['key'] === 'theaudiodb_api_key' && !empty(trim($row['value']))) {
        $lookup->setTheAudioDbKey(trim($row['value']));
    }
    if ($row['key'] === 'ai_provider') {
        $aiProvider = trim($row['value']) ?: 'gemini_ollama';
    }
    if ($row['key'] === 'gemini_api_key' && !empty(trim($row['value']))) {
        $lookup->setGeminiApiKey(trim($row['value']));
    }
    if ($row['key'] === 'ollama_url' && !empty(trim($row['value']))) {
        $lookup->setOllamaUrl(trim($row['value']));
    }
    if ($row['key'] === 'ollama_model' && !empty(trim($row['value']))) {
        $lookup->setOllamaModel(trim($row['value']));
    }
}
$useGemini = in_array($aiProvider, ['gemini', 'gemini_ollama']);
$useOllama = in_array($aiProvider, ['ollama', 'gemini_ollama']);

$musicDir = '/var/lib/rendezvox/music';

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
    $key = strtolower(trim($name));
    if (isset($cache[$key])) return $cache[$key];
    $id = ArtistNormalizer::findOrCreate($db, $name);
    $cache[$key] = $id;
    return $id;
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

// ── Detect genre from title keywords ─────────────────────
function detectGenreByKeywords(string $title): ?string
{
    $lower = strtolower($title);
    $christianWords = ['jesus', 'christ', 'god\'s', 'gospel', 'hymn', 'praise',
        'worship', 'hallelujah', 'amen', 'blessed assurance', 'savior', 'saviour',
        'calvary', 'redeemer', 'holy spirit', 'amazing grace', 'rugged cross',
        'precious memories', 'how great thou art'];
    foreach ($christianWords as $word) {
        if (str_contains($lower, $word)) return 'Christian';
    }
    return null;
}

// ── Rename file to "Artist - Title.ext" ──────────────────
function renameAfterTag(PDO $db, int $songId, string $filePath, string $artist, string $title, string $musicDir): ?string
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

    // Store relative path in DB
    $newRelPath = ltrim(str_replace($musicDir . '/', '', $newPath), '/');
    $db->prepare("UPDATE songs SET file_path = :path WHERE id = :id")
       ->execute(['path' => $newRelPath, 'id' => $songId]);

    return $newPath;
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

// ── Verify-only mode ─────────────────────────────────────
// --verify: skip tagging, batch-verify all tagged songs via Gemini
if ($verifyMode) {
    logMsg('Verify mode: loading all tagged songs for AI verification', $autoMode);
    $stmt = $db->query("
        SELECT s.id, s.title, s.year, s.file_path, s.artist_id, s.country_code,
               a.name AS artist_name, c.name AS current_genre
        FROM songs s
        JOIN artists a ON a.id = s.artist_id
        JOIN categories c ON c.id = s.category_id
        WHERE s.tagged_at IS NOT NULL
          AND s.meta_locked = FALSE
          AND c.type = 'music'
        ORDER BY a.name, s.id
    ");
    $allTagged = $stmt->fetchAll();

    $verifyQueue = [];
    foreach ($allTagged as $s) {
        $verifyQueue[] = [
            'id'        => (int) $s['id'],
            'artist'    => $s['artist_name'],
            'title'     => $s['title'],
            'genre'     => $s['current_genre'],
            'year'      => $s['year'] ?? null,
            'album'     => null,
            'file_path' => $musicDir . '/' . $s['file_path'],
        ];
    }

    $progress['total'] = count($verifyQueue);
    $progress['status'] = 'verifying';
    writeProgress($progress);
    logMsg('Loaded ' . count($verifyQueue) . ' songs for verification', $autoMode);

    // Jump directly to batch verification (reuses the code after the main loop)
    goto batch_verify;
}

// ── Main ─────────────────────────────────────────────────

// --all mode: reset tagged_at so all songs get reprocessed (but NOT meta_locked ones)
if ($allMode && !$dryRun) {
    $db->exec("UPDATE songs SET tagged_at = NULL WHERE meta_locked = FALSE");
    logMsg('Re-scan all: reset tagged_at for non-locked songs', $autoMode);
}

// Get songs to process (skip meta_locked — those were manually edited by admin)
$stmt = $db->query("
    SELECT s.id, s.title, s.year, s.file_path, s.artist_id,
           a.name AS artist_name, c.name AS current_genre
    FROM songs s
    JOIN artists a ON a.id = s.artist_id
    JOIN categories c ON c.id = s.category_id
    WHERE s.tagged_at IS NULL
      AND s.meta_locked = FALSE
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
        @file_put_contents('/tmp/rendezvox_auto_tag_last.json', json_encode([
            'ran_at'    => date('c'),
            'total'     => 0,
            'updated'   => 0,
            'skipped'   => 0,
            'message'   => 'No untagged songs found',
        ]), LOCK_EX);
        @chmod('/tmp/rendezvox_auto_tag_last.json', 0666);
    }
    @unlink($lockFile);
    exit(0);
}

$artistGenreCache = [];
$stopFile         = '/tmp/rendezvox_genre_scan.lock.stop';
$verifyQueue      = []; // Songs to batch-verify after tagging

foreach ($allSongs as $song) {
    // Check for stop signal
    if (file_exists($stopFile)) {
        @unlink($stopFile);
        $progress['status']      = 'stopped';
        $progress['finished_at'] = date('c');
        writeProgress($progress);
        logMsg('Scan stopped — ' . $progress['processed'] . '/' . $progress['total'] . ' processed, ' . $progress['updated'] . ' updated', $autoMode);
        if ($autoMode) {
            @file_put_contents('/tmp/rendezvox_auto_tag_last.json', json_encode([
                'ran_at'    => date('c'),
                'total'     => $progress['total'],
                'updated'   => $progress['updated'],
                'skipped'   => $progress['skipped'],
                'message'   => 'Stopped — ' . $progress['updated'] . ' updated so far',
            ]), LOCK_EX);
            @chmod('/tmp/rendezvox_auto_tag_last.json', 0666);
        }
        @unlink('/tmp/rendezvox_genre_scan.lock');
        exit(0);
    }

    $artistName      = $song['artist_name'];
    $title           = $song['title'];
    $genre           = $song['current_genre'];
    $artistId        = (int) $song['artist_id'];
    $songId          = (int) $song['id'];
    $tagsChanged     = false;
    $gotExternalData = false;
    $mbResult        = null;
    $audioDbResult   = null;
    $originalCoverArt = null;

    // Resolve absolute file path (DB stores relative paths)
    $currentPath = $musicDir . '/' . $song['file_path'];

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

    // Step 2: AcoustID fingerprint lookup — tiered confidence
    //   0.95+ = high confidence → overwrite title/artist
    //   0.85–0.94 = moderate → only fill blanks
    //   <0.85 = rejected by MetadataLookup
    if (file_exists($currentPath)) {
        $acoustResult = $lookup->lookupByFingerprint($currentPath);

        if ($acoustResult) {
            $gotExternalData = true;
            $acoustScore = (float) ($acoustResult['score'] ?? 0);
            $highConfidence = $acoustScore >= 0.95;
            $updates = [];
            $params  = ['id' => $songId];

            if (!empty($acoustResult['title'])) {
                if ($highConfidence || empty(trim($title))) {
                    $updates[] = 'title = :title';
                    $params['title'] = $acoustResult['title'];
                    $title = $acoustResult['title'];
                }
            }

            if (!empty($acoustResult['artist'])) {
                if ($highConfidence || empty(trim($artistName))) {
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
    // Only detect genre for songs that are still "Uncategorized".
    // Songs with an existing genre (set by admin or import) are preserved.
    // Read embedded genre from file BEFORE Step 6 clears tags (needed for tag rewrite).
    $embeddedGenre = '';
    if (file_exists($currentPath)) {
        $embeddedMeta = MetadataExtractor::extract($currentPath);
        $embeddedGenre = trim($embeddedMeta['genre'] ?? '');
    }

    $needsGenreDetection = (strtolower($genre) === 'uncategorized');

    if ($needsGenreDetection) {
        $isAiArtist = (stripos($artistName, 'a.i.') !== false
                    || stripos($artistName, 'ai ') !== false
                    || stripos($artistName, 'ai cover') !== false);

        // 3a: AI artist — extract genre from title parentheses
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
        }
    }

    // Step 4: MusicBrainz recording lookup (year, album, release_id)
    if (!empty(trim($title)) && !empty(trim($artistName))) {
        $mbResult = $lookup->lookupByArtistTitle($artistName, $title);

        if ($mbResult) {
            $gotExternalData = true;

            // Update year if found and currently missing
            $currentYear = $song['year'] ? (int) $song['year'] : null;
            if (!empty($mbResult['year']) && $currentYear === null && !$dryRun) {
                $db->prepare("UPDATE songs SET year = :year WHERE id = :id")
                   ->execute(['year' => $mbResult['year'], 'id' => $songId]);
                $progress['updated']++;
                $tagsChanged = true;
            }

            // 3c: Recording genre (if still uncategorized)
            if (!empty($mbResult['genre']) && strtolower($genre) === 'uncategorized') {
                $genre = $mbResult['genre'];
                $catId = findOrCreateCategory($db, $genre, $categoryCache);
                if (!$dryRun) {
                    $db->prepare("UPDATE songs SET category_id = :cat WHERE id = :id")
                       ->execute(['cat' => $catId, 'id' => $songId]);
                }
                $progress['updated']++;
                $tagsChanged = true;
            }
        }
    }

    // 3d: Title keyword heuristics (if still uncategorized)
    if (strtolower($genre) === 'uncategorized' && !empty(trim($title))) {
        $keywordGenre = detectGenreByKeywords($title);
        if ($keywordGenre) {
            $genre = $keywordGenre;
            $catId = findOrCreateCategory($db, $genre, $categoryCache);
            if (!$dryRun) {
                $db->prepare("UPDATE songs SET category_id = :cat WHERE id = :id")
                   ->execute(['cat' => $catId, 'id' => $songId]);
            }
            $progress['updated']++;
            $tagsChanged = true;
        }
    }

    // 3e: MusicBrainz artist genre — last resort (if still uncategorized)
    if (strtolower($genre) === 'uncategorized') {
        $isAiCheck = (stripos($artistName, 'a.i.') !== false
                   || stripos($artistName, 'ai ') !== false
                   || stripos($artistName, 'ai cover') !== false);
        if (!$isAiCheck) {
            if (isset($artistGenreCache[$artistName])) {
                $artistMeta = $artistGenreCache[$artistName];
            } else {
                $artistMeta = $lookup->lookupArtistMeta($artistName);
                $artistGenreCache[$artistName] = $artistMeta;
            }
            $lookedUpGenre = $artistMeta['genre'] ?? null;

            // Set country_code from MusicBrainz if available
            if (!empty($artistMeta['country_code']) && empty($song['country_code'])) {
                if (!$dryRun) {
                    $db->prepare("UPDATE songs SET country_code = :cc WHERE id = :id")
                       ->execute(['cc' => $artistMeta['country_code'], 'id' => $songId]);
                }
                $tagsChanged = true;
            }

            if ($lookedUpGenre) {
                $gotExternalData = true;
                $genre = $lookedUpGenre;
                $catId = findOrCreateCategory($db, $genre, $categoryCache);
                if (!$dryRun) {
                    $db->prepare("UPDATE songs SET category_id = :cat WHERE id = :id")
                       ->execute(['cat' => $catId, 'id' => $songId]);
                }
                $progress['updated']++;
                $tagsChanged = true;
            } else {
                $progress['skipped']++;
            }
        }
    }

    // 3f: TheAudioDB metadata fallback (fill remaining gaps)
    $needGenre = strtolower($genre) === 'uncategorized';
    $needYear  = empty($song['year']) && empty($mbResult['year'] ?? null);
    if (!empty(trim($artistName)) && !empty(trim($title)) && ($needGenre || $needYear)) {
        $audioDbResult = $lookup->lookupTrackByTheAudioDb($artistName, $title);

        if ($audioDbResult) {
            $gotExternalData = true;

            if ($needGenre && !empty($audioDbResult['genre'])) {
                $genre = $audioDbResult['genre'];
                $catId = findOrCreateCategory($db, $genre, $categoryCache);
                if (!$dryRun) {
                    $db->prepare("UPDATE songs SET category_id = :cat WHERE id = :id")
                       ->execute(['cat' => $catId, 'id' => $songId]);
                }
                $progress['updated']++;
                $tagsChanged = true;
            }

            if ($needYear && !empty($audioDbResult['year']) && !$dryRun) {
                $db->prepare("UPDATE songs SET year = :year WHERE id = :id")
                   ->execute(['year' => $audioDbResult['year'], 'id' => $songId]);
                $progress['updated']++;
                $tagsChanged = true;
            }
        }
    }

    // 3g: Gemini AI metadata enrichment — fill remaining gaps
    // Triggers when: gaps remain OR no external source matched (artist/title may be wrong)
    $aiResult = null;
    $needAiGenre  = strtolower($genre) === 'uncategorized';
    $needAiYear   = empty($song['year']) && empty($mbResult['year'] ?? null) && empty($audioDbResult['year'] ?? null);
    $needAiArtist = MetadataLookup::looksLikeFilenameArtifact($artistName)
                 || (!$gotExternalData && !empty(trim($artistName)));
    $needAiTitle  = MetadataLookup::looksLikeFilenameArtifact($title)
                 || (!$gotExternalData && !empty(trim($title)));
    $needAiAlbum   = empty($mbResult['album'] ?? null) && empty($audioDbResult['album'] ?? null);
    $needAiCountry = empty($song['country_code']) && empty($artistMeta['country_code'] ?? null);
    if ($aiProvider !== 'none' && ($needAiGenre || $needAiYear || $needAiArtist || $needAiTitle || $needAiAlbum || $needAiCountry)) {
        $aiNeeds = [
            'genre'        => $needAiGenre,
            'year'         => $needAiYear,
            'artist'       => $needAiArtist,
            'title'        => $needAiTitle,
            'album'        => $needAiAlbum,
            'country_code' => $needAiCountry,
        ];

        if ($useGemini) {
            $aiResult = $lookup->lookupByAI($artistName, $title, $aiNeeds);
        }

        if ((!$aiResult || isset($aiResult['_error'])) && $useOllama) {
            $aiResult = $lookup->lookupByOllamaAI($artistName, $title, $aiNeeds);
        }

        if ($aiResult && !isset($aiResult['_error'])) {
            $gotExternalData = true;

            if (!empty($aiResult['genre']) && $needAiGenre) {
                $genre = $aiResult['genre'];
                $catId = findOrCreateCategory($db, $genre, $categoryCache);
                if (!$dryRun) {
                    $db->prepare("UPDATE songs SET category_id = :cat WHERE id = :id")
                       ->execute(['cat' => $catId, 'id' => $songId]);
                }
                $progress['updated']++;
                $tagsChanged = true;
            }

            if (!empty($aiResult['year']) && $needAiYear && !$dryRun) {
                $db->prepare("UPDATE songs SET year = :year WHERE id = :id")
                   ->execute(['year' => $aiResult['year'], 'id' => $songId]);
                $progress['updated']++;
                $tagsChanged = true;
            }

            if (!empty($aiResult['artist']) && $needAiArtist) {
                // Only override plausible-looking names when no external source confirmed them
                $isArtifact = MetadataLookup::looksLikeFilenameArtifact($artistName);
                if ($isArtifact || (!$gotExternalData && strtolower(trim($aiResult['artist'])) !== strtolower(trim($artistName)))) {
                    $newArtistId = findOrCreateArtist($db, $aiResult['artist'], $artistCache);
                    $artistName = $aiResult['artist'];
                    $artistId = $newArtistId;
                    if (!$dryRun) {
                        $db->prepare("UPDATE songs SET artist_id = :aid WHERE id = :id")
                           ->execute(['aid' => $newArtistId, 'id' => $songId]);
                    }
                    $progress['updated']++;
                    $tagsChanged = true;
                }
            }

            if (!empty($aiResult['title']) && $needAiTitle) {
                $isArtifact = MetadataLookup::looksLikeFilenameArtifact($title);
                if ($isArtifact || (!$gotExternalData && strtolower(trim($aiResult['title'])) !== strtolower(trim($title)))) {
                    $title = $aiResult['title'];
                    if (!$dryRun) {
                        $db->prepare("UPDATE songs SET title = :title WHERE id = :id")
                           ->execute(['title' => $title, 'id' => $songId]);
                    }
                    $progress['updated']++;
                    $tagsChanged = true;
                }
            }

            if (!empty($aiResult['country_code']) && $needAiCountry && !$dryRun) {
                $db->prepare("UPDATE songs SET country_code = :cc WHERE id = :id")
                   ->execute(['cc' => $aiResult['country_code'], 'id' => $songId]);
                $tagsChanged = true;
            }
        }
    }

    // 3h: Embedded genre tag — last resort before giving up
    // Embedded ID3/Vorbis tags are often inaccurate, so only use when all
    // external APIs (MusicBrainz, TheAudioDB) returned nothing.
    if (strtolower($genre) === 'uncategorized') {
        $isAiCheck = (stripos($artistName, 'a.i.') !== false
                   || stripos($artistName, 'ai ') !== false
                   || stripos($artistName, 'ai cover') !== false);
        $genericTags = ['other', 'unknown', 'misc', 'none', ''];
        if (!$isAiCheck && $embeddedGenre !== '' && !in_array(strtolower($embeddedGenre), $genericTags)) {
            $mapped = MetadataLookup::mapGenre($embeddedGenre);
            $resolvedGenre = $mapped ?: ucfirst(strtolower($embeddedGenre));

            if (strtolower($resolvedGenre) !== strtolower($genre)) {
                $genre = $resolvedGenre;
                $catId = findOrCreateCategory($db, $genre, $categoryCache);
                if (!$dryRun) {
                    $db->prepare("UPDATE songs SET category_id = :cat WHERE id = :id")
                       ->execute(['cat' => $catId, 'id' => $songId]);
                }
                $progress['updated']++;
                $tagsChanged = true;
            }
        }
    }

    // Step 6: Clear ALL tags and write fresh metadata
    if (!$dryRun && file_exists($currentPath)) {
        // Extract original cover art before clearing
        $originalCoverArt = MetadataLookup::extractCoverArt($currentPath);

        // Build fresh tags
        $freshTags = [
            'title'  => $title,
            'artist' => $artistName,
            'genre'  => $genre,
        ];
        $year = $mbResult['year'] ?? ($audioDbResult['year'] ?? ($aiResult['year'] ?? ($song['year'] ?: null)));
        if ($year) {
            $freshTags['date'] = (string) $year;
        }
        $album = $mbResult['album'] ?? ($audioDbResult['album'] ?? ($aiResult['album'] ?? null));
        if ($album) {
            $freshTags['album'] = $album;
        }

        MetadataLookup::clearAndWriteTags($currentPath, $freshTags);

        // Update file_hash in DB since file content changed
        $newHash = hash_file('sha256', $currentPath);
        $db->prepare("UPDATE songs SET file_hash = :hash WHERE id = :id")
           ->execute(['hash' => $newHash, 'id' => $songId]);
    }

    // Step 7: Rename file to "Artist - Title.ext"
    if (!$dryRun && !empty(trim($title)) && file_exists($currentPath)) {
        $renamed = renameAfterTag($db, $songId, $currentPath, $artistName, $title, $musicDir);
        if ($renamed) $currentPath = $renamed;
    }

    // Step 8: Cover art — re-fetch in --all mode, otherwise only if missing
    if (!$dryRun && file_exists($currentPath)) {
        $hasCover = MetadataLookup::hasCoverArt($currentPath);
        $needsFetch = !$hasCover || $allMode;

        if ($needsFetch) {
            $releaseId = $mbResult['release_id'] ?? null;
            $imageData = $lookup->lookupCoverArt($artistName, $title, $releaseId);
            if ($imageData) {
                if (MetadataLookup::embedCoverArt($currentPath, $imageData)) {
                    $db->prepare("UPDATE songs SET has_cover_art = TRUE WHERE id = :id")
                       ->execute(['id' => $songId]);
                    $progress['covers'] = ($progress['covers'] ?? 0) + 1;
                    // Clear cached cover so CoverArtHandler serves the new one
                    @unlink('/tmp/rendezvox_covers/' . $songId . '.jpg');
                }
            } elseif (!$hasCover && $originalCoverArt) {
                // Re-embed original cover art that was stripped during tag clearing
                if (MetadataLookup::embedCoverArt($currentPath, $originalCoverArt)) {
                    $db->prepare("UPDATE songs SET has_cover_art = TRUE WHERE id = :id")
                       ->execute(['id' => $songId]);
                }
            }
        } else {
            // Already has cover art — mark in DB
            $db->prepare("UPDATE songs SET has_cover_art = TRUE WHERE id = :id")
               ->execute(['id' => $songId]);
        }

        // Update file_hash again if cover art changed file content
        $finalHash = hash_file('sha256', $currentPath);
        $db->prepare("UPDATE songs SET file_hash = :hash WHERE id = :id")
           ->execute(['hash' => $finalHash, 'id' => $songId]);
    }

    // Step 9: Relocate files
    if (!$dryRun && file_exists($currentPath)) {
        $relPath = ltrim(str_replace($musicDir . '/', '', $currentPath), '/');

        // untagged/files/ → tagged/files/{Artist}/ if all fields now filled
        if (str_starts_with($relPath, 'untagged/files/') && !empty($genre) && !empty($artistName) && !empty($title)
            && strtolower($genre) !== 'uncategorized') {
            $safeArtist = preg_replace('/[\/\\\\:*?"<>|]/', '', trim($artistName));
            $safeTitle  = preg_replace('/[\/\\\\:*?"<>|]/', '', trim($title));
            $ext = strtolower(pathinfo($currentPath, PATHINFO_EXTENSION));

            if ($safeArtist !== '' && $safeTitle !== '') {
                $newDir  = $musicDir . '/tagged/files/' . $safeArtist;
                $newPath = $newDir . '/' . $safeTitle . '.' . $ext;

                if (!file_exists($newPath)) {
                    if (!is_dir($newDir)) {
                        mkdir($newDir, 0775, true);
                        @chmod($newDir, 0775);
                        @chown($newDir, 'www-data');
                        @chgrp($newDir, 'www-data');
                    }
                    if (rename($currentPath, $newPath)) {
                        $newRelPath = ltrim(str_replace($musicDir . '/', '', $newPath), '/');
                        $db->prepare("UPDATE songs SET file_path = :path WHERE id = :id")
                           ->execute(['path' => $newRelPath, 'id' => $songId]);
                        @chmod($newPath, 0664);
                        @chown($newPath, 'www-data');
                        @chgrp($newPath, 'www-data');
                        @unlink($currentPath . '.info.txt');
                        $progress['relocated'] = ($progress['relocated'] ?? 0) + 1;
                    }
                }
            }
        }

        // Files NOT in tagged/folders/ or untagged/ with no external data → move to untagged/files/
        elseif (!$gotExternalData && !str_starts_with($relPath, 'tagged/folders/') && !str_starts_with($relPath, 'untagged/')) {
            $ext      = strtolower(pathinfo($currentPath, PATHINFO_EXTENSION));
            $basename = pathinfo($currentPath, PATHINFO_FILENAME) . '.' . $ext;
            $destDir  = $musicDir . '/untagged/files';
            $destPath = $destDir . '/' . $basename;

            // Resolve filename conflicts
            $counter = 1;
            while (file_exists($destPath)) {
                $destPath = $destDir . '/' . pathinfo($currentPath, PATHINFO_FILENAME) . "_{$counter}." . $ext;
                $counter++;
            }

            if (!is_dir($destDir)) {
                mkdir($destDir, 0775, true);
            }
            if (rename($currentPath, $destPath)) {
                $newRelPath = ltrim(str_replace($musicDir . '/', '', $destPath), '/');
                $db->prepare("UPDATE songs SET file_path = :path WHERE id = :id")
                   ->execute(['path' => $newRelPath, 'id' => $songId]);
                @chmod($destPath, 0664);
                // Write info sidecar with embedded metadata
                $info = "Title: {$title}\nArtist: {$artistName}\nGenre: {$genre}\nOriginal: {$song['file_path']}\n";
                @file_put_contents($destPath . '.info.txt', $info);
            }
        }

        // tagged/folders/ files: tagged in place, NOT relocated (preserves folder structure for playlists)
    }

    // Queue for batch AI verification (only non-AI-artist songs with a genre set)
    if (!$dryRun && strtolower($genre) !== 'uncategorized') {
        $verifyQueue[] = [
            'id'        => $songId,
            'artist'    => $artistName,
            'title'     => $title,
            'genre'     => $genre,
            'year'      => $song['year'] ?? null,
            'album'     => $mbResult['album'] ?? ($audioDbResult['album'] ?? ($aiResult['album'] ?? null)),
            'file_path' => $currentPath,
        ];
    }

    // Mark song as processed (always, to prevent infinite retry)
    if (!$dryRun) {
        $db->prepare("UPDATE songs SET tagged_at = NOW() WHERE id = :id")
           ->execute(['id' => $songId]);
    }

    $progress['processed']++;
    writeProgress($progress);
}

batch_verify:
// ── Batch AI verification phase ─────────────────────────
// Sends tagged songs to Gemini in batches of 20 to verify/correct
// genre, year, and album. One API call per batch = minimal quota usage.
$verified = 0;
$corrected = 0;
if (!$dryRun && !empty($verifyQueue) && $useGemini && $aiProvider !== 'none') {
    $progress['status'] = 'verifying';
    writeProgress($progress);
    logMsg('AI verification: ' . count($verifyQueue) . ' songs to verify', $autoMode);

    $batchSize = 20;
    $batches = array_chunk($verifyQueue, $batchSize);

    foreach ($batches as $bi => $batch) {
        // Check stop signal
        if (file_exists($stopFile)) {
            break;
        }

        $corrections = $lookup->batchVerifyByAI($batch);
        if ($corrections === null) {
            continue; // API error, skip batch
        }
        if (isset($corrections['_error'])) {
            logMsg('AI verification stopped: ' . ($corrections['_error'] ?? 'rate limited'), $autoMode);
            break; // Rate limited, stop verification
        }

        foreach ($corrections as $i => $fix) {
            if (empty($fix)) {
                $verified++;
                continue; // All correct
            }

            $songId = $batch[$i]['id'];
            $updates = [];
            $params = ['id' => $songId];

            if (!empty($fix['genre'])) {
                $catId = findOrCreateCategory($db, $fix['genre'], $categoryCache);
                $db->prepare("UPDATE songs SET category_id = :cat WHERE id = :id")
                   ->execute(['cat' => $catId, 'id' => $songId]);
                $corrected++;
            }

            if (!empty($fix['year'])) {
                $updates[] = 'year = :year';
                $params['year'] = $fix['year'];
            }

            if (!empty($fix['album'])) {
                // If album changed, try to re-fetch cover art
                $songPath = $batch[$i]['file_path'];
                if (!empty($batch[$i]['artist']) && file_exists($songPath)) {
                    $coverData = $lookup->lookupCoverArt(
                        $batch[$i]['artist'], $batch[$i]['title'], null
                    );
                    if ($coverData) {
                        MetadataLookup::embedCoverArt($songPath, $coverData);
                    }
                }
            }

            if (!empty($updates)) {
                $sql = "UPDATE songs SET " . implode(', ', $updates) . " WHERE id = :id";
                $db->prepare($sql)->execute($params);
            }

            $verified++;
        }

        $progress['verified'] = $verified;
        $progress['corrected'] = $corrected;
        writeProgress($progress);
    }

    logMsg("AI verification complete: {$verified} verified, {$corrected} corrected", $autoMode);
}

$progress['status']      = 'done';
$progress['finished_at'] = date('c');
$progress['verified']    = $verified;
$progress['corrected']   = $corrected;
writeProgress($progress);
@unlink('/tmp/rendezvox_genre_scan.lock');
@unlink($stopFile);

logMsg('Scan complete — ' . $progress['updated'] . ' updated, ' . $progress['skipped'] . ' skipped, ' . ($progress['covers'] ?? 0) . ' covers, ' . ($progress['relocated'] ?? 0) . ' relocated, ' . $corrected . ' AI-corrected out of ' . $progress['total'], $autoMode);

// Write auto-tag summary for the Settings UI
if ($autoMode) {
    @file_put_contents('/tmp/rendezvox_auto_tag_last.json', json_encode([
        'ran_at'    => date('c'),
        'total'     => $progress['total'],
        'updated'   => $progress['updated'],
        'skipped'   => $progress['skipped'],
        'covers'    => $progress['covers'] ?? 0,
        'relocated' => $progress['relocated'] ?? 0,
        'message'   => $progress['updated'] . ' updated, ' . ($progress['covers'] ?? 0) . ' covers, ' . ($progress['relocated'] ?? 0) . ' relocated',
    ]), LOCK_EX);
    @chmod('/tmp/rendezvox_auto_tag_last.json', 0666);
}
