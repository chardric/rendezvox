<?php
/**
 * AI Song Classifier — Uses Gemini to categorize songs.
 *
 * Modes:
 *   php classify_songs.php               — Classify uncategorized songs into proper categories
 *   php classify_songs.php --country     — Detect artist country of origin (sets country_code + auto-OPM)
 *   php classify_songs.php --opm         — Alias for --country (legacy)
 *   php classify_songs.php --category=Rock — Move songs that belong to "Rock" into that category
 *   php classify_songs.php --all         — Reclassify ALL songs (not just uncategorized)
 *   php classify_songs.php --dry-run     — Show what would change without updating DB
 *
 * Requires: gemini_api_key setting in DB.
 */

declare(strict_types=1);

// Bootstrap
require_once __DIR__ . '/../core/Database.php';

// Parse CLI args
$args = getopt('', ['opm', 'country', 'all', 'dry-run', 'category:', 'limit:', 'help']);
if (isset($args['help'])) {
    echo <<<HELP
AI Song Classifier

Usage:
  php classify_songs.php               Classify uncategorized songs
  php classify_songs.php --country     Detect artist country → set country_code + auto-OPM
  php classify_songs.php --opm         Alias for --country (legacy)
  php classify_songs.php --category=X  Find songs belonging to category X
  php classify_songs.php --all         Reclassify ALL songs (not just uncategorized)
  php classify_songs.php --dry-run     Preview without updating
  php classify_songs.php --limit=100   Process at most N songs

HELP;
    exit(0);
}

$isCountry  = isset($args['country']) || isset($args['opm']);
$isAll      = isset($args['all']);
$isDryRun   = isset($args['dry-run']);
$targetCat  = $args['category'] ?? null;
$limit      = isset($args['limit']) ? (int) $args['limit'] : 0;

$db = Database::get();

// Load Gemini API key
$stmt = $db->prepare("SELECT value FROM settings WHERE key = 'gemini_api_key'");
$stmt->execute();
$apiKey = trim((string) $stmt->fetchColumn());
if (!$apiKey) {
    fwrite(STDERR, "Error: gemini_api_key not configured in Settings.\n");
    exit(1);
}

// Load all active categories
$catStmt = $db->query("SELECT id, name FROM categories WHERE is_active = true ORDER BY name");
$categories = [];
$catByName  = [];
while ($row = $catStmt->fetch(PDO::FETCH_ASSOC)) {
    $categories[] = $row;
    $catByName[strtolower($row['name'])] = (int) $row['id'];
}

if (empty($categories)) {
    fwrite(STDERR, "Error: No active categories found.\n");
    exit(1);
}

// Resolve target category for --country or --category
$targetCatId = null;
$opmCatId    = $catByName['opm'] ?? null;

if ($isCountry) {
    if (!$opmCatId) {
        fwrite(STDERR, "Warning: OPM category not found. Filipino songs won't be auto-categorized.\n");
    }
    echo "Mode: Country detection (artist origin → country_code + auto-OPM)\n\n";
} elseif ($targetCat) {
    $targetCatId = $catByName[strtolower($targetCat)] ?? null;
    if (!$targetCatId) {
        fwrite(STDERR, "Error: Category '{$targetCat}' not found.\n");
        exit(1);
    }
    echo "Mode: Detect songs for category '{$targetCat}' (id={$targetCatId})\n\n";
} else {
    echo "Mode: Auto-classify uncategorized songs (with country detection)\n";
    echo "Available categories: " . implode(', ', array_column($categories, 'name')) . "\n\n";
}

// Fetch songs to classify
$where  = ['s.is_active = true', 's.trashed_at IS NULL', 's.file_path IS NOT NULL'];
$params = [];

if ($isCountry) {
    // For country: check all songs without a country_code
    $where[] = 's.country_code IS NULL';
} elseif ($targetCat) {
    // For --category: check all songs not already in that category
    $where[] = 's.category_id != :exclude_cat';
    $params['exclude_cat'] = $targetCatId;
} elseif (!$isAll) {
    // Default: only uncategorized
    $uncatId = $catByName['uncategorized'] ?? null;
    if ($uncatId) {
        $where[] = 's.category_id = :uncat';
        $params['uncat'] = $uncatId;
    } else {
        echo "Warning: No 'Uncategorized' category found. Processing all songs.\n";
    }
}

