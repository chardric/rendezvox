<?php

declare(strict_types=1);

/**
 * POST /api/admin/genre-scan       — start background genre scan
 * GET  /api/admin/genre-scan       — check scan progress
 * DELETE /api/admin/genre-scan     — stop running scan
 */
class GenreScanHandler
{
    private const PROGRESS_FILE = '/tmp/iradio_genre_scan.json';
    private const LOCK_FILE     = '/tmp/iradio_genre_scan.lock';
    private const SCRIPT        = '/var/www/html/src/scripts/fix_genres.php';

    public function start(): void
    {
        Auth::requireAuth();

        // Check for any running scan process (lock file OR process list)
        $runningPid = $this->findRunningPid();
        if ($runningPid) {
            $cmdline = @file_get_contents("/proc/{$runningPid}/cmdline");
            $isAutoTag = $cmdline && str_contains($cmdline, '--auto');

            if ($isAutoTag) {
                // Auto-tag running — stop it, manual takes priority
                file_put_contents(self::LOCK_FILE . '.stop', '1');
                for ($i = 0; $i < 20; $i++) {
                    usleep(500000);
                    if (!file_exists("/proc/{$runningPid}")) break;
                }
                @unlink(self::LOCK_FILE);
                @unlink(self::LOCK_FILE . '.stop');
            } else {
                // Manual scan already running
                Response::json([
                    'message'  => 'Scan already running',
                    'progress' => $this->readProgress(),
                ]);
                return;
            }
        }

        // Always disable auto-tag when manual scan starts
        $db = Database::get();
        $row = $db->prepare("SELECT value FROM settings WHERE key = 'auto_tag_enabled'");
        $row->execute();
        $autoTagWasOn = ($row->fetchColumn() === 'true');
        if ($autoTagWasOn) {
            $db->prepare("UPDATE settings SET value = 'false' WHERE key = 'auto_tag_enabled'")
               ->execute();
        }

        // Launch background process (it creates its own lock file)
        $cmd = 'php ' . escapeshellarg(self::SCRIPT) . ' > /tmp/iradio_genre_scan.log 2>&1 &';
        exec($cmd);

        // Brief pause to let the script start and write initial progress
        usleep(500000);

        $response = [
            'message'  => 'Scan started',
            'progress' => $this->readProgress(),
        ];
        if ($autoTagWasOn) {
            $response['auto_tag_disabled'] = true;
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
                'message' => 'No scan has been run yet',
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
        Auth::requireAuth();

        $progress = $this->readProgress();
        if (!$progress || $progress['status'] !== 'running') {
            Response::json(['message' => 'No scan is running']);
            return;
        }

        // Signal the script to stop by writing a stop file
        file_put_contents(self::LOCK_FILE . '.stop', '1');

        Response::json(['message' => 'Stop signal sent']);
    }

    /**
     * GET /api/admin/auto-tag-status — last auto-tag run result
     */
    public function autoTagStatus(): void
    {
        Auth::requireAuth();

        $file = '/tmp/iradio_auto_tag_last.json';
        if (!file_exists($file)) {
            Response::json([
                'has_run' => false,
                'message' => 'Auto-tag has not run yet',
            ]);
            return;
        }

        $data = @file_get_contents($file);
        $result = $data ? json_decode($data, true) : null;

        if (!$result) {
            Response::json([
                'has_run' => false,
                'message' => 'Auto-tag has not run yet',
            ]);
            return;
        }

        $result['has_run'] = true;
        Response::json($result);
    }

    /**
     * Find a running fix_genres.php process — checks lock file first, falls back to process list.
     */
    private function findRunningPid(): ?int
    {
        // Check lock file
        if (file_exists(self::LOCK_FILE)) {
            $pid = (int) @file_get_contents(self::LOCK_FILE);
            if ($pid > 0 && file_exists("/proc/{$pid}")) {
                return $pid;
            }
            @unlink(self::LOCK_FILE); // stale lock
        }

        // Fallback: search process list for orphaned fix_genres
        $output = [];
        exec("pgrep -f 'fix_genres\\.php' 2>/dev/null", $output);
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
