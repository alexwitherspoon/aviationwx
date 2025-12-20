<?php
/**
 * Weather Data Fetcher
 * Fetches weather data from configured source for the specified airport
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/rate-limit.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/constants.php';
require_once __DIR__ . '/../lib/circuit-breaker.php';

// Weather API adapters (domain-organized)
require_once __DIR__ . '/../lib/weather/utils.php';
require_once __DIR__ . '/../lib/weather/calculator.php';
require_once __DIR__ . '/../lib/weather/staleness.php';
require_once __DIR__ . '/../lib/weather/fetcher.php';
require_once __DIR__ . '/../lib/weather/adapter/tempest-v1.php';
require_once __DIR__ . '/../lib/weather/adapter/ambient-v1.php';
require_once __DIR__ . '/../lib/weather/adapter/weatherlink-v1.php';
require_once __DIR__ . '/../lib/weather/adapter/pwsweather-v1.php';
require_once __DIR__ . '/../lib/weather/adapter/synopticdata-v1.php';
require_once __DIR__ . '/../lib/weather/adapter/metar-v1.php';
require_once __DIR__ . '/../lib/weather/daily-tracking.php';

// parseAmbientResponse() is now in lib/weather/adapter/ambient-v1.php

/**
 * Generate mock weather data for local testing
 * 
 * Generates consistent but varied mock weather data based on airport ID.
 * Uses airport ID as seed to ensure each airport has different but stable weather.
 * Returns realistic values for all weather fields.
 * 
 * @param string $airportId Airport ID (used as seed for consistency)
 * @param array $airport Airport configuration array
 * @return array Mock weather data array with all standard fields
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

// parseMETARResponse() is now in lib/weather/adapter/metar-v1.php

// All staleness functions (mergeWeatherDataWithFallback, nullStaleFieldsBySource)
// are now in lib/weather/staleness.php

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

    // Rate limiting
    if (!checkRateLimit('weather_api', RATE_LIMIT_WEATHER_MAX, RATE_LIMIT_WEATHER_WINDOW)) {
    http_response_code(429);
        header('Retry-After: ' . RATE_LIMIT_WEATHER_WINDOW);
    ob_clean();
    aviationwx_log('warning', 'weather rate limited', [], 'app');
    echo json_encode(['success' => false, 'error' => 'Too many requests. Please try again later.']);
    exit;
    }

    // Get and validate airport identifier (supports ICAO, IATA, FAA, or airport ID)
    $rawIdentifier = $_GET['airport'] ?? '';
    if (empty($rawIdentifier)) {
    http_response_code(400);
    ob_clean();
    aviationwx_log('error', 'missing airport identifier', [], 'user');
    echo json_encode(['success' => false, 'error' => 'Airport identifier required']);
    exit;
    }

    // Basic format validation: identifiers should be 3-4 alphanumeric characters
    // (ICAO: 4 chars, IATA: 3 chars, FAA: 3-4 chars, airport ID: 3-4 chars)
    $trimmed = trim($rawIdentifier);
    if (empty($trimmed) || strlen($trimmed) < 3 || strlen($trimmed) > 4 || !preg_match('/^[a-z0-9]{3,4}$/i', $trimmed)) {
    http_response_code(400);
    ob_clean();
    aviationwx_log('error', 'invalid airport identifier format', ['identifier' => $rawIdentifier], 'user');
    echo json_encode(['success' => false, 'error' => 'Invalid airport identifier format']);
    exit;
    }

    // Find airport by any identifier type
    $result = findAirportByIdentifier($rawIdentifier);
    if ($result === null || !isset($result['airport']) || !isset($result['airportId'])) {
        http_response_code(HTTP_STATUS_NOT_FOUND);
    ob_clean();
    aviationwx_log('error', 'airport not found', ['identifier' => $rawIdentifier], 'user');
    echo json_encode(['success' => false, 'error' => 'Airport not found']);
    exit;
    }

    $airport = $result['airport'];
    $airportId = $result['airportId'];
    
    // Check if airport is enabled (opt-in model: must have enabled: true)
    if (!isAirportEnabled($airport)) {
        http_response_code(HTTP_STATUS_NOT_FOUND);
        ob_clean();
        aviationwx_log('error', 'airport not enabled', ['identifier' => $rawIdentifier, 'airport_id' => $airportId], 'user');
        echo json_encode(['success' => false, 'error' => 'Airport not found']);
        exit;
    }

    // Check if we're using test config or local dev mode - if so, return mock weather data
    $envConfigPath = getenv('CONFIG_PATH');
    $isTestConfig = ($envConfigPath && strpos($envConfigPath, 'airports.json.test') !== false);
    $isLocalDev = (getenv('APP_ENV') !== 'production' && !isProduction());
    $useMockWeather = $isTestConfig || (getenv('MOCK_WEATHER') === 'true') || ($isLocalDev && getenv('MOCK_WEATHER') !== 'false');
    
    if ($useMockWeather) {
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
        
        // Set cache headers (use config default for consistency)
        $defaultWeatherRefresh = getDefaultWeatherRefresh();
        header('Cache-Control: public, max-age=' . $defaultWeatherRefresh);
        header('ETag: ' . $etag);
        header('X-Cache-Status: MOCK');
        
        ob_clean();
        aviationwx_log('info', 'weather mock data served', ['airport' => $airportId], 'user');
        echo $body;
        exit;
    }

    // Weather refresh interval (per-airport, with global config default)
    $defaultWeatherRefresh = getDefaultWeatherRefresh();
    $airportWeatherRefresh = isset($airport['weather_refresh_seconds']) ? intval($airport['weather_refresh_seconds']) : $defaultWeatherRefresh;

    // Cached weather path
    $weatherCacheDir = __DIR__ . '/../cache';
    if (!file_exists($weatherCacheDir)) {
    @mkdir($weatherCacheDir, 0755, true);
    }
    $weatherCacheFile = $weatherCacheDir . '/weather_' . $airportId . '.json';

    // nullStaleFieldsBySource is defined in lib/weather/staleness.php (required at top of file)
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
            $maxStaleSeconds = MAX_STALE_HOURS * 3600;
            $maxStaleSecondsMetar = WEATHER_STALENESS_ERROR_HOURS_METAR * 3600;
            nullStaleFieldsBySource($cached, $maxStaleSeconds, $maxStaleSecondsMetar);
            
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
            $maxStaleSeconds = MAX_STALE_HOURS * 3600;
            $maxStaleSecondsMetar = WEATHER_STALENESS_ERROR_HOURS_METAR * 3600;
            nullStaleFieldsBySource($staleData, $maxStaleSeconds, $maxStaleSecondsMetar);
            
            $hasStaleCache = true;
            
            // Set stale-while-revalidate headers (serve stale, but allow background refresh)
            header('Cache-Control: public, max-age=' . $airportWeatherRefresh . ', stale-while-revalidate=' . STALE_WHILE_REVALIDATE_SECONDS);
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

    // fetchWeatherAsync() and fetchWeatherSync() are now in lib/weather/fetcher.php
    
    // Normalize weather source configuration (handle airports with metar_station but no weather_source)
    if (!normalizeWeatherSource($airport)) {
        http_response_code(HTTP_STATUS_SERVICE_UNAVAILABLE);
        aviationwx_log('error', 'no weather source configured', ['airport' => $airportId], 'app');
        ob_clean();
        echo json_encode(['success' => false, 'error' => 'Weather source not configured']);
        exit;
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
    
    } catch (Throwable $e) {
        $weatherError = 'Error fetching weather: ' . $e->getMessage();
        aviationwx_log('error', 'weather fetch error', [
            'airport' => $airportId,
            'err' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ], 'app');
    }

    if ($weatherError !== null) {
        // If we already served stale cache, don't send another response (background refresh failed)
        if ($hasStaleCache) {
            // Request already finished with stale cache response, just log the error
            aviationwx_log('warning', 'weather api background refresh error, stale cache was served', [
                'airport' => $airportId,
                'err' => $weatherError
            ], 'app');
            exit; // Don't send another response, request already finished
        }
        
        http_response_code(HTTP_STATUS_SERVICE_UNAVAILABLE);
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
    http_response_code(HTTP_STATUS_SERVICE_UNAVAILABLE);
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
    // Always retrieve today's extremes from cache (even if current temp is null)
    // This prevents the merge function from preserving yesterday's values
    $currentTemp = $weatherData['temperature'] ?? null;
    $tempExtremes = getTempExtremes($airportId, $currentTemp ?? 0, $airport);
    $weatherData['temp_high_today'] = $tempExtremes['high'];
    $weatherData['temp_low_today'] = $tempExtremes['low'];
    $weatherData['temp_high_ts'] = $tempExtremes['high_ts'] ?? null;
    $weatherData['temp_low_ts'] = $tempExtremes['low_ts'] ?? null;
    
    // Update the cache with current temperature if available
    if ($currentTemp !== null) {
        // Use explicit observation time from primary source (when weather was actually observed)
        // This is critical for pilot safety - must show accurate observation times
        // Prefer obs_time_primary (explicit observation time from API), fall back to last_updated_primary (fetch time), then current time
        $obsTimestamp = $weatherData['obs_time_primary'] ?? $weatherData['last_updated_primary'] ?? time();
        updateTempExtremes($airportId, $currentTemp, $airport, $obsTimestamp);
        // Re-fetch after update to get the latest values
        $tempExtremes = getTempExtremes($airportId, $currentTemp, $airport);
        $weatherData['temp_high_today'] = $tempExtremes['high'];
        $weatherData['temp_low_today'] = $tempExtremes['low'];
        $weatherData['temp_high_ts'] = $tempExtremes['high_ts'] ?? null;
        $weatherData['temp_low_ts'] = $tempExtremes['low_ts'] ?? null;
    }

    // Calculate VFR/IFR/MVFR status
    calculateAndSetFlightCategory($weatherData);

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

    // Merge with existing cache to preserve last known good values for missing/invalid fields
    $existingCache = null;
    if (file_exists($weatherCacheFile)) {
        $existingCacheJson = @file_get_contents($weatherCacheFile);
        if ($existingCacheJson !== false) {
            $existingCache = json_decode($existingCacheJson, true);
        }
    }
    
    // If we have existing cache, merge it with new data to preserve good values
    // Use separate thresholds for primary source and METAR
    if (is_array($existingCache)) {
        $maxStaleSeconds = MAX_STALE_HOURS * 3600;
        $maxStaleSecondsMetar = WEATHER_STALENESS_ERROR_HOURS_METAR * 3600;
        $weatherData = mergeWeatherDataWithFallback($weatherData, $existingCache, $maxStaleSeconds, $maxStaleSecondsMetar);
        
        // Recalculate flight category after merge - visibility/ceiling may have been added from cache
        // If fresh fetch didn't include METAR data but cache has it, flight_category would be null
        // without this recalculation, causing blank condition field on dashboard
        calculateAndSetFlightCategory($weatherData);
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

// All adapter functions (parseTempestResponse, fetchTempestWeather, parseAmbientResponse, fetchAmbientWeather,
// parseWeatherLinkResponse, fetchWeatherLinkWeather, parseMETARResponse, fetchMETARFromStation, fetchMETAR)
// are now in lib/weather/adapter/ directory


// All calculation functions (calculateDewpoint, calculateHumidityFromDewpoint, calculatePressureAltitude,
// calculateDensityAltitude, calculateFlightCategory) are now in lib/weather/calculator.php

// All utility functions (getAirportTimezone, getAirportDateKey, getSunriseTime, getSunsetTime)
// are now in lib/weather/utils.php

// All daily tracking functions (updatePeakGust, getPeakGust, updateTempExtremes, getTempExtremes)
// are now in lib/weather/daily-tracking.php

