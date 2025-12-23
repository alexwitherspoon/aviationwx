<?php
/**
 * Weather Utility Functions
 * 
 * Utility functions for weather-related operations (timezone, date keys, sunrise/sunset, cache paths).
 */

require_once __DIR__ . '/../config.php';

/**
 * Get airport timezone from config, with fallback to global default
 * 
 * @param array $airport Airport configuration array
 * @return string Timezone identifier (e.g., 'America/New_York')
 */
function getAirportTimezone($airport) {
    if (isset($airport['timezone']) && !empty($airport['timezone'])) {
        return $airport['timezone'];
    }
    
    return getDefaultTimezone();
}

/**
 * Get today's date key (Y-m-d format) based on airport's local timezone midnight
 * 
 * Uses airport's local timezone to determine "today" for daily resets.
 * This ensures daily tracking values reset at local midnight, not UTC midnight.
 * 
 * @param array $airport Airport configuration array
 * @return string Date key in Y-m-d format (e.g., '2024-12-19')
 */
function getAirportDateKey($airport) {
    $timezone = getAirportTimezone($airport);
    $tz = new DateTimeZone($timezone);
    $now = new DateTime('now', $tz);
    return $now->format('Y-m-d');
}

/**
 * Get cache directory path
 * 
 * Returns the absolute path to the cache directory.
 * Validates that the path resolves correctly from the current file location.
 * 
 * @return string Absolute path to cache directory
 * @throws RuntimeException If cache directory path cannot be resolved
 */
function getWeatherCacheDir() {
    $cacheDir = __DIR__ . '/../../cache';
    $realPath = realpath(dirname($cacheDir));
    
    if ($realPath === false) {
        throw new RuntimeException("Cannot resolve cache directory path: {$cacheDir}");
    }
    
    $resolvedPath = $realPath . '/cache';
    
    if (!is_dir($resolvedPath) && !is_dir($cacheDir)) {
        $parentDir = dirname($cacheDir);
        if (!is_dir($parentDir)) {
            throw new RuntimeException("Cache parent directory does not exist: {$parentDir}");
        }
    }
    
    return $resolvedPath;
}

/**
 * Get sunrise time for airport
 * 
 * Calculates sunrise time for today based on airport's latitude, longitude, and timezone.
 * 
 * @param array $airport Airport configuration array (must contain 'lat', 'lon', and optionally 'timezone')
 * @return string|null Sunrise time in HH:mm format (local timezone), or null if calculation fails
 */
