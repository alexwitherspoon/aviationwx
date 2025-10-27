<?php
/**
 * Aviation Weather - Router
 * Routes requests based on airport parameter or subdomain to airport-specific pages
 */

// Get airport ID from query parameter (from subdomain rewrite or direct URL)
$airportId = isset($_GET['airport']) ? strtolower($_GET['airport']) : '';

// Check if airport config exists
$configFile = __DIR__ . '/airports.json';
if (!file_exists($configFile)) {
    http_response_code(500);
    die('Configuration file not found');
}

$config = json_decode(file_get_contents($configFile), true);
if (json_last_error() !== JSON_ERROR_NONE || !$config) {
    http_response_code(500);
    die('Configuration file is not valid JSON: ' . json_last_error_msg());
}

// If airport ID provided, show airport page
if (!empty($airportId)) {
    if (isset($config['airports'][$airportId])) {
        // Set airport-specific variables for use in template
        $airport = $config['airports'][$airportId];
        $airport['id'] = $airportId;
        
        // Include the airport template
        include 'airport-template.php';
        exit;
    } else {
        // Airport not found
        http_response_code(404);
        include '404.php';
        exit;
    }
}

// No airport specified, show homepage
include 'homepage.php';

