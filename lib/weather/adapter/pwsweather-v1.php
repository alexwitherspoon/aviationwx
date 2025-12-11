<?php
/**
 * PWSWeather.com API Adapter v1 (via AerisWeather API)
 * 
 * Handles fetching and parsing weather data from PWSWeather.com stations
 * through the AerisWeather API.
 * 
 * PWSWeather.com stations upload data to pwsweather.com, and station owners
 * receive access to AerisWeather API to retrieve their station's observations.
 * API documentation: https://www.xweather.com/docs/weather-api/endpoints/observations
 */

require_once __DIR__ . '/../../constants.php';
require_once __DIR__ . '/../../test-mocks.php';

/**
 * Parse AerisWeather API response
 * 
 * Parses JSON response from AerisWeather API and converts to standard format.
 * Handles unit conversions where needed (visibility already in statute miles).
 * Observation time is provided as Unix timestamp in seconds.
 * 
 * @param string|null $response JSON response from AerisWeather API
 * @return array|null Weather data array with standard keys, or null on parse error
 */
function parsePWSWeatherResponse(?string $response): ?array {
    if ($response === null || $response === '' || !is_string($response)) {
        return null;
    }
    
    $data = json_decode($response, true);
    if ($data === null || !is_array($data)) {
        return null;
    }
    
    // Check for API errors
    if (isset($data['success']) && $data['success'] === false) {
        return null;
    }
    
    if (!isset($data['response']) || !is_array($data['response'])) {
        return null;
    }
    
    $responseData = $data['response'];
    
    // Get observation data from periods array
    if (!isset($responseData['periods']) || !is_array($responseData['periods']) || empty($responseData['periods'])) {
        return null;
    }
    
    $period = $responseData['periods'][0];
    if (!isset($period['ob']) || !is_array($period['ob'])) {
        return null;
    }
    
    $obs = $period['ob'];
    
    // Parse observation time (when the weather was actually measured)
    // AerisWeather provides timestamp as Unix timestamp in seconds
    $obsTime = null;
    if (isset($obs['timestamp']) && is_numeric($obs['timestamp'])) {
        $obsTime = (int)$obs['timestamp'];
    }
    
    // Temperature - already in Celsius
    $temperature = isset($obs['tempC']) && is_numeric($obs['tempC']) ? (float)$obs['tempC'] : null;
    
    // Humidity - percentage
    $humidity = isset($obs['humidity']) && is_numeric($obs['humidity']) ? (float)$obs['humidity'] : null;
    
    // Pressure - already in inHg
    $pressure = isset($obs['pressureIN']) && is_numeric($obs['pressureIN']) ? (float)$obs['pressureIN'] : null;
    
    // Wind speed - already in knots
    $windSpeedKts = null;
    if (isset($obs['windSpeedKTS']) && is_numeric($obs['windSpeedKTS'])) {
        $windSpeedKts = (int)round((float)$obs['windSpeedKTS']);
    } elseif (isset($obs['windKTS']) && is_numeric($obs['windKTS'])) {
        $windSpeedKts = (int)round((float)$obs['windKTS']);
    }
    
    // Wind direction - degrees
    $windDirection = null;
    if (isset($obs['windDirDEG']) && is_numeric($obs['windDirDEG'])) {
        $windDirection = (int)round((float)$obs['windDirDEG']);
    }
    
    // Gust speed - already in knots
    $gustSpeedKts = null;
    if (isset($obs['windSpeedKTS']) && is_numeric($obs['windSpeedKTS'])) {
        // Use wind speed as gust if no separate gust field
        $gustSpeedKts = $windSpeedKts;
    }
    
    // Precipitation - already in inches
    $precip = isset($obs['precipIN']) && is_numeric($obs['precipIN']) ? (float)$obs['precipIN'] : 0;
    
    // Dewpoint - already in Celsius
    $dewpoint = isset($obs['dewpointC']) && is_numeric($obs['dewpointC']) ? (float)$obs['dewpointC'] : null;
    
    // Visibility - already in statute miles (matches METAR format)
    $visibility = null;
    if (isset($obs['visibilityMI']) && is_numeric($obs['visibilityMI'])) {
        $visibility = (float)$obs['visibilityMI']; // Already in statute miles
    }
    
    // Ceiling - sky cover interpretation
    // AerisWeather provides 'sky' field (0-8 scale) which we can interpret
    // 0 = clear, 1-2 = few, 3-4 = scattered, 5-6 = broken, 7-8 = overcast
    // For now, we'll leave ceiling as null since we don't have base height
    $ceiling = null;
    
    return [
        'temperature' => $temperature,
        'humidity' => $humidity,
        'pressure' => $pressure,
        'wind_speed' => $windSpeedKts,
        'wind_direction' => $windDirection,
        'gust_speed' => $gustSpeedKts,
        'precip_accum' => $precip,
        'dewpoint' => $dewpoint,
        'visibility' => $visibility,
        'ceiling' => $ceiling,
        'temp_high' => null, // Not available from current observations endpoint
        'temp_low' => null,  // Not available from current observations endpoint
        'peak_gust' => $gustSpeedKts,
        'obs_time' => $obsTime,
    ];
}

/**
 * Fetch weather from AerisWeather API (synchronous, for fallback)
 * 
 * Makes HTTP request to AerisWeather API to fetch current weather observations
 * for a PWSWeather.com station. Requires client_id, client_secret, and station_id.
 * Used as fallback when async fetch fails.
 * 
 * @param array $source Weather source configuration (must contain 'station_id', 'client_id', and 'client_secret')
 * @return array|null Weather data array with standard keys, or null on failure
 */
function fetchPWSWeather($source): ?array {
    if (!is_array($source) || !isset($source['station_id']) || !isset($source['client_id']) || !isset($source['client_secret'])) {
        return null;
    }
    
    $stationId = $source['station_id'];
    $clientId = $source['client_id'];
    $clientSecret = $source['client_secret'];
    
    // Fetch current observation from AerisWeather API
    $url = "https://api.aerisapi.com/observations/{$stationId}?client_id=" . urlencode($clientId) . "&client_secret=" . urlencode($clientSecret);
    
    // Check for mock response in test mode
    $mockResponse = getMockHttpResponse($url);
    if ($mockResponse !== null) {
        $response = $mockResponse;
    } else {
        // Create context with explicit timeout
        $context = stream_context_create([
            'http' => [
                'timeout' => CURL_TIMEOUT,
                'ignore_errors' => true,
            ],
        ]);
        
        $response = @file_get_contents($url, false, $context);
        
        if ($response === false) {
            return null;
        }
    }
    
    return parsePWSWeatherResponse($response);
}
