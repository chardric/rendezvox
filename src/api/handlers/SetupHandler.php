<?php

declare(strict_types=1);

/**
 * First-access setup wizard API.
 *
 * GET  /api/setup/status  — returns {needs_setup: bool}
 * POST /api/setup         — creates the first super_admin user
 *
 * Both endpoints are public (no auth). The POST endpoint refuses to
 * operate once any user exists in the database, preventing abuse.
 */
class SetupHandler
{
    /**
     * GET /api/setup/status
     *
     * Returns whether initial setup is needed (no users in DB).
     */
    public function status(): void
    {
        $db = Database::get();
        $count = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();

        Response::json([
            'needs_setup' => $count === 0,
        ]);
    }

    /**
     * POST /api/setup
     *
     * Creates the first super_admin user. Only works when no users exist.
     *
     * Input JSON:
     *   full_name  — required, display name (first name extracted as username)
     *   email      — required, valid email
     *   password   — required, must meet strength requirements
     */
    public function handle(): void
    {
        $db = Database::get();

        // ── Guard: only works when no users exist ────────
        $count = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
        if ($count > 0) {
            Response::error('Setup already completed. Use the login page.', 403);
        }

        // ── Parse input ──────────────────────────────────
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            Response::error('Invalid JSON input', 400);
        }

        $fullName = trim((string) ($input['full_name'] ?? ''));
        $email    = trim((string) ($input['email'] ?? ''));
        $password = (string) ($input['password'] ?? '');

        // ── Validate ─────────────────────────────────────
        if ($fullName === '') {
            Response::error('Full name is required', 400);
        }
        if (mb_strlen($fullName) > 255) {
            Response::error('Full name is too long (max 255 characters)', 422);
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::error('A valid email address is required', 400);
        }

        if ($password === '') {
            Response::error('Password is required', 400);
        }
        if (!Auth::isStrongPassword($password)) {
            Response::error('Password is too weak. Use at least 6 characters with a mix of upper/lowercase, numbers, and symbols.', 422);
        }

        // ── Derive username from first name ──────────────
        $username = $this->deriveUsername($fullName);

        // ── Create the super_admin user ──────────────────
        $hash = Auth::hashPassword($password);

        $stmt = $db->prepare('
            INSERT INTO users (username, email, display_name, password_hash, role, is_active)
            VALUES (:username, :email, :display_name, :password_hash, :role, TRUE)
            RETURNING id
        ');
        $stmt->execute([
            'username'      => $username,
            'email'         => $email,
            'display_name'  => $fullName,
            'password_hash' => $hash,
            'role'          => 'super_admin',
        ]);
        $userId = (int) $stmt->fetchColumn();

        // ── Issue JWT so user is immediately logged in ───
        $token = Auth::createToken([
            'sub'  => $userId,
            'role' => 'super_admin',
        ]);

        Response::json([
            'message' => 'Setup complete! Welcome to RendezVox.',
            'token'   => $token,
            'user'    => [
                'id'           => $userId,
                'username'     => $username,
                'email'        => $email,
                'display_name' => $fullName,
                'role'         => 'super_admin',
            ],
        ], 201);
    }

    /**
     * Extract first name from full name and lowercase it for username.
     *
     * "Richard Santos"   → "richard"
     * "Maria Clara Cruz" → "maria"
     * "DJ"               → "dj"
     *
     * If the derived username contains non-alphanumeric chars, they're stripped.
     * Falls back to "admin" if nothing usable remains.
     */
    private function deriveUsername(string $fullName): string
    {
        // Take first word (space-separated)
        $parts = preg_split('/\s+/', trim($fullName));
        $first = mb_strtolower($parts[0] ?? '');

        // Keep only alphanumeric + underscore
        $username = preg_replace('/[^a-z0-9_]/', '', $first);

        if ($username === '' || mb_strlen($username) < 2) {
            $username = 'admin';
        }

        return $username;
    }
}
