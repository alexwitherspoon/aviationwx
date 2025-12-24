<?php
/**
 * Public API Rate Limiting
 * 
 * Implements multi-window rate limiting (per-minute, per-hour, per-day)
 * for the public API. Uses APCu when available, with file-based fallback.
 */

require_once __DIR__ . '/../logger.php';
require_once __DIR__ . '/config.php';

/**
 * Check rate limits for a public API request
 * 
 * Checks all three time windows (minute, hour, day) and returns
 * detailed rate limit information for response headers.
 * 
 * @param string $identifier IP address or API key identifier
 * @param string $tier 'anonymous' or 'partner'
 * @param bool $isHealthCheck If true, bypass rate limiting
 * @return array {
 *   'allowed' => bool,
 *   'tier' => string,
 *   'limits' => array,
 *   'remaining' => array,
 *   'reset' => array,
 *   'retry_after' => int|null
 * }
 */
function checkPublicApiRateLimit(string $identifier, string $tier = 'anonymous', bool $isHealthCheck = false): array
{
    // Health checks bypass rate limiting
    if ($isHealthCheck) {
        $limits = getPublicApiRateLimits($tier);
        return [
            'allowed' => true,
            'tier' => $tier,
            'limits' => $limits,
            'remaining' => $limits,
            'reset' => [
                'minute' => time() + 60,
                'hour' => time() + 3600,
                'day' => time() + 86400,
            ],
            'retry_after' => null,
        ];
    }
    
    $limits = getPublicApiRateLimits($tier);
    $now = time();
    
    // Check each time window
    $windows = [
        'minute' => ['limit' => $limits['requests_per_minute'], 'seconds' => 60],
        'hour' => ['limit' => $limits['requests_per_hour'], 'seconds' => 3600],
        'day' => ['limit' => $limits['requests_per_day'], 'seconds' => 86400],
    ];
    
    $remaining = [];
    $reset = [];
    $allowed = true;
    $retryAfter = null;
    
    foreach ($windows as $windowName => $windowConfig) {
        $result = checkAndIncrementWindow(
            $identifier,
            $windowName,
            $windowConfig['limit'],
            $windowConfig['seconds']
        );
        
        $remaining[$windowName] = $result['remaining'];
        $reset[$windowName] = $result['reset'];
        
        if (!$result['allowed']) {
            $allowed = false;
            // Use the shortest retry time
            $windowRetry = $result['reset'] - $now;
            if ($retryAfter === null || $windowRetry < $retryAfter) {
                $retryAfter = max(1, $windowRetry);
            }
        }
    }
    
    if (!$allowed) {
        aviationwx_log('warning', 'public api rate limit exceeded', [
            'identifier' => substr($identifier, 0, 16) . '...',
            'tier' => $tier,
            'remaining' => $remaining,
        ], 'api');
    }
    
    return [
        'allowed' => $allowed,
        'tier' => $tier,
        'limits' => [
            'minute' => $limits['requests_per_minute'],
            'hour' => $limits['requests_per_hour'],
            'day' => $limits['requests_per_day'],
        ],
        'remaining' => $remaining,
        'reset' => $reset,
        'retry_after' => $retryAfter,
    ];
}

/**
 * Check and increment a rate limit window
 * 
 * @param string $identifier Unique identifier (IP or API key)
 * @param string $windowName Window name (minute, hour, day)
 * @param int $limit Maximum requests allowed
 * @param int $windowSeconds Window duration in seconds
 * @return array {allowed: bool, remaining: int, reset: int}
 */
function checkAndIncrementWindow(string $identifier, string $windowName, int $limit, int $windowSeconds): array
{
    $cacheKey = 'public_api_' . $windowName . '_' . md5($identifier);
    $now = time();
    
    // Use APCu if available
    if (function_exists('apcu_fetch') && function_exists('apcu_store')) {
        return checkAndIncrementWindowApcu($cacheKey, $limit, $windowSeconds, $now);
    }
    
    // Fallback to file-based
    return checkAndIncrementWindowFile($cacheKey, $limit, $windowSeconds, $now);
}

/**
 * APCu-based rate limit check
 */
