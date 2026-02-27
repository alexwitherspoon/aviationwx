<?php
/**
 * Weather Utility Functions
 *
 * Utility functions for weather-related operations (timezone, date keys, sunrise/sunset, cache paths).
 * Sun calculations use SunCalculator (NOAA formula) for accuracy and polar region handling.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../sun/SunCalculator.php';

/**
 * Get timezone display data for airport (abbreviation + offset)
 *
 * Uses server-side PHP DateTime (reliable IANA data) instead of browser Intl API,
 * which can return wrong abbreviations (e.g. PST for MST). Safety-critical for
 * pilots making go/no-go decisions.
 *
 * @param array $airport Airport configuration array
 * @param int|null $timestamp Optional Unix timestamp (defaults to now)
 * @return array{
 *   abbreviation: string,
 *   offset_hours: int,
 *   offset_display: string,
 *   timezone: string
 * }
 */
function getTimezoneDisplayForAirport(array $airport, ?int $timestamp = null): array
{
    $timezone = getAirportTimezone($airport);
    $ts = $timestamp ?? time();

    try {
        $tz = new DateTimeZone($timezone);
        $dt = new DateTime('@' . $ts);
        $dt->setTimezone($tz);

        $abbreviation = $dt->format('T');
        $offsetSeconds = $tz->getOffset($dt);
        $offsetHours = (int) round($offsetSeconds / 3600);

        $sign = $offsetHours >= 0 ? '+' : '';
        $offsetDisplay = '(UTC' . $sign . $offsetHours . ')';

        return [
            'abbreviation' => $abbreviation ?: 'UTC',
            'offset_hours' => $offsetHours,
            'offset_display' => $offsetDisplay,
            'timezone' => $timezone,
        ];
    } catch (Exception $e) {
        return [
            'abbreviation' => 'UTC',
            'offset_hours' => 0,
            'offset_display' => '(UTC+0)',
            'timezone' => 'UTC',
        ];
    }
}

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
 * Get sun info for airport using SunCalculator (NOAA formula)
 *
 * Uses airport's local date for correct day boundary. For valid locations, always
 * returns an array; polar-region cases are represented by per-event null fields
 * (e.g., no sunrise/sunset), not by a null return value.
 *
 * @param array $airport Airport config with 'lat', 'lon', 'timezone'
 * @return array|null Sun info array, or null if location data missing/invalid or timezone invalid
 */
function getSunInfoForAirport(array $airport): ?array
{
    if (!isset($airport['lat']) || !isset($airport['lon'])) {
        return null;
    }
    try {
        $timezone = getAirportTimezone($airport);
        $today = getAirportDateKey($airport);
        $timestamp = strtotime($today . ' 12:00:00 ' . $timezone);
        if ($timestamp === false) {
            return null;
        }
        return SunCalculator::getSunInfo($timestamp, (float) $airport['lat'], (float) $airport['lon']);
    } catch (InvalidArgumentException $e) {
        return null;
    } catch (\Exception $e) {
        if (function_exists('aviationwx_log')) {
            aviationwx_log('warning', 'sun info unavailable (timezone or calc error)', [
                'airport' => $airport['icao'] ?? $airport['id'] ?? 'unknown',
                'message' => $e->getMessage(),
            ], 'app');
        }
        return null;
    }
}

/**
 * Get sunrise time for airport
 *
 * Uses SunCalculator (NOAA formula). Returns null for polar regions (no sunrise).
 *
 * @param array $airport Airport configuration array (must contain 'lat', 'lon', and optionally 'timezone')
 * @return string|null Sunrise time in HH:mm format (local timezone), or null if no sunrise
 */
function getSunriseTime($airport)
{
    $sunInfo = getSunInfoForAirport($airport);
    if ($sunInfo === null || $sunInfo['sunrise'] === null) {
        return null;
    }
    $timezone = getAirportTimezone($airport);
    $datetime = new DateTime('@' . $sunInfo['sunrise']);
    $datetime->setTimezone(new DateTimeZone($timezone));
    return $datetime->format('H:i');
}

