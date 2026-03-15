<?php

declare(strict_types=1);

/**
 * GET /api/recaps       — get latest recap (public)
 * GET /api/admin/recaps — list all recaps (admin)
 */
class RecapHandler
{
    /** Public: get the most recent recap */
    public function latest(): void
    {
        $db = Database::get();

        $stmt = $db->prepare("SELECT value FROM settings WHERE key = 'recap_enabled'");
        $stmt->execute();
        $row = $stmt->fetch();
        if (!$row || $row['value'] !== 'true') {
            Response::json(['enabled' => false, 'recap' => null]);
            return;
        }

        $recapStmt = $db->query("
            SELECT id, recap_date, recap_type, title, body, created_at
            FROM show_recaps
            ORDER BY recap_date DESC, created_at DESC
            LIMIT 1
        ");
        $recap = $recapStmt->fetch(\PDO::FETCH_ASSOC);

        Response::json([
            'enabled' => true,
            'recap'   => $recap ?: null,
        ]);
    }

    /** Admin: list all recaps with pagination */
    public function list(): void
    {
        Auth::requireAuth();

        $db    = Database::get();
        $page  = max(1, (int) ($_GET['page'] ?? 1));
        $limit = min(50, max(10, (int) ($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $countStmt = $db->query("SELECT COUNT(*) FROM show_recaps");
        $total = (int) $countStmt->fetchColumn();

        $stmt = $db->prepare("
            SELECT id, recap_date, recap_type, title, body, generated_by, created_at
            FROM show_recaps
            ORDER BY recap_date DESC, created_at DESC
            LIMIT :lim OFFSET :off
        ");
        $stmt->bindValue('lim', $limit, \PDO::PARAM_INT);
        $stmt->bindValue('off', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        $recaps = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        Response::json([
            'recaps' => $recaps,
            'total'  => $total,
            'page'   => $page,
            'pages'  => (int) ceil($total / $limit),
        ]);
    }
}
