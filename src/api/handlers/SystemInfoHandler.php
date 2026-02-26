<?php

declare(strict_types=1);

class SystemInfoHandler
{
    public function handle(): void
    {
        // ── CPU ──
        $cpuLoad = sys_getloadavg() ?: [0, 0, 0];
        $cpuCores = 1;
        if (is_readable('/proc/cpuinfo')) {
            $cpuCores = max(1, (int) substr_count(file_get_contents('/proc/cpuinfo'), 'processor'));
        }

        // ── Memory (from /proc/meminfo) ──
        $memTotalMb = 0;
        $memUsedMb  = 0;
        $memPercent = 0.0;
        if (is_readable('/proc/meminfo')) {
            $meminfo = file_get_contents('/proc/meminfo');
            $total     = 0;
            $available = 0;
            if (preg_match('/MemTotal:\s+(\d+)\s+kB/', $meminfo, $m)) {
                $total = (int) $m[1];
            }
            if (preg_match('/MemAvailable:\s+(\d+)\s+kB/', $meminfo, $m)) {
                $available = (int) $m[1];
            }
            $memTotalMb = round($total / 1024, 1);
            $memUsedMb  = round(($total - $available) / 1024, 1);
            $memPercent = $total > 0 ? round(($total - $available) / $total * 100, 1) : 0.0;
        }

        // ── Disk ──
        $disk = DiskSpace::check();

        // ── Uptime ──
        $uptimeStr = null;
        if (is_readable('/proc/uptime')) {
            $raw = file_get_contents('/proc/uptime');
            $secs = (int) floatval($raw);
            $days = intdiv($secs, 86400);
            $hours = intdiv($secs % 86400, 3600);
            $mins = intdiv($secs % 3600, 60);
            $parts = [];
            if ($days > 0) $parts[] = $days . 'd';
            if ($hours > 0) $parts[] = $hours . 'h';
            $parts[] = $mins . 'm';
            $uptimeStr = implode(' ', $parts);
        }

        // ── Service health ──
        $services = $this->checkServices();

        // ── Software versions (only what's detectable from PHP container) ──
        $pgVersion = null;
        try {
            $db = Database::get();
            $stmt = $db->query('SHOW server_version');
            $pgVersion = $stmt->fetchColumn() ?: null;
        } catch (\Exception $e) {
            // DB unavailable
        }

        // ── OS info ──
        $osInfo = php_uname('s') . ' ' . php_uname('r');
        $arch = php_uname('m');

        $hostname = gethostname() ?: null;

        Response::json([
            'cpu_load'        => array_map(fn($v) => round($v, 2), $cpuLoad),
            'cpu_cores'       => $cpuCores,
            'memory_used_mb'  => $memUsedMb,
            'memory_total_mb' => $memTotalMb,
            'memory_percent'  => $memPercent,
            'disk_free_bytes' => $disk['free_bytes'],
            'disk_total_bytes'=> $disk['total_bytes'],
            'uptime'          => $uptimeStr,
            'services'        => $services,
            'php_version'     => PHP_VERSION,
            'pg_version'      => $pgVersion,
            'os'              => $osInfo,
            'arch'            => $arch,
            'hostname'        => $hostname,
        ]);
    }

    private function checkServices(): array
    {
        $services = [];
        $services['nginx'] = 'running';
        $services['php'] = 'running';

        $icecastHost = getenv('RENDEZVOX_ICECAST_HOST') ?: 'icecast';
        $icecastPort = (int) (getenv('RENDEZVOX_ICECAST_PORT') ?: 8000);

        $icecastUp = false;
        $fp = @fsockopen($icecastHost, $icecastPort, $errno, $errstr, 2);
        if ($fp) {
            $icecastUp = true;
            fclose($fp);
        }
        $services['icecast'] = $icecastUp ? 'running' : 'stopped';

        $services['liquidsoap'] = 'stopped';
        if ($icecastUp) {
            $ctx = stream_context_create(['http' => ['timeout' => 2]]);
            $json = @file_get_contents(
                "http://{$icecastHost}:{$icecastPort}/status-json.xsl",
                false,
                $ctx
            );
            if ($json !== false) {
                $data = json_decode($json, true);
                if (isset($data['icestats']['source'])) {
                    $services['liquidsoap'] = 'running';
                }
            }
        }

        return $services;
    }
}
