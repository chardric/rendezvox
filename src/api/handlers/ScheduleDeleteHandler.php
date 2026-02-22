<?php

declare(strict_types=1);

class ScheduleDeleteHandler
{
    public function handle(): void
    {
        $db = Database::get();
        $id = (int) ($_GET['id'] ?? 0);

        if ($id <= 0) {
            Response::error('Invalid schedule ID', 400);
            return;
        }

        $stmt = $db->prepare('DELETE FROM schedules WHERE id = :id');
        $stmt->execute(['id' => $id]);

        if ($stmt->rowCount() === 0) {
            Response::error('Schedule not found', 404);
            return;
        }

        Response::json(['message' => 'Schedule deleted']);
    }
}
