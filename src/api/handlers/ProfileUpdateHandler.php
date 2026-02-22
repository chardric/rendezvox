<?php

declare(strict_types=1);

class ProfileUpdateHandler
{
    public function handle(): void
    {
        $auth  = Auth::requireAuth();
        $input = json_decode(file_get_contents('php://input'), true);

        if (!$input) {
            Response::error('Request body is required', 422);
        }

        $db     = Database::get();
        $userId = $auth['sub'];

        $sets   = [];
        $params = ['id' => $userId];

        // Display name
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
            $sets[]                = 'display_name = :display_name';
            $params['display_name'] = $displayName;
        }

        // Email
        if (array_key_exists('email', $input)) {
            $email = trim((string) ($input['email'] ?? ''));
            if ($email === '') {
                Response::error('Email cannot be empty', 422);
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                Response::error('Invalid email address', 422);
            }

            // Check uniqueness (exclude self)
            $stmt = $db->prepare('SELECT id FROM users WHERE email = :email AND id != :uid');
            $stmt->execute(['email' => $email, 'uid' => $userId]);
            if ($stmt->fetch()) {
                Response::error('Email is already in use by another account', 409);
            }

            $sets[]          = 'email = :email';
            $params['email'] = $email;
        }

        if (empty($sets)) {
            Response::error('No fields to update', 422);
        }

        $setClause = implode(', ', $sets);
        $db->prepare("UPDATE users SET {$setClause} WHERE id = :id")->execute($params);

        // Fetch updated values
        $stmt = $db->prepare('SELECT display_name, email FROM users WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();

        Response::json([
            'display_name' => $user['display_name'],
            'email'        => $user['email'],
        ]);
    }
}
