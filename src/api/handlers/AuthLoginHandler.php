<?php

declare(strict_types=1);

class AuthLoginHandler
{
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
            SELECT id, username, email, password_hash, role, is_active, display_name, avatar_path
            FROM users WHERE username = :username
        ');
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();

        if (!$user || !Auth::verifyPassword($password, $user['password_hash'])) {
            Response::error('Invalid credentials', 401);
        }

        if (!$user['is_active']) {
            Response::error('Account is disabled', 403);
        }

        $ip = Request::clientIp();
        $db->prepare('UPDATE users SET last_login_at = NOW(), last_login_ip = :ip::inet WHERE id = :id')
            ->execute(['ip' => $ip, 'id' => $user['id']]);

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
