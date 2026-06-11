<?php
/**
 * Weather Health Tracking
 *
 * Tracks weather fetch success/failure rates by writing directly to cache file.
 * Uses file locking for thread safety across PHP-FPM workers.
 *
 * Health status is recomputed only during scheduler flush (every 60s), not on
 * every tracking call, to avoid impacting weather fetch performance.
 *
 * Usage:
 *   weatherHealthTrackFetch('kspb', 'tempest', true, 200);
 *   weatherHealthTrackFetch('kspb', 'metar', false, 503);
 *   weatherHealthFlush();
 */

require_once __DIR__ . '/cache-paths.php';
require_once __DIR__ . '/logger.php';

// Cache file for weather health status
if (!defined('WEATHER_HEALTH_CACHE_FILE')) {
    define('WEATHER_HEALTH_CACHE_FILE', CACHE_BASE_DIR . '/weather_health.json');
}

/**
 * Path to weather health JSON (supports PHPUnit via $GLOBALS['weatherHealthTestCacheFile']).
 */
function getWeatherHealthCacheFilePath(): string
{
    if (isset($GLOBALS['weatherHealthTestCacheFile'])
        && is_string($GLOBALS['weatherHealthTestCacheFile'])
        && $GLOBALS['weatherHealthTestCacheFile'] !== ''
    ) {
        return $GLOBALS['weatherHealthTestCacheFile'];
    }

    return WEATHER_HEALTH_CACHE_FILE;
}

/**
 * Ensure the weather health cache directory exists.
 *
 * @return bool True when the directory exists or was created
 */
function weatherHealthEnsureCacheDirectory(): bool
{
    $cacheDir = dirname(getWeatherHealthCacheFilePath());
    if (ensureCacheDir($cacheDir)) {
        return true;
    }

    aviationwx_log('warning', 'weather health: failed to create cache directory', [
        'dir' => $cacheDir,
    ], 'app');

    return false;
}

/**
 * Increment upstream HTTP failure counters shared by fetch and bulk download paths.
 *
 * @param array<string, int> $counters Counter map to update in place
 * @param int|null $httpCode HTTP status when available
 * @param string|null $sourceType Provider key for per-source buckets (e.g. metar, metar_bulk)
 */
function weatherHealthIncrementHttpFailureCounters(array &$counters, ?int $httpCode, ?string $sourceType = null): void
{
    if ($httpCode === null) {
        return;
    }

    if ($httpCode === 429) {
        $counters['upstream_429'] = 1;
        if ($sourceType !== null && $sourceType !== '') {
            $counters["upstream_429_{$sourceType}"] = 1;
        }
    } elseif ($httpCode >= 400 && $httpCode < 500) {
        if ($sourceType !== null && $sourceType !== '') {
            $counters["http_4xx_{$sourceType}"] = 1;
        }
    } elseif ($httpCode >= 500) {
        if ($sourceType !== null && $sourceType !== '') {
            $counters["http_5xx_{$sourceType}"] = 1;
        }
    }
}

// =============================================================================
// TRACKING FUNCTIONS (called by UnifiedFetcher.php and METAR bulk refresh)
// =============================================================================

/**
 * Track a weather fetch event.
 *
 * @param string $airportId Airport identifier
 * @param string $sourceType Source type (tempest, ambient, metar, etc.)
 * @param bool $success Whether fetch succeeded
 * @param int|null $httpCode HTTP status code (optional)
 */
function weatherHealthTrackFetch(string $airportId, string $sourceType, bool $success, ?int $httpCode = null): void
{
    $now = time();
    $currentHour = gmdate('Y-m-d-H', $now);

    $counters = [
        "attempts_{$sourceType}" => 1,
        "airport_attempts_{$airportId}" => 1,
        'total_attempts' => 1,
    ];

    if ($success) {
        $counters["successes_{$sourceType}"] = 1;
        $counters["airport_successes_{$airportId}"] = 1;
        $counters['total_successes'] = 1;
    } else {
        $counters["failures_{$sourceType}"] = 1;
        $counters["airport_failures_{$airportId}"] = 1;
        $counters['total_failures'] = 1;
        weatherHealthIncrementHttpFailureCounters($counters, $httpCode, $sourceType);
    }

    weatherHealthAtomicUpdate($currentHour, $counters, $now);
}

/**
 * Track a circuit breaker open event.
 *
 * @param string $airportId Airport identifier
 * @param string $sourceType Source type
 */
