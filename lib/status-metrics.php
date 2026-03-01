<?php
/**
 * Status Page Metrics Loading
 *
 * Provides optimized access to metrics data for the status page with APCu caching.
 * Uses single bundle read (rolling7, rolling1, today) to eliminate duplicate file reads.
 * Multi-period merges bundle with current-hour APCu at request time.
 */

require_once __DIR__ . '/cached-data-loader.php';
require_once __DIR__ . '/metrics.php';
require_once __DIR__ . '/cache-paths.php';
require_once __DIR__ . '/constants.php';

/**
 * Get status metrics bundle (rolling7, rolling1, multiPeriod)
 *
 * Reads each metrics file once. APCu + file persistence. Multi-period includes
 * real-time current hour from APCu.
 *
 * @return array {
 *   rolling7: array 7-day rolling metrics,
 *   rolling1: array 1-day rolling metrics,
 *   multiPeriod: array per-airport hour/day/week metrics
 * }
 */
function getStatusMetricsBundle(): array {
    $ttl = STATUS_METRICS_CACHE_TTL;
    $bundle = getCachedData(
        fn() => metrics_get_status_bundle(),
        'status_metrics_bundle',
        CACHE_STATUS_METRICS_BUNDLE_FILE,
        $ttl
    );
    $bundle['multiPeriod'] = metrics_build_multi_period_from_bundle($bundle);
    return $bundle;
}

/**
 * Get rolling metrics with APCu caching (legacy; prefer getStatusMetricsBundle)
 *
 * @param int $days Number of days to aggregate (default: 7)
 * @param int|null $ttl Cache TTL in seconds (default: STATUS_METRICS_CACHE_TTL)
 * @return array Aggregated metrics
 */
function getStatusMetricsRolling(int $days = 7, ?int $ttl = null): array {
    $ttl = $ttl ?? STATUS_METRICS_CACHE_TTL;
    return getCachedData(
        fn() => metrics_get_rolling($days),
        "status_metrics_rolling_{$days}d",
        CACHE_BASE_DIR . "/status_metrics_rolling_{$days}d.json",
        $ttl
    );
}

/**
 * Get multi-period metrics (legacy; prefer getStatusMetricsBundle)
 *
 * @param int|null $ttl Cache TTL (default: STATUS_METRICS_CACHE_TTL)
 * @return array Multi-period metrics indexed by airport ID
 */
function getStatusMetricsMultiPeriod(?int $ttl = null): array {
    $ttl = $ttl ?? STATUS_METRICS_CACHE_TTL;
    return getCachedData(
        fn() => metrics_get_multi_period(),
        'status_metrics_multi_period',
        CACHE_BASE_DIR . '/status_metrics_multi_period.json',
        $ttl
    );
}
