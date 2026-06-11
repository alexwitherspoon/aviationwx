<?php
/**
 * NOTAM Health Tracking
 *
 * Tracks NMS API request outcomes by writing directly to cache file.
 * Uses file locking for thread safety across scheduler worker processes.
 *
 * Health status is recomputed only during scheduler flush (every 60s), not on
 * every tracking call, to avoid impacting NOTAM fetch performance.
 *
 * Usage:
 *   notamHealthTrackRequest('location', true, 200);
 *   notamHealthTrackRequest('geo', false, 429);
 *   notamHealthFlush();
 */

require_once __DIR__ . '/cache-paths.php';
require_once __DIR__ . '/logger.php';

if (!defined('NOTAM_HEALTH_CACHE_FILE')) {
    define('NOTAM_HEALTH_CACHE_FILE', CACHE_BASE_DIR . '/notam_health.json');
}

/**
 * Path to NOTAM health JSON (supports PHPUnit via $GLOBALS['notamHealthTestCacheFile']).
 */
function getNotamHealthCacheFilePath(): string
{
    if (isset($GLOBALS['notamHealthTestCacheFile'])
        && is_string($GLOBALS['notamHealthTestCacheFile'])
        && $GLOBALS['notamHealthTestCacheFile'] !== ''
    ) {
        return $GLOBALS['notamHealthTestCacheFile'];
    }

    return NOTAM_HEALTH_CACHE_FILE;
}

/**
 * Ensure the NOTAM health cache directory exists.
 *
 * @return bool True when the directory exists or was created
 */
function notamHealthEnsureCacheDirectory(): bool
{
    $cacheDir = dirname(getNotamHealthCacheFilePath());
    if (ensureCacheDir($cacheDir)) {
        return true;
    }

    aviationwx_log('warning', 'notam health: failed to create cache directory', [
        'dir' => $cacheDir,
    ], 'app');

    return false;
}

/**
 * Human-readable label for an NMS endpoint key.
 *
 * @param string $endpoint Endpoint key (location, geo, auth)
 * @return string Display label
 */
function notamHealthEndpointDisplayName(string $endpoint): string
{
    return match ($endpoint) {
        'location' => 'NMS location query',
        'geo' => 'NMS geo query',
        'auth' => 'NMS auth token',
        default => 'NMS ' . str_replace('_', ' ', $endpoint),
    };
}

/**
 * Increment upstream HTTP failure counters shared by NMS request paths.
 *
 * @param array<string, int> $counters Counter map to update in place
 * @param int|null $httpCode HTTP status when available
 * @param string|null $endpoint Endpoint key for per-endpoint buckets
 */
function notamHealthIncrementHttpFailureCounters(array &$counters, ?int $httpCode, ?string $endpoint = null): void
{
    if ($httpCode === null) {
        return;
    }

    if ($httpCode === 429) {
        $counters['upstream_429'] = 1;
        if ($endpoint !== null && $endpoint !== '') {
            $counters["upstream_429_{$endpoint}"] = 1;
        }
    } elseif ($httpCode >= 400 && $httpCode < 500) {
        if ($endpoint !== null && $endpoint !== '') {
            $counters["http_4xx_{$endpoint}"] = 1;
        }
    } elseif ($httpCode >= 500) {
        if ($endpoint !== null && $endpoint !== '') {
            $counters["http_5xx_{$endpoint}"] = 1;
        }
    }
}

/**
 * Track an NMS API request outcome.
 *
 * @param string $endpoint Endpoint key (location, geo, auth)
 * @param bool $success Whether the request succeeded
 * @param int|null $httpCode HTTP status code when available
 */
function notamHealthTrackRequest(string $endpoint, bool $success, ?int $httpCode = null): void
{
    $safeEndpoint = preg_replace('/[^a-z0-9_]/', '', strtolower($endpoint)) ?? '';
    if ($safeEndpoint === '') {
        return;
    }

    $now = time();
    $currentHour = gmdate('Y-m-d-H', $now);

    $counters = [
        "attempts_{$safeEndpoint}" => 1,
        'total_attempts' => 1,
    ];

    if ($success) {
        $counters["successes_{$safeEndpoint}"] = 1;
        $counters['total_successes'] = 1;
    } else {
        $counters["failures_{$safeEndpoint}"] = 1;
        $counters['total_failures'] = 1;
        notamHealthIncrementHttpFailureCounters($counters, $httpCode, $safeEndpoint);
    }

    notamHealthAtomicUpdate($currentHour, $counters, $now);
}

/**
 * Atomically update the NOTAM health cache file.
 *
 * @param string $currentHour Current hour bucket key (Y-m-d-H)
 * @param array<string, int> $counters Counters to increment
 * @param int $now Current timestamp
 */
