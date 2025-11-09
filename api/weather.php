<?php
/**
 * Weather Data Fetcher
 * Fetches weather data from configured source for the specified airport
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/rate-limit.php';
require_once __DIR__ . '/../lib/logger.php';

/**
 * Circuit breaker: check if weather API should be skipped due to backoff
 * @param string $airportId
 * @param string $sourceType 'primary' or 'metar'
 * @return array ['skip' => bool, 'reason' => string, 'backoff_remaining' => int]
 */
function checkWeatherCircuitBreaker($airportId, $sourceType) {
    $backoffFile = __DIR__ . '/cache/backoff.json';
    $key = $airportId . '_weather_' . $sourceType;
    $now = time();
    
    if (!file_exists($backoffFile)) {
        return ['skip' => false, 'reason' => '', 'backoff_remaining' => 0];
    }
    
    $backoffData = @json_decode(file_get_contents($backoffFile), true) ?: [];
    
    if (!isset($backoffData[$key])) {
        return ['skip' => false, 'reason' => '', 'backoff_remaining' => 0];
    }
    
    $state = $backoffData[$key];
    $nextAllowed = (int)($state['next_allowed_time'] ?? 0);
    
    if ($nextAllowed > $now) {
        $remaining = $nextAllowed - $now;
        return [
            'skip' => true,
            'reason' => 'circuit_open',
            'backoff_remaining' => $remaining,
            'failures' => (int)($state['failures'] ?? 0)
        ];
    }
    
    return ['skip' => false, 'reason' => '', 'backoff_remaining' => 0];
}

/**
 * Record a weather API failure and update backoff state
 * @param string $airportId
 * @param string $sourceType 'primary' or 'metar'
 * @param string $severity 'transient' or 'permanent'
 */
function recordWeatherFailure($airportId, $sourceType, $severity = 'transient') {
    $backoffFile = __DIR__ . '/cache/backoff.json';
    $key = $airportId . '_weather_' . $sourceType;
    $now = time();
    
    $backoffData = [];
    if (file_exists($backoffFile)) {
        $backoffData = @json_decode(file_get_contents($backoffFile), true) ?: [];
    }
    
    if (!isset($backoffData[$key])) {
        $backoffData[$key] = ['failures' => 0, 'next_allowed_time' => 0, 'last_attempt' => 0, 'backoff_seconds' => 0];
    }
    
    $state = &$backoffData[$key];
    $state['failures'] = ((int)($state['failures'] ?? 0)) + 1;
    $state['last_attempt'] = $now;
    
    // Exponential backoff with severity scaling
    // Base: min(60, 2^failures * 60) seconds, capped at 3600s (1 hour)
    $failures = $state['failures'];
    $base = max(60, pow(2, min($failures - 1, 5)) * 60);
    $multiplier = ($severity === 'permanent') ? 2.0 : 1.0;
    $cap = ($severity === 'permanent') ? 7200 : 3600; // cap 2h for permanent
    $backoffSeconds = min($cap, (int)round($base * $multiplier));
    $state['backoff_seconds'] = $backoffSeconds;
    $state['next_allowed_time'] = $now + $backoffSeconds;
    
    @file_put_contents($backoffFile, json_encode($backoffData, JSON_PRETTY_PRINT), LOCK_EX);
}

/**
 * Record a weather API success and reset backoff state
 * @param string $airportId
 * @param string $sourceType 'primary' or 'metar'
 */
function recordWeatherSuccess($airportId, $sourceType) {
    $backoffFile = __DIR__ . '/cache/backoff.json';
    $key = $airportId . '_weather_' . $sourceType;
    $now = time();
    
    $backoffData = [];
    if (file_exists($backoffFile)) {
        $backoffData = @json_decode(file_get_contents($backoffFile), true) ?: [];
    }
    
    if (!isset($backoffData[$key])) {
        return; // No previous state to reset
    }
    
    // Reset on success
    $backoffData[$key] = [
        'failures' => 0,
        'next_allowed_time' => 0,
        'last_attempt' => $now,
        'backoff_seconds' => 0
    ];
    
    @file_put_contents($backoffFile, json_encode($backoffData, JSON_PRETTY_PRINT), LOCK_EX);
}

/**
 * Parse Ambient Weather API response (for async use)
 */
