<?php
/**
 * Status Page Health Pre-warm Worker
 *
 * Computes system, public API, and airport health and writes to cache files.
 * Invoked by scheduler every STATUS_HEALTH_FETCH_INTERVAL seconds.
 * Prevents status page from blocking on first load when cache is cold.
 *
 * Usage: php scripts/fetch-status-health.php
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/constants.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/cache-paths.php';
require_once __DIR__ . '/../lib/status-checks.php';

$config = loadConfig();
if ($config === null) {
    exit(1);
}

$ttl = STATUS_HEALTH_CACHE_TTL;

/**
 * Write cache file in getCachedData format
 *
 * @param string $path File path
 * @param string $key Cache key
 * @param mixed $data Data to cache
 * @param int $ttl TTL in seconds
 * @return bool True on success
 */
function writeStatusHealthCache(string $path, string $key, $data, int $ttl): bool {
    $cacheDir = dirname($path);
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    $fileData = [
        'cached_at' => time(),
        'ttl' => $ttl,
        'key' => $key,
        'data' => $data
    ];
    $tmpFile = $path . '.tmp.' . getmypid();
    $written = @file_put_contents($tmpFile, json_encode($fileData, JSON_PRETTY_PRINT), LOCK_EX);
    if ($written === false) {
        @unlink($tmpFile);
        return false;
    }
    if (!@rename($tmpFile, $path)) {
        @unlink($tmpFile);
        return false;
    }
    return true;
}

$systemHealth = checkSystemHealth();
if (!writeStatusHealthCache(CACHE_SYSTEM_HEALTH_FILE, 'status_system_health', $systemHealth, $ttl)) {
    aviationwx_log('warning', 'fetch-status-health: failed to write system health cache', [], 'app');
}

if (file_exists(__DIR__ . '/../lib/public-api/config.php')) {
    require_once __DIR__ . '/../lib/public-api/config.php';
    if (isPublicApiEnabled()) {
        $publicApiHealth = checkPublicApiHealth();
        if (!writeStatusHealthCache(CACHE_PUBLIC_API_HEALTH_FILE, 'status_public_api_health', $publicApiHealth, $ttl)) {
            aviationwx_log('warning', 'fetch-status-health: failed to write public API health cache', [], 'app');
        }
    }
}

$airportHealth = [];
if (isset($config['airports']) && is_array($config['airports'])) {
    foreach ($config['airports'] as $airportId => $airport) {
        $airportHealth[] = checkAirportHealth($airportId, $airport);
    }
}
if (!writeStatusHealthCache(CACHE_AIRPORT_HEALTH_FILE, 'status_airport_health', $airportHealth, $ttl)) {
    aviationwx_log('warning', 'fetch-status-health: failed to write airport health cache', [], 'app');
}

exit(0);
