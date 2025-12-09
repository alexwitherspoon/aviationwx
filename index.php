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

// Load configuration (with caching) - do this early so getBaseDomain() works
$config = loadConfig();
if ($config === null) {
    error_log('Failed to load configuration');
    http_response_code(500);
    die('Configuration error. Please contact the administrator.');
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
        // TODO: Remove debug logging after fixing redirect issue
        error_log("Subdomain extracted via pattern: host='$host', baseDomain='$baseDomain', identifier='$rawAirportIdentifier'");
    } else {
        // Fallback: check if host has 3+ parts (handles other TLDs and custom domains)
        $hostParts = explode('.', $host);
        if (count($hostParts) >= 3) {
            $potentialId = $hostParts[0];
            // Exclude known non-airport subdomains
            if (!in_array($potentialId, ['www', 'status', 'aviationwx'])) {
                $isAirportRequest = true;
                $rawAirportIdentifier = $potentialId;
                // TODO: Remove debug logging after fixing redirect issue
                error_log("Subdomain extracted via fallback: host='$host', identifier='$rawAirportIdentifier'");
            }
        }
    }
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
        // TODO: Remove debug logging after fixing redirect issue
        error_log("Airport found via direct lookup: identifier='$rawAirportIdentifier', airportId='$airportId'");
    } else {
        // Try lookup by identifier (ICAO, IATA, FAA)
        $result = findAirportByIdentifier($rawAirportIdentifier, $config);
        if ($result !== null && isset($result['airport']) && isset($result['airportId'])) {
            $airport = $result['airport'];
            $airportId = $result['airportId'];
            // TODO: Remove debug logging after fixing redirect issue
            error_log("Airport found via identifier lookup: identifier='$rawAirportIdentifier', airportId='$airportId'");
        } else {
            // TODO: Remove debug logging after fixing redirect issue
            error_log("Airport NOT found: identifier='$rawAirportIdentifier' (searched by ICAO/IATA/FAA)");
            // Additional debug: check if identifier exists in any airport config
            $identifierUpper = strtoupper($rawAirportIdentifier);
            $foundInConfig = false;
            foreach ($config['airports'] as $id => $apt) {
                if (isset($apt['icao']) && strtoupper(trim($apt['icao'])) === $identifierUpper) {
                    // TODO: Remove debug logging after fixing redirect issue
                    error_log("DEBUG: Found matching ICAO '$identifierUpper' in airport '$id'");
                    $foundInConfig = true;
                }
                if (isset($apt['iata']) && strtoupper(trim($apt['iata'])) === $identifierUpper) {
                    // TODO: Remove debug logging after fixing redirect issue
                    error_log("DEBUG: Found matching IATA '$identifierUpper' in airport '$id'");
                    $foundInConfig = true;
                }
                if (isset($apt['faa']) && strtoupper(trim($apt['faa'])) === $identifierUpper) {
                    // TODO: Remove debug logging after fixing redirect issue
                    error_log("DEBUG: Found matching FAA '$identifierUpper' in airport '$id'");
                    $foundInConfig = true;
                }
            }
            if (!$foundInConfig) {
                // TODO: Remove debug logging after fixing redirect issue
                error_log("DEBUG: No matching ICAO/IATA/FAA found for '$identifierUpper' in any airport config");
            }
        }
    }
    
    if ($airport !== null && $airportId !== null) {
        // Check if this is a minimal airport entry (found via cached mapping but not in config)
        // Minimal entries only have ICAO/IATA/FAA codes, no name or other required fields
        $isMinimalEntry = !isset($airport['name']) || empty($airport['name']);
        
        // Get the primary identifier for this airport (ICAO > IATA > FAA > Airport ID)
        // getPrimaryIdentifier() automatically returns the most preferred identifier that exists,
        // so if no ICAO exists, it returns IATA; if no IATA, returns FAA; etc.
        $primaryIdentifier = getPrimaryIdentifier($airportId, $airport);
        $requestedIdentifier = strtoupper(trim($rawAirportIdentifier));
        $primaryIdentifierUpper = strtoupper(trim($primaryIdentifier));
        
        // TODO: Remove debug logging after fixing redirect issue
        error_log(sprintf(
            'Redirect check: requested="%s", primary="%s", airportId="%s", icao=%s, iata=%s, match=%s, minimal=%s',
            $requestedIdentifier,
            $primaryIdentifierUpper,
            $airportId,
            isset($airport['icao']) ? $airport['icao'] : 'null',
            isset($airport['iata']) ? $airport['iata'] : 'null',
            $requestedIdentifier === $primaryIdentifierUpper ? 'YES' : 'NO',
            $isMinimalEntry ? 'YES' : 'NO'
        ));
        
        // Redirect if the requested identifier doesn't match the primary identifier.
        // This ensures we redirect to the most preferred identifier (ICAO > IATA > FAA > Airport ID).
        // Examples:
        // - pdx -> kpdx (IATA/airport ID -> ICAO)
        // - kpdx -> kpdx (no redirect, already using primary)
        // - spb -> kspb (IATA -> ICAO)
        if ($requestedIdentifier !== $primaryIdentifierUpper) {
            // Determine protocol (HTTPS preferred)
            $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            
            // Get base domain
            $baseDomain = getBaseDomain();
            
            // Construct the primary identifier URL
            // Use subdomain format (e.g., https://kpdx.aviationwx.org)
            $primaryUrl = $protocol . '://' . strtolower($primaryIdentifier) . '.' . $baseDomain;
            
            // Preserve any query parameters (excluding the airport parameter)
            $queryParams = $_GET;
            unset($queryParams['airport']);
            if (!empty($queryParams)) {
                $primaryUrl .= '?' . http_build_query($queryParams);
            }
            
            // Perform 301 permanent redirect to the primary identifier URL
            http_response_code(301);
            header('Location: ' . $primaryUrl);
            exit;
        }
        
        // If this is a minimal entry (not in config), show 404 page instead of dashboard
        if ($isMinimalEntry) {
            http_response_code(404);
            // Make airport identifier available to 404 page (use primary identifier)
            $requestedAirportId = $primaryIdentifierUpper;
            include 'pages/error-404-airport.php';
            exit;
        }
        
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

