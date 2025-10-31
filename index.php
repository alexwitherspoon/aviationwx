<?php
/**
 * Aviation Weather - Router
 * Routes requests based on airport parameter or subdomain to airport-specific pages
 */

/**
 * Validate and sanitize airport ID
 * Airport IDs must be 3-4 lowercase alphanumeric characters (ICAO format)
 */
function validateAirportId($id) {
    if (empty($id)) {
        return false;
    }
    // ICAO codes are 3-4 characters: alphanumeric, lowercase
    // Also allow numbers for compatibility
    return preg_match('/^[a-z0-9]{3,4}$/', $id) === 1;
}

// Get airport ID from query parameter or subdomain
$airportId = '';

// First, try query parameter
if (isset($_GET['airport']) && !empty($_GET['airport'])) {
    $rawId = strtolower(trim($_GET['airport']));
    if (validateAirportId($rawId)) {
        $airportId = $rawId;
    }
} else {
    // Try extracting from subdomain (e.g., kspb.aviationwx.org -> kspb)
    $host = isset($_SERVER['HTTP_HOST']) ? strtolower(trim($_SERVER['HTTP_HOST'])) : '';
    
    // Match subdomain pattern (kspb.aviationwx.org)
    if (preg_match('/^([a-z0-9]{3,4})\.aviationwx\.org$/', $host, $matches)) {
        $rawId = $matches[1];
        if (validateAirportId($rawId)) {
            $airportId = $rawId;
        }
    } else {
        // Also check if host has 3+ parts (handles other TLDs)
        $hostParts = explode('.', $host);
        if (count($hostParts) >= 3) {
            $rawId = $hostParts[0];
            if (validateAirportId($rawId)) {
                $airportId = $rawId;
            }
        }
    }
}

/**
 * Load airport configuration safely
 */
function loadConfig() {
    $envConfigPath = getenv('CONFIG_PATH');
    $configFile = ($envConfigPath && file_exists($envConfigPath)) ? $envConfigPath : (__DIR__ . '/airports.json');
    
    // Validate file exists and is not a directory
    if (!file_exists($configFile)) {
        error_log('Configuration file not found: ' . $configFile);
        http_response_code(500);
        die('Configuration error. Please contact the administrator.');
    }
    if (is_dir($configFile)) {
        error_log('Configuration path is a directory: ' . $configFile);
        http_response_code(500);
        die('Configuration error. Please contact the administrator.');
    }
    
    // Read and validate JSON
    $jsonContent = @file_get_contents($configFile);
    if ($jsonContent === false) {
        error_log('Failed to read configuration file: ' . $configFile);
        http_response_code(500);
        die('Configuration error. Please contact the administrator.');
    }
    
    $config = json_decode($jsonContent, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($config)) {
        error_log('Configuration file is not valid JSON: ' . json_last_error_msg() . ' (file: ' . $configFile . ')');
        http_response_code(500);
        die('Configuration error. Please contact the administrator.');
    }
    
    return $config;
}

$config = loadConfig();

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

