<?php

declare(strict_types=1);

class StatsListenersHandler
{
    public function handle(): void
    {
        $db    = Database::get();
        $range = $_GET['range'] ?? '24h';

        $interval = match ($range) {
            '1h'  => '1 hour',
            '6h'  => '6 hours',
            '24h' => '24 hours',
            '7d'  => '7 days',
            '30d' => '30 days',
            default => '24 hours',
        };

        $stmt = $db->prepare("
            SELECT listener_count, peak_listeners, recorded_at
            FROM listener_stats
            WHERE recorded_at >= NOW() - INTERVAL '{$interval}'
            ORDER BY recorded_at ASC
        ");
        $stmt->execute();

        $points = [];
        while ($row = $stmt->fetch()) {
            $points[] = [
                'count'     => (int) $row['listener_count'],
                'peak'      => (int) $row['peak_listeners'],
                'timestamp' => $row['recorded_at'],
            ];
        }

        Response::json(['range' => $range, 'points' => $points]);
    }
}
