<?php

declare(strict_types=1);

/**
 * RendezVox — Path Renamer
 *
 * Atomically renames directories and files to proper title case, updating DB
 * references immediately after each rename to avoid streaming disruption.
 *
 * Phase 1: Rename directories (shallowest first) — one rename + bulk DB update each
 * Phase 2: Rename individual files — one rename + single DB update each
 *
 * Uses pathTitleCase() which forces title-casing of ALL-CAPS words >3 chars
 * (COUNTRY → Country) while preserving short acronyms (DJ, MC, III).
 *
 * Usage:
 *   php rename_paths.php [--dry-run] [--auto]
 */

require __DIR__ . '/../core/Database.php';

$MUSIC_DIR    = '/var/lib/rendezvox/music';
$SYSTEM_DIRS  = ['imports', 'tagged', 'upload', '_untagged', '_duplicates'];
$MINOR_WORDS  = ['a', 'an', 'the', 'and', 'but', 'or', 'nor', 'for', 'yet', 'so',
                 'at', 'by', 'in', 'of', 'on', 'to', 'up', 'as', 'vs',
                 'is', 'it', 'if', 'no', 'not', 'with', 'from'];
$lockFile     = '/tmp/rename-paths.lock';
$stopFile     = '/tmp/rename-paths.lock.stop';
$progressFile = '/tmp/rendezvox_rename_paths.json';
$autoLastFile = '/tmp/rendezvox_auto_rename_last.json';
$dryRun       = in_array('--dry-run', $argv ?? []);
$autoMode     = in_array('--auto', $argv ?? []);

// -- Auto mode: check if enabled in settings --
if ($autoMode) {
    $dbCheck = Database::get();
    $row = $dbCheck->prepare("SELECT value FROM settings WHERE key = 'auto_rename_paths_enabled'");
    $row->execute();
    if ($row->fetchColumn() !== 'true') {
        exit(0);
    }
}

// -- Lock file --
if (file_exists($lockFile)) {
    $lockPid = (int) file_get_contents($lockFile);
    if ($lockPid > 0 && file_exists("/proc/{$lockPid}")) {
        $cmdline = @file_get_contents("/proc/{$lockPid}/cmdline");
        if ($cmdline !== false && str_contains($cmdline, 'rename_paths')) {
            log_msg("Another instance is running (PID {$lockPid}), exiting.");
            exit(0);
        }
    }
    @unlink($lockFile);
}
file_put_contents($lockFile, (string) getmypid());
register_shutdown_function(function () use ($lockFile) {
    @unlink($lockFile);
});

// ── Recover stale .tmp_rename_* items from a previous crashed run ──
// Must run before the directory scan so orphans don't appear as rename candidates.
(function () use ($MUSIC_DIR) {
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($MUSIC_DIR, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iter as $item) {
        $path = $item->getPathname();
        if (!preg_match('/\.tmp_rename_\d+$/', $path)) continue;
        $orig = preg_replace('/\.tmp_rename_\d+$/', '', $path);
        if (!file_exists($orig)) {
            // Original gone — the first rename completed but second did not; restore it
            rename($path, $orig);
            log_msg("Recovered stale tmp: " . basename($orig));
        } elseif (is_dir($path)) {
            // Both exist as directories — merge files from tmp into original, then remove tmp
            $moved = mergeDirFiles($path, $orig);
            log_msg("Merged stale tmp into original: " . basename($orig) . " ({$moved} file(s))");
        } else {
            @unlink($path);
            log_msg("Removed stale tmp file: " . basename($path));
        }
    }
})();

function shouldStop(string $stopFile): bool
{
    if (file_exists($stopFile)) {
        @unlink($stopFile);
        return true;
    }
    return false;
}

// ── Title-case functions ──────────────────────────────────

