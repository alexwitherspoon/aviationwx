<?php
/**
 * Public API - Bulk Weather Endpoint
 * 
 * GET /v1/weather/bulk?airports=kspb,kczk,kpfc
 * 
 * Returns current weather data for multiple airports in a single request.
 */

require_once __DIR__ . '/../../lib/public-api/middleware.php';
require_once __DIR__ . '/../../lib/public-api/response.php';
require_once __DIR__ . '/../../lib/public-api/config.php';
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/metrics.php';

// Include the weather formatting function from the single weather endpoint
require_once __DIR__ . '/weather.php';

/**
 * Handle GET /v1/weather/bulk request
 * 
 * @param array $params Path parameters (empty for this endpoint)
 * @param array $context Request context from middleware
 */
function handleGetWeatherBulk(array $params, array $context): void
{
    // Get airports parameter
    $airportsParam = $_GET['airports'] ?? '';
    
    if (empty($airportsParam)) {
        sendPublicApiError(
            PUBLIC_API_ERROR_INVALID_REQUEST,
            'Missing required parameter: airports',
            400
        );
        return;
    }
    
    // Parse airport IDs
    $requestedIds = array_filter(
        array_map('trim', explode(',', $airportsParam)),
        function ($id) {
            return !empty($id);
        }
    );
    
    if (empty($requestedIds)) {
        sendPublicApiError(
            PUBLIC_API_ERROR_INVALID_REQUEST,
            'No valid airport IDs provided',
            400
        );
        return;
    }
    
    // Check max airports limit
    $maxAirports = getPublicApiBulkMaxAirports();
    if (count($requestedIds) > $maxAirports) {
        sendPublicApiError(
            PUBLIC_API_ERROR_INVALID_REQUEST,
            'Too many airports requested. Maximum is ' . $maxAirports,
            400
        );
        return;
    }
    
    // Fetch weather for each airport
    $weatherData = [];
    $requested = 0;
    $returned = 0;
    $errors = [];
    
    foreach ($requestedIds as $rawId) {
        $requested++;
        $airportId = validatePublicApiAirportId($rawId);
        
        if ($airportId === null) {
            $errors[$rawId] = 'Invalid airport ID format';
            continue;
        }
        
        $airport = getPublicApiAirport($airportId);
        
        if ($airport === null) {
            $errors[$rawId] = 'Airport not found';
            continue;
        }
        
        // Check if airport has weather data
        if (!hasWeatherSources($airport)) {
            $errors[$rawId] = 'No weather data configured';
            continue;
        }
        
        // Get cached weather data
        $weather = getWeatherFromCache($airportId);
        
        if ($weather === null) {
            $errors[$rawId] = 'Weather data unavailable';
            continue;
        }
        
        // Track API request metric (combined with private API metrics)
        metrics_track_weather_request($airportId);
        
        // Format weather response
        $weatherData[$airportId] = formatWeatherResponse($weather, $airport);
        $returned++;
    }
    
    // Build metadata
    $meta = [
        'requested' => $requested,
        'returned' => $returned,
        'units' => getPublicApiWeatherUnits(),
    ];
    
    if (!empty($errors)) {
        $meta['errors'] = $errors;
    }
    
    // Send cache headers for live data
    sendPublicApiCacheHeaders('live');
    
    // Send response
    sendPublicApiSuccess(
        ['weather' => $weatherData],
        $meta
    );
}

