<?php

declare(strict_types=1);

require_once __DIR__ . '/../../core/MetadataLookup.php';

class SongAiTagHandler
{
    public function handle(): void
    {
        $db = Database::get();
        $id = (int) ($_GET['id'] ?? 0);

        if ($id <= 0) {
            Response::error('Invalid song ID', 400);
            return;
        }

        // Load AI settings
        $stmt = $db->prepare("SELECT key, value FROM settings WHERE key IN ('ai_provider', 'gemini_api_key', 'ollama_url', 'ollama_model')");
        $stmt->execute();
        $aiSettings = [];
        while ($row = $stmt->fetch()) {
            $aiSettings[$row['key']] = trim($row['value']);
        }

        $provider = $aiSettings['ai_provider'] ?? 'gemini_ollama';
        if ($provider === 'none') {
            Response::error('AI tagging is disabled. Enable it in Settings → Tools.', 400);
            return;
        }

        $useGemini = in_array($provider, ['gemini', 'gemini_ollama']) && !empty($aiSettings['gemini_api_key']);
        $useOllama = in_array($provider, ['ollama', 'gemini_ollama']) && !empty($aiSettings['ollama_url']);

        if (!$useGemini && !$useOllama) {
            Response::error('No AI provider configured. Set Gemini API key or Ollama URL in Settings → Tools.', 400);
            return;
        }

        // Load song with artist/genre
        $stmt = $db->prepare('
            SELECT s.id, s.title, s.year, s.file_path, s.country_code,
                   a.name AS artist_name, c.name AS genre_name
            FROM songs s
            JOIN artists a ON a.id = s.artist_id
            JOIN categories c ON c.id = s.category_id
            WHERE s.id = :id
        ');
        $stmt->execute(['id' => $id]);
        $song = $stmt->fetch();

        if (!$song) {
            Response::error('Song not found', 404);
            return;
        }

        $lookup = new MetadataLookup();
        if ($useGemini) {
            $lookup->setGeminiApiKey($aiSettings['gemini_api_key']);
        }
        if ($useOllama) {
            $lookup->setOllamaUrl($aiSettings['ollama_url']);
            if (!empty($aiSettings['ollama_model'])) {
                $lookup->setOllamaModel($aiSettings['ollama_model']);
            }
        }

        $needs = [
            'genre'        => true,
            'year'         => true,
            'artist'       => true,
            'title'        => true,
            'album'        => true,
            'country_code' => true,
        ];

        // Try configured provider(s)
        $result = null;
        $source = null;

        if ($useGemini) {
            $result = $lookup->lookupByAI($song['artist_name'], $song['title'], $needs);
            if ($result && !isset($result['_error'])) {
                $source = 'gemini';
            }
        }

        if ((!$result || isset($result['_error'])) && $useOllama) {
            $result = $lookup->lookupByOllamaAI($song['artist_name'], $song['title'], $needs);
            if ($result) {
                $source = 'ollama';
            }
        }

        if (!$result || isset($result['_error'])) {
            $msg = isset($result['_message']) ? $result['_message'] : 'AI could not determine metadata for this song';
            Response::error($msg, 422);
            return;
        }

        Response::json([
            'suggestions' => $result,
            'source'      => $source,
            'current'     => [
                'title'        => $song['title'],
                'artist'       => $song['artist_name'],
                'genre'        => $song['genre_name'],
                'year'         => $song['year'] ? (int) $song['year'] : null,
                'country_code' => $song['country_code'] ?? null,
            ],
        ]);
    }
}
