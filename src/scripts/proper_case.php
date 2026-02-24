<?php
/**
 * Proper Title Case Migration
 *
 * Applies proper title casing to:
 *   - artists.name
 *   - songs.title
 *   - playlists.name
 *   - categories.name
 *   - songs.file_path (renames actual files/directories on disk)
 *
 * Rules:
 *   - Capitalize first and last word always
 *   - Capitalize first word after ( or [
 *   - Capitalize both parts of hyphenated words (Singer-Songwriter)
 *   - Lowercase minor words: a, an, the, and, but, or, nor, for, yet, so,
 *     at, by, in, of, on, to, up, as, vs, is, it, if, no, not, with, from
 *   - Preserve ALL-CAPS words: II, III, IV, VA, LP, DJ, MC, R&B, UB40
 *   - Preserve dotted abbreviations: A.I., R.E.M., K.D., P.M.
 *   - Preserve camelCase / internal caps: McEntire, LeBlanc, CeCe, JoJo, O'Connor
 *   - Preserve words with unicode special chars: *NSYNC, B*Witched, ‐4‐
 *
 * Usage:
 *   php proper_case.php               — full run (DB + file renames)
 *   php proper_case.php --dry-run     — preview changes, no writes
 *   php proper_case.php --db-only     — update DB only, skip file renames
 */

declare(strict_types=1);

require __DIR__ . '/../core/Database.php';

$MUSIC_DIR = '/var/lib/iradio/music';

// System directories that must never be renamed by title-casing
$SYSTEM_DIRS = ['imports', 'tagged', 'upload', '_untagged', '_duplicates'];

$dryRun = in_array('--dry-run', $argv);
$dbOnly = in_array('--db-only', $argv);

if ($dryRun) echo "=== DRY RUN — no changes will be made ===\n\n";
if ($dbOnly) echo "=== DB ONLY — skipping file renames ===\n\n";

// ── Minor words (lowercase unless first/last or after open-paren/bracket) ──
$MINOR_WORDS = [
    'a', 'an', 'the',                                      // articles
    'and', 'but', 'or', 'nor', 'for', 'yet', 'so',         // coordinating conjunctions
    'at', 'by', 'in', 'of', 'on', 'to', 'up', 'as', 'vs', // short prepositions
];

/**
 * Apply proper title case to a string.
 */
function properTitleCase(string $str, array $minorWords): string
{
    if ($str === '') return '';

    $words  = explode(' ', $str);
    $count  = count($words);
    $result = [];
    $afterOpen = false;

    for ($i = 0; $i < $count; $i++) {
        $word = $words[$i];

        if ($word === '') {
            $result[] = $word;
            continue;
        }

        $isFirst = ($i === 0);
        $isLast  = ($i === $count - 1);

        $result[] = processWord($word, $isFirst, $isLast, $afterOpen, $minorWords);

        // Only set afterOpen if word ENDS with ( or [ (next word is first after paren)
        // If paren is at the start like "(For", the word was already handled inside processWord
        $afterOpen = (bool) preg_match('/[\(\[]$/u', $word);
    }

    return implode(' ', $result);
}

/**
 * Process a single word (may recurse for slash/hyphen segments).
 */
