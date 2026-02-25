<?php

declare(strict_types=1);

/**
 * POST   /api/admin/rename-paths — start background path renamer
 * GET    /api/admin/rename-paths — check progress
 * DELETE /api/admin/rename-paths — stop running rename
 */
class RenamePathsHandler
{
    private const PROGRESS_FILE = '/tmp/rendezvox_rename_paths.json';
    private const LOCK_FILE     = '/tmp/rename-paths.lock';
    private const SCRIPT        = '/var/www/html/src/scripts/rename_paths.php';

    public function start(): void
    {
        Auth::requireRole('super_admin');

        $runningPid = $this->findRunningPid();
        if ($runningPid) {
            Response::json([
                'message'  => 'Rename already running',
                'progress' => $this->readProgress(),
            ]);
            return;
        }

        // Disable auto-rename when manual rename starts
        $db = Database::get();
        $row = $db->prepare("SELECT value FROM settings WHERE key = 'auto_rename_paths_enabled'");
        $row->execute();
        $autoWasOn = ($row->fetchColumn() === 'true');
        if ($autoWasOn) {
            $db->prepare("UPDATE settings SET value = 'false' WHERE key = 'auto_rename_paths_enabled'")
               ->execute();
        }

        $cmd = 'php ' . escapeshellarg(self::SCRIPT) . ' > /tmp/rendezvox_rename_paths.log 2>&1 &';
        exec($cmd);

        usleep(500000);

        $response = [
            'message'  => 'Path rename started',
            'progress' => $this->readProgress(),
        ];
        if ($autoWasOn) {
            $response['auto_rename_disabled'] = true;
        }
        Response::json($response);
    }

    public function status(): void
    {
        Auth::requireAuth();

        $progress = $this->readProgress();

        if (!$progress) {
            Response::json([
                'status'  => 'idle',
                'message' => 'No rename has been run yet',
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
        Auth::requireRole('super_admin');

        $progress = $this->readProgress();
        if (!$progress || $progress['status'] !== 'running') {
            Response::json(['message' => 'No rename is running']);
            return;
        }

        file_put_contents(self::LOCK_FILE . '.stop', '1');

        Response::json(['message' => 'Stop signal sent']);
    }

    /**
     * GET /api/admin/auto-rename-status — last auto-rename run result
     */
    public function autoRenameStatus(): void
    {
        Auth::requireAuth();

        $file = '/tmp/rendezvox_auto_rename_last.json';
        if (!file_exists($file)) {
            Response::json([
                'has_run' => false,
                'message' => 'Auto-rename has not run yet',
            ]);
            return;
        }

        $data = @file_get_contents($file);
        $result = $data ? json_decode($data, true) : null;

        if (!$result) {
            Response::json([
                'has_run' => false,
                'message' => 'Auto-rename has not run yet',
            ]);
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
        exec("pgrep -f 'rename_paths\\.php' 2>/dev/null", $output);
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
