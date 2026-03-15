<?php

declare(strict_types=1);

/**
 * Segment CRUD handler.
 *
 * File-based types: announcement, news, devotional, prayer, podcast, promo,
 *                   psa, commercial, weather, editorial, interview, custom
 * Library-based types: opm, song_pick, music_block
 *
 * GET    /api/admin/segments            — list all segments
 * POST   /api/admin/segments            — create segment
 * PUT    /api/admin/segments/:id        — update segment
 * DELETE /api/admin/segments/:id        — delete segment
 * POST   /api/admin/segments/:id/files  — add audio file to rotation
 * DELETE /api/admin/segments/:id/files/:fid — remove file from rotation
 * PUT    /api/admin/segments/:id/files/reorder — reorder rotation files
 */
class SegmentHandler
{
    private const SEGMENT_DIR = '/var/lib/rendezvox/segments';

    private const VALID_TYPES = [
        'announcement', 'news', 'devotional', 'prayer', 'podcast', 'promo',
        'psa', 'commercial', 'weather', 'editorial', 'interview',
        'opm', 'song_pick', 'music_block', 'custom',
    ];

    private const LIBRARY_TYPES = ['opm', 'song_pick', 'music_block'];

    public function list(): void
    {
        Auth::requireAuth();
        $db = Database::get();

        $stmt = $db->query("
            SELECT s.*, u.username AS created_by_name
            FROM segments s
            LEFT JOIN users u ON u.id = s.created_by
            ORDER BY s.play_time ASC, s.name ASC
        ");
        $segments = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($segments as &$seg) {
            $seg['days_of_week'] = $seg['days_of_week']
                ? array_map('intval', explode(',', trim($seg['days_of_week'], '{}')))
                : null;
            $seg['library_config'] = $seg['library_config']
                ? json_decode($seg['library_config'], true)
                : null;
        }
        unset($seg);

        // Load rotation files for file-based segments
        $fileStmt = $db->query("SELECT id, segment_id, file_path, duration_ms, position, last_played_at FROM segment_files ORDER BY segment_id, position");
        $allFiles = $fileStmt->fetchAll(\PDO::FETCH_ASSOC);
        $filesMap = [];
        foreach ($allFiles as $f) {
            $filesMap[(int) $f['segment_id']][] = $f;
        }

        // For library-based segments, resolve song details and category names
        $songIds = [];
        $catIds = [];
        foreach ($segments as &$seg) {
            $seg['files'] = $filesMap[(int) $seg['id']] ?? [];
            if (in_array($seg['segment_type'], self::LIBRARY_TYPES, true) && $seg['library_config']) {
                $ids = $seg['library_config']['song_ids'] ?? [];
                foreach ($ids as $sid) {
                    $songIds[(int) $sid] = true;
                }
                if (isset($seg['library_config']['category_id'])) {
                    $catIds[(int) $seg['library_config']['category_id']] = true;
                }
            }
        }
        unset($seg);

        // Batch-load song info for library segments
        $songsMap = [];
        if (!empty($songIds)) {
            $idList = implode(',', array_map('intval', array_keys($songIds)));
            $songStmt = $db->query("
                SELECT s.id, s.title, s.duration_ms, a.name AS artist
                FROM songs s
                LEFT JOIN artists a ON a.id = s.artist_id
                WHERE s.id IN ({$idList})
            ");
            foreach ($songStmt->fetchAll(\PDO::FETCH_ASSOC) as $song) {
                $songsMap[(int) $song['id']] = $song;
            }
        }

        // Batch-load category names
        $catsMap = [];
        if (!empty($catIds)) {
            $catList = implode(',', array_map('intval', array_keys($catIds)));
            $catStmt = $db->query("SELECT id, name FROM categories WHERE id IN ({$catList})");
            foreach ($catStmt->fetchAll(\PDO::FETCH_ASSOC) as $cat) {
                $catsMap[(int) $cat['id']] = $cat['name'];
            }
        }

        foreach ($segments as &$seg) {
            if (in_array($seg['segment_type'], self::LIBRARY_TYPES, true) && $seg['library_config']) {
                $resolved = [];
                foreach (($seg['library_config']['song_ids'] ?? []) as $sid) {
                    if (isset($songsMap[(int) $sid])) {
                        $resolved[] = $songsMap[(int) $sid];
                    }
                }
                $seg['library_songs'] = $resolved;
                // Inject category name
                if (isset($seg['library_config']['category_id'])) {
                    $cid = (int) $seg['library_config']['category_id'];
                    $seg['library_config']['category_name'] = $catsMap[$cid] ?? null;
                }
            } else {
                $seg['library_songs'] = [];
            }
        }
        unset($seg);

        Response::json(['segments' => $segments]);
    }

    public function create(): void
    {
        $user = Auth::requireAuth();
        $db   = Database::get();

        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            Response::error('Invalid JSON body', 422);
            return;
        }

        $name = trim($input['name'] ?? '');
        $segmentType = trim($input['segment_type'] ?? 'announcement');
        $playTime = trim($input['play_time'] ?? '');
        $daysOfWeek = $input['days_of_week'] ?? null;
        $priority = (int) ($input['priority'] ?? 10);
        $libraryConfig = $input['library_config'] ?? null;
        $intervalMinutes = isset($input['interval_minutes']) ? (int) $input['interval_minutes'] : null;

        if (!$name || !$playTime) {
            Response::error('Name and play_time are required', 422);
            return;
        }

        if (!in_array($segmentType, self::VALID_TYPES, true)) {
            Response::error('Invalid segment type', 422);
            return;
        }

        if ($intervalMinutes !== null && ($intervalMinutes < 5 || $intervalMinutes > 1440)) {
            Response::error('Interval must be between 5 and 1440 minutes', 422);
            return;
        }

        // Parse days_of_week
        $pgDays = null;
        if ($daysOfWeek !== null && $daysOfWeek !== '') {
            if (is_string($daysOfWeek)) {
                $days = array_map('intval', explode(',', $daysOfWeek));
            } else {
                $days = array_map('intval', (array) $daysOfWeek);
            }
            $pgDays = '{' . implode(',', $days) . '}';
        }

        $pgLibConfig = $libraryConfig ? json_encode($libraryConfig) : null;

        $stmt = $db->prepare("
            INSERT INTO segments (name, segment_type, days_of_week, play_time, priority, library_config, interval_minutes, created_by)
            VALUES (:name, :type, :days, :time, :priority, :lib_config, :interval, :user_id)
            RETURNING id
        ");
        $stmt->execute([
            'name'       => $name,
            'type'       => $segmentType,
            'days'       => $pgDays,
            'time'       => $playTime,
            'priority'   => $priority,
            'lib_config' => $pgLibConfig,
            'interval'   => $intervalMinutes,
            'user_id'    => $user['id'] ?? null,
        ]);
        $id = (int) $stmt->fetchColumn();

        Response::json(['id' => $id, 'message' => 'Segment created']);
    }

    public function update(): void
    {
        Auth::requireAuth();
        $db = Database::get();
        $id = (int) ($_GET['id'] ?? 0);

        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            Response::error('Invalid JSON body', 422);
            return;
        }

        $fields = [];
        $params = ['id' => $id];

        foreach (['name', 'segment_type', 'play_time'] as $field) {
            if (isset($input[$field])) {
                $fields[] = "{$field} = :{$field}";
                $params[$field] = trim($input[$field]);
            }
        }

        if (isset($input['priority'])) {
            $fields[] = 'priority = :priority';
            $params['priority'] = (int) $input['priority'];
        }

        if (isset($input['is_active'])) {
            $fields[] = 'is_active = :is_active';
            $params['is_active'] = $input['is_active'] ? 'true' : 'false';
        }

        if (array_key_exists('days_of_week', $input)) {
            if ($input['days_of_week'] === null) {
                $fields[] = 'days_of_week = NULL';
            } else {
                $days = array_map('intval', (array) $input['days_of_week']);
                $fields[] = 'days_of_week = :days';
                $params['days'] = '{' . implode(',', $days) . '}';
            }
        }

        if (array_key_exists('interval_minutes', $input)) {
            if ($input['interval_minutes'] === null) {
                $fields[] = 'interval_minutes = NULL';
            } else {
                $val = (int) $input['interval_minutes'];
                if ($val < 5 || $val > 1440) {
                    Response::error('Interval must be between 5 and 1440 minutes', 422);
                    return;
                }
                $fields[] = 'interval_minutes = :interval';
                $params['interval'] = $val;
            }
        }

        if (array_key_exists('library_config', $input)) {
            if ($input['library_config'] === null) {
                $fields[] = 'library_config = NULL';
            } else {
                $fields[] = 'library_config = :lib_config';
                $params['lib_config'] = json_encode($input['library_config']);
            }
        }

        if (empty($fields)) {
            Response::error('No fields to update', 422);
            return;
        }

        $sql = 'UPDATE segments SET ' . implode(', ', $fields) . ' WHERE id = :id RETURNING id';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        if (!$stmt->fetch()) {
            Response::error('Segment not found', 404);
            return;
        }

        Response::json(['message' => 'Segment updated']);
    }

    public function delete(): void
    {
        Auth::requireAuth();
        $db = Database::get();
        $id = (int) ($_GET['id'] ?? 0);

        $stmt = $db->prepare("SELECT file_path FROM segments WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if (!$row) {
            Response::error('Segment not found', 404);
            return;
        }

        // Delete rotation files from disk
        $fileStmt = $db->prepare("SELECT file_path FROM segment_files WHERE segment_id = :id");
        $fileStmt->execute(['id' => $id]);
        while ($f = $fileStmt->fetch()) {
            if ($f['file_path'] && file_exists($f['file_path'])) {
                @unlink($f['file_path']);
            }
        }

        $db->prepare("DELETE FROM segments WHERE id = :id")->execute(['id' => $id]);

        if ($row['file_path'] && file_exists($row['file_path'])) {
            @unlink($row['file_path']);
        }

        Response::json(['message' => 'Segment deleted']);
    }

    // ── Rotation file management ──────────────────────────

    public function addFile(): void
    {
        Auth::requireAuth();
        $db = Database::get();
        $id = (int) ($_GET['id'] ?? 0);

        $stmt = $db->prepare("SELECT id FROM segments WHERE id = :id");
        $stmt->execute(['id' => $id]);
        if (!$stmt->fetch()) {
            Response::error('Segment not found', 404);
            return;
        }

        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            Response::error('Audio file is required', 422);
            return;
        }

        $file = $_FILES['file'];

        $mime = mime_content_type($file['tmp_name']);
        $allowedMimes = ['audio/mpeg', 'audio/mp3', 'audio/ogg', 'audio/wav', 'audio/flac', 'audio/aac', 'audio/mp4', 'audio/x-m4a'];
        if (!in_array($mime, $allowedMimes, true)) {
            Response::error('Invalid audio file type', 422);
            return;
        }

        $cmd = 'ffprobe -v error -show_entries format=duration -of csv=p=0 '
             . escapeshellarg($file['tmp_name']) . ' 2>/dev/null';
        $durationSec = (float) trim((string) shell_exec($cmd));
        $durationMs = (int) ($durationSec * 1000);
        if ($durationMs <= 0) {
            Response::error('Cannot determine audio duration', 422);
            return;
        }

        if (!is_dir(self::SEGMENT_DIR)) {
            @mkdir(self::SEGMENT_DIR, 0775, true);
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'mp3');
        $basename = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
        $filename = 'seg' . $id . '_' . $basename . '_' . time() . '.' . $ext;
        $destPath = self::SEGMENT_DIR . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            Response::error('Failed to save file', 500);
            return;
        }