function processWord(string $word, bool $isFirst, bool $isLast, bool $afterOpen, array $minorWords): string
{
    // ── Handle slash-separated segments (e.g., "Worthy/Great") ──
    if (strpos($word, '/') !== false && !preg_match('#^https?://#i', $word)) {
        $segments = explode('/', $word);
        $processed = [];
        foreach ($segments as $si => $seg) {
            if ($seg === '') { $processed[] = ''; continue; }
            $segFirst = ($si === 0) ? $isFirst : true; // capitalize after slash
            $segLast  = ($si === count($segments) - 1) ? $isLast : false;
            $segOpen  = ($si === 0) ? $afterOpen : false;
            $processed[] = processWord($seg, $segFirst, $segLast, $segOpen, $minorWords);
        }
        return implode('/', $processed);
    }

    // ── Handle ASCII-hyphenated words (e.g., "Singer-Songwriter", "Post-Rock") ──
    if (strpos($word, '-') !== false) {
        $parts = explode('-', $word);
        if (count($parts) >= 2) {
            $processed = [];
            foreach ($parts as $pi => $part) {
                if ($part === '') { $processed[] = ''; continue; }
                $pFirst = ($pi === 0) ? $isFirst : true; // capitalize after hyphen
                $pLast  = ($pi === count($parts) - 1) ? $isLast : false;
                $pOpen  = ($pi === 0) ? $afterOpen : false;
                $processed[] = processWord($part, $pFirst, $pLast, $pOpen, $minorWords);
            }
            return implode('-', $processed);
        }
    }

    // ── Separate leading punctuation: ( [ " ' ──
    $prefix = '';
    $core   = $word;
    if (preg_match('/^([\(\[\"\'\x{201C}\x{2018}]+)(.+)$/u', $word, $m)) {
        $prefix = $m[1];
        $core   = $m[2];
        // If prefix contains opening paren/bracket, treat next as "first word"
        if (preg_match('/[\(\[]/u', $prefix)) {
            $afterOpen = true;
        }
    }

    // ── Separate trailing punctuation: ) ] " ' , ; : ! ? ──
    $suffix = '';
    if (preg_match('/^(.+?)([\)\]\"\'\x{201D}\x{2019},;:!?]+)$/u', $core, $m2)) {
        // Don't strip dots from abbreviations like "A.I." or "P.M."
        if (!preg_match('/^[A-Za-z]\.[A-Za-z]/u', $core)) {
            $core   = $m2[1];
            $suffix = $m2[2];
        }
    }

    // ── Preservation rules (return as-is) ──

    // ALL-CAPS letters only, 2+ chars (II, III, IV, VA, LP, DJ, MC)
    if (mb_strlen($core) >= 2 && preg_match('/^[A-Z]+$/u', $core)) {
        return $prefix . $core . $suffix;
    }

    // ALL-CAPS with digits (UB40, B52s)
    if (mb_strlen($core) >= 2 && preg_match('/^[A-Z]+\d+\w*$/u', $core)) {
        return $prefix . $core . $suffix;
    }

    // ALL-CAPS with & (R&B)
    if (mb_strlen($core) >= 2 && preg_match('/^[A-Z&]+$/u', $core) && preg_match('/[A-Z]/', $core)) {
        return $prefix . $core . $suffix;
    }

    // Dotted abbreviations (A.I., R.E.M., K.D., P.M.)
    if (preg_match('/^[A-Za-z]\.[A-Za-z]/u', $core)) {
        return $prefix . $core . $suffix;
    }

    // Words with unicode dashes or asterisks (*NSYNC, B*Witched, ‐4‐)
    if (preg_match('/[\*\x{2010}-\x{2015}]/u', $core)) {
        return $prefix . $core . $suffix;
    }

    // Digit-leading: lowercase ALL-CAPS suffix for decade refs (80S→80s, 90S→90s)
    // Requires 2+ digits to avoid band names like 3T, 5SOS
    if (preg_match('/^(\d{2,})([A-Z]+)$/u', $core, $dm)) {
        return $prefix . $dm[1] . mb_strtolower($dm[2]) . $suffix;
    }
    if (preg_match('/^\d/u', $core)) {
        return $prefix . $core . $suffix;
    }

    // Has internal uppercase — camelCase / special proper nouns
    // McEntire, LeBlanc, CeCe, JoJo, HeartCry, AsidorS, O'Connor, etc.
    if (mb_strlen($core) > 1) {
        $rest = mb_substr($core, 1);
        // If the rest (after first char) contains uppercase, and it's not ALL-CAPS, preserve
        if (preg_match('/[A-Z]/u', $rest) && $rest !== mb_strtoupper($rest)) {
            return $prefix . $core . $suffix;
        }
    }

    // ── Apply title case rules ──
    $isFirstEffective = $isFirst || $afterOpen;
    $lowerCore = mb_strtolower($core);

    if ($isFirstEffective || $isLast) {
        return $prefix . mb_ucfirst($lowerCore) . $suffix;
    } elseif (in_array($lowerCore, $minorWords)) {
        return $prefix . $lowerCore . $suffix;
    } else {
        return $prefix . mb_ucfirst($lowerCore) . $suffix;
    }
}

/**
 * Multibyte-safe ucfirst.
 */
function mb_ucfirst(string $str): string
{
    if ($str === '') return '';
    return mb_strtoupper(mb_substr($str, 0, 1)) . mb_substr($str, 1);
}

// ── Database ──
$db = Database::get();

$stats = [
    'artists_changed'    => 0,
    'songs_changed'      => 0,
    'playlists_changed'  => 0,
    'categories_changed' => 0,
    'files_renamed'      => 0,
    'dirs_renamed'       => 0,
];

// ── 1. Categories ──
echo "=== CATEGORIES ===\n";
$rows = $db->query("SELECT id, name FROM categories ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $row) {
    $newName = properTitleCase($row['name'], $MINOR_WORDS);
    if ($newName !== $row['name']) {
        echo "  [{$row['id']}] \"{$row['name']}\" -> \"{$newName}\"\n";
        if (!$dryRun) {
            $stmt = $db->prepare("UPDATE categories SET name = :name WHERE id = :id");
            $stmt->execute(['name' => $newName, 'id' => $row['id']]);
        }
        $stats['categories_changed']++;
    }
}
echo "  Changed: {$stats['categories_changed']}\n\n";

