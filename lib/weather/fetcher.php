<?php
/**
 * Weather Data Fetcher (LEGACY)
 * 
 * @deprecated Use UnifiedFetcher.php instead. This file is kept for backward compatibility
 *             and can be activated via ?legacy=1 query parameter.
 * 
 * Functions for fetching weather data from APIs synchronously and asynchronously.
 * Handles circuit breaker logic, error handling, and parallel requests.
 * 
 * The new UnifiedFetcher provides:
 * - Cleaner aggregation with predictable behavior
 * - All sources fetched in parallel
 * - Field-level source tracking
 * - METAR preference for visibility/ceiling
 * - Wind group integrity (complete from single source)
 */

require_once __DIR__ . '/../logger.php';
require_once __DIR__ . '/../circuit-breaker.php';
require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/../test-mocks.php';
require_once __DIR__ . '/adapter/tempest-v1.php';
require_once __DIR__ . '/adapter/ambient-v1.php';
require_once __DIR__ . '/adapter/weatherlink-v1.php';
require_once __DIR__ . '/adapter/pwsweather-v1.php';
require_once __DIR__ . '/adapter/synopticdata-v1.php';
require_once __DIR__ . '/adapter/metar-v1.php';
require_once __DIR__ . '/utils.php';

/**
 * Determine if backup weather source should be fetched
 * 
 * Backup is fetched when primary source exceeds 4x refresh interval (warm-up period: x5 - 1),
 * or when primary has missing/null fields, or when existing cache shows primary fields are stale.
 * This ensures backup data is ready when primary exceeds 5x threshold.
 * 
 * @param array $airport Airport configuration array
 * @param array|null $primaryData Primary weather data (from current fetch, if available)
 * @param array|null $existingCache Existing cached weather data
 * @param int $refreshIntervalSeconds Weather refresh interval in seconds
 * @return bool True if backup should be fetched, false otherwise
 */
function shouldFetchBackup(array $airport, ?array $primaryData, ?array $existingCache, int $refreshIntervalSeconds): bool {
    // Check if backup is configured
    if (!isset($airport['weather_source_backup']) || !is_array($airport['weather_source_backup'])) {
        return false;
    }
    
    // PRIORITY 1: Check if primary has missing/null fields for expected primary-source fields
    // This triggers backup immediately when any field fails, without waiting for staleness threshold
    $primarySourceFields = [
        'temperature', 'wind_speed', 'wind_direction', 'pressure', 'humidity'
    ];
    
    $hasMissingFields = false;
    if ($primaryData !== null) {
        foreach ($primarySourceFields as $field) {
            if (!isset($primaryData[$field]) || $primaryData[$field] === null) {
                $hasMissingFields = true;
                break;
            }
        }
    } elseif ($existingCache !== null) {
        foreach ($primarySourceFields as $field) {
            if (!isset($existingCache[$field]) || $existingCache[$field] === null) {
                $hasMissingFields = true;
                break;
            }
        }
    }
    
    // If any field is missing/null, trigger backup immediately (don't wait for staleness)
    if ($hasMissingFields) {
        return true;
    }
    
    // PRIORITY 2: Check if primary data is stale (age >= 1x refresh interval)
    // Trigger backup immediately when data is stale, don't wait for 4x threshold
    // This ensures backup is used as soon as primary data becomes outdated
    $now = time();
    
    // Check if primary data is stale (age >= 1x refresh interval)
    if ($primaryData !== null && isset($primaryData['last_updated_primary']) && $primaryData['last_updated_primary'] > 0) {
        $primaryAge = $now - $primaryData['last_updated_primary'];
        // If data is older than refresh interval, trigger backup immediately
        if ($primaryAge >= $refreshIntervalSeconds) {
            return true;
        }
    }
    
    // Check existing cache for staleness
    if ($existingCache !== null && isset($existingCache['last_updated_primary']) && $existingCache['last_updated_primary'] > 0) {
        $cachePrimaryAge = $now - $existingCache['last_updated_primary'];
        // If cache is older than refresh interval, trigger backup immediately
        if ($cachePrimaryAge >= $refreshIntervalSeconds) {
            return true;
        }
    }
    
    return false;
}

/**
 * Fetch weather data asynchronously using curl_multi (parallel requests)
 * 
 * Fetches primary weather source, backup source (if needed), and METAR in parallel when needed.
 * Uses circuit breaker logic to skip sources in backoff period.
 * Backup is fetched when primary exceeds 4x refresh interval (warm-up period).
 * 
 * @param array $airport Airport configuration array
 * @param string|null $airportId Airport identifier (optional, defaults to 'unknown')
 * @param int|null $refreshIntervalSeconds Weather refresh interval in seconds (optional, for backup fetching logic)
 * @param array|null $existingCache Existing cached weather data (optional, for backup fetching logic)
 * @return array|null Weather data array with standard keys, or null on failure
 */
