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

if (preg_match('~^api/v1/([A-Za-z0-9_-]+)$~D', $route)) {
    require __DIR__ . '/api.php';
    exit;
}

// Preserve the original root installer/dashboard redirect behavior.
require __DIR__ . '/index.php';
