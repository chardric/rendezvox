<?php

declare(strict_types=1);

/**
 * iRadio — Schedule Boundary Watcher
 *
 * Long-running CLI process that checks every 30 seconds whether the active
 * schedule has changed and sends `stream.skip` to Liquidsoap if needed.
 *
 * Catches schedule boundary transitions (e.g. 07:00 block ends, 07:00 block
 * starts) with at most ~30s latency instead of waiting for a full song to finish.
 *
 * Usage:
 *   php schedule-watcher.php
 *
 * Run inside the PHP container:
 *   docker exec -d iradio-php php /var/www/html/src/cli/schedule-watcher.php
 */

// -- Bootstrap (outside web context) --
require __DIR__ . '/../core/Database.php';

const LIQ_HOST    = 'iradio-liquidsoap';
const LIQ_PORT    = 1234;
const LIQ_TIMEOUT = 3;
const CHECK_INTERVAL = 30; // seconds

function log_msg(string $msg): void
{
    $ts = date('Y-m-d H:i:s');
    echo "[{$ts}] {$msg}\n";
}

function getSetting(PDO $db, string $key, string $default): string
{
    $stmt = $db->prepare('SELECT value FROM settings WHERE key = :key');
    $stmt->execute(['key' => $key]);
    $val = $stmt->fetchColumn();
    return $val !== false ? (string) $val : $default;
}

function resolveSchedule(PDO $db): ?array
{
    $tz = getSetting($db, 'station_timezone', 'UTC');

    $stmt = $db->prepare("
        SELECT s.id AS schedule_id, s.playlist_id
        FROM schedules s
        JOIN playlists p ON p.id = s.playlist_id
        WHERE s.is_active = true
          AND p.is_active = true
          AND ((EXTRACT(ISODOW FROM NOW() AT TIME ZONE :tz)::int - 1) = ANY(s.days_of_week)
               OR s.days_of_week IS NULL)
          AND (s.start_date IS NULL OR s.start_date <= (NOW() AT TIME ZONE :tz4)::date)
          AND (s.end_date   IS NULL OR s.end_date   >= (NOW() AT TIME ZONE :tz5)::date)
          AND s.start_time <= (NOW() AT TIME ZONE :tz2)::time
          AND s.end_time   >  (NOW() AT TIME ZONE :tz3)::time
        ORDER BY s.priority DESC
        LIMIT 1
    ");
    $stmt->execute(['tz' => $tz, 'tz2' => $tz, 'tz3' => $tz, 'tz4' => $tz, 'tz5' => $tz]);
    return $stmt->fetch() ?: null;
}

function sendSkip(): bool
{
    $sock = @fsockopen(LIQ_HOST, LIQ_PORT, $errno, $errstr, LIQ_TIMEOUT);
    if (!$sock) {
        log_msg("WARNING: Cannot connect to Liquidsoap ({$errstr})");
        return false;
    }

    stream_set_timeout($sock, LIQ_TIMEOUT);

    // Drain the welcome banner
    usleep(100000);
    while (($info = stream_get_meta_data($sock)) && !$info['eof']) {
        $peek = @fread($sock, 1024);
        if ($peek === false || $peek === '') break;
    }

    fwrite($sock, "stream.skip\r\n");
    usleep(200000);
    fread($sock, 1024);
    fwrite($sock, "quit\r\n");
    fclose($sock);

    return true;
}

// -- Main loop --
log_msg('Schedule watcher started (checking every ' . CHECK_INTERVAL . 's)');

$lastPlaylistId = null;
$lastIsPlaying  = null;
$firstRun       = true;

while (true) {
    try {
        $db = Database::get();

        // What SHOULD be playing?
        $schedule   = resolveSchedule($db);
        $shouldPlay = $schedule ? (int) $schedule['playlist_id'] : null;

        // What IS currently playing?
        Database::ensureRotationState();
        $state     = $db->query('SELECT current_playlist_id, is_playing FROM rotation_state WHERE id = 1')->fetch();
        $isPlaying = (bool) ($state['is_playing'] ?? false);
        $currentPl = $state['current_playlist_id'] ? (int) $state['current_playlist_id'] : null;

        $needsSkip = false;
        $reason    = '';

        if ($shouldPlay !== null && !$isPlaying) {
            $needsSkip = true;
            $reason    = "idle→active (playlist #{$shouldPlay})";
        } elseif ($shouldPlay === null && $isPlaying) {
            $needsSkip = true;
            $reason    = 'active→idle (no schedule)';
        } elseif ($shouldPlay !== null && $shouldPlay !== $currentPl) {
            $needsSkip = true;
            $reason    = "playlist change (#{$currentPl}→#{$shouldPlay})";
        }

        // On first run, just record state without skipping
        if ($firstRun) {
            $firstRun = false;
            if ($needsSkip) {
                log_msg("Initial state: {$reason} — sending skip");
                if (sendSkip()) {
                    log_msg('Skip sent successfully');
                }
            } else {
                log_msg('Initial state: in sync (playlist #' . ($currentPl ?? 'none') . ', playing=' . ($isPlaying ? 'yes' : 'no') . ')');
            }
        } elseif ($needsSkip) {
            log_msg("Schedule boundary: {$reason} — sending skip");
            if (sendSkip()) {
                log_msg('Skip sent successfully');
            }
        }

        $lastPlaylistId = $shouldPlay;
        $lastIsPlaying  = $isPlaying;

    } catch (\Throwable $e) {
        log_msg('ERROR: ' . $e->getMessage());
    }

    sleep(CHECK_INTERVAL);
}
