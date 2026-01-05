<?php
/**
 * Cache Header Helpers
 * 
 * Provides consistent cache header generation for all API endpoints.
 * Ensures CDN caching works correctly with dynamic refresh intervals.
 */

require_once __DIR__ . '/constants.php';

/**
 * Generate cache headers for dynamic data endpoints
 * 
 * Uses s-maxage to control CDN cache separately from browser cache.
 * CDN cache expires at half the refresh interval (min 5s) to support
 * fast-refresh airports while reducing origin load. Browser cache can be
 * configured independently based on endpoint needs.
 * 
 * @param int $refreshInterval Actual refresh interval in seconds (not clamped)
 * @param int|null $browserMaxAge Browser cache TTL in seconds (null = no browser cache)
 * @param bool $includeStaleWhileRevalidate Include stale-while-revalidate directive
 * @return array Headers as key-value pairs
 */
function generateCacheHeaders(int $refreshInterval, ?int $browserMaxAge = null, bool $includeStaleWhileRevalidate = true): array {
    // CDN cache: half of refresh interval, minimum 5s to support fast refreshes
    // This ensures requests reach origin periodically for accurate tracking
    $cdnMaxAge = max(5, intval($refreshInterval / 2));
    
    $cacheControl = 'public';
    
    // Browser cache: use provided value or disable (max-age=0)
    if ($browserMaxAge !== null) {
        $cacheControl .= ', max-age=' . $browserMaxAge;
    } else {
        $cacheControl .= ', max-age=0';
    }
    
    // CDN cache: always set s-maxage for CDN control
    $cacheControl .= ', s-maxage=' . $cdnMaxAge;
    
    // Stale-while-revalidate: allow serving stale content while revalidating in background
    if ($includeStaleWhileRevalidate) {
        $cacheControl .= ', stale-while-revalidate=' . STALE_WHILE_REVALIDATE_SECONDS;
    }
    
    $headers = [
        'Cache-Control' => $cacheControl
    ];
    
    // Add Pragma and Expires for older clients when browser cache is disabled
    if ($browserMaxAge === null || $browserMaxAge === 0) {
        $headers['Pragma'] = 'no-cache';
        $headers['Expires'] = '0';
    }
    
    return $headers;
}

/**
 * Send cache headers for dynamic data endpoints
 * 
 * Convenience wrapper that generates and sends headers in one call.
 * 
 * @param int $refreshInterval Actual refresh interval in seconds
 * @param int|null $browserMaxAge Browser cache TTL in seconds (null = no browser cache)
 * @param bool $includeStaleWhileRevalidate Include stale-while-revalidate directive
 * @return void
 */
function sendCacheHeaders(int $refreshInterval, ?int $browserMaxAge = null, bool $includeStaleWhileRevalidate = true): void {
    $headers = generateCacheHeaders($refreshInterval, $browserMaxAge, $includeStaleWhileRevalidate);
    foreach ($headers as $name => $value) {
        header($name . ': ' . $value);
    }
}

