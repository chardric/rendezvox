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
 * Progress is written to /tmp/rendezvox_genre_scan.json so the admin UI
 * can poll for status.
 */

declare(strict_types=1);

require __DIR__ . '/../core/Database.php';
require __DIR__ . '/../core/MetadataLookup.php';
require __DIR__ . '/../core/ArtistNormalizer.php';

$dryRun   = in_array('--dry-run', $argv ?? []);
$autoMode = in_array('--auto', $argv ?? []);

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

$stmt = $db->prepare("SELECT key, value FROM settings WHERE key IN ('acoustid_api_key', 'theaudiodb_api_key')");
$stmt->execute();
while ($row = $stmt->fetch()) {
    if ($row['key'] === 'acoustid_api_key' && !empty(trim($row['value']))) {
        $lookup->setAcoustIdKey(trim($row['value']));
    }
    if ($row['key'] === 'theaudiodb_api_key' && !empty(trim($row['value']))) {
        $lookup->setTheAudioDbKey(trim($row['value']));
    }
}

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

    // Step 2: AcoustID fingerprint lookup — results REPLACE existing values
    if (file_exists($currentPath)) {
        $acoustResult = $lookup->lookupByFingerprint($currentPath);

        if ($acoustResult) {
            $gotExternalData = true;
            $updates = [];
            $params  = ['id' => $songId];

            // Always replace title from AcoustID
            if (!empty($acoustResult['title'])) {
                $updates[] = 'title = :title';
                $params['title'] = $acoustResult['title'];
                $title = $acoustResult['title'];
            }

            // Always replace artist from AcoustID
            if (!empty($acoustResult['artist'])) {
                $newArtistId = findOrCreateArtist($db, $acoustResult['artist'], $artistCache);
                $updates[] = 'artist_id = :artist_id';
                $params['artist_id'] = $newArtistId;
                $artistName = $acoustResult['artist'];
                $artistId = $newArtistId;
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
        // Genre lookup via MusicBrainz artist (cached per artist)
        if (isset($artistGenreCache[$artistName])) {
            $lookedUpGenre = $artistGenreCache[$artistName];
        } else {
            $lookedUpGenre = $lookup->lookupGenreByArtist($artistName);
            $artistGenreCache[$artistName] = $lookedUpGenre;
        }

        if ($lookedUpGenre) {
            $gotExternalData = true;
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
    }

    // Step 4: MusicBrainz recording lookup (year, album, release_id, genre)
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

            // Use recording genre if we still don't have one
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

    // Step 5: TheAudioDB metadata fallback (fill remaining gaps)
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
        $year = $mbResult['year'] ?? ($audioDbResult['year'] ?? ($song['year'] ?: null));
        if ($year) {
            $freshTags['date'] = (string) $year;
        }
        $album = $mbResult['album'] ?? ($audioDbResult['album'] ?? null);
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

    // Step 8: Cover art — fetch external if missing, re-embed original if stripped
    if (!$dryRun && file_exists($currentPath)) {
        if (!MetadataLookup::hasCoverArt($currentPath)) {
            // Try external cover art
            $releaseId = $mbResult['release_id'] ?? null;
            $imageData = $lookup->lookupCoverArt($artistName, $title, $releaseId);
            if ($imageData) {
                if (MetadataLookup::embedCoverArt($currentPath, $imageData)) {
                    $db->prepare("UPDATE songs SET has_cover_art = TRUE WHERE id = :id")
                       ->execute(['id' => $songId]);
                    $progress['covers'] = ($progress['covers'] ?? 0) + 1;
                }
            } elseif ($originalCoverArt) {
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

        // untagged/files/ → tagged/files/ if all fields now filled
        if (str_starts_with($relPath, 'untagged/files/') && !empty($genre) && !empty($artistName) && !empty($title)
            && strtolower($genre) !== 'uncategorized') {
            $safeGenre  = preg_replace('/[\/\\\\:*?"<>|]/', '', trim($genre));
            $safeArtist = preg_replace('/[\/\\\\:*?"<>|]/', '', trim($artistName));
            $safeTitle  = preg_replace('/[\/\\\\:*?"<>|]/', '', trim($title));
            $ext = strtolower(pathinfo($currentPath, PATHINFO_EXTENSION));

            if ($safeGenre !== '' && $safeArtist !== '' && $safeTitle !== '') {
                $newDir  = $musicDir . '/tagged/files/' . $safeGenre . '/' . $safeArtist;
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

    // Mark song as processed (always, to prevent infinite retry)
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
@unlink('/tmp/rendezvox_genre_scan.lock');
@unlink($stopFile);

logMsg('Scan complete — ' . $progress['updated'] . ' updated, ' . $progress['skipped'] . ' skipped, ' . ($progress['covers'] ?? 0) . ' covers, ' . ($progress['relocated'] ?? 0) . ' relocated out of ' . $progress['total'], $autoMode);

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
