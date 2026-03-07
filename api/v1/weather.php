<?php
/**
 * Public API - Get Weather Endpoint
 * 
 * GET /v1/airports/{id}/weather
 * 
 * Returns current weather data for a single airport.
 */

require_once __DIR__ . '/../../lib/public-api/middleware.php';
require_once __DIR__ . '/../../lib/public-api/response.php';
require_once __DIR__ . '/../../lib/public-api/weather-format.php';
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/cache-paths.php';
require_once __DIR__ . '/../../lib/weather/utils.php';
require_once __DIR__ . '/../../lib/metrics.php';

/**
 * Handle GET /v1/airports/{id}/weather request
 * 
 * @param array $params Path parameters [0 => airport_id]
 * @param array $context Request context from middleware
 */
function handleGetWeather(array $params, array $context): void
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
    
    // Check if airport has weather data
    if (!hasWeatherSources($airport)) {
        sendPublicApiError(
            PUBLIC_API_ERROR_INVALID_REQUEST,
            'Airport does not have weather data configured',
            404
        );
        return;
    }
    
    // Track API request metric (combined with private API metrics for high-level view)
    metrics_track_weather_request($airportId);
    
    // Get cached weather data
    $weatherData = getWeatherFromCache($airportId);
    
    if ($weatherData === null) {
        sendPublicApiError(
            PUBLIC_API_ERROR_SERVICE_UNAVAILABLE,
            'Weather data temporarily unavailable',
            503
        );
        return;
    }
    
    // Format weather for response
    $formatted = formatWeatherResponse($weatherData, $airport);
    
    // Build metadata
    $meta = [
        'airport_id' => $airportId,
        'airport_name' => $airport['name'] ?? '',
        'observation_time' => isset($weatherData['obs_time_primary'])
            ? gmdate('c', $weatherData['obs_time_primary'])
            : null,
        'units' => getPublicApiWeatherUnits(),
        'sunrise_sunset_timezone' => $airport['timezone'] ?? 'UTC',
    ];
    
    // Send cache headers for live data
    sendPublicApiCacheHeaders('live');
    
    // Send response
    sendPublicApiSuccess(
        ['weather' => $formatted],
        $meta
    );
}

/**
 * Get weather data from cache file
 * 
 * @param string $airportId Airport ID
 * @return array|null Weather data or null if unavailable
 */
function getWeatherFromCache(string $airportId): ?array
{
    $cacheFile = getWeatherCachePath($airportId);
    
    if (!file_exists($cacheFile)) {
        return null;
    }
    
    $content = @file_get_contents($cacheFile);
    if ($content === false) {
        return null;
    }
    
    $data = json_decode($content, true);
    if (!is_array($data)) {
        return null;
    }
    
    return $data;
}

