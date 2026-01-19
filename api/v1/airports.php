<?php
/**
 * Public API - List Airports Endpoint
 * 
 * GET /v1/airports
 * 
 * Returns a list of all listed (publicly discoverable) airports with basic metadata.
 * Unlisted airports (test sites, airports being commissioned) are excluded by default.
 * 
 * Query parameters:
 * - maintenance: Filter by maintenance mode ('true' or 'false')
 * - include_unlisted: Include unlisted airports ('true' or 'false', default: false)
 */

require_once __DIR__ . '/../../lib/public-api/middleware.php';
require_once __DIR__ . '/../../lib/public-api/response.php';
require_once __DIR__ . '/../../lib/config.php';

/**
 * Handle GET /v1/airports request
 * 
 * @param array $params Path parameters (empty for this endpoint)
 * @param array $context Request context from middleware
 */
function handleListAirports(array $params, array $context): void
{
    // Parse include_unlisted filter parameter (default: false)
    $includeUnlisted = false;
    if (isset($_GET['include_unlisted'])) {
        $unlistedParam = strtolower(trim($_GET['include_unlisted']));
        $includeUnlisted = ($unlistedParam === 'true' || $unlistedParam === '1');
    }
    
    // Get airports (listed by default, optionally including unlisted)
    $airports = getPublicApiAirports(true, $includeUnlisted);
    
    // Parse maintenance filter parameter
    $maintenanceFilter = null;
    if (isset($_GET['maintenance'])) {
        $maintenanceParam = strtolower(trim($_GET['maintenance']));
        if ($maintenanceParam === 'true' || $maintenanceParam === '1') {
            $maintenanceFilter = true;
        } elseif ($maintenanceParam === 'false' || $maintenanceParam === '0') {
            $maintenanceFilter = false;
        }
    }
    
    // Format airports for response
    $formattedAirports = [];
    foreach ($airports as $airportId => $airport) {
        $formatted = formatAirportSummary($airportId, $airport);
        
        // Apply maintenance filter if specified
        if ($maintenanceFilter !== null && $formatted['maintenance'] !== $maintenanceFilter) {
            continue;
        }
        
        $formattedAirports[] = $formatted;
    }
    
    // Sort by airport ID for consistent ordering
    usort($formattedAirports, function ($a, $b) {
        return strcmp($a['id'], $b['id']);
    });
    
    // Send cache headers for metadata
    sendPublicApiCacheHeaders('metadata');
    
    // Send response
    sendPublicApiSuccess(
        ['airports' => $formattedAirports],
        ['total' => count($formattedAirports)]
    );
}

/**
 * Format airport data for list response
 * 
 * @param string $airportId Airport ID
 * @param array $airport Airport configuration
 * @return array Formatted airport summary
 */
function formatAirportSummary(string $airportId, array $airport): array
{
    // Check what data is available
    $hasWeather = isset($airport['weather_source']) || isset($airport['metar_station']);
    $hasWebcams = isset($airport['webcams']) && is_array($airport['webcams']) && count($airport['webcams']) > 0;
    $webcamCount = $hasWebcams ? count($airport['webcams']) : 0;
    
    return [
        'id' => $airportId,
        'name' => $airport['name'] ?? '',
        'icao' => $airport['icao'] ?? null,
        'iata' => $airport['iata'] ?? null,
        'faa' => $airport['faa'] ?? null,
        'lat' => $airport['lat'] ?? null,
        'lon' => $airport['lon'] ?? null,
        'elevation_ft' => $airport['elevation_ft'] ?? null,
        'timezone' => $airport['timezone'] ?? 'UTC',
        'maintenance' => isset($airport['maintenance']) && $airport['maintenance'] === true,
        'unlisted' => isset($airport['unlisted']) && $airport['unlisted'] === true,
        'has_weather' => $hasWeather,
        'has_webcams' => $hasWebcams,
        'webcam_count' => $webcamCount,
    ];
}

