<?php
/**
 * Disk cache orchestration for the NOTAM TFR map layer aggregate GeoJSON.
 *
 * Loads per-airport NOTAM caches, decides when to rebuild tfr-map-layer.json,
 * coordinates single-flight flock rebuilds, and serves geometry for revalidation
 * in {@see notamTfrMapLayerRevalidatePayload()}.
 */

require_once __DIR__ . '/../logger.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../constants.php';
require_once __DIR__ . '/../cache-paths.php';
require_once __DIR__ . '/cache.php';
require_once __DIR__ . '/filter.php';
require_once __DIR__ . '/map-layer.php';

/**
 * Bump when map build, dedup, or serve-time revalidation logic changes.
 * Always included in {@see notamTfrMapLayerCurrentBuildToken()} alongside deploy SHA.
 */
const NOTAM_TFR_MAP_LAYER_LOGIC_VERSION = 1;

/**
 * True when listed sources contain drawable TFR geometry absent from the aggregate.
 *
 * Only runs when the aggregate has no features. Non-empty aggregates rely on
 * source mtime and build-token invalidation to avoid parsing every TFR on each
 * serve-path request. Covers the empty-aggregate gap from issue #144.
 *
 * @param array<string, mixed> $cachedPayload Decoded aggregate cache
 * @param array{
 *     newest_mtime: int,
 *     by_id: array<string, array{notam: array<string, mixed>, timezone: string, airport_id: string}>,
 *     airports: array<string, array{airport: array<string, mixed>, timezone: string, notams: array<int, mixed>, cache_mtime: int}>
 * } $listedCaches Preloaded per-airport caches
 * @param int $nowUnix Current Unix time (UTC instants)
 * @return bool
 */
function notamTfrMapLayerAggregateMissingDrawableGeometry(
    array $cachedPayload,
    array $listedCaches,
    int $nowUnix
): bool {
    $features = $cachedPayload['features'] ?? [];
    if (is_array($features) && $features !== []) {
        return false;
    }

    $seenIds = [];

    foreach ($listedCaches['airports'] as $entry) {
        $airport = $entry['airport'];
        if (!is_array($airport) || !isset($airport['lat'], $airport['lon'])) {
            continue;
        }
        $timezone = $entry['timezone'];
        foreach ($entry['notams'] as $notam) {
            if (!is_array($notam)) {
                continue;
            }
            if (!isTfr($notam)) {
                continue;
            }
            $id = trim((string)($notam['id'] ?? ''));
            if ($id === '' || isset($seenIds[$id])) {
                continue;
            }
            notamEnsureEffectiveSegments($notam);
            $status = revalidateNotamStatus($notam, $timezone, $nowUnix);
            if ($status === 'expired' || $status === 'unknown') {
                continue;
            }
            $minimal = notamTfrMapLayerMinimalFeatureForGeometryKey($notam);
            if ($minimal === null) {
                continue;
            }
            $seenIds[$id] = true;
            $key = notamTfrMapLayerFeatureGeometryKey($minimal);
            if ($key !== null && $key !== '') {
                return true;
            }
        }
    }

    return false;
}

/**
 * Build token stored in aggregate cache so code deploys invalidate stale geometry.
 *
 * Combines deploy SHA ({@see getGitSha()}) and {@see NOTAM_TFR_MAP_LAYER_LOGIC_VERSION}
 * so map-layer logic changes invalidate the aggregate even when GIT_SHA is unchanged.
 *
 * @return string Token such as `abc1234-v1`, or `logic-v1` when SHA is unavailable
 */
function notamTfrMapLayerCurrentBuildToken(): string {
    $versionSuffix = '-v' . NOTAM_TFR_MAP_LAYER_LOGIC_VERSION;
    $sha = getGitSha();
    if ($sha !== '') {
        return $sha . $versionSuffix;
    }

    return 'logic' . $versionSuffix;
}

/**
 * True when decoded aggregate JSON has the expected FeatureCollection shape.
 *
 * @param array<string, mixed>|null $decoded Decoded aggregate cache payload
 * @return bool
 */