// ── 2. Artists ──
echo "=== ARTISTS ===\n";
$rows = $db->query("SELECT id, name FROM artists ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $row) {
    $newName = properTitleCase($row['name'], $MINOR_WORDS);
    if ($newName !== $row['name']) {
        echo "  [{$row['id']}] \"{$row['name']}\" -> \"{$newName}\"\n";
        if (!$dryRun) {
            $stmt = $db->prepare("UPDATE artists SET name = :name WHERE id = :id");
            $stmt->execute(['name' => $newName, 'id' => $row['id']]);
        }
        $stats['artists_changed']++;
    }
}
echo "  Changed: {$stats['artists_changed']}\n\n";

// ── 3. Playlists ──
echo "=== PLAYLISTS ===\n";
$rows = $db->query("SELECT id, name FROM playlists ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $row) {
    $newName = properTitleCase($row['name'], $MINOR_WORDS);
    if ($newName !== $row['name']) {
        echo "  [{$row['id']}] \"{$row['name']}\" -> \"{$newName}\"\n";
        if (!$dryRun) {
            $stmt = $db->prepare("UPDATE playlists SET name = :name WHERE id = :id");
            $stmt->execute(['name' => $newName, 'id' => $row['id']]);
        }
        $stats['playlists_changed']++;
    }
}
echo "  Changed: {$stats['playlists_changed']}\n\n";

// ── 4. Songs (title + file_path) ──
echo "=== SONGS ===\n";
$rows = $db->query("SELECT id, title, file_path FROM songs ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

// Collect all renames needed (deduplicate)
$dirRenames  = []; // oldPath => newPath
$fileRenames = []; // oldPath => newPath

foreach ($rows as $row) {
    $newTitle = properTitleCase($row['title'], $MINOR_WORDS);
    $titleChanged = ($newTitle !== $row['title']);

    $oldPath = $row['file_path'];
    $parts   = explode('/', $oldPath);

    if (count($parts) < 2) {
        // Bare filename — only update title
        if ($titleChanged && !$dryRun) {
            $stmt = $db->prepare("UPDATE songs SET title = :title WHERE id = :id");
            $stmt->execute(['title' => $newTitle, 'id' => $row['id']]);
            $stats['songs_changed']++;
            echo "  [{$row['id']}] title: \"{$row['title']}\" -> \"{$newTitle}\"\n";
        }
        continue;
    }

    // Split into directory components + filename
    $dirParts = array_slice($parts, 0, -1);
    $filename = end($parts);

    // Title-case each directory component (preserve system dir at root level)
    $newDirParts = [];
    foreach ($dirParts as $di => $dp) {
        if ($di === 0 && in_array(strtolower($dp), $SYSTEM_DIRS)) {
            $newDirParts[] = $dp;
        } else {
            $newDirParts[] = properTitleCase($dp, $MINOR_WORDS);
        }
    }

    // Title-case filename (preserve extension, handle "Artist - Title" format)
    $dotPos    = strrpos($filename, '.');
    $nameNoExt = $dotPos !== false ? substr($filename, 0, $dotPos) : $filename;
    $ext       = $dotPos !== false ? mb_strtolower(substr($filename, $dotPos)) : '';
    if (strpos($nameNoExt, ' - ') !== false) {
        $segments = explode(' - ', $nameNoExt);
        $newSegments = array_map(function($seg) use ($MINOR_WORDS) {
            return properTitleCase(trim($seg), $MINOR_WORDS);
        }, $segments);
        $newFilename = implode(' - ', $newSegments) . $ext;
    } else {
        $newFilename = properTitleCase($nameNoExt, $MINOR_WORDS) . $ext;
    }

    $newPath = implode('/', $newDirParts) . '/' . $newFilename;
    $pathChanged = ($newPath !== $oldPath);

    if ($titleChanged || $pathChanged) {
        if ($titleChanged) {
            echo "  [{$row['id']}] title: \"{$row['title']}\" -> \"{$newTitle}\"\n";
        }
        if ($pathChanged) {
            echo "  [{$row['id']}] path:  \"{$oldPath}\" -> \"{$newPath}\"\n";
        }

        if (!$dryRun) {
            try {
                $stmt = $db->prepare("UPDATE songs SET title = :title, file_path = :path WHERE id = :id");
                $stmt->execute(['title' => $newTitle, 'path' => $newPath, 'id' => $row['id']]);
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), '23505') !== false) {
                    echo "  [{$row['id']}] SKIP (duplicate path): \"{$newPath}\"\n";
                    $stmt2 = $db->prepare("UPDATE songs SET title = :title WHERE id = :id");
                    $stmt2->execute(['title' => $newTitle, 'id' => $row['id']]);
                    continue;
                }
                throw $e;
            }
        }
        $stats['songs_changed']++;

        // Queue directory renames at every depth
        if ($pathChanged) {
            for ($d = 0; $d < count($dirParts); $d++) {
                if ($dirParts[$d] !== $newDirParts[$d]) {
                    // Use new names for already-renamed parents, old name at this level
                    $oldDirFull = $MUSIC_DIR . '/' . implode('/', array_merge(
                        array_slice($newDirParts, 0, $d),
                        [$dirParts[$d]]
                    ));
                    $newDirFull = $MUSIC_DIR . '/' . implode('/', array_slice($newDirParts, 0, $d + 1));
                    $dirRenames[$oldDirFull] = $newDirFull;
                }
            }

            // Queue file rename
            if ($filename !== $newFilename) {
                $oldFileFull = $MUSIC_DIR . '/' . implode('/', $newDirParts) . '/' . $filename;
                $newFileFull = $MUSIC_DIR . '/' . implode('/', $newDirParts) . '/' . $newFilename;
                $fileRenames[$oldFileFull] = $newFileFull;
            }
        }
    }
}
echo "  Changed: {$stats['songs_changed']}\n\n";

