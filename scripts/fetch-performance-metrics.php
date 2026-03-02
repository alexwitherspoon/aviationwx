<?php
/**
 * Performance Metrics Pre-warm Worker
 *
 * Computes node performance, image processing metrics, and page render metrics.
 * Invoked by scheduler every PERFORMANCE_METRICS_FETCH_INTERVAL seconds.
 * Prevents status page from blocking on expensive ops (storage calc, percentiles).
 *
 * Usage: php scripts/fetch-performance-metrics.php
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/constants.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/cache-paths.php';
require_once __DIR__ . '/../lib/performance-metrics.php';
require_once __DIR__ . '/../lib/status-utils.php';

$ttl = PERFORMANCE_METRICS_CACHE_TTL;

/**
 * Write cache file in getCachedData format
 *
 * @param string $path File path
 * @param string $key Cache key
 * @param mixed $data Data to cache (array or object; will be JSON-encoded)
 * @param int $ttl TTL in seconds
 * @return bool True on success
 */
function writePerformanceMetricsCache(string $path, string $key, mixed $data, int $ttl): bool {
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

$nodePerformance = getNodePerformance();
if (!writePerformanceMetricsCache(
    CACHE_NODE_PERFORMANCE_FILE,
    'status_node_performance',
    $nodePerformance,
    $ttl
)) {
    aviationwx_log('warning', 'fetch-performance-metrics: failed to write node performance cache', [], 'app');
}

$imageProcessingMetrics = getImageProcessingMetrics();
if (!writePerformanceMetricsCache(
    CACHE_IMAGE_PROCESSING_METRICS_FILE,
    'status_image_processing',
    $imageProcessingMetrics,
    $ttl
)) {
    aviationwx_log('warning', 'fetch-performance-metrics: failed to write image processing metrics cache', [], 'app');
}

$pageRenderMetrics = getPageRenderMetrics();
if (!writePerformanceMetricsCache(
    CACHE_PAGE_RENDER_METRICS_FILE,
    'status_page_render',
    $pageRenderMetrics,
    $ttl
)) {
    aviationwx_log('warning', 'fetch-performance-metrics: failed to write page render metrics cache', [], 'app');
}

exit(0);