/**
 * Get sunset time for airport
 *
 * Uses SunCalculator (NOAA formula). Returns null for polar regions (no sunset).
 *
 * @param array $airport Airport configuration array (must contain 'lat', 'lon', and optionally 'timezone')
 * @return string|null Sunset time in HH:mm format (local timezone), or null if no sunset
 */
function getSunsetTime($airport)
{
    $sunInfo = getSunInfoForAirport($airport);
    if ($sunInfo === null || $sunInfo['sunset'] === null) {
        return null;
    }
    $timezone = getAirportTimezone($airport);
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
 * METAR is enabled if there's a METAR source in the weather_sources array with a station_id.
 * 
 * @param array $airport Single airport configuration array
 * @return bool True if METAR source is configured, false otherwise
 */
function isMetarEnabled(array $airport): bool {
    if (!isset($airport['weather_sources']) || !is_array($airport['weather_sources'])) {
        return false;
    }
    
    foreach ($airport['weather_sources'] as $source) {
        if (($source['type'] ?? '') === 'metar' && !empty($source['station_id'])) {
            return true;
        }
    }
    
    return false;
}

/**
 * Get METAR station ID for an airport
 * 
 * Returns the station_id from the first METAR source in the weather_sources array.
 * 
 * @param array $airport Single airport configuration array
 * @return string|null METAR station ID or null if not configured
 */
function getMetarStationId(array $airport): ?string {
    if (!isset($airport['weather_sources']) || !is_array($airport['weather_sources'])) {
        return null;
    }
    
    foreach ($airport['weather_sources'] as $source) {
        if (($source['type'] ?? '') === 'metar' && !empty($source['station_id'])) {
            return $source['station_id'];
        }
    }
    
    return null;
}

/**
 * Check if airport has any weather sources configured
 * 
 * Returns true if the airport has at least one source in the weather_sources array.
 * 
 * @param array $airport Airport configuration array
 * @return bool True if at least one weather source is configured
 */
function hasWeatherSources(array $airport): bool {
    if (!isset($airport['weather_sources']) || !is_array($airport['weather_sources'])) {
        return false;
    }
    
    foreach ($airport['weather_sources'] as $source) {
        if (!empty($source['type'])) {
            return true;
        }
    }
    
    return false;
}

/**
 * Get primary weather source type for an airport
 * 
 * Returns the type of the first non-backup source, or the first source if all are backups.
 * Used for display purposes (e.g., showing weather source attribution).
 * 
 * @param array $airport Airport configuration array
 * @return string|null Primary source type or null if no sources configured
 */
function getPrimaryWeatherSourceType(array $airport): ?string {
    if (!isset($airport['weather_sources']) || !is_array($airport['weather_sources'])) {
        return null;
    }
    
    $firstSource = null;
    foreach ($airport['weather_sources'] as $source) {
        if (empty($source['type'])) {
            continue;
        }
        
        // Remember first source as fallback
        if ($firstSource === null) {
            $firstSource = $source['type'];
        }
        
        // Return first non-backup source
        if (empty($source['backup'])) {
            return $source['type'];
        }
    }
    
    return $firstSource;
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
        case 'weatherlink_v2':
        case 'weatherlink_v1':
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
                'name' => 'NOAA Aviation Weather',
                'url' => 'https://aviationweather.gov'
            ];
        case 'awosnet':
            return [
                'name' => 'AWOSnet',
                'url' => 'https://awosnet.com'
            ];
        case 'nws':
            return [
                'name' => 'NWS ASOS',
                'url' => 'https://www.weather.gov'
            ];
        case 'aviationwx_api':
            return [
                'name' => 'Federated AviationWX',
                'url' => null
            ];
        case 'swob_auto':
        case 'swob_man':
            return [
                'name' => 'Nav Canada Weather',
                'url' => 'https://www.navcanada.ca/'
            ];
        default:
            return null;
    }
}

