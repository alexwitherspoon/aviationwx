<?php
/**
 * Tempest WeatherFlow API Adapter v1
 * 
 * Handles fetching and parsing weather data from Tempest WeatherFlow API.
 * API documentation: https://weatherflow.github.io/Tempest/api/
 */

require_once __DIR__ . '/../../constants.php';
require_once __DIR__ . '/../../test-mocks.php';

/**
 * Parse Tempest API response
 * 
 * Parses JSON response from Tempest WeatherFlow API and converts to standard format.
 * Handles unit conversions (m/s to knots, mb to inHg, mm to inches).
 * Observation time is provided as Unix timestamp in seconds.
 * 
 * @param string|null $response JSON response from Tempest API
 * @return array|null Weather data array with standard keys, or null on parse error
 */
function parseTempestResponse($response): ?array {
    if ($response === null || $response === '' || !is_string($response)) {
        return null;
    }
    $data = json_decode($response, true);
    if (!isset($data['obs'][0])) {
        return null;
    }
    
    $obs = $data['obs'][0];
    
    // Parse observation time (when the weather was actually measured)
    // Tempest provides timestamp as Unix timestamp in seconds
    $obsTime = null;
    if (isset($obs['timestamp']) && is_numeric($obs['timestamp'])) {
        $obsTime = (int)$obs['timestamp'];
    }
    
    // Note: Daily stats (high/low temp, peak gust) are not available from the basic Tempest API
    // These would require a different API endpoint or subscription level
    $tempHigh = null;
    $tempLow = null;
    $peakGust = null;
    
    // Use current gust as peak gust (as it's the only gust data available)
    // This will be set later if wind_gust is numeric
    
    // Convert pressure from mb to inHg
    $pressureInHg = isset($obs['sea_level_pressure']) ? $obs['sea_level_pressure'] / 33.8639 : null;
    
    // Convert wind speed from m/s to knots
    // Add type checks to handle unexpected input types gracefully
    $windSpeedKts = null;
    if (isset($obs['wind_avg']) && is_numeric($obs['wind_avg'])) {
        $windSpeedKts = (int)round((float)$obs['wind_avg'] * 1.943844);
    }
    $gustSpeedKts = null;
    if (isset($obs['wind_gust']) && is_numeric($obs['wind_gust'])) {
        $gustSpeedKts = (int)round((float)$obs['wind_gust'] * 1.943844);
    }
    // Also update peak_gust calculation
    if ($gustSpeedKts !== null) {
        $peakGust = $gustSpeedKts;
    }
    
    return [
        'temperature' => isset($obs['air_temperature']) ? $obs['air_temperature'] : null, // Celsius
        'humidity' => isset($obs['relative_humidity']) ? $obs['relative_humidity'] : null,
        'pressure' => $pressureInHg, // sea level pressure in inHg
        'wind_speed' => $windSpeedKts,
        'wind_direction' => isset($obs['wind_direction']) && is_numeric($obs['wind_direction']) ? (int)round((float)$obs['wind_direction']) : null,
        'gust_speed' => $gustSpeedKts,
        'precip_accum' => isset($obs['precip_accum_local_day_final']) ? $obs['precip_accum_local_day_final'] * 0.0393701 : 0, // mm to inches
        'dewpoint' => isset($obs['dew_point']) ? $obs['dew_point'] : null,
        'visibility' => null, // Not available from Tempest
        'ceiling' => null, // Not available from Tempest
        'temp_high' => $tempHigh,
        'temp_low' => $tempLow,
        'peak_gust' => $peakGust,
        'obs_time' => $obsTime, // Observation time when weather was actually measured
    ];
}

/**
 * Fetch weather from Tempest API (synchronous, for fallback)
 * 
 * Makes HTTP request to Tempest WeatherFlow API to fetch current weather observations.
 * Used as fallback when async fetch fails. Returns parsed weather data or null on failure.
 * 
 * @param array $source Weather source configuration (must contain 'api_key' and 'station_id')
 * @return array|null Weather data array with standard keys, or null on failure
 */
function fetchTempestWeather($source): ?array {
    if (!is_array($source) || !isset($source['api_key']) || !isset($source['station_id'])) {
        return null;
    }
    
    $apiKey = $source['api_key'];
    $stationId = $source['station_id'];
    
    // Fetch current observation
    $url = "https://swd.weatherflow.com/swd/rest/observations/station/{$stationId}?token={$apiKey}";
    
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
    
    return parseTempestResponse($response);
}