function notamTfrMapLayerAggregateFileIsValid(?array $decoded): bool {
    return is_array($decoded)
        && ($decoded['type'] ?? '') === 'FeatureCollection'
        && isset($decoded['features'])
        && is_array($decoded['features']);
}

/**
 * True when cached aggregate was built by the current deploy or logic version.
 *
 * @param array<string, mixed>|null $cachedPayload Decoded aggregate cache
 * @return bool
 */
function notamTfrMapLayerAggregateBuildTokenMatches(?array $cachedPayload): bool {
    if ($cachedPayload === null) {
        return false;
    }
    $stored = trim((string)($cachedPayload['map_layer_build_token'] ?? ''));
    if ($stored === '') {
        return false;
    }

    return $stored === notamTfrMapLayerCurrentBuildToken();
}

/**
 * Aggregate map layer file mtime after clearing PHP's per-request stat cache.
 *
 * @return int Unix mtime, or 0 when the file is missing
 */
function notamTfrMapLayerAggregateCacheMtime(): int {
    $path = getNotamTfrMapLayerCachePath();
    clearstatcache(true, $path);

    return is_file($path) ? (int)@filemtime($path) : 0;
}

/**
 * Load listed airport NOTAM caches in one pass for build and serve-time revalidation.
 *
 * @param array<string, mixed> $config Full config from {@see loadConfig()}
 * @return array{
 *     newest_mtime: int,
 *     by_id: array<string, array{notam: array<string, mixed>, timezone: string, airport_id: string}>,
 *     airports: array<string, array{airport: array<string, mixed>, timezone: string, notams: array<int, mixed>, cache_mtime: int}>
 * }
 */
