<?php

declare(strict_types=1);

class PasswordChangeHandler
{
    public function handle(): void
    {
        $auth  = Auth::requireAuth();
        $db    = Database::get();
        $input = json_decode(file_get_contents('php://input'), true);

        $currentPassword = (string) ($input['current_password'] ?? '');
        $newPassword     = (string) ($input['new_password'] ?? '');

        if ($currentPassword === '' || $newPassword === '') {
            Response::error('current_password and new_password are required', 400);
        }

        if (!Auth::isStrongPassword($newPassword)) {
            Response::error('Password too weak — use 8+ characters with mixed case, numbers, or symbols', 400);
        }

        // Verify current password
        $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = :id');
        $stmt->execute(['id' => $auth['sub']]);
        $user = $stmt->fetch();

        if (!$user || !Auth::verifyPassword($currentPassword, $user['password_hash'])) {
            Response::error('Current password is incorrect', 403);
        }

        // Update password
        $hash = Auth::hashPassword($newPassword);
        $db->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')
            ->execute(['hash' => $hash, 'id' => $auth['sub']]);

        Response::json(['message' => 'Password changed successfully']);
    }
}
