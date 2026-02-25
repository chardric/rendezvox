<?php

declare(strict_types=1);

class SongCreateHandler
{
    private const BASE_DIR = '/var/lib/rendezvox/music';
    private const UPLOAD_DIR = '/var/lib/rendezvox/music/upload';
    private const IMPORTS_DIR = '/var/lib/rendezvox/music/imports';

    public function handle(): void
    {
        $db = Database::get();

        if (!empty($_FILES['files'])) {
            $this->checkDiskSpace($_FILES['files']['size']);
            $this->handleBatch($db);
        } elseif (!empty($_FILES['file'])) {
            $this->checkDiskSpace([$_FILES['file']['size']]);
            $this->handleUpload($db);
        } else {
            $this->handleJson($db);
        }
    }

    // ── Batch upload (files[]) ────────────────────────────────

    private function handleBatch(\PDO $db): void
    {
        $this->ensureUploadDir();

        $sidecar = $this->buildSidecar();

        $results = [];
        $queued  = 0;
        $errors  = 0;

        $names    = $_FILES['files']['name'];
        $tmpNames = $_FILES['files']['tmp_name'];
        $errs     = $_FILES['files']['error'];

        for ($i = 0, $n = count($names); $i < $n; $i++) {
            $result = $this->stageFile(
                ['name' => $names[$i], 'tmp_name' => $tmpNames[$i], 'error' => $errs[$i]],
                $sidecar
            );

            $results[] = $result;
            if ($result['status'] === 'queued') {
                $queued++;
            } else {
                $errors++;
            }
        }

        Response::json(['results' => $results, 'queued' => $queued, 'errors' => $errors], 201);
    }

    // ── Single file upload ────────────────────────────────────

    private function handleUpload(\PDO $db): void
    {
        $this->ensureUploadDir();

        $result = $this->stageFile($_FILES['file'], $this->buildSidecar());

        if ($result['status'] !== 'queued') {
            Response::error($result['error'], 400);
            return;
        }

        Response::json([
            'status'   => 'queued',
            'filename' => $result['filename'],
            'message'  => 'File queued for processing',
        ], 201);
    }

    // ── Stage file to upload/ directory ──────────────────────

    private function stageFile(array $file, array $sidecar = []): array
    {
        $name    = $file['name'];
        $tmpName = $file['tmp_name'];
        $errCode = (int) $file['error'];

        if ($errCode !== UPLOAD_ERR_OK) {
            return ['filename' => $name, 'status' => 'error', 'error' => 'Upload error code: ' . $errCode];
        }

        $ext     = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $allowed = ['mp3', 'flac', 'ogg', 'wav', 'aac', 'm4a'];
        if (!in_array($ext, $allowed, true)) {
            return ['filename' => $name, 'status' => 'error', 'error' => 'Unsupported audio format'];
        }

        // Validate MIME type
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmpName);
        $allowedMimes = [
            'audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/x-wav',
            'audio/flac', 'audio/x-flac', 'audio/aac', 'audio/mp4',
            'audio/x-m4a', 'application/octet-stream',
        ];
        if (!in_array($mime, $allowedMimes, true)) {
            return ['filename' => $name, 'status' => 'error', 'error' => 'Invalid audio file'];
        }

        // Check for folder upload (relative_path indicates folder structure to preserve)
        $relativePath = trim($_POST['relative_path'] ?? '');
        if ($relativePath !== '') {
            return $this->stageToImports($tmpName, $name, $ext, $relativePath, $sidecar);
        }

        // Sanitize filename
        $basename  = pathinfo($name, PATHINFO_FILENAME);
        $sanitized = preg_replace('/[\/\\\\:*?"<>|]/', '', $basename);
        $sanitized = trim($sanitized) ?: $basename;
        $filename  = $sanitized . '.' . $ext;
        $destPath  = self::UPLOAD_DIR . '/' . $filename;

        // Resolve collisions
        if (file_exists($destPath)) {
            $counter = 2;
            while (file_exists(self::UPLOAD_DIR . '/' . $sanitized . ' (' . $counter . ').' . $ext)) {
                $counter++;
            }
            $filename = $sanitized . ' (' . $counter . ').' . $ext;
            $destPath = self::UPLOAD_DIR . '/' . $filename;
        }

        if (!move_uploaded_file($tmpName, $destPath)) {
            return ['filename' => $name, 'status' => 'error', 'error' => 'Failed to save file'];
        }

        // Write .meta sidecar with genre/artist/title for the organizer
        if ($sidecar) {
            file_put_contents(self::UPLOAD_DIR . '/.' . $filename . '.meta', json_encode($sidecar));
        }

