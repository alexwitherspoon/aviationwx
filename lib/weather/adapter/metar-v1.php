<?php
/**
 * METAR API Adapter v1
 * 
 * Handles fetching and parsing weather data from aviationweather.gov METAR API.
 * API documentation: https://aviationweather.gov/api
 * 
 * Note: Requires calculateHumidityFromDewpoint from lib/weather/calculator.php.
 * This is loaded by api/weather.php before adapters are required.
 */

require_once __DIR__ . '/../../constants.php';
require_once __DIR__ . '/../../logger.php';
require_once __DIR__ . '/../../test-mocks.php';
require_once __DIR__ . '/../data/WeatherReading.php';
require_once __DIR__ . '/../data/WindGroup.php';
require_once __DIR__ . '/../data/WeatherSnapshot.php';

use AviationWX\Weather\Data\WeatherReading;
use AviationWX\Weather\Data\WindGroup;
use AviationWX\Weather\Data\WeatherSnapshot;

/**
 * METAR Adapter Class (implements new interface pattern)
 * 
 * METAR is the authoritative source for:
 * - Visibility
 * - Ceiling
 * - Cloud cover
 * 
 * It also provides temperature, pressure, wind but these are typically
 * less fresh than dedicated weather stations.
 */
class MetarAdapter {
    
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
        'ceiling',
        'cloud_cover',
        'precip_accum',
    ];
    
    /** Fields where METAR is the preferred/authoritative source */
    public const PREFERRED_FIELDS = [
        'visibility',
        'ceiling',
        'cloud_cover',
    ];
    
    /** Typical update frequency in seconds (METAR is hourly with specials) */
    public const UPDATE_FREQUENCY = 3600;
    
    /** Max acceptable age before data is stale (2 hours) */
    public const MAX_ACCEPTABLE_AGE = 7200;
    
    /** Source type identifier */
    public const SOURCE_TYPE = 'metar';
    
    /**
     * Get fields this adapter can provide
     */
    public static function getFieldsProvided(): array {
        return self::FIELDS_PROVIDED;
    }
    
    /**
     * Get fields where this adapter is preferred
     */
    public static function getPreferredFields(): array {
        return self::PREFERRED_FIELDS;
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
     * Check if this adapter is preferred for a specific field
     */
    public static function isPreferredFor(string $fieldName): bool {
        return in_array($fieldName, self::PREFERRED_FIELDS, true);
    }
    
    /**
     * Build the API URL for fetching data
     * 
     * @param array|string $config Source configuration array with station_id, or station ID string (legacy)
     * @return string|null URL or null if invalid
     */
    public static function buildUrl(array|string $config): ?string {
        // Support both array config and legacy string station ID
        $stationId = is_array($config) ? ($config['station_id'] ?? '') : $config;
        
        if (empty($stationId)) {
            return null;
        }
        return "https://aviationweather.gov/api/data/metar?ids={$stationId}&format=json&taf=false&hours=0";
    }
    
    /**
     * Get nearby stations from config for fallback
     * 
     * @param array $config Source configuration
     * @return array List of nearby station IDs
     */
    public static function getNearbyStations(array $config): array {
        return $config['nearby_stations'] ?? [];
    }
    
    /**
     * Parse API response into a WeatherSnapshot
     * 
     * Units returned:
     * - Temperature/Dewpoint: Celsius
     * - Humidity: Percent
     * - Pressure: inHg (converted from hPa)
     * - Precipitation: inches
     * - Wind: knots
     * - Visibility: statute miles
     * - Ceiling: feet AGL
     * 
     * @param string $response Raw API response
     * @param array $airport Airport configuration
     * @return WeatherSnapshot|null
     */
    public static function parseToSnapshot(string $response, array $airport = []): ?WeatherSnapshot {
        // Use existing parser
        $parsed = parseMETARResponse($response, $airport);
        if ($parsed === null) {
            return WeatherSnapshot::empty(self::SOURCE_TYPE);
        }
        
        $obsTime = $parsed['obs_time'] ?? time();
        $source = self::SOURCE_TYPE;
        
        // Handle VRB wind direction - treat as null for aggregation purposes
        // VRB means variable and shouldn't be used in calculations
        $windDirection = $parsed['wind_direction'];
        if ($windDirection === 'VRB') {
            $windDirection = null;
        }
        
        $hasCompleteWind = $parsed['wind_speed'] !== null && $windDirection !== null;
        
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
                    $windDirection,
                    $parsed['gust_speed'],
                    $source,
                    $obsTime
                )
                : WindGroup::empty(),
            visibility: WeatherReading::statuteMiles($parsed['visibility'], $source, $obsTime),
            ceiling: WeatherReading::feet($parsed['ceiling'], $source, $obsTime),
            cloudCover: WeatherReading::text($parsed['cloud_cover'], $source, $obsTime),
            rawMetar: $airport['rawOb'] ?? null,
            isValid: true
        );
    }
}

