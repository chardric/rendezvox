<?php

declare(strict_types=1);

/**
 * POST /api/admin/reject-request
 *
 * Rejects a pending or approved song request.
 * If the request was approved, it is also removed from the playback queue.
 */
class RejectRequestHandler
{
    public function handle(): void
    {
        $input = json_decode(file_get_contents('php://input'), true);

        if (!$input || empty($input['request_id'])) {
            Response::error('Missing required field: request_id', 422);
        }

        $requestId = (int) $input['request_id'];
        $db        = Database::get();

        // Fetch request and verify it's pending or approved
        $stmt = $db->prepare('SELECT id, status FROM song_requests WHERE id = :id');
        $stmt->execute(['id' => $requestId]);
        $request = $stmt->fetch();

        if (!$request) {
            Response::error('Request not found', 404);
        }

        if ($request['status'] !== 'pending' && $request['status'] !== 'approved') {
            Response::error('Request cannot be rejected (current status: ' . $request['status'] . ')', 409);
        }

        // If approved, remove from playback queue first
        if ($request['status'] === 'approved') {
            $db->prepare('DELETE FROM request_queue WHERE request_id = :id')
                ->execute(['id' => $requestId]);
        }

        $db->prepare('UPDATE song_requests SET status = :status WHERE id = :id')
            ->execute(['status' => 'rejected', 'id' => $requestId]);

        Response::json([
            'status'     => 'rejected',
            'request_id' => $requestId,
        ]);
    }
}
