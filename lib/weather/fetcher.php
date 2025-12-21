<?php
/**
 * Weather Data Fetcher
 * 
 * Functions for fetching weather data from APIs synchronously and asynchronously.
 * Handles circuit breaker logic, error handling, and parallel requests.
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
 * Fetch weather data asynchronously using curl_multi (parallel requests)
 * 
 * Fetches primary weather source and METAR in parallel when both are needed.
 * Uses circuit breaker logic to skip sources in backoff period.
 * 
 * @param array $airport Airport configuration array
 * @param string|null $airportId Airport identifier (optional, defaults to 'unknown')
 * @return array|null Weather data array with standard keys, or null on failure
 */
function fetchWeatherAsync($airport, $airportId = null) {
    // Normalize weather source configuration (handle airports with metar_station but no weather_source)
    if (!normalizeWeatherSource($airport)) {
        aviationwx_log('error', 'no weather source configured in fetchWeatherAsync', ['airport' => $airportId ?? 'unknown'], 'app');
        return null;
    }
    
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
    
    // If both are skipped, return null
    if ($primaryCircuit['skip'] && $metarCircuit['skip']) {
        return null;
    }
    
    // Check for mock responses in test mode before making real requests
    $primaryMockResponse = $primaryUrl !== null ? getMockHttpResponse($primaryUrl) : null;
    $metarMockResponse = $metarUrl !== null ? getMockHttpResponse($metarUrl) : null;
    
    // If both are mocked, skip curl entirely and process mock responses directly
    if ($primaryMockResponse !== null && $metarMockResponse !== null) {
        $primaryResponse = $primaryMockResponse;
        $metarResponse = $metarMockResponse;
        $primaryCode = 200;
        $metarCode = 200;
        $primaryError = '';
        $metarError = '';
        $primaryErrno = 0;
        $metarErrno = 0;
        // Skip curl execution and jump to response processing
        goto process_responses;
    }
    
    // Create multi-handle for parallel requests
    $mh = curl_multi_init();
    if ($mh === false) {
        aviationwx_log('error', 'failed to init curl_multi', ['airport' => $airportId], 'app');
        return null;
    }
    
    $ch1 = null;
    $ch2 = null;
    
    // Initialize primary curl handle if not in backoff and not mocked
    if ($primaryUrl !== null && $primaryMockResponse === null) {
        $ch1 = curl_init($primaryUrl);
        if ($ch1 === false) {
            curl_multi_close($mh);
            aviationwx_log('error', 'failed to init primary curl handle', ['airport' => $airportId], 'app');
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
    
    // Execute both requests in parallel with overall timeout protection
    $running = null;
    $startTime = microtime(true);
    $maxOverallTimeout = CURL_MULTI_OVERALL_TIMEOUT;
    
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
    
    // Override with mock responses if available (for mixed mock/real case)
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
    if ($ch2 !== null) {
        if ($metarResponse !== false && $metarCode == 200 && empty($metarError)) {
            recordWeatherSuccess($airportId, 'metar');
        } elseif (!$metarCircuit['skip']) {
            // Only record failure if we actually attempted the request (not in backoff)
            // Pass HTTP code if it's 4xx/5xx, otherwise null
            $httpCodeForBackoff = ($metarCode >= 400 && $metarCode < 600) ? $metarCode : null;
            recordWeatherFailure($airportId, 'metar', $metarSeverity, $httpCodeForBackoff);
            aviationwx_log('warning', 'METAR API error', [
                'airport' => $airportId,
                'station' => $stationId ?? ($airport['metar_station'] ?? 'unknown'),
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
    
    if ($weatherData === null) {
        return null;
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
 * 
 * @param array $airport Airport configuration array
 * @param string|null $airportId Airport identifier (optional, defaults to 'unknown')
 * @return array|null Weather data array with standard keys, or null on failure
 */
function fetchWeatherSync($airport, $airportId = null) {
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
    }
    
    return $weatherData;
}

