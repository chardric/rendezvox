<?php

declare(strict_types=1);

/**
 * GET /api/admin/requests
 *
 * Lists song requests with optional status filter.
 * Runs expiry cleanup before querying.
 */
class ListRequestsHandler
{
    public function handle(): void
    {
        $db = Database::get();

        // Expire stale requests first
        $db->query('SELECT fn_expire_requests()');

        // Read optional status filter from query string
        $statusFilter = $_GET['status'] ?? null;
        $validStatuses = ['pending', 'approved', 'played', 'rejected', 'expired'];

        if ($statusFilter && !in_array($statusFilter, $validStatuses, true)) {
            Response::error('Invalid status filter. Valid values: ' . implode(', ', $validStatuses), 422);
        }

        if ($statusFilter) {
            $stmt = $db->prepare('
                SELECT
                    sr.id,
                    sr.song_id,
                    s.title,
                    a.name       AS artist,
                    sr.listener_ip,
                    sr.listener_name,
                    sr.message,
                    sr.status,
                    sr.created_at,
                    sr.expires_at
                FROM song_requests sr
                JOIN songs   s ON s.id = sr.song_id
                JOIN artists a ON a.id = s.artist_id
                WHERE sr.status = :status
                ORDER BY sr.created_at DESC
                LIMIT 50
            ');
            $stmt->execute(['status' => $statusFilter]);
        } else {
            // Default: show pending + approved
            $stmt = $db->query('
                SELECT
                    sr.id,
                    sr.song_id,
                    s.title,
                    a.name       AS artist,
                    sr.listener_ip,
                    sr.listener_name,
                    sr.message,
                    sr.status,
                    sr.created_at,
                    sr.expires_at
                FROM song_requests sr
                JOIN songs   s ON s.id = sr.song_id
                JOIN artists a ON a.id = s.artist_id
                WHERE sr.status IN (\'pending\', \'approved\')
                ORDER BY sr.created_at DESC
                LIMIT 50
            ');
        }

        $requests = $stmt->fetchAll();

        Response::json([
            'requests' => $requests,
            'count'    => count($requests),
        ]);
    }
}
