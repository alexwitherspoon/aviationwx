<?php
/**
 * NWS API (api.weather.gov) Adapter v1
 * 
 * Fetches real-time weather observations from the National Weather Service API.
 * Provides 5-minute update frequency from ASOS/AWOS stations at airports,
 * compared to METAR's hourly updates.
 * 
 * API Documentation: https://www.weather.gov/documentation/services-web-api
 * 
 * Key Features:
 * - No API key required (public API)
 * - 5-minute observation updates from ASOS stations
 * - Station validation ensures data comes from airport ASOS/AWOS only
 * - Quality control flags indicate data reliability
 * 
 * Configuration:
 * - station_id: ICAO station identifier (e.g., "KJFK", "KSPB") - REQUIRED
 *   Must match an airport ASOS/AWOS station pattern (K***, P***, etc.)
 * 
 * Station Validation:
 * This adapter only accepts data from stations matching airport ICAO patterns:
 * - K + 3 letters (US CONUS airports)
 * - P + 3 letters (Alaska/Pacific)
 * - PH + 2 letters (Hawaii)
 * - TJ + 2 letters (Puerto Rico)
 * 
 * This ensures we only use official airport ASOS/AWOS data, not mesonets,
 * road weather sensors, buoys, or other station types.
 * 
 * @package AviationWX\Weather\Adapter
 */

require_once __DIR__ . '/../../constants.php';
require_once __DIR__ . '/../../test-mocks.php';
require_once __DIR__ . '/../../logger.php';
require_once __DIR__ . '/../data/WeatherReading.php';
require_once __DIR__ . '/../data/WindGroup.php';
require_once __DIR__ . '/../data/WeatherSnapshot.php';

use AviationWX\Weather\Data\WeatherReading;
use AviationWX\Weather\Data\WindGroup;
use AviationWX\Weather\Data\WeatherSnapshot;

/**
 * NWS API Adapter Class
 * 
 * Provides higher-frequency weather observations from NWS ASOS stations.
 * Updates every 5 minutes vs METAR's hourly cycle.
 */
class NwsApiAdapter {
    
    /** Fields this adapter can provide */
    public const FIELDS_PROVIDED = [
        'temperature',
        'dewpoint',
        'humidity',
        'pressure',
        'wind_speed',
        'wind_direction',
        'gust_speed',
        'visibility',
    ];
    
    /** Typical update frequency in seconds (NWS updates every 5 minutes) */
    public const UPDATE_FREQUENCY = 300;
    
    /** Max acceptable age before data is stale (15 minutes - 3 update cycles) */
    public const MAX_ACCEPTABLE_AGE = 900;
    
    /** Source type identifier */
    public const SOURCE_TYPE = 'nws';
    
    /** API base URL */
    private const API_BASE_URL = 'https://api.weather.gov';
    
    /** Valid airport station patterns (ICAO format) */
    private const VALID_STATION_PATTERNS = [
        '/^K[A-Z]{3}$/',     // US CONUS airports (KJFK, KSPB, KORD)
        '/^P[A-Z]{3}$/',     // Alaska/Pacific airports (PANC, PAFA)
        '/^PH[A-Z]{2}$/',    // Hawaii airports (PHNL, PHOG)
        '/^TJ[A-Z]{2}$/',    // Puerto Rico airports (TJSJ)
        '/^TI[A-Z]{2}$/',    // US Virgin Islands (TIST)
        '/^PG[A-Z]{2}$/',    // Guam (PGUM)
        '/^PW[A-Z]{2}$/',    // Wake Island (PWAK)
    ];
    
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
     * 
     * @param array $config Source configuration with station_id
     * @return string|null URL or null if invalid configuration
     */
    public static function buildUrl(array $config): ?string {
        $stationId = $config['station_id'] ?? null;
        
        if (empty($stationId)) {
            return null;
        }
        
        // Normalize to uppercase
        $stationId = strtoupper(trim($stationId));
        
        // Validate station ID format before making request
        if (!self::isValidAirportStation($stationId)) {
            aviationwx_log('warning', 'NWS API: Invalid station ID format', [
                'station_id' => $stationId,
                'reason' => 'Does not match ICAO airport pattern'
            ], 'app');
            return null;
        }
        
        return self::API_BASE_URL . "/stations/{$stationId}/observations/latest";
    }
    
    /**
     * Get HTTP headers for API requests
     * 
     * NWS API requires a User-Agent header identifying the application.
     * 
     * @return array HTTP headers
     */
    public static function getHeaders(): array {
        return [
            'Accept: application/geo+json',
            'User-Agent: AviationWX/2.0 (aviation weather dashboard; contact@aviationwx.org)',
        ];
    }
    