// Acronyms to preserve as ALL-CAPS in path contexts
$PATH_ACRONYMS = ['AI', 'DJ', 'MC', 'II', 'III', 'IV', 'VI', 'VII', 'VIII', 'IX', 'XI', 'XII',
                  'KZ', 'TJ', 'VA', 'LP', 'EP', 'CD', 'FM', 'AM', 'TV', 'UK', 'US', 'USA',
                  'OPM', 'EDM', 'GMA', 'TNT', 'MLTR', 'MYMP', 'DWTA', 'BTS', 'SWV', 'TLC', 'ATC',
                  'NSYNC', 'ABS', 'CBN', 'ABBA', 'ACDC', 'LMFAO', 'INXS', 'DNA', 'NYC'];

/**
 * Path-aware title case: pre-lowers ALL-CAPS words before title-casing,
 * except known acronyms (DJ, MC, OPM, etc.) which stay uppercase.
 * Handles hyphens, accented characters, and case-insensitive acronym recovery.
 */
function pathTitleCase(string $str, array $minorWords): string
{
    global $PATH_ACRONYMS;
    $words = explode(' ', $str);
    $processed = [];
    foreach ($words as $word) {
        $processed[] = preLowerWord($word, $PATH_ACRONYMS);
    }
    return properTitleCase(implode(' ', $processed), $minorWords);
}

/**
 * Pre-lower a single word (possibly hyphenated) before title-casing.
 * - Recursively handles hyphenated words (ABS-CBN, NEWTON-JOHN)
 * - Strips punctuation for analysis (handles "(LP", etc.)
 * - Uppercases dotted abbreviations (a.i.→A.I., r.e.m.→R.E.M.)
 * - Preserves alpha-numeric words (UB40, B52s)
 * - Case-insensitive acronym recovery (Iv→IV, Tj→TJ, Nsync→NSYNC)
 * - mb-aware "mostly uppercase" detection (BUBLé→bublé, COUNTRY→country)
 */
function preLowerWord(string $word, array $acronyms): string
{
    if ($word === '') return $word;

    // Handle hyphenated words recursively
    if (strpos($word, '-') !== false) {
        $parts = explode('-', $word);
        return implode('-', array_map(fn($p) => preLowerWord($p, $acronyms), $parts));
    }

    // Strip leading/trailing punctuation for analysis
    $prefix = '';
    $core = $word;
    $suffix = '';
    if (preg_match('/^([^\pL\pN]*)(.+?)([^\pL\pN]*)$/u', $word, $pm)) {
        $prefix = $pm[1];
        $core = $pm[2];
        $suffix = $pm[3];
    }

    // Dotted abbreviations: force uppercase (a.i.→A.I., r.e.m.→R.E.M., k.d.→K.D.)
    if (preg_match('/^[A-Za-z]\.[A-Za-z]/u', $core)) {
        return $prefix . mb_strtoupper($core) . $suffix;
    }

    // Alpha-numeric mixed words: preserve as-is (UB40, B52s)
    if (preg_match('/\d/', $core) && preg_match('/[A-Za-z]/', $core)) {
        return $word;
    }

    // Words with & (W&W, R&B): preserve as-is
    if (strpos($core, '&') !== false) {
        return $word;
    }

    // Words with unicode dashes or asterisks (JAY‐Z, *NSYNC, B*Witched): preserve as-is
    if (preg_match('/[\*\x{2010}-\x{2015}]/u', $core)) {
        return $word;
    }

    // Acronym handling
    $upper = mb_strtoupper($core);
    if (mb_strlen($core) >= 2 && in_array($upper, $acronyms)) {
        // Already correct uppercase form → preserve
        if ($core === $upper) {
            return $word;
        }
        // Recovery: skip common English words that overlap with acronyms
        // (Am, Us, etc. should NOT become AM, US in filenames)
        static $ambiguous = ['am', 'us', 'ai', 'an', 'as', 'at', 'by', 'if', 'in',
                             'is', 'it', 'no', 'of', 'on', 'or', 'so', 'to', 'up'];
        $lower = mb_strtolower($core);
        if (!in_array($lower, $ambiguous)) {
            return $prefix . $upper . $suffix;
        }
    }

    // Lowercase words that are "mostly uppercase" (handles BUBLé, COUNTRY, etc.)
    if (mb_strlen($core) >= 2 && isMostlyUppercase($core)) {
        return $prefix . mb_strtolower($core) . $suffix;
    }

    return $word;
}