function checkAndIncrementWindowApcu(string $cacheKey, int $limit, int $windowSeconds, int $now): array
{
    $data = apcu_fetch($cacheKey);
    
    if ($data === false) {
        // First request in this window
        $data = ['count' => 1, 'reset' => $now + $windowSeconds];
        apcu_store($cacheKey, $data, $windowSeconds + 10);
        return [
            'allowed' => true,
            'remaining' => $limit - 1,
            'reset' => $data['reset'],
        ];
    }
    
    // Check if window expired
    if ($now >= $data['reset']) {
        $data = ['count' => 1, 'reset' => $now + $windowSeconds];
        apcu_store($cacheKey, $data, $windowSeconds + 10);
        return [
            'allowed' => true,
            'remaining' => $limit - 1,
            'reset' => $data['reset'],
        ];
    }
    
    // Check if limit exceeded
    if ($data['count'] >= $limit) {
        return [
            'allowed' => false,
            'remaining' => 0,
            'reset' => $data['reset'],
        ];
    }
    
    // Increment counter
    $data['count']++;
    apcu_store($cacheKey, $data, $windowSeconds + 10);
    
    return [
        'allowed' => true,
        'remaining' => max(0, $limit - $data['count']),
        'reset' => $data['reset'],
    ];
}

/**
 * File-based rate limit check (fallback)
 */
function checkAndIncrementWindowFile(string $cacheKey, int $limit, int $windowSeconds, int $now): array
{
    $cacheDir = __DIR__ . '/../../cache/rate_limits';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    
    $cacheFile = $cacheDir . '/' . $cacheKey . '.json';
    
    // Use file locking for atomic operations
    $fp = @fopen($cacheFile, 'c+');
    if ($fp === false) {
        // Can't create file, allow request (fail open)
        return [
            'allowed' => true,
            'remaining' => $limit - 1,
            'reset' => $now + $windowSeconds,
        ];
    }
    
    if (!@flock($fp, LOCK_EX)) {
        @fclose($fp);
        return [
            'allowed' => true,
            'remaining' => $limit - 1,
            'reset' => $now + $windowSeconds,
        ];
    }
    
    // Read current data
    $data = [];
    $fileSize = @filesize($cacheFile);
    if ($fileSize > 0) {
        $content = @stream_get_contents($fp);
        if ($content !== false && $content !== '') {
            $data = @json_decode($content, true) ?: [];
        }
    }
    
    // Check if window expired or first request
    $dataReset = isset($data['reset']) ? (int)$data['reset'] : null;
    if ($dataReset === null || $now >= $dataReset) {
        $data = ['count' => 1, 'reset' => $now + $windowSeconds];
    } else {
        // Check if limit exceeded
        $currentCount = isset($data['count']) ? (int)$data['count'] : 0;
        if ($currentCount >= $limit) {
            @flock($fp, LOCK_UN);
            @fclose($fp);
            return [
                'allowed' => false,
                'remaining' => 0,
                'reset' => $dataReset,
            ];
        }
        $data['count'] = $currentCount + 1;
    }
    
    // Write updated data
    @ftruncate($fp, 0);
    @rewind($fp);
    @fwrite($fp, json_encode($data));
    @fflush($fp);
    @flock($fp, LOCK_UN);
    @fclose($fp);
    
    return [
        'allowed' => true,
        'remaining' => max(0, $limit - $data['count']),
        'reset' => $data['reset'],
    ];
}

/**
 * Get rate limit headers for API response
 * 
 * Returns the most restrictive limit (per-minute) for standard headers
 * 
 * @param array $rateLimitResult Result from checkPublicApiRateLimit
 * @return array Headers as key-value pairs
 */
function getPublicApiRateLimitHeaders(array $rateLimitResult): array
{
    // Use per-minute limits for headers (most relevant for clients)
    return [
        'X-RateLimit-Limit' => (string)$rateLimitResult['limits']['minute'],
        'X-RateLimit-Remaining' => (string)$rateLimitResult['remaining']['minute'],
        'X-RateLimit-Reset' => (string)$rateLimitResult['reset']['minute'],
    ];
}

