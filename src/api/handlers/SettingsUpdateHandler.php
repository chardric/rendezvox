<?php

declare(strict_types=1);

class SettingsUpdateHandler
{
    public function handle(): void
    {
        $user = Auth::requireRole('super_admin');
        $db   = Database::get();
        $key  = $_GET['key'] ?? '';
        $body = json_decode(file_get_contents('php://input'), true);

        $readOnlyKeys = ['emergency_auto_activated'];
        if (in_array($key, $readOnlyKeys, true)) {
            Response::error('This setting is managed internally and cannot be modified', 403);
            return;
        }

        if ($key === '') {
            Response::error('Setting key is required', 400);
            return;
        }

        $value = $body['value'] ?? null;
        if ($value === null) {
            Response::error('value is required', 400);
            return;
        }

        $stmt = $db->prepare('
            UPDATE settings SET value = :value, updated_by = :user_id
            WHERE key = :key
        ');
        $stmt->execute([
            'value'   => (string) $value,
            'key'     => $key,
            'user_id' => $user['sub'],
        ]);

        if ($stmt->rowCount() === 0) {
            Response::error('Setting not found: ' . $key, 404);
            return;
        }

        Response::json(['message' => 'Setting updated', 'key' => $key, 'value' => (string) $value]);
    }
}