function fetchWeatherAsync($airport, $airportId = null, $refreshIntervalSeconds = null, $existingCache = null) {// Normalize weather source configuration (handle airports with metar_station but no weather_source)
    if (!normalizeWeatherSource($airport)) {
        aviationwx_log('error', 'no weather source configured in fetchWeatherAsync', ['airport' => $airportId ?? 'unknown'], 'app');
        return null;
    }
    
    $sourceType = $airport['weather_source']['type'];
    $airportId = $airportId ?? 'unknown';
    
    // Check circuit breaker for primary source
    $primaryCircuit = checkWeatherCircuitBreaker($airportId, 'primary');
    if ($primaryCircuit['skip']) {aviationwx_log('warning', 'primary weather API circuit breaker open - skipping fetch', [
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
                $macAddress = isset($airport['weather_source']['mac_address']) ? trim($airport['weather_source']['mac_address']) : null;
                // Sanitize MAC address (remove whitespace)
                if ($macAddress) {
                    $macAddress = preg_replace('/\s+/', '', $macAddress);
                    if (empty($macAddress)) {
                        $macAddress = null;
                    }
                }
                // Use specific device endpoint if MAC address provided, otherwise device list endpoint
                if ($macAddress) {
                    $primaryUrl = "https://api.ambientweather.net/v1/devices/{$macAddress}?applicationKey={$appKey}&apiKey={$apiKey}";
                } else {
                    $primaryUrl = "https://api.ambientweather.net/v1/devices?applicationKey={$appKey}&apiKey={$apiKey}";
                }
                break;
            case 'weatherlink':
                // WeatherLink requires special handling for header auth, so we'll use sync fetch
                // The async multi-curl approach doesn't easily support custom headers per request
                // We'll handle WeatherLink in the sync path instead
                return fetchWeatherSync($airport, $airportId);
            case 'pwsweather':
                $stationId = $airport['weather_source']['station_id'];
                $clientId = $airport['weather_source']['client_id'];
                $clientSecret = $airport['weather_source']['client_secret'];
                $primaryUrl = "https://api.aerisapi.com/observations/{$stationId}?client_id=" . urlencode($clientId) . "&client_secret=" . urlencode($clientSecret);
                break;
            case 'synopticdata':
                $stationId = $airport['weather_source']['station_id'];
                $apiToken = $airport['weather_source']['api_token'];
                $vars = 'air_temp,relative_humidity,pressure,sea_level_pressure,altimeter,wind_speed,wind_direction,wind_gust,dew_point_temperature,precip_accum_since_local_midnight,precip_accum_24_hour,visibility';
                $primaryUrl = "https://api.synopticdata.com/v2/stations/latest?stid=" . urlencode($stationId) . "&token=" . urlencode($apiToken) . "&vars=" . urlencode($vars);
                break;
            default:
                // Not async-able (METAR-only or unsupported)
                return fetchWeatherSync($airport, $airportId);
        }
    }
    
    // Build METAR URL
    $metarUrl = null;
    $stationId = null; // Initialize for use in error logging
    if (!$metarCircuit['skip']) {
        // Fetch METAR if metar_station is configured
        if (isMetarEnabled($airport)) {
            $stationId = $airport['metar_station'];
            $metarUrl = "https://aviationweather.gov/api/data/metar?ids={$stationId}&format=json&taf=false&hours=0";
        } else {
            aviationwx_log('info', 'METAR not configured - skipping METAR fetch', [
                'airport' => $airportId,
                'icao' => $airport['icao'] ?? 'unknown'
            ], 'app');
        }
    }
    
    // Check if backup should be fetched (4x threshold warm-up period)
    $backupUrl = null;
    $backupSourceType = null;
    $backupCircuit = ['skip' => true]; // Default to skip
    if (isset($airport['weather_source_backup']) && is_array($airport['weather_source_backup'])) {
        $backupSourceType = $airport['weather_source_backup']['type'];
        $backupCircuit = checkWeatherCircuitBreaker($airportId, 'backup');
        
        // CRITICAL: If primary circuit breaker is open, always fetch backup (if backup circuit breaker allows)
        // This ensures we have data when primary is unavailable due to rate limiting or failures
        $shouldFetchBackupNow = false;
        if ($primaryCircuit['skip'] && !$backupCircuit['skip']) {
            // Primary is unavailable - always fetch backup as fallback
            $shouldFetchBackupNow = true;
        } elseif (!$backupCircuit['skip'] && $refreshIntervalSeconds !== null) {
            // Normal case: Check if backup should be fetched (4x threshold)
            // Get primary data if available (from existing cache or will be fetched)
            $primaryDataForCheck = null;
            if ($existingCache !== null) {
                $primaryDataForCheck = $existingCache;
            }
            
            $shouldFetchBackupNow = shouldFetchBackup($airport, $primaryDataForCheck, $existingCache, $refreshIntervalSeconds);
        }
        
        if ($shouldFetchBackupNow) {
            // Build backup URL based on source type
            switch ($backupSourceType) {
                    case 'tempest':
                        $backupApiKey = $airport['weather_source_backup']['api_key'];
                        $backupStationId = $airport['weather_source_backup']['station_id'];
                        $backupUrl = "https://swd.weatherflow.com/swd/rest/observations/station/{$backupStationId}?token={$backupApiKey}";
                        break;
                    case 'ambient':
                        $backupApiKey = $airport['weather_source_backup']['api_key'];
                        $backupAppKey = $airport['weather_source_backup']['application_key'];
                        $backupMacAddress = isset($airport['weather_source_backup']['mac_address']) ? trim($airport['weather_source_backup']['mac_address']) : null;
                        if ($backupMacAddress) {
                            $backupMacAddress = preg_replace('/\s+/', '', $backupMacAddress);
                            if (empty($backupMacAddress)) {
                                $backupMacAddress = null;
                            }
                        }
                        if ($backupMacAddress) {
                            $backupUrl = "https://api.ambientweather.net/v1/devices/{$backupMacAddress}?applicationKey={$backupAppKey}&apiKey={$backupApiKey}";
                        } else {
                            $backupUrl = "https://api.ambientweather.net/v1/devices?applicationKey={$backupAppKey}&apiKey={$backupApiKey}";
                        }
                        break;
                    case 'pwsweather':
                        $backupStationId = $airport['weather_source_backup']['station_id'];
                        $backupClientId = $airport['weather_source_backup']['client_id'];
                        $backupClientSecret = $airport['weather_source_backup']['client_secret'];
                        $backupUrl = "https://api.aerisapi.com/observations/{$backupStationId}?client_id=" . urlencode($backupClientId) . "&client_secret=" . urlencode($backupClientSecret);
                        break;
                    case 'synopticdata':
                        $backupStationId = $airport['weather_source_backup']['station_id'];
                        $backupApiToken = $airport['weather_source_backup']['api_token'];
                        $vars = 'air_temp,relative_humidity,pressure,sea_level_pressure,altimeter,wind_speed,wind_direction,wind_gust,dew_point_temperature,precip_accum_since_local_midnight,precip_accum_24_hour,visibility';
                        $backupUrl = "https://api.synopticdata.com/v2/stations/latest?stid=" . urlencode($backupStationId) . "&token=" . urlencode($backupApiToken) . "&vars=" . urlencode($vars);
                        break;
                    case 'weatherlink':
                        // WeatherLink requires sync fetch (custom headers), skip for async
                        break;
                    case 'metar':
                        // METAR is handled separately, skip here
                        break;
            }
        }
    }
    
    // If both primary and METAR are skipped, return null
    if ($primaryCircuit['skip'] && $metarCircuit['skip']) {return null;
    }
    
    // Check for mock responses in test mode before making real requests
    $primaryMockResponse = $primaryUrl !== null ? getMockHttpResponse($primaryUrl) : null;
    $metarMockResponse = $metarUrl !== null ? getMockHttpResponse($metarUrl) : null;
    $backupMockResponse = $backupUrl !== null ? getMockHttpResponse($backupUrl) : null;
    
    // If all are mocked, skip curl entirely and process mock responses directly
    if ($primaryMockResponse !== null && $metarMockResponse !== null && ($backupUrl === null || $backupMockResponse !== null)) {
        $primaryResponse = $primaryMockResponse;
        $metarResponse = $metarMockResponse;
        $backupResponse = $backupMockResponse;
        $primaryCode = 200;
        $metarCode = 200;
        $backupCode = $backupUrl !== null ? 200 : 0;
        $primaryError = '';
        $metarError = '';
        $backupError = '';
        $primaryErrno = 0;
        $metarErrno = 0;
        $backupErrno = 0;
        // Skip curl execution and jump to response processing
        goto process_responses;
    }
    
    // Create multi-handle for parallel requests
    $mh = curl_multi_init();
    if ($mh === false) {aviationwx_log('error', 'failed to init curl_multi', ['airport' => $airportId], 'app');
        return null;
    }
    
    $ch1 = null;
    $ch2 = null;
    $ch3 = null;
    
    // Initialize primary curl handle if not in backoff and not mocked
    if ($primaryUrl !== null && $primaryMockResponse === null) {
        $ch1 = curl_init($primaryUrl);
        if ($ch1 === false) {
            curl_multi_close($mh);aviationwx_log('error', 'failed to init primary curl handle', ['airport' => $airportId], 'app');
            recordWeatherFailure($airportId, 'primary', 'transient');
            return null;
        }
        curl_setopt_array($ch1, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => CURL_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => CURL_CONNECT_TIMEOUT,
            CURLOPT_USERAGENT => 'AviationWX/1.0',
            CURLOPT_FAILONERROR => false, // Don't fail on HTTP error codes, we'll check them
        ]);
        curl_multi_add_handle($mh, $ch1);
    }
    
    // Initialize METAR curl handle if not in backoff and not mocked
    if ($metarUrl !== null && $metarMockResponse === null) {
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
            CURLOPT_TIMEOUT => CURL_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => CURL_CONNECT_TIMEOUT,
            CURLOPT_USERAGENT => 'AviationWX/1.0',
            CURLOPT_FAILONERROR => false, // Don't fail on HTTP error codes, we'll check them
        ]);
        curl_multi_add_handle($mh, $ch2);
    }
    
    // Initialize backup curl handle if not in backoff and not mocked
    if ($backupUrl !== null && $backupMockResponse === null) {
        $ch3 = curl_init($backupUrl);
        if ($ch3 === false) {
            if ($ch1 !== null) {
                curl_multi_remove_handle($mh, $ch1);
                curl_close($ch1);
            }
            if ($ch2 !== null) {
                curl_multi_remove_handle($mh, $ch2);
                curl_close($ch2);
            }
            curl_multi_close($mh);
            aviationwx_log('error', 'failed to init backup curl handle', ['airport' => $airportId], 'app');
            recordWeatherFailure($airportId, 'backup', 'transient');
            // Don't return null - continue with primary and METAR
        } else {
            curl_setopt_array($ch3, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => CURL_TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => CURL_CONNECT_TIMEOUT,
                CURLOPT_USERAGENT => 'AviationWX/1.0',
                CURLOPT_FAILONERROR => false, // Don't fail on HTTP error codes, we'll check them
            ]);
            curl_multi_add_handle($mh, $ch3);
        }
    }
    
    // Execute both requests in parallel with overall timeout protection
    $running = null;
    $startTime = microtime(true);
    $maxOverallTimeout = CURL_MULTI_OVERALL_TIMEOUT;$loopIterations = 0;
    do {
        $status = curl_multi_exec($mh, $running);
        $loopIterations++;if ($status !== CURLM_OK && $status !== CURLM_CALL_MULTI_PERFORM) {
            break;
        }
        
        // Check for overall timeout
        $elapsed = microtime(true) - $startTime;
        if ($elapsed > $maxOverallTimeout) {aviationwx_log('warning', 'curl_multi overall timeout exceeded', [
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
    } while ($running > 0);// Get responses and error information
    $primaryResponse = $ch1 !== null ? curl_multi_getcontent($ch1) : null;
    $metarResponse = $ch2 !== null ? curl_multi_getcontent($ch2) : null;
    $backupResponse = $ch3 !== null ? curl_multi_getcontent($ch3) : null;
    
    // Get HTTP codes and curl error info
    $primaryCode = $ch1 !== null ? curl_getinfo($ch1, CURLINFO_HTTP_CODE) : 0;
    $metarCode = $ch2 !== null ? curl_getinfo($ch2, CURLINFO_HTTP_CODE) : 0;
    $backupCode = $ch3 !== null ? curl_getinfo($ch3, CURLINFO_HTTP_CODE) : 0;
    $primaryError = $ch1 !== null ? curl_error($ch1) : '';
    $metarError = $ch2 !== null ? curl_error($ch2) : '';
    $backupError = $ch3 !== null ? curl_error($ch3) : '';
    $primaryErrno = $ch1 !== null ? curl_errno($ch1) : 0;
    $metarErrno = $ch2 !== null ? curl_errno($ch2) : 0;
    $backupErrno = $ch3 !== null ? curl_errno($ch3) : 0;// Override with mock responses if available (for mixed mock/real case)
    if ($primaryMockResponse !== null) {
        $primaryResponse = $primaryMockResponse;
        $primaryCode = 200;
        $primaryError = '';
        $primaryErrno = 0;
    }
    if ($metarMockResponse !== null) {
        $metarResponse = $metarMockResponse;
        $metarCode = 200;
        $metarError = '';
        $metarErrno = 0;
    }
    if ($backupMockResponse !== null) {
        $backupResponse = $backupMockResponse;
        $backupCode = 200;
        $backupError = '';
        $backupErrno = 0;
    }
    
    process_responses:
    
    // Determine failure severity based on HTTP code
    $primarySeverity = 'transient';
    if ($primaryCode !== 0 && $primaryCode >= 400 && $primaryCode < 500) {
        // HTTP 429 (rate limiting) should be treated as transient - it's temporary
        if ($primaryCode === 429) {
            $primarySeverity = 'transient';
        } else {
            $primarySeverity = 'permanent'; // Other 4xx errors are likely permanent (bad API key, etc.)
        }
    }
    
    $metarSeverity = 'transient';
    if ($metarCode !== 0 && $metarCode >= 400 && $metarCode < 500) {
        // HTTP 429 (rate limiting) should be treated as transient - it's temporary
        if ($metarCode === 429) {
            $metarSeverity = 'transient';
        } else {
            $metarSeverity = 'permanent'; // Other 4xx errors are likely permanent
        }
    }
    
    $backupSeverity = 'transient';
    if ($backupCode !== 0 && $backupCode >= 400 && $backupCode < 500) {
        // HTTP 429 (rate limiting) should be treated as transient - it's temporary
        if ($backupCode === 429) {
            $backupSeverity = 'transient';
        } else {
            $backupSeverity = 'permanent'; // Other 4xx errors are likely permanent
        }
    }
    
    // Check primary response and record success/failure
    if ($ch1 !== null) {
        if ($primaryResponse !== false && $primaryCode == 200 && empty($primaryError)) {
            recordWeatherSuccess($airportId, 'primary');
        } elseif (!$primaryCircuit['skip']) {
            // Only record failure if we actually attempted the request (not in backoff)
            // Pass HTTP code if it's 4xx/5xx, otherwise null
            $httpCodeForBackoff = ($primaryCode >= 400 && $primaryCode < 600) ? $primaryCode : null;
            recordWeatherFailure($airportId, 'primary', $primarySeverity, $httpCodeForBackoff);
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
    if ($ch2 !== null) {if ($metarResponse !== false && $metarCode == 200 && empty($metarError)) {
            recordWeatherSuccess($airportId, 'metar');
        } elseif (!$metarCircuit['skip']) {
            // Only record failure if we actually attempted the request (not in backoff)
            // Pass HTTP code if it's 4xx/5xx, otherwise null
            $httpCodeForBackoff = ($metarCode >= 400 && $metarCode < 600) ? $metarCode : null;
            recordWeatherFailure($airportId, 'metar', $metarSeverity, $httpCodeForBackoff);aviationwx_log('warning', 'METAR API error', [
                'airport' => $airportId,
                'station' => $stationId ?? ($airport['metar_station'] ?? 'unknown'),
                'http_code' => $metarCode,
                'curl_error' => $metarError ?: null,
                'curl_errno' => $metarErrno !== 0 ? $metarErrno : null,
                'response_received' => $metarResponse !== false,
                'severity' => $metarSeverity
            ], 'app');
        }
    } else {}
    
    // Check backup response and record success/failure
    if ($ch3 !== null) {
        if ($backupResponse !== false && $backupCode == 200 && empty($backupError)) {
            recordWeatherSuccess($airportId, 'backup');
        } elseif (!$backupCircuit['skip']) {
            // Only record failure if we actually attempted the request (not in backoff)
            // Pass HTTP code if it's 4xx/5xx, otherwise null
            $httpCodeForBackoff = ($backupCode >= 400 && $backupCode < 600) ? $backupCode : null;
            recordWeatherFailure($airportId, 'backup', $backupSeverity, $httpCodeForBackoff);
            aviationwx_log('warning', 'backup weather API error', [
                'airport' => $airportId,
                'source' => $backupSourceType ?? 'unknown',
                'http_code' => $backupCode,
                'curl_error' => $backupError ?: null,
                'curl_errno' => $backupErrno !== 0 ? $backupErrno : null,
                'response_received' => $backupResponse !== false,
                'severity' => $backupSeverity
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
    if ($ch3 !== null) {
        curl_multi_remove_handle($mh, $ch3);
        curl_close($ch3);
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
                case 'weatherlink':
                    $weatherData = parseWeatherLinkResponse($primaryResponse);
                    break;
                case 'pwsweather':
                    $weatherData = parsePWSWeatherResponse($primaryResponse);
                    break;
                case 'synopticdata':
                    $weatherData = parseSynopticDataResponse($primaryResponse);
                    break;
            }
            if ($weatherData !== null) {$primaryTimestamp = time(); // Track when primary data was fetched
                $weatherData['last_updated_primary'] = $primaryTimestamp;
                // Preserve observation time from primary source (when weather was actually measured)
                // This is critical for accurate timestamps in temperature/gust tracking
                if (isset($weatherData['obs_time']) && $weatherData['obs_time'] !== null) {
                    $weatherData['obs_time_primary'] = $weatherData['obs_time'];
                    
                    // Set _field_obs_time_map for all primary source fields to match obs_time_primary
                    // This ensures field-level observation times match source-level observation time
                    if (!isset($weatherData['_field_obs_time_map'])) {
                        $weatherData['_field_obs_time_map'] = [];
                    }
                    $primarySourceFields = [
                        'temperature', 'temperature_f',
                        'dewpoint', 'dewpoint_f', 'dewpoint_spread', 'humidity',
                        'wind_speed', 'wind_direction', 'gust_speed', 'gust_factor',
                        'pressure', 'precip_accum',
                        'pressure_altitude', 'density_altitude'
                    ];
                    foreach ($primarySourceFields as $field) {
                        if (isset($weatherData[$field]) && $weatherData[$field] !== null) {
                            $weatherData['_field_obs_time_map'][$field] = $weatherData['obs_time_primary'];
                        }
                    }
                }
                // Add HTTP status code to quality metadata
                if (!isset($weatherData['_quality_metadata'])) {
                    $weatherData['_quality_metadata'] = [];
                }
                $weatherData['_quality_metadata']['http_status'] = $primaryCode;
            } else {
                aviationwx_log('warning', 'primary weather response parse failed', [
                    'airport' => $airportId,
                    'source' => $sourceType,
                    'http_code' => $primaryCode,
                    'response_length' => strlen($primaryResponse),
                    'json_error' => $jsonErrorMsg,
                    'response_preview' => substr($primaryResponse, 0, 200)
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
    
    // If primary parse failed, check if we have backup data to use instead
    if ($weatherData === null) {
        // Check if backup was successfully fetched and parsed
        // Note: $backupSourceType is already set earlier in the function (line 226)
        $backupData = null;
        
        // First, try to use backup that was fetched in parallel
        if ($backupResponse !== false && $backupCode == 200 && !empty($backupResponse) && $backupSourceType !== null) {
            try {
                if ($backupSourceType) {
                    switch ($backupSourceType) {
                        case 'tempest':
                            $backupData = parseTempestResponse($backupResponse);
                            break;
                        case 'ambient':
                            $backupData = parseAmbientResponse($backupResponse);
                            break;
                        case 'pwsweather':
                            $backupData = parsePWSWeatherResponse($backupResponse);
                            break;
                        case 'synopticdata':
                            $backupData = parseSynopticDataResponse($backupResponse);
                            break;
                        case 'weatherlink':
                            $backupData = parseWeatherLinkResponse($backupResponse);
                            break;
                    }
                    if ($backupData !== null) {
                        // Use backup data as primary when primary is unavailable
                        $weatherData = $backupData;
                        $weatherData['last_updated_backup'] = time();
                        if (isset($weatherData['obs_time']) && $weatherData['obs_time'] !== null) {
                            $weatherData['obs_time_backup'] = $weatherData['obs_time'];
                        }}
                }
            } catch (Exception $e) {
                // Backup parse failed, continue to return null
            } catch (Throwable $e) {
                // Backup parse failed, continue to return null
            }
        }
        
        // If we still don't have data, return null
        if ($weatherData === null) {return null;
        }
    }
    
    // Check if primary data has null/missing critical fields - if so, trigger backup fetch if not already fetched
    // This handles cases where primary source returns data but with sensor failures (null fields)
    $primarySourceFields = ['temperature', 'wind_speed', 'wind_direction', 'pressure', 'humidity'];
    $hasMissingCriticalFields = false;
    $missingFieldCount = 0;
    foreach ($primarySourceFields as $field) {
        if (!isset($weatherData[$field]) || $weatherData[$field] === null) {
            $hasMissingCriticalFields = true;
            $missingFieldCount++;
        }
    }
    
    // If primary has missing critical fields and backup wasn't already successfully fetched, fetch it now
    // Backup is considered successfully fetched if we got a 200 response
    $backupWasSuccessful = ($backupResponse !== null && $backupResponse !== false && $backupCode == 200);
    if ($hasMissingCriticalFields && !$backupWasSuccessful && isset($airport['weather_source_backup']) && is_array($airport['weather_source_backup'])) {
        $backupSourceType = $airport['weather_source_backup']['type'];
        $backupCircuit = checkWeatherCircuitBreaker($airportId, 'backup');
        
        if (!$backupCircuit['skip']) {
            aviationwx_log('info', 'primary has missing fields, fetching backup', [
                'airport' => $airportId,
                'missing_fields' => $missingFieldCount,
                'backup_type' => $backupSourceType
            ], 'app');
            
            // Fetch backup synchronously (sequential fallback)
            try {
                switch ($backupSourceType) {
                    case 'tempest':
                        $backupData = fetchTempestWeather($airport['weather_source_backup']);
                        break;
                    case 'ambient':
                        $backupData = fetchAmbientWeather($airport['weather_source_backup']);
                        break;
                    case 'pwsweather':
                        $backupData = fetchPWSWeather($airport['weather_source_backup']);
                        break;
                    case 'synopticdata':
                        $backupData = fetchSynopticDataWeather($airport['weather_source_backup']);
                        break;
                    case 'weatherlink':
                        $backupData = fetchWeatherLinkWeather($airport['weather_source_backup']);
                        break;
                    default:
                        $backupData = null;
                }
                
                if ($backupData !== null) {// Store backup data for merging (will be processed below)
                    $weatherData['_backup_data_sequential'] = $backupData;
                    $backupTimestamp = time();
                    $weatherData['last_updated_backup'] = $backupTimestamp;
                    // Preserve observation time from backup source
                    if (isset($backupData['obs_time']) && $backupData['obs_time'] !== null) {
                        $weatherData['obs_time_backup'] = $backupData['obs_time'];
                    }
                    recordWeatherSuccess($airportId, 'backup');
                } else {
                    recordWeatherFailure($airportId, 'backup', 'transient');
                }
            } catch (Exception $e) {
                aviationwx_log('error', 'backup fetch exception after primary null fields', [
                    'airport' => $airportId,
                    'err' => $e->getMessage()
                ], 'app');
                recordWeatherFailure($airportId, 'backup', 'transient');
            }
        }
    }
    
    // Parse backup weather data (if fetched in parallel or sequential)
    $backupData = null;
    $backupTimestamp = null;
    // Check if backup was fetched sequentially (fallback after detecting null fields)
    $backupFetchedSequentially = false;
    if (isset($weatherData['_backup_data_sequential']) && $weatherData['_backup_data_sequential'] !== null) {
        $backupData = $weatherData['_backup_data_sequential'];unset($weatherData['_backup_data_sequential']); // Clean up internal field
        $backupFetchedSequentially = true;
    }
    
    // Check if backup was fetched in parallel (original async path)
    $backupWasFetched = ($backupResponse !== false && $backupCode == 200);
    if ($backupWasFetched && $backupSourceType !== null && !$backupFetchedSequentially) {
        try {
            switch ($backupSourceType) {
                case 'tempest':
                    $backupData = parseTempestResponse($backupResponse);
                    break;
                case 'ambient':
                    $backupData = parseAmbientResponse($backupResponse);
                    break;
                case 'pwsweather':
                    $backupData = parsePWSWeatherResponse($backupResponse);
                    break;
                case 'synopticdata':
                    $backupData = parseSynopticDataResponse($backupResponse);
                    break;
            }
            if ($backupData !== null) {
                $backupTimestamp = time();
                $weatherData['last_updated_backup'] = $backupTimestamp;
                // Preserve observation time from backup source
                if (isset($backupData['obs_time']) && $backupData['obs_time'] !== null) {
                    $weatherData['obs_time_backup'] = $backupData['obs_time'];
                    
                    // Set _field_obs_time_map for all backup source fields to match obs_time_backup
                    // This ensures field-level observation times match source-level observation time
                    if (!isset($backupData['_field_obs_time_map'])) {
                        $backupData['_field_obs_time_map'] = [];
                    }
                    $primarySourceFields = [
                        'temperature', 'temperature_f',
                        'dewpoint', 'dewpoint_f', 'dewpoint_spread', 'humidity',
                        'wind_speed', 'wind_direction', 'gust_speed', 'gust_factor',
                        'pressure', 'precip_accum',
                        'pressure_altitude', 'density_altitude'
                    ];
                    foreach ($primarySourceFields as $field) {
                        if (isset($backupData[$field]) && $backupData[$field] !== null) {
                            $backupData['_field_obs_time_map'][$field] = $weatherData['obs_time_backup'];
                        }
                    }
                }
                // Add HTTP status code to backup quality metadata
                if (!isset($backupData['_quality_metadata'])) {
                    $backupData['_quality_metadata'] = [];
                }
                $backupData['_quality_metadata']['http_status'] = $backupCode;// Store backup data for later field-level merging
                $weatherData['_backup_data'] = $backupData;
            } else {aviationwx_log('warning', 'backup weather response parse failed', [
                    'airport' => $airportId,
                    'source' => $backupSourceType,
                    'http_code' => $backupCode,
                    'response_length' => strlen($backupResponse)
                ], 'app');
            }
        } catch (Exception $e) {
            aviationwx_log('error', 'backup weather parse exception', [
                'airport' => $airportId,
                'source' => $backupSourceType ?? 'unknown',
                'err' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 'app');
            $backupData = null;
        } catch (Throwable $e) {
            aviationwx_log('error', 'backup weather parse throwable', [
                'airport' => $airportId,
                'source' => $backupSourceType ?? 'unknown',
                'err' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 'app');
            $backupData = null;
        }
    }
    
    // Parse and merge METAR data (non-blocking: use what we got)
    $metarTimestamp = null;
    $metarData = null;
    
    if ($metarResponse !== false && $metarCode == 200) {
        try {
            $metarData = parseMETARResponse($metarResponse, $airport);
        } catch (Exception $e) {
            aviationwx_log('error', 'METAR parse exception', [
                'airport' => $airportId,
                'station' => $stationId ?? ($airport['metar_station'] ?? 'unknown'),
                'err' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 'app');
            $metarData = null;
        } catch (Throwable $e) {
            aviationwx_log('error', 'METAR parse throwable', [
                'airport' => $airportId,
                'station' => $stationId ?? ($airport['metar_station'] ?? 'unknown'),
                'err' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ], 'app');
            $metarData = null;
        }
    }
    
    // If primary METAR request failed, try nearby stations as fallback
    if ($metarData === null) {
        require_once __DIR__ . '/adapter/metar-v1.php';
        $primaryStationId = $stationId ?? ($airport['metar_station'] ?? 'unknown');
        
        aviationwx_log('info', 'Primary METAR request failed in async path, attempting nearby stations', [
            'airport' => $airportId,
            'primary_station' => $primaryStationId,
            'nearby_stations' => $airport['nearby_metar_stations'] ?? []
        ], 'app');
        
        $fallbackResult = fetchMETARFromNearbyStations($airport, $primaryStationId);
        if ($fallbackResult !== null) {
            $metarData = $fallbackResult;
            aviationwx_log('info', 'METAR fetch successful from nearby station (async fallback)', [
                'airport' => $airportId,
                'primary_station' => $primaryStationId
            ], 'app');
        }
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
            
            // Add HTTP status code to METAR quality metadata (if not already set)
            if (!isset($metarData['_quality_metadata'])) {
                $metarData['_quality_metadata'] = [];
            }
            $metarData['_quality_metadata']['http_status'] = $metarCode;
            
            // Safely merge METAR data - only update if new value is valid
            // Track observation time for each METAR field in _field_obs_time_map
            if (!isset($weatherData['_field_obs_time_map'])) {
                $weatherData['_field_obs_time_map'] = [];
            }
            $metarObsTime = $weatherData['obs_time_metar'] ?? $metarTimestamp ?? null;
            
            // For ceiling: explicitly set null when METAR data indicates unlimited (no BKN/OVC clouds)
            // This ensures unlimited ceiling overwrites old cached values
            if ($metarData['visibility'] !== null && $metarData['visibility'] !== false) {
                $weatherData['visibility'] = $metarData['visibility'];
                // Track observation time for visibility field
                if ($metarObsTime !== null && $metarObsTime > 0) {
                    $weatherData['_field_obs_time_map']['visibility'] = $metarObsTime;
                }
            }
            // Ceiling: explicitly set to null if METAR parsing returned null (unlimited ceiling)
            // This ensures unlimited ceiling (FEW/SCT clouds) overwrites old cached values
            if (isset($metarData['ceiling'])) {
                $weatherData['ceiling'] = $metarData['ceiling']; // Can be null (unlimited) or a number
                // Track observation time for ceiling field (even if null/unlimited - sentinel value)
                if ($metarObsTime !== null && $metarObsTime > 0) {
                    $weatherData['_field_obs_time_map']['ceiling'] = $metarObsTime;
                }
            }
            if ($metarData['cloud_cover'] !== null && $metarData['cloud_cover'] !== false) {
                $weatherData['cloud_cover'] = $metarData['cloud_cover'];
                // Track observation time for cloud_cover field
                if ($metarObsTime !== null && $metarObsTime > 0) {
                    $weatherData['_field_obs_time_map']['cloud_cover'] = $metarObsTime;
                }
            }
        } else {
            // Log if METAR fetch failed (after trying all stations)
            if ($metarResponse !== false && $metarCode == 200) {
                aviationwx_log('warning', 'METAR response parse failed', [
                    'airport' => $airportId,
                    'station' => $stationId ?? ($airport['metar_station'] ?? 'unknown'),
                    'response_length' => strlen($metarResponse)
                ], 'app');
            }
        }
    
    return $weatherData;
}

/**
 * Fetch weather synchronously (fallback for METAR-only or errors)
 * 
 * Used when async fetch is not applicable (METAR-only source, WeatherLink with custom headers).
 * Handles circuit breaker logic and error recording.
 * Supports backup source fetching when primary exceeds 4x threshold.
 * 
 * @param array $airport Airport configuration array
 * @param string|null $airportId Airport identifier (optional, defaults to 'unknown')
 * @param int|null $refreshIntervalSeconds Weather refresh interval in seconds (optional, for backup fetching logic)
 * @param array|null $existingCache Existing cached weather data (optional, for backup fetching logic)
 * @return array|null Weather data array with standard keys, or null on failure
 */
function fetchWeatherSync($airport, $airportId = null, $refreshIntervalSeconds = null, $existingCache = null) {
    // Normalize weather source configuration (handle airports with metar_station but no weather_source)
    if (!normalizeWeatherSource($airport)) {
        aviationwx_log('error', 'no weather source configured in fetchWeatherSync', ['airport' => $airportId ?? 'unknown'], 'app');
        return null;
    }
    
    $sourceType = $airport['weather_source']['type'];
    $airportId = $airportId ?? 'unknown';
    $weatherData = null;
    
    // Check circuit breaker for primary source (if applicable)
    $primaryCircuit = checkWeatherCircuitBreaker($airportId, 'primary');
    $metarCircuit = checkWeatherCircuitBreaker($airportId, 'metar');
    
    switch ($sourceType) {
        case 'tempest':
        case 'ambient':
        case 'weatherlink':
        case 'pwsweather':
        case 'synopticdata':
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
                } elseif ($sourceType === 'ambient') {
                    $weatherData = fetchAmbientWeather($airport['weather_source']);
                } elseif ($sourceType === 'weatherlink') {
                    $weatherData = fetchWeatherLinkWeather($airport['weather_source']);
                } elseif ($sourceType === 'pwsweather') {
                    $weatherData = fetchPWSWeather($airport['weather_source']);
                } elseif ($sourceType === 'synopticdata') {
                    $weatherData = fetchSynopticDataWeather($airport['weather_source']);
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
            
            // Set _field_obs_time_map for all primary source fields to match obs_time_primary
            // This ensures field-level observation times match source-level observation time
            if (!isset($weatherData['_field_obs_time_map'])) {
                $weatherData['_field_obs_time_map'] = [];
            }
            $primarySourceFields = [
                'temperature', 'temperature_f',
                'dewpoint', 'dewpoint_f', 'dewpoint_spread', 'humidity',
                'wind_speed', 'wind_direction', 'gust_speed', 'gust_factor',
                'pressure', 'precip_accum',
                'pressure_altitude', 'density_altitude'
            ];
            foreach ($primarySourceFields as $field) {
                if (isset($weatherData[$field]) && $weatherData[$field] !== null) {
                    $weatherData['_field_obs_time_map'][$field] = $weatherData['obs_time_primary'];
                }
            }
        }
        
        // Check if primary data has null/missing critical fields - if so, trigger backup fetch immediately
        // This handles cases where primary source returns data but with sensor failures (null fields)
        $criticalFields = ['temperature', 'wind_speed', 'wind_direction', 'pressure', 'humidity'];
        $hasMissingCriticalFields = false;
        $missingFieldCount = 0;
        foreach ($criticalFields as $field) {
            if (!isset($weatherData[$field]) || $weatherData[$field] === null) {
                $hasMissingCriticalFields = true;
                $missingFieldCount++;
            }
        }
        
        // Check if backup should be fetched (4x threshold warm-up period OR if primary has null fields)
        $backupData = null;
        if (isset($airport['weather_source_backup']) && is_array($airport['weather_source_backup'])) {
            $backupSourceType = $airport['weather_source_backup']['type'];
            $backupCircuit = checkWeatherCircuitBreaker($airportId, 'backup');
            
            // Check if backup should be fetched
            // PRIORITY 1: Primary has missing critical fields (immediate trigger)
            // PRIORITY 2: Check staleness threshold (4x refresh interval)
            $shouldFetch = $hasMissingCriticalFields; // Immediate trigger if primary has null fields
            if (!$shouldFetch && !$backupCircuit['skip'] && $refreshIntervalSeconds !== null) {
                // Fallback: Check staleness threshold
                $shouldFetch = shouldFetchBackup($airport, $weatherData, $existingCache, $refreshIntervalSeconds);
            }
            
            if ($shouldFetch && !$backupCircuit['skip']) {
                if ($hasMissingCriticalFields) {
                    aviationwx_log('info', 'primary has missing fields, fetching backup (sync path)', [
                        'airport' => $airportId,
                        'missing_fields' => $missingFieldCount,
                        'backup_type' => $backupSourceType
                    ], 'app');
                }
                    // Fetch backup synchronously (sequential for sync path)
                    try {
                        switch ($backupSourceType) {
                            case 'tempest':
                                $backupData = fetchTempestWeather($airport['weather_source_backup']);
                                break;
                            case 'ambient':
                                $backupData = fetchAmbientWeather($airport['weather_source_backup']);
                                break;
                            case 'weatherlink':
                                $backupData = fetchWeatherLinkWeather($airport['weather_source_backup']);
                                break;
                            case 'pwsweather':
                                $backupData = fetchPWSWeather($airport['weather_source_backup']);
                                break;
                            case 'synopticdata':
                                $backupData = fetchSynopticDataWeather($airport['weather_source_backup']);
                                break;
                        }
                        
                        if ($backupData !== null) {
                            $backupTimestamp = time();
                            $weatherData['last_updated_backup'] = $backupTimestamp;
                            if (isset($backupData['obs_time']) && $backupData['obs_time'] !== null) {
                                $weatherData['obs_time_backup'] = $backupData['obs_time'];
                                
                                // Set _field_obs_time_map for all backup source fields to match obs_time_backup
                                // This ensures field-level observation times match source-level observation time
                                if (!isset($backupData['_field_obs_time_map'])) {
                                    $backupData['_field_obs_time_map'] = [];
                                }
                                $primarySourceFields = [
                                    'temperature', 'temperature_f',
                                    'dewpoint', 'dewpoint_f', 'dewpoint_spread', 'humidity',
                                    'wind_speed', 'wind_direction', 'gust_speed', 'gust_factor',
                                    'pressure', 'precip_accum',
                                    'pressure_altitude', 'density_altitude'
                                ];
                                foreach ($primarySourceFields as $field) {
                                    if (isset($backupData[$field]) && $backupData[$field] !== null) {
                                        $backupData['_field_obs_time_map'][$field] = $weatherData['obs_time_backup'];
                                    }
                                }
                            }
                            $weatherData['_backup_data'] = $backupData;
                            recordWeatherSuccess($airportId, 'backup');
                        } else {
                            recordWeatherFailure($airportId, 'backup', 'transient');
                        }
                    } catch (Exception $e) {
                        aviationwx_log('error', 'backup weather fetch exception', [
                            'airport' => $airportId,
                            'source' => $backupSourceType,
                            'err' => $e->getMessage()
                        ], 'app');
                        recordWeatherFailure($airportId, 'backup', 'transient');
                    }
                }
            }
        }
        
        // Try to fetch METAR for visibility/ceiling if not already present
        // Fetch if metar_station is configured
        if (!$metarCircuit['skip']) {
            if (isMetarEnabled($airport)) {
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

