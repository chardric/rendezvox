<?php

declare(strict_types=1);

class ResetPasswordHandler
{
    public function handle(): void
    {
        $input    = json_decode(file_get_contents('php://input'), true);
        $rawToken = trim((string) ($input['token'] ?? ''));
        $password = (string) ($input['password'] ?? '');

        if ($rawToken === '' || $password === '') {
            Response::error('Token and password are required', 400);
        }

        // Hash token and look up
        $tokenHash = hash('sha256', $rawToken);
        $db        = Database::get();

        $stmt = $db->prepare('
            SELECT prt.id, prt.user_id, prt.expires_at, u.is_active
            FROM password_reset_tokens prt
            JOIN users u ON u.id = prt.user_id
            WHERE prt.token_hash = :hash AND prt.used_at IS NULL
        ');
        $stmt->execute(['hash' => $tokenHash]);
        $token = $stmt->fetch();

        if (!$token) {
            Response::error('Invalid or expired reset link. Please request a new one.', 400);
        }

        // Check expiry
        if (strtotime($token['expires_at']) < time()) {
            // Mark as used so it can't be retried
            $db->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE id = :id')
                ->execute(['id' => $token['id']]);
            Response::error('This reset link has expired. Please request a new one.', 400);
        }

        // Check user is still active
        if (!$token['is_active']) {
            Response::error('This account has been disabled.', 403);
        }

        // Validate password strength
        if (!Auth::isStrongPassword($password)) {
            Response::error('Password is too weak. Use at least 6 characters with a mix of uppercase, lowercase, numbers, and symbols.', 422);
        }

        // Update password
        $hash = Auth::hashPassword($password);
        $db->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')
            ->execute(['hash' => $hash, 'id' => $token['user_id']]);

        // Mark token as used
        $db->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE id = :id')
            ->execute(['id' => $token['id']]);

        // Invalidate all remaining tokens for this user
        $db->prepare('
            UPDATE password_reset_tokens
            SET used_at = NOW()
            WHERE user_id = :user_id AND used_at IS NULL
        ')->execute(['user_id' => $token['user_id']]);

        Response::json(['message' => 'Password has been reset. You can now sign in.']);
    }
}
