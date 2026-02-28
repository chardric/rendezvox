<?php

declare(strict_types=1);

/**
 * RendezVox — Music Directory Restructure Migration
 *
 * One-time script to physically move directories from the old layout to the new:
 *
 *   OLD                         NEW
 *   music/upload/               music/untagged/files/     (upload staging)
 *   music/imports/              music/tagged/folders/     (folder uploads)
 *   music/tagged/Genre/...      music/tagged/files/Genre/... (individual files)
 *   music/_untagged/            music/untagged/files/     (missing metadata)
 *   music/_duplicates/          (removed — duplicates now tagged in DB)
 *
 * Usage:
 *   php migrate_music_dirs.php [--dry-run]
 *
 * IMPORTANT: Run the SQL migration (012_music_dir_restructure.sql) FIRST,
 * then run this script to move the physical files.
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

// Step 1: Signal the media-organizer to stop (it will restart on next cron tick)
echo "Step 1: Stopping media-organizer...\n";
if (!$dryRun) {
    file_put_contents($stopFile, '1');
    sleep(2); // give it time to notice
}

// Step 2: Create new directory structure
echo "Step 2: Creating new directory structure...\n";
$newDirs = [
    $musicDir . '/untagged/files',
    $musicDir . '/untagged/folders',
    $musicDir . '/tagged/files',
    $musicDir . '/tagged/folders',
];

foreach ($newDirs as $dir) {
    if (!is_dir($dir)) {
        if ($dryRun) {
            echo "  Would create: {$dir}\n";
        } else {
            mkdir($dir, 0775, true);
            @chmod($dir, 0775);
            @chown($dir, 'www-data');
            @chgrp($dir, 'www-data');
            echo "  Created: {$dir}\n";
        }
    } else {
        echo "  Exists: {$dir}\n";
    }
}

// Step 3: Move tagged/* → tagged/files/* (contents, not dir itself)
echo "\nStep 3: Moving tagged/* → tagged/files/*...\n";
$taggedDir = $musicDir . '/tagged';
$taggedFilesDir = $musicDir . '/tagged/files';
$movedTagged = 0;

if (is_dir($taggedDir)) {
    foreach (scandir($taggedDir) ?: [] as $item) {
        if ($item === '.' || $item === '..' || $item === 'files' || $item === 'folders') continue;
        $src = $taggedDir . '/' . $item;
        $dst = $taggedFilesDir . '/' . $item;
        if (file_exists($dst)) {
            echo "  SKIP (exists): {$item}\n";
            continue;
        }
        if ($dryRun) {
            echo "  Would move: tagged/{$item} → tagged/files/{$item}\n";
        } else {
            if (rename($src, $dst)) {
                echo "  Moved: tagged/{$item} → tagged/files/{$item}\n";
                $movedTagged++;
            } else {
                echo "  ERROR: Failed to move tagged/{$item}\n";
            }
        }
    }
}
echo "  Moved {$movedTagged} item(s)\n";

// Step 4: Move _untagged/* → untagged/files/*
echo "\nStep 4: Moving _untagged/* → untagged/files/*...\n";
$oldUntaggedDir = $musicDir . '/_untagged';
$newUntaggedFilesDir = $musicDir . '/untagged/files';
$movedUntagged = 0;

if (is_dir($oldUntaggedDir)) {
    foreach (scandir($oldUntaggedDir) ?: [] as $item) {
        if ($item === '.' || $item === '..') continue;
        $src = $oldUntaggedDir . '/' . $item;
        $dst = $newUntaggedFilesDir . '/' . $item;
        if (file_exists($dst)) {
            echo "  SKIP (exists): {$item}\n";
            continue;
        }
        if ($dryRun) {
            echo "  Would move: _untagged/{$item} → untagged/files/{$item}\n";
        } else {
            if (rename($src, $dst)) {
                echo "  Moved: _untagged/{$item} → untagged/files/{$item}\n";
                $movedUntagged++;
            } else {
                echo "  ERROR: Failed to move _untagged/{$item}\n";
            }
        }
    }
}
echo "  Moved {$movedUntagged} item(s)\n";

// Step 5: Move upload/* → untagged/files/*
echo "\nStep 5: Moving upload/* → untagged/files/*...\n";
$uploadDir = $musicDir . '/upload';
$movedUpload = 0;

if (is_dir($uploadDir)) {
    foreach (scandir($uploadDir) ?: [] as $item) {
        if ($item === '.' || $item === '..' || str_starts_with($item, '.')) continue;
        $src = $uploadDir . '/' . $item;
        $dst = $newUntaggedFilesDir . '/' . $item;
        if (file_exists($dst)) {
            echo "  SKIP (exists): {$item}\n";
            continue;
        }
        if ($dryRun) {
            echo "  Would move: upload/{$item} → untagged/files/{$item}\n";
        } else {
            if (rename($src, $dst)) {
                echo "  Moved: upload/{$item} → untagged/files/{$item}\n";
                $movedUpload++;
            } else {
                echo "  ERROR: Failed to move upload/{$item}\n";
            }
        }
    }
}
echo "  Moved {$movedUpload} item(s)\n";

// Step 6: Move imports/* → tagged/folders/*
echo "\nStep 6: Moving imports/* → tagged/folders/*...\n";
$importsDir = $musicDir . '/imports';
$taggedFoldersDir = $musicDir . '/tagged/folders';
$movedImports = 0;

if (is_dir($importsDir)) {
    foreach (scandir($importsDir) ?: [] as $item) {
        if ($item === '.' || $item === '..') continue;
        $src = $importsDir . '/' . $item;
        $dst = $taggedFoldersDir . '/' . $item;
        if (file_exists($dst)) {
            echo "  SKIP (exists): {$item}\n";
            continue;
        }
        if ($dryRun) {
            echo "  Would move: imports/{$item} → tagged/folders/{$item}\n";
        } else {
            if (rename($src, $dst)) {
                echo "  Moved: imports/{$item} → tagged/folders/{$item}\n";
                $movedImports++;
            } else {
                echo "  ERROR: Failed to move imports/{$item}\n";
            }
        }
    }
}
echo "  Moved {$movedImports} item(s)\n";

// Step 7: Remove empty old directories
echo "\nStep 7: Removing empty old directories...\n";
$oldDirs = [$oldUntaggedDir, $musicDir . '/_duplicates', $uploadDir, $importsDir];

foreach ($oldDirs as $dir) {
    if (!is_dir($dir)) {
        echo "  Not found (already removed): {$dir}\n";
        continue;
    }
    // Only remove if empty
    $contents = array_diff(scandir($dir) ?: [], ['.', '..']);
    if (empty($contents)) {
        if ($dryRun) {
            echo "  Would remove: {$dir}\n";
        } else {
            if (rmdir($dir)) {
                echo "  Removed: {$dir}\n";
            } else {
                echo "  ERROR: Failed to remove {$dir}\n";
            }
        }
    } else {
        echo "  SKIP (not empty): {$dir} (" . count($contents) . " items remaining)\n";
    }
}

echo "\n=== Migration complete ===\n";
echo "Tagged:   {$movedTagged}\n";
echo "Untagged: {$movedUntagged}\n";
echo "Upload:   {$movedUpload}\n";
echo "Imports:  {$movedImports}\n";
