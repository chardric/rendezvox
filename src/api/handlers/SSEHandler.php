<?php

declare(strict_types=1);

/**
 * GET /api/sse/now-playing
 *
 * Server-Sent Events endpoint for real-time now-playing updates.
 * Watches a lightweight file snapshot (written by TrackStartedHandler)
 * instead of polling the database. Sends an event only when the
 * track actually changes.
 *
 * The connection auto-closes after 60 seconds; EventSource reconnects
 * automatically so PHP-FPM workers are recycled.
 */
class SSEHandler
{
    private const SNAPSHOT   = '/tmp/rendezvox_now.json';
    private const MAX_TIME   = 60;   // seconds before closing (client reconnects)
    private const CHECK_MS   = 1500; // poll file every 1.5 s
    private const HEARTBEAT  = 15;   // seconds between keep-alive comments

    public function handle(): void
    {
        // SSE headers
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');  // tell nginx to disable buffering

        // Prevent PHP from buffering
        if (function_exists('apache_setenv')) {
            apache_setenv('no-gzip', '1');
        }
        @ini_set('zlib.output_compression', '0');
        @ini_set('output_buffering', '0');
        while (ob_get_level()) ob_end_flush();

        // Free session lock so other requests aren't blocked
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        ignore_user_abort(false);

        $startTime     = time();
        $lastMtime     = 0;
        $lastHeartbeat = time();
        $lastData      = '';

        // Send initial data immediately if snapshot exists
        if (file_exists(self::SNAPSHOT)) {
            $lastMtime = filemtime(self::SNAPSHOT) ?: 0;
            $lastData  = @file_get_contents(self::SNAPSHOT) ?: '';
            if ($lastData !== '') {
                $this->sendEvent('now-playing', $lastData);
            }
        }

        // Event loop
        while (true) {
            // Check if client disconnected
            if (connection_aborted()) break;

            // Check max lifetime
            if ((time() - $startTime) >= self::MAX_TIME) {
                $this->sendEvent('reconnect', json_encode(['reason' => 'timeout']));
                break;
            }

            // Check for snapshot changes
            clearstatcache(true, self::SNAPSHOT);
            $currentMtime = file_exists(self::SNAPSHOT) ? (filemtime(self::SNAPSHOT) ?: 0) : 0;

            if ($currentMtime > $lastMtime) {
                $data = @file_get_contents(self::SNAPSHOT) ?: '';
                if ($data !== '' && $data !== $lastData) {
                    $this->sendEvent('now-playing', $data);
                    $lastData = $data;
                }
                $lastMtime = $currentMtime;
            }

            // Heartbeat to keep connection alive
            if ((time() - $lastHeartbeat) >= self::HEARTBEAT) {
                echo ": heartbeat\n\n";
                $this->flush();
                $lastHeartbeat = time();
            }

            // Sleep between checks (in milliseconds)
            usleep(self::CHECK_MS * 1000);
        }
    }

    private function sendEvent(string $event, string $data): void
    {
        echo "event: {$event}\n";
        // SSE data lines must not contain bare newlines
        foreach (explode("\n", $data) as $line) {
            echo "data: {$line}\n";
        }
        echo "\n";
        $this->flush();
    }

    private function flush(): void
    {
        @ob_flush();
        flush();
    }
}