// =============================================================================
// LEGACY FUNCTIONS (kept for backward compatibility during migration)
// =============================================================================

/**
 * Parse METAR response
 * 
 * Parses JSON response from METAR API and converts to standard format.
 * Handles complex visibility parsing (fractions, mixed numbers), cloud cover,
 * ceiling detection, and variable wind direction (VRB).
 * Attempts to parse observation time from METAR string if not in JSON.
 * 
 * @param string $response JSON response from METAR API
 * @param array $airport Airport configuration array
 * @return array|null Weather data array with standard keys, or null on parse error
 */
function parseMETARResponse($response, $airport): ?array {
    if (!is_string($response) || !is_array($airport)) {
        return null;
    }
    $data = json_decode($response, true);
    
    if (!isset($data[0])) {
        return null;
    }
    
    $metarData = $data[0];
    
    // Parse visibility - use parsed visibility from JSON
    $visibility = null;
    $visibilityFieldPresent = isset($metarData['visib']);
    if ($visibilityFieldPresent) {
        $visStr = str_replace('+', '', $metarData['visib']);
        // Handle "1 1/2" format
        if (preg_match('/(\d+)\s+(\d+\/\d+)/', $visStr, $matches)) {
            $num1 = is_numeric($matches[1]) ? floatval($matches[1]) : 0;
            $fraction = $matches[2];
            if (preg_match('/(\d+)\/(\d+)/', $fraction, $fracMatches)) {
                $numerator = is_numeric($fracMatches[1]) ? floatval($fracMatches[1]) : 0;
                $denominator = is_numeric($fracMatches[2]) && $fracMatches[2] != 0 ? floatval($fracMatches[2]) : 1;
                $visibility = $num1 + ($numerator / $denominator);
            } else {
                $visibility = $num1;
            }
        } elseif (strpos($visStr, '/') !== false) {
            $parts = explode('/', $visStr);
            if (count($parts) === 2 && is_numeric($parts[0]) && is_numeric($parts[1]) && $parts[1] != 0) {
                $visibility = floatval($parts[0]) / floatval($parts[1]);
            }
        } elseif (is_numeric($visStr) || $visStr === '') {
            $visibility = $visStr !== '' ? floatval($visStr) : null;
        }
    }
    
    // Convert null visibility to sentinel value (unlimited) only if field was present in response
    // If field is missing from response, keep it as null (indicates missing data, not unlimited)
    // METAR API: null/empty visib field = unlimited visibility, missing field = missing data
    if ($visibility === null && $visibilityFieldPresent) {
        $visibility = UNLIMITED_VISIBILITY_SM;  // 999.0 = unlimited
    }
    
    // Parse ceiling and cloud cover from clouds array
    $ceiling = null;
    $cloudCover = null;
    $cloudLayer = null;
    
    if (isset($metarData['clouds']) && is_array($metarData['clouds'])) {
        foreach ($metarData['clouds'] as $cloud) {
            if (isset($cloud['cover'])) {
                $cover = $cloud['cover'];
                
                // Record the first cloud layer for reference
                if ($cloudLayer === null) {
                    $base = null;
                    if (isset($cloud['base']) && is_numeric($cloud['base'])) {
                        $base = (int)round((float)$cloud['base']);
                    }
                    $cloudLayer = [
                        'cover' => $cover,
                        'base' => $base
                    ];
                }
                
                // Ceiling exists when BKN or OVC (broken or overcast)
                // Note: CLR/SKC (clear) should not set cloud_cover
                if (in_array($cover, ['BKN', 'OVC', 'OVX'])) {
                    if (isset($cloud['base']) && is_numeric($cloud['base'])) {
                        $ceiling = (int)round((float)$cloud['base']);
                        if ($cover !== 'CLR' && $cover !== 'SKC') {
                            $cloudCover = $cover;
                        }
                        break;
                    }
                }
            }
        }
    }
    
    // Set cloud_cover from first non-CLR/SKC layer if no ceiling was found
    // Note: Ceiling only exists with BKN/OVC/OVX coverage. FEW/SCT clouds do not constitute a ceiling.
    // If only FEW/SCT clouds exist, ceiling remains null (unlimited).
    if ($ceiling === null && isset($cloudLayer) && $cloudLayer['cover'] !== 'CLR' && $cloudLayer['cover'] !== 'SKC') {
        $cloudCover = $cloudLayer['cover'];
    }
    
    // Keep ceiling as null for unlimited (no BKN/OVC clouds)
    // Tests expect null for unlimited ceiling, not sentinel value
    // Sentinel value (99999) is used elsewhere in the codebase, but parseMETARResponse returns null
    // to match test expectations and API contract
    
    // Parse temperature (Celsius)
    $temperature = isset($metarData['temp']) ? $metarData['temp'] : null;
    
    // Parse dewpoint (Celsius)
    $dewpoint = isset($metarData['dewp']) ? $metarData['dewp'] : null;
    
    // Parse wind direction and speed (already in knots)
    // Handle variable wind direction ("VRB") - set to "VRB" string if not numeric
    $windDirection = null;
    if (isset($metarData['wdir'])) {
        if (is_numeric($metarData['wdir'])) {
            $windDirection = (int)round((float)$metarData['wdir']);
        } elseif (strtoupper($metarData['wdir']) === 'VRB') {
            // Variable wind direction - set to "VRB" string for display
            $windDirection = 'VRB';
        }
    }
    // Handle wind speed - check if numeric before rounding
    $windSpeed = null;
    if (isset($metarData['wspd']) && is_numeric($metarData['wspd'])) {
        $windSpeed = (int)round((float)$metarData['wspd']);
    }
    
    // Parse gust speed from raw METAR string if present
    // METAR format: "dddffGggKT" where ddd=direction, ff=speed, Ggg=gust speed, KT=units
    // Example: "06009G19KT" = 060° at 9 knots, gusts to 19 knots
    // Also handles variable wind: "VRBffGggKT" (e.g., VRB05G15KT)
    $gustSpeed = null;
    if (isset($metarData['rawOb'])) {
        $rawOb = $metarData['rawOb'];
        // Match wind pattern with gust: dddffGggKT or VRBffGggKT
        // Pattern matches: direction (3 digits or VRB), wind speed (2-3 digits), G, gust speed (2-3 digits), KT
        if (preg_match('/\b(?:VRB|\d{3})(\d{2,3})G(\d{2,3})KT\b/', $rawOb, $matches)) {
            $gustSpeedKts = (int)$matches[2];
            // Validate gust speed is reasonable (0-200 knots) and logically >= wind speed
            // Note: Wind speed from JSON may differ slightly from raw string, so we validate range only
            if ($gustSpeedKts >= 0 && $gustSpeedKts <= 200) {
                $gustSpeed = $gustSpeedKts;
            }
        }
    }
    
    // Parse pressure (altimeter setting in inHg)
    // METAR API returns altim in hectopascals (hPa/millibars), convert to inHg
    // Conversion: inHg = hPa / 33.8639
    // Normal range: 28.00-31.00 inHg corresponds to ~948-1050 hPa
    $pressure = null;
    if (isset($metarData['altim']) && is_numeric($metarData['altim'])) {
        $altimHpa = (float)$metarData['altim'];
        // Convert from hPa to inHg
        $pressure = $altimHpa / 33.8639;
    }
    
    // Fallback: Parse from raw METAR string if altim field is missing or invalid
    // METAR format: "A3012" means 30.12 inHg (A prefix + 4 digits in hundredths)
    if ($pressure === null && isset($metarData['rawOb'])) {
        $rawOb = $metarData['rawOb'];
        // Match altimeter pattern: A followed by 4 digits (e.g., A3012 = 30.12 inHg)
        if (preg_match('/\bA(\d{4})\b/', $rawOb, $matches)) {
            $altimHundredths = (int)$matches[1];
            $pressure = $altimHundredths / 100.0;
        }
    }
    
    // Calculate humidity from temperature and dewpoint
    $humidity = null;
    if ($temperature !== null && $dewpoint !== null) {
        if (!function_exists('calculateHumidityFromDewpoint')) {
            require_once __DIR__ . '/../calculator.php';
        }
        $humidity = calculateHumidityFromDewpoint($temperature, $dewpoint);
    }
    
    // Parse precipitation (METAR doesn't always have this)
    // Check both pcp24hr and precip fields for compatibility
    // Normalize to 0 for no precipitation (unified standard: 0 = no precip, null = failed)
    $precip = null;
    if (isset($metarData['pcp24hr']) && is_numeric($metarData['pcp24hr'])) {
        $precip = floatval($metarData['pcp24hr']); // Already in inches
    } elseif (isset($metarData['precip']) && is_numeric($metarData['precip'])) {
        $precip = floatval($metarData['precip']); // Already in inches
    }
    // Default to 0 if no precip (unified standard: 0 = no precip, null = failed)
    if ($precip === null) {
        $precip = 0;
    }
    
    // Parse observation time (when the METAR was actually measured)
    $obsTime = null;
    if (isset($metarData['obsTime'])) {
        // obsTime is in ISO 8601 format (e.g., '2025-01-26T16:54:00Z')
        $timestamp = strtotime($metarData['obsTime']);
        if ($timestamp !== false) {
            $obsTime = $timestamp;
        }
    }
    
    // If obsTime not available, try to parse from raw METAR string (rawOb)
    if ($obsTime === null && isset($metarData['rawOb'])) {
        $rawOb = $metarData['rawOb'];
        // METAR observation time format is DDHHMMZ (e.g., "012353Z" = day 01, time 23:53 UTC)
        // Pattern: day (2 digits), hour (2 digits), minute (2 digits), 'Z'
        if (preg_match('/\b(\d{2})(\d{4})Z\b/', $rawOb, $matches)) {
            $dayStr = $matches[1];
            $timeStr = $matches[2];
            if (!is_numeric($dayStr) || !is_numeric($timeStr) || strlen($timeStr) !== 4) {
                // Invalid format, skip parsing
            } else {
                $day = (int)$dayStr;
                $hour = (int)substr($timeStr, 0, 2);
                $minute = (int)substr($timeStr, 2, 2);
                
                // Validate ranges
                if ($day >= 1 && $day <= 31 && $hour >= 0 && $hour <= 23 && $minute >= 0 && $minute <= 59) {
                    // Get current UTC time to determine year and month
                    $now = new DateTime('now', new DateTimeZone('UTC'));
                    $year = (int)$now->format('Y');
                    $month = (int)$now->format('m');
                    $currentDay = (int)$now->format('d');
                
                // Try to create the observation time
                // Handle month rollovers intelligently:
                // - If observation is more than METAR_OBS_TIME_FUTURE_THRESHOLD_DAYS in the future,
                //   it's likely from previous month (e.g., day 31 -> day 1 rollover)
                // - If observation is more than METAR_OBS_TIME_PAST_THRESHOLD_DAYS in the past,
                //   it could be from next month (end-of-month case, e.g., day 1 -> day 31)
                // - Otherwise, assume same month
                // 
                // Edge cases handled:
                // - Month boundaries: Day 31 -> Day 1 (previous month)
                // - End of month: Day 1 -> Day 31 (next month, if >25 days old)
                // - Leap years: February 29 handling via DateTime
                // - Invalid dates: Caught by try/catch
                try {
                    // First, try current month
                    $obsDateTime = new DateTime("{$year}-{$month}-{$day} {$hour}:{$minute}:00", new DateTimeZone('UTC'));
                    $daysDiff = ($obsDateTime->getTimestamp() - $now->getTimestamp()) / SECONDS_PER_DAY;
                    
                    // If observation is more than threshold days in the future, try previous month
                    if ($daysDiff > METAR_OBS_TIME_FUTURE_THRESHOLD_DAYS) {
                        $obsDateTime->modify('-1 month');
                        $daysDiff = ($obsDateTime->getTimestamp() - $now->getTimestamp()) / SECONDS_PER_DAY;
                    }
                    
                    // If observation is more than threshold days in the past, try next month (end-of-month rollover)
                    if ($daysDiff < -METAR_OBS_TIME_PAST_THRESHOLD_DAYS) {
                        $obsDateTime->modify('+1 month');
                        $daysDiff = ($obsDateTime->getTimestamp() - $now->getTimestamp()) / SECONDS_PER_DAY;
                    }
                    
                    // Final validation: observation should be within reasonable range
                    // METAR observations are typically hourly, so METAR_OBS_TIME_MAX_AGE_SECONDS is a safe threshold
                    $maxAgeDays = METAR_OBS_TIME_MAX_AGE_SECONDS / SECONDS_PER_DAY;
                    if ($daysDiff >= -$maxAgeDays && $daysDiff <= METAR_OBS_TIME_FUTURE_THRESHOLD_DAYS) {
                        $obsTime = $obsDateTime->getTimestamp();
                    }
                } catch (Exception $e) {
                    // Invalid date, leave obsTime as null
                    error_log("Failed to parse METAR observation time from rawOb: " . $e->getMessage());
                }
                }
            }
        }
    }
    
    return [
        'temperature' => $temperature,
        'dewpoint' => $dewpoint,
        'humidity' => $humidity,
        'wind_direction' => $windDirection,
        'wind_speed' => $windSpeed,
        'gust_speed' => $gustSpeed, // Parsed from raw METAR string when present (e.g., "06009G19KT")
        'pressure' => $pressure,
        'visibility' => $visibility,
        'ceiling' => $ceiling,
        'cloud_cover' => $cloudCover,
        'precip_accum' => $precip, // Precipitation if available
        'temp_high' => null,
        'temp_low' => null,
        'peak_gust' => $gustSpeed, // Use current gust as peak gust (METAR provides current observation only)
        'obs_time' => $obsTime, // Observation time when METAR was measured
    ];
}

