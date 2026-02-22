<?php

declare(strict_types=1);

/**
 * POST /api/request
 *
 * Listener-facing endpoint to submit a song request.
 * Validates IP ban, rate limit, song eligibility, duplicate check,
 * and the DB trigger enforces the per-listener active request limit.
 */
class SubmitRequestHandler
{
    public function handle(): void
    {
        $input = json_decode(file_get_contents('php://input'), true);

        if (!$input || (empty($input['title']) && empty($input['artist']))) {
            Response::error('Provide at least a song title or artist name', 422);
        }

        $title        = trim((string) $input['title']);
        $artist       = isset($input['artist']) ? trim((string) $input['artist']) : '';
        $listenerName = isset($input['listener_name']) ? trim((string) $input['listener_name']) : null;
        $message      = isset($input['message']) ? trim((string) $input['message']) : null;

        if (mb_strlen($title) > 255) Response::error('Song title is too long (max 255 characters)', 422);
        if (mb_strlen($artist) > 255) Response::error('Artist name is too long (max 255 characters)', 422);
        if ($listenerName !== null && mb_strlen($listenerName) > 100) Response::error('Listener name is too long (max 100 characters)', 422);
        if ($message !== null && mb_strlen($message) > 500) Response::error('Message is too long (max 500 characters)', 422);

        $ip           = $this->getListenerIp();
        $db           = Database::get();

        // ── 0. Emergency mode check ────────────────────────────
        if ($this->getSetting($db, 'emergency_mode', 'false') === 'true') {
            Response::error('Song requests are temporarily unavailable', 503);
        }

        // ── 1. Ban check ─────────────────────────────────────
        if ($this->isBanned($db, $ip)) {
            Response::error('Your IP address has been banned from making requests', 403);
        }

        // ── 2. Rate limit (1 per N seconds) ──────────────────
        $rateLimitSeconds = (int) $this->getSetting($db, 'request_rate_limit_seconds', '60');
        if ($this->isRateLimited($db, $ip, $rateLimitSeconds)) {
            Response::error('Please wait before submitting another request', 429);
        }

        // ── 2b. Content filter ─────────────────────────────
        if ($this->getSetting($db, 'profanity_filter_enabled', 'true') === 'true') {
            $filter = new ContentFilter($db);
            if ($listenerName) {
                $err = $filter->check($listenerName);
                if ($err) Response::error('Listener name: ' . $err, 422);
            }
            if ($message) {
                $err = $filter->check($message);
                if ($err) Response::error('Dedication message: ' . $err, 422);
            }
        }

        // ── 3. Resolve song via title + artist ─────────────────
        $resolver = new SongResolver($db);
        $result   = $resolver->resolve($title, $artist);

        if (empty($result['songs'])) {
            Response::error('No matching song found', 404);
        }

        if (!$result['resolved']) {
            Response::json([
                'error'       => 'Multiple songs matched — please select one',
                'suggestions' => $result['songs'],
            ], 422);
            return;
        }

        $songId = $result['songs'][0]['id'];

        // ── 4. Song validation ───────────────────────────────
        $song = $this->fetchRequestableSong($db, $songId);
        if (!$song) {
            Response::error('Song not found or is not available for requests', 404);
        }

        // ── 4. Duplicate check (global) ──────────────────────
        if ($this->isDuplicate($db, $songId)) {
            Response::error('This song has already been requested', 409);
        }

        // ── 5. Insert request ────────────────────────────────
        $expiryMinutes = (int) $this->getSetting($db, 'request_expiry_minutes', '120');

        try {
            $stmt = $db->prepare("
                INSERT INTO song_requests (song_id, listener_ip, listener_name, message, expires_at)
                VALUES (:song_id, :ip, :name, :message, NOW() + (:expiry || ' minutes')::INTERVAL)
                RETURNING id, status, expires_at
            ");
            $stmt->execute([
                'song_id' => $songId,
                'ip'      => $ip,
                'name'    => $listenerName ?: null,
                'message' => $message ?: null,
                'expiry'  => $expiryMinutes,
            ]);
            $row = $stmt->fetch();
        } catch (\PDOException $e) {
            // DB trigger raises exception when request limit is reached
            if (str_contains($e->getMessage(), 'Request limit')) {
                Response::error('Maximum active requests reached — please wait for your current requests to be played or expire', 429);
            }
            throw $e;
        }

        $requestId = (int) $row['id'];
        $status    = $row['status'];

        // ── 6. Auto-approve if enabled ───────────────────────
        $autoApprove = $this->getSetting($db, 'request_auto_approve', 'false') === 'true';

        if ($autoApprove) {
            $db->prepare('UPDATE song_requests SET status = :status WHERE id = :id')
                ->execute(['status' => 'approved', 'id' => $requestId]);

            $this->addToQueue($db, $requestId, $songId);
            $status = 'approved';
        }

        // ── 7. Response ──────────────────────────────────────
        Response::json([
            'status'     => $status,
            'request_id' => $requestId,
            'song'       => [
                'id'     => (int) $song['id'],
                'title'  => $song['title'],
                'artist' => $song['artist_name'],
            ],
            'expires_at' => $row['expires_at'],
        ], 201);
    }

    private function getListenerIp(): string
    {
        return Request::clientIp();
    }

    private function isBanned(PDO $db, string $ip): bool
    {
        $stmt = $db->prepare('
            SELECT 1 FROM banned_ips
            WHERE ip_address = :ip::inet
              AND (expires_at IS NULL OR expires_at > NOW())
            LIMIT 1
        ');
        $stmt->execute(['ip' => $ip]);
        return (bool) $stmt->fetch();
    }

    private function isRateLimited(PDO $db, string $ip, int $seconds): bool
    {
        if ($seconds <= 0) {
            return false;
        }

        $stmt = $db->prepare("
            SELECT 1 FROM song_requests
            WHERE listener_ip = :ip::inet
              AND created_at > NOW() - (:seconds || ' seconds')::INTERVAL
            LIMIT 1
        ");
        $stmt->execute(['ip' => $ip, 'seconds' => $seconds]);
        return (bool) $stmt->fetch();
    }

    private function fetchRequestableSong(PDO $db, int $songId): ?array
    {
        $stmt = $db->prepare('
            SELECT s.id, s.title, a.name AS artist_name
            FROM songs s
            JOIN artists a ON a.id = s.artist_id
            WHERE s.id = :id
              AND s.is_active = true
              AND s.is_requestable = true
        ');
        $stmt->execute(['id' => $songId]);
        return $stmt->fetch() ?: null;
    }

    private function isDuplicate(PDO $db, int $songId): bool
    {
        $stmt = $db->prepare('
            SELECT 1 FROM song_requests
            WHERE song_id = :song_id
              AND status IN (:s1, :s2)
            LIMIT 1
        ');
        $stmt->execute(['song_id' => $songId, 's1' => 'pending', 's2' => 'approved']);
        return (bool) $stmt->fetch();
    }

    private function addToQueue(PDO $db, int $requestId, int $songId): int
    {
        $stmt = $db->prepare('
            INSERT INTO request_queue (request_id, song_id, position)
            VALUES (:request_id, :song_id, COALESCE((SELECT MAX(position) FROM request_queue), 0) + 1)
            RETURNING position
        ');
        $stmt->execute(['request_id' => $requestId, 'song_id' => $songId]);
        return (int) $stmt->fetchColumn();
    }

    private function getSetting(PDO $db, string $key, string $default): string
    {
        $stmt = $db->prepare('SELECT value FROM settings WHERE key = :key');
        $stmt->execute(['key' => $key]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (string) $val : $default;
    }
}
