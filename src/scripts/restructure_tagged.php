<?php

declare(strict_types=1);

/**
 * RendezVox — Flatten tagged/files/ directory structure.
 *
 * Moves files from:  tagged/files/{Genre}/{Artist}/{file}
 * To:                tagged/files/{Artist}/{file}
 *
 * Also updates songs.file_path in the database and resets tagged_at
 * so fix_genres.php can retag with the embedded-genre-first strategy.
 *
 * Usage:
 *   php restructure_tagged.php           — execute migration
 *   php restructure_tagged.php --dry-run — preview only, no changes
 */

$musicDir = '/var/lib/rendezvox/music';
$dryRun   = in_array('--dry-run', $argv ?? []);
$stopFile = '/tmp/media-organizer.lock.stop';

if ($dryRun) {
    echo "=== DRY RUN — no files will be moved ===\n\n";
}

if (!is_dir($musicDir)) {
    echo "ERROR: Music directory not found: {$musicDir}\n";
    exit(1);
}

require __DIR__ . '/../core/Database.php';
$db = Database::get();

// Step 1: Stop media-organizer
echo "Step 1: Stopping media-organizer...\n";
if (!$dryRun) {
    file_put_contents($stopFile, '1');
    sleep(2);
}

// Step 2: Scan tagged/files/ for Genre subdirectories
$taggedFilesDir = $musicDir . '/tagged/files';
if (!is_dir($taggedFilesDir)) {
    echo "ERROR: tagged/files/ directory not found\n";
    exit(1);
}

$movedDirs  = 0;
$movedFiles = 0;
$skipped    = 0;

echo "\nStep 2: Flattening tagged/files/{Genre}/{Artist}/ → tagged/files/{Artist}/...\n";

foreach (scandir($taggedFilesDir) ?: [] as $genreDir) {
    if ($genreDir === '.' || $genreDir === '..' || str_starts_with($genreDir, '.')) continue;

    $genrePath = $taggedFilesDir . '/' . $genreDir;
    if (!is_dir($genrePath)) continue;

    // Check if this is a Genre dir (contains Artist subdirs, not audio files directly)
    // A Genre dir has subdirs inside it; an Artist dir has audio files inside it.
    $hasSubdirs = false;
    foreach (scandir($genrePath) ?: [] as $child) {
        if ($child === '.' || $child === '..') continue;
        if (is_dir($genrePath . '/' . $child)) {
            $hasSubdirs = true;
            break;
        }
    }

    if (!$hasSubdirs) {
        // This is already an Artist dir at the correct level — skip
        echo "  SKIP (artist dir): {$genreDir}\n";
        continue;
    }

    echo "  Genre: {$genreDir}\n";

    // Iterate Artist subdirectories inside this Genre dir
    foreach (scandir($genrePath) ?: [] as $artistDir) {
        if ($artistDir === '.' || $artistDir === '..' || str_starts_with($artistDir, '.')) continue;

        $artistSrcPath  = $genrePath . '/' . $artistDir;
        $artistDestPath = $taggedFilesDir . '/' . $artistDir;

        if (!is_dir($artistSrcPath)) {
            // Stray file at genre level — move to tagged/files/ root
            $destFile = $taggedFilesDir . '/' . $artistDir;
            if (!file_exists($destFile)) {
                if ($dryRun) {
                    echo "    Would move stray file: {$genreDir}/{$artistDir}\n";
                } else {
                    rename($artistSrcPath, $destFile);
                    echo "    Moved stray file: {$genreDir}/{$artistDir}\n";
                }
            }
            continue;
        }

        if (!is_dir($artistDestPath)) {
            // Artist dir doesn't exist at destination — move entire dir
            if ($dryRun) {
                echo "    Would move: {$genreDir}/{$artistDir}/ → {$artistDir}/\n";
            } else {
                if (rename($artistSrcPath, $artistDestPath)) {
                    echo "    Moved: {$genreDir}/{$artistDir}/ → {$artistDir}/\n";
                    $movedDirs++;
                } else {
                    echo "    ERROR: Failed to move {$genreDir}/{$artistDir}/\n";
                }
            }
        } else {
            // Artist dir already exists — merge files individually
            echo "    Merging: {$genreDir}/{$artistDir}/ → {$artistDir}/ (exists)\n";
            foreach (scandir($artistSrcPath) ?: [] as $file) {
                if ($file === '.' || $file === '..') continue;

                $fileSrc  = $artistSrcPath . '/' . $file;
                $fileDest = $artistDestPath . '/' . $file;

                if (file_exists($fileDest)) {
                    // Resolve collision — append counter
                    $ext  = pathinfo($file, PATHINFO_EXTENSION);
                    $base = pathinfo($file, PATHINFO_FILENAME);
                    $counter = 2;
                    while (file_exists($fileDest)) {
                        $fileDest = $artistDestPath . '/' . $base . " ({$counter})." . $ext;
                        $counter++;
                    }
                }

                if ($dryRun) {
                    echo "      Would move: {$file}\n";
                } else {
                    if (is_dir($fileSrc)) {
                        // Subdirectory inside artist dir — move as-is
                        rename($fileSrc, $fileDest);
                    } else {
                        rename($fileSrc, $fileDest);
                        @chmod($fileDest, 0664);
                    }
                    $movedFiles++;
                }
            }

            // Remove now-empty artist source dir
            if (!$dryRun) {
                @rmdir($artistSrcPath);
            }
        }
    }

    // Remove now-empty genre dir
    if (!$dryRun) {
        $remaining = array_diff(scandir($genrePath) ?: [], ['.', '..']);
        if (empty($remaining)) {
            rmdir($genrePath);
            echo "  Removed empty genre dir: {$genreDir}/\n";
        } else {
            echo "  WARN: Genre dir not empty: {$genreDir}/ (" . count($remaining) . " items remain)\n";
        }
    }
}