function weatherHealthTrackCircuitOpen(string $airportId, string $sourceType): void
{
    $now = time();
    $currentHour = gmdate('Y-m-d-H', $now);

    $counters = [
        'circuit_open_events' => 1,
        "circuit_open_{$sourceType}" => 1,
    ];

    weatherHealthAtomicUpdate($currentHour, $counters, $now);
}

/**
 * Track an upstream self-throttle skip (budget exhausted for this cycle).
 *
 * @param string $airportId Airport identifier
 * @param string $sourceType Source type (tempest, metar, etc.)
 */
function weatherHealthTrackUpstreamThrottleSkip(string $airportId, string $sourceType): void
{
    $now = time();
    $currentHour = gmdate('Y-m-d-H', $now);

    $counters = [
        'upstream_throttle_skips' => 1,
        "upstream_throttle_skip_{$sourceType}" => 1,
        "upstream_throttle_skip_airport_{$airportId}" => 1,
    ];

    weatherHealthAtomicUpdate($currentHour, $counters, $now);
}

/**
 * Track fail-open when upstream rate limit state cannot be read or written.
 *
 * @param string $reason Short reason code (e.g. state_dir_unavailable)
 */
function weatherHealthTrackUpstreamRateLimitFailOpen(string $reason): void
{
    $now = time();
    $currentHour = gmdate('Y-m-d-H', $now);

    $safeReason = preg_replace('/[^a-z0-9_]/', '', strtolower($reason)) ?? 'unknown';
    $counters = [
        'upstream_rate_limit_fail_open' => 1,
        "upstream_rate_limit_fail_open_{$safeReason}" => 1,
    ];

    weatherHealthAtomicUpdate($currentHour, $counters, $now);
}

/**
 * Track a failed AWC national METAR bulk gzip download (scheduler worker).
 *
 * @param int|null $httpCode HTTP status from curl when available
 */
function weatherHealthTrackMetarBulkDownloadFailure(?int $httpCode): void
{
    $now = time();
    $currentHour = gmdate('Y-m-d-H', $now);

    $counters = [
        'metar_bulk_download_failures' => 1,
        'attempts_metar_bulk' => 1,
        'failures_metar_bulk' => 1,
    ];
    weatherHealthIncrementHttpFailureCounters($counters, $httpCode, 'metar_bulk');

    weatherHealthAtomicUpdate($currentHour, $counters, $now);
}

// =============================================================================
// FILE I/O FUNCTIONS
// =============================================================================

/**
 * Atomically update the weather health cache file.
 *
 * @param string $currentHour Current hour bucket key (Y-m-d-H)
 * @param array<string, int> $counters Counters to increment
 * @param int $now Current timestamp
 */
function weatherHealthAtomicUpdate(string $currentHour, array $counters, int $now): bool
{
    if (!weatherHealthEnsureCacheDirectory()) {
        return false;
    }

    $fp = @fopen(getWeatherHealthCacheFilePath(), 'c+');
    if ($fp === false) {
        aviationwx_log('warning', 'weather health: failed to open cache file', [
            'file' => getWeatherHealthCacheFilePath(),
        ], 'app');

        return false;
    }

    if (!@flock($fp, LOCK_EX | LOCK_NB)) {
        @fclose($fp);

        return false;
    }

    $content = @stream_get_contents($fp);
    $data = [];
    if ($content !== false && $content !== '') {
        $data = @json_decode($content, true) ?: [];
    }

    if (!isset($data['hourly_buckets'])) {
        $data['hourly_buckets'] = [];
    }

    if (!isset($data['hourly_buckets'][$currentHour])) {
        $data['hourly_buckets'][$currentHour] = [];
    }

    foreach ($counters as $key => $value) {
        if (!isset($data['hourly_buckets'][$currentHour][$key])) {
            $data['hourly_buckets'][$currentHour][$key] = 0;
        }
        $data['hourly_buckets'][$currentHour][$key] += $value;
    }

    $data['last_fetch'] = $now;
    $data['last_update'] = $now;
    $data['current_hour'] = $currentHour;

    $twoHoursAgo = gmdate('Y-m-d-H', $now - 7200);
    foreach (array_keys($data['hourly_buckets']) as $hourKey) {
        if ($hourKey < $twoHoursAgo) {
            unset($data['hourly_buckets'][$hourKey]);
        }
    }

    @ftruncate($fp, 0);
    @fseek($fp, 0);
    $written = @fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));

    @flock($fp, LOCK_UN);
    @fclose($fp);

    if ($written === false) {
        aviationwx_log('warning', 'weather health: failed to write cache file', [
            'file' => getWeatherHealthCacheFilePath(),
        ], 'app');

        return false;
    }

    return true;
}

