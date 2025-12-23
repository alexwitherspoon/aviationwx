<?php
/**
 * SynopticData.com Weather API Adapter v1
 * 
 * Handles fetching and parsing weather data from SynopticData.com Weather API.
 * API documentation: https://docs.synopticdata.com/services/weather-api
 * 
 * SynopticData provides access to over 170,000 weather stations worldwide
 * with comprehensive weather observations including temperature, wind, pressure,
 * humidity, precipitation, and more. Typically used selectively on airports
 * where other primary sources (Tempest, Ambient, WeatherLink, PWSWeather) aren't available.
 * 
 * Configuration Requirements:
 * - station_id: SynopticData station identifier (STID)
 * - api_token: SynopticData API token (required for authentication)
 * 
 * API Endpoint:
 * - Latest observations: https://api.synopticdata.com/v2/stations/latest
 * 
 * Rate Limits:
 * - SynopticData API has rate limits based on subscription tier
 * - Circuit breaker logic in fetcher.php handles rate limit errors
 * 
 * Error Handling:
 * - Invalid API responses return null
 * - Missing required fields return null
 * - API errors (status: ERROR) return null
 * - Network timeouts handled by CURL_TIMEOUT constant
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
 * SynopticData Adapter Class (implements new interface pattern)
 * 
 * SynopticData is an aggregator of 170,000+ weather stations.
 * Updates every 5-10 minutes (slower than direct sensor APIs).
 * Good backup source when primary sensors are unavailable.
 */
class SynopticDataAdapter {
    
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
        'visibility', // May be available from some stations
    ];
    
    /** Typical update frequency in seconds (5-10 min) */
    public const UPDATE_FREQUENCY = 600;
    
    /** Max acceptable age before data is stale (30 minutes) */
    public const MAX_ACCEPTABLE_AGE = 1800;
    
    /** Source type identifier */
    public const SOURCE_TYPE = 'synopticdata';
    
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
        if (!isset($config['station_id']) || !isset($config['api_token'])) {
            return null;
        }
        $vars = 'air_temp,relative_humidity,pressure,sea_level_pressure,altimeter,wind_speed,wind_direction,wind_gust,dew_point_temperature,precip_accum_since_local_midnight,precip_accum_24_hour,visibility';
        return "https://api.synopticdata.com/v2/stations/latest?stid=" . urlencode($config['station_id']) . "&token=" . urlencode($config['api_token']) . "&vars=" . urlencode($vars);
    }
    
    /**
     * Parse API response into a WeatherSnapshot
     * 
     * @param string $response Raw API response
     * @param array $config Source configuration
     * @return WeatherSnapshot|null
     */
    public static function parseToSnapshot(string $response, array $config = []): ?WeatherSnapshot {
        // Use existing parser
        $parsed = parseSynopticDataResponse($response);
        if ($parsed === null) {
            return WeatherSnapshot::empty(self::SOURCE_TYPE);
        }
        
        $obsTime = $parsed['obs_time'] ?? time();
        $source = self::SOURCE_TYPE;
        
        $hasCompleteWind = $parsed['wind_speed'] !== null && $parsed['wind_direction'] !== null;
        
        return new WeatherSnapshot(
            source: $source,
            fetchTime: time(),
            temperature: $parsed['temperature'] !== null 
                ? WeatherReading::from($parsed['temperature'], $source, $obsTime)
                : WeatherReading::null($source),
            dewpoint: $parsed['dewpoint'] !== null
                ? WeatherReading::from($parsed['dewpoint'], $source, $obsTime)
                : WeatherReading::null($source),
            humidity: $parsed['humidity'] !== null
                ? WeatherReading::from($parsed['humidity'], $source, $obsTime)
                : WeatherReading::null($source),
            pressure: $parsed['pressure'] !== null
                ? WeatherReading::from($parsed['pressure'], $source, $obsTime)
                : WeatherReading::null($source),
            precipAccum: $parsed['precip_accum'] !== null
                ? WeatherReading::from($parsed['precip_accum'], $source, $obsTime)
                : WeatherReading::null($source),
            wind: $hasCompleteWind
                ? WindGroup::from(
                    $parsed['wind_speed'],
                    $parsed['wind_direction'],
                    $parsed['gust_speed'],
                    $source,
                    $obsTime
                )
                : WindGroup::empty(),
            visibility: $parsed['visibility'] !== null
                ? WeatherReading::from($parsed['visibility'], $source, $obsTime)
                : WeatherReading::null($source),
            ceiling: WeatherReading::null($source), // SynopticData doesn't provide ceiling
            cloudCover: WeatherReading::null($source), // SynopticData doesn't provide cloud cover
            isValid: true
        );
    }
}

// =============================================================================
// LEGACY FUNCTIONS (kept for backward compatibility during migration)
// =============================================================================

