<?php
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/constants.php';
require_once __DIR__ . '/config.php';
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
function checkRateLimit($key, $maxRequests = RATE_LIMIT_WEATHER_MAX, $windowSeconds = RATE_LIMIT_WEATHER_WINDOW) {
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
            apcu_store($rateLimitKey, ['count' => 1, 'reset' => time() + $windowSeconds], $windowSeconds + RATE_LIMIT_APCU_TTL_BUFFER);
            return true;
        }
        
        // Check if window expired
        if (time() >= ($data['reset'] ?? 0)) {
            // Reset window
            apcu_store($rateLimitKey, ['count' => 1, 'reset' => time() + $windowSeconds], $windowSeconds + RATE_LIMIT_APCU_TTL_BUFFER);
            return true;
        }
        
        if (($data['count'] ?? 0) >= $maxRequests) {
            // Rate limit exceeded
            aviationwx_log('warning', 'rate limit exceeded', [
                'key' => $key,
                'ip' => $ip,
                'limit' => $maxRequests,
                'reset' => $data['reset'] ?? null
            ], 'app');
            return false;
        }
        
        // Increment counter
        $data['count'] = ($data['count'] ?? 0) + 1;
        apcu_store($rateLimitKey, $data, $windowSeconds + RATE_LIMIT_APCU_TTL_BUFFER);
        return true;
    }
    
    // Fallback: File-based rate limiting if APCu not available
    $isProd = isProduction();
    if ($isProd) {
        aviationwx_log('warning', 'APCu not available, using file-based rate limiting fallback', [
            'key' => $key
        ], 'app');
    }
    return checkRateLimitFileBased($key, $maxRequests, $windowSeconds, $ip);
}

/**
 * File-based rate limiting fallback
 * @param string $key Rate limit key
 * @param int $maxRequests Maximum requests allowed
 * @param int $windowSeconds Time window in seconds
 * @param string $ip Client IP address
 * @return bool True if allowed, false if rate limited
 */
function checkRateLimitFileBased($key, $maxRequests, $windowSeconds, $ip) {
    $cacheDir = __DIR__ . '/../cache';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    
    $rateLimitFile = $cacheDir . '/rate_limit_' . md5($key . '_' . $ip) . '.json';
    $now = time();
    
    // Read existing data
    $data = [];
    if (file_exists($rateLimitFile)) {
        $content = @file_get_contents($rateLimitFile);
        if ($content !== false) {
            $data = @json_decode($content, true) ?: [];
        }
    }
    
    // Check if window expired
    if (isset($data['reset']) && $now >= $data['reset']) {
        $data = ['count' => 0, 'reset' => $now + $windowSeconds];
    } elseif (!isset($data['reset'])) {
        $data = ['count' => 0, 'reset' => $now + $windowSeconds];
    }
    
    // Check if limit exceeded
    if (($data['count'] ?? 0) >= $maxRequests) {
        aviationwx_log('warning', 'rate limit exceeded (file-based)', [
            'key' => $key,
            'ip' => $ip,
            'limit' => $maxRequests,
            'reset' => $data['reset'] ?? null
        ], 'app');
        return false;
    }
    
    // Increment counter
    $data['count'] = ($data['count'] ?? 0) + 1;
    if (!isset($data['reset'])) {
        $data['reset'] = $now + $windowSeconds;
    }
    
    // Write with file locking
    @file_put_contents($rateLimitFile, json_encode($data), LOCK_EX);
    
    return true;
}

/**
 * Get remaining rate limit for this key
 * @param string $key Rate limit key
 * @param int $maxRequests Maximum requests allowed
 * @param int $windowSeconds Time window in seconds (optional, for consistency)
 * @return int Returns remaining count as integer, or maxRequests if APCu unavailable
 */
function getRateLimitRemaining($key, $maxRequests = RATE_LIMIT_WEATHER_MAX, $windowSeconds = RATE_LIMIT_WEATHER_WINDOW) {
    if (!function_exists('apcu_fetch')) {
        // Fallback to file-based check
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $ip = trim(explode(',', $ip)[0]);
        
        $cacheDir = __DIR__ . '/../cache';
        $rateLimitFile = $cacheDir . '/rate_limit_' . md5($key . '_' . $ip) . '.json';
        
        if (file_exists($rateLimitFile)) {
            $content = @file_get_contents($rateLimitFile);
            if ($content !== false) {
                $data = @json_decode($content, true) ?: [];
                $now = time();
                
                if (isset($data['reset']) && $now < $data['reset']) {
                    $currentCount = $data['count'] ?? 0;
                    return (int)max(0, $maxRequests - $currentCount);
                }
            }
        }
        
        return $maxRequests;
    }
    
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $ip = trim(explode(',', $ip)[0]);
    $rateLimitKey = 'rate_limit_' . $key . '_' . md5($ip);
    $data = apcu_fetch($rateLimitKey);
    
    if ($data === false) {
        // No rate limit data exists, so all requests are available
        return $maxRequests;
    }
    
    // Extract count and reset time from the data array
    $currentCount = is_array($data) ? ($data['count'] ?? 0) : (is_numeric($data) ? $data : 0);
    
    // Check if window expired
    if (is_array($data) && isset($data['reset']) && time() >= $data['reset']) {
        // Window expired, all requests are available
        return $maxRequests;
    }
    
    return (int)max(0, $maxRequests - $currentCount);
}
