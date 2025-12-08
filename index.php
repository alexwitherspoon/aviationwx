<?php
/**
 * Aviation Weather - Router
 * Routes requests based on airport parameter or subdomain to airport-specific pages
 */

require_once __DIR__ . '/lib/config.php';

// Check if this is a status page request
$host = isset($_SERVER['HTTP_HOST']) ? strtolower(trim($_SERVER['HTTP_HOST'])) : '';
if (strpos($host, 'status') !== false || (isset($_GET['status']) && $_GET['status'] === '1')) {
    // Route to status page
    include 'pages/status.php';
    exit;
}

// Get airport ID from request
$airportId = getAirportIdFromRequest();

// Check if there's a path component in the request (for general 404s)
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$parsedUri = parse_url($requestUri);
$requestPath = isset($parsedUri['path']) ? trim($parsedUri['path'], '/') : '';
$hasPathComponent = !empty($requestPath) && $requestPath !== 'index.php';

// Load configuration (with caching)
$config = loadConfig();
if ($config === null) {
    error_log('Failed to load configuration');
    http_response_code(500);
    die('Configuration error. Please contact the administrator.');
}

// If airport ID provided, show airport page
if (!empty($airportId)) {
    // Additional validation: airport must exist in config
    if (!isset($config['airports']) || !is_array($config['airports'])) {
        error_log('Invalid airport configuration structure');
        http_response_code(500);
        die('Configuration error. Please contact the administrator.');
    }
    
    if (isset($config['airports'][$airportId])) {
        // Set airport-specific variables for use in template
        $airport = $config['airports'][$airportId];
        $airport['id'] = $airportId;
        
        // Include the airport template
        include 'pages/airport.php';
        exit;
    } else {
        // Airport not found on subdomain - show airport-specific 404
        http_response_code(404);
        // Make airport ID available to 404 page
        $requestedAirportId = $airportId;
        include 'pages/error-404-airport.php';
        exit;
    }
}

// No airport specified
// If there's a path component, it's a general 404 (invalid path)
if ($hasPathComponent) {
    http_response_code(404);
    include 'pages/error-404.php';
    exit;
}

// No airport and no path - show homepage
include 'pages/homepage.php';