$limitSql = $limit > 0 ? "LIMIT {$limit}" : '';
$sql = "SELECT s.id, s.title, s.country_code, a.name AS artist, c.name AS category, s.category_id
        FROM songs s
        LEFT JOIN artists a ON a.id = s.artist_id
        LEFT JOIN categories c ON c.id = s.category_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY s.id
        {$limitSql}";

$songStmt = $db->prepare($sql);
$songStmt->execute($params);
$songs = $songStmt->fetchAll(PDO::FETCH_ASSOC);

$total = count($songs);
echo "Songs to process: {$total}\n";
if ($total === 0) {
    echo "Nothing to do.\n";
    exit(0);
}

// Process in batches
$batchSize  = 20;
$updated    = 0;
$skipped    = 0;
$errors     = 0;
$batches    = array_chunk($songs, $batchSize);

echo "Batches: " . count($batches) . " (batch size: {$batchSize})\n";
if ($isDryRun) echo "[DRY RUN — no changes will be made]\n";
echo str_repeat('─', 60) . "\n";

$updateCatStmt     = $db->prepare("UPDATE songs SET category_id = :cat WHERE id = :id");
$updateCountryStmt = $db->prepare("UPDATE songs SET country_code = :cc WHERE id = :id");
$updateBothStmt    = $db->prepare("UPDATE songs SET country_code = :cc, category_id = :cat WHERE id = :id");