/**
 * Get weather source display name
 * 
 * Returns only the human-readable name for a weather source type.
 * For ICAO-keyed sources (metar, swob, nws, awosnet), pass stationId for "Source Name (ICAO)" format.
 * 
 * @param string $sourceType Weather source type (e.g., 'tempest', 'ambient', 'metar')
 * @param string|null $stationId Optional station ICAO for "Source Name (ICAO)" attribution
 * @return string Display name, or 'Unknown Source' if source type is unknown
 */
function getWeatherSourceDisplayName(string $sourceType, ?string $stationId = null): string {
    $info = getWeatherSourceInfo($sourceType);
    if ($info === null) {
        return 'Unknown Source';
    }
    $name = $info['name'];
    if ($stationId !== null && $stationId !== '') {
        $name .= ' (' . strtoupper(trim($stationId)) . ')';
    }
    return $name;
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

/**
 * Daylight phase constants
 * 
 * Used by getDaylightPhase() to categorize current lighting conditions.
 * Phases progress: DAY -> CIVIL_TWILIGHT -> NAUTICAL_TWILIGHT -> NIGHT
 */
define('DAYLIGHT_PHASE_DAY', 'day');                    // Sun above horizon
define('DAYLIGHT_PHASE_CIVIL_TWILIGHT', 'civil');       // Sun 0-6° below horizon (still bright)
define('DAYLIGHT_PHASE_NAUTICAL_TWILIGHT', 'nautical'); // Sun 6-12° below horizon (getting dark)
define('DAYLIGHT_PHASE_NIGHT', 'night');                // Sun >12° below horizon (dark)

/**
 * Get current daylight phase for airport location
 * 
 * Determines lighting conditions based on sun position:
 * - day: Sun above horizon (full daylight)
 * - civil: Sun 0-6° below horizon (bright enough to see without lights)
 * - nautical: Sun 6-12° below horizon (horizon barely visible)
 * - night: Sun >12° below horizon (full darkness)
 * 
 * Used for context-aware image validation thresholds.
 * 
 * @param array $airport Airport config with 'lat' and 'lon'
 * @param int|null $timestamp Unix timestamp to check (default: now)
 * @return string One of: 'day', 'civil', 'nautical', 'night'
 */
function getDaylightPhase(array $airport, ?int $timestamp = null): string
{
    if (!isset($airport['lat']) || !isset($airport['lon'])) {
        return DAYLIGHT_PHASE_DAY;
    }
    $lat = (float) $airport['lat'];
    $lon = (float) $airport['lon'];
    $timestamp = $timestamp ?? time();

    try {
        $timezone = getAirportTimezone($airport);
        $dt = new \DateTime('@' . $timestamp);
        $dt->setTimezone(new \DateTimeZone($timezone));
        $localDate = $dt->format('Y-m-d');
        $dayTimestamp = strtotime($localDate . ' 12:00:00 ' . $timezone);
        if ($dayTimestamp === false) {
            return DAYLIGHT_PHASE_DAY;
        }
    } catch (\Exception $e) {
        if (function_exists('aviationwx_log')) {
            aviationwx_log('warning', 'daylight phase timezone error', [
                'airport' => $airport['id'] ?? $airport['icao'] ?? null,
                'message' => $e->getMessage(),
            ], 'app');
        }
        return DAYLIGHT_PHASE_DAY;
    }

    try {
        $sunInfo = SunCalculator::getSunInfo($dayTimestamp, $lat, $lon);
    } catch (InvalidArgumentException $e) {
        return DAYLIGHT_PHASE_DAY;
    }

    if ($sunInfo['sunrise'] === null || $sunInfo['sunset'] === null) {
        $sunPosition = getSunAltitude($lat, $lon, $timestamp);
        if ($sunPosition > 0.0) {
            return DAYLIGHT_PHASE_DAY;
        }
        if ($sunPosition > -6.0) {
            return DAYLIGHT_PHASE_CIVIL_TWILIGHT;
        }
        if ($sunPosition > -12.0) {
            return DAYLIGHT_PHASE_NAUTICAL_TWILIGHT;
        }
        return DAYLIGHT_PHASE_NIGHT;
    }

    $sunrise = $sunInfo['sunrise'];
    $sunset = $sunInfo['sunset'];
    $civilDawn = $sunInfo['civil_twilight_begin'];
    $civilDusk = $sunInfo['civil_twilight_end'];
    $nauticalDawn = $sunInfo['nautical_twilight_begin'];
    $nauticalDusk = $sunInfo['nautical_twilight_end'];

    if ($timestamp >= $sunrise && $timestamp < $sunset) {
        return DAYLIGHT_PHASE_DAY;
    }
    if (($civilDawn !== null && $timestamp >= $civilDawn && $timestamp < $sunrise) ||
        ($civilDusk !== null && $timestamp >= $sunset && $timestamp < $civilDusk)) {
        return DAYLIGHT_PHASE_CIVIL_TWILIGHT;
    }
    if (($nauticalDawn !== null && $civilDawn !== null && $timestamp >= $nauticalDawn && $timestamp < $civilDawn) ||
        ($nauticalDusk !== null && $civilDusk !== null && $timestamp >= $civilDusk && $timestamp < $nauticalDusk)) {
        return DAYLIGHT_PHASE_NAUTICAL_TWILIGHT;
    }

    return DAYLIGHT_PHASE_NIGHT;
}

/**
 * Calculate sun altitude angle at a given time and location
 * 
 * Used for polar region handling where sunrise/sunset may not occur.
 * 
 * @param float $lat Latitude in degrees
 * @param float $lon Longitude in degrees  
 * @param int $timestamp Unix timestamp
 * @return float Sun altitude in degrees (positive = above horizon)
 */
function getSunAltitude(float $lat, float $lon, int $timestamp): float {
    // Convert to radians
    $latRad = deg2rad($lat);
    
    // Calculate day of year
    $dayOfYear = (int)date('z', $timestamp) + 1;
    
    // Calculate solar declination (simplified)
    $declination = deg2rad(23.45 * sin(deg2rad(360 * ($dayOfYear - 81) / 365)));
    
    // Calculate hour angle
    $hour = (int)gmdate('G', $timestamp) + (int)gmdate('i', $timestamp) / 60;
    $solarNoon = 12 - ($lon / 15); // Approximate solar noon in UTC hours
    $hourAngle = deg2rad(15 * ($hour - $solarNoon));
    
    // Calculate altitude
    $sinAlt = sin($latRad) * sin($declination) + 
              cos($latRad) * cos($declination) * cos($hourAngle);
    
    return rad2deg(asin($sinAlt));
}

/**
 * Check if currently daytime at airport location
 * 
 * Convenience function - returns true for 'day' phase only.
 * 
 * @param array $airport Airport config with 'lat' and 'lon'
 * @param int|null $timestamp Unix timestamp to check (default: now)
 * @return bool True if sun is above horizon
 */
function isDaytime(array $airport, ?int $timestamp = null): bool {
    return getDaylightPhase($airport, $timestamp) === DAYLIGHT_PHASE_DAY;
}

/**
 * Check if currently nighttime at airport location
 * 
 * Convenience function - returns true for 'night' phase only.
 * Civil and nautical twilight are NOT considered night.
 * 
 * @param array $airport Airport config with 'lat' and 'lon'
 * @param int|null $timestamp Unix timestamp to check (default: now)
 * @return bool True if sun is more than 12° below horizon
 */
function isNighttime(array $airport, ?int $timestamp = null): bool {
    return getDaylightPhase($airport, $timestamp) === DAYLIGHT_PHASE_NIGHT;
}

/**
 * Get airport location (lat/lon) from config
 * 
 * Helper to extract just the location data needed for daylight calculations.
 * 
 * @param array $airport Airport configuration array
 * @return array{lat: float, lon: float}|null Location array or null if not available
 */
function getAirportLocation(array $airport): ?array {
    if (!isset($airport['lat']) || !isset($airport['lon'])) {
        return null;
    }
    
    return [
        'lat' => (float)$airport['lat'],
        'lon' => (float)$airport['lon']
    ];
}
