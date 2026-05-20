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
function nwsPointsCoordinatesValid(float $lat, float $lon): bool
{
    return $lat >= -90.0 && $lat <= 90.0 && $lon >= -180.0 && $lon <= 180.0;
}

/**
 * Format one coordinate for NWS URL and cache keys (four decimal places).
 *
 * @return string Coordinate string for api.weather.gov /points URLs
 */
function nwsPointsNormalizeCoord(float $coord): string
{
    $formatted = number_format($coord, 4, '.', '');
    if ($formatted === '-0.0000') {
        return '0.0000';
    }

    return $formatted;
}

/**
 * Cache filename stem for a lat/lon pair (no extension).
 *
 * @return string e.g. 45.7710,-122.8600
 */
function nwsPointsCacheKey(float $lat, float $lon): string
{
    return nwsPointsNormalizeCoord($lat) . ',' . nwsPointsNormalizeCoord($lon);
}

/**
 * Resolve cache file path (supports test override via $GLOBALS['nwsPointsCacheTestRoot']).
 *
 * @param string $cacheKey From nwsPointsCacheKey() (no path separators)
 */
function nwsPointsCacheFilePath(string $cacheKey): string
{
    if (isset($GLOBALS['nwsPointsCacheTestRoot'])
        && is_string($GLOBALS['nwsPointsCacheTestRoot'])
        && $GLOBALS['nwsPointsCacheTestRoot'] !== ''
    ) {
        return rtrim($GLOBALS['nwsPointsCacheTestRoot'], '/') . '/' . $cacheKey . '.json';
    }

    return getNwsPointsCacheFilePath($cacheKey);
}

/**
 * @param array{fetched_at?: int|string} $entry Cache envelope from disk
 * @param int|null $now Injectable reference time for tests
 */
function nwsPointsCacheEntryIsFresh(array $entry, ?int $now = null): bool
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
function nwsPointsCacheRead(float $lat, float $lon, ?int $now = null): ?string
{
    if (!nwsPointsCoordinatesValid($lat, $lon)) {
        return null;
    }

    $path = nwsPointsCacheFilePath(nwsPointsCacheKey($lat, $lon));
    if (!is_file($path)) {
        return null;
    }

    clearstatcache(true, $path);
    $entry = json_decode((string) file_get_contents($path), true);
    if (!is_array($entry) || !nwsPointsCacheEntryIsFresh($entry, $now)) {
        return null;
    }

    $expectedLat = nwsPointsNormalizeCoord($lat);
    $expectedLon = nwsPointsNormalizeCoord($lon);
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
function nwsPointsCacheWrite(float $lat, float $lon, string $body, ?int $now = null): bool
{
    if (!nwsPointsCoordinatesValid($lat, $lon) || $body === '') {
        return false;
    }

    $cacheKey = nwsPointsCacheKey($lat, $lon);
    $path = nwsPointsCacheFilePath($cacheKey);
    $dir = dirname($path);
    if (!ensureCacheDir($dir)) {
        return false;
    }

    $now = $now ?? time();
    $payload = json_encode([
        'fetched_at' => $now,
        'lat' => nwsPointsNormalizeCoord($lat),
        'lon' => nwsPointsNormalizeCoord($lon),
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
