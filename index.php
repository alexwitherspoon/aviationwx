<?php
/**
 * Aviation Weather - Router
 * Routes requests based on airport parameter or subdomain to airport-specific pages
 */

require_once __DIR__ . '/lib/config.php';

// Check if this is a status page request
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$parsedUri = parse_url($requestUri);
$requestPath = isset($parsedUri['path']) ? trim($parsedUri['path'], '/') : '';

// Route status.php requests
if ($requestPath === 'status.php') {
    include 'pages/status.php';
    exit;
}

$host = isset($_SERVER['HTTP_HOST']) ? strtolower(trim($_SERVER['HTTP_HOST'])) : '';
if (strpos($host, 'status') !== false || (isset($_GET['status']) && $_GET['status'] === '1')) {
    // Route to status page
    include 'pages/status.php';
    exit;
}

// Check if there's a path component in the request (for general 404s)
$hasPathComponent = !empty($requestPath) && $requestPath !== 'index.php';

// Detect if this is an airport-related request (query parameter or subdomain)
$isAirportRequest = false;
$rawAirportIdentifier = '';

// Check for airport query parameter
if (isset($_GET['airport']) && !empty($_GET['airport'])) {
    $isAirportRequest = true;
    $rawAirportIdentifier = trim($_GET['airport']);
}

// Check for airport subdomain
if (!$isAirportRequest) {
    $host = isset($_SERVER['HTTP_HOST']) ? strtolower(trim($_SERVER['HTTP_HOST'])) : '';
    $baseDomain = getBaseDomain();
    
    // Match subdomain pattern (e.g., kspb.aviationwx.org or 03s.aviationwx.org)
    $pattern = '/^([a-z0-9-]{3,50})\.' . preg_quote($baseDomain, '/') . '$/';
    if (preg_match($pattern, $host, $matches)) {
        $isAirportRequest = true;
        $rawAirportIdentifier = $matches[1];
    } else {
        // Fallback: check if host has 3+ parts (handles other TLDs and custom domains)
        $hostParts = explode('.', $host);
        if (count($hostParts) >= 3) {
            $potentialId = $hostParts[0];
            // Exclude known non-airport subdomains
            if (!in_array($potentialId, ['www', 'status', 'aviationwx'])) {
                $isAirportRequest = true;
                $rawAirportIdentifier = $potentialId;
            }
        }
    }
}

// Load configuration (with caching)
$config = loadConfig();
if ($config === null) {
    error_log('Failed to load configuration');
    http_response_code(500);
    die('Configuration error. Please contact the administrator.');
}

// If this is an airport-related request, handle it
if ($isAirportRequest && !empty($rawAirportIdentifier)) {
    // Additional validation: airport must exist in config
    if (!isset($config['airports']) || !is_array($config['airports'])) {
        error_log('Invalid airport configuration structure');
        http_response_code(500);
        die('Configuration error. Please contact the administrator.');
    }
    
    // Lookup airport (supports lookup by any identifier type)
    $airport = null;
    $airportId = null;
    
    // First try direct lookup (backward compatibility)
    if (isset($config['airports'][$rawAirportIdentifier])) {
        $airport = $config['airports'][$rawAirportIdentifier];
        $airportId = $rawAirportIdentifier;
    } else {
        // Try lookup by identifier (ICAO, IATA, FAA)
        $result = findAirportByIdentifier($rawAirportIdentifier, $config);
        if ($result !== null && isset($result['airport']) && isset($result['airportId'])) {
            $airport = $result['airport'];
            $airportId = $result['airportId'];
        }
    }
    
    if ($airport !== null && $airportId !== null) {
        // Set airport-specific variables for use in template
        $airport['id'] = $airportId;
        
        // Include the airport template
        include 'pages/airport.php';
        exit;
    } else {
        // Airport not found - show airport-specific 404
        http_response_code(404);
        // Make airport identifier available to 404 page (use original identifier, not normalized)
        $requestedAirportId = strtoupper(trim($rawAirportIdentifier));
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