function notamTfrMapLayerLoadListedAirportCaches(array $config): array {
    $result = [
        'newest_mtime' => 0,
        'by_id' => [],
        'airports' => [],
    ];
    if (!isset($config['airports']) || !is_array($config['airports'])) {
        return $result;
    }

    $listed = getListedAirports($config);
    foreach ($listed as $airportId => $airport) {
        if (!is_array($airport) || !isAirportEnabled($airport)) {
            continue;
        }
        $cachePath = notamCacheFilePath((string)$airportId);
        $cache = notamReadCachePayload($cachePath);
        $cacheMtime = is_readable($cachePath) ? (int)@filemtime($cachePath) : 0;
        if ($cacheMtime > $result['newest_mtime']) {
            $result['newest_mtime'] = $cacheMtime;
        }
        $notams = [];
        if ($cache !== null && isset($cache['notams']) && is_array($cache['notams'])) {
            $notams = $cache['notams'];
        }
        $timezone = getAirportTimezone($airport);
        $result['airports'][(string)$airportId] = [
            'airport' => $airport,
            'timezone' => $timezone,
            'notams' => $notams,
            'cache_mtime' => $cacheMtime,
        ];
        foreach ($notams as $notam) {
            if (!is_array($notam)) {
                continue;
            }
            $id = trim((string)($notam['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            if (isset($result['by_id'][$id])) {
                continue;
            }
            $result['by_id'][$id] = [
                'notam' => $notam,
                'timezone' => $timezone,
                'airport_id' => (string)$airportId,
            ];
        }
    }

    return $result;
}

/**
 * Latest modification time among listed airport NOTAM cache files.
 *
 * @param array<string, mixed> $config Full config from {@see loadConfig()}
 * @return int Unix mtime, or 0 when no readable per-airport cache exists
 */
function notamTfrMapLayerNewestPerAirportCacheMtime(array $config): int {
    return notamTfrMapLayerLoadListedAirportCaches($config)['newest_mtime'];
}

/**
 * Whether the on-disk aggregate map layer should be rebuilt from source caches.
 *
 * Pure predicate: pass a decoded aggregate payload when already loaded to avoid
 * extra disk reads. Does not read the aggregate file itself.
 *
 * @param int $mapMtime {@see filemtime()} of aggregate cache, or 0 when missing
 * @param array<string, mixed> $config Full config from {@see loadConfig()}
 * @param int $ttl Aggregate max age from {@see getNotamCacheTtlSeconds()}
 * @param array<string, mixed>|null $cachedPayload Decoded aggregate JSON, if available
 * @param int|null $newestSourceMtime Optional preload from {@see notamTfrMapLayerLoadListedAirportCaches()}
 * @param array{
 *     newest_mtime: int,
 *     by_id: array<string, array{notam: array<string, mixed>, timezone: string, airport_id: string}>,
 *     airports: array<string, array{airport: array<string, mixed>, timezone: string, notams: array<int, mixed>, cache_mtime: int}>
 * }|null $listedCaches Optional preload from {@see notamTfrMapLayerLoadListedAirportCaches()}
 * @param int|null $nowUnix Optional fixed clock for tests; defaults to {@see time()}
 * @return bool True when geometry rebuild is required
 */
function notamTfrMapLayerAggregateNeedsRebuild(
    int $mapMtime,
    array $config,
    int $ttl,
    ?array $cachedPayload = null,
    ?int $newestSourceMtime = null,
    ?array $listedCaches = null,
    ?int $nowUnix = null
): bool {
    $nowUnix = $nowUnix ?? time();
    if ($mapMtime <= 0) {
        return true;
    }

    $age = $nowUnix - $mapMtime;
    if ($age < 0 || $age >= $ttl) {
        return true;
    }

    if ($cachedPayload === null || !notamTfrMapLayerAggregateFileIsValid($cachedPayload)) {
        return true;
    }

    if (!notamTfrMapLayerAggregateBuildTokenMatches($cachedPayload)) {
        return true;
    }

    if ($newestSourceMtime === null) {
        $listedCaches = $listedCaches ?? notamTfrMapLayerLoadListedAirportCaches($config);
        $sourceMtime = $listedCaches['newest_mtime'];
    } else {
        $sourceMtime = $newestSourceMtime;
    }
    if ($sourceMtime > $mapMtime) {
        return true;
    }

    $features = $cachedPayload['features'] ?? [];
    if (is_array($features) && $features !== []) {
        return false;
    }

    $listedCaches = $listedCaches ?? notamTfrMapLayerLoadListedAirportCaches($config);

    return notamTfrMapLayerAggregateMissingDrawableGeometry($cachedPayload, $listedCaches, $nowUnix);
}

/**
 * Read decoded aggregate map layer JSON from disk.
 *
 * @return array<string, mixed>|null FeatureCollection payload or null when missing/invalid
 */
function notamTfrMapLayerReadAggregateCache(): ?array {
    $path = getNotamTfrMapLayerCachePath();
    clearstatcache(true, $path);
    if (!is_readable($path)) {
        return null;
    }

    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        aviationwx_log('warning', 'notam map layer: unreadable aggregate cache', [
            'path' => $path,
        ], 'app');

        return null;
    }

    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        aviationwx_log('warning', 'notam map layer: invalid aggregate cache JSON', [
            'path' => $path,
            'error' => $e->getMessage(),
        ], 'app');

        return null;
    }

    if (!notamTfrMapLayerAggregateFileIsValid(is_array($decoded) ? $decoded : null)) {
        aviationwx_log('warning', 'notam map layer: aggregate cache has unexpected shape', [
            'path' => $path,
        ], 'app');

        return null;
    }

    return $decoded;
}

/**
 * Recompute status, map style, and tooltip lines from per-airport NOTAM caches.
 *
 * Cached geometry is retained; deduplication runs again so active restrictions
 * win when wall-clock status changes without a geometry rebuild. Uses the same
 * TFR filter and status helper as {@see api/notam.php}.
 *
 * @param array<string, mixed> $payload GeoJSON FeatureCollection from disk or build
 * @param array<string, mixed> $config Full config from {@see loadConfig()}
 * @param int|null $nowUnix Current Unix time (UTC instants); defaults to {@see time()}
 * @param array{
 *     newest_mtime: int,
 *     by_id: array<string, array{notam: array<string, mixed>, timezone: string, airport_id: string}>,
 *     airports: array<string, array{airport: array<string, mixed>, timezone: string, notams: array<int, mixed>, cache_mtime: int}>
 * }|null $listedCaches Optional preload from {@see notamTfrMapLayerLoadListedAirportCaches()}
 * @return array<string, mixed> Payload with fresh status fields and {@see time()} as generated_at
 */
