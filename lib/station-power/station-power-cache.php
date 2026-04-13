<?php
/**
 * Read / write canonical station power JSON cache.
 */

declare(strict_types=1);

require_once __DIR__ . '/../cache-paths.php';
require_once __DIR__ . '/../constants.php';

/**
 * Load decoded station power cache for an airport, or null if missing/invalid.
 *
 * @return array<string,mixed>|null
 */
function loadStationPowerCache(string $airportId): ?array
{
    $path = getStationPowerCachePath($airportId);
    if (!is_readable($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

/**
 * Whether cached data is fresh enough to render (fetched_at within product retention).
 *
 * @param array<string,mixed> $cache Canonical cache
 */
function stationPowerCacheIsDisplayable(array $cache): bool
{
    if (!isset($cache['fetched_at']) || !is_numeric($cache['fetched_at'])) {
        return false;
    }
    $fetched = (int) $cache['fetched_at'];
    if ($fetched <= 0) {
        return false;
    }
    return (time() - $fetched) <= STATION_POWER_CACHE_MAX_DISPLAY_AGE_SECONDS;
}

/**
 * Atomically write canonical station power JSON.
 *
 * @param array<string,mixed> $canonical
 */
function saveStationPowerCache(string $airportId, array $canonical): bool
{
    $path = getStationPowerCachePath($airportId);
    $dir = dirname($path);
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
    }
    $json = json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false;
    }
    $tmp = $path . '.tmp.' . getmypid();
    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    return true;
}
