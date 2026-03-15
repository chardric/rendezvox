<?php

declare(strict_types=1);

/**
 * POST /api/vote/cast — cast a vote in the active poll (public, IP-limited)
 *
 * Body: { "poll_id": int, "song_id": int }
 */
class VoteCastHandler
{
    public function handle(): void
    {
        $db = Database::get();

        // Check if voting is enabled
        $stmt = $db->prepare("SELECT value FROM settings WHERE key = 'voting_enabled'");
        $stmt->execute();
        $row = $stmt->fetch();
        if (!$row || $row['value'] !== 'true') {
            Response::error('Voting is not enabled', 403);
            return;
        }

        $input  = json_decode(file_get_contents('php://input'), true);
        $pollId = (int) ($input['poll_id'] ?? 0);
        $songId = (int) ($input['song_id'] ?? 0);
        $ip     = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        if ($pollId <= 0 || $songId <= 0) {
            Response::error('Missing poll_id or song_id', 422);
            return;
        }

        // Verify poll is active and song is a candidate
        $pollStmt = $db->prepare("
            SELECT id, candidate_ids FROM request_polls
            WHERE id = :id AND status = 'active' AND expires_at > NOW()
        ");
        $pollStmt->execute(['id' => $pollId]);
        $poll = $pollStmt->fetch();

        if (!$poll) {
            Response::error('Poll not found or expired', 404);
            return;
        }

        $candidateIds = array_map('intval', explode(',', trim($poll['candidate_ids'], '{}')));
        if (!in_array($songId, $candidateIds, true)) {
            Response::error('Song is not a candidate in this poll', 422);
            return;
        }

        // Insert vote (UNIQUE constraint handles duplicate IP)
        try {
            $db->prepare("
                INSERT INTO request_votes (poll_id, song_id, voter_ip)
                VALUES (:poll_id, :song_id, :ip)
            ")->execute(['poll_id' => $pollId, 'song_id' => $songId, 'ip' => $ip]);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'unique') || str_contains($e->getMessage(), 'duplicate')) {
                Response::error('You have already voted in this poll', 409);
                return;
            }
            throw $e;
        }

        Response::json(['ok' => true, 'message' => 'Vote recorded']);
    }
}