/**
 * Check if a word is "mostly uppercase" using mb-aware character analysis.
 * Returns true if >=75% of alphabetic characters are uppercase.
 */
function isMostlyUppercase(string $word): bool
{
    $upperCount = 0;
    $alphaCount = 0;
    $len = mb_strlen($word);

    for ($i = 0; $i < $len; $i++) {
        $char = mb_substr($word, $i, 1);
        $charUpper = mb_strtoupper($char);
        $charLower = mb_strtolower($char);

        // Only count characters that have distinct upper/lower forms (alphabetic)
        if ($charUpper !== $charLower) {
            $alphaCount++;
            if ($char === $charUpper) {
                $upperCount++;
            }
        }
    }

    if ($alphaCount < 2) return false;
    return ($upperCount / $alphaCount) >= 0.75;
}

function properTitleCase(string $str, array $minorWords): string
{
    if ($str === '') return '';

    $words  = explode(' ', $str);
    $count  = count($words);
    $result = [];
    $afterOpen = false;

    for ($i = 0; $i < $count; $i++) {
        $word = $words[$i];
        if ($word === '') { $result[] = $word; continue; }

        $isFirst = ($i === 0);
        $isLast  = ($i === $count - 1);
        $result[] = processWord($word, $isFirst, $isLast, $afterOpen, $minorWords);
        $afterOpen = (bool) preg_match('/[\(\[]$/u', $word);
    }

    return implode(' ', $result);
}