        $posStmt = $db->prepare("SELECT COALESCE(MAX(position), -1) + 1 FROM segment_files WHERE segment_id = :id");
        $posStmt->execute(['id' => $id]);
        $nextPos = (int) $posStmt->fetchColumn();

        $insertStmt = $db->prepare("
            INSERT INTO segment_files (segment_id, file_path, duration_ms, position)
            VALUES (:sid, :path, :dur, :pos)
            RETURNING id
        ");
        $insertStmt->execute([
            'sid'  => $id,
            'path' => $destPath,
            'dur'  => $durationMs,
            'pos'  => $nextPos,
        ]);
        $fileId = (int) $insertStmt->fetchColumn();

        Response::json([
            'id'          => $fileId,
            'file_path'   => $destPath,
            'duration_ms' => $durationMs,
            'position'    => $nextPos,
            'message'     => 'File added to rotation',
        ], 201);
    }

    public function deleteFile(): void
    {
        Auth::requireAuth();
        $db = Database::get();
        $id  = (int) ($_GET['id'] ?? 0);
        $fid = (int) ($_GET['fid'] ?? 0);

        $stmt = $db->prepare("SELECT file_path FROM segment_files WHERE id = :fid AND segment_id = :sid");
        $stmt->execute(['fid' => $fid, 'sid' => $id]);
        $row = $stmt->fetch();

        if (!$row) {
            Response::error('File not found', 404);
            return;
        }

        $db->prepare("DELETE FROM segment_files WHERE id = :fid")->execute(['fid' => $fid]);

        if ($row['file_path'] && file_exists($row['file_path'])) {
            @unlink($row['file_path']);
        }

        $db->prepare("
            WITH ranked AS (
                SELECT id, ROW_NUMBER() OVER (ORDER BY position) - 1 AS new_pos
                FROM segment_files WHERE segment_id = :sid
            )
            UPDATE segment_files SET position = ranked.new_pos
            FROM ranked WHERE segment_files.id = ranked.id
        ")->execute(['sid' => $id]);

        Response::json(['message' => 'File removed from rotation']);
    }

    public function reorderFiles(): void
    {
        Auth::requireAuth();
        $db = Database::get();
        $id = (int) ($_GET['id'] ?? 0);

        $input = json_decode(file_get_contents('php://input'), true);
        $order = $input['order'] ?? [];

        if (!is_array($order) || empty($order)) {
            Response::error('order array is required', 422);
            return;
        }

        $stmt = $db->prepare("UPDATE segment_files SET position = :pos WHERE id = :fid AND segment_id = :sid");
        foreach ($order as $pos => $fid) {
            $stmt->execute(['pos' => (int) $pos, 'fid' => (int) $fid, 'sid' => $id]);
        }

        Response::json(['message' => 'Rotation order updated']);
    }
}
