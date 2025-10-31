<?php
/**
 * Simple Rate Limiting Utility
 * IP-based rate limiting for API endpoints
 */

/**
 * Check if request should be rate limited
 * @param string $key Unique key for this rate limit (e.g., 'weather_api')
 * @param int $maxRequests Maximum requests allowed
 * @param int $windowSeconds Time window in seconds
 * @return bool True if allowed, false if rate limited
 */
function checkRateLimit($key, $maxRequests = 60, $windowSeconds = 60) {
    // Get client IP (respect proxy headers)
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    // Handle comma-separated IPs (X-Forwarded-For can contain multiple IPs)
    $ip = trim(explode(',', $ip)[0]);
    
    // Use APCu if available for rate limiting
    if (function_exists('apcu_fetch') && function_exists('apcu_store')) {
        $rateLimitKey = 'rate_limit_' . $key . '_' . md5($ip);
        $data = apcu_fetch($rateLimitKey);
        
        if ($data === false) {
            // First request in this window
            apcu_store($rateLimitKey, ['count' => 1, 'reset' => time() + $windowSeconds], $windowSeconds + 10);
            return true;
        }
        
        // Check if window expired
        if (time() >= ($data['reset'] ?? 0)) {
            // Reset window
            apcu_store($rateLimitKey, ['count' => 1, 'reset' => time() + $windowSeconds], $windowSeconds + 10);
            return true;
        }
        
        if (($data['count'] ?? 0) >= $maxRequests) {
            // Rate limit exceeded
            return false;
        }
        
        // Increment counter
        $data['count'] = ($data['count'] ?? 0) + 1;
        apcu_store($rateLimitKey, $data, $windowSeconds + 10);
        return true;
    }
    
    // Fallback: No rate limiting if APCu not available (for development)
    // In production, APCu should be available via Dockerfile
    return true;
}

/**
 * Get remaining rate limit for this key
 * @param string $key Rate limit key
 * @param int $maxRequests Maximum requests allowed
 * @return int Remaining requests, or -1 if unknown
 */
function getRateLimitRemaining($key, $maxRequests = 60) {
    if (!function_exists('apcu_fetch')) {
        return -1;
    }
    
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $ip = trim(explode(',', $ip)[0]);
    $rateLimitKey = 'rate_limit_' . $key . '_' . md5($ip);
    $current = apcu_fetch($rateLimitKey);
    
    if ($current === false) {
        return $maxRequests;
    }
    
    return max(0, $maxRequests - $current);
}
