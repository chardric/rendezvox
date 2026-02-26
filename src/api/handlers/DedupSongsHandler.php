<?php

declare(strict_types=1);

/**
 * POST   /api/admin/dedup-songs — start background song dedup
 * GET    /api/admin/dedup-songs — check dedup progress
 * DELETE /api/admin/dedup-songs — stop running dedup
 */
class DedupSongsHandler
{
    private const PROGRESS_FILE = '/tmp/rendezvox_dedup_songs.json';
    private const LOCK_FILE     = '/tmp/rendezvox_dedup_songs.lock';
    private const SCRIPT        = '/var/www/html/src/scripts/dedup_songs.php';

    public function start(): void
    {
        Auth::requireRole('super_admin');

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
                    'message'  => 'Song dedup already running',
                    'progress' => $this->readProgress(),
                ]);
                return;
            }
        }

        // Disable auto-dedup when manual starts
        $db = Database::get();
        $row = $db->prepare("SELECT value FROM settings WHERE key = 'auto_dedup_songs_enabled'");
        $row->execute();
        $autoWasOn = ($row->fetchColumn() === 'true');
        if ($autoWasOn) {
            $db->prepare("UPDATE settings SET value = 'false' WHERE key = 'auto_dedup_songs_enabled'")
               ->execute();
        }

        $cmd = 'php ' . escapeshellarg(self::SCRIPT) . ' > /tmp/rendezvox_dedup_songs.log 2>&1 &';
        exec($cmd);

        usleep(500000);

        $response = [
            'message'  => 'Song dedup started',
            'progress' => $this->readProgress(),
        ];
        if ($autoWasOn) {
            $response['auto_dedup_songs_disabled'] = true;
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
                'message' => 'No song dedup has been run yet',
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
            Response::json(['message' => 'No song dedup is running']);
            return;
        }

        file_put_contents(self::LOCK_FILE . '.stop', '1');
        Response::json(['message' => 'Stop signal sent']);
    }

    public function autoStatus(): void
    {
        Auth::requireAuth();

        $file = '/tmp/rendezvox_auto_dedup_songs_last.json';
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
        exec("pgrep -f 'dedup_songs\\.php' 2>/dev/null", $output);
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
