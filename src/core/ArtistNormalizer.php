<?php

declare(strict_types=1);

/**
 * Extracts the primary artist from collaboration tags.
 *
 * Always splits on: feat., ft., featuring, with, ×, x (word boundary)
 * Smart splits on: &, and, , → only if the first part already exists as
 * a standalone artist in the database.
 */
class ArtistNormalizer
{
    /**
     * Return the primary artist name, stripping collaboration suffixes.
     */
    public static function extractPrimary(string $name, \PDO $db): string
    {
        $name = trim($name);
        if ($name === '') return $name;

        // Strip wrapping parentheses: "(Artist feat. Other)" → "Artist feat. Other"
        if (preg_match('/^\((.+)\)$/', $name, $m)) {
            $name = trim($m[1]);
        }

        // Always-split separators (case-insensitive)
        // "x" requires word boundaries so it doesn't match inside names like "Rex"
        // After "feat." / "ft." the trailing space is optional (handles "Feat.Sia")
        $alwaysSplit = '/\s+(?:feat\.?\s*|ft\.?\s*|featuring\s+|with\s+|×\s+)|\s+x\s+/iu';

        if (preg_match($alwaysSplit, $name)) {
            $parts = preg_split($alwaysSplit, $name, 2);
            $name = trim($parts[0]);
        }

        // Smart-split separators: &, and, ,
        // Only split if the first part matches an existing artist
        $smartSplit = '/\s*[&,]\s*|\s+and\s+/iu';

        if (preg_match($smartSplit, $name)) {
            $parts = preg_split($smartSplit, $name, 2);
            $candidate = trim($parts[0]);

            if ($candidate !== '' && self::artistExists($db, $candidate)) {
                $name = $candidate;
            }
            // Otherwise keep the full name (protects duo names)
        }

        return trim($name);
    }

    /**
     * Find a canonical artist that matches the given name via aliases:
     * initials, number words, "The" prefix, common suffixes.
     * Returns ['id' => int, 'name' => string] or null.
     */
    public static function findAlias(\PDO $db, string $name, int $excludeId = 0): ?array
    {
        $lower = mb_strtolower(trim($name));
        if ($lower === '') return null;

        // Load all artists once
        $stmt = $db->query('SELECT id, name, normalized_name FROM artists ORDER BY id');
        $all = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($all as $a) {
            if ((int) $a['id'] === $excludeId) continue;
            $otherLower = mb_strtolower(trim($a['name']));
            if ($otherLower === $lower) continue; // exact match handled elsewhere

            // 1. Initials: "MLTR" ↔ "Michael Learns to Rock"
            $nameInit  = self::initials($lower);
            $otherInit = self::initials($otherLower);
            if (strlen($nameInit) >= 2 && $nameInit === $otherLower) {
                return ['id' => (int) $a['id'], 'name' => $a['name']];
            }
            if (strlen($otherInit) >= 2 && $otherInit === $lower) {
                return ['id' => (int) $a['id'], 'name' => $a['name']];
            }

            // 2. Number words: "Matchbox 20" ↔ "Matchbox Twenty"
            if (self::normalizeNumbers($lower) === self::normalizeNumbers($otherLower)) {
                // Prefer the longer/spelled-out name
                return ['id' => (int) $a['id'], 'name' => $a['name']];
            }

            // 3. "The" prefix: "Beatles" ↔ "The Beatles"
            $stripped      = preg_replace('/^the\s+/i', '', $lower);
            $otherStripped = preg_replace('/^the\s+/i', '', $otherLower);
            if ($stripped !== $lower && $stripped === $otherLower) {
                return ['id' => (int) $a['id'], 'name' => $a['name']];
            }
            if ($otherStripped !== $otherLower && $otherStripped === $lower) {
                return ['id' => (int) $a['id'], 'name' => $a['name']];
            }
        }

        return null;
    }

    private static function initials(string $s): string
    {
        $words = preg_split('/\s+/', preg_replace('/[^a-z0-9 ]/u', '', $s));
        $out = '';
        foreach ($words as $w) {
            if ($w !== '') $out .= $w[0];
        }
        return $out;
    }

    private static $numWords = [
        'zero'=>'0','one'=>'1','two'=>'2','three'=>'3','four'=>'4','five'=>'5',
        'six'=>'6','seven'=>'7','eight'=>'8','nine'=>'9','ten'=>'10',
        'eleven'=>'11','twelve'=>'12','thirteen'=>'13','fourteen'=>'14','fifteen'=>'15',
        'sixteen'=>'16','seventeen'=>'17','eighteen'=>'18','nineteen'=>'19',
        'twenty'=>'20','thirty'=>'30','forty'=>'40','fifty'=>'50',
    ];

    private static function normalizeNumbers(string $s): string
    {
        // Replace number words with digits for comparison
        foreach (self::$numWords as $word => $digit) {
            $s = preg_replace('/\b' . $word . '\b/i', $digit, $s);
        }
        return $s;
    }

    /**
     * Find or create an artist, checking aliases before creating.
     * Centralizes all artist creation to prevent duplicates.
     */
    public static function findOrCreate(\PDO $db, string $name): int
    {
        $name = self::extractPrimary($name, $db);
        $normalized = mb_strtolower(trim($name));

        if ($normalized === '') {
            // Fallback: return or create "Unknown Artist"
            $normalized = 'unknown artist';
            $name = 'Unknown Artist';
        }

        // 1. Exact normalized match
        $stmt = $db->prepare('SELECT id FROM artists WHERE normalized_name = :norm');
        $stmt->execute(['norm' => $normalized]);
        $row = $stmt->fetch();
        if ($row) {
            return (int) $row['id'];
        }

        // 2. Alias match (initials, number words, "The" prefix)
        $alias = self::findAlias($db, $name);
        if ($alias) {
            return $alias['id'];
        }

        // 3. No match — create new
        $stmt = $db->prepare('INSERT INTO artists (name, normalized_name) VALUES (:name, :norm) RETURNING id');
        $stmt->execute(['name' => trim($name), 'norm' => $normalized]);
        return (int) $stmt->fetchColumn();
    }

    private static function artistExists(\PDO $db, string $name): bool
    {
        $normalized = mb_strtolower(trim($name));
        $stmt = $db->prepare('SELECT 1 FROM artists WHERE normalized_name = :norm LIMIT 1');
        $stmt->execute(['norm' => $normalized]);
        return (bool) $stmt->fetchColumn();
    }
}
