<?php
/**
 * Batch Playlist Import — runs in background, processes folders one by one.
 *
 * Reads job parameters from /tmp/rendezvox_batch_import_params.json
 * Writes progress to /tmp/rendezvox_batch_import.json
 * Supports stop signal via /tmp/rendezvox_batch_import.lock.stop
 *
 * Each folder: find unscanned files → parallel ffprobe → insert songs → create playlist
 */

declare(strict_types=1);

require __DIR__ . '/../core/Database.php';
require __DIR__ . '/../core/MetadataExtractor.php';
require __DIR__ . '/../core/MetadataLookup.php';
require __DIR__ . '/../core/ArtistNormalizer.php';
require __DIR__ . '/../core/RotationEngine.php';

// ── Constants ────────────────────────────────────────────
$MUSIC_DIR    = '/var/lib/rendezvox/music';
$AUDIO_EXTS   = ['mp3', 'flac', 'ogg', 'wav', 'aac', 'm4a', 'wma', 'opus', 'aiff'];
$PROGRESS_FILE = '/tmp/rendezvox_batch_import.json';
$LOCK_FILE     = '/tmp/rendezvox_batch_import.lock';
$STOP_FILE     = '/tmp/rendezvox_batch_import.lock.stop';
$PARAMS_FILE   = '/tmp/rendezvox_batch_import_params.json';

$PALETTE = [
    '#00c8a0', '#f87171', '#34d399', '#fbbf24', '#60a5fa',
    '#a78bfa', '#f472b6', '#2dd4bf', '#fb923c', '#818cf8',
    '#4ade80', '#f97316', '#38bdf8', '#e879f9', '#facc15',
    '#22d3ee', '#fb7185', '#a3e635', '#c084fc', '#fdba74',
];

// ── Lock file ────────────────────────────────────────────
if (file_exists($LOCK_FILE)) {
    $pid = (int) @file_get_contents($LOCK_FILE);
    if ($pid > 0 && file_exists("/proc/{$pid}")) {
        exit(0); // Another import running
    }
    @unlink($LOCK_FILE);
}
file_put_contents($LOCK_FILE, (string) getmypid());
register_shutdown_function(function () use ($LOCK_FILE) {
    @unlink($LOCK_FILE);
});

// ── Read params ──────────────────────────────────────────
if (!file_exists($PARAMS_FILE)) {
    exit(1);
}
$params = json_decode(file_get_contents($PARAMS_FILE), true);
@unlink($PARAMS_FILE);

if (!$params || empty($params['folders'])) {
    exit(1);
}

$folders   = $params['folders'];
$recursive = (bool) ($params['recursive'] ?? true);
$userId    = (int) ($params['user_id'] ?? 0);

set_time_limit(0); // No time limit for background process

$db = Database::get();

// ── Initial progress ─────────────────────────────────────
$stats = [
    'status'            => 'running',
    'total_folders'     => count($folders),
    'folders_processed' => 0,
    'playlists_created' => 0,
    'songs_scanned'     => 0,
    'skipped'           => 0,
    'current_folder'    => '',
    'started_at'        => date('c'),
];
writeProgress($PROGRESS_FILE, $stats);

// ── Pre-load existing data ───────────────────────────────
// Existing playlist names
$stmt = $db->query('SELECT LOWER(name) AS lname FROM playlists');
$existingNames = [];
while ($row = $stmt->fetch()) {
    $existingNames[$row['lname']] = true;
}

// Used colors
$stmt = $db->query('SELECT LOWER(color) AS lcolor FROM playlists WHERE color IS NOT NULL');
$usedColors = [];
while ($row = $stmt->fetch()) {
    $usedColors[$row['lcolor']] = true;
}

// Existing file paths for dedup
$existingPaths = [];
$pathStmt = $db->query('SELECT file_path FROM songs');
while ($row = $pathStmt->fetch()) {
    $existingPaths[$row['file_path']] = true;
}

// Category cache
$categoryCache = [];
$catStmt = $db->query('SELECT id, LOWER(name) AS lname FROM categories');
while ($row = $catStmt->fetch()) {
    $categoryCache[$row['lname']] = (int) $row['id'];
}
$defaultCatId = (int) (getenv('RENDEZVOX_DEFAULT_CATEGORY_ID') ?: 1);

