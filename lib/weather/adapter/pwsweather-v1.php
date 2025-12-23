<?php
/**
 * PWSWeather.com API Adapter v1 (via AerisWeather API)
 * 
 * Handles fetching and parsing weather data from PWSWeather.com stations
 * through the AerisWeather API.
 * 
 * PWSWeather.com stations upload data to pwsweather.com, and station owners
 * receive access to AerisWeather API to retrieve their station's observations.
 * 
 * API Documentation: https://www.xweather.com/docs/weather-api/endpoints/observations
 * 
 * Configuration Requirements:
 * - station_id: PWSWeather.com station identifier
 * - client_id: AerisWeather API client ID
 * - client_secret: AerisWeather API client secret
 * 
 * Rate Limits:
 * - AerisWeather API has rate limits based on subscription tier
 * - Free tier: Limited requests per day
 * - Paid tiers: Higher rate limits
 * - Circuit breaker logic in fetcher.php handles rate limit errors
 * 
 * Error Handling:
 * - Invalid API responses return null
 * - Missing required fields return null
 * - API errors (success: false) return null
 * - Network timeouts handled by CURL_TIMEOUT constant
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
 * Field Mappings:
 * - tempC -> temperature (Celsius, no conversion)
 * - humidity -> humidity (percentage, no conversion)
 * - pressureIN -> pressure (inHg, no conversion)
 * - windSpeedKTS/windKTS -> wind_speed (knots, no conversion)
 * - windGustKTS -> gust_speed (knots, no conversion)
 * - windDirDEG -> wind_direction (degrees, no conversion)
 * - precipIN -> precip_accum (inches, no conversion)
 * - dewpointC -> dewpoint (Celsius, no conversion)
 * - visibilityMI -> visibility (statute miles, no conversion)
 * - timestamp -> obs_time (Unix seconds, no conversion)
 * 
 * @param string|null $response JSON response from AerisWeather API
 * @return array|null Weather data array with standard keys, or null on parse error
 *                    Returns null on any parse error (invalid JSON, missing fields, API errors)
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
    
    // Validate that we have at least some basic weather data
    // At minimum, we need a timestamp to consider this a valid observation
    if (!isset($obs['timestamp']) || !is_numeric($obs['timestamp'])) {
        return null;
    }
    
    // Parse observation time (when the weather was actually measured)
    // AerisWeather provides timestamp as Unix timestamp in seconds
    // Timestamp already validated above, so safe to cast
    $obsTime = (int)$obs['timestamp'];
    
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
    // AerisWeather API provides windGustKTS field for gust data
    $gustSpeedKts = null;
    if (isset($obs['windGustKTS']) && is_numeric($obs['windGustKTS'])) {
        $gustSpeedKts = (int)round((float)$obs['windGustKTS']);
    }
    
    // Note: AerisWeather/PWSWeather API does not provide daily peak gust fields in the current observations endpoint.
    // Daily peak gust tracking is handled by the application using current gust values.
    $peakGustHistorical = null;
    $peakGustHistoricalObsTime = null;
    
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
    
    // Extract quality metadata (API-specific quality indicators)
    $qualityMetadata = [];
    
    // Extract QCcode from observation data (quality code)
    // QCcode: 10 = data quality issues (sensor problems)
    if (isset($obs['QCcode']) && is_numeric($obs['QCcode'])) {
        $qualityMetadata['qc_code'] = (int)$obs['QCcode'];
        // QCcode: 10 indicates known sensor problems
        $qualityMetadata['has_quality_issues'] = ($obs['QCcode'] == 10);
    }
    
    $result = [
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
        'peak_gust_historical' => $peakGustHistorical, // Daily peak gust from API if available
        'peak_gust_historical_obs_time' => $peakGustHistoricalObsTime,
        'obs_time' => $obsTime,
    ];
    
    
    // Add quality metadata if present (internal only)
    if (!empty($qualityMetadata)) {
        $result['_quality_metadata'] = $qualityMetadata;
    }
    
    return $result;
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

