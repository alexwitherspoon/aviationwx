<?php
/**
 * File-backed cache for NWS api.weather.gov /points/{lat},{lon} metadata.
 *
 * Points responses change infrequently; caching reduces load and keeps grid
 * lookups stable across worker processes.
 */

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/cache-paths.php';

/**
 * True when latitude and longitude are valid for an NWS points request.
 *
 * @param float $lat WGS84 latitude (-90 to 90)
 * @param float $lon WGS84 longitude (-180 to 180)
 */
function nws_points_coordinates_valid(float $lat, float $lon): bool
{
    return $lat >= -90.0 && $lat <= 90.0 && $lon >= -180.0 && $lon <= 180.0;
}

/**
 * Format one coordinate for NWS URL and cache keys (four decimal places).
 *
 * @return string Coordinate string for api.weather.gov /points URLs
 */
function nws_points_normalize_coord(float $coord): string
{
    return number_format($coord, 4, '.', '');
}

/**
 * Cache filename stem for a lat/lon pair (no extension).
 *
 * @return string e.g. 45.7710,-122.8600
 */
function nws_points_cache_key(float $lat, float $lon): string
{
    return nws_points_normalize_coord($lat) . ',' . nws_points_normalize_coord($lon);
}

/**
 * Resolve cache file path (supports test override via $GLOBALS['nws_points_cache_test_root']).
 *
 * @param string $cacheKey From nws_points_cache_key() (no path separators)
 */
function nws_points_cache_file_path(string $cacheKey): string
{
    if (isset($GLOBALS['nws_points_cache_test_root'])
        && is_string($GLOBALS['nws_points_cache_test_root'])
        && $GLOBALS['nws_points_cache_test_root'] !== ''
    ) {
        return rtrim($GLOBALS['nws_points_cache_test_root'], '/') . '/' . $cacheKey . '.json';
    }

    return getNwsPointsCacheFilePath($cacheKey);
}

/**
 * @param array{fetched_at?: int|string} $entry Cache envelope from disk
 * @param int|null $now Injectable reference time for tests
 */
function nws_points_cache_entry_is_fresh(array $entry, ?int $now = null): bool
{
    $fetchedAt = (int) ($entry['fetched_at'] ?? 0);
    if ($fetchedAt <= 0) {
        return false;
    }

    $now = $now ?? time();

    return ($now - $fetchedAt) < NWS_POINTS_CACHE_TTL_SECONDS;
}

/**
 * Read cached points JSON body when fresh.
 *
 * @param float $lat WGS84 latitude
 * @param float $lon WGS84 longitude
 * @param int|null $now Injectable reference time for tests
 * @return string|null Raw JSON body from a prior successful /points response
 */
function nws_points_cache_read(float $lat, float $lon, ?int $now = null): ?string
{
    if (!nws_points_coordinates_valid($lat, $lon)) {
        return null;
    }

    $path = nws_points_cache_file_path(nws_points_cache_key($lat, $lon));
    if (!is_file($path)) {
        return null;
    }

    clearstatcache(true, $path);
    $entry = json_decode((string) file_get_contents($path), true);
    if (!is_array($entry) || !nws_points_cache_entry_is_fresh($entry, $now)) {
        return null;
    }

    $expectedLat = nws_points_normalize_coord($lat);
    $expectedLon = nws_points_normalize_coord($lon);
    if (($entry['lat'] ?? '') !== $expectedLat || ($entry['lon'] ?? '') !== $expectedLon) {
        return null;
    }

    $body = $entry['body'] ?? null;

    return is_string($body) && $body !== '' ? $body : null;
}

/**
 * Persist a successful /points response body (atomic replace).
 *
 * @param float $lat WGS84 latitude
 * @param float $lon WGS84 longitude
 * @param string $body Raw JSON response body from NWS
 * @param int|null $now Injectable store time for tests
 * @return bool False when coordinates invalid or write fails
 */
function nws_points_cache_write(float $lat, float $lon, string $body, ?int $now = null): bool
{
    if (!nws_points_coordinates_valid($lat, $lon) || $body === '') {
        return false;
    }

    $cacheKey = nws_points_cache_key($lat, $lon);
    $path = nws_points_cache_file_path($cacheKey);
    $dir = dirname($path);
    if (!ensureCacheDir($dir)) {
        return false;
    }

    $now = $now ?? time();
    $payload = json_encode([
        'fetched_at' => $now,
        'lat' => nws_points_normalize_coord($lat),
        'lon' => nws_points_normalize_coord($lon),
        'body' => $body,
    ], JSON_UNESCAPED_SLASHES);

    if ($payload === false) {
        return false;
    }

    $tmp = $path . '.tmp.' . getmypid();
    if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
        @unlink($tmp);

        return false;
    }

    if (!@rename($tmp, $path)) {
        @unlink($tmp);

        return false;
    }

    return true;
}
