<?php

declare(strict_types=1);

// -- Error handling --
$debug = getenv('IRADIO_APP_DEBUG') === 'true';
error_reporting($debug ? E_ALL : 0);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
ini_set('expose_php', '0');

// Global exception handler — never leak stack traces in production
set_exception_handler(function (Throwable $e) use ($debug) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    $response = ['error' => 'Internal server error'];
    if ($debug) {
        $response['debug'] = $e->getMessage();
        $response['trace'] = $e->getTraceAsString();
    }
    echo json_encode($response);
    exit;
});

// -- Core --
require __DIR__ . '/../src/core/Response.php';
require __DIR__ . '/../src/core/Database.php';
require __DIR__ . '/../src/core/Router.php';
require __DIR__ . '/../src/core/RotationEngine.php';
require __DIR__ . '/../src/core/Auth.php';
require __DIR__ . '/../src/core/Request.php';
require __DIR__ . '/../src/core/SmtpMailer.php';
require __DIR__ . '/../src/core/SongResolver.php';
require __DIR__ . '/../src/core/ContentFilter.php';
require __DIR__ . '/../src/core/MetadataExtractor.php';
require __DIR__ . '/../src/core/MetadataLookup.php';
require __DIR__ . '/../src/core/DiskSpace.php';
require __DIR__ . '/../src/core/ArtistNormalizer.php';

// -- Routes --
require __DIR__ . '/../src/api/index.php';

// -- Dispatch --
Router::dispatch();
