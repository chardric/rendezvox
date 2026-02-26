<?php

declare(strict_types=1);

class UserCreateHandler
{
    private const INVITE_TOKEN_EXPIRY_SECONDS = 259200; // 72 hours

    public function handle(): void
    {
        Auth::requireRole('super_admin');
        $db    = Database::get();
        $input = json_decode(file_get_contents('php://input'), true);

        $username    = trim((string) ($input['username'] ?? ''));
        $email       = trim((string) ($input['email'] ?? ''));
        $role        = (string) ($input['role'] ?? 'dj');
        $displayName = null;

        if (array_key_exists('display_name', $input) && $input['display_name'] !== null) {
            $displayName = trim((string) $input['display_name']);
            if ($displayName === '') {
                $displayName = null;
            } elseif (strlen($displayName) > 255) {
                Response::error('Display name is too long (max 255 characters)', 422);
            }
        }

        if ($username === '' || $email === '') {
            Response::error('username and email are required', 400);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('Invalid email address', 400);
        }

        if (!in_array($role, ['super_admin', 'dj'], true)) {
            Response::error('Role must be super_admin or dj', 400);
        }

        // Check uniqueness
        $stmt = $db->prepare('SELECT id FROM users WHERE username = :u OR email = :e');
        $stmt->execute(['u' => $username, 'e' => $email]);
        if ($stmt->fetch()) {
            Response::error('Username or email already exists', 409);
        }

        // Check if SMTP is configured
        $smtpConfigured = $this->isSmtpConfigured($db);

        if ($smtpConfigured) {
            // SMTP available: create inactive user with placeholder hash, send invite
            $placeholderHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT);

            $stmt = $db->prepare('
                INSERT INTO users (username, email, display_name, password_hash, role, is_active)
                VALUES (:username, :email, :display_name, :password_hash, :role, FALSE)
                RETURNING id
            ');
            $stmt->execute([
                'username'      => $username,
                'email'         => $email,
                'display_name'  => $displayName,
                'password_hash' => $placeholderHash,
                'role'          => $role,
            ]);
            $id = (int) $stmt->fetchColumn();

            // Generate activation token (same mechanism as password reset)
            $rawToken  = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $rawToken);
            $expiresAt = date('Y-m-d H:i:s', time() + self::INVITE_TOKEN_EXPIRY_SECONDS);
            $ip        = Request::clientIp();

            $db->prepare('
                INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, created_ip)
                VALUES (:user_id, :token_hash, :expires_at, :ip::inet)
            ')->execute([
                'user_id'    => $id,
                'token_hash' => $tokenHash,
                'expires_at' => $expiresAt,
                'ip'         => $ip,
            ]);

            // Build activation URL
            $scheme      = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host        = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $activateUrl = "{$scheme}://{$host}/admin/activate-account?token={$rawToken}";

            // Send invite email
            try {
                $mailer = SmtpMailer::fromSettings();

                $nameStmt = $db->prepare("SELECT value FROM settings WHERE key = 'station_name'");
                $nameStmt->execute();
                $stationName = $nameStmt->fetchColumn() ?: 'RendezVox';

                $logoUrl = "{$scheme}://{$host}/api/logo";
                $html = $this->buildInviteEmail($stationName, $activateUrl, $displayName ?: $username, $logoUrl);
                $mailer->send($email, "Welcome to {$stationName}! Activate Your Account", $html);
            } catch (Throwable $e) {
                error_log('UserCreate invite email failed: ' . $e->getMessage());
            }

            Response::json([
                'message'      => 'User created. Activation email sent.',
                'invite_sent'  => true,
                'user'         => [
                    'id'           => $id,
                    'username'     => $username,
                    'email'        => $email,
                    'display_name' => $displayName,
                    'role'         => $role,
                    'is_active'    => false,
                ],
            ], 201);
        } else {
            // No SMTP: create active user with temporary password
            $tempPassword = $this->generateTempPassword();
            $hash = Auth::hashPassword($tempPassword);

            $stmt = $db->prepare('
                INSERT INTO users (username, email, display_name, password_hash, role, is_active)
                VALUES (:username, :email, :display_name, :password_hash, :role, TRUE)
                RETURNING id
            ');
            $stmt->execute([
                'username'      => $username,
                'email'         => $email,
                'display_name'  => $displayName,
                'password_hash' => $hash,
                'role'          => $role,
            ]);
            $id = (int) $stmt->fetchColumn();

            Response::json([
                'message'        => 'User created with temporary password (SMTP not configured).',
                'invite_sent'    => false,
                'temp_password'  => $tempPassword,
                'user'           => [
                    'id'           => $id,
                    'username'     => $username,
                    'email'        => $email,
                    'display_name' => $displayName,
                    'role'         => $role,
                    'is_active'    => true,
                ],
            ], 201);
        }
    }

    private function isSmtpConfigured(PDO $db): bool
    {
        $stmt = $db->prepare("SELECT value FROM settings WHERE key = 'smtp_host'");
        $stmt->execute();
        $host = trim((string) ($stmt->fetchColumn() ?: ''));
        return $host !== '';
    }

    private function generateTempPassword(): string
    {
        $chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%';
        $password = '';
        for ($i = 0; $i < 16; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $password;
    }

    private function buildInviteEmail(string $stationName, string $activateUrl, string $userName, string $logoUrl): string
    {
        $escapedUrl  = htmlspecialchars($activateUrl, ENT_QUOTES, 'UTF-8');
        $escapedName = htmlspecialchars($stationName, ENT_QUOTES, 'UTF-8');
        $escapedUser = htmlspecialchars($userName, ENT_QUOTES, 'UTF-8');
        $escapedLogo = htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8');
        $year = date('Y');

        return <<<HTML
        <div style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;max-width:520px;margin:0 auto;background:#ffffff">
          <div style="background:#1a1a2e;padding:28px 32px;text-align:center;border-radius:8px 8px 0 0">
            <img src="{$escapedLogo}" alt="{$escapedName}" style="width:64px;height:64px;border-radius:50%;object-fit:cover;margin-bottom:10px" />
            <div style="color:#ffffff;font-size:22px;font-weight:700;letter-spacing:.5px">{$escapedName}</div>
            <div style="color:#9ca3af;font-size:12px;margin-top:4px">Online FM Radio</div>
          </div>
          <div style="padding:32px;border:1px solid #e5e7eb;border-top:none;border-radius:0 0 8px 8px">
            <p style="color:#333;font-size:15px;line-height:1.6;margin:0 0 12px">Hi <strong>{$escapedUser}</strong>,</p>
            <p style="color:#333;font-size:15px;line-height:1.6;margin:0 0 24px">You've been invited to join the <strong>{$escapedName}</strong> admin panel. Click the button below to set your password and activate your account:</p>
            <div style="text-align:center;margin:0 0 24px">
              <a href="{$escapedUrl}" style="display:inline-block;background:#00c8a0;color:#fff;text-decoration:none;padding:14px 40px;border-radius:6px;font-weight:600;font-size:15px">Activate Account</a>
            </div>
            <p style="color:#666;font-size:13px;line-height:1.5;margin:0 0 16px">This link expires in <strong>72 hours</strong>. If you didn't expect this invitation, you can safely ignore this email.</p>
            <p style="color:#9ca3af;font-size:12px;word-break:break-all;margin:0">Or copy this URL: <a href="{$escapedUrl}" style="color:#00c8a0">{$escapedUrl}</a></p>
          </div>
          <div style="text-align:center;padding:16px 32px">
            <p style="color:#9ca3af;font-size:11px;margin:0">&copy; {$year} {$escapedName}. All rights reserved.</p>
          </div>
        </div>
        HTML;
    }
}
