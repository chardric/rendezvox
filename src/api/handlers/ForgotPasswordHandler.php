<?php

declare(strict_types=1);

class ForgotPasswordHandler
{
    private const TOKEN_EXPIRY_SECONDS = 3600; // 1 hour
    private const MAX_REQUESTS_PER_IP  = 5;
    private const RATE_WINDOW_SECONDS  = 3600; // 1 hour

    public function handle(): void
    {
        $input = json_decode(file_get_contents('php://input'), true);
        $email = trim((string) ($input['email'] ?? ''));

        // Always return the same message to prevent email enumeration
        $safeMessage = 'If that email exists, a reset link has been sent.';

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::json(['message' => $safeMessage]);
        }

        $db = Database::get();
        $ip = Request::clientIp();

        // Rate limit: max N requests per IP per hour (DB-level)
        $stmt = $db->prepare('
            SELECT COUNT(*) FROM password_reset_tokens
            WHERE created_ip = :ip::inet
              AND created_at > NOW() - INTERVAL :window
        ');
        $stmt->execute([
            'ip'     => $ip,
            'window' => self::RATE_WINDOW_SECONDS . ' seconds',
        ]);
        if ((int) $stmt->fetchColumn() >= self::MAX_REQUESTS_PER_IP) {
            // Silent — don't reveal rate limiting to attacker
            Response::json(['message' => $safeMessage]);
        }

        // Look up active user by email
        $stmt = $db->prepare('
            SELECT id, email FROM users
            WHERE email = :email AND is_active = TRUE
        ');
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if (!$user) {
            // No user found — return same message (no enumeration)
            Response::json(['message' => $safeMessage]);
        }

        // Invalidate prior unused tokens for this user
        $db->prepare('
            UPDATE password_reset_tokens
            SET used_at = NOW()
            WHERE user_id = :user_id AND used_at IS NULL
        ')->execute(['user_id' => $user['id']]);

        // Generate secure token
        $rawToken  = bin2hex(random_bytes(32)); // 256-bit
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = date('Y-m-d H:i:s', time() + self::TOKEN_EXPIRY_SECONDS);

        $db->prepare('
            INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, created_ip)
            VALUES (:user_id, :token_hash, :expires_at, :ip::inet)
        ')->execute([
            'user_id'    => $user['id'],
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
            'ip'         => $ip,
        ]);

        // Build reset URL
        $scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $resetUrl = "{$scheme}://{$host}/admin/reset-password?token={$rawToken}";

        // Send email (silently catch failures)
        try {
            $mailer = SmtpMailer::fromSettings();

            // Get station name
            $nameStmt = $db->prepare("SELECT value FROM settings WHERE key = 'station_name'");
            $nameStmt->execute();
            $stationName = $nameStmt->fetchColumn() ?: 'RendezVox';

            $html = $this->buildEmail($stationName, $resetUrl);
            $mailer->send($user['email'], "{$stationName} — Password Reset", $html);
        } catch (Throwable $e) {
            // Log but don't reveal to user
            error_log('ForgotPassword email failed: ' . $e->getMessage());
        }

        Response::json(['message' => $safeMessage]);
    }

    private function buildEmail(string $stationName, string $resetUrl): string
    {
        $escapedUrl  = htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8');
        $escapedName = htmlspecialchars($stationName, ENT_QUOTES, 'UTF-8');

        return <<<HTML
        <div style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;max-width:480px;margin:0 auto;padding:32px">
          <h2 style="color:#6c63ff;margin:0 0 16px">{$escapedName}</h2>
          <p style="color:#333;line-height:1.6">You requested a password reset for your <strong>{$escapedName}</strong> admin account.</p>
          <p style="color:#333;line-height:1.6">Click the button below to set a new password:</p>
          <div style="text-align:center;margin:28px 0">
            <a href="{$escapedUrl}" style="display:inline-block;background:#6c63ff;color:#fff;text-decoration:none;padding:12px 32px;border-radius:6px;font-weight:600;font-size:14px">Reset Password</a>
          </div>
          <p style="color:#666;font-size:13px;line-height:1.5">This link expires in <strong>1 hour</strong>. If you didn't request this, you can safely ignore this email.</p>
          <p style="color:#999;font-size:12px;word-break:break-all;margin-top:16px">Or copy this URL: {$escapedUrl}</p>
          <hr style="border:none;border-top:1px solid #eee;margin:24px 0">
          <p style="color:#999;font-size:12px">Sent from {$escapedName} Admin</p>
        </div>
        HTML;
    }
}
