<?php

declare(strict_types=1);

class ActivateAccountHandler
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
            Response::error('Invalid or expired activation link.', 400);
        }

        // Check expiry
        if (strtotime($token['expires_at']) < time()) {
            $db->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE id = :id')
                ->execute(['id' => $token['id']]);
            Response::error('This activation link has expired. Please ask your administrator to resend the invitation.', 400);
        }

        // If user is already active, this token shouldn't be used for activation
        if ($token['is_active']) {
            Response::error('This account is already active. Try signing in or use forgot password.', 400);
        }

        // Validate password strength
        if (!Auth::isStrongPassword($password)) {
            Response::error('Password is too weak. Use at least 6 characters with a mix of uppercase, lowercase, numbers, and symbols.', 422);
        }

        // Set password and activate user
        $hash = Auth::hashPassword($password);
        $db->prepare('UPDATE users SET password_hash = :hash, is_active = TRUE WHERE id = :id')
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

        Response::json(['message' => 'Account activated. You can now sign in.']);
    }
}
