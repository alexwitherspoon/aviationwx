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
require_once __DIR__ . '/../../lib/config.php';
require_once __DIR__ . '/../../lib/cache-paths.php';
require_once __DIR__ . '/../../lib/weather/utils.php';

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

/**
 * Format weather data for API response
 * 
 * @param array $weather Raw weather data from cache
 * @param array $airport Airport configuration
 * @return array Formatted weather data
 */
function formatWeatherResponse(array $weather, array $airport): array
{
    return [
        'flight_category' => $weather['flight_category'] ?? null,
        'temperature' => $weather['temperature'] ?? null,
        'temperature_f' => $weather['temperature_f'] ?? null,
        'dewpoint' => $weather['dewpoint'] ?? null,
        'dewpoint_f' => $weather['dewpoint_f'] ?? null,
        'dewpoint_spread' => $weather['dewpoint_spread'] ?? null,
        'humidity' => $weather['humidity'] ?? null,
        'wind_speed' => $weather['wind_speed'] ?? null,
        'wind_direction' => $weather['wind_direction'] ?? null,
        'gust_speed' => $weather['gust_speed'] ?? null,
        'gust_factor' => $weather['gust_factor'] ?? null,
        'pressure' => $weather['pressure'] ?? null,
        'visibility' => $weather['visibility'] ?? null,
        'ceiling' => $weather['ceiling'] ?? null,
        'cloud_cover' => $weather['cloud_cover'] ?? null,
        'precip_accum' => $weather['precip_accum'] ?? null,
        'density_altitude' => $weather['density_altitude'] ?? null,
        'pressure_altitude' => $weather['pressure_altitude'] ?? null,
        'sunrise' => $weather['sunrise'] ?? null,
        'sunset' => $weather['sunset'] ?? null,
        'daily' => [
            'temp_high' => $weather['temp_high_today'] ?? null,
            'temp_high_time' => isset($weather['temp_high_ts']) 
                ? gmdate('c', $weather['temp_high_ts']) 
                : null,
            'temp_low' => $weather['temp_low_today'] ?? null,
            'temp_low_time' => isset($weather['temp_low_ts']) 
                ? gmdate('c', $weather['temp_low_ts']) 
                : null,
            'peak_gust' => $weather['peak_gust_today'] ?? null,
            'peak_gust_time' => isset($weather['peak_gust_time']) 
                ? gmdate('c', $weather['peak_gust_time']) 
                : null,
        ],
        'observation_time' => isset($weather['obs_time_primary']) 
            ? gmdate('c', $weather['obs_time_primary']) 
            : null,
        'last_updated' => isset($weather['last_updated']) 
            ? gmdate('c', $weather['last_updated']) 
            : null,
    ];
}