function processWord(string $word, bool $isFirst, bool $isLast, bool $afterOpen, array $minorWords): string
{
    // Slash-separated segments
    if (strpos($word, '/') !== false && !preg_match('#^https?://#i', $word)) {
        $segments = explode('/', $word);
        $processed = [];
        foreach ($segments as $si => $seg) {
            if ($seg === '') { $processed[] = ''; continue; }
            $processed[] = processWord($seg, ($si === 0) ? $isFirst : true, ($si === count($segments) - 1) ? $isLast : false, ($si === 0) ? $afterOpen : false, $minorWords);
        }
        return implode('/', $processed);
    }

    // Hyphenated words
    if (strpos($word, '-') !== false) {
        $parts = explode('-', $word);
        if (count($parts) >= 2) {
            $processed = [];
            foreach ($parts as $pi => $part) {
                if ($part === '') { $processed[] = ''; continue; }
                $processed[] = processWord($part, ($pi === 0) ? $isFirst : true, ($pi === count($parts) - 1) ? $isLast : false, ($pi === 0) ? $afterOpen : false, $minorWords);
            }
            return implode('-', $processed);
        }
    }

    // Separate leading punctuation
    $prefix = '';
    $core   = $word;
    if (preg_match('/^([\(\[\"\'\x{201C}\x{2018}]+)(.+)$/u', $word, $m)) {
        $prefix = $m[1];
        $core   = $m[2];
        if (preg_match('/[\(\[]/u', $prefix)) $afterOpen = true;
    }

    // Separate trailing punctuation
    $suffix = '';
    if (preg_match('/^(.+?)([\)\]\"\'\x{201D}\x{2019},;:!?]+)$/u', $core, $m2)) {
        if (!preg_match('/^[A-Za-z]\.[A-Za-z]/u', $core)) {
            $core   = $m2[1];
            $suffix = $m2[2];
        }
    }

    // Preservation rules
    if (mb_strlen($core) >= 2 && preg_match('/^[A-Z]+$/u', $core)) return $prefix . $core . $suffix;
    if (mb_strlen($core) >= 2 && preg_match('/^[A-Z]+\d+\w*$/u', $core)) return $prefix . $core . $suffix;
    if (mb_strlen($core) >= 2 && preg_match('/^[A-Z&]+$/u', $core) && preg_match('/[A-Z]/', $core)) return $prefix . $core . $suffix;
    if (preg_match('/^[A-Za-z]\.[A-Za-z]/u', $core)) return $prefix . $core . $suffix;
    if (preg_match('/[\*\x{2010}-\x{2015}]/u', $core)) return $prefix . $core . $suffix;
    // Digit-leading: lowercase ALL-CAPS suffix for decade refs (80S→80s, 90S→90s)
    // Requires 2+ digits to avoid band names like 3T, 5SOS
    if (preg_match('/^(\d{2,})([A-Z]+)$/u', $core, $dm)) {
        return $prefix . $dm[1] . mb_strtolower($dm[2]) . $suffix;
    }
    if (preg_match('/^\d/u', $core)) return $prefix . $core . $suffix;

    if (mb_strlen($core) > 1) {
        $rest = mb_substr($core, 1);
        if (preg_match('/[A-Z]/u', $rest) && $rest !== mb_strtoupper($rest)) return $prefix . $core . $suffix;
    }

    // Apply title case
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

function mb_ucfirst(string $str): string
{
    if ($str === '') return '';
    return mb_strtoupper(mb_substr($str, 0, 1)) . mb_substr($str, 1);
}

/**
 * Merge files (not subdirs) from $src into $dst, then remove $src if empty.
 * Used when two directories with the same case-insensitive name both exist
 * on a case-sensitive filesystem and need to be consolidated.
 * Returns the number of files moved.
 */
function mergeDirFiles(string $src, string $dst): int
{
    $moved = 0;
    foreach (scandir($src) ?: [] as $item) {
        if ($item === '.' || $item === '..') continue;
        $srcPath = $src . '/' . $item;
        $dstPath = $dst . '/' . $item;
        if (!is_file($srcPath)) continue; // subdirs handled by dirRenames pass
        if (file_exists($dstPath)) continue; // skip conflicts
        if (rename($srcPath, $dstPath)) $moved++;
    }
    @rmdir($src); // remove only if now empty
    return $moved;
}

// ── Main ──────────────────────────────────────────────────

$db = Database::get();

$stats = [
    'status'        => 'running',
    'phase'         => 'directories',
    'dirs_renamed'  => 0,
    'files_renamed' => 0,
    'total'         => 0,
    'processed'     => 0,
    'started_at'    => date('c'),
];

log_msg('Path renamer started' . ($dryRun ? ' (DRY-RUN)' : ''));
writeProgress($progressFile, $stats);

// ── Phase 1: Directory Renames ──
// Collect directories from BOTH the database AND the filesystem.
// The DB query finds dirs with active songs; the filesystem scan catches
// directories that exist on disk but have no active songs (e.g. genre
// folders with only inactive/unscanned files).

// 1a) Directories referenced by active songs in the DB
$rows = $db->query("
    SELECT DISTINCT regexp_replace(file_path, '/[^/]+$', '') AS dir_path
    FROM songs WHERE is_active = true
    ORDER BY dir_path
")->fetchAll(PDO::FETCH_ASSOC);

$allDirPaths = [];
foreach ($rows as $row) {
    $allDirPaths[$row['dir_path']] = true;
}

// 1b) Directories that actually exist on disk (recursive scan)
// Use callback filter to skip hidden directories BEFORE descending (avoids permission errors on .albumart etc.)
$diskDirIterator = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($MUSIC_DIR, FilesystemIterator::SKIP_DOTS),
        function ($current) {
            $name = $current->getFilename();
            return !str_starts_with($name, '.') && !preg_match('/\.tmp_rename_\d+$/', $name);
        }
    ),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($diskDirIterator as $fileInfo) {
    if (!$fileInfo->isDir()) continue;
    $absPath = $fileInfo->getPathname();
    $relPath = substr($absPath, strlen($MUSIC_DIR) + 1);
    if ($relPath === false || $relPath === '') continue;
    $allDirPaths[$relPath] = true;
}

$dirRenames = []; // oldFullPath => [new_full, old_rel, new_rel]

foreach (array_keys($allDirPaths) as $dirPath) {
    $parts = explode('/', $dirPath);

    $newParts = [];
    foreach ($parts as $i => $part) {
        // Skip hidden dirs and system dirs at the top level
        if (str_starts_with($part, '.')) {
            $newParts[] = $part;
        } elseif ($i === 0 && in_array(strtolower($part), $SYSTEM_DIRS)) {
            $newParts[] = $part;
        } else {
            $newParts[] = pathTitleCase($part, $MINOR_WORDS);
        }
    }

    // Check each level for differences
    for ($d = 0; $d < count($parts); $d++) {
        if ($parts[$d] !== $newParts[$d]) {
            $oldFull = $MUSIC_DIR . '/' . implode('/', array_merge(
                array_slice($newParts, 0, $d),
                [$parts[$d]]
            ));
            $newFull = $MUSIC_DIR . '/' . implode('/', array_slice($newParts, 0, $d + 1));

            $oldRel = implode('/', array_merge(
                array_slice($newParts, 0, $d),
                [$parts[$d]]
            ));
            $newRel = implode('/', array_slice($newParts, 0, $d + 1));

            $dirRenames[$oldFull] = [
                'new_full' => $newFull,
                'old_rel'  => $oldRel,
                'new_rel'  => $newRel,
            ];
        }
    }
}

// Sort shallowest first
uksort($dirRenames, fn($a, $b) => strlen($a) - strlen($b));

log_msg('Phase 1: ' . count($dirRenames) . ' directories to rename');

foreach ($dirRenames as $oldFull => $info) {
    if (shouldStop($stopFile)) {
        log_msg('Stop signal received.');
        $stats['status'] = 'stopped';
        $stats['finished_at'] = date('c');
        writeProgress($progressFile, $stats);
        exit(0);
    }

    $newFull = $info['new_full'];
    $oldRel  = $info['old_rel'];
    $newRel  = $info['new_rel'];

    log_msg("  DIR: {$oldRel}/ -> {$newRel}/");

    if (!$dryRun) {
        // Step 1: Rename on disk
        if (is_dir($oldFull)) {
            if (is_dir($newFull) && strtolower($oldFull) === strtolower($newFull)) {
                if (realpath($oldFull) === realpath($newFull)) {
                    // Case-insensitive FS: same physical dir, just changing case
                    $tmpDir = $oldFull . '.tmp_rename_' . getmypid();
                    if (rename($oldFull, $tmpDir) && !rename($tmpDir, $newFull)) {
                        rename($tmpDir, $oldFull); // rollback
                        log_msg("    WARN: case-rename failed, rolled back");
                        $stats['processed']++;
                        writeProgress($progressFile, $stats);
                        continue;
                    }
                } else {
                    // Case-sensitive FS: two separate dirs with same lowercased name — merge files
                    $merged = mergeDirFiles($oldFull, $newFull);
                    log_msg("    MERGE: {$merged} file(s) moved into target dir, removed old");
                }
            } elseif (!is_dir($newFull)) {
                rename($oldFull, $newFull);
            } else {
                log_msg("    SKIP (target exists, different name)");
                $stats['processed']++;
                writeProgress($progressFile, $stats);
                continue;
            }
        }

        // Step 2: Update all affected songs in DB (atomic with rename)
        $oldPrefix = $oldRel . '/';
        $newPrefix = $newRel . '/';

        $stmt = $db->prepare("
            UPDATE songs
            SET file_path = :new_prefix || substr(file_path, :start_pos)
            WHERE file_path LIKE :pattern
        ");
        $stmt->execute([
            'new_prefix' => $newPrefix,
            'start_pos'  => mb_strlen($oldPrefix) + 1,
            'pattern'    => str_replace(['%', '_'], ['\\%', '\\_'], $oldPrefix) . '%',
        ]);

        $affected = $stmt->rowCount();
        if ($affected > 0) {
            log_msg("    Updated {$affected} song path(s)");
        }

        $stats['dirs_renamed']++;
    }

    $stats['processed']++;
    $stats['total'] = count($dirRenames) + 1; // +1 to show we're not done yet
    writeProgress($progressFile, $stats);
}

// ── Phase 2: File Renames ──
$stats['phase'] = 'files';
writeProgress($progressFile, $stats);

// Re-read ALL songs (paths updated by Phase 1) — include inactive so their paths stay in sync
$rows = $db->query("SELECT id, file_path FROM songs ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

$fileRenames = [];
foreach ($rows as $row) {
    $parts = explode('/', $row['file_path']);
    $filename = end($parts);

    $dotPos    = strrpos($filename, '.');
    $nameNoExt = $dotPos !== false ? substr($filename, 0, $dotPos) : $filename;
    $ext       = $dotPos !== false ? mb_strtolower(substr($filename, $dotPos)) : '';

    if (strpos($nameNoExt, ' - ') !== false) {
        $segments = explode(' - ', $nameNoExt);
        $newSegments = array_map(function ($seg) use ($MINOR_WORDS) {
            return pathTitleCase(trim($seg), $MINOR_WORDS);
        }, $segments);
        $newFilename = implode(' - ', $newSegments) . $ext;
    } else {
        $newFilename = pathTitleCase($nameNoExt, $MINOR_WORDS) . $ext;
    }

    if ($filename !== $newFilename) {
        $dirPath = implode('/', array_slice($parts, 0, -1));
        $fileRenames[] = [
            'song_id'  => $row['id'],
            'old_path' => $row['file_path'],
            'new_path' => $dirPath . '/' . $newFilename,
            'old_abs'  => $MUSIC_DIR . '/' . $row['file_path'],
            'new_abs'  => $MUSIC_DIR . '/' . $dirPath . '/' . $newFilename,
            'old_name' => $filename,
            'new_name' => $newFilename,
        ];
    }
}

$stats['total'] = $stats['processed'] + count($fileRenames);
writeProgress($progressFile, $stats);

log_msg('Phase 2: ' . count($fileRenames) . ' files to rename');

foreach ($fileRenames as $r) {
    if (shouldStop($stopFile)) {
        log_msg('Stop signal received.');
        $stats['status'] = 'stopped';
        $stats['finished_at'] = date('c');
        writeProgress($progressFile, $stats);
        exit(0);
    }

    log_msg("  FILE: {$r['old_name']} -> {$r['new_name']}");

    if (!$dryRun) {
        // Step 1: Rename on disk
        if (file_exists($r['old_abs'])) {
            if (!file_exists($r['new_abs'])) {
                rename($r['old_abs'], $r['new_abs']);
            } elseif (strtolower($r['old_abs']) === strtolower($r['new_abs'])) {
                $tmp = $r['old_abs'] . '.tmp_rename_' . getmypid();
                if (rename($r['old_abs'], $tmp) && !rename($tmp, $r['new_abs'])) {
                    rename($tmp, $r['old_abs']); // rollback
                    log_msg("    WARN: case-rename failed, rolled back");
                    $stats['processed']++;
                    writeProgress($progressFile, $stats);
                    continue;
                }
            } else {
                log_msg("    SKIP (target exists)");
                $stats['processed']++;
                writeProgress($progressFile, $stats);
                continue;
            }
        }

        // Step 2: Update DB
        try {
            $stmt = $db->prepare("UPDATE songs SET file_path = :path WHERE id = :id");
            $stmt->execute(['path' => $r['new_path'], 'id' => $r['song_id']]);
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), '23505') !== false) {
                log_msg("    SKIP (duplicate path in DB)");
                if (file_exists($r['new_abs']) && !file_exists($r['old_abs'])) {
                    rename($r['new_abs'], $r['old_abs']);
                }
                $stats['processed']++;
                writeProgress($progressFile, $stats);
                continue;
            }
            throw $e;
        }

        $stats['files_renamed']++;
    }

    $stats['processed']++;
    writeProgress($progressFile, $stats);
}