/**
 * Flush weather health data (prune old buckets and recompute status).
 */
function weatherHealthFlush(): bool
{
    if (!weatherHealthEnsureCacheDirectory()) {
        return false;
    }

    $now = time();

    if (!file_exists(getWeatherHealthCacheFilePath())) {
        $data = [
            'hourly_buckets' => [],
            'current_hour' => gmdate('Y-m-d-H', $now),
            'last_flush' => $now,
            'health' => weatherHealthComputeStatus([]),
            'sources' => [],
        ];

        return @file_put_contents(getWeatherHealthCacheFilePath(), json_encode($data, JSON_PRETTY_PRINT), LOCK_EX) !== false;
    }

    $fp = @fopen(getWeatherHealthCacheFilePath(), 'c+');
    if ($fp === false) {
        return false;
    }

    if (!@flock($fp, LOCK_EX)) {
        @fclose($fp);

        return false;
    }

    $content = @stream_get_contents($fp);
    $data = [];
    if ($content !== false && $content !== '') {
        $data = @json_decode($content, true) ?: [];
    }

    $twoHoursAgo = gmdate('Y-m-d-H', $now - 7200);
    if (isset($data['hourly_buckets'])) {
        foreach (array_keys($data['hourly_buckets']) as $hourKey) {
            if ($hourKey < $twoHoursAgo) {
                unset($data['hourly_buckets'][$hourKey]);
            }
        }
    }

    $data['last_flush'] = $now;
    $data['current_hour'] = gmdate('Y-m-d-H', $now);
    $data['health'] = weatherHealthComputeStatus($data);
    $data['sources'] = weatherHealthComputeSourceStatus($data);

    @ftruncate($fp, 0);
    @fseek($fp, 0);
    @fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));

    @flock($fp, LOCK_UN);
    @fclose($fp);

    return true;
}

// =============================================================================
// HEALTH STATUS FUNCTIONS
// =============================================================================

/**
 * Compute overall health status from aggregated data.
 *
 * @param array<string, mixed> $data Aggregated weather health data
 * @return array<string, mixed> Health status array
 */
function weatherHealthComputeStatus(array $data): array
{
    $health = [
        'name' => 'Weather Data Fetching',
        'status' => 'operational',
        'message' => 'All weather sources responding',
        'lastChanged' => $data['last_fetch'] ?? 0,
        'metrics' => [],
    ];

    $oneHourAgo = gmdate('Y-m-d-H', time() - 3600);
    $totals = [
        'total_attempts' => 0,
        'total_successes' => 0,
        'total_failures' => 0,
        'circuit_open_events' => 0,
        'upstream_throttle_skips' => 0,
        'upstream_rate_limit_fail_open' => 0,
        'upstream_429' => 0,
        'metar_bulk_download_failures' => 0,
    ];

    foreach ($data['hourly_buckets'] ?? [] as $hourKey => $bucket) {
        if ($hourKey >= $oneHourAgo) {
            foreach ($totals as $key => &$value) {
                $value += $bucket[$key] ?? 0;
            }
        }
    }

    $successRate = $totals['total_attempts'] > 0
        ? $totals['total_successes'] / $totals['total_attempts']
        : 1.0;

    $noFetchActivity = $totals['total_attempts'] === 0 && $totals['metar_bulk_download_failures'] === 0;

    if ($totals['total_attempts'] > 0 && $successRate < 0.5) {
        $health['status'] = 'down';
        $health['message'] = sprintf('Low success rate: %.1f%%', $successRate * 100);
    } elseif ($totals['total_attempts'] > 0 && $successRate < 0.8) {
        $health['status'] = 'degraded';
        $health['message'] = sprintf('Degraded success rate: %.1f%%', $successRate * 100);
    } elseif ($totals['circuit_open_events'] > 0) {
        $health['status'] = 'degraded';
        $health['message'] = sprintf('%d circuit breaker event(s) in last hour', $totals['circuit_open_events']);
    } elseif ($totals['upstream_rate_limit_fail_open'] > 0) {
        $health['status'] = 'degraded';
        $health['message'] = sprintf(
            '%d upstream rate limit fail-open event(s) in last hour (check cache/upstream-limits/)',
            $totals['upstream_rate_limit_fail_open']
        );
    } elseif ($totals['metar_bulk_download_failures'] > 0) {
        $health['status'] = 'degraded';
        $health['message'] = sprintf('%d METAR bulk download failure(s) in last hour', $totals['metar_bulk_download_failures']);
    } elseif ($totals['upstream_429'] > 0) {
        $health['status'] = 'degraded';
        $health['message'] = sprintf('%d upstream HTTP 429 response(s) in last hour', $totals['upstream_429']);
    } elseif ($totals['upstream_throttle_skips'] > 0) {
        $health['status'] = 'operational';
        $health['message'] = sprintf('%d upstream throttle skip(s) in last hour', $totals['upstream_throttle_skips']);
    } elseif ($noFetchActivity) {
        $health['status'] = 'degraded';
        $health['message'] = 'No recent weather fetch activity';
    }

    $health['metrics'] = [
        'success_rate' => round($successRate * 100, 1),
        'total_attempts_last_hour' => $totals['total_attempts'],
        'total_successes_last_hour' => $totals['total_successes'],
        'total_failures_last_hour' => $totals['total_failures'],
        'circuit_open_events_last_hour' => $totals['circuit_open_events'],
        'upstream_throttle_skips_last_hour' => $totals['upstream_throttle_skips'],
        'upstream_rate_limit_fail_open_last_hour' => $totals['upstream_rate_limit_fail_open'],
        'upstream_429_last_hour' => $totals['upstream_429'],
        'metar_bulk_download_failures_last_hour' => $totals['metar_bulk_download_failures'],
    ];

    return $health;
}