function parseAmbientResponse($response) {
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
 * Generate mock weather data for local testing
 * Returns realistic static weather data for visualization
 */
function generateMockWeatherData($airportId, $airport) {
    // Generate consistent but varied mock data based on airport ID
    // This ensures each airport has different but stable weather
    $seed = crc32($airportId);
    mt_srand($seed);
    
    // Base values that vary by airport
    $baseTemp = 15 + (mt_rand() % 20); // 15-35°C (59-95°F)
    $baseWindSpeed = 5 + (mt_rand() % 15); // 5-20 knots
    $baseWindDir = mt_rand() % 360; // 0-359 degrees
    $basePressure = 29.8 + (mt_rand() % 10) / 10; // 29.8-30.7 inHg
    $baseHumidity = 50 + (mt_rand() % 40); // 50-90%
    $baseVisibility = 8 + (mt_rand() % 5); // 8-13 SM
    
    // Reset random seed for consistent values
    mt_srand($seed);
    
    $now = time();
    $tempC = $baseTemp;
    $tempF = ($tempC * 9/5) + 32;
    $dewpointC = $tempC - 5 - (mt_rand() % 10); // 5-15°C below temp
    $dewpointF = ($dewpointC * 9/5) + 32;
    
    return [
        'temperature' => $tempC,
        'temperature_f' => $tempF,
        'dewpoint' => $dewpointC,
        'dewpoint_f' => $dewpointF,
        'dewpoint_spread' => $tempC - $dewpointC,
        'humidity' => $baseHumidity,
        'wind_speed' => $baseWindSpeed,
        'wind_direction' => $baseWindDir,
        'gust_speed' => $baseWindSpeed + 3 + (mt_rand() % 5), // 3-8 knots above wind speed
        'gust_factor' => 3 + (mt_rand() % 5),
        'pressure' => $basePressure,
        'visibility' => $baseVisibility,
        'ceiling' => null, // VFR conditions
        'cloud_cover' => 'SCT',
        'precip_accum' => 0.0,
        'flight_category' => 'VFR',
        'flight_category_class' => 'status-vfr',
        'last_updated' => $now,
        'last_updated_iso' => date('c', $now),
        'last_updated_primary' => $now,
        'last_updated_metar' => $now,
        'obs_time_primary' => $now,
    ];
}

/**
 * Parse METAR response (for async use)
 */
function parseMETARResponse($response, $airport) {
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
            $visibility = floatval($matches[1]) + floatval($matches[2]);
        } elseif (strpos($visStr, '/') !== false) {
            $parts = explode('/', $visStr);
            $visibility = floatval($parts[0]) / floatval($parts[1]);
        } else {
            $visibility = floatval($visStr);
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
                    $cloudLayer = [
                        'cover' => $cover,
                        'base' => isset($cloud['base']) ? intval($cloud['base']) : null
                    ];
                }
                
                // Ceiling exists when BKN or OVC (broken or overcast)
                // Note: CLR/SKC (clear) should not set cloud_cover
                if (in_array($cover, ['BKN', 'OVC', 'OVX'])) {
                    if (isset($cloud['base'])) {
                        $ceiling = intval($cloud['base']);
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
            $day = intval($matches[1]);
            $hour = intval(substr($matches[2], 0, 2));
            $minute = intval(substr($matches[2], 2, 2));
            
            // Validate ranges
            if ($day >= 1 && $day <= 31 && $hour >= 0 && $hour <= 23 && $minute >= 0 && $minute <= 59) {
                // Get current UTC time to determine year and month
                $now = new DateTime('now', new DateTimeZone('UTC'));
                $year = intval($now->format('Y'));
                $month = intval($now->format('m'));
                
                // Try to create the observation time
                // Note: We need to handle month rollovers - if day is greater than current day,
                // it might be from the previous month
                try {
                    $obsDateTime = new DateTime("{$year}-{$month}-{$day} {$hour}:{$minute}:00", new DateTimeZone('UTC'));
                    
                    // If the observation time is more than 3 days in the future, assume it's from previous month
                    $daysDiff = ($obsDateTime->getTimestamp() - $now->getTimestamp()) / 86400;
                    if ($daysDiff > 3) {
                        // Subtract one month
                        $obsDateTime->modify('-1 month');
                    }
                    // If the observation time is more than 3 days in the past (but less than 28 days), assume it's from next month
                    // (This handles end-of-month cases)
                    elseif ($daysDiff < -25) {
                        // Add one month
                        $obsDateTime->modify('+1 month');
                    }
                    
                    $obsTime = $obsDateTime->getTimestamp();
                } catch (Exception $e) {
                    // Invalid date, leave obsTime as null
                    error_log("Failed to parse METAR observation time from rawOb: " . $e->getMessage());
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
 * Merge new weather data with existing cache, preserving last known good values
 * for fields that are missing or invalid in new data
 */
function mergeWeatherDataWithFallback($newData, $existingData, $maxStaleSeconds) {
    if (!is_array($existingData) || !is_array($newData)) {
        return $newData;
    }
    
    // Fields that should be preserved from cache if new data is missing/invalid
    $preservableFields = [
        'temperature', 'temperature_f',
        'dewpoint', 'dewpoint_f', 'dewpoint_spread', 'humidity',
        'wind_speed', 'wind_direction', 'gust_speed', 'gust_factor',
        'pressure', 'precip_accum',
        'pressure_altitude', 'density_altitude',
        'visibility', 'ceiling', 'cloud_cover'
    ];
    
    // Track which source each field comes from for staleness checking
    $primarySourceFields = [
        'temperature', 'temperature_f',
        'dewpoint', 'dewpoint_f', 'dewpoint_spread', 'humidity',
        'wind_speed', 'wind_direction', 'gust_speed', 'gust_factor',
        'pressure', 'precip_accum',
        'pressure_altitude', 'density_altitude'
    ];
    
    $metarSourceFields = [
        'visibility', 'ceiling', 'cloud_cover'
    ];
    
    $result = $newData;
    
    // For each field, check if we should preserve the old value
    foreach ($preservableFields as $field) {
        $newValue = $newData[$field] ?? null;
        $oldValue = $existingData[$field] ?? null;
        
        // If new value is missing/null, check if we can use old value
        if ($newValue === null && $oldValue !== null) {
            // Determine which source this field comes from
            $isPrimaryField = in_array($field, $primarySourceFields);
            $isMetarField = in_array($field, $metarSourceFields);
            
            // Special case: For METAR fields (ceiling, visibility, cloud_cover), if METAR data was
            // successfully fetched (last_updated_metar is set), then null means unlimited/missing,
            // not "data not available", so we should use null (unlimited) rather than preserving old value.
            // This ensures unlimited ceiling (FEW/SCT clouds) overwrites old cached values.
            if ($isMetarField && isset($newData['last_updated_metar']) && $newData['last_updated_metar'] > 0) {
                // METAR was successfully fetched - null means unlimited/missing, use null
                $result[$field] = null;
                continue;
            }
            
            // Check if old value is still fresh enough to use
            $isStale = false;
            if ($isPrimaryField && isset($existingData['last_updated_primary'])) {
                $age = time() - $existingData['last_updated_primary'];
                $isStale = ($age >= $maxStaleSeconds);
            } elseif ($isMetarField && isset($existingData['last_updated_metar'])) {
                $age = time() - $existingData['last_updated_metar'];
                $isStale = ($age >= $maxStaleSeconds);
            }
            
            // Preserve old value if it's not too stale
            if (!$isStale) {
                $result[$field] = $oldValue;
            }
        }
    }
    
    // Preserve daily tracking values (always valid)
    $dailyTrackingFields = [
        'temp_high_today', 'temp_low_today', 'peak_gust_today',
        'temp_high_ts', 'temp_low_ts', 'peak_gust_time'
    ];
    foreach ($dailyTrackingFields as $field) {
        if (isset($existingData[$field]) && !isset($result[$field])) {
            $result[$field] = $existingData[$field];
        }
    }
    
    return $result;
}

/**
 * Helper function to null out stale fields based on source timestamps
 * Note: Daily tracking values (temp_high_today, temp_low_today, peak_gust_today) are NOT
 * considered stale - they represent valid historical data for the day regardless of current measurement age
 */
function nullStaleFieldsBySource(&$data, $maxStaleSeconds) {
    $primarySourceFields = [
        'temperature', 'temperature_f',
        'dewpoint', 'dewpoint_f', 'dewpoint_spread', 'humidity',
        'wind_speed', 'wind_direction', 'gust_speed', 'gust_factor',
        'pressure', 'precip_accum',
        'pressure_altitude', 'density_altitude'
        // Note: temp_high_today, temp_low_today, peak_gust_today are preserved (daily tracking values)
    ];
    
    $metarSourceFields = [
        'visibility', 'ceiling', 'cloud_cover'
    ];
    
    $primaryStale = false;
    if (isset($data['last_updated_primary']) && $data['last_updated_primary'] > 0) {
        $primaryAge = time() - $data['last_updated_primary'];
        $primaryStale = ($primaryAge >= $maxStaleSeconds); // >= means at threshold is stale
        
        if ($primaryStale) {
            foreach ($primarySourceFields as $field) {
                if (isset($data[$field])) {
                    $data[$field] = null;
                }
            }
        }
    }
    
    $metarStale = false;
    if (isset($data['last_updated_metar']) && $data['last_updated_metar'] > 0) {
        $metarAge = time() - $data['last_updated_metar'];
        $metarStale = ($metarAge >= $maxStaleSeconds); // >= means at threshold is stale
        
        if ($metarStale) {
            foreach ($metarSourceFields as $field) {
                if (isset($data[$field])) {
                    $data[$field] = null;
                }
            }
        }
    }
    
    // Recalculate flight category if METAR data is stale
    // Note: If METAR is stale, visibility and ceiling are nulled, but we might still have
    // valid ceiling from primary source or other data that allows category calculation
    if ($metarStale) {
        $data['flight_category'] = calculateFlightCategory($data);
        if ($data['flight_category'] === null) {
            $data['flight_category_class'] = '';
        } else {
            $data['flight_category_class'] = 'status-' . strtolower($data['flight_category']);
        }
    } elseif ($data['visibility'] === null && $data['ceiling'] === null) {
        // If both are null but METAR is not stale, recalculate anyway
        $data['flight_category'] = calculateFlightCategory($data);
        if ($data['flight_category'] === null) {
            $data['flight_category_class'] = '';
        } else {
            $data['flight_category_class'] = 'status-' . strtolower($data['flight_category']);
        }
    }
}

// Only execute endpoint logic when called as a web request (not when included for testing)
    if (php_sapi_name() !== 'cli' && !empty($_SERVER['REQUEST_METHOD'])) {
    // Start output buffering to catch any stray output (errors, warnings, whitespace)
    ob_start();

    // Set JSON header
    header('Content-Type: application/json');
    // Correlate
    header('X-Request-ID: ' . aviationwx_get_request_id());
    aviationwx_log('info', 'weather request start', [
    'airport' => $_GET['airport'] ?? null,
    'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
    'ua' => $_SERVER['HTTP_USER_AGENT'] ?? null
    ], 'user');

    // Rate limiting (60 requests per minute per IP)
    if (!checkRateLimit('weather_api', 60, 60)) {
    http_response_code(429);
    header('Retry-After: 60');
    ob_clean();
    aviationwx_log('warning', 'weather rate limited', [], 'app');
    echo json_encode(['success' => false, 'error' => 'Too many requests. Please try again later.']);
    exit;
    }

    // Get and validate airport ID
    $rawAirportId = $_GET['airport'] ?? '';
    if (empty($rawAirportId) || !validateAirportId($rawAirportId)) {
    http_response_code(400);
    ob_clean();
    aviationwx_log('error', 'invalid airport id', ['airport' => $rawAirportId], 'user');
    echo json_encode(['success' => false, 'error' => 'Invalid airport ID']);
    exit;
    }

    $airportId = strtolower(trim($rawAirportId));

    // Load airport config (with caching)
    $config = loadConfig();
    if ($config === null) {
    http_response_code(500);
    ob_clean();
    aviationwx_log('error', 'config load failed', [], 'app');
    echo json_encode(['success' => false, 'error' => 'Service temporarily unavailable']);
    exit;
    }

    if (!isset($config['airports'][$airportId])) {
    http_response_code(404);
    ob_clean();
    aviationwx_log('error', 'airport not found', ['airport' => $airportId], 'user');
    echo json_encode(['success' => false, 'error' => 'Airport not found']);
    exit;
    }

    $airport = $config['airports'][$airportId];

    // Check if we're using test config - if so, return mock weather data
    $envConfigPath = getenv('CONFIG_PATH');
    $isTestConfig = ($envConfigPath && strpos($envConfigPath, 'airports.json.test') !== false);
    
    if ($isTestConfig) {
        // Generate mock weather data for local testing
        $mockWeather = generateMockWeatherData($airportId, $airport);
        
        // Calculate additional aviation-specific metrics
        $mockWeather['density_altitude'] = calculateDensityAltitude($mockWeather, $airport);
        $mockWeather['pressure_altitude'] = calculatePressureAltitude($mockWeather, $airport);
        $mockWeather['sunrise'] = getSunriseTime($airport);
        $mockWeather['sunset'] = getSunsetTime($airport);
        
        // Track peak gust and temps for today
        $obsTimestamp = time();
        updatePeakGust($airportId, $mockWeather['gust_speed'] ?? 0, $airport, $obsTimestamp);
        $peakGustInfo = getPeakGust($airportId, $mockWeather['gust_speed'] ?? 0, $airport);
        if (is_array($peakGustInfo)) {
            $mockWeather['peak_gust_today'] = $peakGustInfo['value'] ?? $mockWeather['gust_speed'] ?? 0;
            $mockWeather['peak_gust_time'] = $peakGustInfo['ts'] ?? null;
        } else {
            $mockWeather['peak_gust_today'] = $peakGustInfo;
            $mockWeather['peak_gust_time'] = null;
        }
        
        if ($mockWeather['temperature'] !== null) {
            $currentTemp = $mockWeather['temperature'];
            updateTempExtremes($airportId, $currentTemp, $airport, $obsTimestamp);
            $tempInfo = getTempExtremes($airportId, $currentTemp, $airport);
            $mockWeather['temp_high_today'] = $tempInfo['high'] ?? $currentTemp;
            $mockWeather['temp_low_today'] = $tempInfo['low'] ?? $currentTemp;
            $mockWeather['temp_high_ts'] = $tempInfo['high_ts'] ?? null;
            $mockWeather['temp_low_ts'] = $tempInfo['low_ts'] ?? null;
        }
        
        // Build response
        $payload = ['success' => true, 'weather' => $mockWeather];
        $body = json_encode($payload);
        $etag = 'W/"' . sha1($body) . '"';
        
        // Set cache headers
        header('Cache-Control: public, max-age=60');
        header('ETag: ' . $etag);
        header('X-Cache-Status: MOCK');
        
        ob_clean();
        aviationwx_log('info', 'weather mock data served', ['airport' => $airportId], 'user');
        echo $body;
        exit;
    }

    // Weather refresh interval (per-airport, with env default)
    $defaultWeatherRefresh = getenv('WEATHER_REFRESH_DEFAULT') !== false ? intval(getenv('WEATHER_REFRESH_DEFAULT')) : 60;
    $airportWeatherRefresh = isset($airport['weather_refresh_seconds']) ? intval($airport['weather_refresh_seconds']) : $defaultWeatherRefresh;

    // Cached weather path
    $weatherCacheDir = __DIR__ . '/cache';
    if (!file_exists($weatherCacheDir)) {
    @mkdir($weatherCacheDir, 0755, true);
    }
    $weatherCacheFile = $weatherCacheDir . '/weather_' . $airportId . '.json';

    // nullStaleFieldsBySource is already defined at the top of the file (line 185)
    // No need to redefine it here - use the existing function

    // Check if this is a cron job request (force refresh regardless of cache freshness)
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $isCronRequest = (strpos($userAgent, 'AviationWX Weather Cron Bot') !== false);
    $forceRefresh = $isCronRequest || isset($_GET['force_refresh']);
    
    if ($isCronRequest) {
        aviationwx_log('info', 'cron request detected - forcing weather refresh', [
            'airport' => $airportId,
            'user_agent' => $userAgent
        ], 'app');
    }

    // Stale-while-revalidate: Serve stale cache immediately, refresh in background
    $hasStaleCache = false;
    $staleData = null;

    if (file_exists($weatherCacheFile) && !$forceRefresh) {
    $age = time() - filemtime($weatherCacheFile);
    
    // If cache is fresh, serve it normally
    if ($age < $airportWeatherRefresh) {
        $cached = json_decode(file_get_contents($weatherCacheFile), true);
        if (is_array($cached)) {
            // Safety check: Check per-source staleness
            $maxStaleHours = 3;
            $maxStaleSeconds = $maxStaleHours * 3600;
            nullStaleFieldsBySource($cached, $maxStaleSeconds);
            
            // Set cache headers for cached responses
            $remainingTime = $airportWeatherRefresh - $age;
            header('Cache-Control: public, max-age=' . $remainingTime);
            header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $remainingTime) . ' GMT');
            header('X-Cache-Status: HIT');
            
            ob_clean();
            echo json_encode(['success' => true, 'weather' => $cached]);
            exit;
        }
    } elseif (file_exists($weatherCacheFile) && !$forceRefresh) {
        // Cache is stale but exists - check per-source staleness
        $age = time() - filemtime($weatherCacheFile);
        $staleData = json_decode(file_get_contents($weatherCacheFile), true);
        if (is_array($staleData)) {
            // Safety check: Check per-source staleness
            $maxStaleHours = 3;
            $maxStaleSeconds = $maxStaleHours * 3600;
            nullStaleFieldsBySource($staleData, $maxStaleSeconds);
            
            $hasStaleCache = true;
            
            // Set stale-while-revalidate headers (serve stale, but allow background refresh)
            header('Cache-Control: public, max-age=' . $airportWeatherRefresh . ', stale-while-revalidate=300');
            header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $airportWeatherRefresh) . ' GMT');
            header('X-Cache-Status: STALE');
            
            // Serve stale data immediately with flush
            ob_clean();
            echo json_encode(['success' => true, 'weather' => $staleData, 'stale' => true]);
            
            // Flush output to client immediately
            if (function_exists('fastcgi_finish_request')) {
                // FastCGI - finish request but keep script running
                fastcgi_finish_request();
                // Set time limit for background refresh (increased to handle slow APIs)
                set_time_limit(45);
                aviationwx_log('info', 'background refresh started', [
                    'airport' => $airportId,
                    'cache_age' => $age,
                    'refresh_interval' => $airportWeatherRefresh
                ], 'app');
            } else {
                // Regular PHP - flush output and continue in background
                if (ob_get_level() > 0) {
                    ob_end_flush();
                }
                flush();
                
                // Set time limit for background refresh (increased to handle slow APIs)
                set_time_limit(45);
                aviationwx_log('info', 'background refresh started', [
                    'airport' => $airportId,
                    'cache_age' => $age,
                    'refresh_interval' => $airportWeatherRefresh
                ], 'app');
            }
            
            // Continue to refresh in background (don't exit here)
        }
    }
    // If forceRefresh is true or no cache file exists, continue to fetch fresh weather
    }

    /**
     * Fetch weather data asynchronously using curl_multi (parallel requests)
     * Fetches primary weather source and METAR in parallel when both are needed
     */
    function fetchWeatherAsync($airport, $airportId = null) {
    $sourceType = $airport['weather_source']['type'];
    $airportId = $airportId ?? 'unknown';
    
    // Check circuit breaker for primary source
    $primaryCircuit = checkWeatherCircuitBreaker($airportId, 'primary');
    if ($primaryCircuit['skip']) {
        aviationwx_log('warning', 'primary weather API circuit breaker open - skipping fetch', [
            'airport' => $airportId,
            'backoff_remaining' => $primaryCircuit['backoff_remaining'],
            'failures' => $primaryCircuit['failures']
        ], 'app');
        // Don't skip METAR fetch - continue with METAR only
    }
    
    // Check circuit breaker for METAR source
    $metarCircuit = checkWeatherCircuitBreaker($airportId, 'metar');
    if ($metarCircuit['skip']) {
        aviationwx_log('warning', 'METAR API circuit breaker open - skipping fetch', [
            'airport' => $airportId,
            'backoff_remaining' => $metarCircuit['backoff_remaining'],
            'failures' => $metarCircuit['failures']
        ], 'app');
        // If both are in backoff, return null
        if ($primaryCircuit['skip']) {
            return null;
        }
        // Otherwise continue with primary only
    }
    
    // Build primary weather URL
    $primaryUrl = null;
    if (!$primaryCircuit['skip']) {
        switch ($sourceType) {
            case 'tempest':
                $apiKey = $airport['weather_source']['api_key'];
                $stationId = $airport['weather_source']['station_id'];
                $primaryUrl = "https://swd.weatherflow.com/swd/rest/observations/station/{$stationId}?token={$apiKey}";
                break;
            case 'ambient':
                $apiKey = $airport['weather_source']['api_key'];
                $appKey = $airport['weather_source']['application_key'];
                // Ambient uses device list endpoint, not individual device endpoint
                $primaryUrl = "https://api.ambientweather.net/v1/devices?applicationKey={$appKey}&apiKey={$apiKey}";
                break;
            default:
                // Not async-able (METAR-only or unsupported)
                return fetchWeatherSync($airport);
        }
    }
    
    // Build METAR URL
    $metarUrl = null;
    if (!$metarCircuit['skip']) {
        $stationId = $airport['metar_station'] ?? $airport['icao'];
        $metarUrl = "https://aviationweather.gov/api/data/metar?ids={$stationId}&format=json&taf=false&hours=0";
    }
    
    // If both are skipped, return null
    if ($primaryCircuit['skip'] && $metarCircuit['skip']) {
        return null;
    }
    
    // Create multi-handle for parallel requests
    $mh = curl_multi_init();
    if ($mh === false) {
        aviationwx_log('error', 'failed to init curl_multi', ['airport' => $airportId], 'app');
        return null;
    }
    
    $ch1 = null;
    $ch2 = null;
    
    // Initialize primary curl handle if not in backoff
    if ($primaryUrl !== null) {
        $ch1 = curl_init($primaryUrl);
        if ($ch1 === false) {
            curl_multi_close($mh);
            aviationwx_log('error', 'failed to init primary curl handle', ['airport' => $airportId], 'app');
            recordWeatherFailure($airportId, 'primary', 'transient');
            return null;
        }
        curl_setopt_array($ch1, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT => 'AviationWX/1.0',
            CURLOPT_FAILONERROR => false, // Don't fail on HTTP error codes, we'll check them
        ]);
        curl_multi_add_handle($mh, $ch1);
    }
    
    // Initialize METAR curl handle if not in backoff
    if ($metarUrl !== null) {
        $ch2 = curl_init($metarUrl);
        if ($ch2 === false) {
            if ($ch1 !== null) {
                curl_multi_remove_handle($mh, $ch1);
                curl_close($ch1);
            }
            curl_multi_close($mh);
            aviationwx_log('error', 'failed to init METAR curl handle', ['airport' => $airportId], 'app');
            recordWeatherFailure($airportId, 'metar', 'transient');
            return null;
        }
        curl_setopt_array($ch2, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT => 'AviationWX/1.0',
            CURLOPT_FAILONERROR => false, // Don't fail on HTTP error codes, we'll check them
        ]);
        curl_multi_add_handle($mh, $ch2);
    }
    
    // Execute both requests in parallel with overall timeout protection
    $running = null;
    $startTime = microtime(true);
    $maxOverallTimeout = 15; // Overall timeout of 15 seconds (5s buffer over individual timeouts)
    
    do {
        $status = curl_multi_exec($mh, $running);
        if ($status !== CURLM_OK && $status !== CURLM_CALL_MULTI_PERFORM) {
            break;
        }
        
        // Check for overall timeout
        $elapsed = microtime(true) - $startTime;
        if ($elapsed > $maxOverallTimeout) {
            aviationwx_log('warning', 'curl_multi overall timeout exceeded', [
                'airport' => $airportId,
                'elapsed' => round($elapsed, 2),
                'max_timeout' => $maxOverallTimeout
            ], 'app');
            // Force cleanup and break
            break;
        }
        
        if ($running > 0) {
            curl_multi_select($mh, 0.1);
        }
    } while ($running > 0);
    
    // Get responses and error information
    $primaryResponse = $ch1 !== null ? curl_multi_getcontent($ch1) : null;
    $metarResponse = $ch2 !== null ? curl_multi_getcontent($ch2) : null;
    
    // Get HTTP codes and curl error info
    $primaryCode = $ch1 !== null ? curl_getinfo($ch1, CURLINFO_HTTP_CODE) : 0;
    $metarCode = $ch2 !== null ? curl_getinfo($ch2, CURLINFO_HTTP_CODE) : 0;
    $primaryError = $ch1 !== null ? curl_error($ch1) : '';
    $metarError = $ch2 !== null ? curl_error($ch2) : '';
    $primaryErrno = $ch1 !== null ? curl_errno($ch1) : 0;
    $metarErrno = $ch2 !== null ? curl_errno($ch2) : 0;
    
    // Determine failure severity based on HTTP code
    $primarySeverity = 'transient';
    if ($primaryCode !== 0 && $primaryCode >= 400 && $primaryCode < 500) {
        $primarySeverity = 'permanent'; // 4xx errors are likely permanent (bad API key, etc.)
    }
    
    $metarSeverity = 'transient';
    if ($metarCode !== 0 && $metarCode >= 400 && $metarCode < 500) {
        $metarSeverity = 'permanent'; // 4xx errors are likely permanent
    }
    
    // Check primary response and record success/failure
    if ($ch1 !== null) {
        if ($primaryResponse !== false && $primaryCode == 200 && empty($primaryError)) {
            recordWeatherSuccess($airportId, 'primary');
        } elseif (!$primaryCircuit['skip']) {
            // Only record failure if we actually attempted the request (not in backoff)
            recordWeatherFailure($airportId, 'primary', $primarySeverity);
            aviationwx_log('warning', 'primary weather API error', [
                'airport' => $airportId,
                'source' => $sourceType,
                'http_code' => $primaryCode,
                'curl_error' => $primaryError ?: null,
                'curl_errno' => $primaryErrno !== 0 ? $primaryErrno : null,
                'response_received' => $primaryResponse !== false,
                'severity' => $primarySeverity
            ], 'app');
        }
    }
    
    // Check METAR response and record success/failure
    if ($ch2 !== null) {
        if ($metarResponse !== false && $metarCode == 200 && empty($metarError)) {
            recordWeatherSuccess($airportId, 'metar');
        } elseif (!$metarCircuit['skip']) {
            // Only record failure if we actually attempted the request (not in backoff)
            recordWeatherFailure($airportId, 'metar', $metarSeverity);
            aviationwx_log('warning', 'METAR API error', [
                'airport' => $airportId,
                'station' => $stationId,
                'http_code' => $metarCode,
                'curl_error' => $metarError ?: null,
                'curl_errno' => $metarErrno !== 0 ? $metarErrno : null,
                'response_received' => $metarResponse !== false,
                'severity' => $metarSeverity
            ], 'app');
        }
    }
    
    // Cleanup
    if ($ch1 !== null) {
        curl_multi_remove_handle($mh, $ch1);
        curl_close($ch1);
    }
    if ($ch2 !== null) {
        curl_multi_remove_handle($mh, $ch2);
        curl_close($ch2);
    }
    curl_multi_close($mh);
    
    // Parse primary weather with error handling
    $weatherData = null;
    $primaryTimestamp = null;
    if ($primaryResponse !== false && $primaryCode == 200) {
        try {
            switch ($sourceType) {
                case 'tempest':
                    $weatherData = parseTempestResponse($primaryResponse);
                    break;
                case 'ambient':
                    $weatherData = parseAmbientResponse($primaryResponse);
                    break;
            }
            if ($weatherData !== null) {
                $primaryTimestamp = time(); // Track when primary data was fetched
                $weatherData['last_updated_primary'] = $primaryTimestamp;
                // Preserve observation time from primary source (when weather was actually measured)
                // This is critical for accurate timestamps in temperature/gust tracking
                if (isset($weatherData['obs_time']) && $weatherData['obs_time'] !== null) {
                    $weatherData['obs_time_primary'] = $weatherData['obs_time'];
                }
            } else {
                aviationwx_log('warning', 'primary weather response parse failed', [
                    'airport' => $airportId,
                    'source' => $sourceType,
                    'response_length' => strlen($primaryResponse)
                ], 'app');
            }
        } catch (Exception $e) {
            aviationwx_log('error', 'primary weather parse exception', [
                'airport' => $airportId,
                'source' => $sourceType,
                'err' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 'app');
            // Continue - we'll try to use what we have
        } catch (Throwable $e) {
            aviationwx_log('error', 'primary weather parse throwable', [
                'airport' => $airportId,
                'source' => $sourceType,
                'err' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 'app');
            // Continue - we'll try to use what we have
        }
    }
    
    if ($weatherData === null) {
        return null;
    }
    
    // Parse and merge METAR data (non-blocking: use what we got)
    $metarTimestamp = null;
    if ($metarResponse !== false && $metarCode == 200) {
        try {
            $metarData = parseMETARResponse($metarResponse, $airport);
        } catch (Exception $e) {
            aviationwx_log('error', 'METAR parse exception', [
                'airport' => $airportId,
                'station' => $stationId,
                'err' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 'app');
            $metarData = null;
        } catch (Throwable $e) {
            aviationwx_log('error', 'METAR parse throwable', [
                'airport' => $airportId,
                'station' => $stationId,
                'err' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 'app');
            $metarData = null;
        }
        
        if ($metarData !== null) {
            // Use observation time if available, otherwise fall back to fetch time
            $metarTimestamp = isset($metarData['obs_time']) && $metarData['obs_time'] !== null 
                ? $metarData['obs_time'] 
                : time();
            $weatherData['last_updated_metar'] = $metarTimestamp;
            
            // Preserve METAR observation time separately (for comparison with primary source)
            // This is critical when METAR is hourly but primary source is more frequent
            if (isset($metarData['obs_time']) && $metarData['obs_time'] !== null) {
                $weatherData['obs_time_metar'] = $metarData['obs_time'];
                // Also copy to obs_time for backward compatibility (used by frontend for visibility/ceiling timestamps)
                $weatherData['obs_time'] = $metarData['obs_time'];
            }
            
            // Safely merge METAR data - only update if new value is valid
            // For ceiling: explicitly set null when METAR data indicates unlimited (no BKN/OVC clouds)
            // This ensures unlimited ceiling overwrites old cached values
            if ($metarData['visibility'] !== null && $metarData['visibility'] !== false) {
                $weatherData['visibility'] = $metarData['visibility'];
            }
            // Ceiling: explicitly set to null if METAR parsing returned null (unlimited ceiling)
            // This ensures unlimited ceiling (FEW/SCT clouds) overwrites old cached values
            if (isset($metarData['ceiling'])) {
                $weatherData['ceiling'] = $metarData['ceiling']; // Can be null (unlimited) or a number
            }
            if ($metarData['cloud_cover'] !== null && $metarData['cloud_cover'] !== false) {
                $weatherData['cloud_cover'] = $metarData['cloud_cover'];
            }
        } else {
            aviationwx_log('warning', 'METAR response parse failed', [
                'airport' => $airportId,
                'station' => $stationId,
                'response_length' => strlen($metarResponse)
            ], 'app');
        }
    }
    
    return $weatherData;
    }

    // parseAmbientResponse and parseMETARResponse are already defined at the top of the file (lines 14, 53)
    // No need to redefine them here - they're available globally

    /**
     * Fetch weather synchronously (fallback for METAR-only or errors)
     */
    function fetchWeatherSync($airport, $airportId = null) {
    $sourceType = $airport['weather_source']['type'];
    $airportId = $airportId ?? 'unknown';
    $weatherData = null;
    
    // Check circuit breaker for primary source (if applicable)
    $primaryCircuit = checkWeatherCircuitBreaker($airportId, 'primary');
    $metarCircuit = checkWeatherCircuitBreaker($airportId, 'metar');
    
    switch ($sourceType) {
        case 'tempest':
        case 'ambient':
            if ($primaryCircuit['skip']) {
                aviationwx_log('warning', 'primary weather API circuit breaker open - skipping sync fetch', [
                    'airport' => $airportId,
                    'backoff_remaining' => $primaryCircuit['backoff_remaining'],
                    'failures' => $primaryCircuit['failures']
                ], 'app');
                // Continue to try METAR even if primary is in backoff
            } else {
                if ($sourceType === 'tempest') {
                    $weatherData = fetchTempestWeather($airport['weather_source']);
                } else {
                    $weatherData = fetchAmbientWeather($airport['weather_source']);
                }
                // Record success/failure for primary source
                if ($weatherData !== null) {
                    recordWeatherSuccess($airportId, 'primary');
                } else {
                    recordWeatherFailure($airportId, 'primary', 'transient');
                }
            }
            break;
        case 'metar':
            // METAR-only source
            if ($metarCircuit['skip']) {
                aviationwx_log('warning', 'METAR API circuit breaker open - skipping sync fetch', [
                    'airport' => $airportId,
                    'backoff_remaining' => $metarCircuit['backoff_remaining'],
                    'failures' => $metarCircuit['failures']
                ], 'app');
                return null;
            }
            $weatherData = fetchMETAR($airport);
            // Record success/failure for METAR source
            if ($weatherData !== null) {
                recordWeatherSuccess($airportId, 'metar');
            } else {
                recordWeatherFailure($airportId, 'metar', 'transient');
            }
            // METAR-only: all data is from METAR source
            if ($weatherData !== null) {
            // Use observation time if available, otherwise fall back to fetch time
            $weatherData['last_updated_metar'] = isset($weatherData['obs_time']) && $weatherData['obs_time'] !== null 
                ? $weatherData['obs_time'] 
                : time();
            $weatherData['last_updated_primary'] = null;
            // Preserve METAR observation time separately for consistency
            if (isset($weatherData['obs_time']) && $weatherData['obs_time'] !== null) {
                $weatherData['obs_time_metar'] = $weatherData['obs_time'];
            }
            // Keep obs_time field for frontend - it represents the actual observation time
            // last_updated_metar tracks when data was fetched/processed, obs_time is when observation occurred
            }
            return $weatherData;
        default:
            return null;
    }
    
    if ($weatherData !== null) {
        $weatherData['last_updated_primary'] = time();
        // Preserve observation time from primary source (when weather was actually measured)
        // This is critical for accurate timestamps in temperature/gust tracking
        if (isset($weatherData['obs_time']) && $weatherData['obs_time'] !== null) {
            $weatherData['obs_time_primary'] = $weatherData['obs_time'];
        }
        
        // Try to fetch METAR for visibility/ceiling if not already present
        if (!$metarCircuit['skip']) {
            $metarData = fetchMETAR($airport);
            // Record success/failure for METAR source
            if ($metarData !== null) {
                recordWeatherSuccess($airportId, 'metar');
            } else {
                recordWeatherFailure($airportId, 'metar', 'transient');
            }
            if ($metarData !== null) {
                // Use observation time if available, otherwise fall back to fetch time
                $weatherData['last_updated_metar'] = isset($metarData['obs_time']) && $metarData['obs_time'] !== null 
                    ? $metarData['obs_time'] 
                    : time();
                
                // Preserve METAR observation time separately (for comparison with primary source)
                // This is critical when METAR is hourly but primary source is more frequent
                if (isset($metarData['obs_time']) && $metarData['obs_time'] !== null) {
                    $weatherData['obs_time_metar'] = $metarData['obs_time'];
                    // Also copy to obs_time for backward compatibility (used by frontend for visibility/ceiling timestamps)
                    $weatherData['obs_time'] = $metarData['obs_time'];
                }
                
                // Safely merge METAR data - only update if new value is valid
                // Use null coalescing to preserve existing values if new ones are null
                if ($metarData['visibility'] !== null && $metarData['visibility'] !== false) {
                    $weatherData['visibility'] = $metarData['visibility'];
                }
                if ($metarData['ceiling'] !== null && $metarData['ceiling'] !== false) {
                    $weatherData['ceiling'] = $metarData['ceiling'];
                }
                if ($metarData['cloud_cover'] !== null && $metarData['cloud_cover'] !== false) {
                    $weatherData['cloud_cover'] = $metarData['cloud_cover'];
                }
            }
        }
    }
    
    return $weatherData;
    }

    // Fetch weather based on source
    $weatherData = null;
    $weatherError = null;
    try {
    // Use async fetch when METAR supplementation is needed, otherwise sync
    if ($airport['weather_source']['type'] !== 'metar') {
        $weatherData = fetchWeatherAsync($airport, $airportId);
    } else {
        $weatherData = fetchWeatherSync($airport, $airportId);
    }
    
    if ($weatherData === null) {
        $weatherError = 'Weather data unavailable';
        aviationwx_log('warning', 'weather fetch returned null', [
            'airport' => $airportId,
            'source' => $airport['weather_source']['type'] ?? 'unknown'
        ], 'app');
    }
    } catch (Exception $e) {
    $weatherError = 'Error fetching weather: ' . $e->getMessage();
    aviationwx_log('error', 'weather fetch exception', [
        'airport' => $airportId,
        'err' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ], 'app');
    } catch (Throwable $e) {
    $weatherError = 'Error fetching weather: ' . $e->getMessage();
    aviationwx_log('error', 'weather fetch throwable', [
        'airport' => $airportId,
        'err' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ], 'app');
    }

    if ($weatherError !== null) {
    http_response_code(503);
    aviationwx_log('error', 'weather api error', ['airport' => $airportId, 'err' => $weatherError], 'app');
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Unable to fetch weather data']);
    exit;
    }

    if ($weatherData === null) {
    // If we already served stale cache, just exit silently (background refresh failed)
    if ($hasStaleCache) {
        // Request already finished with stale cache response, just update cache in background
        aviationwx_log('warning', 'weather api refresh failed, stale cache was served', [
            'airport' => $airportId,
            'weather_error' => $weatherError ?? 'unknown error'
        ], 'app');
        exit; // Don't send another response, request already finished
    }
    
    // No stale cache available - send error response
    http_response_code(503);
    aviationwx_log('error', 'weather api no data', ['airport' => $airportId], 'app');
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Weather data unavailable']);
    exit;
    }

    // Calculate additional aviation-specific metrics
    $weatherData['density_altitude'] = calculateDensityAltitude($weatherData, $airport);
    $weatherData['pressure_altitude'] = calculatePressureAltitude($weatherData, $airport);
    $weatherData['sunrise'] = getSunriseTime($airport);
    $weatherData['sunset'] = getSunsetTime($airport);

    // Track and update today's peak gust (store value and timestamp)
    $currentGust = $weatherData['gust_speed'] ?? 0;
    // Use explicit observation time from primary source (when weather was actually observed)
    // This is critical for pilot safety - must show accurate observation times
    // Prefer obs_time_primary (explicit observation time from API), fall back to last_updated_primary (fetch time), then current time
    $obsTimestamp = $weatherData['obs_time_primary'] ?? $weatherData['last_updated_primary'] ?? time();
    updatePeakGust($airportId, $currentGust, $airport, $obsTimestamp);
    $peakGustInfo = getPeakGust($airportId, $currentGust, $airport);
    if (is_array($peakGustInfo)) {
    $weatherData['peak_gust_today'] = $peakGustInfo['value'] ?? $currentGust;
    $weatherData['peak_gust_time'] = $peakGustInfo['ts'] ?? null; // UNIX timestamp (UTC)
    } else {
    // Backward compatibility with older scalar cache files
    $weatherData['peak_gust_today'] = $peakGustInfo;
    $weatherData['peak_gust_time'] = null;
    }

    // Track and update today's high and low temperatures
    if ($weatherData['temperature'] !== null) {
    $currentTemp = $weatherData['temperature'];
    // Use explicit observation time from primary source (when weather was actually observed)
    // This is critical for pilot safety - must show accurate observation times
    // Prefer obs_time_primary (explicit observation time from API), fall back to last_updated_primary (fetch time), then current time
    $obsTimestamp = $weatherData['obs_time_primary'] ?? $weatherData['last_updated_primary'] ?? time();
    updateTempExtremes($airportId, $currentTemp, $airport, $obsTimestamp);
    $tempExtremes = getTempExtremes($airportId, $currentTemp, $airport);
    $weatherData['temp_high_today'] = $tempExtremes['high'];
    $weatherData['temp_low_today'] = $tempExtremes['low'];
    $weatherData['temp_high_ts'] = $tempExtremes['high_ts'] ?? null;
    $weatherData['temp_low_ts'] = $tempExtremes['low_ts'] ?? null;
    }

    // Calculate VFR/IFR/MVFR status
    $weatherData['flight_category'] = calculateFlightCategory($weatherData);
    $weatherData['flight_category_class'] = 'status-' . strtolower($weatherData['flight_category']);

    // Format temperatures to °F for display
    $weatherData['temperature_f'] = $weatherData['temperature'] !== null ? round(($weatherData['temperature'] * 9/5) + 32) : null;
    $weatherData['dewpoint_f'] = $weatherData['dewpoint'] !== null ? round(($weatherData['dewpoint'] * 9/5) + 32) : null;

    // Calculate gust factor
    $weatherData['gust_factor'] = ($weatherData['gust_speed'] && $weatherData['wind_speed']) ? 
    round($weatherData['gust_speed'] - $weatherData['wind_speed']) : 0;

    // Calculate dewpoint spread (temperature - dewpoint)
    if ($weatherData['temperature'] !== null && $weatherData['dewpoint'] !== null) {
    $weatherData['dewpoint_spread'] = round($weatherData['temperature'] - $weatherData['dewpoint'], 1);
    } else {
    $weatherData['dewpoint_spread'] = null;
    }

    // NOTE: Staleness check for fresh data has been removed (Option 2 fix)
    // Fresh data from API should never be nulled out - it's already current
    // Staleness checks are only applied to cached data before serving (see lines 686, 705)
    // This prevents the bug where fresh data was incorrectly nulled, causing merge to preserve old cache values
    
    // Recalculate flight category if visibility/ceiling are missing
    if ($weatherData['visibility'] === null && $weatherData['ceiling'] === null) {
    $weatherData['flight_category'] = calculateFlightCategory($weatherData);
    if ($weatherData['flight_category'] === null) {
        $weatherData['flight_category_class'] = '';
    } else {
        $weatherData['flight_category_class'] = 'status-' . strtolower($weatherData['flight_category']);
    }
    }

    // Merge with existing cache to preserve last known good values for missing/invalid fields
    $existingCache = null;
    if (file_exists($weatherCacheFile)) {
        $existingCacheJson = @file_get_contents($weatherCacheFile);
        if ($existingCacheJson !== false) {
            $existingCache = json_decode($existingCacheJson, true);
        }
    }
    
    // If we have existing cache, merge it with new data to preserve good values
    // Use 3-hour staleness threshold for merge (same as cached data staleness check)
    if (is_array($existingCache)) {
        $maxStaleSeconds = 3 * 3600; // 3 hours
        $weatherData = mergeWeatherDataWithFallback($weatherData, $existingCache, $maxStaleSeconds);
    }
    
    // Set overall last_updated to most recent observation time from ALL data sources
    // This ensures we pick the latest observation time even if METAR is hourly (low frequency)
    // and primary source is more frequent (e.g., every few minutes)
    // Prefer observation time (obs_time_primary/obs_time_metar) over fetch time (last_updated_primary/last_updated_metar)
    // This ensures the "last updated" on the page shows when the weather observation was made, not when it was fetched
    
    // Collect all available observation times (preferred) and fetch times (fallback)
    $allTimes = [];
    
    // Primary source: prefer observation time, fallback to fetch time
    if (isset($weatherData['obs_time_primary']) && $weatherData['obs_time_primary'] > 0) {
        $allTimes[] = $weatherData['obs_time_primary'];
    } elseif (isset($weatherData['last_updated_primary']) && $weatherData['last_updated_primary'] > 0) {
        $allTimes[] = $weatherData['last_updated_primary'];
    }
    
    // METAR source: prefer observation time, fallback to fetch time
    if (isset($weatherData['obs_time_metar']) && $weatherData['obs_time_metar'] > 0) {
        $allTimes[] = $weatherData['obs_time_metar'];
    } elseif (isset($weatherData['last_updated_metar']) && $weatherData['last_updated_metar'] > 0) {
        $allTimes[] = $weatherData['last_updated_metar'];
    }
    
    // Use the latest (most recent) time from all sources
    $lastUpdated = !empty($allTimes) ? max($allTimes) : time();
    if ($lastUpdated > 0) {
    $weatherData['last_updated'] = $lastUpdated;
    $weatherData['last_updated_iso'] = date('c', $lastUpdated);
    } else {
    // Fallback if no source timestamps (shouldn't happen with new code)
    $weatherData['last_updated'] = time();
    $weatherData['last_updated_iso'] = date('c', $weatherData['last_updated']);
    }

    $cacheWriteResult = @file_put_contents($weatherCacheFile, json_encode($weatherData), LOCK_EX);
    
    if ($cacheWriteResult === false) {
        aviationwx_log('error', 'failed to write weather cache file', [
            'airport' => $airportId,
            'file' => $weatherCacheFile
        ], 'app');
    } else {
        aviationwx_log('info', 'weather cache updated', [
            'airport' => $airportId,
            'cache_size' => $cacheWriteResult,
            'last_updated' => $weatherData['last_updated'] ?? null
        ], 'app');
    }

    // If we served stale data, we're in background refresh mode
    // Don't send headers or output again (already sent to client)
    if ($hasStaleCache) {
    // Just update the cache silently in background
    aviationwx_log('info', 'background refresh completed successfully', ['airport' => $airportId], 'app');
    exit;
    }

    // Build ETag for response based on content
    $payload = ['success' => true, 'weather' => $weatherData];
    $body = json_encode($payload);
    $etag = 'W/"' . sha1($body) . '"';

    // Conditional requests support
    $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
    if ($ifNoneMatch === $etag) {
    header('Cache-Control: public, max-age=' . $airportWeatherRefresh);
    header('ETag: ' . $etag);
    header('X-Cache-Status: MISS');
    http_response_code(304);
    exit;
    }

    // Set cache headers for fresh data (short-lived)
    header('Cache-Control: public, max-age=' . $airportWeatherRefresh);
    header('ETag: ' . $etag);
    header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $airportWeatherRefresh) . ' GMT');
    header('X-Cache-Status: MISS');

    ob_clean();
    aviationwx_log('info', 'weather request success', ['airport' => $airportId], 'user');
    aviationwx_maybe_log_alert();
    echo $body;
}

/**
 * Parse Tempest API response (for async use)
 */
function parseTempestResponse($response) {
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
 */
function fetchTempestWeather($source) {
    $apiKey = $source['api_key'];
    $stationId = $source['station_id'];
    
    // Fetch current observation
    $url = "https://swd.weatherflow.com/swd/rest/observations/station/{$stationId}?token={$apiKey}";
    
    // Create context with explicit timeout (10 seconds)
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
    
    return parseTempestResponse($response);
}

/**
 * Fetch weather from Ambient Weather API (synchronous, for fallback)
 */
function fetchAmbientWeather($source) {
    // Ambient Weather API requires API Key and Application Key
    if (!isset($source['api_key']) || !isset($source['application_key'])) {
        return null;
    }
    
    $apiKey = $source['api_key'];
    $applicationKey = $source['application_key'];
    
    // Fetch current conditions (uses device list endpoint)
    $url = "https://api.ambientweather.net/v1/devices?applicationKey={$applicationKey}&apiKey={$apiKey}";
    
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
    
    return parseAmbientResponse($response);
}

/**
 * Fetch METAR data from aviationweather.gov (synchronous, for fallback)
 */
function fetchMETAR($airport) {
    $stationId = $airport['metar_station'] ?? $airport['icao'];
    
    // Fetch METAR from aviationweather.gov (new API format)
    $url = "https://aviationweather.gov/api/data/metar?ids={$stationId}&format=json&taf=false&hours=0";
    
    // Create context with explicit timeout (10 seconds)
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
    
    return parseMETARResponse($response, $airport);
}


/**
 * Calculate dewpoint
 */
function calculateDewpoint($tempC, $humidity) {
    if ($tempC === null || $humidity === null) return null;
    
    $a = 6.1121;
    $b = 17.368;
    $c = 238.88;
    
    $gamma = log($humidity / 100) + ($b * $tempC) / ($c + $tempC);
    $dewpoint = ($c * $gamma) / ($b - $gamma);
    
    return $dewpoint;
}

/**
 * Calculate humidity from temperature and dewpoint
 */
function calculateHumidityFromDewpoint($tempC, $dewpointC) {
    if ($tempC === null || $dewpointC === null) return null;
    
    // Magnus formula
    $esat = 6.112 * exp((17.67 * $tempC) / ($tempC + 243.5));
    $e = 6.112 * exp((17.67 * $dewpointC) / ($dewpointC + 243.5));
    
    $humidity = ($e / $esat) * 100;
    
    return round($humidity);
}

/**
 * Calculate pressure altitude
 */
function calculatePressureAltitude($weather, $airport) {
    if (!isset($weather['pressure'])) {
        return null;
    }
    
    $stationElevation = $airport['elevation_ft'];
    $pressureInHg = $weather['pressure'];
    
    // Calculate pressure altitude
    $pressureAlt = $stationElevation + (29.92 - $pressureInHg) * 1000;
    
    return round($pressureAlt);
}

/**
 * Calculate density altitude
 */
function calculateDensityAltitude($weather, $airport) {
    if (!isset($weather['temperature']) || !isset($weather['pressure'])) {
        return null;
    }
    
    $stationElevation = $airport['elevation_ft'];
    $tempC = $weather['temperature'];
    $pressureInHg = $weather['pressure'];
    
    // Convert to feet
    $pressureAlt = $stationElevation + (29.92 - $pressureInHg) * 1000;
    
    // Calculate density altitude (simplified)
    $stdTempF = 59 - (0.003566 * $stationElevation);
    $actualTempF = ($tempC * 9/5) + 32;
    $densityAlt = $stationElevation + (120 * ($actualTempF - $stdTempF));
    
    return (int)round($densityAlt);
}

/**
 * Get airport timezone from config, with fallback to America/Los_Angeles
 */
function getAirportTimezone($airport) {
    // Check if timezone is specified in airport config
    if (isset($airport['timezone']) && !empty($airport['timezone'])) {
        return $airport['timezone'];
    }
    
    // Default fallback (can be overridden per airport)
    return 'America/Los_Angeles';
}

/**
 * Get today's date key (Y-m-d format) based on airport's local timezone midnight
 * Uses local timezone to determine "today" for daily resets
 */
function getAirportDateKey($airport) {
    $timezone = getAirportTimezone($airport);
    $tz = new DateTimeZone($timezone);
    $now = new DateTime('now', $tz);
    return $now->format('Y-m-d');
}

/**
 * Get sunrise time for airport
 */
function getSunriseTime($airport) {
    $lat = $airport['lat'];
    $lon = $airport['lon'];
    $timezone = getAirportTimezone($airport);
    
    // Use date_sun_info for PHP 8.1+
    $timestamp = strtotime('today');
    $sunInfo = date_sun_info($timestamp, $lat, $lon);
    
    if ($sunInfo['sunrise'] === false) {
        return null;
    }
    
    $datetime = new DateTime('@' . $sunInfo['sunrise']);
    $datetime->setTimezone(new DateTimeZone($timezone));
    
    return $datetime->format('H:i');
}

/**
 * Get sunset time for airport
 */
function getSunsetTime($airport) {
    $lat = $airport['lat'];
    $lon = $airport['lon'];
    $timezone = getAirportTimezone($airport);
    
    // Use date_sun_info for PHP 8.1+
    $timestamp = strtotime('today');
    $sunInfo = date_sun_info($timestamp, $lat, $lon);
    
    if ($sunInfo['sunset'] === false) {
        return null;
    }
    
    $datetime = new DateTime('@' . $sunInfo['sunset']);
    $datetime->setTimezone(new DateTimeZone($timezone));
    
    return $datetime->format('H:i');
}

/**
 * Update today's peak gust for an airport
 * Uses airport's local timezone midnight for date key to ensure daily reset at local midnight
 * Still uses Y-m-d format for consistency, but calculated from local timezone
 * @param string $airportId Airport identifier
 * @param float $currentGust Current gust speed value
 * @param array|null $airport Airport configuration array
 * @param int|null $obsTimestamp Observation timestamp (when the weather was actually observed), defaults to current time
 */
function updatePeakGust($airportId, $currentGust, $airport = null, $obsTimestamp = null) {
    try {
        $cacheDir = __DIR__ . '/cache';
        if (!file_exists($cacheDir)) {
            if (!mkdir($cacheDir, 0755, true)) {
                error_log("Failed to create cache directory: {$cacheDir}");
                return;
            }
        }
        
        $file = $cacheDir . '/peak_gusts.json';
        // Use airport's local timezone to determine "today" (midnight reset at local timezone)
        // Fallback to UTC if airport not provided (backward compatibility)
        $dateKey = $airport !== null ? getAirportDateKey($airport) : gmdate('Y-m-d');
        
        $peakGusts = [];
        if (file_exists($file)) {
            $content = file_get_contents($file);
            if ($content !== false) {
                $decoded = json_decode($content, true);
                $jsonError = json_last_error();
                
                // Validate JSON format - if invalid, delete and recreate file
                if ($jsonError !== JSON_ERROR_NONE || !is_array($decoded)) {
                    aviationwx_log('warning', 'peak_gusts.json has invalid format - recreating', [
                        'airport' => $airportId,
                        'json_error' => json_last_error_msg(),
                        'json_error_code' => $jsonError
                    ], 'app');
                    // Delete corrupted file
                    @unlink($file);
                    // Start with empty array
                    $peakGusts = [];
                } else {
                    $peakGusts = $decoded;
                }
            }
        }
        
        // Clean up old entries (older than 2 days) to prevent file bloat and stale data
        $currentDate = new DateTime($dateKey);
        $cleaned = 0;
        foreach ($peakGusts as $key => $value) {
            if (!is_string($key) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $key)) {
                continue; // Skip invalid keys
            }
            $entryDate = new DateTime($key);
            $daysDiff = $currentDate->diff($entryDate)->days;
            if ($daysDiff > 2) {
                unset($peakGusts[$key]);
                $cleaned++;
            }
        }
        if ($cleaned > 0) {
            aviationwx_log('info', 'cleaned old peak gusts', ['removed' => $cleaned, 'date_key' => $dateKey], 'app');
        }
        
        // Normalize existing entry to structured format {value, ts}
        $existing = $peakGusts[$dateKey][$airportId] ?? null;
        if (is_array($existing)) {
            $existingValue = $existing['value'] ?? 0;
        } else {
            $existingValue = is_numeric($existing) ? (float)$existing : 0;
        }

        // Use observation timestamp if provided, otherwise fall back to current time
        $timestamp = $obsTimestamp !== null ? $obsTimestamp : time();
        
        // If no entry for today (new day) or current gust is higher, update value and timestamp
        // This ensures we never use yesterday's data for today
        if (!isset($peakGusts[$dateKey][$airportId])) {
            aviationwx_log('info', 'initializing new day peak gust', ['airport' => $airportId, 'date_key' => $dateKey, 'gust' => $currentGust, 'obs_ts' => $timestamp], 'app');
            $peakGusts[$dateKey][$airportId] = [
                'value' => $currentGust,
                'ts' => $timestamp, // Observation timestamp (when weather was actually observed)
            ];
        } elseif ($currentGust > $existingValue) {
            // Update if current gust is higher (only for today's entry)
            $peakGusts[$dateKey][$airportId] = [
                'value' => $currentGust,
                'ts' => $timestamp, // Observation timestamp (when weather was actually observed)
            ];
        }
        
        $jsonData = json_encode($peakGusts);
        if ($jsonData !== false) {
            file_put_contents($file, $jsonData, LOCK_EX);
        }
    } catch (Exception $e) {
        error_log("Error updating peak gust: " . $e->getMessage());
    }
}

/**
 * Get today's peak gust for an airport
 * Uses airport's local timezone midnight for date key to ensure daily reset at local midnight
 * Still uses Y-m-d format for consistency, but calculated from local timezone
 */
function getPeakGust($airportId, $currentGust, $airport = null) {
    $file = __DIR__ . '/cache/peak_gusts.json';
    // Use airport's local timezone to determine "today" (midnight reset at local timezone)
    // Fallback to UTC if airport not provided (backward compatibility)
    $dateKey = $airport !== null ? getAirportDateKey($airport) : gmdate('Y-m-d');

    if (!file_exists($file)) {
        return ['value' => $currentGust, 'ts' => null];
    }

    $content = file_get_contents($file);
    if ($content === false) {
        return ['value' => $currentGust, 'ts' => null];
    }
    
    $decoded = json_decode($content, true);
    $jsonError = json_last_error();
    
    // Validate JSON format - if invalid, delete and recreate file
    if ($jsonError !== JSON_ERROR_NONE || !is_array($decoded)) {
        aviationwx_log('warning', 'peak_gusts.json has invalid format - recreating', [
            'airport' => $airportId,
            'json_error' => json_last_error_msg(),
            'json_error_code' => $jsonError
        ], 'app');
        // Delete corrupted file
        @unlink($file);
        // Return current gust as today's value (file will be recreated on next update)
        return ['value' => $currentGust, 'ts' => null];
    }
    
    $peakGusts = $decoded;
    
    // Only return data for today's date key (never yesterday or older dates)
    $entry = $peakGusts[$dateKey][$airportId] ?? null;
    if ($entry === null) {
        // No entry for today - return current gust as today's value
        return ['value' => $currentGust, 'ts' => null];
    }

    // Support both legacy scalar and new structured format
    if (is_array($entry)) {
        $value = $entry['value'] ?? 0;
        $ts = $entry['ts'] ?? null;
        // Ensure we return at least the current gust if it's higher
        // Only use today's stored value, never yesterday's
        $value = max($value, $currentGust);
        return ['value' => $value, 'ts' => $ts];
    }

    // Legacy scalar format - ensure we only use today's data
    $value = max((float)$entry, $currentGust);
    return ['value' => $value, 'ts' => null];
}

/**
 * Calculate flight category (VFR, MVFR, IFR, LIFR) based on ceiling and visibility
 * Uses standard FAA aviation weather category definitions (worst-case rule):
 * - LIFR (Magenta): Visibility < 1 mile OR Ceiling < 500 feet
 * - IFR (Red): Visibility 1 to <= 3 miles OR Ceiling 500 to < 1,000 feet
 * - MVFR (Blue): Visibility 3 to 5 miles OR Ceiling 1,000 to < 3,000 feet
 * - VFR (Green): Visibility > 3 miles AND Ceiling >= 1,000 feet (BOTH must be true)
 * 
 * For categories other than VFR, the WORST of the two conditions determines the category.
 * VFR requires BOTH conditions to meet minimums per FAA standards.
 */
function calculateFlightCategory($weather) {
    $ceiling = $weather['ceiling'] ?? null;
    $visibility = $weather['visibility'] ?? null;
    
    // Cannot determine category without any data
    if ($visibility === null && $ceiling === null) {
        return null;
    }
    
    // Determine category for visibility and ceiling separately (worst-case rule)
    $visibilityCategory = null;
    $ceilingCategory = null;
    
    // Categorize visibility
    if ($visibility !== null) {
        if ($visibility < 1) {
            $visibilityCategory = 'LIFR';
        } elseif ($visibility >= 1 && $visibility <= 3) {
            $visibilityCategory = 'IFR';
        } elseif ($visibility > 3 && $visibility <= 5) {
            $visibilityCategory = 'MVFR';
        } else {
            $visibilityCategory = 'VFR';  // > 5 SM
        }
    }
    
    // Categorize ceiling
    if ($ceiling !== null) {
        if ($ceiling < 500) {
            $ceilingCategory = 'LIFR';
        } elseif ($ceiling >= 500 && $ceiling < 1000) {
            $ceilingCategory = 'IFR';
        } elseif ($ceiling >= 1000 && $ceiling < 3000) {
            $ceilingCategory = 'MVFR';
        } else {
            $ceilingCategory = 'VFR';  // >= 3000 ft
        }
    }
    
    // If both are categorized, use worst-case (most restrictive) category
    // Order of restrictiveness: LIFR > IFR > MVFR > VFR
    if ($visibilityCategory !== null && $ceilingCategory !== null) {
        // VFR requires BOTH conditions to be VFR (or better)
        // If either is not VFR, use the worst of the two
        if ($visibilityCategory === 'VFR' && $ceilingCategory === 'VFR') {
            return 'VFR';
        }
        
        // Otherwise, use worst-case category
        $categoryOrder = ['LIFR' => 0, 'IFR' => 1, 'MVFR' => 2, 'VFR' => 3];
        $visibilityOrder = $categoryOrder[$visibilityCategory];
        $ceilingOrder = $categoryOrder[$ceilingCategory];
        
        return ($visibilityOrder < $ceilingOrder) ? $visibilityCategory : $ceilingCategory;
    }
    
    // If only one is known, check if VFR is still possible
    // VFR requires visibility >= 3 SM AND ceiling >= 1,000 ft
    if ($visibilityCategory !== null && $ceiling === null) {
        // If visibility is not VFR, use that category
        if ($visibilityCategory !== 'VFR') {
            return $visibilityCategory;
        }
        // If visibility is VFR and ceiling is null (unlimited/no clouds), ceiling is effectively VFR
        // Unlimited ceiling means no restriction - this is VFR conditions
        return 'VFR';
    }
    
    if ($ceilingCategory !== null && $visibility === null) {
        // If ceiling is not VFR, use that category
        if ($ceilingCategory !== 'VFR') {
            return $ceilingCategory;
        }
        // If ceiling is VFR but visibility unknown, cannot confirm VFR
        // Return MVFR as conservative estimate (visibility could be 3-5 SM)
        return 'MVFR';
    }
    
    // Should not reach here, but fallback
    return null;
}

/**
 * Update today's high and low temperatures for an airport
 * Uses airport's local timezone midnight for date key to ensure daily reset at local midnight
 * Still uses Y-m-d format for consistency, but calculated from local timezone
 * @param string $airportId Airport identifier
 * @param float $currentTemp Current temperature value
 * @param array|null $airport Airport configuration array
 * @param int|null $obsTimestamp Observation timestamp (when the weather was actually observed), defaults to current time
 */
function updateTempExtremes($airportId, $currentTemp, $airport = null, $obsTimestamp = null) {
    try {
        $cacheDir = __DIR__ . '/cache';
        if (!file_exists($cacheDir)) {
            if (!mkdir($cacheDir, 0755, true)) {
                error_log("Failed to create cache directory: {$cacheDir}");
                return;
            }
        }
        
        $file = $cacheDir . '/temp_extremes.json';
        // Use airport's local timezone to determine "today" (midnight reset at local timezone)
        // Fallback to UTC if airport not provided (backward compatibility)
        $dateKey = $airport !== null ? getAirportDateKey($airport) : gmdate('Y-m-d');
        
        $tempExtremes = [];
        if (file_exists($file)) {
            $content = file_get_contents($file);
            if ($content !== false) {
                $decoded = json_decode($content, true);
                $jsonError = json_last_error();
                
                // Validate JSON format - if invalid, delete and recreate file
                if ($jsonError !== JSON_ERROR_NONE || !is_array($decoded)) {
                    aviationwx_log('warning', 'temp_extremes.json has invalid format - recreating', [
                        'airport' => $airportId,
                        'json_error' => json_last_error_msg(),
                        'json_error_code' => $jsonError
                    ], 'app');
                    // Delete corrupted file
                    @unlink($file);
                    // Start with empty array
                    $tempExtremes = [];
                } else {
                    $tempExtremes = $decoded;
                }
            }
        }
        
        // Clean up old entries (older than 2 days) to prevent file bloat and stale data
        $currentDate = new DateTime($dateKey);
        $cleaned = 0;
        foreach ($tempExtremes as $key => $value) {
            if (!is_string($key) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $key)) {
                continue; // Skip invalid keys
            }
            $entryDate = new DateTime($key);
            $daysDiff = $currentDate->diff($entryDate)->days;
            if ($daysDiff > 2) {
                unset($tempExtremes[$key]);
                $cleaned++;
            }
        }
        if ($cleaned > 0) {
            aviationwx_log('info', 'cleaned old temp extremes', ['removed' => $cleaned, 'date_key' => $dateKey], 'app');
        }
        
        // Use observation timestamp if provided, otherwise fall back to current time
        $obsTs = $obsTimestamp !== null ? $obsTimestamp : time();
        
        // Initialize today's entry if it doesn't exist (always start fresh for new day)
        // This ensures we never use yesterday's data for today
        if (!isset($tempExtremes[$dateKey][$airportId])) {
            aviationwx_log('info', 'initializing new day temp extremes', ['airport' => $airportId, 'date_key' => $dateKey, 'temp' => $currentTemp, 'obs_ts' => $obsTs], 'app');
            $tempExtremes[$dateKey][$airportId] = [
                'high' => $currentTemp,
                'low' => $currentTemp,
                'high_ts' => $obsTs,  // Observation timestamp (when weather was actually observed)
                'low_ts' => $obsTs    // Observation timestamp (when weather was actually observed)
            ];
        } else {
            // Update high if current is higher
            if ($currentTemp > $tempExtremes[$dateKey][$airportId]['high']) {
                $tempExtremes[$dateKey][$airportId]['high'] = $currentTemp;
                $tempExtremes[$dateKey][$airportId]['high_ts'] = $obsTs; // Observation timestamp (when weather was actually observed)
            }
            // Update low if current is lower
            if ($currentTemp < $tempExtremes[$dateKey][$airportId]['low']) {
                $tempExtremes[$dateKey][$airportId]['low'] = $currentTemp;
                $tempExtremes[$dateKey][$airportId]['low_ts'] = $obsTs; // Observation timestamp (when weather was actually observed)
            }
        }
        
        $jsonData = json_encode($tempExtremes);
        if ($jsonData !== false) {
            file_put_contents($file, $jsonData, LOCK_EX);
        }
    } catch (Exception $e) {
        error_log("Error updating temp extremes: " . $e->getMessage());
    }
}