// ── Phase 3: Lowercase File Extensions on Disk ──
// Catches files not tracked in the DB (e.g. .Mp3, .FLAC imports)
$stats['phase'] = 'extensions';
$stats['exts_fixed'] = 0;
writeProgress($progressFile, $stats);

$extIterator = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($MUSIC_DIR, FilesystemIterator::SKIP_DOTS),
        function ($current) {
            return !str_starts_with($current->getFilename(), '.');
        }
    ),
    RecursiveIteratorIterator::LEAVES_ONLY
);

$AUDIO_EXTS = ['mp3', 'flac', 'wav', 'ogg', 'm4a', 'wma', 'aac', 'opus', 'aiff'];
$extRenames = [];
foreach ($extIterator as $fileInfo) {
    if (!$fileInfo->isFile()) continue;
    $absPath = $fileInfo->getPathname();
    $basename = $fileInfo->getBasename();
    $dotPos = strrpos($basename, '.');
    if ($dotPos === false) continue;
    $ext = substr($basename, $dotPos);
    $lowerExt = mb_strtolower($ext);
    // Only process known audio extensions (skip temp/artifact files)
    if (!in_array(ltrim($lowerExt, '.'), $AUDIO_EXTS)) continue;
    if ($ext !== $lowerExt) {
        $newAbs = $fileInfo->getPath() . '/' . substr($basename, 0, $dotPos) . $lowerExt;
        $extRenames[] = ['old' => $absPath, 'new' => $newAbs, 'name' => $basename];
    }
}

