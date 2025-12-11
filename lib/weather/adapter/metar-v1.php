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
require_once __DIR__ . '/../../test-mocks.php';

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
    if (isset($metarData['visib'])) {
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
    
    // Parse pressure (altimeter setting in inHg)
    $pressure = null;
    if (isset($metarData['altim'])) {
        $pressure = (float)$metarData['altim'];
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
    $precip = null;
    if (isset($metarData['pcp24hr']) && is_numeric($metarData['pcp24hr'])) {
        $precip = floatval($metarData['pcp24hr']); // Already in inches
    } elseif (isset($metarData['precip']) && is_numeric($metarData['precip'])) {
        $precip = floatval($metarData['precip']); // Already in inches
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
                // - If day > current day by more than 3, it's likely from previous month
                // - If day < current day by more than 3, it could be from next month (end-of-month case)
                // - Otherwise, assume same month
                try {
                    // First, try current month
                    $obsDateTime = new DateTime("{$year}-{$month}-{$day} {$hour}:{$minute}:00", new DateTimeZone('UTC'));
                    $daysDiff = ($obsDateTime->getTimestamp() - $now->getTimestamp()) / 86400;
                    
                    // If observation is more than 3 days in the future, try previous month
                    if ($daysDiff > 3) {
                        $obsDateTime->modify('-1 month');
                        $daysDiff = ($obsDateTime->getTimestamp() - $now->getTimestamp()) / 86400;
                    }
                    
                    // If observation is more than 25 days in the past, try next month (end-of-month rollover)
                    if ($daysDiff < -25) {
                        $obsDateTime->modify('+1 month');
                        $daysDiff = ($obsDateTime->getTimestamp() - $now->getTimestamp()) / 86400;
                    }
                    
                    // Final validation: observation should be within reasonable range (not more than 3 days old or future)
                    // METAR observations are typically hourly, so 3 days is a safe threshold
                    if ($daysDiff >= -72 && $daysDiff <= 3) {
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
        'gust_speed' => null, // METAR doesn't always include gusts
        'pressure' => $pressure,
        'visibility' => $visibility,
        'ceiling' => $ceiling,
        'cloud_cover' => $cloudCover,
        'precip_accum' => $precip, // Precipitation if available
        'temp_high' => null,
        'temp_low' => null,
        'peak_gust' => null,
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
function fetchMETARFromStation($stationId, $airport): ?array {
    if (!is_string($stationId) || !is_array($airport)) {
        return null;
    }
    // Fetch METAR from aviationweather.gov (new API format)
    $url = "https://aviationweather.gov/api/data/metar?ids={$stationId}&format=json&taf=false&hours=0";
    
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
    
    $parsed = parseMETARResponse($response, $airport);
    
    // If parsing succeeded, add metadata about which station was used
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
    require_once __DIR__ . '/../../logger.php';
    require_once __DIR__ . '/../utils.php';
    
    // METAR enabled if metar_station is configured
    if (!isMetarEnabled($airport)) {
        aviationwx_log('info', 'METAR not configured - skipping METAR fetch', [
            'airport' => $airport['icao'] ?? 'unknown'
        ], 'app');
        return null;
    }
    
    // Station is guaranteed to exist and be non-empty after isMetarEnabled() check
    $stationId = $airport['metar_station'];
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
 * Attempts to fetch METAR data from nearby_metar_stations if primary station fails.
 * Used as a fallback mechanism when primary METAR station is unavailable.
 * 
 * @param array $airport Airport configuration array
 * @param string $primaryStationId Primary METAR station ID (for logging)
 * @return array|null Parsed METAR data from first successful nearby station, or null if all fail
 */
function fetchMETARFromNearbyStations(array $airport, string $primaryStationId): ?array {
    if (!isset($airport['nearby_metar_stations']) || 
        !is_array($airport['nearby_metar_stations']) || 
        empty($airport['nearby_metar_stations'])) {
        return null;
    }
    
    require_once __DIR__ . '/../../logger.php';
    
    foreach ($airport['nearby_metar_stations'] as $nearbyStation) {
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