        return [
            'filename' => $name,
            'status'   => 'queued',
        ];
    }

    // ── Stage folder upload to imports/ preserving structure ──

    private function stageToImports(string $tmpName, string $originalName, string $ext, string $relativePath, array $sidecar): array
    {
        // Sanitize relative path: no "..", no leading "/", no null bytes
        $safePath = $this->sanitizeRelativePath($relativePath);
        if ($safePath === null) {
            return ['filename' => $originalName, 'status' => 'error', 'error' => 'Invalid folder path'];
        }

        $destDir  = self::IMPORTS_DIR . '/' . dirname($safePath);
        $filename = basename($safePath);

        // Sanitize the filename portion
        $basename  = pathinfo($filename, PATHINFO_FILENAME);
        $sanitized = preg_replace('/[\\\\:*?"<>|]/', '', $basename);
        $sanitized = trim($sanitized) ?: $basename;
        $filename  = $sanitized . '.' . $ext;

        if (!is_dir($destDir)) {
            if (!mkdir($destDir, 0775, true)) {
                return ['filename' => $originalName, 'status' => 'error', 'error' => 'Failed to create directory'];
            }
            @chmod($destDir, 0775);
        }

        $destPath = $destDir . '/' . $filename;

        // Resolve collisions
        if (file_exists($destPath)) {
            $counter = 2;
            while (file_exists($destDir . '/' . $sanitized . ' (' . $counter . ').' . $ext)) {
                $counter++;
            }
            $filename = $sanitized . ' (' . $counter . ').' . $ext;
            $destPath = $destDir . '/' . $filename;
        }

        if (!move_uploaded_file($tmpName, $destPath)) {
            return ['filename' => $originalName, 'status' => 'error', 'error' => 'Failed to save file'];
        }

        @chmod($destPath, 0664);

        return [
            'filename' => $originalName,
            'status'   => 'queued',
        ];
    }

    /**
     * Sanitize a relative path from webkitRelativePath.
     * Prevents path traversal and strips dangerous characters.
     *
     * @return string|null Sanitized path or null if invalid
     */
    private function sanitizeRelativePath(string $path): ?string
    {
        // Reject null bytes
        if (str_contains($path, "\0")) {
            return null;
        }

        // Normalize separators
        $path = str_replace('\\', '/', $path);

        // Split into segments and validate each
        $segments = explode('/', $path);
        $clean    = [];

        foreach ($segments as $seg) {
            $seg = trim($seg);
            if ($seg === '' || $seg === '.' || $seg === '..') {
                continue;
            }
            // Strip control characters
            $seg = preg_replace('/[\x00-\x1F]/', '', $seg);
            if ($seg === '') {
                continue;
            }
            $clean[] = $seg;
        }

        if (empty($clean)) {
            return null;
        }

        return implode('/', $clean);
    }

    // ── JSON metadata-only creation ───────────────────────────

    private function handleJson(\PDO $db): void
    {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!$body) {
            Response::error('Invalid JSON body or no file provided', 400);
            return;
        }

        $title      = trim($body['title'] ?? '');
        $artistId   = (int) ($body['artist_id'] ?? 0);
        $categoryId = (int) ($body['category_id'] ?? 0);
        $filePath   = trim($body['file_path'] ?? '');
        $durationMs = (int) ($body['duration_ms'] ?? 0);
        $weight     = (float) ($body['rotation_weight'] ?? 1.0);

        if ($title === '' || $artistId <= 0 || $categoryId <= 0 || $filePath === '' || $durationMs <= 0) {
            Response::error('title, artist_id, category_id, file_path, and duration_ms are required', 400);
            return;
        }

        $stmt = $db->prepare('
            INSERT INTO songs (title, artist_id, category_id, file_path, duration_ms, rotation_weight)
            VALUES (:title, :artist_id, :category_id, :file_path, :duration_ms, :weight)
            RETURNING id
        ');
        $stmt->execute([
            'title'       => $title,
            'artist_id'   => $artistId,
            'category_id' => $categoryId,
            'file_path'   => $filePath,
            'duration_ms' => $durationMs,
            'weight'      => $weight,
        ]);

        Response::json(['id' => (int) $stmt->fetchColumn(), 'message' => 'Song created successfully'], 201);
    }

    // ── Helpers ───────────────────────────────────────────────

    private function buildSidecar(): array
    {
        $sidecar = [];
        if (($cid = (int) ($_POST['category_id'] ?? 0)) > 0) $sidecar['category_id'] = $cid;
        if (($aid = (int) ($_POST['artist_id'] ?? 0)) > 0)   $sidecar['artist_id'] = $aid;
        if (($t = trim($_POST['title'] ?? '')) !== '')        $sidecar['title'] = $t;
        return $sidecar;
    }

    private function checkDiskSpace(array $sizes): void
    {
        $total = 0;
        foreach ($sizes as $s) {
            $total += (int) $s;
        }
        $err = DiskSpace::requireSpace($total);
        if ($err !== null) {
            Response::error($err, 507);
        }
    }

    private function ensureUploadDir(): void
    {
        if (!is_dir(self::UPLOAD_DIR)) {
            mkdir(self::UPLOAD_DIR, 0775, true);
        }
    }
}