foreach ($batches as $bi => $batch) {
    $batchNum = $bi + 1;

    // Mandatory cooldown between batches to respect free tier (20 req/min)
    if ($bi > 0) {
        echo "  Cooldown 5s...\n";
        sleep(5);
    }

    echo "\nBatch {$batchNum}/" . count($batches) . " (" . count($batch) . " songs)...\n";

    $result = callGemini($apiKey, $batch, $categories, $isCountry, $targetCat);

    if ($result === null) {
        echo "  [ERROR] Gemini request failed, skipping batch\n";
        $errors += count($batch);
        continue;
    }

    if (isset($result['_error'])) {
        if ($result['_error'] === 'rate_limited') {
            $wait = $result['_retry_delay'] ?? 15;
            echo "  [RATE LIMITED] Waiting {$wait}s...\n";
            sleep($wait);
            $result = callGemini($apiKey, $batch, $categories, $isCountry, $targetCat);
            if ($result === null || isset($result['_error'])) {
                $wait2 = ($result['_retry_delay'] ?? 60) + 5;
                echo "  [RATE LIMITED] Waiting {$wait2}s...\n";
                sleep($wait2);
                $result = callGemini($apiKey, $batch, $categories, $isCountry, $targetCat);
                if ($result === null || isset($result['_error'])) {
                    echo "  [ERROR] Still rate limited, skipping batch\n";
                    $errors += count($batch);
                    continue;
                }
            }
        } else {
            echo "  [ERROR] {$result['_error']}\n";
            $errors += count($batch);
            continue;
        }
    }

    foreach ($batch as $i => $song) {
        $classification = $result[$i] ?? null;
        if (!$classification) {
            $skipped++;
            continue;
        }

        if ($isCountry) {
            // Country mode: extract country_code
            $cc = !empty($classification['country_code']) ? strtoupper(trim($classification['country_code'])) : null;
            if (!$cc || !preg_match('/^[A-Z]{2}$/', $cc)) {
                $skipped++;
                continue;
            }

            $flag = countryFlag($cc);
            echo "  [{$song['id']}] \"{$song['title']}\" by {$song['artist']} → {$flag} {$cc}";

            // Auto-assign OPM category for Filipino artists
            if ($cc === 'PH' && $opmCatId && (int) $song['category_id'] !== $opmCatId) {
                echo " → OPM";
                if (!$isDryRun) {
                    $updateBothStmt->execute(['cc' => $cc, 'cat' => $opmCatId, 'id' => $song['id']]);
                }
            } else {
                if (!$isDryRun) {
                    $updateCountryStmt->execute(['cc' => $cc, 'id' => $song['id']]);
                }
            }
            echo "\n";
            $updated++;
        } elseif ($targetCat) {
            // Category mode
            if (empty($classification['belongs'])) {
                $skipped++;
                continue;
            }
            $newCatId = $targetCatId;
            if ($newCatId === (int) $song['category_id']) {
                $skipped++;
                continue;
            }
            echo "  [{$song['id']}] \"{$song['title']}\" by {$song['artist']} → {$targetCat}";
            if ($song['category']) echo " (was: {$song['category']})";
            echo "\n";
            if (!$isDryRun) {
                $updateCatStmt->execute(['cat' => $newCatId, 'id' => $song['id']]);
            }
            $updated++;
        } else {
            // Auto-classify mode: category + optional country_code
            $newCatName = strtolower(trim($classification['category'] ?? ''));
            $cc = !empty($classification['country_code']) ? strtoupper(trim($classification['country_code'])) : null;
            if ($cc && !preg_match('/^[A-Z]{2}$/', $cc)) $cc = null;

            $catChanged = false;
            $newCatId   = null;
            if ($newCatName && isset($catByName[$newCatName])) {
                $newCatId = $catByName[$newCatName];
                if ($newCatId !== (int) $song['category_id']) {
                    $catChanged = true;
                }
            }

            // Auto-OPM for Filipino artists
            if ($cc === 'PH' && $opmCatId && (int) $song['category_id'] !== $opmCatId) {
                $newCatId = $opmCatId;
                $catChanged = true;
            }

            if (!$catChanged && !$cc) {
                $skipped++;
                continue;
            }

            $label = $catChanged ? ucfirst($newCatName ?: 'opm') : $song['category'];
            $flag  = $cc ? countryFlag($cc) . ' ' : '';
            echo "  [{$song['id']}] \"{$song['title']}\" by {$song['artist']} → {$flag}{$label}";
            if ($catChanged && $song['category']) echo " (was: {$song['category']})";
            echo "\n";

            if (!$isDryRun) {
                if ($catChanged && $cc) {
                    $updateBothStmt->execute(['cc' => $cc, 'cat' => $newCatId, 'id' => $song['id']]);
                } elseif ($catChanged) {
                    $updateCatStmt->execute(['cat' => $newCatId, 'id' => $song['id']]);
                } elseif ($cc) {
                    $updateCountryStmt->execute(['cc' => $cc, 'id' => $song['id']]);
                }
            }
            $updated++;
        }
    }
}

echo "\n" . str_repeat('─', 60) . "\n";
echo "Done. Updated: {$updated}, Skipped: {$skipped}, Errors: {$errors}\n";
if ($isDryRun) echo "[DRY RUN — no changes were made]\n";


/**
 * Convert ISO 3166-1 alpha-2 to flag emoji for CLI output.
 */
function countryFlag(string $code): string
{
    $code = strtoupper($code);
    $a = mb_chr(0x1F1E6 + ord($code[0]) - 65);
    $b = mb_chr(0x1F1E6 + ord($code[1]) - 65);
    return $a . $b;
}

/**
 * Call Gemini to classify a batch of songs.
 */
