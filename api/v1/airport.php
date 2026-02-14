<?php
/**
 * Public API - Get Airport Endpoint
 * 
 * GET /v1/airports/{id}
 * 
 * Returns detailed metadata for a single airport including
 * runways, frequencies, and services.
 */

require_once __DIR__ . '/../../lib/public-api/middleware.php';
require_once __DIR__ . '/../../lib/public-api/response.php';
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/weather/utils.php';

/**
 * Handle GET /v1/airports/{id} request
 * 
 * @param array $params Path parameters [0 => airport_id]
 * @param array $context Request context from middleware
 */
function handleGetAirport(array $params, array $context): void
{
    $airportId = validatePublicApiAirportId($params[0] ?? '');
    
    if ($airportId === null) {
        sendPublicApiError(
            PUBLIC_API_ERROR_INVALID_REQUEST,
            'Invalid airport ID format',
            400
        );
        return;
    }
    
    $airport = getPublicApiAirport($airportId);
    
    if ($airport === null) {
        sendPublicApiError(
            PUBLIC_API_ERROR_AIRPORT_NOT_FOUND,
            'Airport not found: ' . $params[0],
            404
        );
        return;
    }
    
    // Format airport for response
    $formatted = formatAirportDetails($airportId, $airport);
    
    // Send cache headers for metadata
    sendPublicApiCacheHeaders('metadata');
    
    // Send response
    sendPublicApiSuccess(
        ['airport' => $formatted],
        ['airport_id' => $airportId]
    );
}

/**
 * Format airport data for detailed response
 * 
 * @param string $airportId Airport ID
 * @param array $airport Airport configuration
 * @return array Formatted airport details
 */
function formatAirportDetails(string $airportId, array $airport): array
{
    $formatted = [
        'id' => $airportId,
        'name' => $airport['name'] ?? '',
        'icao' => $airport['icao'] ?? null,
        'iata' => $airport['iata'] ?? null,
        'faa' => $airport['faa'] ?? null,
        'lat' => $airport['lat'] ?? null,
        'lon' => $airport['lon'] ?? null,
        'elevation_ft' => $airport['elevation_ft'] ?? null,
        'timezone' => $airport['timezone'] ?? 'UTC',
        'address' => $airport['address'] ?? null,
        'maintenance' => isset($airport['maintenance']) && $airport['maintenance'] === true,
        'limited_availability' => isset($airport['limited_availability']) && $airport['limited_availability'] === true,
    ];
    
    // Add runways
    if (isset($airport['runways']) && is_array($airport['runways'])) {
        $formatted['runways'] = array_map(function ($runway) {
            return [
                'name' => $runway['name'] ?? '',
                'heading_1' => $runway['heading_1'] ?? null,
                'heading_2' => $runway['heading_2'] ?? null,
            ];
        }, $airport['runways']);
    } else {
        $formatted['runways'] = [];
    }
    
    // Add frequencies
    if (isset($airport['frequencies']) && is_array($airport['frequencies'])) {
        $formatted['frequencies'] = $airport['frequencies'];
    } else {
        $formatted['frequencies'] = [];
    }
    
    // Add services
    if (isset($airport['services']) && is_array($airport['services'])) {
        $formatted['services'] = $airport['services'];
    } else {
        $formatted['services'] = [];
    }
    
    // Add availability flags
    $formatted['has_weather'] = hasWeatherSources($airport);
    $formatted['has_webcams'] = isset($airport['webcams']) && is_array($airport['webcams']) && count($airport['webcams']) > 0;
    $formatted['webcam_count'] = $formatted['has_webcams'] ? count($airport['webcams']) : 0;
    
    return $formatted;
}

