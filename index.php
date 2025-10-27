<?php
/**
 * Aviation Weather - Subdomain Router
 * Routes requests based on subdomain to airport-specific pages
 */

// Get the subdomain
$host = $_SERVER['HTTP_HOST'] ?? '';
$subdomain = explode('.', $host)[0] ?? '';

// Allow query parameter override for local testing
if (isset($_GET['airport'])) {
    $subdomain = $_GET['airport'];
    $airportId = strtolower($subdomain);
    
    // Check if airport config exists
    $configFile = __DIR__ . '/airports.json';
    if (!file_exists($configFile)) {
        http_response_code(500);
        die('Configuration file not found');
    }
    
    $config = json_decode(file_get_contents($configFile), true);
    
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

// If no subdomain (main domain), show homepage
if ($subdomain === '' || $subdomain === 'aviationwx' || $subdomain === 'localhost' || $subdomain === '127.0.0.1' || $subdomain === 'www' || strpos($host, 'localhost') !== false) {
    include 'homepage.php';
    exit;
}

// Check if airport config exists
$configFile = __DIR__ . '/airports.json';
if (!file_exists($configFile)) {
    http_response_code(500);
    die('Configuration file not found');
}

$config = json_decode(file_get_contents($configFile), true);

// Convert subdomain to lowercase for lookup
$airportId = strtolower($subdomain);

if (isset($config['airports'][$airportId])) {
    // Set airport-specific variables for use in template
    $airport = $config['airports'][$airportId];
    $airport['id'] = $airportId;
    
    // Include the airport template
    include 'airport-template.php';
} else {
    // Subdomain not found
    http_response_code(404);
    include '404.php';
}

