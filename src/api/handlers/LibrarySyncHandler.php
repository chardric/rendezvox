<?php

declare(strict_types=1);

/**
 * POST   /api/admin/library-sync — start background library sync
 * GET    /api/admin/library-sync — check sync progress
 * DELETE /api/admin/library-sync — stop running sync
 */
class LibrarySyncHandler
{
    private const PROGRESS_FILE = '/tmp/iradio_library_sync.json';
    private const LOCK_FILE     = '/tmp/iradio_library_sync.lock';
    private const SCRIPT        = '/var/www/html/src/scripts/library_sync.php';

    public function start(): void
    {
        Auth::requireRole('super_admin');

        // Check for concurrent sync
        $progress = $this->readProgress();
        if ($progress && $progress['status'] === 'running') {
            if (file_exists(self::LOCK_FILE)) {
                $pid = (int) @file_get_contents(self::LOCK_FILE);
                if ($pid > 0 && file_exists("/proc/{$pid}")) {
                    Response::json([
                        'message'  => 'Sync already running',
                        'progress' => $progress,
                    ]);
                    return;
                }
            }
        }

        // Launch background process
        $cmd = 'php ' . escapeshellarg(self::SCRIPT) . ' > /tmp/iradio_library_sync.log 2>&1 &';
        exec($cmd);

        // Brief pause to let the script start and write initial progress
        usleep(500000);

        Response::json([
            'message'  => 'Sync started',
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
                'message' => 'No sync has been run yet',
            ]);
            return;
        }

        // Clean up lock file if finished
        if (in_array($progress['status'], ['done', 'stopped']) && file_exists(self::LOCK_FILE)) {
            @unlink(self::LOCK_FILE);
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

    private function readProgress(): ?array
    {
        if (!file_exists(self::PROGRESS_FILE)) return null;
        $data = @file_get_contents(self::PROGRESS_FILE);
        if (!$data) return null;
        return json_decode($data, true);
    }
}
