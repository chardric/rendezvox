<?php

declare(strict_types=1);

/**
 * POST   /api/admin/playlists/batch-import  — start background batch import
 * GET    /api/admin/playlists/batch-import  — check import progress
 * DELETE /api/admin/playlists/batch-import  — stop running import
 */
class PlaylistBatchImportHandler
{
    private const MAX_FOLDERS   = 1000;
    private const PROGRESS_FILE = '/tmp/rendezvox_batch_import.json';
    private const LOCK_FILE     = '/tmp/rendezvox_batch_import.lock';
    private const PARAMS_FILE   = '/tmp/rendezvox_batch_import_params.json';
    private const SCRIPT        = '/var/www/html/src/scripts/batch_import.php';

    public function start(): void
    {
        $user = Auth::requireAuth();
        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        $folders   = $body['folders'] ?? [];
        $recursive = (bool) ($body['recursive'] ?? true);

        if (!is_array($folders) || count($folders) === 0) {
            Response::error('folders array is required', 400);
            return;
        }

        if (count($folders) > self::MAX_FOLDERS) {
            Response::error('Maximum ' . self::MAX_FOLDERS . ' folders per request', 400);
            return;
        }

        // Check if already running
        $runningPid = $this->findRunningPid();
        if ($runningPid) {
            Response::json([
                'status'   => 'already_running',
                'message'  => 'Batch import already running',
                'progress' => $this->readProgress(),
            ]);
            return;
        }

        // Write params for background script
        $params = [
            'folders'   => $folders,
            'recursive' => $recursive,
            'user_id'   => $user['sub'] ?? 0,
        ];
        file_put_contents(self::PARAMS_FILE, json_encode($params), LOCK_EX);

        // Launch background process
        $cmd = 'php ' . escapeshellarg(self::SCRIPT) . ' > /tmp/rendezvox_batch_import.log 2>&1 &';
        exec($cmd);

        // Brief pause to let script start
        usleep(500000);

        Response::json([
            'status'   => 'started',
            'message'  => 'Batch import started for ' . count($folders) . ' folder(s)',
            'progress' => $this->readProgress(),
        ]);
    }

    public function status(): void
    {
        Auth::requireAuth();

        $progress = $this->readProgress();

        if (!$progress) {
            Response::json(['status' => 'idle']);
            return;
        }

        // If progress says "running" but no process exists, mark as stopped
        if ($progress['status'] === 'running' && !$this->findRunningPid()) {
            $progress['status'] = 'stopped';
            $progress['finished_at'] = date('c');
            @file_put_contents(self::PROGRESS_FILE, json_encode($progress), LOCK_EX);
        }

        // Clean up lock file if finished
        if (in_array($progress['status'], ['done', 'stopped']) && file_exists(self::LOCK_FILE)) {
            @unlink(self::LOCK_FILE);
        }

        // Clear stale progress from old runs (>5 min old)
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
            Response::json(['message' => 'No batch import is running']);
            return;
        }

        file_put_contents(self::LOCK_FILE . '.stop', '1');
        Response::json(['message' => 'Stop signal sent']);
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
        exec("pgrep -f 'batch_import\\.php' 2>/dev/null", $output);
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
