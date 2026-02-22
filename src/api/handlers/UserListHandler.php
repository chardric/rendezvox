<?php

declare(strict_types=1);

class UserListHandler
{
    public function handle(): void
    {
        Auth::requireRole('super_admin');
        $db = Database::get();

        $stmt = $db->query('
            SELECT id, username, email, display_name, role, is_active, last_login_at, created_at
            FROM users
            ORDER BY id ASC
        ');

        $users = [];
        while ($row = $stmt->fetch()) {
            $users[] = [
                'id'            => (int) $row['id'],
                'username'      => $row['username'],
                'email'         => $row['email'],
                'display_name'  => $row['display_name'],
                'role'          => $row['role'],
                'is_active'     => (bool) $row['is_active'],
                'last_login_at' => $row['last_login_at'],
                'created_at'    => $row['created_at'],
            ];
        }

        Response::json(['users' => $users]);
    }
}
