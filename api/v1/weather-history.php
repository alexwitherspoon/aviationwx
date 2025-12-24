<?php
/**
 * Public API - Get Weather History Endpoint
 * 
 * GET /v1/airports/{id}/weather/history
 * 
 * Returns 24-hour rolling weather history for an airport.
 * 
 * Query parameters:
 * - hours: Number of hours of history (1-24, default 24)
 * - start: Start timestamp (optional)
 * - end: End timestamp (optional)
 * - resolution: 'all', 'hourly', or '15min' (default 'all')
 */

require_once __DIR__ . '/../../lib/public-api/middleware.php';
require_once __DIR__ . '/../../lib/public-api/response.php';
require_once __DIR__ . '/../../lib/public-api/config.php';
require_once __DIR__ . '/../../lib/weather/history.php';

/**
 * Handle GET /v1/airports/{id}/weather/history request
 * 
 * @param array $params Path parameters [0 => airport_id]
 * @param array $context Request context from middleware
 */
function handleGetWeatherHistory(array $params, array $context): void
{
    // Check if weather history is enabled
    if (!isPublicApiWeatherHistoryEnabled()) {
        sendPublicApiError(
            PUBLIC_API_ERROR_INVALID_REQUEST,
            'Weather history is not enabled',
            404
        );
        return;
    }
    
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
    
    // Parse query parameters
    $hours = isset($_GET['hours']) ? (int)$_GET['hours'] : 24;
    $hours = max(1, min(24, $hours)); // Clamp to 1-24
    
    $startTime = isset($_GET['start']) ? (int)$_GET['start'] : (time() - ($hours * 3600));
    $endTime = isset($_GET['end']) ? (int)$_GET['end'] : time();
    
    $resolution = $_GET['resolution'] ?? 'all';
    if (!in_array($resolution, ['all', 'hourly', '15min'])) {
        $resolution = 'all';
    }
    
    // Get weather history
    $history = getWeatherHistory($airportId, $startTime, $endTime, $resolution);
    
    // Format observations for response
    $observations = array_map(function ($obs) {
        return [
            'obs_time' => $obs['obs_time'] ?? null,
            'obs_time_iso' => $obs['obs_time_iso'] ?? null,
            'temperature' => $obs['temperature'] ?? null,
            'temperature_f' => $obs['temperature_f'] ?? null,
            'dewpoint' => $obs['dewpoint'] ?? null,
            'humidity' => $obs['humidity'] ?? null,
            'wind_speed' => $obs['wind_speed'] ?? null,
            'wind_direction' => $obs['wind_direction'] ?? null,
            'gust_speed' => $obs['gust_speed'] ?? null,
            'pressure' => $obs['pressure'] ?? null,
            'visibility' => $obs['visibility'] ?? null,
            'ceiling' => $obs['ceiling'] ?? null,
            'cloud_cover' => $obs['cloud_cover'] ?? null,
            'flight_category' => $obs['flight_category'] ?? null,
        ];
    }, $history['observations']);
    
    // Build metadata
    $meta = [
        'airport_id' => $airportId,
        'airport_name' => $airport['name'] ?? '',
        'start_time' => $history['start_time'] ? gmdate('c', $history['start_time']) : null,
        'end_time' => $history['end_time'] ? gmdate('c', $history['end_time']) : null,
        'observation_count' => $history['observation_count'],
        'resolution' => $resolution,
        'units' => getPublicApiWeatherUnits(),
    ];
    
    // Send cache headers for live data (history changes as new observations arrive)
    sendPublicApiCacheHeaders('live');
    
    // Send response
    sendPublicApiSuccess(
        ['observations' => $observations],
        $meta
    );
}

