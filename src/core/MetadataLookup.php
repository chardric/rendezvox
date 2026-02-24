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
        $ua = 'iRadio/1.0 (metadata-lookup)';
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
     * @return array{title?: string, artist?: string, album?: string, recording_id?: string}|null
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
        if ($score < 0.7) {
            return null;
        }

        $recordings = $best['recordings'] ?? [];
        if (empty($recordings)) {
            return null;
        }

        $rec    = $recordings[0];
        $result = [];

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
    public function lookupGenreByArtist(string $artistName): ?string
    {
        $query = urlencode($artistName);
        $url   = "https://musicbrainz.org/ws/2/artist/?query=artist:" . $query . "&limit=1&fmt=json";

        $this->rateLimit('musicbrainz', 1.0);
        $resp = @file_get_contents($url, false, $this->httpContext);
        if (!$resp) {
            return null;
        }

        $data    = json_decode($resp, true);
        $artists = $data['artists'] ?? [];
        if (empty($artists)) {
            return null;
        }

        $mbid  = $artists[0]['id'] ?? null;
        $score = (int) ($artists[0]['score'] ?? 0);
        if (!$mbid || $score < 80) {
            return null;
        }

        $this->rateLimit('musicbrainz', 1.0);
        $url2  = "https://musicbrainz.org/ws/2/artist/{$mbid}?inc=genres+tags&fmt=json";
        $resp2 = @file_get_contents($url2, false, $this->httpContext);
        if (!$resp2) {
            return null;
        }

        $data2      = json_decode($resp2, true);
        $allEntries = array_merge($data2['genres'] ?? [], $data2['tags'] ?? []);
        usort($allEntries, fn($a, $b) => ($b['count'] ?? 0) - ($a['count'] ?? 0));

        foreach ($allEntries as $entry) {
            $mapped = self::mapGenre($entry['name']);
            if ($mapped) {
                return $mapped;
            }
        }

        return null;
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

        // Find album + year + release_id
        $albumTitle = null;
        $year       = null;
        $releaseId  = null;

        foreach ($releases as $rel) {
            $group   = $rel['release-group']['primary-type'] ?? '';
            $relDate = $rel['date'] ?? '';
            if (strtolower($group) === 'album') {
                $albumTitle = trim($rel['title']);
                $releaseId  = $rel['id'] ?? null;
                if ($relDate && preg_match('/\b(19|20)\d{2}\b/', $relDate, $m)) {
                    $year = (int) $m[0];
                }
                break;
            }
        }

        if ($albumTitle === null) {
            $albumTitle = trim($releases[0]['title']);
            $releaseId  = $releases[0]['id'] ?? null;
            $relDate    = $releases[0]['date'] ?? '';
            if ($relDate && preg_match('/\b(19|20)\d{2}\b/', $relDate, $m)) {
                $year = (int) $m[0];
            }
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
     * 1. Try Cover Art Archive via MusicBrainz release ID (free, no key)
     * 2. Fall back to TheAudioDB if key is configured
     *
     * @return string|null Raw image bytes or null
     */
    public function lookupCoverArt(string $artist, string $title, ?string $releaseId = null): ?string
    {
        // Try Cover Art Archive first (if we have a release ID)
        if ($releaseId) {
            $imageData = $this->fetchCoverArtArchive($releaseId);
            if ($imageData) {
                return $imageData;
            }
        }

        // Fall back to TheAudioDB
        if ($this->theAudioDbKey !== '') {
            $imageData = $this->fetchTheAudioDbCover($artist, $title);
            if ($imageData) {
                return $imageData;
            }
        }

        return null;
    }

    /**
     * Fetch cover art from Cover Art Archive.
     */
    private function fetchCoverArtArchive(string $releaseId): ?string
    {
        $this->rateLimit('coverartarchive', 1.0);

        $url = "https://coverartarchive.org/release/{$releaseId}/front-500";
        $ctx = stream_context_create(['http' => [
            'header'          => "User-Agent: iRadio/1.0 (cover-art)\r\n",
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
            'header'          => "User-Agent: iRadio/1.0 (cover-art)\r\n",
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

        $tmpImage = '/tmp/iradio_cover_' . md5($filePath) . '.' . $imgExt;
        $tmpAudio = '/tmp/iradio_coverembed_' . md5($filePath) . '.' . $ext;

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

        $tmpFile = '/tmp/iradio_extract_cover_' . md5($filePath . microtime()) . '.jpg';
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

        $tmpFile = '/tmp/iradio_cleantag_' . md5($filePath . microtime()) . '.' . $ext;

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