function notamTfrMapLayerRevalidatePayload(
    array $payload,
    array $config,
    ?int $nowUnix = null,
    ?array $listedCaches = null
): array {
    $nowUnix = $nowUnix ?? time();
    $ttl = getNotamCacheTtlSeconds();
    $features = $payload['features'] ?? [];
    if (!is_array($features) || $features === []) {
        return [
            'type' => 'FeatureCollection',
            'features' => [],
            'generated_at' => $nowUnix,
            'cache_ttl_seconds' => $ttl,
        ];
    }

    if ($listedCaches === null) {
        $listedCaches = notamTfrMapLayerLoadListedAirportCaches($config);
    }
    $lookup = $listedCaches['by_id'];
    $revalidated = [];
    foreach ($features as $feature) {
        if (!is_array($feature)) {
            continue;
        }
        $props = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
        $notamId = trim((string)($props['notam_id'] ?? ''));
        if ($notamId === '' || !isset($lookup[$notamId])) {
            continue;
        }

        $entry = $lookup[$notamId];
        $notam = $entry['notam'];
        if (!isTfr($notam)) {
            continue;
        }
        notamEnsureEffectiveSegments($notam);
        $status = revalidateNotamStatus($notam, $entry['timezone'], $nowUnix);
        if ($status === 'expired' || $status === 'unknown') {
            continue;
        }

        $props['status'] = $status;
        $props['map_layer_style'] = notamTfrMapLayerStyleBucket($status);
        $props['airport_id'] = $entry['airport_id'];
        $statusLine = notamTfrMapLayerTooltipStatusLine($notam, $status, $entry['timezone'], $nowUnix);
        if ($statusLine !== null && $statusLine !== '') {
            $props['status_line'] = $statusLine;
        } else {
            unset($props['status_line']);
        }
        $feature['properties'] = $props;
        $revalidated[] = $feature;
    }

    $revalidated = notamTfrMapLayerDeduplicateFeaturesByGeometry($revalidated);

    return [
        'type' => 'FeatureCollection',
        'features' => $revalidated,
        'generated_at' => $nowUnix,
        'cache_ttl_seconds' => $ttl,
    ];
}

/**
 * Rebuild aggregate geometry under flock (shared by non-blocking and blocking paths).
 *
 * @param array<string, mixed> $config Full config from {@see loadConfig()}
 * @param int $ttl Aggregate max age from {@see getNotamCacheTtlSeconds()}
 * @param array{
 *     newest_mtime: int,
 *     by_id: array<string, array{notam: array<string, mixed>, timezone: string, airport_id: string}>,
 *     airports: array<string, array{airport: array<string, mixed>, timezone: string, notams: array<int, mixed>, cache_mtime: int}>
 * } $listedCaches Preloaded per-airport caches
 * @param bool $blocking True for {@see flock()} LOCK_EX; false for LOCK_NB
 * @param int|null $mapMtime Known aggregate mtime before lock (non-blocking only)
 * @param array<string, mixed>|null $cachedPayload Decoded aggregate JSON (non-blocking only)
 * @return array<string, mixed>|null Payload, existing cache, or null when non-blocking lock is held
 */
