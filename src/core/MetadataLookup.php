<?php

declare(strict_types=1);

/**
 * MetadataLookup — Centralized external API lookup for audio metadata.
 *
 * Consolidates AcoustID, MusicBrainz, Cover Art Archive, and TheAudioDB
 * lookups into a single class with built-in rate limiting.
 *
 * Usage:
 *   $lookup = new MetadataLookup();
 *   $lookup->setAcoustIdKey('your-key');
 *   $lookup->setTheAudioDbKey('2');  // '2' = free tier
 *   $result = $lookup->lookupByFingerprint('/path/to/song.mp3');
 */
class MetadataLookup
{
    private string $acoustIdKey = '';
    private string $theAudioDbKey = '';
    private string $geminiApiKey = '';
    private string $ollamaUrl = '';
    private string $ollamaModel = 'qwen2.5:3b';

    /** @var array<string, float> Last request timestamps per API domain */
    private array $lastRequest = [];

    /** Stream context for HTTP requests */
    private $httpContext;

    // ── Canonical genre map (single source of truth) ─────────
    private const GENRE_MAP = [
        'country' => 'Country', 'country pop' => 'Country', 'country rock' => 'Country',
        'contemporary country' => 'Country', 'outlaw country' => 'Country',
        'alt-country' => 'Country', 'americana' => 'Country', 'bluegrass' => 'Country',
        'rock' => 'Rock', 'soft rock' => 'Rock', 'hard rock' => 'Rock',
        'classic rock' => 'Rock', 'progressive rock' => 'Rock', 'pop rock' => 'Pop Rock',
        'alternative rock' => 'Rock', 'indie rock' => 'Rock',
        'pop' => 'Pop', 'adult contemporary' => 'Pop', 'singer-songwriter' => 'Pop',
        'jazz' => 'Jazz', 'swing' => 'Jazz', 'soul' => 'Soul', 'r&b' => 'R&B',
        'funk' => 'Funk', 'blues' => 'Blues', 'folk' => 'Folk',
        'electronic' => 'Electronic', 'dance' => 'Electronic', 'disco' => 'Disco',
        'hip hop' => 'Hip Hop', 'rap' => 'Hip Hop',
        'classical' => 'Classical', 'reggae' => 'Reggae', 'latin' => 'Latin',
        'metal' => 'Metal', 'punk' => 'Punk', 'gospel' => 'Gospel',
        'christian' => 'Christian', 'new age' => 'New Age', 'world' => 'World',
        'easy listening' => 'Easy Listening',
    ];

    public function __construct()
    {
        $ua = 'RendezVox/1.0 (metadata-lookup)';
        $this->httpContext = stream_context_create(['http' => [
            'header'  => "User-Agent: {$ua}\r\nAccept: application/json\r\n",
            'timeout' => 10,
        ]]);
    }

    public function setAcoustIdKey(string $key): void
    {
        $this->acoustIdKey = trim($key);
    }

    public function setTheAudioDbKey(string $key): void
    {
        $this->theAudioDbKey = trim($key);
    }

    public function setGeminiApiKey(string $key): void
    {
        $this->geminiApiKey = trim($key);
    }

    public function setOllamaUrl(string $url): void
    {
        $this->ollamaUrl = rtrim(trim($url), '/');
    }

    public function setOllamaModel(string $model): void
    {
        $model = trim($model);
        if ($model !== '') {
            $this->ollamaModel = $model;
        }
    }

    // ── Genre mapping (static, usable without instance) ──────

    /**
     * Map a raw genre string to a canonical display name.
     * Returns null if no match is found.
     */
    public static function mapGenre(string $raw): ?string
    {
        $lower = strtolower($raw);
        if (isset(self::GENRE_MAP[$lower])) {
            return self::GENRE_MAP[$lower];
        }
        foreach (self::GENRE_MAP as $key => $display) {
            if (str_contains($lower, $key)) {
                return $display;
            }
        }
        return null;
    }

    // ── AcoustID fingerprint lookup ──────────────────────────