function getSunriseTime($airport) {
    $lat = $airport['lat'];
    $lon = $airport['lon'];
    $timezone = getAirportTimezone($airport);
    
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
 * 
 * Calculates sunset time for today based on airport's latitude, longitude, and timezone.
 * 
 * @param array $airport Airport configuration array (must contain 'lat', 'lon', and optionally 'timezone')
 * @return string|null Sunset time in HH:mm format (local timezone), or null if calculation fails
 */
function getSunsetTime($airport) {
    $lat = $airport['lat'];
    $lon = $airport['lon'];
    $timezone = getAirportTimezone($airport);
    
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
 * Add cache-busting parameter to URL
 * 
 * Adds a cache-busting query parameter (`_cb`) to a URL to force fresh requests.
 * This is used client-side to bypass Service Worker and browser caches.
 * 
 * The parameter value is a timestamp (milliseconds since epoch) to ensure uniqueness.
 * 
 * @param string $url Base URL (may already have query parameters)
 * @param int|null $timestamp Optional timestamp in milliseconds (defaults to current time)
 * @return string URL with cache-busting parameter added
 */
function addCacheBustingParameter($url, $timestamp = null) {
    if ($timestamp === null) {
        $timestamp = round(microtime(true) * 1000); // Milliseconds, matching JavaScript Date.now()
    }
    
    // Handle fragment identifier - cache-busting should come before fragment
    $fragment = '';
    $fragmentPos = strpos($url, '#');
    if ($fragmentPos !== false) {
        $fragment = substr($url, $fragmentPos);
        $url = substr($url, 0, $fragmentPos);
    }
    
    // Check if URL already has query parameters
    $separator = (strpos($url, '?') !== false) ? '&' : '?';
    
    return $url . $separator . '_cb=' . $timestamp . $fragment;
}

/**
 * Check if METAR is enabled for a specific airport
 * 
 * METAR is enabled if metar_station is configured (exists and is not empty).
 * Per-airport: each airport independently enables METAR by configuring metar_station.
 * 
 * @param array $airport Single airport configuration array
 * @return bool True if metar_station is configured, false otherwise
 */
function isMetarEnabled(array $airport): bool {
    return isset($airport['metar_station']) && 
           !empty(trim($airport['metar_station'] ?? ''));
}

/**
 * Normalize weather source configuration
 * 
 * Ensures weather_source is properly configured. If weather_source is missing
 * but metar_station is configured, defaults to METAR-only source.
 * 
 * @param array $airport Airport configuration array (modified in place)
 * @return bool True if weather source is now configured, false if no source available
 */
function normalizeWeatherSource(array &$airport): bool {
    if (isset($airport['weather_source']) && isset($airport['weather_source']['type'])) {
        return true;
    }
    
    if (isMetarEnabled($airport)) {
        $airport['weather_source'] = ['type' => 'metar'];
        return true;
    }
    
    return false;
}

/**
 * Get weather source display information
 * 
 * Returns human-readable name and URL for a weather source type.
 * This is the centralized mapping of weather source types to display names.
 * 
 * @param string $sourceType Weather source type (e.g., 'tempest', 'ambient', 'metar')
 * @return array{name: string, url: string}|null Returns array with 'name' and 'url' keys, or null if source type is unknown
 */
function getWeatherSourceInfo(string $sourceType): ?array {
    switch ($sourceType) {
        case 'tempest':
            return [
                'name' => 'Tempest Weather',
                'url' => 'https://tempestwx.com'
            ];
        case 'ambient':
            return [
                'name' => 'Ambient Weather',
                'url' => 'https://ambientweather.net'
            ];
        case 'weatherlink':
            return [
                'name' => 'Davis WeatherLink',
                'url' => 'https://weatherlink.com'
            ];
        case 'pwsweather':
            return [
                'name' => 'PWSWeather.com',
                'url' => 'https://pwsweather.com'
            ];
        case 'synopticdata':
            return [
                'name' => 'SynopticData',
                'url' => 'https://synopticdata.com'
            ];
        case 'metar':
            return [
                'name' => 'Aviation Weather',
                'url' => 'https://aviationweather.gov'
            ];
        default:
            return null;
    }
}

/**
 * Get weather source display name
 * 
 * Returns only the human-readable name for a weather source type.
 * 
 * @param string $sourceType Weather source type (e.g., 'tempest', 'ambient', 'metar')
 * @return string Display name, or 'Unknown Source' if source type is unknown
 */
function getWeatherSourceDisplayName(string $sourceType): string {
    $info = getWeatherSourceInfo($sourceType);
    return $info !== null ? $info['name'] : 'Unknown Source';
}

/**
 * Check if visibility value represents unlimited
 * 
 * Sentinel value (999.0 SM) is used internally to represent unlimited visibility,
 * differentiating it from null (which represents a failed state).
 * 
 * @param float|null $visibility Visibility in statute miles
 * @return bool True if value represents unlimited visibility, false otherwise
 */
function isUnlimitedVisibility(?float $visibility): bool {
    require_once __DIR__ . '/../constants.php';
    return $visibility === UNLIMITED_VISIBILITY_SM;
}

/**
 * Check if ceiling value represents unlimited
 * 
 * Sentinel value (99999 ft) is used internally to represent unlimited ceiling,
 * differentiating it from null (which represents a failed state).
 * 
 * @param int|null $ceiling Ceiling in feet
 * @return bool True if value represents unlimited ceiling, false otherwise
 */
function isUnlimitedCeiling(?int $ceiling): bool {
    require_once __DIR__ . '/../constants.php';
    return $ceiling === UNLIMITED_CEILING_FT;
}