function callGemini(string $apiKey, array $batch, array $categories, bool $isCountry, ?string $targetCat): ?array
{
    // Build song list
    $lines = [];
    foreach ($batch as $i => $s) {
        $n = $i + 1;
        $artist = $s['artist'] ?: 'Unknown';
        $lines[] = "{$n}. \"{$s['title']}\" by {$artist}";
    }
    $songList = implode("\n", $lines);

    if ($isCountry) {
        $prompt = "You are a music expert with deep knowledge of artists worldwide. "
            . "Determine the country of origin for each artist.\n\n"
            . "Songs:\n{$songList}\n\n"
            . "Return a JSON array where each element corresponds to a song (same order). "
            . "Each element should be: {\"country_code\": \"XX\"} with the ISO 3166-1 alpha-2 code "
            . "of the artist's country of origin (e.g. US, GB, PH, KR, JP, AU, CA, DE, FR, BR, NZ, SE, NG), "
            . "or {} if unknown.\n\n"
            . "Rules:\n"
            . "- Use standard ISO 3166-1 alpha-2 country codes (2 uppercase letters)\n"
            . "- Filipino artists (OPM) should be PH\n"
            . "- Only return a country if you are CONFIDENT about the artist's origin\n"
            . "- When uncertain, return {} (do not guess)\n"
            . "- Return valid JSON array only, no markdown or explanation";
    } elseif ($targetCat) {
        $prompt = "You are a music classification expert. "
            . "Determine which of these songs belong to the \"{$targetCat}\" category.\n\n"
            . "Songs:\n{$songList}\n\n"
            . "Return a JSON array where each element corresponds to a song (same order). "
            . "Each element should be: {\"belongs\": true} if the song belongs to \"{$targetCat}\", "
            . "or {} if it does not or you are uncertain.\n\n"
            . "Rules:\n"
            . "- Only mark songs you are CONFIDENT belong to \"{$targetCat}\"\n"
            . "- When uncertain, return {}\n"
            . "- Return valid JSON array only, no markdown or explanation";
    } else {
        // Auto-classify into best category + detect country
        $catNames = array_column($categories, 'name');
        $catList  = implode(', ', $catNames);

        $prompt = "You are a music classification expert. "
            . "Classify each song into the most appropriate category AND determine the artist's country of origin.\n\n"
            . "Available categories: {$catList}\n\n"
            . "Songs:\n{$songList}\n\n"
            . "Return a JSON array where each element corresponds to a song (same order). "
            . "Each element should be: {\"category\": \"CategoryName\", \"country_code\": \"XX\"}.\n"
            . "If category is uncertain, use \"Uncategorized\". If country is uncertain, omit country_code.\n\n"
            . "Rules:\n"
            . "- Use ONLY category names from the list above (exact spelling)\n"
            . "- OPM = Original Pilipino Music (songs by Filipino artists, country_code: PH)\n"
            . "- country_code must be ISO 3166-1 alpha-2 (2 uppercase letters, e.g. US, GB, PH, KR)\n"
            . "- Choose the single most fitting category\n"
            . "- Return valid JSON array only, no markdown or explanation";
    }

    usleep(200000); // 0.2s rate limit

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key='
         . urlencode($apiKey);

    $payload = json_encode([
        'contents' => [
            ['parts' => [['text' => $prompt]]]
        ],
        'generationConfig' => [
            'responseMimeType' => 'application/json',
            'temperature'      => 0.1,
            'maxOutputTokens'  => 2048,
            'thinkingConfig'   => ['thinkingBudget' => 0],
        ],
    ]);

    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\nUser-Agent: RendezVox/1.0\r\n",
            'content'       => $payload,
            'timeout'       => 30,
            'ignore_errors' => true,
        ],
    ]);

    $resp = @file_get_contents($url, false, $ctx);
    if (!$resp) return null;

    $data = json_decode($resp, true);
    if (!$data) return null;

    if (isset($data['error'])) {
        $code = (int) ($data['error']['code'] ?? 0);
        $msg  = $data['error']['message'] ?? 'unknown';
        fwrite(STDERR, "  Gemini API error [{$code}]: {$msg}\n");
        if ($code === 429) {
            // Parse retry delay from error message
            $retryDelay = 60;
            if (preg_match('/retry in ([\d.]+)s/i', $msg, $m)) {
                $retryDelay = (int) ceil((float) $m[1]) + 2;
            }
            return ['_error' => 'rate_limited', '_retry_delay' => $retryDelay];
        }
        return null;
    }

    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
    if (!$text) return null;

    $parsed = json_decode($text, true);
    if (!is_array($parsed) || count($parsed) !== count($batch)) {
        fwrite(STDERR, "Gemini returned unexpected format (got " . (is_array($parsed) ? count($parsed) : 'non-array') . ", expected " . count($batch) . ")\n");
        return null;
    }

    return $parsed;
}
