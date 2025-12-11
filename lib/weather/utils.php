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
 * Check if METAR is enabled for a specific airport
 * 
 * Per-airport setting: each airport can independently enable/disable METAR.
 * Requires both metar_enabled=true AND metar_station configured.
 * 
 * @param array $airport Single airport configuration array
 * @return bool True if METAR is enabled for this airport, false otherwise
 */
function isMetarEnabled(array $airport): bool {
    return isset($airport['metar_enabled']) && 
           $airport['metar_enabled'] === true && 
           isset($airport['metar_station']) && 
           !empty($airport['metar_station']);
}
