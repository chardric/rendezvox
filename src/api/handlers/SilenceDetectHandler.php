<?php

declare(strict_types=1);

/**
 * POST   /api/admin/silence-detect       — start background silence detection
 * GET    /api/admin/silence-detect       — check analysis progress
 * DELETE /api/admin/silence-detect       — stop running analysis
 * GET    /api/admin/auto-silence-status  — last auto-silence-detect run result
 */
class SilenceDetectHandler
{
    private const PROGRESS_FILE = '/tmp/rendezvox_silence_detect.json';
    private const LOCK_FILE     = '/tmp/rendezvox_silence_detect.lock';
    private const SCRIPT        = '/var/www/html/src/scripts/silence_detect.php';

    public function start(): void
    {
        Auth::requireAuth();

        $runningPid = $this->findRunningPid();
        if ($runningPid) {
            $cmdline = @file_get_contents("/proc/{$runningPid}/cmdline");
            $isAuto = $cmdline && str_contains($cmdline, '--auto');

            if ($isAuto) {
                file_put_contents(self::LOCK_FILE . '.stop', '1');
                for ($i = 0; $i < 20; $i++) {
                    usleep(500000);
                    if (!file_exists("/proc/{$runningPid}")) break;
                }
                @unlink(self::LOCK_FILE);
                @unlink(self::LOCK_FILE . '.stop');
            } else {
                Response::json([
                    'message'  => 'Silence detection already running',
                    'progress' => $this->readProgress(),
                ]);
                return;
            }
        }

        $db = Database::get();
        $row = $db->prepare("SELECT value FROM settings WHERE key = 'auto_silence_detect_enabled'");
        $row->execute();
        $autoWasOn = ($row->fetchColumn() === 'true');
        if ($autoWasOn) {
            $db->prepare("UPDATE settings SET value = 'false' WHERE key = 'auto_silence_detect_enabled'")
               ->execute();
        }

        $cmd = 'php ' . escapeshellarg(self::SCRIPT) . ' > /tmp/rendezvox_silence_detect.log 2>&1 &';
        exec($cmd);

        usleep(500000);

        $response = [
            'message'  => 'Silence detection started',
            'progress' => $this->readProgress(),
        ];
        if ($autoWasOn) {
            $response['auto_silence_disabled'] = true;
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
                'message' => 'No silence detection has been run yet',
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
            Response::json(['message' => 'No silence detection is running']);
            return;
        }

        file_put_contents(self::LOCK_FILE . '.stop', '1');

        Response::json(['message' => 'Stop signal sent']);
    }

    public function autoSilenceStatus(): void
    {
        Auth::requireAuth();

        $file = '/tmp/rendezvox_auto_silence_last.json';
        if (!file_exists($file)) {
            Response::json([
                'has_run' => false,
                'message' => 'Auto-silence-detect has not run yet',
            ]);
            return;
        }

        $data = @file_get_contents($file);
        $result = $data ? json_decode($data, true) : null;

        if (!$result) {
            Response::json([
                'has_run' => false,
                'message' => 'Auto-silence-detect has not run yet',
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
        exec("pgrep -f 'silence_detect\\.php' 2>/dev/null", $output);
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