/**
 * Compute per-source health status.
 *
 * @param array<string, mixed> $data Aggregated weather health data
 * @return array<string, array<string, mixed>> Source health keyed by source type
 */
function weatherHealthComputeSourceStatus(array $data): array
{
    $sources = [];
    $oneHourAgo = gmdate('Y-m-d-H', time() - 3600);

    $sourceTypes = ['tempest', 'ambient', 'metar', 'synopticdata', 'weatherlink_v2', 'weatherlink_v1', 'pwsweather', 'awosnet'];

    foreach ($sourceTypes as $sourceType) {
        $attempts = 0;
        $successes = 0;
        $failures = 0;
        $http4xx = 0;
        $http5xx = 0;
        $upstream429 = 0;

        foreach ($data['hourly_buckets'] ?? [] as $hourKey => $bucket) {
            if ($hourKey >= $oneHourAgo) {
                $attempts += $bucket["attempts_{$sourceType}"] ?? 0;
                $successes += $bucket["successes_{$sourceType}"] ?? 0;
                $failures += $bucket["failures_{$sourceType}"] ?? 0;
                $http4xx += $bucket["http_4xx_{$sourceType}"] ?? 0;
                $http5xx += $bucket["http_5xx_{$sourceType}"] ?? 0;
                $upstream429 += $bucket["upstream_429_{$sourceType}"] ?? 0;
            }
        }

        if ($attempts === 0) {
            continue;
        }

        $successRate = $successes / $attempts;

        $status = 'operational';
        $message = 'Responding normally';

        if ($successRate < 0.5) {
            $status = 'down';
            $message = sprintf('%.0f%% success rate', $successRate * 100);
        } elseif ($successRate < 0.8) {
            $status = 'degraded';
            $message = sprintf('%.0f%% success rate', $successRate * 100);
        } elseif ($http5xx > 0) {
            $status = 'degraded';
            $message = sprintf('%d server errors', $http5xx);
        } elseif ($upstream429 > 0) {
            $status = 'degraded';
            $message = sprintf('%d HTTP 429 response(s)', $upstream429);
        }

        $sources[$sourceType] = [
            'status' => $status,
            'message' => $message,
            'metrics' => [
                'success_rate' => round($successRate * 100, 1),
                'attempts' => $attempts,
                'successes' => $successes,
                'failures' => $failures,
                'http_4xx' => $http4xx,
                'http_5xx' => $http5xx,
                'upstream_429' => $upstream429,
            ],
        ];
    }

    return $sources;
}

/**
 * Get weather health status (for status page).
 *
 * @return array<string, mixed> Health status
 */
function weatherHealthGetStatus(): array
{
    $default = [
        'name' => 'Weather Data Fetching',
        'status' => 'operational',
        'message' => 'No data available',
        'lastChanged' => 0,
        'metrics' => [],
    ];

    if (!file_exists(getWeatherHealthCacheFilePath())) {
        return $default;
    }

    $content = @file_get_contents(getWeatherHealthCacheFilePath());
    if ($content === false) {
        return $default;
    }

    $data = @json_decode($content, true);
    if (!is_array($data) || !isset($data['health'])) {
        return $default;
    }

    return $data['health'];
}

