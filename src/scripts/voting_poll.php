<?php
/**
 * Voting Poll Manager — cron job to manage request voting polls.
 *
 * Usage:
 *   php voting_poll.php   — run every minute via cron
 *
 * Closes expired polls (selects winner), creates new poll if voting enabled.
 * Candidates are picked from popular/requestable songs not recently played.
 */

declare(strict_types=1);

require __DIR__ . '/../core/Database.php';

$db = Database::get();

// Check if voting is enabled
$stmt = $db->prepare("SELECT value FROM settings WHERE key = 'voting_enabled'");
$stmt->execute();
$row = $stmt->fetch();
if (!$row || $row['value'] !== 'true') {
    exit(0);
}

$durationStmt = $db->prepare("SELECT value FROM settings WHERE key = 'voting_duration_minutes'");
$durationStmt->execute();
$durationRow = $durationStmt->fetch();
$durationMinutes = $durationRow ? (int) $durationRow['value'] : 15;

$countStmt = $db->prepare("SELECT value FROM settings WHERE key = 'voting_candidate_count'");
$countStmt->execute();
$countRow = $countStmt->fetch();
$candidateCount = $countRow ? max(2, min(6, (int) $countRow['value'])) : 4;

// ── Close expired polls ─────────────────────────────────
$expiredStmt = $db->prepare("
    SELECT id, candidate_ids FROM request_polls
    WHERE status = 'active' AND expires_at <= NOW()
");
$expiredStmt->execute();
$expired = $expiredStmt->fetchAll(\PDO::FETCH_ASSOC);

foreach ($expired as $poll) {
    $pollId = (int) $poll['id'];
    $candidates = trim($poll['candidate_ids'], '{}');

    // Count votes per song
    $voteStmt = $db->prepare("
        SELECT song_id, COUNT(*) AS vote_count
        FROM request_votes
        WHERE poll_id = :poll_id
        GROUP BY song_id
        ORDER BY vote_count DESC
        LIMIT 1
    ");
    $voteStmt->execute(['poll_id' => $pollId]);
    $winner = $voteStmt->fetch();

    $winnerId = $winner ? (int) $winner['song_id'] : null;

    if (!$winnerId && $candidates) {
        // No votes cast — pick random from candidates
        $ids = array_map('intval', explode(',', $candidates));
        $winnerId = $ids[array_rand($ids)];
    }

    // Close the poll
    $db->prepare("
        UPDATE request_polls
        SET status = 'closed', winner_song_id = :winner
        WHERE id = :id
    ")->execute(['winner' => $winnerId, 'id' => $pollId]);

    // Queue the winner as an approved request
    if ($winnerId) {
        // Create a system request for the winner
        $db->prepare("
            INSERT INTO song_requests (song_id, listener_ip, listener_name, status, expires_at)
            VALUES (:song_id, '127.0.0.1'::inet, 'Community Vote', 'approved', NOW() + INTERVAL '2 hours')
        ")->execute(['song_id' => $winnerId]);

        $reqId = (int) $db->lastInsertId();

        // Add to request queue
        $maxPosStmt = $db->query("SELECT COALESCE(MAX(position), 0) FROM request_queue");
        $maxPos = (int) $maxPosStmt->fetchColumn();

        $db->prepare("
            INSERT INTO request_queue (request_id, song_id, position)
            VALUES (:req_id, :song_id, :pos)
        ")->execute(['req_id' => $reqId, 'song_id' => $winnerId, 'pos' => $maxPos + 1]);

        $db->prepare("
            UPDATE request_polls SET status = 'played' WHERE id = :id
        ")->execute(['id' => $pollId]);
    }
}

// ── Create new poll if none active ─────────────────────
$activeStmt = $db->query("SELECT id FROM request_polls WHERE status = 'active' LIMIT 1");
if ($activeStmt->fetch()) {
    exit(0); // Already have an active poll
}

// Pick candidates: requestable songs not recently played, varied genres
$candidatesStmt = $db->prepare("
    SELECT s.id
    FROM songs s
    JOIN categories c ON c.id = s.category_id
    WHERE s.is_active = true
      AND s.is_requestable = true
      AND s.duplicate_of IS NULL
      AND s.trashed_at IS NULL
      AND c.type = 'music'
      AND s.id NOT IN (
          SELECT song_id FROM play_history
          WHERE started_at > NOW() - INTERVAL '6 hours'
      )
    ORDER BY random()
    LIMIT :count
");
$candidatesStmt->execute(['count' => $candidateCount]);
$picks = $candidatesStmt->fetchAll(\PDO::FETCH_COLUMN);

if (count($picks) < 2) {
    // Not enough candidates — skip poll creation
    exit(0);
}

$pgArray = '{' . implode(',', $picks) . '}';
$expiresAt = (new \DateTime())->modify("+{$durationMinutes} minutes")->format('Y-m-d H:i:s');

$db->prepare("
    INSERT INTO request_polls (candidate_ids, expires_at)
    VALUES (:candidates, :expires)
")->execute(['candidates' => $pgArray, 'expires' => $expiresAt]);