/**
 * Fetch METAR data from a single station ID
 * 
 * Makes HTTP request to aviationweather.gov API to fetch METAR data for a specific station.
 * Parses the response and returns standardized weather data. Used by fetchMETAR() for
 * primary and fallback station fetching.
 * 
 * @param string $stationId The METAR station ID to fetch (e.g., 'KSPB')
 * @param array $airport Airport configuration array (for logging context)
 * @return array|null Parsed METAR data array with standard keys, or null on failure
 */
function fetchMETARFromStation($stationId, $airport): ?array {if (!is_string($stationId) || !is_array($airport)) {
        return null;
    }
    // Fetch METAR from aviationweather.gov (new API format)
    $url = "https://aviationweather.gov/api/data/metar?ids={$stationId}&format=json&taf=false&hours=0";// Check for mock response in test mode
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
        
        $response = @file_get_contents($url, false, $context);if ($response === false) {
            return null;
        }
    }$parsed = parseMETARResponse($response, $airport);// If parsing succeeded, add metadata about which station was used
    if ($parsed !== null) {
        $parsed['_metar_station_used'] = $stationId;
    }
    
    return $parsed;
}

/**
 * Fetch METAR data from aviationweather.gov (synchronous, for fallback)
 * 
 * Attempts to fetch from primary metar_station first, then falls back to
 * nearby_metar_stations if configured and primary fails.
 * 
 * @param array $airport Airport configuration
 * @return array|null Parsed METAR data or null on failure
 */
