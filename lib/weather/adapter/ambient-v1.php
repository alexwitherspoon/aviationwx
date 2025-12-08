<?php
/**
 * Ambient Weather API Adapter v1
 * 
 * Handles fetching and parsing weather data from Ambient Weather API.
 * API documentation: https://ambientweather.docs.apiary.io/
 */

require_once __DIR__ . '/../../constants.php';
require_once __DIR__ . '/../../test-mocks.php';

/**
 * Parse Ambient Weather API response
 * 
 * Parses JSON response from Ambient Weather API and converts to standard format.
 * Handles unit conversions (Fahrenheit to Celsius, mph to knots).
 * Observation time is converted from milliseconds to seconds.
 * 
 * @param string $response JSON response from Ambient Weather API
 * @return array|null Weather data array with standard keys, or null on parse error
 */
function parseAmbientResponse($response): ?array {
    if (!is_string($response)) {
        return null;
    }
    $data = json_decode($response, true);
    
    if (!isset($data[0]) || !isset($data[0]['lastData'])) {
        return null;
    }
    
    $obs = $data[0]['lastData'];
    
    // Parse observation time (when the weather was actually measured)
    // Ambient Weather provides dateutc in milliseconds (Unix timestamp * 1000)
    $obsTime = null;
    if (isset($obs['dateutc']) && is_numeric($obs['dateutc'])) {
        // Convert from milliseconds to seconds
        $obsTime = (int)($obs['dateutc'] / 1000);
    }
    
    // Convert all measurements to our standard format
    $temperature = isset($obs['tempf']) && is_numeric($obs['tempf']) ? ((float)$obs['tempf'] - 32) / 1.8 : null; // F to C
    $humidity = isset($obs['humidity']) ? $obs['humidity'] : null;
    $pressure = isset($obs['baromrelin']) ? $obs['baromrelin'] : null; // Already in inHg
    $windSpeed = isset($obs['windspeedmph']) && is_numeric($obs['windspeedmph']) ? (int)round((float)$obs['windspeedmph'] * 0.868976) : null; // mph to knots
    $windDirection = isset($obs['winddir']) && is_numeric($obs['winddir']) ? (int)round((float)$obs['winddir']) : null;
    $gustSpeed = isset($obs['windgustmph']) && is_numeric($obs['windgustmph']) ? (int)round((float)$obs['windgustmph'] * 0.868976) : null; // mph to knots
    $precip = isset($obs['dailyrainin']) ? $obs['dailyrainin'] : 0; // Already in inches
    $dewpoint = isset($obs['dewPoint']) && is_numeric($obs['dewPoint']) ? ((float)$obs['dewPoint'] - 32) / 1.8 : null; // F to C
    
    return [
        'temperature' => $temperature,
        'humidity' => $humidity,
        'pressure' => $pressure,
        'wind_speed' => $windSpeed,
        'wind_direction' => $windDirection,
        'gust_speed' => $gustSpeed,
        'precip_accum' => $precip,
        'dewpoint' => $dewpoint,
        'visibility' => null, // Not available from Ambient Weather
        'ceiling' => null, // Not available from Ambient Weather
        'temp_high' => null,
        'temp_low' => null,
        'peak_gust' => $gustSpeed,
        'obs_time' => $obsTime, // Observation time when weather was actually measured
    ];
}

/**
 * Fetch weather from Ambient Weather API (synchronous, for fallback)
 * 
 * Makes HTTP request to Ambient Weather API to fetch current weather observations.
 * Requires both API key and application key. Used as fallback when async fetch fails.
 * 
 * @param array $source Weather source configuration (must contain 'api_key' and 'application_key')
 * @return array|null Weather data array with standard keys, or null on failure
 */
function fetchAmbientWeather($source): ?array {
    // Ambient Weather API requires API Key and Application Key
    if (!is_array($source) || !isset($source['api_key']) || !isset($source['application_key'])) {
        return null;
    }
    
    $apiKey = $source['api_key'];
    $applicationKey = $source['application_key'];
    
    // Fetch current conditions (uses device list endpoint)
    $url = "https://api.ambientweather.net/v1/devices?applicationKey={$applicationKey}&apiKey={$apiKey}";
    
    // Check for mock response in test mode
    $mockResponse = getMockHttpResponse($url);
    if ($mockResponse !== null) {
        $response = $mockResponse;
    } else {
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);
        
        $response = @file_get_contents($url, false, $context);
        
        if ($response === false) {
            return null;
        }
    }
    
    return parseAmbientResponse($response);
}

