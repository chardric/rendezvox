<?php

declare(strict_types=1);

class Router
{
    private static array $routes = [];

    private const INTERNAL_ROUTES = ['/next-track', '/track-started', '/track-played'];

    private static function requireInternalSecret(string $path): void
    {
        foreach (self::INTERNAL_ROUTES as $r) {
            if ($path === $r) {
                $expected = getenv('RENDEZVOX_INTERNAL_SECRET') ?: '';
                if ($expected === '') {
                    $appEnv = getenv('RENDEZVOX_APP_ENV') ?: 'production';
                    if ($appEnv !== 'development') {
                        Response::error('Internal secret not configured', 500);
                    }
                    return;
                }
                $provided = $_SERVER['HTTP_X_INTERNAL_SECRET'] ?? '';
                if (!hash_equals($expected, $provided)) {
                    Response::error('Forbidden', 403);
                }
                return;
            }
        }
    }

    /**
     * Lightweight in-memory rate limiter for public API endpoints.
     * Uses APCu if available, otherwise file-based.
     */
    private static function checkRateLimit(string $ip, string $path): void
    {
        // Only rate-limit public endpoints (admin endpoints are JWT-protected)
        $publicPaths = ['/search-song', '/request', '/config', '/now-playing', '/forgot-password', '/reset-password', '/activate-account'];
        $isPublic = false;
        foreach ($publicPaths as $pp) {
            if (str_starts_with($path, $pp)) {
                $isPublic = true;
                break;
            }
        }
        if (!$isPublic) {
            return;
        }

        // Allow 60 requests per minute per IP for public endpoints
        $limit = 60;
        $window = 60;
        $key = 'ratelimit_' . md5($ip . '_' . $path);

        if (function_exists('apcu_fetch')) {
            $count = apcu_fetch($key);
            if ($count === false) {
                apcu_store($key, 1, $window);
            } elseif ($count >= $limit) {
                http_response_code(429);
                header('Content-Type: application/json; charset=utf-8');
                header('Retry-After: ' . $window);
                echo json_encode(['error' => 'Too many requests']);
                exit;
            } else {
                apcu_inc($key);
            }
        }
        // Without APCu, rate limiting is handled by Nginx (see nginx config)
    }

    public static function get(string $path, callable|array $handler): void
    {
        self::$routes[] = ['GET', $path, $handler];
    }

    public static function post(string $path, callable|array $handler): void
    {
        self::$routes[] = ['POST', $path, $handler];
    }

    public static function put(string $path, callable|array $handler): void
    {
        self::$routes[] = ['PUT', $path, $handler];
    }

    public static function patch(string $path, callable|array $handler): void
    {
        self::$routes[] = ['PATCH', $path, $handler];
    }

    public static function delete(string $path, callable|array $handler): void
    {
        self::$routes[] = ['DELETE', $path, $handler];
    }

    public static function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        // Block null bytes in URI (path traversal attack)
        if (str_contains($uri, "\0")) {
            Response::error('Bad request', 400);
        }

        // Strip /api prefix for clean route matching
        $path = preg_replace('#^/api#', '', $uri) ?: '/';

        // Rate limit public endpoints
        $ip = class_exists('Request') ? Request::clientIp() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        self::checkRateLimit($ip, $path);

        $methodMatched = false;

        foreach (self::$routes as [$routeMethod, $routePath, $handler]) {
            $params = self::match($routePath, $path);

            if ($params !== null) {
                $methodMatched = true;
                if ($routeMethod === $method) {
                    // Inject matched params into $_GET
                    foreach ($params as $k => $v) {
                        $_GET[$k] = $v;
                    }

                    // Auth middleware: protect /admin/* except /admin/login
                    if (str_starts_with($routePath, '/admin/') && $routePath !== '/admin/login') {
                        Auth::requireAuth();
                    }

                    // Protect internal service endpoints (Liquidsoap → API)
                    self::requireInternalSecret($routePath);

                    if (is_array($handler)) {
                        [$class, $func] = $handler;
                        (new $class())->$func();
                    } else {
                        $handler();
                    }
                    return;
                }
            }
        }

        if ($methodMatched) {
            Response::error('Method not allowed', 405);
        }

        // Browser navigating to an unknown non-API path → redirect to listener page
        $acceptsHtml = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'text/html');
        $isApiPath   = str_starts_with($uri, '/api/') || $uri === '/api';
        if ($acceptsHtml && !$isApiPath) {
            http_response_code(302);
            header('Location: /');
            exit;
        }

        Response::error('Not found', 404);
    }

    /**
     * Match a route pattern against a request path.
     *
     * Returns an associative array of params on match, or null on no match.
     * Supports :param segments: /admin/songs/:id matches /admin/songs/42
     */
    private static function match(string $pattern, string $path): ?array
    {
        $patternParts = explode('/', trim($pattern, '/'));
        $pathParts    = explode('/', trim($path, '/'));

        if (count($patternParts) !== count($pathParts)) {
            return null;
        }

        $params = [];

        for ($i = 0; $i < count($patternParts); $i++) {
            if (str_starts_with($patternParts[$i], ':')) {
                $params[substr($patternParts[$i], 1)] = $pathParts[$i];
            } elseif ($patternParts[$i] !== $pathParts[$i]) {
                return null;
            }
        }

        return $params;
    }
}
