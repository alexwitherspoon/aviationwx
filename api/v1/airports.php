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
 * - limited_availability: Filter by limited availability ('true' or 'false')
 * - include_unlisted: Include unlisted airports ('true' or 'false', default: false)
 * - operator: Include airports that have one or more matching weathercams or weather sources
 */

require_once __DIR__ . '/../../lib/public-api/middleware.php';
require_once __DIR__ . '/../../lib/public-api/response.php';
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/weather/utils.php';

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
    
    // Parse limited_availability filter parameter
    $limitedAvailabilityFilter = null;
    if (isset($_GET['limited_availability'])) {
        $limitedParam = strtolower(trim($_GET['limited_availability']));
        if ($limitedParam === 'true' || $limitedParam === '1') {
            $limitedAvailabilityFilter = true;
        } elseif ($limitedParam === 'false' || $limitedParam === '0') {
            $limitedAvailabilityFilter = false;
        }
    }

    $operatorParse = parseOperatorQueryFromGet();
    if (!$operatorParse['ok']) {
        sendPublicApiError(
            PUBLIC_API_ERROR_INVALID_REQUEST,
            $operatorParse['error'],
            400
        );
        return;
    }
    $operatorFilter = $operatorParse['value'];
    
    // Format airports for response
    $formattedAirports = [];
    foreach ($airports as $airportId => $airport) {
        $formatted = formatAirportSummary($airportId, $airport, $operatorFilter);
        
        // Apply maintenance filter if specified
        if ($maintenanceFilter !== null && $formatted['maintenance'] !== $maintenanceFilter) {
            continue;
        }
        
        // Apply limited_availability filter if specified
        if ($limitedAvailabilityFilter !== null && $formatted['limited_availability'] !== $limitedAvailabilityFilter) {
            continue;
        }

        if ($operatorFilter !== null && !airportMatchesOperator($airport, $operatorFilter)) {
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
        ['total' => count($formattedAirports), 'coordinate_system' => 'WGS84']
    );
}

/**
 * Format airport data for list response
 *
 * @param string $airportId Airport ID
 * @param array $airport Airport configuration
 * @param string|null $operatorFilter When set, webcam_count counts matching weathercams only
 * @return array Formatted airport summary
 */
function formatAirportSummary(string $airportId, array $airport, ?string $operatorFilter = null): array
{
    // Weather is always the fused observation, so has_weather is not operator-sliced.
    $hasWeather = hasWeatherSources($airport);
    $webcamCount = countWeathercamsForOperator($airport, $operatorFilter);
    $hasWebcams = $webcamCount > 0;

    $baseDomain = getBaseDomain();
    $url = 'https://' . $airportId . '.' . $baseDomain . '/';

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
        'magnetic_declination' => getMagneticDeclination($airport),
        'maintenance' => isset($airport['maintenance']) && $airport['maintenance'] === true,
        'limited_availability' => isset($airport['limited_availability']) && $airport['limited_availability'] === true,
        'unlisted' => isset($airport['unlisted']) && $airport['unlisted'] === true,
        'has_weather' => $hasWeather,
        'has_webcams' => $hasWebcams,
        'webcam_count' => $webcamCount,
        'url' => $url,
    ];
}

