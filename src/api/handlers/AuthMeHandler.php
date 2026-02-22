<?php

declare(strict_types=1);

class AuthMeHandler
{
    public function handle(): void
    {
        $auth = Auth::requireAuth();

        $db   = Database::get();
        $stmt = $db->prepare('
            SELECT id, username, email, role, is_active, display_name, last_login_at, last_login_ip, created_at, avatar_path
            FROM users WHERE id = :id
        ');
        $stmt->execute(['id' => $auth['sub']]);
        $user = $stmt->fetch();

        if (!$user || !$user['is_active']) {
            Response::error('User not found or disabled', 401);
        }

        Response::json([
            'user' => [
                'id'             => (int) $user['id'],
                'username'       => $user['username'],
                'email'          => $user['email'],
                'role'           => $user['role'],
                'display_name'   => $user['display_name'],
                'last_login_at'  => $user['last_login_at'],
                'last_login_ip'  => $user['last_login_ip'],
                'created_at'     => $user['created_at'],
                'avatar_path'    => $user['avatar_path'],
            ],
        ]);
    }
}
