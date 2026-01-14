<?php
/**
 * Embed Widget Helpers
 * 
 * Helper functions for embed widgets to fetch data from the public API.
 * This ensures widgets use the same data source as external API consumers.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/cache-paths.php';
require_once __DIR__ . '/weather/daily-tracking.php';

// Load public API middleware functions (but don't execute middleware checks)
// We only need getPublicApiAirport() function, not the full middleware
require_once __DIR__ . '/public-api/middleware.php';

/**
 * Fetch latest weather data using the same logic as the public API
 * 
 * Calls the weather API handler function directly to get the most recent data.
 * This ensures we get fresh data that has been processed with daily tracking updates.
 * 
 * @param string $airportId Airport identifier
 * @param array $airport Airport configuration
 * @return array|null Weather data array or null if unavailable
 */
function fetchLatestWeatherFromApi(string $airportId, array $airport): ?array {
    // Trigger weather API refresh to ensure we have the latest data
    // This uses the stale-while-revalidate pattern - serves cache immediately but refreshes in background
    // For widgets, we want the absolute latest, so we'll make a request that triggers refresh
    
    // Determine base URL for internal API call
    // Use localhost directly for internal calls (faster, avoids DNS)
    $weatherUrl = "http://127.0.0.1:8080/api/weather.php?airport=" . urlencode($airportId);
    
    // Make internal HTTP request to trigger weather refresh/get latest
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $weatherUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => [
            'X-Embed-Widget: 1', // Identify as internal widget request
        ],
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    // Parse weather API response
    if ($httpCode === 200 && $response !== false && empty($error)) {
        $data = json_decode($response, true);
        if (is_array($data) && isset($data['success']) && $data['success'] && isset($data['weather'])) {
            $weatherData = $data['weather'];
            
            // CRITICAL: Always update daily tracking with the LATEST temperature from the API response
            // This ensures that when a new day starts, the extremes reflect the current temperature, not an old initialization value
            // The weather API response contains the most recent temperature, so we use that to update tracking
            $currentTemp = $weatherData['temperature'] ?? null;
            
            if ($currentTemp !== null) {
                // Update daily tracking with current temperature from API (this is the latest value)
                // This is especially important when a new day starts - we want extremes to reflect the current temp, not old init values
                $obsTimestamp = $weatherData['obs_time_primary'] ?? $weatherData['last_updated_primary'] ?? time();
                updateTempExtremes($airportId, $currentTemp, $airport, $obsTimestamp);
                
                // Immediately get the updated extremes after updating
                // If day just reset, both high and low should now equal currentTemp
                $tempExtremes = getTempExtremes($airportId, $currentTemp, $airport);
                
                // Override the weather data with the freshly updated extremes
                // This ensures widgets always show the latest extremes based on current temperature
                $weatherData['temp_high_today'] = $tempExtremes['high'];
                $weatherData['temp_low_today'] = $tempExtremes['low'];
                $weatherData['temp_high_ts'] = $tempExtremes['high_ts'] ?? null;
                $weatherData['temp_low_ts'] = $tempExtremes['low_ts'] ?? null;
            } else {
                // No current temp - still get extremes (might be from earlier today)
                $tempExtremes = getTempExtremes($airportId, 0, $airport);
                $weatherData['temp_high_today'] = $tempExtremes['high'];
                $weatherData['temp_low_today'] = $tempExtremes['low'];
                $weatherData['temp_high_ts'] = $tempExtremes['high_ts'] ?? null;
                $weatherData['temp_low_ts'] = $tempExtremes['low_ts'] ?? null;
            }
            
            return $weatherData;
        }
    }
    
    // Fallback: read from cache file directly if API call fails
    require_once __DIR__ . '/../api/v1/weather.php';
    $weatherData = getWeatherFromCache($airportId);
    
    if ($weatherData === null) {
        return null;
    }
    
    // Ensure daily tracking is up to date even from cache
    $currentTemp = $weatherData['temperature'] ?? null;
    if ($currentTemp !== null) {
        $obsTimestamp = $weatherData['obs_time_primary'] ?? $weatherData['last_updated_primary'] ?? time();
        updateTempExtremes($airportId, $currentTemp, $airport, $obsTimestamp);
        $tempExtremes = getTempExtremes($airportId, $currentTemp, $airport);
        $weatherData['temp_high_today'] = $tempExtremes['high'];
        $weatherData['temp_low_today'] = $tempExtremes['low'];
        $weatherData['temp_high_ts'] = $tempExtremes['high_ts'] ?? null;
        $weatherData['temp_low_ts'] = $tempExtremes['low_ts'] ?? null;
    }
    
    return $weatherData;
}

/**
 * Fetch airport and weather data from public API for embed widgets
 * 
 * Uses the same data source as the public API endpoints.
 * This ensures widgets get daily tracking data (temp_high_today, temp_low_today)
 * that's already calculated and stored in the weather cache.
 * 
 * @param string $airportId Airport identifier
 * @return array|null {
 *   'airport' => array,  // Airport configuration data
 *   'weather' => array,  // Weather data with daily tracking (temp_high_today, temp_low_today)
 *   'airportId' => string
 * } or null if airport not found
 */
function fetchEmbedDataFromApi(string $airportId): ?array {
    // Normalize airport ID (same validation as public API)
    $trimmed = trim($airportId);
    if (empty($trimmed) || strlen($trimmed) < 3 || strlen($trimmed) > 20) {
        return null;
    }
    if (!preg_match('/^[a-zA-Z0-9-]+$/i', $trimmed)) {
        return null;
    }
    $normalizedId = strtolower($trimmed);
    
    // Get airport data (same function used by public API)
    // getPublicApiAirport() doesn't require API to be enabled - it just gets airport config
    $airport = getPublicApiAirport($normalizedId);
    if ($airport === null) {
        return null;
    }
    
    // Fetch latest weather data from public API endpoint (ensures fresh data)
    // This triggers the weather API to fetch/refresh data and return the latest values
    $weatherData = fetchLatestWeatherFromApi($normalizedId, $airport);
    
    if ($weatherData === null) {
        // Weather unavailable - return airport data only
        return [
            'airport' => $airport,
            'weather' => [],
            'airportId' => $normalizedId
        ];
    }
    
    return [
        'airport' => $airport,
        'weather' => $weatherData,
        'airportId' => $normalizedId
    ];
}
