<?php

declare(strict_types=1);

class AuthLoginHandler
{
    private const MAX_FAILED_ATTEMPTS = 5;
    private const LOCKOUT_SECONDS     = 900; // 15 minutes

    public function handle(): void
    {
        $input = json_decode(file_get_contents('php://input'), true);

        if (!$input || empty($input['username']) || empty($input['password'])) {
            Response::error('Missing username or password', 422);
        }

        $username = trim((string) $input['username']);
        $password = (string) $input['password'];

        $db   = Database::get();
        $stmt = $db->prepare('
            SELECT id, username, email, password_hash, role, is_active,
                   display_name, avatar_path, failed_login_count, locked_until
            FROM users WHERE username = :username
        ');
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();

        // Check account lockout before credential verification
        if ($user && $user['locked_until'] && strtotime($user['locked_until']) > time()) {
            $remaining = (int) ceil((strtotime($user['locked_until']) - time()) / 60);
            Response::error("Account temporarily locked. Try again in {$remaining} minute(s).", 429);
        }

        if (!$user || !Auth::verifyPassword($password, $user['password_hash'])) {
            // Track failed attempt if user exists
            if ($user) {
                $newCount  = (int) $user['failed_login_count'] + 1;
                $lockUntil = $newCount >= self::MAX_FAILED_ATTEMPTS
                    ? date('Y-m-d H:i:s', time() + self::LOCKOUT_SECONDS)
                    : null;
                $db->prepare('UPDATE users SET failed_login_count = :count, locked_until = :lock WHERE id = :id')
                    ->execute(['count' => $newCount, 'lock' => $lockUntil, 'id' => $user['id']]);
            }
            Response::error('Invalid credentials', 401);
        }

        if (!$user['is_active']) {
            Response::error('Account is disabled', 403);
        }

        // Successful login — reset lockout counter
        $ip = Request::clientIp();
        $db->prepare('
            UPDATE users
            SET last_login_at = NOW(), last_login_ip = :ip::inet,
                failed_login_count = 0, locked_until = NULL
            WHERE id = :id
        ')->execute(['ip' => $ip, 'id' => $user['id']]);

        $token = Auth::createToken([
            'sub'      => (int) $user['id'],
            'username' => $user['username'],
            'role'     => $user['role'],
        ]);

        Response::json([
            'token' => $token,
            'user'  => [
                'id'           => (int) $user['id'],
                'username'     => $user['username'],
                'email'        => $user['email'],
                'role'         => $user['role'],
                'display_name' => $user['display_name'] ?? null,
                'avatar_path'  => $user['avatar_path'],
            ],
        ]);
    }
}
