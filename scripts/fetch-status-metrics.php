<?php
/**
 * Status Metrics Pre-warm Worker
 *
 * Computes metrics bundle (rolling7, rolling1, today) and writes to file cache.
 * Invoked by scheduler every STATUS_METRICS_FETCH_INTERVAL seconds.
 * Page loads read from APCu/file cache for fast access.
 *
 * Usage: php scripts/fetch-status-metrics.php
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/constants.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/cache-paths.php';
require_once __DIR__ . '/../lib/cached-data-loader.php';
require_once __DIR__ . '/../lib/metrics.php';

$bundle = metrics_get_status_bundle();

$fileData = [
    'cached_at' => time(),
    'ttl' => STATUS_METRICS_CACHE_TTL,
    'key' => 'status_metrics_bundle',
    'data' => $bundle
];

$cacheDir = dirname(CACHE_STATUS_METRICS_BUNDLE_FILE);
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0755, true);
}

$tmpFile = CACHE_STATUS_METRICS_BUNDLE_FILE . '.tmp.' . getmypid();
$written = @file_put_contents($tmpFile, json_encode($fileData, JSON_PRETTY_PRINT), LOCK_EX);
if ($written === false) {
    aviationwx_log('warning', 'fetch-status-metrics: failed to write cache file', [
        'path' => CACHE_STATUS_METRICS_BUNDLE_FILE
    ], 'app');
    exit(1);
}
if (!@rename($tmpFile, CACHE_STATUS_METRICS_BUNDLE_FILE)) {
    @unlink($tmpFile);
    exit(1);
}

exit(0);