function fetchMETAR($airport): ?array {
    if (!is_array($airport)) {
        return null;
    }
    require_once __DIR__ . '/../utils.php';
    
    // METAR enabled if there's a METAR source in the sources array
    if (!isMetarEnabled($airport)) {
        aviationwx_log('info', 'METAR not configured - skipping METAR fetch', [
            'airport' => $airport['icao'] ?? 'unknown'
        ], 'app');
        return null;
    }
    
    // Get station ID from sources array
    $stationId = getMetarStationId($airport);
    if ($stationId === null) {
        return null;
    }
    
    $result = fetchMETARFromStation($stationId, $airport);
    
    // If primary station failed and nearby stations are configured, try fallback
    if ($result === null) {
        $fallbackResult = fetchMETARFromNearbyStations($airport, $stationId);
        if ($fallbackResult !== null) {
            return $fallbackResult;
        }
    }
    
    return $result;
}

/**
 * Fetch METAR data from nearby stations as fallback
 * 
 * Attempts to fetch METAR data from nearby_stations if primary station fails.
 * Used as a fallback mechanism when primary METAR station is unavailable.
 * 
 * @param array $airport Airport configuration array
 * @param string $primaryStationId Primary METAR station ID (for logging)
 * @return array|null Parsed METAR data from first successful nearby station, or null if all fail
 */
function fetchMETARFromNearbyStations(array $airport, string $primaryStationId): ?array {
    // Get nearby stations from METAR source in weather_sources array
    $nearbyStations = [];
    if (isset($airport['weather_sources']) && is_array($airport['weather_sources'])) {
        foreach ($airport['weather_sources'] as $source) {
            if (($source['type'] ?? '') === 'metar' && isset($source['nearby_stations'])) {
                $nearbyStations = $source['nearby_stations'];
                break;
            }
        }
    }
    
    if (empty($nearbyStations)) {
        return null;
    }
    
    foreach ($nearbyStations as $nearbyStation) {
        // Skip empty, non-string, or whitespace-only stations
        if (!is_string($nearbyStation) || empty(trim($nearbyStation))) {
            continue;
        }
        
        $fallbackResult = fetchMETARFromStation($nearbyStation, $airport);
        if ($fallbackResult !== null) {
            aviationwx_log('info', 'METAR fetch successful from nearby station', [
                'airport' => $airport['icao'] ?? 'unknown',
                'primary_station' => $primaryStationId,
                'used_station' => $nearbyStation
            ], 'app');
            return $fallbackResult;
        }
    }
    
    return null;
}

