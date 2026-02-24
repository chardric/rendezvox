<?php

declare(strict_types=1);

/**
 * POST   /api/admin/artist-dedup — start background artist deduplication
 * GET    /api/admin/artist-dedup — check dedup progress
 * DELETE /api/admin/artist-dedup — stop running dedup
 */
class ArtistDedupHandler
{
    private const PROGRESS_FILE = '/tmp/iradio_artist_dedup.json';
    private const LOCK_FILE     = '/tmp/iradio_artist_dedup.lock';
    private const SCRIPT        = '/var/www/html/src/scripts/artist_dedup.php';

    public function start(): void
    {
        Auth::requireRole('super_admin');

        // Check for any running dedup process (lock file OR process list)
        $runningPid = $this->findRunningPid();
        if ($runningPid) {
            $cmdline = @file_get_contents("/proc/{$runningPid}/cmdline");
            $isAuto = $cmdline && str_contains($cmdline, '--auto');

            if ($isAuto) {
                // Auto-dedup running — stop it, manual takes priority
                file_put_contents(self::LOCK_FILE . '.stop', '1');
                for ($i = 0; $i < 20; $i++) {
                    usleep(500000);
                    if (!file_exists("/proc/{$runningPid}")) break;
                }
                @unlink(self::LOCK_FILE);
                @unlink(self::LOCK_FILE . '.stop');
            } else {
                Response::json([
                    'message'  => 'Dedup already running',
                    'progress' => $this->readProgress(),
                ]);
                return;
            }
        }

        // Always disable auto-dedup when manual starts
        $db = Database::get();
        $row = $db->prepare("SELECT value FROM settings WHERE key = 'auto_artist_dedup_enabled'");
        $row->execute();
        $autoWasOn = ($row->fetchColumn() === 'true');
        if ($autoWasOn) {
            $db->prepare("UPDATE settings SET value = 'false' WHERE key = 'auto_artist_dedup_enabled'")
               ->execute();
        }

        // Launch background process
        $cmd = 'php ' . escapeshellarg(self::SCRIPT) . ' > /tmp/iradio_artist_dedup.log 2>&1 &';
        exec($cmd);

        // Brief pause to let the script start and write initial progress
        usleep(500000);

        $response = [
            'message'  => 'Dedup started',
            'progress' => $this->readProgress(),
        ];
        if ($autoWasOn) {
            $response['auto_dedup_disabled'] = true;
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
                'message' => 'No dedup has been run yet',
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
            Response::json(['message' => 'No dedup is running']);
            return;
        }

        // Signal the script to stop
        file_put_contents(self::LOCK_FILE . '.stop', '1');

        Response::json(['message' => 'Stop signal sent']);
    }

    /**
     * Find a running artist_dedup.php process — checks lock file first, falls back to process list.
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
        exec("pgrep -f 'artist_dedup\\.php' 2>/dev/null", $output);
        foreach ($output as $line) {
            $pid = (int) trim($line);
            if ($pid > 0 && $pid !== getmypid() && file_exists("/proc/{$pid}")) {
                return $pid;
            }
        }

        return null;
    }

    public function autoDedupStatus(): void
    {
        Auth::requireAuth();

        $file = '/tmp/iradio_auto_dedup_last.json';
        if (!file_exists($file)) {
            Response::json([
                'has_run' => false,
                'message' => 'Auto-dedup has not run yet',
            ]);
            return;
        }

        $data = @file_get_contents($file);
        $result = $data ? json_decode($data, true) : null;

        if (!$result) {
            Response::json([
                'has_run' => false,
                'message' => 'Auto-dedup has not run yet',
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
