<?php
/**
 * Scheduler-driven warmup for NWS api.weather.gov /points/{lat},{lon} cache.
 *
 * Entry: nwsPointsRefreshRun()
 */

require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/cache-paths.php';
require_once __DIR__ . '/nws-points-cache.php';

/**
 * Lock file path for the background points refresh worker.
 */
function getNwsPointsRefreshLockPath(): string
{
    return getNwsPointsCacheDir() . '/refresh.lock';
}

/**
 * Unique lat/lon pairs for enabled airports with an NWS weather source.
 *
 * @param array<string, mixed>|null $config Loaded airports config
 * @return list<array{lat: float, lon: float, cache_key: string}>
 */
function nwsPointsCollectCoordinatesFromConfig(?array $config = null): array
{
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/weather/utils.php';
    if ($config === null) {
        $config = loadConfig(false);
    }

    $out = [];
    if (!is_array($config) || !isset($config['airports']) || !is_array($config['airports'])) {
        return [];
    }

    foreach ($config['airports'] as $airport) {
        if (!is_array($airport) || !isAirportEnabled($airport) || !hasWeatherSources($airport)) {
            continue;
        }

        $hasNws = false;
        foreach ($airport['weather_sources'] as $source) {
            if (is_array($source) && ($source['type'] ?? '') === 'nws') {
                $hasNws = true;
                break;
            }
        }
        if (!$hasNws) {
            continue;
        }

        if (!isset($airport['lat'], $airport['lon']) || !is_numeric($airport['lat']) || !is_numeric($airport['lon'])) {
            continue;
        }

        $lat = (float) $airport['lat'];
        $lon = (float) $airport['lon'];
        if (!nwsPointsCoordinatesValid($lat, $lon)) {
            continue;
        }

        $cacheKey = nwsPointsCacheKey($lat, $lon);
        if (!isset($out[$cacheKey])) {
            $out[$cacheKey] = [
                'lat' => $lat,
                'lon' => $lon,
                'cache_key' => $cacheKey,
            ];
        }
    }

    return array_values($out);
}

/**
 * True when the on-disk points cache is missing or past TTL.
 *
 * @param int|null $now Injectable reference time for tests
 */
function nwsPointsCoordinateNeedsRefresh(float $lat, float $lon, ?int $now = null): bool
{
    return nwsPointsCacheRead($lat, $lon, $now) === null;
}

/**
 * Refresh stale NWS /points cache entries for configured airports.
 *
 * @param array<string, mixed>|null $config Loaded airports config
 * @return array{
 *   ok: bool,
 *   coordinates?: int,
 *   fetched?: int,
 *   skipped_fresh?: int,
 *   skipped_throttle?: int,
 *   failed?: int,
 *   note?: string
 * }
 */
function nwsPointsRefreshRun(?array $config = null): array
{
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/logger.php';
    require_once __DIR__ . '/upstream-rate-limit.php';
    require_once __DIR__ . '/weather/adapter/nws-api-v1.php';

    $result = [
        'ok' => false,
        'coordinates' => 0,
        'fetched' => 0,
        'skipped_fresh' => 0,
        'skipped_throttle' => 0,
        'failed' => 0,
    ];

    $forceRun = !empty($GLOBALS['nwsPointsRefreshTestForceRun']);
    if (!$forceRun && (shouldMockExternalServices() || isTestMode())) {
        $result['ok'] = true;
        $result['note'] = 'skipped_mock_or_test_mode';

        return $result;
    }

    $coordinates = nwsPointsCollectCoordinatesFromConfig($config);
    $result['coordinates'] = count($coordinates);
    if ($coordinates === []) {
        $result['ok'] = true;
        $result['note'] = 'no_nws_coordinates';

        return $result;
    }

    if (!ensureCacheDir(getNwsPointsCacheDir())) {
        $result['note'] = 'cache_dir_unavailable';

        return $result;
    }

    $lockPath = getNwsPointsRefreshLockPath();
    $lockFp = @fopen($lockPath, 'cb');
    if ($lockFp === false) {
        $result['note'] = 'lock_open_failed';

        return $result;
    }
    if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
        fclose($lockFp);
        $result['ok'] = true;
        $result['note'] = 'skipped_lock_held';

        return $result;
    }

    $rateLimitSource = [
        'type' => 'nws',
        'station_id' => '_points_refresh',
    ];

    foreach ($coordinates as $coordinate) {
        $lat = $coordinate['lat'];
        $lon = $coordinate['lon'];

        if (!nwsPointsCoordinateNeedsRefresh($lat, $lon)) {
            $result['skipped_fresh']++;
            continue;
        }

        if (!upstreamRateLimitConsumeForSource($rateLimitSource)['allowed']) {
            $result['skipped_throttle']++;
            continue;
        }

        $decoded = nwsFetchPoints($lat, $lon);
        if ($decoded === null) {
            $result['failed']++;
            continue;
        }

        $result['fetched']++;
    }

    flock($lockFp, LOCK_UN);
    fclose($lockFp);

    $result['ok'] = true;

    if ($result['failed'] > 0) {
        aviationwx_log('warning', 'nws_points_refresh: one or more /points fetches failed', [
            'coordinates' => $result['coordinates'],
            'fetched' => $result['fetched'],
            'failed' => $result['failed'],
            'skipped_throttle' => $result['skipped_throttle'],
        ], 'app');
    }

    return $result;
}