    /**
     * Validate that a station ID matches airport ICAO patterns
     * 
     * This ensures we only accept data from official airport ASOS/AWOS stations,
     * not mesonets, road weather sensors, buoys, or other network types.
     * 
     * @param string $stationId Station identifier to validate
     * @return bool True if station matches airport pattern
     */
    public static function isValidAirportStation(string $stationId): bool {
        foreach (self::VALID_STATION_PATTERNS as $pattern) {
            if (preg_match($pattern, $stationId)) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Parse API response into a WeatherSnapshot
     * 
     * Units in NWS API response:
     * - Temperature: degC
     * - Wind Speed: km/h (converted to knots)
     * - Wind Direction: degrees
     * - Pressure: Pa (converted to inHg)
     * - Visibility: meters (converted to statute miles)
     * - Humidity: percent
     * 
     * @param string $response Raw API response (JSON)
     * @param array $config Source configuration
     * @return WeatherSnapshot|null Parsed snapshot or null on error
     */
    public static function parseToSnapshot(string $response, array $config = []): ?WeatherSnapshot {
        $parsed = self::parseResponse($response, $config);
        
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
            precipAccum: WeatherReading::null($source), // NWS API precip data is limited
            wind: $hasCompleteWind
                ? WindGroup::from(
                    $parsed['wind_speed'],
                    $parsed['wind_direction'],
                    $parsed['gust_speed'],
                    $source,
                    $obsTime
                )
                : WindGroup::empty(),
            visibility: WeatherReading::statuteMiles($parsed['visibility'], $source, $obsTime),
            ceiling: WeatherReading::null($source),    // Use METAR for ceiling
            cloudCover: WeatherReading::null($source), // Use METAR for cloud cover
            isValid: true
        );
    }
    
    /**
     * Parse raw NWS API response into standard weather array
     * 
     * @param string $response Raw JSON response
     * @param array $config Source configuration
     * @return array|null Parsed weather data or null on error
     */
    private static function parseResponse(string $response, array $config = []): ?array {
        if (empty($response)) {
            return null;
        }
        
        $data = json_decode($response, true);
        if ($data === null || !is_array($data)) {
            aviationwx_log('warning', 'NWS API: Failed to parse JSON response', [], 'app');
            return null;
        }
        
        // Check for API error response
        if (isset($data['status']) && $data['status'] >= 400) {
            aviationwx_log('warning', 'NWS API: Error response', [
                'status' => $data['status'],
                'detail' => $data['detail'] ?? 'Unknown error'
            ], 'app');
            return null;
        }
        
        // Extract properties from GeoJSON response
        $props = $data['properties'] ?? null;
        if ($props === null) {
            aviationwx_log('warning', 'NWS API: Missing properties in response', [], 'app');
            return null;
        }
        
        // Validate station ID matches expected
        $expectedStation = strtoupper($config['station_id'] ?? '');
        $actualStation = strtoupper($props['stationId'] ?? $props['station'] ?? '');
        
        // Extract station ID from URL if needed (e.g., "https://api.weather.gov/stations/KJFK")
        if (empty($actualStation) && isset($props['station'])) {
            $stationUrl = $props['station'];
            if (preg_match('/\/stations\/([A-Z0-9]+)$/i', $stationUrl, $matches)) {
                $actualStation = strtoupper($matches[1]);
            }
        }
        
        if (!empty($expectedStation) && $actualStation !== $expectedStation) {
            aviationwx_log('warning', 'NWS API: Station mismatch', [
                'expected' => $expectedStation,
                'actual' => $actualStation
            ], 'app');
            return null;
        }
        
        // Validate station is airport type
        if (!empty($actualStation) && !self::isValidAirportStation($actualStation)) {
            aviationwx_log('warning', 'NWS API: Station is not airport type', [
                'station' => $actualStation
            ], 'app');
            return null;
        }
        
        // Parse observation timestamp
        $obsTime = null;
        if (!empty($props['timestamp'])) {
            $obsTime = strtotime($props['timestamp']);
            if ($obsTime === false) {
                $obsTime = null;
            }
        }
        
        // Extract and convert measurements
        // Temperature: degC (no conversion needed)
        $temperature = self::extractValue($props, 'temperature');
        
        // Dewpoint: degC (no conversion needed)
        $dewpoint = self::extractValue($props, 'dewpoint');
        
        // Humidity: percent (no conversion needed)
        $humidity = self::extractValue($props, 'relativeHumidity');
        
        // Pressure: Pa → inHg
        // Conversion: inHg = Pa / 3386.39
        $pressure = null;
        $pressurePa = self::extractValue($props, 'barometricPressure');
        if ($pressurePa !== null) {
            $pressure = $pressurePa / 3386.39;
        }
        
        // Wind Speed: km/h → knots
        // Conversion: knots = km/h / 1.852
        $windSpeed = null;
        $windSpeedKmh = self::extractValue($props, 'windSpeed');
        if ($windSpeedKmh !== null) {
            $windSpeed = (int)round($windSpeedKmh / 1.852);
        }
        
        // Wind Direction: degrees (no conversion needed)
        $windDirection = null;
        $windDirValue = self::extractValue($props, 'windDirection');
        if ($windDirValue !== null) {
            $windDirection = (int)round($windDirValue);
        }
        
        // Gust Speed: km/h → knots
        $gustSpeed = null;
        $gustSpeedKmh = self::extractValue($props, 'windGust');
        if ($gustSpeedKmh !== null) {
            $gustSpeed = (int)round($gustSpeedKmh / 1.852);
        }
        
        // Visibility: meters → statute miles
        // Conversion: SM = m / 1609.344
        $visibility = null;
        $visibilityM = self::extractValue($props, 'visibility');
        if ($visibilityM !== null) {
            $visibility = $visibilityM / 1609.344;
            // Cap at reasonable maximum
            if ($visibility > 50) {
                $visibility = 50.0;
            }
        }
        
        // Log quality control issues if any key fields have bad QC
        $qcIssues = [];
        foreach (['temperature', 'windSpeed', 'barometricPressure'] as $field) {
            $qc = $props[$field]['qualityControl'] ?? 'V';
            if ($qc === 'X' || $qc === 'Q') {
                $qcIssues[] = "{$field}={$qc}";
            }
        }
        if (!empty($qcIssues)) {
            aviationwx_log('info', 'NWS API: Quality control flags', [
                'station' => $actualStation,
                'issues' => implode(', ', $qcIssues)
            ], 'app');
        }
        
        return [
            'temperature' => $temperature,
            'dewpoint' => $dewpoint,
            'humidity' => $humidity,
            'pressure' => $pressure,
            'wind_speed' => $windSpeed,
            'wind_direction' => $windDirection,
            'gust_speed' => $gustSpeed,
            'visibility' => $visibility,
            'obs_time' => $obsTime,
            '_station_id' => $actualStation,
            '_quality_control' => $qcIssues,
        ];
    }
    
    /**
     * Extract numeric value from NWS API property
     * 
     * NWS API returns values as objects with 'value' and 'unitCode' properties.
     * Quality control is indicated by 'qualityControl' field:
     * - V: Verified/Valid
     * - C: Coerced (adjusted by QC)
     * - S: Suspect
     * - X: Rejected/Invalid
     * - Q: Question
     * - Z: Null/Missing
     * 
     * @param array $props Properties object
     * @param string $field Field name
     * @return float|null Value or null if missing/invalid
     */
    private static function extractValue(array $props, string $field): ?float {
        if (!isset($props[$field])) {
            return null;
        }
        
        $fieldData = $props[$field];
        
        // Handle simple value
        if (!is_array($fieldData)) {
            return is_numeric($fieldData) ? (float)$fieldData : null;
        }
        
        // Handle object with value property
        if (!isset($fieldData['value'])) {
            return null;
        }
        
        $value = $fieldData['value'];
        if ($value === null || !is_numeric($value)) {
            return null;
        }
        
        // Check quality control - reject clearly bad data
        $qc = $fieldData['qualityControl'] ?? 'V';
        if ($qc === 'X') {
            // X = Rejected/Invalid - don't use this data
            return null;
        }
        
        return (float)$value;
    }
}

// =============================================================================
// STANDALONE FETCH FUNCTION (for testing/fallback)
// =============================================================================

/**
 * Fetch weather from NWS API (synchronous, for testing)
 * 
 * @param array $config Source configuration with station_id
 * @return array|null Weather data or null on failure
 */
function fetchNwsApiWeather(array $config): ?array {
    $url = NwsApiAdapter::buildUrl($config);
    if ($url === null) {
        return null;
    }
    
    // Check for mock response in test mode
    $mockResponse = getMockHttpResponse($url);
    if ($mockResponse !== null) {
        $response = $mockResponse;
    } else {
        $context = stream_context_create([
            'http' => [
                'timeout' => CURL_TIMEOUT,
                'ignore_errors' => true,
                'header' => implode("\r\n", NwsApiAdapter::getHeaders()),
            ],
        ]);
        
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return null;
        }
    }
    
    $snapshot = NwsApiAdapter::parseToSnapshot($response, $config);
    if ($snapshot === null || !$snapshot->isValid) {
        return null;
    }
    
    // Convert snapshot to array format for compatibility
    return [
        'temperature' => $snapshot->temperature?->value,
        'dewpoint' => $snapshot->dewpoint?->value,
        'humidity' => $snapshot->humidity?->value,
        'pressure' => $snapshot->pressure?->value,
        'wind_speed' => $snapshot->wind?->speed?->value,
        'wind_direction' => $snapshot->wind?->direction?->value,
        'gust_speed' => $snapshot->wind?->gust?->value,
        'visibility' => $snapshot->visibility?->value,
        'obs_time' => $snapshot->temperature?->observationTime ?? time(),
    ];
}
