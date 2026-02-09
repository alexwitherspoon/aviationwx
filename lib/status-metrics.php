<?php
/**
 * Status Page Metrics Loading
 * 
 * Provides optimized access to metrics data for the status page with APCu caching.
 * Eliminates duplicate file reads and provides consistent caching strategy.
 */

require_once __DIR__ . '/cached-data-loader.php';
require_once __DIR__ . '/metrics.php';
require_once __DIR__ . '/cache-paths.php';

/**
 * Get rolling metrics with APCu caching
 * 
 * Caches aggregated metrics to avoid reading 30+ JSON files on every request.
 * Cache is shared across all status page loads for optimal performance.
 * 
 * @param int $days Number of days to aggregate (default: 7)
 * @param int $ttl Cache TTL in seconds (default: 60)
 * @return array Aggregated metrics with structure:
 *   - period_days: int
 *   - period_start: int timestamp
 *   - period_end: int timestamp
 *   - airports: array<string, array> (keyed by airport ID)
 *   - webcams: array<string, array> (keyed by webcam_airportid_camindex)
 *   - global: array (totals)
 */
function getStatusMetricsRolling(int $days = 7, int $ttl = 60): array {
    return getCachedData(
        fn() => metrics_get_rolling($days),
        "status_metrics_rolling_{$days}d",
        CACHE_BASE_DIR . "/status_metrics_rolling_{$days}d.json",
        $ttl
    );
}

/**
 * Get multi-period metrics with APCu caching
 * 
 * Returns hour/day/week metrics organized by airport for efficient display.
 * Caching eliminates redundant file reads across multiple time periods.
 * 
 * @param int $ttl Cache TTL in seconds (default: 60)
 * @return array Multi-period metrics indexed by airport ID with structure:
 *   - [airportId]['hour']: hourly metrics
 *   - [airportId]['day']: daily metrics
 *   - [airportId]['week']: weekly metrics
 *   - [airportId]['webcams']: per-webcam breakdown
 */
function getStatusMetricsMultiPeriod(int $ttl = 60): array {
    return getCachedData(
        fn() => metrics_get_multi_period(),
        'status_metrics_multi_period',
        CACHE_BASE_DIR . '/status_metrics_multi_period.json',
        $ttl
    );
}
