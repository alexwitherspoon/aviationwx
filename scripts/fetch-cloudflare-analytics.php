<?php
/**
 * Cloudflare Analytics Fetcher (Worker)
 *
 * Fetches analytics from Cloudflare API and writes to file cache.
 * Invoked by scheduler every CLOUDFLARE_ANALYTICS_FETCH_INTERVAL seconds.
 * Scheduler runs this in background (non-blocking) so page loads read pre-warmed cache.
 *
 * Exits 0 when Cloudflare is not configured (no-op). Exits 1 on fetch or write failure.
 *
 * Usage: php scripts/fetch-cloudflare-analytics.php
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/cloudflare-analytics.php';

$config = loadConfig();
if (!isset($config['config']['cloudflare']['api_token']) || empty($config['config']['cloudflare']['api_token'])) {
    exit(0);
}

$analytics = fetchCloudflareAnalytics();

if (empty($analytics)) {
    exit(1);
}

$fallbackFile = CLOUDFLARE_ANALYTICS_FALLBACK_FILE;
$cacheDir = dirname($fallbackFile);
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0755, true);
}

$written = @file_put_contents($fallbackFile, json_encode($analytics));
if ($written === false) {
    aviationwx_log('error', 'fetch-cloudflare-analytics: failed to write cache file', [
        'path' => $fallbackFile
    ], 'app');
    exit(1);
}

exit(0);
