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
 * Uses file locking to ensure atomic read-modify-write operations
 * @param string $key Rate limit key
 * @param int $maxRequests Maximum requests allowed
 * @param int $windowSeconds Time window in seconds
 * @param string $ip Client IP address
 * @return bool True if allowed, false if rate limited
 */
function checkRateLimitFileBased($key, $maxRequests, $windowSeconds, $ip) {
    $cacheDir = __DIR__ . '/../cache';
    if (!is_dir($cacheDir)) {
        if (!@mkdir($cacheDir, 0755, true)) {
            aviationwx_log('error', 'rate limit cache directory creation failed', ['dir' => $cacheDir], 'app');
            return true; // Fail open - allow request if we can't create directory
        }
    }
    
    $rateLimitFile = $cacheDir . '/rate_limit_' . md5($key . '_' . $ip) . '.json';
    $now = time();
    
    // Use file locking for atomic read-modify-write
    $fp = @fopen($rateLimitFile, 'c+');
    if ($fp === false) {
        // Fallback to non-locked write if we can't open file
        aviationwx_log('warning', 'rate limit file open failed, using fallback', ['file' => $rateLimitFile], 'app');
        return checkRateLimitFileBasedFallback($key, $maxRequests, $windowSeconds, $ip, $rateLimitFile, $now);
    }
    
    // Acquire exclusive lock (blocking to ensure atomicity)
    if (!@flock($fp, LOCK_EX)) {
        @fclose($fp);
        aviationwx_log('warning', 'rate limit file lock failed, using fallback', ['file' => $rateLimitFile], 'app');
        return checkRateLimitFileBasedFallback($key, $maxRequests, $windowSeconds, $ip, $rateLimitFile, $now);
    }
    
    // Read current data while lock is held
    $data = [];
    $fileSize = @filesize($rateLimitFile);
    if ($fileSize > 0) {
        $content = @stream_get_contents($fp);
        if ($content !== false && $content !== '') {
            $data = @json_decode($content, true);
            if (!is_array($data)) {
                $data = [];
            }
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
        @flock($fp, LOCK_UN);
        @fclose($fp);
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
    
    // Write modified data back while lock is still held
    @ftruncate($fp, 0);
    @rewind($fp);
    @fwrite($fp, json_encode($data));
    @fflush($fp);
    
    // Release lock and close
    @flock($fp, LOCK_UN);
    @fclose($fp);
    
    return true;
}

/**
 * Fallback for file-based rate limiting when file handle operations fail
 * Uses file_put_contents with LOCK_EX as last resort
 */
function checkRateLimitFileBasedFallback($key, $maxRequests, $windowSeconds, $ip, $rateLimitFile, $now) {
    $data = [];
    if (file_exists($rateLimitFile)) {
        $content = @file_get_contents($rateLimitFile);
        if ($content !== false) {
            $data = @json_decode($content, true) ?: [];
        }
    }
    
    if (isset($data['reset']) && $now >= $data['reset']) {
        $data = ['count' => 0, 'reset' => $now + $windowSeconds];
    } elseif (!isset($data['reset'])) {
        $data = ['count' => 0, 'reset' => $now + $windowSeconds];
    }
    
    if (($data['count'] ?? 0) >= $maxRequests) {
        aviationwx_log('warning', 'rate limit exceeded (file-based fallback)', [
            'key' => $key,
            'ip' => $ip,
            'limit' => $maxRequests
        ], 'app');
        return false;
    }
    
    $data['count'] = ($data['count'] ?? 0) + 1;
    if (!isset($data['reset'])) {
        $data['reset'] = $now + $windowSeconds;
    }
    
    @file_put_contents($rateLimitFile, json_encode($data), LOCK_EX);
    return true;
}

/**
 * Get remaining rate limit for this key
 * @param string $key Rate limit key
 * @param int $maxRequests Maximum requests allowed
 * @param int $windowSeconds Time window in seconds (optional, for consistency)
 * @return array|null Returns array with 'remaining' and 'reset' keys, or null if unavailable
 */
function getRateLimitRemaining($key, $maxRequests = RATE_LIMIT_WEATHER_MAX, $windowSeconds = RATE_LIMIT_WEATHER_WINDOW) {
    // Extract IP address (shared logic)
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $ip = trim(explode(',', $ip)[0]);
    
    if (!function_exists('apcu_fetch')) {
        // Fallback to file-based check
        $cacheDir = __DIR__ . '/../cache';
        $rateLimitFile = $cacheDir . '/rate_limit_' . md5($key . '_' . $ip) . '.json';
        $now = time();
        
        if (file_exists($rateLimitFile)) {
            $content = @file_get_contents($rateLimitFile);
            if ($content !== false) {
                $data = @json_decode($content, true);
                if (is_array($data)) {
                    if (isset($data['reset']) && $now < $data['reset']) {
                        $currentCount = $data['count'] ?? 0;
                        return [
                            'remaining' => (int)max(0, $maxRequests - $currentCount),
                            'reset' => (int)$data['reset']
                        ];
                    }
                }
            }
        }
        
        // No rate limit data or window expired
        return [
            'remaining' => $maxRequests,
            'reset' => $now + $windowSeconds
        ];
    }
    
    $rateLimitKey = 'rate_limit_' . $key . '_' . md5($ip);
    $data = apcu_fetch($rateLimitKey);
    
    if ($data === false) {
        // No rate limit data exists, so all requests are available
        return [
            'remaining' => $maxRequests,
            'reset' => time() + $windowSeconds
        ];
    }
    
    // Extract count and reset time from the data array
    $currentCount = is_array($data) ? ($data['count'] ?? 0) : (is_numeric($data) ? $data : 0);
    $now = time();
    
    // Check if window expired
    if (is_array($data) && isset($data['reset']) && $now >= $data['reset']) {
        // Window expired, all requests are available
        return [
            'remaining' => $maxRequests,
            'reset' => $now + $windowSeconds
        ];
    }
    
    $resetTime = is_array($data) && isset($data['reset']) ? (int)$data['reset'] : ($now + $windowSeconds);
    
    return [
        'remaining' => (int)max(0, $maxRequests - $currentCount),
        'reset' => $resetTime
    ];
}
