<?php
/**
 * Status Page Health Pre-warm Worker
 *
 * Computes system, public API, and airport health and writes to cache files.
 * Invoked by scheduler every STATUS_PAGE_BACKGROUND_FETCH_INTERVAL seconds.
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
 * @param mixed $data Data to cache (array or object; will be JSON-encoded)
 * @param int $ttl TTL in seconds
 * @return bool True on success
 */
function writeStatusHealthCache(string $path, string $key, mixed $data, int $ttl): bool {
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
    $json = json_encode($fileData, JSON_PRETTY_PRINT);
    if ($json === false) {
        aviationwx_log('error', 'fetch-status-health: json_encode failed', [
            'path' => $path,
            'key' => $key,
            'json_error' => json_last_error_msg(),
        ], 'app');
        return false;
    }
    $tmpFile = $path . '.tmp.' . getmypid();
    $written = @file_put_contents($tmpFile, $json, LOCK_EX);
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

$exitCode = 0;

$systemHealth = checkSystemHealth();
if (!writeStatusHealthCache(CACHE_SYSTEM_HEALTH_FILE, 'status_system_health', $systemHealth, $ttl)) {
    aviationwx_log('warning', 'fetch-status-health: failed to write system health cache', [], 'app');
    $exitCode = 1;
}

if (file_exists(__DIR__ . '/../lib/public-api/config.php')) {
    require_once __DIR__ . '/../lib/public-api/config.php';
    if (isPublicApiEnabled()) {
        $publicApiHealth = checkPublicApiHealth();
        if (!writeStatusHealthCache(CACHE_PUBLIC_API_HEALTH_FILE, 'status_public_api_health', $publicApiHealth, $ttl)) {
            aviationwx_log('warning', 'fetch-status-health: failed to write public API health cache', [], 'app');
            $exitCode = 1;
        }
    }
}

$airportHealth = [];
$listedAirports = getListedAirports($config);
foreach ($listedAirports as $airportId => $airport) {
    $airportHealth[] = checkAirportHealth($airportId, $airport);
}
if (!writeStatusHealthCache(CACHE_AIRPORT_HEALTH_FILE, 'status_airport_health', $airportHealth, $ttl)) {
    aviationwx_log('warning', 'fetch-status-health: failed to write airport health cache', [], 'app');
    $exitCode = 1;
}

exit($exitCode);