/**
 * Parse SynopticData API response
 * 
 * Parses JSON response from SynopticData API and converts to standard format.
 * Handles unit conversions where needed.
 * Observation time is provided as Unix timestamp in seconds.
 * 
 * Response Structure (actual):
 * {
 *   "SUMMARY": { "RESPONSE_CODE": 1, "RESPONSE_MESSAGE": "OK" },
 *   "STATION": [
 *     {
 *       "STID": "AT297",
 *       "OBSERVATIONS": {
 *         "air_temp_value_1": {
 *           "value": 19.444,
 *           "date_time": "2025-12-20T00:00:00Z"
 *         },
 *         "wind_speed_value_1": {
 *           "value": 4.471,
 *           "date_time": "2025-12-20T00:00:00Z"
 *         },
 *         "dew_point_temperature_value_1d": {
 *           "value": 7.14,
 *           "date_time": "2025-12-20T00:00:00Z"
 *         },
 *         ...
 *       }
 *     }
 *   ]
 * }
 * 
 * Field Mappings:
 * - air_temp_value_1 -> temperature (Celsius, typically no conversion needed)
 * - relative_humidity_value_1 -> humidity (percentage, no conversion)
 * - pressure_value_1d or sea_level_pressure_value_1d -> pressure (mb/hPa to inHg)
 * - wind_speed_value_1 -> wind_speed (m/s to knots)
 * - wind_gust_value_1 -> gust_speed (m/s to knots)
 * - wind_direction_value_1 -> wind_direction (degrees, no conversion)
 * - dew_point_temperature_value_1d -> dewpoint (Celsius, typically no conversion)
 * - precip_accum_since_local_midnight_value_1 -> precip_accum (mm to inches)
 * - date_time from any observation -> obs_time (ISO 8601, converted to Unix seconds)
 * 
 * @param string|null $response JSON response from SynopticData API
 * @return array|null Weather data array with standard keys, or null on parse error
 */
