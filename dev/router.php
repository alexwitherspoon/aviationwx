<?php
/**
 * Local testing script
 * 
 * Usage:
 * php -S localhost:8080 dev/router.php
 * 
 * Then visit: http://kspb.localhost:8080 or http://localhost:8080/airports.json
 */

// Simple routing for local testing
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH);
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';

// Get subdomain
$parts = explode('.', $host);
$subdomain = isset($parts[0]) ? $parts[0] : '';

// Serve static files from project root (document root when running php -S)
$projectRoot = dirname(__DIR__);
if (file_exists($projectRoot . $path) && is_file($projectRoot . $path)) {
    return false; // Let PHP's built-in server handle it
}

// Handle API subdomain for local testing (api.localhost or /api/v1 paths)
if ($subdomain === 'api' || strpos($host, 'api.') === 0 || strpos($path, '/api/v1') === 0) {
    // Route to API documentation at root
    if ($path === '/' && ($subdomain === 'api' || strpos($host, 'api.') === 0)) {
        include __DIR__ . '/../api/docs/index.php';
        exit;
    }
    
    // Route to OpenAPI spec
    if ($path === '/openapi.json' || $path === '/api/docs/openapi.json') {
        header('Content-Type: application/json; charset=utf-8');
        readfile(__DIR__ . '/../api/docs/openapi.json');
        exit;
    }
    
    // Route to API v1 endpoints
    if (strpos($path, '/v1') === 0 || strpos($path, '/api/v1') === 0) {
        include __DIR__ . '/../api/v1/router.php';
        exit;
    }
}

// Handle guides subdomain for local testing
if ($subdomain === 'guides' || strpos($host, 'guides.') === 0) {
    // For local testing, set a mock base domain so routing works
    // This allows guides.localhost to work like guides.aviationwx.org
    $_SERVER['HTTP_HOST'] = 'guides.aviationwx.org';
    include __DIR__ . '/../index.php';
    exit;
}

// If no subdomain or subdomain is 'localhost' or '127.0.0.1', serve homepage
if ($subdomain === 'localhost' || $subdomain === '127' || $subdomain === '' || 
    $host === 'localhost' || $host === '127.0.0.1') {
    include __DIR__ . '/../pages/homepage.php';
    exit;
}

// For subdomains, use the main index.php logic
$_SERVER['HTTP_HOST'] = $host;
include __DIR__ . '/../index.php';

