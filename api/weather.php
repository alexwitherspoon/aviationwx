<?php
/**
 * Weather Data Fetcher
 * Fetches weather data from configured source for the specified airport
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/cache-paths.php';
require_once __DIR__ . '/../lib/rate-limit.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/constants.php';
require_once __DIR__ . '/../lib/circuit-breaker.php';
require_once __DIR__ . '/../lib/metrics.php';

// Weather pipeline
require_once __DIR__ . '/../lib/weather/utils.php';
require_once __DIR__ . '/../lib/weather/calculator.php';
require_once __DIR__ . '/../lib/weather/daily-tracking.php';
require_once __DIR__ . '/../lib/weather/UnifiedFetcher.php';
require_once __DIR__ . '/../lib/weather/cache-utils.php';
require_once __DIR__ . '/../lib/weather/history.php';

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

// parseMETARResponse() is in lib/weather/adapter/metar-v1.php
// nullStaleFieldsBySource() is in lib/weather/cache-utils.php

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
    
    // Track weather request metric
    metrics_track_weather_request($airportId);
    
    // Debug mode: collect pipeline information for diagnostics
    // Usage: ?airport=kspb&debug=1 (requires force=1 or cron user agent for fresh fetch)
    $debugMode = isset($_GET['debug']) && $_GET['debug'] === '1';
    $debugInfo = [];
    if ($debugMode) {
        $debugInfo['request'] = [
            'timestamp' => time(),
            'timestamp_iso' => date('c'),
            'airport_id' => $airportId,
            'raw_identifier' => $rawIdentifier,
            'force' => isset($_GET['force']) && $_GET['force'] === '1',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ];
        $debugInfo['config'] = [
            'weather_source_type' => $airport['weather_source']['type'] ?? null,
            'weather_source_backup_type' => $airport['weather_source_backup']['type'] ?? null,
            'metar_station' => $airport['metar_station'] ?? null,
            'refresh_interval' => $airport['weather_refresh_seconds'] ?? getDefaultWeatherRefresh(),
        ];
    }
    
    // Check if airport is enabled (opt-in model: must have enabled: true)
    if (!isAirportEnabled($airport)) {
        http_response_code(HTTP_STATUS_NOT_FOUND);
        ob_clean();
        aviationwx_log('error', 'airport not enabled', ['identifier' => $rawIdentifier, 'airport_id' => $airportId], 'user');
        echo json_encode(['success' => false, 'error' => 'Airport not found']);
        exit;
    }

    // Check if we should use mock weather data (only during tests or when explicitly enabled)
    // Mock weather is enabled when:
    // 1. Using test config (CONFIG_PATH contains airports.json.test), OR
    // 2. Running in test environment (APP_ENV=testing, set by PHPUnit), OR
    // 3. Explicitly enabled via MOCK_WEATHER=true (manual override)
    $envConfigPath = getenv('CONFIG_PATH');
    $isTestConfig = ($envConfigPath && strpos($envConfigPath, 'airports.json.test') !== false);
    $isTestEnvironment = (getenv('APP_ENV') === 'testing');
    $useMockWeather = $isTestConfig || $isTestEnvironment || (getenv('MOCK_WEATHER') === 'true');
    
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
        
        // Ensure _field_obs_time_map is present (for frontend fail-closed staleness validation)
        if (!isset($mockWeather['_field_obs_time_map']) || !is_array($mockWeather['_field_obs_time_map'])) {
            $mockWeather['_field_obs_time_map'] = [];
        }
        
        // Build response
        $payload = ['success' => true, 'weather' => $mockWeather];
        $body = json_encode($payload);
        $etag = 'W/"' . sha1($body) . '"';
        
        // Set cache headers (use config default for consistency)
        $defaultWeatherRefresh = getDefaultWeatherRefresh();
        $cloudflareMaxAge = max(30, intval($defaultWeatherRefresh / 2));
        header('Cache-Control: public, max-age=' . $defaultWeatherRefresh . ', s-maxage=' . $cloudflareMaxAge . ', stale-while-revalidate=' . STALE_WHILE_REVALIDATE_SECONDS);
        header('ETag: ' . $etag);
        header('Vary: Accept');
        header('X-Cache-Status: MOCK');
        
        ob_clean();
        aviationwx_log('info', 'weather mock data served', ['airport' => $airportId], 'user');
        echo $body;
        exit;
    }

    // Weather refresh interval (per-airport, with global config default)
    $defaultWeatherRefresh = getDefaultWeatherRefresh();
    $airportWeatherRefresh = isset($airport['weather_refresh_seconds']) ? intval($airport['weather_refresh_seconds']) : $defaultWeatherRefresh;

    // Cached weather path - use centralized cache paths
    ensureCacheDir(CACHE_WEATHER_DIR);
    $weatherCacheFile = getWeatherCachePath($airportId);

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
            // Safety check: Apply failclosed staleness (hide data too old to display)
            $isMetarOnly = isset($airport['weather_source']['type']) && $airport['weather_source']['type'] === 'metar';
            applyFailclosedStaleness($cached, $airport, $isMetarOnly);
            
            // Set cache headers for cached responses
            // Use s-maxage to control Cloudflare cache separately from browser cache
            // Cloudflare cache expires faster (half of refresh interval, min 30s) to reduce stale cache issues
            // Browser cache can be longer for better offline experience
            $remainingTime = $airportWeatherRefresh - $age;
            $cloudflareMaxAge = max(30, min($remainingTime, intval($airportWeatherRefresh / 2)));
            header('Cache-Control: public, max-age=' . $remainingTime . ', s-maxage=' . $cloudflareMaxAge . ', stale-while-revalidate=' . STALE_WHILE_REVALIDATE_SECONDS);
            header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $remainingTime) . ' GMT');
            header('Vary: Accept');
            header('X-Cache-Status: HIT');
            
            // Ensure _field_obs_time_map is present (for frontend fail-closed staleness validation)
            // Old cache files might not have this field
            if (!isset($cached['_field_obs_time_map']) || !is_array($cached['_field_obs_time_map'])) {
                $cached['_field_obs_time_map'] = [];
            }
            
            $payload = ['success' => true, 'weather' => $cached];
            
            // Add debug info if debug mode is enabled
            if ($debugMode) {
                $now = time();
                $debugInfo['cache'] = [
                    'status' => 'fresh',
                    'age_seconds' => $age,
                    'cache_file' => basename($weatherCacheFile),
                ];
                $debugInfo['result'] = [
                    'temperature' => $cached['temperature'] ?? null,
                    'pressure' => $cached['pressure'] ?? null,
                    'wind_speed' => $cached['wind_speed'] ?? null,
                    'obs_time_primary' => $cached['obs_time_primary'] ?? null,
                    'obs_time_primary_age_seconds' => isset($cached['obs_time_primary']) ? ($now - $cached['obs_time_primary']) : null,
                    'last_updated' => $cached['last_updated'] ?? null,
                ];
                $debugInfo['field_obs_times'] = $cached['_field_obs_time_map'] ?? [];
                $debugInfo['field_obs_times_ages'] = [];
                foreach (($cached['_field_obs_time_map'] ?? []) as $field => $obsTime) {
                    $debugInfo['field_obs_times_ages'][$field] = $now - $obsTime;
                }
                $payload['debug'] = $debugInfo;
            }
            
            ob_clean();
            echo json_encode($payload, $debugMode ? JSON_PRETTY_PRINT : 0);
            exit;
        }
    } elseif (file_exists($weatherCacheFile) && !$forceRefresh) {
        // Cache is stale but exists - check per-source staleness
        $age = time() - filemtime($weatherCacheFile);
        $staleData = json_decode(file_get_contents($weatherCacheFile), true);
        if (is_array($staleData)) {
            // Safety check: Apply failclosed staleness (hide data too old to display)
            $isMetarOnly = isset($airport['weather_source']['type']) && $airport['weather_source']['type'] === 'metar';
            applyFailclosedStaleness($staleData, $airport, $isMetarOnly);
            
            $hasStaleCache = true;
            
            // Set stale-while-revalidate headers (serve stale, but allow background refresh)
            // Use s-maxage to limit Cloudflare cache - shorter TTL to prevent serving stale data too long
            // Cloudflare cache expires faster (half of refresh interval, min 30s) to reduce stale cache issues
            $cloudflareMaxAge = max(30, intval($airportWeatherRefresh / 2));
            header('Cache-Control: public, max-age=' . $airportWeatherRefresh . ', s-maxage=' . $cloudflareMaxAge . ', stale-while-revalidate=' . STALE_WHILE_REVALIDATE_SECONDS);
            header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $airportWeatherRefresh) . ' GMT');
            header('Vary: Accept');
            header('X-Cache-Status: STALE');
            
            // Ensure _field_obs_time_map is present (for frontend fail-closed staleness validation)
            // Old cache files might not have this field
            if (!isset($staleData['_field_obs_time_map']) || !is_array($staleData['_field_obs_time_map'])) {
                $staleData['_field_obs_time_map'] = [];
            }
            
            $payload = ['success' => true, 'weather' => $staleData, 'stale' => true];
            
            // Add debug info if debug mode is enabled
            if ($debugMode) {
                $now = time();
                $debugInfo['cache'] = [
                    'status' => 'stale',
                    'age_seconds' => $age,
                    'cache_file' => basename($weatherCacheFile),
                    'triggering_background_refresh' => true,
                ];
                $debugInfo['result'] = [
                    'temperature' => $staleData['temperature'] ?? null,
                    'pressure' => $staleData['pressure'] ?? null,
                    'wind_speed' => $staleData['wind_speed'] ?? null,
                    'obs_time_primary' => $staleData['obs_time_primary'] ?? null,
                    'obs_time_primary_age_seconds' => isset($staleData['obs_time_primary']) ? ($now - $staleData['obs_time_primary']) : null,
                    'last_updated' => $staleData['last_updated'] ?? null,
                ];
                $debugInfo['field_obs_times'] = $staleData['_field_obs_time_map'] ?? [];
                $debugInfo['field_obs_times_ages'] = [];
                foreach (($staleData['_field_obs_time_map'] ?? []) as $field => $obsTime) {
                    $debugInfo['field_obs_times_ages'][$field] = $now - $obsTime;
                }
                $payload['debug'] = $debugInfo;
            }
            
            // Serve stale data immediately with flush
            ob_clean();
            echo json_encode($payload, $debugMode ? JSON_PRETTY_PRINT : 0);
            
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
            }
            
            // Use file-based locking to prevent concurrent refreshes from multiple clients
            $lockFile = $weatherCacheDir . '/refresh_' . $airportId . '.lock';
            
            // Clean up stale locks (older than 5 minutes) - use atomic check-and-delete
            if (file_exists($lockFile)) {
                $lockMtime = @filemtime($lockFile);
                if ($lockMtime !== false && (time() - $lockMtime) > FILE_LOCK_STALE_SECONDS) {
                    // Try to delete only if still old (race condition protection)
                    $currentMtime = @filemtime($lockFile);
                    if ($currentMtime !== false && (time() - $currentMtime) > FILE_LOCK_STALE_SECONDS) {
                        @unlink($lockFile);
                    }
                }
            }
            
            $lockFp = @fopen($lockFile, 'c+');
            $lockAcquired = false;
            $lockCleanedUp = false; // Track if lock has been cleaned up to prevent double cleanup
            
            if ($lockFp !== false) {
                // Try to acquire exclusive lock (non-blocking)
                if (@flock($lockFp, LOCK_EX | LOCK_NB)) {
                    $lockAcquired = true;
                    // Write PID and timestamp to lock file for debugging
                    @fwrite($lockFp, json_encode([
                        'pid' => getmypid(),
                        'started' => time(),
                        'airport' => $airportId
                    ]));
                    @fflush($lockFp);
                    
                    // Register shutdown function to clean up lock on script exit
                    register_shutdown_function(function() use ($lockFp, $lockFile, &$lockCleanedUp) {
                        if ($lockCleanedUp) {
                            return; // Already cleaned up
                        }
                        if (is_resource($lockFp)) {
                            @flock($lockFp, LOCK_UN);
                            @fclose($lockFp);
                        }
                        if (file_exists($lockFile)) {
                            @unlink($lockFile);
                        }
                        $lockCleanedUp = true;
                    });
                } else {
                    // Another refresh is already in progress
                    @fclose($lockFp);
                    aviationwx_log('info', 'weather background refresh skipped - already in progress', [
                        'airport' => $airportId
                    ], 'app');
                    exit; // Exit silently - another process is handling the refresh
                }
            } else {
                // Couldn't create lock file, but continue anyway (non-critical)
                aviationwx_log('warning', 'weather background refresh lock file creation failed', [
                    'airport' => $airportId
                ], 'app');
            }
            
            // Only continue with background refresh if we acquired the lock
            if ($lockAcquired) {
                // Set time limit for background refresh (increased to handle slow APIs)
                set_time_limit(45);
                aviationwx_log('info', 'background refresh started', [
                    'airport' => $airportId,
                    'cache_age' => $age,
                    'refresh_interval' => $airportWeatherRefresh
                ], 'app');
                
                // Continue to refresh in background (don't exit here)
            } else {
                // Lock not acquired, exit silently
                exit;
            }
        }
    }
    // If forceRefresh is true or no cache file exists, continue to fetch fresh weather
    }
    
    // Normalize weather source configuration (handle airports with metar_station but no weather_source)
    if (!normalizeWeatherSource($airport)) {
        http_response_code(HTTP_STATUS_SERVICE_UNAVAILABLE);
        aviationwx_log('error', 'no weather source configured', ['airport' => $airportId], 'app');
        ob_clean();
        echo json_encode(['success' => false, 'error' => 'Weather source not configured']);
        exit;
    }
    
    // Fetch weather using unified fetcher
    $weatherData = null;
    $weatherError = null;
    
    try {
        $fetchStartTime = microtime(true);
        $weatherData = fetchWeatherUnified($airport, $airportId);
        if ($debugMode) {
            $debugInfo['fetch'] = [
                'method' => 'unified',
                'duration_ms' => round((microtime(true) - $fetchStartTime) * 1000, 2),
                'sources_attempted' => $weatherData['_sources_attempted'] ?? 0,
                'sources_succeeded' => $weatherData['_sources_succeeded'] ?? 0,
            ];
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

    // Daily tracking (side effects - updates cache files)
    // Track and update today's peak gust (store value and timestamp)
    // Use peak_gust field if available (set by adapters), otherwise gust_speed, otherwise 0
    // peak_gust is set by adapters and might survive merge better than gust_speed
    $currentGust = $weatherData['peak_gust'] ?? $weatherData['gust_speed'] ?? 0;
    // Use explicit observation time from primary source (when weather was actually observed)
    // This is critical for pilot safety - must show accurate observation times
    // Prefer obs_time_primary (explicit observation time from API), fall back to last_updated_primary (fetch time), then current time
    $obsTimestamp = $weatherData['obs_time_primary'] ?? $weatherData['last_updated_primary'] ?? time();
    // Only update if we have a valid gust value (> 0)
    if ($currentGust > 0) {
        updatePeakGust($airportId, $currentGust, $airport, $obsTimestamp);
    }
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

    // Unified fetcher already provides all calculated fields and _field_obs_time_map
    // Just need to finalize the response

    // Ensure _field_obs_time_map is present for frontend validation
    if (!isset($weatherData['_field_obs_time_map']) || !is_array($weatherData['_field_obs_time_map'])) {
        $weatherData['_field_obs_time_map'] = [];
    }

    // Determine backup status from field source map
    $backupStatus = 'standby';
    $fieldSourceMap = $weatherData['_field_source_map'] ?? [];
    if (!empty($fieldSourceMap)) {
        foreach ($fieldSourceMap as $field => $source) {
            if ($source === 'backup') {
                $backupStatus = 'active';
                break;
            }
        }
    }
    $weatherData['backup_status'] = $backupStatus;
    
    // Set recovery cycles (legacy field, keep for compatibility)
    $weatherData['primary_recovery_cycles'] = 0;
    
    // Capture field source map for history before removing it
    $historySourceMap = $fieldSourceMap;
    
    // Remove internal fields before caching and sending response
    // Strip internal fields before API response
    unset($weatherData['_field_source_map']);
    // Keep _field_obs_time_map for frontend fail-closed staleness validation
    // Frontend uses per-field observation times to hide stale data when offline
    // Ensure _field_obs_time_map is always present (even if empty) for frontend validation
    if (!isset($weatherData['_field_obs_time_map']) || !is_array($weatherData['_field_obs_time_map'])) {
        $weatherData['_field_obs_time_map'] = [];
    }
    unset($weatherData['_quality_metadata']);

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
        
        // Append to weather history with source attribution
        appendWeatherHistory($airportId, $weatherData, $historySourceMap);
    }

    // If we served stale data, we're in background refresh mode
    // Don't send headers or output again (already sent to client)
    // History was already appended above after cache write
    if ($hasStaleCache) {
        // Just update the cache silently in background
        aviationwx_log('info', 'background refresh completed successfully', ['airport' => $airportId], 'app');
        exit;
    }

    // Build ETag for response based on content
    $payload = ['success' => true, 'weather' => $weatherData];
    
    // Add debug info if debug mode is enabled
    if ($debugMode) {
        $now = time();
        $debugInfo['result'] = [
            'temperature' => $weatherData['temperature'] ?? null,
            'pressure' => $weatherData['pressure'] ?? null,
            'wind_speed' => $weatherData['wind_speed'] ?? null,
            'obs_time_primary' => $weatherData['obs_time_primary'] ?? null,
            'obs_time_primary_age_seconds' => isset($weatherData['obs_time_primary']) ? ($now - $weatherData['obs_time_primary']) : null,
            'obs_time_backup' => $weatherData['obs_time_backup'] ?? null,
            'obs_time_metar' => $weatherData['obs_time_metar'] ?? null,
            'last_updated' => $weatherData['last_updated'] ?? null,
            'last_updated_primary' => $weatherData['last_updated_primary'] ?? null,
            'primary_recovery_cycles' => $weatherData['primary_recovery_cycles'] ?? null,
            'backup_status' => $weatherData['backup_status'] ?? null,
        ];
        $debugInfo['field_obs_times'] = $weatherData['_field_obs_time_map'] ?? [];
        $debugInfo['field_obs_times_ages'] = [];
        foreach (($weatherData['_field_obs_time_map'] ?? []) as $field => $obsTime) {
            $debugInfo['field_obs_times_ages'][$field] = $now - $obsTime;
        }
        $debugInfo['staleness_thresholds'] = [
            'warning_seconds' => getStaleWarningSeconds($airport),
            'error_seconds' => getStaleErrorSeconds($airport),
            'failclosed_seconds' => getStaleFailclosedSeconds($airport),
        ];
        $payload['debug'] = $debugInfo;
    }
    
    $body = json_encode($payload, JSON_PRETTY_PRINT);
    $etag = 'W/"' . sha1($body) . '"';

    // Conditional requests support
    $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
    if ($ifNoneMatch === $etag) {
    // Use s-maxage to control Cloudflare cache separately from browser cache
    $cloudflareMaxAge = max(30, intval($airportWeatherRefresh / 2));
    header('Cache-Control: public, max-age=' . $airportWeatherRefresh . ', s-maxage=' . $cloudflareMaxAge . ', stale-while-revalidate=' . STALE_WHILE_REVALIDATE_SECONDS);
    header('ETag: ' . $etag);
    header('Vary: Accept');
    header('X-Cache-Status: MISS');
    http_response_code(304);
    exit;
    }

    // Set cache headers for fresh data (short-lived)
    // Use s-maxage to control Cloudflare cache separately from browser cache
    // Cloudflare cache expires faster (half of refresh interval, min 30s) to reduce stale cache issues
    // Browser cache can be longer for better offline experience
    $cloudflareMaxAge = max(30, intval($airportWeatherRefresh / 2));
    header('Cache-Control: public, max-age=' . $airportWeatherRefresh . ', s-maxage=' . $cloudflareMaxAge . ', stale-while-revalidate=' . STALE_WHILE_REVALIDATE_SECONDS);
    header('ETag: ' . $etag);
    header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $airportWeatherRefresh) . ' GMT');
    header('Vary: Accept');
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

