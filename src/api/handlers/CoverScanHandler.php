<?php

declare(strict_types=1);

/**
 * POST   /api/admin/cover-scan       — start background cover re-fetch
 * GET    /api/admin/cover-scan       — check progress
 * DELETE /api/admin/cover-scan       — stop running scan
 */
class CoverScanHandler
{
    private const PROGRESS_FILE = '/tmp/rendezvox_cover_rescan.json';
    private const LOCK_FILE     = '/tmp/rendezvox_cover_rescan.lock';
    private const SCRIPT        = '/var/www/html/src/scripts/refetch_covers.php';

    public function start(): void
    {
        Auth::requireAuth();

        $mode = trim($_GET['mode'] ?? 'all');
        if (!in_array($mode, ['all', 'missing'], true)) {
            $mode = 'all';
        }

        $runningPid = $this->findRunningPid();
        if ($runningPid) {
            Response::json([
                'message'  => 'Cover scan already running',
                'progress' => $this->readProgress(),
            ]);
            return;
        }

        $args = $mode === 'missing' ? ' --missing' : '';
        $cmd = 'php ' . escapeshellarg(self::SCRIPT) . $args . ' > /tmp/rendezvox_cover_rescan.log 2>&1 &';
        exec($cmd);

        usleep(500000);

        Response::json([
            'message'  => 'Cover scan started',
            'progress' => $this->readProgress(),
        ]);
    }

    public function status(): void
    {
        Auth::requireAuth();

        $progress = $this->readProgress();

        if (!$progress) {
            Response::json([
                'status'  => 'idle',
                'message' => 'No cover scan has been run yet',
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
            Response::json(['message' => 'No cover scan is running']);
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
        exec("pgrep -f 'refetch_covers\\.php' 2>/dev/null", $output);
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
        if (!file_exists(self::PROGRESS_FILE)) {
            return null;
        }
        $data = @file_get_contents(self::PROGRESS_FILE);
        if (!$data) {
            return null;
        }
        return json_decode($data, true);
    }
}
