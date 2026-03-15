<?php

declare(strict_types=1);

/**
 * GET /api/vote/poll — get current active poll with candidates (public)
 */
class VotePollHandler
{
    public function handle(): void
    {
        $db = Database::get();

        // Check if voting is enabled
        $stmt = $db->prepare("SELECT value FROM settings WHERE key = 'voting_enabled'");
        $stmt->execute();
        $row = $stmt->fetch();
        if (!$row || $row['value'] !== 'true') {
            Response::json(['enabled' => false, 'poll' => null]);
            return;
        }

        // Get active poll
        $pollStmt = $db->query("
            SELECT id, candidate_ids, expires_at, created_at
            FROM request_polls
            WHERE status = 'active'
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $poll = $pollStmt->fetch();

        if (!$poll) {
            Response::json(['enabled' => true, 'poll' => null]);
            return;
        }

        // Parse candidate IDs
        $candidateIds = array_map('intval', explode(',', trim($poll['candidate_ids'], '{}')));

        if (empty($candidateIds)) {
            Response::json(['enabled' => true, 'poll' => null]);
            return;
        }

        // Fetch candidate song details
        $placeholders = [];
        $params = [];
        foreach ($candidateIds as $i => $id) {
            $key = 'id_' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }

        $songStmt = $db->prepare("
            SELECT s.id, s.title, s.has_cover_art, s.duration_ms,
                   a.name AS artist, c.name AS category
            FROM songs s
            JOIN artists a ON a.id = s.artist_id
            JOIN categories c ON c.id = s.category_id
            WHERE s.id IN (" . implode(',', $placeholders) . ")
        ");
        $songStmt->execute($params);
        $songs = $songStmt->fetchAll(\PDO::FETCH_ASSOC);

        // Get vote counts per candidate
        $voteStmt = $db->prepare("
            SELECT song_id, COUNT(*) AS votes
            FROM request_votes
            WHERE poll_id = :poll_id
            GROUP BY song_id
        ");
        $voteStmt->execute(['poll_id' => $poll['id']]);
        $voteCounts = [];
        while ($v = $voteStmt->fetch()) {
            $voteCounts[(int) $v['song_id']] = (int) $v['votes'];
        }

        $totalVotes = array_sum($voteCounts);

        // Check if this IP already voted
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $votedStmt = $db->prepare("
            SELECT song_id FROM request_votes
            WHERE poll_id = :poll_id AND voter_ip = :ip
        ");
        $votedStmt->execute(['poll_id' => $poll['id'], 'ip' => $ip]);
        $votedFor = $votedStmt->fetchColumn();

        // Build candidates with vote data
        $candidates = [];
        foreach ($songs as $s) {
            $songId = (int) $s['id'];
            $candidates[] = [
                'id'            => $songId,
                'title'         => $s['title'],
                'artist'        => $s['artist'],
                'category'      => $s['category'],
                'has_cover_art' => (bool) $s['has_cover_art'],
                'duration_ms'   => (int) $s['duration_ms'],
                'votes'         => $voteCounts[$songId] ?? 0,
            ];
        }

        Response::json([
            'enabled' => true,
            'poll' => [
                'id'          => (int) $poll['id'],
                'candidates'  => $candidates,
                'total_votes' => $totalVotes,
                'voted_for'   => $votedFor !== false ? (int) $votedFor : null,
                'expires_at'  => $poll['expires_at'],
                'created_at'  => $poll['created_at'],
            ],
        ]);
    }
}