/**
 * Get per-source health status (for status page).
 *
 * @return array<string, array<string, mixed>> Source health keyed by source type
 */
function weatherHealthGetSources(): array
{
    if (!file_exists(getWeatherHealthCacheFilePath())) {
        return [];
    }

    $content = @file_get_contents(getWeatherHealthCacheFilePath());
    if ($content === false) {
        return [];
    }

    $data = @json_decode($content, true);
    if (!is_array($data) || !isset($data['sources'])) {
        return [];
    }

    return $data['sources'];
}

/**
 * Display name for a weather provider key used in upstream 429 breakdown.
 *
 * @param string $provider Provider key from hourly bucket counters
 * @return string Human-readable label
 */
function weatherHealthProviderDisplayName(string $provider): string
{
    if ($provider === 'metar_bulk') {
        return 'METAR bulk (AWC national gzip)';
    }

    require_once __DIR__ . '/weather/utils.php';

    $info = getWeatherSourceInfo($provider);
    if ($info !== null) {
        return $info['name'];
    }

    return ucfirst(str_replace('_', ' ', $provider));
}

/**
 * Build per-provider rows for status page upstream 429 breakdown.
 *
 * @return list<array<string, mixed>> Provider rows sorted by upstream 429 count
 */
function weatherHealthGetProviderBreakdown(): array
{
    if (!file_exists(getWeatherHealthCacheFilePath())) {
        return [];
    }

    $content = @file_get_contents(getWeatherHealthCacheFilePath());
    if ($content === false) {
        return [];
    }

    $data = @json_decode($content, true);
    if (!is_array($data)) {
        return [];
    }

    $oneHourAgo = gmdate('Y-m-d-H', time() - 3600);
    $byProvider = [];

    foreach ($data['hourly_buckets'] ?? [] as $hourKey => $bucket) {
        if ($hourKey < $oneHourAgo) {
            continue;
        }

        foreach ($bucket as $key => $value) {
            if (!is_string($key) || !is_numeric($value)) {
                continue;
            }

            if (preg_match('/^attempts_(.+)$/', $key, $matches) === 1) {
                $provider = $matches[1];
                if (!isset($byProvider[$provider])) {
                    $byProvider[$provider] = [
                        'attempts' => 0,
                        'successes' => 0,
                        'upstream_429' => 0,
                    ];
                }
                $byProvider[$provider]['attempts'] += (int) $value;
                continue;
            }

            if (preg_match('/^successes_(.+)$/', $key, $matches) === 1) {
                $provider = $matches[1];
                if (!isset($byProvider[$provider])) {
                    $byProvider[$provider] = [
                        'attempts' => 0,
                        'successes' => 0,
                        'upstream_429' => 0,
                    ];
                }
                $byProvider[$provider]['successes'] += (int) $value;
                continue;
            }

            if (preg_match('/^upstream_429_(.+)$/', $key, $matches) === 1) {
                $provider = $matches[1];
                if (!isset($byProvider[$provider])) {
                    $byProvider[$provider] = [
                        'attempts' => 0,
                        'successes' => 0,
                        'upstream_429' => 0,
                    ];
                }
                $byProvider[$provider]['upstream_429'] += (int) $value;
            }
        }
    }

    $providers = [];
    foreach ($byProvider as $providerId => $stats) {
        if ($stats['attempts'] === 0 && $stats['upstream_429'] === 0) {
            continue;
        }

        if ($stats['attempts'] === 0) {
            $stats['attempts'] = $stats['upstream_429'];
        }

        $providers[] = [
            'id' => $providerId,
            'name' => weatherHealthProviderDisplayName($providerId),
            'upstream_429' => $stats['upstream_429'],
            'attempts' => $stats['attempts'],
            'success_rate' => round(($stats['successes'] / $stats['attempts']) * 100, 1),
        ];
    }

    usort($providers, static function (array $a, array $b): int {
        $cmp = ($b['upstream_429'] ?? 0) <=> ($a['upstream_429'] ?? 0);
        if ($cmp !== 0) {
            return $cmp;
        }

        return ($b['attempts'] ?? 0) <=> ($a['attempts'] ?? 0);
    });

    return $providers;
}