log_msg('Phase 3: ' . count($extRenames) . ' file extensions to lowercase');

foreach ($extRenames as $er) {
    if (shouldStop($stopFile)) {
        log_msg('Stop signal received.');
        $stats['status'] = 'stopped';
        $stats['finished_at'] = date('c');
        writeProgress($progressFile, $stats);
        exit(0);
    }

    $oldBase = basename($er['old']);
    $newBase = basename($er['new']);
    log_msg("  EXT: {$oldBase} -> {$newBase}");

    if (!$dryRun) {
        if (file_exists($er['old'])) {
            if (strtolower($er['old']) === strtolower($er['new'])) {
                // Case-only rename: use temp file
                $tmp = $er['old'] . '.tmp_ext_' . getmypid();
                rename($er['old'], $tmp);
                rename($tmp, $er['new']);
            } else {
                rename($er['old'], $er['new']);
            }

            // Update DB if this file happens to be tracked
            $relOld = substr($er['old'], strlen($MUSIC_DIR) + 1);
            $relNew = substr($er['new'], strlen($MUSIC_DIR) + 1);
            $stmt = $db->prepare("UPDATE songs SET file_path = :new WHERE file_path = :old");
            $stmt->execute(['new' => $relNew, 'old' => $relOld]);

            $stats['exts_fixed']++;
        }
    }

    $stats['processed']++;
    writeProgress($progressFile, $stats);
}

$stats['status'] = 'done';
$stats['finished_at'] = date('c');
writeProgress($progressFile, $stats);

$prefix = $dryRun ? '[DRY-RUN] ' : '';
log_msg("{$prefix}Path renamer complete: {$stats['dirs_renamed']} dirs, {$stats['files_renamed']} files, {$stats['exts_fixed']} extensions renamed.");

// Write auto-mode summary for status endpoint
if ($autoMode && !$dryRun) {
    $autoSummary = [
        'ran_at'        => date('c'),
        'total'         => $stats['processed'],
        'dirs_renamed'  => $stats['dirs_renamed'],
        'files_renamed' => $stats['files_renamed'],
        'exts_fixed'    => $stats['exts_fixed'],
        'status'        => 'done',
    ];
    @file_put_contents($autoLastFile, json_encode($autoSummary, JSON_PRETTY_PRINT), LOCK_EX);
}

function writeProgress(string $file, array $stats): void
{
    @file_put_contents($file, json_encode($stats, JSON_PRETTY_PRINT), LOCK_EX);
}

function log_msg(string $msg): void
{
    $ts = date('Y-m-d H:i:s');
    echo "[{$ts}] {$msg}\n";
}