    /**
     * Fingerprint an audio file via fpcalc and look up metadata from AcoustID.
     *
     * @return array{title?: string, artist?: string, album?: string, recording_id?: string, score?: float}|null
     */
    public function lookupByFingerprint(string $filePath): ?array
    {
        if ($this->acoustIdKey === '' || !file_exists($filePath)) {
            return null;
        }

        $cmd = 'fpcalc -json ' . escapeshellarg($filePath) . ' 2>/dev/null';
        $output = shell_exec($cmd);
        if (!$output) {
            return null;
        }

        $fp = json_decode($output, true);
        if (!$fp || empty($fp['fingerprint']) || empty($fp['duration'])) {
            return null;
        }

        $this->rateLimit('acoustid', 1.0);

        $params = http_build_query([
            'client'      => $this->acoustIdKey,
            'fingerprint' => $fp['fingerprint'],
            'duration'    => (int) $fp['duration'],
            'meta'        => 'recordings releasegroups',
        ]);
        $url  = "https://api.acoustid.org/v2/lookup?" . $params;
        $resp = @file_get_contents($url, false, $this->httpContext);
        if (!$resp) {
            return null;
        }

        $data    = json_decode($resp, true);
        $results = $data['results'] ?? [];
        if (empty($results)) {
            return null;
        }

        $best  = $results[0];
        $score = (float) ($best['score'] ?? 0);
        if ($score < 0.85) {
            return null;
        }

        $recordings = $best['recordings'] ?? [];
        if (empty($recordings)) {
            return null;
        }

        $rec    = $recordings[0];
        $result = ['score' => $score];

        if (!empty($rec['id'])) {
            $result['recording_id'] = $rec['id'];
        }
        if (!empty($rec['title'])) {
            $result['title'] = trim($rec['title']);
        }

        $artists = $rec['artists'] ?? [];
        if (!empty($artists)) {
            $result['artist'] = trim($artists[0]['name'] ?? '');
        }

        // Album from release groups
        $releaseGroups = $rec['releasegroups'] ?? [];
        foreach ($releaseGroups as $rg) {
            $type = $rg['type'] ?? '';
            if (!empty($rg['title']) && empty($result['album'])) {
                if (strtolower($type) === 'album') {
                    $result['album'] = trim($rg['title']);
                }
            }
        }
        if (empty($result['album']) && !empty($releaseGroups[0]['title'])) {
            $result['album'] = trim($releaseGroups[0]['title']);
        }

        return !empty($result) ? $result : null;
    }

    // ── MusicBrainz: genre lookup by artist name ─────────────

    /**
     * Look up genre for an artist via MusicBrainz.
     * Returns a mapped genre display name or null.
     */
    /**
     * Look up genre and country by artist name via MusicBrainz.
     * Returns ['genre' => ?string, 'country_code' => ?string].
     */
    public function lookupArtistMeta(string $artistName): array
    {
        $result = ['genre' => null, 'country_code' => null];

        $query = urlencode($artistName);
        $url   = "https://musicbrainz.org/ws/2/artist/?query=artist:" . $query . "&limit=1&fmt=json";

        $this->rateLimit('musicbrainz', 1.0);
        $resp = @file_get_contents($url, false, $this->httpContext);
        if (!$resp) {
            return $result;
        }

        $data    = json_decode($resp, true);
        $artists = $data['artists'] ?? [];
        if (empty($artists)) {
            return $result;
        }

        $mbid  = $artists[0]['id'] ?? null;
        $score = (int) ($artists[0]['score'] ?? 0);
        if (!$mbid || $score < 80) {
            return $result;
        }

        // Extract country from search result (area.iso-3166-1-codes)
        $areaCodes = $artists[0]['area']['iso-3166-1-codes'] ?? [];
        if (!empty($areaCodes)) {
            $result['country_code'] = strtoupper($areaCodes[0]);
        }

        $this->rateLimit('musicbrainz', 1.0);
        $url2  = "https://musicbrainz.org/ws/2/artist/{$mbid}?inc=genres+tags&fmt=json";
        $resp2 = @file_get_contents($url2, false, $this->httpContext);
        if (!$resp2) {
            return $result;
        }

        $data2 = json_decode($resp2, true);

        // Extract country from detail response if not already found
        if (!$result['country_code']) {
            $areaCodes2 = $data2['area']['iso-3166-1-codes'] ?? [];
            if (!empty($areaCodes2)) {
                $result['country_code'] = strtoupper($areaCodes2[0]);
            }
        }

        $allEntries = array_merge($data2['genres'] ?? [], $data2['tags'] ?? []);
        usort($allEntries, fn($a, $b) => ($b['count'] ?? 0) - ($a['count'] ?? 0));

        foreach ($allEntries as $entry) {
            $mapped = self::mapGenre($entry['name']);
            if ($mapped) {
                $result['genre'] = $mapped;
                break;
            }
        }

        return $result;
    }

    public function lookupGenreByArtist(string $artistName): ?string
    {
        return $this->lookupArtistMeta($artistName)['genre'];
    }

    // ── MusicBrainz: recording lookup for year, genre, release ID ──

    /**
     * Look up recording by artist+title via MusicBrainz.
     *
     * @return array{album?: string, year?: int, genre?: string, release_id?: string}|null
     */
    public function lookupByArtistTitle(string $artist, string $title): ?array
    {
        $query = urlencode('recording:"' . $title . '" AND artist:"' . $artist . '"');
        $url   = "https://musicbrainz.org/ws/2/recording/?query=" . $query . "&limit=1&fmt=json";

        $this->rateLimit('musicbrainz', 1.0);
        $resp = @file_get_contents($url, false, $this->httpContext);
        if (!$resp) {
            return null;
        }

        $data       = json_decode($resp, true);
        $recordings = $data['recordings'] ?? [];
        if (empty($recordings)) {
            return null;
        }

        $score = (int) ($recordings[0]['score'] ?? 0);
        if ($score < 80) {
            return null;
        }

        $result   = [];
        $releases = $recordings[0]['releases'] ?? [];

        // Try to get genre from recording tags
        $recId = $recordings[0]['id'] ?? null;
        if ($recId) {
            $result['recording_id'] = $recId;
            $genre = $this->lookupRecordingGenre($recId);
            if ($genre) {
                $result['genre'] = $genre;
            }
        }

        if (empty($releases)) {
            return !empty($result) ? $result : null;
        }

        // Find best release: Single > EP > Album > first available
        $singleRelease = null;
        $epRelease     = null;
        $albumRelease  = null;

        foreach ($releases as $rel) {
            $group = strtolower($rel['release-group']['primary-type'] ?? '');
            if ($group === 'single' && !$singleRelease) {
                $singleRelease = $rel;
            } elseif ($group === 'ep' && !$epRelease) {
                $epRelease = $rel;
            } elseif ($group === 'album' && !$albumRelease) {
                $albumRelease = $rel;
            }
        }

        $bestRelease = $singleRelease ?? $epRelease ?? $albumRelease ?? $releases[0];
        $albumTitle  = trim($bestRelease['title']);
        $releaseId   = $bestRelease['id'] ?? null;
        $year        = null;
        $relDate     = $bestRelease['date'] ?? '';
        if ($relDate && preg_match('/\b(19|20)\d{2}\b/', $relDate, $m)) {
            $year = (int) $m[0];
        }

        if ($albumTitle) {
            $result['album'] = $albumTitle;
        }
        if ($year) {
            $result['year'] = $year;
        }
        if ($releaseId) {
            $result['release_id'] = $releaseId;
        }

        return !empty($result) ? $result : null;
    }

