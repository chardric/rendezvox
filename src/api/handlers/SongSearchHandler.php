<?php

declare(strict_types=1);

/**
 * GET /api/search-song?title=...&artist=...
 * Public endpoint (no auth) — used by request form autocomplete.
 */
class SongSearchHandler
{
    public function handle(): void
    {
        $title  = trim($_GET['title'] ?? '');
        $artist = trim($_GET['artist'] ?? '');

        if (mb_strlen($title) > 255 || mb_strlen($artist) > 255) {
            Response::json(['error' => 'Search query too long (max 255 characters)'], 400);
            return;
        }

        if (mb_strlen($title) < 2 && mb_strlen($artist) < 2) {
            Response::json(['error' => 'Enter at least 2 characters for title or artist'], 400);
            return;
        }

        $db       = Database::get();
        $resolver = new SongResolver($db);
        $result   = $resolver->resolve($title, $artist);

        Response::json($result);
    }
}
