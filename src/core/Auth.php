<?php

declare(strict_types=1);

class Auth
{
    private static string $algo = 'sha256';

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT);
    }

    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public static function createToken(array $payload): string
    {
        $header = self::base64url(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));

        $payload['iat'] = $payload['iat'] ?? time();
        $payload['exp'] = $payload['exp'] ?? time() + 86400; // 24 hours

        $body      = self::base64url(json_encode($payload));
        $signature = self::base64url(hash_hmac(self::$algo, "{$header}.{$body}", self::getSecret(), true));

        return "{$header}.{$body}.{$signature}";
    }

    public static function validateToken(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$header, $body, $signature] = $parts;

        $expected = self::base64url(hash_hmac(self::$algo, "{$header}.{$body}", self::getSecret(), true));

        if (!hash_equals($expected, $signature)) {
            return null;
        }

        $payload = json_decode(self::base64urlDecode($body), true);
        if (!$payload) {
            return null;
        }

        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return null;
        }

        return $payload;
    }

    public static function requireAuth(): array
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            Response::error('Authentication required', 401);
        }

        $payload = self::validateToken($matches[1]);
        if (!$payload) {
            Response::error('Invalid or expired token', 401);
        }

        return $payload;
    }

    public static function requireRole(string ...$roles): array
    {
        $payload = self::requireAuth();

        if (!in_array($payload['role'] ?? '', $roles, true)) {
            Response::error('Forbidden', 403);
        }

        return $payload;
    }

    /**
     * Check password strength: requires score >= 3 (Good).
     * Score: +1 for >=6 chars, +1 for >=10 chars, +1 for mixed case,
     *        +1 for digits, +1 for symbols. Clamped to 1-4.
     */
    public static function isStrongPassword(string $password): bool
    {
        $score = 0;
        if (strlen($password) >= 6)  $score++;
        if (strlen($password) >= 10) $score++;
        if (preg_match('/[a-z]/', $password) && preg_match('/[A-Z]/', $password)) $score++;
        if (preg_match('/[0-9]/', $password)) $score++;
        if (preg_match('/[^a-zA-Z0-9]/', $password)) $score++;
        return min(4, max(1, $score)) >= 3;
    }

    public static function getSecret(): string
    {
        $secret = getenv('RENDEZVOX_JWT_SECRET');
        if (!$secret || $secret === 'changeme') {
            // Auto-derive a secret from the database password so JWT works
            // even without explicitly setting RENDEZVOX_JWT_SECRET.
            // This is deterministic per deployment (same DB password = same JWT secret).
            $dbPass = getenv('RENDEZVOX_DB_PASSWORD') ?: '';
            $secret = hash('sha256', 'rendezvox-jwt-' . $dbPass . '-' . (__DIR__));
        }
        return $secret;
    }

    private static function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64urlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
