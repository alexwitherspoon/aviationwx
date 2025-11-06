<?php
/**
 * Aviation Weather - Router
 * Routes requests based on airport parameter or subdomain to airport-specific pages
 */

require_once __DIR__ . '/config-utils.php';

// Check if this is a status page request
$host = isset($_SERVER['HTTP_HOST']) ? strtolower(trim($_SERVER['HTTP_HOST'])) : '';
if (strpos($host, 'status') !== false || (isset($_GET['status']) && $_GET['status'] === '1')) {
    // Route to status page
    include 'status.php';
    exit;
}

// Get airport ID from request
$airportId = getAirportIdFromRequest();

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
        include 'airport-template.php';
        exit;
    } else {
        // Airport not found - don't reveal which airports exist
        http_response_code(404);
        include '404.php';
        exit;
    }
}

// No airport specified, show homepage
include 'homepage.php';

