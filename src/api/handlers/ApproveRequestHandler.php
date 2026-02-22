<?php

declare(strict_types=1);

/**
 * POST /api/admin/approve-request
 *
 * Approves a pending song request and adds it to the playback queue.
 */
class ApproveRequestHandler
{
    public function handle(): void
    {
        $input = json_decode(file_get_contents('php://input'), true);

        if (!$input || empty($input['request_id'])) {
            Response::error('Missing required field: request_id', 422);
        }

        $requestId = (int) $input['request_id'];
        $db        = Database::get();

        // Fetch request and verify it's pending
        $stmt = $db->prepare('SELECT id, song_id, status FROM song_requests WHERE id = :id');
        $stmt->execute(['id' => $requestId]);
        $request = $stmt->fetch();

        if (!$request) {
            Response::error('Request not found', 404);
        }

        if ($request['status'] !== 'pending') {
            Response::error('Request is not pending (current status: ' . $request['status'] . ')', 409);
        }

        $db->beginTransaction();
        try {
            // Update status
            $db->prepare('UPDATE song_requests SET status = :status WHERE id = :id')
                ->execute(['status' => 'approved', 'id' => $requestId]);

            // Add to playback queue
            $stmt = $db->prepare('
                INSERT INTO request_queue (request_id, song_id, position)
                VALUES (:request_id, :song_id, COALESCE((SELECT MAX(position) FROM request_queue), 0) + 1)
                RETURNING position
            ');
            $stmt->execute([
                'request_id' => $requestId,
                'song_id'    => $request['song_id'],
            ]);
            $position = (int) $stmt->fetchColumn();

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log('ApproveRequest failed: ' . $e->getMessage());
            Response::error('Failed to approve request', 500);
        }

        Response::json([
            'status'         => 'approved',
            'request_id'     => $requestId,
            'queue_position' => $position,
        ]);
    }
}