function notamHealthAtomicUpdate(string $currentHour, array $counters, int $now): bool
{
    if (!notamHealthEnsureCacheDirectory()) {
        return false;
    }

    $fp = @fopen(getNotamHealthCacheFilePath(), 'c+');
    if ($fp === false) {
        aviationwx_log('warning', 'notam health: failed to open cache file', [
            'file' => getNotamHealthCacheFilePath(),
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
        aviationwx_log('warning', 'notam health: failed to write cache file', [
            'file' => getNotamHealthCacheFilePath(),
        ], 'app');

        return false;
    }

    return true;
}

/**
 * Flush NOTAM health data (prune old buckets and recompute status).
 */
function notamHealthFlush(): bool
{
    if (!notamHealthEnsureCacheDirectory()) {
        return false;
    }

    $now = time();

    if (!file_exists(getNotamHealthCacheFilePath())) {
        $data = [
            'hourly_buckets' => [],
            'current_hour' => gmdate('Y-m-d-H', $now),
            'last_flush' => $now,
            'health' => notamHealthComputeStatus([]),
            'providers' => [],
        ];

        return @file_put_contents(getNotamHealthCacheFilePath(), json_encode($data, JSON_PRETTY_PRINT), LOCK_EX) !== false;
    }

    $fp = @fopen(getNotamHealthCacheFilePath(), 'c+');
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
    $data['health'] = notamHealthComputeStatus($data);
    $data['providers'] = notamHealthComputeProviderStatus($data);

    @ftruncate($fp, 0);
    @fseek($fp, 0);
    @fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));

    @flock($fp, LOCK_UN);
    @fclose($fp);

    return true;
}

/**
 * Compute overall health status from aggregated data.
 *
 * @param array<string, mixed> $data Aggregated NOTAM health data
 * @return array<string, mixed> Health status array
 */
function notamHealthComputeStatus(array $data): array
{
    $health = [
        'name' => 'NOTAM Data Fetching',
        'status' => 'operational',
        'message' => 'NMS API responding normally',
        'lastChanged' => $data['last_fetch'] ?? 0,
        'metrics' => [],
    ];

    $oneHourAgo = gmdate('Y-m-d-H', time() - 3600);
    $totals = [
        'total_attempts' => 0,
        'total_successes' => 0,
        'total_failures' => 0,
        'upstream_429' => 0,
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

    if ($totals['upstream_429'] > 0) {
        $health['status'] = 'degraded';
        $health['message'] = sprintf('%d NMS HTTP 429 response(s) in last hour', $totals['upstream_429']);
    } elseif ($totals['total_attempts'] > 0 && $successRate < 0.5) {
        $health['status'] = 'down';
        $health['message'] = sprintf('Low NMS success rate: %.1f%%', $successRate * 100);
    } elseif ($totals['total_attempts'] > 0 && $successRate < 0.8) {
        $health['status'] = 'degraded';
        $health['message'] = sprintf('Degraded NMS success rate: %.1f%%', $successRate * 100);
    } elseif ($totals['total_attempts'] === 0) {
        $health['status'] = 'degraded';
        $health['message'] = 'No recent NOTAM fetch activity';
    }

    $health['metrics'] = [
        'success_rate' => round($successRate * 100, 1),
        'total_attempts_last_hour' => $totals['total_attempts'],
        'total_successes_last_hour' => $totals['total_successes'],
        'total_failures_last_hour' => $totals['total_failures'],
        'upstream_429_last_hour' => $totals['upstream_429'],
    ];

    return $health;
}

/**
 * Compute per-endpoint provider status for the status page.
 *
 * @param array<string, mixed> $data Aggregated NOTAM health data
 * @return list<array<string, mixed>> Provider rows sorted by upstream 429 count
 */
function notamHealthComputeProviderStatus(array $data): array
{
    $oneHourAgo = gmdate('Y-m-d-H', time() - 3600);
    $endpoints = ['location', 'geo', 'auth'];
    $providers = [];

    foreach ($endpoints as $endpoint) {
        $attempts = 0;
        $successes = 0;
        $failures = 0;
        $upstream429 = 0;

        foreach ($data['hourly_buckets'] ?? [] as $hourKey => $bucket) {
            if ($hourKey >= $oneHourAgo) {
                $attempts += $bucket["attempts_{$endpoint}"] ?? 0;
                $successes += $bucket["successes_{$endpoint}"] ?? 0;
                $failures += $bucket["failures_{$endpoint}"] ?? 0;
                $upstream429 += $bucket["upstream_429_{$endpoint}"] ?? 0;
            }
        }

        if ($attempts === 0) {
            continue;
        }

        $providers[] = [
            'id' => $endpoint,
            'name' => notamHealthEndpointDisplayName($endpoint),
            'upstream_429' => $upstream429,
            'attempts' => $attempts,
            'success_rate' => round(($successes / $attempts) * 100, 1),
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

/**
 * Get NOTAM health status (for status page).
 *
 * @return array<string, mixed> Health status
 */
function notamHealthGetStatus(): array
{
    $default = [
        'name' => 'NOTAM Data Fetching',
        'status' => 'operational',
        'message' => 'No data available',
        'lastChanged' => 0,
        'metrics' => [],
    ];

    if (!file_exists(getNotamHealthCacheFilePath())) {
        return $default;
    }

    $content = @file_get_contents(getNotamHealthCacheFilePath());
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
 * Get per-endpoint provider breakdown (for status page).
 *
 * @return list<array<string, mixed>> Provider rows
 */
function notamHealthGetProviders(): array
{
    if (!file_exists(getNotamHealthCacheFilePath())) {
        return [];
    }

    $content = @file_get_contents(getNotamHealthCacheFilePath());
    if ($content === false) {
        return [];
    }

    $data = @json_decode($content, true);
    if (!is_array($data) || !isset($data['providers']) || !is_array($data['providers'])) {
        return [];
    }

    return $data['providers'];
}
