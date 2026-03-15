<?php

declare(strict_types=1);

/**
 * POST   /api/admin/mood-analyze  — start background mood analysis
 * GET    /api/admin/mood-analyze  — check analysis progress
 * DELETE /api/admin/mood-analyze  — stop running analysis
 */
class MoodAnalyzeHandler
{
    private const PROGRESS_FILE = '/tmp/rendezvox_mood_analyze.json';
    private const LOCK_FILE     = '/tmp/rendezvox_mood_analyze.lock';
    private const SCRIPT        = '/var/www/html/src/scripts/mood_analyze.php';

    public function start(): void
    {
        Auth::requireAuth();

        $runningPid = $this->findRunningPid();
        if ($runningPid) {
            Response::json([
                'message'  => 'Mood analysis already running',
                'progress' => $this->readProgress(),
            ]);
            return;
        }

        $db = Database::get();
        $row = $db->prepare("SELECT value FROM settings WHERE key = 'auto_mood_analyze_enabled'");
        $row->execute();
        $autoWasOn = ($row->fetchColumn() === 'true');
        if ($autoWasOn) {
            $db->prepare("UPDATE settings SET value = 'false' WHERE key = 'auto_mood_analyze_enabled'")
               ->execute();
        }

        $cmd = 'php ' . escapeshellarg(self::SCRIPT) . ' > /tmp/rendezvox_mood_analyze.log 2>&1 &';
        exec($cmd);

        usleep(500000);

        $response = [
            'message'  => 'Mood analysis started',
            'progress' => $this->readProgress(),
        ];
        if ($autoWasOn) {
            $response['auto_mood_disabled'] = true;
        }
        Response::json($response);
    }

    public function status(): void
    {
        Auth::requireAuth();

        $progress = $this->readProgress();

        if (!$progress) {
            $db = Database::get();
            $countStmt = $db->query("
                SELECT COUNT(*) FROM songs WHERE is_active = true AND mood_analyzed_at IS NULL
            ");
            $pending = (int) $countStmt->fetchColumn();

            $totalStmt = $db->query("
                SELECT COUNT(*) FROM songs WHERE is_active = true AND mood_analyzed_at IS NOT NULL
            ");
            $analyzed = (int) $totalStmt->fetchColumn();

            Response::json([
                'status'   => 'idle',
                'pending'  => $pending,
                'analyzed' => $analyzed,
            ]);
            return;
        }

        if ($progress['status'] === 'running' && !$this->findRunningPid()) {
            $progress['status'] = 'stopped';
            $progress['finished_at'] = date('c');
            @file_put_contents(self::PROGRESS_FILE, json_encode($progress), LOCK_EX);
        }

        if (in_array($progress['status'], ['done', 'stopped']) && file_exists(self::LOCK_FILE)) {
            @unlink(self::LOCK_FILE);
        }

        if (in_array($progress['status'], ['done', 'stopped'])) {
            $finishedAt = $progress['finished_at'] ?? null;
            if ($finishedAt && (time() - strtotime($finishedAt)) > 300) {
                @unlink(self::PROGRESS_FILE);
                Response::json(['status' => 'idle']);
                return;
            }
        }

        Response::json($progress);
    }

    public function stop(): void
    {
        Auth::requireAuth();

        $progress = $this->readProgress();
        if (!$progress || $progress['status'] !== 'running') {
            Response::json(['message' => 'No mood analysis is running']);
            return;
        }

        file_put_contents(self::LOCK_FILE . '.stop', '1');
        Response::json(['message' => 'Stop signal sent']);
    }

    /** GET /api/admin/auto-mood-status */
    public function autoMoodStatus(): void
    {
        Auth::requireAuth();

        $file = '/tmp/rendezvox_auto_mood_last.json';
        if (!file_exists($file)) {
            Response::json(['has_run' => false]);
            return;
        }

        $data = @file_get_contents($file);
        $result = $data ? json_decode($data, true) : null;

        if (!$result) {
            Response::json(['has_run' => false]);
            return;
        }

        $result['has_run'] = true;
        Response::json($result);
    }

    private function findRunningPid(): ?int
    {
        if (file_exists(self::LOCK_FILE)) {
            $pid = (int) @file_get_contents(self::LOCK_FILE);
            if ($pid > 0 && file_exists("/proc/{$pid}")) {
                return $pid;
            }
            @unlink(self::LOCK_FILE);
        }

        $output = [];
        exec("pgrep -f 'mood_analyze\\.php' 2>/dev/null", $output);
        foreach ($output as $line) {
            $pid = (int) trim($line);
            if ($pid > 0 && $pid !== getmypid() && file_exists("/proc/{$pid}")) {
                return $pid;
            }
        }

        return null;
    }

    private function readProgress(): ?array
    {
        if (!file_exists(self::PROGRESS_FILE)) return null;
        $data = @file_get_contents(self::PROGRESS_FILE);
        if (!$data) return null;
        return json_decode($data, true);
    }
}