function notamTfrMapLayerRebuildAggregateUnderFlock(
    array $config,
    int $ttl,
    array $listedCaches,
    bool $blocking,
    ?int $mapMtime = null,
    ?array $cachedPayload = null
): ?array {
    $lockPath = getNotamTfrMapLayerRebuildLockPath();
    $lockDir = dirname($lockPath);
    if (!is_dir($lockDir)) {
        if (!@mkdir($lockDir, 0755, true) && !is_dir($lockDir)) {
            return notamTfrMapLayerBuildPayload($config, $listedCaches);
        }
    }

    $fp = @fopen($lockPath, 'c+');
    if ($fp === false) {
        return notamTfrMapLayerBuildPayload($config, $listedCaches);
    }

    $lockFlags = $blocking ? LOCK_EX : (LOCK_EX | LOCK_NB);
    if (!@flock($fp, $lockFlags)) {
        fclose($fp);

        return $blocking ? notamTfrMapLayerReadAggregateCache() : null;
    }

    try {
        $path = getNotamTfrMapLayerCachePath();
        $currentMtime = notamTfrMapLayerAggregateCacheMtime();
        if (!$blocking && $mapMtime !== null && $currentMtime === $mapMtime) {
            $currentCached = $cachedPayload;
        } else {
            $currentCached = notamTfrMapLayerReadAggregateCache();
        }

        if (!notamTfrMapLayerAggregateNeedsRebuild(
            $currentMtime,
            $config,
            $ttl,
            $currentCached,
            $listedCaches['newest_mtime'],
            $listedCaches
        )) {
            if ($blocking) {
                return $currentCached;
            }

            return $currentCached ?? notamTfrMapLayerReadAggregateCache();
        }

        $payload = notamTfrMapLayerBuildPayload($config, $listedCaches);
        if (!notamTfrMapLayerWriteCache($payload)) {
            aviationwx_log('warning', 'notam map layer: failed to write cache', [
                'path' => $path,
                'feature_count' => count($payload['features'] ?? []),
            ], 'app');
        } elseif (!$blocking) {
            aviationwx_log('info', 'notam map layer: cache rebuilt', [
                'feature_count' => count($payload['features'] ?? []),
                'ttl_seconds' => $ttl,
            ], 'app');
        }

        return $payload;
    } finally {
        @flock($fp, LOCK_UN);
        fclose($fp);
    }
}

/**
 * Rebuild aggregate geometry under an exclusive flock (single-flight, non-blocking).
 *
 * @param array<string, mixed> $config Full config from {@see loadConfig()}
 * @param int $mapMtime Known aggregate mtime before lock attempt
 * @param int $ttl Aggregate max age from {@see getNotamCacheTtlSeconds()}
 * @param array{
 *     newest_mtime: int,
 *     by_id: array<string, array{notam: array<string, mixed>, timezone: string, airport_id: string}>,
 *     airports: array<string, array{airport: array<string, mixed>, timezone: string, notams: array<int, mixed>, cache_mtime: int}>
 * } $listedCaches Preloaded per-airport caches
 * @param array<string, mixed>|null $cachedPayload Decoded aggregate JSON, if already read
 * @return array<string, mixed>|null Fresh or existing payload; null when a waiter should retry read
 */
function notamTfrMapLayerRebuildAggregateLocked(
    array $config,
    int $mapMtime,
    int $ttl,
    array $listedCaches,
    ?array $cachedPayload = null
): ?array {
    return notamTfrMapLayerRebuildAggregateUnderFlock(
        $config,
        $ttl,
        $listedCaches,
        false,
        $mapMtime,
        $cachedPayload
    );
}

/**
 * Rebuild aggregate geometry under a blocking flock (cold start).
 *
 * @param array<string, mixed> $config Full config from {@see loadConfig()}
 * @param int $ttl Aggregate max age from {@see getNotamCacheTtlSeconds()}
 * @param array{
 *     newest_mtime: int,
 *     by_id: array<string, array{notam: array<string, mixed>, timezone: string, airport_id: string}>,
 *     airports: array<string, array{airport: array<string, mixed>, timezone: string, notams: array<int, mixed>, cache_mtime: int}>
 * } $listedCaches Preloaded per-airport caches
 * @return array<string, mixed>|null Fresh or existing payload
 */
function notamTfrMapLayerRebuildAggregateBlocking(array $config, int $ttl, array $listedCaches): ?array {
    return notamTfrMapLayerRebuildAggregateUnderFlock($config, $ttl, $listedCaches, true);
}

/**
 * Load or rebuild cached geometry (disk aggregate) for the map layer.
 *
 * Serve path cases:
 * 1. Fresh aggregate on disk - return as-is.
 * 2. Stale aggregate - non-blocking flock rebuild.
 * 3. Lock held - return existing aggregate if another worker rebuilt.
 * 4. Cold start - blocking flock rebuild.
 * 5. Lock unavailable - build in-process without persisting coordination.
 *
 * @param array<string, mixed> $config Full config from {@see loadConfig()}
 * @param int $ttl Aggregate max age from {@see getNotamCacheTtlSeconds()}
 * @param array{
 *     newest_mtime: int,
 *     by_id: array<string, array{notam: array<string, mixed>, timezone: string, airport_id: string}>,
 *     airports: array<string, array{airport: array<string, mixed>, timezone: string, notams: array<int, mixed>, cache_mtime: int}>
 * } $listedCaches Preloaded per-airport caches
 * @return array<string, mixed> GeoJSON FeatureCollection with geometry metadata
 */
