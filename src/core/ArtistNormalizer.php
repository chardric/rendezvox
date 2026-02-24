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

    private static function artistExists(\PDO $db, string $name): bool
    {
        $normalized = mb_strtolower(trim($name));
        $stmt = $db->prepare('SELECT 1 FROM artists WHERE normalized_name = :norm LIMIT 1');
        $stmt->execute(['norm' => $normalized]);
        return (bool) $stmt->fetchColumn();
    }
}
