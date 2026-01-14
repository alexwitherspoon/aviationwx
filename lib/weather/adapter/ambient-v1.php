<?php
/**
 * Ambient Weather API Adapter v1
 * 
 * Handles fetching and parsing weather data from Ambient Weather API.
 * API documentation: https://ambientweather.docs.apiary.io/
 */

require_once __DIR__ . '/../../constants.php';
require_once __DIR__ . '/../../test-mocks.php';
require_once __DIR__ . '/../data/WeatherReading.php';
require_once __DIR__ . '/../data/WindGroup.php';
require_once __DIR__ . '/../data/WeatherSnapshot.php';

use AviationWX\Weather\Data\WeatherReading;
use AviationWX\Weather\Data\WindGroup;
use AviationWX\Weather\Data\WeatherSnapshot;

/**
 * Ambient Weather Adapter Class (implements new interface pattern)
 * 
 * Ambient Weather stations provide real-time weather data.
 * Updates every ~60 seconds but can vary by model.
 */
class AmbientAdapter {
    
    /** Fields this adapter can provide */
    public const FIELDS_PROVIDED = [
        'temperature',
        'dewpoint',
        'humidity',
        'pressure',
        'wind_speed',
        'wind_direction',
        'gust_speed',
        'precip_accum',
    ];
    
    /** Typical update frequency in seconds */
    public const UPDATE_FREQUENCY = 60;
    
    /** Max acceptable age before data is stale (5 minutes) */
    public const MAX_ACCEPTABLE_AGE = 300;
    
    /** Source type identifier */
    public const SOURCE_TYPE = 'ambient';
    
    /**
     * Get fields this adapter can provide
     */
    public static function getFieldsProvided(): array {
        return self::FIELDS_PROVIDED;
    }
    
    /**
     * Get typical update frequency in seconds
     */
    public static function getTypicalUpdateFrequency(): int {
        return self::UPDATE_FREQUENCY;
    }
    
    /**
     * Get maximum acceptable age before data is stale
     */
    public static function getMaxAcceptableAge(): int {
        return self::MAX_ACCEPTABLE_AGE;
    }
    
    /**
     * Get source type identifier
     */
    public static function getSourceType(): string {
        return self::SOURCE_TYPE;
    }
    
    /**
     * Check if this adapter provides a specific field
     */
    public static function providesField(string $fieldName): bool {
        return in_array($fieldName, self::FIELDS_PROVIDED, true);
    }
    
    /**
     * Build the API URL for fetching data
     */
    public static function buildUrl(array $config): ?string {
        if (!isset($config['api_key']) || !isset($config['application_key'])) {
            return null;
        }
        $apiKey = $config['api_key'];
        $appKey = $config['application_key'];
        
        if (isset($config['mac_address']) && !empty($config['mac_address'])) {
            return "https://rt.ambientweather.net/v1/devices/{$config['mac_address']}?apiKey={$apiKey}&applicationKey={$appKey}";
        }
        return "https://rt.ambientweather.net/v1/devices?apiKey={$apiKey}&applicationKey={$appKey}";
    }
    
    /**
     * Parse API response into a WeatherSnapshot
     * 
     * Units returned:
     * - Temperature/Dewpoint: Celsius (converted from F)
     * - Humidity: Percent
     * - Pressure: inHg
     * - Precipitation: inches
     * - Wind: knots (converted from mph)
     * 
     * @param string $response Raw API response
     * @param array $config Source configuration
     * @return WeatherSnapshot|null
     */
    public static function parseToSnapshot(string $response, array $config = []): ?WeatherSnapshot {
        // Use existing parser
        $parsed = parseAmbientResponse($response);
        if ($parsed === null) {
            return WeatherSnapshot::empty(self::SOURCE_TYPE);
        }
        
        $obsTime = $parsed['obs_time'] ?? time();
        $source = self::SOURCE_TYPE;
        
        $hasCompleteWind = $parsed['wind_speed'] !== null && $parsed['wind_direction'] !== null;
        
        return new WeatherSnapshot(
            source: $source,
            fetchTime: time(),
            temperature: WeatherReading::celsius($parsed['temperature'], $source, $obsTime),
            dewpoint: WeatherReading::celsius($parsed['dewpoint'], $source, $obsTime),
            humidity: WeatherReading::percent($parsed['humidity'], $source, $obsTime),
            pressure: WeatherReading::inHg($parsed['pressure'], $source, $obsTime),
            precipAccum: WeatherReading::inches($parsed['precip_accum'], $source, $obsTime),
            wind: $hasCompleteWind
                ? WindGroup::from(
                    $parsed['wind_speed'],
                    $parsed['wind_direction'],
                    $parsed['gust_speed'],
                    $source,
                    $obsTime
                )
                : WindGroup::empty(),
            visibility: WeatherReading::null($source), // Ambient doesn't provide visibility
            ceiling: WeatherReading::null($source),    // Ambient doesn't provide ceiling
            cloudCover: WeatherReading::null($source), // Ambient doesn't provide cloud cover
            isValid: true
        );
    }
}

