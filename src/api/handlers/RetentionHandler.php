<?php

declare(strict_types=1);

/**
 * GET /api/admin/retention — get retention heatmap data
 */
class RetentionHandler
{
    public function handle(): void
    {
        Auth::requireAuth();

        require_once __DIR__ . '/../../core/RetentionScorer.php';

        $db    = Database::get();
        $sort  = $_GET['sort'] ?? 'worst';
        $limit = min(200, max(10, (int) ($_GET['limit'] ?? 100)));

        $data = RetentionScorer::getHeatmapData($db, $limit, $sort);

        Response::json([
            'songs' => $data,
            'count' => count($data),
        ]);
    }
}
