<?php

declare(strict_types=1);

class HealthHandler
{
    public function handle(): void
    {
        $dbStatus = 'ok';

        try {
            $db = Database::get();
            $db->query('SELECT 1');
        } catch (Throwable $e) {
            error_log('Health check DB error: ' . $e->getMessage());
            $dbStatus = 'error';
        }

        $status = ($dbStatus === 'ok') ? 200 : 503;

        Response::json([
            'status'    => $dbStatus === 'ok' ? 'ok' : 'degraded',
            'service'   => 'iradio-api',
            'database'  => $dbStatus,
            'timestamp' => gmdate('c'),
        ], $status);
    }
}
