<?php

declare(strict_types=1);

class UserUpdateHandler
{
    public function handle(): void
    {
        $auth = Auth::requireRole('super_admin');
        $db   = Database::get();
        $id   = (int) ($_GET['id'] ?? 0);

        if ($id <= 0) {
            Response::error('Invalid user ID', 400);
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            Response::error('Request body is required', 400);
        }

        // Fetch current user
        $stmt = $db->prepare('SELECT id, username, email, display_name, role, is_active FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $user = $stmt->fetch();

        if (!$user) {
            Response::error('User not found', 404);
        }

        $username    = isset($input['username']) ? trim((string) $input['username']) : $user['username'];
        $email       = isset($input['email'])    ? trim((string) $input['email'])    : $user['email'];
        $role        = isset($input['role'])     ? (string) $input['role']           : $user['role'];
        $isActive    = isset($input['is_active']) ? (bool) $input['is_active']       : (bool) $user['is_active'];
        $displayName = $user['display_name'];
        if (array_key_exists('display_name', $input)) {
            $displayName = $input['display_name'];
            if ($displayName !== null) {
                $displayName = trim((string) $displayName);
                if ($displayName === '') {
                    $displayName = null;
                } elseif (strlen($displayName) > 255) {
                    Response::error('Display name is too long (max 255 characters)', 422);
                }
            }
        }

        if ($role !== '' && !in_array($role, ['super_admin', 'dj'], true)) {
            Response::error('Role must be super_admin or dj', 400);
        }

        $isSelf = (int) $auth['sub'] === $id;

        // Prevent deactivating yourself
        if ($isSelf && !$isActive) {
            Response::error('Cannot deactivate your own account', 400);
        }

        // Prevent removing last super_admin
        if ($user['role'] === 'super_admin' && $role !== 'super_admin') {
            $count = (int) $db->query("SELECT COUNT(*) FROM users WHERE role = 'super_admin' AND is_active = true")->fetchColumn();
            if ($count <= 1) {
                Response::error('Cannot remove the last super admin', 400);
            }
        }

        // Check uniqueness if username/email changed
        if ($username !== $user['username'] || $email !== $user['email']) {
            $stmt = $db->prepare('SELECT id FROM users WHERE (username = :u OR email = :e) AND id != :id');
            $stmt->execute(['u' => $username, 'e' => $email, 'id' => $id]);
            if ($stmt->fetch()) {
                Response::error('Username or email already exists', 409);
            }
        }

        // Build update
        $sets   = ['username = :username', 'email = :email', 'display_name = :display_name', 'role = :role', 'is_active = :is_active'];
        $params = [
            'username'     => $username,
            'email'        => $email,
            'display_name' => $displayName,
            'role'         => $role,
            'is_active'    => $isActive ? 'true' : 'false',
            'id'           => $id,
        ];

        // Password update (optional)
        if (!empty($input['password'])) {
            $password = (string) $input['password'];
            if (!Auth::isStrongPassword($password)) {
                Response::error('Password too weak — use 8+ characters with mixed case, numbers, or symbols', 400);
            }
            $sets[]               = 'password_hash = :password_hash';
            $params['password_hash'] = Auth::hashPassword($password);
        }

        $setClause = implode(', ', $sets);
        $db->prepare("UPDATE users SET {$setClause} WHERE id = :id")->execute($params);

        Response::json(['message' => 'User updated']);
    }
}
