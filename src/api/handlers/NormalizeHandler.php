<?php

declare(strict_types=1);

/**
 * POST   /api/admin/normalize       — start background loudness analysis
 * GET    /api/admin/normalize       — check analysis progress
 * DELETE /api/admin/normalize       — stop running analysis
 * GET    /api/admin/auto-norm-status — last auto-normalize run result
 */
class NormalizeHandler
{
    private const PROGRESS_FILE = '/tmp/iradio_normalize.json';
    private const LOCK_FILE     = '/tmp/iradio_normalize.lock';
    private const SCRIPT        = '/var/www/html/src/scripts/normalize_audio.php';

    public function start(): void
    {
        Auth::requireAuth();

        // Handle concurrent runs
        $progress = $this->readProgress();
        if ($progress && $progress['status'] === 'running') {
            if (file_exists(self::LOCK_FILE)) {
                $pid = (int) @file_get_contents(self::LOCK_FILE);
                if ($pid > 0 && file_exists("/proc/{$pid}")) {
                    $cmdline = @file_get_contents("/proc/{$pid}/cmdline");
                    $isAuto = $cmdline && str_contains($cmdline, '--auto');

                    if ($isAuto) {
                        // Auto-normalize running — stop it, manual takes priority
                        file_put_contents(self::LOCK_FILE . '.stop', '1');
                        for ($i = 0; $i < 20; $i++) {
                            usleep(500000);
                            if (!file_exists("/proc/{$pid}")) break;
                        }
                        @unlink(self::LOCK_FILE);
                        @unlink(self::LOCK_FILE . '.stop');
                    } else {
                        Response::json([
                            'message'  => 'Normalization already running',
                            'progress' => $progress,
                        ]);
                        return;
                    }
                }
            }
        }

        // Always disable auto-normalize when manual starts
        $db = Database::get();
        $row = $db->prepare("SELECT value FROM settings WHERE key = 'auto_normalize_enabled'");
        $row->execute();
        $autoWasOn = ($row->fetchColumn() === 'true');
        if ($autoWasOn) {
            $db->prepare("UPDATE settings SET value = 'false' WHERE key = 'auto_normalize_enabled'")
               ->execute();
        }

        // Launch background process
        $cmd = 'php ' . escapeshellarg(self::SCRIPT) . ' > /tmp/iradio_normalize.log 2>&1 &';
        exec($cmd);

        // Brief pause to let the script start and write initial progress
        usleep(500000);

        $response = [
            'message'  => 'Normalization started',
            'progress' => $this->readProgress(),
        ];
        if ($autoWasOn) {
            $response['auto_normalize_disabled'] = true;
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
                'message' => 'No normalization has been run yet',
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
        Auth::requireAuth();

        $progress = $this->readProgress();
        if (!$progress || $progress['status'] !== 'running') {
            Response::json(['message' => 'No normalization is running']);
            return;
        }

        // Signal the script to stop
        file_put_contents(self::LOCK_FILE . '.stop', '1');

        Response::json(['message' => 'Stop signal sent']);
    }

    /**
     * GET /api/admin/auto-norm-status — last auto-normalize run result
     */
    public function autoNormStatus(): void
    {
        Auth::requireAuth();

        $file = '/tmp/iradio_auto_norm_last.json';
        if (!file_exists($file)) {
            Response::json([
                'has_run' => false,
                'message' => 'Auto-normalize has not run yet',
            ]);
            return;
        }

        $data = @file_get_contents($file);
        $result = $data ? json_decode($data, true) : null;

        if (!$result) {
            Response::json([
                'has_run' => false,
                'message' => 'Auto-normalize has not run yet',
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
