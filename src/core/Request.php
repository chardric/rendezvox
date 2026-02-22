<?php

declare(strict_types=1);

class Request
{
    public static function clientIp(): string
    {
        // Nginx resolves real client IP into REMOTE_ADDR via $real_client_ip
        // (prefers CF-Connecting-IP for Cloudflare, falls back to TCP peer).
        // Never trust X-Forwarded-For/X-Real-IP — they're attacker-controllable.
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        return ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) ? $ip : '0.0.0.0';
    }
}
