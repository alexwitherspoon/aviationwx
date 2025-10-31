<?php
/**
 * Aviation Weather - Router
 * Routes requests based on airport parameter or subdomain to airport-specific pages
 */

// Get airport ID from query parameter or subdomain
$airportId = '';

// First, try query parameter
if (isset($_GET['airport']) && !empty($_GET['airport'])) {
    $airportId = strtolower($_GET['airport']);
} else {
    // Try extracting from subdomain (e.g., kspb.aviationwx.org -> kspb)
    // Only match if there are exactly 3 parts (subdomain.domain.tld)
    $host = isset($_SERVER['HTTP_HOST']) ? strtolower($_SERVER['HTTP_HOST']) : '';
    if (preg_match('/^([a-z0-9]+)\.aviationwx\.org$/', $host, $matches)) {
        $airportId = $matches[1];
    }
    // Also check if host has 3+ parts (handles other TLDs)
    $hostParts = explode('.', $host);
    if (count($hostParts) >= 3 && empty($airportId)) {
        $airportId = $hostParts[0];
    }
}

// Check if airport config exists
$envConfigPath = getenv('CONFIG_PATH');
$configFile = ($envConfigPath && file_exists($envConfigPath)) ? $envConfigPath : (__DIR__ . '/airports.json');
if (!file_exists($configFile)) {
    http_response_code(500);
    die('Configuration file not found: ' . htmlspecialchars($configFile));
}
if (is_dir($configFile)) {
    http_response_code(500);
    die('Configuration path is a directory, not a file: ' . htmlspecialchars($configFile));
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