$basePrefix = rtrim($MUSIC_DIR, '/') . '/';
$colorIndex = 0;

// ── Process each folder ──────────────────────────────────
foreach ($folders as $entry) {
    // Check stop signal
    if (file_exists($STOP_FILE)) {
        @unlink($STOP_FILE);
        $stats['status'] = 'stopped';
        $stats['current_folder'] = '';
        $stats['finished_at'] = date('c');
        writeProgress($PROGRESS_FILE, $stats);
        exit(0);
    }

    $folderPath = trim((string) ($entry['path'] ?? ''));
    $name       = trim((string) ($entry['name'] ?? ''));

    if ($folderPath === '' || $name === '') {
        $stats['folders_processed']++;
        $stats['skipped']++;
        writeProgress($PROGRESS_FILE, $stats);
        continue;
    }

    if (isset($existingNames[strtolower($name)])) {
        $stats['folders_processed']++;
        $stats['skipped']++;
        writeProgress($PROGRESS_FILE, $stats);
        continue;
    }

    // Title-case the playlist name (folder names are often ALL-CAPS)
    $name = playlistTitleCase($name);

    $stats['current_folder'] = $name;
    writeProgress($PROGRESS_FILE, $stats);

    $absPath = safePath($folderPath, $MUSIC_DIR);
    if ($absPath === null || !is_dir($absPath)) {
        $stats['folders_processed']++;
        $stats['skipped']++;
        writeProgress($PROGRESS_FILE, $stats);
        continue;
    }

    $relPath = str_starts_with($absPath, $basePrefix)
        ? substr($absPath, strlen($basePrefix))
        : ltrim($absPath, '/');
    $prefix = $relPath . '/';

    // Step 1: Find unscanned audio files in this folder
    $newFiles = [];
    findAudioFiles($absPath, $relPath, $recursive, $existingPaths, $newFiles, $AUDIO_EXTS);

    // Step 2: Parallel ffprobe + insert for this folder's new files
    if (!empty($newFiles)) {
        $absPaths = array_column($newFiles, 'abs');
        $workers = MetadataExtractor::safeWorkerCount();
        MetadataExtractor::prefetch($absPaths, $workers);

        foreach ($newFiles as $file) {
            $meta = MetadataExtractor::extract($file['abs']);
            if ($meta['duration_ms'] <= 0) continue;

            $title  = $meta['title'] ?: pathinfo(basename($file['abs']), PATHINFO_FILENAME);
            $artist = $meta['artist'] ?: 'Unknown Artist';
            $artistId = findOrCreateArtist($db, $artist);

            $catId = $defaultCatId;
            $rawGenre = trim($meta['genre'] ?? '');
            if ($rawGenre !== '') {
                $mapped = MetadataLookup::mapGenre($rawGenre);
                if ($mapped) {
                    $catId = findOrCreateCategory($db, $mapped, $categoryCache);
                }
            }

            $fileHash = hash_file('sha256', $file['abs']);
            $canonicalId = null;
            if ($fileHash) {
                $dupStmt = $db->prepare('SELECT id FROM songs WHERE file_hash = :hash AND duplicate_of IS NULL ORDER BY id LIMIT 1');
                $dupStmt->execute(['hash' => $fileHash]);
                $cid = $dupStmt->fetchColumn();
                if ($cid !== false) $canonicalId = (int) $cid;
            }

            try {
                $insertStmt = $db->prepare('
                    INSERT INTO songs (title, artist_id, category_id, file_path, file_hash, duration_ms, year, duplicate_of)
                    VALUES (:title, :artist_id, :category_id, :file_path, :file_hash, :duration_ms, :year, :duplicate_of)
                ');
                $insertStmt->execute([
                    'title'        => $title,
                    'artist_id'    => $artistId,
                    'category_id'  => $catId,
                    'file_path'    => $file['rel'],
                    'file_hash'    => $fileHash ?: null,
                    'duration_ms'  => $meta['duration_ms'],
                    'year'         => $meta['year'] ?: null,
                    'duplicate_of' => $canonicalId,
                ]);
                $existingPaths[$file['rel']] = true;
                $stats['songs_scanned']++;
            } catch (\PDOException $e) {
                continue;
            }
        }
    }

    // Step 3: Query songs for this folder (including newly scanned)
    if ($recursive) {
        $songStmt = $db->prepare('
            SELECT id FROM songs
            WHERE  file_path LIKE :prefix AND is_active = true AND duplicate_of IS NULL
            ORDER BY file_path
        ');
        $songStmt->execute(['prefix' => $prefix . '%']);
    } else {
        $songStmt = $db->prepare('
            SELECT id FROM songs
            WHERE  file_path LIKE :prefix AND file_path NOT LIKE :subprefix AND is_active = true AND duplicate_of IS NULL
            ORDER BY file_path
        ');
        $songStmt->execute([
            'prefix'    => $prefix . '%',
            'subprefix' => $prefix . '%/%',
        ]);
    }
    $songIds = $songStmt->fetchAll(\PDO::FETCH_COLUMN);

    if (count($songIds) === 0) {
        $stats['folders_processed']++;
        $stats['skipped']++;
        writeProgress($PROGRESS_FILE, $stats);
        continue;
    }

    // Step 4: Create playlist and add songs
    $color = pickColor($colorIndex, $usedColors, $PALETTE);
    $usedColors[strtolower($color)] = true;
    $colorIndex++;

    $db->beginTransaction();
    try {
        $createStmt = $db->prepare('
            INSERT INTO playlists (name, description, type, color, created_by)
            VALUES (:name, :desc, :type, :color, :user_id)
            RETURNING id
        ');
        $createStmt->execute([
            'name'    => $name,
            'desc'    => 'Imported from ' . $folderPath,
            'type'    => 'manual',
            'color'   => $color,
            'user_id' => $userId > 0 ? $userId : null,
        ]);
        $playlistId = (int) $createStmt->fetchColumn();

        $insert = $db->prepare('
            INSERT INTO playlist_songs (playlist_id, song_id, position)
            VALUES (:pid, :sid, :pos)
        ');
        $pos = 0;
        foreach ($songIds as $sid) {
            $pos++;
            $insert->execute(['pid' => $playlistId, 'sid' => (int) $sid, 'pos' => $pos]);
        }

        $db->commit();

        // Shuffle with artist/category/title separation
        RotationEngine::generateCycleOrder($db, $playlistId);

        $existingNames[strtolower($name)] = true;
        $stats['playlists_created']++;
    } catch (\Exception $e) {
        $db->rollBack();
        // Log but continue with next folder
    }

    $stats['folders_processed']++;
    writeProgress($PROGRESS_FILE, $stats);
}

// ── Done ─────────────────────────────────────────────────
$stats['status'] = 'done';
$stats['current_folder'] = '';
$stats['finished_at'] = date('c');
writeProgress($PROGRESS_FILE, $stats);

// ── Helper functions ─────────────────────────────────────

function safePath(string $rawPath, string $baseDir): ?string
{
    if (str_contains($rawPath, '\\')) return null;
    $parts    = explode('/', '/' . ltrim($rawPath, '/'));
    $resolved = [];
    foreach ($parts as $part) {
        if ($part === '' || $part === '.') continue;
        if ($part === '..') { array_pop($resolved); continue; }
        $resolved[] = $part;
    }
    $abs = $baseDir . ($resolved ? '/' . implode('/', $resolved) : '');
    if (file_exists($abs)) {
        $real = realpath($abs);
        if ($real === false || !str_starts_with($real, $baseDir)) return null;
        return $real;
    }
    if (!str_starts_with($abs, $baseDir . '/') && $abs !== $baseDir) return null;
    return $abs;
}

function findAudioFiles(
    string $absDir, string $relDir, bool $recursive,
    array &$existingPaths, array &$newFiles, array $audioExts
): void {
    $entries = @scandir($absDir);
    if (!$entries) return;
    foreach ($entries as $item) {
        if ($item === '.' || $item === '..' || str_starts_with($item, '.')) continue;
        $fullPath = $absDir . '/' . $item;
        if (is_dir($fullPath) && $recursive) {
            findAudioFiles($fullPath, $relDir . '/' . $item, true, $existingPaths, $newFiles, $audioExts);
        } elseif (is_file($fullPath)) {
            $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
            if (!in_array($ext, $audioExts)) continue;
            if (str_contains($item, '.tmp.')) continue;
            $relPath = $relDir . '/' . $item;
            if (isset($existingPaths[$relPath])) continue;
            $newFiles[] = ['abs' => $fullPath, 'rel' => $relPath];
        }
    }
}

function findOrCreateArtist(\PDO $db, string $name): int
{
    $name = ArtistNormalizer::extractPrimary($name, $db);
    $normalized = mb_strtolower(trim($name));
    $stmt = $db->prepare('SELECT id FROM artists WHERE normalized_name = :norm');
    $stmt->execute(['norm' => $normalized]);
    $row = $stmt->fetch();
    if ($row) return (int) $row['id'];
    $stmt = $db->prepare('INSERT INTO artists (name, normalized_name) VALUES (:name, :norm) RETURNING id');
    $stmt->execute(['name' => trim($name), 'norm' => $normalized]);
    return (int) $stmt->fetchColumn();
}

function findOrCreateCategory(\PDO $db, string $name, array &$cache): int
{
    $key = strtolower(trim($name));
    if (isset($cache[$key])) return $cache[$key];
    $stmt = $db->prepare('SELECT id FROM categories WHERE LOWER(name) = LOWER(:name)');
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

function pickColor(int $index, array $usedColors, array $palette): string
{
    $available = [];
    foreach ($palette as $c) {
        if (!isset($usedColors[strtolower($c)])) $available[] = $c;
    }
    if (!empty($available)) return $available[$index % count($available)];
    $r = mt_rand(40, 240);
    $g = mt_rand(40, 240);
    $b = mt_rand(40, 240);
    return '#' . str_pad(dechex(($r << 16) + ($g << 8) + $b), 6, '0', STR_PAD_LEFT);
}

/**
 * Title-case a playlist name: "TIMELESS CLASSICS" → "Timeless Classics"
 * Preserves short acronyms (DJ, OPM, EDM), decade refs (90s, 80s),
 * and mixed-case words (iPhone, McCartney).
 */
function playlistTitleCase(string $name): string
{
    static $acronyms = ['DJ', 'MC', 'OPM', 'EDM', 'RnB', 'R&B', 'BTS', 'ABBA', 'AC/DC',
                         'LP', 'EP', 'FM', 'AM', 'TV', 'UK', 'US', 'USA', 'AI', 'II', 'III',
                         'IV', 'VI', 'VII', 'VIII', 'GMA', 'TNT', 'MLTR', 'MYMP', 'TLC',
                         'NSYNC', 'LMFAO', 'INXS', 'SWV', 'ATC', 'Vol'];
    static $minor = ['a', 'an', 'the', 'and', 'but', 'or', 'nor', 'for', 'yet', 'so',
                     'at', 'by', 'in', 'of', 'on', 'to', 'up', 'as', 'vs', 'is', 'it',
                     'if', 'no', 'not', 'with', 'from'];

    $acronymMap = [];
    foreach ($acronyms as $a) {
        $acronymMap[mb_strtoupper($a)] = $a;
    }

    $words = explode(' ', $name);
    $result = [];
    $count = count($words);

    foreach ($words as $i => $word) {
        if ($word === '') { $result[] = $word; continue; }

        $upper = mb_strtoupper($word);

        // Preserve known acronyms (case-insensitive match)
        if (isset($acronymMap[$upper])) {
            $result[] = $acronymMap[$upper];
            continue;
        }

        // Preserve digit-leading words: 90s, 80S→80s, 2000s
        if (preg_match('/^(\d+)([A-Za-z]*)$/', $word, $m)) {
            $result[] = $m[1] . mb_strtolower($m[2]);
            continue;
        }

        // Mixed-case words (not ALL-CAPS, not all-lower): preserve as-is (McCartney, iPhone)
        $isAllUpper = ($word === $upper);
        $isAllLower = ($word === mb_strtolower($word));
        if (!$isAllUpper && !$isAllLower) {
            $result[] = $word;
            continue;
        }

        // ALL-CAPS or all-lower: apply title case
        $lower = mb_strtolower($word);
        $isFirst = ($i === 0);
        $isLast = ($i === $count - 1);

        if (!$isFirst && !$isLast && in_array($lower, $minor)) {
            $result[] = $lower;
        } else {
            $result[] = mb_strtoupper(mb_substr($lower, 0, 1)) . mb_substr($lower, 1);
        }
    }

    return implode(' ', $result);
}

function writeProgress(string $file, array $stats): void
{
    @file_put_contents($file, json_encode($stats, JSON_PRETTY_PRINT), LOCK_EX);
}
