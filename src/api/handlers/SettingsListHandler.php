<?php

declare(strict_types=1);

class SettingsListHandler
{
    public function handle(): void
    {
        Auth::requireRole('super_admin');
        $db = Database::get();

        $stmt = $db->query('
            SELECT key, value, type, description, updated_at
            FROM settings
            ORDER BY key ASC
        ');

        $settings = [];
        while ($row = $stmt->fetch()) {
            $settings[] = [
                'key'         => $row['key'],
                'value'       => $row['value'],
                'type'        => $row['type'],
                'description' => $row['description'],
                'updated_at'  => $row['updated_at'],
            ];
        }

        Response::json(['settings' => $settings]);
    }
}