// =============================================================================
// LEGACY FUNCTIONS (kept for backward compatibility during migration)
// =============================================================================

/**
 * Parse Ambient Weather API response
 * 
 * Parses JSON response from Ambient Weather API and converts to standard format.
 * Handles unit conversions (Fahrenheit to Celsius, mph to knots).
 * Observation time is converted from milliseconds to seconds.
 * 
 * Supports both array response (multiple devices) and single device object response.
 * 
 * @param string $response JSON response from Ambient Weather API
 * @return array|null Weather data array with standard keys, or null on parse error
 */
function parseAmbientResponse($response): ?array {
    if (!is_string($response)) {
        return null;
    }
    $data = json_decode($response, true);
    
    // Handle multiple response formats:
    // 1. Array of devices: [{"lastData": {...}}, ...] - use first device's lastData
    // 2. Single device object: {"lastData": {...}} - use lastData
    // 3. Array of observations: [{observation}, ...] - use first observation (most recent)
    $obs = null;
    if (isset($data[0]) && isset($data[0]['lastData'])) {
        // Array response: multiple devices, use first one's lastData
        $obs = $data[0]['lastData'];
    } elseif (isset($data['lastData'])) {
        // Single device object response
        $obs = $data['lastData'];
    } elseif (isset($data[0]) && is_array($data[0]) && isset($data[0]['dateutc'])) {
        // Array of observations directly (no device wrapper) - use first observation (most recent)
        $obs = $data[0];
    } else {
        return null;
    }
    
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
    
    // Note: Ambient Weather API does not provide daily peak gust fields in the current observations endpoint.
    // Daily peak gust tracking is handled by the application using current gust values.
    $peakGustHistorical = null;
    $peakGustHistoricalObsTime = null;
    
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
        'peak_gust_historical' => $peakGustHistorical, // Daily peak gust from API if available
        'peak_gust_historical_obs_time' => $peakGustHistoricalObsTime,
        'obs_time' => $obsTime, // Observation time when weather was actually measured
    ];
}

/**
 * Fetch weather from Ambient Weather API (synchronous, for fallback)
 * 
 * Makes HTTP request to Ambient Weather API to fetch current weather observations.
 * Requires both API key and application key. Used as fallback when async fetch fails.
 * 
 * If mac_address is provided, fetches data for that specific device.
 * Otherwise, fetches all devices and uses the first one.
 * 
 * @param array $source Weather source configuration (must contain 'api_key' and 'application_key', optionally 'mac_address')
 * @return array|null Weather data array with standard keys, or null on failure
 */
function fetchAmbientWeather($source): ?array {
    // Ambient Weather API requires API Key and Application Key
    if (!is_array($source) || !isset($source['api_key']) || !isset($source['application_key'])) {
        return null;
    }
    
    $apiKey = $source['api_key'];
    $applicationKey = $source['application_key'];
    $macAddress = isset($source['mac_address']) ? trim($source['mac_address']) : null;
    
    // Sanitize MAC address if provided (remove extra whitespace, normalize)
    if ($macAddress) {
        $macAddress = preg_replace('/\s+/', '', $macAddress); // Remove all whitespace
        if (empty($macAddress)) {
            $macAddress = null; // Treat empty after sanitization as no MAC address
        }
    }
    
    // Build URL: use specific device endpoint if MAC address provided, otherwise device list endpoint
    if ($macAddress) {
        // Fetch specific device by MAC address (MAC address used directly in path, no encoding needed)
        $url = "https://api.ambientweather.net/v1/devices/{$macAddress}?applicationKey={$applicationKey}&apiKey={$apiKey}";
    } else {
        // Fetch all devices (uses first device in response)
        $url = "https://api.ambientweather.net/v1/devices?applicationKey={$applicationKey}&apiKey={$apiKey}";
    }
    
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
        
        $response = @file_get_contents($url, false, $context);if ($response === false) {return null;
        }}
    
    $parsed = parseAmbientResponse($response);return $parsed;
}

