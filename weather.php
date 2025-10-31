<?php
/**
 * Weather Data Fetcher
 * Fetches weather data from configured source for the specified airport
 */

require_once __DIR__ . '/config-utils.php';
require_once __DIR__ . '/rate-limit.php';
require_once __DIR__ . '/logger.php';

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

// Stale-while-revalidate: Serve stale cache immediately, refresh in background
$hasStaleCache = false;
$staleData = null;

if (file_exists($weatherCacheFile)) {
    $age = time() - filemtime($weatherCacheFile);
    
    // If cache is fresh, serve it normally
    if ($age < $airportWeatherRefresh) {
        $cached = json_decode(file_get_contents($weatherCacheFile), true);
        if (is_array($cached)) {
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
        // Cache is stale but exists - serve it immediately, then refresh
        $staleData = json_decode(file_get_contents($weatherCacheFile), true);
        if (is_array($staleData)) {
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
    if ($primaryResponse !== false && $primaryCode == 200) {
        switch ($sourceType) {
            case 'tempest':
                $weatherData = parseTempestResponse($primaryResponse);
                break;
            case 'ambient':
                $weatherData = parseAmbientResponse($primaryResponse);
                break;
        }
    }
    
    if ($weatherData === null) {
        return null;
    }
    
    // Parse and merge METAR data (non-blocking: use what we got)
    if ($metarResponse !== false && $metarCode == 200) {
        $metarData = parseMETARResponse($metarResponse, $airport);
        if ($metarData !== null) {
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
    ];
}

/**
 * Fetch weather synchronously (fallback for METAR-only or errors)
 */
function fetchWeatherSync($airport) {
    switch ($airport['weather_source']['type']) {
        case 'tempest':
            return fetchTempestWeather($airport['weather_source']);
        case 'ambient':
            return fetchAmbientWeather($airport['weather_source']);
        case 'metar':
            return fetchMETAR($airport);
        default:
            return null;
    }
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
    // If we served stale data, just update cache silently (don't error)
    if ($hasStaleCache) {
        // Keep the stale data in cache, but log the failure
        error_log('Weather API: Failed to refresh for ' . $airportId . ', using stale cache');
        exit; // Background refresh failed, but we already served stale data
    }
    
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
updatePeakGust($airportId, $currentGust);
$peakGustInfo = getPeakGust($airportId, $currentGust);
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
    updateTempExtremes($airportId, $currentTemp);
    $tempExtremes = getTempExtremes($airportId, $currentTemp);
    $weatherData['temp_high_today'] = $tempExtremes['high'];
    $weatherData['temp_low_today'] = $tempExtremes['low'];
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

// Stamp last_updated and write cache
$weatherData['last_updated'] = time();
$weatherData['last_updated_iso'] = date('c', $weatherData['last_updated']);
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
    if (isset($obs['wind_gust'])) {
        $peakGust = round($obs['wind_gust'] * 1.943844); // Convert m/s to knots
    }
    
    // Convert pressure from mb to inHg
    $pressureInHg = isset($obs['sea_level_pressure']) ? $obs['sea_level_pressure'] / 33.8639 : null;
    
    // Convert wind speed from m/s to knots
    $windSpeedKts = isset($obs['wind_avg']) ? round($obs['wind_avg'] * 1.943844) : null;
    $gustSpeedKts = isset($obs['wind_gust']) ? round($obs['wind_gust'] * 1.943844) : null;
    
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
    
    return round($densityAlt);
}

/**
 * Get sunrise time for airport
 */
function getSunriseTime($airport) {
    $lat = $airport['lat'];
    $lon = $airport['lon'];
    
    // Use date_sun_info for PHP 8.1+
    $timestamp = strtotime('today');
    $sunInfo = date_sun_info($timestamp, $lat, $lon);
    
    if ($sunInfo['sunrise'] === false) {
        return null;
    }
    
    $datetime = new DateTime('@' . $sunInfo['sunrise']);
    $datetime->setTimezone(new DateTimeZone('America/Los_Angeles'));
    
    return $datetime->format('H:i');
}

/**
 * Get sunset time for airport
 */
function getSunsetTime($airport) {
    $lat = $airport['lat'];
    $lon = $airport['lon'];
    
    // Use date_sun_info for PHP 8.1+
    $timestamp = strtotime('today');
    $sunInfo = date_sun_info($timestamp, $lat, $lon);
    
    if ($sunInfo['sunset'] === false) {
        return null;
    }
    
    $datetime = new DateTime('@' . $sunInfo['sunset']);
    $datetime->setTimezone(new DateTimeZone('America/Los_Angeles'));
    
    return $datetime->format('H:i');
}

/**
 * Update today's peak gust for an airport
 * Uses UTC date for date key to ensure consistent daily reset regardless of server timezone
 */
function updatePeakGust($airportId, $currentGust) {
    try {
        $cacheDir = __DIR__ . '/cache';
        if (!file_exists($cacheDir)) {
            if (!mkdir($cacheDir, 0755, true)) {
                error_log("Failed to create cache directory: {$cacheDir}");
                return;
            }
        }
        
        $file = $cacheDir . '/peak_gusts.json';
        // Use UTC date for consistent daily reset across all timezones
        $dateKey = gmdate('Y-m-d');
        
        $peakGusts = [];
        if (file_exists($file)) {
            $content = file_get_contents($file);
            if ($content !== false) {
                $peakGusts = json_decode($content, true) ?? [];
            }
        }
        
        // Clean up old entries (older than 2 days) to prevent file bloat
        $currentDate = new DateTime($dateKey);
        foreach ($peakGusts as $key => $value) {
            if (!is_string($key) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $key)) {
                continue; // Skip invalid keys
            }
            $entryDate = new DateTime($key);
            $daysDiff = $currentDate->diff($entryDate)->days;
            if ($daysDiff > 2) {
                unset($peakGusts[$key]);
            }
        }
        
        // Normalize existing entry to structured format {value, ts}
        $existing = $peakGusts[$dateKey][$airportId] ?? null;
        if (is_array($existing)) {
            $existingValue = $existing['value'] ?? 0;
        } else {
            $existingValue = is_numeric($existing) ? (float)$existing : 0;
        }

        // If no entry or current gust is higher, update value and timestamp (now)
        if (!isset($peakGusts[$dateKey][$airportId]) || $currentGust > $existingValue) {
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
 * Uses UTC date for date key to ensure consistent daily reset regardless of server timezone
 */
function getPeakGust($airportId, $currentGust) {
    $file = __DIR__ . '/cache/peak_gusts.json';
    // Use UTC date for consistent daily reset across all timezones
    $dateKey = gmdate('Y-m-d');

    if (!file_exists($file)) {
        return ['value' => $currentGust, 'ts' => null];
    }

    $peakGusts = json_decode(file_get_contents($file), true) ?? [];
    $entry = $peakGusts[$dateKey][$airportId] ?? null;
    if ($entry === null) {
        // No entry for today, return current gust as today's value
        return ['value' => $currentGust, 'ts' => null];
    }

    // Support both legacy scalar and new structured format
    if (is_array($entry)) {
        $value = $entry['value'] ?? 0;
        $ts = $entry['ts'] ?? null;
        // Ensure we return at least the current gust if it's higher
        if ($currentGust > $value) {
            $value = $currentGust;
        }
        return ['value' => $value, 'ts' => $ts];
    }

    // Legacy scalar format
    $value = max((float)$entry, $currentGust);
    return ['value' => $value, 'ts' => null];
}

/**
 * Calculate VFR/IFR/MVFR flight category based on ceiling and visibility
 */
function calculateFlightCategory($weather) {
    $ceiling = $weather['ceiling'] ?? null;
    $visibility = $weather['visibility'] ?? null;
    
    // IFR: ceiling < 1000 ft or visibility < 3 SM
    if ($ceiling !== null && $ceiling < 1000) {
        return 'IFR';
    }
    if ($visibility !== null && $visibility < 3) {
        return 'IFR';
    }
    
    // MVFR: ceiling < 3000 ft or visibility < 5 SM
    if ($ceiling !== null && $ceiling < 3000) {
        return 'MVFR';
    }
    if ($visibility !== null && $visibility < 5) {
        return 'MVFR';
    }
    
    // VFR: all other conditions
    return 'VFR';
}

/**
 * Update today's high and low temperatures for an airport
 * Uses UTC date for date key to ensure consistent daily reset regardless of server timezone
 */
function updateTempExtremes($airportId, $currentTemp) {
    try {
        $cacheDir = __DIR__ . '/cache';
        if (!file_exists($cacheDir)) {
            if (!mkdir($cacheDir, 0755, true)) {
                error_log("Failed to create cache directory: {$cacheDir}");
                return;
            }
        }
        
        $file = $cacheDir . '/temp_extremes.json';
        // Use UTC date for consistent daily reset across all timezones
        $dateKey = gmdate('Y-m-d');
        
        $tempExtremes = [];
        if (file_exists($file)) {
            $content = file_get_contents($file);
            if ($content !== false) {
                $tempExtremes = json_decode($content, true) ?? [];
            }
        }
        
        // Initialize today's entry if it doesn't exist
        if (!isset($tempExtremes[$dateKey][$airportId])) {
            $tempExtremes[$dateKey][$airportId] = [
                'high' => $currentTemp,
                'low' => $currentTemp
            ];
        } else {
            // Update high if current is higher
            if ($currentTemp > $tempExtremes[$dateKey][$airportId]['high']) {
                $tempExtremes[$dateKey][$airportId]['high'] = $currentTemp;
            }
            // Update low if current is lower
            if ($currentTemp < $tempExtremes[$dateKey][$airportId]['low']) {
                $tempExtremes[$dateKey][$airportId]['low'] = $currentTemp;
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
 * Uses UTC date for date key to ensure consistent daily reset regardless of server timezone
 */
function getTempExtremes($airportId, $currentTemp) {
    $file = __DIR__ . '/cache/temp_extremes.json';
    // Use UTC date for consistent daily reset across all timezones
    $dateKey = gmdate('Y-m-d');
    
    if (!file_exists($file)) {
        return ['high' => $currentTemp, 'low' => $currentTemp];
    }
    
    $tempExtremes = json_decode(file_get_contents($file), true) ?? [];
    
    if (isset($tempExtremes[$dateKey][$airportId])) {
        // Return the stored extremes, ensuring current temp is included
        return [
            'high' => max($tempExtremes[$dateKey][$airportId]['high'], $currentTemp),
            'low' => min($tempExtremes[$dateKey][$airportId]['low'], $currentTemp)
        ];
    }
    
    return ['high' => $currentTemp, 'low' => $currentTemp];
}