// Step 3: Update DB paths — strip genre segment
echo "\nStep 3: Updating database paths...\n";
if (!$dryRun) {
    // tagged/files/{Genre}/{Artist}/{file} → tagged/files/{Artist}/{file}
    // Match paths with 3+ segments after tagged/files/
    $stmt = $db->query("
        SELECT id, file_path FROM songs
        WHERE file_path ~ '^tagged/files/[^/]+/[^/]+/'
    ");
    $updated = 0;
    while ($row = $stmt->fetch()) {
        // Strip the genre segment (first segment after tagged/files/)
        $newPath = preg_replace('#^tagged/files/[^/]+/(.+)$#', 'tagged/files/$1', $row['file_path']);
        if ($newPath !== $row['file_path']) {
            $db->prepare("UPDATE songs SET file_path = :path WHERE id = :id")
               ->execute(['path' => $newPath, 'id' => $row['id']]);
            $updated++;
        }
    }
    echo "  Updated {$updated} song paths\n";
} else {
    $stmt = $db->query("
        SELECT COUNT(*) FROM songs
        WHERE file_path ~ '^tagged/files/[^/]+/[^/]+/'
    ");
    $count = (int) $stmt->fetchColumn();
    echo "  Would update {$count} song paths\n";
}

// Step 4: Reset tagged_at so fix_genres.php can retag
echo "\nStep 4: Resetting tagged_at for retagging...\n";
if (!$dryRun) {
    $stmt = $db->exec("UPDATE songs SET tagged_at = NULL WHERE file_path LIKE 'tagged/files/%'");
    echo "  Reset tagged_at for tagged/files/ songs\n";
} else {
    echo "  Would reset tagged_at for all tagged/files/ songs\n";
}

echo "\n=== Migration complete ===\n";
echo "Dirs moved:   {$movedDirs}\n";
echo "Files merged:  {$movedFiles}\n";
echo "Skipped:       {$skipped}\n";