// ── 5. Rename files and directories on disk ──
if (!$dryRun && !$dbOnly) {
    echo "=== FILE RENAMES ===\n";

    // First: rename directories (shallowest first)
    uksort($dirRenames, function ($a, $b) {
        return strlen($a) - strlen($b);
    });

    foreach ($dirRenames as $oldDir => $newDir) {
        if ($oldDir === $newDir) continue;
        if (!is_dir($oldDir)) {
            continue;
        }
        if (is_dir($newDir) && $oldDir !== $newDir) {
            if (strtolower($oldDir) === strtolower($newDir)) {
                $tmpDir = $oldDir . '.tmp_rename_' . getmypid();
                if (rename($oldDir, $tmpDir) && !rename($tmpDir, $newDir)) {
                    rename($tmpDir, $oldDir); // rollback
                    echo "  WARN: case-rename failed, rolled back: {$oldDir}\n";
                    continue;
                }
            } else {
                echo "  SKIP dir (target exists): {$oldDir} -> {$newDir}\n";
                continue;
            }
        } else {
            rename($oldDir, $newDir);
        }
        echo "  DIR: {$oldDir} -> {$newDir}\n";
        $stats['dirs_renamed']++;
    }

    // Then: rename individual files
    foreach ($fileRenames as $oldFile => $newFile) {
        if ($oldFile === $newFile) continue;
        if (!file_exists($oldFile)) continue;

        if (!file_exists($newFile)) {
            rename($oldFile, $newFile);
            echo "  FILE: " . basename($oldFile) . " -> " . basename($newFile) . "\n";
            $stats['files_renamed']++;
        } elseif (strtolower($oldFile) === strtolower($newFile)) {
            $tmpFile = $oldFile . '.tmp_rename_' . getmypid();
            if (rename($oldFile, $tmpFile) && !rename($tmpFile, $newFile)) {
                rename($tmpFile, $oldFile); // rollback
                echo "  WARN: case-rename failed, rolled back: " . basename($oldFile) . "\n";
                continue;
            }
            echo "  FILE: " . basename($oldFile) . " -> " . basename($newFile) . "\n";
            $stats['files_renamed']++;
        }
    }

    echo "  Dirs renamed:  {$stats['dirs_renamed']}\n";
    echo "  Files renamed: {$stats['files_renamed']}\n\n";
} elseif ($dryRun) {
    echo "=== FILE RENAMES (would be applied) ===\n";
    foreach ($dirRenames as $old => $new) {
        echo "  DIR: {$old} -> {$new}\n";
    }
    foreach ($fileRenames as $old => $new) {
        echo "  FILE: " . basename($old) . " -> " . basename($new) . "\n";
    }
    echo "\n";
}

echo "=== SUMMARY ===\n";
echo "  Categories changed: {$stats['categories_changed']}\n";
echo "  Artists changed:    {$stats['artists_changed']}\n";
echo "  Playlists changed:  {$stats['playlists_changed']}\n";
echo "  Songs changed:      {$stats['songs_changed']}\n";
echo "  Dirs renamed:       {$stats['dirs_renamed']}\n";
echo "  Files renamed:      {$stats['files_renamed']}\n";
echo "\nDone.\n";
