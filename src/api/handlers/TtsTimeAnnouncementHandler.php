<?php

declare(strict_types=1);

/**
 * Internal endpoint for Liquidsoap to generate a time announcement TTS.
 * Called during hourly station ID playback so the time is announced
 * right after the station ID.
 *
 * Returns JSON with file_path to the generated MP3, or null if disabled.
 */
class TtsTimeAnnouncementHandler
{
    public function handle(): void
    {
        $db = Database::get();

        $enabled = $this->getSetting($db, 'tts_time_enabled', 'false');
        if ($enabled !== 'true') {
            Response::json(['file_path' => null]);
            return;
        }

        require_once __DIR__ . '/../../core/TtsEngine.php';

        $tz       = $this->getSetting($db, 'station_timezone', 'UTC');
        $voice    = $this->getSetting($db, 'tts_voice', 'male');
        $speed    = (int) $this->getSetting($db, 'tts_speed', '160');
        $template = $this->getSetting($db, 'tts_time_template', 'The time is {time}');

        $dt      = new \DateTime('now', new \DateTimeZone($tz));
        $timeStr = $this->spokenTime($dt);
        $text    = str_replace('{time}', $timeStr, $template);

        // No silence padding — plays in station ID overlay, not as a song pre-roll
        $path = TtsEngine::generate($text, $voice, $speed, 0.3, 0.3);

        if ($path !== null) {
            $db->prepare('UPDATE rotation_state SET last_time_tts_at = NOW() WHERE id = 1')->execute();
        }

        Response::json(['file_path' => $path]);
    }

    /**
     * Convert a DateTime to natural spoken time.
     * e.g. "4 o'clock in the afternoon", "10 o'clock in the morning"
     */
    private function spokenTime(\DateTime $dt): string
    {
        $hour24 = (int) $dt->format('G');
        $hour12 = (int) $dt->format('g');
        $min    = (int) $dt->format('i');

        if ($hour24 === 0 && $min === 0) {
            return '12 midnight';
        }
        if ($hour24 === 12 && $min === 0) {
            return '12 noon';
        }

        // Time of day
        if ($hour24 >= 1 && $hour24 < 12) {
            $period = 'in the morning';
        } elseif ($hour24 >= 12 && $hour24 < 18) {
            $period = 'in the afternoon';
        } else {
            $period = 'at night';
        }

        if ($min === 0) {
            return $hour12 . " o'clock " . $period;
        }
        if ($min < 10) {
            return $hour12 . ' oh ' . $min . ' ' . $period;
        }
        return $hour12 . ' ' . $min . ' ' . $period;
    }

    private function getSetting(\PDO $db, string $key, string $default): string
    {
        $stmt = $db->prepare('SELECT value FROM settings WHERE key = :key');
        $stmt->execute(['key' => $key]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (string) $val : $default;
    }
}