function notamTfrMapLayerResolveCachedGeometry(array $config, int $ttl, array $listedCaches): array {
    $path = getNotamTfrMapLayerCachePath();
    $mapMtime = notamTfrMapLayerAggregateCacheMtime();
    $cached = notamTfrMapLayerReadAggregateCache();

    if (!notamTfrMapLayerAggregateNeedsRebuild(
        $mapMtime,
        $config,
        $ttl,
        $cached,
        $listedCaches['newest_mtime'],
        $listedCaches
    )) {
        return $cached ?? [
            'type' => 'FeatureCollection',
            'features' => [],
            'generated_at' => time(),
            'cache_ttl_seconds' => $ttl,
            'map_layer_build_token' => notamTfrMapLayerCurrentBuildToken(),
        ];
    }

    $rebuilt = notamTfrMapLayerRebuildAggregateLocked($config, $mapMtime, $ttl, $listedCaches, $cached);
    if ($rebuilt !== null) {
        return $rebuilt;
    }

    $cached = notamTfrMapLayerReadAggregateCache();
    if ($cached !== null) {
        return $cached;
    }

    $rebuilt = notamTfrMapLayerRebuildAggregateBlocking($config, $ttl, $listedCaches);
    if ($rebuilt !== null) {
        return $rebuilt;
    }

    $payload = notamTfrMapLayerBuildPayload($config, $listedCaches);
    if (!notamTfrMapLayerWriteCache($payload)) {
        aviationwx_log('warning', 'notam map layer: failed to write cache after lock miss', [
            'path' => $path,
            'feature_count' => count($payload['features'] ?? []),
        ], 'app');
    }

    return $payload;
}

/**
 * Write aggregated map layer JSON to disk (atomic replace when possible).
 *
 * @param array<string, mixed> $payload GeoJSON payload plus metadata
 * @return bool True when the file was written
 */
function notamTfrMapLayerWriteCache(array $payload): bool {
    $path = getNotamTfrMapLayerCachePath();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
    }
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }
    if (!@rename($tmp, $path)) {
        $ok = @file_put_contents($path, $json, LOCK_EX) !== false;
        @unlink($tmp);
        return $ok;
    }
    return true;
}

/**
 * Return map GeoJSON: shared disk cache for geometry, serve-time status refresh.
 *
 * Three TTL concepts apply to this endpoint:
 * - {@see getNotamCacheTtlSeconds()} on disk aggregate and in JSON cache_ttl_seconds
 *   (client poll interval on airports.php).
 * - {@see NOTAM_API_CACHE_TTL_SECONDS} on HTTP Cache-Control (browser/CDN sharing).
 * - Serve-time revalidation uses wall clock on every origin request.
 *
 * @return array<string, mixed> GeoJSON FeatureCollection plus generated_at and cache_ttl_seconds
 */
function notamTfrMapLayerServeOrRebuild(): array {
    $ttl = getNotamCacheTtlSeconds();
    $now = time();
    $config = loadConfig();
    $emptyConfig = ['airports' => []];

    if ($config === null || !isset($config['airports'])) {
        $empty = [
            'type' => 'FeatureCollection',
            'features' => [],
            'generated_at' => $now,
            'cache_ttl_seconds' => $ttl,
        ];
        $listedCaches = notamTfrMapLayerLoadListedAirportCaches($emptyConfig);

        return notamTfrMapLayerRevalidatePayload($empty, $emptyConfig, $now, $listedCaches);
    }

    $listedCaches = notamTfrMapLayerLoadListedAirportCaches($config);
    $geometry = notamTfrMapLayerResolveCachedGeometry($config, $ttl, $listedCaches);

    return notamTfrMapLayerRevalidatePayload($geometry, $config, $now, $listedCaches);
}
