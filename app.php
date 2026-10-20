<?php
/**
 * SMSKIT central web entry point (PHP 7.4 compatible).
 *
 * Legacy URLs are routed here by .htaccess. The existing handlers are included
 * rather than copied, preserving their request method, query string, headers,
 * relative paths, and JSON response contracts while avoiding a risky rewrite.
 */
$route = isset($_GET['route']) ? (string) $_GET['route'] : '';

if ($route === 'dashboard') {
    require __DIR__ . '/dashboard/index.php';
    exit;
}

if ($route === 'setup') {
    require __DIR__ . '/setup.php';
    exit;
}

if (preg_match('~^api/v1/([A-Za-z0-9_-]+)$~D', $route, $matches)) {
    $endpoint = $matches[1];

    // Internal helper files must never be exposed as API endpoints.
    if ($endpoint !== '' && $endpoint[0] !== '_') {
        $handler = __DIR__ . '/api/v1/' . $endpoint . '.php';
        if (is_file($handler)) {
            require $handler;
            exit;
        }
    }

    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'API endpoint not found']);
    exit;
}

// Preserve the original root installer/dashboard redirect behavior.
require __DIR__ . '/index.php';