function parseSynopticDataResponse(?string $response): ?array {
    if ($response === null || $response === '' || !is_string($response)) {
        return null;
    }
    
    $data = json_decode($response, true);
    if ($data === null || !is_array($data)) {
        return null;
    }
    
    // Extract quality metadata (API-specific quality indicators)
    $qualityMetadata = [];
    
    // Extract RESPONSE_CODE from SUMMARY
    // RESPONSE_CODE: 1 = success, != 1 = error
    if (isset($data['SUMMARY']['RESPONSE_CODE'])) {
        $qualityMetadata['response_code'] = (int)$data['SUMMARY']['RESPONSE_CODE'];
        $qualityMetadata['has_quality_issues'] = ($data['SUMMARY']['RESPONSE_CODE'] !== 1);
        
        // If RESPONSE_CODE indicates error, return null (existing behavior)
        if ($data['SUMMARY']['RESPONSE_CODE'] !== 1) {
            return null;
        }
    }
    
    if (!isset($data['STATION']) || !is_array($data['STATION']) || empty($data['STATION'])) {
        return null;
    }
    
    // Get first station (we're requesting a specific station, so should only be one)
    $station = $data['STATION'][0];
    if (!isset($station['OBSERVATIONS']) || !is_array($station['OBSERVATIONS'])) {
        return null;
    }
    
    $obs = $station['OBSERVATIONS'];
    
    // Helper function to get value from observation field
    // Fields have suffixes like _value_1 or _value_1d (for derived)
    $getValue = function($fieldPattern) use ($obs) {
        // Search for fields matching the pattern (e.g., "air_temp" matches "air_temp_value_1")
        foreach ($obs as $key => $value) {
            if (strpos($key, $fieldPattern) === 0 && isset($value['value']) && $value['value'] !== null) {
                return $value['value'];
            }
        }
        return null;
    };
    
    // Parse observation time - use the most recent date_time from any observation
    $obsTime = null;
    $latestTime = 0;
    foreach ($obs as $key => $value) {
        if (isset($value['date_time']) && !empty($value['date_time'])) {
            $dt = strtotime($value['date_time']);
            if ($dt !== false && $dt > $latestTime) {
                $latestTime = $dt;
                $obsTime = $dt;
            }
        }
    }
    
    // Temperature - typically in Celsius
    $temperature = $getValue('air_temp');
    if ($temperature !== null && is_numeric($temperature)) {
        $temperature = (float)$temperature;
    } else {
        $temperature = null;
    }
    
    // Humidity - percentage
    $humidity = $getValue('relative_humidity');
    if ($humidity !== null && is_numeric($humidity)) {
        $humidity = (float)$humidity;
    } else {
        $humidity = null;
    }
    
    // Pressure - try multiple sources: sea_level_pressure, pressure, then altimeter
    // SynopticData may provide:
    // - sea_level_pressure: in mb/hPa (convert to inHg)
    // - pressure: in mb/hPa (convert to inHg)
    // - altimeter: already in inHg (no conversion needed)
    $pressure = $getValue('sea_level_pressure');
    $pressureSource = 'sea_level_pressure';
    if ($pressure === null) {
        $pressure = $getValue('pressure');
        $pressureSource = 'pressure';
    }
    if ($pressure === null) {
        // Altimeter is typically already in inches of mercury (inHg)
        $pressure = $getValue('altimeter');
        $pressureSource = 'altimeter';
    }
    if ($pressure !== null && is_numeric($pressure)) {
        // Convert mb/hPa to inHg if from sea_level_pressure or pressure
        // Altimeter is already in inHg, so no conversion needed
        if ($pressureSource === 'sea_level_pressure' || $pressureSource === 'pressure') {
            $pressure = (float)$pressure / 33.8639;
        } else {
            // Altimeter is already in inHg
            $pressure = (float)$pressure;
        }
    } else {
        $pressure = null;
    }
    
    // Wind speed - SynopticData provides wind_speed in m/s
    // Convert to knots: kts = m/s × 1.943844
    $windSpeed = $getValue('wind_speed');
    if ($windSpeed !== null && is_numeric($windSpeed)) {
        // Convert m/s to knots
        $windSpeedKts = (int)round((float)$windSpeed * 1.943844);
    } else {
        $windSpeedKts = null;
    }
    
    // Wind direction - degrees
    $windDirection = $getValue('wind_direction');
    if ($windDirection !== null && is_numeric($windDirection)) {
        $windDirection = (int)round((float)$windDirection);
    } else {
        $windDirection = null;
    }
    
    // Gust speed - same conversion as wind speed (m/s to knots)
    $gustSpeed = $getValue('wind_gust');
    if ($gustSpeed !== null && is_numeric($gustSpeed)) {
        // Convert m/s to knots
        $gustSpeedKts = (int)round((float)$gustSpeed * 1.943844);
    } else {
        $gustSpeedKts = null;
    }
    
    // Note: SynopticData API does not provide daily peak gust fields in the latest observations endpoint.
    // Daily peak gust tracking is handled by the application using current gust values.
    $peakGustHistorical = null;
    $peakGustHistoricalObsTime = null;
    
    // Precipitation - prefer since_local_midnight for daily accumulation
    // SynopticData typically provides precipitation in mm
    // Convert to inches: inches = mm × 0.0393701
    $precip = $getValue('precip_accum_since_local_midnight');
    if ($precip === null) {
        $precip = $getValue('precip_accum_24_hour');
    }
    if ($precip === null) {
        $precip = $getValue('precip_accum_one_hour');
    }
    if ($precip !== null && is_numeric($precip)) {
        // Convert mm to inches
        $precip = (float)$precip * 0.0393701;
    } else {
        $precip = 0;
    }
    
    // Dewpoint - typically in Celsius
    // Note: field name is dew_point_temperature (with underscore, not hyphen)
    $dewpoint = $getValue('dew_point_temperature');
    if ($dewpoint !== null && is_numeric($dewpoint)) {
        $dewpoint = (float)$dewpoint;
    } else {
        $dewpoint = null;
    }
    
    // Visibility - may be available in some stations
    // SynopticData may provide visibility in meters or miles
    $visibility = $getValue('visibility');
    if ($visibility !== null && is_numeric($visibility)) {
        // Check if in meters (typical) and convert to statute miles
        // If value is > 10, likely in meters; if < 10, likely already in miles
        if ($visibility > 10) {
            // Convert meters to statute miles: SM = m / 1609.344
            $visibility = (float)$visibility / 1609.344;
        } else {
            $visibility = (float)$visibility;
        }
    } else {
        $visibility = null;
    }
    
    // Ceiling - typically not available from SynopticData
    $ceiling = null;
    
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
        'temp_high' => null, // Not available from latest observations endpoint
        'temp_low' => null,  // Not available from latest observations endpoint
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
 * Fetch weather from SynopticData API (synchronous, for fallback)
 * 
 * Makes HTTP request to SynopticData API to fetch current weather observations
 * for a specific station. Requires api_token and station_id.
 * Used as fallback when async fetch fails.
 * 
 * API Endpoint: https://api.synopticdata.com/v2/stations/latest
 * 
 * Request Parameters:
 * - stid: Station ID (required)
 * - token: API token (required)
 * - vars: Optional comma-separated list of variables to request
 * 
 * @param array $source Weather source configuration (must contain 'station_id' and 'api_token')
 * @return array|null Weather data array with standard keys, or null on failure
 */
function fetchSynopticDataWeather($source): ?array {
    if (!is_array($source) || !isset($source['station_id']) || !isset($source['api_token'])) {
        return null;
    }
    
    $stationId = $source['station_id'];
    $apiToken = $source['api_token'];
    
    // Build API URL with required parameters
    // Request common weather variables
    // Note: Use dew_point_temperature (with underscore) not dewpoint_temperature
    // Include altimeter as it may be the primary pressure source for some stations
    $vars = 'air_temp,relative_humidity,pressure,sea_level_pressure,altimeter,wind_speed,wind_direction,wind_gust,dew_point_temperature,precip_accum_since_local_midnight,precip_accum_24_hour,visibility';
    $url = "https://api.synopticdata.com/v2/stations/latest?stid=" . urlencode($stationId) . "&token=" . urlencode($apiToken) . "&vars=" . urlencode($vars);
    
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
    
    return parseSynopticDataResponse($response);
}