/**
 * Get today's high and low temperatures for an airport
 * Uses airport's local timezone midnight for date key to ensure daily reset at local midnight
 * Still uses Y-m-d format for consistency, but calculated from local timezone
 */
function getTempExtremes($airportId, $currentTemp, $airport = null) {
    $file = __DIR__ . '/cache/temp_extremes.json';
    // Use airport's local timezone to determine "today" (midnight reset at local timezone)
    // Fallback to UTC if airport not provided (backward compatibility)
    $dateKey = $airport !== null ? getAirportDateKey($airport) : gmdate('Y-m-d');
    
    if (!file_exists($file)) {
        $now = time();
        return [
            'high' => $currentTemp, 
            'low' => $currentTemp,
            'high_ts' => $now,
            'low_ts' => $now
        ];
    }
    
    $content = file_get_contents($file);
    if ($content === false) {
        $now = time();
        return [
            'high' => $currentTemp, 
            'low' => $currentTemp,
            'high_ts' => $now,
            'low_ts' => $now
        ];
    }
    
    $decoded = json_decode($content, true);
    $jsonError = json_last_error();
    
    // Validate JSON format - if invalid, delete and recreate file
    if ($jsonError !== JSON_ERROR_NONE || !is_array($decoded)) {
        aviationwx_log('warning', 'temp_extremes.json has invalid format - recreating', [
            'airport' => $airportId,
            'json_error' => json_last_error_msg(),
            'json_error_code' => $jsonError
        ], 'app');
        // Delete corrupted file
        @unlink($file);
        // Return current temp as today's value (file will be recreated on next update)
        $now = time();
        return [
            'high' => $currentTemp, 
            'low' => $currentTemp,
            'high_ts' => $now,
            'low_ts' => $now
        ];
    }
    
    $tempExtremes = $decoded;
    
    // Only return data for today's date key (never yesterday or older dates)
    if (isset($tempExtremes[$dateKey][$airportId])) {
        $stored = $tempExtremes[$dateKey][$airportId];
        
        // Return stored values without modification (this is a getter function)
        // updateTempExtremes is responsible for updating values
        return [
            'high' => $stored['high'] ?? $currentTemp,
            'low' => $stored['low'] ?? $currentTemp,
            'high_ts' => $stored['high_ts'] ?? time(),
            'low_ts' => $stored['low_ts'] ?? time()
        ];
    }
    
    // No entry for today - return current temp as today's value
    $now = time();
    return [
        'high' => $currentTemp, 
        'low' => $currentTemp,
        'high_ts' => $now,
        'low_ts' => $now
    ];
}