    /**
     * Look up genre tags for a MusicBrainz recording by MBID.
     */
    private function lookupRecordingGenre(string $mbid): ?string
    {
        $url = "https://musicbrainz.org/ws/2/recording/{$mbid}?inc=genres+tags&fmt=json";

        $this->rateLimit('musicbrainz', 1.0);
        $resp = @file_get_contents($url, false, $this->httpContext);
        if (!$resp) {
            return null;
        }

        $data       = json_decode($resp, true);
        $allEntries = array_merge($data['genres'] ?? [], $data['tags'] ?? []);
        usort($allEntries, fn($a, $b) => ($b['count'] ?? 0) - ($a['count'] ?? 0));

        foreach ($allEntries as $entry) {
            $mapped = self::mapGenre($entry['name']);
            if ($mapped) {
                return $mapped;
            }
        }
        return null;
    }

    // ── Cover art lookup ─────────────────────────────────────

    /**
     * Check if a file already has embedded cover art via ffprobe.
     */
    public static function hasCoverArt(string $filePath): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }

        $cmd = 'ffprobe -v quiet -select_streams v -show_entries stream=codec_type,disposition '
             . '-of json ' . escapeshellarg($filePath) . ' 2>/dev/null';
        $output = shell_exec($cmd);
        if (!$output) {
            return false;
        }

        $data    = json_decode($output, true);
        $streams = $data['streams'] ?? [];
        foreach ($streams as $stream) {
            if (($stream['codec_type'] ?? '') === 'video') {
                return true;
            }
        }
        return false;
    }

    /**
     * Fetch cover art image bytes.
     *
     * Fallback chain:
     * 1. Cover Art Archive via MusicBrainz release ID (prefers singles)
     * 2. TheAudioDB track/album thumbnail
     * 3. TheAudioDB artist photo
     * 4. App logo (icon-512x512.png)
     *
     * @return string|null Raw image bytes or null
     */
    public function lookupCoverArt(string $artist, string $title, ?string $releaseId = null): ?string
    {
        // 1. Cover Art Archive (if we have a release ID)
        if ($releaseId) {
            $imageData = $this->fetchCoverArtArchive($releaseId);
            if ($imageData) {
                return $imageData;
            }
        }

        // 2. TheAudioDB track/album thumbnail
        if ($this->theAudioDbKey !== '') {
            $imageData = $this->fetchTheAudioDbCover($artist, $title);
            if ($imageData) {
                return $imageData;
            }
        }

        // 3. TheAudioDB artist photo
        $imageData = $this->fetchArtistPhoto($artist);
        if ($imageData) {
            return $imageData;
        }

        // 4. App logo as final fallback
        return self::getDefaultCoverArt();
    }

    /**
     * Fetch cover art from Cover Art Archive.
     */
    private function fetchCoverArtArchive(string $releaseId): ?string
    {
        $this->rateLimit('coverartarchive', 1.0);

        $url = "https://coverartarchive.org/release/{$releaseId}/front-500";
        $ctx = stream_context_create(['http' => [
            'header'          => "User-Agent: RendezVox/1.0 (cover-art)\r\n",
            'timeout'         => 15,
            'follow_location' => true,
            'max_redirects'   => 3,
        ]]);

        $data = @file_get_contents($url, false, $ctx);
        if ($data && strlen($data) > 1000) {
            return $data;
        }
        return null;
    }

    /**
     * Fetch cover art from TheAudioDB.
     */
    private function fetchTheAudioDbCover(string $artist, string $title): ?string
    {
        $this->rateLimit('theaudiodb', 2.0);

        $url = 'https://theaudiodb.com/api/v1/json/' . urlencode($this->theAudioDbKey)
             . '/searchtrack.php?s=' . urlencode($artist) . '&t=' . urlencode($title);

        $resp = @file_get_contents($url, false, $this->httpContext);
        if (!$resp) {
            return null;
        }

        $data   = json_decode($resp, true);
        $tracks = $data['track'] ?? [];
        if (empty($tracks)) {
            return null;
        }

        $thumbUrl = $tracks[0]['strTrackThumb'] ?? '';
        if ($thumbUrl === '') {
            // Try album thumb as fallback
            $thumbUrl = $tracks[0]['strAlbumThumb'] ?? '';
        }
        if ($thumbUrl === '') {
            return null;
        }

        $ctx = stream_context_create(['http' => [
            'header'          => "User-Agent: RendezVox/1.0 (cover-art)\r\n",
            'timeout'         => 15,
            'follow_location' => true,
            'max_redirects'   => 3,
        ]]);

        $imageData = @file_get_contents($thumbUrl, false, $ctx);
        if ($imageData && strlen($imageData) > 1000) {
            return $imageData;
        }
        return null;
    }

    /**
     * Fetch artist photo from TheAudioDB as cover art fallback.
     */
    private function fetchArtistPhoto(string $artist): ?string
    {
        if ($this->theAudioDbKey === '' || $artist === '') {
            return null;
        }

        $this->rateLimit('theaudiodb', 2.0);

        $url = 'https://theaudiodb.com/api/v1/json/' . urlencode($this->theAudioDbKey)
             . '/search.php?s=' . urlencode($artist);

        $resp = @file_get_contents($url, false, $this->httpContext);
        if (!$resp) {
            return null;
        }

        $data    = json_decode($resp, true);
        $artists = $data['artists'] ?? [];
        if (empty($artists)) {
            return null;
        }

        $thumbUrl = $artists[0]['strArtistThumb'] ?? '';
        if ($thumbUrl === '') {
            return null;
        }

        $ctx = stream_context_create(['http' => [
            'header'          => "User-Agent: RendezVox/1.0 (cover-art)\r\n",
            'timeout'         => 15,
            'follow_location' => true,
            'max_redirects'   => 3,
        ]]);

        $imageData = @file_get_contents($thumbUrl, false, $ctx);
        if ($imageData && strlen($imageData) > 1000) {
            return $imageData;
        }
        return null;
    }

    /**
     * Get app logo as final cover art fallback.
     * Returns raw PNG bytes from the app icon.
     */
    public static function getDefaultCoverArt(): ?string
    {
        $logoPath = '/var/www/html/public/assets/icon-512x512.png';
        if (!file_exists($logoPath)) {
            return null;
        }
        $data = file_get_contents($logoPath);
        return ($data !== false && strlen($data) > 100) ? $data : null;
    }

    /**
     * Embed cover art into an audio file using ffmpeg.
     *
     * @param string $filePath Path to the audio file
     * @param string $imageData Raw image bytes (JPEG or PNG)
     * @return bool True if embedding succeeded
     */
    public static function embedCoverArt(string $filePath, string $imageData): bool
    {
        if (!file_exists($filePath) || strlen($imageData) < 100) {
            return false;
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $supported = ['mp3', 'flac', 'ogg', 'm4a', 'aac', 'opus'];
        if (!in_array($ext, $supported)) {
            return false;
        }

        // Detect image type from magic bytes
        $imgExt = 'jpg';
        if (substr($imageData, 0, 8) === "\x89PNG\r\n\x1a\n") {
            $imgExt = 'png';
        }

        $tmpImage = '/tmp/rendezvox_cover_' . md5($filePath) . '.' . $imgExt;
        $tmpAudio = '/tmp/rendezvox_coverembed_' . md5($filePath) . '.' . $ext;

        file_put_contents($tmpImage, $imageData);

        // Build ffmpeg command to embed cover art
        if ($ext === 'mp3') {
            // MP3: embed as ID3v2 attached picture
            $cmd = 'ffmpeg -y -i ' . escapeshellarg($filePath)
                 . ' -i ' . escapeshellarg($tmpImage)
                 . ' -map 0:a -map 1:0 -codec copy'
                 . ' -metadata:s:v title="Album cover" -metadata:s:v comment="Cover (front)"'
                 . ' -disposition:v attached_pic'
                 . ' ' . escapeshellarg($tmpAudio) . ' 2>/dev/null';
        } elseif ($ext === 'flac') {
            // FLAC: embed as metadata block picture
            $cmd = 'ffmpeg -y -i ' . escapeshellarg($filePath)
                 . ' -i ' . escapeshellarg($tmpImage)
                 . ' -map 0:a -map 1:0 -codec copy'
                 . ' -disposition:v attached_pic'
                 . ' ' . escapeshellarg($tmpAudio) . ' 2>/dev/null';
        } else {
            // M4A/AAC/OGG/OPUS: generic approach
            $cmd = 'ffmpeg -y -i ' . escapeshellarg($filePath)
                 . ' -i ' . escapeshellarg($tmpImage)
                 . ' -map 0:a -map 1:0 -codec copy'
                 . ' -disposition:v attached_pic'
                 . ' ' . escapeshellarg($tmpAudio) . ' 2>/dev/null';
        }

        exec($cmd, $output, $exitCode);

        @unlink($tmpImage);

        if ($exitCode === 0 && file_exists($tmpAudio) && filesize($tmpAudio) > 0) {
            rename($tmpAudio, $filePath);
            return true;
        }

        @unlink($tmpAudio);
        return false;
    }

    // ── TheAudioDB metadata lookup ─────────────────────────

    /**
     * Look up track metadata from TheAudioDB by artist + title.
     *
     * @return array{title?: string, artist?: string, genre?: string, year?: int, album?: string}|null
     */
    public function lookupTrackByTheAudioDb(string $artist, string $title): ?array
    {
        if ($this->theAudioDbKey === '' || $artist === '' || $title === '') {
            return null;
        }

        $this->rateLimit('theaudiodb', 2.0);

        $url = 'https://theaudiodb.com/api/v1/json/' . urlencode($this->theAudioDbKey)
             . '/searchtrack.php?s=' . urlencode($artist) . '&t=' . urlencode($title);

        $resp = @file_get_contents($url, false, $this->httpContext);
        if (!$resp) {
            return null;
        }

        $data   = json_decode($resp, true);
        $tracks = $data['track'] ?? [];
        if (empty($tracks)) {
            return null;
        }

        $track  = $tracks[0];
        $result = [];

        if (!empty($track['strTrack'])) {
            $result['title'] = trim($track['strTrack']);
        }
        if (!empty($track['strArtist'])) {
            $result['artist'] = trim($track['strArtist']);
        }
        if (!empty($track['strGenre'])) {
            $mapped = self::mapGenre($track['strGenre']);
            $result['genre'] = $mapped ?: ucfirst(strtolower(trim($track['strGenre'])));
        }
        if (!empty($track['intYearReleased']) && (int) $track['intYearReleased'] > 0) {
            $result['year'] = (int) $track['intYearReleased'];
        }
        if (!empty($track['strAlbum'])) {
            $result['album'] = trim($track['strAlbum']);
        }

        return !empty($result) ? $result : null;
    }

    // ── Tag clearing and writing ─────────────────────────────

    /**
     * Extract embedded cover art from an audio file.
     *
     * @return string|null Raw image bytes or null if no cover art
     */
    public static function extractCoverArt(string $filePath): ?string
    {
        if (!file_exists($filePath)) {
            return null;
        }

        if (!self::hasCoverArt($filePath)) {
            return null;
        }

        $tmpFile = '/tmp/rendezvox_extract_cover_' . md5($filePath . microtime()) . '.jpg';
        $cmd = 'ffmpeg -y -i ' . escapeshellarg($filePath)
             . ' -an -vcodec mjpeg -frames:v 1 ' . escapeshellarg($tmpFile)
             . ' 2>/dev/null';
        exec($cmd, $output, $exitCode);

        if ($exitCode === 0 && file_exists($tmpFile) && filesize($tmpFile) > 100) {
            $imageData = file_get_contents($tmpFile);
            @unlink($tmpFile);
            return $imageData;
        }

        @unlink($tmpFile);
        return null;
    }

    /**
     * Clear ALL existing metadata from an audio file and write fresh tags.
     *
     * Uses ffmpeg with -map_metadata -1 to strip all metadata + cover art,
     * then writes only the provided tags.
     *
     * @param string $filePath Absolute path to audio file
     * @param array  $tags     Associative array: title, artist, album, genre, date/year, track
     * @return bool True on success
     */
    public static function clearAndWriteTags(string $filePath, array $tags): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }

        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $supported = ['mp3', 'flac', 'ogg', 'wav', 'aac', 'm4a', 'opus'];
        if (!in_array($ext, $supported)) {
            return false;
        }

        $tmpFile = '/tmp/rendezvox_cleantag_' . md5($filePath . microtime()) . '.' . $ext;

        // Build ffmpeg command: strip all metadata, copy audio only, write fresh tags
        $cmd = 'ffmpeg -y -i ' . escapeshellarg($filePath)
             . ' -map 0:a -codec copy -map_metadata -1';

        // Add each tag as metadata
        foreach ($tags as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $key   = strtolower(trim($key));
            $value = trim((string) $value);
            $cmd  .= ' -metadata ' . escapeshellarg("{$key}={$value}");
        }

        $cmd .= ' ' . escapeshellarg($tmpFile) . ' 2>/dev/null';
        exec($cmd, $output, $exitCode);

        if ($exitCode === 0 && file_exists($tmpFile) && filesize($tmpFile) > 0) {
            rename($tmpFile, $filePath);
            return true;
        }

        @unlink($tmpFile);
        return false;
    }

    // ── Gemini AI metadata lookup ─────────────────────────

    /**
     * Check if a string looks like a filename artifact rather than real metadata.
     * Examples: "Unknown Artist", "01_track", "Track 3", "audio_file", etc.
     */
    public static function looksLikeFilenameArtifact(string $value): bool
    {
        $lower = strtolower(trim($value));
        if ($lower === '') {
            return true;
        }

        $artifacts = [
            'unknown artist', 'unknown', 'various artists', 'various',
            'untitled', 'no artist', 'n/a', 'none', 'track', 'audio',
            'recording', 'file', 'document', 'media',
        ];
        if (in_array($lower, $artifacts, true)) {
            return true;
        }

        // Patterns: "01_track", "track_03", "audio_001", pure numbers, timestamps
        if (preg_match('/^(\d+[_\-\s]*(track|audio|file|recording)|(track|audio|file|recording)[_\-\s]*\d+|\d{6,}|\d+)$/i', $lower)) {
            return true;
        }

        return false;
    }

    /**
     * Look up missing metadata using Google Gemini Flash AI.
     *
     * Only fills gaps — never overrides existing high-confidence data.
     *
     * @param string $artist  Current artist name
     * @param string $title   Current title
     * @param array  $needs   Flags: 'genre', 'year', 'artist', 'title', 'album' (true if missing)
     * @return array{genre?: string, year?: int, artist?: string, title?: string, album?: string}|null
     */
    public function lookupByAI(string $artist, string $title, array $needs): ?array
    {
        if ($this->geminiApiKey === '') {
            return null;
        }

        // Nothing to ask for
        $fields = array_filter($needs);
        if (empty($fields)) {
            return null;
        }

        // Build a dynamic prompt asking only for what's missing
        $parts = [];
        if (!empty($needs['genre'])) {
            $parts[] = '"genre": the primary music genre (e.g. Rock, Pop, Country, Jazz, R&B, Hip Hop, Electronic, Classical, Folk, Blues, Soul, Funk, Reggae, Latin, Metal, Punk, Gospel, Christian, Disco, Easy Listening, New Age, World, Pop Rock)';
        }
        if (!empty($needs['year'])) {
            $parts[] = '"year": the original release year as a number';
        }
        if (!empty($needs['artist'])) {
            $parts[] = '"artist": the correct artist/performer name';
        }
        if (!empty($needs['title'])) {
            $parts[] = '"title": the correct song title';
        }
        if (!empty($needs['album'])) {
            $parts[] = '"album": the album or single this song was released on';
        }
        if (!empty($needs['country_code'])) {
            $parts[] = '"country_code": the ISO 3166-1 alpha-2 country code of the artist\'s country of origin (e.g. US, GB, PH, KR, JP, AU, CA, DE, FR, BR)';
        }

        $fieldList = implode(",\n", $parts);

        $prompt = "You are a music metadata expert. Given this song information:\n"
                . "Artist: {$artist}\n"
                . "Title: {$title}\n\n"
                . "Return a JSON object with ONLY these fields (omit any you're unsure about):\n"
                . $fieldList . "\n\n"
                . "Rules:\n"
                . "- Only return fields you are confident about\n"
                . "- Genre must be a single primary genre, not a list\n"
                . "- Year must be between 1900 and " . date('Y') . "\n"
                . "- If you cannot determine a field with confidence, omit it entirely\n"
                . "- Return valid JSON only, no markdown or explanation";

        $this->rateLimit('gemini', 0.15); // 2.5 Flash free tier: 500 RPM

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key='
             . urlencode($this->geminiApiKey);

        $payload = json_encode([
            'contents' => [
                ['parts' => [['text' => $prompt]]]
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'temperature'      => 0.1,
                'maxOutputTokens'  => 256,
                'thinkingConfig'   => ['thinkingBudget' => 0],
            ],
        ]);

        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\nUser-Agent: RendezVox/1.0\r\n",
                'content'       => $payload,
                'timeout'       => 15,
                'ignore_errors' => true,
            ],
        ]);

        $resp = @file_get_contents($url, false, $ctx);
        if (!$resp) {
            return null;
        }

        $data = json_decode($resp, true);
        if (!$data) {
            return null;
        }

        // Check for API errors (rate limit, auth, etc.)
        if (isset($data['error'])) {
            $code = (int) ($data['error']['code'] ?? 0);
            if ($code === 429) {
                return ['_error' => 'rate_limited', '_message' => 'Gemini API rate limit exceeded. Free tier resets daily.'];
            }
            if ($code === 403) {
                return ['_error' => 'auth_failed', '_message' => 'Gemini API key is invalid or expired.'];
            }
            return ['_error' => 'api_error', '_message' => $data['error']['message'] ?? 'Unknown API error'];
        }

        // Extract text from Gemini response
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (!$text) {
            return null;
        }

        $parsed = json_decode($text, true);
        if (!is_array($parsed)) {
            return null;
        }

        $result = [];

        // Validate genre
        if (!empty($parsed['genre']) && is_string($parsed['genre'])) {
            $mapped = self::mapGenre($parsed['genre']);
            if ($mapped) {
                $result['genre'] = $mapped;
            } else {
                // Accept as-is if it's a reasonable genre name
                $clean = ucfirst(strtolower(trim($parsed['genre'])));
                if (strlen($clean) >= 2 && strlen($clean) <= 30) {
                    $result['genre'] = $clean;
                }
            }
        }

        // Validate year
        if (!empty($parsed['year'])) {
            $year = (int) $parsed['year'];
            if ($year >= 1900 && $year <= (int) date('Y')) {
                $result['year'] = $year;
            }
        }

        // Validate artist
        if (!empty($parsed['artist']) && is_string($parsed['artist'])) {
            $clean = trim($parsed['artist']);
            if (strlen($clean) >= 2 && !self::looksLikeFilenameArtifact($clean)) {
                $result['artist'] = $clean;
            }
        }

        // Validate title
        if (!empty($parsed['title']) && is_string($parsed['title'])) {
            $clean = trim($parsed['title']);
            if (strlen($clean) >= 2 && !self::looksLikeFilenameArtifact($clean)) {
                $result['title'] = $clean;
            }
        }

        // Validate album
        if (!empty($parsed['album']) && is_string($parsed['album'])) {
            $clean = trim($parsed['album']);
            if (strlen($clean) >= 2) {
                $result['album'] = $clean;
            }
        }

        // Validate country_code
        if (!empty($parsed['country_code']) && is_string($parsed['country_code'])) {
            $cc = strtoupper(trim($parsed['country_code']));
            if (preg_match('/^[A-Z]{2}$/', $cc)) {
                $result['country_code'] = $cc;
            }
        }

        return !empty($result) ? $result : null;
    }

    /**
     * Batch-verify metadata for multiple songs using Gemini AI.
     * Sends up to 20 songs per API call to minimize quota usage.
     *
     * @param array $songs Array of ['id'=>int, 'artist'=>string, 'title'=>string, 'genre'=>string, 'year'=>int|null, 'album'=>string|null]
     * @return array|null Map of song index => ['genre'=>..., 'year'=>..., 'album'=>...] with corrections, or null on failure
     */
    public function batchVerifyByAI(array $songs): ?array
    {
        if ($this->geminiApiKey === '' || empty($songs)) {
            return null;
        }

        // Build song list for the prompt
        $lines = [];
        foreach ($songs as $i => $s) {
            $n = $i + 1;
            $year = !empty($s['year']) ? (int) $s['year'] : 'unknown';
            $album = !empty($s['album']) ? $s['album'] : 'unknown';
            $lines[] = "{$n}. \"{$s['title']}\" by {$s['artist']} — genre: {$s['genre']}, year: {$year}, album: {$album}";
        }
        $songList = implode("\n", $lines);

        $prompt = "You are a music metadata expert. Below is a list of songs with their current metadata tags. "
                . "Some may be incorrectly tagged. For each song, verify the genre, year, and album.\n\n"
                . "Songs:\n{$songList}\n\n"
                . "Return a JSON array where each element corresponds to a song (same order). "
                . "For each song, include ONLY fields that need correction. If all fields are correct, return an empty object {}.\n\n"
                . "Format: [{\"genre\":\"correct genre\",\"year\":2001,\"album\":\"correct album\"}, {}, ...]\n\n"
                . "Rules:\n"
                . "- Only include fields that are WRONG and need correction\n"
                . "- Genre must be a single primary genre (e.g. Rock, Pop, Country, Jazz, R&B, Hip Hop, Electronic, Classical, Folk, Blues, Soul, Funk, Reggae, Latin, Metal, Punk, Gospel, Disco, Easy Listening, Pop Rock)\n"
                . "- Year must be between 1900 and " . date('Y') . "\n"
                . "- If a field is correct or you're unsure, omit it\n"
                . "- Return valid JSON array only, no markdown or explanation";

        $this->rateLimit('gemini', 0.15);

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key='
             . urlencode($this->geminiApiKey);

        $payload = json_encode([
            'contents' => [
                ['parts' => [['text' => $prompt]]]
            ],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'temperature'      => 0.1,
                'maxOutputTokens'  => 2048,
                'thinkingConfig'   => ['thinkingBudget' => 0],
            ],
        ]);

        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\nUser-Agent: RendezVox/1.0\r\n",
                'content'       => $payload,
                'timeout'       => 30,
                'ignore_errors' => true,
            ],
        ]);

        $resp = @file_get_contents($url, false, $ctx);
        if (!$resp) {
            return null;
        }

        $data = json_decode($resp, true);
        if (!$data) {
            return null;
        }

        if (isset($data['error'])) {
            $code = (int) ($data['error']['code'] ?? 0);
            if ($code === 429) {
                return ['_error' => 'rate_limited'];
            }
            return null;
        }

        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (!$text) {
            return null;
        }

        $parsed = json_decode($text, true);
        if (!is_array($parsed) || count($parsed) !== count($songs)) {
            return null;
        }

        // Validate and clean each correction
        $results = [];
        foreach ($parsed as $i => $corrections) {
            $clean = [];
            if (!is_array($corrections)) {
                $results[$i] = [];
                continue;
            }

            if (!empty($corrections['genre']) && is_string($corrections['genre'])) {
                $mapped = self::mapGenre($corrections['genre']);
                $genre = $mapped ?: ucfirst(strtolower(trim($corrections['genre'])));
                if (strlen($genre) >= 2 && strlen($genre) <= 30) {
                    $clean['genre'] = $genre;
                }
            }

            if (!empty($corrections['year'])) {
                $year = (int) $corrections['year'];
                if ($year >= 1900 && $year <= (int) date('Y')) {
                    $clean['year'] = $year;
                }
            }

            if (!empty($corrections['album']) && is_string($corrections['album'])) {
                $album = trim($corrections['album']);
                if (strlen($album) >= 2) {
                    $clean['album'] = $album;
                }
            }

            $results[$i] = $clean;
        }

        return $results;
    }

    /**
     * Look up missing metadata using a local Ollama instance.
     * Fallback when Gemini is unavailable or rate-limited.
     *
     * @param string $artist  Current artist name
     * @param string $title   Current title
     * @param array  $needs   Flags: 'genre', 'year', 'artist', 'title', 'album' (true if missing)
     * @return array{genre?: string, year?: int, artist?: string, title?: string, album?: string}|null
     */
    public function lookupByOllamaAI(string $artist, string $title, array $needs): ?array
    {
        if ($this->ollamaUrl === '') {
            return null;
        }

        $fields = array_filter($needs);
        if (empty($fields)) {
            return null;
        }

        // Build the same prompt as Gemini
        $parts = [];
        if (!empty($needs['genre'])) {
            $parts[] = '"genre": the primary music genre (e.g. Rock, Pop, Country, Jazz, R&B, Hip Hop, Electronic, Classical, Folk, Blues, Soul, Funk, Reggae, Latin, Metal, Punk, Gospel, Christian, Disco, Easy Listening, New Age, World, Pop Rock)';
        }
        if (!empty($needs['year'])) {
            $parts[] = '"year": the original release year as a number';
        }
        if (!empty($needs['artist'])) {
            $parts[] = '"artist": the correct artist/performer name';
        }
        if (!empty($needs['title'])) {
            $parts[] = '"title": the correct song title';
        }
        if (!empty($needs['album'])) {
            $parts[] = '"album": the album or single this song was released on';
        }
        if (!empty($needs['country_code'])) {
            $parts[] = '"country_code": the ISO 3166-1 alpha-2 country code of the artist\'s country of origin (e.g. US, GB, PH, KR, JP, AU, CA, DE, FR, BR)';
        }

        $fieldList = implode(",\n", $parts);

        $prompt = "You are a music metadata expert. Given this song information:\n"
                . "Artist: {$artist}\n"
                . "Title: {$title}\n\n"
                . "Return a JSON object with ONLY these fields (omit any you're unsure about):\n"
                . $fieldList . "\n\n"
                . "Rules:\n"
                . "- Only return fields you are confident about\n"
                . "- Genre must be a single primary genre, not a list\n"
                . "- Year must be between 1900 and " . date('Y') . "\n"
                . "- If you cannot determine a field with confidence, omit it entirely\n"
                . "- Return valid JSON only, no markdown or explanation";

        $url = $this->ollamaUrl . '/api/generate';

        $payload = json_encode([
            'model'  => $this->ollamaModel,
            'prompt' => $prompt,
            'format' => 'json',
            'stream' => false,
            'options' => [
                'temperature'   => 0.1,
                'num_predict'   => 150,
            ],
        ]);

        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\nUser-Agent: RendezVox/1.0\r\n",
                'content'       => $payload,
                'timeout'       => 120, // Ollama on ARM can be slow
                'ignore_errors' => true,
            ],
        ]);

        $resp = @file_get_contents($url, false, $ctx);
        if (!$resp) {
            return null;
        }

        $data = json_decode($resp, true);
        if (!$data || !isset($data['response'])) {
            return null;
        }

        $parsed = json_decode($data['response'], true);
        if (!is_array($parsed)) {
            return null;
        }

        // Same validation as Gemini
        $result = [];

        if (!empty($parsed['genre']) && is_string($parsed['genre'])) {
            $mapped = self::mapGenre($parsed['genre']);
            if ($mapped) {
                $result['genre'] = $mapped;
            } else {
                $clean = ucfirst(strtolower(trim($parsed['genre'])));
                if (strlen($clean) >= 2 && strlen($clean) <= 30) {
                    $result['genre'] = $clean;
                }
            }
        }

        if (!empty($parsed['year'])) {
            $year = (int) $parsed['year'];
            if ($year >= 1900 && $year <= (int) date('Y')) {
                $result['year'] = $year;
            }
        }

        if (!empty($parsed['artist']) && is_string($parsed['artist'])) {
            $clean = trim($parsed['artist']);
            if (strlen($clean) >= 2 && !self::looksLikeFilenameArtifact($clean)) {
                $result['artist'] = $clean;
            }
        }

        if (!empty($parsed['title']) && is_string($parsed['title'])) {
            $clean = trim($parsed['title']);
            if (strlen($clean) >= 2 && !self::looksLikeFilenameArtifact($clean)) {
                $result['title'] = $clean;
            }
        }

        if (!empty($parsed['album']) && is_string($parsed['album'])) {
            $clean = trim($parsed['album']);
            if (strlen($clean) >= 2) {
                $result['album'] = $clean;
            }
        }

        // Validate country_code
        if (!empty($parsed['country_code']) && is_string($parsed['country_code'])) {
            $cc = strtoupper(trim($parsed['country_code']));
            if (preg_match('/^[A-Z]{2}$/', $cc)) {
                $result['country_code'] = $cc;
            }
        }

        return !empty($result) ? $result : null;
    }

    // ── Rate limiting ────────────────────────────────────────

    /**
     * Enforce minimum delay between requests to the same API.
     */
    private function rateLimit(string $domain, float $minSeconds): void
    {
        $now = microtime(true);
        if (isset($this->lastRequest[$domain])) {
            $elapsed = $now - $this->lastRequest[$domain];
            if ($elapsed < $minSeconds) {
                $wait = (int) ceil(($minSeconds - $elapsed) * 1000000);
                usleep($wait);
            }
        }
        $this->lastRequest[$domain] = microtime(true);
    }
}
