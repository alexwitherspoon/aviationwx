<?php
/**
 * Weather Data Fetcher
 * Fetches weather data from configured source for the specified airport
 */

require_once __DIR__ . '/config-utils.php';
require_once __DIR__ . '/rate-limit.php';
require_once __DIR__ . '/logger.php';

/**
 * Parse Ambient Weather API response (for async use)
 */
function parseAmbientResponse($response) {
    $data = json_decode($response, true);
    
    if (!isset($data[0]) || !isset($data[0]['lastData'])) {
        return null;
    }
    
    $obs = $data[0]['lastData'];
    
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
    
    // If no ceiling found but clouds exist, use lowest base
    // Note: CLR (clear) should not set cloud_cover - clear sky means no clouds
    if ($ceiling === null && isset($cloudLayer) && $cloudLayer['base'] !== null) {
        $ceiling = $cloudLayer['base'];
        // Only set cloud_cover if it's not CLR (clear)
        if ($cloudLayer['cover'] !== 'CLR' && $cloudLayer['cover'] !== 'SKC') {
            $cloudCover = $cloudLayer['cover'];
        }
    }
    
    // Parse temperature (Celsius)
    $temperature = isset($metarData['temp']) ? $metarData['temp'] : null;
    
    // Parse dewpoint (Celsius)
    $dewpoint = isset($metarData['dewp']) ? $metarData['dewp'] : null;
    
    // Parse wind direction and speed (already in knots)
    $windDirection = isset($metarData['wdir']) ? (int)round($metarData['wdir']) : null;
    $windSpeed = isset($metarData['wspd']) ? (int)round($metarData['wspd']) : null;
    
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
    ]);

    // Rate limiting (60 requests per minute per IP)
    if (!checkRateLimit('weather_api', 60, 60)) {
    http_response_code(429);
    header('Retry-After: 60');
    ob_clean();
    aviationwx_log('warning', 'weather rate limited');
    echo json_encode(['success' => false, 'error' => 'Too many requests. Please try again later.']);
    exit;
    }

    // Get and validate airport ID
    $rawAirportId = $_GET['airport'] ?? '';
    if (empty($rawAirportId) || !validateAirportId($rawAirportId)) {
    ob_clean();
    aviationwx_log('error', 'invalid airport id', ['airport' => $rawAirportId]);
    echo json_encode(['success' => false, 'error' => 'Invalid airport ID']);
    exit;
    }

    $airportId = strtolower(trim($rawAirportId));

    // Load airport config (with caching)
    $config = loadConfig();
    if ($config === null) {
    ob_clean();
    aviationwx_log('error', 'config load failed');
    echo json_encode(['success' => false, 'error' => 'Service temporarily unavailable']);
    exit;
    }

    if (!isset($config['airports'][$airportId])) {
    ob_clean();
    aviationwx_log('error', 'airport not found', ['airport' => $airportId]);
    echo json_encode(['success' => false, 'error' => 'Airport not found']);
    exit;
    }

    $airport = $config['airports'][$airportId];

    // Weather refresh interval (per-airport, with env default)
    $defaultWeatherRefresh = getenv('WEATHER_REFRESH_DEFAULT') !== false ? intval(getenv('WEATHER_REFRESH_DEFAULT')) : 60;
    $airportWeatherRefresh = isset($airport['weather_refresh_seconds']) ? intval($airport['weather_refresh_seconds']) : $defaultWeatherRefresh;

    // Cached weather path
    $weatherCacheDir = __DIR__ . '/cache';
    if (!file_exists($weatherCacheDir)) {
    @mkdir($weatherCacheDir, 0755, true);
    }
    $weatherCacheFile = $weatherCacheDir . '/weather_' . $airportId . '.json';

    // Helper function to null out stale fields based on source timestamps
    // Note: Daily tracking values (temp_high_today, temp_low_today, peak_gust_today) are NOT
    // considered stale - they represent valid historical data for the day regardless of current measurement age
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
        $primaryStale = ($primaryAge > $maxStaleSeconds);
        
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
        $metarStale = ($metarAge > $maxStaleSeconds);
        
        if ($metarStale) {
            foreach ($metarSourceFields as $field) {
                if (isset($data[$field])) {
                    $data[$field] = null;
                }
            }
        }
    }
    
    // Recalculate flight category if METAR data is stale
    if ($metarStale || ($data['visibility'] === null && $data['ceiling'] === null)) {
        $data['flight_category'] = calculateFlightCategory($data);
        if ($data['flight_category'] === null) {
            $data['flight_category_class'] = '';
        } else {
            $data['flight_category_class'] = 'status-' . strtolower($data['flight_category']);
        }
    }
    }

    // Stale-while-revalidate: Serve stale cache immediately, refresh in background
    $hasStaleCache = false;
    $staleData = null;

    if (file_exists($weatherCacheFile)) {
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
    } else {
        // Cache is stale but exists - check per-source staleness
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
            } else {
                // Regular PHP - flush output and continue in background
                if (ob_get_level() > 0) {
                    ob_end_flush();
                }
                flush();
                
                // Set time limit for background refresh
                set_time_limit(30);
            }
            
            // Continue to refresh in background (don't exit here)
        }
    }
    }

    /**
     * Fetch weather data asynchronously using curl_multi (parallel requests)
     * Fetches primary weather source and METAR in parallel when both are needed
     */
    function fetchWeatherAsync($airport) {
    $sourceType = $airport['weather_source']['type'];
    
    // Build primary weather URL
    $primaryUrl = null;
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
    
    // Build METAR URL
    $stationId = $airport['metar_station'] ?? $airport['icao'];
    $metarUrl = "https://aviationweather.gov/api/data/metar?ids={$stationId}&format=json&taf=false&hours=0";
    
    // Create multi-handle for parallel requests
    $mh = curl_multi_init();
    $ch1 = curl_init($primaryUrl);
    $ch2 = curl_init($metarUrl);
    
    // Configure primary weather request
    curl_setopt_array($ch1, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERAGENT => 'AviationWX/1.0',
    ]);
    
    // Configure METAR request
    curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERAGENT => 'AviationWX/1.0',
    ]);
    
    // Add handles to multi-curl
    curl_multi_add_handle($mh, $ch1);
    curl_multi_add_handle($mh, $ch2);
    
    // Execute both requests in parallel
    $running = null;
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 0.1);
    } while ($running > 0);
    
    // Get responses
    $primaryResponse = curl_multi_getcontent($ch1);
    $metarResponse = curl_multi_getcontent($ch2);
    
    // Get HTTP codes
    $primaryCode = curl_getinfo($ch1, CURLINFO_HTTP_CODE);
    $metarCode = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    
    // Cleanup
    curl_multi_remove_handle($mh, $ch1);
    curl_multi_remove_handle($mh, $ch2);
    curl_multi_close($mh);
    curl_close($ch1);
    curl_close($ch2);
    
    // Parse primary weather
    $weatherData = null;
    $primaryTimestamp = null;
    if ($primaryResponse !== false && $primaryCode == 200) {
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
        }
    }
    
    if ($weatherData === null) {
        return null;
    }
    
    // Parse and merge METAR data (non-blocking: use what we got)
    $metarTimestamp = null;
    if ($metarResponse !== false && $metarCode == 200) {
        $metarData = parseMETARResponse($metarResponse, $airport);
        if ($metarData !== null) {
            // Use observation time if available, otherwise fall back to fetch time
            $metarTimestamp = isset($metarData['obs_time']) && $metarData['obs_time'] !== null 
                ? $metarData['obs_time'] 
                : time();
            $weatherData['last_updated_metar'] = $metarTimestamp;
            
            if ($weatherData['visibility'] === null && $metarData['visibility'] !== null) {
                $weatherData['visibility'] = $metarData['visibility'];
            }
            if ($weatherData['ceiling'] === null && $metarData['ceiling'] !== null) {
                $weatherData['ceiling'] = $metarData['ceiling'];
            }
            if ($metarData['cloud_cover'] !== null) {
                $weatherData['cloud_cover'] = $metarData['cloud_cover'];
            }
        }
    }
    
    return $weatherData;
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
    
    // Convert all measurements to our standard format
    $temperature = isset($obs['tempf']) ? ($obs['tempf'] - 32) / 1.8 : null; // F to C
    $humidity = isset($obs['humidity']) ? $obs['humidity'] : null;
    $pressure = isset($obs['baromrelin']) ? $obs['baromrelin'] : null; // Already in inHg
    $windSpeed = isset($obs['windspeedmph']) ? round($obs['windspeedmph'] * 0.868976) : null; // mph to knots
    $windDirection = isset($obs['winddir']) ? round($obs['winddir']) : null;
    $gustSpeed = isset($obs['windgustmph']) ? round($obs['windgustmph'] * 0.868976) : null; // mph to knots
    $precip = isset($obs['dailyrainin']) ? $obs['dailyrainin'] : 0; // Already in inches
    $dewpoint = isset($obs['dewPoint']) ? ($obs['dewPoint'] - 32) / 1.8 : null; // F to C
    
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
                if (in_array($cover, ['BKN', 'OVC', 'OVX'])) {
                    if (isset($cloud['base'])) {
                        $ceiling = intval($cloud['base']);
                        $cloudCover = $cover;
                        break;
                    }
                }
            }
        }
    }
    
    // If no ceiling but we have cloud layers, indicate it
    if ($ceiling === null && $cloudLayer !== null) {
        $cloudCover = $cloudLayer['cover'];
    }
    
    // Parse wind data
    $windSpeed = null;
    $windDirection = null;
    $gustSpeed = null;
    
    if (isset($metarData['wdir'])) {
        $windDirection = intval($metarData['wdir']);
    }
    
    if (isset($metarData['wspd'])) {
        $windSpeed = intval($metarData['wspd']); // Already in knots
    }
    
    if (isset($metarData['wgust'])) {
        $gustSpeed = intval($metarData['wgust']); // Already in knots
    }
    
    // Parse temperature
    $temperature = null;
    $dewpoint = null;
    if (isset($metarData['temp'])) {
        $temperature = floatval($metarData['temp']);
    }
    if (isset($metarData['dewp'])) {
        $dewpoint = floatval($metarData['dewp']);
    }
    
    // Parse humidity (calculate from temp and dewpoint)
    $humidity = null;
    if ($temperature !== null && $dewpoint !== null) {
        $humidity = calculateHumidityFromDewpoint($temperature, $dewpoint);
    }
    
    // Parse pressure
    $pressure = null;
    if (isset($metarData['altim'])) {
        $pressure = floatval($metarData['altim']); // Already in inHg
    }
    
    // Parse precipitation (METAR doesn't always have this)
    $precip = null;
    if (isset($metarData['pcp24hr'])) {
        $precip = floatval($metarData['pcp24hr']); // Already in inches
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
    
    return [
        'temperature' => $temperature,
        'humidity' => $humidity,
        'pressure' => $pressure,
        'wind_speed' => $windSpeed,
        'wind_direction' => $windDirection,
        'gust_speed' => $gustSpeed,
        'precip_accum' => $precip ?? 0,
        'dewpoint' => $dewpoint,
        'visibility' => $visibility,
        'ceiling' => $ceiling,
        'cloud_cover' => $cloudCover,
        'temp_high' => null,
        'temp_low' => null,
        'peak_gust' => $gustSpeed,
        'obs_time' => $obsTime, // Observation time when METAR was measured
    ];
    }

    /**
     * Fetch weather synchronously (fallback for METAR-only or errors)
     */
    function fetchWeatherSync($airport) {
    $sourceType = $airport['weather_source']['type'];
    $weatherData = null;
    
    switch ($sourceType) {
        case 'tempest':
            $weatherData = fetchTempestWeather($airport['weather_source']);
            break;
        case 'ambient':
            $weatherData = fetchAmbientWeather($airport['weather_source']);
            break;
        case 'metar':
            $weatherData = fetchMETAR($airport);
            // METAR-only: all data is from METAR source
            if ($weatherData !== null) {
            // Use observation time if available, otherwise fall back to fetch time
            $weatherData['last_updated_metar'] = isset($weatherData['obs_time']) && $weatherData['obs_time'] !== null 
                ? $weatherData['obs_time'] 
                : time();
            $weatherData['last_updated_primary'] = null;
            // Keep obs_time field for frontend - it represents the actual observation time
            // last_updated_metar tracks when data was fetched/processed, obs_time is when observation occurred
            }
            return $weatherData;
        default:
            return null;
    }
    
    if ($weatherData !== null) {
        $weatherData['last_updated_primary'] = time();
        
        // Try to fetch METAR for visibility/ceiling if not already present
        $metarData = fetchMETAR($airport);
        if ($metarData !== null) {
            // Use observation time if available, otherwise fall back to fetch time
            $weatherData['last_updated_metar'] = isset($metarData['obs_time']) && $metarData['obs_time'] !== null 
                ? $metarData['obs_time'] 
                : time();
            
            if ($weatherData['visibility'] === null && $metarData['visibility'] !== null) {
                $weatherData['visibility'] = $metarData['visibility'];
            }
            if ($weatherData['ceiling'] === null && $metarData['ceiling'] !== null) {
                $weatherData['ceiling'] = $metarData['ceiling'];
            }
            if ($metarData['cloud_cover'] !== null) {
                $weatherData['cloud_cover'] = $metarData['cloud_cover'];
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
        $weatherData = fetchWeatherAsync($airport);
    } else {
        $weatherData = fetchWeatherSync($airport);
    }
    
    if ($weatherData === null) {
        $weatherError = 'Weather data unavailable';
    }
    } catch (Exception $e) {
    $weatherError = 'Error fetching weather: ' . $e->getMessage();
    aviationwx_log('error', 'weather fetch exception', ['err' => $e->getMessage()]);
    }

    if ($weatherError !== null) {
    aviationwx_log('error', 'weather api error', ['airport' => $airportId, 'err' => $weatherError]);
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Unable to fetch weather data']);
    exit;
    }

    if ($weatherData === null) {
    // If we already served stale cache, just exit silently (background refresh failed)
    if ($hasStaleCache) {
        // Request already finished with stale cache response, just update cache in background
        aviationwx_log('warning', 'weather api refresh failed, stale cache was served', ['airport' => $airportId]);
        exit; // Don't send another response, request already finished
    }
    
    // No stale cache available - send error response
    aviationwx_log('error', 'weather api no data', ['airport' => $airportId]);
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
    updatePeakGust($airportId, $currentGust, $airport);
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
    updateTempExtremes($airportId, $currentTemp, $airport);
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

    // Safety check: If weather data is older than 3 hours, null out only fields from stale sources
    // This ensures pilots don't see dangerously out-of-date information while preserving valid data
    $maxStaleHours = 3;
    $maxStaleSeconds = $maxStaleHours * 3600;

    // Fields that come from PRIMARY source (Tempest/Ambient)
    // Note: Daily tracking values (temp_high_today, temp_low_today, peak_gust_today) are NOT
    // included here - they represent valid historical data for the day regardless of current measurement age
    $primarySourceFields = [
    'temperature', 'temperature_f',
    'dewpoint', 'dewpoint_f', 'dewpoint_spread', 'humidity',
    'wind_speed', 'wind_direction', 'gust_speed', 'gust_factor',
    'pressure', 'precip_accum',
    'pressure_altitude', 'density_altitude' // Calculated from primary data
    ];

    // Fields that come from METAR source
    $metarSourceFields = [
    'visibility', 'ceiling', 'cloud_cover'
    ];

    // Check primary source staleness
    $primaryStale = false;
    if (isset($weatherData['last_updated_primary']) && $weatherData['last_updated_primary'] > 0) {
    $primaryAge = time() - $weatherData['last_updated_primary'];
    $primaryStale = ($primaryAge > $maxStaleSeconds);
    
    if ($primaryStale) {
        aviationwx_log('warning', 'primary weather source stale - nulling primary fields', [
            'airport' => $airportId,
            'source' => 'primary',
            'age_hours' => round($primaryAge / 3600, 2),
            'max_age_hours' => $maxStaleHours
        ]);
        
        // Null out only primary source fields
        foreach ($primarySourceFields as $field) {
            if (isset($weatherData[$field])) {
                $weatherData[$field] = null;
            }
        }
    }
    }

    // Check METAR source staleness
    $metarStale = false;
    if (isset($weatherData['last_updated_metar']) && $weatherData['last_updated_metar'] > 0) {
    $metarAge = time() - $weatherData['last_updated_metar'];
    $metarStale = ($metarAge > $maxStaleSeconds);
    
    if ($metarStale) {
        aviationwx_log('warning', 'METAR source stale - nulling METAR fields', [
            'airport' => $airportId,
            'source' => 'metar',
            'age_hours' => round($metarAge / 3600, 2),
            'max_age_hours' => $maxStaleHours
        ]);
        
        // Null out only METAR source fields
        foreach ($metarSourceFields as $field) {
            if (isset($weatherData[$field])) {
                $weatherData[$field] = null;
            }
        }
    }
    }

    // If visibility or ceiling is stale, recalculate flight category (will be null if both are null)
    if ($metarStale || ($weatherData['visibility'] === null && $weatherData['ceiling'] === null)) {
    $weatherData['flight_category'] = calculateFlightCategory($weatherData);
    if ($weatherData['flight_category'] === null) {
        $weatherData['flight_category_class'] = '';
    } else {
        $weatherData['flight_category_class'] = 'status-' . strtolower($weatherData['flight_category']);
    }
    }

    // Set overall last_updated to most recent source timestamp
    $lastUpdated = max(
    $weatherData['last_updated_primary'] ?? 0,
    $weatherData['last_updated_metar'] ?? 0
    );
    if ($lastUpdated > 0) {
    $weatherData['last_updated'] = $lastUpdated;
    $weatherData['last_updated_iso'] = date('c', $lastUpdated);
    } else {
    // Fallback if no source timestamps (shouldn't happen with new code)
    $weatherData['last_updated'] = time();
    $weatherData['last_updated_iso'] = date('c', $weatherData['last_updated']);
    }

    @file_put_contents($weatherCacheFile, json_encode($weatherData), LOCK_EX);

    // If we served stale data, we're in background refresh mode
    // Don't send headers or output again (already sent to client)
    if ($hasStaleCache) {
    // Just update the cache silently in background
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
    aviationwx_log('info', 'weather request success', ['airport' => $airportId]);
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
        'wind_direction' => isset($obs['wind_direction']) ? round($obs['wind_direction']) : null,
        'gust_speed' => $gustSpeedKts,
        'precip_accum' => isset($obs['precip_accum_local_day_final']) ? $obs['precip_accum_local_day_final'] * 0.0393701 : 0, // mm to inches
        'dewpoint' => isset($obs['dew_point']) ? $obs['dew_point'] : null,
        'visibility' => null, // Not available from Tempest
        'ceiling' => null, // Not available from Tempest
        'temp_high' => $tempHigh,
        'temp_low' => $tempLow,
        'peak_gust' => $peakGust,
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
    $response = @file_get_contents($url);
    
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
    $response = @file_get_contents($url);
    
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
 */
function updatePeakGust($airportId, $currentGust, $airport = null) {
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
                $peakGusts = json_decode($content, true) ?? [];
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
            aviationwx_log('info', 'cleaned old peak gusts', ['removed' => $cleaned, 'date_key' => $dateKey]);
        }
        
        // Normalize existing entry to structured format {value, ts}
        $existing = $peakGusts[$dateKey][$airportId] ?? null;
        if (is_array($existing)) {
            $existingValue = $existing['value'] ?? 0;
        } else {
            $existingValue = is_numeric($existing) ? (float)$existing : 0;
        }

        // If no entry for today (new day) or current gust is higher, update value and timestamp
        // This ensures we never use yesterday's data for today
        if (!isset($peakGusts[$dateKey][$airportId])) {
            aviationwx_log('info', 'initializing new day peak gust', ['airport' => $airportId, 'date_key' => $dateKey, 'gust' => $currentGust]);
            $peakGusts[$dateKey][$airportId] = [
                'value' => $currentGust,
                'ts' => time(), // store as UNIX timestamp (UTC)
            ];
        } elseif ($currentGust > $existingValue) {
            // Update if current gust is higher (only for today's entry)
            $peakGusts[$dateKey][$airportId] = [
                'value' => $currentGust,
                'ts' => time(), // store as UNIX timestamp (UTC)
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

    $peakGusts = json_decode(file_get_contents($file), true) ?? [];
    
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
 * Uses standard aviation weather category definitions:
 * - LIFR (Magenta): Visibility < 1 mile, Ceiling < 500 feet
 * - IFR (Red): Visibility 1 to < 3 miles, Ceiling 500 to < 1,000 feet
 * - MVFR (Blue): Visibility 3 to 5 miles, Ceiling 1,000 to < 3,000 feet
 * - VFR (Green): Visibility > 5 miles, Ceiling > 3,000 feet
 */
function calculateFlightCategory($weather) {
    $ceiling = $weather['ceiling'] ?? null;
    $visibility = $weather['visibility'] ?? null;
    
    // LIFR: ceiling < 500 ft AND visibility < 1 SM
    // (Most restrictive - check first)
    // Both conditions must be met, but if one is missing, use the worst category based on what we know
    $lifrCeiling = ($ceiling !== null && $ceiling < 500);
    $lifrVisibility = ($visibility !== null && $visibility < 1);
    
    if ($lifrCeiling && $lifrVisibility) {
        return 'LIFR';
    }
    // If ceiling < 500 but visibility unknown, check if visibility would make it IFR or worse
    // If visibility < 1 but ceiling unknown, check if ceiling would make it IFR or worse
    if ($lifrCeiling && $visibility === null) {
        // Ceiling alone indicates LIFR range, but without visibility, use worst case
        return 'LIFR';
    }
    if ($lifrVisibility && $ceiling === null) {
        // Visibility alone indicates LIFR range, but without ceiling, use worst case
        return 'LIFR';
    }
    
    // IFR: ceiling 500 to < 1000 ft OR visibility 1 to <= 3 SM (includes exactly 3 SM)
    if ($ceiling !== null && $ceiling >= 500 && $ceiling < 1000) {
        return 'IFR';
    }
    if ($visibility !== null && $visibility >= 1 && $visibility <= 3) {
        return 'IFR';
    }
    
    // MVFR: ceiling 1000 to < 3000 ft OR visibility > 3 to <= 5 SM (excludes 3 SM which is IFR)
    if ($ceiling !== null && $ceiling >= 1000 && $ceiling < 3000) {
        return 'MVFR';
    }
    if ($visibility !== null && $visibility > 3 && $visibility <= 5) {
        return 'MVFR';
    }
    
    // VFR: all other conditions (visibility > 5 SM and ceiling > 3000 ft)
    // But only if we have at least one piece of valid data
    if ($visibility === null && $ceiling === null) {
        return null; // Cannot determine category without any data
    }
    
    return 'VFR';
}

/**
 * Update today's high and low temperatures for an airport
 * Uses airport's local timezone midnight for date key to ensure daily reset at local midnight
 * Still uses Y-m-d format for consistency, but calculated from local timezone
 */
function updateTempExtremes($airportId, $currentTemp, $airport = null) {
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
                $tempExtremes = json_decode($content, true) ?? [];
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
            aviationwx_log('info', 'cleaned old temp extremes', ['removed' => $cleaned, 'date_key' => $dateKey]);
        }
        
        // Initialize today's entry if it doesn't exist (always start fresh for new day)
        // This ensures we never use yesterday's data for today
        $now = time(); // Current timestamp in UTC
        if (!isset($tempExtremes[$dateKey][$airportId])) {
            aviationwx_log('info', 'initializing new day temp extremes', ['airport' => $airportId, 'date_key' => $dateKey, 'temp' => $currentTemp]);
            $tempExtremes[$dateKey][$airportId] = [
                'high' => $currentTemp,
                'low' => $currentTemp,
                'high_ts' => $now,  // Timestamp when high was recorded
                'low_ts' => $now    // Timestamp when low was recorded
            ];
        } else {
            // Update high if current is higher
            if ($currentTemp > $tempExtremes[$dateKey][$airportId]['high']) {
                $tempExtremes[$dateKey][$airportId]['high'] = $currentTemp;
                $tempExtremes[$dateKey][$airportId]['high_ts'] = $now; // Update timestamp when new high is set
            }
            // Update low if current is lower
            if ($currentTemp < $tempExtremes[$dateKey][$airportId]['low']) {
                $tempExtremes[$dateKey][$airportId]['low'] = $currentTemp;
                $tempExtremes[$dateKey][$airportId]['low_ts'] = $now; // Update timestamp when new low is set
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
    
    $tempExtremes = json_decode(file_get_contents($file), true) ?? [];
    
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

