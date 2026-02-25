<?php

declare(strict_types=1);

/**
 * POST   /api/admin/library-sync — start background library sync
 * GET    /api/admin/library-sync — check sync progress
 * DELETE /api/admin/library-sync — stop running sync
 */
class LibrarySyncHandler
{
    private const PROGRESS_FILE = '/tmp/rendezvox_library_sync.json';
    private const LOCK_FILE     = '/tmp/rendezvox_library_sync.lock';
    private const SCRIPT        = '/var/www/html/src/scripts/library_sync.php';

    public function start(): void
    {
        Auth::requireRole('super_admin');

        // Check for any running sync process (lock file OR process list)
        $runningPid = $this->findRunningPid();
        if ($runningPid) {
            $cmdline = @file_get_contents("/proc/{$runningPid}/cmdline");
            $isAuto = $cmdline && str_contains($cmdline, '--auto');

            if ($isAuto) {
                // Auto-sync running — stop it, manual takes priority
                file_put_contents(self::LOCK_FILE . '.stop', '1');
                for ($i = 0; $i < 20; $i++) {
                    usleep(500000);
                    if (!file_exists("/proc/{$runningPid}")) break;
                }
                @unlink(self::LOCK_FILE);
                @unlink(self::LOCK_FILE . '.stop');
            } else {
                Response::json([
                    'message'  => 'Sync already running',
                    'progress' => $this->readProgress(),
                ]);
                return;
            }
        }

        // Always disable auto-sync when manual starts
        $db = Database::get();
        $row = $db->prepare("SELECT value FROM settings WHERE key = 'auto_library_sync_enabled'");
        $row->execute();
        $autoWasOn = ($row->fetchColumn() === 'true');
        if ($autoWasOn) {
            $db->prepare("UPDATE settings SET value = 'false' WHERE key = 'auto_library_sync_enabled'")
               ->execute();
        }

        // Launch background process
        $cmd = 'php ' . escapeshellarg(self::SCRIPT) . ' > /tmp/rendezvox_library_sync.log 2>&1 &';
        exec($cmd);

        // Brief pause to let the script start and write initial progress
        usleep(500000);

        $response = [
            'message'  => 'Sync started',
            'progress' => $this->readProgress(),
        ];
        if ($autoWasOn) {
            $response['auto_sync_disabled'] = true;
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
                'message' => 'No sync has been run yet',
            ]);
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
        Auth::requireRole('super_admin');

        $progress = $this->readProgress();
        if (!$progress || $progress['status'] !== 'running') {
            Response::json(['message' => 'No sync is running']);
            return;
        }

        // Signal the script to stop
        file_put_contents(self::LOCK_FILE . '.stop', '1');

        Response::json(['message' => 'Stop signal sent']);
    }

    /**
     * Find a running library_sync.php process — checks lock file first, falls back to process list.
     */
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
        exec("pgrep -f 'library_sync\\.php' 2>/dev/null", $output);
        foreach ($output as $line) {
            $pid = (int) trim($line);
            if ($pid > 0 && $pid !== getmypid() && file_exists("/proc/{$pid}")) {
                return $pid;
            }
        }

        return null;
    }

    public function autoSyncStatus(): void
    {
        Auth::requireAuth();

        $file = '/tmp/rendezvox_auto_sync_last.json';
        if (!file_exists($file)) {
            Response::json([
                'has_run' => false,
                'message' => 'Auto-sync has not run yet',
            ]);
            return;
        }

        $data = @file_get_contents($file);
        $result = $data ? json_decode($data, true) : null;

        if (!$result) {
            Response::json([
                'has_run' => false,
                'message' => 'Auto-sync has not run yet',
            ]);
            return;
        }

        $result['has_run'] = true;
        Response::json($result);
    }

    private function readProgress(): ?array
    {
        if (!file_exists(self::PROGRESS_FILE)) return null;
        $data = @file_get_contents(self::PROGRESS_FILE);
        if (!$data) return null;
        return json_decode($data, true);
    }
}
