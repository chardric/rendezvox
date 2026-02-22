<?php

declare(strict_types=1);

class UserDeleteHandler
{
    public function handle(): void
    {
        $auth = Auth::requireRole('super_admin');
        $db   = Database::get();
        $id   = (int) ($_GET['id'] ?? 0);

        if ($id <= 0) {
            Response::error('Invalid user ID', 400);
        }

        // Prevent deleting yourself
        if ((int) $auth['sub'] === $id) {
            Response::error('Cannot delete your own account', 400);
        }

        // Check user exists and role
        $stmt = $db->prepare('SELECT role FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $user = $stmt->fetch();

        if (!$user) {
            Response::error('User not found', 404);
        }

        // Prevent deleting last super_admin
        if ($user['role'] === 'super_admin') {
            $count = (int) $db->query("SELECT COUNT(*) FROM users WHERE role = 'super_admin'")->fetchColumn();
            if ($count <= 1) {
                Response::error('Cannot delete the last super admin', 400);
            }
        }

        $db->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $id]);

        Response::json(['message' => 'User deleted']);
    }
}
