<?php

declare(strict_types=1);

/**
 * POST /api/admin/stream-control
 *
 * Starts or stops the Icecast output via Liquidsoap telnet.
 * Body: { "action": "start" | "stop" }
 */
class StreamControlHandler
{
    private const LIQ_HOST    = 'rendezvox-liquidsoap';
    private const LIQ_PORT    = 1234;
    private const LIQ_TIMEOUT = 3;

    public function handle(): void
    {
        Auth::requireAuth();

        $input  = json_decode(file_get_contents('php://input'), true) ?? [];
        $action = $input['action'] ?? '';

        if (!in_array($action, ['start', 'stop'], true)) {
            Response::error('Invalid action. Use "start" or "stop".', 400);
            return;
        }

        $sock = @fsockopen(self::LIQ_HOST, self::LIQ_PORT, $errno, $errstr, self::LIQ_TIMEOUT);
        if (!$sock) {
            Response::error('Stream engine unavailable: ' . $errstr, 503);
            return;
        }

        stream_set_timeout($sock, self::LIQ_TIMEOUT);

        // Flush any initial banner
        usleep(100000);
        while (($info = stream_get_meta_data($sock)) && !$info['eof']) {
            $peek = @fread($sock, 1024);
            if ($peek === false || $peek === '') break;
        }

        $value = $action === 'start' ? 'true' : 'false';
        fwrite($sock, "var.set stream_active = {$value}\r\n");
        usleep(200000);
        fread($sock, 1024);

        fwrite($sock, "quit\r\n");
        fclose($sock);

        // Persist state in settings
        $db      = Database::get();
        $enabled = $action === 'start' ? 'true' : 'false';

        $db->prepare("
            INSERT INTO settings (key, value, type) VALUES ('stream_enabled', :val, 'boolean')
            ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value
        ")->execute(['val' => $enabled]);

        Response::json([
            'message'       => $action === 'stop' ? 'Stream stopped' : 'Stream started',
            'stream_active' => $action === 'start',
        ]);
    }
}
