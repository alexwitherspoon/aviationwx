<?php
/**
 * Embed Widget Helpers
 * 
 * READ-ONLY helper functions for embed widgets to fetch data from the public API.
 * 
 * ARCHITECTURE PRINCIPLE:
 * Embed widgets are PURE CONSUMERS of weather data. They should NEVER write to
 * any cache files or tracking files. All data updates happen through the 
 * scheduler → fetch-weather → api/weather.php pipeline.
 * 
 * Data flow:
 * 1. Scheduler triggers weather refresh (WRITE PATH)
 * 2. api/weather.php updates daily tracking + writes cache file
 * 3. Public API reads cache file (READ PATH)
 * 4. Embed widgets call Public API (READ ONLY)
 * 
 * RATE LIMITING:
 * Embed requests forward the original client IP via X-Forwarded-Client-IP header.
 * The public API uses this for rate limiting, so each end user gets their own
 * rate limit bucket (anonymous tier: 20/min, 200/hr, 2000/day).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/cache-paths.php';

// Load public API functions for airport lookup
require_once __DIR__ . '/public-api/middleware.php';

/**
 * Get the original client IP address for forwarding to internal API
 * 
 * Determines the actual end-user's IP address from the incoming embed request.
 * This is forwarded to the public API so rate limiting applies per end-user.
 * 
 * @return string Client IP address
 */
function getOriginalClientIp(): string
{
    // Check for CDN headers first (Cloudflare)
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return $_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    
    // Check X-Forwarded-For (behind load balancer/proxy)
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    
    // Direct connection
    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

/**
 * Fetch weather data from the PUBLIC API
 * 
 * This is a READ-ONLY operation. The cache file already contains all daily
 * tracking data (temp_high_today, temp_low_today, peak_gust_today) because
 * api/weather.php writes it during the refresh cycle.
 * 
 * @param string $airportId Airport identifier
 * @return array|null Weather data array or null if unavailable
 */
function fetchWeatherFromPublicApi(string $airportId): ?array {
    // Call the public API endpoint
    // Use localhost for internal calls (faster, avoids DNS/SSL overhead)
    $apiUrl = "http://127.0.0.1:8080/api/v1/airports/" . urlencode($airportId) . "/weather";
    
    // Get the original client IP to forward for rate limiting
    // This allows rate limiting per end-user, not per internal service
    $originalClientIp = getOriginalClientIp();
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'X-Internal-Request: embed-widget',
            'X-Forwarded-Client-IP: ' . $originalClientIp, // Forward original user IP for rate limiting
        ],
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    
    // Parse public API response
    if ($httpCode === 200 && $response !== false && empty($error)) {
        $data = json_decode($response, true);
        if (is_array($data) && isset($data['success']) && $data['success'] && isset($data['data']['weather'])) {
            $apiWeather = $data['data']['weather'];
            
            // Convert public API format back to internal format for templates
            // Public API uses 'daily' nested object, templates expect flat fields
            return convertPublicApiToInternalFormat($apiWeather);
        }
    }
    
    // Fallback: read directly from cache file if API call fails
    // This is still READ-ONLY - we just read the file that api/weather.php wrote
    return readWeatherCacheFile($airportId);
}

/**
 * Convert public API weather format to internal template format
 * 
 * Public API nests daily data under 'daily' key with ISO timestamps.
 * Templates expect flat fields with Unix timestamps.
 * 
 * @param array $apiWeather Weather data from public API
 * @return array Weather data in internal format
 */
function convertPublicApiToInternalFormat(array $apiWeather): array {
    $weather = $apiWeather;
    
    // Flatten daily tracking data from nested 'daily' object
    if (isset($apiWeather['daily']) && is_array($apiWeather['daily'])) {
        $daily = $apiWeather['daily'];
        
        $weather['temp_high_today'] = $daily['temp_high'] ?? null;
        $weather['temp_low_today'] = $daily['temp_low'] ?? null;
        $weather['peak_gust_today'] = $daily['peak_gust'] ?? null;
        
        // Convert ISO timestamps back to Unix timestamps
        $weather['temp_high_ts'] = isset($daily['temp_high_time']) 
            ? strtotime($daily['temp_high_time']) 
            : null;
        $weather['temp_low_ts'] = isset($daily['temp_low_time']) 
            ? strtotime($daily['temp_low_time']) 
            : null;
        $weather['peak_gust_time'] = isset($daily['peak_gust_time']) 
            ? strtotime($daily['peak_gust_time']) 
            : null;
        
        unset($weather['daily']);
    }
    
    // Convert observation_time and last_updated from ISO to Unix
    if (isset($apiWeather['observation_time']) && is_string($apiWeather['observation_time'])) {
        $weather['obs_time_primary'] = strtotime($apiWeather['observation_time']);
    }
    if (isset($apiWeather['last_updated']) && is_string($apiWeather['last_updated'])) {
        $weather['last_updated'] = strtotime($apiWeather['last_updated']);
        $weather['last_updated_primary'] = $weather['last_updated'];
    }
    
    return $weather;
}

/**
 * Read weather data directly from cache file (fallback)
 * 
 * This is a READ-ONLY fallback when the HTTP API call fails.
 * The cache file contains all data including daily tracking.
 * 
 * @param string $airportId Airport identifier
 * @return array|null Weather data or null if unavailable
 */
function readWeatherCacheFile(string $airportId): ?array {
    $cacheFile = getWeatherCachePath($airportId);
    
    if (!file_exists($cacheFile)) {
        return null;
    }
    
    $content = @file_get_contents($cacheFile);
    if ($content === false) {
        return null;
    }
    
    $data = json_decode($content, true);
    if (!is_array($data)) {
        return null;
    }
    
    return $data;
}

/**
 * Fetch airport and weather data for embed widgets
 * 
 * READ-ONLY: Calls the public API to get weather data.
 * All daily tracking data (temp_high_today, temp_low_today) is already
 * in the cache file, written by api/weather.php during refresh.
 * 
 * @param string $airportId Airport identifier
 * @return array|null {
 *   'airport' => array,  // Airport configuration data
 *   'weather' => array,  // Weather data with daily tracking
 *   'airportId' => string
 * } or null if airport not found
 */
function fetchEmbedDataFromApi(string $airportId): ?array {
    // Normalize airport ID (same validation as public API)
    $trimmed = trim($airportId);
    if (empty($trimmed) || strlen($trimmed) < 3 || strlen($trimmed) > 20) {
        return null;
    }
    if (!preg_match('/^[a-zA-Z0-9-]+$/i', $trimmed)) {
        return null;
    }
    $normalizedId = strtolower($trimmed);
    
    // Get airport configuration
    // getPublicApiAirport() is a read-only lookup in the config
    $airport = getPublicApiAirport($normalizedId);
    if ($airport === null) {
        return null;
    }
    
    // Fetch weather data from public API (READ-ONLY)
    $weatherData = fetchWeatherFromPublicApi($normalizedId);
    
    if ($weatherData === null) {
        // Weather unavailable - return airport data only
        return [
            'airport' => $airport,
            'weather' => [],
            'airportId' => $normalizedId
        ];
    }
    
    return [
        'airport' => $airport,
        'weather' => $weatherData,
        'airportId' => $normalizedId
    ];
}
